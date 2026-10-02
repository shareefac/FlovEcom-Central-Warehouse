<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\Db;

/**
 * The registry of live document types: type code => DocumentHandler. A type without a handler cannot be drafted,
 * posted or reversed (409 type_not_built); its documents screens say which phase brings it (document_type.phase).
 *
 * EMPTY in Phase I-1 (I27): the document base, the review queue, the number series and the reference screens are
 * real, but no type is live yet. The phases register:
 *   - I-2: PO (purchase orders; IM5), and supplier activation reviews (review_task subject 'supplier', IM4);
 *   - I-3: GRN (receive + invoice with the duty-stamp checks; IM6);
 *   - I-4: SINV, DN (supplier invoices, credit/debit notes, supplier returns; IM7), CNT, ADJ, WO (counts,
 *          adjustments, write-offs; IM2);
 *   - I-6: TRD (trade, inter-site and shop-unit issues; IM11).
 * Tests register tests/Support/Documents/FixtureAdjustmentHandler as ADJ (through Ui\Kernel's $handlers).
 */
final class DocumentHandlers
{
    /** @return array<string, DocumentHandler> type code => handler */
    public static function all(Db $db): array
    {
        return [];
    }
}
