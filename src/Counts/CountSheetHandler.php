<?php

declare(strict_types=1);

namespace CW\Counts;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\DocumentHandler;
use CW\Movements;

/**
 * What a stock count record (CNT) does when it is posted or cancelled (pack A2a; docs/decisions.md CT1-CT9). The count ENGINE is
 * Movements::bookForDocument with movements of type `count` (the as-of rule, "a later count wins", its two reviews): nothing of it
 * is repeated or changed here. A CNT record has a `count_sheet` header of one of three kinds:
 *
 *   sheet          a count sheet. Its document lines are its counted lines (written by CountSheets::post just before the posting:
 *                  line k is the k-th counted line). post() books them as count movements, one per count time (CountTimes: at most
 *                  the engine's 20), each line with the quantity counted; a time older than a day goes as a count typed in later
 *                  (the engine's `backdated`, up to 90 days). Before the stock is touched it writes, on the sheet's own lines (locked
 *                  first, so nothing new is locked after the stock: I21), what it expects of each line: the figure just before, the
 *                  difference, "left out" where a later count exists, and the check of a difference above the tolerance. After the
 *                  booking it compares the engine's answer line by line; one that differs (stock moved in that instant) refuses the
 *                  posting whole (409 moved_meanwhile: the person looks at the differences again).
 *                  validate() refuses, with the lines named: a line whose stock moved after it was counted and that the person has
 *                  not chosen to post as counted (409 moved_since: the module plan's decision 6, warn first); a count older than the
 *                  engine takes (422 count_too_old); more count times than one posting has room for (409 too_many_times).
 *   line_reversal  the record that puts back the count of ONE line of a posted sheet (a rejected difference: CountReviews). One line:
 *                  the item and minus what the count changed. It is booked as an `adjustment` of that many units (the engine can
 *                  only negate a whole record; a count movement would set a new count time). The sheet line names this record
 *                  (count_sheet_line.reversal_document_id, written by CountReviews before the posting), once.
 *   reversal       the cancellation of a whole posted sheet (Documents::reverse): the stock is negated by the document base
 *                  (Movements::reverseDocument); reverse() refuses it when a line of the sheet was already put back on its own (it
 *                  would be put back twice), gives the cancellation its header and closes the sheet's open checks.
 *
 * Units for the document base's own "check after it is final" rule: always 0 (docs/decisions.md CT8). A count's differences are
 * checked one by one, by the rule read where a difference is cleared (CountReviews), so "only over N items" never opens a check of the
 * whole sheet; "every one" still does (the base's own rule for any kind of record).
 */
final class CountSheetHandler implements DocumentHandler
{
    public const TYPE = 'CNT';
    public const KINDS = ['sheet', 'reversal', 'line_reversal'];

    private readonly Movements $moves;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (the engine's time windows; tests pin it) */
    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null)
    {
        $this->moves = new Movements($db, null, $clock);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(Db $db, Document $doc, array $lines): void
    {
        $sheet = self::header($db, $doc->id);
        if ($doc->warehouseId === null) {
            throw new CwException('warehouse_required', 'say which warehouse was counted', 422, ['field' => 'warehouse']);
        }
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            if ($l['sku_id'] === null || $l['qty'] === null) {
                throw new CwException('bad_line', "line {$no}: a count's line names an item and a quantity", 422, ['line' => $no]);
            }
            if ($l['warehouse_id'] !== null && (int) $l['warehouse_id'] !== $doc->warehouseId) {
                throw new CwException('line_warehouse', "line {$no}: every line is in the sheet's warehouse", 422, ['line' => $no]);
            }
            if ($l['unit_cost'] !== null || $l['reason_code'] !== null) {
                throw new CwException('bad_line', "line {$no}: a count's line carries no cost and no reason", 422, ['line' => $no]);
            }
        }
        if ($sheet['kind'] === 'line_reversal') {
            self::lineToPutBack($db, $doc, $sheet, $lines);
            return;
        }
        if ($sheet['kind'] !== 'sheet') {
            throw new CwException('not_count_sheet', 'the cancellation of a count sheet is made by cancelling the sheet', 409);
        }
        $plan = CountPosting::plan($db, $doc->id, $this->now());
        self::sameLines($plan, $lines);
        $old = array_values(array_column(array_filter($plan['lines'], static fn (array $l): bool => $l['too_old']), 'line_no'));
        if ($old !== []) {
            throw new CwException('count_too_old', 'lines ' . implode(', ', $old) . ' were counted more than ' . intdiv(Movements::BACKDATED_MAX_AGE_SEC, 86_400)
                . ' days ago: count them again', 422, ['lines' => $old, 'days' => intdiv(Movements::BACKDATED_MAX_AGE_SEC, 86_400)]);
        }
        $moved = array_values(array_column(array_filter($plan['lines'], static fn (array $l): bool => $l['needs_choice']), 'line_no'));
        if ($moved !== []) {
            throw new CwException('moved_since', 'stock of lines ' . implode(', ', $moved) . ' moved after they were counted: count them again, or choose to post '
                . 'them as counted', 409, ['lines' => $moved]);
        }
        if ($plan['too_many_times']) {
            throw new CwException('too_many_times', 'too much stock moved while this sheet was counted for one posting (' . Movements::MAX_DOCUMENT_MOVEMENTS
                . ' count times): count some of its lines again', 409, ['max' => Movements::MAX_DOCUMENT_MOVEMENTS]);
        }
    }

    /** A count asks for no OK before it is posted: counts post at once, so the shelf figure is right straight away (IM2). */
    public function approvalUnits(Db $db, Document $doc, array $lines): int
    {
        return 0;
    }

    public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int
    {
        $sheet = self::header($db, $doc->id);
        $ref = ['document_id' => $doc->id, 'doc_ref' => (string) $doc->number];
        if ($sheet['kind'] === 'line_reversal') {
            $line = self::lineToPutBack($db, $doc, $sheet, $lines);
            $code = (string) $db->value('SELECT code FROM warehouse WHERE id = ?', [$doc->warehouseId]);
            $this->moves->bookForDocument($caller, $ref, $opKey, [['type' => 'adjustment', 'warehouse' => $code,
                'note' => 'puts back line ' . $line['line_no'] . ' of ' . $line['number'],
                'lines' => [['document_line' => (int) $lines[0]['line_no'], 'sku_id' => (int) $lines[0]['sku_id'], 'qty' => (int) $lines[0]['qty']]]]]);
            return 0;
        }
        // The sheet's counted lines, locked before the stock (their rows are changed below, and never after the stock locks).
        $db->all('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND counted_qty IS NOT NULL ORDER BY line_no FOR UPDATE', [$doc->id]);
        $plan = CountPosting::plan($db, $doc->id, $this->now());
        $docLine = self::sameLines($plan, $lines);
        if ($plan['too_many_times'] || array_filter($plan['lines'], static fn (array $l): bool => $l['needs_choice'] || $l['too_old']) !== []) {
            throw new \LogicException("count sheet {$doc->id} was not validated before its posting");
        }
        $byNo = array_column($plan['lines'], null, 'line_no');
        foreach ($plan['lines'] as $l) {
            $open = $l['over'] !== [];
            $db->exec('UPDATE count_sheet_line SET doc_line_no = ?, system_qty = ?, booked_delta = ?, outcome = ?, review = ?, review_units = ?, review_percent = ? '
                . 'WHERE document_id = ? AND line_no = ?', [$docLine[$l['line_no']], $l['held'], $l['delta'], $l['stale'] ? 'stale' : 'counted', $open ? 'open' : 'none',
                    $open ? $plan['units_limit'] : null, $open ? $plan['percent_limit'] : null, $doc->id, $l['line_no']]);
        }
        $movements = [];
        foreach ($plan['groups'] as $g) {
            $movements[] = ['type' => 'count', 'warehouse' => $plan['warehouse'], 'counted_at' => Clock::iso($g['at'])] + ($g['backdated'] ? ['backdated' => true] : [])
                + ['lines' => array_map(static fn (int $no): array => ['document_line' => $docLine[$no], 'sku_id' => $byNo[$no]['sku_id'], 'qty' => $byNo[$no]['counted']], $g['lines'])];
        }
        $booked = $this->moves->bookForDocument($caller, $ref, $opKey, $movements);
        // The engine's answer against what was expected, on the balances this transaction now holds locked.
        $sheetLine = array_flip($docLine);
        $differs = [];
        foreach ($booked as $movement) {
            foreach ($movement['lines'] as $r) {
                $l = $byNo[$sheetLine[(int) $r['line_index']]];
                $stale = ($r['result'] ?? null) === 'stale_count';
                if ($stale !== $l['stale'] || (!$stale && (($r['result'] ?? null) !== 'counted' || (int) $r['delta'] !== $l['delta']))) {
                    $differs[] = $l['line_no'];
                }
            }
        }
        if ($differs !== []) {
            sort($differs);
            throw new CwException('moved_meanwhile', 'stock of lines ' . implode(', ', $differs) . ' moved while the sheet was being posted: nothing was booked, look at '
                . 'the differences again', 409, ['lines' => $differs]);
        }
        return 0;
    }

    public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void
    {
        $sheet = self::header($db, $original->id);
        if ($sheet['kind'] !== 'sheet') {
            throw new CwException('not_reversible', 'a record that puts a count back is not cancelled: count the item again instead', 409);
        }
        $back = $db->value('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND reversal_document_id IS NOT NULL ORDER BY line_no LIMIT 1 FOR UPDATE', [$original->id]);
        if ($back !== null) {
            throw new CwException('line_put_back', "line {$back} of this sheet was already put back on its own: the sheet cannot be cancelled as a whole "
                . '(reject its other differences one by one)', 409, ['line' => (int) $back]);
        }
        $now = Clock::db($this->now());
        $db->exec("INSERT INTO count_sheet (document_id, kind, location_id, created_at, updated_at) VALUES (?, 'reversal', ?, ?, ?)",
            [$reversal->id, $sheet['location_id'], $now, $now]);
        // Its open checks are settled by the cancellation: the counts they were about are put back.
        $db->exec("UPDATE count_sheet_line SET review = 'rejected', reviewed_by = ?, reviewed_at = ?, review_note = ?, reversal_document_id = ? "
            . "WHERE document_id = ? AND review = 'open'", [$caller->staffUserId, $now, 'the whole sheet was cancelled by ' . $reversal->number, $reversal->id, $original->id]);
    }

    /** @return array<string, mixed> the record's count_sheet row (422 not_count_sheet: a CNT made around the sheet service) */
    public static function header(Db $db, int $documentId): array
    {
        return $db->one('SELECT document_id, CAST(kind AS CHAR) AS kind, location_id, reverses_document_id, reverses_line_no FROM count_sheet WHERE document_id = ?', [$documentId])
            ?? throw new CwException('not_count_sheet', 'this stock count has no count sheet: counts are made on Stock > Counts', 422);
    }

    /**
     * The document lines of a sheet are its counted lines, in line order (line k of the record is the k-th counted line): written by
     * CountSheets::post in the same transaction. Anything else is refused (409 sheet_changed).
     *
     * @param array{lines: list<array<string, mixed>>} $plan
     * @param list<array<string, mixed>> $lines the document's lines
     * @return array<int, int> sheet line number => document line number
     */
    private static function sameLines(array $plan, array $lines): array
    {
        $map = [];
        $same = count($plan['lines']) === count($lines);
        foreach ($plan['lines'] as $i => $l) {
            $d = $lines[$i] ?? null;
            $same = $same && $d !== null && (int) $d['sku_id'] === $l['sku_id'] && (int) $d['qty'] === $l['counted'];
            $map[$l['line_no']] = $d === null ? 0 : (int) $d['line_no'];
        }
        if (!$same) {
            throw new CwException('sheet_changed', 'the sheet changed since this page was drawn: look at it again', 409);
        }
        return $map;
    }

    /**
     * The sheet line a line_reversal puts back, checked: its sheet is posted (not cancelled), the line was booked and changed the
     * stock, this record is the one the line names, and its one line is the item with minus what the count changed.
     *
     * @param array<string, mixed> $sheet
     * @param list<array<string, mixed>> $lines
     * @return array{line_no: int, number: string}
     */
    private static function lineToPutBack(Db $db, Document $doc, array $sheet, array $lines): array
    {
        $l = $db->one('SELECT l.line_no, l.sku_id, l.booked_delta, CAST(l.outcome AS CHAR) AS outcome, l.reversal_document_id, d.number, d.status, d.warehouse_id '
            . 'FROM count_sheet_line l JOIN document d ON d.id = l.document_id WHERE l.document_id = ? AND l.line_no = ?',
            [(int) $sheet['reverses_document_id'], (int) $sheet['reverses_line_no']])
            ?? throw new CwException('unknown_line', 'there is no such line of a count sheet', 422);
        if ($l['status'] !== 'posted' || $l['outcome'] !== 'counted' || (int) $l['booked_delta'] === 0) {
            throw new CwException('nothing_to_put_back', 'that count changed nothing that can be put back (its sheet was cancelled, or it booked no difference)', 409);
        }
        if ((int) ($l['reversal_document_id'] ?? 0) !== $doc->id || (int) $l['warehouse_id'] !== $doc->warehouseId) {
            throw new CwException('sheet_changed', 'this record is not the one that puts that line back', 409);
        }
        if (count($lines) !== 1 || (int) $lines[0]['sku_id'] !== (int) $l['sku_id'] || (int) $lines[0]['qty'] !== -(int) $l['booked_delta']) {
            throw new CwException('sheet_changed', 'a record that puts a count back has one line: the item and what the count changed, the other way', 409);
        }
        return ['line_no' => (int) $l['line_no'], 'number' => (string) $l['number']];
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock ?? static fn (): \DateTimeImmutable => Clock::now())();
    }
}
