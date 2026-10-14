<?php

declare(strict_types=1);

namespace CW\Queues;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Relay\RelayIntake;
use CW\SiteWriter\SiteModes;
use CW\Stock;

/**
 * ERPNext's selling-mode changes, relayed by a store into the policy-review queue (policy_review, source erp_update_product; plan §9;
 * docs/decisions.md PF8). Approvals › From the stores › Mode changes. Only changes that change something reach it (RelayIntake's
 * filter); a newer change of the same variant replaces an older one still open.
 *
 *   accept()   applies the change in CW, by the item's state NOW:
 *              - an item not counted yet (stock rule legacy): the selling mode CW writes on THAT store becomes the mode ERPNext set,
 *                with its low-stock level when the store sent one (SiteModes::set, IM10's switch, on the relaying store only); the
 *                stock rule stays legacy (only the count gate protects an item, plan §7.4);
 *              - a counted, protected item: its stock rule becomes the one the mode proposes (the settings relay.mode_*: In-Stock
 *                backorder, From-Warehouse strict, Out-Of-Stock stopped), by the steps of Stock::setPolicy inside this transaction (the
 *                item's balances, then the item row, then the feed clock), so a reserve deciding on the old rule finishes first.
 *              The row is resolved (`accepted`) in the same transaction.
 *   dismiss()  CW keeps what it has; the row is dismissed with the note.
 *
 * Who: queues.modes (the purchasing desk and manager, the stock controller, the manager: the people who set modes, I158), re-read
 * inside the transaction. The queue row is locked first.
 */
final class ModeChanges
{
    public const TABLE = 'policy_review';
    public const PERM = 'queues.modes';
    public const SOURCE = RelayIntake::SOURCE_MODE;

    public function __construct(private readonly Db $db)
    {
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM policy_review WHERE status = 'open' AND source = ?", [self::SOURCE]);
    }

    /** @return list<array<string, mixed>> open rows oldest first, or every row of a status newest first */
    public function list(?string $status = 'open', int $limit = QueueRules::PAGE + 1): array
    {
        $order = $status === 'open' ? 'p.created_at, p.id' : 'p.id DESC';
        $rows = $this->db->all(
            'SELECT p.id, p.sku_id, s.code AS sku_code, s.name AS sku_name, CAST(s.sell_policy AS CHAR) AS policy_now, p.listing_id, l.external_variant_id, '
            . 'l.channel_id AS listing_channel_id, p.site_mode, CAST(p.current_policy AS CHAR) AS current_policy, CAST(p.proposed_policy AS CHAR) AS proposed_policy, '
            . 'CAST(p.detail AS CHAR) AS detail, CAST(p.status AS CHAR) AS status, p.resolution, p.resolved_by, p.resolved_at, p.note, p.created_at '
            . 'FROM policy_review p LEFT JOIN sku s ON s.id = p.sku_id LEFT JOIN channel_listing l ON l.id = p.listing_id '
            . 'WHERE p.source = ?' . ($status === null ? '' : ' AND p.status = ?') . " ORDER BY {$order} LIMIT " . max(1, min(500, $limit)),
            $status === null ? [self::SOURCE] : [self::SOURCE, $status],
        );
        $names = QueueRules::names($this->db, array_column($rows, 'resolved_by'));
        $stores = QueueRules::stores($this->db);
        foreach ($rows as &$r) {
            $d = json_decode((string) $r['detail'], true);
            $r['detail'] = is_array($d) ? $d : [];
            $cid = $r['listing_channel_id'] ?? ($r['detail']['channel_id'] ?? null);
            $r['store'] = $cid === null ? null : ($stores[(int) $cid] ?? null);
            $r['resolved_by_name'] = $r['resolved_by'] === null ? null : ($names[(int) $r['resolved_by']] ?? null);
            $r['applies'] = $r['policy_now'] === 'legacy' ? 'mode' : 'policy';
        }
        return $rows;
    }

    /** @return array{applied: string, changed: bool} applied: mode (the store's selling mode) | policy (the item's stock rule) */
    public function accept(Caller $caller, int $id, string $note): array
    {
        $note = QueueRules::note($note);
        return $this->db->transaction(function (Db $db) use ($caller, $id, $note): array {
            QueueRules::authorise($db, $caller, self::PERM);
            $row = QueueRules::lockOpen($db, self::TABLE, $id, 'source = ?', [self::SOURCE]);
            $d = json_decode((string) ($row['detail'] ?? '{}'), true);
            $d = is_array($d) ? $d : [];
            $skuId = $row['sku_id'] === null ? throw new CwException('not_matched', 'this change names no item', 409) : (int) $row['sku_id'];
            $sku = $db->one('SELECT code, CAST(sell_policy AS CHAR) AS policy, merged_into_sku_id FROM sku WHERE id = ?', [$skuId])
                ?? throw new CwException('unknown_item', 'no such item', 404);
            if ($sku['merged_into_sku_id'] !== null) {
                throw new CwException('merged_item', "{$sku['code']} was merged into another item: dismiss this change", 409);
            }
            QueueRules::settle($db, $caller, self::TABLE, $id, 'resolved', 'accepted', $note);
            if ($sku['policy'] === 'legacy') {
                $channelId = (int) ($d['channel_id'] ?? 0);
                $code = $db->value('SELECT code FROM channel WHERE id = ?', [$channelId]) ?? throw new CwException('unknown_site', 'the store of this change is gone', 409);
                $low = $d['low_stock'] ?? null;
                $r = (new SiteModes($db))->set($caller, $skuId, (string) $row['site_mode'], [(string) $code], is_int($low) ? (string) $low : '', $note);
                $applied = 'mode';
                $changed = $r['changed'] !== [];
            } else {
                $proposed = (string) $row['proposed_policy'];
                // Stock::setPolicy's steps in this transaction: the item's balances, then the item row, then the feed clock (LAST).
                $stock = new Stock($db);
                $whs = $db->column('SELECT warehouse_id FROM stock_balance WHERE sku_id = ?', [$skuId]);
                $stock->lock(array_map(static fn ($wh): array => [(int) $wh, $skuId], $whs));
                $from = (string) $db->value('SELECT CAST(sell_policy AS CHAR) FROM sku WHERE id = ? FOR UPDATE', [$skuId]);
                $changed = $from !== $proposed;
                if ($changed) {
                    $db->exec('UPDATE sku SET sell_policy = ? WHERE id = ?', [$proposed, $skuId]);
                    Audit::write($db, $caller, 'sku.policy', 'sku', (string) $skuId, null, ['from' => $from, 'to' => $proposed, 'policy_review' => $id]);
                    $stock->skuChanged($skuId, 'policy');
                }
                $applied = 'policy';
            }
            Audit::write($db, $caller, 'policy_review.accept', self::TABLE, (string) $id, null, ['sku_code' => $sku['code'], 'mode' => $row['site_mode'],
                'applied' => $applied, 'changed' => $changed, 'note' => $note]);
            return ['applied' => $applied, 'changed' => $changed];
        });
    }

    public function dismiss(Caller $caller, int $id, string $note): void
    {
        $note = QueueRules::note($note);
        $this->db->transaction(function (Db $db) use ($caller, $id, $note): void {
            QueueRules::authorise($db, $caller, self::PERM);
            $row = QueueRules::lockOpen($db, self::TABLE, $id, 'source = ?', [self::SOURCE]);
            QueueRules::settle($db, $caller, self::TABLE, $id, 'dismissed', 'dismissed', $note);
            Audit::write($db, $caller, 'policy_review.dismiss', self::TABLE, (string) $id, null, ['mode' => $row['site_mode'], 'note' => $note]);
        });
    }
}
