<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Auth\Permissions;
use CW\Caller;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\PurchaseOrdersController as PurchaseOrdersControllerAlias;
use CW\Ui\Words;

/**
 * The purchase order on ONE page (the owner, 26 Oct 2026: "this purchase order all this related all operation i need single view like in
 * purchase order module have supplier choose option add new button in this dropdown. dont need multiple step"; docs/decisions.md PX1-PX9),
 * as cw_app through the real kernel:
 *  - "New purchase order" opens the page at once; opening it (or leaving it) makes no draft;
 *  - the draft is made by the first POST with something to keep (a product, "+ Add new item", Save) and a supplier, once however often
 *    the form is sent; a refusal (no supplier, nothing to add, not found, several choices, an item refused) leaves no draft;
 *  - "+ Add new supplier" in the supplier's dropdown: a draft supplier (its approval rules unchanged), chosen on the page; Confirm is
 *    refused in plain words until it is approved;
 *  - "+ Add new item" in the product's dropdown: Pack B's rules (a barcode on another item refused, the same name answered), the line;
 *  - who sees the "+ Add new" lines (suppliers.manage, items.create) and the one line the others see; doc.PO.post for the page;
 *  - the CSRF token on every POST; the page without the script (a plain select, plain fields, the scan box); the product box's choices.
 */
final class PoOnePageScreensTest extends KernelUiTestCase
{
    private ?string $suppliersManage = null;

    protected function tearDown(): void
    {
        if ($this->suppliersManage !== null) {
            self::$db->exec("UPDATE role_permission SET roles = ? WHERE permission = 'suppliers.manage'", [$this->suppliersManage]);
            $this->suppliersManage = null;
        }
        Permissions::useStored(null);
        parent::tearDown();
    }

    /** An active supplier made by $buyerId and approved by a fresh reviewer. @return array<string, mixed> */
    private function supplier(int $buyerId, string $name): array
    {
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyerId), ['name' => $name, 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA', 'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example',
            'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400), 'dd_checked_by' => (string) $buyerId,
            'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyerId), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    private function pos(): PurchaseOrders
    {
        return new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
    }

    private static function drafts(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'PO'");
    }

    private static function orderId(?string $location): int
    {
        self::assertSame(1, preg_match('#^/ui/purchasing/orders/(\d+)[?\#]#', (string) $location, $m), (string) $location);
        return (int) $m[1];
    }

    private static function nodes(UiResponse $r, string $xpath): int
    {
        return (new \DOMXPath($r->dom()))->query($xpath)->length;
    }

    /** The new page's one form (its action is the list's address). @return array<string, string> */
    private static function newForm(UiResponse $r): array
    {
        $f = $r->form('/ui/purchasing/orders');
        self::assertArrayHasKey('form_key', $f, $r->describe());
        return $f;
    }

    public function testNewPurchaseOrderOpensOnePageAtOnceAndMakesNothing(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $s = $this->supplier($buyerUser['id'], 'Open Page Supplies');
        $web = $this->signIn($buyerUser);

        // The list's "New purchase order" leads to the page itself: no side panel, no "Start a new order" step.
        $list = $web->get('/ui/purchasing/orders');
        self::assertSame(200, $list->status, $list->describe());
        // "+ New purchase order" in the header, alone (DC4: no toolbar button, no second More of other create actions).
        self::assertSame(['/ui/purchasing/orders/new'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array((new \DOMXPath($list->dom()))->query('//header[contains(@class, "page-head")]//a[contains(concat(" ", @class, " "), " primary ")] | //header[contains(@class, "page-head")]//details[@class="more"]//a'))));
        self::assertSame(0, self::nodes($list, '//*[@id="new"]'), 'no side panel');
        self::assertSame(0, self::nodes($list, '//main//form[@method="post"]'), 'no form on the list');

        $page = $web->get('/ui/purchasing/orders/new');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(Words::PO_ONE['title'], trim((string) (new \DOMXPath($page->dom()))->evaluate('string(//main//h1)')));
        $form = self::newForm($page);
        self::assertSame(['csrf', 'form_key'], array_slice(array_keys($form), 0, 2));
        foreach (['supplier_id', 'new_supplier_name', 'new_supplier_email', 'new_supplier_phone', 'expected_date', 'external_ref', 'note', 'q', 'pick_si',
            'pick_sku', 'packs', 'charge_amount'] as $field) {
            self::assertArrayHasKey($field, $form, $field);
        }
        // Short and simple (the owner: monday.com's way, nothing over complicated): no order date, no charge's words or VAT, no folds but
        // the two "+ Add new" forms (opened from the dropdowns), one primary button.
        foreach (['doc_date', 'charge_description', 'charge_vat'] as $gone) {
            self::assertArrayNotHasKey($gone, $form, $gone);
        }
        self::assertSame('', $form['supplier_id'], 'no supplier chosen for the person');
        // Enter anywhere is "Add": the form's first button; Save and Confirm on the same page.
        $buttons = array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'),
            iterator_to_array((new \DOMXPath($page->dom()))->query('//form[@action="/ui/purchasing/orders"]//button[@type="submit"]')));
        self::assertSame('add', $buttons[0] ?? null);
        foreach (['new_supplier', 'add', 'save', 'approve'] as $b) {
            self::assertContains($b, $buttons, $b);
        }
        self::assertSame(0, self::drafts(), 'opening the page makes no draft');
        $web->get('/ui/purchasing/orders/new');
        $web->get('/ui/purchasing/orders');
        self::assertSame(0, self::drafts(), 'an empty page left alone leaves no draft');

        // The supplier's card opens it with the supplier chosen.
        self::assertContains('/ui/purchasing/orders/new?supplier=' . $s['id'], $web->get('/ui/purchasing/suppliers/' . $s['id'])->hrefs());
        self::assertSame((string) $s['id'], self::newForm($web->get('/ui/purchasing/orders/new', ['supplier' => $s['id']]))['supplier_id']);

        // The side panel's and the step's addresses are gone.
        self::assertSame(404, $web->post('/ui/purchasing/orders/new-supplier', ['csrf' => $this->token($web), 'form_key' => str_repeat('a', 32), 'new_supplier_name' => 'X Ltd'])->status);
        self::assertSame(404, $web->get('/ui/purchasing/orders/1/new-item')->status);
        self::assertSame(404, $web->post('/ui/purchasing/orders/1/new-supplier', ['csrf' => $this->token($web), 'version' => '1', 'new_supplier_name' => 'X Ltd'])->status);

        // Who does not order never opens it.
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        self::assertSame(403, $desk->get('/ui/purchasing/orders/new')->status);
        self::assertSame(403, $desk->get('/ui/purchasing/orders/products', ['q' => 'pod'])->status);
        self::assertSame(403, $desk->post('/ui/purchasing/orders', ['csrf' => $this->token($desk), 'form_key' => str_repeat('a', 32), 'supplier_id' => (string) $s['id'],
            'q' => 'pod', 'action' => 'add'])->status);
        self::assertSame(0, self::drafts());
    }

    public function testASupplierAndAProductMakeOneDraftAndARefusalMakesNone(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $bid = $buyerUser['id'];
        $s = $this->supplier($bid, 'One Page Supplies');
        $pod = self::makeSku('One page pod 2ml');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES ('5012345000028', ?, 1)", [$pod]);
        $siPod = (new SupplierItems(self::$db))->create(Caller::staff($bid), (int) $s['id'], $pod, ['units_per_pack' => '10', 'supplier_code' => 'OP-10'], ['pack_price' => '12.50']);
        $cherry = self::makeSku('One page liquid cherry');
        self::makeSku('One page liquid cherry ice');
        $siCherry = (new SupplierItems(self::$db))->create(Caller::staff($bid), (int) $s['id'], $cherry, ['units_per_pack' => '6', 'supplier_code' => 'OC-6'], ['pack_price' => '9.00']);
        $web = $this->signIn($buyerUser);
        $form = self::newForm($web->get('/ui/purchasing/orders/new'));
        $sid = (string) $s['id'];

        // No supplier: refused in words, what was typed kept, nothing made.
        $r = $web->post('/ui/purchasing/orders', ['q' => '5012345000028', 'action' => 'add', 'external_ref' => 'Q-9'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::PO_ONE['need_supplier'], $r->text());
        self::assertSame(['5012345000028', 'Q-9'], [self::newForm($r)['q'], self::newForm($r)['external_ref']]);
        // Nothing to add: refused, nothing made.
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => $sid, 'action' => 'add'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::PO_ONE['need_product'], $r->text());
        self::assertSame($sid, self::newForm($r)['supplier_id'], 'the supplier stays chosen');
        // Not found: nothing made.
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => $sid, 'q' => 'zzzz no such', 'action' => 'add'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('BUY_ERROR', 'not_found', 'zzzz no such'), $r->text());
        self::assertSame(0, self::drafts());
        // Several products: the choices on the same page; nothing made until one is chosen.
        $c = $web->post('/ui/purchasing/orders', ['supplier_id' => $sid, 'q' => 'liquid cherry', 'action' => 'add', 'external_ref' => 'Q-1'] + $form);
        self::assertSame(200, $c->status, $c->describe());
        self::assertStringContainsString(Words::say('ORDER', 'choose', 'liquid cherry'), $c->text());
        self::assertSame(0, self::drafts());
        $choice = $web->post('/ui/purchasing/orders', ['add_si' => (string) $siCherry['id'], 'packs' => '2'] + self::newForm($c));
        self::assertSame(303, $choice->status, $choice->describe());
        $first = self::orderId($choice->location());
        self::assertStringContainsString('notice=added', (string) $choice->location());
        self::assertSame(1, self::drafts());
        self::assertSame(['Q-1', $bid], [self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$first]),
            (int) self::$db->value('SELECT created_by FROM document WHERE id = ?', [$first])]);
        self::assertSame([[(int) $siCherry['id'], 2]], array_map(static fn (array $l): array => [(int) $l['supplier_item_id'], (int) $l['packs']],
            self::$db->all('SELECT supplier_item_id, packs FROM po_line WHERE document_id = ?', [$first])));

        // The supplier chosen and a product scanned: one draft, however often the form is sent.
        $expected = gmdate('Y-m-d', time() + 14 * 86400);
        $send = ['supplier_id' => $sid, 'q' => '5012345000028', 'packs' => '3', 'action' => 'add', 'expected_date' => $expected] + self::newForm($web->get('/ui/purchasing/orders/new'));
        $a = $web->post('/ui/purchasing/orders', $send);
        self::assertSame(303, $a->status, $a->describe());
        $b = $web->post('/ui/purchasing/orders', $send);
        self::assertSame($a->location(), $b->location(), 'sent twice: the same draft');
        self::assertSame(2, self::drafts());
        $id = self::orderId($a->location());
        self::assertSame([[(int) $siPod['id'], 3]], array_map(static fn (array $l): array => [(int) $l['supplier_item_id'], (int) $l['packs']],
            self::$db->all('SELECT supplier_item_id, packs FROM po_line WHERE document_id = ?', [$id])));
        self::assertSame([$expected, (int) $s['id']], [(string) self::$db->value('SELECT expected_date FROM purchase_order WHERE document_id = ?', [$id]),
            (int) self::$db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$id])]);

        // The draft is the same page, with its products.
        $ed = $web->follow($a);
        self::assertSame(200, $ed->status, $ed->describe());
        self::assertStringContainsString(Words::PO_NOTICE['added'], $ed->text());
        $f = $ed->form('/lines');
        self::assertSame(['csrf', 'version', 'line_count', 'lines_editable'], array_slice(array_keys($f), 0, 4), 'version and line_count come first');
        self::assertSame(['1', '3'], [$f['line_count'], $f['line_1_packs']]);
        // The product box's choice (its hidden fields, filled by the script) adds that product.
        $r = $web->post("/ui/purchasing/orders/{$id}/lines", ['q' => 'One page liquid cherry', 'pick_si' => (string) $siCherry['id'], 'action' => 'add'] + $f);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=added#scan", $r->location(), $r->describe());
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$id]));
        // Each row has its remove (x): that row goes, the others' edits are saved.
        $f = $web->get("/ui/purchasing/orders/{$id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$id}/lines", ['remove_line' => '2', 'line_1_packs' => '4'] + $f);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=removed#scan", $r->location(), $r->describe());
        self::assertSame([[(int) $siPod['id'], 4]], array_map(static fn (array $l): array => [(int) $l['supplier_item_id'], (int) $l['packs']],
            self::$db->all('SELECT supplier_item_id, packs FROM po_line WHERE document_id = ?', [$id])));
        self::assertStringContainsString(Words::PO_NOTICE['removed'], $web->follow($r)->text());
        // The Codex review of a90d22d, finding 3: the row removed is not read (its packs box emptied first).
        $f = $web->get("/ui/purchasing/orders/{$id}")->form('/lines');
        self::assertSame('1', $f['line_count']);
        $web->post("/ui/purchasing/orders/{$id}/lines", ['add_si' => (string) $siCherry['id']] + $f);
        $f = $web->get("/ui/purchasing/orders/{$id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$id}/lines", ['remove_line' => '2', 'line_2_packs' => ''] + $f);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=removed#scan", $r->location(), $r->describe());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$id]));
        // A choice is used only by Add: Save leaves it (and says the scan box was not added on Confirm).
        $f = $web->get("/ui/purchasing/orders/{$id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$id}/lines", ['pick_si' => (string) $siPod['id'], 'action' => 'save'] + $f);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=saved#scan", $r->location(), $r->describe());
        self::assertSame(4, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$id]));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$id]));

        // Save with only the supplier: a draft (the person asked for it).
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => $sid, 'action' => 'save'] + self::newForm($web->get('/ui/purchasing/orders/new')));
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('notice=created', (string) $r->location());
        self::assertSame(3, self::drafts());
        // Confirm on the new page with nothing on it: refused, nothing made.
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => $sid, 'action' => 'approve'] + self::newForm($web->get('/ui/purchasing/orders/new')));
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(3, self::drafts());
    }

    public function testANewSupplierFromTheDropdownIsADraftSupplierPutOnTheOrder(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $known = $this->supplier($buyerUser['id'], 'Known Supplies');
        $web = $this->signIn($buyerUser);
        $page = $web->get('/ui/purchasing/orders/new');
        // "+ Add new supplier" is part of the one form: name, e-mail and phone, and its own button.
        self::assertSame(1, self::nodes($page, '//form[@action="/ui/purchasing/orders"]//details[@id="po-add-supplier"]//button[@name="action"][@value="new_supplier"]'));
        self::assertSame(Words::PO_ADD['new_supplier'], trim((string) (new \DOMXPath($page->dom()))->evaluate('string(//details[@id="po-add-supplier"]/summary)')));
        $form = self::newForm($page);

        // No name: refused in words, what was typed kept, the form open again, nothing made.
        $bad = $web->post('/ui/purchasing/orders', ['new_supplier_email' => 'sales@fresh.example', 'action' => 'new_supplier'] + $form);
        self::assertSame(422, $bad->status, $bad->describe());
        self::assertSame('sales@fresh.example', self::newForm($bad)['new_supplier_email']);
        self::assertSame(1, self::nodes($bad, '//details[@id="po-add-supplier"][@open]'));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM supplier'));

        // With a name: a draft supplier, and the page comes back with it chosen and what was typed kept. No order yet.
        $send = ['new_supplier_name' => 'Fresh Vapes', 'new_supplier_phone' => '0161 000 0000', 'external_ref' => 'Q-1', 'action' => 'new_supplier'] + self::newForm($bad);
        $r = $web->post('/ui/purchasing/orders', $send);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(1, preg_match('#^/ui/purchasing/orders/new\?supplier=(\d+)&#', (string) $r->location(), $m), (string) $r->location());
        self::assertStringContainsString('external_ref=Q-1', (string) $r->location());
        self::assertStringContainsString('notice=supplier_added', (string) $r->location());
        self::assertSame($r->location(), $web->post('/ui/purchasing/orders', $send)->location(), 'sent twice: one supplier');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM supplier'));
        self::assertSame(0, self::drafts(), 'a new supplier alone makes no order');
        $sid = (int) $m[1];
        $s = (array) self::$db->one('SELECT name, status, phone FROM supplier WHERE id = ?', [$sid]);
        self::assertSame(['Fresh Vapes', 'draft', '0161 000 0000'], [$s['name'], $s['status'], $s['phone']]);
        $back = $web->follow($r);
        self::assertSame(200, $back->status, $back->describe());
        self::assertStringContainsString(Words::PO_NOTICE['supplier_added'], $back->text());
        self::assertSame([(string) $sid, 'Q-1'], [self::newForm($back)['supplier_id'], self::newForm($back)['external_ref']]);

        // The first product makes the draft for it; Confirm is refused in plain words while the supplier is not approved.
        $sku = self::makeSku('Fresh pod');
        $r = $web->post('/ui/purchasing/orders', ['q' => sprintf('CW-%06d', $sku), 'action' => 'add'] + self::newForm($back));
        self::assertSame(303, $r->status, $r->describe());
        $id = self::orderId($r->location());
        self::assertSame([$sid, 'Q-1'], [(int) self::$db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$id]),
            self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$id])]);
        $ed = $web->follow($r);
        self::assertStringContainsString(Words::say('PO_ONE', 'not_approved', 'Fresh Vapes'), $ed->text());
        self::assertContains('/ui/purchasing/suppliers/' . $sid, $ed->hrefs());
        $ok = $web->post("/ui/purchasing/orders/{$id}/lines", ['action' => 'approve'] + $ed->form('/lines'));
        self::assertSame(422, $ok->status, $ok->describe());
        self::assertStringContainsString(Words::BUY_ERROR['supplier_not_active'], $ok->text());
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));

        // A draft without products: the new supplier goes on it (at its version), with the order's details typed.
        $d = $this->pos()->createDraft(Caller::staff($buyerUser['id']), (int) $known['id'], []);
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        self::assertArrayHasKey('new_supplier_name', $f);
        $expected = gmdate('Y-m-d', time() + 10 * 86400);
        $send = ['new_supplier_name' => 'Second Choice Ltd', 'expected_date' => $expected, 'action' => 'new_supplier'] + $f;
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", $send);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=supplier_added", $r->location(), $r->describe());
        self::assertSame($r->location(), $web->post("/ui/purchasing/orders/{$d->id}/lines", $send)->location(), 'sent twice: one supplier');
        $second = (int) self::$db->value("SELECT id FROM supplier WHERE name = 'Second Choice Ltd'");
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Second Choice Ltd'"));
        self::assertSame([$second, $expected], [(int) self::$db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$d->id]),
            (string) self::$db->value('SELECT expected_date FROM purchase_order WHERE document_id = ?', [$d->id])]);
        // The same form with another key and the old version: refused, nothing made.
        $stale = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['form_key' => str_repeat('c', 32), 'new_supplier_name' => 'Stale Ltd'] + $send);
        self::assertSame(409, $stale->status, $stale->describe());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Stale Ltd'"));
        // Codex review of bd26fc3, finding 4: a name left in the supplier's form never makes a supplier: only its own button does. Add with the
        // scan box empty saves the draft, as before.
        $left = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['new_supplier_name' => 'Left Behind Ltd', 'action' => 'add'] + $left);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=saved#scan", $r->location(), $r->describe());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Left Behind Ltd'"));
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['new_supplier_name' => 'Third Choice Ltd', 'action' => 'new_supplier']
            + $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines'));
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=supplier_added", $r->location(), $r->describe());
        self::assertSame((int) self::$db->value("SELECT id FROM supplier WHERE name = 'Third Choice Ltd'"),
            (int) self::$db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$d->id]));
        // On the new page too: Add with nothing to add is refused, and the name left there makes nothing.
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => (string) $known['id'], 'new_supplier_name' => 'Left Behind Ltd', 'action' => 'add']
            + self::newForm($web->get('/ui/purchasing/orders/new')));
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::PO_ONE['need_product'], $r->text());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Left Behind Ltd'"));

        // With a product on it the supplier is fixed: no box, no "+ Add new supplier".
        $this->pos()->saveDraft(Caller::staff($buyerUser['id']), $d->id, (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$d->id]), [],
            [['sku_id' => self::makeSku('Fixed supplier pod'), 'packs' => 1]]);
        $ed = $web->get("/ui/purchasing/orders/{$d->id}");
        $f = $ed->form('/lines');
        self::assertArrayNotHasKey('supplier_id', $f);
        self::assertArrayNotHasKey('new_supplier_name', $f);
        $third = (int) self::$db->value("SELECT id FROM supplier WHERE name = 'Third Choice Ltd'");
        self::assertSame('Third Choice Ltd', trim((string) (new \DOMXPath($ed->dom()))->evaluate('string(//form[contains(@action, "/lines")]//p[@class="po-fixed"]/a[@href="/ui/purchasing/suppliers/'
            . $third . '"])')), 'the supplier named, with its page');
    }

    public function testANewItemFromTheDropdownFollowsPackBsRules(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier($pmUser['id'], 'Item Supplies');
        $web = $this->signIn($pmUser);
        $page = $web->get('/ui/purchasing/orders/new');
        self::assertSame(1, self::nodes($page, '//form[@action="/ui/purchasing/orders"]//details[@id="po-add-item"]//button[@name="action"][@value="new_item"]'));
        self::assertSame(Words::PO_ADD['new_item'], trim((string) (new \DOMXPath($page->dom()))->evaluate('string(//details[@id="po-add-item"]/summary)')));

        // On the new page: the item, its supply with this supplier, the line and the draft, in one go (once however often it is sent).
        $send = ['supplier_id' => (string) $s['id'], 'packs' => '3', 'item_name' => 'Mystery Pod 2ml', 'item_brand' => 'Mystery', 'item_barcode' => '5060000000023',
            'item_code' => 'MYS-6', 'item_upp' => '6', 'item_price' => '9.00', 'action' => 'new_item'] + self::newForm($page);
        $r = $web->post('/ui/purchasing/orders', $send);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringEndsWith('?notice=item_added#scan', (string) $r->location());
        self::assertSame($r->location(), $web->post('/ui/purchasing/orders', $send)->location(), 'sent twice: one item');
        $id = self::orderId($r->location());
        self::assertSame([1, 1], [(int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Mystery Pod 2ml'"), self::drafts()]);
        $sku = (int) self::$db->value("SELECT id FROM sku WHERE name = 'Mystery Pod 2ml'");
        self::assertSame([['MYS-6', 3, 6, '9.0000']], array_map(static fn (array $l): array => [$l['supplier_code'], (int) $l['packs'], (int) $l['units_per_pack'], $l['pack_price']],
            self::$db->all('SELECT supplier_code, packs, units_per_pack, pack_price FROM po_line WHERE document_id = ?', [$id])));
        $ed = $web->follow($r);
        self::assertStringContainsString(Words::PO_NOTICE['item_added'], $ed->text());

        // A barcode on another item: refused, nothing saved (not even the table), and that item is offered in the page (one press adds it).
        $f = $ed->form('/lines');
        $bad = $web->post("/ui/purchasing/orders/{$id}/lines", ['item_name' => 'Copy pod', 'item_barcode' => '5060000000023', 'line_1_packs' => '4', 'action' => 'new_item'] + $f);
        self::assertSame(409, $bad->status, $bad->describe());
        self::assertStringContainsString(Words::ITEMS_ERROR['barcode_on_other_item'], $bad->text());
        self::assertSame(1, self::nodes($bad, '//details[@id="po-add-item"][@open]'));
        self::assertSame(['Copy pod', '4'], [$bad->form('/lines')['item_name'], $bad->form('/lines')['line_1_packs']], 'what was typed is kept');
        self::assertSame(3, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$id]));
        self::assertSame(1, self::nodes($bad, '//form[contains(@action, "/lines")]//details[@id="po-add-item"]//button[@name="add_sku"][@value="' . $sku . '"]'));
        $use = $web->post("/ui/purchasing/orders/{$id}/lines", ['add_sku' => (string) $sku] + $bad->form('/lines'));
        self::assertSame("/ui/purchasing/orders/{$id}?notice=incremented#scan", $use->location(), $use->describe());
        self::assertSame(5, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$id]), 'the typed 4, then one more pack');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Copy pod'"), 'no copy made');

        // The same name: the item that is there is shown, with "it is a different item" (never ticked for the person).
        $f = $web->get("/ui/purchasing/orders/{$id}")->form('/lines');
        $same = $web->post("/ui/purchasing/orders/{$id}/lines", ['item_name' => 'Mystery Pod 2ml', 'item_brand' => 'Mystery', 'external_ref' => 'Q-7', 'action' => 'new_item'] + $f);
        self::assertSame(409, $same->status, $same->describe());
        self::assertStringContainsString(Words::PO_ADD['same_title'], $same->text());
        $again = $same->form('/lines');
        self::assertArrayNotHasKey('item_anyway', $again);
        self::assertSame(1, self::nodes($same, '//details[@id="po-add-item"]//input[@type="checkbox"][@name="item_anyway"]'));
        $made = $web->post("/ui/purchasing/orders/{$id}/lines", ['item_anyway' => '1', 'action' => 'new_item'] + $again); // its own button (finding 4)
        self::assertSame("/ui/purchasing/orders/{$id}?notice=item_added#scan", $made->location(), $made->describe());
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Mystery Pod 2ml'"));
        self::assertSame('Q-7', self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$id]), 'the table\'s edits are saved with it');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$id]));
    }

    /**
     * Codex review of bd26fc3, finding 3: a draft's "+ Add new item" sent twice (a double click, a retry after a lost answer) is done once
     * and lands where the first did: the draft's lines are read and checked inside FormOnce, so a replay is not refused by them.
     */
    public function testAnAddNewOnADraftSentTwiceIsDoneOnce(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier($pmUser['id'], 'Twice Supplies');
        $d = $this->pos()->createDraft(Caller::staff($pmUser['id']), (int) $s['id'], []);
        $this->pos()->saveDraft(Caller::staff($pmUser['id']), $d->id, (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$d->id]), [],
            [['sku_id' => self::makeSku('Twice pod'), 'packs' => 1]]);
        $web = $this->signIn($pmUser);
        $send = ['item_name' => 'Twice new pod', 'item_code' => 'TW-1', 'action' => 'new_item'] + $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $a = $web->post("/ui/purchasing/orders/{$d->id}/lines", $send);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=item_added#scan", $a->location(), $a->describe());
        $b = $web->post("/ui/purchasing/orders/{$d->id}/lines", $send);
        self::assertSame([303, $a->location()], [$b->status, $b->location()], 'the same form again: the same answer, ' . $b->describe());
        self::assertSame([1, 2], [(int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Twice new pod'"),
            (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$d->id])]);
    }

    /**
     * Codex review of bd26fc3, finding 1 (the server's side): two packs of one product are two choices whose words differ (the box compares
     * its words), and the one chosen is the one added.
     */
    public function testTwoPacksOfOneProductAreTwoChoicesTheBoxTellsApart(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $s = $this->supplier($buyerUser['id'], 'Packs Supplies');
        $sku = self::makeSku('Packs pod');
        $si6 = (new SupplierItems(self::$db))->create(Caller::staff($buyerUser['id']), (int) $s['id'], $sku, ['units_per_pack' => '6', 'supplier_code' => 'PK-6', 'is_preferred' => '0'], ['pack_price' => '6.00']);
        $si24 = (new SupplierItems(self::$db))->create(Caller::staff($buyerUser['id']), (int) $s['id'], $sku, ['units_per_pack' => '24', 'supplier_code' => 'PK-24', 'is_preferred' => '0'], ['pack_price' => '20.00']);
        $web = $this->signIn($buyerUser);
        $r = $web->get('/ui/purchasing/orders/products', ['supplier' => $s['id'], 'q' => sprintf('CW-%06d', $sku)]);
        $xp = new \DOMXPath($r->dom());
        $rows = [];
        foreach ($xp->query('//ul[@data-po-choices]/li') ?: [] as $li) {
            /** @var \DOMElement $li */
            $rows[$li->getAttribute('data-si')] = $li->getAttribute('data-label');
        }
        self::assertSame([(string) $si6['id'], (string) $si24['id']], array_map('strval', array_keys($rows)), 'both packs offered');
        self::assertCount(2, array_unique(array_values($rows)), 'their words differ: ' . implode(' | ', $rows));
        $form = ['supplier_id' => (string) $s['id'], 'q' => $rows[(string) $si6['id']], 'pick_si' => (string) $si6['id'], 'action' => 'add']
            + self::newForm($web->get('/ui/purchasing/orders/new'));
        $add = $web->post('/ui/purchasing/orders', $form);
        self::assertSame(303, $add->status, $add->describe());
        self::assertSame([(int) $si6['id']], array_map('intval', self::$db->column('SELECT supplier_item_id FROM po_line WHERE document_id = ?', [self::orderId($add->location())])));
    }

    /**
     * Codex review of bd26fc3, finding 5: a refusal keeps the product chosen in the box (its words, its hidden fields and the choice itself in
     * the box's list), so the corrected form adds that product; a choice of another supplier's product is not kept.
     */
    public function testARefusalKeepsTheProductChosenInTheBox(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $s = $this->supplier($buyerUser['id'], 'Kept Supplies');
        $pod = self::makeSku('Kept pod');
        $cherry = self::makeSku('Kept cherry');
        $siPod = (new SupplierItems(self::$db))->create(Caller::staff($buyerUser['id']), (int) $s['id'], $pod, ['units_per_pack' => '1', 'supplier_code' => 'KP-1'], ['pack_price' => '2.00']);
        $siCherry = (new SupplierItems(self::$db))->create(Caller::staff($buyerUser['id']), (int) $s['id'], $cherry, ['units_per_pack' => '6', 'supplier_code' => 'KC-6', 'purchase_unit' => 'case'], ['pack_price' => '9.00']);
        $d = $this->pos()->createDraft(Caller::staff($buyerUser['id']), (int) $s['id'], []);
        $this->pos()->saveDraft(Caller::staff($buyerUser['id']), $d->id, (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$d->id]), [],
            [['supplier_item_id' => (int) $siPod['id'], 'packs' => 1]]);
        $web = $this->signIn($buyerUser);
        $label = PurchaseOrdersControllerAlias::productChoice(['sku_id' => $cherry, 'sku_code' => sprintf('CW-%06d', $cherry), 'name' => 'Kept cherry', 'brand' => null,
            'supplier_item_id' => (int) $siCherry['id'], 'units_per_pack' => 6, 'supplier_code' => 'KC-6', 'purchase_unit' => 'case'])['label'];
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $bad = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['q' => $label, 'pick_si' => (string) $siCherry['id'], 'line_1_price' => 'lots', 'action' => 'add'] + $f);
        self::assertSame(422, $bad->status, $bad->describe());
        $again = $bad->form('/lines');
        self::assertSame([$label, (string) $siCherry['id'], ''], [$again['q'], $again['pick_si'], $again['pick_sku']], 'the choice is kept');
        self::assertSame(1, self::nodes($bad, '//ul[@data-po-list]/li[@data-label="' . $label . '"][@data-si="' . $siCherry['id'] . '"]'), 'and drawn in the box\'s list');
        $ok = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['line_1_price' => '2.50'] + $again);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=added#scan", $ok->location(), $ok->describe());
        self::assertSame([(int) $siPod['id'], (int) $siCherry['id']], array_map('intval', self::$db->column('SELECT supplier_item_id FROM po_line WHERE document_id = ? ORDER BY line_no', [$d->id])));
        // Words that are not the choice's any more: no choice kept.
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $bad = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['q' => 'Kept che', 'pick_si' => (string) $siCherry['id'], 'line_1_price' => 'lots', 'action' => 'add'] + $f);
        self::assertSame(['', ''], [$bad->form('/lines')['pick_si'], $bad->form('/lines')['pick_sku']]);
        self::assertSame(0, self::nodes($bad, '//ul[@data-po-list]/li'));
    }

    /**
     * Codex review of bd26fc3, finding 6: "+ Add new supplier" keeps a new item typed before it: the page comes back with it in its form,
     * open.
     */
    public function testANewSupplierKeepsTheNewItemTypedBeforeIt(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $web = $this->signIn($pmUser);
        $item = ['item_name' => 'Waiting pod 2ml', 'item_brand' => 'Waiting', 'item_barcode' => '5060000000047', 'item_code' => 'WT-6', 'item_upp' => '6', 'item_price' => '7.50'];
        $r = $web->post('/ui/purchasing/orders', $item + ['action' => 'new_item'] + self::newForm($web->get('/ui/purchasing/orders/new')));
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::PO_ONE['need_supplier'], $r->text());
        self::assertSame(1, self::nodes($r, '//details[@id="po-add-item"][@open]'));
        $add = $web->post('/ui/purchasing/orders', ['new_supplier_name' => 'Fresh Waiting Ltd', 'action' => 'new_supplier'] + self::newForm($r));
        self::assertSame(303, $add->status, $add->describe());
        $back = $web->follow($add);
        self::assertSame(1, self::nodes($back, '//details[@id="po-add-item"][@open]'), 'the new item\'s form open again');
        $f = self::newForm($back);
        foreach ($item as $k => $v) {
            self::assertSame($v, $f[$k] ?? null, $k);
        }
        self::assertSame(0, self::drafts());

        // The Codex review of a90d22d, finding 1: the same on a draft without products: the new item typed goes with the new supplier, and
        // its form opens again with it (with the scan box's words and packs).
        $s = $this->supplier($pmUser['id'], 'Draft Waiting Supplies');
        $d = $this->pos()->createDraft(Caller::staff($pmUser['id']), (int) $s['id'], []);
        $send = $item + ['q' => 'waiting pod', 'packs' => '4', 'new_supplier_name' => 'Draft Fresh Ltd', 'action' => 'new_supplier']
            + $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $add = $web->post("/ui/purchasing/orders/{$d->id}/lines", $send);
        self::assertSame(303, $add->status, $add->describe());
        self::assertStringStartsWith("/ui/purchasing/orders/{$d->id}?notice=supplier_added", (string) $add->location());
        self::assertSame($add->location(), $web->post("/ui/purchasing/orders/{$d->id}/lines", $send)->location(), 'sent twice: the same answer');
        $back = $web->follow($add);
        self::assertSame(1, self::nodes($back, '//details[@id="po-add-item"][@open]'), 'the new item\'s form open again on the draft');
        $f = $back->form('/lines');
        foreach ($item + ['q' => 'waiting pod', 'packs' => '4'] as $k => $v) {
            self::assertSame($v, $f[$k] ?? null, "draft: {$k}");
        }
        self::assertSame((int) self::$db->value("SELECT id FROM supplier WHERE name = 'Draft Fresh Ltd'"), (int) self::$db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$d->id]));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Waiting pod 2ml'"), 'nothing made but the supplier');
    }

    /**
     * The Codex review of a90d22d, finding 2: packs that are not a whole number from 1 (2.5, 0, words) are refused in words for "+ Add new
     * item" and for Add, and nothing is made (an empty packs box is one pack, as before).
     */
    /**
     * The Codex review of 0917562, finding 1: the product chosen in the box goes through "+ Add new supplier" as its item (the box's words
     * become the item's own: a supply of another supplier is not the new one's), on the new page and on a draft, and Add then adds it.
     */
    public function testTheProductChosenGoesThroughANewSupplier(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $old = $this->supplier($buyerUser['id'], 'Old Pick Supplies');
        $pod = self::makeSku('Blue Pick Pod');
        $siOld = (new SupplierItems(self::$db))->create(Caller::staff($buyerUser['id']), (int) $old['id'], $pod, ['units_per_pack' => '10', 'supplier_code' => 'BP-10']);
        $web = $this->signIn($buyerUser);
        $plain = PurchaseOrdersControllerAlias::productChoice(['sku_id' => $pod, 'sku_code' => sprintf('CW-%06d', $pod), 'name' => 'Blue Pick Pod', 'brand' => null,
            'supplier_item_id' => null, 'units_per_pack' => 1, 'supplier_code' => null, 'purchase_unit' => 'each'])['label'];
        // New page, no supplier yet: the item chosen (no supply shown), then a new supplier.
        $r = $web->post('/ui/purchasing/orders', ['q' => $plain, 'pick_sku' => (string) $pod, 'new_supplier_name' => 'New Pick Ltd', 'action' => 'new_supplier']
            + self::newForm($web->get('/ui/purchasing/orders/new')));
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('pick_sku=' . $pod, (string) $r->location());
        $back = $web->follow($r);
        $f = self::newForm($back);
        self::assertSame([$plain, (string) $pod, ''], [$f['q'], $f['pick_sku'], $f['pick_si']]);
        $add = $web->post('/ui/purchasing/orders', ['action' => 'add'] + $f);
        self::assertSame(303, $add->status, $add->describe());
        self::assertSame([[$pod, null]], array_map(static fn (array $l): array => [(int) $l['sku_id'], $l['supplier_item_id'] === null ? null : (int) $l['supplier_item_id']],
            self::$db->all('SELECT dl.sku_id, pl.supplier_item_id FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no '
            . 'WHERE pl.document_id = ?', [self::orderId($add->location())])), 'the item, with no supply of the new supplier yet');
        // A draft without products: a supply of its supplier chosen, then a new supplier: the item goes through, in its own words.
        $d = $this->pos()->createDraft(Caller::staff($buyerUser['id']), (int) $old['id'], []);
        $withPack = PurchaseOrdersControllerAlias::productChoice(['sku_id' => $pod, 'sku_code' => sprintf('CW-%06d', $pod), 'name' => 'Blue Pick Pod', 'brand' => null,
            'supplier_item_id' => (int) $siOld['id'], 'units_per_pack' => 10, 'supplier_code' => 'BP-10', 'purchase_unit' => 'each'])['label'];
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['q' => $withPack, 'pick_si' => (string) $siOld['id'], 'new_supplier_name' => 'Draft Pick Ltd',
            'action' => 'new_supplier'] + $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines'));
        self::assertSame(303, $r->status, $r->describe());
        $f = $web->follow($r)->form('/lines');
        self::assertSame([$plain, (string) $pod, ''], [$f['q'], $f['pick_sku'], $f['pick_si']], 'the item in its own words, not the old supplier\'s supply');
        $add = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['action' => 'add'] + $f);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=added#scan", $add->location(), $add->describe());
        self::assertSame([$pod], array_map('intval', self::$db->column('SELECT dl.sku_id FROM document_line dl WHERE dl.document_id = ?', [$d->id])));
    }

    /**
     * The Codex review of 0917562, finding 2: on a draft, an Add that finds nothing or several products keeps the "+ Add new" forms as typed
     * (nothing of them was saved), and not what was saved with the table (the delivery charge is on the order now: typed again it would be
     * added twice).
     */
    public function testAnAddThatFindsNothingOrSeveralKeepsTheAddNewFormsAsTyped(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier($pmUser['id'], 'Kept Forms Supplies');
        self::makeSku('Kept forms liquid cherry');
        self::makeSku('Kept forms liquid mango');
        $d = $this->pos()->createDraft(Caller::staff($pmUser['id']), (int) $s['id'], []);
        $web = $this->signIn($pmUser);
        $item = ['item_name' => 'Kept new pod', 'item_barcode' => '5060000000054', 'item_code' => 'KN-1', 'item_price' => '3.00'];
        $supplier = ['new_supplier_name' => 'Kept Fresh Ltd', 'new_supplier_phone' => '0161 222 2222'];
        // Nothing found: both forms come back as typed (the draft has no products, so its supplier can still change).
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", $item + $supplier + ['q' => 'zzzz no such', 'action' => 'add'] + $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines'));
        self::assertSame(422, $r->status, $r->describe());
        $f = $r->form('/lines');
        foreach ($item + $supplier as $k => $v) {
            self::assertSame($v, $f[$k] ?? null, "nothing found: {$k} kept");
        }
        self::assertSame(1, self::nodes($r, '//details[@id="po-add-item"][@open]'), 'the new item\'s form open');
        // Several found, with a delivery charge typed: the charge is saved (and is on the order now, so not typed again), the new item kept.
        foreach (['kept forms liquid' => 200, 'zzzz none again' => 422] as $q => $status) {
            $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", $item + ['q' => $q, 'charge_amount' => '5.00', 'action' => 'add']
                + $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines'));
            self::assertSame($status, $r->status, "{$q}: " . $r->describe());
            $f = $r->form('/lines');
            foreach ($item as $k => $v) {
                self::assertSame($v, $f[$k] ?? null, "{$q}: {$k} kept");
            }
            self::assertSame('', $f['charge_amount'] ?? null, "{$q}: the charge saved is not typed again");
        }
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM po_line WHERE document_id = ? AND kind = 'charge'", [$d->id]), 'one charge a send, never one more');
    }

    /**
     * The owner, 26 Oct 2026: "yes add cancel draft button" (PX11). A saved draft's page has one small plain "Cancel draft" next to Save and
     * Confirm, opening one short step on the same page (a reason, an optional note, "Cancel the draft"): the existing cancel route, its reason
     * list (none chosen for the person), its checks (CSRF, doc.PO.post, the version) and its audit. The new page, with nothing saved, has none.
     */
    public function testADraftIsCancelledFromItsPage(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $s = $this->supplier($buyerUser['id'], 'Cancel Draft Supplies');
        $d = $this->pos()->createDraft(Caller::staff($buyerUser['id']), (int) $s['id'], []);
        $d = $this->pos()->saveDraft(Caller::staff($buyerUser['id']), $d->id, $d->version, [], [['sku_id' => self::makeSku('Cancel pod'), 'packs' => 2]]);
        $web = $this->signIn($buyerUser);
        $new = $web->get('/ui/purchasing/orders/new');
        self::assertFalse($new->hasForm('/cancel'), 'nothing to cancel on the new page');
        self::assertSame(0, self::nodes($new, '//details[contains(@class, "po-cancel")]'));

        $ed = $web->get("/ui/purchasing/orders/{$d->id}");
        $xp = new \DOMXPath($ed->dom());
        // In the summary card, beside Save and Confirm: a small plain control, never the primary one; its step holds the reason and the button.
        $summary = $xp->query('//form[contains(@action, "/lines")]//section[contains(@class, "po-summary")]//details[contains(@class, "po-cancel")][not(@open)]/summary');
        self::assertSame(1, $summary->length);
        self::assertSame(Words::PO_ONE['cancel'], trim((string) $summary->item(0)?->textContent));
        self::assertStringNotContainsString('primary', (string) $summary->item(0)?->attributes?->getNamedItem('class')?->nodeValue);
        self::assertSame(1, $xp->query('//details[contains(@class, "po-cancel")]//button[@type="submit"][@form="po-cancel"][not(contains(@class, "primary"))]')->length);
        self::assertSame(Words::ORDER['choose_reason'], trim((string) $xp->evaluate('string(//select[@name="reason_code"][@form="po-cancel"]/option[1])')));
        $c = $ed->form('/cancel');
        self::assertEqualsCanonicalizing(['csrf', 'version', 'note', 'reason_code'], array_keys($c), 'its own form, not the order\'s');
        self::assertSame('', $c['reason_code'], 'no reason chosen for the person');
        self::assertArrayNotHasKey('reason_code', $ed->form('/lines'));
        self::assertSame('', $ed->form('/lines')['note'] ?? null, 'the order\'s note to the supplier is still the order\'s own field');

        // No token, a wrong token: refused; who does not order: refused; nothing cancelled.
        $no = ['reason_code' => 'not_needed'] + $c;
        unset($no['csrf']);
        self::assertSame(403, $web->post("/ui/purchasing/orders/{$d->id}/cancel", $no)->status);
        self::assertSame(403, $web->post("/ui/purchasing/orders/{$d->id}/cancel", ['csrf' => 'x', 'reason_code' => 'not_needed'] + $c)->status);
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        self::assertSame(403, $desk->post("/ui/purchasing/orders/{$d->id}/cancel", ['csrf' => $this->token($desk), 'version' => $c['version'], 'reason_code' => 'not_needed'])->status);
        // No reason: refused, the step open again, nothing cancelled.
        $bad = $web->post("/ui/purchasing/orders/{$d->id}/cancel", $c);
        self::assertSame(400, $bad->status, $bad->describe());
        self::assertSame(1, self::nodes($bad, '//details[contains(@class, "po-cancel")][@open]'));
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$d->id]));
        // An old version: refused.
        self::assertSame(409, $web->post("/ui/purchasing/orders/{$d->id}/cancel", ['reason_code' => 'not_needed', 'version' => (string) ((int) $c['version'] - 1)] + $c)->status);
        // A reason: cancelled, audited, and the page says so.
        $ok = $web->post("/ui/purchasing/orders/{$d->id}/cancel", ['reason_code' => 'not_needed'] + $c);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=cancelled", $ok->location(), $ok->describe());
        self::assertSame('cancelled', self::$db->value('SELECT status FROM document WHERE id = ?', [$d->id]));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'document.cancel' AND entity_id = ?", [(string) $d->id]));
        self::assertStringContainsString(Words::PO_NOTICE['cancelled'], $web->follow($ok)->text());
    }

    public function testPacksThatAreNotAWholeNumberAreRefusedAndNothingIsMade(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier($pmUser['id'], 'Packs Check Supplies');
        $web = $this->signIn($pmUser);
        $refusal = Words::say('PO_ADD', 'bad_number', Words::PO_ADD['item_packs']);
        foreach (['2.5', '0', 'two', '-1', '1000001'] as $packs) {
            $r = $web->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id'], 'packs' => $packs, 'item_name' => 'Half pod', 'action' => 'new_item']
                + self::newForm($web->get('/ui/purchasing/orders/new')));
            self::assertSame(422, $r->status, "new page, packs {$packs}: " . $r->describe());
            self::assertStringContainsString($refusal, $r->text(), $packs);
        }
        self::assertSame([0, 0], [(int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Half pod'"), self::drafts()]);
        $d = $this->pos()->createDraft(Caller::staff($pmUser['id']), (int) $s['id'], []);
        $sku = self::makeSku('Packs check pod');
        foreach ([['packs' => '2.5', 'item_name' => 'Half pod', 'action' => 'new_item'], ['packs' => '0', 'q' => sprintf('CW-%06d', $sku), 'action' => 'add']] as $send) {
            $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", $send + $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines'));
            self::assertSame(422, $r->status, "draft, {$send['action']}: " . $r->describe());
            self::assertStringContainsString($refusal, $r->text(), $send['action']);
            self::assertSame($send['packs'], $r->form('/lines')['packs'], 'what was typed is kept');
        }
        self::assertSame([0, 0], [(int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Half pod'"), (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$d->id])]);
        // An empty packs box is one pack.
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['packs' => '', 'q' => sprintf('CW-%06d', $sku), 'action' => 'add'] + $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines'));
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=added#scan", $r->location(), $r->describe());
        self::assertSame(1, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ?', [$d->id]));
    }

    public function testOnlyWhoMayAddSeesTheAddNewLineTheOthersAreToldWhoToAsk(): void
    {
        self::assertSame('Ask a buyer to add it.', Words::askToAdd('suppliers.manage'));
        self::assertSame('Ask a matching lead to add it.', Words::askToAdd('items.create'));
        $buyerUser = $this->uiUser('buyer');
        $s = $this->supplier($buyerUser['id'], 'Ask Supplies');
        $web = $this->signIn($buyerUser);

        // A buyer adds suppliers but not items: no "+ Add new item", one short line instead; the action sent anyway is refused.
        $page = $web->get('/ui/purchasing/orders/new');
        self::assertSame(1, self::nodes($page, '//details[@id="po-add-supplier"]'));
        self::assertSame(0, self::nodes($page, '//details[@id="po-add-item"]') + self::nodes($page, '//button[@value="new_item"]'));
        self::assertStringContainsString(Words::askToAdd('items.create'), $page->text());
        self::assertSame(Words::askToAdd('items.create'), (string) (new \DOMXPath($page->dom()))->evaluate('string(//input[@name="q"]/@data-ask)'), 'the line the dropdown ends with');
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id'], 'item_name' => 'Sneaky pod', 'action' => 'new_item'] + self::newForm($page));
        self::assertSame(403, $r->status, $r->describe());
        $d = $this->pos()->createDraft(Caller::staff($buyerUser['id']), (int) $s['id'], []);
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['item_name' => 'Sneaky pod', 'action' => 'new_item'] + $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines'));
        self::assertSame(403, $r->status, $r->describe());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Sneaky pod'"));
        self::assertSame(1, self::drafts());

        // Without suppliers.manage (changed on the Roles and permissions page): no "+ Add new supplier", the line instead; refused if sent.
        $this->suppliersManage = (string) self::$db->value("SELECT roles FROM role_permission WHERE permission = 'suppliers.manage'");
        self::$db->exec("UPDATE role_permission SET roles = 'purchasing_manager' WHERE permission = 'suppliers.manage'");
        $page = $web->get('/ui/purchasing/orders/new');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(0, self::nodes($page, '//details[@id="po-add-supplier"]') + self::nodes($page, '//button[@value="new_supplier"]'));
        self::assertSame('Ask a purchasing manager to add it.', Words::askToAdd('suppliers.manage'), 'the map in force');
        self::assertStringContainsString(Words::askToAdd('suppliers.manage'), $page->text());
        self::assertSame(Words::askToAdd('suppliers.manage'), (string) (new \DOMXPath($page->dom()))->evaluate('string(//select[@name="supplier_id"]/@data-ask)'));
        $r = $web->post('/ui/purchasing/orders', ['new_supplier_name' => 'Nope Ltd', 'action' => 'new_supplier'] + self::newForm($page));
        self::assertSame(403, $r->status, $r->describe());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Nope Ltd'"));
    }

    public function testEveryPostOfThePageNeedsItsCsrfToken(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier($pmUser['id'], 'Token Supplies');
        $web = $this->signIn($pmUser);
        $form = ['supplier_id' => (string) $s['id']] + self::newForm($web->get('/ui/purchasing/orders/new'));
        $d = $this->pos()->createDraft(Caller::staff($pmUser['id']), (int) $s['id'], []);
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $sends = [['action' => 'add', 'q' => 'Token pod'], ['action' => 'save'], ['action' => 'new_supplier', 'new_supplier_name' => 'Token Ltd'],
            ['action' => 'new_item', 'item_name' => 'Token pod']];
        foreach ($sends as $send) {
            foreach (['/ui/purchasing/orders' => $form, "/ui/purchasing/orders/{$d->id}/lines" => $f] as $path => $fields) {
                $no = $send + $fields;
                unset($no['csrf']);
                self::assertSame(403, $web->post($path, $no)->status, "{$path} {$send['action']}: no token");
                self::assertSame(403, $web->post($path, ['csrf' => 'x'] + $send + $fields)->status, "{$path} {$send['action']}: a wrong token");
            }
        }
        self::assertSame(1, self::drafts());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Token Ltd'") + (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Token pod'"));
        self::assertSame((int) $d->version, (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$d->id]), 'the draft unchanged');
    }

    public function testWithoutTheScriptThePageIsPlainFormsAndTheBoxesAreReady(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier($pmUser['id'], 'Plain Supplies');
        $web = $this->signIn($pmUser);
        $page = $web->get('/ui/purchasing/orders/new');
        $one = '//form[@action="/ui/purchasing/orders"][@method="post"]';
        // The supplier: a plain select (the script makes it a box with its own list, from these options and these words).
        self::assertSame(1, self::nodes($page, $one . '//select[@name="supplier_id"][@id="po-supplier"][@data-po-supplier]'));
        self::assertSame(1, self::nodes($page, $one . '//select[@name="supplier_id"]/option[@value="' . $s['id'] . '"]'));
        self::assertSame(Words::PO_ADD['new_supplier'], (string) (new \DOMXPath($page->dom()))->evaluate('string(//select[@name="supplier_id"]/@data-add)'));
        self::assertSame('po-add-supplier', (string) (new \DOMXPath($page->dom()))->evaluate('string(//select[@name="supplier_id"]/@data-panel)'));
        // The product: the plain scan box, the list the script draws (empty, hidden), the two hidden fields of a choice.
        self::assertSame(1, self::nodes($page, $one . '//input[@name="q"][@data-po-pick][starts-with(@data-options, "/ui/purchasing/orders/products?")]'));
        self::assertSame(1, self::nodes($page, $one . '//ul[@data-po-list][@role="listbox"][@hidden][not(*)]'));
        self::assertSame(Words::PO_ADD['new_item'], (string) (new \DOMXPath($page->dom()))->evaluate('string(//input[@name="q"]/@data-add)'));
        self::assertSame(2, self::nodes($page, $one . '//input[@type="hidden"][@name="pick_si" or @name="pick_sku"][@value=""]'));
        // The "+ Add new" forms: fields of the one form in a fold that opens by itself, each with its own button.
        foreach (['po-add-supplier' => ['new_supplier_name', 'new_supplier_email', 'new_supplier_phone'], 'po-add-item' => ['item_name', 'item_brand', 'item_barcode',
            'item_code', 'item_upp', 'item_price']] as $panel => $fields) {
            self::assertSame(1, self::nodes($page, $one . "//details[@id='{$panel}'][@data-po-panel][not(@open)]"), $panel);
            foreach ($fields as $field) {
                self::assertSame(1, self::nodes($page, $one . "//details[@id='{$panel}']//input[@name='{$field}'][not(@required)]"), $field);
            }
        }
        self::assertStringNotContainsString('<datalist', $page->body);
        self::assertStringNotContainsString('data-filter-select', $page->body);
        // monday.com's board (the owner): the products are a board whose last row is "+ Add product", the scan box; one summary card with
        // Save and the one primary button, Confirm order; no other fold, no lines file, no cancel form, no help under the fields.
        self::assertSame(1, self::nodes($page, $one . '//table[contains(@class, "board")]//tr[contains(@class, "grp-add")][last()]//input[@name="q"][@placeholder="'
            . Words::PO_ONE['add_product'] . '"]'));
        self::assertSame(2, self::nodes($page, '//main//details'), 'the two "+ Add new" forms only');
        self::assertSame(['approve'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'),
            iterator_to_array((new \DOMXPath($page->dom()))->query($one . '//button[contains(concat(" ", @class, " "), " primary ")]'))));
        self::assertSame([Words::PO_ONE['save'], Words::PO_ONE['confirm']], array_map(static fn (\DOMElement $b): string => trim($b->textContent),
            iterator_to_array((new \DOMXPath($page->dom()))->query($one . '//section[contains(@class, "po-summary")]//button'))));
        self::assertSame(0, self::nodes($page, '//main//p[contains(@class, "hint") and not(contains(@class, "po-ask"))]'), 'no help under the fields');
        self::assertFalse($page->hasForm('/lines/import'));
        self::assertFalse($page->hasForm('/cancel'));
    }

    public function testTheProductBoxListsTheSuppliersItemsFirst(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $bid = $buyerUser['id'];
        $s = $this->supplier($bid, 'List Supplies');
        $pod = self::makeSku('List pod 2ml');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES ('5012345000035', ?, 1)", [$pod]);
        $siPod = (new SupplierItems(self::$db))->create(Caller::staff($bid), (int) $s['id'], $pod, ['units_per_pack' => '10', 'supplier_code' => 'LP-10']);
        $ice = self::makeSku('List liquid cherry ice');
        $cherry = self::makeSku('List liquid cherry');
        $siCherry = (new SupplierItems(self::$db))->create(Caller::staff($bid), (int) $s['id'], $cherry, ['units_per_pack' => '6', 'supplier_code' => 'LC-6']);
        $web = $this->signIn($buyerUser);

        $r = $web->get('/ui/purchasing/orders/products', ['supplier' => $s['id'], 'q' => 'liquid cherry']);
        self::assertSame(200, $r->status, $r->describe());
        self::assertSame(1, self::nodes($r, '/html/body/*[@data-po-fragment]'), 'a fragment, not a page');
        self::assertSame(0, self::nodes($r, '//header') + self::nodes($r, '//nav'));
        $xp = new \DOMXPath($r->dom());
        $rows = array_map(static fn (\DOMElement $li): array => [$li->getAttribute('data-si'), $li->getAttribute('data-sku'), trim((string) $xp->evaluate('string(span[@class="sm-opt-name"])', $li)),
            $li->getAttribute('data-label')], iterator_to_array($xp->query('//ul[@data-po-choices]/li')));
        self::assertSame([(string) $siCherry['id'], '', 'List liquid cherry'], array_slice($rows[0] ?? [], 0, 3), 'set up with this supplier first');
        self::assertSame(['', (string) $ice, 'List liquid cherry ice'], array_slice($rows[1] ?? [], 0, 3));
        self::assertCount(2, $rows);
        self::assertStringStartsWith('List liquid cherry · ' . sprintf('CW-%06d', $cherry), $rows[0][3], 'the box\'s words for it: the name, then its CW number');
        self::assertStringContainsString('LC-6', trim((string) $xp->evaluate('string(//ul[@data-po-choices]/li[1]/span[@class="sm-opt-meta"])')));
        // A scan: that product.
        $scan = $web->get('/ui/purchasing/orders/products', ['supplier' => $s['id'], 'q' => '5012345000035']);
        self::assertSame([(string) $siPod['id']], array_map(static fn (\DOMElement $li): string => $li->getAttribute('data-si'),
            iterator_to_array((new \DOMXPath($scan->dom()))->query('//ul[@data-po-choices]/li'))));
        // One letter finds too much: nothing yet. Nothing found: said in words.
        self::assertSame(0, self::nodes($web->get('/ui/purchasing/orders/products', ['supplier' => $s['id'], 'q' => 'l']), '//ul[@data-po-choices]/li'));
        $none = $web->get('/ui/purchasing/orders/products', ['supplier' => $s['id'], 'q' => 'zzzz']);
        self::assertSame(Words::say('PO_ONE', 'none', 'zzzz'), trim((string) (new \DOMXPath($none->dom()))->evaluate('string(//*[@data-po-says])')));
        // No supplier chosen yet: the products, none of them set up.
        $any = $web->get('/ui/purchasing/orders/products', ['q' => 'liquid cherry']);
        self::assertSame(['', ''], array_map(static fn (\DOMElement $li): string => $li->getAttribute('data-si'),
            iterator_to_array((new \DOMXPath($any->dom()))->query('//ul[@data-po-choices]/li'))));
    }
}
