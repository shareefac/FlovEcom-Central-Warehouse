<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\CostEngine;
use CW\Profit\ItemCosts;
use CW\Profit\SnapshotBody;
use CW\Tests\Support\StockTestCase;

/**
 * The Codex re-check of 37a2cf0 (build/A/codex_r3.txt), each shown failing first: the price of an item at a moment is one indexed read,
 * however long its history, unless a late event changed it (R6); an all-digit tracking number is DPD's only on a DPD label the shop made
 * (b12).
 */
final class ProfitReviewR3Test extends StockTestCase
{
    /** Rows MySQL read for this connection so far (every Handler_read_* counter). */
    private static function rowsRead(): int
    {
        $n = 0;
        foreach (self::$db->all("SHOW SESSION STATUS LIKE 'Handler_read%'") as $r) {
            $n += (int) $r['Value'];
        }
        return $n;
    }

    public function testThePriceAtAMomentIsOneReadUnlessALateEventChangedIt(): void
    {
        $a = $this->item('legacy');
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 10000, 'erp_bin', '2026-11-16', '2026-11-16')", [$a]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES (?, '2030-01-01')", [CostEngine::JOB]);
        // A year of history: the start at £1, then 400 deliveries of one unit at £1, in order.
        $rows = ["(?, '2026-11-16 00:00:00', '2026-11-16 00:00:00', 'start', ?, 0, NULL, NULL, 10000)"];
        $params = [$a, 'start:' . $a];
        for ($i = 1; $i <= 400; $i++) {
            $t = (new \DateTimeImmutable('2026-11-16 00:00:00'))->modify("+{$i} hours")->format('Y-m-d H:i:s');
            $rows[] = "(?, '{$t}', '{$t}', 'receipt', ?, 1, {$i}, 10000, 10000)";
            array_push($params, $a, 'r3:' . $a . ':' . $i);
        }
        self::$db->exec('INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, units, on_hand, price_e4, new_cost_e4) VALUES ' . implode(', ', $rows), $params);
        $costs = new ItemCosts(self::$db);
        $costs->at([$a], '2026-12-31 00:00:00'); // the mark and settings read once
        $overhead = -self::rowsRead() + self::rowsRead(); // what reading the counters reads itself
        $before = self::rowsRead();
        self::assertSame(['e4' => 10000, 'source' => 'cw_cost'], $costs->at([$a], '2026-12-31 00:00:00')[$a]);
        self::assertLessThan(60, self::rowsRead() - $before - $overhead, 'one indexed read, not the 401 events folded');
        // A delivery of 10 at £3 of 17 Nov 00:30 written late (after all the others): from it on, the history is folded in its own order.
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, units, on_hand, price_e4, old_cost_e4, new_cost_e4) "
            . "VALUES (?, '2026-12-30 00:00:00', '2026-11-17 00:30:00', 'receipt', ?, 10, 24, 30000, 10000, 15882)", [$a, 'r3late:' . $a]);
        $at = (new ItemCosts(self::$db))->at([$a], '2026-11-17 00:45:00')[$a]['e4'];
        self::assertSame(15882, $at, 'at its own place: (24 x £1 + 10 x £3) / 34');
        self::assertSame(15882, (new ItemCosts(self::$db))->at([$a], '2026-11-17 00:45:00')[$a]['e4']);
        $late = (int) (new ItemCosts(self::$db))->at([$a], '2026-12-31 00:00:00')[$a]['e4'];
        self::assertTrue($late > 10000 && $late < 15882, 'later moments fold it in: ' . $late);
        self::assertSame(10000, (new ItemCosts(self::$db))->at([$a], '2026-11-17 00:15:00')[$a]['e4'], 'before it: as it was');
    }

    public function testAnAllDigitTrackingNumberIsDpdsOnlyOnADpdLabelTheShopMade(): void
    {
        self::assertSame('15501234567890', SnapshotBody::tracking('1550 1234 5678 90', 'dpd', false));
        self::assertSame('1550123456', SnapshotBody::tracking('1550123456', 'dpd', false));
        self::assertNull(SnapshotBody::tracking('2025550123', 'manual', true), 'typed by hand on a parcel marked as sent: maybe a phone number');
        self::assertNull(SnapshotBody::tracking('2025550123', 'dpd', true));
        self::assertNull(SnapshotBody::tracking('20255501234567', 'royal_mail', false));
        self::assertSame('AB123456789GB', SnapshotBody::tracking('ab 123 456 789 gb', 'manual', true), 'a carrier\'s own lettered shape stands on its own');
        $p = SnapshotBody::parse(['v' => 1, 'version' => 1, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z',
            'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'pp', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [], 'delivery' => null, 'order_refund_inc' => 0,
            'grand_inc' => 0, 'parcels' => [
                ['parcel_ref' => 'odl:1', 'tracking_no' => '2025550123', 'carrier' => 'manual', 'service_code' => null, 'service' => null, 'status' => 'shipped',
                    'label_at' => '2026-11-19T11:00:00Z', 'shipped_at' => null, 'manual' => true, 'partial' => false],
                ['parcel_ref' => 'odl:2', 'tracking_no' => '15501234567890', 'carrier' => 'dpd', 'service_code' => '12', 'service' => 'Next day', 'status' => 'shipped',
                    'label_at' => '2026-11-19T11:00:00Z', 'shipped_at' => null, 'manual' => false, 'partial' => false]]], new \DateTimeImmutable('2026-11-20 00:00:00'));
        self::assertSame([null, '15501234567890'], array_column($p['parcels'], 'tracking_no'));
    }
}
