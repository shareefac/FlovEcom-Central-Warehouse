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
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

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
        self::assertSame(['label' => 'Receive + invoice', 'href' => '/ui/receiving'], self::nav($list)['Deliveries'][0], 'the menu\'s Deliveries section');
        self::assertStringContainsString(Words::RECEIVING['none'], $list->text());
        $form = ['po_id' => (string) $po->id, 'invoice_number' => 'SCR-001', 'copy' => '1'] + $list->form('/ui/receiving');
        $r = $desk->post('/ui/receiving', $form);
        self::assertSame(303, $r->status, $r->describe());
        $id = self::receiptId($r->location());
        self::assertSame($r->location(), $desk->post('/ui/receiving', $form)->location(), 'the same form again lands on the same receipt');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'GRN'"));

        $ed = $desk->follow($r);
        self::assertSame(200, $ed->status, $ed->describe());
        self::assertStringContainsString(Words::RECEIPT_NOTICE['copied'], $ed->text());
        $f = $ed->form('/lines');
        self::assertSame(['csrf', 'version', 'line_count', 'lines_editable'], array_slice(array_keys($f), 0, 4), 'version and line_count come first');
        self::assertSame(['1', '3', 'default', '1'], [$f['line_count'], $f['line_1_packs'], $f['line_1_mode'], $f['line_1_po']]);
        // The box's own barcode: one more box of 5 (Enter in the scan box adds and saves the table).
        $r = $desk->post("/ui/receiving/{$id}/lines", ['q' => '5012345678917', 'action' => 'add'] + $f);
        self::assertSame("/ui/receiving/{$id}?notice=incremented&line=1&u=5#line-1", $r->location(), $r->describe());
        $added = $desk->follow($r);
        self::assertStringContainsString('Line 1, ' . self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]) . ' Screen box liquid 10ml: +5 items (now 4 packs of 5 = 20 items)',
            $added->text(), 'the notice names the line, the item and the units added (I173), in items (U85)');
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
        self::assertStringContainsString('= 15 items ' . Words::BENCH['on_paperwork'], $bv->text());
        $bf = $bv->form("/ui/receiving/{$id}/bench", true);
        self::assertArrayHasKey('b_1_stamp', $bf);
        $notMine = $bench->post("/ui/receiving/{$id}/lines", ['csrf' => $this->token($bench), 'action' => 'save'] + $page->form('/lines'));
        self::assertSame(403, $notMine->status, 'only the desk person who keyed it changes its lines');
        self::assertStringContainsString(Words::RECEIPT_ERROR['not_creator'], $notMine->text());
        $saved = $bench->post("/ui/receiving/{$id}/bench", ['paperwork_ok' => '1', 'b_1_stamp' => '1', 'b_1_type' => 'digital', 'b_1_damaged' => '2'] + $bf);
        self::assertSame("/ui/receiving/{$id}/bench?notice=checked#line-1", $saved->location(), $saved->describe());
        $stale = $bench->post("/ui/receiving/{$id}/bench", ['paperwork_ok' => '0', 'b_1_damaged' => '3'] + $bf);
        self::assertSame(409, $stale->status, 'a check drawn before the other bench save is redrawn, with what was typed');
        self::assertStringContainsString('someone saved this delivery since you opened this page', $stale->text());
        self::assertSame(['0', '3'], [$stale->form("/ui/receiving/{$id}/bench", true)['paperwork_ok'], $stale->form("/ui/receiving/{$id}/bench", true)['b_1_damaged']]);

        // Posted from the editor: 13 into MAIN, 2 into VERIFY, an incident; the PO part-received.
        $f = $desk->get("/ui/receiving/{$id}")->form('/lines');
        $r = $desk->post("/ui/receiving/{$id}/lines", ['action' => 'post'] + $f);
        self::assertSame("/ui/receiving/{$id}?notice=posted", $r->location(), $r->describe());
        $view = $desk->follow($r);
        self::assertStringContainsString('GRN-000001', $view->text());
        self::assertStringContainsString('13 into stock, 2 set aside to check', $view->text(), 'where the items went, in words (U85)');
        self::assertFalse($view->hasForm("/ui/receiving/{$id}/lines"), 'a posted receipt is never edited');
        self::assertSame([13, 2], [(int) self::$db->value("SELECT on_hand FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id WHERE w.code = 'MAIN' AND b.sku_id = ?", [$sku]),
            (int) self::$db->value("SELECT on_hand FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id WHERE w.code = 'VERIFY' AND b.sku_id = ?", [$sku])]);
        self::assertSame('part_received', self::$db->value('SELECT state FROM purchase_order WHERE document_id = ?', [$po->id]));

        // The review: the bench checker (also a reviewer) is told why not; another reviewer decides on the receipt's page.
        self::assertStringContainsString(Words::RECEIPT['refusal_bench'], $bench->get("/ui/receiving/{$id}")->text());
        // ... and is never offered it (I133 in Documents::decidableCounts, which the redesign's badge and Home cards share): one check
        // fewer than another reviewer, in the counts, the "Waiting for me" badge and Home's "Deliveries booked in to check" card (U87).
        $otherReviewer = $this->uiUser('reviewer');
        $benchCounts = $docs->decidableCounts($benchUser['id'], ['goods_in', 'reviewer']);
        $otherCounts = $docs->decidableCounts($otherReviewer['id'], ['reviewer']);
        self::assertGreaterThanOrEqual(1, $otherCounts['review'], 'the receipt waits for a review');
        self::assertSame([$otherCounts['review'] - 1, $otherCounts['approval']], [$benchCounts['review'], $benchCounts['approval']],
            'the bench checker is not offered the receipt it checked');
        self::assertSame(array_sum($benchCounts), $docs->decidableCount($benchUser['id'], ['goods_in', 'reviewer']));
        $reviewer = $this->signIn($otherReviewer);
        $shown = static function (\CW\Tests\Support\UiResponse $home): array {
            $label = self::nav($home)['To check'][0]['label'];
            $card = (new \DOMXPath($home->dom()))->evaluate('string(//li[@data-card="deliveries_check"]//p[@class="count"]/text()[1])');
            return [preg_match('/(\d+)$/', $label, $m) === 1 ? (int) $m[1] : 0, (int) trim((string) $card)];
        };
        $benchShown = $shown($bench->get('/ui/'));
        $otherShown = $shown($reviewer->get('/ui/'));
        self::assertGreaterThanOrEqual(1, $otherShown[0], 'another reviewer\'s badge counts the receipt');
        self::assertGreaterThanOrEqual(1, $otherShown[1], 'another reviewer\'s Home card counts the receipt');
        self::assertSame([1, 0], [$docs->decidableCountsByType($otherReviewer['id'], ['reviewer'])['GRN']['review'] ?? 0,
            $docs->decidableCountsByType($benchUser['id'], ['goods_in', 'reviewer'])['GRN']['review'] ?? 0], 'the deliveries part of the counts (Home\'s own card)');
        self::assertSame([$otherShown[0] - 1, $otherShown[1] - 1], $benchShown, 'the bench checker\'s badge and Home card do not');
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
        self::assertStringContainsString('Damaged: 2 items of Screen box liquid 10ml', $inc->text());
        $incId = (int) self::$db->value('SELECT id FROM incident WHERE document_id = ?', [$id]);
        $closed = $desk->post("/ui/receiving/incidents/{$incId}", ['status' => 'resolved', 'note' => 'credit note asked for'] + $inc->form("/ui/receiving/incidents/{$incId}"));
        self::assertSame('/ui/receiving/incidents?notice=resolved', $closed->location(), $closed->describe());
        self::assertSame(['resolved', 'credit note asked for'], array_values((array) self::$db->one('SELECT status, resolution FROM incident WHERE id = ?', [$incId])));
        self::assertStringContainsString(Words::INCIDENTS['none_open'], $desk->get('/ui/receiving/incidents')->text());
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
        self::assertStringContainsString(Words::say('RECEIPT_ADDED', 'cleared_one', 'line 1'), $desk->follow($q)->text());
        $stale = $bench->post("/ui/receiving/{$id}/bench", ['bench_note' => 'pallet wrapped', 'b_1_damaged' => '1'] + $bf);
        self::assertSame(409, $stale->status, $stale->describe());
        self::assertStringContainsString(Words::say('RECEIPT_ERROR', 'bench_except', 'line 1'), $stale->text());
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
        self::assertStringContainsString(Words::RECEIPT_NOTICE['imported'] . ' ' . Words::RECEIPT_ADDED['skipped_one'], $imp->text());
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
        self::assertStringContainsString(Words::say('RECEIPT', 'reversed_by', 'GRN-000002'), $desk->follow($rev)->text());
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
        self::assertStringContainsString(' is already on delivery #', $dup2->text(), 'the other delivery, in words (U86)');
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
        self::assertSame('form_truncated', $t->errorCode());
        self::assertStringContainsString(Words::say('RECEIPT_ERROR', 'form_truncated_lines', 1, 0, \CW\Ui\Controller\ReceivingController::editorMaxLines()), $t->text(), 'the editor\'s own words (U86)');
        self::assertStringNotContainsString('form_truncated', $t->text(), 'the code is not printed (plan F041)');
        // Unknown receipts.
        self::assertSame(404, $desk->get('/ui/receiving/999999')->status);
        self::assertSame(404, $desk->get('/ui/receiving/999999/bench')->status);
    }

    /**
     * The plain-words pass of the delivery pages (U85-U90), for each job: every page has its title and its one-sentence intro (for a
     * person who can only look: who changes it), no code, no "UTC", no "MiB", no "(GBP)", no stock-place code (VERIFY, UNSTAMPED,
     * MAIN) and no "receipt" outside the "?" that names them for older notes, and every table turns into cards on a phone. Home has
     * the delivery cards for the jobs that act on them: the bench, the checked delivery to book in, the delivery booked in to check
     * (never for its bench checker), the open incidents.
     */
    public function testTheDeliveryPagesSpeakPlainWordsForEachJob(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $benchUser = $this->uiUser('goods_in');
        $reviewerUser = $this->uiUser('reviewer');
        $controllerUser = $this->uiUser('stock_controller');
        $auditorUser = $this->uiUser('auditor');
        $accountantUser = $this->uiUser('accountant');
        self::$db->exec("UPDATE staff_user SET display_name = CONCAT('Person ', id) WHERE display_name LIKE '%\\_%'");
        $s = $this->supplier($buyer['id'], 'Plain Delivery Supplies');
        $sku = self::makeSku('Plain delivery liquid 10ml');
        $this->liquid($sku);
        $si = (new SupplierItems(self::$db))->create(Caller::staff($buyer['id']), (int) $s['id'], $sku, ['units_per_pack' => '5', 'supplier_code' => 'PD5'], ['pack_price' => '10.00']);
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $pos = new PurchaseOrders(self::$db, $docs);
        $po = $pos->createDraft(Caller::staff($buyer['id']), (int) $s['id'], []);
        $po = $pos->saveDraft(Caller::staff($buyer['id']), $po->id, $po->version, [], [['supplier_item_id' => (int) $si['id'], 'packs' => 3]]);
        $po = $pos->approve(Caller::staff($buyer['id']), $po->id, $po->version);

        $desk = $this->signIn($deskUser, $this->browser('198.51.100.71'));
        $bench = $this->signIn($benchUser, $this->browser('198.51.100.72'));
        $reviewer = $this->signIn($reviewerUser, $this->browser('198.51.100.73'));
        $controller = $this->signIn($controllerUser, $this->browser('198.51.100.74'));
        $auditor = $this->signIn($auditorUser, $this->browser('198.51.100.75'));
        $accountant = $this->signIn($accountantUser, $this->browser('198.51.100.76'));
        $r = $desk->post('/ui/receiving', ['po_id' => (string) $po->id, 'invoice_number' => 'PLAIN-1', 'copy' => '1'] + $desk->get('/ui/receiving')->form('/ui/receiving'));
        $id = self::receiptId($r->location());
        $desk->postMultipart("/ui/receiving/{$id}/files", ['role' => 'supplier_invoice'] + $desk->get("/ui/receiving/{$id}")->form('/files'),
            ['file' => ['path' => $this->pdf(), 'name' => 'PLAIN-1.pdf']]);
        $draftTitle = Words::say('RECEIPT', 'title_draft', 'Plain Delivery Supplies');
        $card = static fn (UiResponse $home, string $key): ?int => ($c = (new \DOMXPath($home->dom()))->query("//li[@data-card='{$key}']")) !== false && $c->length > 0
            ? (int) trim((string) (new \DOMXPath($home->dom()))->evaluate("string(//li[@data-card='{$key}']//p[@class='count']/text()[1])")) : null;

        // Before the bench: the desk's editor, the bench's list and check, everyone else's look.
        foreach ([
            [$desk, '/ui/receiving', Words::MENU['receiving'], 'receiving', false],
            [$desk, "/ui/receiving/{$id}", $draftTitle, 'receipt_draft', false],
            [$bench, '/ui/receiving/bench', Words::MENU['bench'], 'bench', false],
            [$bench, "/ui/receiving/{$id}/bench", Words::say('BENCH', 'title', 'Plain Delivery Supplies'), 'receipt_bench', false],
            [$bench, "/ui/receiving/{$id}", $draftTitle, 'receipt_other', false],
            [$reviewer, '/ui/receiving', Words::MENU['receiving'], 'receiving', true],
            [$controller, "/ui/receiving/{$id}", $draftTitle, 'receipt_other', true],
            [$accountant, '/ui/receiving', Words::MENU['receiving'], 'receiving', true],
        ] as $n => [$web, $path, $title, $intro, $lookOnly]) {
            self::checkPage($web->get($path), "{$path} #{$n}", $title, $intro, $lookOnly);
        }
        self::assertSame(1, $card($bench->get('/ui/'), 'bench'), 'goods in: a delivery waits for the bench');
        self::assertSame(1, $card($desk->get('/ui/'), 'bench'));
        self::assertNull($card($desk->get('/ui/'), 'to_post'), 'not checked yet: nothing to book in');

        // The bench first says the paperwork is not right: the desk's Home asks it to cancel the delivery (the list's "refused" filter),
        // and the bench's card stops counting it. The bench then says it was wrong and checks it again (below).
        $refused = $bench->post("/ui/receiving/{$id}/bench", ['paperwork_ok' => '0'] + $bench->get("/ui/receiving/{$id}/bench")->form("/ui/receiving/{$id}/bench"));
        self::assertSame(303, $refused->status, $refused->describe());
        self::assertSame([null, 1, null], [$card($desk->get('/ui/'), 'bench'), $card($desk->get('/ui/'), 'bench_refused'), $card($desk->get('/ui/'), 'to_post')]);
        self::assertContains("/ui/receiving/{$id}", $desk->get('/ui/receiving', ['state' => 'refused'])->hrefs());
        self::assertNotContains("/ui/receiving/{$id}", $desk->get('/ui/receiving', ['state' => 'checked'])->hrefs());

        // The bench checks it (2 damaged): the desk has a delivery to book in, and the list's "checked" filter shows it.
        $bf = $bench->get("/ui/receiving/{$id}/bench")->form("/ui/receiving/{$id}/bench");
        $saved = $bench->post("/ui/receiving/{$id}/bench", ['paperwork_ok' => '1', 'b_1_ok' => '1', 'b_1_stamp' => '1', 'b_1_type' => 'digital', 'b_1_damaged' => '2'] + $bf);
        self::assertSame(303, $saved->status, $saved->describe());
        self::assertSame([null, null, 1], [$card($desk->get('/ui/'), 'bench'), $card($desk->get('/ui/'), 'bench_refused'), $card($desk->get('/ui/'), 'to_post')]);
        self::assertContains("/ui/receiving/{$id}", $desk->get('/ui/receiving', ['state' => 'checked'])->hrefs());
        self::assertNotContains("/ui/receiving/{$id}", $desk->get('/ui/receiving', ['state' => 'refused'])->hrefs());
        self::assertNotContains("/ui/receiving/{$id}", $desk->get('/ui/receiving', ['state' => 'posted'])->hrefs());

        // Booked in: the delivery's page for each job, the review, the incidents.
        $r = $desk->post("/ui/receiving/{$id}/lines", ['action' => 'post'] + $desk->get("/ui/receiving/{$id}")->form('/lines'));
        self::assertSame("/ui/receiving/{$id}?notice=posted", $r->location(), $r->describe());
        $number = (string) self::$db->value('SELECT number FROM document WHERE id = ?', [$id]);
        $title = Words::say('RECEIPT', 'title', $number, 'Plain Delivery Supplies');
        foreach ([
            [$desk, "/ui/receiving/{$id}", $title, 'receipt', false],
            [$reviewer, "/ui/receiving/{$id}", $title, 'receipt', false],
            [$auditor, "/ui/receiving/{$id}", $title, 'receipt', false],
            [$desk, '/ui/receiving/incidents', Words::MENU['incidents'], 'incidents', false],
            [$controller, '/ui/receiving/incidents', Words::MENU['incidents'], 'incidents', false],
            [$bench, '/ui/receiving/incidents', Words::MENU['incidents'], 'incidents', true],
            [$auditor, '/ui/receiving/incidents?status=all', Words::MENU['incidents'], 'incidents', true],
            [$desk, '/ui/receiving', Words::MENU['receiving'], 'receiving', false],
        ] as $n => [$web, $path, $title2, $intro, $lookOnly]) {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            self::checkPage($web->get((string) parse_url($path, PHP_URL_PATH), array_map('strval', $query)), "{$path} #{$n} after", $title2, $intro, $lookOnly);
        }
        $page = $desk->get("/ui/receiving/{$id}");
        self::assertStringContainsString('13 into stock, 2 set aside to check', $page->text());
        $rv = $reviewer->get("/ui/receiving/{$id}");
        self::assertStringContainsString(Words::RECEIPT['not_ok_does'], $rv->text(), 'what each answer does');
        self::assertTrue($rv->hasForm('/approve'));
        $home = $reviewer->get('/ui/');
        self::assertSame(1, $card($home, 'deliveries_check'), 'the reviewer: a delivery booked in to check');
        $badge = preg_match('/(\d+)$/', self::nav($home)['To check'][0]['label'], $m) === 1 ? (int) $m[1] : 0;
        self::assertSame($badge, ($card($home, 'approvals') ?? 0) + ($card($home, 'checks') ?? 0) + 1, 'the cards add up to the "Waiting for me" badge');
        self::assertSame(1, $card($desk->get('/ui/'), 'incidents'), 'the desk closes the damaged incident');
        self::assertSame(1, $card($controller->get('/ui/'), 'incidents'));
        self::assertNull($card($bench->get('/ui/'), 'incidents'), 'goods in looks at incidents but does not close them');
    }

    /** One delivery page: its title, its intro (or who changes it), no codes or technical words, tables as cards. */
    private static function checkPage(UiResponse $page, string $where, string $title, string $intro, bool $lookOnly): void
    {
        self::assertSame(200, $page->status, $where . ': ' . $page->describe());
        $xp = new \DOMXPath($page->dom());
        self::assertSame($title, trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate('string(//main//h1)'))), "{$where}: the title");
        $lede = trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate('string(//main//p[@class="lede"])')));
        self::assertStringStartsWith(Words::PAGE_INTRO[$intro][0], $lede, "{$where}: the intro");
        if ($lookOnly) {
            self::assertStringContainsString('You can look;', $lede, "{$where}: who changes it, for a person who can only look");
        }
        foreach ($xp->query('//main//table') ?: [] as $table) {
            /** @var \DOMElement $table */
            self::assertMatchesRegularExpression('/\bstack\b/', $table->getAttribute('class'), "{$where}: a table that turns into cards on a phone");
        }
        // What a person reads: the "?" texts (they name VERIFY and UNSTAMPED for older notes), <code> (a stamp code), e-mails are kept apart.
        foreach (iterator_to_array($xp->query('//main//code | //main//details[contains(@class, "help")]') ?: []) as $kept) {
            $kept->parentNode?->removeChild($kept);
        }
        $text = (string) preg_replace('/\S+@\S+/', '', trim((string) preg_replace('/\s+/u', ' ', (string) $xp->query('//main')->item(0)?->textContent)));
        self::assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\b/', $text, "{$where}: a code on the screen (rule 2)");
        foreach (['UTC', 'MiB', '(GBP)', 'VERIFY', 'UNSTAMPED', ' MAIN', 'receipt', 'Receipt', 'item card', 'posted', 'Post the', 'credible', 'units'] as $word) {
            self::assertStringNotContainsString($word, $text, "{$where}: \"{$word}\"");
        }
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
        self::assertStringContainsString('<table class="stack list receipts">', $desk->get('/ui/receiving')->body, 'B\'s list cards on a phone');
        $bv = $desk->get("/ui/receiving/{$id}/bench");
        self::assertStringContainsString('<article class="bench-line card unchecked" id="line-1" data-codes="', $bv->body);
        self::assertStringContainsString('inputmode="numeric"', $bv->body, 'the count fields open the number keypad');
        self::assertDoesNotMatchRegularExpression('/id="bench-find"[^>]*inputmode=/', $bv->body, '"Find a line" takes letters too (CW numbers, supplier codes)');
        self::assertStringContainsString('capture="environment"', $bv->body);
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">', $bv->body, 'design A\'s frame');
        self::assertStringContainsString(Words::BENCH['stamp_needed'], $bv->text());
        self::assertStringContainsString('data-not-here="', $bv->body, 'the bench\'s "not on this page" in words, for app.js');
        self::assertStringContainsString('<div class="actions sticky">', $desk->get("/ui/receiving/{$id}")->body, 'the editor\'s save bar stays in reach');
    }

    /** One bottle scanned from a supplier of boxes: the question names the product by its name (U86), though it is not on the delivery yet. */
    public function testAOneUnitScanAsksInTheProductsName(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier($buyer['id'], 'Choice Box Supplies');
        $sku = self::makeSku('Choice liquid 10ml');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES ('5012345678948', ?, 1)", [$sku]);
        (new SupplierItems(self::$db))->create(Caller::staff($buyer['id']), (int) $s['id'], $sku, ['units_per_pack' => '10', 'supplier_code' => 'BOX10'], ['pack_price' => '18.00']);
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $r = $desk->post('/ui/receiving', ['supplier_id' => (string) $s['id']] + $desk->get('/ui/receiving')->form('/ui/receiving'));
        $id = self::receiptId($r->location());
        $asked = $desk->post("/ui/receiving/{$id}/lines", ['q' => '5012345678948', 'action' => 'add'] + $desk->follow($r)->form('/lines'));
        self::assertSame(200, $asked->status, $asked->describe());
        self::assertStringContainsString(Words::say('RECEIPT_PLAN', 'one_unit', 'Choice liquid 10ml', 'packs of 10'), $asked->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM grn_line WHERE document_id = ?', [$id]), 'asked, nothing added yet');
    }
}
