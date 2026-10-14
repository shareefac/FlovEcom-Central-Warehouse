<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Counts;

use CW\Clock;
use CW\Output\CsvReader;

/**
 * Count sheets through the service, against the real document base and the count engine (pack A2a; docs/decisions.md CT1-CT9): a
 * posted sheet books exactly its counted lines through the engine, skips blank lines and books a 0; it posts once; cancelling it puts
 * the figures back; lines counted at different moments are booked under times the engine reads the same way; "warn first" for an item
 * that moved after its line was counted, and both choices; a line an older count cannot touch; the engine's time windows; the scan
 * box; the download and the upload with its preview; who may open, enter and post.
 */
final class CountSheetsTest extends CountsTestCase
{
    public function testAPostedSheetBooksExactlyItsCountedLinesSkipsBlankOnesBooksAZeroAndPostsOnce(): void
    {
        $a = $this->item('strict', 10);
        $b = $this->item('legacy', 5);
        $c = $this->item('legacy', 0);
        $d = $this->item('strict', 7);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a, $b, $c, $d]);
        self::assertSame(['draft', 'CNT', null], [$sheet->status, $sheet->docType, $sheet->number]);
        self::assertSame([10, 5, 0, 7], array_map('intval', self::$db->column('SELECT expected_qty FROM count_sheet_line WHERE document_id = ? ORDER BY line_no', [$sheet->id])),
            'each line keeps what the system held when it was put on the sheet');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM document_line WHERE document_id = ?', [$sheet->id]), 'nothing is a document line before the posting');
        $this->enter($sc, $sheet, $a, 8);
        $this->enter($sc, $sheet, $b, 5);
        $this->enter($sc, $sheet, $c, 0);
        $counted = $this->nowDb();
        self::assertSame([8, $counted, $sc->staffUserId, 'typed'], [(int) $this->sheetLine($sheet->id, $a)['counted_qty'], $this->sheetLine($sheet->id, $a)['counted_at'],
            (int) $this->sheetLine($sheet->id, $a)['counted_by'], $this->sheetLine($sheet->id, $a)['entered_how']]);
        self::assertSame(0, $this->ledgerCountOf($sheet->id), 'entering a quantity books nothing');

        $this->later('3 minutes');
        $posted = $this->post($sc, $sheet);
        self::assertSame(['posted', 'CNT-000001', 'not_required'], [$posted->status, $posted->number, $posted->reviewState]);
        self::assertSame([[$a, 'count', -2, $counted], [$b, 'count', 0, $counted], [$c, 'count', 0, $counted]], $this->ledgerOf($sheet->id),
            'the counted lines and only they: a count that found no difference and a count of nothing are journalled, the blank line is not');
        self::assertSame([8, 5, 0, 7], [$this->onHand($a), $this->onHand($b), $this->onHand($c), $this->onHand($d)]);
        self::assertSame([$counted, $counted, $counted, null], [$this->countedAt($a), $this->countedAt($b), $this->countedAt($c), $this->countedAt($d)]);
        self::assertSame([[1, $a, 8], [2, $b, 5], [3, $c, 0]], array_map(static fn (array $l): array => [(int) $l['line_no'], (int) $l['sku_id'], (int) $l['qty']],
            self::$db->all('SELECT line_no, sku_id, qty FROM document_line WHERE document_id = ? ORDER BY line_no', [$sheet->id])), 'the posted record holds the counted lines');
        self::assertSame([[1, 10, -2, 'counted'], [2, 5, 0, 'counted'], [3, 0, 0, 'counted'], [null, null, null, null]], array_map(
            static fn (array $l): array => [$l['doc_line_no'] === null ? null : (int) $l['doc_line_no'], $l['system_qty'] === null ? null : (int) $l['system_qty'],
                $l['booked_delta'] === null ? null : (int) $l['booked_delta'], $l['outcome']],
            self::$db->all('SELECT doc_line_no, system_qty, booked_delta, outcome FROM count_sheet_line WHERE document_id = ? ORDER BY line_no', [$sheet->id])),
            'the sheet stays, with what was booked');
        // The count gate's own fields are not touched (plan §7.4): the item's identity count and its selling rule.
        self::assertSame([[null, 'strict'], [null, 'legacy'], [null, 'legacy'], [null, 'strict']], array_map(static fn (array $s): array => [$s['counted_at'], $s['sell_policy']],
            self::$db->all('SELECT counted_at, CAST(sell_policy AS CHAR) AS sell_policy FROM sku WHERE id IN (?, ?, ?, ?) ORDER BY id', [$a, $b, $c, $d])));

        // A second post of the same sheet books nothing; a posted sheet takes no more quantities.
        $rows = $this->ledgerCount();
        self::refusedWith('not_draft', fn () => $this->post($sc, $sheet));
        self::refusedWith('not_draft', fn () => $this->enter($sc, $sheet, $d, 7));
        self::assertSame($rows, $this->ledgerCount());

        // Cancelling the whole sheet is a new record that puts the figures back; the count time stays (the engine's rule).
        $rev = $this->sheets->reverse($sc, $sheet->id, 'entered_in_error', null);
        self::assertSame(['posted', 'CNT-000002', $sheet->id], [$rev->status, $rev->number, $rev->reversesId]);
        self::assertSame('reversed', $this->docs->get($sheet->id)->status);
        self::assertSame([10, 5, 0, 7], [$this->onHand($a), $this->onHand($b), $this->onHand($c), $this->onHand($d)]);
        self::assertSame($counted, $this->countedAt($a));
        self::assertSame('reversal', self::$db->value('SELECT kind FROM count_sheet WHERE document_id = ?', [$rev->id]));
    }

    public function testACancellationThatPutsBackMoreThanTheLimitWaitsForAReviewerAndGetsItsHeaderWhenBooked(): void
    {
        $a = $this->item('strict', 30);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a]);
        $this->enter($sc, $sheet, $a, 10);
        $this->post($sc, $sheet);
        self::assertSame([10, 'open'], [$this->onHand($a), $this->sheetLine($sheet->id, $a)['review']]);
        // Cancelling it would put 20 units back on the shelf: the document base's rule asks a reviewer first (as for any record).
        $wait = $this->sheets->reverse($sc, $sheet->id, 'entered_in_error', 'counted the wrong shelf');
        self::assertSame('awaiting_approval', $wait->status);
        self::assertNull(self::$db->value('SELECT kind FROM count_sheet WHERE document_id = ?', [$wait->id]), 'no count header until it is booked');
        self::assertSame([10, 'open', 'posted'], [$this->onHand($a), $this->sheetLine($sheet->id, $a)['review'], $this->docs->get($sheet->id)->status], 'nothing changes while it waits');
        self::assertSame($wait->id, (int) $this->sheets->sheet($sheet->id)['reversed_by_id'], 'the sheet names the cancellation that waits');
        // The reviewer says OK: the cancellation is booked as its requester's, gets its header, and closes the sheet's check.
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = 'approval' AND state = 'open'", [$wait->id]);
        $done = $this->docs->approve($this->staffUser('reviewer'), $task, 'the wrong shelf: checked');
        self::assertSame(['posted', 'reversed'], [$done->status, $this->docs->get($sheet->id)->status]);
        self::assertSame('reversal', self::$db->value('SELECT kind FROM count_sheet WHERE document_id = ?', [$wait->id]));
        self::assertSame([30, 'rejected', $wait->id, $sc->staffUserId], [$this->onHand($a), $this->sheetLine($sheet->id, $a)['review'],
            (int) $this->sheetLine($sheet->id, $a)['reversal_document_id'], (int) $this->sheetLine($sheet->id, $a)['reviewed_by']]);
    }

    public function testASheetWithNothingCountedIsNotPostedAndADraftCanBeStopped(): void
    {
        $a = $this->item('strict', 3);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a]);
        self::refusedWith('nothing_counted', fn () => $this->post($sc, $sheet));
        self::assertSame('draft', $this->docs->get($sheet->id)->status);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM document_line WHERE document_id = ?', [$sheet->id]));
        $stopped = $this->sheets->cancel($sc, $sheet->id, $this->docs->get($sheet->id)->version, 'opened twice');
        self::assertSame('cancelled', $stopped->status);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ?', [$sheet->id]), 'a stopped sheet is kept');
        // A line can be cleared again: it is "not counted", and nothing is booked for it.
        $two = $this->sheet($sc, [$a]);
        $this->enter($sc, $two, $a, 4);
        $this->enter($sc, $two, $a, null);
        self::assertSame([null, null, null], [$this->sheetLine($two->id, $a)['counted_qty'], $this->sheetLine($two->id, $a)['counted_at'], $this->sheetLine($two->id, $a)['counted_by']]);
        self::refusedWith('nothing_counted', fn () => $this->post($sc, $two));
        self::refusedWith('bad_quantity', fn () => $this->enter($sc, $two, $a, -1));
        self::refusedWith('bad_quantity', fn () => $this->enter($sc, $two, $a, 10_000_001));
        self::refusedWith('unknown_line', fn () => $this->sheets->setQuantities($sc, $two->id, [99 => 1]));
    }

    public function testEnteringTheSameQuantityAgainKeepsTheMomentItWasCounted(): void
    {
        $a = $this->item('strict', 3);
        $b = $this->item('strict', 4);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a, $b]);
        $this->enter($sc, $sheet, $a, 3);
        $first = $this->nowDb();
        $this->later('7 minutes');
        // A whole page saved again: the line that did not change keeps its time, the other gets this moment.
        self::assertSame(1, $this->sheets->setQuantities($sc, $sheet->id, [$this->lineOf($sheet->id, $a) => 3, $this->lineOf($sheet->id, $b) => 4]));
        self::assertSame([$first, $this->nowDb()], [$this->sheetLine($sheet->id, $a)['counted_at'], $this->sheetLine($sheet->id, $b)['counted_at']]);
    }

    public function testLinesCountedAtDifferentMomentsAreBookedUnderTimesTheEngineReadsTheSameWay(): void
    {
        [$a, $b, $c, $d] = [$this->item('strict', 4), $this->item('strict', 4), $this->item('strict', 4), $this->item('strict', 4)];
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a, $b, $c, $d]);
        $this->enter($sc, $sheet, $a, 4);
        $t0 = $this->nowDb();
        $this->later('5 minutes');
        $this->enter($sc, $sheet, $b, 3);
        $this->later('15 minutes');
        $this->enter($sc, $sheet, $c, 4);
        $t20 = $this->nowDb();
        $this->later('2 minutes');
        $this->book('goods_in', $d, 6); // D's stock moves BEFORE it is counted
        $this->later('3 minutes');
        $this->enter($sc, $sheet, $d, 10);
        $t25 = $this->nowDb();
        $this->later('1 minute');
        $posted = $this->post($sc, $sheet);
        // A and B (five minutes apart, nothing moved) share A's time; C was counted more than ten minutes later; D's delivery came
        // after C's time and before D was counted, so D cannot share C's time: under it the engine would overwrite the delivery.
        self::assertSame([[$a, 'count', 0, $t0], [$b, 'count', -1, $t0], [$c, 'count', 0, $t20], [$d, 'count', 0, $t25]], $this->ledgerOf($posted->id));
        self::assertSame([$t0, $t0, $t20, $t25], [$this->countedAt($a), $this->countedAt($b), $this->countedAt($c), $this->countedAt($d)]);
        self::assertSame([4, 3, 4, 10], [$this->onHand($a), $this->onHand($b), $this->onHand($c), $this->onHand($d)]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM count_review'), 'nothing was overwritten, so the engine asks nothing');
    }

    public function testAnItemThatLeftAfterItsLineWasCountedIsShownFirstAndBothChoicesDoWhatTheySay(): void
    {
        $site = $this->site();
        $a = $this->item('strict', 10);
        $other = $this->item('strict', 6);
        $this->listing($site, 'V1', $a);
        $this->commit($site, '101', [self::line('V1', 'a', 'b')]);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a, $other]);
        $this->enter($sc, $sheet, $a, 10);
        $this->enter($sc, $sheet, $other, 6);
        $counted = $this->nowDb();
        $this->later('20 minutes');
        $this->ship($site, '101', ['a', 'b'], Clock::iso(Clock::db($this->now->modify('-5 minutes')))); // both left 15 minutes after the count
        self::assertSame(8, $this->onHand($a));

        $before = $this->ledgerCount();
        $e = self::refusedWith('moved_since', fn () => $this->post($sc, $sheet));
        self::assertSame([$this->lineOf($sheet->id, $a)], $e->detail['lines'], 'the refusal names the line; the other line is fine');
        self::assertSame([$before, 'draft', 0], [$this->ledgerCount(), $this->docs->get($sheet->id)->status,
            (int) self::$db->value('SELECT COUNT(*) FROM document_line WHERE document_id = ?', [$sheet->id])], 'nothing was booked and nothing adjusted behind the person\'s back');
        $diff = $this->sheets->differences($sheet->id);
        $line = $diff['lines'][array_search($a, array_column($diff['lines'], 'sku_id'), true)];
        self::assertSame([true, false, 2, -2, 0], [$line['needs_choice'], $line['acked'], $line['moved']['n'], $line['moved']['ships'], $line['moved']['others']]);
        self::assertSame(['ship', 'ship'], array_column($line['moved']['rows'], 'movement_type'));
        self::assertSame(0, $line['delta'], 'counted 10, 2 left after the count, the system holds 8: no difference');
        self::assertSame(1, $diff['needs_choice']);

        // Choice 1: post it as counted. The engine's as-of rule does the arithmetic: 10 counted less the 2 that left = 8.
        $this->sheets->postAsCounted($sc, $sheet->id, $this->lineOf($sheet->id, $a), $line['moved']['max_id']);
        self::assertTrue($this->sheets->differences($sheet->id)['lines'][0]['acked'] || $this->sheets->differences($sheet->id)['lines'][1]['acked']);
        $posted = $this->post($sc, $sheet);
        self::assertSame([[$a, 'count', 0, $counted], [$other, 'count', 0, $counted]], $this->ledgerOf($posted->id));
        self::assertSame([8, $counted], [$this->onHand($a), $this->countedAt($a)]);
        self::assertSame('counted=10 ships_after=-2', self::$db->value("SELECT note FROM stock_ledger WHERE document_id = ? AND sku_id = ?", [$posted->id, $a]));

        // Choice 2, on a second sheet: count it again. The line is empty again, and the new count is after everything that moved.
        $this->commit($site, '102', [self::line('V1', 'c')]);
        $two = $this->sheet($sc, [$a]);
        $this->enter($sc, $two, $a, 8);
        $this->later('20 minutes');
        $this->ship($site, '102', ['c'], Clock::iso(Clock::db($this->now->modify('-5 minutes'))));
        self::refusedWith('moved_since', fn () => $this->post($sc, $two));
        $this->sheets->recount($sc, $two->id, $this->lineOf($two->id, $a));
        self::assertNull($this->sheetLine($two->id, $a)['counted_qty']);
        self::refusedWith('nothing_counted', fn () => $this->post($sc, $two));
        $this->enter($sc, $two, $a, 7);
        $again = $this->nowDb();
        $done = $this->post($sc, $two);
        self::assertSame([[$a, 'count', 0, $again]], $this->ledgerOf($done->id));
        self::assertSame([7, $again], [$this->onHand($a), $this->countedAt($a)]);
    }

    public function testADeliveryAfterTheCountIsShownFirstPostedAsCountedItIsOverwrittenAndANewMovementAsksAgain(): void
    {
        $b = $this->item('strict', 5);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$b]);
        $this->enter($sc, $sheet, $b, 5);
        $counted = $this->nowDb();
        $this->later('30 minutes');
        $this->book('goods_in', $b, 6);
        self::refusedWith('moved_since', fn () => $this->post($sc, $sheet));
        $line = $this->sheets->differences($sheet->id)['lines'][0];
        self::assertSame([1, 0, 6, -6], [$line['moved']['n'], $line['moved']['ships'], $line['moved']['others'], $line['delta']], 'the count would take the delivery off again');
        $this->sheets->postAsCounted($sc, $sheet->id, 1, $line['moved']['max_id']);
        // Something else moves before the posting: the person has not seen it, so the question comes back.
        $this->later('5 minutes');
        $this->book('goods_in', $b, 1);
        self::refusedWith('moved_since', fn () => $this->post($sc, $sheet));
        $line = $this->sheets->differences($sheet->id)['lines'][0];
        self::assertSame([true, 2, 7], [$line['needs_choice'], $line['moved']['n'], $line['moved']['others']]);
        // A movement the person was never shown cannot be waved through by a hand-made form.
        self::refusedWith('moved_since', fn () => $this->sheets->postAsCounted($sc, $sheet->id, 1, $line['moved']['max_id'] + 1000));
        $this->sheets->postAsCounted($sc, $sheet->id, 1, $line['moved']['max_id']);
        $posted = $this->post($sc, $sheet);
        self::assertSame([[$b, 'count', -7, $counted]], $this->ledgerOf($posted->id));
        self::assertSame(5, $this->onHand($b), 'posted as counted: the shelf figure is the count');
        // The engine's own review says what was overwritten, and the difference is above the tolerance too.
        self::assertSame(['count_after_movements', 'open', $posted->number], array_values((array) self::$db->one('SELECT source, status, ref FROM count_review WHERE sku_id = ?', [$b])));
        self::assertSame('open', $this->sheetLine($sheet->id, $b)['review']);
    }

    public function testALineOlderThanAPostedCountOfTheItemIsLeftOutAndTheSheetSaysSo(): void
    {
        $a = $this->item('strict', 9);
        $b = $this->item('strict', 2);
        $sc = $this->staffUser('stock_controller');
        $old = $this->sheet($sc, [$a, $b]);
        $this->enter($sc, $old, $a, 7);
        $this->enter($sc, $old, $b, 2);
        $this->later('20 minutes');
        $new = $this->sheet($sc, [$a]);
        $this->enter($sc, $new, $a, 9);
        $newer = $this->nowDb();
        $this->post($sc, $new);
        $this->later('5 minutes');
        $diff = $this->sheets->differences($old->id);
        $line = $diff['lines'][array_search($a, array_column($diff['lines'], 'sku_id'), true)];
        self::assertSame([true, false, 1], [$line['stale'], $line['needs_choice'], $diff['stale']], 'said before posting: a later count exists, the line will be left out');
        $posted = $this->post($sc, $old);
        self::assertSame([[$b, 'count', 0, self::$db->value('SELECT counted_at FROM count_sheet_line WHERE document_id = ? AND sku_id = ?', [$old->id, $b])]], $this->ledgerOf($posted->id));
        self::assertSame(['stale', null, 9], [$this->sheetLine($old->id, $a)['outcome'], $this->sheetLine($old->id, $a)['booked_delta'], (int) $this->sheetLine($old->id, $a)['system_qty']]);
        self::assertSame([9, $newer], [$this->onHand($a), $this->countedAt($a)]);
    }

    public function testACountFromYesterdayIsPostedWithItsOwnTimeAndOneOlderThanTheEngineTakesIsRefused(): void
    {
        $a = $this->item('strict', 3);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a]);
        $this->enter($sc, $sheet, $a, 2);
        $counted = $this->nowDb();
        $this->later('2 days');
        $posted = $this->post($sc, $sheet);
        self::assertSame([[$a, 'count', -1, $counted]], $this->ledgerOf($posted->id), 'older than a day: booked as a count typed in later, with the time it was counted');
        self::assertSame($counted, $this->countedAt($a));

        $late = $this->sheet($sc, [$a]);
        $this->enter($sc, $late, $a, 2);
        $this->later('91 days');
        $e = self::refusedWith('count_too_old', fn () => $this->post($sc, $late));
        self::assertSame([1], $e->detail['lines']);
        self::assertSame('draft', $this->docs->get($late->id)->status);
    }

    public function testTheScanBoxFindsALineOffersAnItemNotOnTheSheetAndNeverGuesses(): void
    {
        $a = $this->item('strict', 1);
        $b = $this->item('strict', 1);
        $twin = $this->item('strict', 1);
        $sc = $this->staffUser('stock_controller');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan) VALUES ('5060000000017', ?, 1, 1), ('5060000000024', ?, 1, 1), "
            . "('15060000000014', ?, 1, 10), ('5060000000031', ?, 0, 1)", [$a, $b, $a, $twin]);
        $sheet = $this->sheet($sc, [$a]);
        self::assertSame(['status' => 'on_sheet', 'line_no' => 1, 'sku_id' => $a, 'units' => 1], $this->sheets->find($sheet->id, ' 5060000000017 '));
        self::assertSame(['on_sheet', 10], [$this->sheets->find($sheet->id, '15060000000014')['status'], $this->sheets->find($sheet->id, '15060000000014')['units']],
            'a case barcode goes to the item\'s line and says how many units it stands for');
        $off = $this->sheets->find($sheet->id, '5060000000024');
        self::assertSame(['not_on_sheet', $b], [$off['status'], $off['sku_id']]);
        self::assertSame(['status' => 'unknown'], $this->sheets->find($sheet->id, '5060000000999'), 'on no item: it says so');
        self::assertSame(['status' => 'ambiguous'], $this->sheets->find($sheet->id, '5060000000031'), 'a barcode found on two items is never used');
        self::assertSame('on_sheet', $this->sheets->find($sheet->id, sprintf('CW-%06d', $a))['status'], 'a CW number typed in');
        self::assertSame('not_on_sheet', $this->sheets->find($sheet->id, 'cw' . $b)['status']);
        self::assertSame(['status' => 'unknown'], $this->sheets->find($sheet->id, (string) $b), 'bare digits are a barcode, never read as a CW number');
        self::assertSame(['status' => 'unknown'], $this->sheets->find($sheet->id, ''));
        // The offer taken: the item is on the sheet, with what the system holds now; taking it twice adds nothing.
        self::assertSame(['line_no' => 2, 'added' => true], $this->sheets->addItem($sc, $sheet->id, $b));
        self::assertSame(['line_no' => 2, 'added' => false], $this->sheets->addItem($sc, $sheet->id, $b));
        self::assertSame('on_sheet', $this->sheets->find($sheet->id, '5060000000024')['status']);
        // A merged item is not added.
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$a, $twin]);
        self::refusedWith('merged_item', fn () => $this->sheets->addItem($sc, $sheet->id, $twin));
        self::$db->exec('UPDATE sku SET merged_into_sku_id = NULL WHERE id = ?', [$twin]);
    }

    public function testTheDownloadComesBackAsAnUploadWhosePreviewWritesNothing(): void
    {
        $a = $this->item('strict', 10, 'Alpha pod');
        $b = $this->item('strict', 5, '=SUM(A1) liquid');
        $c = $this->item('strict', 2, 'Gamma kit');
        $stranger = $this->item('strict', 1, 'Not on the sheet');
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a, $b, $c]);
        $this->enter($sc, $sheet, $c, 2);
        $code = static fn (int $id): string => sprintf('CW-%06d', $id);

        // The download: one row per line, the system's figure beside it (the setting), an empty column to fill in.
        $file = sys_get_temp_dir() . '/cw_count_' . bin2hex(random_bytes(5)) . '.csv';
        try {
            file_put_contents($file, $this->sheets->csv($sheet->id));
            $table = CsvReader::table($file);
            self::assertSame(['line', 'cw_code', 'item', 'brand', 'barcodes', 'stock_now', 'counted'], $table['header']);
            $rows = iterator_to_array($table['rows'], false);
            self::assertSame([[$code($a), '10', ''], [$code($b), '5', ''], [$code($c), '2', '2']], array_map(static fn (array $r): array => [$r['cw_code'], $r['stock_now'], $r['counted']], $rows));
            self::assertSame("'=SUM(A1) liquid", $rows[1]['item'], 'a name that looks like a formula is written as text');
            $this->setting('counts.sheet_shows_stock', false);
            file_put_contents($file, $this->sheets->csv($sheet->id));
            self::assertSame(['line', 'cw_code', 'item', 'brand', 'barcodes', 'counted'], CsvReader::table($file)['header'], 'a blind count: the file does not say what the system holds');

            // The file comes back filled in (and with rows it should not have).
            file_put_contents($file, "cw_code,counted,anything\r\n{$code($a)},8,x\r\n{$code($b)},,\r\n{$code($c)},2,\r\n{$code($stranger)},4,\r\nCW-999999,1,\r\n"
                . "{$code($a)},9,\r\n,3,\r\n");
            $sums = fn (): array => array_map(static fn (string $t): string => (string) self::$db->one("CHECKSUM TABLE {$t}")['Checksum'],
                ['count_sheet_line', 'count_sheet', 'document', 'document_line', 'stock_ledger', 'stock_balance', 'audit_log']);
            $before = $sums();
            $this->later('10 minutes');
            $preview = $this->sheets->readUpload($sheet->id, $file);
            self::assertSame($before, $sums(), 'the preview writes nothing');
            self::assertSame([['row' => 1, 'line_no' => 1, 'code' => $code($a), 'name' => 'Alpha pod', 'from' => null, 'to' => 8]], $preview['fill']);
            self::assertSame([1, 1], [$preview['same'], $preview['blank']], 'a line that already holds that quantity, and a row left empty');
            self::assertSame([['row' => 4, 'code' => $code($stranger)], ['row' => 5, 'code' => 'CW-999999']], $preview['not_on_sheet']);
            self::assertSame([['row' => 6, 'code' => $code($a), 'why' => 'twice', 'value' => '9'], ['row' => 7, 'code' => '', 'why' => 'no_code', 'value' => '3']], $preview['bad']);
            self::assertFalse($preview['applied']);

            // Confirmed: only the sheet's own lines are filled, with the time the person said it was counted; nothing is posted.
            $when = $this->now->modify('-2 hours');
            $done = $this->sheets->applyUpload($sc, $sheet->id, $file, $when);
            self::assertSame([true, 1], [$done['applied'], $done['filled']]);
            self::assertSame([8, Clock::db($when), $this->nowDb(), 'upload', $sc->staffUserId], [(int) $this->sheetLine($sheet->id, $a)['counted_qty'], $this->sheetLine($sheet->id, $a)['counted_at'],
                $this->sheetLine($sheet->id, $a)['entered_at'], $this->sheetLine($sheet->id, $a)['entered_how'], (int) $this->sheetLine($sheet->id, $a)['counted_by']]);
            self::assertNull($this->sheetLine($sheet->id, $b)['counted_qty'], 'an empty cell leaves the line as it was');
            self::assertSame('typed', $this->sheetLine($sheet->id, $c)['entered_how'], 'a line that already held that quantity keeps its own time');
            self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ?', [$sheet->id]), 'no line was added');
            self::assertSame(['draft', 0], [$this->docs->get($sheet->id)->status, $this->ledgerCountOf($sheet->id)], 'an upload never posts');

            // Quantities that cannot be read are skipped and said; the rest is filled.
            file_put_contents($file, "cw_code;counted\n{$code($a)};1.5\n{$code($b)};-2\n{$code($c)};99999999999\n{$code($a)};seven\n{$code($b)};3\n");
            $mixed = $this->sheets->readUpload($sheet->id, $file);
            self::assertSame([['not_whole', '1.5'], ['negative', '-2'], ['too_large', '99999999999'], ['not_whole', 'seven']], array_map(static fn (array $x): array => [$x['why'], $x['value']], $mixed['bad']));
            self::assertSame([[5, 3]], array_map(static fn (array $x): array => [$x['row'], $x['to']], $mixed['fill']));
            // A time in the future, or older than the engine takes, is refused before anything is filled.
            self::refusedWith('bad_counted_at', fn () => $this->sheets->applyUpload($sc, $sheet->id, $file, $this->now->modify('+1 hour')));
            // Not a minute after now either (review M-6, 14 Oct 2026: CT12 says "not after now"; a count time in the future would keep the
            // next dispatches on the shelf figure).
            self::refusedWith('bad_counted_at', fn () => $this->sheets->applyUpload($sc, $sheet->id, $file, $this->now->modify('+1 minute')));
            self::refusedWith('bad_counted_at', fn () => $this->sheets->applyUpload($sc, $sheet->id, $file, $this->now->modify('+1 second')));
            self::refusedWith('bad_counted_at', fn () => $this->sheets->applyUpload($sc, $sheet->id, $file, $this->now->modify('-91 days')));
            self::assertNull($this->sheetLine($sheet->id, $b)['counted_qty']);
            // A file that is no table of this kind is refused as a whole.
            file_put_contents($file, "name,qty\nAlpha,3\n");
            self::refusedWith('bad_file', fn () => $this->sheets->readUpload($sheet->id, $file));
            file_put_contents($file, "\x00\x01\x02 not a csv \xFF\xFE");
            self::refusedWith('bad_file', fn () => $this->sheets->readUpload($sheet->id, $file));
            file_put_contents($file, '');
            self::refusedWith('bad_file', fn () => $this->sheets->applyUpload($sc, $sheet->id, $file, null));
        } finally {
            @unlink($file);
        }
    }

    public function testWhoMayOpenEnterAndPostASheet(): void
    {
        $a = $this->item('strict', 3);
        $sc = $this->staffUser('stock_controller');
        $wh = $this->staffUser('warehouse');
        self::refusedWith('role_not_allowed', fn () => $this->sheet($this->staffUser('buyer'), [$a]));
        self::refusedWith('admin_cannot_post', fn () => $this->sheet($this->staffUser('admin'), [$a]));
        self::refusedWith('role_not_allowed', fn () => $this->sheet($this->staffUser('reviewer'), [$a]));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM count_sheet'));
        $sheet = $this->sheet($wh, [$a]);
        // The person who opened a sheet enters and posts it (the document base's rule for a draft).
        self::refusedWith('not_creator', fn () => $this->enter($sc, $sheet, $a, 3));
        self::refusedWith('not_creator', fn () => $this->sheets->addItem($sc, $sheet->id, $this->item('strict', 1)));
        $this->enter($wh, $sheet, $a, 3);
        self::refusedWith('not_creator', fn () => $this->post($sc, $sheet));
        self::refusedWith('role_not_allowed', fn () => $this->post($this->staffUser('buyer'), $sheet));
        self::assertSame('draft', $this->docs->get($sheet->id)->status);
        self::assertSame('posted', $this->post($wh, $sheet)->status);
        // A sheet names a warehouse that is switched on, and items that exist, each once.
        self::refusedWith('warehouse_required', fn () => $this->sheets->open($sc, [], [$a]));
        self::refusedWith('unknown_sku', fn () => $this->sheet($sc, [999_999]));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ?', [$this->sheet($sc, [$a, $a])->id]));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ?', [$this->sheet($sc, [])->id]), 'an empty sheet: items come by the scan box');
        // Another kind of record is not a count sheet.
        self::refusedWith('not_count_sheet', fn () => $this->sheets->setQuantities($sc, 999_999, [1 => 1]));
    }

    public function testMoreCountTimesThanTheLimitAllowsAreRefusedTheEarliestLinesAreMarkedAndCountingThemAgainLetsTheSheetGoFinal(): void
    {
        // Review I-1, M-2 (14 Oct 2026): 21 lines, each counted 11 minutes after the one before. At the ten-minute limit (the setting
        // counts.time_window_max_minutes) each needs a count time of its own: 21, one more than a posting has room for. The first
        // version widened the window behind the person's back; now the sheet is refused, the lines to count again are marked (the two
        // earliest groups: counted again close together, they share one time), and nothing is booked.
        $sc = $this->staffUser('stock_controller');
        $items = [];
        for ($i = 0; $i < 21; $i++) {
            $items[] = $this->item('strict', 0);
        }
        $sheet = $this->sheet($sc, $items);
        foreach ($items as $i => $sku) {
            if ($i > 0) {
                $this->later('11 minutes');
            }
            $this->enter($sc, $sheet, $sku, 1);
        }
        $this->later('1 minute');
        $ledger = $this->ledgerCount();
        $e = self::refusedWith('too_many_times', fn () => $this->post($sc, $sheet));
        self::assertSame([409, 20], [$e->httpStatus, $e->detail['max'] ?? null]);
        self::assertSame([$ledger, 'draft'], [$this->ledgerCount(), $this->docs->get($sheet->id)->status], 'nothing was booked');
        $d = $this->sheets->differences($sheet->id);
        self::assertTrue($d['too_many_times']);
        self::assertSame(600, $d['window'], 'never wider than the limit');
        $marked = static fn (array $d): array => array_values(array_map(static fn (array $l): int => $l['line_no'],
            array_filter($d['lines'], static fn (array $l): bool => $l['retime'])));
        $mine = $marked($d);
        sort($mine);
        self::assertSame([1, 2], $mine, 'the lines of the two earliest times');
        self::assertSame(2, $d['retime']);

        // The owner may raise the limit (a setting, never hard-coded): 30 minutes fits these 21 lines in 11 times of 20 minutes.
        $this->setting('counts.time_window_max_minutes', 30);
        $wide = $this->sheets->differences($sheet->id);
        self::assertSame([false, 1200, []], [$wide['too_many_times'], $wide['window'], $marked($wide)]);
        $this->setting('counts.time_window_max_minutes', 10);

        // "Count the marked lines again" empties exactly them; counted again one minute apart they share the newest line's time.
        self::refusedWith('not_creator', fn () => $this->sheets->recountForTimes($this->staffUser('warehouse'), $sheet->id));
        self::assertSame(2, $this->sheets->recountForTimes($sc, $sheet->id));
        self::assertSame([null, null, 1], [$this->sheetLine($sheet->id, $items[0])['counted_qty'], $this->sheetLine($sheet->id, $items[1])['counted_qty'],
            (int) $this->sheetLine($sheet->id, $items[2])['counted_qty']]);
        self::assertSame(0, $this->sheets->recountForTimes($sc, $sheet->id), 'nothing is marked any more');
        $this->enter($sc, $sheet, $items[0], 1);
        $this->later('1 minute');
        $this->enter($sc, $sheet, $items[1], 1);
        $this->later('1 minute');
        $doc = $this->post($sc, $sheet);
        self::assertSame('posted', $doc->status);
        self::assertCount(19, array_unique(array_column($this->ledgerOf($doc->id), 3)), '19 count times: 18 of their own, and the newest shared by three lines');
    }

    public function testStockThatMovesBetweenTheCheckAndTheBookingIsRefusedWith409AndNotAnError(): void
    {
        // Review M-3 (14 Oct 2026): post() works the plan out again; when a movement lands after validate() (the posting may wait on the
        // CNT number series), it says the same 409 as validate(), not an error page.
        $a = $this->item('strict', 5);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a]);
        $this->enter($sc, $sheet, $a, 5);
        $this->later('5 minutes');
        $this->book('goods_in', $a, 2);
        $handler = new \CW\Counts\CountSheetHandler(self::$db, fn (): \DateTimeImmutable => $this->now);
        $lines = [['line_no' => 1, 'sku_id' => $a, 'qty' => 5, 'warehouse_id' => null, 'unit_cost' => null, 'reason_code' => null]];
        $doc = $this->docs->get($sheet->id);
        $e = self::refusedWith('moved_since', fn () => self::$db->transaction(fn () => $handler->post(self::$db, $doc, $lines, $sc, 'test:m3')));
        self::assertSame([409, [1]], [$e->httpStatus, $e->detail['lines'] ?? null]);
        self::assertSame([7, null], [$this->onHand($a), $this->sheetLine($sheet->id, $a)['outcome']], 'nothing was booked or written');
    }

    public function testEachLineKeepsTheMomentItsBoxWasFilledInAndATimeAfterNowOrBeforeTheLineIsRefused(): void
    {
        // Review M-7 (14 Oct 2026): a page saved once, after boxes were filled in one by one with Tab, gave every line the moment Enter
        // was pressed. Each box now brings the moment it was filled in (app.js); the server keeps it when it is not after now and not
        // before the line was put on the sheet.
        $a = $this->item('strict', 5);
        $b = $this->item('strict', 4);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a, $b]);
        $opened = $this->now;
        $this->later('10 minutes');
        $filled = $this->now->modify('-6 minutes');
        self::assertSame(2, $this->sheets->setQuantities($sc, $sheet->id, [$this->lineOf($sheet->id, $a) => 5, $this->lineOf($sheet->id, $b) => 3], null, 'typed',
            [$this->lineOf($sheet->id, $a) => $filled]));
        self::assertSame([Clock::db($filled), $this->nowDb(), $this->nowDb()], [$this->sheetLine($sheet->id, $a)['counted_at'], $this->sheetLine($sheet->id, $b)['counted_at'],
            $this->sheetLine($sheet->id, $a)['entered_at']], 'line A keeps the moment its box was filled in; B, with none, takes now');
        // After now, or before the line was on the sheet: refused, and nothing of the page is saved.
        foreach ([$this->now->modify('+1 second'), $opened->modify('-1 second')] as $bad) {
            $e = self::refusedWith('bad_count_time', fn () => $this->sheets->setQuantities($sc, $sheet->id, [$this->lineOf($sheet->id, $a) => 6, $this->lineOf($sheet->id, $b) => 4],
                null, 'typed', [$this->lineOf($sheet->id, $b) => $bad]));
            self::assertSame([422, $this->lineOf($sheet->id, $b)], [$e->httpStatus, $e->detail['line'] ?? null]);
        }
        self::assertSame([5, 3], [(int) $this->sheetLine($sheet->id, $a)['counted_qty'], (int) $this->sheetLine($sheet->id, $b)['counted_qty']]);
        // The moment the line was put on the sheet, and now itself, are inside.
        self::assertSame(2, $this->sheets->setQuantities($sc, $sheet->id, [$this->lineOf($sheet->id, $a) => 6, $this->lineOf($sheet->id, $b) => 4], null, 'typed',
            [$this->lineOf($sheet->id, $a) => $opened, $this->lineOf($sheet->id, $b) => $this->now]));
        self::assertSame([Clock::db($opened), $this->nowDb()], [$this->sheetLine($sheet->id, $a)['counted_at'], $this->sheetLine($sheet->id, $b)['counted_at']]);
    }

    private function ledgerCountOf(int $documentId): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$documentId]);
    }
}
