<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\Db;
use CW\PurchaseOrders\PurchaseOrderHandler;
use CW\Receiving\GoodsReceiptHandler;

/**
 * The registry of live document types: type code => DocumentHandler. A type without a handler cannot be drafted,
 * posted or reversed (409 type_not_built); its documents screens say which phase brings it (document_type.phase).
 *
 * Empty in Phase I-1 (I27). The phases register:
 *   - I-2: PO (purchase orders, IM5: CW\PurchaseOrders\PurchaseOrderHandler, I48) — LIVE; supplier activation reviews are
 *          review_task subject 'supplier' (CW\Suppliers\Suppliers, IM4), not a document type;
 *   - I-3: GRN (receive + invoice with the duty-stamp checks; IM6: CW\Receiving\GoodsReceiptHandler, I125-I147) — LIVE;
 *   - I-4: SINV, DN (supplier invoices, credit/debit notes, supplier returns; IM7), CNT, ADJ, WO (counts,
 *          adjustments, write-offs; IM2);
 *   - I-6: TRD (trade, inter-site and shop-unit issues; IM11).
 * Tests also register tests/Support/Documents/FixtureAdjustmentHandler as ADJ (KernelUiTestCase::kernel(), the document
 * tests): it never ships.
 */
final class DocumentHandlers
{
    /** @return array<string, DocumentHandler> type code => handler */
    public static function all(Db $db): array
    {
        return ['PO' => new PurchaseOrderHandler($db), 'GRN' => new GoodsReceiptHandler($db)];
    }
}
