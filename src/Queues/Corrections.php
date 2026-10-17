<?php

declare(strict_types=1);

namespace CW\Queues;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Movements;
use CW\OpResult;
use CW\Relay\RelayIntake;
use CW\Settings;
use CW\Stock;

/**
 * ERPNext's corrections ("set to this number", stock_reconcillation.php), relayed by a store into the count-review queue (count_review,
 * source erp_reconciliation; plan §9; docs/decisions.md PF7). Approvals › From the stores › ERPNext corrections.
 *
 *   effect()   what confirming would do, worked out from the stock ledger AT THE MOMENT ERPNext set the number (the relayed posted_at,
 *              else the moment CW received it): what CW held on the shelf then (the on_hand balance after the last row booked by then),
 *              the units it kept for paid orders not sent yet (allocated, likewise), and the shelf figure ERPNext's number stands for:
 *              the number in CW's units, plus those paid units when relay.correction_is_for_sale is on (the go-live plan's rule: on the
 *              website the corrected number is the figure customers can buy; a shelf count would leave the paid units out). The
 *              difference is what confirming books.
 *   confirm()  books that difference NOW as an adjustment through the movement route (Movements::bookInTransaction; the ERPNext
 *              document's name on the ledger), so the stock that moved since the correction (sales sent, deliveries) is kept on top,
 *              and settles the row (resolved, `booked`, or `no_difference` when there is nothing to book), in ONE transaction under the
 *              key queue:correction:<id>. It is NOT a count: the item's count time (stock_balance.counted_at, the count plan's) and its
 *              stock rule are left alone ("ERPNext's pushes never become counts automatically"; the item is counted on a count sheet).
 *   dismiss()  nothing is booked; the row is dismissed with the note.
 *
 * Who: queues.corrections (the stock controller), re-read inside the transaction. The queue row is locked first, then the booking's
 * balance and the feed clock.
 */
final class Corrections
{
    public const TABLE = 'count_review';
    public const PERM = 'queues.corrections';
    public const SOURCE = RelayIntake::SOURCE_RECONCILIATION;
    /** The Idempotency-Key of a confirmation (and of the adjustment it books): KEY . <count_review id> (the rollback file knows them by it). */
    public const KEY = 'queue:correction:';

    public function __construct(private readonly Db $db)
    {
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM count_review FORCE INDEX (ix_count_review_open) WHERE status = 'open' AND source = ?", [self::SOURCE]);
    }

    /**
     * The list: open rows oldest first (each with its effect()), or every row of a status, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $status = 'open', int $limit = QueueRules::PAGE + 1): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->all(
            'SELECT STRAIGHT_JOIN r.id, r.warehouse_id, w.code AS warehouse_code, w.name AS warehouse, r.sku_id, s.code AS sku_code, s.name AS sku_name, r.proposed_qty, r.ref, '
            . 'CAST(r.detail AS CHAR) AS detail, CAST(r.status AS CHAR) AS status, r.resolution, r.resolved_by, r.resolved_at, r.note, r.created_at '
            . 'FROM count_review r' . ($status === null ? '' : ' FORCE INDEX (ix_count_review_open)') . ' JOIN sku s ON s.id = r.sku_id JOIN warehouse w ON w.id = r.warehouse_id '
            . 'WHERE r.source = ?' . ($status === null ? '' : ' AND r.status = ?') . ' ORDER BY r.id' . ($status === 'open' ? '' : ' DESC') . " LIMIT {$limit}",
            $status === null ? [self::SOURCE] : [self::SOURCE, $status],
        );
        $names = QueueRules::names($this->db, array_column($rows, 'resolved_by'));
        $stores = QueueRules::stores($this->db);
        $settings = new Settings($this->db);
        foreach ($rows as &$r) {
            $d = json_decode((string) $r['detail'], true);
            $r['detail'] = is_array($d) ? $d : [];
            $r['at'] = self::at($r);
            $r['store'] = isset($r['detail']['channel_id']) ? ($stores[(int) $r['detail']['channel_id']] ?? null) : null;
            $r['resolved_by_name'] = $r['resolved_by'] === null ? null : ($names[(int) $r['resolved_by']] ?? null);
            $r['effect'] = $r['status'] === 'open' ? $this->effect((int) $r['warehouse_id'], (int) $r['sku_id'], (int) $r['proposed_qty'], $r['at'], $settings,
                (int) $r['id']) : null;
        }
        return $rows;
    }

    /**
     * What confirming books (class docblock): shelf and allocated as the ledger had them at $at, the shelf figure the correction stands
     * for, and the difference.
     *
     * Review finding 1 (Codex, 14 Oct 2026): corrections of one item confirmed late must not compound. What CW held at the moment
     * also counts the corrections of the same item and place made at or before it but booked after it (confirmed later): without them
     * 20 -> 10 -> 8, both waiting, booked -10 and -12. `carried` is their sum; `newer` says a correction made after this one was confirmed
     * already (it set the figure: this one is out of date and is refused, 409 newer_correction).
     *
     * @return array{shelf: int, allocated: int, target: int, delta: int, for_sale: bool, carried: int, newer: bool}
     */
    public function effect(int $warehouseId, int $skuId, int $units, string $at, ?Settings $settings = null, int $self = 0): array
    {
        $settings ??= new Settings($this->db);
        $forSale = !$settings->has('relay.correction_is_for_sale') || $settings->get('relay.correction_is_for_sale') === true;
        // The last ledger row booked by then (the created_at index, one row), then each bucket's last row of this balance at or before it
        // (the balance's (warehouse, item, id) index, read backwards from there).
        $last = $this->db->value('SELECT id FROM stock_ledger WHERE created_at <= ? ORDER BY created_at DESC, id DESC LIMIT 1', [$at]);
        $bucket = fn (string $b): int => $last === null ? 0 : (int) ($this->db->value(
            'SELECT balance_after FROM stock_ledger FORCE INDEX (ix_stock_ledger_balance) WHERE warehouse_id = ? AND sku_id = ? AND id <= ? AND bucket = ? ORDER BY id DESC LIMIT 1',
            [$warehouseId, $skuId, (int) $last, $b],
        ) ?? 0);
        $carried = 0;
        $newer = false;
        foreach ($this->confirmedOf($warehouseId, $skuId, $self) as $c) {
            if ($c['at'] > $at) {
                $newer = true;
            } elseif ($c['booked_at'] > $at) {
                $carried += $c['delta'];
            }
        }
        $shelf = $bucket('on_hand') + $carried;
        $allocated = $bucket('allocated');
        $target = $units + ($forSale ? max(0, $allocated) : 0);
        return ['shelf' => $shelf, 'allocated' => $allocated, 'target' => $target, 'delta' => $target - $shelf, 'for_sale' => $forSale, 'carried' => $carried,
            'newer' => $newer];
    }

    /**
     * The confirmed corrections of one item at one place (not $except): when each was made, when it was booked and what it booked
     * (detail.booked, written by confirm()).
     *
     * @return list<array{at: string, booked_at: string, delta: int}>
     */
    private function confirmedOf(int $warehouseId, int $skuId, int $except): array
    {
        $out = [];
        foreach ($this->db->all("SELECT id, CAST(detail AS CHAR) AS detail, created_at, resolved_at FROM count_review WHERE sku_id = ? AND warehouse_id = ? AND source = ? "
            . "AND status = 'resolved' AND id <> ?", [$skuId, $warehouseId, self::SOURCE, $except]) as $r) {
            $d = json_decode((string) $r['detail'], true);
            $d = is_array($d) ? $d : [];
            $booked = is_array($d['booked'] ?? null) ? $d['booked'] : [];
            $out[] = ['at' => self::at(['detail' => $d, 'created_at' => $r['created_at']]), 'booked_at' => (string) ($booked['at'] ?? $r['resolved_at']),
                'delta' => (int) ($booked['delta'] ?? 0)];
        }
        return $out;
    }

    /** @return array{result: string, delta: int, replayed: bool} */
    public function confirm(Caller $caller, int $id, string $note): array
    {
        $note = QueueRules::note($note);
        // Who first (and again inside): a person without the job hears that, not what the key stored for someone else's answer.
        QueueRules::authorise($this->db, $caller, self::PERM);
        $key = self::KEY . $id;
        $r = (new Idempotency($this->db))->run($caller, $key, 'queue.correction.confirm', '/ui/queues/corrections/' . $id, ['id' => $id, 'note' => $note],
            self::TABLE, (string) $id, function (Db $db) use ($caller, $id, $note, $key): OpResult {
                QueueRules::authorise($db, $caller, self::PERM);
                $row = QueueRules::lockOpen($db, self::TABLE, $id, 'source = ?', [self::SOURCE]);
                $d = json_decode((string) ($row['detail'] ?? '{}'), true);
                $row['detail'] = is_array($d) ? $d : [];
                $at = self::at($row);
                // The item's balance first: two corrections of one item confirmed at once decide one after the other, each seeing what
                // the other booked (finding 1). bookInTransaction locks the same balance again (held).
                (new Stock($db))->lock([[(int) $row['warehouse_id'], (int) $row['sku_id']]]);
                $countedAt = $db->value('SELECT counted_at FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?',
                    [(int) $row['warehouse_id'], (int) $row['sku_id']]);
                if ($countedAt !== null && (string) $countedAt > $at) {
                    throw new CwException('newer_count', 'a later physical count already set this balance: close the older correction', 409);
                }
                $e = $this->effect((int) $row['warehouse_id'], (int) $row['sku_id'], (int) $row['proposed_qty'], $at, null, $id);
                if ($e['newer']) {
                    throw new CwException('newer_correction', 'a newer ERPNext correction of this item was confirmed already: this one is out of date, close it', 409);
                }
                QueueRules::settle($db, $caller, self::TABLE, $id, 'resolved', $e['delta'] === 0 ? 'no_difference' : 'booked', $note);
                $onHand = null;
                $bookedAt = (string) $db->value('SELECT NOW(6)');
                if ($e['delta'] !== 0) {
                    $wh = (string) $db->value('SELECT code FROM warehouse WHERE id = ?', [(int) $row['warehouse_id']]);
                    $lines = (new Movements($db))->bookInTransaction($caller, ['type' => 'adjustment', 'doc_ref' => (string) $row['ref'], 'warehouse' => $wh,
                        'note' => mb_substr('ERPNext correction, review #' . $id, 0, 255),
                        'lines' => [['sku_id' => (int) $row['sku_id'], 'qty' => $e['delta'], 'line_index' => (int) ($row['detail']['line_index'] ?? 0)]]], $key);
                    $onHand = (int) $lines[0]['on_hand'];
                    // When the adjustment entered the ledger (its own row, under the balance's lock): what a later correction compares with.
                    $bookedAt = (string) $db->value("SELECT created_at FROM stock_ledger FORCE INDEX (ix_stock_ledger_balance) WHERE warehouse_id = ? AND sku_id = ? "
                        . "AND bucket = 'on_hand' ORDER BY id DESC LIMIT 1", [(int) $row['warehouse_id'], (int) $row['sku_id']]);
                }
                $db->exec("UPDATE count_review SET detail = JSON_SET(COALESCE(detail, JSON_OBJECT()), '$.booked', JSON_OBJECT('delta', ?, 'at', ?)) WHERE id = ?",
                    [$e['delta'], $bookedAt, $id]);
                Audit::write($db, $caller, 'count_review.confirm', self::TABLE, (string) $id, $key, ['ref' => $row['ref'], 'at' => $at,
                    'units' => (int) $row['proposed_qty'], 'note' => $note] + $e);
                return OpResult::of(200, ['result' => $e['delta'] === 0 ? 'no_difference' : 'booked', 'id' => $id, 'delta' => $e['delta'], 'on_hand' => $onHand]);
            });
        QueueRules::refused($r);
        return ['result' => (string) $r->body['result'], 'delta' => (int) $r->body['delta'], 'replayed' => $r->replayed];
    }

    public function dismiss(Caller $caller, int $id, string $note): void
    {
        $note = QueueRules::note($note);
        $this->db->transaction(function (Db $db) use ($caller, $id, $note): void {
            QueueRules::authorise($db, $caller, self::PERM);
            $row = QueueRules::lockOpen($db, self::TABLE, $id, 'source = ?', [self::SOURCE]);
            QueueRules::settle($db, $caller, self::TABLE, $id, 'dismissed', 'dismissed', $note);
            Audit::write($db, $caller, 'count_review.dismiss', self::TABLE, (string) $id, null, ['ref' => $row['ref'], 'units' => (int) $row['proposed_qty'], 'note' => $note]);
        });
    }

    /** The moment ERPNext set the number: the relayed posted_at, else when CW received it (the database's form). @param array<string, mixed> $row */
    public static function at(array $row): string
    {
        $p = is_array($row['detail'] ?? null) ? ($row['detail']['posted_at'] ?? null) : null;
        if (is_string($p) && $p !== '') {
            return $p;
        }
        return Clock::db(Clock::fromDb((string) $row['created_at']));
    }
}
