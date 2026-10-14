<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Caller;
use CW\Documents\Document;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Invoices\SupplierInvoices;
use CW\Invoices\SupplierReturns;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Receiving\GoodsReceipts;
use CW\Tests\Integration\Receiving\ReceivingTestCase;

/**
 * Base of the supplier invoice, credit note and return tests (pack C; docs/decisions.md PC1-PC16): the receipt test base (suppliers,
 * orders, deliveries with a temporary file store, the full invariant check after every test, the invoice checks V1-V7 among them), the
 * invoice and return services, posted deliveries in one call, and kinds of record and settings changed for a test and put back.
 */
abstract class InvoicesTestCase extends ReceivingTestCase
{
    protected SupplierInvoices $invoices;
    protected SupplierReturns $returns;
    /** @var array<string, array<string, mixed>> document_type code => its row to put back */
    private array $savedTypes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->services();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedTypes as $code => $row) {
            self::$db->exec('UPDATE document_type SET review_rule = ?, review_limit_units = ?, approval_rule = ?, approval_limit_units = ?, size_approval = ?, '
                . 'size_units = ?, size_value = ? WHERE code = ?', [$row['review_rule'], $row['review_limit_units'], $row['approval_rule'], $row['approval_limit_units'],
                    $row['size_approval'], $row['size_units'], $row['size_value'], $code]);
        }
        $this->savedTypes = [];
        parent::tearDown();
    }

    /** The services again (settings are read once per instance; ReceivingTestCase::setting() rebuilds its own). */
    protected function services(): void
    {
        $this->docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $this->pos = new PurchaseOrders(self::$db, $this->docs);
        $this->grns = new GoodsReceipts(self::$db, $this->docs, null, fn (): FileStore => $this->store);
        $this->invoices = new SupplierInvoices(self::$db, $this->docs, fn (): FileStore => $this->store);
        $this->returns = new SupplierReturns(self::$db, $this->docs, fn (): FileStore => $this->store);
    }

    protected function setting(string $key, string $json): void
    {
        parent::setting($key, $json);
        $this->services();
    }

    /** Changes a kind of record's rules for this test (admin connection; put back in tearDown). @param array<string, mixed> $set */
    protected function rule(string $type, array $set): void
    {
        $this->savedTypes[$type] ??= (array) self::$db->one('SELECT * FROM document_type WHERE code = ?', [$type]);
        foreach ($set as $col => $v) {
            self::$db->exec("UPDATE document_type SET `{$col}` = ? WHERE code = ?", [$v, $type]);
        }
    }

    /**
     * An approved order of one line ($packs packs of $upp of a fresh item at $price) and its posted delivery of $received packs.
     *
     * @param array<int, array<string, mixed>> $bench the bench's findings by line
     * @return array{po: Document, grn: Document, sku: int, si: array<string, mixed>}
     */
    protected function orderedAndDelivered(Caller $desk, Caller $buyer, int $supplierId, int $packs, int $upp, string $price, int $received, array $bench = []): array
    {
        $o = $this->approvedPoFor($buyer, $supplierId, $packs, $upp, $price);
        $grn = $this->delivered($desk, $supplierId, [['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'packs' => $received, 'units_per_pack' => $upp,
            'pack_price' => $price, 'po_line_no' => 1]], $o['doc']->id, $bench);
        return ['po' => $o['doc'], 'grn' => $grn, 'sku' => $o['sku'], 'si' => $o['si']];
    }

    /**
     * A posted delivery (invoice copy attached, the bench's check done) of these lines (GoodsReceipts::saveDraft's shape).
     *
     * @param list<array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $bench
     */
    protected function delivered(Caller $desk, int $supplierId, array $lines, ?int $poId = null, array $bench = [], array $header = []): Document
    {
        $d = $this->receipt($desk, $supplierId, $lines, $header, $poId);
        $this->ready($desk, $d->id, $bench);
        return $this->post($desk, $d->id);
    }

    /** The invoice of a delivery the quick way, its totals typed (net: the lines' sum unless given) and posted. */
    protected function invoiceDelivery(Caller $desk, int $grnId, ?string $net = null, string $vat = '0.00'): Document
    {
        $r = $this->invoices->createFromReceipt($desk, $grnId);
        return $this->postWithTotals($desk, $r['document'], $net, $vat);
    }

    /** Types a draft's totals (net: the lines' sum unless given) and posts it. */
    protected function postWithTotals(Caller $desk, Document $d, ?string $net = null, string $vat = '0.00', array $header = []): Document
    {
        $net ??= (string) self::$db->value('SELECT CAST(COALESCE(SUM(amount), 0) AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$d->id]);
        $d = $this->invoices->saveHeader($desk, $d->id, $this->docs->get($d->id)->version, ['net_total' => $net, 'vat_total' => $vat] + $header);
        return $this->invoices->post($desk, $d->id, $d->version);
    }

    /** @return array<string, mixed> the stored match of an invoice */
    protected function stored(int $id): array
    {
        return (array) self::$db->one('SELECT match_state, match_lines_off, match_difference FROM supplier_invoice WHERE document_id = ?', [$id]);
    }

    /** The open task of a document (0 when none). */
    protected function openDocTask(int $documentId, string $kind = 'approval'): int
    {
        return (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = ? AND state = 'open'", [$documentId, $kind]);
    }
}
