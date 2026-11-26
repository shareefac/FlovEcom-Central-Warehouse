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
use CW\Items\ItemOwners;
use CW\Receiving\GoodsReceipts;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;

/**
 * CW-1153, the final review of 2d13f32 (Fable), the screens, red on 2d13f32 first:
 *  M5   a final bill that is a shop's own bill says so on its own page ("Whose bill"), not only on the list
 *  L11a Real profit says the shop's own goods are "on their own line above" only when such a line is shown
 */
final class OwnItemsFinalScreensTest extends KernelUiTestCase
{
    private string $dir;
    private string|false $prevStore = false;
    private ?string $prevCutoff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_ownbill_ui_' . bin2hex(random_bytes(4));
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

    /** @return array<string, mixed> an active supplier (made by a buyer, approved by a reviewer) */
    private function supplier(string $name): array
    {
        $buyer = $this->uiUser('buyer')['id'];
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => $name, 'address_line1' => '2 Mill Lane', 'city' => 'Leeds', 'postcode' => 'M1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    private function store(): FileStore
    {
        return new FileStore(self::$db, new LocalFileStorage($this->dir . '/store'));
    }

    private function pdf(): string
    {
        $pdf = $this->dir . '/invoice-' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($pdf, (new \CW\Output\PdfWriter('Invoice ' . bin2hex(random_bytes(4)), 'test', true))->output());
        return $pdf;
    }

    /** @return array<string, string> a two-column facts list: label => value */
    private static function facts(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//dt') ?: [] as $dt) {
            $dd = $dt->nextSibling;
            while ($dd !== null && !($dd instanceof \DOMElement && $dd->tagName === 'dd')) {
                $dd = $dd->nextSibling;
            }
            $out[trim((string) preg_replace('/\s+/u', ' ', $dt->textContent))] = $dd === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $dd->textContent));
        }
        return $out;
    }

    public function testFableM5AFinalOwnBillSaysWhoseBillOnItsOwnPage(): void
    {
        $this->site('vapebig');
        $s = $this->supplier('Cloud Tech Final');
        $sku = self::makeSku('Hayati 4000 final bill');
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]), 'Vape Big\'s own goods');
        $deskUser = $this->uiUser('purchasing_desk');
        $desk = Caller::staff($deskUser['id']);
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => $this->store());
        $d = $grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'OWN-' . bin2hex(random_bytes(3))]);
        $d = $grns->saveDraft($desk, $d->id, $d->version, [], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 1, 'pack_price' => '3.75']]);
        $grns->attach($desk, $d->id, $this->pdf(), 'invoice.pdf', 'supplier_invoice');
        $d = $grns->bench(Caller::staff($this->uiUser('goods_in')['id']), $d->id, $docs->get($d->id)->version, ['paperwork_ok' => '1'],
            [1 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital']]);
        $grn = $grns->post($desk, $d->id, $docs->get($d->id)->version);
        $invoices = new SupplierInvoices(self::$db, $docs, fn (): FileStore => $this->store());
        $inv = $invoices->createFromReceipt($desk, $grn->id)['document'];
        $inv = $invoices->saveHeader($desk, $inv->id, $docs->get($inv->id)->version, ['net_total' => '3.75', 'vat_total' => '0.00']);
        $inv = $invoices->post($desk, $inv->id, $inv->version);
        self::assertNotNull(self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$inv->id]), 'the shop\'s own bill');
        $web = $this->signIn($deskUser);
        $page = $web->get('/ui/purchasing/invoices/' . $inv->id);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame('VAPEBIG test site\'s own bill: not ours to pay', self::facts($page)['Whose bill'] ?? null, 'on the final bill\'s own page');
    }

    public function testFableL11aRealProfitSaysTheOwnLineIsAboveOnlyWhenItIs(): void
    {
        $vb = $this->site('vapebig');
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', ['"2026-11-01"', 'profit.real_from_vapebig']);
        try {
            $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');
            $page = $this->signIn($this->uiUser('finance'))->get('/ui/profit/real', ['period' => 'choose', 'from' => $day, 'to' => $day, 'store' => (int) $vb->channelId]);
            self::assertSame(200, $page->status, $page->describe());
            self::assertStringNotContainsString('on their own line above', (string) preg_replace('/\s+/u', ' ', $page->text()), 'no own line shown: no such words');
        } finally {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', ['"2026-12-01"', 'profit.real_from_vapebig']);
        }
    }
}
