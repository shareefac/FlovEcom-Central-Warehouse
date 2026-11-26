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
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * CW-1152 on the screens (docs/decisions.md NI1-NI6): the tick "Can book goods in without the invoice" on the supplier form (saved, shown on
 * the supplier's page, taken off again); a ticked supplier's delivery not booked in yet says "Invoice still to come" (a note, not something
 * to settle); once booked in its page says "Invoice still to come", so does Purchase invoices > Delivered, no invoice yet, and "Book its
 * invoice" starts the invoice from it.
 */
final class GoodsInWithoutInvoiceScreensTest extends KernelUiTestCase
{
    private string $dir;
    private string|false $prevStore = false;
    private ?string $prevCutoff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_noinv_ui_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/store', 0700, true);
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

    /** @return array<string, mixed> an active supplier made by $buyer and approved by a reviewer (the services) */
    private function supplier(int $buyer, string $name): array
    {
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => $name, 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    public function testTheTickTheDeliveryPagesAndBookItsInvoice(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $s = $this->supplier($buyerUser['id'], 'Screen Carbon Books Ltd');
        $sid = (int) $s['id'];
        $buyer = $this->signIn($buyerUser);

        // The tick on the supplier form: off by default, saved, shown on the page, taken off again.
        $edit = $buyer->get("/ui/purchasing/suppliers/{$sid}/edit");
        self::assertSame(200, $edit->status, $edit->describe());
        self::assertStringContainsString(Words::SUPPLIER_FORM['without_invoice_tick'], $edit->text());
        $form = $edit->form("/ui/purchasing/suppliers/{$sid}");
        self::assertArrayNotHasKey('goods_in_without_invoice', $form, 'not ticked');
        $r = $buyer->post("/ui/purchasing/suppliers/{$sid}", ['goods_in_without_invoice' => '1'] + $form);
        self::assertSame("/ui/purchasing/suppliers/{$sid}?notice=saved", $r->location(), $r->describe());
        self::assertSame(1, (int) self::$db->value('SELECT goods_in_without_invoice FROM supplier WHERE id = ?', [$sid]));
        $page = $buyer->get("/ui/purchasing/suppliers/{$sid}");
        self::assertStringContainsString(Words::SUPPLIER['without_invoice_yes'], $page->text());
        $edit = $buyer->get("/ui/purchasing/suppliers/{$sid}/edit");
        $form = $edit->form("/ui/purchasing/suppliers/{$sid}");
        self::assertSame('1', $form['goods_in_without_invoice'] ?? null, 'drawn ticked');
        unset($form['goods_in_without_invoice']);
        $buyer->post("/ui/purchasing/suppliers/{$sid}", $form);
        self::assertSame(0, (int) self::$db->value('SELECT goods_in_without_invoice FROM supplier WHERE id = ?', [$sid]), 'unticked: off again');
        self::assertStringNotContainsString(Words::SUPPLIER['without_invoice_yes'], $buyer->get("/ui/purchasing/suppliers/{$sid}")->text());
        $s = (new Suppliers(self::$db))->update(Caller::staff($buyerUser['id']), $sid, (int) self::$db->value('SELECT version FROM supplier WHERE id = ?', [$sid]),
            ['goods_in_without_invoice' => '1']);

        // A delivery of it with no invoice number and no copy, checked at the bench.
        $deskUser = $this->uiUser('purchasing_desk');
        $desk = Caller::staff($deskUser['id']);
        $sku = self::makeSku('Screen carbon item ' . bin2hex(random_bytes(3)));
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => new FileStore(self::$db, new LocalFileStorage($this->dir . '/store')));
        $d = $grns->createDraft($desk, $sid, []);
        $d = $grns->saveDraft($desk, $d->id, $d->version, [], [['sku_id' => $sku, 'packs' => 4, 'units_per_pack' => 1, 'pack_price' => '2.50']]);
        $d = $grns->bench(Caller::staff($this->uiUser('goods_in')['id']), $d->id, $docs->get($d->id)->version, ['paperwork_ok' => '1'],
            [1 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital']]);
        $web = $this->signIn($deskUser);
        $draft = $web->get('/ui/receiving/' . $d->id);
        self::assertSame(200, $draft->status, $draft->describe());
        self::assertStringContainsString(Words::say('RECEIPT_PLAN', 'invoice_to_come_both', (string) $s['name']), $draft->text(), 'a note, in words');
        self::assertStringNotContainsString(Words::RECEIPT_PLAN['invoice_number_required'], $draft->text(), 'not something to settle');
        self::assertStringNotContainsString(Words::RECEIPT_PLAN['invoice_file_required'], $draft->text());
        $p = $grns->post($desk, $d->id, $docs->get($d->id)->version);

        // Booked in: its page and the list of deliveries waiting for their invoice say the invoice is still to come.
        $grnPage = $web->get('/ui/receiving/' . $p->id);
        self::assertSame(200, $grnPage->status, $grnPage->describe());
        self::assertStringContainsString(Words::RECEIPT['invoice_to_come'], $grnPage->text());
        $list = $web->get('/ui/purchasing/invoices/not-invoiced');
        self::assertSame(200, $list->status, $list->describe());
        self::assertStringContainsString((string) $p->number, $list->text());
        self::assertStringContainsString(Words::INVOICES['invoice_to_come'], $list->text());
        $r = $web->post('/ui/purchasing/invoices/from-receipt', $list->form('/ui/purchasing/invoices/from-receipt'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(1, preg_match('#^/ui/purchasing/invoices/(\d+)#', (string) $r->location(), $m), (string) $r->location());
        self::assertSame(['draft', null], array_values((array) self::$db->one('SELECT status, external_ref FROM document WHERE id = ?', [(int) $m[1]])),
            'the invoice is started with no number: the desk types it from the paper');
        $inv = $web->get('/ui/purchasing/invoices/' . $m[1]);
        self::assertSame(200, $inv->status, $inv->describe());
    }
}
