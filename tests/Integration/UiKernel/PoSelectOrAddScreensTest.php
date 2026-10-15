<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The owner's "In purchase order view: select or add new option" (15 Oct 2026; pack PO, docs/decisions.md PO1-PO6) on the screens,
 * as cw_app through the real kernel: the supplier's list with its search box and "Add a new supplier" (on the list's "Start a new
 * order" and on a draft without products), what a supplier not approved yet still needs, the scan box with "Add a new item" (a step
 * that returns to the order: Pack B's rules, the supplier item, the line), who sees the "add" options and who is told whom to ask,
 * and the CSRF token and the route's permission on every new POST.
 */
final class PoSelectOrAddScreensTest extends KernelUiTestCase
{
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

    private static function orderId(?string $location): int
    {
        self::assertSame(1, preg_match('#^/ui/purchasing/orders/(\d+)#', (string) $location, $m), (string) $location);
        return (int) $m[1];
    }

    private static function filterFor(UiResponse $r, string $select): int
    {
        return (new \DOMXPath($r->dom()))->query("//input[@type='search'][@data-filter-select='{$select}'][@hidden]")->length
            * (new \DOMXPath($r->dom()))->query("//select[@id='{$select}']")->length;
    }

    public function testANewSupplierIsAddedWhereTheOrderIsStarted(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $this->supplier($buyerUser['id'], 'Known Supplies');
        $web = $this->signIn($buyerUser);
        $list = $web->get('/ui/purchasing/orders');
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(1, self::filterFor($list, 'new-order-supplier'), 'the supplier list has its search box (shown by the script)');
        self::assertStringContainsString(Words::PO_ADD['new_supplier'], $list->text());
        $form = $list->form('/ui/purchasing/orders/new-supplier');
        self::assertSame(['csrf', 'form_key', 'new_supplier_name', 'new_supplier_email', 'new_supplier_phone'], array_keys($form));
        self::assertNotSame($form['form_key'], $list->form('/ui/purchasing/orders')['form_key'], 'its own form key');

        // No CSRF token: refused. No name: refused in words, what was typed kept, nothing made.
        $noCsrf = $form;
        unset($noCsrf['csrf']);
        self::assertSame(403, $web->post('/ui/purchasing/orders/new-supplier', ['new_supplier_name' => 'Fresh Vapes'] + $noCsrf)->status);
        $bad = $web->post('/ui/purchasing/orders/new-supplier', ['new_supplier_email' => 'sales@fresh.example'] + $form);
        self::assertSame(422, $bad->status, $bad->describe());
        self::assertSame('sales@fresh.example', $bad->form('/ui/purchasing/orders/new-supplier')['new_supplier_email']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM supplier'));

        $form = ['new_supplier_name' => 'Fresh Vapes', 'new_supplier_phone' => '0161 000 0000'] + $bad->form('/ui/purchasing/orders/new-supplier');
        $r = $web->post('/ui/purchasing/orders/new-supplier', $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertMatchesRegularExpression('#^/ui/purchasing/orders/\d+\?notice=supplier_added$#', (string) $r->location());
        self::assertSame($r->location(), $web->post('/ui/purchasing/orders/new-supplier', $form)->location(), 'the same form again: the same order');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM supplier'));
        $id = self::orderId($r->location());
        $s = (array) self::$db->one("SELECT id, status, phone FROM supplier WHERE name = 'Fresh Vapes'");
        self::assertSame(['draft', '0161 000 0000'], [$s['status'], $s['phone']]);
        self::assertSame((int) $s['id'], (int) self::$db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$id]));

        // The editor says what the supplier still needs; confirming is refused in words and the order stays a draft.
        $ed = $web->follow($r);
        self::assertSame(200, $ed->status, $ed->describe());
        self::assertStringContainsString(Words::PO_NOTICE['supplier_added'], $ed->text());
        self::assertStringContainsString(Words::PO_ADD['needs_title'], $ed->text());
        self::assertStringContainsString(Words::say('PO_ADD', 'needs_new', 'Fresh Vapes'), $ed->text());
        self::assertStringContainsString(Words::PO_ADD['needs_ask'], $ed->text());
        self::assertStringNotContainsString(Words::say('PO_WARN', 'supplier_not_approved', 'Fresh Vapes'), $ed->text(), 'said once');
        self::assertContains('/ui/purchasing/suppliers/' . $s['id'], $ed->hrefs());
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $pos->saveDraft(Caller::staff($buyerUser['id']), $id, (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]), [],
            [['sku_id' => self::makeSku('Fresh pod'), 'packs' => 2, 'pack_price' => '3.00']]);
        $ed = $web->get("/ui/purchasing/orders/{$id}");
        $ok = $web->post("/ui/purchasing/orders/{$id}/lines", ['action' => 'approve'] + $ed->form('/lines'));
        self::assertSame(422, $ok->status);
        self::assertStringContainsString(Words::BUY_ERROR['supplier_not_active'], $ok->text());
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));

        // Waiting for the reviewer: the note says so.
        $sup = new Suppliers(self::$db);
        $row = $sup->update(Caller::staff($buyerUser['id']), (int) $s['id'], (int) $sup->get((int) $s['id'])['version'], ['address_line1' => '1 Road', 'postcode' => 'M2 2BB',
            'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 86400), 'dd_checked_by' => (string) $buyerUser['id'],
            'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $sup->requestActivation(Caller::staff($buyerUser['id']), (int) $s['id'], (int) $row['version']);
        self::assertStringContainsString(Words::say('PO_ADD', 'needs_pending', 'Fresh Vapes'), $web->get("/ui/purchasing/orders/{$id}")->text());

        // Who may not add suppliers never reaches the form: the desk has no new-order form, and its POST is 403.
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        self::assertFalse($desk->get('/ui/purchasing/orders')->hasForm('/ui/purchasing/orders/new-supplier'));
        self::assertSame(403, $desk->post('/ui/purchasing/orders/new-supplier', ['csrf' => $this->token($desk), 'form_key' => str_repeat('a', 32), 'new_supplier_name' => 'X Ltd'])->status);
    }

    public function testADraftWithoutProductsTakesANewSupplier(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $s = $this->supplier($buyerUser['id'], 'First Choice Ltd');
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $pos->createDraft(Caller::staff($buyerUser['id']), (int) $s['id'], []);
        $web = $this->signIn($buyerUser);
        $ed = $web->get("/ui/purchasing/orders/{$d->id}");
        self::assertSame(1, self::filterFor($ed, 'po-supplier'));
        $form = $ed->form("/ui/purchasing/orders/{$d->id}/new-supplier");
        self::assertSame(['csrf', 'version', 'new_supplier_name', 'new_supplier_email', 'new_supplier_phone'], array_keys($form));
        $noCsrf = $form;
        unset($noCsrf['csrf']);
        self::assertSame(403, $web->post("/ui/purchasing/orders/{$d->id}/new-supplier", ['new_supplier_name' => 'Second Choice Ltd'] + $noCsrf)->status);
        $r = $web->post("/ui/purchasing/orders/{$d->id}/new-supplier", ['new_supplier_name' => 'Second Choice Ltd'] + $form);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=supplier_added", $r->location(), $r->describe());
        $new = (int) self::$db->value("SELECT id FROM supplier WHERE name = 'Second Choice Ltd'");
        self::assertSame($new, (int) self::$db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$d->id]));
        // The same form again (its version is old now): refused, no second supplier.
        $stale = $web->post("/ui/purchasing/orders/{$d->id}/new-supplier", ['new_supplier_name' => 'Second Choice Ltd'] + $form);
        self::assertSame(409, $stale->status);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Second Choice Ltd'"));

        // With a product on it the supplier is fixed: no "add" option.
        $sku = self::makeSku('Fixed supplier pod');
        $pos->saveDraft(Caller::staff($buyerUser['id']), $d->id, (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$d->id]), [], [['sku_id' => $sku, 'packs' => 1]]);
        $ed = $web->get("/ui/purchasing/orders/{$d->id}");
        self::assertFalse($ed->hasForm('/new-supplier'));
        self::assertStringContainsString(Words::ORDER['supplier_fixed'], $ed->text());
    }

    public function testANewItemIsMadeInAStepThatReturnsToTheOrder(): void
    {
        $pmUser = $this->uiUser('purchasing_manager');
        $s = $this->supplier($pmUser['id'], 'Step Supplies');
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $pos->createDraft(Caller::staff($pmUser['id']), (int) $s['id'], []);
        $web = $this->signIn($pmUser);
        $ed = $web->get("/ui/purchasing/orders/{$d->id}");
        self::assertStringContainsString(Words::PO_ADD['new_item'], $ed->text());
        // Search first: nothing found. The scan text and the packs go with "Add a new item", and the table is saved first.
        $nf = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['q' => 'Mystery Pod 2ml', 'packs' => '3', 'action' => 'add', 'external_ref' => 'Q-1'] + $ed->form('/lines'));
        self::assertSame(422, $nf->status);
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['action' => 'new_item'] + $nf->form('/lines'));
        self::assertSame("/ui/purchasing/orders/{$d->id}/new-item?q=Mystery%20Pod%202ml&packs=3", $r->location(), $r->describe());
        self::assertSame('Q-1', self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$d->id]), 'the table was saved');
        $step = $web->follow($r);
        self::assertSame(200, $step->status, $step->describe());
        $form = $step->form("/ui/purchasing/orders/{$d->id}/new-item");
        self::assertSame(['Mystery Pod 2ml', '', '3', '1'], [$form['name'], $form['barcode'], $form['packs'], $form['units_per_pack']]);
        $noCsrf = $form;
        unset($noCsrf['csrf']);
        self::assertSame(403, $web->post("/ui/purchasing/orders/{$d->id}/new-item", $noCsrf)->status);
        $form = ['brand' => 'Mystery', 'barcode' => '5060000000023', 'supplier_code' => 'MYS-6', 'units_per_pack' => '6', 'pack_price' => '9.00'] + $form;
        $done = $web->post("/ui/purchasing/orders/{$d->id}/new-item", $form);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=item_added#scan", $done->location(), $done->describe());
        self::assertSame($done->location(), $web->post("/ui/purchasing/orders/{$d->id}/new-item", $form)->location(), 'the same form again: one item');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Mystery Pod 2ml'"));
        self::assertSame([['MYS-6', 3, 6, '9.0000']], array_map(static fn (array $l): array => [$l['supplier_code'], (int) $l['packs'], (int) $l['units_per_pack'], $l['pack_price']],
            self::$db->all('SELECT supplier_code, packs, units_per_pack, pack_price FROM po_line WHERE document_id = ?', [$d->id])));
        self::assertStringContainsString(Words::PO_NOTICE['item_added'], $web->follow($done)->text());

        // A barcode on another item: refused, and that item is offered instead (one click adds it).
        $step = $web->get("/ui/purchasing/orders/{$d->id}/new-item", ['q' => '5060000000023']);
        $form = ['name' => 'Copy pod'] + $step->form("/ui/purchasing/orders/{$d->id}/new-item");
        self::assertSame('5060000000023', $form['barcode'], 'digits in the scan box: the barcode');
        $bad = $web->post("/ui/purchasing/orders/{$d->id}/new-item", $form);
        self::assertSame(409, $bad->status, $bad->describe());
        self::assertStringContainsString(Words::ITEMS_ERROR['barcode_on_other_item'], $bad->text());
        $use = $bad->form("/ui/purchasing/orders/{$d->id}/lines");
        self::assertSame(['csrf', 'version', 'line_count', 'lines_editable', 'packs'], array_keys($use));
        $sku = (int) self::$db->value("SELECT id FROM sku WHERE name = 'Mystery Pod 2ml'");
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['add_sku' => (string) $sku] + $use);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=incremented#scan", $r->location(), $r->describe());
        self::assertSame(4, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$d->id]));

        // The same name: the item that is there is shown, with "it is a different item".
        $step = $web->get("/ui/purchasing/orders/{$d->id}/new-item", ['q' => 'Mystery Pod 2ml']);
        $form = ['brand' => 'Mystery'] + $step->form("/ui/purchasing/orders/{$d->id}/new-item");
        $same = $web->post("/ui/purchasing/orders/{$d->id}/new-item", $form);
        self::assertSame(409, $same->status, $same->describe());
        self::assertStringContainsString(Words::PO_ADD['same_title'], $same->text());
        $again = $same->form("/ui/purchasing/orders/{$d->id}/new-item");
        self::assertArrayNotHasKey('anyway', $again, 'not ticked for the person');
        $made = $web->post("/ui/purchasing/orders/{$d->id}/new-item", ['anyway' => '1'] + $again);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=item_added#scan", $made->location(), $made->describe());
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Mystery Pod 2ml'"));
    }

    public function testABuyerIsToldWhoAddsItemsAndNeverReachesTheStep(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $s = $this->supplier($buyerUser['id'], 'Buyer Supplies');
        $sku = self::makeSku('Buyer pod');
        (new SupplierItems(self::$db))->create(Caller::staff($buyerUser['id']), (int) $s['id'], $sku, ['units_per_pack' => '1', 'supplier_code' => 'BP-1']);
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $pos->createDraft(Caller::staff($buyerUser['id']), (int) $s['id'], []);
        $web = $this->signIn($buyerUser);
        $ed = $web->get("/ui/purchasing/orders/{$d->id}");
        self::assertStringNotContainsString(Words::PO_ADD['new_item'] . '<', $ed->body);
        self::assertSame(0, (new \DOMXPath($ed->dom()))->query("//button[@value='new_item']")->length);
        self::assertStringContainsString(Words::say('PO_ADD', 'ask_item', Words::whoCan('items.create')), $ed->text());
        self::assertSame(403, $web->get("/ui/purchasing/orders/{$d->id}/new-item")->status);
        self::assertSame(403, $web->post("/ui/purchasing/orders/{$d->id}/new-item", ['csrf' => $this->token($web), 'form_key' => str_repeat('b', 32), 'version' => '1',
            'name' => 'Sneaky pod'])->status);
        // The scan box still selects what is there.
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['q' => 'BP-1', 'action' => 'add'] + $ed->form('/lines'));
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=added#scan", $r->location(), $r->describe());
        // Another buyer's draft: the step sends them back to the order's page.
        $pmWeb = $this->signIn($this->uiUser('purchasing_manager'));
        self::assertSame("/ui/purchasing/orders/{$d->id}", $pmWeb->get("/ui/purchasing/orders/{$d->id}/new-item")->location());
    }
}
