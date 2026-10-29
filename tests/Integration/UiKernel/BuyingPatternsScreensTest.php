<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Suppliers and buying on the approved patterns (wave w9, docs/decisions.md W9), as the pages are served: the lists are boards (one Show
 * row, the coloured groups over cards with the 6 px edge, the solid status cell, "+ New" at the end of the open group), a supplier and a
 * return are record pages (the status block and the actions in the head, the figures strip, the paper card; the supplier's four tabs), a new
 * supplier and a return's draft are one-page forms (two columns, the bar at the bottom), and What to buy, its settings, the sales history and
 * the duty register are reports (the figures strip over the boards). Layout only: every form posts where and what it did.
 */
final class BuyingPatternsScreensTest extends KernelUiTestCase
{
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

    /** No old toolbar pieces: the Filter pop-over, the "How it works" fold, the KPI tiles. */
    private static function assertNoOldLayout(UiResponse $page, string $what): void
    {
        $xp = self::xp($page);
        self::assertSame(0, $xp->query('//main//details[contains(@class, "tb-pop")] | //main//details[contains(@class, "how")] | //main//*[contains(@class, "kpis")]')->length,
            "{$what}: the old layout");
    }

    /** The board pattern (1) as served: one Show row; each group's card has its coloured edge and every row's status cell is filled. */
    private static function assertBoard(UiResponse $page, string $what, bool $search = true): void
    {
        $xp = self::xp($page);
        self::assertSame($search ? 1 : 0, $xp->query('//main//form[contains(@class, "toolbar")]//input[@type="search"]')->length, "{$what}: the search");
        self::assertSame(1, $xp->query('//main//form[contains(@class, "toolbar")]//div[@class="tb-state"]')->length, "{$what}: one Show row");
        self::assertNoOldLayout($page, $what);
        self::assertGreaterThan(0, $xp->query('//main//div[contains(@class, "doc-group")]/div[contains(@class, "doc-board")]//table[contains(@class, "doc-list")]')->length,
            "{$what}: the groups' cards");
        self::assertSame($xp->query('//main//div[contains(@class, "doc-board")]//tbody/tr[not(contains(@class, "grp-add"))]')->length,
            $xp->query('//main//div[contains(@class, "doc-board")]//tbody/tr/td[contains(@class, "doc-st")]')->length, "{$what}: every row's status cell is filled");
    }

    /** The report pattern (5) as served: the figures strip, and no old layout. */
    private static function assertReport(UiResponse $page, string $what): void
    {
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(1, self::xp($page)->query('//main//section[contains(@class, "doc-progress")]')->length, "{$what}: the figures strip");
        self::assertNoOldLayout($page, $what);
    }

    /** @return array<string, mixed> an active supplier (the services: a buyer makes it, a reviewer approves it) */
    private function activeSupplier(string $name): array
    {
        $buyer = $this->uiUser('buyer')['id'];
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => $name, 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    public function testANewSupplierIsOnePageThenARecordWithFourTabsOnTheBoard(): void
    {
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $new = $web->get('/ui/purchasing/suppliers/new');
        self::assertSame(200, $new->status, $new->describe());
        $xp = self::xp($new);
        // The 27 fields in three sections of two columns, the country a picker, the bar at the bottom with ONE Save.
        self::assertSame([Words::SUPPLIER_FORM['sec_details'], Words::SUPPLIER_FORM['sec_terms'], Words::SUPPLIER_FORM['sec_dd']],
            self::texts($new, '//form[@action="/ui/purchasing/suppliers"]/fieldset[contains(@class, "doc-grid")]/legend'));
        self::assertSame(27, $xp->query('//form[@action="/ui/purchasing/suppliers"]//*[@name and (self::input or self::select or self::textarea) and not(@type="hidden")]')->length);
        self::assertSame(count(Words::COUNTRY), $xp->query('//select[@name="country"]/option')->length, 'every country once');
        self::assertSame(['GB'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'), iterator_to_array($xp->query('//select[@name="country"]/option[@selected]'))));
        self::assertSame(Words::COUNTRY['GB'], trim((string) $xp->evaluate('string(//select[@name="country"]/option[1])')), 'the United Kingdom first');
        self::assertSame([Words::DOC['leave'], Words::SUPPLIER_FORM['save_new']], self::texts($new, '//form[@action="/ui/purchasing/suppliers"]/div[@class="doc-bar"]/*'));
        self::assertSame(1, $xp->query('//form[@action="/ui/purchasing/suppliers"][@data-unsaved="1"]/div[@class="doc-bar"]/button[contains(@class, "primary")]')->length);

        // Saved as it was: a draft, its record page.
        $form = ['name' => 'Pattern Vapes Wholesale', 'email' => 'orders@pattern.example', 'country' => 'IE', 'is_overseas' => '1'] + $new->form('/ui/purchasing/suppliers');
        $r = $web->post('/ui/purchasing/suppliers', $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(1, preg_match('#^/ui/purchasing/suppliers/(\d+)#', (string) $r->location(), $m));
        $id = (int) $m[1];
        self::assertSame('IE', self::$db->value('SELECT country FROM supplier WHERE id = ?', [$id]), 'the picker sends the two-letter code');
        $page = $web->follow($r);
        $xp = self::xp($page);
        self::assertSame(1, $xp->query('//div[@class="doc-head"]//div[contains(@class, "doc-actions")]//details[@class="more"]//a[@href="/ui/purchasing/suppliers/' . $id . '/edit"]')->length,
            'Change the details under More, in the head');
        self::assertSame(4, $xp->query('//section[contains(@class, "doc-progress")]/div[@class="doc-step"]')->length, 'the figures strip');
        self::assertSame([Words::SUPPLIER['tab_details'], Words::SUPPLIER['tab_items'], Words::SUPPLIER['tab_orders'], Words::SUPPLIER['tab_invoices']],
            self::texts($page, '//nav[contains(@class, "record-tabs")]/a[@class="tab"]'));
        self::assertSame(Words::SUPPLIER['tab_details'], trim((string) $xp->evaluate('string(//nav[contains(@class, "record-tabs")]/a[@aria-current="page" and not(@href)])')));
        self::assertSame(1, $xp->query('//article[contains(@class, "doc-view")]//div[contains(@class, "doc-meta")]//section[contains(@class, "doc-party")]')->length, 'the paper card');
        self::assertSame(1, $xp->query('//section[@id="arrivals"]')->length, 'its arrivals settings on Details (#arrivals)');
        // Each tab its own address; the open one no link.
        foreach (['items' => 'tab_items', 'orders' => 'tab_orders', 'invoices' => 'tab_invoices'] as $tab => $word) {
            $t = $web->get('/ui/purchasing/suppliers/' . $id, ['tab' => $tab]);
            self::assertSame(200, $t->status, $t->describe());
            self::assertSame(Words::SUPPLIER[$word], trim((string) self::xp($t)->evaluate('string(//nav[contains(@class, "record-tabs")]/a[@aria-current="page" and not(@href)])')), $tab);
            self::assertSame(0, self::xp($t)->query('//article[contains(@class, "doc-view")]')->length, "{$tab}: Details is another tab");
        }
        self::assertContains('/ui/purchasing/suppliers/' . $id . '/items/new', $web->get('/ui/purchasing/suppliers/' . $id, ['tab' => 'items'])->hrefs(), 'Add a product');

        // The board: the draft in the Draft group, "+ New supplier" at its end.
        $this->activeSupplier('Pattern Approved Ltd');
        $list = $web->get('/ui/purchasing/suppliers');
        self::assertBoard($list, 'suppliers');
        $groups = self::texts($list, '//main//div[contains(@class, "doc-group")]/h3/button');
        self::assertContains(Words::SUPPLIER_STATUS['draft'], $groups);
        self::assertContains(Words::SUPPLIER_STATUS['active'], $groups);
        self::assertSame(array_values(array_intersect(array_map(static fn (array $g): string => $g[1], \CW\Ui\Controller\SuppliersController::board()), $groups)), $groups,
            'the groups in the board\'s order');
        self::assertSame(['/ui/purchasing/suppliers/new'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array(self::xp($list)->query('//div[contains(@class, "doc-group") and contains(@class, "waiting")]//tr[@class="grp-add"]//a'))));
        self::assertSame(Words::SUPPLIER_STATUS['draft'], self::texts($list, '//tr[th/a[@href="/ui/purchasing/suppliers/' . $id . '"]]/td[contains(@class, "doc-st")]')[0] ?? null);
        // Home's links still narrow it.
        self::assertSame([Words::SUPPLIER_STATUS['draft']], self::texts($web->get('/ui/purchasing/suppliers', ['status' => 'draft']), '//main//div[contains(@class, "doc-group")]/h3/button'));
    }

    public function testAReturnIsABoardItsDraftOnePageAndFinalARecord(): void
    {
        $s = $this->activeSupplier('Pattern Returns Ltd');
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $list = $web->get('/ui/purchasing/returns');
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(1, self::xp($list)->query('//details[@id="new"]//form[@action="/ui/purchasing/returns"]/fieldset[contains(@class, "doc-grid")]//select[@name="supplier_id"]')->length,
            'the new return\'s fields in two columns, in its drawer');
        $r = $web->post('/ui/purchasing/returns', ['supplier_id' => (string) $s['id'], 'return_ref' => 'RMA-9'] + $list->form('/ui/purchasing/returns'));
        self::assertSame(303, $r->status, $r->describe());
        $page = (string) parse_url((string) $r->location(), PHP_URL_PATH);

        // The draft: its details in two columns, the add row, the bar at the bottom (Leave, the note, Make final naming its own form).
        $draft = $web->get($page);
        self::assertSame(200, $draft->status, $draft->describe());
        $xp = self::xp($draft);
        self::assertSame(1, $xp->query('//form[@action="' . $page . '/details"][@data-unsaved="1"]/fieldset[contains(@class, "doc-grid")]')->length);
        self::assertSame(1, $xp->query('//section[contains(@class, "doc-progress")]')->length);
        self::assertSame([Words::DOC['leave'], Words::RETURNS['post_button']], self::texts($draft, '//div[@class="doc-bar"]/a | //div[@class="doc-bar"]/button'));
        self::assertSame('ret-post', $xp->query('//div[@class="doc-bar"]/button[contains(@class, "primary")]')->item(0)?->getAttribute('form'));
        self::assertSame($page . '/post', $xp->query('//form[@id="ret-post"]')->item(0)?->getAttribute('action'));
        self::assertSame(303, $web->post($page . '/add-claim', ['amount' => '12.50', 'description' => 'Short delivery'] + $draft->form($page . '/add-claim'))->status);
        $draft = $web->get($page);
        self::assertSame([Words::DOC['leave'], Words::STOCK_OPS['save_lines'], Words::RETURNS['post_button']], self::texts($draft, '//div[@class="doc-bar"]/a | //div[@class="doc-bar"]/button'));
        self::assertSame(1, self::xp($draft)->query('//form[@id="ret-lines"][@data-unsaved="1"]//table[contains(@class, "doc-lines")]')->length);

        // The list: the draft in the Draft group, "+ New return" at its end.
        $list = $web->get('/ui/purchasing/returns');
        self::assertBoard($list, 'returns');
        self::assertSame(['#new'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array(self::xp($list)->query('//div[contains(@class, "doc-group")]//tr[@class="grp-add"]//a'))));

        // Final (its reason given first, a return's rule): the record page (the paper card, the files and the claim on the right).
        self::assertSame(303, $web->post($page . '/details', ['reason_code' => 'damaged'] + $draft->form($page . '/details'))->status);
        $draft = $web->get($page);
        $r = $web->post($page . '/post', $draft->form($page . '/post'));
        self::assertSame(303, $r->status, $r->describe());
        $final = $web->get($page);
        $xp = self::xp($final);
        self::assertSame(1, $xp->query('//div[@class="doc-head"]//div[contains(@class, "doc-actions")]')->length, 'the action bar in the head');
        self::assertSame(1, $xp->query('//article[contains(@class, "doc-view")]//section[contains(@class, "doc-party")]//a[@href="/ui/purchasing/suppliers/' . $s['id'] . '"]')->length);
        self::assertSame(1, $xp->query('//article[contains(@class, "doc-view")]//div[contains(@class, "doc-foot")]//section[contains(@class, "doc-totals")]')->length);
        self::assertSame(0, $xp->query('//div[@class="doc-bar"]')->length, 'no form, no bar');
        self::assertBoard($web->get('/ui/purchasing/returns'), 'returns, final');
    }

    public function testTheInvoiceListsArrivingAndIncidentsAreBoardsTheDutyRegisterAReport(): void
    {
        $s = $this->activeSupplier('Pattern Credits Ltd');
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        // A credit note's draft: the Draft group, "+ New credit note" at its end (the drawer's fields in two columns).
        $list = $web->get('/ui/purchasing/credit-notes');
        self::assertSame(1, self::xp($list)->query('//details[@id="new"]//form[@action="/ui/purchasing/credit-notes"]/fieldset[contains(@class, "doc-grid")]')->length);
        $r = $web->post('/ui/purchasing/credit-notes', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'CR-9'] + $list->form('/ui/purchasing/credit-notes'));
        self::assertSame(303, $r->status, $r->describe());
        $list = $web->get('/ui/purchasing/credit-notes');
        self::assertBoard($list, 'credit notes');
        self::assertSame([Words::INVOICE_KIND['credit_note']['add_row']], self::texts($list, '//tr[@class="grp-add"]//a[@href="#new"]'));
        // The lists without rows here still have their one Show row and no old pieces.
        foreach (['/ui/purchasing/invoices/to-match' => false, '/ui/purchasing/invoices/not-invoiced' => true, '/ui/receiving/incidents' => false] as $path => $search) {
            $p = $web->get($path);
            self::assertSame(200, $p->status, $p->describe());
            self::assertSame(1, self::xp($p)->query('//main//form[contains(@class, "toolbar")]//div[@class="tb-state"]')->length, "{$path}: one Show row");
            self::assertSame($search ? 1 : 0, self::xp($p)->query('//main//form[contains(@class, "toolbar")]//input[@type="search"]')->length, "{$path}: the search");
            self::assertNoOldLayout($p, $path);
        }
        foreach (['/ui/purchasing/invoices/timed', '/ui/receiving/arriving'] as $path) {
            $p = $web->get($path);
            self::assertSame(200, $p->status, $p->describe());
            self::assertNoOldLayout($p, $path);
        }
        $duty = $web->get('/ui/receiving/duty');
        self::assertReport($duty, 'duty');
        self::assertSame(4, self::xp($duty)->query('//section[contains(@class, "doc-progress")]/div[@class="doc-step"]')->length);
        self::assertStringContainsString(Words::DUTY['held_title'], $duty->text());
    }

    public function testWhatToBuyItsSettingsAndTheSalesHistoryAreReports(): void
    {
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $page = $web->get('/ui/purchasing/reorder');
        self::assertReport($page, 'reorder');
        $xp = self::xp($page);
        // Upkeep under More in the head: the sales data, Work out again, Work out sales again, the brands, the days left out, the sales history.
        $more = '//div[@class="doc-head"]//div[contains(@class, "doc-actions")]/details[@class="more"]/div[@class="more-menu"]';
        foreach (['/ui/purchasing/reorder/refresh', '/ui/purchasing/reorder/recalculate'] as $action) {
            self::assertSame(1, $xp->query($more . '//form[@action="' . $action . '"]')->length, $action);
        }
        self::assertSame(['/ui/purchasing/reorder/brands', '/ui/purchasing/reorder/anomalies', '/ui/purchasing/sales-history'],
            array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'), iterator_to_array($xp->query($more . '/a[@class="menu-item"]'))));
        self::assertSame(0, $xp->query('//div[@class="doc-head"]//*[contains(concat(" ", @class, " "), " primary ")]')->length, 'nothing in the head is the main action');
        foreach (['/ui/purchasing/reorder/brands', '/ui/purchasing/reorder/anomalies', '/ui/purchasing/reorder/suppliers', '/ui/purchasing/reorder/low-stock',
            '/ui/purchasing/sales-history'] as $path) {
            self::assertReport($web->get($path), $path);
        }
    }
}
