<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;

/**
 * The receiving screens through the real /ui kernel as cw_app (IM6; I141): the owner's I-3 test on the screens — a delivery
 * against a PO copied down ("receive all as ordered"), a scan of the outer case (a box of 5), the invoice copy attached, the bench
 * check on the bench view, "Save and post" with the checklist, the receipt's page, the review decided there, the incident closed
 * in the register; the supplier-sheet import, a cancel and a reversal; who sees and does what; the phone-width markup.
 */
final class ReceivingScreensTest extends KernelUiTestCase
{
    private string $dir;
    private string|false $prevStore = false;
    private ?string $prevCutoff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_rcv_ui_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        mkdir($this->dir . '/store', 0700);
        // Files go to a temporary file store, never to the server's own (app.env file_store_dir: docs/dev.md).
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
        $this->prevCutoff = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'receiving.unstamped_refusal_from'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'receiving.unstamped_refusal_from'",
            [json_encode(gmdate('Y-m-d', time() + 365 * 86400), JSON_THROW_ON_ERROR)]);
    }

    protected function tearDown(): void
    {
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        if ($this->prevCutoff !== null) {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'receiving.unstamped_refusal_from'", [$this->prevCutoff]);
        }
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** An active supplier created by $buyerId and approved by a fresh reviewer (through the services). @return array<string, mixed> */
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

    /** An e-liquid card (duty-liable, 10 ml), written by a stock controller. */
    private function liquid(int $sku): void
    {
        $editor = Caller::staff($this->uiUser('stock_controller')['id']);
        (new ItemCards(self::$db))->save($editor, $sku, 0, ['product_type' => 'e-liquid', 'liquid_ml' => '10', 'nicotine_mg' => '6', 'duty_liable' => 'yes']);
    }

    private function pdf(): string
    {
        $p = new \CW\Output\PdfWriter('Invoice ' . bin2hex(random_bytes(4)), 'test', true);
        $path = $this->dir . '/invoice-' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($path, $p->output());
        return $path;
    }

    private static function receiptId(?string $location): int
    {
        self::assertSame(1, preg_match('#^/ui/receiving/(\d+)#', (string) $location, $m), (string) $location);
        return (int) $m[1];
    }

    /** The owner's I-3 test on the screens: a PO copied down, a box of 5 scanned, the invoice, the bench, post, review, incident. */
    public function testReceiveAPoDeliveryCheckItAtTheBenchPostAndReview(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $benchUser = $this->uiUser(['goods_in', 'reviewer']);
        $s = $this->supplier($buyer['id'], 'Screen Box Supplies');
        $sku = self::makeSku('Screen box liquid 10ml');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES ('5012345678900', ?, 1), ('5012345678917', ?, 5)", [$sku, $sku]);
        $this->liquid($sku);
        $si = (new SupplierItems(self::$db))->create(Caller::staff($buyer['id']), (int) $s['id'], $sku, ['units_per_pack' => '5', 'supplier_code' => 'BOX5'], ['pack_price' => '10.00']);
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $pos = new PurchaseOrders(self::$db, $docs);
        $po = $pos->createDraft(Caller::staff($buyer['id']), (int) $s['id'], []);
        $po = $pos->saveDraft(Caller::staff($buyer['id']), $po->id, $po->version, [], [['supplier_item_id' => (int) $si['id'], 'packs' => 3]]);
        $po = $pos->approve(Caller::staff($buyer['id']), $po->id, $po->version);

        $desk = $this->signIn($deskUser);
        $list = $desk->get('/ui/receiving');
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(['label' => 'Receive + invoice', 'href' => '/ui/receiving'], self::nav($list)['Receiving'][0]);
        self::assertStringContainsString('No receipt matches.', $list->text());
        $form = ['po_id' => (string) $po->id, 'invoice_number' => 'SCR-001', 'copy' => '1'] + $list->form('/ui/receiving');
        $r = $desk->post('/ui/receiving', $form);
        self::assertSame(303, $r->status, $r->describe());
        $id = self::receiptId($r->location());
        self::assertSame($r->location(), $desk->post('/ui/receiving', $form)->location(), 'the same form again lands on the same receipt');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'GRN'"));

        $ed = $desk->follow($r);
        self::assertSame(200, $ed->status, $ed->describe());
        self::assertStringContainsString('outstanding lines were copied down', $ed->text());
        $f = $ed->form('/lines');
        self::assertSame(['csrf', 'version', 'line_count', 'lines_editable'], array_slice(array_keys($f), 0, 4), 'version and line_count come first');
        self::assertSame(['1', '3', 'default', '1'], [$f['line_count'], $f['line_1_packs'], $f['line_1_mode'], $f['line_1_po']]);
        // The box's own barcode: one more box of 5 (Enter in the scan box adds and saves the table).
        $r = $desk->post("/ui/receiving/{$id}/lines", ['q' => '5012345678917', 'action' => 'add'] + $f);
        self::assertSame("/ui/receiving/{$id}?notice=incremented&line=1&u=5#line-1", $r->location(), $r->describe());
        $added = $desk->follow($r);
        self::assertStringContainsString('Line 1, ' . self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]) . ' Screen box liquid 10ml: +5 units (now 4 packs of 5 = 20 units)',
            $added->text(), 'the notice names the line, the item and the units added (I173)');
        $f = $added->form('/lines');
        self::assertSame('4', $f['line_1_packs']);
        // Back to the 3 boxes that came; "Save and post" before the invoice and the bench: refused, the checklist says why, nothing posted.
        $post = $desk->post("/ui/receiving/{$id}/lines", ['line_1_packs' => '3', 'action' => 'post'] + $f);
        self::assertSame(422, $post->status, $post->describe());
        self::assertStringContainsString('Attach the supplier\'s invoice', $post->text());
        self::assertStringContainsString('Waiting for the goods-in bench', $post->text());
        self::assertSame('3', $post->form('/lines')['line_1_packs'], 'what was typed is shown again');
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        $f = $desk->get("/ui/receiving/{$id}")->form('/lines');
        $desk->post("/ui/receiving/{$id}/lines", ['line_1_packs' => '3', 'action' => 'save'] + $f);
        $page = $desk->get("/ui/receiving/{$id}");
        $up = $desk->postMultipart("/ui/receiving/{$id}/files", ['role' => 'supplier_invoice'] + $page->form('/files'), ['file' => ['path' => $this->pdf(), 'name' => 'SCR-001.pdf']]);
        self::assertSame("/ui/receiving/{$id}?notice=attached", $up->location(), $up->describe());

        // The bench, on its own screen (another person: never the reviewer of this receipt).
        $bench = $this->signIn($benchUser);
        $bl = $bench->get('/ui/receiving/bench');
        self::assertSame(200, $bl->status, $bl->describe());
        self::assertContains("/ui/receiving/{$id}/bench", $bl->hrefs());
        $bv = $bench->get("/ui/receiving/{$id}/bench");
        self::assertSame(200, $bv->status, $bv->describe());
        self::assertStringContainsString('= 15 units on the paperwork', $bv->text());
        $bf = $bv->form("/ui/receiving/{$id}/bench", true);
        self::assertArrayHasKey('b_1_stamp', $bf);
        $notMine = $bench->post("/ui/receiving/{$id}/lines", ['csrf' => $this->token($bench), 'action' => 'save'] + $page->form('/lines'));
        self::assertSame(403, $notMine->status, 'only the desk person who keyed it changes its lines');
        self::assertStringContainsString('only the person who keyed a receipt changes its lines', $notMine->text());
        $saved = $bench->post("/ui/receiving/{$id}/bench", ['paperwork_ok' => '1', 'b_1_stamp' => '1', 'b_1_type' => 'digital', 'b_1_damaged' => '2'] + $bf);
        self::assertSame("/ui/receiving/{$id}/bench?notice=checked#line-1", $saved->location(), $saved->describe());
        $stale = $bench->post("/ui/receiving/{$id}/bench", ['paperwork_ok' => '0', 'b_1_damaged' => '3'] + $bf);
        self::assertSame(409, $stale->status, 'a check drawn before the other bench save is redrawn, with what was typed');
        self::assertStringContainsString('Someone saved this receipt since you opened this page', $stale->text());
        self::assertSame(['0', '3'], [$stale->form("/ui/receiving/{$id}/bench", true)['paperwork_ok'], $stale->form("/ui/receiving/{$id}/bench", true)['b_1_damaged']]);

        // Posted from the editor: 13 into MAIN, 2 into VERIFY, an incident; the PO part-received.
        $f = $desk->get("/ui/receiving/{$id}")->form('/lines');
        $r = $desk->post("/ui/receiving/{$id}/lines", ['action' => 'post'] + $f);
        self::assertSame("/ui/receiving/{$id}?notice=posted", $r->location(), $r->describe());
        $view = $desk->follow($r);
        self::assertStringContainsString('GRN-000001', $view->text());
        self::assertStringContainsString('13 MAIN, 2 VERIFY', $view->text());
        self::assertFalse($view->hasForm("/ui/receiving/{$id}/lines"), 'a posted receipt is never edited');
        self::assertSame([13, 2], [(int) self::$db->value("SELECT on_hand FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id WHERE w.code = 'MAIN' AND b.sku_id = ?", [$sku]),
            (int) self::$db->value("SELECT on_hand FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id WHERE w.code = 'VERIFY' AND b.sku_id = ?", [$sku])]);
        self::assertSame('part_received', self::$db->value('SELECT state FROM purchase_order WHERE document_id = ?', [$po->id]));

        // The review: the bench checker (also a reviewer) is told why not; another reviewer decides on the receipt's page.
        self::assertStringContainsString('You checked this delivery at the goods-in bench: another reviewer must review it.', $bench->get("/ui/receiving/{$id}")->text());
        $reviewer = $this->signIn($this->uiUser('reviewer'));
        $queue = $reviewer->get('/ui/documents/reviews', ['type' => 'GRN']);
        self::assertContains("/ui/receiving/{$id}", $queue->hrefs());
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND state = 'open'", [$id]);
        $rv = $reviewer->get("/ui/receiving/{$id}");
        $dec = $reviewer->post("/ui/documents/reviews/{$task}/approve", $rv->form("/ui/documents/reviews/{$task}/approve"));
        self::assertSame("/ui/receiving/{$id}?notice=approved_review", $dec->location(), $dec->describe());
        self::assertSame('approved', self::$db->value('SELECT review_state FROM document WHERE id = ?', [$id]));
        self::assertContains("/ui/receiving/{$id}", $reviewer->get("/ui/documents/{$id}")->hrefs(), 'the document page opens the receipt');

        // The incident register: the desk closes the damaged incident with what was done.
        $inc = $desk->get('/ui/receiving/incidents');
        self::assertSame(200, $inc->status, $inc->describe());
        self::assertStringContainsString('damaged: 2 units of', $inc->text());
        $incId = (int) self::$db->value('SELECT id FROM incident WHERE document_id = ?', [$id]);
        $closed = $desk->post("/ui/receiving/incidents/{$incId}", ['status' => 'resolved', 'note' => 'credit note asked for'] + $inc->form("/ui/receiving/incidents/{$incId}"));
        self::assertSame('/ui/receiving/incidents?notice=resolved', $closed->location(), $closed->describe());
        self::assertSame(['resolved', 'credit note asked for'], array_values((array) self::$db->one('SELECT status, resolution FROM incident WHERE id = ?', [$incId])));
        self::assertStringContainsString('No incident matches.', $desk->get('/ui/receiving/incidents')->text());
    }

    /**
     * The desk and the bench work on one delivery at once (review 7 Oct; I175): a desk save after a bench save is saved (the bench
     * changed nothing the desk's form holds), the bench's findings kept; a desk change of a line's quantity clears that line's check
     * and says so; a bench save after that is redrawn with what was typed, except on the changed line. The invoice number is not a
     * required field of the editor (a scan must work before the invoice arrives), and someone who did not key the receipt sets it.
     */
    public function testTheDeskAndTheBenchKeepEachOthersWork(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier($buyer['id'], 'Screen Together Ltd');
        $sku = self::makeSku('Together liquid 10ml');
        $this->liquid($sku);
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $r = $desk->post('/ui/receiving', ['supplier_id' => (string) $s['id']] + $desk->get('/ui/receiving')->form('/ui/receiving'));
        $id = self::receiptId($r->location());
        $ed = $desk->follow($r);
        self::assertStringNotContainsString(' required value=', (string) preg_replace('/\s+/', ' ', (string) strstr((string) strstr($ed->body, 'name="invoice_number"'), '>', true)),
            'the editor does not require the invoice number (only posting does)');
        $desk->post("/ui/receiving/{$id}/lines", ['q' => 'CW-' . sprintf('%06d', $sku), 'action' => 'add', 'price' => '2.40'] + $ed->form('/lines'));
        $f = $desk->get("/ui/receiving/{$id}")->form('/lines');
        self::assertSame('2.4000', self::$db->value('SELECT pack_price FROM grn_line WHERE document_id = ?', [$id]), 'the price typed with the scan');

        $bench = $this->signIn($this->uiUser('goods_in'));
        $bf = $bench->get("/ui/receiving/{$id}/bench")->form("/ui/receiving/{$id}/bench", true);
        $b = $bench->post("/ui/receiving/{$id}/bench", ['b_1_stamp' => '1', 'b_1_type' => 'digital'] + $bf);
        self::assertSame(303, $b->status, $b->describe());
        // The desk's form was drawn before the bench saved: saved all the same, the bench's findings kept.
        $saved = $desk->post("/ui/receiving/{$id}/lines", ['line_1_price' => '2.55', 'line_1_note' => 'per invoice', 'action' => 'save'] + $f);
        self::assertSame(303, $saved->status, $saved->describe());
        $row = self::$db->one('SELECT pack_price, stamp_on_pack, stamp_type, checked_at FROM grn_line WHERE document_id = ?', [$id]);
        self::assertSame(['2.5500', 1, 'digital'], [$row['pack_price'], (int) $row['stamp_on_pack'], $row['stamp_type']]);
        self::assertNotNull($row['checked_at']);

        // The bench draws the page; the desk then changes the quantity: the line's check is cleared, and the desk is told.
        $bf = $bench->get("/ui/receiving/{$id}/bench")->form("/ui/receiving/{$id}/bench", true);
        $q = $desk->post("/ui/receiving/{$id}/lines", ['line_1_packs' => '2', 'action' => 'save'] + $desk->get("/ui/receiving/{$id}")->form('/lines'));
        self::assertSame("/ui/receiving/{$id}?notice=saved&rc=1#scan", $q->location(), $q->describe());
        self::assertStringContainsString('The bench check of line 1 was cleared (its quantity or item changed): the bench counts it again.', $desk->follow($q)->text());
        $stale = $bench->post("/ui/receiving/{$id}/bench", ['bench_note' => 'pallet wrapped', 'b_1_damaged' => '1'] + $bf);
        self::assertSame(409, $stale->status, $stale->describe());
        self::assertStringContainsString('except on line 1, whose item or quantity changed', $stale->text());
        $again = $stale->form("/ui/receiving/{$id}/bench", true);
        self::assertSame(['pallet wrapped', '0'], [$again['bench_note'], $again['b_1_damaged']], 'what was typed stays, but not on the changed line');

        // The invoice set by the bench person, who did not key the receipt (its read-only page has the form).
        $page = $bench->get("/ui/receiving/{$id}");
        self::assertTrue($page->hasForm("/ui/receiving/{$id}/invoice"));
        $inv = $bench->post("/ui/receiving/{$id}/invoice", ['invoice_number' => 'TOG-9'] + $page->form("/ui/receiving/{$id}/invoice"));
        self::assertSame("/ui/receiving/{$id}?notice=invoice_set", $inv->location(), $inv->describe());
        self::assertSame('TOG-9', self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$id]));
        self::assertFalse($desk->get("/ui/receiving/{$id}")->hasForm("/ui/receiving/{$id}/invoice"), 'the keyer sets it in the editor');
    }

    public function testTheSheetImportACancelAndAReversalOnTheScreens(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Screen Sheet Ltd');
        $coil = self::makeSku('Screen coil');
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $coil, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        (new SupplierItems(self::$db))->create(Caller::staff($buyer['id']), (int) $s['id'], $coil, ['units_per_pack' => '5', 'supplier_code' => 'CL-5'], ['pack_price' => '4.00']);
        $desk = $this->signIn($deskUser);
        $list = $desk->get('/ui/receiving');
        $r = $desk->post('/ui/receiving', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'SHEET-1'] + $list->form('/ui/receiving'));
        $id = self::receiptId($r->location());
        $page = $desk->follow($r);
        $csv = $this->dir . '/sheet.csv';
        file_put_contents($csv, "Code,Qty,Price\r\nCL-5,4,4.10\r\n,Carriage,\r\n");
        $imp = $desk->postMultipart("/ui/receiving/{$id}/import", ['mode' => 'append'] + $page->form('/import'), ['file' => ['path' => $csv, 'name' => 'sheet.csv']]);
        self::assertSame(200, $imp->status, $imp->describe());
        self::assertStringContainsString('Lines imported from the supplier\'s sheet. 1 row without an item code skipped', $imp->text());
        self::assertSame('4', $imp->form('/lines')['line_1_packs']);
        file_put_contents($csv, "Code,Qty\r\nNOPE,4\r\n");
        $bad = $desk->postMultipart("/ui/receiving/{$id}/import", ['mode' => 'replace'] + $imp->form('/import'), ['file' => ['path' => $csv, 'name' => 'bad.csv']]);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('row 2, supplier code: this supplier has no active item with the code NOPE', $bad->text());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM grn_line WHERE document_id = ?', [$id]), 'nothing replaced');

        // Post it (invoice, bench by the same person: allowed, it is the review that needs another), then reverse it.
        $up = $desk->postMultipart("/ui/receiving/{$id}/files", ['role' => 'supplier_invoice'] + $bad->form('/files'), ['file' => ['path' => $this->pdf(), 'name' => 'inv.pdf']]);
        self::assertSame(303, $up->status, $up->describe());
        $bv = $desk->get("/ui/receiving/{$id}/bench");
        $desk->post("/ui/receiving/{$id}/bench", ['paperwork_ok' => '1', 'b_1_ok' => '1'] + $bv->form("/ui/receiving/{$id}/bench"));
        $r = $desk->post("/ui/receiving/{$id}/lines", ['action' => 'post'] + $desk->get("/ui/receiving/{$id}")->form('/lines'));
        self::assertSame("/ui/receiving/{$id}?notice=posted", $r->location(), $r->describe());
        $view = $desk->follow($r);
        $rev = $desk->post("/ui/receiving/{$id}/reverse", ['reason_code' => 'entered_in_error'] + $view->form("/ui/receiving/{$id}/reverse"));
        self::assertSame("/ui/receiving/{$id}?notice=reversed", $rev->location(), $rev->describe());
        self::assertStringContainsString('Reversed by GRN-000002', $desk->follow($rev)->text());
        self::assertSame(0, (int) self::$db->value('SELECT COALESCE(SUM(on_hand), 0) FROM stock_balance WHERE sku_id = ?', [$coil]));

        // A draft cancelled: its invoice number is free again.
        $list = $desk->get('/ui/receiving');
        $r = $desk->post('/ui/receiving', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'SHEET-2'] + $list->form('/ui/receiving'));
        $id2 = self::receiptId($r->location());
        $c = $desk->post("/ui/receiving/{$id2}/cancel", ['reason' => 'keyed by mistake'] + $desk->follow($r)->form('/cancel'));
        self::assertSame("/ui/receiving/{$id2}?notice=cancelled", $c->location(), $c->describe());
        self::assertSame('cancelled', self::$db->value('SELECT status FROM document WHERE id = ?', [$id2]));
        $dup = $desk->post('/ui/receiving', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'sheet-1'] + $desk->get('/ui/receiving')->form('/ui/receiving'));
        self::assertSame(303, $dup->status, 'SHEET-1 was reversed: free again');
        $dup2 = $desk->post('/ui/receiving', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'SHEET -1'] + $desk->get('/ui/receiving')->form('/ui/receiving'));
        self::assertSame(409, $dup2->status);
        self::assertStringContainsString('is already on draft receipt', $dup2->text());
    }

    public function testWhoSeesAndDoesWhat(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier($buyer['id'], 'Screen Roles Ltd');
        $b = $this->signIn($buyer);
        foreach (['/ui/receiving', '/ui/receiving/bench', '/ui/receiving/incidents'] as $p) {
            self::assertSame(403, $b->get($p)->status, $p);
        }
        $reviewer = $this->signIn($this->uiUser('reviewer'));
        $rl = $reviewer->get('/ui/receiving');
        self::assertSame(200, $rl->status);
        self::assertFalse($rl->hasForm('/ui/receiving') && str_contains($rl->body, 'name="form_key"'), 'no new-delivery form for a reviewer');
        self::assertSame(403, $reviewer->post('/ui/receiving', ['csrf' => $this->token($reviewer), 'supplier_id' => (string) $s['id'], 'form_key' => str_repeat('a', 32)])->status);
        self::assertSame(403, $reviewer->get('/ui/receiving/bench')->status);
        self::assertSame(200, $reviewer->get('/ui/receiving/incidents')->status);
        $controller = $this->signIn($this->uiUser('stock_controller'));
        self::assertSame(200, $controller->get('/ui/receiving/incidents')->status);
        $admin = $this->signIn($this->uiUser('admin'));
        self::assertSame(403, $admin->get('/ui/receiving')->status);
        // A forged form (no CSRF token) and a form without its version.
        $deskUser = $this->uiUser('purchasing_desk');
        $desk = $this->signIn($deskUser);
        self::assertSame(403, $desk->post('/ui/receiving', ['supplier_id' => (string) $s['id'], 'form_key' => str_repeat('b', 32)])->status);
        $r = $desk->post('/ui/receiving', ['supplier_id' => (string) $s['id']] + $desk->get('/ui/receiving')->form('/ui/receiving'));
        $id = self::receiptId($r->location());
        self::assertSame(400, $desk->post("/ui/receiving/{$id}/post", ['csrf' => $this->token($desk)])->status);
        // The editor's truncation guard: fewer line rows than it said.
        $sku = self::makeSku('Truncated item');
        $f = $desk->get("/ui/receiving/{$id}")->form('/lines');
        $desk->post("/ui/receiving/{$id}/lines", ['q' => 'CW-' . sprintf('%06d', $sku), 'action' => 'add'] + $f);
        $f = $desk->get("/ui/receiving/{$id}")->form('/lines');
        unset($f['line_1_packs']);
        $t = $desk->post("/ui/receiving/{$id}/lines", $f);
        self::assertSame(400, $t->status);
        self::assertStringContainsString('form_truncated', $t->text());
        // Unknown receipts.
        self::assertSame(404, $desk->get('/ui/receiving/999999')->status);
        self::assertSame(404, $desk->get('/ui/receiving/999999/bench')->status);
    }

    /** Phone and tablet width: the lists stack, the bench is one card per line with big number fields and the camera for photos. */
    public function testThePhoneAndTabletMarkup(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier($buyer['id'], 'Screen Phone Ltd');
        $sku = self::makeSku('Phone liquid');
        $this->liquid($sku);
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $r = $desk->post('/ui/receiving', ['supplier_id' => (string) $s['id']] + $desk->get('/ui/receiving')->form('/ui/receiving'));
        $id = self::receiptId($r->location());
        $desk->post("/ui/receiving/{$id}/lines", ['q' => 'CW-' . sprintf('%06d', $sku), 'action' => 'add'] + $desk->follow($r)->form('/lines'));
        self::assertStringContainsString('<table class="stack receipts">', $desk->get('/ui/receiving')->body);
        $bv = $desk->get("/ui/receiving/{$id}/bench");
        self::assertStringContainsString('<article class="bench-line card unchecked" id="line-1" data-codes="', $bv->body);
        self::assertStringContainsString('inputmode="numeric"', $bv->body);
        self::assertStringContainsString('capture="environment"', $bv->body);
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $bv->body);
        self::assertStringContainsString('duty stamp required', $bv->text());
    }
}
