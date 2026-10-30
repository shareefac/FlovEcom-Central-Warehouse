<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\StockOps\StockOps;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Stock moves and hand-overs on the approved patterns (wave w8, docs/decisions.md W8), as the pages are served: the lists are boards (one
 * search, one Show row, the coloured groups, the solid status cell, "+ New" at the end of the open group), a draft is the one-page form
 * (its details in two columns, the lines board, the totals, the bar at the bottom with Leave first), a saved record the record page (the
 * status block and the actions in the head, the figures strip, the paper card, the files and the history), and the overview, the movements,
 * the balances and the accounts reports (the figures strip over the board). Layout only: every form posts where and what it did.
 */
final class StockPatternsScreensTest extends KernelUiTestCase
{
    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    private static function xp(UiResponse $page): \DOMXPath
    {
        return new \DOMXPath($page->dom());
    }

    /** @return list<string> the texts of the nodes $path finds */
    private static function texts(UiResponse $page, string $path): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) preg_replace('/\s+/u', ' ', (string) $n->textContent)),
            iterator_to_array(self::xp($page)->query($path) ?: []));
    }

    private function start(KernelBrowser $web, string $kind, array $over = []): string
    {
        $r = $web->post(StockOps::PATHS[$kind], $over + $web->get(StockOps::PATHS[$kind])->form(StockOps::PATHS[$kind], true));
        self::assertSame(303, $r->status, $r->describe());
        return (string) parse_url((string) $r->location(), PHP_URL_PATH);
    }

    private function submit(KernelBrowser $web, string $page, string $action, array $over = []): UiResponse
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe() . ' ' . implode(' | ', self::$log));
        return $web->post($action, $over + $p->form($action, true));
    }

    /** The board pattern (1) on a page: one search, one Show row and no Filter pop-over; each group's card has its coloured edge. */
    private static function assertBoard(UiResponse $page, string $what): void
    {
        $xp = self::xp($page);
        self::assertSame(1, $xp->query('//main//form[contains(@class, "toolbar")]//input[@type="search"]')->length, "{$what}: one search");
        self::assertSame(1, $xp->query('//main//form[contains(@class, "toolbar")]//div[@class="tb-state"]')->length, "{$what}: one Show row");
        self::assertSame(0, $xp->query('//main//details[contains(@class, "tb-pop")]')->length, "{$what}: no Filter pop-over");
        self::assertGreaterThan(0, $xp->query('//main//div[contains(@class, "doc-group")]/div[contains(@class, "doc-board")]//table[contains(@class, "doc-list")]')->length,
            "{$what}: the groups' cards");
        self::assertSame($xp->query('//main//div[contains(@class, "doc-board")]//tbody/tr[not(contains(@class, "grp-add"))]')->length,
            $xp->query('//main//div[contains(@class, "doc-board")]//tbody/tr/td[contains(@class, "doc-st")]')->length, "{$what}: every row's status cell is filled");
    }

    /**
     * The redesign's simple list (docs/decisions.md RD-A2, RD-A5, RD-A7): one search, ONE Show choice (no Show row of selects), no Filter pop-over,
     * no figures strip, no "+ Add" row, the groups' cards.
     */
    private static function assertSimple(UiResponse $page, string $what): void
    {
        $xp = self::xp($page);
        self::assertSame(1, $xp->query('//main//form[contains(@class, "toolbar")]//input[@type="search"]')->length, "{$what}: one search");
        self::assertSame(1, $xp->query('//main//form[contains(@class, "toolbar")]/details[contains(@class, "show-pick")]')->length, "{$what}: ONE Show choice");
        self::assertSame(0, $xp->query('//main//div[@class="tb-state"] | //main//details[contains(@class, "tb-pop")] | //main//section[contains(@class, "rp-figs")] | //main//tr[contains(@class, "grp-add")]')->length,
            "{$what}: no Show row, Filter pop-over, figures strip or \"+ Add\" row");
        self::assertGreaterThan(0, $xp->query('//main//div[contains(@class, "doc-group")]/div[contains(@class, "doc-board")]//table[contains(@class, "doc-list")]')->length,
            "{$what}: the groups' cards");
    }

    public function testAStockRecordIsABoardThenTheOnePageFormThenTheRecordPage(): void
    {
        $sku = $this->item('strict', 4, 'Omega pod 2ml');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $list = $web->get('/ui/stock/in');
        self::assertSame(200, $list->status, $list->describe());
        // The new form (the "+ New" of the head and of the open group): its fields in two columns.
        self::assertSame(1, self::xp($list)->query('//details[@id="new"]//form[@action="/ui/stock/in"]/fieldset[contains(@class, "doc-grid")]//select[@name="warehouse"]')->length);
        // The head's "+ New" (the page draws its own toolbar, so the layout's create button comes in through the head's side).
        self::assertSame(['/ui/stock/in#new'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array(self::xp($list)->query('//header[contains(@class, "page-head")]/div[contains(@class, "ph-actions")]/a[contains(@class, "btn") and contains(@class, "primary")]'))), 'the head: + New');
        $page = $this->start($web, 'in', ['reason_code' => 'found', 'external_ref' => 'BOX 7']);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $sku, 'qty' => '5', 'cost' => '1.50'])->status);

        // The simple list (RD-A5): the draft in its group; the state of before under the head's More.
        $list = $web->get('/ui/stock/in');
        self::assertSimple($list, 'stock in');
        self::assertSame([Words::STOCK_OPS['no_number']], self::texts($list, '//div[contains(@class, "doc-group") and contains(@class, "waiting")]//tbody/tr/th/a[contains(@class, "row-link")]'));
        self::assertSame(['draft', 'awaiting_approval', 'posted', 'reversed', 'cancelled'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'),
            array_slice(iterator_to_array(self::xp($list)->query('//header//details[contains(@class, "page-more")]//select[@name="state"]/option')), 1)), 'More\'s state: every state');

        // The draft: the one-page form. Its details in two columns (their own form, as before), the lines board, the totals, the bar.
        $draft = $web->get($page);
        $xp = self::xp($draft);
        self::assertSame(1, $xp->query('//div[@class="doc-head"]//h1')->length, 'the head');
        self::assertSame(1, $xp->query('//form[@action="' . $page . '/details" and @data-unsaved="1"]/fieldset[contains(@class, "doc-grid")]//input[@name="external_ref" and @value="BOX 7"]')->length);
        self::assertSame(1, $xp->query('//form[@action="' . $page . '/lines"]//table[contains(@class, "doc-lines")]//input[@name="u_1" and @value="5"]')->length);
        self::assertSame(['5', '£7.50'], self::texts($draft, '//form[@action="' . $page . '/lines"]//section[contains(@class, "doc-totals")]//dd'));
        $bar = '//form[@action="' . $page . '/lines"]//div[contains(@class, "doc-bar")]';
        self::assertSame('/ui/stock/in', (string) $xp->evaluate('string(' . $bar . '/a[@data-leave]/@href)'), 'Leave without saving: back to the list');
        self::assertSame(['save', 'post'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'), iterator_to_array($xp->query($bar . '/button'))));
        self::assertSame(1, $xp->query('//div[@class="doc-head"]//details[contains(@class, "more")]//form[@action="' . $page . '/cancel"]')->length, 'Stop this draft is under More');
        self::assertSame(1, $xp->query('//section[@id="files"]//form[@action="' . $page . '/files"]')->length, 'its files');

        // Final: the record page. Status block and More in the head, the figures, the paper card, the files, the totals.
        self::assertSame(303, $web->post($page . '/lines', ['action' => 'post'] + $draft->form($page . '/lines', true))->status);
        $rec = $web->get($page);
        $xp = self::xp($rec);
        self::assertSame(1, $xp->query('//div[@class="doc-head"]//h1/span[contains(@class, "chip")]')->length, 'the status block');
        self::assertSame(1, $xp->query('//div[@class="doc-head"]//div[contains(@class, "doc-actions")]//details[contains(@class, "more")]//form[@action="' . $page . '/reverse"]')->length,
            'Cancel this record is under More');
        self::assertSame([Words::STOCK_OPS['products'], Words::STOCK_OPS['units'], Words::STOCK_OPS['value'], Words::STOCK_OPS['date']],
            self::texts($rec, '//section[contains(@class, "rp-figs")]/div/p[@class="doc-step-label"]'));
        self::assertSame(['1', '5', '£7.50'], array_slice(self::texts($rec, '//section[contains(@class, "rp-figs")]/div/p[@class="doc-step-big"]'), 0, 3));
        self::assertSame(1, $xp->query('//article[contains(@class, "doc-view")]//table[contains(@class, "doc-lines")]//th/a[. = "Omega pod 2ml"]')->length);
        self::assertSame(1, $xp->query('//article[contains(@class, "doc-view")]//div[@class="doc-foot"]//div[@id="files"]//form[@action="' . $page . '/files"]')->length);
        self::assertStringContainsString('BOX 7', implode(' ', self::texts($rec, '//article[contains(@class, "doc-view")]//dl[contains(@class, "doc-facts")]/dd')));
        self::assertSame(0, $xp->query('//main//dl[contains(@class, "wide")]')->length, 'the old facts list is gone');
    }

    public function testATransferRecordsMainActionIsItsNote(): void
    {
        $sku = $this->item('strict', 10, 'Tau coil');
        $shop = (int) self::$db->insert("INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, sort_order) VALUES ('W8SHOP', 'W8 shop room', 0, 1, 'own', 45)");
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $this->start($web, 'transfer', ['to_warehouse' => (string) $shop]);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $sku, 'qty' => '3'])->status);
        self::assertSame(303, $this->submit($web, $page, $page . '/lines', ['action' => 'post'])->status);
        $rec = $web->get($page);
        self::assertSame([$page . '/pdf'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array(self::xp($rec)->query('//div[@class="doc-head"]//div[contains(@class, "doc-actions")]/a[contains(@class, "primary")]'))), 'the one main action');
        self::assertSame([Words::STOCK_OPS['from'], 'W8 shop room'], [self::texts($rec, '//section[contains(@class, "doc-party")]/p[@class="doc-label"]')[0] ?? null,
            self::texts($rec, '//dl[contains(@class, "doc-facts")]/dt[. = "' . Words::STOCK_OPS['warehouse_to'] . '"]/following-sibling::dd[1]')[0] ?? null]);
    }

    public function testAHandOverIsABoardThenTheOnePageFormThenTheRecordPage(): void
    {
        $sku = $this->item('legacy', 10, 'Kappa liquid 10ml');
        $cp = (int) self::$db->insert("INSERT INTO counterparty (kind, name, vat_code, created_actor) VALUES ('trade', 'W8 Trade Ltd', 'S', 'test')");
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $list = $web->get('/ui/stock/handovers');
        self::assertSame(1, self::xp($list)->query('//details[@id="new"]//form[@action="/ui/stock/handovers"]/fieldset[contains(@class, "doc-grid")]//select[@name="counterparty"]')->length);
        $r = $web->post('/ui/stock/handovers', ['counterparty' => (string) $cp] + $list->form('/ui/stock/handovers', true));
        self::assertSame(303, $r->status, $r->describe());
        $page = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $sku, 'qty' => '5', 'price' => '2.40'])->status);
        $list = $web->get('/ui/stock/handovers');
        self::assertBoard($list, 'hand-overs');
        self::assertSame(['#new'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array(self::xp($list)->query('//div[@id="g-draft"]//tr[contains(@class, "grp-add")]//a[contains(@class, "add-item")]'))));

        $draft = $web->get($page);
        $xp = self::xp($draft);
        self::assertSame(1, $xp->query('//form[@action="' . $page . '/details"]/fieldset[contains(@class, "doc-grid")]//select[@name="counterparty"]')->length);
        self::assertSame(['5', '£12.00'], self::texts($draft, '//form[@action="' . $page . '/lines"]//section[contains(@class, "doc-totals")]//dd'));
        self::assertSame('/ui/stock/handovers', (string) $xp->evaluate('string(//form[@action="' . $page . '/lines"]//div[contains(@class, "doc-bar")]/a[@data-leave]/@href)'));

        self::assertSame(303, $web->post($page . '/lines', ['action' => 'post'] + $draft->form($page . '/lines', true))->status);
        $rec = $web->get($page);
        $xp = self::xp($rec);
        self::assertSame(['W8 Trade Ltd'], self::texts($rec, '//article[contains(@class, "doc-view")]//p[@class="doc-party-name"]/a'));
        self::assertSame(1, $xp->query('//div[@class="doc-head"]//details[contains(@class, "more")]//form[@action="' . $page . '/copy"]')->length, 'Copy is under More');
        self::assertSame(['£12.00', '£2.40', '£14.40'], self::texts($rec, '//article[contains(@class, "doc-view")]//section[contains(@class, "doc-totals")]//dd'),
            'net, VAT at 20%, total');
        // The customer's board and its record page, and the balances report.
        $customers = $web->get('/ui/stock/handovers/customers');
        self::assertBoard($customers, 'customers');
        $cpage = $web->get('/ui/stock/handovers/customers/' . $cp);
        $cx = self::xp($cpage);
        self::assertSame(1, $cx->query('//div[@class="doc-head"]//h1')->length);
        self::assertSame(1, $cx->query('//section[contains(@class, "rp-figs")]')->length, 'the figures strip');
        self::assertSame(1, $cx->query('//article[contains(@class, "doc-view")]//dl[contains(@class, "doc-facts")]')->length, 'the paper card');
        $bal = $web->get('/ui/stock/handovers/balances');
        self::assertSame(1, self::xp($bal)->query('//section[contains(@class, "rp-figs")]')->length, 'the balances: a report');
        self::assertSame(['W8 Trade Ltd'], self::texts($bal, '//div[contains(@class, "doc-board")]//table[contains(@class, "balances")]/tbody/tr/th/a'));
        self::assertSame(1, self::xp($bal)->query('//section[@id="customer-' . $cp . '"]//form[@action="/ui/stock/handovers/balances/' . $cp . '/payments"]')->length,
            'its payment form, at its anchor');
    }

    public function testTheOverviewMovementsReservationsAndAccountsAreReportsAndBoards(): void
    {
        $this->item('legacy', 12, 'Alpha liquid 10ml');
        self::$db->insert("INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, owner_entity, sort_order) VALUES ('W8TWO', 'W8 room', 0, 1, 'other', 'W8 Two Ltd', 40)");
        $web = $this->signIn($this->uiUser(['stock_controller', 'purchasing_desk']));
        // Stock on hand and Stock history are simple lists since the redesign (no figures strip on a list, RD-A7); the balance owed is a report.
        foreach (['/ui/stock' => 0, '/ui/stock/movements' => 0, '/ui/stock/accounts' => 3] as $path => $figures) {
            $page = $web->get($path);
            self::assertSame(200, $page->status, $path . ' ' . $page->describe());
            self::assertCount($figures, self::texts($page, '//main//section[contains(@class, "rp-figs")]/div[contains(@class, "doc-step")]'), "{$path}: the figures strip");
            self::assertSame(0, self::xp($page)->query('//main//ul[contains(@class, "kpis")]')->length, "{$path}: the old tiles are gone");
        }
        foreach (['/ui/stock', '/ui/stock/movements'] as $path) {
            $page = $web->get($path);
            self::assertSame(0, self::xp($page)->query('//main//details[contains(@class, "tb-pop")]')->length, "{$path}: no Filter pop-over");
            self::assertGreaterThan(0, self::xp($page)->query('//main//div[contains(@class, "doc-board")]//table')->length, "{$path}: the board");
        }
        $resv = $web->get('/ui/stock/reservations');
        self::assertSame(1, self::xp($resv)->query('//main//form[contains(@class, "toolbar")]/details[contains(@class, "show-pick")]')->length, 'reservations: the Show choice');
        self::assertSame(0, self::xp($resv)->query('//main//details[contains(@class, "tb-pop")]')->length);
        $repack = $web->get('/ui/stock/repack');
        self::assertSame(1, self::xp($repack)->query('//main//form[contains(@class, "toolbar")]/details[contains(@class, "show-pick")]')->length, 'repack: the Show choice');
    }
}
