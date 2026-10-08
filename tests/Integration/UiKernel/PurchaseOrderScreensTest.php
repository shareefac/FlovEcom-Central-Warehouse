<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Output\XlsxWriter;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Controller\PurchaseOrdersController;
use CW\Ui\Kernel;
use CW\Ui\PoWarnings;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * The purchase order screens through the real /ui kernel as cw_app (spec §8.1, §8.3, §9.2; I48-I59) — the owner's step
 * "a draft PO -> PDF": a new order by form (sent twice: one draft), the one-form editor (a scan and the table's edits in
 * one submit, a repeated scan adding a pack, an ambiguous search with its choices, the truncation guard), the lines file
 * (CSV and XLSX import, append and replace, the all-or-nothing error list; the exports), approval, the PDF download, send,
 * cancel, amend, the read-only views of the desk and the reviewer, an over-limit approval decided on the order's page, the
 * review queue's type filter and £ units, and the document page's link.
 */
final class PurchaseOrderScreensTest extends KernelUiTestCase
{
    private string $dir;
    private string|false $prevStore = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_pos_ui_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        mkdir($this->dir . '/store', 0700);
        // The PDF as sent goes to a temporary file store, never to the server's own (app.env file_store_dir).
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
    }

    protected function tearDown(): void
    {
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** An active supplier created by $buyerId and approved by a fresh reviewer (through the services). @return array<string, mixed> */
    private function supplier(int $buyerId, string $name): array
    {
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyerId), ['name' => $name, 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA', 'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example',
            'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400), 'dd_checked_by' => (string) $buyerId,
            'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400), 'min_order_value' => '100.00']);
        $s = $sup->requestActivation(Caller::staff($buyerId), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    /** @return array<string, mixed> */
    private function supplierItem(int $buyerId, int $supplierId, int $sku, int $upp, ?string $price, string $code): array
    {
        return (new SupplierItems(self::$db))->create(Caller::staff($buyerId), $supplierId, $sku, ['units_per_pack' => (string) $upp, 'supplier_code' => $code],
            $price === null ? null : ['pack_price' => $price]);
    }

    private static function orderId(?string $location): int
    {
        self::assertSame(1, preg_match('#^/ui/purchasing/orders/(\d+)#', (string) $location, $m), (string) $location);
        return (int) $m[1];
    }

    public function testDraftScanEditImportApprovePdfSend(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $bid = $buyerUser['id'];
        $s = $this->supplier($bid, 'Screen Supplies');
        $pod = self::makeSku('Screen pod 2ml');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES ('5012345000011', ?, 1)", [$pod]);
        $cherry = self::makeSku('Screen liquid cherry');
        $cherryIce = self::makeSku('Screen liquid cherry ice');
        $siPod = $this->supplierItem($bid, (int) $s['id'], $pod, 10, '12.5000', 'POD-10');
        $siCherry = $this->supplierItem($bid, (int) $s['id'], $cherry, 6, '9.0000', 'CH-6');
        $buyer = $this->signIn($buyerUser);

        // The list and the new-order form; the same form twice: one draft.
        $list = $buyer->get('/ui/purchasing/orders');
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(['label' => 'Purchase Orders', 'href' => '/ui/purchasing/orders', 'count' => 0, 'current' => true], self::sectionTabs($list)[1]);
        self::assertSame(['/ui/purchasing/orders#new', '/ui/purchasing/suppliers/new'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array((new \DOMXPath($list->dom()))->query('//form[@class="toolbar"]//div[@class="split"]//a'))), 'the toolbar\'s "New purchase order" leads to the form');
        self::assertSame(1, (new \DOMXPath($list->dom()))->query('//*[@id="new"]//form[@action="/ui/purchasing/orders"]')->length);
        self::assertStringContainsString(Words::ORDERS['none'], $list->text());
        self::assertStringContainsString(Words::ORDERS['none_buyer'], $list->text(), 'an empty list says what to do (F304)');
        $form = ['supplier_id' => (string) $s['id']] + $list->form('/ui/purchasing/orders');
        $r = $buyer->post('/ui/purchasing/orders', $form);
        self::assertSame(303, $r->status, $r->describe());
        $id = self::orderId($r->location());
        $again = $buyer->post('/ui/purchasing/orders', $form);
        self::assertSame($r->location(), $again->location(), 'a replay lands on the same draft');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'PO'"));

        // The editor: scan (Enter = Add) and edit in one form.
        $ed = $buyer->follow($r);
        self::assertSame(200, $ed->status, $ed->describe());
        self::assertStringContainsString(Words::PO_NOTICE['created'], $ed->text());
        $f = $ed->form('/lines');
        self::assertSame(['csrf', 'version', 'line_count', 'lines_editable'], array_slice(array_keys($f), 0, 4), 'version and line_count come first');
        $r = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['q' => '5012345000011', 'action' => 'add', 'external_ref' => 'Q-55'] + $f);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=added#scan", $r->location(), $r->describe());
        $ed = $buyer->get("/ui/purchasing/orders/{$id}");
        $f = $ed->form('/lines');
        self::assertSame(['1', '1', 'Q-55'], [$f['line_count'], $f['line_1_packs'], $f['external_ref']]);
        // Edit packs and price of line 1 and scan again in the same submit: the edit is saved, the repeat scan adds a pack.
        $r = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['line_1_packs' => '4', 'line_1_price' => '12.00', 'q' => '5012345000011', 'action' => 'add'] + $f);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=incremented#scan", $r->location(), $r->describe());
        $l1 = self::$db->one('SELECT packs, pack_price FROM po_line WHERE document_id = ? AND line_no = 1', [$id]) ?? [];
        self::assertSame([5, '12.0000'], [(int) $l1['packs'], $l1['pack_price']], 'the edit saved, then one more pack');
        self::assertStringContainsString('is below the smallest order Screen Supplies takes (£100.00).', $buyer->get("/ui/purchasing/orders/{$id}")->text(),
            'a warning in words, with the supplier\'s name (F262), not a refusal');
        // An ambiguous search: the choices, then a choice.
        $f = $buyer->get("/ui/purchasing/orders/{$id}")->form('/lines');
        $c = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['q' => 'liquid cherry', 'action' => 'add'] + $f);
        self::assertSame(200, $c->status, $c->describe());
        self::assertStringContainsString(Words::say('ORDER', 'choose', 'liquid cherry'), $c->text());
        self::assertStringContainsString(Words::say('ORDER', 'choice_set_up', 'CH-6', PurchaseOrdersController::pack('each', 6)), $c->text());
        self::assertStringContainsString(Words::ORDER['choice_new'], $c->text());
        $f = $c->form('/lines');
        $r = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['add_si' => (string) $siCherry['id'], 'packs' => '2'] + $f);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=added#scan", $r->location(), $r->describe());
        $f = $buyer->get("/ui/purchasing/orders/{$id}")->form('/lines');
        $r = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['add_sku' => (string) $cherryIce] + $f);
        self::assertSame(303, $r->status, $r->describe());
        $nf = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['q' => 'zzzz no such', 'action' => 'add'] + $buyer->get("/ui/purchasing/orders/{$id}")->form('/lines'));
        self::assertSame(422, $nf->status);
        self::assertStringContainsString(Words::say('BUY_ERROR', 'not_found', 'zzzz no such'), $nf->text());
        self::assertSame([[1, (int) $siPod['id'], 5], [2, (int) $siCherry['id'], 2], [3, null, 1]], array_map(static fn (array $l): array => [(int) $l['line_no'],
            $l['supplier_item_id'] === null ? null : (int) $l['supplier_item_id'], (int) $l['packs']], self::$db->all('SELECT line_no, supplier_item_id, packs FROM po_line '
            . 'WHERE document_id = ? ORDER BY line_no', [$id])));

        // Save: packs 0 removes a line; a charge; a stale version is redrawn (409); a truncated form is refused (400).
        $ed = $buyer->get("/ui/purchasing/orders/{$id}");
        $f = $ed->form('/lines');
        $saved = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['line_3_packs' => '0', 'charge_description' => 'Delivery', 'charge_amount' => '7.50', 'action' => 'save'] + $f);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=saved#scan", $saved->location(), $saved->describe());
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$id]));
        self::assertSame('charge', self::$db->value('SELECT kind FROM po_line WHERE document_id = ? AND line_no = 3', [$id]));
        $stale = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['action' => 'save'] + $f);
        self::assertSame(409, $stale->status);
        self::assertStringContainsString(Words::BUY_ERROR['version_conflict'], $stale->text());
        $f = $buyer->get("/ui/purchasing/orders/{$id}")->form('/lines');
        $cut = $f;
        unset($cut['line_3_packs'], $cut['line_3_price'], $cut['line_3_note']);
        $t = $buyer->post("/ui/purchasing/orders/{$id}/lines", $cut);
        self::assertSame(400, $t->status);
        self::assertSame('form_truncated', $t->errorCode());
        $bad = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['line_1_price' => 'twelve'] + $f);
        self::assertSame(422, $bad->status);
        self::assertSame('twelve', $bad->form('/lines')['line_1_price'], 'what was typed is shown again');

        // The lines file: CSV append (packs add up on the same supplier item), XLSX replace, an all-or-nothing refusal.
        $csv = "{$this->dir}/lines.csv";
        file_put_contents($csv, "cw_code,supplier_code,packs,units,pack_price,note\r\n" . sprintf('CW-%06d', $pod) . ",,2,20,,\r\n,CH-6,1,,8.75,promo\r\n");
        $imp = $buyer->get("/ui/purchasing/orders/{$id}")->form('/lines/import');
        self::assertSame(['csrf', 'version', 'mode'], array_keys($imp));
        $r = $buyer->postMultipart("/ui/purchasing/orders/{$id}/lines/import", ['mode' => 'append'] + $imp, ['file' => ['path' => $csv, 'name' => 'lines.csv']]);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=imported", $r->location(), $r->describe());
        self::assertSame([[1, 7], [2, 3], [3, 1]], array_map(static fn (array $l): array => [(int) $l['line_no'], (int) $l['packs']],
            self::$db->all('SELECT line_no, packs FROM po_line WHERE document_id = ? ORDER BY line_no', [$id])), 'merged into the existing lines');
        $badCsv = "{$this->dir}/bad.csv";
        file_put_contents($badCsv, "cw_code,packs,units\r\n" . sprintf('CW-%06d', $pod) . ",2,2\r\nCW-999999,1,\r\n");
        $imp = $buyer->get("/ui/purchasing/orders/{$id}")->form('/lines/import');
        $r = $buyer->postMultipart("/ui/purchasing/orders/{$id}/lines/import", ['mode' => 'replace'] + $imp, ['file' => ['path' => $badCsv, 'name' => 'bad.csv']]);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('BUY_ERROR', 'import_refused', 2), $r->text());
        self::assertStringContainsString(PoWarnings::fileRow('row 1, units: 2 is not packs × units per pack (2 × 10 = 20)'), $r->text());
        self::assertStringContainsString('Row 1 (units): 2 is not packs × units per pack (2 × 10 = 20).', $r->text(), 'the file\'s rows as sentences (F270)');
        self::assertStringContainsString(PoWarnings::fileRow('row 2, cw_code: there is no item CW-999999'), $r->text());
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$id]), 'nothing changed');
        $x = new XlsxWriter([['Supplier Code', 'text'], ['Packs', 'number'], ['Pack Price', 'number']]);
        $x->add(['POD-10', 3, '11.5'])->add(['CH-6', 24.0, null]);
        $xlsx = "{$this->dir}/lines.xlsx";
        file_put_contents($xlsx, $x->output());
        $imp = $buyer->get("/ui/purchasing/orders/{$id}")->form('/lines/import');
        $r = $buyer->postMultipart("/ui/purchasing/orders/{$id}/lines/import", ['mode' => 'replace'] + $imp, ['file' => ['path' => $xlsx, 'name' => 'lines.xlsx']]);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=imported", $r->location(), $r->describe());
        self::assertSame([[1, 3, '11.5000'], [2, 24, '9.0000']], array_map(static fn (array $l): array => [(int) $l['line_no'], (int) $l['packs'], $l['pack_price']],
            self::$db->all('SELECT line_no, packs, pack_price FROM po_line WHERE document_id = ? ORDER BY line_no', [$id])), 'replaced; the price defaults to the last price');

        // The exports.
        $dl = $buyer->get("/ui/purchasing/orders/{$id}/lines.csv");
        self::assertSame(200, $dl->status);
        self::assertSame('text/csv; charset=utf-8', $dl->header('content-type'));
        self::assertStringContainsString("filename=\"PO-draft-{$id}-lines.csv\"", (string) $dl->header('content-disposition'));
        self::assertStringContainsString('"POD-10"', $dl->body);
        $dx = $buyer->get("/ui/purchasing/orders/{$id}/lines.xlsx");
        self::assertSame(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "PK\x03\x04"], [$dx->header('content-type'), substr($dx->body, 0, 4)]);
        self::assertSame(['sandbox', Kernel::CSP], $dx->headerValues('content-security-policy'));
        $orders = $buyer->get('/ui/purchasing/orders.csv');
        self::assertStringContainsString("\"draft #{$id}\"", $orders->body);

        // Approve: numbered, then the PDF and send.
        $ed = $buyer->get("/ui/purchasing/orders/{$id}");
        self::assertStringContainsString('£250.50', $ed->text(), 'net 3 x 11.50 + 24 x 9.00');
        // The company details are not confirmed (TestDb::clean leaves no version): the draft says why its PDF says "do not send" and links
        // to the Company details screen (I96); a buyer may look, not change them.
        $note = (new \DOMXPath($ed->dom()))->query('//main//p[contains(@class, "company-note")]/a');
        self::assertSame(1, $note->length);
        self::assertSame(['/ui/reference/company', Words::ORDER['company_see']], [$note->item(0)?->getAttribute('href'), trim((string) $note->item(0)?->textContent)]);
        self::assertStringContainsString(Words::ORDER['company_not_confirmed'], $ed->text());
        // Correction f: under the approval limit the editor's main button confirms the order (it gets a PO number).
        self::assertSame(1, (new \DOMXPath($ed->dom()))->query('//form[contains(@action, "/lines")]//button[@value="approve"]//span[@class="btn-title" and normalize-space(.)="'
            . Words::ORDER['confirm_draft'] . '"]')->length);
        self::assertFalse($ed->hasForm("/ui/purchasing/orders/{$id}/approve"), 'the editor approves through its own form (review finding)');
        // Review finding: packs typed and not saved are what is approved (Approve is a button of the editor form).
        $f = $ed->form('/lines');
        self::assertSame('3', $f['line_1_packs']);
        $scanLeft = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['action' => 'approve', 'q' => 'CH-6'] + $f);
        self::assertSame(422, $scanLeft->status, 'text left in the scan box: nothing saved, nothing approved');
        self::assertStringContainsString(Words::say('BUY_ERROR', 'scan_pending', 'CH-6'), $scanLeft->text());
        $ap = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['action' => 'approve', 'line_1_packs' => '4'] + $f);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=approved", $ap->location(), $ap->describe());
        self::assertSame([4, 'posted', 'PO-000001'], [(int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$id]),
            self::$db->value('SELECT status FROM document WHERE id = ?', [$id]), self::$db->value('SELECT number FROM document WHERE id = ?', [$id])],
            'the typed 4 packs were saved and approved');
        $view = $buyer->follow($ap);
        self::assertStringContainsString('PO-000001', $view->text());
        self::assertStringContainsString(Words::say('PO_NOTICE', 'approved', 'PO-000001'), $view->text());
        self::assertSame('PO-000001 – Screen Supplies', trim((string) (new \DOMXPath($view->dom()))->evaluate('string(//main//h1)')), 'the order by number and supplier (F359-like)');
        self::assertFalse($view->hasForm('/lines'), 'no editor after approval');
        $pdf = $buyer->get("/ui/purchasing/orders/{$id}/pdf");
        self::assertSame(200, $pdf->status);
        self::assertSame('application/pdf', $pdf->header('content-type'));
        self::assertSame('attachment; filename="PO-000001.pdf"; filename*=UTF-8\'\'PO-000001.pdf', $pdf->header('content-disposition'));
        self::assertSame(['sandbox', Kernel::CSP], $pdf->headerValues('content-security-policy'));
        self::assertStringStartsWith('%PDF-', $pdf->body);
        $send = $view->form("/ui/purchasing/orders/{$id}/send");
        self::assertSame(['csrf', 'version', 'to', 'via'], array_keys($send));
        self::assertSame('sales@screensupplies.example', $send['to'], 'the supplier\'s e-mail is offered');
        // Review finding (I86): the company details are not confirmed: the form says so and asks for "send anyway", and links to them (I96).
        self::assertStringContainsString(Words::PO_WARN['send_company'], $view->text());
        self::assertSame(['/ui/reference/company'], array_map(static fn (\DOMAttr $a): string => $a->value,
            iterator_to_array((new \DOMXPath($view->dom()))->query('//form[contains(@action, "/send")]//span[@class="warnings"]/a/@href'))));
        self::assertStringContainsString(Words::ORDER['company_not_confirmed'], $view->text());
        $warned = $buyer->post("/ui/purchasing/orders/{$id}/send", $send);
        self::assertSame(409, $warned->status, $warned->describe());
        self::assertStringContainsString(Words::BUY_ERROR['send_warnings'], $warned->text());
        $send = ['send_anyway' => '1'] + $warned->form("/ui/purchasing/orders/{$id}/send");
        $r = $buyer->post("/ui/purchasing/orders/{$id}/send", $send);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=sent_archived", $r->location(), $r->describe());
        $po = self::$db->one('SELECT state, sent_file_id FROM purchase_order WHERE document_id = ?', [$id]) ?? [];
        self::assertSame('sent', $po['state']);
        $file = self::$db->one('SELECT f.kind, f.mime, f.original_name, df.role FROM stored_file f JOIN document_file df ON df.file_id = f.id WHERE f.id = ? AND df.document_id = ?',
            [(int) $po['sent_file_id'], $id]);
        self::assertSame(['generated_pdf', 'application/pdf', 'PO-000001.pdf', 'generated_pdf'], array_values((array) $file), 'the PDF as sent is kept and attached');
        $sentView = $buyer->follow($r);
        self::assertContains('/ui/files/' . $po['sent_file_id'], $sentView->hrefs());
        self::assertSame(200, $buyer->get('/ui/files/' . $po['sent_file_id'])->status);
        self::assertSame(403, $this->signIn($this->uiUser('warehouse'))->get('/ui/files/' . $po['sent_file_id'])->status, 'a PO\'s PDF needs Purchasing (I85)');
        $again = $buyer->post("/ui/purchasing/orders/{$id}/send", $send);
        self::assertSame(409, $again->status, 'the same form again: the version moved');
        // Re-sent from a server without a file store: marked, not archived (the earlier PDF stays the one kept).
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/missing');
        $r = $buyer->post("/ui/purchasing/orders/{$id}/send", ['via' => 'phone', 'send_anyway' => '1'] + $sentView->form("/ui/purchasing/orders/{$id}/send"));
        self::assertSame("/ui/purchasing/orders/{$id}?notice=sent_unarchived", $r->location(), $r->describe());
        self::assertSame([(int) $po['sent_file_id'], 'phone'], array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v,
            array_values((array) self::$db->one('SELECT sent_file_id, sent_via FROM purchase_order WHERE document_id = ?', [$id]))));
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');

        // Amend (FormOnce: one new draft) — then the original is cancelled, the new draft is the buyer's editor.
        $view = $buyer->get("/ui/purchasing/orders/{$id}");
        $am = ['reason_code' => 'po_amended'] + $view->form("/ui/purchasing/orders/{$id}/amend");
        $r = $buyer->post("/ui/purchasing/orders/{$id}/amend", $am);
        self::assertSame(303, $r->status, $r->describe());
        $newId = self::orderId($r->location());
        self::assertSame($r->location(), $buyer->post("/ui/purchasing/orders/{$id}/amend", $am)->location(), 'the replay: the same new draft');
        $orig = $buyer->get("/ui/purchasing/orders/{$id}");
        self::assertStringContainsString(Words::say('ORDER', 'cancel_record', 'PO-000002'), $orig->text());
        $nd = $buyer->follow($r);
        self::assertTrue($nd->hasForm('/lines'), 'the amendment is an editable draft');
        self::assertStringContainsString(Words::PO_NOTICE['amended'], $nd->text());
        self::assertSame(303, $buyer->get("/ui/purchasing/orders/" . (int) self::$db->value('SELECT id FROM document WHERE reverses_id = ?', [$id]))->status,
            'a cancellation document opens its order');
        // Cancel the new draft.
        $cf = ['reason_code' => 'not_needed'] + $nd->form("/ui/purchasing/orders/{$newId}/cancel");
        $r = $buyer->post("/ui/purchasing/orders/{$newId}/cancel", $cf);
        self::assertSame("/ui/purchasing/orders/{$newId}?notice=cancelled", $r->location(), $r->describe());
    }

    public function testReadOnlyViewsTheReviewerDecidesOnThePageAndTheQueue(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $bid = $buyerUser['id'];
        $s = $this->supplier($bid, 'Review Supplies');
        $sku = self::makeSku('Review item');
        $si = $this->supplierItem($bid, (int) $s['id'], $sku, 1, '10000.5000', 'BIG-1');
        $buyer = $this->signIn($buyerUser);
        $r = $buyer->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id']] + $buyer->get('/ui/purchasing/orders')->form('/ui/purchasing/orders'));
        $id = self::orderId($r->location());
        $f = $buyer->get("/ui/purchasing/orders/{$id}")->form('/lines');
        $buyer->post("/ui/purchasing/orders/{$id}/lines", ['q' => 'BIG-1', 'action' => 'add'] + $f);
        $ed = $buyer->get("/ui/purchasing/orders/{$id}");
        // Correction f (7 Oct): over the approval limit the button does not say "Confirm order": the order goes to a reviewer first.
        $button = static fn (\CW\Tests\Support\UiResponse $p): string => trim((string) (new \DOMXPath($p->dom()))->evaluate(
            'string(//form[contains(@action, "/lines")]//button[@value="approve"]//span[@class="btn-title"])'));
        self::assertSame(Words::PO['confirm_over_limit'], $button($ed));
        self::assertStringContainsString(Words::PO['confirm_over_limit_note'], $ed->text());
        $ap = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['action' => 'approve'] + $ed->form('/lines'));
        self::assertSame("/ui/purchasing/orders/{$id}?notice=submitted", $ap->location(), $ap->describe());
        $view = $buyer->follow($ap);
        self::assertStringContainsString(Words::PO_STATE['awaiting_approval'], $view->text());
        self::assertStringContainsString(Words::say('PO_NOTICE', 'submitted', '£10,000.00'), $view->text(), 'F283');
        self::assertTrue($view->hasForm("/ui/purchasing/orders/{$id}/withdraw"), 'the requester may withdraw');

        // The desk and the reviewer read the order; neither has the buyer's forms, and a POST is 403.
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $dv = $desk->get("/ui/purchasing/orders/{$id}");
        self::assertSame(200, $dv->status, $dv->describe());
        foreach (['/send', '/cancel', '/amend', '/copy', '/withdraw', '/lines'] as $a) {
            self::assertFalse($dv->hasForm("/ui/purchasing/orders/{$id}{$a}"), $a);
        }
        self::assertSame(403, $desk->post("/ui/purchasing/orders/{$id}/cancel", ['csrf' => $this->token($desk), 'version' => '1', 'reason_code' => 'not_needed'])->status);
        self::assertSame(403, $desk->post('/ui/purchasing/orders', ['csrf' => $this->token($desk), 'supplier_id' => (string) $s['id'], 'form_key' => str_repeat('a', 32)])->status);

        $reviewer = $this->signIn($this->uiUser('reviewer'));
        $queue = $reviewer->get('/ui/documents/reviews', ['type' => 'PO']);
        self::assertSame(200, $queue->status, $queue->describe());
        self::assertStringContainsString(\CW\Ui\Html::money(self::$db->value('SELECT net_total FROM purchase_order WHERE document_id = ?', [$id])), $queue->text(),
            'the order\'s value in £ (plan F095), not the task\'s whole-pound units');
        self::assertStringContainsString(\CW\Ui\Words::say('CHECKS', 'order_no_number', (string) $s['name']), $queue->text(), 'named by its supplier (F099)');
        self::assertContains("/ui/purchasing/orders/{$id}", $queue->hrefs());
        self::assertStringContainsString(\CW\Ui\Words::CHECKS['none_kind'], $reviewer->get('/ui/documents/reviews', ['type' => 'GRN'])->text());
        $rv = $reviewer->get("/ui/purchasing/orders/{$id}");
        self::assertSame(200, $rv->status);
        self::assertStringContainsString('(the limit is £10,000.00)', $rv->text());
        self::assertStringContainsString(Words::ORDER['ok_approval'], $rv->text(), 'the reviewer\'s answers say what they do (design B)');
        self::assertStringContainsString(Words::ORDER['does_not_ok_approval'], $rv->text());
        self::assertSame(403, $reviewer->post("/ui/purchasing/orders/{$id}/withdraw", ['csrf' => $this->token($reviewer), 'version' => '3'])->status);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND state = 'open'", [$id]);
        $dec = $reviewer->post("/ui/documents/reviews/{$task}/approve", $rv->form("/ui/documents/reviews/{$task}/approve"));
        self::assertSame("/ui/purchasing/orders/{$id}?notice=approved_posted", $dec->location(), $dec->describe());
        self::assertStringContainsString('PO-000001', $reviewer->follow($dec)->text());
        self::assertSame($bid, (int) self::$db->value('SELECT posted_by FROM document WHERE id = ?', [$id]), 'posted as the requester');

        // A second order, below the limit: the weekly review, rejected -> recorded; then the buyer cancels it.
        $si2 = $this->supplierItem($bid, (int) $s['id'], self::makeSku('Small item'), 1, '5.0000', 'SM-1');
        $r = $buyer->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id']] + $buyer->get('/ui/purchasing/orders')->form('/ui/purchasing/orders'));
        $id2 = self::orderId($r->location());
        $buyer->post("/ui/purchasing/orders/{$id2}/lines", ['q' => 'SM-1', 'action' => 'add', 'packs' => '30'] + $buyer->get("/ui/purchasing/orders/{$id2}")->form('/lines'));
        $buyer->post("/ui/purchasing/orders/{$id2}/lines", ['action' => 'approve'] + $buyer->get("/ui/purchasing/orders/{$id2}")->form('/lines'));
        $queue = $reviewer->get('/ui/documents/reviews', ['type' => 'PO']);
        self::assertStringContainsString('PO-000002', $queue->text());
        $task2 = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND state = 'open'", [$id2]);
        $rv2 = $reviewer->get("/ui/purchasing/orders/{$id2}");
        $rej = $reviewer->post("/ui/documents/reviews/{$task2}/reject", ['note' => 'price too high'] + $rv2->form("/ui/documents/reviews/{$task2}/reject"));
        self::assertSame("/ui/purchasing/orders/{$id2}?notice=rejected_recorded", $rej->location(), $rej->describe());
        $after = $buyer->get("/ui/purchasing/orders/{$id2}");
        self::assertStringContainsString('A reviewer said this order is not right: "price too high"', $after->text());
        self::assertStringContainsString('price too high', $after->text());
        self::assertSame(['posted', 'rejected'], array_values((array) self::$db->one('SELECT status, review_state FROM document WHERE id = ?', [$id2])));
        self::assertStringContainsString('PO-000002', $buyer->get('/ui/purchasing/orders', ['rejected' => '1'])->text());
        $cf = ['reason_code' => 'not_needed'] + $after->form("/ui/purchasing/orders/{$id2}/cancel");
        $c = $buyer->post("/ui/purchasing/orders/{$id2}/cancel", $cf);
        self::assertSame("/ui/purchasing/orders/{$id2}?notice=cancelled_posted", $c->location(), $c->describe());
        self::assertStringContainsString(Words::say('ORDER', 'cancel_record', 'PO-000003'), $buyer->follow($c)->text());

        // The generic document page links to the order's page; the supplier card lists the recent orders.
        $doc = $reviewer->get("/ui/documents/{$id}");
        self::assertContains("/ui/purchasing/orders/{$id}", $doc->hrefs());
        self::assertFalse($doc->hasForm('/reverse'), 'a PO is cancelled in Purchasing');
        // Review finding (I85): stock control sees documents but not purchase orders (prices, suppliers).
        $sc = $this->signIn($this->uiUser('stock_controller'));
        self::assertSame(403, $sc->get("/ui/documents/{$id}")->status);
        self::assertSame(403, $sc->get("/ui/documents/{$id}/pdf")->status);
        self::assertStringNotContainsString('PO-000001', $sc->get('/ui/documents')->text());
        self::assertSame(200, $sc->get('/ui/documents')->status);
        $card = $buyer->get('/ui/purchasing/suppliers/' . $s['id']);
        self::assertStringContainsString(Words::SUPPLIER['orders'], $card->text());
        self::assertContains("/ui/purchasing/orders/{$id2}", $card->hrefs());
        self::assertSame(200, $reviewer->get('/ui/purchasing/orders')->status, 'the reviewer reads the list');
        self::assertArrayNotHasKey('form_key', $reviewer->get('/ui/purchasing/orders')->form('/ui/purchasing/orders'), 'but has no new-order form');
    }

    /**
     * Review finding (I73): the editor's 300 lines did not fit max_input_vars = 1000, so from about 249 lines every save
     * was refused and at 248 a typed charge was dropped silently. Now the editor is offered only while its fields stay below
     * max_input_vars (editorFields counts the most a form sends), and Kernel refuses any POST that arrives with that many.
     */
    public function testTheEditorStaysBelowMaxInputVars(): void
    {
        self::assertSame(1000, UiRequest::maxInputVars(), 'the UI pool and the CLI keep the default');
        $buyerUser = $this->uiUser('buyer');
        $bid = $buyerUser['id'];
        $s = $this->supplier($bid, 'Wide Supplies');
        $buyer = $this->signIn($buyerUser);
        $r = $buyer->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id']] + $buyer->get('/ui/purchasing/orders')->form('/ui/purchasing/orders'));
        $id = self::orderId($r->location());
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $lines = [];
        for ($i = 1; $i <= 163; $i++) {
            $lines[] = ['kind' => 'item', 'sku_id' => self::makeSku("Wide item {$i}"), 'units_per_pack' => 1, 'packs' => 1, 'pack_price' => '1.00'];
        }
        $version = (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]);
        $pos->saveDraft(Caller::staff($bid), $id, $version, [], $lines);
        $rows = self::$db->all('SELECT kind, supplier_item_id FROM po_line WHERE document_id = ? ORDER BY line_no', [$id]);
        self::assertSame(24 + 163 * 6, PurchaseOrdersController::editorFields($rows));
        self::assertFalse(PurchaseOrdersController::editorFits($rows), '163 lines without a supplier item send up to 1,002 fields');
        $ed = $buyer->get("/ui/purchasing/orders/{$id}");
        self::assertStringContainsString(Words::say('ORDER', 'too_many', PurchaseOrdersController::editorMaxLines()), $ed->text());
        $f = $ed->form('/lines');
        self::assertSame(['0', '0'], [$f['line_count'], $f['lines_editable']]);
        self::assertArrayNotHasKey('line_1_packs', $f);
        // Read-only lines: the header and a charge still save (and Approve still works on what is saved).
        $ok = $buyer->post("/ui/purchasing/orders/{$id}/lines", ['external_ref' => 'Q-WIDE', 'action' => 'save'] + $f);
        self::assertSame("/ui/purchasing/orders/{$id}?notice=saved#scan", $ok->location(), $ok->describe());
        self::assertSame(163, (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$id]));

        // 150 such lines fit: every field the rendered form can send stays within the count.
        $pos->saveDraft(Caller::staff($bid), $id, (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]), [], array_slice($lines, 0, 150));
        $rows = self::$db->all('SELECT kind, supplier_item_id FROM po_line WHERE document_id = ? ORDER BY line_no', [$id]);
        self::assertTrue(PurchaseOrdersController::editorFits($rows));
        $ed = $buyer->get("/ui/purchasing/orders/{$id}");
        $names = [];
        $xp = new \DOMXPath($ed->dom());
        foreach ($xp->query("//form[contains(@action, '/lines') and not(contains(@action, '/import'))]//*[@name]") ?: [] as $el) {
            if ($el instanceof \DOMElement) {
                $names[$el->getAttribute('name')] = true;
            }
        }
        self::assertGreaterThan(150 * 6, count($names));
        self::assertLessThanOrEqual(PurchaseOrdersController::editorFields($rows), count($names), 'editorFields counts every named field of the form');
        $f = $ed->form('/lines');
        self::assertSame(['150', '1'], [$f['line_count'], $f['lines_editable']]);

        // A POST that arrives with max_input_vars fields may have lost some: refused, nothing saved.
        $big = $f + ['action' => 'save', 'charge_description' => 'Delivery', 'charge_amount' => '9.00'];
        for ($i = count($big); $i < UiRequest::maxInputVars(); $i++) {
            $big["pad_{$i}"] = 'x';
        }
        $t = $buyer->post("/ui/purchasing/orders/{$id}/lines", $big);
        self::assertSame(400, $t->status, $t->describe());
        self::assertSame('form_truncated', $t->errorCode());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM po_line WHERE document_id = ? AND kind = 'charge'", [$id]), 'the charge was not saved');
    }
}
