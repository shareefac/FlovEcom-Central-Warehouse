<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\CostEngine;
use CW\Profit\ItemCosts;
use CW\Profit\ProfitMath;
use CW\Profit\SnapshotBody;
use CW\Tests\Support\StockTestCase;

/**
 * The Codex re-check of 37a2cf0 (build/A/codex_r3.txt), each shown failing first: the price of an item at a moment is one indexed read,
 * however long its history (R6; since PR79 a late event makes the job write the item's history again as its next timeline); an all-digit tracking number is DPD's only on a DPD label the shop made
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
        // A delivery of 10 at £3 of 17 Nov 00:30 that came late: since PR79 the cost job writes the item's whole history again, in time order, as
        // its next timeline (CostEngine::rebuild); written here as it writes it (each event's price by ProfitMath::step on the one before).
        $events = self::$db->all('SELECT * FROM item_cost_event WHERE sku_id = ? AND timeline = 0 ORDER BY source_at, id', [$a]);
        $late = ['sku_id' => $a, 'at' => '2026-11-17 00:30:00', 'source_at' => '2026-11-17 00:30:00', 'kind' => 'receipt', 'source_key' => 'r3late:' . $a, 'units' => 10,
            'on_hand' => 24, 'price_e4' => 30000, 'booked_e4' => null];
        array_splice($events, 25, 0, [$late]); // after the start and the 24 deliveries up to 17 Nov 00:00
        $rows = [];
        $params = [];
        $price = null;
        foreach ($events as $ev) {
            $price = ProfitMath::step($price, $ev + ['new_cost_e4' => $ev['new_cost_e4'] ?? null]);
            $rows[] = '(?, 1, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($params, $a, $ev['source_at'], $ev['source_at'], $ev['kind'], $ev['source_key'], $ev['units'], $ev['on_hand'], $ev['price_e4'], $price);
        }
        self::$db->exec('INSERT INTO item_cost_event (sku_id, timeline, at, source_at, kind, source_key, units, on_hand, price_e4, new_cost_e4) VALUES ' . implode(', ', $rows), $params);
        self::$db->exec('UPDATE item_cost SET timeline = 1, unit_cost_e4 = ? WHERE sku_id = ?', [$price, $a]);
        self::assertSame(15882, (new ItemCosts(self::$db))->at([$a], '2026-11-17 00:45:00')[$a]['e4'], 'at its own place: (24 x £1 + 10 x £3) / 34');
        $costs = new ItemCosts(self::$db);
        $costs->at([$a], '2026-11-17 00:45:00');
        $before = self::rowsRead();
        $later = (int) $costs->at([$a], '2026-12-31 00:00:00')[$a]['e4'];
        self::assertLessThan(60, self::rowsRead() - $before - $overhead, 'the timeline in force: one indexed read');
        self::assertTrue($later > 10000 && $later < 15882, 'later moments have it in: ' . $later);
        self::assertSame($price, $later);
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
