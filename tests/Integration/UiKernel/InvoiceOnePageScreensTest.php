<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Invoices\SupplierInvoices;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Receiving\GoodsReceipts;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The supplier invoice on ONE page (the owner, 29 Oct 2026: "purchase order / purchase invoice / sales invoice — keep always user
 * experience much good" and "professional: refer Zoho Inventory"; docs/decisions.md PV1-PV12), as cw_app through the real kernel:
 *  - "New supplier invoice" opens the page at once; opening it makes nothing;
 *  - the draft is made by the first POST with a supplier and something to keep (a line, "From", Save), once however often it is sent; a
 *    refusal (no supplier, nothing to add, a duplicate number, a refused "make final") leaves nothing;
 *  - "From" a delivery or an order fills the lines; another supplier's document is refused;
 *  - "+ Add new supplier" and "+ Add new item" as on the purchase order's page (who may, their rules);
 *  - "make final" keeps the 3-way match, and its differences go to the people who accept them;
 *  - the CSRF token on every POST, who may, the page without the script, the product box's choices;
 *  - the lists and the saved documents read like documents (the invoices' and the orders'), and the order's page keeps its pins.
 */
final class InvoiceOnePageScreensTest extends KernelUiTestCase
{
    private string $dir;
    private string|false $prevStore = false;
    private ?string $prevCutoff = null;
    /** @var array<string, string> setting key => its value before the test */
    private array $savedSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_pione_ui_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/store', 0700, true);
        // Files go to a temporary file store, never to the server's own (docs/dev.md).
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
        $this->prevCutoff = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'receiving.unstamped_refusal_from'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'receiving.unstamped_refusal_from'",
            [json_encode(gmdate('Y-m-d', time() + 365 * 86400), JSON_THROW_ON_ERROR)]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->savedSettings = [];
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        if ($this->prevCutoff !== null) {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'receiving.unstamped_refusal_from'", [$this->prevCutoff]);
        }
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed> an active supplier (made by a buyer, approved by a reviewer), with $days of payment terms */
    private function supplier(string $name, ?int $days = null): array
    {
        $buyer = $this->uiUser('buyer')['id'];
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => $name, 'address_line1' => '2 Mill Lane', 'city' => 'Leeds', 'postcode' => 'M1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        $s = $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
        if ($days !== null) {
            self::$db->exec('UPDATE supplier SET payment_terms_days = ? WHERE id = ?', [$days, (int) $s['id']]);
        }
        return $s;
    }

    /** A dry item (no duty stamp) of $name. */
    private function dryItem(string $name): int
    {
        $sku = self::makeSku($name);
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        return $sku;
    }

    private function store(): FileStore
    {
        return new FileStore(self::$db, new LocalFileStorage($this->dir . '/store'));
    }

    private function pdf(): string
    {
        $pdf = $this->dir . '/invoice-' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($pdf, (new \CW\Output\PdfWriter('Invoice ' . bin2hex(random_bytes(4)), 'test', true))->output());
        return $pdf;
    }

    /** A posted delivery of $packs of a fresh item at $price, its invoice copy attached. @return array{grn: int, sku: int, number: string} */
    private function delivery(int $deskId, int $supplierId, int $packs, string $price, string $ref = ''): array
    {
        $sku = $this->dryItem('One page delivered ' . bin2hex(random_bytes(3)));
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => $this->store());
        $desk = Caller::staff($deskId);
        $d = $grns->createDraft($desk, $supplierId, ['invoice_number' => $ref !== '' ? $ref : 'DEL-' . bin2hex(random_bytes(3))]);
        $d = $grns->saveDraft($desk, $d->id, $d->version, [], [['sku_id' => $sku, 'packs' => $packs, 'units_per_pack' => 1, 'pack_price' => $price]]);
        $grns->attach($desk, $d->id, $this->pdf(), 'invoice.pdf', 'supplier_invoice');
        $d = $grns->bench(Caller::staff($this->uiUser('goods_in')['id']), $d->id, $docs->get($d->id)->version, ['paperwork_ok' => '1'],
            [1 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital']]);
        $p = $grns->post($desk, $d->id, $docs->get($d->id)->version);
        return ['grn' => $p->id, 'sku' => $sku, 'number' => (string) $p->number];
    }

    /** A confirmed order of $packs of a fresh item at £2.00 a pack. @return array{po: int, sku: int, number: string} */
    private function order(int $supplierId, int $packs): array
    {
        $buyer = Caller::staff($this->uiUser('buyer')['id']);
        $sku = $this->dryItem('One page ordered ' . bin2hex(random_bytes(3)));
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $pos->createDraft($buyer, $supplierId, []);
        $d = $pos->saveDraft($buyer, $d->id, $d->version, [], [['sku_id' => $sku, 'packs' => $packs, 'units_per_pack' => 1, 'pack_price' => '2.00']]);
        $d = $pos->approve($buyer, $d->id, $d->version);
        return ['po' => $d->id, 'sku' => $sku, 'number' => (string) $d->number];
    }

    private function setting(string $key, string $json): void
    {
        $this->savedSettings[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    private static function invoices(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'SINV'");
    }

    private static function invoiceId(?string $location): int
    {
        self::assertSame(1, preg_match('#^/ui/purchasing/invoices/(\d+)(?:[?\#]|$)#', (string) $location, $m), (string) $location);
        return (int) $m[1];
    }

    private static function nodes(UiResponse $r, string $xpath): int
    {
        return (new \DOMXPath($r->dom()))->query($xpath)->length;
    }

    private static function texts(UiResponse $r, string $xpath): array
    {
        return array_map(static fn (\DOMNode $n): string => trim(preg_replace('/\s+/u', ' ', (string) $n->textContent) ?? ''), iterator_to_array((new \DOMXPath($r->dom()))->query($xpath)));
    }

    /** The new page's one form (its action is the list's address). @return array<string, string> */
    private static function newForm(UiResponse $r): array
    {
        $f = $r->form('/ui/purchasing/invoices', true);
        self::assertArrayHasKey('form_key', $f, $r->describe());
        return $f;
    }

    /** A draft's one form. @return array<string, string> */
    private static function draftForm(UiResponse $r, int $id): array
    {
        $f = $r->form('/ui/purchasing/invoices/' . $id . '/lines', true);
        self::assertArrayHasKey('version', $f, $r->describe());
        return $f;
    }

    // ------------------------------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------------------------------

    public function testNewSupplierInvoiceOpensOnePageAtOnceAndMakesNothing(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Open Page Invoices Ltd');
        $web = $this->signIn($deskUser);

        // The list's "New supplier invoice" leads to the page itself: no side panel, no "start an invoice" step, no form on the list.
        $list = $web->get('/ui/purchasing/invoices');
        self::assertSame(200, $list->status, $list->describe());
        self::assertContains('/ui/purchasing/invoices/new', $list->hrefs());
        self::assertSame(0, self::nodes($list, '//*[@id="new"]'), 'no side panel');
        self::assertSame(0, self::nodes($list, '//main//form[@method="post"]'), 'no form on the list');

        $page = $web->get('/ui/purchasing/invoices/new');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(Words::PI_ONE['title'], trim((string) (new \DOMXPath($page->dom()))->evaluate('string(//main//h1)')));
        self::assertSame(['Purchasing', 'Invoices'], [self::currentSection($page), self::currentTab($page)]);
        $form = self::newForm($page);
        self::assertSame(['csrf', 'form_key'], array_slice(array_keys($form), 0, 2));
        foreach (['supplier_id', 'invoice_number', 'invoice_date', 'due_date', 'from', 'q', 'pick_si', 'pick_sku', 'packs', 'price', 'charge_kind', 'charge_amount',
            'charge_description', 'net_total', 'vat_total', 'note', 'file_role'] as $field) {
            self::assertArrayHasKey($field, $form, $field);
        }
        self::assertSame('', $form['supplier_id'], 'no supplier chosen for the person');
        self::assertSame(1, self::nodes($page, '//form[@action="/ui/purchasing/invoices"][@enctype="multipart/form-data"]//input[@type="file"][@name="file"]'));
        // Enter anywhere is "Add" (the form's first button); the rest are buttons of the same form.
        $buttons = array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'),
            iterator_to_array((new \DOMXPath($page->dom()))->query('//form[@action="/ui/purchasing/invoices"]//button[@type="submit"]')));
        self::assertSame('add', $buttons[0] ?? null);
        foreach (['from', 'add', 'add_charge', 'save', 'post'] as $b) {
            self::assertContains($b, $buttons, $b);
        }
        // Zoho's "New Bill" as the layout: the header in a grid, the lines table with its headings, the totals, the bar at the bottom.
        self::assertSame(1, self::nodes($page, '//form[@action="/ui/purchasing/invoices"]//fieldset[contains(@class, "doc-grid")]'));
        self::assertSame([Words::PI_ONE['item'], Words::PI_ONE['qty'], Words::PI_ONE['price'], Words::PI_ONE['amount']],
            array_slice(self::texts($page, '//table[contains(@class, "doc-lines")]/thead/tr/th'), 0, 4));
        self::assertSame(1, self::nodes($page, '//table[contains(@class, "doc-lines")]//tr[contains(@class, "grp-add")]//input[@name="q"][@placeholder="' . Words::PI_ONE['add_line'] . '"]'));
        self::assertSame(1, self::nodes($page, '//section[contains(@class, "doc-totals")]//input[@name="net_total"]'));
        self::assertSame(1, self::nodes($page, '//section[contains(@class, "doc-totals")]//input[@name="vat_total"]'));
        self::assertSame([Words::PI_ONE['draft'], Words::PI_ONE['final']], self::texts($page, '//div[contains(@class, "doc-bar")]//button'));
        self::assertSame(['post'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'),
            iterator_to_array((new \DOMXPath($page->dom()))->query('//form[@action="/ui/purchasing/invoices"]//button[contains(concat(" ", @class, " "), " primary ")]'))),
            'one main action');
        self::assertContains('/ui/purchasing/invoices', self::hrefsIn($page, '//div[contains(@class, "doc-bar")]//a'), 'leave without saving');
        self::assertSame(0, self::nodes($page, '//main//p[contains(@class, "hint") and not(contains(@class, "po-ask"))]'), 'no help under the fields');
        self::assertFalse($page->hasForm('/cancel'), 'nothing to stop on the new page');
        self::assertFalse($page->hasForm('/schedule'), 'nothing to time before it is saved');

        self::assertSame(0, self::invoices(), 'opening the page makes nothing');
        $web->get('/ui/purchasing/invoices/new');
        $web->get('/ui/purchasing/invoices');
        self::assertSame(0, self::invoices(), 'an empty page left alone leaves nothing');

        // The supplier's address chooses it; a person who only looks never opens it.
        self::assertSame((string) $s['id'], self::newForm($web->get('/ui/purchasing/invoices/new', ['supplier' => $s['id']]))['supplier_id']);
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(403, $buyer->get('/ui/purchasing/invoices/new')->status);
        self::assertSame(403, $buyer->get('/ui/purchasing/invoices/products', ['q' => 'pod'])->status);
        self::assertFalse($buyer->get('/ui/purchasing/invoices')->hasForm('/ui/purchasing/invoices'), 'a buyer looks');
        self::assertNotContains('/ui/purchasing/invoices/new', $buyer->get('/ui/purchasing/invoices')->hrefs());
    }

    /** @return list<string> */
    private static function hrefsIn(UiResponse $r, string $xpath): array
    {
        return array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'), iterator_to_array((new \DOMXPath($r->dom()))->query($xpath)));
    }

    public function testASupplierAndALineMakeOneDraftOnceAndARefusalMakesNone(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('One Draft Invoices Ltd');
        $sid = (string) $s['id'];
        $pod = $this->dryItem('One page invoice pod 2ml');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES ('5012345000127', ?, 1)", [$pod]);
        $web = $this->signIn($deskUser);
        $form = self::newForm($web->get('/ui/purchasing/invoices/new'));

        // No supplier: refused in words, what was typed kept, nothing made.
        $r = $web->post('/ui/purchasing/invoices', ['invoice_number' => 'ONE-1', 'q' => '5012345000127', 'action' => 'add'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::INVOICE_ERROR['need_supplier'], $r->text());
        self::assertSame(['ONE-1', '5012345000127'], [self::newForm($r)['invoice_number'], self::newForm($r)['q']]);
        // Add with nothing to add: refused, the supplier stays chosen, nothing made.
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => $sid, 'action' => 'add'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::INVOICE_ERROR['need_product'], $r->text());
        self::assertSame($sid, self::newForm($r)['supplier_id']);
        // Not found: nothing made.
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => $sid, 'q' => 'zzzz no such', 'action' => 'add'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(0, self::invoices());

        // A supplier and a line: one draft, however often the form is sent.
        $send = ['supplier_id' => $sid, 'invoice_number' => 'ONE-1', 'q' => '5012345000127', 'packs' => '3', 'price' => '2.50', 'action' => 'add',
            'note' => 'Paper came by e-mail'] + self::newForm($web->get('/ui/purchasing/invoices/new'));
        $a = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame(303, $a->status, $a->describe());
        $b = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame($a->location(), $b->location(), 'sent twice: the same draft');
        self::assertSame(1, self::invoices());
        $id = self::invoiceId($a->location());
        self::assertSame(['ONE-1', 'Paper came by e-mail', 'draft', $deskUser['id']], [self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$id]),
            self::$db->value('SELECT note FROM document WHERE id = ?', [$id]), self::$db->value('SELECT status FROM document WHERE id = ?', [$id]),
            (int) self::$db->value('SELECT created_by FROM document WHERE id = ?', [$id])]);
        self::assertSame([[$pod, 3, '2.5000']], array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs'], (string) $l['pack_price']],
            self::$db->all('SELECT dl.sku_id, sl.packs, sl.pack_price FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no '
                . 'WHERE sl.document_id = ?', [$id])));

        // The draft is the same page, now with its line; its one form carries the version and the lines first.
        $ed = $web->follow($a);
        self::assertSame(200, $ed->status, $ed->describe());
        self::assertSame(1, self::nodes($ed, '//form[contains(@action, "/lines")]//fieldset[contains(@class, "doc-grid")]'));
        $f = self::draftForm($ed, $id);
        self::assertSame(['csrf', 'version', 'line_count', 'form_key'], array_slice(array_keys($f), 0, 4));
        self::assertSame(['1', '3', '2.50'], [$f['line_count'], $f['p_1'], $f['pp_1']]);
        self::assertArrayNotHasKey('supplier_id', $f, 'a saved invoice keeps its supplier');
        self::assertStringContainsString('One Draft Invoices Ltd', $ed->text());
        // A charge, then an edit and the row's remove, all through the one form.
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['charge_kind' => 'freight', 'charge_amount' => '7.50', 'charge_description' => 'Courier', 'action' => 'add_charge'] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([['goods', '7.50'], ['freight', '7.50']], array_map(static fn (array $l): array => [(string) $l['kind'], (string) $l['amount']],
            self::$db->all('SELECT sl.kind, CAST(dl.amount AS DECIMAL(14,2)) AS amount FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no '
                . 'WHERE sl.document_id = ? ORDER BY sl.line_no', [$id])));
        $f = self::draftForm($web->get("/ui/purchasing/invoices/{$id}"), $id);
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['remove_line' => '2', 'p_1' => '4'] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::INVOICE_NOTICE['removed'], $web->follow($r)->text());
        self::assertSame([[4, 'goods']], array_map(static fn (array $l): array => [(int) $l['packs'], (string) $l['kind']],
            self::$db->all('SELECT packs, kind FROM sinv_line WHERE document_id = ?', [$id])));
        // Save as draft with the totals typed: saved, still a draft.
        $f = self::draftForm($web->get("/ui/purchasing/invoices/{$id}"), $id);
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['net_total' => '10.00', 'vat_total' => '2.00', 'action' => 'save'] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['10.00', '2.00', 'draft'], [(string) self::$db->value('SELECT net_total FROM supplier_invoice WHERE document_id = ?', [$id]),
            (string) self::$db->value('SELECT vat_total FROM supplier_invoice WHERE document_id = ?', [$id]), self::$db->value('SELECT status FROM document WHERE id = ?', [$id])]);
        self::assertStringContainsString(Words::INVOICE_NOTICE['saved'], $web->follow($r)->text());

        // Save as draft with only a supplier: a draft (the person asked for it).
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => $sid, 'action' => 'save'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(2, self::invoices());
        // "Save and make final" with words left in the item box: refused (as the order's Confirm), nothing made.
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => $sid, 'invoice_number' => 'ONE-2', 'q' => '5012345000127', 'action' => 'post']
            + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('INVOICE_ERROR', 'scan_pending', '5012345000127'), $r->text());
        self::assertSame(2, self::invoices());
        // "Save and make final" on the new page with what stops it (a charge typed is kept with it, as the order's delivery charge; no
        // date, no totals): the Codex review of 6418b15, finding 1: the save is its own transaction and the posting the unchanged one after
        // it, so the draft is kept (never a half-final invoice) and the page says it was saved, not made final, and why.
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => $sid, 'invoice_number' => 'ONE-2', 'charge_amount' => '5.00', 'charge_description' => 'Carriage',
            'action' => 'post'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString((string) strstr(Words::PI_ONE['not_final'], '%s', true), $r->text());
        self::assertStringContainsString(Words::INVOICE_ERROR['totals_required'], $r->text());
        self::assertSame(3, self::invoices(), 'the draft is kept');
        $kept = (int) self::$db->value("SELECT id FROM document WHERE doc_type = 'SINV' AND external_ref = 'ONE-2'");
        self::assertSame(['draft', 'freight'], [self::$db->value('SELECT status FROM document WHERE id = ?', [$kept]),
            self::$db->value('SELECT kind FROM sinv_line WHERE document_id = ?', [$kept])]);
        self::assertSame('ONE-2', self::draftForm($r, $kept)['invoice_number'], 'the page is the draft\'s');
        // On the draft the edits are saved even when making it final is refused; then made final.
        $f = self::draftForm($r, $kept);
        $r = $web->post("/ui/purchasing/invoices/{$kept}/lines", ['a_1' => '6.00', 'action' => 'post'] + $f);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame('6.00', (string) self::$db->value('SELECT CAST(amount AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$kept]), 'saved');
        $f = self::draftForm($r, $kept);
        $r = $web->post("/ui/purchasing/invoices/{$kept}/lines", ['invoice_date' => gmdate('Y-m-d', time() - 86400), 'net_total' => '6.00', 'vat_total' => '1.20',
            'action' => 'post'] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('posted', self::$db->value('SELECT status FROM document WHERE id = ?', [$kept]));
    }

    /**
     * The Codex review of 6418b15, findings 1 and 2: "Save and make final" sent twice makes one invoice, made final once; a file sent with
     * a refused "make final" is kept with the draft (its rows committed with the save), never bytes without rows.
     */
    public function testMakeFinalSentTwiceIsOnceAndARefusalKeepsTheFile(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Twice Invoices Ltd');
        $web = $this->signIn($deskUser);
        $send = ['supplier_id' => (string) $s['id'], 'invoice_number' => 'TWICE-1', 'invoice_date' => gmdate('Y-m-d', time() - 86400), 'charge_amount' => '5.00',
            'charge_description' => 'Carriage', 'net_total' => '5.00', 'vat_total' => '1.00', 'action' => 'post'] + self::newForm($web->get('/ui/purchasing/invoices/new'));
        $a = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame(303, $a->status, $a->describe());
        $b = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame(303, $b->status, $b->describe());
        $id = self::invoiceId($a->location());
        self::assertSame($id, self::invoiceId($b->location()), 'sent twice: the same invoice');
        self::assertSame(1, self::invoices());
        self::assertSame('posted', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        self::assertStringContainsString(Words::say('INVOICE_NOTICE', 'posted', (string) self::$db->value('SELECT number FROM document WHERE id = ?', [$id])),
            $web->follow($b)->text());

        $before = (int) self::$db->value('SELECT COUNT(*) FROM stored_file');
        $r = $web->postMultipart('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'TWICE-2', 'charge_amount' => '5.00', 'action' => 'post']
            + self::newForm($web->get('/ui/purchasing/invoices/new')), ['file' => ['path' => $this->pdf(), 'name' => 'paper.pdf']]);
        self::assertSame(422, $r->status, $r->describe());
        $kept = (int) self::$db->value("SELECT id FROM document WHERE doc_type = 'SINV' AND external_ref = 'TWICE-2'");
        self::assertGreaterThan(0, $kept, 'the draft is kept');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document_file WHERE document_id = ? AND role = 'supplier_invoice'", [$kept]), 'and its file');
        self::assertSame($before + 1, (int) self::$db->value('SELECT COUNT(*) FROM stored_file'));
    }

    /**
     * The Codex review of 6418b15, finding 3: an invoice with more lines than the form can carry (PHP's max_input_vars, read on the server)
     * shows its lines read-only; the head, the totals and the bar still work, so it is saved and made final from the page.
     */
    public function testABigInvoiceShowsItsLinesReadOnlyAndIsStillMadeFinal(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Big Invoices Ltd');
        $sku = $this->dryItem('Big invoice pod');
        $n = intdiv(\CW\Ui\UiRequest::maxInputVars(), 2) + 10;
        $si = new SupplierInvoices(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $desk = Caller::staff($deskUser['id']);
        $d = $si->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'BIG-1', 'invoice_date' => gmdate('Y-m-d', time() - 86400)]);
        $d = $si->saveLines($desk, $d->id, $d->version, array_fill(0, $n, ['kind' => 'goods', 'sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 1, 'pack_price' => '1.00']));
        $web = $this->signIn($deskUser);
        $page = $web->get("/ui/purchasing/invoices/{$d->id}");
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(0, self::nodes($page, '//input[@name="p_1"]'), 'read-only lines');
        self::assertStringContainsString(Words::PI_ONE['too_many'], $page->text());
        $f = self::draftForm($page, $d->id);
        self::assertLessThan(\CW\Ui\UiRequest::maxInputVars(), count($f) + 5, 'the form fits');
        $r = $web->post("/ui/purchasing/invoices/{$d->id}/lines", ['net_total' => number_format($n, 2, '.', ''), 'vat_total' => '0.00', 'action' => 'post'] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['posted', $n], [self::$db->value('SELECT status FROM document WHERE id = ?', [$d->id]),
            (int) self::$db->value('SELECT COUNT(*) FROM sinv_line WHERE document_id = ?', [$d->id])]);
    }

    /**
     * The Codex review of 6418b15, finding 4: the supplier's terms fill the due date of a new invoice (and of a draft made without one); a
     * due date the person cleared on a draft stays empty when the page is drawn again and on the next save.
     */
    public function testAClearedDueDateStaysClearedAndTheTermsFillANewOne(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Due Terms Ltd', 30);
        $web = $this->signIn($deskUser);
        $date = gmdate('Y-m-d', time() - 3 * 86400);
        $due = (new \DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d');
        // Without the script the first form sends no due date (the page could not draw one): the terms fill it.
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'DUE-T1', 'invoice_date' => $date, 'action' => 'save']
            + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(303, $r->status, $r->describe());
        $id = self::invoiceId($r->location());
        self::assertSame($due, (string) self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$id]));
        // Cleared by the person: empty when drawn again, and after another save.
        $f = self::draftForm($web->get("/ui/purchasing/invoices/{$id}"), $id);
        self::assertSame($due, $f['due_date']);
        self::assertSame(303, $web->post("/ui/purchasing/invoices/{$id}/lines", ['due_date' => '', 'action' => 'save'] + $f)->status);
        self::assertNull(self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$id]));
        $f = self::draftForm($web->get("/ui/purchasing/invoices/{$id}"), $id);
        self::assertSame('', $f['due_date'], 'not filled again');
        self::assertSame(303, $web->post("/ui/purchasing/invoices/{$id}/lines", ['note' => 'Checked', 'action' => 'save'] + $f)->status);
        self::assertNull(self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$id]), 'nor saved again');
        // A due date shown on the new page and cleared there is kept empty too.
        $form = self::newForm($web->get('/ui/purchasing/invoices/new', ['supplier' => $s['id'], 'invoice_date' => $date]));
        self::assertSame($due, $form['due_date']);
        $r = $web->post('/ui/purchasing/invoices', ['invoice_number' => 'DUE-T2', 'due_date' => '', 'action' => 'save'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertNull(self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [self::invoiceId($r->location())]));
        // A draft made from a delivery ("Book its invoice") gets the terms' due date from the delivery's invoice date.
        $x = $this->delivery($deskUser['id'], (int) $s['id'], 2, '1.00');
        $r = $web->post('/ui/purchasing/invoices/from-receipt', $web->get('/ui/receiving/' . $x['grn'])->form('/ui/purchasing/invoices/from-receipt'));
        self::assertSame(303, $r->status, $r->describe());
        $fid = self::invoiceId($r->location());
        $dd = (string) self::$db->value('SELECT doc_date FROM document WHERE id = ?', [$fid]);
        self::assertSame((new \DateTimeImmutable($dd))->modify('+30 days')->format('Y-m-d'), (string) self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$fid]));
    }

    /** The Codex review of 6418b15, finding 8: a date that is not a real date is refused in words (no 500), what was typed kept. */
    public function testADateThatIsNotARealDateIsRefusedInWords(): void
    {
        $s = $this->supplier('Bad Date Ltd', 30);
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'DUE-T3', 'invoice_date' => '2026-99-99', 'action' => 'save']
            + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::INVOICE_ERROR['bad_date'], $r->text());
        self::assertSame('2026-99-99', self::newForm($r)['invoice_date']);
        self::assertSame(0, self::invoices());
    }

    /** The Codex review of 6418b15, finding 6: the item chosen goes through "+ Add new supplier" as its item (the order page's rule, PX). */
    public function testTheItemChosenGoesThroughANewSupplier(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier('Pick Invoices Ltd');
        $mint = $this->dryItem('Pick invoice mint 10ml');
        $six = (new SupplierItems(self::$db))->create(Caller::staff($pmUser['id']), (int) $s['id'], $mint, ['units_per_pack' => '6', 'supplier_code' => 'PM-6'],
            ['pack_price' => '9.00']);
        $web = $this->signIn($pmUser);
        $choice = (new \DOMXPath($web->get('/ui/purchasing/invoices/products', ['supplier' => $s['id'], 'q' => 'pick invoice mint'])->dom()))
            ->query('//ul[@data-po-choices]/li[@data-si="' . $six['id'] . '"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $choice);
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'q' => $choice->getAttribute('data-label'), 'pick_si' => (string) $six['id'],
            'action' => 'new_supplier', 'new_supplier_name' => 'Pick Fresh Ltd'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('pick_sku=' . $mint, (string) $r->location());
        $back = self::newForm($web->follow($r));
        self::assertSame([(string) $mint, ''], [$back['pick_sku'], $back['pick_si']]);
        $r = $web->post('/ui/purchasing/invoices', ['action' => 'add'] + $back);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([$mint], array_map('intval', self::$db->column('SELECT dl.sku_id FROM document_line dl WHERE dl.document_id = ?', [self::invoiceId($r->location())])));
    }

    /** The Codex review of 6418b15, finding 7: a search keeps the list's other filters (hidden fields of the toolbar). */
    public function testASearchKeepsTheOtherFilters(): void
    {
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $hidden = static function (UiResponse $r): array {
            $out = [];
            foreach ((new \DOMXPath($r->dom()))->query('//form[@class="toolbar"]//input[@type="hidden"]') ?: [] as $in) {
                $out[$in->getAttribute('name')] = $in->getAttribute('value');
            }
            return $out;
        };
        $invoices = $web->get('/ui/purchasing/invoices', ['supplier' => '7', 'match' => 'needs_review', 'cancelled' => '1', 'q' => 'x']);
        $f = $hidden($invoices);
        self::assertSame(['7', 'needs_review'], [$f['supplier'] ?? null, $f['match'] ?? null]);
        // An old link's cancelled=1 is the Show menu's "all", which the search sends (PV26: a choice in the menu wins over the old flag).
        self::assertSame(['all'], self::texts($invoices, '//form[@class="toolbar"]//select[@name="show"]/option[@selected]/@value'));
        $orders = $web->get('/ui/purchasing/orders', ['supplier' => '7', 'rejected' => '1', 'show' => 'all']);
        $f = $hidden($orders);
        self::assertSame(['7', '1'], [$f['supplier'] ?? null, $f['rejected'] ?? null]);
        // The approved design (PV18): which orders show is the toolbar's own Show menu, so it goes with the search as it is.
        self::assertSame(['all'], self::texts($orders, '//form[@class="toolbar"]//select[@name="show"]/option[@selected]/@value'));
    }

    public function testFromADeliveryOrAnOrderFillsTheLinesAndAnotherSuppliersIsRefused(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $a = $this->supplier('From Supplier A Ltd');
        $b = $this->supplier('From Supplier B Ltd');
        $x = $this->delivery($deskUser['id'], (int) $a['id'], 10, '3.00', 'DEL-A-1');
        $y = $this->delivery($deskUser['id'], (int) $b['id'], 4, '1.00');
        $o = $this->order((int) $a['id'], 6);
        $web = $this->signIn($deskUser);

        // "From": every open delivery and order, each saying whose it is (the script keeps the chosen supplier's).
        $page = $web->get('/ui/purchasing/invoices/new');
        self::assertSame(1, self::nodes($page, '//select[@name="from"]/optgroup/option[@value="grn:' . $x['grn'] . '"][@data-supplier="' . $a['id'] . '"]'));
        self::assertSame(1, self::nodes($page, '//select[@name="from"]/optgroup/option[@value="grn:' . $y['grn'] . '"][@data-supplier="' . $b['id'] . '"]'));
        self::assertSame(1, self::nodes($page, '//select[@name="from"]/optgroup/option[@value="po:' . $o['po'] . '"][@data-supplier="' . $a['id'] . '"]'));
        // With the supplier chosen on the new page every supplier's are still there (the Codex review of 6418b15, finding 5: the supplier can
        // still change; the script keeps the chosen one's), each saying whose it is.
        $withA = $web->get('/ui/purchasing/invoices/new', ['supplier' => $a['id']]);
        self::assertSame(1, self::nodes($withA, '//select[@name="from"]//option[@value="grn:' . $y['grn'] . '"][@data-supplier="' . $b['id'] . '"]'));
        self::assertSame(1, self::nodes($withA, '//select[@name="from"]//option[@value="grn:' . $x['grn'] . '"][@data-supplier="' . $a['id'] . '"]'));

        // Another supplier's delivery: refused in words, nothing made.
        $form = self::newForm($page);
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $b['id'], 'from' => 'grn:' . $x['grn'], 'action' => 'from'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::INVOICE_ERROR['delivery_other_supplier'], $r->text());
        self::assertSame(0, self::invoices());
        // "Fill the lines" with nothing chosen: said, nothing made.
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $a['id'], 'from' => '', 'action' => 'from'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::INVOICE_ERROR['need_source'], $r->text());

        // Its own delivery: one draft with the delivery's lines and copy; the number typed wins over the delivery's.
        $send = ['supplier_id' => (string) $a['id'], 'from' => 'grn:' . $x['grn'], 'invoice_number' => 'INV-A-77', 'action' => 'from'] + $form;
        $r = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($r->location(), $web->post('/ui/purchasing/invoices', $send)->location(), 'sent twice: one draft');
        self::assertSame(1, self::invoices());
        $id = self::invoiceId($r->location());
        self::assertSame('INV-A-77', self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$id]));
        self::assertSame([[$x['grn'], 10]], array_map(static fn (array $l): array => [(int) $l['grn_document_id'], (int) $l['packs'] * (int) $l['units_per_pack']],
            self::$db->all('SELECT grn_document_id, packs, units_per_pack FROM sinv_line WHERE document_id = ?', [$id])));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document_file WHERE document_id = ? AND role = 'supplier_invoice'", [$id]), 'the delivery\'s copy');

        // On the draft: its order fills more lines; another supplier's delivery is refused, nothing saved.
        $ed = $web->follow($r);
        self::assertSame(0, self::nodes($ed, '//select[@name="from"]//option[@value="grn:' . $y['grn'] . '"]'), 'only this supplier\'s');
        $f = self::draftForm($ed, $id);
        $bad = $web->post("/ui/purchasing/invoices/{$id}/lines", ['from' => 'grn:' . $y['grn'], 'action' => 'from'] + $f);
        self::assertSame(422, $bad->status, $bad->describe());
        self::assertStringContainsString(Words::INVOICE_ERROR['delivery_other_supplier'], $bad->text());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sinv_line WHERE document_id = ?', [$id]));
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['from' => 'po:' . $o['po'], 'action' => 'from'] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([[null, $x['grn']], [$o['po'], null]], array_map(static fn (array $l): array => [$l['po_document_id'] === null ? null : (int) $l['po_document_id'],
            $l['grn_document_id'] === null ? null : (int) $l['grn_document_id']], self::$db->all('SELECT po_document_id, grn_document_id FROM sinv_line WHERE document_id = ? '
            . 'ORDER BY line_no', [$id])));
        self::assertStringContainsString(Words::INVOICE_NOTICE['order_added'], $web->follow($r)->text());

        // An order on the new page: the draft and its lines in one go.
        $o2 = $this->order((int) $a['id'], 2);
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $a['id'], 'from' => 'po:' . $o2['po'], 'action' => 'from']
            + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([$o2['po']], array_map('intval', self::$db->column('SELECT po_document_id FROM sinv_line WHERE document_id = ?', [self::invoiceId($r->location())])));
        // The order's page offers to record its invoice: the new page with the supplier and the order chosen.
        $view = $web->get('/ui/purchasing/orders/' . $o2['po']);
        $link = '/ui/purchasing/invoices/new?supplier=' . $a['id'] . '&from=po%3A' . $o2['po'];
        self::assertContains($link, $view->hrefs());
        $pre = $web->get('/ui/purchasing/invoices/new', ['supplier' => $a['id'], 'from' => 'po:' . $o2['po']]);
        self::assertSame(['po:' . $o2['po'], (string) $a['id']], [self::newForm($pre)['from'], self::newForm($pre)['supplier_id']]);
    }

    public function testANewSupplierAndANewItemAsOnThePurchaseOrdersPage(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier('Known Invoices Ltd');
        $web = $this->signIn($pmUser);
        $page = $web->get('/ui/purchasing/invoices/new');
        // "+ Add new supplier" / "+ Add new item": folds of the one form, each with its own button; the dropdowns end with them.
        self::assertSame(1, self::nodes($page, '//form[@action="/ui/purchasing/invoices"]//details[@id="pi-add-supplier"][@data-po-panel][not(@open)]//button[@name="action"][@value="new_supplier"]'));
        self::assertSame(1, self::nodes($page, '//form[@action="/ui/purchasing/invoices"]//details[@id="pi-add-item"][@data-po-panel][not(@open)]//button[@name="action"][@value="new_item"]'));
        self::assertSame(Words::PO_ADD['new_supplier'], (string) (new \DOMXPath($page->dom()))->evaluate('string(//select[@name="supplier_id"]/@data-add)'));
        self::assertSame(Words::PO_ADD['new_item'], (string) (new \DOMXPath($page->dom()))->evaluate('string(//input[@name="q"]/@data-add)'));
        $form = self::newForm($page);

        // No name: refused in words, the form open again, nothing made.
        $r = $web->post('/ui/purchasing/invoices', ['action' => 'new_supplier', 'invoice_number' => 'NS-1'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(1, self::nodes($r, '//details[@id="pi-add-supplier"][@open]'));
        self::assertSame(0, self::invoices());
        // A name: a draft supplier, chosen on the page, what was typed carried; no invoice is made by it; sent twice: one supplier.
        $send = ['action' => 'new_supplier', 'new_supplier_name' => 'Fresh Invoice Supplies', 'new_supplier_email' => 'sales@fresh.example', 'invoice_number' => 'NS-1',
            'invoice_date' => gmdate('Y-m-d', time() - 86400)] + self::newForm($r);
        $r = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($r->location(), $web->post('/ui/purchasing/invoices', $send)->location());
        $new = self::$db->one("SELECT id, status FROM supplier WHERE name = 'Fresh Invoice Supplies'");
        self::assertSame('draft', $new['status'] ?? null, 'a draft supplier, as the supplier screens make one');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Fresh Invoice Supplies'"));
        self::assertSame(0, self::invoices());
        $back = $web->follow($r);
        self::assertStringContainsString(Words::INVOICE_NOTICE['supplier_added'], $back->text());
        self::assertSame([(string) $new['id'], 'NS-1'], [self::newForm($back)['supplier_id'], self::newForm($back)['invoice_number']]);

        // "+ Add new item" with the supplier: Pack B's item and its line, in the draft made by it.
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'action' => 'new_item', 'item_name' => 'Fresh invoice pod 1ml', 'item_price' => '1.25',
            'packs' => '5'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(303, $r->status, $r->describe());
        $id = self::invoiceId($r->location());
        $sku = (int) self::$db->value("SELECT id FROM sku WHERE name = 'Fresh invoice pod 1ml'");
        self::assertGreaterThan(0, $sku);
        self::assertSame([[$sku, 5]], array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs']],
            self::$db->all('SELECT dl.sku_id, sl.packs FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no WHERE sl.document_id = ?', [$id])));
        // The same name again on the draft: answered with the item that is there (a button that adds it), nothing made.
        $f = self::draftForm($web->get("/ui/purchasing/invoices/{$id}"), $id);
        $same = $web->post("/ui/purchasing/invoices/{$id}/lines", ['action' => 'new_item', 'item_name' => 'Fresh invoice pod 1ml'] + $f);
        self::assertSame(409, $same->status, $same->describe());
        self::assertSame(1, self::nodes($same, '//details[@id="pi-add-item"][@open]//button[@name="add_sku"][@value="' . $sku . '"]'));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Fresh invoice pod 1ml'"));

        // Who may not add one sees the line naming whom to ask, and the action sent anyway is refused before anything is read.
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $dp = $desk->get('/ui/purchasing/invoices/new');
        self::assertSame(0, self::nodes($dp, '//details[@id="pi-add-supplier"]') + self::nodes($dp, '//details[@id="pi-add-item"]'));
        self::assertSame(Words::askToAdd('suppliers.manage'), (string) (new \DOMXPath($dp->dom()))->evaluate('string(//select[@name="supplier_id"]/@data-ask)'));
        self::assertSame(Words::askToAdd('items.create'), (string) (new \DOMXPath($dp->dom()))->evaluate('string(//input[@name="q"]/@data-ask)'));
        $df = self::newForm($dp);
        self::assertSame(403, $desk->post('/ui/purchasing/invoices', ['action' => 'new_supplier', 'new_supplier_name' => 'Not Mine Ltd'] + $df)->status);
        self::assertSame(403, $desk->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'action' => 'new_item', 'item_name' => 'Not mine pod'] + $df)->status);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Not Mine Ltd'") + (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Not mine pod'"));
    }

    public function testADuplicateNumberIsRefusedAndMakingItFinalKeepsTheMatch(): void
    {
        $this->setting('invoices.check_price', 'true'); // PC19: the tolerances apply
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Match Invoices Ltd', 30);
        $x = $this->delivery($deskUser['id'], (int) $s['id'], 10, '3.00');
        $si = new SupplierInvoices(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)), fn (): FileStore => $this->store());
        $si->createDraft(Caller::staff($deskUser['id']), 'invoice', (int) $s['id'], ['invoice_number' => 'DUP-1']);
        $web = $this->signIn($deskUser);

        // The supplier's number twice: refused in plain words, nothing made (a refusal keeps nothing).
        $before = self::invoices();
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'dup-1', 'action' => 'save']
            + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString((string) strstr(Words::say('INVOICE_ERROR', 'duplicate_invoice', 'DUP-1', '@@'), '@@', true), $r->text());
        self::assertSame('dup-1', self::newForm($r)['invoice_number'], 'what was typed is kept');
        self::assertSame($before, self::invoices());

        // From the delivery, the paper's price typed, the totals, the due date from the supplier's terms, then made final at once.
        $date = gmdate('Y-m-d', time() - 2 * 86400);
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'from' => 'grn:' . $x['grn'], 'invoice_number' => 'MATCH-1', 'invoice_date' => $date,
            'action' => 'from'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(303, $r->status, $r->describe());
        $id = self::invoiceId($r->location());
        $ed = $web->follow($r);
        $f = self::draftForm($ed, $id);
        self::assertSame((new \DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d'), $f['due_date'], 'from the supplier\'s payment terms');
        self::assertStringContainsString(Words::INVOICE_ERROR['totals_required'], $ed->text(), 'what stops it is said before');
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['pp_1' => '4.00', 'net_total' => '40.00', 'vat_total' => '8.00', 'action' => 'post'] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['posted', (new \DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d')], [self::$db->value('SELECT status FROM document WHERE id = ?', [$id]),
            (string) self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$id])]);
        $view = $web->follow($r);
        $number = (string) self::$db->value('SELECT number FROM document WHERE id = ?', [$id]);
        self::assertStringContainsString(Words::say('INVOICE_NOTICE', 'posted', $number), $view->text());
        self::assertSame('needs_review', self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$id]), '£1 a pack dearer: outside the tolerance');
        self::assertStringContainsString(Words::MATCH_WHY['price_over'], $view->text());
        // The saved invoice reads like a document: its actions in one bar at the top, the supplier's block, the lines, the totals.
        self::assertFalse($view->hasForm("/ui/purchasing/invoices/{$id}/lines"), 'no editor once final');
        // The approved design: what differs first (the three ways, the cells that differ tinted), the next step in the accent (accept the
        // differences), the rest under More, the balance large by the supplier's block.
        self::assertSame([Words::DOC['diff_one']], self::texts($view, '//section[contains(@class, "doc-diff")]//h2'));
        self::assertSame([Words::DOC['item'], Words::DOC['ordered'], Words::DOC['received'], Words::DOC['invoiced'], Words::DOC['price_order'], Words::DOC['price_invoice'],
            Words::DOC['difference']], self::texts($view, '//section[contains(@class, "doc-diff")]//thead/tr/th'));
        self::assertGreaterThanOrEqual(2, self::nodes($view, '//section[contains(@class, "doc-diff")]//td[contains(@class, "diff")]'), 'the prices and the difference tinted');
        self::assertSame([Words::INVOICES['accept_title']], self::texts($view, '//div[contains(@class, "doc-actions")]/details[contains(@class, "doc-act")]/summary[contains(@class, "primary")]'));
        self::assertSame(1, self::nodes($view, '//div[contains(@class, "doc-actions")]/details[contains(@class, "doc-more")]'));
        self::assertStringContainsString('£48.00', implode(' ', self::texts($view, '//*[contains(@class, "doc-big")]')));
        self::assertSame(1, self::nodes($view, '//div[contains(@class, "doc-head")]//h1//span[contains(@class, "chip")]'));
        self::assertSame(1, self::nodes($view, '//main//div[contains(@class, "doc-actions")]'));
        self::assertStringContainsString('Match Invoices Ltd', implode(' ', self::texts($view, '//*[contains(@class, "doc-party")]')));
        self::assertSame(1, self::nodes($view, '//table[contains(@class, "doc-lines")]'));
        self::assertStringContainsString('£48.00', implode(' ', self::texts($view, '//section[contains(@class, "doc-totals")]')));
        // On the board it needs a look: the group, its status cell, the match as a soft tag.
        $look = $web->get('/ui/purchasing/invoices');
        self::assertSame([Words::INVOICES['group_look'] . ' ' . Words::INVOICE_KIND['invoice']['group_one'], Words::INVOICES['group_draft'] . ' '
            . Words::INVOICE_KIND['invoice']['group_one']], self::texts($look, '//div[contains(@class, "doc-group")]/h3[contains(@class, "grp-title")]'));
        self::assertSame([Words::INVOICES['needs_look']], self::texts($look, '//div[contains(@class, "doc-group")][contains(@class, "needs")]//td[contains(@class, "doc-st")]'));
        self::assertSame([Words::say('INVOICES', 'tag_diff_one', 1)], self::texts($look, '//div[contains(@class, "doc-group")][contains(@class, "needs")]//span[contains(@class, "tag")]'));
        self::assertSame([Words::INVOICES['tag_unchecked']], self::texts($look, '//div[contains(@class, "doc-group")][contains(@class, "waiting")]//span[contains(@class, "tag")]'));
        // The differences go to the people who accept them (invoices.accept), on the same page.
        $acceptPath = "/ui/purchasing/invoices/{$id}/accept";
        $a = $web->post($acceptPath, ['note' => 'Price rise agreed by phone'] + $view->form($acceptPath));
        self::assertSame(303, $a->status, $a->describe());
        self::assertSame('accepted', self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$id]));
        $accountant = $this->signIn($this->uiUser('accountant'));
        self::assertSame(403, $accountant->post($acceptPath, ['csrf' => $this->token($accountant), 'note' => 'Not mine'])->status);

        // The list: a board grouped by status (needs a look, draft, final), one search, one Show menu; the row opens the invoice; the match as
        // a soft tag, the due date, the balance.
        self::$db->exec('UPDATE supplier_invoice SET due_date = ? WHERE document_id = ?', [gmdate('Y-m-d', time() - 2 * 86400), $id]); // a due date passed
        $list = $web->get('/ui/purchasing/invoices');
        self::assertSame([Words::INVOICES['group_draft'] . ' ' . Words::INVOICE_KIND['invoice']['group_one'], Words::INVOICES['group_final'] . ' '
            . Words::INVOICE_KIND['invoice']['group_one']], self::texts($list, '//div[contains(@class, "doc-group")]/h3[contains(@class, "grp-title")]'), 'accepted: final');
        self::assertSame([Words::DOC_LIST['invoice'], Words::DOC_LIST['status'], Words::DOC_LIST['supplier'], Words::DOC_LIST['match'], Words::DOC_LIST['due'],
            Words::DOC_LIST['total'], Words::DOC_LIST['balance']], self::texts($list, '(//table[contains(@class, "doc-list")])[1]/thead/tr/th'));
        self::assertSame([Words::DOC_STATUS['posted']], self::texts($list, '//div[contains(@class, "doc-group")][contains(@class, "done")]//td[contains(@class, "doc-st")]'));
        self::assertSame([Words::INVOICES['tag_accepted']], self::texts($list, '//div[contains(@class, "doc-group")][contains(@class, "done")]//span[contains(@class, "tag")]'));
        self::assertSame(1, self::nodes($list, '//div[contains(@class, "doc-group")][contains(@class, "done")]//td[contains(@class, "late")]'), 'a due date passed, money owed: red');
        self::assertSame(1, self::nodes($list, '//form[@class="toolbar"]//input[@type="search"][@name="q"]'));
        self::assertSame(1, self::nodes($list, '//form[@class="toolbar"]//select[@name="show"]'));
        self::assertSame(0, self::nodes($list, '//form[@class="toolbar"]//select[@name="state"]'));
        self::assertContains("/ui/purchasing/invoices/{$id}", self::hrefsIn($list, '//table[contains(@class, "doc-list")]//a[contains(@class, "row-link")]'));
        self::assertStringContainsString('£48.00', $list->text());
        self::assertStringContainsString('MATCH-1', $web->get('/ui/purchasing/invoices', ['q' => 'Match Invoices'])->text(), 'one search: the supplier too');
    }

    /** Every POST of the page refuses a form without the session's CSRF token, before it does anything; who only looks is refused. */
    public function testEveryPostOfThePageChecksTheCsrfTokenAndWhoMay(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Token Invoices Ltd');
        $si = new SupplierInvoices(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $si->createDraft(Caller::staff($deskUser['id']), 'invoice', (int) $s['id'], ['invoice_number' => 'TOK-1']);
        $web = $this->signIn($deskUser);
        $before = self::invoices();
        foreach (['/ui/purchasing/invoices', "/ui/purchasing/invoices/{$d->id}/lines"] as $path) {
            foreach ([[], ['csrf' => 'not-the-token']] as $token) {
                $r = $web->post($path, $token + ['form_key' => str_repeat('d', 32), 'version' => (string) $d->version, 'line_count' => '0', 'supplier_id' => (string) $s['id'],
                    'action' => 'save', 'new_supplier_name' => 'Token Ltd']);
                self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()], $path);
            }
        }
        self::assertSame($before, self::invoices());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Token Ltd'"));
        self::assertSame($d->version, (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$d->id]), 'the draft unchanged');
        // A draft belongs to its creator: another desk person sees it, never its form, and its POST is refused.
        $other = $this->signIn($this->uiUser('purchasing_desk'));
        $look = $other->get("/ui/purchasing/invoices/{$d->id}");
        self::assertSame(200, $look->status, $look->describe());
        self::assertFalse($look->hasForm("/ui/purchasing/invoices/{$d->id}/lines"));
        $r = $other->post("/ui/purchasing/invoices/{$d->id}/lines", ['csrf' => $this->token($other), 'version' => (string) $d->version, 'line_count' => '0',
            'form_key' => str_repeat('e', 32), 'invoice_number' => 'TAKEN', 'action' => 'save']);
        self::assertSame(403, $r->status, $r->describe());
        self::assertSame('TOK-1', self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$d->id]));
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(403, $buyer->post('/ui/purchasing/invoices', ['csrf' => $this->token($buyer), 'form_key' => str_repeat('f', 32), 'supplier_id' => (string) $s['id'],
            'action' => 'save'])->status);
    }

    public function testWithoutTheScriptThePageIsPlainFormsAndTheBoxesAreReady(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier('Plain Invoices Ltd', 14);
        $web = $this->signIn($pmUser);
        $page = $web->get('/ui/purchasing/invoices/new');
        $one = '//form[@action="/ui/purchasing/invoices"][@method="post"]';
        // The supplier: the order page's plain select (the script makes it the site's one dropdown), its payment terms on each option.
        self::assertSame(1, self::nodes($page, $one . '//select[@name="supplier_id"][@id="po-supplier"][@data-po-supplier]'));
        self::assertSame(1, self::nodes($page, $one . '//select[@name="supplier_id"]/option[@value="' . $s['id'] . '"][@data-terms="14"]'));
        self::assertSame('pi-add-supplier', (string) (new \DOMXPath($page->dom()))->evaluate('string(//select[@name="supplier_id"]/@data-panel)'));
        // The item: the order page's scan box, its list (empty, hidden), the two hidden fields of a choice; the invoice's own route.
        self::assertSame(1, self::nodes($page, $one . '//input[@name="q"][@data-po-pick][starts-with(@data-options, "/ui/purchasing/invoices/products?")]'));
        self::assertSame(1, self::nodes($page, $one . '//ul[@data-po-list][@role="listbox"][@hidden][not(*)]'));
        self::assertSame(2, self::nodes($page, $one . '//input[@type="hidden"][@name="pick_si" or @name="pick_sku"][@value=""]'));
        // "From": a plain select and its own button; the due date the script can fill (data-due).
        self::assertSame(1, self::nodes($page, $one . '//select[@name="from"][@data-pi-from]'));
        self::assertSame(1, self::nodes($page, $one . '//button[@name="action"][@value="from"]'));
        self::assertSame(1, self::nodes($page, $one . '//input[@name="due_date"][@type="date"][@data-pi-due]'));
        // The "+ Add new" forms and the charge: folds of the one form, each with its own button, none open.
        foreach (['pi-add-supplier' => ['new_supplier_name', 'new_supplier_email', 'new_supplier_phone'], 'pi-add-item' => ['item_name', 'item_brand', 'item_barcode', 'item_code',
            'item_upp', 'item_price']] as $panel => $fields) {
            self::assertSame(1, self::nodes($page, $one . "//details[@id='{$panel}'][@data-po-panel][not(@open)]"), $panel);
            foreach ($fields as $field) {
                self::assertSame(1, self::nodes($page, $one . "//details[@id='{$panel}']//input[@name='{$field}'][not(@required)]"), $field);
            }
        }
        self::assertSame(1, self::nodes($page, $one . '//details[contains(@class, "pi-charge")][not(@open)]//button[@name="action"][@value="add_charge"]'));
        // The approved design: "From" on a row of its own with a soft "Fill the lines"; the file in a dashed drop area; the due date says
        // where it comes from when the supplier's terms are known.
        self::assertSame(1, self::nodes($page, $one . '//fieldset[contains(@class, "doc-grid")]/div[contains(@class, "doc-wide")]//button[@value="from"][contains(@class, "soft")]'));
        self::assertSame(1, self::nodes($page, $one . '//div[contains(@class, "doc-drop")]//input[@type="file"][@name="file"]'));
        $withTerms = $web->get('/ui/purchasing/invoices/new', ['supplier' => $s['id']]);
        self::assertSame([Words::say('PI_ONE', 'terms_hint', 14)], self::texts($withTerms, '//span[@data-pi-due-hint][not(@hidden)]'));
        self::assertStringNotContainsString('<datalist', $page->body);
        // The supplier chosen and the date known: the due date is filled by the page itself.
        $form = self::newForm($web->get('/ui/purchasing/invoices/new', ['supplier' => $s['id']]));
        $r = $web->post('/ui/purchasing/invoices', ['invoice_date' => '2026-10-01', 'action' => 'add'] + $form);
        self::assertSame('2026-10-15', self::newForm($r)['due_date'], 'a refused form comes back with the due date from the terms');
        self::assertSame('2026-10-01', self::newForm($r)['invoice_date']);
    }

    // ------------------------------------------------------------------------------------------
    // The Codex reviews of e1db3a4 (runs/pione_codex_review2.txt) and 9ce3515 (runs/pione_codex_review3.txt): docs/decisions.md PV21-PV26
    // ------------------------------------------------------------------------------------------

    /**
     * Review 2, M1 (PV21): "Save and make final" sent again makes final only what that form saved. A first form whose posting was refused
     * keeps its draft; the draft is then finished with "Save as draft"; the first form sent again must not make the finished draft final.
     */
    public function testAFormSentAgainNeverMakesLaterEditsFinal(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Replay Invoices Ltd');
        $web = $this->signIn($deskUser);
        $send = ['supplier_id' => (string) $s['id'], 'invoice_number' => 'REPLAY-1', 'invoice_date' => gmdate('Y-m-d', time() - 86400), 'charge_amount' => '5.00',
            'charge_description' => 'Carriage', 'action' => 'post'] + self::newForm($web->get('/ui/purchasing/invoices/new'));
        $r = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame(422, $r->status, $r->describe());
        $id = (int) self::$db->value("SELECT id FROM document WHERE doc_type = 'SINV' AND external_ref = 'REPLAY-1'");
        self::assertGreaterThan(0, $id, 'the refused posting kept the draft');
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['net_total' => '5.00', 'vat_total' => '1.00', 'action' => 'save']
            + self::draftForm($web->get("/ui/purchasing/invoices/{$id}"), $id));
        self::assertSame(303, $r->status, $r->describe());
        $r = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]), 'the first form made final what it never saw: ' . $r->describe());
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString((string) strstr(Words::PI_ONE['not_final'], '%s', true), $r->text());
    }

    /**
     * The Codex review of 8f6fbb7, M1 (PV32): a form FormOnce remembered before PV21 (its result names no saved version) is never made final
     * when sent again: the draft may have been changed since; the page asks for "Save and make final" on the draft as it is now.
     */
    public function testAFormRememberedWithoutItsSavedVersionIsNeverMadeFinal(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Old Replay Ltd');
        $web = $this->signIn($deskUser);
        $send = ['supplier_id' => (string) $s['id'], 'invoice_number' => 'REPLAY-OLD', 'invoice_date' => gmdate('Y-m-d', time() - 86400), 'charge_amount' => '5.00',
            'charge_description' => 'Carriage', 'action' => 'post'] + self::newForm($web->get('/ui/purchasing/invoices/new'));
        self::assertSame(422, $web->post('/ui/purchasing/invoices', $send)->status);
        $id = (int) self::$db->value("SELECT id FROM document WHERE doc_type = 'SINV' AND external_ref = 'REPLAY-OLD'");
        // As FormOnce stored it before PV21: no version in its result.
        $row = self::$db->one('SELECT idem_key, response_body FROM idempotency WHERE idem_key LIKE ?', ['%:' . $send['form_key']]);
        self::assertNotNull($row);
        $body = json_decode((string) $row['response_body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('version', $body);
        unset($body['version']);
        self::$db->exec('UPDATE idempotency SET response_body = ? WHERE idem_key = ?', [json_encode($body, JSON_THROW_ON_ERROR), $row['idem_key']]);
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['net_total' => '5.00', 'vat_total' => '1.00', 'action' => 'save']
            + self::draftForm($web->get("/ui/purchasing/invoices/{$id}"), $id));
        self::assertSame(303, $r->status, $r->describe());
        $r = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]), 'an old form made final what it never saw: ' . $r->describe());
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::PI_ONE['form_again'], $r->text());
    }

    /**
     * Review 2, M2 (PV22): a due date cleared on the new page stays cleared through a refused button ("Fill the lines" with nothing chosen)
     * and the save after it.
     */
    public function testAClearedDueDateStaysClearedThroughARefusal(): void
    {
        $s = $this->supplier('Due Refused Ltd', 30);
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $date = gmdate('Y-m-d', time() - 3 * 86400);
        $due = (new \DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d');
        $form = self::newForm($web->get('/ui/purchasing/invoices/new', ['supplier' => $s['id'], 'invoice_date' => $date]));
        self::assertSame([$due, $due], [$form['due_date'], $form['due_shown']]);
        $r = $web->post('/ui/purchasing/invoices', ['invoice_number' => 'DUE-R1', 'due_date' => '', 'from' => '', 'action' => 'from'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        $again = self::newForm($r);
        self::assertSame('', $again['due_date']);
        $r = $web->post('/ui/purchasing/invoices', ['action' => 'save'] + $again);
        self::assertSame(303, $r->status, $r->describe());
        self::assertNull(self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [self::invoiceId($r->location())]), 'not filled again');
    }

    /** Review 2, M3 (PV23): a charge typed goes through "+ Add new supplier" with the rest of what was typed (PV5, PV6). */
    public function testTheChargeTypedGoesThroughANewSupplier(): void
    {
        $s = $this->supplier('Charge Invoices Ltd');
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'charge_kind' => 'duty', 'charge_amount' => '7.50', 'charge_description' => 'Courier',
            'action' => 'new_supplier', 'new_supplier_name' => 'Charge Fresh Ltd'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(303, $r->status, $r->describe());
        $back = self::newForm($web->follow($r));
        self::assertSame(['duty', '7.50', 'Courier'], [$back['charge_kind'] ?? null, $back['charge_amount'] ?? null, $back['charge_description'] ?? null]);
    }

    /**
     * Review 3, M1 (PV24): the differences panel says what the match counts: units billed net of the posted credit notes (the credited ones
     * said), the units the delivery left for this invoice; never a figure the match does not use.
     */
    public function testTheDifferencesPanelCountsWhatTheMatchCounts(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Panel Credits Ltd');
        $x = $this->delivery($deskUser['id'], (int) $s['id'], 8, '1.00');
        $web = $this->signIn($deskUser);
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'from' => 'grn:' . $x['grn'], 'invoice_number' => 'PANEL-C1',
            'invoice_date' => gmdate('Y-m-d', time() - 86400), 'action' => 'from'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(303, $r->status, $r->describe());
        $id = self::invoiceId($r->location());
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['p_1' => '12', 'net_total' => '12.00', 'vat_total' => '0.00', 'action' => 'post']
            + self::draftForm($web->follow($r), $id));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('posted', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        $si = new SupplierInvoices(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)), fn (): FileStore => $this->store());
        $desk = Caller::staff($deskUser['id']);
        $cn = $si->createDraft($desk, 'credit_note', (int) $s['id'], ['invoice_number' => 'PANEL-CN1', 'invoice_date' => gmdate('Y-m-d', time() - 86400), 'credits_id' => $id]);
        $cn = $si->addCredit($desk, $cn->id, $cn->version, 1, 2);
        $cn = $si->saveHeader($desk, $cn->id, $cn->version, ['net_total' => '2.00', 'vat_total' => '0.00']);
        self::assertSame('posted', $si->post($desk, $cn->id, $cn->version)->status);
        $view = $web->get("/ui/purchasing/invoices/{$id}");
        $cell = static fn (string $label): string => implode(' ', self::texts($view, '//section[contains(@class, "doc-diff")]//td[@data-label="' . $label . '"]'));
        self::assertSame('10 ' . Words::say('DOC', 'credited_units', 2), $cell(Words::DOC['invoiced']), '12 billed, 2 credited: 10, as the match counts');
        self::assertSame('8', $cell(Words::DOC['received']));
        self::assertSame('£2.00', $cell(Words::DOC['difference']));
    }

    /**
     * Review 3, M4 (PV25): the differences panel reads its figures with the match (one read for the orders' lines whatever their number),
     * so an invoice of three deliveries opens with the same statements as an invoice of one.
     */
    public function testTheDifferencesPanelReadsTheSameForOneDeliveryOrThree(): void
    {
        $this->setting('invoices.check_price', 'true');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Panel Reads Ltd');
        $web = $this->signIn($deskUser);
        $make = function (string $ref, int $n) use ($deskUser, $s, $web): int {
            $grns = [];
            for ($i = 0; $i < $n; $i++) {
                $grns[] = $this->delivery($deskUser['id'], (int) $s['id'], 10, '3.00')['grn'];
            }
            $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'from' => 'grn:' . $grns[0], 'invoice_number' => $ref,
                'invoice_date' => gmdate('Y-m-d', time() - 86400), 'action' => 'from'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
            self::assertSame(303, $r->status, $r->describe());
            $id = self::invoiceId($r->location());
            foreach (array_slice($grns, 1) as $g) {
                $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['from' => 'grn:' . $g, 'action' => 'from'] + self::draftForm($web->get("/ui/purchasing/invoices/{$id}"), $id));
                self::assertSame(303, $r->status, $r->describe());
            }
            $prices = [];
            for ($no = 1; $no <= $n; $no++) {
                $prices['pp_' . $no] = '4.00';
            }
            $r = $web->post("/ui/purchasing/invoices/{$id}/lines", $prices + ['net_total' => number_format(40 * $n, 2, '.', ''), 'vat_total' => '0.00', 'action' => 'post']
                + self::draftForm($web->get("/ui/purchasing/invoices/{$id}"), $id));
            self::assertSame(303, $r->status, $r->describe());
            self::assertSame('needs_review', self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$id]));
            return $id;
        };
        $reads = static function (int $id) use ($web): int {
            $n = static fn (): int => (int) self::$appDb->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
            $before = $n();
            $view = $web->get("/ui/purchasing/invoices/{$id}");
            self::assertSame(1, self::nodes($view, '//section[contains(@class, "doc-diff")]'));
            return $n() - $before - 1;
        };
        $one = $make('READS-1', 1);
        $three = $make('READS-3', 3);
        $reads($one);
        self::assertSame($reads($one), $reads($three), 'one delivery or three: the same statements');
    }

    /**
     * Review 3, M2 (PV24): "still to come" adds up what each order line still waits for: 11 of 10 in (within the tolerance) and none of 2
     * leaves 2 to come, not 12 less 11.
     */
    public function testStillToComeAddsUpEachLine(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $buyer = Caller::staff($buyerUser['id']);
        $s = $this->supplier('To Come Ltd');
        $a = $this->dryItem('To come pod A');
        $b = $this->dryItem('To come pod B');
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $pos->createDraft($buyer, (int) $s['id'], []);
        $d = $pos->saveDraft($buyer, $d->id, $d->version, [], [['sku_id' => $a, 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '2.00'],
            ['sku_id' => $b, 'packs' => 2, 'units_per_pack' => 1, 'pack_price' => '2.00']]);
        $d = $pos->approve($buyer, $d->id, $d->version);
        // By hand (put back below, for the stock checks after the test): 11 of the first line in, none of the second.
        self::$db->exec("UPDATE po_line SET received_units = 11 WHERE document_id = ? AND line_no = 1 AND kind = 'item'", [$d->id]);
        self::$db->exec("UPDATE purchase_order SET state = 'part_received' WHERE document_id = ?", [$d->id]);
        $strip = implode(' ', self::texts($this->signIn($buyerUser)->get('/ui/purchasing/orders/' . $d->id), '//section[contains(@class, "doc-progress")]'));
        self::$db->exec("UPDATE po_line SET received_units = 0 WHERE document_id = ? AND kind = 'item'", [$d->id]);
        self::$db->exec("UPDATE purchase_order SET state = 'approved' WHERE document_id = ?", [$d->id]);
        self::assertStringContainsString(Words::say('DOC', 'to_come', 2), $strip);
    }

    /** Review 3, L5 (PV26): the Show menu says "Open" with its own value, and a choice made in it wins over an old link's cancelled=1. */
    public function testShowOpenWinsOverAnOldCancelledLink(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Show Open Ltd');
        $si = new SupplierInvoices(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)), fn (): FileStore => $this->store());
        $desk = Caller::staff($deskUser['id']);
        $d = $si->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'SHOW-X1']);
        $si->cancel($desk, $d->id, $d->version, 'Typed twice');
        $web = $this->signIn($deskUser);
        $selected = static fn (UiResponse $r): array => self::texts($r, '//form[@class="toolbar"]//select[@name="show"]/option[@selected]/@value');
        $old = $web->get('/ui/purchasing/invoices', ['cancelled' => '1']);
        self::assertSame(['all'], $selected($old));
        self::assertStringContainsString('SHOW-X1', $old->text());
        self::assertSame(['open', 'all', 'cancelled'], self::texts($old, '//form[@class="toolbar"]//select[@name="show"]/option/@value'));
        $open = $web->get('/ui/purchasing/invoices', ['cancelled' => '1', 'show' => 'open']);
        self::assertSame(['open'], $selected($open));
        self::assertStringNotContainsString('SHOW-X1', $open->text());
    }

    /** Review 3, L6 (PV26): an action under More refused for its reason opens More as well as its own fold, so the person sees why. */
    public function testARefusedActionUnderMoreOpensMore(): void
    {
        $s = $this->supplier('More Opens Ltd');
        $o = $this->order((int) $s['id'], 2);
        $web = $this->signIn($this->uiUser('buyer'));
        $path = '/ui/purchasing/orders/' . $o['po'];
        $r = $web->post($path . '/amend', ['reason_code' => ''] + $web->get($path)->form($path . '/amend'));
        self::assertGreaterThanOrEqual(400, $r->status, $r->describe());
        self::assertSame(1, self::nodes($r, '//details[contains(@class, "doc-more")][@open]//details[contains(@class, "doc-act")][@open]//form[contains(@action, "/amend")]'),
            'the order\'s Correct, refused without a reason');
        // The Codex review of 8f6fbb7, L3 (PV32): a reason that needs a note, sent without one, opens the same folds.
        $r = $web->post($path . '/cancel', ['reason_code' => 'other', 'note' => ''] + $web->get($path)->form($path . '/cancel'));
        self::assertGreaterThanOrEqual(400, $r->status, $r->describe());
        self::assertSame(1, self::nodes($r, '//details[contains(@class, "doc-more")][@open]//details[contains(@class, "doc-act")][@open]//form[contains(@action, "/cancel")]'),
            'the order\'s Cancel, "other" without its note');
        $r = $web->post($path . '/amend', ['reason_code' => 'other', 'note' => ''] + $web->get($path)->form($path . '/amend'));
        self::assertSame(1, self::nodes($r, '//details[contains(@class, "doc-more")][@open]//details[contains(@class, "doc-act")][@open]//form[contains(@action, "/amend")]'),
            'the order\'s Correct, "other" without its note: ' . $r->describe());
        $deskUser = $this->uiUser('purchasing_desk');
        $desk = $this->signIn($deskUser);
        $send = ['supplier_id' => (string) $s['id'], 'invoice_number' => 'MORE-1', 'invoice_date' => gmdate('Y-m-d', time() - 86400), 'charge_amount' => '5.00',
            'charge_description' => 'Carriage', 'net_total' => '5.00', 'vat_total' => '1.00', 'action' => 'post'] + self::newForm($desk->get('/ui/purchasing/invoices/new'));
        $id = self::invoiceId($desk->post('/ui/purchasing/invoices', $send)->location());
        $ipath = '/ui/purchasing/invoices/' . $id;
        $r = $desk->post($ipath . '/reverse', ['reason_code' => 'other', 'note' => ''] + $desk->get($ipath)->form($ipath . '/reverse'));
        self::assertGreaterThanOrEqual(400, $r->status, $r->describe());
        self::assertSame(1, self::nodes($r, '//details[contains(@class, "doc-more")][@open]//details[contains(@class, "doc-act")][@open]//form[contains(@action, "/reverse")]'),
            'the invoice\'s Cancel, refused without the note "other" needs');
    }

    // ------------------------------------------------------------------------------------------
    // The coordinator's Chrome check of 8f6fbb7 on staging (29 Oct 2026): docs/decisions.md PV27-PV31
    // ------------------------------------------------------------------------------------------

    /**
     * Item 1 (PV27): a saved order's and a saved invoice's action bar (More and the next step) is in the header, at its right beside the
     * title, before the warnings, the reviewer's card and the section's pills; the header's "+ New ..." button gives it that place. Item 8: the
     * new invoice page shows no "+ New supplier invoice" of its own; the list still does.
     */
    public function testTheActionBarIsInTheHeaderAndTheNewPageHasNoNewButton(): void
    {
        $s = $this->supplier('Head Actions Ltd');
        $o = $this->order((int) $s['id'], 2);
        $web = $this->signIn($this->uiUser('buyer'));
        $view = $web->get('/ui/purchasing/orders/' . $o['po']);
        $bar = '//header[contains(@class, "page-head")]/div[contains(@class, "ph-actions")]/div[contains(@class, "doc-actions")]';
        self::assertSame(1, self::nodes($view, $bar), $view->describe());
        self::assertSame(1, self::nodes($view, $bar . '/details[contains(@class, "doc-more")]'));
        self::assertSame(1, self::nodes($view, $bar . '/*[contains(@class, "primary")] | ' . $bar . '/details/summary[contains(@class, "primary")]'));
        self::assertSame(1, self::nodes($view, '//main//div[contains(@class, "doc-actions")]'), 'one bar');
        self::assertSame(0, self::nodes($view, '//header[contains(@class, "page-head")]//a[contains(@href, "/ui/purchasing/orders/new")]'), 'the bar takes the "+ New" place');
        $deskUser = $this->uiUser('purchasing_desk');
        $desk = $this->signIn($deskUser);
        $send = ['supplier_id' => (string) $s['id'], 'invoice_number' => 'HEAD-1', 'invoice_date' => gmdate('Y-m-d', time() - 86400), 'charge_amount' => '5.00',
            'charge_description' => 'Carriage', 'net_total' => '5.00', 'vat_total' => '1.00', 'action' => 'post'] + self::newForm($desk->get('/ui/purchasing/invoices/new'));
        $id = self::invoiceId($desk->post('/ui/purchasing/invoices', $send)->location());
        $inv = $desk->get('/ui/purchasing/invoices/' . $id);
        self::assertSame(1, self::nodes($inv, $bar . '/details[contains(@class, "doc-more")]'), $inv->describe());
        self::assertSame(1, self::nodes($inv, '//main//div[contains(@class, "doc-actions")]'));
        $new = '//header[contains(@class, "page-head")]//a[contains(@href, "/ui/purchasing/invoices/new")]';
        self::assertSame(0, self::nodes($desk->get('/ui/purchasing/invoices/new'), $new), 'not on the new page itself');
        self::assertGreaterThanOrEqual(1, self::nodes($desk->get('/ui/purchasing/invoices'), $new), 'the list keeps it');
    }

    /**
     * Item 2 (PV28): the order's strip never says "X of Y" with X over Y: more received than ordered, or more invoiced than received, says
     * how many more.
     */
    public function testTheStripNeverSaysMoreOfLess(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Strip Words Ltd');
        $o = $this->order((int) $s['id'], 3);
        $si = new SupplierInvoices(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)), fn (): FileStore => $this->store());
        $desk = Caller::staff($deskUser['id']);
        $d = $si->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'STRIP-1', 'invoice_date' => gmdate('Y-m-d', time() - 86400)]);
        $d = $si->addOrder($desk, $d->id, $d->version, $o['po']);
        $d = $si->saveHeader($desk, $d->id, $d->version, ['net_total' => '6.00', 'vat_total' => '0.00']);
        self::assertSame('posted', $si->submit($desk, $d->id, $d->version)->status);
        $web = $this->signIn($this->uiUser('buyer'));
        $strip = function (int $received) use ($web, $o): string {
            // By hand, put back at once (for the stock checks after the test).
            self::$db->exec("UPDATE po_line SET received_units = ? WHERE document_id = ? AND kind = 'item'", [$received, $o['po']]);
            self::$db->exec("UPDATE purchase_order SET state = ? WHERE document_id = ?", [$received >= 3 ? 'received' : 'part_received', $o['po']]);
            $text = implode(' ', self::texts($web->get('/ui/purchasing/orders/' . $o['po']), '//section[contains(@class, "doc-progress")]//p[contains(@class, "doc-step-big")]'));
            self::$db->exec("UPDATE po_line SET received_units = 0 WHERE document_id = ? AND kind = 'item'", [$o['po']]);
            self::$db->exec("UPDATE purchase_order SET state = 'approved' WHERE document_id = ?", [$o['po']]);
            return $text;
        };
        $two = $strip(2);
        self::assertStringContainsString(Words::say('DOC', 'of', 2, 3), $two);
        self::assertStringContainsString(Words::say('DOC', 'more_than_received', 3, 1), $two, 'invoiced 3, received 2');
        self::assertStringNotContainsString(Words::say('DOC', 'of', 3, 2), $two);
        $four = $strip(4);
        self::assertStringContainsString(Words::say('DOC', 'more_than_ordered', 4, 1), $four, 'received 4 of an order of 3');
        self::assertStringContainsString(Words::say('DOC', 'of', 3, 4), $four);
    }

    /**
     * Item 4 (PV29): the new page's head is the design's grid: Supplier | Supplier's invoice number; "From" its own row; Invoice date | Due date
     * (one column on a phone: UiUnitTest). Item 5: the "+ Add line" row puts Qty and Price in their columns and Add at the end of the row.
     */
    public function testTheNewPageHeadAndItsAddRowFollowTheDesign(): void
    {
        $s = $this->supplier('Grid Invoices Ltd', 30);
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $web->get('/ui/purchasing/invoices/new', ['supplier' => $s['id']]);
        $head = '//fieldset[contains(@class, "po-header")]';
        $cells = array_map(static fn (\DOMElement $c): string => $c->getAttribute('class') . '|' . implode(',', array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            iterator_to_array((new \DOMXPath($c->ownerDocument))->query('.//input[@name and not(@type="hidden")] | .//select[@name]', $c)))),
            iterator_to_array((new \DOMXPath($page->dom()))->query($head . '/*[self::div or self::label]')));
        self::assertSame(['po-box-field|supplier_id', '|invoice_number', 'doc-field doc-wide|from', '|invoice_date', '|due_date'],
            array_map(static fn (string $c): string => (string) preg_replace('/^[^|]*?\b(po-box-field|doc-field doc-wide)\b[^|]*\|/', '$1|', $c), $cells), 'the grid\'s cells in order');
        self::assertSame(0, self::nodes($page, $head . '/div[contains(@class, "po-box-field")][contains(@class, "doc-wide")]'), 'the supplier half the row');
        $row = '//table[contains(@class, "pi-lines")]//tr[contains(@class, "grp-add")]/td';
        self::assertSame(4, self::nodes($page, $row));
        self::assertSame(1, self::nodes($page, '(' . $row . ')[1]//input[@name="q"]'));
        self::assertSame(1, self::nodes($page, '(' . $row . ')[2][@data-label="' . Words::PI_ONE['qty'] . '"]//input[@name="packs"]'));
        self::assertSame(1, self::nodes($page, '(' . $row . ')[3][@data-label="' . Words::PI_ONE['price'] . '"]//input[@name="price"]'));
        self::assertSame(1, self::nodes($page, '(' . $row . ')[4]//button[@value="add"]'));
    }

    /**
     * Item 6 (PV30): "From" offers only what "Fill the lines" takes: an order with nothing left to invoice (its units on final invoices, or
     * all on this draft already) and a delivery all on this draft are left out.
     */
    public function testFromLeavesOutWhatHasNothingLeftToInvoice(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('From Left Ltd');
        $done = $this->order((int) $s['id'], 2);
        $open = $this->order((int) $s['id'], 2);
        $web = $this->signIn($deskUser);
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'from' => 'po:' . $done['po'], 'invoice_number' => 'LEFT-1',
            'invoice_date' => gmdate('Y-m-d', time() - 86400), 'net_total' => '4.00', 'vat_total' => '0.00', 'action' => 'post'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('posted', self::$db->value('SELECT status FROM document WHERE id = ?', [self::invoiceId($r->location())]));
        $page = $web->get('/ui/purchasing/invoices/new', ['supplier' => $s['id']]);
        self::assertSame(0, self::nodes($page, '//select[@name="from"]//option[@value="po:' . $done['po'] . '"]'), 'every unit invoiced');
        self::assertSame(1, self::nodes($page, '//select[@name="from"]//option[@value="po:' . $open['po'] . '"]'));
        // A draft holding every unit of an order and of a delivery: neither is offered on it again.
        $x = $this->delivery($deskUser['id'], (int) $s['id'], 3, '1.00');
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'from' => 'grn:' . $x['grn'], 'invoice_number' => 'LEFT-2', 'action' => 'from']
            + self::newForm($web->get('/ui/purchasing/invoices/new')));
        $id = self::invoiceId($r->location());
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['from' => 'po:' . $open['po'], 'action' => 'from'] + self::draftForm($web->get("/ui/purchasing/invoices/{$id}"), $id));
        self::assertSame(303, $r->status, $r->describe());
        $draft = $web->get("/ui/purchasing/invoices/{$id}");
        self::assertSame(0, self::nodes($draft, '//select[@name="from"]//option[@value="po:' . $open['po'] . '"]'), 'all on this draft');
        self::assertSame(0, self::nodes($draft, '//select[@name="from"]//option[@value="grn:' . $x['grn'] . '"]'), 'all on this draft');
    }

    /**
     * Item 7 (PV31): a final invoice whose match needs review says "Needs a look" in its status block (amber), as the list does; "Final" is a
     * quiet line beside it, and no second tag says the same.
     */
    public function testANeedsALookInvoiceSaysSoAsTheListDoes(): void
    {
        $this->setting('invoices.check_price', 'true');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Look Words Ltd');
        $x = $this->delivery($deskUser['id'], (int) $s['id'], 10, '3.00');
        $web = $this->signIn($deskUser);
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'from' => 'grn:' . $x['grn'], 'invoice_number' => 'LOOK-W1',
            'invoice_date' => gmdate('Y-m-d', time() - 86400), 'action' => 'from'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
        $id = self::invoiceId($r->location());
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['pp_1' => '4.00', 'net_total' => '40.00', 'vat_total' => '0.00', 'action' => 'post']
            + self::draftForm($web->follow($r), $id));
        self::assertSame('needs_review', self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$id]), $r->describe());
        $view = $web->get('/ui/purchasing/invoices/' . $id);
        self::assertSame([Words::INVOICES['needs_look']], self::texts($view, '//div[contains(@class, "doc-head")]//h1//span[contains(@class, "chip")][contains(@class, "needs")]'));
        self::assertSame([Words::of('DOC_STATUS', 'posted')], self::texts($view, '//div[contains(@class, "doc-head")]//p[contains(@class, "ph-meta")]/span[contains(@class, "muted")]'));
        self::assertSame(0, self::nodes($view, '//div[contains(@class, "doc-head")]//span[contains(@class, "tag")]'), 'no second word for it');
        self::assertSame([Words::INVOICES['needs_look']], self::texts($web->get('/ui/purchasing/invoices', ['q' => 'LOOK-W1']), '//td[contains(@class, "doc-st")]'));
    }

    /**
     * Item 9 (PV31): the item box (the order's and the invoice's one engine) puts the name the words begin first, the supplier's items
     * before items not set up with the supplier yet, then the rest.
     */
    public function testTheItemBoxPutsTheNameTheWordsBeginFirst(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier('Rank Items Ltd');
        $t = 'Qz' . bin2hex(random_bytes(3));
        $other = $this->dryItem("{$t} Cotton Elf Bar 600 Prefilled Pod Kit");
        $this->dryItem("{$t} Elf Bar 600 Prefilled Pod Kit");
        $longer = $this->dryItem("{$t} Elf Bar 600 Prefilled Pod Kit 3 Pack");
        $this->dryItem("Elf Bar 600 {$t} Prefilled");
        $items = new SupplierItems(self::$db);
        $items->create(Caller::staff($pmUser['id']), (int) $s['id'], $other, ['units_per_pack' => '1', 'supplier_code' => 'RK-1'], ['pack_price' => '1.00']);
        $items->create(Caller::staff($pmUser['id']), (int) $s['id'], $longer, ['units_per_pack' => '1', 'supplier_code' => 'RK-2'], ['pack_price' => '1.00']);
        $web = $this->signIn($pmUser);
        foreach (['/ui/purchasing/invoices/products', '/ui/purchasing/orders/products'] as $route) {
            $order = self::texts($web->get($route, ['supplier' => $s['id'], 'q' => "{$t} Elf Bar 600 Prefilled"]), '//ul[@data-po-choices]/li/span[@class="sm-opt-name"]');
            self::assertSame(["{$t} Elf Bar 600 Prefilled Pod Kit 3 Pack", "{$t} Elf Bar 600 Prefilled Pod Kit", "{$t} Cotton Elf Bar 600 Prefilled Pod Kit",
                "Elf Bar 600 {$t} Prefilled"], $order, $route . ': begins with the words (the supplier\'s first), then the rest (the supplier\'s first)');
        }
    }

    public function testTheItemBoxListsTheSuppliersItemsAndAChoiceAddsItsPack(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Box Invoices Ltd');
        $buyer = $this->uiUser('buyer')['id'];
        $mint = $this->dryItem('Box invoice mint 10ml');
        $siSix = (new SupplierItems(self::$db))->create(Caller::staff($buyer), (int) $s['id'], $mint, ['units_per_pack' => '6', 'supplier_code' => 'BM-6'], ['pack_price' => '9.00']);
        (new SupplierItems(self::$db))->create(Caller::staff($buyer), (int) $s['id'], $mint, ['units_per_pack' => '24', 'supplier_code' => 'BM-24'], ['pack_price' => '30.00']);
        $web = $this->signIn($deskUser);
        $r = $web->get('/ui/purchasing/invoices/products', ['supplier' => $s['id'], 'q' => 'box invoice mint']);
        self::assertSame(200, $r->status, $r->describe());
        self::assertSame(1, self::nodes($r, '/html/body/*[@data-po-fragment]'), 'a fragment, not a page');
        $labels = array_map(static fn (\DOMElement $li): string => $li->getAttribute('data-si'), iterator_to_array((new \DOMXPath($r->dom()))->query('//ul[@data-po-choices]/li')));
        self::assertContains((string) $siSix['id'], $labels, 'each of this supplier\'s packs is a choice');
        self::assertSame(0, self::nodes($web->get('/ui/purchasing/invoices/products', ['supplier' => $s['id'], 'q' => 'b']), '//ul[@data-po-choices]/li'), 'one letter: nothing yet');
        // The 6-pack chosen: its pack and its price on the line.
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'q' => 'Box invoice mint 10ml', 'pick_si' => (string) $siSix['id'], 'packs' => '2',
            'action' => 'add'] + self::newForm($web->get('/ui/purchasing/invoices/new')));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([[$mint, 2, 6, '9.0000']], array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs'], (int) $l['units_per_pack'], (string) $l['pack_price']],
            self::$db->all('SELECT dl.sku_id, sl.packs, sl.units_per_pack, sl.pack_price FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id '
                . 'AND dl.line_no = sl.line_no WHERE sl.document_id = ?', [self::invoiceId($r->location())])));
    }

    /**
     * The purchase order's screens in the same look (the brief: the order's page behaves as before, PX1-PX11 and its tests unchanged):
     * the header fields in a grid, the totals and the bar at the bottom, the list a clean table, the confirmed order a document with its
     * actions in one bar at the top.
     */
    public function testThePurchaseOrderScreensShareTheLook(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $s = $this->supplier('Look Orders Ltd');
        $web = $this->signIn($buyerUser);
        $page = $web->get('/ui/purchasing/orders/new');
        $one = '//form[@action="/ui/purchasing/orders"]';
        self::assertSame(1, self::nodes($page, $one . '//fieldset[contains(@class, "po-header")][contains(@class, "doc-grid")]'));
        self::assertSame(1, self::nodes($page, $one . '//table[contains(@class, "po-lines")][contains(@class, "doc-lines")]'));
        self::assertSame(1, self::nodes($page, $one . '//section[contains(@class, "po-summary")]//*[contains(@class, "doc-totals")]'));
        self::assertSame([Words::PO_ONE['save'], Words::PO_ONE['confirm']], self::texts($page, $one . '//section[contains(@class, "po-summary")]//div[contains(@class, "doc-bar")]//button'));
        self::assertContains('/ui/purchasing/orders', self::hrefsIn($page, $one . '//div[contains(@class, "doc-bar")]//a'));

        $o = $this->order((int) $s['id'], 3);
        $old = $this->order((int) $s['id'], 1);
        $done = $this->order((int) $s['id'], 2);
        self::$db->exec("UPDATE purchase_order SET state = 'received', updated_at = UTC_TIMESTAMP(6) WHERE document_id = ?", [$done['po']]);
        self::$db->exec("UPDATE purchase_order SET state = 'received', updated_at = UTC_TIMESTAMP(6) - INTERVAL 400 DAY WHERE document_id = ?", [$old['po']]);
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id'], 'action' => 'save'] + $web->get('/ui/purchasing/orders/new')->form('/ui/purchasing/orders'));
        self::assertSame(303, $r->status, $r->describe());

        // The approved design (the owner's "yes", 29 Oct 2026: monday.com's style): the list is a board, its rows grouped by status, each
        // group a coloured heading with its count over a white card with its colour on the left edge, each status a solid coloured cell.
        $list = $web->get('/ui/purchasing/orders');
        $heads = '//div[contains(@class, "grp-block")][contains(@class, "doc-group")]/h3[contains(@class, "grp-title")]';
        self::assertSame([Words::ORDERS['group_on_way'] . ' ' . Words::ORDERS['group_one'], Words::ORDERS['group_draft'] . ' ' . Words::ORDERS['group_one'],
            Words::ORDERS['group_done'] . ' ' . Words::ORDERS['group_one']], self::texts($list, $heads), 'the order: waiting, on the way, draft, received or closed lately');
        self::assertSame(['info', 'waiting', 'done'], array_map(static fn (\DOMElement $g): string => (string) preg_replace('/^.*\bdoc-group\s+(\w+).*$/', '$1', $g->getAttribute('class')),
            iterator_to_array((new \DOMXPath($list->dom()))->query('//div[contains(@class, "doc-group")]'))));
        self::assertSame(3, self::nodes($list, '//div[contains(@class, "doc-group")]/div[contains(@class, "doc-board")]/table'));
        self::assertSame([Words::DOC_LIST['order'], Words::DOC_LIST['status'], Words::DOC_LIST['supplier'], Words::DOC_LIST['expected'], Words::DOC_LIST['received'],
            Words::DOC_LIST['total']], self::texts($list, '(//table[contains(@class, "doc-list")])[1]/thead/tr/th'));
        self::assertSame([Words::PO_STATE['approved']], self::texts($list, '//div[contains(@class, "doc-group")][contains(@class, "info")]//td[contains(@class, "doc-st")]'));
        self::assertSame(1, self::nodes($list, '//div[contains(@class, "doc-group")][contains(@class, "info")]//td[contains(@class, "doc-st")][contains(@class, "needs")]'),
            'the status cell keeps its own colour in the group');
        self::assertSame(1, self::nodes($list, '//div[contains(@class, "doc-group")][contains(@class, "info")]//progress[@max="3"][@value="0"]'), 'received of ordered');
        self::assertSame(['/ui/purchasing/orders/new'], self::hrefsIn($list, '//div[contains(@class, "doc-group")][contains(@class, "waiting")]//tr[contains(@class, "grp-add")]//a'));
        self::assertContains('/ui/purchasing/orders/' . $o['po'], self::hrefsIn($list, '//table[contains(@class, "doc-list")]//a[contains(@class, "row-link")]'));
        self::assertNotContains('/ui/purchasing/orders/' . $old['po'], $list->hrefs(), 'received long ago: under All orders');
        self::assertSame(['', 'all', 'closed'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'),
            iterator_to_array((new \DOMXPath($list->dom()))->query('//form[@class="toolbar"]//select[@name="show"]/option'))), 'one Show menu');
        self::assertSame(0, self::nodes($list, '//form[@class="toolbar"]//select[@name="state"]'));
        self::assertContains('/ui/purchasing/orders/' . $old['po'], $web->get('/ui/purchasing/orders', ['show' => 'all'])->hrefs());
        $closed = $web->get('/ui/purchasing/orders', ['show' => 'closed']);
        self::assertContains('/ui/purchasing/orders/' . $old['po'], $closed->hrefs());
        self::assertNotContains('/ui/purchasing/orders/' . $o['po'], $closed->hrefs());
        self::assertStringContainsString('Look Orders', $web->get('/ui/purchasing/orders', ['q' => 'Look Orders'])->text(), 'one search: the supplier too');

        // The saved order: its status a solid block by the title, one action bar (the next step in the accent, the rest under More), the
        // progress strip (ordered, received, invoiced, expected and what is still to come), then the paper.
        $view = $web->get('/ui/purchasing/orders/' . $o['po']);
        self::assertSame(1, self::nodes($view, '//div[contains(@class, "doc-head")]//p[contains(@class, "ph-meta")]/span[contains(@class, "chip")]'), 'beside the title (the h1 stays the order\'s name)');
        self::assertSame(1, self::nodes($view, '//main//div[contains(@class, "doc-actions")]'));
        self::assertSame([Words::ORDER['send']], self::texts($view, '//div[contains(@class, "doc-actions")]/details[contains(@class, "doc-act")][@open]/summary[contains(@class, "primary")]'),
            'confirmed, not sent: Send is the next step');
        self::assertSame(1, self::nodes($view, '//div[contains(@class, "doc-actions")]//form[contains(@action, "/send")]'), 'send, in the bar');
        self::assertSame(1, self::nodes($view, '//div[contains(@class, "doc-actions")]/details[contains(@class, "doc-more")]//form[contains(@action, "/copy")]'), 'the rest under More');
        self::assertContains('/ui/purchasing/orders/' . $o['po'] . '/pdf', self::hrefsIn($view, '//div[contains(@class, "doc-actions")]//a'));
        self::assertSame([Words::DOC['ordered'], Words::DOC['received'], Words::DOC['invoiced'], Words::DOC['expected']],
            self::texts($view, '//section[contains(@class, "doc-progress")]//*[contains(@class, "doc-step-label")]'));
        self::assertStringContainsString(Words::say('DOC', 'of', 0, 3), implode(' ', self::texts($view, '//section[contains(@class, "doc-progress")]')));
        self::assertStringContainsString(Words::say('DOC', 'to_come', 3), implode(' ', self::texts($view, '//section[contains(@class, "doc-progress")]')));
        self::assertSame(2, self::nodes($view, '//section[contains(@class, "doc-progress")]//progress'));
        self::assertStringContainsString('Look Orders Ltd', implode(' ', self::texts($view, '//*[contains(@class, "doc-party")]')));
        self::assertSame(1, self::nodes($view, '//table[contains(@class, "doc-lines")]'));
        self::assertSame(1, self::nodes($view, '//section[contains(@class, "doc-totals")]'));
        // The two orders marked received above (by hand, with nothing received) go back, for the stock checks after the test.
        self::$db->exec("UPDATE purchase_order SET state = 'approved' WHERE document_id IN (?, ?)", [$done['po'], $old['po']]);
    }
}
