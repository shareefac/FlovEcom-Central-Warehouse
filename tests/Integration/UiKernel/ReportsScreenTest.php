<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Clock;
use CW\Reports\ReportRefresh;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Pack E (docs/decisions.md PE1, PE8-PE11): the Reports tabs through the real /ui kernel as cw_app: who sees which, what each lists and in
 * which order, the settings that decide a list, the money adding up, and each list as a CSV.
 */
final class ReportsScreenTest extends KernelUiTestCase
{
    /** @return list<string> the product names of a report's table, in order */
    private static function names(UiResponse $r, string $table): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query("//table[contains(@class, \"{$table}\")]/tbody/tr/th/a")));
    }

    /**
     * @return list<list<string>> a table's rows as their cells' text: its own rows, not a group's heading or total nor the report's Total
     * (the standard report layout, docs/decisions.md RD-C11). Sales by store is one table since: its groups by store and day by day.
     */
    private static function cells(UiResponse $r, string $table): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        $rows = match ($table) {
            'report-sales' => '//table[contains(@class, "report-sales")]/tbody[1]/tr[not(@class)]',
            'report-days' => '//table[contains(@class, "report-sales")]/tbody[2]/tr[not(@class)]',
            default => "//table[contains(@class, \"{$table}\")]/tbody/tr[not(@class)]",
        };
        foreach ($xp->query($rows) ?: [] as $tr) {
            $row = [];
            foreach ($xp->query('th|td', $tr) ?: [] as $c) {
                $row[] = trim((string) preg_replace('/\s+/u', ' ', (string) $c->textContent));
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<array<int, string>> the rows of a CSV download */
    private static function csv(UiResponse $r): array
    {
        self::assertSame(200, $r->status, $r->describe());
        self::assertStringStartsWith('text/csv', (string) $r->header('Content-Type'));
        $lines = array_values(array_filter(explode("\r\n", substr($r->body, 3)), static fn (string $l): bool => $l !== ''));
        return array_map(static fn (string $l): array => str_getcsv($l), $lines);
    }

    public function testWhoSeesWhichReport(): void
    {
        $rev = $this->signIn($this->uiUser('reviewer'));
        $page = $rev->get('/ui/reports/stock-by-store');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['Reports', Words::MENU['stock_by_store']], [self::currentSection($page), self::currentTab($page)]);
        self::assertSame([Words::MENU['stock_value'], Words::MENU['stock_by_store'], Words::MENU['low_stock'], Words::MENU['slow_stock'], Words::MENU['sales_by_store']],
            array_column(self::sectionTabs($page), 'label'), 'Sidebar.dc.html\'s order');
        self::assertSame([], self::moreTabs($page), 'the audit log is a Settings page (RD7)');
        self::assertSame([null], array_values(array_filter(array_column(self::sectionTabs($page), 'href'), static fn (?string $h): bool => $h === null)),
            'every report is an entry: only the one shown has no link (CV13)');
        self::assertNull(self::sectionTabs($page)[1]['href'], 'the one shown');

        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame([Words::MENU['stock_by_store'], Words::MENU['low_stock'], Words::MENU['slow_stock'], Words::MENU['sales_by_store']],
            array_column(self::sectionTabs($buyer->get('/ui/reports/low-stock')), 'label'), 'no money');
        self::assertSame(403, $buyer->get('/ui/reports/stock-value')->status);
        self::assertSame(403, $buyer->get('/ui/reports/stock-value.csv')->status);
        $admin = $this->signIn($this->uiUser('admin'));
        foreach (array_keys(\CW\Ui\Controller\ReportsController::PAGES) as $slug) {
            self::assertSame(403, $admin->get('/ui/reports/' . $slug)->status, "admin: {$slug}");
        }
        $viewer = $this->signIn($this->uiUser('viewer'));
        self::assertSame(403, $viewer->get('/ui/reports/slow-stock')->status);
        self::assertSame(405, $rev->post('/ui/reports/low-stock', ['csrf' => $this->token($rev)])->status, 'read only');
    }

    public function testStockByStoreShowsWhatEachStoreCanSell(): void
    {
        $vpg = $this->site('vpg');
        $efg = $this->site('efg');
        $a = $this->item('strict', 10, 'Alpha 10ml');
        $b = $this->item('strict', 0, 'Beta pod');
        $c = $this->item('strict', 7, 'Gamma coil');
        $this->listing($vpg, 'VA', $a);
        $this->listing($vpg, 'VB', $b);
        $this->listing($vpg, 'VC', $c, 3);
        $this->listing($vpg, 'VX', null);
        $this->listing($efg, 'EA', $a);
        $this->ok($this->commit($vpg, 'o1', [self::line('VA', 'u1', 'u2')])); // 2 of Alpha's 10 are reserved for an order
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get('/ui/reports/stock-by-store', ['store' => (string) $vpg->channelId]);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['Beta pod', 'Alpha 10ml', 'Gamma coil'], self::names($page, 'report-store'), 'out of stock first, then by name; the unmatched variant is not a product');
        self::assertSame([[Words::REPORTS['out_of_stock'], '0'], [Words::REPORTS['in_stock'], '8'], [Words::REPORTS['in_stock'], '7']],
            array_map(static fn (array $r): array => [$r[2], $r[3]], self::cells($page, 'report-store')), 'Alpha: 10 less the 2 kept for an order (after the CW number, RD-C11)');
        self::assertStringContainsString(sprintf(Words::REPORTS['pack'], 3), $page->text());
        self::assertStringContainsString('15', $page->text(), 'Vape and Go can sell 8 + 7 units');
        self::assertSame(['Beta pod'], self::names($web->get('/ui/reports/stock-by-store', ['store' => (string) $vpg->channelId, 'show' => 'out']), 'report-store'));
        self::assertSame(['Beta pod', 'Alpha 10ml', 'Gamma coil'], self::names($web->get('/ui/reports/stock-by-store', ['store' => (string) $vpg->channelId, 'page' => '99']),
            'report-store'), 'a page past the end shows the last page');
        self::assertSame(['Gamma coil'], self::names($web->get('/ui/reports/stock-by-store', ['store' => (string) $vpg->channelId, 'q' => 'gamma']), 'report-store'));
        self::assertSame(['Alpha 10ml'], self::names($web->get('/ui/reports/stock-by-store', ['store' => (string) $efg->channelId]), 'report-store'));
        $csv = self::csv($web->get('/ui/reports/stock-by-store.csv', ['store' => (string) $vpg->channelId]));
        self::assertCount(4, $csv);
        self::assertSame(['VPG test site', sprintf('CW-%06d', $b), 'Beta pod'], array_slice($csv[1], 0, 3));
        self::assertSame(200, $web->get('/ui/reports/stock-by-store', ['store' => '999', 'show' => 'x', 'page' => '-3'])->status, 'nonsense is ignored');
    }

    public function testLowStockUsesTheDefaultLevelOrTheProductsOwn(): void
    {
        $vpg = $this->site('vpg');
        $x = $this->item('strict', 3, 'X low');
        $y = $this->item('strict', 0, 'Y none');
        $z = $this->item('strict', 8, 'Z own level');
        $w = $this->item('strict', 4, 'W discontinued');
        $v = $this->item('strict', 6, 'V plenty');
        $u = $this->item('strict', 1, 'U not sold');
        foreach (['X' => $x, 'Y' => $y, 'Z' => $z, 'W' => $w, 'V' => $v] as $variant => $sku) {
            $this->listing($vpg, $variant, $sku);
        }
        self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 10, 'system:test')", [$z]);
        (new \CW\Catalogue\ItemCards(self::$db))->save($this->staffUser('stock_controller'), $w, 0, ['discontinued' => 'yes']);
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/reports/low-stock');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['X low', 'Z own level'], self::names($page, 'report-low'), 'the fewest first; U is sold by no store, W is discontinued, V has 6 > 5');
        self::assertStringContainsString(Words::say('RD_REPORTS', 'low_line', 2, 1, 5), $page->text(), 'the figures in the report\'s head (RD-C11)');
        self::assertStringContainsString(Words::REPORTS['own_level'], $page->text());
        self::assertSame(['Y none'], self::names($web->get('/ui/reports/low-stock', ['show' => 'out']), 'report-low'));
        // The default is a setting: at 6, V is low too.
        $saved = self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'reports.low_stock_default'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST('6' AS JSON) WHERE setting_key = 'reports.low_stock_default'");
        try {
            self::assertSame(['X low', 'V plenty', 'Z own level'], self::names($web->get('/ui/reports/low-stock'), 'report-low'));
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'reports.low_stock_default'", [(string) $saved]);
        }
        $csv = self::csv($web->get('/ui/reports/low-stock.csv'));
        self::assertSame([sprintf('CW-%06d', $x), 'X low'], array_slice($csv[1], 0, 2));
        self::assertSame(['10', 'yes'], array_slice($csv[2], 4, 2));
        self::assertNotContains('/ui/purchasing/reorder/items/' . $x, $this->signIn($this->uiUser('goods_in'))->get('/ui/reports/low-stock')->hrefs(),
            'no reorder link for a job that cannot open the reorder list');
        self::assertContains('/ui/purchasing/reorder/items/' . $x, $page->hrefs());
        unset($u);
    }

    public function testStockValueAddsUpByProductBrandAndWarehouse(): void
    {
        $a = $this->item('strict', 0, 'Alpha');
        $b = $this->item('strict', 0, 'Beta');
        $c = $this->item('strict', 0, 'Gamma');
        self::$db->exec("UPDATE sku SET brand = 'Elux' WHERE id IN (?, ?)", [$a, $c]);
        $this->book('goods_in', $a, 10, 'MAIN', null, '2.500000');
        $this->book('goods_in', $a, 5, 'MAIN', null, '4.000000');
        $this->book('goods_in', $b, 3);
        $this->book('goods_in', $c, 3, 'MAIN', null, '1.333333');
        $other = self::$db->insert("INSERT INTO warehouse (code, name, is_sellable, stock_owner, owner_entity) VALUES ('ROOM2', 'Room of VPG 2', 0, 'other', 'VPG 2 Ltd')");
        $this->book('goods_in', $a, 4, 'ROOM2', null, '9.000000');
        (new ReportRefresh(self::$db))->run(true);
        $web = $this->signIn($this->uiUser('accountant'));
        $page = $web->get('/ui/reports/stock-value');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = new \DOMXPath($page->dom());
        // The report's head (the standard report layout, docs/decisions.md RD-C11; before it TP_Report's strip, W13.3): the day, at average cost,
        // items with stock and not sold in the slow days (the three came in today: none yet), the units without a cost, another account's units;
        // the Total row: units on the shelf (18 with a cost + 3 without) and our value at cost.
        $lines = array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent), iterator_to_array($xp->query('//div[@class="rp-paper-head"]/p[contains(@class, "rp-line")]')));
        self::assertStringEndsWith(Words::RD_REPORTS['at_cost'], $lines[0]);
        self::assertSame([Words::say('RD_REPORTS', 'items_n', 3) . ' · ' . Words::say('RD_REPORTS', 'not_sold', '£0.00', 60), Words::say('RD_REPORTS', 'no_cost', 3),
            Words::say('RD_REPORTS', 'theirs', 4)], array_slice($lines, 1, 3));
        self::assertSame([Words::RD_REPORTS['total'], '21', '', '£67.95'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query('//table[contains(@class, "report-value")]//tr[@class="rp-total"]/*'))));
        self::assertSame(1, $xp->query('//main//header//a[@download and starts-with(@href, "/ui/reports/stock-value.csv")]')->length, 'Export at the head\'s right');
        self::assertSame(['Alpha', 'Gamma', 'Beta'], self::names($page, 'report-value'), 'the biggest value first; a product without a cost last');
        self::assertSame([['15', '£4.26', '£63.95'], ['3', '£1.33', '£4.00'], ['3', Words::REPORTS['no_cost'], '']],
            array_map(static fn (array $r): array => array_slice($r, 2), self::cells($page, 'report-value')));
        // By brand: TP_Report's Share of our value and the total row (the units without a cost stay in the file and the strip).
        $total = static fn (UiResponse $r, string $t): array => array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//table[contains(@class, "' . $t . '")]//tr[@class="rp-total"]/*')));
        $brands = $web->get('/ui/reports/stock-value', ['by' => 'brand']);
        self::assertSame([['Elux', '2', '18', '£67.95', '100%'], [Words::REPORTS['no_brand'], '1', '3', '£0.00', '0%']], self::cells($brands, 'report-brands'));
        self::assertSame([Words::RD_REPORTS['total'], '3', '21', '£67.95', ''], $total($brands, 'report-brands'));
        $elu = $web->get('/ui/reports/stock-value', ['by' => 'brand', 'q' => 'elu']);
        self::assertSame([['Elux', '2', '18', '£67.95', '100%']], self::cells($elu, 'report-brands'), 'the one search finds a brand');
        self::assertSame([Words::RD_REPORTS['total'], '2', '18', '£67.95', ''], $total($elu, 'report-brands'));
        $wh = $web->get('/ui/reports/stock-value', ['by' => 'warehouse']);
        $byWh = self::cells($wh, 'report-warehouses');
        self::assertSame(['Main warehouse', Words::REPORTS['ours'], '3', '21', '£67.95', '100%'], $byWh[0]);
        self::assertSame(['Room of VPG 2', Words::REPORTS['not_ours'], '1', '4', '£17.05', ''], $byWh[1], 'shown, never in our value');
        self::assertSame([Words::REPORTS['ours_total'], '', '21', '£67.95', ''], $total($wh, 'report-warehouses'), 'the total is ours only');
        $brandCsv = self::csv($web->get('/ui/reports/stock-value.csv', ['by' => 'brand', 'q' => 'elu']));
        self::assertSame([['brand', 'products', 'units', 'value_gbp', 'units_without_a_cost'], ['Elux', '2', '18', '67.95', '0']], $brandCsv, 'the file holds the same search');
        self::assertSame(['Beta'], self::names($web->get('/ui/reports/stock-value', ['nocost' => '1']), 'report-value'));
        $csv = self::csv($web->get('/ui/reports/stock-value.csv'));
        self::assertSame(['Alpha', '15', '4.263158', '63.95'], [$csv[1][1], $csv[1][3], $csv[1][4], $csv[1][5]]);
        self::assertSame(['Beta', '3', '', ''], [$csv[3][1], $csv[3][3], $csv[3][4], $csv[3][5]]);
        self::assertStringContainsString(Words::REPORTS['past_date'], $page->text());
        unset($other);
    }

    public function testSlowStockByDaysWithoutASale(): void
    {
        $p1 = $this->item('strict', 4, 'P1 sold lately');
        $p2 = $this->item('strict', 5, 'P2 slow');
        $p3 = $this->item('strict', 6, 'P3 never sold');
        $p4 = $this->item('strict', 7, 'P4 not read yet');
        $today = new \DateTimeImmutable(ReportRefresh::ukDay(Clock::db(Clock::now())));
        $day = static fn (int $ago): string => $today->modify("-{$ago} days")->format('Y-m-d');
        foreach ([[$p1, $day(10), $day(500)], [$p2, $day(100), $day(500)], [$p3, null, $day(400)]] as [$sku, $sale, $in]) {
            self::$db->exec('INSERT INTO report_item (sku_id, in_units, in_value, first_in_on, last_sale_on) VALUES (?, 10, 20, ?, ?)', [$sku, $in, $sale]);
        }
        self::$db->exec("INSERT INTO report_refresh (id, ran_at) VALUES (1, '2026-10-01 09:00:00')");
        $rev = $this->signIn($this->uiUser('reviewer'));
        $page = $rev->get('/ui/reports/slow-stock');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['P2 slow'], self::names($page, 'report-slow'), 'no sale for 60 days or more, under 180');
        self::assertSame(['P3 never sold'], self::names($rev->get('/ui/reports/slow-stock', ['show' => 'dead']), 'report-slow'));
        // The approved TP_Report (W13.6): the two lists are the strip's figures and the Show row's choices, each with its count.
        self::assertSame([sprintf(Words::REPORTS['show_slow_n'], 1), sprintf(Words::REPORTS['show_dead_n'], 1)], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($page->dom()))->query('//main//form[@method="get"]//select[@name="show"]/option'))), 'both lists counted in the one read');

        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//main//nav[contains(@class, "tabs")]')->length, 'no tab row (DC28)');
        self::assertSame(['P3 never sold'], self::names($rev->get('/ui/reports/slow-stock', ['show' => 'dead', 'page' => '7']), 'report-slow'));
        self::assertStringContainsString(Words::say('RD_REPORTS', 'slow_line', 1, 60, 1, 180), $page->text(), 'the slow and dead days and their counts: the report\'s head (RD-C11)');
        self::assertStringContainsString(sprintf(Words::REPORTS['figures_at'], '1 Oct 2026, 10:00'), $page->text(), 'when the job last ran, in UK time');
        $dead = self::cells($rev->get('/ui/reports/slow-stock', ['show' => 'dead']), 'report-slow');
        self::assertStringContainsString(Words::REPORTS['never_sold'], $dead[0][3]);
        self::assertSame(['400', '£12.00'], [$dead[0][4], $dead[0][5]], '6 units at £2.00');
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertCount(5, self::cells($buyer->get('/ui/reports/slow-stock', ['show' => 'dead']), 'report-slow')[0], 'no money for a buyer');
        $csv = self::csv($buyer->get('/ui/reports/slow-stock.csv', ['show' => 'dead']));
        self::assertNotContains('value_gbp', $csv[0]);
        unset($p4);
    }

    public function testSalesByStoreOverAPeriod(): void
    {
        $vpg = $this->site('vpg');
        $efg = $this->site('efg');
        $today = new \DateTimeImmutable(ReportRefresh::ukDay(Clock::db(Clock::now())));
        $d = static fn (int $ago): string => $today->modify("-{$ago} days")->format('Y-m-d');
        foreach ([[$d(1), $vpg, 3, 7, 2, 1, 0], [$d(2), $vpg, 1, 1, 0, 0, 1], [$d(1), $efg, 2, 4, 0, 0, 0], [$d(40), $vpg, 9, 9, 9, 0, 0]] as $r) {
            self::$db->exec('INSERT INTO report_sales_day (sale_date, channel_id, orders, units_sold, units_sent, units_cancelled, units_returned) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$r[0], $r[1]->channelId, $r[2], $r[3], $r[4], $r[5], $r[6]]);
        }
        $web = $this->signIn($this->uiUser('manager'));
        $page = $web->get('/ui/reports/sales-by-store');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame([['VPG test site', '4', '8', '2', '1', '1'], ['EFG test site', '2', '4', '0', '0', '0']],
            self::cells($page, 'report-sales'), 'the last 30 days: the day 40 days ago is left out');
        $gx = new \DOMXPath($page->dom());
        self::assertSame([Words::REPORTS['total'], '6', '12', '2', '1', '1'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($gx->query('//table[contains(@class, "report-sales")]//tr[@class="rp-gtotal"]/*'))), 'the group\'s total (RD-C11)');
        self::assertSame([Words::RD_REPORTS['total'], '6', '12', '2', '1', '1'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($gx->query('//table[contains(@class, "report-sales")]//tr[@class="rp-total"]/*'))), 'the report\'s Total');
        self::assertCount(2, self::cells($page, 'report-days'));
        self::assertStringContainsString(Words::REPORTS['never_run'], $page->text());
        // The standard report layout (docs/decisions.md RD-C11): the groups by store and day by day in one table, the days and the store
        // among the options, the file a download at the head's right.
        $xp = new \DOMXPath($page->dom());
        self::assertSame([Words::REPORTS['by_store'], Words::REPORTS['by_day']], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query('//table[contains(@class, "report-sales")]//tr[@class="rp-group"]/th'))));
        self::assertSame(['period', 'from', 'to', 'store'], array_map(static fn (\DOMElement $el): string => $el->getAttribute('name'),
            iterator_to_array($xp->query('//main//form[contains(@class, "rp-options")]//*[self::select or self::input]'))));
        self::assertSame(1, $xp->query('//main//header//a[@download and starts-with(@href, "/ui/reports/sales-by-store.csv")]')->length, 'the file at the head\'s right (W13.7)');
        self::assertSame(0, $xp->query('//main//form[@method="get"]//a[@download]')->length);
        self::assertStringContainsString(Words::REPORTS['never_run'], (string) $xp->evaluate('string(//div[@class="rp-paper-head"])'), 'the days and when: the report\'s head');
        self::assertSame(1, $xp->query('//main//header//details[@class="help"]')->length, 'what a unit is: the page\'s "?", not a paragraph under the boards');
        self::assertStringContainsString('pack of 3 is 3 units', (string) $xp->evaluate('string(//main//header//details[@class="help"])'));
        $one = $web->get('/ui/reports/sales-by-store', ['store' => (string) $efg->channelId]);
        self::assertSame([['2', '4']], array_map(static fn (array $r): array => [$r[1], $r[2]], self::cells($one, 'report-days')));
        // Chosen days, given the wrong way round; longer than the setting allows: the last days are kept, and it says so.
        $chosen = $web->get('/ui/reports/sales-by-store', ['period' => 'choose', 'from' => $d(1), 'to' => $d(45)]);
        self::assertSame('13', self::cells($chosen, 'report-sales')[0][1]);
        $saved = self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'reports.sales_max_days'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST('7' AS JSON) WHERE setting_key = 'reports.sales_max_days'");
        try {
            $long = $web->get('/ui/reports/sales-by-store', ['period' => 'choose', 'from' => $d(60), 'to' => $d(0)]);
            self::assertStringContainsString(sprintf(Words::REPORTS['too_long'], '7'), $long->text());
            self::assertSame('4', self::cells($long, 'report-sales')[0][1], 'only the last 7 days');
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'reports.sales_max_days'", [(string) $saved]);
        }
        self::assertStringContainsString(Words::REPORTS['bad_dates'], $web->get('/ui/reports/sales-by-store', ['period' => 'choose', 'from' => '2026-02-31', 'to' => 'x'])->text());
        $csv = self::csv($web->get('/ui/reports/sales-by-store.csv'));
        self::assertSame(['day', 'store', 'orders', 'units_sold', 'units_sent', 'units_cancelled', 'units_returned'], $csv[0]);
        self::assertSame([$d(1), Words::REPORTS['all_stores'], '5', '11'], array_slice($csv[1], 0, 4), 'newest day first, the stores added up');
        // Nothing sold in the days: says why.
        $empty = $web->get('/ui/reports/sales-by-store', ['period' => 'choose', 'from' => $d(300), 'to' => $d(290)]);
        self::assertStringContainsString(Words::REPORTS['no_sales'], $empty->text());
    }
}
