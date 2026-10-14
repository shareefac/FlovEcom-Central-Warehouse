<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Caller;
use CW\Reorder\DemandBuilder;
use CW\Reorder\DemandMath;
use CW\Reorder\SalesFold;

/**
 * Pack G (docs/decisions.md PG1, PG2): CW's own record of each store's sales. The store's orders, as the connector reports them, are folded
 * from the stock ledger by UK day in each variant's own units (a sale +, a cancellation -, an uncancellation +; never an opening order);
 * a day is folded once, and the last days once more on a later day. The demand then takes a named store's days from the record after it
 * connected, the imported history before, and a day neither has is "no data".
 */
final class SalesFoldTest extends ReorderTestCase
{
    private function at(string $utc): \Closure
    {
        return static fn (): \DateTimeImmutable => new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    /** Puts the ledger rows of an order (and its reservation's commit time) at a UTC moment. */
    private function moveOrder(Caller $site, string $ref, string $utc, ?string $type = null): void
    {
        self::$db->exec('UPDATE stock_ledger SET created_at = ? WHERE channel_id = ? AND order_ref = ?' . ($type === null ? '' : ' AND movement_type = ?'),
            $type === null ? [$utc, $site->channelId, $ref] : [$utc, $site->channelId, $ref, $type]);
    }

    /** @return array<string, int> "variant date" => units */
    private static function record(): array
    {
        $out = [];
        foreach (self::$db->all('SELECT external_variant_id, sale_date, units FROM reorder_sales_day ORDER BY sale_date, external_variant_id') as $r) {
            $out[$r['external_variant_id'] . ' ' . $r['sale_date']] = (int) $r['units'];
        }
        return $out;
    }

    public function testAStoresOrdersAreFoldedByUkDay(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $x = $this->item('legacy', 100, 'Folded item');
        $this->listing($site, 'V1', $x);
        $this->listing($site, 'V3', $x, 3); // a pack of 3 sold as one
        self::$db->exec("INSERT INTO channel_opening (channel_id, t0_at, t0_last_order_id) VALUES (?, '2026-10-05 09:00:00', 1)", [$site->channelId]);
        $this->ok($this->commit($site, 'o1', [self::line('V1', 'u1', 'u2')]));
        $this->ok($this->commit($site, 'o2', [self::line('V3', 'u3')]));
        $this->ok($this->commit($site, 'o3', [self::line('V1', 'u4')]));
        $this->ok($this->res->cancel($site, 'o3', ['u4'], true, $this->key('cancel')));
        $this->ok($this->commit($site, 'o4', [self::line('V1', 'u5')], 'opening'));
        $this->ok($this->commit($site, 'o5', [self::line('V1', 'u6')]));
        $this->moveOrder($site, 'o1', '2026-10-06 10:00:00');
        $this->moveOrder($site, 'o2', '2026-10-06 22:30:00'); // 23:30 in the UK: still the 6th
        $this->moveOrder($site, 'o3', '2026-10-06 12:00:00', 'commit');
        $this->moveOrder($site, 'o3', '2026-10-07 09:00:00', 'cancel'); // cancelled the next day: lands on the 7th
        $this->moveOrder($site, 'o4', '2026-10-06 11:00:00'); // an opening order: never in the record
        $this->moveOrder($site, 'o5', '2026-10-06 23:30:00'); // 00:30 on the 7th in the UK
        $fold = new SalesFold(self::$db, null, $this->at('2026-10-08 05:00:00'));
        self::assertSame([DemandMath::day('2026-10-05'), DemandMath::day('2026-10-06'), DemandMath::day('2026-10-07')], $fold->due(3), 'from the UK day of the connect moment to yesterday');
        $r = $fold->run();
        self::assertSame([['2026-10-05', 0, 0], ['2026-10-06', 2 + 1 + 1, 4], ['2026-10-07', 0, 2]],
            array_map(static fn (array $d): array => [$d['date'], $d['units'], $d['rows']], $r['days']), 'units in each variant\'s own units; rows read');
        self::assertSame(['V1 2026-10-06' => 3, 'V3 2026-10-06' => 1, 'V1 2026-10-07' => 0], self::record(), 'the 7th: o5 +1, o3\'s cancellation -1');
        self::assertSame(['2026-10-05', '2026-10-07', false], [$r['first'], $r['last'], $r['more']]);
        self::assertSame([], $fold->due(3), 'the same day: nothing is due');
        self::assertSame([], $fold->run()['days']);

        // The next day: the 8th for the first time, the 6th and the 7th once more. A late cancellation of o1 on the 8th lands there.
        $this->ok($this->res->cancel($site, 'o1', ['u1'], true, $this->key('cancel')));
        $this->moveOrder($site, 'o1', '2026-10-08 15:00:00', 'cancel');
        $next = new SalesFold(self::$db, null, $this->at('2026-10-09 05:00:00'));
        self::assertSame([DemandMath::day('2026-10-06'), DemandMath::day('2026-10-07'), DemandMath::day('2026-10-08')], $next->due(3));
        $next->run();
        self::assertSame(['V1 2026-10-06' => 3, 'V3 2026-10-06' => 1, 'V1 2026-10-07' => 0, 'V1 2026-10-08' => -1], self::record());
        self::assertSame([1, 2, 2, 1], array_map('intval', self::$db->column('SELECT times FROM reorder_fold_day ORDER BY fold_date')),
            'the 5th once, the 6th and 7th twice, the 8th once');
        $later = new SalesFold(self::$db, null, $this->at('2026-10-12 05:00:00'));
        self::assertSame([DemandMath::day('2026-10-09'), DemandMath::day('2026-10-10'), DemandMath::day('2026-10-11')], $later->due(3),
            'the days not folded; the 8th was folded again on the 9th, outside the last three days now');
        // A day below zero reads as nothing sold.
        self::assertSame(['V1' => [DemandMath::day('2026-10-06') => 3]], $next->history($site->channelId, ['V1'], DemandMath::day('2026-10-06'), DemandMath::day('2026-10-08')));
        // No store connected: nothing to fold.
        self::$db->exec('DELETE FROM channel_opening');
        self::assertSame([[], null], [$later->due(3), $later->firstDay()]);
    }

    public function testTheDemandTakesANamedStoresDaysFromItsOwnRecordAfterItConnected(): void
    {
        $vpg = $this->channelOf('vapeandgo');
        $x = $this->brandItem('Own record item', 'Elux');
        $this->listingOf($vpg, '901', $x);
        $this->history($vpg, '2026-06-01', '2026-10-01', self::daily('901', '2026-06-01', '2026-10-01', 10));
        self::$db->exec("INSERT INTO channel_opening (channel_id, t0_at, t0_last_order_id) VALUES (?, '2026-10-05 09:00:00', 1)", [$vpg]);
        for ($d = DemandMath::day('2026-10-05'); $d <= DemandMath::day('2026-10-13'); $d++) {
            self::$db->exec("INSERT INTO reorder_fold_day (fold_date, folded_at) VALUES (?, NOW(6))", [DemandMath::date($d)]);
            if ($d >= DemandMath::day('2026-10-06')) {
                self::$db->exec("INSERT INTO reorder_sales_day (channel_id, external_variant_id, sale_date, units, folded_at) VALUES (?, '901', ?, 20, NOW(6))",
                    [$vpg, DemandMath::date($d)]);
            }
        }
        $clock = $this->at('2026-10-14 05:00:00');
        // Not named: the imported history alone, to 1 Oct.
        $b = (new DemandBuilder(self::$db, null, $clock))->rebuild();
        self::assertSame(['vapeandgo' => ['from' => '2026-06-01', 'to' => '2026-10-01']], $b['channels']);
        self::assertSame('10.0000', $this->demandOf($x)['rate']);
        // Named: from the 6th (the first whole UK day after the connect moment) the record; 2 to 5 Oct no source has.
        $this->setting('reorder.own_sales_stores', '"vapeandgo"');
        $builder = new DemandBuilder(self::$db, null, $clock);
        $c = $builder->channels()[$vpg];
        self::assertSame([DemandMath::day('2026-06-01'), DemandMath::day('2026-10-13'), DemandMath::day('2026-10-01'), DemandMath::day('2026-10-06'),
            DemandMath::day('2026-10-13')], [$c['start'], $c['end'], $c['imp_end'], $c['own_from'], $c['own_end']]);
        self::assertSame([true, false, false, true], [DemandBuilder::covered($c, DemandMath::day('2026-10-01')), DemandBuilder::covered($c, DemandMath::day('2026-10-02')),
            DemandBuilder::covered($c, DemandMath::day('2026-10-05')), DemandBuilder::covered($c, DemandMath::day('2026-10-06'))]);
        $builder->rebuild();
        $d = $this->demandOf($x);
        // 91 days to 13 Oct: 4 without data; 79 at 10 and 8 at 20 -> 950 / 87; the last 28: 16 at 10 and 8 at 20 -> 320 / 24; half and half.
        $rS = DemandMath::halfUpDiv(320 * 10_000, 24);
        $rL = DemandMath::halfUpDiv(950 * 10_000, 87);
        self::assertSame(DemandMath::e4(DemandMath::halfUpDiv(500_000 * $rS + 500_000 * $rL, 1_000_000)), $d['rate']);
        self::assertSame('12.1264', $d['rate']);
        self::assertSame([4, 87, 24], [$d['excluded_other'], (int) $d['valid_days_long'], (int) $d['valid_days_short']], 'the four days are "no data"');
        self::assertSame('2026-10-13', $d['last_sale_date']);
        self::assertSame(8 * 20, $d['monthly']['2026-10'] - 10, 'October: 1 Oct imported (10) + 8 days of the record (20)');
        // The list's header names the record.
        $h = $this->reorderList()->header();
        self::assertSame([['code' => 'vapeandgo', 'from' => '2026-10-06', 'to' => '2026-10-13']], $h['own']);
        // The item page computes the same, day by day.
        $item = $builder->item($x);
        self::assertSame($d['rate'], DemandMath::e4($item['rate_e4']));
        $days = array_column($item['listings'][0]['days'], null, 'date');
        self::assertSame([[10, null], [0, 'nodata'], [20, null]], [[$days['2026-10-01']['q'], $days['2026-10-01']['reason']],
            [$days['2026-10-03']['q'], $days['2026-10-03']['reason']], [$days['2026-10-06']['q'], $days['2026-10-06']['reason']]]);
    }
}
