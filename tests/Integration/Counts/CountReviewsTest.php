<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Counts;

use CW\Caller;
use CW\Documents\Document;

/**
 * The count differences to check, and the count plan (pack A2a; docs/decisions.md CT6-CT12): which posted lines open a check (at,
 * below and above each limit; 0 units = every difference; percent 0 = not used; an item that held nothing), accepting one (the count
 * stays) and rejecting one (that line's count is put back by a record, which says who), who may clear one with the second-person
 * rule off and on, the engine's own two kinds of review in the same list, and the plan with "Next to count".
 */
final class CountReviewsTest extends CountsTestCase
{
    /**
     * A sheet opened, counted and posted by $who: item => quantity.
     *
     * @param array<int, int> $counts
     */
    private function posted(Caller $who, array $counts): Document
    {
        $sheet = $this->sheet($who, array_keys($counts));
        $this->sheets->setQuantities($who, $sheet->id, array_combine(array_map(fn (int $sku): int => $this->lineOf($sheet->id, $sku), array_keys($counts)), array_values($counts)));
        return $this->post($who, $sheet);
    }

    /** @return list<int> the items of the lines with an open check, in line order */
    private function openOn(int $sheetId): array
    {
        return array_map('intval', self::$db->column("SELECT sku_id FROM count_sheet_line WHERE document_id = ? AND review = 'open' ORDER BY line_no", [$sheetId]));
    }

    public function testAPostedLineOpensACheckOnlyAboveALimit(): void
    {
        $atUnits = $this->item('strict', 100);    // 95: 5 units, 5 percent
        $overUnits = $this->item('strict', 100);  // 94: 6 units
        $overPercent = $this->item('strict', 20); // 17: 3 units, 15 percent
        $atPercent = $this->item('strict', 30);   // 27: 3 units, exactly 10 percent
        $found = $this->item('legacy', 0);        // 1 found where none was held
        $same = $this->item('strict', 50);
        $up = $this->item('strict', 40);          // 46: found counts as missing does
        $sc = $this->staffUser('stock_controller');
        $doc = $this->posted($sc, [$atUnits => 95, $overUnits => 94, $overPercent => 17, $atPercent => 27, $found => 1, $same => 50, $up => 46]);
        self::assertSame('posted', $doc->status);
        self::assertSame([$overUnits, $overPercent, $found, $up], $this->openOn($doc->id));
        self::assertSame([5, 10], [(int) $this->sheetLine($doc->id, $overUnits)['review_units'], (int) $this->sheetLine($doc->id, $overUnits)['review_percent']],
            'the check says the limits it was opened under');
        self::assertSame('none', $this->sheetLine($doc->id, $atUnits)['review']);
        // The counts are booked whatever the check says: the shelf figure is right straight away.
        self::assertSame([95, 94, 17, 27, 1, 50, 46], array_map(fn (int $s): int => $this->onHand($s), [$atUnits, $overUnits, $overPercent, $atPercent, $found, $same, $up]));

        // 0 units: every difference. Percent 0: not used.
        $this->later('1 hour');
        $this->setting('counts.review_over_units', 0);
        $this->setting('counts.review_over_percent', 0);
        [$one, $none] = [$this->item('strict', 10), $this->item('strict', 10)];
        $doc = $this->posted($sc, [$one => 9, $none => 10]);
        self::assertSame([$one], $this->openOn($doc->id));
        $this->setting('counts.review_over_units', 1000);
        [$big, $zero] = [$this->item('strict', 10), $this->item('legacy', 0)];
        $doc = $this->posted($sc, [$big => 1, $zero => 7]);
        self::assertSame([], $this->openOn($doc->id), 'the percent is not used, so nothing held is no reason either');
        $this->setting('counts.review_over_percent', 50);
        [$half, $more] = [$this->item('strict', 10), $this->item('strict', 10)];
        $doc = $this->posted($sc, [$half => 5, $more => 4]);
        self::assertSame([$more], $this->openOn($doc->id));
    }

    public function testAcceptingKeepsTheCountAndRejectingPutsThatLinesCountBackByARecord(): void
    {
        $a = $this->item('strict', 100);
        $b = $this->item('strict', 100);
        $c = $this->item('strict', 30);
        $sc = $this->staffUser('stock_controller');
        $doc = $this->posted($sc, [$a => 90, $b => 80, $c => 30]);
        self::assertSame([$a, $b], $this->openOn($doc->id));
        $list = $this->reviews->open();
        self::assertSame([[$doc->id, $b, 100, 80, -20], [$doc->id, $a, 100, 90, -10]], array_map(static fn (array $r): array => [$r['document_id'], $r['sku_id'], $r['held'], $r['counted'], $r['delta']],
            $list['lines']), 'the item, the sheet, what was held, what was counted, the difference; the newest line first');
        self::assertSame([$doc->number, $sc->staffUserId], [$list['lines'][0]['number'], $list['lines'][0]['counted_by']]);
        self::assertSame([], $list['engine']);

        // Accept: the count stays; the row says who and why. A note is needed.
        self::refusedWith('note_required', fn () => $this->reviews->acceptLine($sc, $doc->id, $this->lineOf($doc->id, $a), ' '));
        $this->later('10 minutes');
        $this->reviews->acceptLine($sc, $doc->id, $this->lineOf($doc->id, $a), 'two boxes were damaged and thrown away');
        self::assertSame(['accepted', $sc->staffUserId, $this->nowDb(), 'two boxes were damaged and thrown away', null], [$this->sheetLine($doc->id, $a)['review'],
            (int) $this->sheetLine($doc->id, $a)['reviewed_by'], $this->sheetLine($doc->id, $a)['reviewed_at'], $this->sheetLine($doc->id, $a)['review_note'], $this->sheetLine($doc->id, $a)['reversal_document_id']]);
        self::assertSame(90, $this->onHand($a));
        self::refusedWith('not_open', fn () => $this->reviews->acceptLine($sc, $doc->id, $this->lineOf($doc->id, $a), 'again'));

        // Reject: a new record puts back what that line's count changed, and only that; never a delete.
        $counted = $this->countedAt($b);
        $back = $this->reviews->rejectLine($sc, $doc->id, $this->lineOf($doc->id, $b), 'counted the wrong shelf');
        self::assertSame(['posted', 'CNT-000002', 'CNT', null], [$back->status, $back->number, $back->docType, $back->reversesId]);
        self::assertSame(['line_reversal', $doc->id, $this->lineOf($doc->id, $b)], array_values((array) self::$db->one('SELECT kind, reverses_document_id, reverses_line_no FROM count_sheet WHERE document_id = ?', [$back->id])));
        self::assertSame([[$b, 'adjustment', 20, $this->nowDb()]], $this->ledgerOf($back->id));
        self::assertSame([100, 90, 30], [$this->onHand($b), $this->onHand($a), $this->onHand($c)]);
        self::assertSame(['rejected', $sc->staffUserId, 'counted the wrong shelf', $back->id], [$this->sheetLine($doc->id, $b)['review'], (int) $this->sheetLine($doc->id, $b)['reviewed_by'],
            $this->sheetLine($doc->id, $b)['review_note'], (int) $this->sheetLine($doc->id, $b)['reversal_document_id']]);
        self::assertSame('posted', $this->docs->get($doc->id)->status, 'the sheet stands: its other lines are as they were');
        self::assertSame($counted, $this->countedAt($b), 'the count time stays, as when the engine reverses a count');
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$doc->id]), 'nothing of the sheet was removed');
        self::assertSame([], $this->reviews->open()['lines']);
        // Once only; and a sheet with a line put back cannot be cancelled as a whole afterwards (it would put that line back twice).
        self::refusedWith('not_open', fn () => $this->reviews->rejectLine($sc, $doc->id, $this->lineOf($doc->id, $b), 'again please'));
        $ledger = $this->ledgerCount();
        self::refusedWith('line_put_back', fn () => $this->sheets->reverse($sc, $doc->id, 'entered_in_error', null));
        self::assertSame([$ledger, 'posted'], [$this->ledgerCount(), $this->docs->get($doc->id)->status]);
        self::refusedWith('note_required', fn () => $this->reviews->rejectLine($sc, $doc->id, $this->lineOf($doc->id, $c), 'no'));
    }

    public function testASecondPersonIsNeededOnlyWhileTheRuleOfStockCountsAsksForOne(): void
    {
        $sc = $this->staffUser('stock_controller');
        $other = $this->staffUser('warehouse');
        [$a, $b, $c, $d] = [$this->item('strict', 100), $this->item('strict', 100), $this->item('strict', 100), $this->item('strict', 100)];
        $doc = $this->posted($sc, [$a => 80, $b => 80, $c => 92, $d => 80]);
        $line = fn (int $sku): int => $this->lineOf($doc->id, $sku);
        // Nobody without the job, and never admin.
        self::refusedWith('role_not_allowed', fn () => $this->reviews->acceptLine($this->staffUser('buyer'), $doc->id, $line($a), 'looks fine'));
        self::refusedWith('role_not_allowed', fn () => $this->reviews->rejectLine($this->staffUser('reviewer'), $doc->id, $line($a), 'looks wrong'));
        self::refusedWith('admin_cannot_post', fn () => $this->reviews->acceptLine($this->staffUser('admin'), $doc->id, $line($a), 'looks fine'));
        self::assertSame(null, $this->reviews->refusal($sc->staffUserId, ['stock_controller'], $this->reviews->open()['lines'][0]), 'the rule is off: the person who counted may clear it');

        // Every one: the person who counted, opened and posted the sheet is refused; someone else with the job is not.
        $this->countRule('all');
        $e = self::refusedWith('own_count', fn () => $this->reviews->acceptLine($sc, $doc->id, $line($a), 'my own count is right'));
        self::assertSame(403, $e->httpStatus);
        self::refusedWith('own_count', fn () => $this->reviews->rejectLine($sc, $doc->id, $line($a), 'my own count is wrong'));
        self::assertSame('own_count', $this->reviews->refusal($sc->staffUserId, ['stock_controller'], $this->reviews->open()['lines'][0])['code']);
        self::assertSame('open', $this->sheetLine($doc->id, $a)['review']);
        $this->reviews->acceptLine($other, $doc->id, $line($a), 'checked the shelf with them');
        self::assertSame(['accepted', $other->staffUserId], [$this->sheetLine($doc->id, $a)['review'], (int) $this->sheetLine($doc->id, $a)['reviewed_by']]);
        $back = $this->reviews->rejectLine($other, $doc->id, $line($b), 'recounted: 100 are there');
        self::assertSame([$other->staffUserId, 100], [$back->postedBy, $this->onHand($b)]);

        // Only over a limit: a difference above it needs the second person, one at or below it does not.
        $this->countRule('over_limit', 10);
        $this->reviews->acceptLine($sc, $doc->id, $line($c), '8 missing: a broken case, written off');
        self::assertSame('accepted', $this->sheetLine($doc->id, $c)['review']);
        self::refusedWith('own_count', fn () => $this->reviews->acceptLine($sc, $doc->id, $line($d), '20 missing'));
        // Off again: the person who counted clears their own, and the row says who did.
        $this->countRule('none');
        $this->reviews->acceptLine($sc, $doc->id, $line($d), '20 missing: sent to the shop, not booked yet');
        self::assertSame($sc->staffUserId, (int) $this->sheetLine($doc->id, $d)['reviewed_by']);
    }

    public function testACancelledSheetClosesItsChecksAndItsLinesAreNotClearedAfterwards(): void
    {
        $a = $this->item('strict', 100);
        $sc = $this->staffUser('stock_controller');
        $doc = $this->posted($sc, [$a => 108]);
        self::assertSame('open', $this->sheetLine($doc->id, $a)['review']);
        $rev = $this->sheets->reverse($sc, $doc->id, 'entered_in_error', 'wrong warehouse');
        self::assertSame('posted', $rev->status, 'it takes 8 units off again: no OK is needed for that');
        self::assertSame(['rejected', $rev->id, $sc->staffUserId], [$this->sheetLine($doc->id, $a)['review'], (int) $this->sheetLine($doc->id, $a)['reversal_document_id'],
            (int) $this->sheetLine($doc->id, $a)['reviewed_by']]);
        self::assertSame(100, $this->onHand($a));
        self::assertSame([], $this->reviews->open()['lines']);
        self::refusedWith('not_open', fn () => $this->reviews->rejectLine($sc, $doc->id, 1, 'too late'));
    }

    public function testTheEnginesOwnTwoKindsOfReviewAreListedAndCleared(): void
    {
        $site = $this->site();
        $moved = $this->item('strict', 5);
        $near = $this->item('strict', 10);
        $this->listing($site, 'V1', $near);
        $this->commit($site, '201', [self::line('V1', 'u1')]);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$moved, $near]);
        // A unit of `near` left three minutes BEFORE it was counted: the count is right, but the engine asks a person to look.
        $this->ship($site, '201', ['u1'], '2026-09-26T17:57:00Z');
        $this->enter($sc, $sheet, $moved, 5);
        $this->enter($sc, $sheet, $near, 9);
        $this->later('30 minutes');
        $this->book('goods_in', $moved, 2);
        $this->sheets->postAsCounted($sc, $sheet->id, $this->lineOf($sheet->id, $moved), $this->sheets->differences($sheet->id)['lines'][0]['moved']['max_id']);
        $doc = $this->post($sc, $sheet);
        self::assertSame([5, 9], [$this->onHand($moved), $this->onHand($near)]);
        $engine = $this->reviews->open()['engine'];
        self::assertSame([['count_near_ship', $near, $doc->id, $this->lineOf($sheet->id, $near)], ['count_after_movements', $moved, $doc->id, $this->lineOf($sheet->id, $moved)]],
            array_map(static fn (array $r): array => [$r['source'], $r['sku_id'], $r['document_id'], $r['line_no']], $engine), 'each with the sheet line it is about, the newest first');
        self::assertSame(2, $engine[1]['detail']['net'], 'what the count overwrote: the 2 that came in');
        // A different kind of review (stock below zero) is not this list's.
        self::$db->exec("INSERT INTO count_review (warehouse_id, sku_id, source, ref) VALUES (1, ?, 'negative_on_hand', 'ORD-9')", [$moved]);
        self::assertCount(2, $this->reviews->open()['engine']);

        // Accept the one: resolved, with who and why. Reject the other: that line's count is put back by a record.
        $this->reviews->acceptEngine($sc, $engine[0]['id'], 'the parcel had left: 9 is right');
        self::assertSame(['resolved', 'accepted', $sc->staffUserId, 'the parcel had left: 9 is right'], array_values((array) self::$db->one(
            'SELECT status, resolution, resolved_by, note FROM count_review WHERE id = ?', [$engine[0]['id']])));
        self::refusedWith('not_open', fn () => $this->reviews->acceptEngine($sc, $engine[0]['id'], 'again'));
        $back = $this->reviews->rejectEngine($sc, $engine[1]['id'], 'the delivery was on the shelf: 7 are there');
        self::assertNotNull($back);
        self::assertSame([[$moved, 'adjustment', 2, $this->nowDb()]], $this->ledgerOf($back->id));
        self::assertSame(7, $this->onHand($moved));
        self::assertSame(['resolved', 'rejected'], array_values((array) self::$db->one('SELECT status, resolution FROM count_review WHERE id = ?', [$engine[1]['id']])));
        self::assertSame($back->id, (int) $this->sheetLine($sheet->id, $moved)['reversal_document_id']);
        self::assertSame([], $this->reviews->open()['engine']);
        self::refusedWith('unknown_review', fn () => $this->reviews->acceptEngine($sc, 999_999, 'nothing there'));
        self::refusedWith('role_not_allowed', fn () => $this->reviews->acceptEngine($this->staffUser('buyer'), $engine[0]['id'], 'not my job'));
    }

    public function testThePlanSaysWhatIsInsideAndOverdueAndWhatToCountNext(): void
    {
        $sc = $this->staffUser('stock_controller');
        $main = self::warehouseId('MAIN');
        $slow = $this->item('strict', 4, 'Slow seller');
        $best = $this->item('strict', 9, 'Best seller');
        $unsold = $this->item('legacy', 2, 'Never sold');
        $old = $this->item('strict', 3, 'Counted long ago');
        $older = $this->item('strict', 3, 'Counted longest ago');
        $fresh = $this->item('strict', 5, 'Counted lately');
        $merged = $this->item('strict', 1, 'Merged away');
        $elsewhere = $this->item('legacy', 0, 'No figure here');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$best, $merged]);
        foreach ([[$slow, '0.2500'], [$best, '7.5000'], [$fresh, '9.0000'], [$older, '0.1000'], [$merged, '99.0000']] as [$sku, $rate]) {
            self::$db->exec("INSERT INTO reorder_demand (sku_id, computed_at, rate, rate_raw_30, valid_days_short, valid_days_long, excluded_anomaly, excluded_promo, excluded_oos, "
                . "excluded_other, capped_days, units_365, monthly, detail) VALUES (?, UTC_TIMESTAMP(6), ?, ?, 30, 90, 0, 0, 0, 0, 0, 100, '{}', '{}')", [$sku, $rate, $rate]);
        }
        // Three counts in the past, booked through the engine as a sheet books them.
        $start = $this->now;
        foreach ([[$older, '-200 days'], [$old, '-130 days'], [$fresh, '-10 days']] as [$sku, $ago]) {
            $this->now = $start->modify($ago);
            $this->book('count', $sku, $this->onHand($sku), 'MAIN', \CW\Clock::iso(\CW\Clock::db($this->now)));
        }
        $this->now = $start;

        self::assertSame(['items' => 6, 'inside' => 1, 'overdue' => 5, 'never' => 3], $this->plan->tiles($main, 120, $this->now),
            'the items CW keeps a figure for here; a merged item and one with no figure are not in the plan');
        self::assertSame(['items' => 6, 'inside' => 2, 'overdue' => 4, 'never' => 3], $this->plan->tiles($main, 150, $this->now), 'the setting moves them');
        self::assertSame(['items' => 6, 'inside' => 0, 'overdue' => 6, 'never' => 3], $this->plan->tiles($main, 5, $this->now));
        self::assertSame(0, $this->plan->tiles(self::warehouseId('VERIFY'), 120, $this->now)['items']);

        // Never counted first (the best sellers first, then the ones with no sales by their number), then the longest ago.
        $next = $this->plan->next($main, 50);
        self::assertSame([$best, $slow, $unsold, $older, $old, $fresh], array_column($next, 'sku_id'));
        self::assertSame([null, null, null], array_slice(array_column($next, 'counted_at'), 0, 3));
        self::assertSame(['Best seller', 9, '7.5000'], [$next[0]['name'], $next[0]['on_hand'], $next[0]['rate']]);
        self::assertSame([$best, $slow], array_column($this->plan->next($main, 2), 'sku_id'), 'the next N');
        self::assertSame([$best, $slow, $unsold, $older], $this->plan->nextIds($main, 4));
        // A search (a word, a brand, a CW number) finds items whatever their turn; a merged one is never offered.
        self::assertSame([$old, $older, $fresh], array_column($this->plan->search($main, 'counted', 50), 'sku_id'));
        self::assertSame([$elsewhere], array_column($this->plan->search($main, 'no figure', 50), 'sku_id'), 'an item with no figure here can still go on a sheet');
        self::assertSame([], $this->plan->search($main, 'merged away', 50));
        self::assertSame([$slow], array_column($this->plan->search($main, sprintf('CW-%06d', $slow), 50), 'sku_id'));

        // Counting the first of the list takes it off "never counted" and puts it inside the plan.
        $sheet = $this->sheet($sc, $this->plan->nextIds($main, 1));
        $this->enter($sc, $sheet, $best, 9);
        $this->post($sc, $sheet);
        self::assertSame(['items' => 6, 'inside' => 2, 'overdue' => 4, 'never' => 2], $this->plan->tiles($main, 120, $this->now));
        self::assertSame([$slow, $unsold, $older, $old, $fresh, $best], array_column($this->plan->next($main, 50), 'sku_id'));
    }
}
