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
use CW\Receiving\GoodsReceipts;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * Pack J's screens (docs/decisions.md PJ14) through the real /ui kernel: the invoice's "How its goods come in" and "Make it final
 * later" boxes, Purchasing › Invoices › Timed, Goods In › Arriving and its "Receive", a receipt made from an invoice with what did not
 * come and "Claim what did not come", a supplier's "Goods on their way"; who may open and do what; the CSRF token on every new POST.
 */
final class ArrivalScreensTest extends KernelUiTestCase
{
    private string $dir;
    private string|false $prevStore = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_arr_ui_' . bin2hex(random_bytes(4));
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

    /** A draft invoice of $packs of a fresh dry item at £3.00 a pack (no delivery yet), its totals typed. @return array{id: int, sku: int} */
    private function invoice(int $deskId, int $supplierId, int $packs, string $number): array
    {
        $sku = self::makeSku('Screen arriving item ' . bin2hex(random_bytes(3)));
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        $si = new SupplierInvoices(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)), fn (): FileStore => $this->store());
        $desk = Caller::staff($deskId);
        $d = $si->createDraft($desk, 'invoice', $supplierId, ['invoice_number' => $number, 'invoice_date' => gmdate('Y-m-d', time() - 86400)]);
        $d = $si->addItem($desk, $d->id, $d->version, '', $packs, '3.0000', $sku)['document'];
        $si->saveHeader($desk, $d->id, $d->version, ['net_total' => number_format(3 * $packs, 2, '.', ''), 'vat_total' => '0.00']);
        return ['id' => $d->id, 'sku' => $sku];
    }

    private static function idFrom(?string $location, string $base): int
    {
        self::assertSame(1, preg_match('#^' . preg_quote($base, '#') . '/(\d+)#', (string) $location, $m), (string) $location);
        return (int) $m[1];
    }

    public function testTheOwnersDayOnTheScreens(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Screen Arrivals Ltd');
        $x = $this->invoice($deskUser['id'], (int) $s['id'], 6, 'ARR-SCR-1');
        $desk = $this->signIn($deskUser);
        $path = '/ui/purchasing/invoices/' . $x['id'];
        $page = $desk->get($path);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::ARRIVALS['how_title'], $page->text());
        self::assertStringContainsString(Words::ARRIVALS['sells_before'], $page->text(), 'the supplier sells before arrival (the setting)');
        self::assertTrue($page->hasForm($path . '/receiving'));
        // Made final tomorrow at 06:00 (UK time) by itself.
        $form = $page->form($path . '/schedule');
        $form['submit_at'] = (new \DateTimeImmutable('tomorrow 06:00', new \DateTimeZone('Europe/London')))->format('Y-m-d\TH:i');
        $r = $desk->post($path . '/schedule', $form);
        self::assertSame(303, $r->status, $r->describe());
        $page = $desk->follow($r);
        self::assertStringContainsString(Words::INVOICE_NOTICE['timed'], $page->text());
        $timed = $desk->get(\CW\Ui\Controller\ArrivalsController::TIMED);
        self::assertSame(200, $timed->status, $timed->describe());
        self::assertSame(['Purchasing', 'Invoices'], [self::currentSection($timed), self::currentTab($timed)]);
        self::assertStringContainsString('ARR-SCR-1', $timed->text());
        self::assertStringContainsString(Words::ARRIVALS['waiting'], $timed->text());
        // Taken off again, then made final now.
        $r = $desk->post($path . '/schedule', ['csrf' => $this->token($desk), 'version' => (string) self::$db->value('SELECT version FROM document WHERE id = ?', [$x['id']]),
            'clear' => '1']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringNotContainsString('ARR-SCR-1', $desk->get(\CW\Ui\Controller\ArrivalsController::TIMED)->text());
        $page = $desk->get($path);
        // The draft is the invoice's one page (docs/decisions.md PV): "Save and make final" is a button of its one form.
        $r = $desk->post($path . '/lines', ['action' => 'post'] + $page->form($path . '/lines'));
        self::assertSame(303, $r->status, $r->describe());
        $page = $desk->follow($r);
        self::assertStringContainsString(Words::ARRIVAL_STATE['open'], $page->text(), 'its goods on their way');
        $number = (string) self::$db->value('SELECT number FROM document WHERE id = ?', [$x['id']]);
        // A final invoice's page does not tell the person to check a draft (the 6.3 browser test); the strip counts what is arriving.
        self::assertStringContainsString(Words::PAGE_INTRO['supplier_invoice_final'][1], $page->text());
        self::assertStringNotContainsString(Words::PAGE_INTRO['supplier_invoice'][1], $page->text());
        self::assertStringContainsString(Words::say('FLOW', 'goods_in_text', 0) . ' · ' . Words::say('FLOW', 'goods_in_arriving', 1), $page->text());

        // Goods In › Arriving: Receive.
        $goodsIn = $this->signIn($this->uiUser('goods_in'));
        $list = $goodsIn->get(\CW\Ui\Controller\ArrivalsController::ARRIVING);
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(['Purchasing', 'Goods In'], [self::currentSection($list), self::currentTab($list)]);
        self::assertStringContainsString($number, $list->text());
        self::assertStringContainsString('Screen Arrivals Ltd', $list->text());
        $r = $goodsIn->post('/ui/receiving/arriving/' . $x['id'] . '/receive', $list->form('/ui/receiving/arriving/' . $x['id'] . '/receive'));
        self::assertSame(303, $r->status, $r->describe());
        $grn = self::idFrom($r->location(), '/ui/receiving');
        self::assertStringContainsString(Words::RECEIPT_NOTICE['from_invoice'], $goodsIn->follow($r)->text());
        // Counted at the bench: two of six did not come; booked in.
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => $this->store());
        $pdf = $this->dir . '/invoice.pdf';
        file_put_contents($pdf, (new \CW\Output\PdfWriter('Invoice ' . bin2hex(random_bytes(4)), 'test', true))->output());
        $grns->attach(Caller::staff($deskUser['id']), $grn, $pdf, 'invoice.pdf', 'supplier_invoice');
        $grns->bench(Caller::staff($this->uiUser('goods_in')['id']), $grn, $docs->get($grn)->version, ['paperwork_ok' => '1'], [1 => ['short_units' => '2']]);
        $grns->post(Caller::staff($deskUser['id']), $grn, $docs->get($grn)->version);
        // The Codex review of 9ce3515, M1 (docs/decisions.md PV24): the invoice's differences panel counts what its match counts: the
        // receipt that took it in received 4 of the 6 billed (its own line names no delivery: the receipt is the arrival's).
        $look = new \DOMXPath($desk->get($path)->dom());
        self::assertSame(['4', '6'], [trim((string) $look->evaluate('string(//section[contains(@class, "doc-diff")]//td[@data-label="' . Words::DOC['received'] . '"])')),
            trim((string) $look->evaluate('string(//section[contains(@class, "doc-diff")]//td[@data-label="' . Words::DOC['invoiced'] . '"])'))]);
        $receipt = $desk->get('/ui/receiving/' . $grn);
        self::assertSame(200, $receipt->status, $receipt->describe());
        self::assertStringContainsString(Words::ARRIVALS['against'], $receipt->text());
        self::assertStringContainsString(Words::ARRIVALS['missing_title'], $receipt->text());
        // Its invoice box names the invoice it was received against, never "no invoice bills it" (the 6.3 browser test).
        self::assertStringContainsString(Words::INVOICES['receipt_arrival'] . ' ' . $number, $receipt->text());
        self::assertStringNotContainsString(Words::INVOICES['receipt_none'], $receipt->text());
        $r = $desk->post('/ui/receiving/' . $grn . '/claim', $receipt->form('/ui/receiving/' . $grn . '/claim'));
        self::assertSame(303, $r->status, $r->describe());
        $dn = self::idFrom($r->location(), '/ui/purchasing/returns');
        $claim = $desk->follow($r);
        self::assertStringContainsString(Words::RETURN_NOTICE['claim_started'], $claim->text());
        self::assertSame('6.00', (string) self::$db->value('SELECT CAST(SUM(amount) AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$dn]), 'two at £3.00');
        self::assertFalse($desk->get('/ui/receiving/' . $grn)->hasForm('/ui/receiving/' . $grn . '/claim'), 'one claim: the page opens it');
        // A claim without goods says nothing leaves the stock, before and after it is final (the 6.3 browser test).
        self::assertStringContainsString(Words::PAGE_INTRO['supplier_return_claim'][1], $claim->text());
        self::assertStringContainsString(Words::RETURNS['post_does_claim'], $claim->text());
        self::assertStringNotContainsString(Words::RETURNS['post_does'], $claim->text());
        $r = $desk->post('/ui/purchasing/returns/' . $dn . '/post', $claim->form('/ui/purchasing/returns/' . $dn . '/post'));
        self::assertSame(303, $r->status, $r->describe());
        $dnNumber = (string) self::$db->value('SELECT number FROM document WHERE id = ?', [$dn]);
        $final = $desk->follow($r);
        self::assertSame(Words::say('RETURN_NOTICE', 'posted_claim', $dnNumber), trim((string) (new \DOMXPath($final->dom()))->evaluate('string(//p[contains(@class, "notice")])')));
        self::assertStringContainsString(Words::PAGE_INTRO['supplier_return_claim_final'][0], $final->text(), 'a claim, not goods going back');
        self::assertStringNotContainsString(Words::PAGE_INTRO['supplier_return_final'][0], $final->text());
        // The invoice it claims against says so, on To match and on its page: nobody claims twice or accepts it by mistake.
        $claimed = Words::say('INVOICES', 'claimed_on', $dnNumber, '£6.00');
        $toMatch = $desk->get('/ui/purchasing/invoices/to-match');
        self::assertSame(200, $toMatch->status, $toMatch->describe());
        self::assertStringContainsString($number, $toMatch->text());
        self::assertStringContainsString($claimed, $toMatch->text());
        self::assertStringContainsString($claimed, $desk->get($path)->text());
        // The supplier's credit note answers the claim: it reads as credited, never "waiting" (Codex review of the pack, finding 1).
        $r = $desk->post(\CW\Ui\Controller\InvoicesController::FROM_RETURN, $final->form(\CW\Ui\Controller\InvoicesController::FROM_RETURN));
        self::assertSame(303, $r->status, $r->describe());
        $cn = self::idFrom($r->location(), '/ui/purchasing/credit-notes');
        $cnPath = '/ui/purchasing/credit-notes/' . $cn;
        $cnPage = $desk->get($cnPath);
        self::assertStringContainsString(Words::PAGE_INTRO['supplier_credit_note'][0], $cnPage->text(), 'a credit note, not an invoice');
        $form = ['invoice_number' => 'CN-ARR-1', 'invoice_date' => gmdate('Y-m-d'), 'net_total' => '6.00', 'vat_total' => '1.20'] + $cnPage->form($cnPath . '/details');
        $r = $desk->post($cnPath . '/details', $form);
        self::assertSame(303, $r->status, $r->describe());
        $r = $desk->post($cnPath . '/post', $desk->get($cnPath)->form($cnPath . '/post'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('posted', self::$db->value('SELECT status FROM document WHERE id = ?', [$cn]), $desk->follow($r)->describe());
        self::assertStringContainsString(Words::PAGE_INTRO['supplier_credit_note_final'][1], $desk->get($cnPath)->text());
        $invoice = $desk->get($path);
        self::assertStringContainsString(Words::say('INVOICES', 'claimed_credited', $dnNumber, '£6.00'), $invoice->text());
        self::assertStringNotContainsString($claimed, $invoice->text());
    }

    /**
     * Review finding 6 (Codex, 15 Oct 2026): a time that comes twice the night the clocks go back (01:30 BST, then 01:30 GMT an hour
     * later) is not guessed: both are shown and the person chooses; what is set is the one chosen, and the page says which.
     */
    public function testATimeThatComesTwiceIsChosenByThePerson(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Clock Change Ltd');
        $x = $this->invoice($deskUser['id'], (int) $s['id'], 2, 'ARR-CLK-1');
        $desk = $this->signIn($deskUser);
        $path = '/ui/purchasing/invoices/' . $x['id'];
        $day = (new \DateTimeImmutable('last sunday of october ' . ((int) gmdate('Y') + 1), new \DateTimeZone('Europe/London')))->format('Y-m-d');
        $typed = $day . 'T01:30';
        $form = $desk->get($path)->form($path . '/schedule');
        $form['submit_at'] = $typed;
        $r = $desk->post($path . '/schedule', $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertNull(self::$db->value('SELECT submit_at FROM supplier_invoice WHERE document_id = ?', [$x['id']]), 'nothing set until the person chooses');
        self::assertStringContainsString(Words::ARRIVAL_ERROR['ambiguous_submit_at'], $r->text());
        self::assertStringContainsString(Words::say('ARRIVALS', 'clock_bst', '01:30'), $r->text(), 'the first 01:30 shown');
        self::assertStringContainsString(Words::say('ARRIVALS', 'clock_gmt', '01:30'), $r->text(), 'the second 01:30 shown');
        $again = $r->form($path . '/schedule');
        self::assertSame($typed, $again['submit_at'], 'the time typed is kept');
        foreach (['BST' => '00:30', 'GMT' => '01:30'] as $clock => $utc) {
            $f = $desk->get($path)->form($path . '/schedule');
            $f['submit_at'] = $typed;
            $f['submit_clock'] = $clock;
            $r = $desk->post($path . '/schedule', $f);
            self::assertSame(303, $r->status, $r->describe());
            self::assertSame("{$day} {$utc}", substr((string) self::$db->value('SELECT submit_at FROM supplier_invoice WHERE document_id = ?', [$x['id']]), 0, 16),
                "{$clock}: stored in UTC");
            self::assertStringContainsString("01:30 {$clock}", $desk->follow($r)->text(), 'the page says which 01:30');
        }
        // A time that does not exist (the clocks go forward over it) is refused as before.
        $spring = (new \DateTimeImmutable('last sunday of march ' . ((int) gmdate('Y') + 1), new \DateTimeZone('Europe/London')))->format('Y-m-d');
        $f = $desk->get($path)->form($path . '/schedule');
        $f['submit_at'] = $spring . 'T01:30';
        self::assertSame(400, $desk->post($path . '/schedule', $f)->status);
    }

    public function testASupplierChoosesWhatHappensToItsGoodsOnTheirWay(): void
    {
        $s = $this->supplier('Screen Choices Ltd');
        $buyer = $this->signIn($this->uiUser('buyer'));
        $page = $buyer->get('/ui/purchasing/suppliers/' . $s['id']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::ARRIVALS['supplier_title'], $page->text());
        self::assertStringContainsString(Words::ARRIVALS['own_none'], $page->text());
        $form = $page->form('/ui/purchasing/suppliers/' . $s['id'] . '/arrivals');
        unset($form['sell_before']);
        $form['default_mode'] = 'received';
        $r = $buyer->post('/ui/purchasing/suppliers/' . $s['id'] . '/arrivals', $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::SUPPLIER_NOTICE['arrivals_saved'], $buyer->follow($r)->text());
        self::assertSame([0, 'received'], array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, array_values((array) self::$db->one(
            'SELECT sell_before_arrival, default_mode FROM supplier_arrival_pref WHERE supplier_id = ?', [(int) $s['id']]))));
        $look = $this->signIn($this->uiUser('purchasing_desk'))->get('/ui/purchasing/suppliers/' . $s['id']);
        self::assertFalse($look->hasForm('/ui/purchasing/suppliers/' . $s['id'] . '/arrivals'), 'the desk looks');
        self::assertStringContainsString(Words::RECEIVE_MODE['received'], $look->text());
    }

    public function testWhoMayOpenAndDoWhat(): void
    {
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(403, $buyer->get(\CW\Ui\Controller\ArrivalsController::ARRIVING)->status, 'a buyer does not see Goods In');
        self::assertSame(200, $buyer->get(\CW\Ui\Controller\ArrivalsController::TIMED)->status, 'a buyer reads the invoices');
        $goodsIn = $this->signIn($this->uiUser('goods_in'));
        self::assertSame(403, $goodsIn->get(\CW\Ui\Controller\ArrivalsController::TIMED)->status, 'goods in: invoices are money');
        $reviewer = $this->signIn($this->uiUser('reviewer'));
        self::assertSame(200, $reviewer->get(\CW\Ui\Controller\ArrivalsController::ARRIVING)->status);
        $refused = $reviewer->post('/ui/receiving/arriving/1/receive', ['csrf' => $this->token($reviewer)]);
        self::assertSame(403, $refused->status, 'a reviewer looks, never receives');
        $refused = $goodsIn->post('/ui/receiving/1/claim', ['csrf' => $this->token($goodsIn)]);
        self::assertSame(403, $refused->status, 'goods in does not claim from suppliers');
        $refused = $goodsIn->post('/ui/purchasing/suppliers/1/arrivals', ['csrf' => $this->token($goodsIn), 'default_mode' => 'count']);
        self::assertSame(403, $refused->status);
    }

    /** Every new POST route refuses a form without the session's CSRF token (403 csrf), before it does anything. */
    public function testEveryNewPostChecksTheCsrfToken(): void
    {
        $web = $this->signIn($this->uiUser('purchasing_manager'));
        $posts = ['/ui/receiving/arriving/1/receive', '/ui/receiving/1/claim', '/ui/purchasing/suppliers/1/arrivals', '/ui/purchasing/invoices/1/receiving',
            '/ui/purchasing/invoices/1/schedule'];
        foreach ($this->kernel()->router()->routes() as $route) {
            if ($route->method === 'POST' && preg_match('#^/ui/(receiving/arriving|receiving/\{id\}/claim|purchasing/suppliers/\{id\}/arrivals)#', $route->pattern) === 1) {
                self::assertContains(str_replace('{id}', '1', $route->pattern), $posts, $route->pattern);
            }
        }
        $before = [(int) self::$db->value('SELECT COUNT(*) FROM document'), (int) self::$db->value('SELECT COUNT(*) FROM supplier_arrival_pref')];
        foreach ($posts as $path) {
            foreach ([[], ['csrf' => 'not-the-token']] as $form) {
                $r = $web->post($path, $form + ['version' => '1', 'default_mode' => 'count', 'receive_mode' => 'count', 'submit_at' => '2030-01-01T06:00', 'clear' => '1']);
                self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()], $path);
            }
        }
        self::assertSame($before, [(int) self::$db->value('SELECT COUNT(*) FROM document'), (int) self::$db->value('SELECT COUNT(*) FROM supplier_arrival_pref')], 'nothing was made');
    }
}
