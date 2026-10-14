<?php

declare(strict_types=1);

namespace CW\Queues;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Movements;
use CW\OpResult;
use CW\Relay\RelayIntake;
use CW\Stock;

/**
 * Goods-in suspense (goods_in_suspense; plan §9, D17; docs/decisions.md PF6): the lines a store relayed from ERPNext that CW could
 * not place on an item (a variant CW does not know, one not matched, an ERPNext item with no override). Approvals › From the stores ›
 * Lines to place.
 *
 *   pick()     a person names the item (its CW code) and how many of its units one unit of the line is (1 unless the document counts
 *              packs). A delivery, a supplier return or an ERPNext sale is then booked through the movement route
 *              (Movements::bookInTransaction: the same checks and booking as POST /v1/movements, on the store's selling warehouse,
 *              with the ERPNext document's name on the ledger) and the row is resolved, in ONE transaction under the key
 *              queue:suspense:<id> (a second click replays the first answer; the row's status refuses any other answer, 409). A
 *              relayed CORRECTION (movement type erp_reconciliation) books nothing: picking its item opens it in the corrections
 *              queue, where it is confirmed or dismissed like any other.
 *   dismiss()  nothing is booked (booked another way, not ours, a test): the row is dismissed with the note.
 *
 * Who: queues.suspense (the purchasing desk and manager, the stock controller), re-read inside the transaction. Locks: the row, then
 * the item's balance (Stock::lock), then the row's update naming the item (its foreign key reads the item row: balances before the
 * item, Stock::setPolicy's order), then the booking's own lock (the same balance, held) and the feed clock last.
 */
final class Suspense
{
    public const TABLE = 'goods_in_suspense';
    public const PERM = 'queues.suspense';
    /** The kinds a person may book from here (the relay's stock kinds), and the relayed correction that opens a correction instead. */
    public const STOCK_KINDS = ['goods_in', 'supplier_return', 'erp_sale'];
    public const UNITS_MAX = 10000;

    public function __construct(private readonly Db $db)
    {
    }

    public function openCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM goods_in_suspense WHERE status = 'open'");
    }

    /**
     * The list: open rows oldest first, or every row of a status (newest settled first), at most $limit.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $status = 'open', int $limit = QueueRules::PAGE + 1): array
    {
        $order = $status === 'open' ? 'g.created_at, g.id' : 'g.id DESC';
        $rows = $this->db->all(
            'SELECT g.id, g.source, g.channel_id, g.doc_type, g.doc_ref, g.line_index, g.movement_type, g.qty, g.external_variant_id, g.erp_item_code, '
            . 'g.barcode, g.description, g.reason, CAST(g.status AS CHAR) AS status, g.resolved_sku_id, g.resolved_by, g.resolved_at, g.note, g.created_at, '
            . 's.code AS sku_code, s.name AS sku_name FROM goods_in_suspense g LEFT JOIN sku s ON s.id = g.resolved_sku_id'
            . ($status === null ? '' : ' WHERE g.status = ?') . " ORDER BY {$order} LIMIT " . max(1, min(500, $limit)),
            $status === null ? [] : [$status],
        );
        $names = QueueRules::names($this->db, array_column($rows, 'resolved_by'));
        $stores = QueueRules::stores($this->db);
        foreach ($rows as &$r) {
            $r['resolved_by_name'] = $r['resolved_by'] === null ? null : ($names[(int) $r['resolved_by']] ?? null);
            $r['store'] = $r['channel_id'] === null ? null : ($stores[(int) $r['channel_id']] ?? null);
        }
        return $rows;
    }

    /**
     * Places a line on an item (class docblock). $itemCode: the item's CW code; $unitsPer: the item's units in one unit of the line.
     *
     * @return array{result: string, id: int, sku_code: string, units: int, review_id?: int, replayed: bool}
     */
    public function pick(Caller $caller, int $id, string $itemCode, int $unitsPer, string $note): array
    {
        $note = QueueRules::note($note);
        $itemCode = strtoupper(trim($itemCode));
        if ($itemCode === '' || strlen($itemCode) > 16) {
            throw new CwException('bad_item', 'give the item\'s code, like CW-000123', 400, ['field' => 'item']);
        }
        if ($unitsPer < 1 || $unitsPer > self::UNITS_MAX) {
            throw new CwException('bad_units', 'one unit of the line is 1 to ' . self::UNITS_MAX . ' of the item\'s units', 400, ['field' => 'units']);
        }
        $kind = $this->db->value('SELECT movement_type FROM goods_in_suspense WHERE id = ?', [$id])
            ?? throw new CwException('unknown_queue_row', 'there is no such row in this queue', 404);
        if ((string) $kind === RelayIntake::KIND_RECONCILIATION) {
            return $this->pickCorrection($caller, $id, $itemCode, $unitsPer, $note);
        }
        if (!in_array((string) $kind, self::STOCK_KINDS, true)) {
            throw new CwException('not_bookable', "a {$kind} line is not booked from goods-in suspense", 422);
        }
        // Who first (and again inside): a person without the job hears that, not what the key stored for someone else's answer.
        QueueRules::authorise($this->db, $caller, self::PERM);
        $key = 'queue:suspense:' . $id;
        $r = (new Idempotency($this->db))->run($caller, $key, 'queue.suspense.book', '/ui/queues/suspense/' . $id,
            ['id' => $id, 'item' => $itemCode, 'units' => $unitsPer, 'note' => $note], self::TABLE, (string) $id,
            function (Db $db) use ($caller, $id, $itemCode, $unitsPer, $note, $key): OpResult {
                QueueRules::authorise($db, $caller, self::PERM);
                $row = QueueRules::lockOpen($db, self::TABLE, $id);
                $sku = $this->item($db, $itemCode);
                $wh = $this->warehouse($db, $row['channel_id'] === null ? null : (int) $row['channel_id']);
                $units = abs((int) $row['qty']) * $unitsPer;
                // The item's balance before the row's update names the item (lock order: balances, then the item row; Stock::setPolicy's).
                (new Stock($db))->lock([[$wh['id'], $sku['id']]]);
                $db->exec('UPDATE goods_in_suspense SET resolved_sku_id = ? WHERE id = ?', [$sku['id'], $id]);
                QueueRules::settle($db, $caller, self::TABLE, $id, 'resolved', null, $note);
                $lines = (new Movements($db))->bookInTransaction($caller, ['type' => (string) $row['movement_type'], 'doc_ref' => (string) $row['doc_ref'],
                    'doc_type' => (string) $row['doc_type'], 'warehouse' => $wh['code'], 'note' => mb_substr('suspense #' . $id, 0, 255),
                    'lines' => [['sku_id' => $sku['id'], 'qty' => $units, 'line_index' => (int) $row['line_index']]]], $key);
                $line = $lines[0];
                Audit::write($db, $caller, 'goods_in_suspense.book', self::TABLE, (string) $id, $key, ['type' => $row['movement_type'], 'doc_ref' => $row['doc_ref'],
                    'line_index' => (int) $row['line_index'], 'sku_code' => $sku['code'], 'units_per' => $unitsPer, 'delta' => $line['delta'], 'note' => $note]);
                return OpResult::of(200, ['result' => 'booked', 'id' => $id, 'sku_code' => $sku['code'], 'units' => (int) $line['delta'], 'on_hand' => (int) $line['on_hand']]);
            });
        QueueRules::refused($r);
        return ['result' => (string) $r->body['result'], 'id' => $id, 'sku_code' => (string) $r->body['sku_code'], 'units' => (int) $r->body['units'], 'replayed' => $r->replayed];
    }

    /** Dismisses a line: nothing is booked. */
    public function dismiss(Caller $caller, int $id, string $note): void
    {
        $note = QueueRules::note($note);
        $this->db->transaction(function (Db $db) use ($caller, $id, $note): void {
            QueueRules::authorise($db, $caller, self::PERM);
            $row = QueueRules::lockOpen($db, self::TABLE, $id);
            QueueRules::settle($db, $caller, self::TABLE, $id, 'dismissed', null, $note);
            Audit::write($db, $caller, 'goods_in_suspense.dismiss', self::TABLE, (string) $id, null, ['type' => $row['movement_type'], 'doc_ref' => $row['doc_ref'],
                'line_index' => (int) $row['line_index'], 'note' => $note]);
        });
    }

    /** @return array{result: string, id: int, sku_code: string, units: int, review_id: int, replayed: bool} */
    private function pickCorrection(Caller $caller, int $id, string $itemCode, int $unitsPer, string $note): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $itemCode, $unitsPer, $note): array {
            QueueRules::authorise($db, $caller, self::PERM);
            $row = QueueRules::lockOpen($db, self::TABLE, $id);
            $sku = $this->item($db, $itemCode);
            $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
            $payload = is_array($payload) ? $payload : [];
            $wh = isset($payload['warehouse_id']) && is_int($payload['warehouse_id'])
                ? $payload['warehouse_id'] : $this->warehouse($db, $row['channel_id'] === null ? null : (int) $row['channel_id'])['id'];
            $units = (int) $row['qty'] * $unitsPer;
            $db->exec('UPDATE goods_in_suspense SET resolved_sku_id = ? WHERE id = ?', [$sku['id'], $id]);
            QueueRules::settle($db, $caller, self::TABLE, $id, 'resolved', null, $note);
            $review = RelayIntake::openCorrection($db, $caller, $wh, $sku['id'], $units, (string) $row['doc_ref'], (int) $row['line_index'], [
                'channel_id' => $row['channel_id'] === null ? null : (int) $row['channel_id'], 'qty' => (int) $row['qty'], 'u' => $unitsPer,
                'variant_id' => $row['external_variant_id'], 'erp_item_code' => $row['erp_item_code'], 'description' => $row['description'],
                'posted_at' => is_string($payload['posted_at'] ?? null) ? $payload['posted_at'] : (string) $row['created_at'], 'from_suspense' => $id,
            ]);
            Audit::write($db, $caller, 'goods_in_suspense.correction', self::TABLE, (string) $id, null, ['doc_ref' => $row['doc_ref'],
                'line_index' => (int) $row['line_index'], 'sku_code' => $sku['code'], 'units' => $units, 'review_id' => $review, 'note' => $note]);
            return ['result' => 'queued', 'id' => $id, 'sku_code' => $sku['code'], 'units' => $units, 'review_id' => $review, 'replayed' => false];
        });
    }

    /** @return array{id: int, code: string} */
    private function item(Db $db, string $code): array
    {
        $sku = $db->one('SELECT id, code, merged_into_sku_id FROM sku WHERE code = ?', [$code])
            ?? throw new CwException('unknown_item', 'there is no item ' . mb_substr($code, 0, 16), 422, ['field' => 'item']);
        if ($sku['merged_into_sku_id'] !== null) {
            throw new CwException('merged_item', "{$sku['code']} was merged into another item: use that one", 409, ['field' => 'item']);
        }
        return ['id' => (int) $sku['id'], 'code' => (string) $sku['code']];
    }

    /** The relaying store's selling warehouse, else MAIN. @return array{id: int, code: string} */
    private function warehouse(Db $db, ?int $channelId): array
    {
        $w = $channelId === null ? null : $db->one('SELECT w.id, w.code FROM channel_warehouse cw JOIN warehouse w ON w.id = cw.warehouse_id '
            . 'WHERE cw.channel_id = ? AND cw.is_sellable = 1', [$channelId]);
        $w ??= $db->one("SELECT id, code FROM warehouse WHERE code = 'MAIN'") ?? throw new \RuntimeException('no MAIN warehouse');
        return ['id' => (int) $w['id'], 'code' => (string) $w['code']];
    }
}
