<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Ops\OpeningEstimate;
use CW\Tests\Support\StockTestCase;
use DateTimeImmutable;

/** The opening on_hand estimate of a site (plan §8.1, D40, D40a): bin/import_opening_estimate.php. */
final class OpeningEstimateTest extends StockTestCase
{
    private const DOC = 'opening:vpg:2026-10-01t00:00+01:00';

    private function op(): OpeningEstimate
    {
        return new OpeningEstimate(self::$db, $this->moves);
    }

    private function sys(): Caller
    {
        return Caller::system(OpeningEstimate::ACTOR_JOB);
    }

    /** An as-of moment before every fixture row (ledger created_at is the database's real clock). */
    private function longAgo(): DateTimeImmutable
    {
        return new DateTimeImmutable('2000-01-01T00:00:00Z');
    }

    private function bookPlan(array $plan): array
    {
        return $this->op()->apply($this->sys(), $plan['lines'], self::DOC, 'opening estimate (not a count): test');
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (CwException $e) {
            return $e->errorCode;
        }
        self::fail('expected a refusal');
    }

    public function testLinkedListingsBookTheirPositiveFigureTimesUnitsAsOneAdjustmentPerItem(): void
    {
        $site = $this->site();
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        $c = $this->item('legacy');
        $this->listing($site, 'A1', $a);
        $this->listing($site, 'A2', $a);          // a duplicate listing of the same item: summed
        $this->listing($site, 'B10', $b, 10);     // "10 x" listing: x u
        $this->listing($site, 'C', $c);
        $this->listing($site, 'SUG', null, 1, 'suggested'); // a proposal, not a link: ignored
        $this->listing($site, 'U', null);         // unlinked: ignored

        $plan = $this->op()->plan('vpg', [['A1', 5], ['A2', 3], ['B10', 2], ['C', -40], ['SUG', 9], ['U', 4], ['GONE', 6], ['Z', 0]], self::DOC, $this->longAgo());
        self::assertSame([$a => 8, $b => 20], $plan['lines']);
        $r = $plan['report'];
        self::assertSame([8, 6, 29], [$r['rows'], $r['positive_rows'], $r['positive_units']]);
        self::assertSame(['rows' => 2, 'units' => -40], $r['skipped']['zero_or_negative']);
        self::assertSame(['rows' => 1, 'units' => 6], $r['skipped']['no_listing']);
        self::assertSame(['suggested' => ['rows' => 1, 'units' => 9], 'unmapped' => ['rows' => 1, 'units' => 4]], $r['not_linked_by_status']);
        self::assertSame([2, 28, 1], [$r['items'], $r['units'], $r['items_from_several_listings']]);

        self::assertSame(['booked_items' => 2, 'booked_units' => 28, 'replayed_items' => 0], $this->bookPlan($plan));
        $this->assertBal(8, 0, 0, $a);
        $this->assertBal(20, 0, 0, $b);
        $this->assertBal(0, 0, 0, $c);
        self::assertSame(8, $this->view($site, 'A1')['available']);
        self::assertSame(2, $this->view($site, 'B10')['available'], 'the site sees floor(available / u)');

        $rows = self::$db->all('SELECT sku_id, bucket, qty_delta, movement_type, doc_ref, actor, note FROM stock_ledger WHERE doc_ref = ? ORDER BY sku_id', [self::DOC]);
        self::assertSame([[$a, 'on_hand', 8], [$b, 'on_hand', 20]], array_map(static fn (array $x): array => [(int) $x['sku_id'], $x['bucket'], (int) $x['qty_delta']], $rows));
        self::assertSame(['adjustment'], array_values(array_unique(array_column($rows, 'movement_type'))));
        self::assertSame(['system:opening_estimate'], array_values(array_unique(array_column($rows, 'actor'))));
        self::assertStringContainsString('not a count', (string) $rows[0]['note']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM sku WHERE id IN (?, ?) AND counted_at IS NOT NULL', [$a, $b]), 'an estimate is not a count');
    }

    public function testCountedItemsEarlierOpeningsAndMovementsBeforeTheAsOfMomentAreLeftAlone(): void
    {
        $site = $this->site();
        $counted = $this->item('legacy');
        self::$db->exec('UPDATE sku SET counted_at = ? WHERE id = ?', ['2026-09-25 10:00:00.000000', $counted]);
        $moved = $this->item('legacy', 7);   // a goods-in already in CW
        $other = $this->item('legacy');
        $fresh = $this->item('legacy');
        foreach (['K' => $counted, 'M' => $moved, 'O' => $other, 'F' => $fresh] as $v => $sku) {
            $this->listing($site, $v, $sku);
        }
        // An earlier opening (another doc_ref) on $other.
        $this->op()->apply($this->sys(), [$other => 2], 'opening:vpg:earlier', 'n');

        // As of NOW: the goods-in happened before it, so the site figure already holds it.
        $now = new DateTimeImmutable('now', Clock::utc());
        $plan = $this->op()->plan('vpg', [['K', 50], ['M', 100], ['O', 9], ['F', 3]], self::DOC, $now);
        self::assertSame([$fresh => 3], $plan['lines']);
        self::assertSame(['items' => 3, 'units' => 159, 'why' => ['counted' => 1, 'earlier_opening' => 1, 'moved_before_as_of' => 1]],
            $plan['report']['skipped']['has_stock_history']);

        // As of long ago: the goods-in came after it, so the estimate sits under it.
        $plan = $this->op()->plan('vpg', [['K', 50], ['M', 100], ['O', 9], ['F', 3]], self::DOC, $this->longAgo());
        self::assertSame([$moved => 100, $fresh => 3], $plan['lines']);
        $this->bookPlan($plan);
        $this->assertBal(107, 0, 0, $moved);
        $this->assertBal(0, 0, 0, $counted);
        $this->assertBal(2, 0, 0, $other);
        $this->assertBal(3, 0, 0, $fresh);
    }

    public function testARerunOrAResumeBooksOnlyWhatIsMissingAndADifferentFileIsRefused(): void
    {
        $site = $this->site();
        $skus = [];
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $skus[$i] = $this->item('legacy');
            $this->listing($site, "V{$i}", $skus[$i]);
            $rows[] = ["V{$i}", $i];
        }
        $plan = $this->op()->plan('vpg', $rows, self::DOC, $this->longAgo());

        // A run that stopped after two items.
        $this->op()->apply($this->sys(), array_slice($plan['lines'], 0, 2, true), self::DOC, 'n');

        $resume = $this->op()->plan('vpg', $rows, self::DOC, $this->longAgo());
        self::assertSame(['items' => 2, 'units' => 3], $resume['report']['already_booked']);
        self::assertSame([$skus[3] => 3, $skus[4] => 4, $skus[5] => 5], $resume['lines']);
        self::assertSame(['booked_items' => 3, 'booked_units' => 12, 'replayed_items' => 0], $this->bookPlan($resume));
        $ledger = $this->ledgerCount();

        // The same file again: everything already booked, nothing to do.
        $again = $this->op()->plan('vpg', $rows, self::DOC, $this->longAgo());
        self::assertSame([], $again['lines']);
        self::assertSame(['items' => 5, 'units' => 15], $again['report']['already_booked']);
        self::assertSame($ledger, $this->ledgerCount());

        // Different figures under the same doc_ref: refused before anything is booked.
        $rows[0] = ['V1', 99];
        self::assertSame('opening_conflict', $this->code(fn () => $this->op()->plan('vpg', $rows, self::DOC, $this->longAgo())));
        self::assertSame($ledger, $this->ledgerCount());
        $this->assertBal(1, 0, 0, $skus[1]);
    }

    public function testTheSameOpeningRetypedInCapitalsCannotBookTwice(): void
    {
        $site = $this->site();
        $sku = $this->item('legacy');
        $this->listing($site, 'V', $sku);
        $this->bookPlan($this->op()->plan('vpg', [['V', 5]], self::DOC, $this->longAgo()));

        $upper = $this->op()->plan('vpg', [['V', 5]], strtoupper(self::DOC), $this->longAgo());
        self::assertSame([], $upper['lines'], 'the lower-case opening is an earlier opening for the retyped one');
        self::assertSame(['earlier_opening' => 1], $upper['report']['skipped']['has_stock_history']['why']);
        $this->assertBal(5, 0, 0, $sku);
    }

    public function testQuarantinedListingsWithStockStopTheRun(): void
    {
        $site = $this->site();
        $sku = $this->item('legacy');
        $this->listing($site, 'M', $sku);
        $this->listing($site, 'Q', $sku, 1, 'quarantined');
        self::assertSame('quarantined_listings', $this->code(fn () => $this->op()->plan('vpg', [['M', 5], ['Q', 4]], self::DOC, $this->longAgo())));
        // A quarantined listing with no stock does not matter.
        self::assertSame([$sku => 5], $this->op()->plan('vpg', [['M', 5], ['Q', 0]], self::DOC, $this->longAgo())['lines']);
    }

    public function testBadInputIsRefused(): void
    {
        $site = $this->site();
        $this->listing($site, 'A', $this->item('legacy'));
        self::assertSame('duplicate_variant', $this->code(fn () => $this->op()->plan('vpg', [['A', 1], ['A', 2]], self::DOC, $this->longAgo())));
        self::assertSame('empty_input', $this->code(fn () => $this->op()->plan('vpg', [], self::DOC, $this->longAgo())));
        self::assertSame('too_many_units', $this->code(fn () => $this->op()->plan('vpg', [['A', 10_000_001]], self::DOC, $this->longAgo())));
        self::assertSame('unknown_channel', $this->code(fn () => $this->op()->plan('nope', [['A', 1]], self::DOC, $this->longAgo())));
    }
}
