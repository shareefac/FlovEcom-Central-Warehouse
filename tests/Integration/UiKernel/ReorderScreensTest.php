<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Integration\Reorder\ReorderFixtures;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;

/**
 * The reorder screens through the real /ui kernel as cw_app (spec §8.1, §9.3; I68) — the owner's step "a reorder list for a
 * brand -> draft PO": the list filtered by brand with its "Why" (the stockpiling window named), "create draft PO" (303 to
 * the PO editor with the lines; the same form twice: one draft; several suppliers: the list with the new drafts and what was
 * skipped), the truncation guard, the read-only reviewer and the refused desk, an item's settings (and the version
 * conflict), a brand factor, the anomaly windows, Recalculate and the CSV.
 */
final class ReorderScreensTest extends KernelUiTestCase
{
    use ReorderFixtures;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    /** @return array<string, mixed> the scenario with the stockpiling window, rebuilt */
    private function scenario(): array
    {
        $s = $this->listScenario();
        $this->stockpiling();
        $this->builder()->rebuild();
        return $s;
    }

    /** @return list<string> the item codes of the list's rows, in order */
    private static function codes(UiResponse $r): array
    {
        $out = [];
        foreach ((new \DOMXPath($r->dom()))->query('//table[contains(@class, "reorder")]/tbody/tr/th/a') ?: [] as $a) {
            $out[] = trim((string) $a->textContent);
        }
        return $out;
    }

    private static function skuCode(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    public function testTheBuyerFiltersABrandReadsWhyAndCreatesADraft(): void
    {
        $s = $this->scenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/purchasing/reorder', ['brand' => 'Elux']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame([self::skuCode($s['b']), self::skuCode($s['d']), self::skuCode($s['a'])], self::codes($page), 'Elux lines to order: B and D urgent, then A');
        self::assertStringContainsString('9 excluded: Pre-duty stockpiling 14–22 Sep', $page->text(), 'Why names the stockpiling window');
        self::assertStringContainsString('vapeandgo: sales history 2026-06-01 to 2026-10-01', $page->text());
        self::assertSame([['label' => 'Suppliers', 'href' => '/ui/purchasing/suppliers'], ['label' => 'Purchase orders', 'href' => '/ui/purchasing/orders'],
            ['label' => 'Reorder list', 'href' => '/ui/purchasing/reorder'], ['label' => 'Sales history', 'href' => '/ui/purchasing/sales-history']], self::nav($page)['Purchasing']);
        $form = $page->form('/ui/purchasing/reorder/draft');
        self::assertSame('3', $form['row_count']);
        self::assertSame(['pick_' . $s['b'], 'pick_' . $s['d'], 'pick_' . $s['a']], array_values(array_filter(array_keys($form), static fn (string $k): bool => str_starts_with($k, 'pick_'))),
            'the lines with packs are ticked (list order)');
        self::assertSame('5', $form['packs_' . $s['a']]);
        // Elux: A and B at S1, D has no supplier -> one draft and D skipped: back to the list with the new draft and the skipped item.
        $form['packs_' . $s['a']] = '6';
        $r = $web->post('/ui/purchasing/reorder/draft', $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertMatchesRegularExpression('#^/ui/purchasing/reorder\?notice=drafts&drafts=\d+&skipped=' . $s['d'] . '&brand=Elux$#', (string) $r->location());
        $done = $web->follow($r);
        self::assertSame(200, $done->status);
        self::assertStringContainsString('Draft purchase orders created from the ticked lines', $done->text());
        self::assertStringContainsString('2 lines, £294 net', $done->text(), 'A 6 boxes × £45 + B 3 packs × £8');
        self::assertStringContainsString(self::skuCode($s['d']) . ' Elux Legend Mint: no preferred supplier', $done->text());
        // The same form again: the same drafts (one effect).
        $again = $web->post('/ui/purchasing/reorder/draft', $form);
        self::assertSame($r->location(), $again->location());
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM purchase_order WHERE source = 'reorder'"));

        // One supplier, nothing skipped: straight to the PO editor with the line.
        $page = $web->get('/ui/purchasing/reorder', ['supplier' => (string) $s['s2']['id']]);
        $r = $web->post('/ui/purchasing/reorder/draft', $page->form('/ui/purchasing/reorder/draft'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertMatchesRegularExpression('#^/ui/purchasing/orders/(\d+)\?notice=created$#', (string) $r->location());
        $editor = $web->follow($r);
        self::assertSame(200, $editor->status);
        self::assertStringContainsString('MARY-C', $editor->text());
        self::assertTrue($editor->hasForm('/lines'), 'the creator gets the editor');
        $id = (int) preg_replace('#\D#', '', (string) parse_url((string) $r->location(), PHP_URL_PATH));
        self::assertSame([[20, 120]], array_map('array_values', self::$db->all('SELECT packs, suggested_units FROM po_line WHERE document_id = ?', [$id])));
    }

    public function testTheGuardsAndTheRoles(): void
    {
        $s = $this->scenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $form = $web->get('/ui/purchasing/reorder')->form('/ui/purchasing/reorder/draft');
        unset($form['packs_' . $s['a']]);
        $r = $web->post('/ui/purchasing/reorder/draft', $form);
        self::assertSame(400, $r->status);
        self::assertStringContainsString('form_truncated', $r->text());
        $form = $web->get('/ui/purchasing/reorder')->form('/ui/purchasing/reorder/draft');
        foreach (array_keys($form) as $k) {
            if (str_starts_with($k, 'pick_')) {
                unset($form[$k]);
            }
        }
        $r = $web->post('/ui/purchasing/reorder/draft', $form);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('tick at least one line', $r->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM purchase_order'));

        $reviewer = $this->signIn($this->uiUser('reviewer'));
        $page = $reviewer->get('/ui/purchasing/reorder');
        self::assertSame(200, $page->status);
        self::assertFalse($page->hasForm('/ui/purchasing/reorder/draft'), 'no create form for a reviewer');
        self::assertFalse($page->hasForm('/ui/purchasing/reorder/recalculate'));
        self::assertGreaterThan(0, count(self::codes($page)), 'the list is readable');
        $t = $this->token($reviewer);
        self::assertSame(403, $reviewer->post('/ui/purchasing/reorder/draft', ['csrf' => $t, 'row_count' => '0', 'form_key' => str_repeat('a', 32)])->status);
        self::assertSame(403, $reviewer->post('/ui/purchasing/reorder/recalculate', ['csrf' => $t])->status);
        self::assertSame(403, $reviewer->post('/ui/purchasing/reorder/items/' . $s['a'], ['csrf' => $t, 'version' => '0'])->status);
        $item = $reviewer->get('/ui/purchasing/reorder/items/' . $s['a']);
        self::assertSame(200, $item->status);
        self::assertFalse($item->hasForm('/ui/purchasing/reorder/items/'));
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        foreach (['/ui/purchasing/reorder', '/ui/purchasing/reorder.csv', '/ui/purchasing/reorder/brands', '/ui/purchasing/sales-history'] as $path) {
            self::assertSame(403, $desk->get($path)->status, $path);
        }
    }

    public function testItemSettingsWithTheVersionConflict(): void
    {
        $s = $this->scenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/purchasing/reorder/items/' . $s['a']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString('Day by day (computed now)', $page->text());
        self::assertStringContainsString('no: Pre-duty stockpiling (+43% units/day)', $page->text());
        self::assertStringContainsString('Need 120 → 5 × box of 24 = 120', $page->text());
        $form = $page->form('/ui/purchasing/reorder/items/' . $s['a']);
        self::assertSame('0', $form['version']);
        $form['demand_factor'] = '0.85';
        $form['safety_days'] = '7';
        $r = $web->post('/ui/purchasing/reorder/items/' . $s['a'], $form);
        self::assertSame(303, $r->status, $r->describe());
        $after = $web->follow($r);
        self::assertStringContainsString('Item settings saved', $after->text());
        self::assertStringContainsString('item factor 0.85 → 10.2/day', $after->text());
        self::assertSame(['0.85', 7, 1], array_values(array_map(static fn (mixed $v): mixed => is_int($v) ? $v : (string) $v,
            (array) self::$db->one('SELECT demand_factor, safety_days, version FROM item_reorder WHERE sku_id = ?', [$s['a']]))));
        // The same (stale) form again: 409, the page redrawn with the current values.
        $form['safety_days'] = '9';
        $r = $web->post('/ui/purchasing/reorder/items/' . $s['a'], $form);
        self::assertSame(409, $r->status);
        self::assertStringContainsString('These settings were changed since you opened them', $r->text());
        self::assertSame('7', $r->form('/ui/purchasing/reorder/items/' . $s['a'])['safety_days']);
        // A bad value: 422, what was typed is kept.
        $form = $r->form('/ui/purchasing/reorder/items/' . $s['a']);
        $form['max_stock'] = '1';
        $form['min_stock'] = '5';
        $bad = $web->post('/ui/purchasing/reorder/items/' . $s['a'], $form);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('the maximum stock is below the minimum stock', $bad->text());
        self::assertSame('1', $bad->form('/ui/purchasing/reorder/items/' . $s['a'])['max_stock']);
    }

    public function testBrandsAnomaliesRecalculateAndCsv(): void
    {
        $s = $this->scenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $brands = $web->get('/ui/purchasing/reorder/brands', ['brand' => 'Elux']);
        self::assertSame(200, $brands->status, $brands->describe());
        $form = $brands->form('/ui/purchasing/reorder/brands');
        self::assertSame(['Elux', '0'], [$form['brand'], $form['version']]);
        $form['demand_factor'] = '0.80';
        $r = $web->post('/ui/purchasing/reorder/brands', $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('Brand settings saved', $web->follow($r)->text());
        self::assertSame('0.80', (string) self::$db->value("SELECT demand_factor FROM reorder_brand WHERE brand = 'Elux'"));
        self::assertSame(409, $web->post('/ui/purchasing/reorder/brands', $form)->status, 'version 0 again');

        // Anomaly windows: add (one per form), end, end again.
        $page = $web->get('/ui/purchasing/reorder/anomalies');
        self::assertStringContainsString('Pre-duty stockpiling (+43% units/day)', $page->text());
        $add = $page->form('/ui/purchasing/reorder/anomalies');
        $add = ['date_from' => '2026-09-23', 'date_to' => '2026-09-24', 'brand' => 'Elux', 'label' => 'Elux 6 for £10', 'channel_id' => (string) $s['vpg']] + $add;
        $r = $web->post('/ui/purchasing/reorder/anomalies', $add);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($r->location(), $web->post('/ui/purchasing/reorder/anomalies', $add)->location(), 'the same form twice: one window');
        $id = (int) self::$db->value("SELECT id FROM demand_anomaly WHERE label = 'Elux 6 for £10'");
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM demand_anomaly WHERE label = 'Elux 6 for £10'"));
        $fresh = $web->get('/ui/purchasing/reorder/anomalies')->form('/ui/purchasing/reorder/anomalies');
        $bad = $web->post('/ui/purchasing/reorder/anomalies', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'label' => 'too long', 'csrf' => $fresh['csrf'],
            'form_key' => $fresh['form_key']] + $add);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('a window covers at most 93 days', $bad->text());
        $t = $this->token($web);
        $end = $web->post("/ui/purchasing/reorder/anomalies/{$id}/end", ['csrf' => $t]);
        self::assertSame(303, $end->status);
        self::assertStringContainsString('Window ended', $web->follow($end)->text());
        $again = $web->post("/ui/purchasing/reorder/anomalies/{$id}/end", ['csrf' => $t]);
        self::assertSame(409, $again->status);
        self::assertStringContainsString('already ended', $again->text());

        // Recalculate, then the CSV with every line and its Why.
        $r = $web->post('/ui/purchasing/reorder/recalculate', ['csrf' => $t]);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/purchasing/reorder?notice=recalculated', $r->location());
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'reorder.recalculate'"));
        $csv = $web->get('/ui/purchasing/reorder.csv', ['show' => 'all']);
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('attachment; filename="reorder.csv"', (string) $csv->header('content-disposition'));
        $rows = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), array_values(array_filter(preg_split('/\r\n/', substr($csv->body, 3)) ?: [])));
        self::assertSame(['item', 'name', 'brand', 'supplier', 'supplier_code', 'demand_per_day', 'rate', 'rate_raw_30'], array_slice($rows[0], 0, 8));
        self::assertCount(6, $rows, 'the header and every item (show=all)');
        $byCode = array_column(array_slice($rows, 1), null, 0);
        self::assertSame('12.0000', $byCode[self::skuCode($s['a'])][7], 'rate_raw_30: the plain average, stockpiling included');
        self::assertStringContainsString('brand factor 0.80', $byCode[self::skuCode($s['a'])][29]);

        // Review finding (I84): a catalogue too big to rebuild inside a UI request is refused with a pointer to the server tool.
        $vpg = (int) self::$db->value("SELECT id FROM channel WHERE code = 'vapeandgo'");
        $digits = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 '
            . 'UNION ALL SELECT 8 UNION ALL SELECT 9)';
        self::$db->exec("INSERT INTO sku (name) SELECT CONCAT('Bulk item ', a.d * 1000 + b.d * 100 + c.d * 10 + e.d) FROM {$digits} a, {$digits} b, {$digits} c, {$digits} e "
            . 'WHERE a.d * 1000 + b.d * 100 + c.d * 10 + e.d BETWEEN 1 AND 3001');
        self::$db->exec("UPDATE sku SET code = CONCAT('CW-', LPAD(id, 6, '0')) WHERE code IS NULL AND name LIKE 'Bulk item %'");
        self::$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) SELECT ?, CONCAT('bulk-', id), id, 1, 'mapped' "
            . "FROM sku WHERE name LIKE 'Bulk item %'", [$vpg]);
        $big = $web->post('/ui/purchasing/reorder/recalculate', ['csrf' => $t]);
        self::assertSame(409, $big->status, $big->describe());
        self::assertStringContainsString('bin/reorder_demand.php', $big->text());
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'reorder.recalculate'"), 'nothing rebuilt');
    }

    public function testTheLayoutOfAnEmptyList(): void
    {
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $page = $web->get('/ui/purchasing/reorder');
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString('No sales history is loaded yet', $page->text());
        self::assertStringContainsString('No line matches', $page->text());
        self::assertTrue($page->hasForm('/ui/purchasing/reorder/recalculate'));
        self::assertInstanceOf(KernelBrowser::class, $web);
    }
}
