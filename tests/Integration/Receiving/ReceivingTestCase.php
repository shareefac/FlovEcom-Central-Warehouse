<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Receiving;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\CwException;
use CW\Documents\Document;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Output\PdfWriter;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Receiving\GoodsReceipts;
use CW\Tests\Integration\PurchaseOrders\PurchaseOrderTestCase;

/**
 * Base of the goods receipt tests (IM6; docs/decisions.md I125-I147): the document base with the production handlers (PO, GRN),
 * the receipt service with a temporary file store (never the server's own, docs/dev.md), suppliers made active by a second
 * person, item cards, settings changed for a test and restored, and the full invariant check after every test (stock, documents
 * D1-D7, suppliers, POs, item cards, receipts G1-G8).
 */
abstract class ReceivingTestCase extends PurchaseOrderTestCase
{
    protected GoodsReceipts $grns;
    protected string $dir;
    protected FileStore $store;
    /** @var array<string, string> setting_key => value_json to restore */
    private array $savedSettings = [];
    private int $fileSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_rcv_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700);
        mkdir($this->dir . '/store', 0700);
        mkdir($this->dir . '/src', 0700);
        $this->store = new FileStore(self::$db, new LocalFileStorage($this->dir . '/store'));
        $this->grns = new GoodsReceipts(self::$db, $this->docs, null, fn (): FileStore => $this->store);
        // Before the refusal date unless a test says otherwise (the real 1 Jan 2027 would flip these tests when it passes).
        $this->setting('receiving.unstamped_refusal_from', json_encode(self::day('+1 year'), JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->savedSettings = [];
        if (str_starts_with(basename($this->dir), 'cw_rcv_')) {
            exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    /** Changes a setting for this test (admin connection; restored in tearDown). */
    protected function setting(string $key, string $json): void
    {
        if (!isset($this->savedSettings[$key])) {
            $this->savedSettings[$key] = (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        }
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        // Settings are read once per instance: services built after the change see it.
        $this->docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $this->pos = new PurchaseOrders(self::$db, $this->docs);
        $this->grns = new GoodsReceipts(self::$db, $this->docs, null, fn (): FileStore => $this->store);
    }

    /** A small real PDF (unique content) in the test's source directory. */
    protected function pdf(string $title = 'Supplier invoice'): string
    {
        $p = new PdfWriter($title . ' ' . (++$this->fileSeq) . ' ' . bin2hex(random_bytes(4)), 'test', true);
        $p->keyValues(['Invoice' => 'INV-' . $this->fileSeq]);
        $path = $this->dir . '/src/invoice-' . $this->fileSeq . '.pdf';
        file_put_contents($path, $p->output());
        return $path;
    }

    /** A file with these bytes in the test's source directory. */
    protected function file(string $bytes, string $name): string
    {
        $path = $this->dir . '/src/' . (++$this->fileSeq) . '-' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    /**
     * An item card (written through ItemCards as a stock controller), confirmed when $confirm.
     *
     * @param array<string, mixed> $fields product_type, liquid_ml, nicotine_mg, duty_liable, single_use ...
     */
    protected function card(int $sku, array $fields, bool $confirm = false, bool $acknowledge = false): void
    {
        $cards = new ItemCards(self::$db);
        $editor = $this->staffUser('stock_controller');
        $v = (int) (self::$db->value('SELECT version FROM item_card WHERE sku_id = ?', [$sku]) ?? 0);
        $r = $cards->save($editor, $sku, $v, $fields);
        if ($confirm) {
            $cards->confirm($editor, $sku, $r['version'], $acknowledge);
        }
    }

    /** An e-liquid card: duty-liable, $ml ml, 6 mg/ml (no TRPR breach at 10 ml). */
    protected function liquid(int $sku, string $ml = '10'): void
    {
        $this->card($sku, ['product_type' => 'e-liquid', 'liquid_ml' => $ml, 'nicotine_mg' => '6', 'duty_liable' => 'yes']);
    }

    /** A coil card: not duty-liable. */
    protected function dry(int $sku): void
    {
        $this->card($sku, ['product_type' => 'coil', 'duty_liable' => 'no']);
    }

    /**
     * A draft receipt of $desk with these lines (saveDraft's shape) and header.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $header
     */
    protected function receipt(Caller $desk, int $supplierId, array $lines, array $header = [], ?int $poId = null): Document
    {
        $d = $this->grns->createDraft($desk, $supplierId, $header + ['invoice_number' => 'INV-' . bin2hex(random_bytes(3))], $poId);
        return $lines === [] ? $d : $this->grns->saveDraft($desk, $d->id, $d->version, [], $lines);
    }

    /** Attaches a PDF invoice. */
    protected function invoice(Caller $who, int $id): void
    {
        $this->grns->attach($who, $id, $this->pdf(), 'invoice.pdf', 'supplier_invoice');
    }

    /**
     * The bench check: supplier and paperwork credible, and per line the given findings (default for every line: the stamp on
     * the pack, digital).
     *
     * @param array<int, array<string, mixed>> $lines
     */
    protected function bench(Caller $bench, int $id, array $lines = [], string $paperwork = '1'): Document
    {
        $doc = $this->docs->get($id);
        $all = [];
        foreach ($this->grns->lines($id) as $i => $l) {
            $all[$i + 1] = ['stamp_on_pack' => '1', 'stamp_type' => 'digital'];
        }
        foreach ($lines as $no => $f) {
            $all[$no] = $f + ['stamp_on_pack' => '1', 'stamp_type' => 'digital'];
        }
        return $this->grns->bench($bench, $id, $doc->version, ['paperwork_ok' => $paperwork], $all);
    }

    /** Invoice attached and the bench check done (default findings): ready to post. */
    protected function ready(Caller $desk, int $id, array $benchLines = []): Document
    {
        $this->invoice($desk, $id);
        return $this->bench($this->staffUser('goods_in'), $id, $benchLines);
    }

    /** Posts a draft at its current version. */
    protected function post(Caller $who, int $id): Document
    {
        return $this->grns->post($who, $id, $this->docs->get($id)->version);
    }

    /** on_hand of an item at a warehouse (0 when no balance). */
    protected function onHand(int $sku, string $warehouse = 'MAIN'): int
    {
        return (int) (self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = ?', [$sku, self::warehouseId($warehouse)]) ?? 0);
    }

    /** @return list<array<string, mixed>> the incidents of a receipt (kind, disposition, units, status), by id */
    protected function incidents(int $id): array
    {
        return self::$db->all('SELECT kind, disposition, units, status FROM incident WHERE document_id = ? ORDER BY id', [$id]);
    }

    /** @return array{doc: Document, sku: int, si: array<string, mixed>} an approved PO of $packs packs of $upp of a fresh item */
    protected function approvedPoFor(Caller $buyer, int $supplierId, int $packs, int $upp, string $price = '6.0000'): array
    {
        $sku = self::makeSku('PO item ' . bin2hex(random_bytes(3)));
        $si = $this->supplierItem($buyer, $supplierId, $sku, $upp, $price, ['supplier_code' => 'SC-' . $sku]);
        $d = $this->draftPo($buyer, $supplierId, [['supplier_item_id' => (int) $si['id'], 'packs' => $packs]]);
        return ['doc' => $this->pos->approve($buyer, $d->id, $d->version), 'sku' => $sku, 'si' => $si];
    }

    /** Asserts that $fn throws a CwException with this code (any status); returns it. */
    protected function refusedCode(string $code, callable $fn, string $why = ''): CwException
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame($code, $e->errorCode, trim($why . ' ' . $e->getMessage()));
            return $e;
        }
        self::fail("expected {$code}, nothing was thrown {$why}");
    }

    /** A purchasing desk person and a buyer, and an active supplier the buyer made. @return array{desk: Caller, buyer: Caller, supplier: array<string, mixed>} */
    protected function people(string $supplierName = 'Receiving Supplies'): array
    {
        $buyer = $this->staffUser('buyer');
        return ['desk' => $this->staffUser('purchasing_desk'), 'buyer' => $buyer, 'supplier' => $this->activeSupplier($buyer, ['name' => $supplierName])];
    }
}
