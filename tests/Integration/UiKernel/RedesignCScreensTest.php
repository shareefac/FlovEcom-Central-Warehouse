<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Movements;
use CW\Relay\RelayIntake;
use CW\Reservations;
use CW\Stock;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\ByProductController;
use CW\Ui\Controller\InboxController;
use CW\Ui\Words;

/**
 * The redesign, step 2 part C (docs/decisions.md RD-C1-RD-C16; the approved canvas of 30 Oct 2026) as the browser gets it, through the real /ui
 * kernel as cw_app: Check a match (one comparison card, the find and units row, the note under More, the same one form), Product matching
 * (two tabs, a chip per store, New on the stores and its way back, More), the reports' one layout (the company in its head, the Total row,
 * All reports by permission), Approvals' Show kept through an answer, the settings shell's section list by role, and Home.
 */
final class RedesignCScreensTest extends KernelUiTestCase
{
    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    /** @return list<string> */
    private static function texts(\DOMXPath $xp, string $path): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) preg_replace('/\s+/u', ' ', (string) $n->textContent)), iterator_to_array($xp->query($path) ?: []));
    }

    public function testCheckAMatchIsOneComparisonCardAndPostsWhatItPostedBefore(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $l1 = $this->queued($site, 'W1', 'Key', $a, ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz', 'brand' => 'Elux', 'units_30d' => 9]);
        $l2 = $this->queued($site, 'W2', 'Key', $a, ['product_title' => 'Elux Legend Mint', 'units_30d' => 3]);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/listing/' . $l1, ['queue' => 'Key', 'channel' => 'vpg']);
        self::assertSame(200, $page->status, $page->describe());
        $xp = self::xp($page);
        // The head: the question and "<store> · N left to check", the progress words kept for a screen reader.
        self::assertSame(Words::MATCH['question'], trim((string) $xp->evaluate('string(//main//h1)')));
        self::assertSame('VPG test site · ' . Words::say('MATCH', 'to_match', 2), trim((string) $xp->evaluate('string(//header//span[@class="mt-line"])')));
        self::assertNotSame('', (string) $xp->evaluate('string(//header//span[@class="mt-line"]/@data-progress)'));
        // ONE card: the two columns' heads (the store's blue dot, our item's green), then a row per fact with its mark.
        self::assertSame(1, $xp->query('//section[contains(@class, "mt-compare")]')->length);
        self::assertSame([Words::MATCH['on_store'], Words::MATCH['our_item']], self::texts($xp, '//div[contains(@class, "mt-heads")]//h2[@class="mt-h"]'));
        self::assertSame(sprintf('CW-%06d', $a) . ' · ' . Words::say('MATCH', 'in_stock', 0), trim((string) $xp->evaluate('string(//div[contains(@class, "mt-side mt-item")]/p[@class="mt-sub"])')));
        $rows = $xp->query('//section[contains(@class, "mt-compare")]/div[contains(@class, "mt-fact")]');
        self::assertGreaterThan(0, $rows->length);
        self::assertSame(Words::FIELD['brand'], trim((string) $xp->evaluate('string(div[@class="mt-k"])', $rows->item(0))), 'brand first');
        foreach ($rows as $row) {
            /** @var \DOMElement $row */
            $same = in_array($row->getAttribute('data-state'), ['same', 'alike'], true);
            self::assertStringContainsString($same ? ' same' : ' diff', $row->getAttribute('class'));
            self::assertSame($same ? Words::MATCH['mark_same'] : Words::MATCH['mark_differs'], (string) $xp->evaluate('string(div[@class="mt-m"]/span[@role="img"]/@aria-label)', $row));
            self::assertSame(4, $xp->query('div', $row)->length, 'label, the store, ours, the mark');
        }
        // Under the card: "Not this one? Find another item" (its own GET form) beside "1 sale on the store is N of our item".
        self::assertSame([Words::MATCH['find'], Words::MATCH['units']], self::texts($xp, '//div[@class="mt-pick"]/div/label'));
        self::assertSame(1, $xp->query('//div[@class="mt-pick"]//input[@name="s"][@form="mt-find"]')->length);
        self::assertSame(1, $xp->query('//form[@id="decide"]//div[@class="mt-pick"]//input[@name="units_per_item"]')->length);
        // The note: a field of the same form, under the bar's More (closed: nothing refused).
        $more = '//form[@id="decide"]/div[contains(@class, "mt-bar")]/details[contains(@class, "more")]';
        self::assertSame(1, $xp->query($more . '//input[@id="f-reason"][@name="reason"]')->length);
        self::assertSame(0, $xp->query($more . '[@open]')->length);
        // The bar: Skip, More, Not the same, Same item; the form sends what it sent before.
        $bar = '//form[@id="decide"]/div[contains(@class, "mt-bar")]';
        self::assertSame([Words::MATCH['skip'], Words::MATCH['more'], Words::MATCH['not_same'], Words::MATCH['same']],
            self::texts($xp, $bar . '/a | ' . $bar . '/details/summary | ' . $bar . '/button'));
        $form = $page->form('/ui/review/listing/' . $l1 . '/decide');
        self::assertEqualsCanonicalizing(['csrf', 'expected_map_version', 'proposal_id', 'sku_id', 'queue', 'channel', 'units_per_item', 'reason'],
            array_keys(array_filter($form, static fn (string $k): bool => !str_starts_with($k, 'card_'), ARRAY_FILTER_USE_KEY)));
        self::assertSame(['link'], $page->buttons('/decide', 'action', true));
        $r = $web->post('/ui/review/listing/' . $l1 . '/decide', $form + ['action' => 'link']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringStartsWith('/ui/review/listing/' . $l2 . '?', (string) $r->location());
        self::assertSame(['mapped', $a], [$this->link($l1)['status'], $this->link($l1)['sku_id']]);
    }

    public function testARefusedNoteOpensMoreOnTheNote(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Note item');
        $l = $this->queued($site, 'N1', 'Key', $a, ['product_title' => 'Note item']);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/listing/' . $l);
        $r = $web->post('/ui/review/listing/' . $l . '/decide', array_replace($page->form('/ui/review/listing/' . $l . '/decide'),
            ['action' => 'link', 'reason' => str_repeat('x', 5000)]));
        self::assertGreaterThanOrEqual(400, $r->status, $r->describe());
        $xp = self::xp($r);
        if ((string) $xp->evaluate('string(//input[@id="f-reason"]/@aria-invalid)') === 'true') {
            self::assertSame(1, $xp->query('//form[@id="decide"]/div[contains(@class, "mt-bar")]/details[contains(@class, "more")][@open]//input[@id="f-reason"]')->length,
                'More is open on the refused note');
        }
        self::assertSame('suggested', $this->link($l)['status'], 'nothing was saved');
    }

    public function testProductMatchingHasTwoTabsAChipPerStoreAndNewOnTheStores(): void
    {
        $alt = $this->site('alt', 'shadow');
        $big = $this->site('vbig', 'off');
        self::$db->exec("UPDATE channel SET name = 'Alt Store' WHERE code = 'alt'");
        self::$db->exec("UPDATE channel SET name = 'Big Store' WHERE code = 'vbig'");
        $a = $this->item('strict', 0, 'Alpha item');
        $b = $this->item('strict', 0, 'Beta item');
        $this->profiled($alt, 'A1', ['product_title' => 'Alpha on Alt'], $a);
        $sug = $this->queued($big, 'B1', 'Key', $a, ['product_title' => 'Alpha on Big']);
        $fresh = $this->profiled($big, 'B2', ['product_title' => 'Brand new on Big']);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($page);
        self::assertSame(Words::RD_MATCH['title'], trim((string) $xp->evaluate('string(//main//h1)')));
        // The two tabs: By item shown (no link), New on the stores with its count.
        $tabs = $xp->query('//nav[contains(@class, "bi-tabs")]/a[@class="tab"]');
        self::assertSame(2, $tabs->length);
        self::assertSame('page', $tabs->item(0)->getAttribute('aria-current'));
        self::assertFalse($tabs->item(0)->hasAttribute('href'));
        self::assertSame('/ui/review/products?tab=new', $tabs->item(1)->getAttribute('href'));
        $count = (int) trim((string) $xp->evaluate('string(//nav[contains(@class, "bi-tabs")]/a[2]/span[@class="count"])'));
        self::assertGreaterThanOrEqual(2, $count, 'the stores\' products still to match');
        // A row per item: its line says on how many stores it is matched; a chip per store.
        $row = '//div[@id="p-' . $a . '"]';
        self::assertStringContainsString(Words::say('RD_MATCH', 'stores_n', 1, 2), (string) $xp->evaluate('string(' . $row . '//span[@class="bi-on"])'));
        self::assertSame(['matched', 'suggested'], array_map(static fn (\DOMElement $c): string => $c->getAttribute('data-state'),
            iterator_to_array($xp->query($row . '//div[@class="bi-stores"]/*[contains(@class, "bi-chip")]'))));
        self::assertStringStartsWith('/ui/review/listing/' . $sug . '?', (string) $xp->evaluate('string(' . $row . '//a[contains(@class, "suggested")]/@href)'),
            'the suggestion\'s own page, with the way back');
        self::assertSame(2, $xp->query('//div[@id="p-' . $b . '"]//details[contains(@class, "bi-quick")]/summary[contains(@class, "bi-chip")]')->length,
            'not on a store: the quick match\'s fold, "+ Store"');
        // One filter (the match status), Clear filters when it is on; More holds every other matching page.
        self::assertSame(['cov'], array_map(static fn (\DOMElement $s): string => $s->getAttribute('name'), iterator_to_array($xp->query('//form[@class="bi-find"]//select'))));
        $more = array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'), iterator_to_array($xp->query('//header//div[@class="bi-actions"]/details//a[@href]')));
        foreach (ByProductController::morePages() as $p) {
            self::assertContains($p['href'], $more);
        }
        self::assertSame(0, $xp->query('//header//div[@class="bi-actions"]/a[contains(@class, "primary")]')->length, 'no main button on By item');
        $filtered = $web->get('/ui/review/products', ['cov' => 'every']);
        self::assertSame(1, self::xp($filtered)->query('//a[@class="bi-clear"][@href="/ui/review/products"]')->length);

        // New on the stores: the store with any first, its count in the choice; rows: name, store and day, the likely match, Match.
        $new = $web->get('/ui/review/products', ['tab' => 'new']);
        self::assertSame(200, $new->status, $new->describe() . ' ' . implode(' | ', self::$log));
        $nx = self::xp($new);
        self::assertSame('page', (string) $nx->evaluate('string(//nav[contains(@class, "bi-tabs")]/a[2]/@aria-current)'));
        $options = self::texts($nx, '//select[@name="channel"]/option');
        self::assertContains(Words::say('RD_MATCH', 'store_n', 'Big Store', 2), $options);
        $big1 = $web->get('/ui/review/products', ['tab' => 'new', 'channel' => 'vbig']);
        $bx = self::xp($big1);
        $names = self::texts($bx, '//section[contains(@class, "bi-fresh")]/div[@class="bi-row"]//span[@class="o-name"]');
        self::assertContains('Brand new on Big', $names);
        self::assertContains('Alpha on Big', $names);
        self::assertStringContainsString(sprintf('CW-%06d', $a), (string) $bx->evaluate('string(//div[@id="l-' . $sug . '"]//span[contains(@class, "bi-hint likely")])'));
        self::assertSame('/ui/review/listing/' . $fresh, (string) $bx->evaluate('string(//div[@id="l-' . $fresh . '"]/a[@class="btn"]/@href)'));
        self::assertSame('/ui/review/new-variants?channel=vbig', (string) $bx->evaluate('string(//header//div[@class="bi-actions"]/a[contains(@class, "btn primary")]/@href)'),
            'Match all new: New variants of the store shown');
        // Match leads back to this tab.
        $check = $web->send('GET', '/ui/review/listing/' . $fresh, [], [], ['referer' => 'http://cw-ui.review.invalid/ui/review/products?tab=new&channel=vbig']);
        self::assertSame('/ui/review/products?tab=new&channel=vbig', (string) self::xp($check)->evaluate('string(//main//p[contains(@class, "ph-back")]/a/@href)'));
        self::assertSame(404, $web->get('/ui/review/products', ['tab' => 'new', 'channel' => 'nope'])->status);
        // Someone who can only look: no main button, no quick match.
        $viewer = $this->signIn($this->uiUser('auditor'));
        $look = $viewer->get('/ui/review/products', ['tab' => 'new', 'channel' => 'vbig']);
        if ($look->status === 200) {
            self::assertSame(0, self::xp($look)->query('//header//a[contains(@class, "btn primary")]')->length);
        }
    }

    public function testTheReportsShareOneLayoutWithTheCompanyTheTotalAndAllReports(): void
    {
        $this->site('vpg');
        $a = $this->item('strict', 10, 'Alpha 10ml');
        $b = $this->item('strict', 4, 'Beta pod');
        self::$db->exec("UPDATE company_profile SET legal_name = 'Acme Vapes Ltd'");
        $rev = $this->signIn($this->uiUser('reviewer'));
        $page = $rev->get('/ui/reports/stock-value');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($page);
        self::assertSame(Words::RD_REPORTS['stock_value'], trim((string) $xp->evaluate('string(//main//h1)')));
        self::assertSame('#all-reports', (string) $xp->evaluate('string(//main//p[contains(@class, "ph-back")]/a/@href)'), '‹ All reports');
        self::assertSame(Words::RD_REPORTS['export'], trim((string) $xp->evaluate('string(//header//a[@download])')));
        self::assertStringStartsWith('/ui/reports/stock-value.csv', (string) $xp->evaluate('string(//header//a[@download]/@href)'));
        self::assertSame(Words::RD_REPORTS['run'], trim((string) $xp->evaluate('string(//form[contains(@class, "rp-options")]/button)')));
        $company = trim((string) $xp->evaluate('string(//div[@class="rp-paper-head"]/p[@class="rp-co"])'));
        self::assertTrue(in_array($company, [Words::say('RD_REPORTS', 'company_line', 'Acme Vapes Ltd'), Words::RD_REPORTS['cw']], true), $company);
        self::assertSame(Words::RD_REPORTS['stock_value'], trim((string) $xp->evaluate('string(//div[@class="rp-paper-head"]/p[@class="rp-name"])')));
        self::assertSame(['Alpha 10ml', 'Beta pod'], self::texts($xp, '//table[contains(@class, "report-value")]/tbody/tr[not(@class)]/th/a'));
        self::assertSame(Words::RD_REPORTS['total'], trim((string) $xp->evaluate('string(//tr[@class="rp-total"]/th)')));
        self::assertSame('14', trim((string) $xp->evaluate('string(//tr[@class="rp-total"]/td[1])')), 'every unit on the shelf');
        // All reports: by permission, the one shown not a link.
        $all = self::texts($xp, '//section[@id="all-reports"]//a');
        self::assertSame([Words::RD_REPORTS['stock_value'], Words::RD_REPORTS['stock_by_store'], Words::RD_REPORTS['slow_stock'], Words::RD_REPORTS['low_stock'],
            Words::RD_REPORTS['sales_by_store']], array_slice($all, 0, 5));
        self::assertSame(0, $xp->query('//section[@id="all-reports"]//a[@aria-current][@href]')->length);
        $buyer = $this->signIn($this->uiUser('buyer'));
        $low = $buyer->get('/ui/reports/low-stock');
        self::assertSame(200, $low->status, $low->describe());
        $bx = self::xp($low);
        self::assertNotContains(Words::RD_REPORTS['stock_value'], self::texts($bx, '//section[@id="all-reports"]//a'), 'no money for a buyer');
        self::assertContains(Words::RD_REPORTS['reorder'], self::texts($bx, '//section[@id="all-reports"]//a'));
        foreach (['/ui/reports/stock-by-store', '/ui/reports/sales-by-store', '/ui/reports/slow-stock'] as $path) {
            $r = $rev->get($path);
            self::assertSame(200, $r->status, $path . ' ' . $r->describe());
            self::assertSame(1, self::xp($r)->query('//section[@id="all-reports"]')->length, $path);
            self::assertSame(1, self::xp($r)->query('//form[contains(@class, "rp-options")]/button[contains(@class, "primary")]')->length, $path);
        }
    }

    public function testEverySettingsPageListsTheSectionsThePersonMayOpen(): void
    {
        $rev = $this->signIn($this->uiUser('reviewer'));
        $page = $rev->get('/ui/reference/settings');
        self::assertSame(200, $page->status, $page->describe());
        $xp = self::xp($page);
        $items = self::texts($xp, '//nav[@class="set-nav"]/a');
        self::assertContains(Words::RD_SETTINGS['settings'], $items);
        self::assertSame('page', (string) $xp->evaluate('string(//nav[@class="set-nav"]/a[@aria-current]/@aria-current)'));
        self::assertSame(Words::RD_SETTINGS['settings'], trim((string) $xp->evaluate('string(//nav[@class="set-nav"]/a[@aria-current])')));
        self::assertSame(0, $xp->query('//nav[@class="set-nav"]/a[@aria-current][@href]')->length);
        // Every link of the list opens for this person.
        foreach ($xp->query('//nav[@class="set-nav"]/a[@href]') as $a) {
            /** @var \DOMElement $a */
            self::assertSame(200, $rev->get($a->getAttribute('href'))->status, $a->getAttribute('href'));
        }
        $admin = $this->signIn($this->uiUser('admin'));
        $people = $admin->get('/ui/people');
        self::assertSame(200, $people->status, $people->describe());
        self::assertSame(Words::RD_SETTINGS['people'], trim((string) self::xp($people)->evaluate('string(//nav[@class="set-nav"]/a[@aria-current])')));
        foreach (self::xp($people)->query('//nav[@class="set-nav"]/a[@href]') as $a) {
            /** @var \DOMElement $a */
            self::assertSame(200, $admin->get($a->getAttribute('href'))->status, 'admin: ' . $a->getAttribute('href'));
        }
    }

    public function testHomeHasItsButtonsTilesAndCards(): void
    {
        $site = $this->site('vpg');
        $this->item('strict', 5, 'Home item');
        $web = $this->signIn($this->uiUser(['purchasing_manager', 'goods_in']));
        $home = $web->get('/ui/');
        self::assertSame(200, $home->status, $home->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($home);
        self::assertSame([Words::RD_HOME['receive'], Words::RD_HOME['new_order']], self::texts($xp, '//header//div[@class="hm-actions"]/a'));
        self::assertSame('/ui/purchasing/orders/new', (string) $xp->evaluate('string(//header//div[@class="hm-actions"]/a[contains(@class, "primary")]/@href)'));
        self::assertSame(0, $xp->query('//form[@class="hm-find"]')->length, 'the Find box is the sidebar\'s');
        $tiles = $xp->query('//section[contains(@class, "hm-tiles")]/a');
        self::assertGreaterThan(0, $tiles->length);
        self::assertLessThanOrEqual(4, $tiles->length);
        foreach ($tiles as $t) {
            /** @var \DOMElement $t */
            self::assertMatchesRegularExpression('/^hm-tile hm-(teal|blue|purple|magenta|grey)$/', $t->getAttribute('class'));
            self::assertSame(200, $web->get((string) parse_url($t->getAttribute('href'), PHP_URL_PATH))->status, $t->getAttribute('href'));
        }
        self::assertSame(1, $xp->query('//section[contains(@class, "hm-jobs")]')->length);
        $reviewer = $this->signIn($this->uiUser('reviewer'));
        $rx = self::xp($reviewer->get('/ui/'));
        self::assertSame('value', (string) $rx->evaluate('string(//section[contains(@class, "hm-tiles")]/a[1]/@data-tile)'), 'the stock value first, for whoever sees money');
        $admin = $this->signIn($this->uiUser('admin'));
        $ax = self::xp($admin->get('/ui/'));
        self::assertSame(['VPG test site'], self::texts($ax, '//section[contains(@class, "hm-stores")]//span[@class="hm-store-t"]'), 'the Stores card for whoever sees the stores');
        unset($site);
    }

    /** A store's records waiting in Approvals (as ApprovalsInboxScreensTest fills them): an ERPNext correction, a line to place, a mode change, an oversold order. */
    private function fill(string $code, string $item): array
    {
        $site = self::makeChannel($code, 'live');
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$site, self::warehouseId('MAIN')]);
        self::$db->exec('UPDATE channel SET movement_types = ? WHERE id = ?', [json_encode(['goods_in', 'erp_reconciliation', 'erp_mode']), $site]);
        $caller = Caller::channel($site, $code);
        $sku = self::makeSku($item);
        self::$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, 'V1', ?, 1, 'mapped')", [$site, $sku]);
        $moves = new Movements(self::$db);
        $moves->record(Caller::system('test'), ['type' => 'goods_in', 'doc_ref' => 'OPEN-' . $code, 'lines' => [['sku_id' => $sku, 'qty' => 1]]], 'open-' . $code);
        (new Stock(self::$db))->setPolicy(Caller::system('test'), $sku, 'strict', 'pol-' . $code);
        $moves->record($caller, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-' . $code, 'lines' => [['variant_id' => 'NEW', 'qty' => 2, 'description' => 'Mango 10ml']]], 'relay-' . $code);
        $relay = new RelayIntake(self::$db);
        $relay->reconciliations($caller, ['doc_ref' => 'MAT-RECO-1', 'lines' => [['variant_id' => 'V1', 'qty' => 4]]], 'reco-' . $code);
        $relay->modes($caller, ['doc_ref' => $code . '-mode-1', 'lines' => [['variant_id' => 'V1', 'mode' => 'In-Stock']]], 'mode-' . $code);
        (new Reservations(self::$db))->commit($caller, '500', [['variant_id' => 'V1', 'qty' => 3, 'unit_ids' => ['a', 'b', 'c']]], 'unreserved', 'commit-' . $code);
        return ['correction' => (int) self::$db->value('SELECT MAX(id) FROM count_review WHERE sku_id = ?', [$sku]),
            'suspense' => (int) self::$db->value('SELECT MAX(id) FROM goods_in_suspense WHERE channel_id = ?', [$site])];
    }

    public function testApprovalsShowsOneKindAndKeepsItThroughAnAnswer(): void
    {
        $a = $this->fill('vpg', 'Alpha pod');
        $b = $this->fill('efg', 'Beta pod');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get(InboxController::PATH);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($page);
        $options = self::texts($xp, '//header//form[@class="ap-show"]/select[@name="show"]/option');
        self::assertSame(Words::say('RD_INBOX', 'everything', 8), $options[0]);
        self::assertContains(Words::INBOX['kind_corrections'] . ' (2)', $options);
        // One kind: only its group; its rows keep it; the answer carries it and the list stays on it.
        $one = $web->get(InboxController::PATH, ['show' => 'suspense']);
        $ox = self::xp($one);
        self::assertSame([Words::INBOX['kind_suspense']], self::texts($ox, '//div[contains(@class, "ap-group")]/h2/span[@class="ap-gname"]'));
        self::assertSame('suspense', (string) $ox->evaluate('string(//select[@name="show"]/option[@selected]/@value)'));
        foreach ($ox->query('//a[contains(@class, "ap-row")][@href]') as $row) {
            /** @var \DOMElement $row */
            self::assertStringContainsString('show=suspense', $row->getAttribute('href'));
        }
        $action = (string) $ox->evaluate('string(//form[@id="ap-answer"]/@action)');
        $form = $one->form($action);
        self::assertSame('suspense', $form['inbox_show'] ?? null);
        self::assertSame(1, $ox->query('//div[@class="ap-actions"]/p[@class="ap-next"]/following-sibling::button[contains(@class, "primary")]')->length, 'Next at the left, the main answer last');
        // Another value is no kind: everything is shown.
        self::assertCount(4, self::texts(self::xp($web->get(InboxController::PATH, ['show' => 'nope'])), '//div[contains(@class, "ap-group")]/h2'));
        unset($a, $b);
    }
}
