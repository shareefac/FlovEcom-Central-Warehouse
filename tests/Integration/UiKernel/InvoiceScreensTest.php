<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Receiving\GoodsReceipts;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * Purchasing › Invoices and Purchasing › Returns on the screens (pack C; docs/decisions.md PC17): the quick way (a delivery not invoiced
 * yet, "Book its invoice", the totals typed, posted, matched), an invoice that does not match on "To match" and accepted there, a return
 * of a delivery's damaged units posted and its credit note started; who may open and do what; every POST checks the CSRF token.
 */
final class InvoiceScreensTest extends KernelUiTestCase
{
    private string $dir;
    private string|false $prevStore = false;
    private ?string $prevCutoff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_inv_ui_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/store', 0700, true);
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

    /** @return array<string, mixed> an active supplier made by a buyer and approved by a reviewer (the services) */
    private function supplier(string $name): array
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

    /**
     * A posted delivery of $packs of a fresh item at $price (the invoice copy in the test's store, the bench's check done).
     *
     * @param array<string, string> $bench the bench's findings of the line
     * @return array{grn: int, sku: int, number: string}
     */
    private function delivery(int $deskId, int $supplierId, int $packs, string $price, array $bench = []): array
    {
        $sku = self::makeSku('Screen invoice item ' . bin2hex(random_bytes(3)));
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => new FileStore(self::$db, new LocalFileStorage($this->dir . '/store')));
        $desk = Caller::staff($deskId);
        $d = $grns->createDraft($desk, $supplierId, ['invoice_number' => 'SCR-' . bin2hex(random_bytes(3))]);
        $d = $grns->saveDraft($desk, $d->id, $d->version, [], [['sku_id' => $sku, 'packs' => $packs, 'units_per_pack' => 1, 'pack_price' => $price]]);
        $pdf = $this->dir . '/invoice-' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($pdf, (new \CW\Output\PdfWriter('Invoice ' . bin2hex(random_bytes(4)), 'test', true))->output());
        $grns->attach($desk, $d->id, $pdf, 'invoice.pdf', 'supplier_invoice');
        $d = $grns->bench(Caller::staff($this->uiUser('goods_in')['id']), $d->id, $docs->get($d->id)->version, ['paperwork_ok' => '1'],
            [1 => $bench + ['stamp_on_pack' => '1', 'stamp_type' => 'digital']]);
        $p = $grns->post($desk, $d->id, $docs->get($d->id)->version);
        return ['grn' => $p->id, 'sku' => $sku, 'number' => (string) $p->number];
    }

    private static function idFrom(?string $location, string $base): int
    {
        self::assertSame(1, preg_match('#^' . preg_quote($base, '#') . '/(\d+)#', (string) $location, $m), (string) $location);
        return (int) $m[1];
    }

    public function testTheQuickWayOnTheScreensAndAnInvoiceThatDoesNotMatch(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Screen Invoices Ltd');
        $x = $this->delivery($deskUser['id'], (int) $s['id'], 10, '3.00');
        $desk = $this->signIn($deskUser);

        // The delivery's own page offers the quick way too.
        $grnPage = $desk->get('/ui/receiving/' . $x['grn']);
        self::assertTrue($grnPage->hasForm('/ui/purchasing/invoices/from-receipt'), $grnPage->describe());
        self::assertStringContainsString(Words::say('INVOICES', 'receipt_left', 10), $grnPage->text());
        $list = $desk->get('/ui/purchasing/invoices/not-invoiced');
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(['Purchasing', 'Invoices'], [self::currentSection($list), self::currentTab($list)]);
        self::assertSame([['Invoices', false], ['To match', false], ['Not invoiced yet', true], ['Credit notes', false]],
            array_map(static fn (array $g): array => [$g['label'], $g['current']], self::segments($list)));
        self::assertStringContainsString($x['number'], $list->text());
        $r = $desk->post('/ui/purchasing/invoices/from-receipt', $list->form('/ui/purchasing/invoices/from-receipt'));
        self::assertSame(303, $r->status, $r->describe());
        $id = self::idFrom($r->location(), '/ui/purchasing/invoices');
        $page = $desk->get('/ui/purchasing/invoices/' . $id);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::INVOICE_ERROR['totals_required'], $page->text(), 'what stops it is said before posting');
        self::assertStringContainsString(Words::INVOICES['match_preview'], $page->text());
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document_file WHERE document_id = ? AND role = 'supplier_invoice'", [$id]), 'the delivery\'s copy');

        // The paper charges £4.00 a pack where the delivery said £3.00: the lines are typed as the paper says them.
        $form = $page->form('/ui/purchasing/invoices/' . $id . '/lines');
        $form['pp_1'] = '4.00';
        self::assertSame(303, $desk->post('/ui/purchasing/invoices/' . $id . '/lines', $form)->status);
        $page = $desk->get('/ui/purchasing/invoices/' . $id);
        $form = ['net_total' => '40.00', 'vat_total' => '8.00'] + $page->form('/ui/purchasing/invoices/' . $id . '/details');
        self::assertSame(303, $desk->post('/ui/purchasing/invoices/' . $id . '/details', $form)->status);
        $page = $desk->get('/ui/purchasing/invoices/' . $id);
        $r = $desk->post('/ui/purchasing/invoices/' . $id . '/post', $page->form('/ui/purchasing/invoices/' . $id . '/post'));
        self::assertSame(303, $r->status, $r->describe());
        $page = $desk->follow($r);
        self::assertStringContainsString(Words::say('INVOICE_NOTICE', 'posted', 'SINV-000001'), $page->text());
        self::assertStringContainsString(Words::MATCH_STATE['needs_review'], $page->text(), '£10 (33 %) dearer than the delivery: outside the tolerance');
        self::assertStringContainsString(Words::MATCH_WHY['price_over'], $page->text());

        // "To match": the badge, the list, and the acceptance with a reason.
        $tm = $desk->get('/ui/purchasing/invoices/to-match');
        self::assertSame(200, $tm->status);
        self::assertSame(1, array_column(self::segments($tm), 'count', 'label')['To match'], 'the badge counts it');
        self::assertContains('/ui/purchasing/invoices/' . $id, $tm->hrefs());
        $bad = $desk->post('/ui/purchasing/invoices/' . $id . '/accept', ['csrf' => $this->token($desk), 'note' => 'x']);
        self::assertSame(400, $bad->status);
        self::assertStringContainsString(Words::INVOICE_ERROR['accept_note'], $bad->text());
        $r = $desk->post('/ui/purchasing/invoices/' . $id . '/accept', ['csrf' => $this->token($desk), 'note' => 'Supplier price rise agreed by phone']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('accepted', self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$id]));
        self::assertStringContainsString(Words::INVOICES['none_to_match'], $desk->get('/ui/purchasing/invoices/to-match')->text());
        self::assertContains('/ui/purchasing/invoices/' . $id, $desk->get('/ui/purchasing/invoices/to-match', ['show' => 'accepted'])->hrefs());
        self::assertStringContainsString(Words::INVOICES['none_waiting'], $desk->get('/ui/purchasing/invoices/not-invoiced')->text());

        // The delivery's page names its invoice now, and offers nothing more to book.
        $grnPage = $desk->get('/ui/receiving/' . $x['grn']);
        self::assertContains('/ui/purchasing/invoices/' . $id, $grnPage->hrefs());
        self::assertFalse($grnPage->hasForm('/ui/purchasing/invoices/from-receipt'));
        // The record's generic page leads to its own.
        $generic = $desk->get('/ui/documents/' . $id);
        self::assertContains('/ui/purchasing/invoices/' . $id, $generic->hrefs());
    }

    public function testAReturnOfADeliverysDamagedUnitsAndItsCreditNote(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Screen Returns Ltd');
        $x = $this->delivery($deskUser['id'], (int) $s['id'], 10, '2.00', ['damaged_units' => '2']);
        $desk = $this->signIn($deskUser);
        $list = $desk->get('/ui/purchasing/returns');
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(['Purchasing', 'Returns'], [self::currentSection($list), self::currentTab($list)]);
        $r = $desk->post('/ui/purchasing/returns/from-receipt', $list->form('/ui/purchasing/returns/from-receipt'));
        self::assertSame(303, $r->status, $r->describe());
        $id = self::idFrom($r->location(), '/ui/purchasing/returns');
        $page = $desk->get('/ui/purchasing/returns/' . $id);
        self::assertStringContainsString(Words::INVOICE_ERROR['reason_required'], $page->text(), 'say why it goes back');
        $form = ['reason_code' => 'damaged'] + $page->form('/ui/purchasing/returns/' . $id . '/details');
        self::assertSame(303, $desk->post('/ui/purchasing/returns/' . $id . '/details', $form)->status);
        $page = $desk->get('/ui/purchasing/returns/' . $id);
        $r = $desk->post('/ui/purchasing/returns/' . $id . '/post', $page->form('/ui/purchasing/returns/' . $id . '/post'));
        self::assertSame(303, $r->status, $r->describe());
        $page = $desk->follow($r);
        self::assertStringContainsString(Words::say('RETURN_NOTICE', 'posted', 'DN-000001'), $page->text());
        self::assertStringContainsString(Words::CREDIT_STATE['open'], $page->text());
        self::assertSame(0, (int) (self::$db->value("SELECT on_hand FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id AND w.code = 'VERIFY' WHERE b.sku_id = ?",
            [$x['sku']]) ?? 0), 'the two damaged units left VERIFY');
        $r = $desk->post('/ui/purchasing/credit-notes/from-return', $page->form('/ui/purchasing/credit-notes/from-return'));
        self::assertSame(303, $r->status, $r->describe());
        $cn = self::idFrom($r->location(), '/ui/purchasing/credit-notes');
        self::assertSame(['SCN', $id], [self::$db->value('SELECT doc_type FROM document WHERE id = ?', [$cn]),
            (int) self::$db->value('SELECT debit_note_document_id FROM supplier_invoice WHERE document_id = ?', [$cn])]);
        self::assertSame(200, $desk->get('/ui/purchasing/credit-notes/' . $cn)->status);
    }

    public function testWhoMayOpenAndDoWhat(): void
    {
        $pages = ['/ui/purchasing/invoices', '/ui/purchasing/invoices/to-match', '/ui/purchasing/invoices/not-invoiced', '/ui/purchasing/credit-notes'];
        $goodsIn = $this->signIn($this->uiUser('goods_in'));
        foreach ($pages as $p) {
            self::assertSame(403, $goodsIn->get($p)->status, "goods in: {$p} (money)");
        }
        self::assertSame(200, $goodsIn->get('/ui/purchasing/returns')->status, 'goods in reads the returns (they pack the goods)');
        self::assertFalse($goodsIn->get('/ui/purchasing/returns')->hasForm('/ui/purchasing/returns/from-receipt'), 'and keeps none');

        $buyer = $this->signIn($this->uiUser('buyer'));
        foreach ([...$pages, '/ui/purchasing/returns'] as $p) {
            self::assertSame(200, $buyer->get($p)->status, "buyer: {$p}");
        }
        $refused = $buyer->post('/ui/purchasing/invoices', ['csrf' => $this->token($buyer), 'form_key' => str_repeat('a', 32), 'supplier_id' => '1']);
        self::assertSame([403, 'role_not_allowed'], [$refused->status, $refused->errorCode()]);
        self::assertFalse($buyer->get('/ui/purchasing/invoices')->hasForm('/ui/purchasing/invoices'), 'a buyer looks');

        $admin = $this->signIn($this->uiUser(['admin', 'auditor']));
        self::assertSame(200, $admin->get('/ui/purchasing/invoices')->status, 'the auditor reads');
        $refused = $admin->post('/ui/purchasing/returns', ['csrf' => $this->token($admin), 'form_key' => str_repeat('b', 32), 'supplier_id' => '1']);
        self::assertSame(403, $refused->status, 'admin never keeps records');

        $accountant = $this->signIn($this->uiUser('accountant'));
        self::assertSame(200, $accountant->get('/ui/purchasing/invoices/to-match')->status);
        self::assertSame(403, $accountant->post('/ui/purchasing/invoices/1/accept', ['csrf' => $this->token($accountant), 'note' => 'Not mine to accept'])->status);
        self::assertSame(403, $accountant->post('/ui/purchasing/invoices/to-match/recheck', ['csrf' => $this->token($accountant)])->status);
    }

    /** Every POST route of the two tabs refuses a form without the session's CSRF token (403 csrf), before it does anything. */
    public function testEveryPostChecksTheCsrfToken(): void
    {
        $user = $this->uiUser('purchasing_desk');
        $web = $this->signIn($user);
        $posts = [];
        foreach ($this->kernel()->router()->routes() as $route) {
            if ($route->method === 'POST' && preg_match('#^/ui/purchasing/(invoices|credit-notes|returns)#', $route->pattern) === 1) {
                $posts[] = str_replace('{id}', '1', $route->pattern);
            }
        }
        self::assertGreaterThanOrEqual(40, count($posts), 'the invoice, credit note and return actions');
        $before = (int) self::$db->value('SELECT COUNT(*) FROM document');
        foreach ($posts as $path) {
            foreach ([[], ['csrf' => 'not-the-token']] as $form) {
                $r = $web->post($path, $form + ['form_key' => str_repeat('c', 32), 'version' => '1', 'supplier_id' => '1', 'grn_id' => '1', 'note' => 'abc']);
                self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()], $path);
            }
        }
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM document'), 'nothing was made');
    }
}
