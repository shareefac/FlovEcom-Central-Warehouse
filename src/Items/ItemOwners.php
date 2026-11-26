<?php

declare(strict_types=1);

namespace CW\Items;

use CW\Admin\ConfigHistory;
use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Settings;

/**
 * A shop's OWN items (CW-1153; docs/decisions.md VB1-VB6): items whose stock is the selling shop's own goods, bought with its own money,
 * not ours (Vape Big's Hayati 4000 and Elux ranges). Their stock stays in MAIN and sells exactly as today: CW only MARKS them, so that our
 * figures (profit, stock value, purchasing, reorder) leave them out and the shop's page shows them on their own line.
 *
 * The mark has a history by PERIOD (item_owner: from_at .. to_at): every figure uses the period of the event's own time (an order's paid
 * time, a bill's posting), never today's list. Re-adding an item after a removal is a new period; periods never overlap. Each add and
 * remove has a reason and an audit row; nothing is rewritten.
 *
 * Who: an admin or a reviewer (settings.manage, ConfigHistory::authorise), on Settings > Own items; a system caller (the go-live kit's tool)
 * may start a period at a moment in the past (the one explicit exception: the items sold since 17 Nov 2026 were the shop's own from the
 * count that brought them in), a person only now.
 *
 * Locks: the item's row FOR UPDATE, the same row every link decision reads FOR SHARE before it checks the mark (DecisionService), and every
 * bill reads FOR SHARE when it is made final (SupplierInvoiceHandler): a mark and a link (or a bill) never both pass on what the other has
 * not committed yet.
 */
final class ItemOwners
{
    public const SETTING = 'stock.own_items_shop';
    public const HISTORY_LIMIT = 200;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    // ---- reading ----------------------------------------------------------------------------------------------------------------

    /**
     * The shop whose own items the screen adds (the setting), or null when it names no shop.
     *
     * @return array{id: int, code: string, name: string}|null
     */
    public static function shop(Db $db): ?array
    {
        $settings = new Settings($db);
        $code = $settings->has(self::SETTING) ? (string) $settings->get(self::SETTING) : '';
        if ($code === '') {
            return null;
        }
        $r = $db->one('SELECT id, code, name FROM channel WHERE code = ?', [$code]);
        return $r === null ? null : ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name']];
    }

    /**
     * The items among $skuIds that are some shop's own NOW (an open period): sku => that shop's channel id. A plain read: a caller that
     * must be exact holds the items' rows (FOR SHARE or FOR UPDATE) first.
     *
     * @param list<int> $skuIds
     * @return array<int, int>
     */
    public static function open(Db $db, array $skuIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $skuIds)));
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($db->all('SELECT sku_id, channel_id FROM item_owner WHERE to_at IS NULL AND sku_id IN (' . self::marks($chunk) . ')', $chunk) as $r) {
                $out[(int) $r['sku_id']] = (int) $r['channel_id'];
            }
        }
        return $out;
    }

    /** Every item that is some shop's own now: sku => channel id. @return array<int, int> */
    public static function allOpen(Db $db): array
    {
        $out = [];
        foreach ($db->all('SELECT sku_id, channel_id FROM item_owner WHERE to_at IS NULL') as $r) {
            $out[(int) $r['sku_id']] = (int) $r['channel_id'];
        }
        return $out;
    }

    /**
     * Every period of the items among $skuIds, oldest first: sku => list of [channel id, from_at, to_at|null].
     *
     * @param list<int> $skuIds
     * @return array<int, list<array{0: int, 1: string, 2: ?string}>>
     */
    public static function periods(Db $db, array $skuIds): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($skuIds, static fn ($s): bool => $s !== null))));
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($db->all('SELECT sku_id, channel_id, from_at, to_at FROM item_owner WHERE sku_id IN (' . self::marks($chunk) . ') ORDER BY sku_id, from_at', $chunk) as $r) {
                $out[(int) $r['sku_id']][] = [(int) $r['channel_id'], (string) $r['from_at'], $r['to_at'] === null ? null : (string) $r['to_at']];
            }
        }
        return $out;
    }

    /**
     * The shop whose own goods $sku was at the moment $at (a UTC DATETIME(6) string), from periods(): its channel id, or null (ours).
     *
     * @param array<int, list<array{0: int, 1: string, 2: ?string}>> $periods
     */
    public static function ownerAt(array $periods, ?int $sku, ?string $at): ?int
    {
        if ($sku === null || $at === null) {
            return null;
        }
        if (strlen($at) === 19) {
            $at .= '.000000'; // a time stored without its microseconds compares as the same moment
        }
        foreach ($periods[$sku] ?? [] as [$channel, $from, $to]) {
            if ($at >= $from && ($to === null || $at < $to)) {
                return $channel;
            }
        }
        return null;
    }

    /**
     * The items that are $channelId's own now, with what each holds in our warehouses (on hand, sold waiting to ship, reserved) and its
     * buying price now (item_cost: 0 is a price, free; null: none known): code order.
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $channelId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['period_id'], 'sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'],
            'name' => (string) $r['name'], 'from_at' => (string) $r['from_at'], 'on_hand' => (int) $r['on_hand'], 'allocated' => (int) $r['allocated'],
            'held' => (int) $r['held'], 'unit_cost_e4' => $r['unit_cost_e4'] === null ? null : (int) $r['unit_cost_e4']],
            $this->db->all('SELECT p.id AS period_id, p.sku_id, p.from_at, s.code, s.name, ic.unit_cost_e4, '
                . 'COALESCE(SUM(b.on_hand), 0) AS on_hand, COALESCE(SUM(b.allocated), 0) AS allocated, COALESCE(SUM(b.held), 0) AS held '
                . 'FROM item_owner p JOIN sku s ON s.id = p.sku_id LEFT JOIN item_cost ic ON ic.sku_id = p.sku_id LEFT JOIN stock_balance b ON b.sku_id = p.sku_id '
                . 'WHERE p.channel_id = ? AND p.to_at IS NULL GROUP BY p.id, p.sku_id, p.from_at, s.code, s.name, ic.unit_cost_e4 ORDER BY s.code', [$channelId]));
    }

    /**
     * The newest periods first (added and removed), with who and why: the screen's history.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = self::HISTORY_LIMIT): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'shop' => (string) $r['shop'],
            'from_at' => (string) $r['from_at'], 'to_at' => $r['to_at'] === null ? null : (string) $r['to_at'], 'add_reason' => (string) $r['add_reason'],
            // a system tool (the go-live kit) is CW, never its system name (the final review, Fable L11b)
            'added_by' => (string) ($r['added_name'] ?? (str_starts_with((string) $r['added_actor'], 'system:') ? \CW\Ui\Words::RECORD['by_cw'] : $r['added_actor'])),
            'added_at' => (string) $r['added_at'],
            'remove_reason' => $r['remove_reason'] === null ? null : (string) $r['remove_reason'],
            'removed_by' => $r['to_at'] === null ? null : (string) ($r['removed_name']
                ?? (str_starts_with((string) $r['removed_actor'], 'system:') ? \CW\Ui\Words::RECORD['by_cw'] : $r['removed_actor']))],
            $this->db->all('SELECT p.*, s.code, s.name, c.name AS shop, a.display_name AS added_name, r.display_name AS removed_name FROM item_owner p '
                . 'JOIN sku s ON s.id = p.sku_id JOIN channel c ON c.id = p.channel_id LEFT JOIN staff_user a ON a.id = p.added_by LEFT JOIN staff_user r ON r.id = p.removed_by '
                . 'ORDER BY COALESCE(p.to_at, p.added_at) DESC, p.id DESC LIMIT ' . max(1, min(1000, $limit))));
    }

    // ---- changing ---------------------------------------------------------------------------------------------------------------

    /**
     * Marks the item $code as the setting's shop's own from now (a person), or from $fromAt (a system caller only: the go-live kit; a UTC
     * moment not in the future and after the item's last period). With $reason (3-500 characters). Refused: 422 unknown_item, 409
     * merged_item, 409 no_own_items_shop (the setting names no shop), 409 own_item_already (it is a shop's own now), 409
     * own_item_other_shop (another shop's website sells it: detail.shops), 409 own_item_overlap, 403 backdate_system_only, 400 bad_from.
     *
     * @return array{period_id: int, sku_id: int, code: string, channel_id: int, from_at: string}
     */
    public function add(Caller $caller, string $code, string $reason, ?string $fromAt = null): array
    {
        $reason = ConfigHistory::reason($reason);
        $code = strtoupper(trim($code));
        if ($fromAt !== null && ($caller->staffUserId !== null || $caller->isChannel())) {
            throw new CwException('backdate_system_only', 'a person marks an item from now; an earlier start is the go-live tool\'s', 403);
        }
        if ($fromAt !== null) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', strlen($fromAt) === 19 ? $fromAt . '.000000' : $fromAt, Clock::utc());
            if ($d === false) {
                throw new CwException('bad_from', 'the start is a UTC moment like 2026-11-16 09:00:00, not in the future', 400, ['field' => 'from']);
            }
            $fromAt = Clock::db($d);
        }
        $r = $this->db->transaction(function (Db $db) use ($caller, $code, $reason, $fromAt): array {
            ConfigHistory::authorise($db, $caller);
            // The setting's row FOR SHARE to the end of this transaction (the final review of 2d13f32, M17): a change of the shop being written is
            // waited for, and none can start (it counts the open marks under that row, Settings::checkRule) before this mark commits.
            $db->one('SELECT setting_key FROM app_setting WHERE setting_key = ? FOR SHARE', [self::SETTING]);
            $shop = self::shop($db) ?? throw new CwException('no_own_items_shop', 'the setting "' . self::SETTING . '" names no shop', 409);
            $sku = $db->one('SELECT id, code, merged_into_sku_id FROM sku WHERE code = ? FOR UPDATE', [$code])
                ?? throw new CwException('unknown_item', 'there is no item ' . mb_substr($code, 0, 16), 422, ['field' => 'code']);
            // The period's time read only now, under the item's row (the Codex review of bd9104d, M11): a sale worked out meanwhile reads the
            // periods under the same row (OrderSnapshots::lockItems), so it is either before this mark (its paid time is earlier) or sees it.
            $now = Clock::db(($this->clock)());
            if ($fromAt !== null && $fromAt > $now) {
                throw new CwException('bad_from', 'the start is a UTC moment like 2026-11-16 09:00:00, not in the future', 400, ['field' => 'from']);
            }
            $id = (int) $sku['id'];
            if ($sku['merged_into_sku_id'] !== null) {
                throw new CwException('merged_item', "{$sku['code']} was merged into another item: mark that one", 409, ['field' => 'code']);
            }
            $open = $db->one('SELECT id, channel_id FROM item_owner WHERE sku_id = ? AND to_at IS NULL', [$id]);
            if ($open !== null) {
                throw new CwException('own_item_already', "{$sku['code']} is already marked as a shop's own item", 409, ['field' => 'code']);
            }
            $shops = array_map('strval', $db->column("SELECT DISTINCT c.name FROM channel_listing l JOIN channel c ON c.id = l.channel_id WHERE l.sku_id = ? "
                . "AND l.status IN ('mapped', 'quarantined') AND l.channel_id <> ? ORDER BY c.name", [$id, $shop['id']]));
            if ($shops !== []) {
                throw new CwException('own_item_other_shop', "{$sku['code']} is sold on " . implode(', ', $shops) . ': only an item sold on ' . $shop['name']
                    . ' alone can be its own item', 409, ['field' => 'code', 'shops' => $shops]);
            }
            $from = $fromAt ?? $now;
            $last = $db->value('SELECT MAX(to_at) FROM item_owner WHERE sku_id = ?', [$id]);
            if ($last !== null && (string) $last > $from) {
                throw new CwException('own_item_overlap', "{$sku['code']} was a shop's own item until " . substr((string) $last, 0, 19) . ': a new period starts after it', 409,
                    ['field' => 'from']);
            }
            $pid = $db->insert('INSERT INTO item_owner (sku_id, channel_id, from_at, add_reason, added_by, added_actor, added_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $shop['id'], $from, $reason, $caller->staffUserId, $caller->actor, $now]);
            Audit::write($db, $caller, 'item_owner.add', 'sku', (string) $id, null, ['code' => $sku['code'], 'shop' => $shop['code'], 'from_at' => $from,
                'period' => $pid, 'reason' => $reason]);
            return ['period_id' => $pid, 'sku_id' => $id, 'code' => (string) $sku['code'], 'channel_id' => $shop['id'], 'from_at' => $from];
        });
        self::refreshReorder($this->db, [$r['sku_id']]);
        if ($fromAt === null) {
            // a mark from now (a backdated one is the go-live tool's, which refreshes every sale of its items itself)
            self::reconcileSales($this->db, (int) $r['sku_id'], (string) $r['from_at']);
        }
        return $r;
    }

    /**
     * Ends the item's period now (it is ours again from this moment: sales paid before stay the shop's own), with $reason. Refused: 422
     * unknown_item, 409 not_own_item.
     *
     * @return array{period_id: int, sku_id: int, code: string, to_at: string}
     */
    public function remove(Caller $caller, string $code, string $reason): array
    {
        $reason = ConfigHistory::reason($reason);
        $code = strtoupper(trim($code));
        $r = $this->db->transaction(function (Db $db) use ($caller, $code, $reason): array {
            ConfigHistory::authorise($db, $caller);
            $sku = $db->one('SELECT id, code FROM sku WHERE code = ? FOR UPDATE', [$code])
                ?? throw new CwException('unknown_item', 'there is no item ' . mb_substr($code, 0, 16), 422, ['field' => 'code']);
            $now = Clock::db(($this->clock)()); // under the item's row, as add() (M11)
            $id = (int) $sku['id'];
            $open = $db->one('SELECT id, from_at FROM item_owner WHERE sku_id = ? AND to_at IS NULL', [$id])
                ?? throw new CwException('not_own_item', "{$sku['code']} is not marked as a shop's own item", 409, ['field' => 'code']);
            if ((string) $open['from_at'] >= $now) {
                throw new CwException('own_item_too_new', "{$sku['code']} was marked a moment ago: try again in a second", 409);
            }
            $db->exec('UPDATE item_owner SET to_at = ?, remove_reason = ?, removed_by = ?, removed_actor = ? WHERE id = ? AND to_at IS NULL',
                [$now, $reason, $caller->staffUserId, $caller->actor, (int) $open['id']]);
            Audit::write($db, $caller, 'item_owner.remove', 'sku', (string) $id, null, ['code' => $sku['code'], 'period' => (int) $open['id'], 'to_at' => $now,
                'reason' => $reason]);
            return ['period_id' => (int) $open['id'], 'sku_id' => $id, 'code' => (string) $sku['code'], 'to_at' => $now];
        });
        self::refreshReorder($this->db, [$r['sku_id']]);
        self::reconcileSales($this->db, (int) $r['sku_id'], (string) $r['to_at']);
        return $r;
    }

    /** At most this many orders are put right after one mark or removal (more: item_owner_refresh does it; the nightly O3 says which). */
    public const RECONCILE_MAX_ORDERS = 200;

    /**
     * The sales of the item already worked out whose paid time is at or after $at (a mark's start, a removal's end), after that change's commit
     * and on its own (the final review of 2d13f32, M16): a shop's clock may stamp a sale up to Clock::MAX_AHEAD_SEC ahead of CW's, so a sale
     * worked out just before the mark committed can be paid "after" its start. Each order's owners and own figures written again from its stored
     * lines (OrderSnapshots::reown: no cost moves). Skipped inside a caller's transaction (the go-live tool, which refreshes every sale of its
     * items itself) and never fails the change: what is left the nightly O3 reports and bin/item_owner_refresh.php puts right (docs/ops.md).
     */
    public static function reconcileSales(Db $db, int $sku, string $at): void
    {
        if ($db->inTransaction()) {
            return;
        }
        try {
            $orders = $db->all('SELECT DISTINCT o.channel_id, o.order_ref FROM sale_line l JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
                . 'WHERE l.sku_id = ? AND o.paid_at >= ? ORDER BY o.channel_id, o.order_ref LIMIT ' . self::RECONCILE_MAX_ORDERS, [$sku, $at]);
            $service = new \CW\Profit\OrderSnapshots($db);
            foreach ($orders as $o) {
                try {
                    $service->reown((int) $o['channel_id'], (string) $o['order_ref']);
                } catch (\Throwable) {
                    // another money writer holds it: its own work reads the periods again (apply()), or the refresh does
                }
            }
        } catch (\Throwable) {
            // left to item_owner_refresh / the nightly O3
        }
    }

    /**
     * The go-live kit's step for a bill made final BEFORE its items were marked (Cloud Tech's SINV-000627; docs/decisions.md VB7): the bill
     * becomes the shop's own bill when EVERY goods line's item was that one shop's own at the bill's posting time (the periods, never a
     * choice); otherwise refused (409 bill_not_own: none or another of its items is, or two shops'). Under the bill's row and its items' rows.
     * Its write-once posting check (V2) does not cover this column. Audit item_owner.bill. $apply false: what it would do, nothing written.
     *
     * The Codex review of bd9104d: (M16) with $allowedSkus / $expectLines (the kit: the 81 items, the 8 reviewed goods lines) the bill's goods
     * lines are checked under its row first: exactly $expectLines goods lines, each an item of $allowedSkus, else 409 bill_not_pinned, nothing
     * written; (M10) its credit notes already final go with it (a credit note is always its bill's owner: SupplierInvoiceHandler::ownerOf),
     * each audited.
     *
     * @param list<int>|null $allowedSkus
     * @return array{document_id: int, number: string, channel_id: int, lines: int, changed: bool, credits: int}
     */
    public function markBill(Caller $caller, string $number, bool $apply, ?array $allowedSkus = null, ?int $expectLines = null): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $number, $apply, $allowedSkus, $expectLines): array {
            ConfigHistory::authorise($db, $caller);
            $d = $db->one("SELECT d.id, d.number, d.posted_at, d.status, si.item_owner FROM document d JOIN supplier_invoice si ON si.document_id = d.id "
                . "WHERE d.number = ? AND d.doc_type = 'SINV' AND si.kind = 'invoice' FOR UPDATE", [$number])
                ?? throw new CwException('unknown_bill', 'there is no supplier invoice ' . mb_substr($number, 0, 32), 404);
            if ($d['status'] !== 'posted' || $d['posted_at'] === null) {
                throw new CwException('bill_not_final', "{$d['number']} is not final: its owner is decided when it is made final", 409);
            }
            $goods = array_map(static fn ($v): ?int => $v === null ? null : (int) $v, $db->column("SELECT dl.sku_id FROM sinv_line sl JOIN document_line dl "
                . "ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no WHERE sl.document_id = ? AND sl.kind = 'goods' ORDER BY sl.line_no", [(int) $d['id']]));
            if ($expectLines !== null && count($goods) !== $expectLines) {
                throw new CwException('bill_not_pinned', "{$d['number']} has " . count($goods) . " goods lines, not the {$expectLines} checked: nothing marked", 409);
            }
            if ($allowedSkus !== null) {
                $allowed = array_fill_keys(array_map('intval', $allowedSkus), true);
                foreach ($goods as $g) {
                    if ($g === null || !isset($allowed[$g])) {
                        throw new CwException('bill_not_pinned', "a goods line of {$d['number']} is not one of the items listed: nothing marked", 409);
                    }
                }
            }
            $skus = array_values(array_unique(array_filter($goods, static fn (?int $g): bool => $g !== null)));
            sort($skus);
            foreach (array_chunk($skus, 500) as $chunk) {
                $db->all('SELECT id FROM sku WHERE id IN (' . self::marks($chunk) . ') ORDER BY id FOR SHARE', $chunk);
            }
            $periods = self::periods($db, $skus);
            $owners = [];
            foreach ($skus as $s) {
                $owners[self::ownerAt($periods, $s, (string) $d['posted_at']) ?? 0] = true;
            }
            if ($skus === [] || count($owners) !== 1 || isset($owners[0])) {
                throw new CwException('bill_not_own', "{$d['number']}'s goods were not all one shop's own items when it was made final", 409);
            }
            $ch = (int) array_key_first($owners);
            $changed = $d['item_owner'] === null || (int) $d['item_owner'] !== $ch;
            // its credit notes made final (a draft takes its bill's owner when it is made final)
            $credits = $db->all("SELECT si.document_id, si.item_owner, d.number FROM supplier_invoice si JOIN document d ON d.id = si.document_id "
                . "WHERE si.credits_document_id = ? AND si.kind = 'credit_note' AND d.status = 'posted' ORDER BY si.document_id FOR UPDATE", [(int) $d['id']]);
            $credits = array_values(array_filter($credits, static fn (array $c): bool => $c['item_owner'] === null || (int) $c['item_owner'] !== $ch));
            if ($apply && $changed) {
                $db->exec('UPDATE supplier_invoice SET item_owner = ? WHERE document_id = ?', [$ch, (int) $d['id']]);
                Audit::write($db, $caller, 'item_owner.bill', 'document', (string) $d['id'], null, ['number' => $d['number'], 'channel' => $ch, 'lines' => count($goods)]);
            }
            if ($apply) {
                foreach ($credits as $c) {
                    $db->exec('UPDATE supplier_invoice SET item_owner = ? WHERE document_id = ?', [$ch, (int) $c['document_id']]);
                    Audit::write($db, $caller, 'item_owner.bill', 'document', (string) $c['document_id'], null, ['number' => $c['number'], 'channel' => $ch,
                        'credits' => $d['number']]);
                }
            }
            return ['document_id' => (int) $d['id'], 'number' => (string) $d['number'], 'channel_id' => $ch, 'lines' => count($goods), 'changed' => $changed,
                'credits' => count($credits)];
        });
    }

    /**
     * Every final bill holding any of $skus made at or after $fromAt (the final review of 2d13f32, H2: a backdated mark covers bills made since
     * its start; until now only the pinned bill was marked and a second one stayed payable by us), read under the bills' rows and the items'
     * rows (the caller's transaction holds the items' rows FOR UPDATE: the go-live tool): one whose goods were all one shop's own items at its
     * posting time becomes that shop's own bill, its credit notes made final with it (markBill()); one that mixes them with ours is refused
     * (409 bills_mixed, naming every such bill: nothing of the caller's transaction is written). A copy of an ERPNext invoice is none's.
     *
     * @param list<int> $skuIds
     * @return list<string> the bills made the shop's own now
     */
    public function reconcileBills(Caller $caller, array $skuIds, string $fromAt): array
    {
        if ($skuIds === []) {
            return [];
        }
        $from = strlen($fromAt) === 19 ? $fromAt . '.000000' : $fromAt;
        return $this->db->transaction(function (Db $db) use ($caller, $skuIds, $from): array {
            $numbers = array_map('strval', $db->column("SELECT DISTINCT d.number FROM document d JOIN supplier_invoice si ON si.document_id = d.id "
                . "JOIN document_line dl ON dl.document_id = d.id JOIN sinv_line sl ON sl.document_id = dl.document_id AND sl.line_no = dl.line_no AND sl.kind = 'goods' "
                . "WHERE d.doc_type = 'SINV' AND d.status = 'posted' AND si.kind = 'invoice' AND si.item_owner IS NULL AND COALESCE(si.origin, 'cw') <> 'erpnext' "
                . 'AND d.posted_at >= ? AND dl.sku_id IN (' . self::marks($skuIds) . ') ORDER BY d.number', [$from, ...$skuIds]));
            $marked = [];
            $mixed = [];
            foreach ($numbers as $n) {
                try {
                    $r = $this->markBill($caller, $n, true);
                    if ($r['changed']) {
                        $marked[] = $n;
                    }
                } catch (CwException $e) {
                    if ($e->errorCode !== 'bill_not_own') {
                        throw $e;
                    }
                    $mixed[] = $n;
                }
            }
            if ($mixed !== []) {
                throw new CwException('bills_mixed', 'these final bills hold these items together with our goods (or goods that were not the shop\'s own when they were '
                    . 'made final): ' . implode(', ', $mixed) . '. A bill is either the shop\'s own or ours: decide each before the mark (nothing written)', 409,
                    ['bills' => $mixed]);
            }
            return $marked;
        });
    }

    /**
     * The item's stored reorder line worked out again after its mark changed (VB16: a shop's own item is never suggested), after the mark's
     * commit and on its own. A failure here never undoes the mark: the reorder job of every night works every line out again.
     *
     * @param list<int> $skus
     */
    public static function refreshReorder(Db $db, array $skus): void
    {
        if ($db->inTransaction()) {
            return; // a caller's own transaction (a tool marking many): it refreshes once after its commit
        }
        try {
            (new \CW\Reorder\ReorderRefresh($db, new \CW\PurchaseOrders\PurchaseOrders($db, new \CW\Documents\Documents($db, []))))->items($skus);
        } catch (\Throwable) {
            // left to the nightly refresh (ReorderJobs): the list says "never suggested" from then on
        }
    }

    /** @param array<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
