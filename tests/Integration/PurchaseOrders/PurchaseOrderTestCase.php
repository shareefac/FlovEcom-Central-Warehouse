<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Caller;
use CW\Documents\Document;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Tests\Integration\Suppliers\SupplierTestCase;

/**
 * Base of the purchase-order tests: the document base with the production handlers (DocumentHandlers::all: PO), the PO
 * service, suppliers made active by a second person (SupplierTestCase), supplier items with prices, and the full invariant
 * check after every test (stock, documents D1-D7, suppliers S1-S5, purchase orders P1-P6).
 */
abstract class PurchaseOrderTestCase extends SupplierTestCase
{
    protected Documents $docs;
    protected PurchaseOrders $pos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $this->pos = new PurchaseOrders(self::$db, $this->docs);
    }

    /** An item with a usable barcode (the GTIN key) and optionally a case barcode. */
    protected function itemWithBarcode(string $name, string $barcode, ?string $caseBarcode = null, int $perCase = 6): int
    {
        $sku = self::makeSku($name);
        self::$db->exec('INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES (?, ?, 1)', [ltrim($barcode, '0'), $sku]);
        if ($caseBarcode !== null) {
            self::$db->exec('INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES (?, ?, ?)', [ltrim($caseBarcode, '0'), $sku, $perCase]);
        }
        return $sku;
    }

    /**
     * A supplier item (made by $buyer) with a manual price.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function supplierItem(Caller $buyer, int $supplierId, int $sku, int $upp = 1, ?string $price = null, array $fields = []): array
    {
        return $this->items->create($buyer, $supplierId, $sku, $fields + ['units_per_pack' => (string) $upp], $price === null ? null : ['pack_price' => $price]);
    }

    /**
     * A draft PO of $buyer with these lines.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $header
     */
    protected function draftPo(Caller $buyer, int $supplierId, array $lines, array $header = []): Document
    {
        $d = $this->pos->createDraft($buyer, $supplierId, $header);
        return $this->pos->saveDraft($buyer, $d->id, $d->version, [], $lines);
    }

    /**
     * An approved PO (one line of $packs packs of a fresh supplier item at $price).
     *
     * @return array{doc: Document, supplier: array<string, mixed>, sku: int, si: array<string, mixed>}
     */
    protected function approvedPo(Caller $buyer, int $packs = 2, string $price = '12.0000', int $upp = 6): array
    {
        $s = $this->activeSupplier($buyer, ['name' => 'Supplier ' . bin2hex(random_bytes(3))]);
        $sku = self::makeSku('PO item ' . bin2hex(random_bytes(3)));
        $si = $this->supplierItem($buyer, (int) $s['id'], $sku, $upp, $price, ['supplier_code' => 'SC-' . $sku]);
        $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => $packs]]);
        $d = $this->pos->approve($buyer, $d->id, $d->version);
        return ['doc' => $d, 'supplier' => $s, 'sku' => $sku, 'si' => $si];
    }

    /** @return array<string, mixed> */
    protected function poRow(int $id): array
    {
        return (array) self::$db->one('SELECT * FROM purchase_order WHERE document_id = ?', [$id]);
    }

    /** @return list<string> the audit actions of a document, oldest first */
    protected function audits(int $documentId): array
    {
        return array_map('strval', self::$db->column("SELECT action FROM audit_log WHERE entity_type = 'document' AND entity_id = ? ORDER BY id", [(string) $documentId]));
    }

    /** The open task of a document (0 when none). */
    protected function docTask(int $documentId, string $kind = 'review'): int
    {
        return (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = ? AND state = 'open'",
            [$documentId, $kind]);
    }
}
