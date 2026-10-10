<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\Db;
use CW\PurchaseOrders\PurchaseOrderHandler;
use CW\Receiving\GoodsReceiptHandler;
use CW\StockOps\StockOpHandler;

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
 *   - pack A1 (8 Oct 2026, the owner's module list): SIN stock in, SOUT stock out, ADJ adjustments (write-offs included), TRF
 *          transfers, REL releases from another account's warehouse (CW\StockOps\StockOpHandler, docs/decisions.md SO1-SO16) — LIVE.
 * The document-base tests still register tests/Support/Documents/FixtureAdjustmentHandler as ADJ on their own Documents (and
 * KernelUiTestCase::kernel() puts it in place of the real ADJ for the screen tests written before A1): it never ships.
 */
final class DocumentHandlers
{
    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock for the stock records (tests pin it)
     * @return array<string, DocumentHandler> type code => handler
     */
    public static function all(Db $db, ?\Closure $clock = null): array
    {
        return ['PO' => new PurchaseOrderHandler($db), 'GRN' => new GoodsReceiptHandler($db)] + StockOpHandler::all($db, $clock);
    }
}
