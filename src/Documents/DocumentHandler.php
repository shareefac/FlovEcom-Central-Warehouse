<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\Caller;
use CW\Db;

/**
 * What one document type does when it is posted or reversed (one implementation per type, built by the phase that
 * brings its screens: DocumentHandlers). The generic part (Documents) owns the header, the status machine, the
 * number, the review tasks, the audit rows and the generic stock reversal; a handler owns the type's rules and its
 * effects. Every method runs inside the posting transaction, after the document row and (for post/reverse) the
 * number series row are locked; a handler takes its own module rows next and books stock last (I21).
 *
 * $lines are `document_line` rows as read (line_no order): line_no, sku_id, warehouse_id, qty, unit_cost, amount,
 * reason_code, description.
 */
interface DocumentHandler
{
    /** The document_type.code it handles ('ADJ'). */
    public function type(): string;

    /**
     * Refuses a document the type cannot post (CwException 422 naming the problem and the line). Called before the
     * approval check and again when an approval is given (the world may have changed since the request).
     *
     * @param list<array<string, mixed>> $lines
     */
    public function validate(Db $db, Document $doc, array $lines): void;

    /**
     * The figure compared with document_type.approval_limit_units when the type has an approval rule (ADJ: the
     * positive units without a supplier document). 0 = no approval needed.
     *
     * The limit applies per document, so a handler must not be fooled by splitting (I19, I37; for the I-4 ADJ handler
     * and decision 11): "a supplier document" must be verifiable evidence (an attached stored file with the role
     * supplier_invoice or delivery_note, or a posted SINV/GRN it names), never any non-empty external_ref; and the
     * handler should count the person's other positive units of the day toward the limit (an aggregate), so five
     * postings of +10 are one of +50.
     *
     * @param list<array<string, mixed>> $lines
     */
    public function approvalUnits(Db $db, Document $doc, array $lines): int;

    /**
     * The posting's effects: module rows first, stock last and in ONE Movements::bookForDocument call (one lock(),
     * one flush(): nothing may be locked after the feed clock). $doc already carries its number (the ledger's doc_ref).
     * Returns the units compared with document_type.review_limit_units (over_limit rule).
     *
     * @param list<array<string, mixed>> $lines
     */
    public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int;

    /**
     * The module side of a reversal (module rows only). Stock is reversed generically afterwards
     * (Movements::reverseDocument, the exact negation of the original's ledger rows), so a handler never books stock here.
     *
     * @param list<array<string, mixed>> $lines the reversal's lines (the original's, negated)
     */
    public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void;
}
