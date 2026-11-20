<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\CostEngine;
use CW\Profit\ItemCosts;
use CW\Profit\ProfitViews;
use CW\Tests\Support\StockTestCase;

/**
 * The Codex re-check of 6b8a7ee (build/A/codex_r4.txt), each shown failing first: the price of an item at a PAST moment is one indexed
 * read however long its history after that moment (R6; since PR79 a timeline is always in time order); the lists by
 * product, brand and supplier rank the rows whose units wait for their cost in SQL, before the list is cut at its 300 rows, and find a
 * brand's waiting units whatever the case of its name (c6).
 */
final class ProfitReviewR4Test extends StockTestCase
{
    private const DAY = '2026-11-18';

    /** Rows MySQL read for this connection so far (every Handler_read_* counter). */
    private static function rowsRead(): int
    {
        $n = 0;
        foreach (self::$db->all("SHOW SESSION STATUS LIKE 'Handler_read%'") as $r) {
            $n += (int) $r['Value'];
        }
        return $n;
    }

    public function testAPastMomentIsOneReadHoweverLongTheHistoryAfterIt(): void
    {
        $a = $this->item('legacy');
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 10000, 'erp_bin', '2026-11-16', '2026-11-16')", [$a]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES (?, '2030-01-01')", [CostEngine::JOB]);
        // The start at £1, then 400 deliveries of one unit an hour, in order: the first 200 at £1, the next 200 at £3.
        $rows = ["(?, '2026-11-16 00:00:00', '2026-11-16 00:00:00', 'start', ?, 0, NULL, NULL, 10000)"];
        $params = [$a, 'start:' . $a];
        for ($i = 1; $i <= 400; $i++) {
            $t = (new \DateTimeImmutable('2026-11-16 00:00:00'))->modify("+{$i} hours")->format('Y-m-d H:i:s');
            $rows[] = "(?, '{$t}', '{$t}', 'receipt', ?, 1, {$i}, " . ($i <= 200 ? 10000 : 30000) . ', ' . ($i <= 200 ? 10000 : 'NULL') . ')';
            array_push($params, $a, 'r4:' . $a . ':' . $i);
        }
        self::$db->exec('INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, units, on_hand, price_e4, new_cost_e4) VALUES ' . implode(', ', $rows), $params);
        $costs = new ItemCosts(self::$db);
        $costs->at([$a], '2026-11-24 07:30:00'); // the mark and settings read once
        $overhead = -self::rowsRead() + self::rowsRead();
        $before = self::rowsRead();
        // 24 Nov 07:30 is after the 199th delivery: 200 events come after it.
        self::assertSame(['e4' => 10000, 'source' => 'cw_cost'], $costs->at([$a], '2026-11-24 07:30:00')[$a]);
        self::assertLessThan(60, self::rowsRead() - $before - $overhead, 'one indexed read of the timeline in force, not the 200 events after the moment');
        // A history that is no longer in force (an earlier timeline, before a rebuild) is never read.
        self::$db->exec("INSERT INTO item_cost_event (sku_id, timeline, at, source_at, kind, source_key, units, on_hand, price_e4, new_cost_e4) "
            . "VALUES (?, 7, '2026-11-24 07:00:00', '2026-11-24 07:00:00', 'receipt', ?, 10, 24, 30000, 99999)", [$a, 'r4other:' . $a]);
        self::assertSame(10000, (new ItemCosts(self::$db))->at([$a], '2026-11-24 07:30:00')[$a]['e4']);
    }

    public function testWaitingUnitsAreRankedBeforeTheListIsCut(): void
    {
        $ch = self::makeChannel('vapeandgo', 'live');
        self::$db->exec("INSERT INTO sale_order (channel_id, order_ref, paid_at, day, vat_bp, snapshot_version, first_at, updated_at) VALUES (?, '1', ?, ?, 2000, 1, NOW(6), NOW(6))",
            [$ch, self::DAY . ' 10:00:00', self::DAY]);
        // 300 products whose one unit waits for its cost (no cost yet: 100%), and one with its cost: £1.00 sold for £0.50, 50%.
        $days = [];
        $lines = [];
        $params = [];
        $lp = [];
        for ($i = 1; $i <= 300; $i++) {
            $days[] = "(?, ?, ?, 1, 100, 0, 0)";
            array_push($params, $ch, self::DAY, 900000 + $i);
            $lines[] = "(?, ?, '1', ?, ?, 1, 1, 120, 100, ?, NOW(6))";
            array_push($lp, $ch, 'w' . $i, 'v' . $i, 900000 + $i, self::DAY);
        }
        $days[] = "(?, ?, 900999, 1, 100, 50, 0)";
        array_push($params, $ch, self::DAY);
        self::$db->exec('INSERT INTO profit_item_day (channel_id, day, sku_id, units, sale_ex, cost, units_no_cost) VALUES ' . implode(', ', $days), $params);
        self::$db->exec('INSERT INTO sale_line (channel_id, line_id, order_ref, variant_id, sku_id, qty, units, price_inc, sale_ex, day, updated_at) VALUES ' . implode(', ', $lines), $lp);
        $views = new ProfitViews(self::$db);
        foreach (['margin', 'margin_low'] as $sort) {
            $r = $views->breakdown(self::DAY, self::DAY, null, 'product', $sort);
            self::assertTrue($r['more'], $sort);
            self::assertCount(ProfitViews::ROW_LIMIT, $r['rows']);
            self::assertSame([900999, false, 5000], [$r['rows'][0]['id'], $r['rows'][0]['f']['incomplete'], $r['rows'][0]['f']['margin_bp']],
                "{$sort}: the one whose margin is known first, never cut off by the 300 that wait");
            self::assertSame([true, 1], [$r['rows'][1]['f']['incomplete'], $r['rows'][1]['f']['pending']], $sort);
        }
    }

    public function testABrandsWaitingUnitsAreFoundWhateverTheCaseOfItsName(): void
    {
        $ch = self::makeChannel('vapeandgo', 'live');
        $skus = [];
        foreach (['Acme', 'Acme', 'acme'] as $i => $brand) {
            $skus[$i] = self::makeSku('Brand case ' . $i);
            self::$db->exec('UPDATE sku SET brand = ? WHERE id = ?', [$brand, $skus[$i]]);
            self::$db->exec('INSERT INTO profit_item_day (channel_id, day, sku_id, units, sale_ex, cost, units_no_cost) VALUES (?, ?, ?, 1, 100, ?, 0)',
                [$ch, self::DAY, $skus[$i], $i < 2 ? 50 : 0]);
        }
        self::$db->exec("INSERT INTO sale_order (channel_id, order_ref, paid_at, day, vat_bp, snapshot_version, first_at, updated_at) VALUES (?, '1', ?, ?, 2000, 1, NOW(6), NOW(6))",
            [$ch, self::DAY . ' 10:00:00', self::DAY]);
        self::$db->exec("INSERT INTO sale_line (channel_id, line_id, order_ref, variant_id, sku_id, qty, units, price_inc, sale_ex, day, updated_at) "
            . "VALUES (?, 'w1', '1', 'v1', ?, 1, 1, 120, 100, ?, NOW(6))", [$ch, $skus[2], self::DAY]);
        $r = (new ProfitViews(self::$db))->breakdown(self::DAY, self::DAY, null, 'brand', 'profit');
        self::assertCount(1, $r['rows'], 'one brand, as the list groups it (case does not matter)');
        self::assertSame(['Acme', 1, true], [$r['rows'][0]['label'], $r['rows'][0]['f']['pending'], $r['rows'][0]['f']['incomplete']],
            'the unit of "acme" that waits is the brand\'s');
    }
}
