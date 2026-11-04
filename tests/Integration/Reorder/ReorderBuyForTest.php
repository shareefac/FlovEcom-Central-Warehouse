<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Reorder\DraftPos;
use CW\Reorder\ReorderLines;
use CW\Reorder\ReorderList;
use CW\Reorder\ReorderMath;
use CW\Reorder\ReorderRefresh;
use CW\Reorder\ReorderSettings;

/**
 * "Buy for N days" on the reorder-list scenario (ReorderFixtures::listScenario; docs/decisions.md BF1-BF12): the stored list bought for N
 * (its lines, "Products to buy", its count and its order follow N; the usual list is the one it was), the same as the list worked out now
 * for N, the SQL that chooses and orders the lines the same as ReorderMath on 400 random lines, "create draft orders" storing N's
 * suggestion, and the refresh writing the compact inputs (and writing again a row whose inputs are missing or old).
 */
final class ReorderBuyForTest extends ReorderTestCase
{
    private function stored(): ReorderLines
    {
        return new ReorderLines(self::$db);
    }

    /** @return array<int, array<string, mixed>> sku id => stored line (bought for N when the query says so), in list order */
    private function page(array $q = []): array
    {
        return array_column($this->stored()->page(ReorderList::filters($q), 1, 200)['lines'], null, 'sku_id');
    }

    private function refresh(): ReorderRefresh
    {
        return new ReorderRefresh(self::$db, $this->pos);
    }

    private function drafts(): DraftPos
    {
        return new DraftPos(self::$db, $this->pos, $this->reorderList(), $this->refresh());
    }

    /**
     * The scenario (12, 2, 5, 1 and 3 a day; 5 normal spare days) with 5 boxes of A (120) on an approved order, so A has 60 + 120 = 180:
     *   usual  A 3 + 7 + 5 = 15 × 12 = 180: nothing to buy; B, C, D as ReorderEngineTest
     *   N=14   A 3 + 14 + 5 = 22 × 12 = 264 - 180 = 84 -> 4 boxes of 24 (3.5 up, the smallest order 2); B 21 × 2 = 42 -> 5 packs of 10;
     *          C 23 × 5 = 115 -> 20 packs of 6 (its own cycle is 14); D 21 singles
     *   N=1    A 9 × 12 = 108 < 180: nothing; B 8 × 2 = 16 -> 2 packs; C 10 × 5 = 50 -> 9 packs; D 8
     * The reorder point, urgent and the safety stock never move with N.
     *
     * @return array<string, mixed>
     */
    private function scenarioWithAOnOrder(): array
    {
        $s = $this->listScenario();
        $po = $this->draftPo($s['buyer'], (int) $s['s1']['id'], [['supplier_item_id' => (int) $s['si']['a']['id'], 'packs' => 5]]);
        $this->pos->approve($s['buyer'], $po->id, $po->version);
        $this->refreshLines();
        return $s;
    }

    public function testTheStoredListBoughtForNDays(): void
    {
        $s = $this->scenarioWithAOnOrder();
        $usual = $this->page();
        self::assertSame([$s['c'], $s['b'], $s['d']], array_keys($usual), 'the usual list: A is covered by its order');
        self::assertSame(3, $this->stored()->page(ReorderList::filters([]), 1, 200)['total']);
        self::assertArrayNotHasKey('buy_for', $usual[$s['b']], 'a usual line is the line as stored');

        $two = $this->page(['buy_for' => '14']);
        self::assertSame([$s['c'], $s['b'], $s['d'], $s['a']], array_keys($two), 'A is to buy for 2 weeks');
        self::assertSame(4, $this->stored()->page(ReorderList::filters(['buy_for' => '14']), 1, 200)['total'], 'the count follows N');
        $pick = static fn (array $l): array => [$l['review'], $l['usual_review'], $l['cover_days'], $l['target'], $l['position'], $l['need'], $l['packs'], $l['units'],
            $l['value_e4'], $l['rop'], $l['urgent'], $l['safety_stock'], $l['buy_for']];
        self::assertSame([14, 7, 22, 264, 180, 84, 4, 96, 4 * 450_000, 96, false, 60, 14], $pick($two[$s['a']]));
        self::assertSame([14, 7, 21, 42, 0, 42, 5, 50, 5 * 80_000, 14, true, 10, 14], $pick($two[$s['b']]));
        self::assertSame([14, 14, 23, 115, 0, 115, 20, 120, 20 * 120_000, 45, true, 25, 14], $pick($two[$s['c']]));
        self::assertSame([14, 7, 21, 21, 0, 21, 21, 21, null, 7, true, 5, 14], $pick($two[$s['d']]));
        foreach ([$s['b'], $s['c'], $s['d']] as $id) {
            self::assertSame([$usual[$id]['rop'], $usual[$id]['urgent'], $usual[$id]['safety_stock'], $usual[$id]['below_safety'], $usual[$id]['cover_now_e1']],
                [$two[$id]['rop'], $two[$id]['urgent'], $two[$id]['safety_stock'], $two[$id]['below_safety'], $two[$id]['cover_now_e1']], "{$id}: N moves none of them");
        }

        $one = $this->page(['buy_for' => '1']);
        self::assertSame([$s['c'], $s['b'], $s['d']], array_keys($one), 'nothing of A for 1 day');
        self::assertSame([9, 2, 8], [$one[$s['c']]['packs'], $one[$s['b']]['packs'], $one[$s['d']]['packs']]);
        $all = $this->page(['buy_for' => '1', 'show' => 'all']);
        self::assertSame([$s['c'], $s['b'], $s['d'], $s['e'], $s['a']], array_keys($all), 'every product, bought for 1 day');
        self::assertSame([0, 0], [$all[$s['a']]['packs'], $all[$s['e']]['packs']]);
        self::assertSame([$s['c'], $s['b'], $s['d']], array_keys($this->page(['below' => '1', 'buy_for' => '14'])), 'below the safety stock: as before');
        self::assertSame(5, $this->page(['below' => '1', 'buy_for' => '14'])[$s['b']]['packs'], '... with the packs for N');
        self::assertSame([$s['b'], $s['d'], $s['a']], array_keys($this->page(['brand' => 'elux', 'buy_for' => '14'])), 'the other filters as before');

        // The stored list bought for N is the list worked out now for N (the whole line, through its JSON), in the same order.
        foreach (['28', '1', '180'] as $n) {
            $live = array_column($this->reorderList()->lines(ReorderList::filters(['show' => 'all', 'buy_for' => $n])), null, 'sku_id');
            self::assertEquals(array_map(static fn (array $l): array => json_decode((string) json_encode($l), true), $live), $this->page(['show' => 'all', 'buy_for' => $n]),
                "N = {$n}");
            self::assertSame(array_keys($live), array_keys($this->page(['show' => 'all', 'buy_for' => $n])), "N = {$n}: the order");
        }

        // The order follows N: C capped at 120 by its largest stock; for 180 days B's cost (38 × £8 = £304) passes C's (20 × £12 = £240).
        (new ReorderSettings(self::$db))->saveItem($s['buyer'], $s['c'], 0, ['max_stock' => '120']);
        $this->refresh()->items([$s['c']]);
        self::assertSame([$s['c'], $s['b'], $s['d']], array_keys($this->page()), 'the usual list as it was (C 115 < 120)');
        $long = $this->page(['buy_for' => '180']);
        self::assertSame([$s['b'], $s['c'], $s['d'], $s['a']], array_keys($long));
        self::assertSame([[38, 380, 304 * 10_000], [945, 120, true, 20, 240 * 10_000], [87, 2088]], [[$long[$s['b']]['packs'], $long[$s['b']]['units'], $long[$s['b']]['value_e4']],
            [$long[$s['c']]['target_raw'], $long[$s['c']]['target'], $long[$s['c']]['max_applied'], $long[$s['c']]['packs'], $long[$s['c']]['value_e4']],
            [$long[$s['a']]['packs'], $long[$s['a']]['units']]]);
    }

    /**
     * 400 lines with random inputs (and 10 not worked out for N yet): for each N the SQL keeps and orders exactly the lines ReorderMath gives
     * packs for, in ReorderMath::compare's order, and "every product" holds them all.
     */
    public function testTheSqlWorksNOutExactlyAsReorderMath(): void
    {
        mt_srand(20261104);
        $names = [];
        for ($i = 0; $i < 410; $i++) {
            $names[] = "Buy for item {$i}";
        }
        self::$db->exec('INSERT INTO sku (name) VALUES ' . implode(', ', array_fill(0, count($names), '(?)')), $names);
        $ids = array_map('intval', self::$db->column("SELECT id FROM sku WHERE name LIKE 'Buy for item %' ORDER BY id"));
        $rows = [];
        $params = [];
        foreach ($ids as $k => $id) {
            $rate = mt_rand(0, 4) === 0 ? 0 : mt_rand(1, 2_000_000);
            $factor = mt_rand(0, 9) === 0 ? 0 : mt_rand(50, 150);
            $min = mt_rand(0, 3) === 0 ? mt_rand(0, 500) : null;
            $max = mt_rand(0, 3) === 0 ? max($min ?? 0, mt_rand(0, 4_000)) : null;
            $r = ['sku_id' => $id, 'rate_e4' => $rate, 'factor_e2' => $factor, 'lead' => mt_rand(0, 30), 'safety' => mt_rand(0, 30), 'available' => mt_rand(-200, 3_000),
                'min_stock' => $min, 'max_stock' => $max, 'upp' => [1, 1, 6, 10, 24, 100][mt_rand(0, 5)], 'moq' => mt_rand(1, 5), 'mult' => mt_rand(1, 4),
                'rounding' => mt_rand(0, 3) === 0 ? 'nearest' : 'up', 'never' => mt_rand(0, 9) === 0, 'pack_price_e4' => mt_rand(0, 4) === 0 ? null : mt_rand(0, 2_000_000),
                'urgent' => mt_rand(0, 1) === 1, 'cover_now_e1' => mt_rand(0, 5) === 0 ? null : mt_rand(-50, 2_000), 'ready' => $k < 400];
            $rows[$id] = $r;
            $d = $rate * $factor;
            array_push($params, $id, $r['urgent'] ? 1 : 0, $r['never'] ? 1 : 0, $r['cover_now_e1'] === null ? 1 : 0, $r['cover_now_e1'] ?? 0, mt_rand(0, 9_999_999),
                $r['ready'] ? $d : null, $r['lead'], $r['safety'], $r['available'], $min, $max, $r['upp'], $r['moq'], $r['mult'], $r['rounding'] === 'nearest' ? 1 : 0,
                $r['pack_price_e4']);
        }
        self::$db->exec('INSERT INTO reorder_line (sku_id, listed, need, urgent, below_safety, never_suggested, no_demand, cover_now_e1, value_e4, brand, supplier_id, '
            . 'safety_stock, available, low_stock_level, line_hash, read_at, changed_at, ' . implode(', ', ReorderLines::INPUTS) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), "(?, 1, 0, ?, 0, ?, ?, ?, ?, NULL, NULL, 0, 0, NULL, '" . sha1('x') . "', '2026-11-04 10:00:00', '2026-11-04 10:00:00', "
                . '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
        self::assertSame(10, $this->stored()->page(['buy_for' => 14, 'show' => 'all'], 1, 1)['waiting'], 'the 10 rows without inputs');
        self::assertSame(0, $this->stored()->page(['show' => 'all'], 1, 1)['waiting'], 'the usual list: none');

        foreach ([1, 7, 14, 28, 45, 180, 365] as $n) {
            $lines = [];
            foreach ($rows as $id => $r) {
                $m = ReorderMath::line(['rate_e4' => $r['rate_e4'], 'factor_e2' => $r['factor_e2'], 'lead' => $r['lead'], 'review' => $n, 'safety' => $r['safety'],
                    'min_stock' => $r['min_stock'], 'max_stock' => $r['max_stock'], 'available' => $r['available'], 'on_order' => 0, 'in_drafts' => 0, 'upp' => $r['upp'],
                    'moq' => $r['moq'], 'mult' => $r['mult'], 'rounding' => $r['rounding'], 'never' => $r['never'], 'pack_price_e4' => $r['pack_price_e4']]);
                $lines[] = ['sku_id' => $id, 'urgent' => $r['urgent'], 'cover_now_e1' => $r['cover_now_e1'], 'packs' => $r['ready'] ? $m['packs'] : 0,
                    'value_e4' => $r['ready'] ? $m['value_e4'] : null];
            }
            usort($lines, ReorderMath::compare(...));
            $need = array_values(array_filter($lines, static fn (array $l): bool => $l['packs'] > 0));
            self::assertGreaterThan(50, count($need), "N = {$n}: enough lines to buy to mean something");
            self::assertSame(array_column($need, 'sku_id'), $this->stored()->ids(['buy_for' => $n, 'show' => 'need']), "N = {$n}: to buy, in order");
            self::assertSame(array_column($lines, 'sku_id'), $this->stored()->ids(['buy_for' => $n, 'show' => 'all']), "N = {$n}: every product, in order");
            $page = $this->stored()->page(['buy_for' => $n, 'show' => 'need'], 2, 25);
            self::assertSame(count($need), $page['total'], "N = {$n}: the count");
        }
    }

    /** "Create draft orders" from the list bought for N: the packs the buyer kept, N's suggestion stored with each line; N out of range refused. */
    public function testDraftsKeepTheSuggestionForN(): void
    {
        $s = $this->listScenario();
        $r = $this->drafts()->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => 9, 'supplier_item_id' => (int) $s['si']['a']['id'], 'units_per_pack' => 24],
            ['sku_id' => $s['b'], 'packs' => 5, 'supplier_item_id' => (int) $s['si']['b']['id'], 'units_per_pack' => 10]], 'cw', str_repeat('e', 32), 14);
        self::assertCount(1, $r['drafts']);
        self::assertSame([], $r['skipped']);
        // A 22 × 12 = 264 - 60 = 204 -> 9 boxes = 216; B 21 × 2 = 42 -> 5 packs = 50.
        self::assertSame([[(int) $s['si']['a']['id'], 9, 216], [(int) $s['si']['b']['id'], 5, 50]], array_map(static fn (array $x): array => array_map('intval', array_values($x)),
            self::$db->all('SELECT supplier_item_id, packs, suggested_units FROM po_line WHERE document_id = ? ORDER BY supplier_item_id', [$r['drafts'][0]['document_id']])));
        $audit = json_decode((string) self::$db->value("SELECT CAST(detail AS CHAR) FROM audit_log WHERE action = 'reorder.draft_pos' ORDER BY id DESC LIMIT 1"), true);
        self::assertSame(14, $audit['buy_for'] ?? null);
        // The usual list's drafts as before: the usual suggestion, no buy_for in the audit.
        $u = $this->drafts()->create($s['buyer'], [['sku_id' => $s['c'], 'packs' => 20]], 'cw', str_repeat('f', 32));
        self::assertSame([[20, 120]], array_map(static fn (array $x): array => array_map('intval', array_values($x)),
            self::$db->all('SELECT packs, suggested_units FROM po_line WHERE document_id = ?', [$u['drafts'][0]['document_id']])));
        self::assertArrayNotHasKey('buy_for', (array) json_decode((string) self::$db->value("SELECT CAST(detail AS CHAR) FROM audit_log WHERE action = 'reorder.draft_pos' "
            . 'ORDER BY id DESC LIMIT 1'), true));
        // Outside reorder.buy_for_min_days..max_days (1..180): refused, nothing made.
        $before = (int) self::$db->value('SELECT COUNT(*) FROM purchase_order');
        foreach ([0, 181, 999] as $n) {
            self::refused(422, 'bad_buy_for', fn () => $this->drafts()->create($s['buyer'], [['sku_id' => $s['d'], 'packs' => 1]], 'cw', str_repeat('a', 32), $n));
        }
        $this->setting('reorder.buy_for_max_days', '30');
        self::refused(422, 'bad_buy_for', fn () => $this->drafts()->create($s['buyer'], [['sku_id' => $s['b'], 'packs' => 1]], 'cw', str_repeat('b', 32), 31));
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM purchase_order'));
    }

    /**
     * The refresh writes each line's compact inputs; a row without them (before 0061) or with old ones (written by older code) is written again
     * at the next run whatever its sha1, and the list counts it as "to buy" for N again only then.
     */
    public function testTheRefreshWritesTheInputsAndMendsMissingOrOldOnes(): void
    {
        $s = $this->listScenario();
        $inputsOf = static fn (int $id): array => array_map(static fn (mixed $v): ?int => $v === null ? null : (int) $v,
            array_values((array) self::$db->one('SELECT ' . implode(', ', ReorderLines::INPUTS) . ' FROM reorder_line WHERE sku_id = ?', [$id])));
        $all = $this->page(['show' => 'all']);
        foreach ($all as $id => $l) {
            self::assertSame(ReorderLines::inputs($l), $inputsOf($id), "{$id}: the inputs of its line");
        }
        self::assertSame([12_000_000, 3, 5, 60, null, null, 24, 2, 1, 0, 450_000], $inputsOf($s['a']), '12 a day (e6), 3 + 5 days, 60, boxes of 24, the smallest order 2, £45');
        $waiting = fn (): int => $this->stored()->page(ReorderList::filters(['buy_for' => '14']), 1, 200)['waiting'];
        self::assertSame(0, $waiting());

        self::$db->exec('UPDATE reorder_line SET d_e6 = NULL WHERE sku_id = ?', [$s['b']]); // a row of before 0061
        self::$db->exec('UPDATE reorder_line SET stock_position = 999 WHERE sku_id = ?', [$s['c']]); // old figures, written by older code
        self::assertSame(1, $waiting());
        self::assertSame(0, $this->stored()->page(ReorderList::filters(['buy_for' => '14', 'brand' => 'Lost Mary']), 1, 200)['waiting'], 'of these filters');
        self::assertSame([$s['d'], $s['a']], array_keys($this->page(['buy_for' => '14'])), 'B is not worked out for N yet; C has 999 of 115');
        self::assertSame(5, $this->page(['buy_for' => '14', 'show' => 'all'])[$s['b']]['packs'], 'its line is shown bought for N all the same');
        $r = $this->refresh()->all();
        self::assertSame(2, $r['changed'], 'both written again, though their lines did not change');
        self::assertSame(ReorderLines::inputs($all[$s['b']]), $inputsOf($s['b']));
        self::assertSame(ReorderLines::inputs($all[$s['c']]), $inputsOf($s['c']));
        self::assertSame(0, $waiting());
        self::assertSame([$s['c'], $s['b'], $s['d'], $s['a']], array_keys($this->page(['buy_for' => '14'])));
        self::assertSame(0, $this->refresh()->all()['changed'], 'nothing more to write');
    }

    /** The website-stock view (worked out on the page) follows N too. */
    public function testTheWebsiteStockViewFollowsN(): void
    {
        $s = $this->listScenario();
        $site = array_column($this->reorderList()->lines(ReorderList::filters(['stock' => 'site', 'buy_for' => '14'])), null, 'sku_id');
        self::assertSame([14, 22, 264, 0, 11], [$site[$s['a']]['review'], $site[$s['a']]['cover_days'], $site[$s['a']]['target'], $site[$s['a']]['available'],
            $site[$s['a']]['packs']], 'no website stock: 264 -> 11 boxes');
        self::assertSame('site', $site[$s['a']]['stock_source']);
    }
}
