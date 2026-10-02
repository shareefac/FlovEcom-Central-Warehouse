<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Reorder\ReorderList;
use CW\Reorder\ReorderSettings;

/**
 * The reorder lines (spec §7.4, docs/decisions.md I64-I65) against real purchase orders (sent, part-received, cancelled,
 * draft), the filters (brand, supplier, words, urgent, need / all), the site's stock, do-not-reorder, no supplier, the
 * brand and item factors, and the "Why".
 */
final class ReorderListTest extends ReorderTestCase
{
    /** @return array<int, array<string, mixed>> sku id => line */
    private function lines(array $q = [], ?array $skus = null): array
    {
        return array_column($this->reorderList()->lines(ReorderList::filters($q), $skus, true), null, 'sku_id');
    }

    /** POs of item A at S1: sent 2 boxes, approved 1 box with 10 units received, approved then cancelled, a draft of 3 boxes. */
    private function orders(array $s): void
    {
        $si = (int) $s['si']['a']['id'];
        $sent = $this->draftPo($s['buyer'], (int) $s['s1']['id'], [['supplier_item_id' => $si, 'packs' => 2]]);
        $sent = $this->pos->approve($s['buyer'], $sent->id, $sent->version);
        $this->pos->markSent($s['buyer'], $sent->id, $sent->version, 'email', 'orders@example.test', true);
        $part = $this->draftPo($s['buyer'], (int) $s['s1']['id'], [['supplier_item_id' => $si, 'packs' => 1]]);
        $part = $this->pos->approve($s['buyer'], $part->id, $part->version);
        self::$db->transaction(fn () => $this->pos->applyReceipt($part->id, [1 => 10], 'GRN-TEST'));
        $gone = $this->draftPo($s['buyer'], (int) $s['s1']['id'], [['supplier_item_id' => $si, 'packs' => 1]]);
        $gone = $this->pos->approve($s['buyer'], $gone->id, $gone->version);
        $this->pos->cancel($s['buyer'], $gone->id, $gone->version, 'not_needed', null);
        $this->draftPo($s['buyer'], (int) $s['s1']['id'], [['supplier_item_id' => $si, 'packs' => 3]]);
    }

    public function testTheLinesAgainstThePurchaseOrders(): void
    {
        $s = $this->listScenario();
        $this->orders($s);
        $l = $this->lines();
        self::assertSame([$s['c'], $s['b'], $s['d'], $s['a']], array_keys($l), 'urgent first (cover 0: value descending), then by cover now; E is never suggested');
        $a = $l[$s['a']];
        self::assertSame([120_000, 3, 7, 5, 15, 180, 96, 60, 48 + 14, 72, 122, 58, 3, 72, '45.0000', 3 * 450_000, false, 102],
            [$a['rate_e4'], $a['lead'], $a['review'], $a['safety'], $a['cover_days'], $a['target'], $a['rop'], $a['available'], $a['on_order'], $a['in_drafts'],
                $a['position'], $a['need'], $a['packs'], $a['units'], $a['pack_price'], $a['value_e4'], $a['urgent'], $a['cover_now_e1']],
            'lead from the supplier item; on order = sent 48 + the part-received box 24 - 10 received; the cancelled order and the draft (72) do not count');
        self::assertSame([(int) $s['s1']['id'], (string) $s['s1']['code'], 'ELX-A', 'box', 24, 2, 1], [$a['supplier_id'], $a['supplier'], $a['supplier_code'],
            $a['purchase_unit'], $a['upp'], $a['moq'], $a['mult']]);
        self::assertStringContainsString('Cover 3 lead + 7 review + 5 safety = 15 days → target 180. Available 60 + on order 62 = 122 (in drafts 72). '
            . 'Need 58 → 3 × box of 24 = 72. Plain 30-day average 12.0/day.', $a['explain']);
        self::assertStringStartsWith('Demand 12.0/day = 0.5×12.0 (28 days: 28 valid) + 0.5×12.0 (91 days: 91 valid); factor 1.00.', $a['explain']);
        $b = $l[$s['b']];
        self::assertSame([2, 7, 5, 28, 28, 3, 30, true, 3 * 80_000], [$b['lead'], $b['review'], $b['safety'], $b['target'], $b['need'], $b['packs'], $b['units'],
            $b['urgent'], $b['value_e4']], 'no lead on the supplier item or the supplier: the default 2');
        $c = $l[$s['c']];
        self::assertSame([4, 14, 5, 23, 115, 20, 120, true, 45], [$c['lead'], $c['review'], $c['safety'], $c['cover_days'], $c['target'], $c['packs'], $c['units'],
            $c['urgent'], $c['rop']], "the supplier's lead and review days");
        self::assertSame(['urgent'], $c['flags']);
        $d = $l[$s['d']];
        self::assertSame([null, 1, 14, 14, null, ['urgent', 'no_supplier']], [$d['supplier_id'], $d['upp'], $d['packs'], $d['units'], $d['value_e4'], $d['flags']]);
        self::assertStringContainsString('Need 14 → 14 units (no preferred supplier).', $d['explain']);
        // show=all: the item never suggested appears, with its reason.
        $all = $this->lines(['show' => 'all']);
        self::assertSame([$s['c'], $s['b'], $s['d'], $s['e'], $s['a']], array_keys($all));
        $e = $all[$s['e']];
        self::assertSame([42, 42, 0, ['urgent', 'no_supplier', 'do_not_reorder'], 'marked "do not reorder"'], [$e['target'], $e['need'], $e['packs'], $e['flags'], $e['never']]);
        self::assertStringContainsString('Need 42, never suggested: marked "do not reorder".', $e['explain']);
    }

    public function testTheFilters(): void
    {
        $s = $this->listScenario();
        self::assertSame([$s['b'], $s['d'], $s['a']], array_keys($this->lines(['brand' => 'elux'])), 'brand (any case)');
        self::assertSame([$s['c']], array_keys($this->lines(['supplier' => (string) $s['s2']['id']])), 'preferred supplier');
        self::assertSame([$s['b']], array_keys($this->lines(['q' => 'legend cola'])), 'words');
        self::assertSame([$s['a']], array_keys($this->lines(['q' => sprintf('CW-%06d', $s['a'])])), 'a CW code');
        self::assertSame([$s['c'], $s['b'], $s['d'], $s['a']], array_keys($this->lines(['urgent' => '1'])), 'A is urgent too without its orders: 60 < 96');
        self::assertSame([$s['a']], array_keys($this->lines(['show' => 'all'], [$s['a']])), 'the draft form reads its items only');
        self::assertSame([], $this->lines([], []));
        $f = ReorderList::filters(['brand' => ' ', 'supplier' => 'x', 'show' => 'everything', 'stock' => 'erp', 'urgent' => 'yes']);
        self::assertSame(['brand' => null, 'supplier' => null, 'q' => '', 'urgent' => false, 'show' => 'need', 'stock' => 'cw'], $f, 'unknown values: the defaults');
        $list = $this->reorderList();
        self::assertSame(['Elux', 'Lost Mary'], $list->brands());
        self::assertSame([(int) $s['s1']['id'], (int) $s['s2']['id']], array_column($list->suppliers(), 'id'));
    }

    public function testTheSitesOwnStock(): void
    {
        $s = $this->listScenario();
        $batch = (int) self::$db->value('SELECT id FROM sales_import_batch');
        // 601 managed stock (From-Warehouse, sellable); 603 In-Stock mode (sold whatever its figure); 602 In-Stock far below 0 (the
        // review's variant 48 at -35,794); 604 unsellable with a figure left on it (review finding, I78).
        self::$db->exec("INSERT INTO listing_stock_latest (channel_id, external_variant_id, snapshot_date, stock, stock_mode, sellable, batch_id) VALUES "
            . "(?, '601', '2026-10-01', 100, 'From-Warehouse', 1, ?), (?, '603', '2026-10-01', 3, 'In-Stock', 1, ?), (?, '602', '2026-10-01', -35794, 'In-Stock', 1, ?), "
            . "(?, '604', '2026-10-01', 40, 'Out-Of-Stock', 0, ?)", [$s['vpg'], $batch, $s['vpg'], $batch, $s['vpg'], $batch, $s['vpg'], $batch]);
        $l = $this->lines(['stock' => 'site', 'show' => 'all']);
        self::assertSame([100, '2026-10-01', 'site', 80, 4, 96], [$l[$s['a']]['available'], $l[$s['a']]['site_date'], $l[$s['a']]['stock_source'], $l[$s['a']]['need'],
            $l[$s['a']]['packs'], $l[$s['a']]['units']], 'need 80 at 24 a box: 4 boxes (MOQ 2 met)');
        self::assertNotContains('site_stock_unreliable', $l[$s['a']]['flags']);
        self::assertStringContainsString('Site stock 100 (vapeandgo, 1 Oct 2026) + on order 0 = 100 (in drafts 0).', $l[$s['a']]['explain']);
        self::assertSame(3, $l[$s['c']]['available']);
        self::assertContains('site_stock_unreliable', $l[$s['c']]['flags'], 'In-Stock mode: the figure is not kept');
        self::assertSame([0, 28, true], [$l[$s['b']]['available'], $l[$s['b']]['need'], in_array('site_stock_unreliable', $l[$s['b']]['flags'], true)],
            'below 0 counts as 0, not as 35,794 units more to buy');
        self::assertStringContainsString('[not reliable: the site sells In-Stock variants whatever their figure; below 0 counts as 0]', $l[$s['b']]['explain']);
        self::assertSame(0, $l[$s['d']]['available'], 'an unsellable listing\'s figure is not stock');
        self::assertSame(60, $this->lines()[$s['a']]['available'], 'CW stock by default');
    }

    public function testBrandAndItemSettings(): void
    {
        $s = $this->listScenario();
        $set = new ReorderSettings(self::$db);
        $set->saveBrand($s['buyer'], 'ELUX', 0, ['demand_factor' => '0.50', 'safety_days' => '10']);
        $a = $this->lines(['show' => 'all'])[$s['a']];
        self::assertSame([50, 'brand', 10, 6_000_000, 120, 60], [$a['factor_e2'], $a['factor_source'], $a['safety'], $a['d_e6'], $a['target'], $a['need']],
            'brand factor 0.50 and 10 safety days: 6 a day × 20 days');
        self::assertStringContainsString('; brand factor 0.50 → 6.0/day.', $a['explain']);
        self::assertSame(1, $this->lines()[$s['c']]['factor_e2'] / 100, 'another brand: unchanged');
        $set->saveItem($s['buyer'], $s['a'], 0, ['demand_factor' => '1.00', 'safety_days' => '0', 'lead_days_override' => '1', 'min_stock' => '200',
            'max_stock' => '250', 'pack_rounding' => 'nearest']);
        $a = $this->lines(['show' => 'all'])[$s['a']];
        self::assertSame([100, 'item', 0, 1, 200, 140, 6], [$a['factor_e2'], $a['factor_source'], $a['safety'], $a['lead'], $a['target'], $a['need'], $a['packs']],
            'the item comes first: 12 × 8 = 96 days of cover, raised to the minimum 200; need 140 = 5.83 boxes, nearest 6');
        $set->saveItem($s['buyer'], $s['a'], 1, ['max_stock' => '90']);
        self::assertSame([90, 30, 2], [$this->lines()[$s['a']]['target'], $this->lines()[$s['a']]['need'], $this->lines()[$s['a']]['packs']],
            'only a maximum of 90 now: the brand factor and safety days again, 6 × 20 = 120 capped at 90');
        self::refused(409, 'version_conflict', fn () => $set->saveItem($s['buyer'], $s['a'], 1, ['max_stock' => '80']));
        self::refused(403, 'role_not_allowed', fn () => $set->saveItem($this->staffUser('reviewer'), $s['a'], 2, ['max_stock' => '80']));
        self::refused(422, 'bad_field', fn () => $set->saveItem($s['buyer'], $s['a'], 2, ['demand_factor' => '5.5']));
        self::refused(422, 'unknown_brand', fn () => $set->saveBrand($s['buyer'], 'Nobody Makes This', 0, []));
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action IN ('reorder.item_settings', 'reorder.brand_settings')"));
    }

    public function testAMergedItemAndNoDemand(): void
    {
        $s = $this->listScenario();
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$s['a'], $s['b']]);
        $b = $this->lines(['show' => 'all'])[$s['b']];
        self::assertSame([0, 'merged into ' . sprintf('CW-%06d', $s['a'])], [$b['packs'], $b['never']]);
        self::assertContains('merged', $b['flags']);
        self::assertArrayNotHasKey($s['b'], $this->lines(), 'never suggested');
        // An item with only a minimum stock (no history) is on the list.
        $z = $this->brandItem('Spare pods', null);
        self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 10, 'system:test')", [$z]);
        $l = $this->lines()[$z];
        self::assertSame([0, 10, 10, ['urgent', 'no_supplier', 'no_history']], [$l['rate_e4'], $l['target'], $l['packs'], $l['flags']]);
        self::assertStringStartsWith('No sales history: demand 0/day; factor 1.00.', (string) $l['explain']);
    }
}
