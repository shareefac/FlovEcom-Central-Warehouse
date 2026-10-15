<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Receiving\GoodsReceipts;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Integration\Reorder\ReorderFixtures;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * The screen side of the Codex review of the PO module (pack PO, docs/decisions.md PO7-PO11; runs/po_module_codex_review.txt), each
 * written first against 552851a and seen failing:
 *   finding 1  the order "OK first" switched off: the draft editor opens (it was a 500);
 *   finding 2  "Reopen order" on a closed order (a reason, the CSRF token, buyers only); a reviewer of a delivery of a closed order
 *              can say "not OK" again;
 *   finding 4  the reorder list sends the supplier item and pack it showed; a line whose supply changed is listed as skipped;
 *   finding 5  a refused confirm or save shows again the charge, the scan text and the packs that were typed;
 *   finding 6  a later delivery on the same supplier invoice: the refusal offers the step "it is a later delivery on this invoice";
 *   finding 8  a delivery charge has its own "remove" control.
 */
final class PurchaseOrderFixesScreensTest extends KernelUiTestCase
{
    use ReorderFixtures;

    private string $dir;
    private string|false $prevStore = false;
    /** @var array<string, mixed>|null the PO document_type row to put back */
    private ?array $poType = null;
    private ?string $prevCutoff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_pofix_ui_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        mkdir($this->dir . '/store', 0700);
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
    }

    protected function tearDown(): void
    {
        if ($this->poType !== null) {
            self::$db->exec('UPDATE document_type SET approval_rule = ?, approval_limit_units = ? WHERE code = ?',
                [$this->poType['approval_rule'], $this->poType['approval_limit_units'], 'PO']);
            $this->poType = null;
        }
        if ($this->prevCutoff !== null) {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'receiving.unstamped_refusal_from'", [$this->prevCutoff]);
            $this->prevCutoff = null;
        }
        $this->cleanReorderFixtures();
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** A supplier made by $buyerId, approved by a fresh reviewer when $active. @return array<string, mixed> */
    private function supplier(int $buyerId, string $name, bool $active = true): array
    {
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyerId), ['name' => $name, 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA', 'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example',
            'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400), 'dd_checked_by' => (string) $buyerId,
            'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        if (!$active) {
            return $s;
        }
        $s = $sup->requestActivation(Caller::staff($buyerId), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    private function pos(): PurchaseOrders
    {
        return new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
    }

    // ---- finding 1 ------------------------------------------------------------------------------------------------------

    public function testTheDraftEditorOpensWithTheOrdersOkFirstSwitchedOff(): void
    {
        $this->poType = (array) self::$db->one("SELECT approval_rule, approval_limit_units FROM document_type WHERE code = 'PO'");
        self::$db->exec("UPDATE document_type SET approval_rule = 'none', approval_limit_units = NULL WHERE code = 'PO'");
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier($buyer['id'], 'No Ok First Ltd');
        $d = $this->pos()->createDraft(Caller::staff($buyer['id']), (int) $s['id'], []);
        $web = $this->signIn($buyer);
        $ed = $web->get("/ui/purchasing/orders/{$d->id}");
        self::assertSame(200, $ed->status, $ed->describe());
        self::assertTrue($ed->hasForm('/lines'), 'the editor');
        self::assertStringContainsString(Words::ORDER['limit_note_off'], $ed->text());
        self::assertStringNotContainsString('Over  (before VAT)', $ed->text());
    }

    // ---- finding 2 ------------------------------------------------------------------------------------------------------

    public function testAClosedOrderIsReopenedOnItsPageAndItsDeliveryCanStillBeRejected(): void
    {
        $this->prevCutoff = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'receiving.unstamped_refusal_from'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'receiving.unstamped_refusal_from'",
            [json_encode(gmdate('Y-m-d', time() + 365 * 86400), JSON_THROW_ON_ERROR)]);
        $buyerUser = $this->uiUser('buyer');
        $buyer = Caller::staff($buyerUser['id']);
        $desk = Caller::staff($this->uiUser('purchasing_desk')['id']);
        $s = $this->supplier($buyerUser['id'], 'Closed Order Supplies');
        $sku = self::makeSku('Closed order coil');
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        $si = (new SupplierItems(self::$db))->create($buyer, (int) $s['id'], $sku, ['units_per_pack' => '1', 'supplier_code' => 'CO-1'], ['pack_price' => '1.00']);
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $pos = new PurchaseOrders(self::$db, $docs);
        $po = $pos->createDraft($buyer, (int) $s['id'], []);
        $po = $pos->saveDraft($buyer, $po->id, $po->version, [], [['supplier_item_id' => (int) $si['id'], 'packs' => 10]]);
        $po = $pos->approve($buyer, $po->id, $po->version);
        $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => new FileStore(self::$db, new LocalFileStorage($this->dir . '/store')));
        $g = $grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'CO-INV-1'], $po->id);
        $g = $grns->saveDraft($desk, $g->id, $g->version, [], [['sku_id' => $sku, 'packs' => 4]]);
        $pdf = $this->dir . '/inv.pdf';
        file_put_contents($pdf, (new \CW\Output\PdfWriter('Invoice ' . bin2hex(random_bytes(4)), 'test', true))->output());
        $grns->attach($desk, $g->id, $pdf, 'inv.pdf', 'supplier_invoice');
        $bench = Caller::staff($this->uiUser('goods_in')['id']);
        $grns->bench($bench, $g->id, $docs->get($g->id)->version, ['paperwork_ok' => '1'], [1 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital']]);
        $grns->post($desk, $g->id, $docs->get($g->id)->version);
        $pos->close($buyer, $po->id, $docs->get($po->id)->version, 'the rest is not coming');

        // The reviewer of the delivery still has "not OK" (it takes the delivery back; the page says what it does to the order).
        $reviewer = $this->signIn($this->uiUser('reviewer'));
        $page = $reviewer->get("/ui/receiving/{$g->id}");
        self::assertSame(200, $page->status, $page->describe());
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND state = 'open'", [$g->id]);
        self::assertTrue($page->hasForm("/ui/documents/reviews/{$task}/reject"), 'the reject form is there for a delivery of a closed order');
        self::assertStringContainsString(Words::say('RECEIPT', 'po_closed', (string) $po->number), $page->text());

        // Reopen: the buyer's form on the closed order's page; a reason, the CSRF token; not for the desk.
        $web = $this->signIn($buyerUser);
        $view = $web->get("/ui/purchasing/orders/{$po->id}");
        self::assertSame(200, $view->status, $view->describe());
        self::assertTrue($view->hasForm("/ui/purchasing/orders/{$po->id}/reopen"));
        $form = $view->form("/ui/purchasing/orders/{$po->id}/reopen");
        self::assertSame(['csrf', 'version', 'reason'], array_keys($form));
        $noCsrf = $form;
        unset($noCsrf['csrf']);
        $r = $web->post("/ui/purchasing/orders/{$po->id}/reopen", ['reason' => 'it is coming'] + $noCsrf);
        self::assertSame(403, $r->status, 'no CSRF token: refused');
        $r = $web->post("/ui/purchasing/orders/{$po->id}/reopen", ['reason' => 'x'] + $form);
        self::assertSame(400, $r->status);
        self::assertStringContainsString(Words::BUY_ERROR['reopen_reason_required'], $r->text());
        $deskWeb = $this->signIn($this->uiUser('purchasing_desk'));
        self::assertFalse($deskWeb->get("/ui/purchasing/orders/{$po->id}")->hasForm('/reopen'));
        self::assertSame(403, $deskWeb->post("/ui/purchasing/orders/{$po->id}/reopen", ['csrf' => $this->token($deskWeb), 'version' => $form['version'], 'reason' => 'it is coming'])->status);
        $r = $web->post("/ui/purchasing/orders/{$po->id}/reopen", ['reason' => 'the supplier found the rest'] + $form);
        self::assertSame("/ui/purchasing/orders/{$po->id}?notice=reopened", $r->location(), $r->describe());
        self::assertStringContainsString(Words::PO_NOTICE['reopened'], $web->follow($r)->text());
        self::assertSame('part_received', self::$db->value('SELECT state FROM purchase_order WHERE document_id = ?', [$po->id]));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'po.reopen' AND entity_id = ?", [(string) $po->id]));
    }

    // ---- finding 4 ------------------------------------------------------------------------------------------------------

    public function testTheReorderListOrdersWhatItShowed(): void
    {
        $s = $this->listScenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/purchasing/reorder', ['brand' => 'Elux']);
        $form = $page->form('/ui/purchasing/reorder/draft');
        self::assertSame([(string) $s['si']['a']['id'], '24'], [$form['si_' . $s['a']] ?? null, $form['upp_' . $s['a']] ?? null], 'the line carries what it showed');
        // Meanwhile A's main supply becomes another supplier's packs of 6.
        (new SupplierItems(self::$db))->create($s['buyer'], (int) $s['s2']['id'], $s['a'], ['units_per_pack' => '6', 'supplier_code' => 'MARY-A6', 'is_preferred' => '1'],
            ['pack_price' => '10.00']);
        $this->refreshLines();
        $r = $web->post('/ui/purchasing/reorder/draft', $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('changed=' . $s['a'], (string) $r->location());
        $done = $web->follow($r);
        self::assertStringContainsString(Words::REORDER['skip_changed'], $done->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM document_line WHERE sku_id = ?', [$s['a']]), 'A was not ordered in other packs');
    }

    // ---- finding 5 ------------------------------------------------------------------------------------------------------

    public function testARefusedConfirmShowsTheChargeScanAndPacksAgain(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier($buyer['id'], 'Waiting Approval Ltd', false);
        $sku = self::makeSku('Waiting pod');
        $si = (new SupplierItems(self::$db))->create(Caller::staff($buyer['id']), (int) $s['id'], $sku, ['units_per_pack' => '1', 'supplier_code' => 'WP-1'], ['pack_price' => '3.00']);
        $pos = $this->pos();
        $d = $pos->createDraft(Caller::staff($buyer['id']), (int) $s['id'], []);
        $pos->saveDraft(Caller::staff($buyer['id']), $d->id, $d->version, [], [['supplier_item_id' => (int) $si['id'], 'packs' => 2]]);
        $web = $this->signIn($buyer);
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['charge_description' => 'Delivery', 'charge_amount' => '7.50', 'charge_vat' => 'S', 'action' => 'approve'] + $f);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::BUY_ERROR['supplier_not_active'], $r->text());
        $again = $r->form('/lines');
        self::assertSame(['Delivery', '7.50', 'S'], [$again['charge_description'] ?? null, $again['charge_amount'] ?? null, $again['charge_vat'] ?? null],
            'the charge typed is still there: confirming again does not drop it');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM po_line WHERE document_id = ? AND kind = 'charge'", [$d->id]), 'nothing saved');
        // A line refused with a scan and packs typed: they come back too.
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['line_1_price' => 'lots', 'q' => 'WP-1', 'packs' => '3', 'action' => 'add'] + $f);
        self::assertSame(422, $r->status);
        $again = $r->form('/lines');
        self::assertSame(['WP-1', '3', 'lots'], [$again['q'] ?? null, $again['packs'] ?? null, $again['line_1_price'] ?? null]);
    }

    // ---- finding 6 ------------------------------------------------------------------------------------------------------

    public function testALaterDeliveryOnTheSameInvoiceIsOfferedAsASecondStep(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $buyer = Caller::staff($buyerUser['id']);
        $deskUser = $this->uiUser('purchasing_desk');
        $desk = Caller::staff($deskUser['id']);
        $s = $this->supplier($buyerUser['id'], 'Split Delivery Supplies');
        $sku = self::makeSku('Split delivery coil');
        $si = (new SupplierItems(self::$db))->create($buyer, (int) $s['id'], $sku, ['units_per_pack' => '1', 'supplier_code' => 'SD-1'], ['pack_price' => '1.00']);
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $pos = new PurchaseOrders(self::$db, $docs);
        $po = $pos->createDraft($buyer, (int) $s['id'], []);
        $po = $pos->saveDraft($buyer, $po->id, $po->version, [], [['supplier_item_id' => (int) $si['id'], 'packs' => 10]]);
        $po = $pos->approve($buyer, $po->id, $po->version);
        $grns = new GoodsReceipts(self::$db, $docs);
        $grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'SPLIT-9', 'delivery_note' => 'DN-A'], $po->id);
        $b = $grns->createDraft($desk, (int) $s['id'], ['delivery_note' => 'DN-B'], $po->id);

        $web = $this->signIn($deskUser);
        $ed = $web->get("/ui/receiving/{$b->id}");
        self::assertSame(200, $ed->status, $ed->describe());
        $r = $web->post("/ui/receiving/{$b->id}/lines", ['invoice_number' => 'SPLIT-9', 'action' => 'save'] + $ed->form('/lines'));
        self::assertSame(409, $r->status, $r->describe());
        self::assertTrue($r->hasForm("/ui/receiving/{$b->id}/invoice"), 'the second step');
        $step = $r->form("/ui/receiving/{$b->id}/invoice");
        self::assertSame(['SPLIT-9', '1'], [$step['invoice_number'] ?? null, $step['invoice_split'] ?? null]);
        self::assertStringContainsString(Words::say('RECEIPT', 'split_offer', (string) $po->number), $r->text());
        $ok = $web->post("/ui/receiving/{$b->id}/invoice", $step);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame(2, (int) self::$db->value('SELECT invoice_part FROM goods_receipt WHERE document_id = ?', [$b->id]));
        self::assertStringContainsString(Words::say('RECEIPT', 'split_part', 2), $web->follow($ok)->text());
    }

    // ---- finding 8 ------------------------------------------------------------------------------------------------------

    public function testADeliveryChargeIsRemovedOnTheEditor(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier($buyer['id'], 'Charge Removal Ltd');
        $sku = self::makeSku('Charge pod');
        $si = (new SupplierItems(self::$db))->create(Caller::staff($buyer['id']), (int) $s['id'], $sku, ['units_per_pack' => '1', 'supplier_code' => 'CH-1'], ['pack_price' => '3.00']);
        $pos = $this->pos();
        $d = $pos->createDraft(Caller::staff($buyer['id']), (int) $s['id'], []);
        $pos->saveDraft(Caller::staff($buyer['id']), $d->id, $d->version, [], [['supplier_item_id' => (int) $si['id'], 'packs' => 2],
            ['kind' => 'charge', 'description' => 'Delivery', 'pack_price' => '7.50']]);
        $web = $this->signIn($buyer);
        $ed = $web->get("/ui/purchasing/orders/{$d->id}");
        $xp = new \DOMXPath($ed->dom());
        self::assertSame(1, $xp->query('//form[contains(@action, "/lines")]//input[@type="checkbox"][@name="line_2_remove"]')->length, 'the charge has a remove box');
        self::assertStringContainsString(Words::ORDER['charge_remove'], $ed->text());
        $f = $ed->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['line_2_remove' => '1', 'action' => 'save'] + $f);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=saved#scan", $r->location(), $r->describe());
        self::assertSame(['item'], array_map('strval', self::$db->column('SELECT kind FROM po_line WHERE document_id = ? ORDER BY line_no', [$d->id])), 'the charge is gone');
    }
}
