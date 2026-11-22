<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Arrivals\ArrivalRest;
use CW\Arrivals\Arrivals;
use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Invoices\SupplierInvoices;
use CW\Receiving\GoodsReceipts;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Controller\ArrivalsController;
use CW\Ui\Words;

/**
 * CW-1155 "Receive the rest" through the real /ui kernel (docs/decisions.md RR13): an invoice whose first receipt came short says "Waiting for
 * the rest: 5 (£5.00)" and how long on Purchases › To match, on its page and on its first receipt's page (Goods In sees the units, never the
 * money); "Receive the rest" (Goods In's right, a CSRF-protected POST) lands on the receipt of the rest, which says whose rest it is; the accept
 * panel says it stops waiting; who may press it; the days and the late mark in words.
 */
final class ReceiveRestScreensTest extends KernelUiTestCase
{
    private string $dir;
    private string|false $prevStore = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_rest_ui_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/store', 0700, true);
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
    }

    protected function tearDown(): void
    {
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function store(): FileStore
    {
        return new FileStore(self::$db, new LocalFileStorage($this->dir . '/store'));
    }

    /** @return array<string, mixed> an active supplier made by a buyer and approved by a reviewer */
    private function supplier(string $name): array
    {
        $buyer = $this->uiUser('buyer')['id'];
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => $name, 'address_line1' => '3 Mill Lane', 'postcode' => 'M1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    /**
     * A final invoice of 5 of a fresh dry item at £1.00, its first receipt posted with all 5 short.
     *
     * @return array{invoice: int, number: string, grn: int, grn_number: string, sku: int, desk: array<string, mixed>}
     */
    private function shortInvoice(): array
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $desk = Caller::staff($deskUser['id']);
        $s = $this->supplier('Screen Rest ' . bin2hex(random_bytes(2)) . ' Ltd');
        $sku = self::makeSku('Screen rest item ' . bin2hex(random_bytes(3)));
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $si = new SupplierInvoices(self::$db, $docs, fn (): FileStore => $this->store());
        $d = $si->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'REST-' . bin2hex(random_bytes(3)), 'invoice_date' => gmdate('Y-m-d', time() - 86400)]);
        $d = $si->addItem($desk, $d->id, $d->version, '', 5, '1.0000', $sku)['document'];
        $pdf = $this->dir . '/invoice-' . $d->id . '.pdf';
        file_put_contents($pdf, (new \CW\Output\PdfWriter('Invoice ' . bin2hex(random_bytes(4)), 'test', true))->output());
        $si->attach($desk, $d->id, $pdf, 'invoice.pdf', 'supplier_invoice');
        $d = $si->saveHeader($desk, $d->id, $docs->get($d->id)->version, ['net_total' => '5.00', 'vat_total' => '0.00']);
        $p = $si->submit($desk, $d->id, $d->version);
        $arr = new Arrivals(self::$db, $docs, fn (): FileStore => $this->store());
        $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => $this->store());
        $g = $arr->receive($desk, $p->id);
        $grns->bench(Caller::staff($this->uiUser('goods_in')['id']), $g->id, $docs->get($g->id)->version, ['paperwork_ok' => '1'], [1 => ['short_units' => '5']]);
        $g = $grns->post($desk, $g->id, $docs->get($g->id)->version);
        return ['invoice' => $p->id, 'number' => (string) $p->number, 'grn' => $g->id, 'grn_number' => (string) $g->number, 'sku' => $sku, 'desk' => $deskUser];
    }

    public function testTheWaitIsShownAndTheRestReceivedFromTheScreens(): void
    {
        $x = $this->shortInvoice();
        $desk = $this->signIn($x['desk']);
        $waiting = Words::say('ARRIVALS', 'rest_waiting', 5, '£5.00');
        $toMatch = $desk->get('/ui/purchasing/invoices/to-match');
        self::assertSame(200, $toMatch->status, $toMatch->describe());
        self::assertStringContainsString($waiting, $toMatch->text());
        self::assertStringContainsString(Words::ARRIVALS['rest_today'], $toMatch->text());
        $path = '/ui/purchasing/invoices/' . $x['invoice'];
        $page = $desk->get($path);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString($waiting, $page->text());
        self::assertStringContainsString(Words::ARRIVALS['rest_title'], $page->text());
        self::assertStringContainsString(Words::say('ARRIVALS', 'rest_claim', $x['grn_number']), $page->text(), 'it will not come: the claim, on the first receipt');
        self::assertStringContainsString(Words::say('ARRIVALS', 'rest_accept', 5), $page->text(), 'accepting says it stops waiting');
        self::assertTrue($page->hasForm('/ui/receiving/arriving/' . $x['invoice'] . '/receive-rest'), 'the desk receives goods too');

        // Goods In on the first receipt: the units, no money; "Receive the rest" there.
        $goodsIn = $this->signIn($this->uiUser('goods_in'));
        $receipt = $goodsIn->get('/ui/receiving/' . $x['grn']);
        self::assertSame(200, $receipt->status, $receipt->describe());
        self::assertStringContainsString(Words::say('ARRIVALS', 'rest_waiting_items', 5), $receipt->text());
        self::assertStringNotContainsString('£5.00', $receipt->text(), 'Goods In does not read money');
        $action = '/ui/receiving/arriving/' . $x['invoice'] . '/receive-rest';
        $r = $goodsIn->post($action, $receipt->form($action));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(1, preg_match('#^/ui/receiving/(\d+)#', (string) $r->location(), $m), (string) $r->location());
        $rest = (int) $m[1];
        $draft = $goodsIn->follow($r);
        self::assertStringContainsString(Words::RECEIPT_NOTICE['rest_from_invoice'], $draft->text());
        self::assertStringContainsString(Words::say('ARRIVALS', 'rest_of', $x['number'], $x['grn_number']), $draft->text());
        // Pressed again (or by someone else): the same draft, opened from the invoice.
        self::assertStringContainsString(Words::ARRIVALS['rest_open'], $desk->get($path)->text());
        self::assertFalse($desk->get($path)->hasForm($action));
        // Booked in: the invoice matches, the wait is gone, the rest is listed.
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => $this->store());
        $grns->bench(Caller::staff($this->uiUser('goods_in')['id']), $rest, $docs->get($rest)->version, ['paperwork_ok' => '1'], [1 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital']]);
        $grns->post(Caller::staff($x['desk']['id']), $rest, $docs->get($rest)->version);
        $restNumber = (string) self::$db->value('SELECT number FROM document WHERE id = ?', [$rest]);
        $page = $desk->get($path);
        self::assertStringNotContainsString($waiting, $page->text());
        self::assertStringContainsString(Words::say('ARRIVALS', 'rest_received_on', $restNumber, 5), $page->text());
        self::assertStringNotContainsString($waiting, $desk->get('/ui/purchasing/invoices/to-match')->text());
        $restPage = $goodsIn->get('/ui/receiving/' . $rest);
        self::assertStringContainsString(Words::say('ARRIVALS', 'rest_of', $x['number'], $x['grn_number']), $restPage->text());
        self::assertFalse($restPage->hasForm('/ui/receiving/' . $rest . '/claim'), 'claims are made on the first receipt');
        // Pressed once more: nothing is owed, said in words on the page it was pressed on.
        $r = $goodsIn->post($action, ['csrf' => $this->token($goodsIn), 'from_grn' => (string) $x['grn']]);
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::ARRIVAL_ERROR['nothing_owed'], $r->text());
    }

    public function testWhoMayReceiveTheRestAndTheCsrfToken(): void
    {
        $x = $this->shortInvoice();
        $action = '/ui/receiving/arriving/' . $x['invoice'] . '/receive-rest';
        $reviewer = $this->signIn($this->uiUser('reviewer'));
        self::assertFalse($reviewer->get('/ui/receiving/' . $x['grn'])->hasForm($action), 'a reviewer looks');
        self::assertSame(403, $reviewer->post($action, ['csrf' => $this->token($reviewer)])->status);
        $goodsIn = $this->signIn($this->uiUser('goods_in'));
        foreach ([[], ['csrf' => 'not-the-token']] as $form) {
            $r = $goodsIn->post($action, $form);
            self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()]);
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM goods_receipt_invoice WHERE sinv_document_id = ?', [$x['invoice']]), 'nothing made');
    }

    /**
     * The Codex check of 3ae3345: a refused "Receive the rest" never shows the invoice (its money) to Goods In (H1); an invoice within the
     * quantity tolerance still waiting for the rest is on To match (M); a first receipt whose shortfall was given up never says "everything
     * came" (LOW).
     */
    public function testTheReviewOfTheScreens(): void
    {
        $x = $this->shortInvoice();
        $goodsIn = $this->signIn($this->uiUser('goods_in'));
        $desk = $this->signIn($x['desk']);
        $page = $desk->get('/ui/purchasing/invoices/' . $x['invoice']);
        $fingerprint = (string) (new \DOMXPath($page->dom()))->evaluate('string(//input[@name="match_fingerprint"]/@value)');
        $owedSeen = (string) (new \DOMXPath($page->dom()))->evaluate('string(//input[@name="rest_owed"]/@value)');
        self::assertSame('1:5', $owedSeen, 'the accept form carries what it shows as waited for');
        $r = $desk->post('/ui/purchasing/invoices/' . $x['invoice'] . '/accept', ['csrf' => $this->token($desk), 'note' => 'They will not send them',
            'match_fingerprint' => $fingerprint, 'rest_owed' => $owedSeen]);
        self::assertSame(303, $r->status, $r->describe());
        $r = $goodsIn->post('/ui/receiving/arriving/' . $x['invoice'] . '/receive-rest', ['csrf' => $this->token($goodsIn)]);
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::ARRIVAL_ERROR['nothing_owed'], $r->text());
        self::assertStringNotContainsString('£', $r->text(), 'Goods In is shown Arriving, never the invoice');
        $receipt = $goodsIn->get('/ui/receiving/' . $x['grn']);
        self::assertStringContainsString(Words::ARRIVALS['rest_nothing_waited'], $receipt->text());
        self::assertStringNotContainsString(Words::ARRIVALS['nothing_missing'], $receipt->text());

        // Within a 10% quantity tolerance: 1 of 20 short is "within", still waited for, and listed.
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"10\"' AS JSON) WHERE setting_key = 'invoices.qty_tolerance_pct'");
        try {
            $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
            $si = new SupplierInvoices(self::$db, $docs, fn (): FileStore => $this->store());
            $deskC = Caller::staff($x['desk']['id']);
            $sku = self::makeSku('Tolerance item ' . bin2hex(random_bytes(3)));
            (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
            $supplier = (int) self::$db->value('SELECT supplier_id FROM supplier_invoice WHERE document_id = ?', [$x['invoice']]);
            $d = $si->createDraft($deskC, 'invoice', $supplier, ['invoice_number' => 'TOL-' . bin2hex(random_bytes(3)), 'invoice_date' => gmdate('Y-m-d', time() - 86400)]);
            $d = $si->addItem($deskC, $d->id, $d->version, '', 20, '1.0000', $sku)['document'];
            $pdf = $this->dir . '/tol-' . $d->id . '.pdf';
            file_put_contents($pdf, (new \CW\Output\PdfWriter('Invoice ' . bin2hex(random_bytes(4)), 'test', true))->output());
            $si->attach($deskC, $d->id, $pdf, 'invoice.pdf', 'supplier_invoice');
            $d = $si->saveHeader($deskC, $d->id, $docs->get($d->id)->version, ['net_total' => '20.00', 'vat_total' => '0.00']);
            $p = $si->submit($deskC, $d->id, $d->version);
            $arr = new Arrivals(self::$db, $docs, fn (): FileStore => $this->store());
            $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => $this->store());
            $g = $arr->receive($deskC, $p->id);
            $grns->bench(Caller::staff($this->uiUser('goods_in')['id']), $g->id, $docs->get($g->id)->version, ['paperwork_ok' => '1'], [1 => ['short_units' => '1']]);
            $grns->post($deskC, $g->id, $docs->get($g->id)->version);
            self::assertSame('within_tolerance', (string) self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$p->id]));
            $toMatch = $desk->get('/ui/purchasing/invoices/to-match');
            self::assertStringContainsString((string) $p->number, $toMatch->text(), 'listed although within the tolerance');
            self::assertStringContainsString(Words::say('ARRIVALS', 'rest_waiting', 1, '£1.00'), $toMatch->text());
            // The tolerance set back to exact and "Check these again": the listed one is checked again too (the Codex check of 6c78108, M).
            self::$db->exec("UPDATE app_setting SET value_json = CAST('\"0\"' AS JSON) WHERE setting_key = 'invoices.qty_tolerance_pct'");
            $r = $desk->post('/ui/purchasing/invoices/to-match/recheck', ['csrf' => $this->token($desk)]);
            self::assertSame(303, $r->status, $r->describe());
            self::assertSame('needs_review', (string) self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$p->id]));
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST('\"0\"' AS JSON) WHERE setting_key = 'invoices.qty_tolerance_pct'");
        }
    }

    /**
     * The Codex check of 6c78108: an accept form without the lines waited for (drawn before CW-1155) is refused while something is waited for
     * (M); the differences panel counts what the receipts of the rest brought as received (LOW).
     */
    public function testAnOldAcceptFormAndThePanelOfAPartArrival(): void
    {
        $x = $this->shortInvoice();
        $desk = $this->signIn($x['desk']);
        $path = '/ui/purchasing/invoices/' . $x['invoice'];
        $fingerprint = (string) (new \DOMXPath($desk->get($path)->dom()))->evaluate('string(//input[@name="match_fingerprint"]/@value)');
        $r = $desk->post($path . '/accept', ['csrf' => $this->token($desk), 'note' => 'They will not send them', 'match_fingerprint' => $fingerprint]);
        self::assertSame(409, $r->status, $r->describe());
        self::assertNull(self::$db->value('SELECT accepted_at FROM supplier_invoice WHERE document_id = ?', [$x['invoice']]), 'nothing waived unseen');
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $arr = new Arrivals(self::$db, $docs, fn (): FileStore => $this->store());
        $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => $this->store());
        $g = $arr->receiveRest(Caller::staff($x['desk']['id']), $x['invoice']);
        $grns->bench(Caller::staff($this->uiUser('goods_in')['id']), $g->id, $docs->get($g->id)->version, ['paperwork_ok' => '1'], [1 => ['short_units' => '2']]);
        $grns->post(Caller::staff($x['desk']['id']), $g->id, $docs->get($g->id)->version);
        $look = new \DOMXPath($desk->get($path)->dom());
        self::assertSame(['3', '5'], [trim((string) $look->evaluate('string(//section[contains(@class, "doc-diff")]//td[@data-label="' . Words::DOC['received'] . '"])')),
            trim((string) $look->evaluate('string(//section[contains(@class, "doc-diff")]//td[@data-label="' . Words::DOC['invoiced'] . '"])'))], 'three of five came');
    }

    /**
     * RR29: the one-page receipt editor's own save of a receipt of the rest (its lines sent back unchanged, "All fine" on the line) passes the
     * frozen lines, and the editor books it in.
     */
    public function testTheOnePageEditorSavesAndBooksAReceiptOfTheRest(): void
    {
        $x = $this->shortInvoice();
        $desk = $this->signIn($x['desk']);
        $action = '/ui/receiving/arriving/' . $x['invoice'] . '/receive-rest';
        $r = $desk->post($action, $desk->get('/ui/purchasing/invoices/' . $x['invoice'])->form($action));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(1, preg_match('#^/ui/receiving/(\d+)#', (string) $r->location(), $m));
        $id = (int) $m[1];
        $page = $desk->follow($r);
        $form = $page->form("/ui/receiving/{$id}/lines", true);
        self::assertNotSame([], $form, $page->describe());
        $saved = $desk->post("/ui/receiving/{$id}/lines", ['paperwork_ok' => '1', 'fine' => '1', 'action' => 'save'] + $form);
        self::assertSame(303, $saved->status, $saved->describe());
        self::assertStringStartsWith("/ui/receiving/{$id}?notice=saved", (string) $saved->location(), 'saved through the frozen lines');
        $page = $desk->follow($saved);
        $r = $desk->post("/ui/receiving/{$id}/lines", ['action' => 'post'] + $page->form("/ui/receiving/{$id}/lines", true));
        self::assertSame("/ui/receiving/{$id}?notice=posted", $r->location(), $r->describe());
        self::assertSame(['posted', 'matched'], [(string) self::$db->value('SELECT status FROM document WHERE id = ?', [$id]),
            (string) self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$x['invoice']])]);
    }

    /** The days waiting and the late mark (arrivals.rest_late_days, 14): from the first receipt's UK day. */
    public function testTheDaysAndTheLateMark(): void
    {
        $x = $this->shortInvoice();
        $posted = (string) self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$x['grn']]);
        $at = static fn (string $m): \DateTimeImmutable => (new \DateTimeImmutable($posted, new \DateTimeZone('UTC')))->modify($m);
        $w = static fn (string $m): array => array_intersect_key((array) ArrivalRest::waiting(self::$db, $x['invoice'], $at($m)), ['days' => 0, 'late' => 0]);
        self::assertSame(['days' => 13, 'late' => false], $w('+13 days'));
        self::assertSame(['days' => 14, 'late' => true], $w('+14 days'));
        self::assertSame([Words::ARRIVALS['rest_today'], Words::ARRIVALS['rest_day'], Words::say('ARRIVALS', 'rest_days', 3), 'Late · 15 days waiting'],
            [ArrivalsController::daysText(0, false), ArrivalsController::daysText(1, false), ArrivalsController::daysText(3, false), ArrivalsController::daysText(15, true)]);
        self::assertSame('3 days waiting', ArrivalsController::daysText(3, false));
    }
}
