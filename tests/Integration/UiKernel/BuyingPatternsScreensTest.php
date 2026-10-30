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

    /** The head's "+ New …" button (the section's create button: the simple list's one way to start one, RD-B6). @return list<string> */
    private static function headNew(UiResponse $page): array
    {
        return array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array(self::xp($page)->query('//main//header[contains(@class, "page-head")]//div[@class="ph-actions"]/a[contains(@class, "primary")]')));
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

        // The board: the draft in the Draft group; "+ New supplier" is the head's one button, no row at a group's end (RD-B6).
        $this->activeSupplier('Pattern Approved Ltd');
        $list = $web->get('/ui/purchasing/suppliers');
        self::assertBoard($list, 'suppliers');
        $groups = self::texts($list, '//main//div[contains(@class, "doc-group")]/h3/button');
        self::assertContains(Words::SUPPLIER_STATUS['draft'], $groups);
        self::assertContains(Words::SUPPLIER_STATUS['active'], $groups);
        self::assertSame(array_values(array_intersect(array_map(static fn (array $g): string => $g[1], \CW\Ui\Controller\SuppliersController::board()), $groups)), $groups,
            'the groups in the board\'s order');
        self::assertSame(0, self::xp($list)->query('//tr[contains(@class, "grp-add")]')->length, 'no add row');
        self::assertSame(['/ui/purchasing/suppliers/new'], self::headNew($list));
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

        // The list: the draft in the Draft group; "+ New return" is the head's one button (its drawer), no add row (RD-B6).
        $list = $web->get('/ui/purchasing/returns');
        self::assertBoard($list, 'returns');
        self::assertSame(0, self::xp($list)->query('//tr[contains(@class, "grp-add")]')->length, 'no add row');
        self::assertSame(['/ui/purchasing/returns#new'], self::headNew($list));

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
        self::assertSame(0, self::xp($list)->query('//tr[contains(@class, "grp-add")]')->length, 'no add row (RD-B6)');
        self::assertSame(['/ui/purchasing/credit-notes#new'], self::headNew($list), 'the head\'s one button opens the drawer');
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

    // ------------------------------------------------------------------------------------------
    // The Codex review of a96ff5b (docs/decisions.md W9.15-W9.18)
    // ------------------------------------------------------------------------------------------

    /**
     * Main's guard on a page as served (CV14, IV13; docs/decisions.md W9.15): every form that keeps typed values (a POST form with a field to
     * type in or choose; an upload is its own send) carries data-unsaved in DOC's words; on a page with such a form and another form, every
     * form carries data-leaves (it says so first when another form holds typed values).
     *
     * @return list<string> the guarded forms' actions, in the page's order
     */
    private static function assertGuarded(UiResponse $page, string $what): array
    {
        self::assertSame(200, $page->status, $page->describe());
        $xp = self::xp($page);
        $forms = iterator_to_array($xp->query('//main//form') ?: []);
        $guarded = [];
        foreach ($forms as $form) {
            /** @var \DOMElement $form */
            $action = (string) parse_url($form->getAttribute('action'), PHP_URL_PATH);
            $typed = strtolower($form->getAttribute('method')) === 'post' && $xp->query('.//input[@type="file"]', $form)->length === 0
                && $xp->query('.//input[not(@type="hidden") and not(@type="submit") and not(@type="button")] | .//select | .//textarea', $form)->length > 0;
            if ($typed) {
                $guarded[] = $action;
                self::assertSame('1', $form->getAttribute('data-unsaved'), "{$what} {$action}: what is typed here is guarded");
                self::assertSame(Words::DOC['unsaved'], $form->getAttribute('data-unsaved-text'), "{$what} {$action}: in the page's words");
            } else {
                self::assertFalse($form->hasAttribute('data-unsaved'), "{$what} {$action}: nothing to keep");
            }
        }
        if ($guarded !== [] && count($forms) > 1) {
            foreach ($forms as $form) {
                self::assertSame('1', $form->getAttribute('data-leaves'), "{$what} " . $form->getAttribute('action') . ': it leaves the page, so it asks first when another form holds typed values');
            }
        }
        return $guarded;
    }

    /**
     * W9.15 (MEDIUM): a line's quantity changed on a return's draft, then "Save details" pressed, was lost without a word (only the details
     * were sent, and the redirect drew the line as saved). Both save forms now carry data-leaves, the add row's two forms and every other
     * form with a typed field carry data-unsaved, and every form of the page asks first; so do the other buying pages that keep typed values.
     */
    public function testEveryFormThatKeepsTypedValuesIsGuardedAndEveryFormThatLeavesAsksFirst(): void
    {
        $s = $this->activeSupplier('Pattern Guard Ltd');
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $list = $web->get('/ui/purchasing/returns');
        self::assertContains('/ui/purchasing/returns', self::assertGuarded($list, 'returns'), 'the new return\'s drawer');
        $r = $web->post('/ui/purchasing/returns', ['supplier_id' => (string) $s['id']] + $list->form('/ui/purchasing/returns'));
        self::assertSame(303, $r->status, $r->describe());
        $page = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        $draft = $web->get($page);
        self::assertSame(303, $web->post($page . '/add-claim', ['amount' => '4.00', 'description' => 'Short by one'] + $draft->form($page . '/add-claim'))->status);

        // The draft with a line: its details, its lines, the add row (an item, a claim) and the stop's reason are guarded; every form asks.
        $draft = $web->get($page);
        $guarded = array_map(static fn (string $a): string => substr($a, strlen($page)), self::assertGuarded($draft, 'the draft'));
        sort($guarded);
        self::assertSame(['/add-claim', '/add-item', '/cancel', '/details', '/lines'], $guarded);
        $xp = self::xp($draft);
        foreach (['/details', '/lines'] as $save) {
            self::assertSame('1', $xp->query('//form[@action="' . $page . $save . '"]')->item(0)?->getAttribute('data-leaves'), "{$save}: saving it leaves the other's typed values");
        }

        // Final: the record page's forms follow the same rule.
        self::assertSame(303, $web->post($page . '/details', ['reason_code' => 'damaged'] + $draft->form($page . '/details'))->status);
        self::assertSame(303, $web->post($page . '/post', $web->get($page)->form($page . '/post'))->status);
        self::assertGuarded($web->get($page), 'the final return');

        // A supplier's record (its arrivals settings, the stop's reason), the credit notes' drawer.
        $record = self::assertGuarded($web->get('/ui/purchasing/suppliers/' . $s['id']), 'the supplier');
        self::assertContains('/ui/purchasing/suppliers/' . $s['id'] . '/deactivate', $record);
        self::assertContains('/ui/purchasing/credit-notes', self::assertGuarded($web->get('/ui/purchasing/credit-notes'), 'credit notes'));
    }

    /**
     * W9.16 (MEDIUM): the Items and packs tab read and drew every one of a supplier's pack records (50,000 records, 50,000 rows). It draws the
     * first SuppliersController::ITEM_TAB_ROWS, group by group (main supplier, a backup supplier, no longer bought here), so only the last
     * group shown is cut: its count reads "N+". The whole list stays one link away (the products page, unchanged: every record).
     */
    public function testTheItemsTabDrawsTheFirstRowsAndItsCutGroupReadsWithAPlus(): void
    {
        $s = $this->activeSupplier('Pattern Many Items Ltd');
        $id = (int) $s['id'];
        $max = \CW\Ui\Controller\SuppliersController::ITEM_TAB_ROWS;
        $many = $max + 3;
        $digits = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 '
            . 'UNION ALL SELECT 8 UNION ALL SELECT 9)';
        self::$db->exec("INSERT INTO sku (name) SELECT CONCAT('Tab item ', LPAD(a.d * 1000 + b.d * 100 + c.d * 10 + e.d, 4, '0')) FROM {$digits} a, {$digits} b, {$digits} c, {$digits} e "
            . 'WHERE a.d * 1000 + b.d * 100 + c.d * 10 + e.d BETWEEN 1 AND ' . $many);
        self::$db->exec("UPDATE sku SET code = CONCAT('CW-', LPAD(id, 6, '0')) WHERE code IS NULL AND name LIKE 'Tab item %'");
        self::$db->exec("INSERT INTO supplier_item (supplier_id, sku_id, created_actor, updated_actor) SELECT ?, id, 'system:test', 'system:test' FROM sku WHERE name LIKE 'Tab item %'", [$id]);
        // The two named last are this supplier's main ones; the first is no longer bought here.
        self::$db->exec('UPDATE supplier_item i JOIN sku k ON k.id = i.sku_id SET i.is_preferred = 1 WHERE i.supplier_id = ? AND k.name IN (?, ?)',
            [$id, sprintf('Tab item %04d', $many), sprintf('Tab item %04d', $many - 1)]);
        self::$db->exec("UPDATE supplier_item i JOIN sku k ON k.id = i.sku_id SET i.is_active = 0 WHERE i.supplier_id = ? AND k.name = 'Tab item 0001'", [$id]);
        $web = $this->signIn($this->uiUser('purchasing_manager'));

        $tab = $web->get('/ui/purchasing/suppliers/' . $id, ['tab' => 'items']);
        self::assertSame(200, $tab->status, $tab->describe());
        $section = '//section[@aria-labelledby="items-h"]';
        $xp = self::xp($tab);
        self::assertSame($max, $xp->query($section . '//div[contains(@class, "doc-board")]//tbody/tr[not(contains(@class, "grp-add"))]')->length, 'the first rows only');
        self::assertSame([Words::SUPPLIER_ITEMS['group_main'], Words::SUPPLIER_ITEMS['group_other']], self::texts($tab, $section . '//div[contains(@class, "doc-group")]/h3/button'),
            'the main ones first, whole; the cut comes in the last group shown');
        self::assertSame([Words::say('SUPPLIER_ITEMS', 'group_many', 2), Words::say('SUPPLIER_ITEMS', 'group_many', number_format($max - 2) . '+')],
            self::texts($tab, $section . '//div[contains(@class, "doc-group")]/h3/span[@class="grp-count"]'));
        self::assertSame(1, $xp->query($section . '//a[@href="/ui/purchasing/suppliers/' . $id . '/items"]')->length, 'every product one link away');

        // The products page is as it was: every record, no count with a "+".
        $all = $web->get('/ui/purchasing/suppliers/' . $id . '/items');
        self::assertSame(200, $all->status, $all->describe());
        self::assertSame($many, self::xp($all)->query('//div[contains(@class, "doc-board")]//tbody/tr[not(contains(@class, "grp-add"))]')->length);
        self::assertSame([Words::say('SUPPLIER_ITEMS', 'group_many', 2), Words::say('SUPPLIER_ITEMS', 'group_many', $many - 3), Words::SUPPLIER_ITEMS['group_one']],
            self::texts($all, '//div[contains(@class, "doc-group")]/h3/span[@class="grp-count"]'));

        // A supplier with fewer records than the limit: every one, no "+".
        $few = $this->activeSupplier('Pattern Few Items Ltd');
        self::$db->exec("INSERT INTO supplier_item (supplier_id, sku_id, created_actor, updated_actor) SELECT ?, id, 'system:test', 'system:test' FROM sku "
            . "WHERE name IN ('Tab item 0002', 'Tab item 0003')", [(int) $few['id']]);
        $tab = $web->get('/ui/purchasing/suppliers/' . $few['id'], ['tab' => 'items']);
        self::assertSame(200, $tab->status, $tab->describe());
        self::assertSame([Words::say('SUPPLIER_ITEMS', 'group_many', 2)], self::texts($tab, $section . '//div[contains(@class, "doc-group")]/h3/span[@class="grp-count"]'));
    }

    /**
     * W9.17 (LOW): Sales history's "Loads" figure counted the loads it lists, and that list stops at SalesHistoryController::LOADS: with more
     * loads than that it looked like the whole total. At the limit it reads "100+" (W8.14's form); below it, the count.
     */
    public function testTheLoadsFigureReadsWithAPlusAtTheListsLimit(): void
    {
        $vpg = self::makeChannel('w9loads');
        $digits = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 '
            . 'UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $loads = static function (int $from, int $to) use ($vpg, $digits): void {
            self::$db->exec('INSERT INTO sales_import_batch (channel_id, source, date_from, date_to, sales_file, sales_sha256, manifest, exported_at, status, actor) '
                . "SELECT ?, 'cps', '2026-01-01', '2026-01-31', 'f.csv', SHA2(CONCAT('w9-load-', n), 256), JSON_OBJECT(), NOW(6), 'failed', 'system:test' "
                . "FROM (SELECT a.d * 1000 + b.d * 100 + c.d * 10 + e.d AS n FROM {$digits} a, {$digits} b, {$digits} c, {$digits} e) x WHERE n BETWEEN ? AND ?",
                [$vpg, $from, $to]);
        };
        $figure = static fn (UiResponse $p): string => trim((string) self::xp($p)->evaluate('string(//section[contains(@class, "doc-progress")]/div[@class="doc-step"]'
            . '[p[@class="doc-step-label"] = "' . Words::SALES['fig_loads'] . '"]/p[@class="doc-step-big"])'));
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $loads(1, 3);
        self::assertSame('3', $figure($web->get('/ui/purchasing/sales-history')));
        $max = \CW\Ui\Controller\SalesHistoryController::LOADS;
        $loads(4, $max + 50);
        $page = $web->get('/ui/purchasing/sales-history');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(number_format($max) . '+', $figure($page), 'the list stops there: the figure says so');
        self::assertSame($max, self::xp($page)->query('//table[contains(@class, "batches")]/tbody/tr')->length, 'the list itself is as it was');
    }

    /**
     * W9.18 (LOW): the supplier form's Save sent an extra action=save (doc_bar names its buttons `action` by default); the old Save was
     * unnamed. It is unnamed again, so pressing it sends the form's fields alone, as before; the other pages' bars still name theirs.
     */
    public function testTheSupplierFormsSaveSendsItsFieldsAloneAsBefore(): void
    {
        $s = $this->activeSupplier('Pattern Save Ltd');
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        foreach (['/ui/purchasing/suppliers/new' => '/ui/purchasing/suppliers', '/ui/purchasing/suppliers/' . $s['id'] . '/edit' => '/ui/purchasing/suppliers/' . $s['id']] as $path => $action) {
            $page = $web->get($path);
            self::assertSame(200, $page->status, $page->describe());
            $buttons = iterator_to_array(self::xp($page)->query('//form[@action="' . $action . '"]//button[@type="submit"]') ?: []);
            self::assertCount(1, $buttons, "{$path}: one Save");
            /** @var \DOMElement $save */
            $save = $buttons[0];
            self::assertFalse($save->hasAttribute('name'), "{$path}: Save sends no field of its own");
            self::assertFalse($save->hasAttribute('value'), "{$path}: Save sends no field of its own");
        }
    }
}
