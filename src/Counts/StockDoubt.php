<?php

declare(strict_types=1);

namespace CW\Counts;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\OpResult;
use CW\Output\CsvReader;
use CW\Queues\QueueRules;
use CW\Stock;

/**
 * Doubtful stock (0066; docs/decisions.md DS1-): the items of a store whose stock figure is in doubt (CW and the shop differ, CW holds them
 * below zero), each checked on the shelf and answered once, on Inventory › Doubtful stock, a page that shows ONLY those items.
 *
 *   load()     bin/doubt_load.php: a list (readFile(): a CSV of cw_code, shop_qty, cw_qty, reason, sold_30d, note) becomes the store's open
 *              rows, in ONE transaction with one audit row. An item already open is refreshed in place (its figures, why, the list's name and
 *              note), never doubled: under the open row's lock (taken by its id, as the answers take it), and the database's UNIQUE (store,
 *              open item). Every list a row was on is kept (`batches`): an item answered on a list is not opened again by that list (a list
 *              run twice is safe), and an older list never puts its figures back over a newer one; a new list opens it again. An item CW
 *              does not have, or one merged into
 *              another, refuses the whole list (nothing written). plan() is the same reading with nothing written (the dry run).
 *   count()    "Save count": the shelf figure is booked as a stock count of that ONE item at the store's selling warehouse through the
 *              counts engine (CountSheets: a one-line sheet with the note "Doubtful stock #<id>", its quantity, posted: the count record,
 *              its ledger row, its review when the rules ask for one), and the row is cleared with what was counted, what it booked and the
 *              record, all in ONE transaction under the key doubt:<id> (Idempotency: a second press replays the first answer; any other
 *              answer for a row no longer open is refused, 409 doubt_settled). A count the engine leaves out (the item counted meanwhile with
 *              a later time: nothing booked) is refused whole (409 counted_later), never a row cleared with nothing booked. A refusal books
 *              nothing and keeps nothing: the row stays open.
 *   dismiss()  "Not needed": nothing is booked; the row is dismissed with the person's note (QueueRules::note: 3 to 500 characters).
 *
 * Who answers: the people who count stock (CountSheets::mayCount: doc.CNT.post, never admin), checked before, and again inside the
 * transaction once the row is held. Locks: the doubt row first, always by its id (FOR UPDATE; the load too), then, before the one-line
 * sheet names the item, the count's number series and the item's balance at that warehouse (the engine's own order), then the rest of
 * what the counts engine takes (the record, the item).
 * The lists (open(), checked()) read only; open() orders the rows with order() (pure, unit-tested) and pages them PAGE at a time.
 */
final class StockDoubt
{
    public const TABLE = 'stock_doubt';
    public const REASONS = ['differs', 'below_zero', 'other'];
    public const STATUSES = ['open', 'cleared', 'dismissed'];
    /** The columns a list needs (any others are ignored). */
    public const COLUMNS = ['cw_code', 'shop_qty', 'cw_qty', 'reason', 'sold_30d', 'note'];
    /** The Idempotency-Key of a "Save count" (and of its audit row): KEY . <stock_doubt id>. */
    public const KEY = 'doubt:';
    /** Rows a page shows. */
    public const PAGE = 50;
    /** The most rows one list holds (CsvReader's cap). */
    public const MAX_ROWS = 2000;
    public const BATCH_MAX = 64;
    public const NOTE_MAX = 500;
    public const JOB = 'doubt_load';
    /** The note on the count record a "Save count" posts. */
    public const SHEET_NOTE = 'Doubtful stock #%d';

    public function __construct(private readonly Db $db, private readonly ?CountSheets $sheets = null)
    {
    }

    // ------------------------------------------------------------------------------------------
    // Pure rules
    // ------------------------------------------------------------------------------------------

    /**
     * Reads a list: its header must name the six COLUMNS (others are ignored); each row is checked, and the first bad one refuses the whole
     * file (400 bad_file, detail row = its data-row number, column = the column): a CW number (CW-000123, any letters' case), the figures
     * whole numbers (shop_qty and sold_30d may be empty: not known; sold_30d not below 0), why one of REASONS (any case), a note of at most
     * NOTE_MAX characters (control characters become spaces; empty: none). A line break inside any cell, of any column, is refused (a quote
     * left open would take the next rows into it; a note is one line). An item twice is refused. At most MAX_ROWS rows.
     *
     * @return list<array{row: int, cw_code: string, shop_qty: ?int, cw_qty: int, reason: string, sold_30d: ?int, note: ?string}>
     */
    public static function readFile(string $path): array
    {
        $t = CsvReader::table($path, CsvReader::DEFAULT_MAX_BYTES, self::MAX_ROWS);
        $missing = array_values(array_diff(self::COLUMNS, $t['header']));
        if ($missing !== []) {
            throw new CwException('bad_file', 'the list needs the columns ' . implode(', ', self::COLUMNS) . ' (missing: ' . implode(', ', $missing) . ')', 400,
                ['column' => $missing[0]]);
        }
        $out = [];
        $seen = [];
        foreach ($t['rows'] as $n => $r) {
            $bad = static fn (string $column, string $why): CwException => new CwException('bad_file', "row {$n}, {$column}: {$why}", 400, ['row' => $n, 'column' => $column]);
            foreach ($r as $column => $cell) {
                if (preg_match('/[\r\n]/', $cell) === 1) {
                    // A cell opened with a quote and never closed takes the rows after it into itself (Codex rounds 2 and 3), in any column,
                    // one the load ignores too: one line per cell.
                    throw $bad((string) $column, 'a line break inside a cell: is a quote missing?');
                }
            }
            $code = strtoupper(trim($r['cw_code']));
            if (preg_match('/^CW-[0-9]{6,9}$/D', $code) !== 1) {
                throw $bad('cw_code', 'a CW number, like CW-000123');
            }
            if (isset($seen[$code])) {
                throw $bad('cw_code', "{$code} is on the list twice (row {$seen[$code]} too)");
            }
            $seen[$code] = $n;
            $reason = strtolower(trim($r['reason']));
            if (!in_array($reason, self::REASONS, true)) {
                throw $bad('reason', 'one of ' . implode(', ', self::REASONS));
            }
            $note = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $r['note']));
            if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > self::NOTE_MAX) {
                throw $bad('note', 'at most ' . self::NOTE_MAX . ' characters');
            }
            $out[] = ['row' => $n, 'cw_code' => $code, 'shop_qty' => self::figure($r, 'shop_qty', true, true, $bad), 'cw_qty' => (int) self::figure($r, 'cw_qty', false, true, $bad),
                'reason' => $reason, 'sold_30d' => self::figure($r, 'sold_30d', true, false, $bad), 'note' => $note === '' ? null : $note];
        }
        if ($out === []) {
            throw new CwException('bad_file', 'the list has no rows', 400);
        }
        return $out;
    }

    /**
     * A figure of a list's row: a whole number of at most nine digits (a minus sign when $signed); empty is null (not known) when $empty.
     *
     * @param array<string, string> $r
     * @param \Closure(string, string): CwException $bad
     */
    private static function figure(array $r, string $column, bool $empty, bool $signed, \Closure $bad): ?int
    {
        $v = trim($r[$column]);
        if ($v === '' && $empty) {
            return null;
        }
        if (preg_match($signed ? '/^-?[0-9]{1,9}$/D' : '/^[0-9]{1,9}$/D', $v) !== 1) {
            throw $bad($column, 'a whole number' . ($signed ? '' : ', 0 or more') . ($empty ? ', or empty' : ''));
        }
        return (int) $v;
    }

    /** The name of a list (the load's --batch): 1 to BATCH_MAX characters of letters, digits, spaces and . _ : -, starting with a letter or digit. */
    public static function batch(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._:-]{0,' . (self::BATCH_MAX - 1) . '}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('--batch is the list\'s name: 1 to ' . self::BATCH_MAX . ' letters, digits, spaces or . _ : - (starting with a letter or digit)');
        }
        return $name;
    }

    /**
     * The order the page shows the rows in: CW and the shop differ first, the biggest difference first (a shop figure not known: after every
     * known difference); then CW below zero, the most units sold in 30 days first (not known: last); then the rest; within a kind, the
     * oldest row first.
     *
     * @param list<array<string, mixed>> $rows each with id, reason, shop_qty, cw_qty, sold_30d
     * @return list<array<string, mixed>>
     */
    public static function order(array $rows): array
    {
        $key = static function (array $r): array {
            $reason = (string) $r['reason'];
            $rank = array_search($reason, self::REASONS, true);
            $size = match ($reason) {
                'differs' => $r['shop_qty'] === null ? -1 : abs((int) $r['shop_qty'] - (int) $r['cw_qty']),
                'below_zero' => $r['sold_30d'] === null ? -1 : (int) $r['sold_30d'],
                default => 0,
            };
            return [$rank === false ? 99 : $rank, -$size, (int) $r['id']];
        };
        usort($rows, static fn (array $a, array $b): int => $key($a) <=> $key($b));
        return array_values($rows);
    }

    /** What was typed in "On the shelf": a whole number of units from 0 to CountSheets::MAX_QTY (422 bad_quantity otherwise). */
    public static function quantity(string $typed): int
    {
        $v = trim($typed);
        if (preg_match('/^[0-9]{1,9}$/D', $v) !== 1 || (int) $v > CountSheets::MAX_QTY) {
            throw new CwException('bad_quantity', 'what is on the shelf is a whole number of units from 0 to ' . CountSheets::MAX_QTY, 422, ['field' => 'qty']);
        }
        return (int) $v;
    }

    // ------------------------------------------------------------------------------------------
    // The load
    // ------------------------------------------------------------------------------------------

    /**
     * What a load of $rows would do for the store, with nothing written: per row its item and `action` (add: no open row; refresh: the open
     * row gets these figures; same: the open row has them already; skip: answered in this same list), and the counts. 422 unknown_item /
     * merged_item (detail row) for an item CW does not have or that was merged into another; 400 bad_file for an empty list or an item twice.
     *
     * @param list<array<string, mixed>> $rows readFile()'s
     * @return array{rows: list<array<string, mixed>>, counts: array{add: int, refresh: int, same: int, skip: int}}
     */
    public function plan(int $channelId, array $rows, string $batch): array
    {
        return $this->planOn($this->db, $channelId, $rows, self::batch($batch), false);
    }

    /**
     * Loads a list for the store (plan(), then each row written) in ONE transaction, the open rows locked as they are read; one audit row
     * stock_doubt.load ($file: what the list was called, for the audit). Returns plan()'s answer as it was applied.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, counts: array{add: int, refresh: int, same: int, skip: int}}
     */
    public function load(Caller $caller, int $channelId, array $rows, string $batch, string $file): array
    {
        $batch = self::batch($batch);
        return $this->db->transaction(function (Db $db) use ($caller, $channelId, $rows, $batch, $file): array {
            $plan = $this->planOn($db, $channelId, $rows, $batch, true);
            foreach ($plan['rows'] as $r) {
                $values = [$r['reason'], $r['shop_qty'], $r['cw_qty'], $r['sold_30d'], $batch, $r['note']];
                $lists = json_encode($r['batches'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if ($r['action'] === 'add') {
                    $db->exec('INSERT INTO stock_doubt (channel_id, sku_id, reason, shop_qty, cw_qty, sold_30d, batch, note, batches) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS JSON))',
                        [$channelId, $r['sku_id'], ...$values, $lists]);
                } elseif ($r['action'] === 'refresh') {
                    $db->exec("UPDATE stock_doubt SET reason = ?, shop_qty = ?, cw_qty = ?, sold_30d = ?, batch = ?, note = ?, batches = CAST(? AS JSON) WHERE id = ? AND status = 'open'",
                        [...$values, $lists, $r['doubt_id']]);
                }
            }
            Audit::write($db, $caller, 'stock_doubt.load', 'channel', (string) $channelId, null, ['batch' => $batch, 'file' => mb_substr($file, 0, 200),
                'rows' => count($plan['rows'])] + $plan['counts']);
            return $plan;
        });
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, counts: array{add: int, refresh: int, same: int, skip: int}}
     */
    private function planOn(Db $db, int $channelId, array $rows, string $batch, bool $lock): array
    {
        if ($rows === []) {
            throw new CwException('bad_file', 'the list has no rows', 400);
        }
        if ($db->value('SELECT 1 FROM channel WHERE id = ?', [$channelId]) === null) {
            throw new CwException('unknown_store', 'there is no such store', 422);
        }
        $codes = [];
        foreach ($rows as $r) {
            $code = (string) $r['cw_code'];
            if (isset($codes[$code])) {
                throw new CwException('bad_file', "row {$r['row']}: {$code} is on the list twice", 400, ['row' => (int) $r['row'], 'column' => 'cw_code']);
            }
            $codes[$code] = true;
        }
        $items = [];
        foreach (array_chunk(array_keys($codes), 500) as $chunk) {
            foreach ($db->all('SELECT id, code, merged_into_sku_id FROM sku WHERE code IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $s) {
                $items[(string) $s['code']] = $s;
            }
        }
        $out = [];
        $counts = ['add' => 0, 'refresh' => 0, 'same' => 0, 'skip' => 0];
        foreach ($rows as $r) {
            $code = (string) $r['cw_code'];
            $s = $items[$code] ?? throw new CwException('unknown_item', "row {$r['row']}: CW has no item {$code}: nothing was loaded", 422, ['row' => (int) $r['row']]);
            if ($s['merged_into_sku_id'] !== null) {
                throw new CwException('merged_item', "row {$r['row']}: {$code} was merged into another item: put that one on the list; nothing was loaded", 422,
                    ['row' => (int) $r['row']]);
            }
            $sku = (int) $s['id'];
            // The open row: found by the store's UNIQUE (channel_id, open_sku_id) with no lock, then locked by its id, as an answer locks it
            // (Codex round 1: locking through that index first, while an answer holds the row and changes the index, could deadlock), and
            // read again under the lock: answered meanwhile, it is no longer the open row.
            $openId = $db->value('SELECT id FROM stock_doubt WHERE channel_id = ? AND open_sku_id = ?', [$channelId, $sku]);
            $open = $openId === null ? null : $db->one('SELECT id, channel_id, sku_id, CAST(status AS CHAR) AS status, CAST(reason AS CHAR) AS reason, shop_qty, cw_qty, '
                . 'sold_30d, batch, CAST(batches AS CHAR) AS batches, note FROM stock_doubt WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [(int) $openId]);
            if ($open !== null && ($open['status'] !== 'open' || (int) $open['channel_id'] !== $channelId || (int) $open['sku_id'] !== $sku)) {
                $open = null;
            }
            $new = ['reason' => (string) $r['reason'], 'shop_qty' => $r['shop_qty'] === null ? null : (int) $r['shop_qty'], 'cw_qty' => (int) $r['cw_qty'],
                'sold_30d' => $r['sold_30d'] === null ? null : (int) $r['sold_30d'], 'batch' => $batch, 'note' => $r['note'] === null ? null : (string) $r['note']];
            $lists = $open === null ? [] : self::lists((string) $open['batches']);
            // Answered already on this list (the row of every list that put or refreshed it): this list never opens it again.
            $answered = $db->value("SELECT 1 FROM stock_doubt WHERE channel_id = ? AND sku_id = ? AND status <> 'open' AND JSON_CONTAINS(batches, JSON_QUOTE(?)) LIMIT 1",
                [$channelId, $sku, $batch]) !== null;
            if ($answered || ($open !== null && in_array($batch, $lists, true) && $batch !== (string) $open['batch'])) {
                // ... nor does an older list put its figures back over a newer list's.
                $action = 'skip';
            } elseif ($open !== null) {
                $held = ['reason' => (string) $open['reason'], 'shop_qty' => $open['shop_qty'] === null ? null : (int) $open['shop_qty'], 'cw_qty' => (int) $open['cw_qty'],
                    'sold_30d' => $open['sold_30d'] === null ? null : (int) $open['sold_30d'], 'batch' => (string) $open['batch'],
                    'note' => $open['note'] === null ? null : (string) $open['note']];
                $action = $held === $new && in_array($batch, $lists, true) ? 'same' : 'refresh';
            } else {
                $action = 'add';
            }
            $counts[$action]++;
            $out[] = ['row' => (int) $r['row'], 'cw_code' => $code, 'sku_id' => $sku, 'action' => $action, 'doubt_id' => $open === null ? null : (int) $open['id'],
                'batches' => array_values(array_unique([...$lists, $batch]))] + $new;
        }
        return ['rows' => $out, 'counts' => $counts];
    }

    /** @return list<string> the names of the lists a row was on (its `batches`) */
    private static function lists(string $json): array
    {
        $v = json_decode($json, true);
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    // ------------------------------------------------------------------------------------------
    // The answers
    // ------------------------------------------------------------------------------------------

    /**
     * "Save count" (the class docblock). $qty: the units on the shelf, 0 to CountSheets::MAX_QTY (422 bad_quantity). 403 for who may not
     * count, 404 unknown_doubt, 409 doubt_settled (detail status) for a row answered already, 409 no_selling_warehouse for a store that sells
     * from none; the counts engine's own refusals (merged_item, moved_since, ...) as they are. Every refusal books nothing.
     *
     * @return array{result: string, id: int, counted: int, had: int, delta: ?int, document_id: int, number: string, replayed: bool}
     */
    public function count(Caller $caller, int $id, int $qty): array
    {
        if ($qty < 0 || $qty > CountSheets::MAX_QTY) {
            throw new CwException('bad_quantity', 'what is on the shelf is a whole number of units from 0 to ' . CountSheets::MAX_QTY, 422, ['field' => 'qty']);
        }
        $sheets = $this->sheets ?? throw new \LogicException('StockDoubt::count needs the count sheets');
        // Who first (and again inside): a person who may not count hears that, not a stored answer.
        CountSheets::mayCount($this->db, $caller);
        $key = self::KEY . $id;
        $r = (new Idempotency($this->db))->run($caller, $key, 'stock_doubt.count', '/ui/stock/doubtful/' . $id . '/count', ['id' => $id, 'qty' => $qty],
            self::TABLE, (string) $id, function (Db $db) use ($caller, $id, $qty, $key, $sheets): OpResult {
                $row = self::lock($db, $id);
                // Who, again, now that the row is held: a job taken away while this waited on the row is refused (Codex round 1).
                CountSheets::mayCount($db, $caller);
                self::mustBeOpen($row);
                $wh = $db->value('SELECT warehouse_id FROM channel_warehouse WHERE channel_id = ? AND is_sellable = 1', [(int) $row['channel_id']])
                    ?? throw new CwException('no_selling_warehouse', 'this store sells from no warehouse: there is nothing to count it against', 409);
                $sku = (int) $row['sku_id'];
                // The engine's own order before the sheet's line names the item (a shared lock on it, held to the end of this one transaction):
                // the count's number series, then the item's balance at that warehouse, as a posting takes them (Documents::post, I21). Else
                // a selling-mode change (balance, then the item) and this count could wait on each other (Codex round 2).
                $prefix = (string) $db->value('SELECT prefix FROM document_type WHERE code = ?', [CountSheets::TYPE]);
                $db->value('SELECT last_no FROM number_series WHERE prefix = ? FOR UPDATE', [$prefix]);
                (new Stock($db))->lock([[(int) $wh, $sku]]);
                $sheet = $sheets->open($caller, ['warehouse' => (int) $wh, 'note' => sprintf(self::SHEET_NOTE, $id)], [$sku]);
                $sheets->setQuantities($caller, $sheet->id, [1 => $qty]);
                $doc = $sheets->post($caller, $sheet->id, (int) $db->value('SELECT version FROM document WHERE id = ?', [$sheet->id]));
                if ($doc->status !== 'posted') {
                    // A count posts at once (CountSheetHandler::approvalUnits is 0): anything else would leave the row cleared with nothing booked.
                    throw new \LogicException("count record {$doc->id} is {$doc->status}, not posted");
                }
                $line = (array) $db->one('SELECT system_qty, booked_delta, outcome FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$sheet->id]);
                if (($line['outcome'] ?? null) !== 'counted' || ($line['booked_delta'] ?? null) === null) {
                    // The engine left this count out: the item was counted with a later time (another count booked meanwhile), so nothing was
                    // booked. The whole answer is refused (Codex round 1, HIGH): the row stays to check, nothing is kept.
                    throw new CwException('counted_later', 'this item was counted again, with a later time, while you saved: nothing was booked; look at the shelf again',
                        409);
                }
                $had = (int) $line['system_qty'];
                $delta = (int) $line['booked_delta'];
                $now = Clock::db(Clock::now());
                $n = $db->exec("UPDATE stock_doubt SET status = 'cleared', counted_qty = ?, booked_delta = ?, count_document_id = ?, resolved_by = ?, resolved_at = ? "
                    . "WHERE id = ? AND status = 'open'", [$qty, $delta, $sheet->id, $caller->staffUserId, $now, $id]);
                if ($n !== 1) {
                    throw new \LogicException("stock_doubt {$id} was not open under its lock");
                }
                $body = ['result' => 'counted', 'id' => $id, 'counted' => $qty, 'had' => $had, 'delta' => $delta, 'document_id' => $sheet->id, 'number' => (string) $doc->number];
                Audit::write($db, $caller, 'stock_doubt.clear', self::TABLE, (string) $id, $key, ['channel_id' => (int) $row['channel_id'], 'sku_id' => $sku,
                    'warehouse_id' => (int) $wh] + $body);
                return OpResult::of(200, $body);
            });
        if ($r->status === 422 && ($r->body['error'] ?? null) === 'idempotency_key_reused') {
            // The key is kept only by a count that cleared the row: this is another answer for a row answered already.
            throw new CwException('doubt_settled', 'this item was checked already: nothing more was booked', 409, ['status' => 'cleared']);
        }
        QueueRules::refused($r);
        $b = $r->body;
        return ['result' => (string) $b['result'], 'id' => $id, 'counted' => (int) $b['counted'], 'had' => (int) $b['had'], 'delta' => $b['delta'] === null ? null : (int) $b['delta'],
            'document_id' => (int) $b['document_id'], 'number' => (string) $b['number'], 'replayed' => $r->replayed];
    }

    /**
     * "Not needed": nothing is booked; the row is dismissed with the note (400 note_required, 404, 409 doubt_settled, 403). The same press
     * again (the same person, the same note: a double click, a resend) is the first answer and changes nothing; any other answer for a row
     * no longer open is refused.
     */
    public function dismiss(Caller $caller, int $id, string $note): void
    {
        $note = QueueRules::note($note);
        CountSheets::mayCount($this->db, $caller);
        $this->db->transaction(function (Db $db) use ($caller, $id, $note): void {
            $row = self::lock($db, $id);
            // Who, again, now that the row is held (Codex round 1).
            CountSheets::mayCount($db, $caller);
            if ($row['status'] === 'dismissed' && $row['resolved_by'] !== null && (int) $row['resolved_by'] === $caller->staffUserId && $row['resolved_note'] === $note) {
                return;
            }
            self::mustBeOpen($row);
            $db->exec("UPDATE stock_doubt SET status = 'dismissed', resolved_by = ?, resolved_at = ?, resolved_note = ? WHERE id = ? AND status = 'open'",
                [$caller->staffUserId, Clock::db(Clock::now()), $note, $id]);
            Audit::write($db, $caller, 'stock_doubt.dismiss', self::TABLE, (string) $id, null, ['channel_id' => (int) $row['channel_id'], 'sku_id' => (int) $row['sku_id'],
                'note' => $note]);
        });
    }

    /**
     * The row, locked by its id (the one way every writer locks a row: the load too); 404 unknown_doubt.
     *
     * @return array<string, mixed>
     */
    private static function lock(Db $db, int $id): array
    {
        return $db->one('SELECT id, channel_id, sku_id, CAST(status AS CHAR) AS status, resolved_by, resolved_note FROM stock_doubt WHERE id = ? FOR UPDATE', [$id])
            ?? throw new CwException('unknown_doubt', 'there is no such item to check', 404);
    }

    /** 409 doubt_settled for a row answered already. @param array<string, mixed> $row */
    private static function mustBeOpen(array $row): void
    {
        if ($row['status'] !== 'open') {
            throw new CwException('doubt_settled', "this item was checked already ({$row['status']})", 409, ['status' => $row['status']]);
        }
    }

    // ------------------------------------------------------------------------------------------
    // The lists
    // ------------------------------------------------------------------------------------------

    /** Open rows: of one store, or of every store. */
    public function openCount(?int $channelId = null): int
    {
        return (int) ($channelId === null
            ? $this->db->value("SELECT COUNT(*) FROM stock_doubt WHERE status = 'open'")
            : $this->db->value("SELECT COUNT(*) FROM stock_doubt WHERE channel_id = ? AND status = 'open'", [$channelId]));
    }

    /**
     * Every store, with its open rows and all its rows (the page's choice of store), in id order.
     *
     * @return list<array{id: int, code: string, name: string, open: int, rows: int}>
     */
    public function stores(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'open' => (int) $r['open'],
            'rows' => (int) $r['all_rows']], $this->db->all("SELECT c.id, c.code, c.name, (SELECT COUNT(*) FROM stock_doubt d WHERE d.channel_id = c.id AND d.status = 'open') AS open, "
            . '(SELECT COUNT(*) FROM stock_doubt d WHERE d.channel_id = c.id) AS all_rows FROM channel c ORDER BY c.id'));
    }

    /**
     * What an answered row says (the page's line after an answer): its item's code and status; a cleared one what was counted, what CW
     * held just before (its count line's figure) and what it booked. Null for a row that is not there or still open.
     *
     * @return array{code: string, status: string, counted: ?int, had: ?int, delta: ?int}|null
     */
    public function answered(int $id): ?array
    {
        $r = $this->db->one("SELECT s.code, CAST(d.status AS CHAR) AS status, d.counted_qty, d.booked_delta, l.system_qty FROM stock_doubt d JOIN sku s ON s.id = d.sku_id "
            . "LEFT JOIN count_sheet_line l ON l.document_id = d.count_document_id AND l.line_no = 1 WHERE d.id = ? AND d.status <> 'open'", [$id]);
        return $r === null ? null : ['code' => (string) $r['code'], 'status' => (string) $r['status'], 'counted' => $r['counted_qty'] === null ? null : (int) $r['counted_qty'],
            'had' => $r['system_qty'] === null ? null : (int) $r['system_qty'], 'delta' => $r['booked_delta'] === null ? null : (int) $r['booked_delta']];
    }

    /**
     * The open rows of one store, in order(), PAGE at a time ($page past the end: the last page): each with its item (sku_id, code, name),
     * why, the list's figures and CW's figure now (`cw_now`: on hand at the store's selling warehouse, the figure a count sets; 0 when it has
     * none). $why: one of REASONS, or null for all; $q: words of the item's CW number or name.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function open(int $channelId, ?string $why, string $q, int $page): array
    {
        $where = ["d.channel_id = ?", "d.status = 'open'"];
        $params = [$channelId];
        if ($why !== null && in_array($why, self::REASONS, true)) {
            $where[] = 'd.reason = ?';
            $params[] = $why;
        }
        $q = mb_substr(trim($q), 0, 100);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(s.code LIKE ? OR s.name LIKE ?)';
            array_push($params, $like, $like);
        }
        $rows = $this->db->all('SELECT d.id, d.sku_id, s.code, s.name, CAST(d.reason AS CHAR) AS reason, d.shop_qty, d.cw_qty, d.sold_30d, d.batch, d.note, d.created_at, '
            . 'COALESCE(b.on_hand, 0) AS cw_now FROM stock_doubt d JOIN sku s ON s.id = d.sku_id '
            . 'LEFT JOIN channel_warehouse w ON w.channel_id = d.channel_id AND w.is_sellable = 1 '
            . 'LEFT JOIN stock_balance b ON b.warehouse_id = w.warehouse_id AND b.sku_id = d.sku_id WHERE ' . implode(' AND ', $where), $params);
        $rows = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
            'reason' => (string) $r['reason'], 'shop_qty' => $r['shop_qty'] === null ? null : (int) $r['shop_qty'], 'cw_qty' => (int) $r['cw_qty'],
            'sold_30d' => $r['sold_30d'] === null ? null : (int) $r['sold_30d'], 'batch' => (string) $r['batch'], 'note' => $r['note'], 'created_at' => (string) $r['created_at'],
            'cw_now' => (int) $r['cw_now']], $rows);
        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PAGE));
        $page = max(1, min($page, $pages));
        return ['rows' => array_slice(self::order($rows), ($page - 1) * self::PAGE, self::PAGE), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * The answered rows of one store, newest answer first, PAGE at a time: who (resolved_by_name), when, what was counted and booked (a
     * cleared row; its count record's number) or why it was not needed (a dismissed row).
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function checked(int $channelId, int $page): array
    {
        $total = (int) $this->db->value("SELECT COUNT(*) FROM stock_doubt WHERE channel_id = ? AND status <> 'open'", [$channelId]);
        $pages = max(1, (int) ceil($total / self::PAGE));
        $page = max(1, min($page, $pages));
        $rows = $this->db->all('SELECT d.id, d.sku_id, s.code, s.name, CAST(d.reason AS CHAR) AS reason, CAST(d.status AS CHAR) AS status, d.counted_qty, d.booked_delta, '
            . 'd.count_document_id, doc.number, d.resolved_by, u.display_name AS resolved_by_name, d.resolved_at, d.resolved_note FROM stock_doubt d '
            . 'JOIN sku s ON s.id = d.sku_id LEFT JOIN document doc ON doc.id = d.count_document_id LEFT JOIN staff_user u ON u.id = d.resolved_by '
            . "WHERE d.channel_id = ? AND d.status <> 'open' ORDER BY d.resolved_at DESC, d.id DESC LIMIT " . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE), [$channelId]);
        return ['rows' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'],
            'name' => (string) $r['name'], 'reason' => (string) $r['reason'], 'status' => (string) $r['status'],
            'counted_qty' => $r['counted_qty'] === null ? null : (int) $r['counted_qty'], 'booked_delta' => $r['booked_delta'] === null ? null : (int) $r['booked_delta'],
            'count_document_id' => $r['count_document_id'] === null ? null : (int) $r['count_document_id'], 'number' => $r['number'],
            'resolved_by_name' => $r['resolved_by_name'], 'resolved_at' => (string) $r['resolved_at'], 'resolved_note' => $r['resolved_note']], $rows),
            'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
