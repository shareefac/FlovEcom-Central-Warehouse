<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Counts;

use CW\Counts\CountInvariants;
use CW\Documents\Document;
use CW\Invariants;

/**
 * The nightly checks of stock counting, C1-C5 (pack A2a; docs/decisions.md CT16): clean after a sheet is counted, made final (a
 * counted line, a count of nothing, a line left out by a later count, a line not counted), a line put back by a record and another
 * sheet cancelled as a whole; then each kind of damage, written straight into the tables with the test's admin connection, is
 * reported. This class turns off the base's "clean after every test" check: it damages the data on purpose.
 */
final class CountInvariantsTest extends CountsTestCase
{
    private Document $sheet;
    private Document $back;
    private Document $cancelled;
    /** @var array<string, int> */
    private array $sku = [];

    protected function setUp(): void
    {
        parent::setUp();
        $sc = $this->staffUser('stock_controller');
        foreach (['counted' => 10, 'zero' => 3, 'stale' => 4, 'blank' => 2, 'other' => 7] as $k => $qty) {
            $this->sku[$k] = $this->item('strict', $qty);
        }
        $sheet = $this->sheet($sc, [$this->sku['counted'], $this->sku['zero'], $this->sku['stale'], $this->sku['blank']]);
        $this->enter($sc, $sheet, $this->sku['counted'], 30);
        $this->enter($sc, $sheet, $this->sku['zero'], 0);
        $this->enter($sc, $sheet, $this->sku['stale'], 4);
        $this->later('15 minutes');
        $later = $this->sheet($sc, [$this->sku['stale']]);
        $this->enter($sc, $later, $this->sku['stale'], 5);
        $this->post($sc, $later);
        $this->later('5 minutes');
        $this->sheet = $this->post($sc, $sheet);
        self::assertSame('stale', $this->sheetLine($this->sheet->id, $this->sku['stale'])['outcome']);
        $this->back = $this->reviews->rejectLine($sc, $this->sheet->id, $this->lineOf($this->sheet->id, $this->sku['counted']), 'counted the wrong shelf');
        $other = $this->sheet($sc, [$this->sku['other']]);
        $this->enter($sc, $other, $this->sku['other'], 1);
        $posted = $this->post($sc, $other);
        $this->cancelled = $this->sheets->reverse($sc, $posted->id, 'entered_in_error', null);
        self::assertSame([], CountInvariants::check(self::$db), 'clean before the damage');
        self::assertSame([], Invariants::check(self::$db));
    }

    /** The data is damaged on purpose here: no "clean after every test" check. */
    protected function assertPostConditions(): void
    {
    }

    private function assertReports(string $needle): void
    {
        $v = CountInvariants::check(self::$db);
        self::assertNotSame([], array_filter($v, static fn (string $s): bool => str_contains($s, $needle)), "expected a violation containing \"{$needle}\", got: " . json_encode($v));
        self::assertNotSame([], array_filter(Invariants::check(self::$db), static fn (string $s): bool => str_contains($s, $needle)), 'the nightly run reports it too');
    }

    public function testC1AFinalCountWithoutItsHeaderOrWithAHeaderOfTheWrongKind(): void
    {
        self::$db->exec("UPDATE count_sheet SET kind = 'sheet' WHERE document_id = ?", [$this->cancelled->id]);
        $this->assertReports("stock count {$this->cancelled->number}: its count header is of the kind sheet but it is a cancellation");
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            self::$db->exec('DELETE FROM count_sheet WHERE document_id = ?', [$this->cancelled->id]);
        } finally {
            self::$db->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->assertReports("stock count {$this->cancelled->number}: it has no count header");
    }

    public function testC2RecordLinesThatAreNotTheCountedLines(): void
    {
        $line = $this->lineOf($this->sheet->id, $this->sku['zero']);
        self::$db->exec('UPDATE document_line SET qty = 9 WHERE document_id = ? AND line_no = 2', [$this->sheet->id]);
        $this->assertReports("count sheet {$this->sheet->number} line {$line}: its record line 2 is not the item and the quantity counted");
        self::$db->exec('UPDATE document_line SET qty = 0 WHERE document_id = ? AND line_no = 2', [$this->sheet->id]);
        self::$db->exec("UPDATE count_sheet_line SET counted_qty = 1, counted_at = '2026-09-26 18:00:00', entered_at = '2026-09-26 18:00:00' WHERE document_id = ? AND sku_id = ?",
            [$this->sheet->id, $this->sku['blank']]);
        $this->assertReports("count sheet {$this->sheet->number}: 3 record lines for 4 counted lines");
        $this->assertReports('it was counted but says nothing of what the posting did with it');
    }

    public function testC2ASheetThatWasNeverFinalHoldsNothingOfAPosting(): void
    {
        $sc = $this->staffUser('stock_controller');
        $draft = $this->sheet($sc, [$this->sku['blank']]);
        $this->enter($sc, $draft, $this->sku['blank'], 2);
        self::assertSame([], CountInvariants::check(self::$db));
        self::$db->exec("UPDATE count_sheet_line SET outcome = 'counted', doc_line_no = 1, system_qty = 2, booked_delta = 0 WHERE document_id = ?", [$draft->id]);
        $this->assertReports("count sheet {$draft->id} (draft): 0 record lines and 1 lines with an outcome, but it was never made final");
    }

    public function testC3WhatTheSheetBookedIsNotWhatItsLinesSay(): void
    {
        $zero = $this->lineOf($this->sheet->id, $this->sku['zero']);
        self::$db->exec('UPDATE count_sheet_line SET booked_delta = -2 WHERE document_id = ? AND line_no = ?', [$this->sheet->id, $zero]);
        $this->assertReports("count sheet {$this->sheet->number} line {$zero} (counted, -2): the record booked 1 count movements of its item, -3 in all");
        self::$db->exec('UPDATE count_sheet_line SET booked_delta = -3 WHERE document_id = ? AND line_no = ?', [$this->sheet->id, $zero]);
        $stale = $this->lineOf($this->sheet->id, $this->sku['stale']);
        self::$db->exec("UPDATE stock_ledger SET document_id = ? WHERE document_id <> ? AND sku_id = ? AND movement_type = 'count'", [$this->sheet->id, $this->sheet->id, $this->sku['stale']]);
        $this->assertReports("count sheet {$this->sheet->number} line {$stale} (stale): the record booked 1 count movements of its item");
        self::$db->exec("UPDATE stock_ledger SET document_id = ? WHERE document_id = ? AND sku_id = ?", [$this->sheet->id, (int) $this->cancelled->reversesId, $this->sku['other']]);
        $this->assertReports("count sheet {$this->sheet->number} booked a count of on_hand for item {$this->sku['other']}, which is no line it counted");
    }

    public function testC4ADifferenceWaitingOnASheetThatIsNotFinalOrARejectionWithoutItsRecord(): void
    {
        $counted = $this->lineOf($this->sheet->id, $this->sku['counted']);
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            self::$db->exec('UPDATE count_sheet_line SET reversal_document_id = 999999 WHERE document_id = ? AND line_no = ?', [$this->sheet->id, $counted]);
        } finally {
            self::$db->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->assertReports("count sheet {$this->sheet->number} line {$counted}: a rejected difference names no record as the one that put it back");
        $orig = (int) $this->cancelled->reversesId;
        $line = (int) self::$db->value('SELECT line_no FROM count_sheet_line WHERE document_id = ?', [$orig]);
        self::$db->exec("UPDATE count_sheet_line SET review = 'open', reviewed_by = NULL, reviewed_at = NULL, review_note = NULL, reversal_document_id = NULL, "
            . 'review_units = 5, review_percent = 10 WHERE document_id = ?', [$orig]);
        $this->assertReports("line {$line}: a difference waits on a sheet that is reversed");
    }

    public function testC5ARecordThatPutsALineBackAndDoesNotMatchIt(): void
    {
        $counted = $this->lineOf($this->sheet->id, $this->sku['counted']);
        $what = "the record {$this->back->number} that puts back line {$counted} of count sheet {$this->sheet->id}";
        self::$db->exec('UPDATE stock_ledger SET qty_delta = qty_delta + 1 WHERE document_id = ?', [$this->back->id]);
        $this->assertReports("{$what}: it holds 1 lines and booked 1 movements, expected one adjustment of -20 of item {$this->sku['counted']}");
        self::$db->exec('UPDATE stock_ledger SET qty_delta = qty_delta - 1 WHERE document_id = ?', [$this->back->id]);
        self::assertSame([], CountInvariants::check(self::$db));
        self::$db->exec("UPDATE document SET status = 'reversed' WHERE id = ?", [$this->sheet->id]);
        $this->assertReports("{$what}: the sheet is reversed or in another warehouse");
        self::$db->exec("UPDATE document SET status = 'posted' WHERE id = ?", [$this->sheet->id]);
        self::$db->exec("UPDATE count_sheet_line SET review = 'accepted', reversal_document_id = NULL WHERE document_id = ? AND line_no = ?", [$this->sheet->id, $counted]);
        $this->assertReports("{$what}: 0 sheet lines name it, expected exactly that one");
    }
}
