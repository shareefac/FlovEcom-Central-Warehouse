<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\Db;

/**
 * A document type whose records can wait for a reviewer's OK because they are big (pack A1; docs/decisions.md SO5): the "OK first
 * for a big record" of the Approval Rules page (document_type.size_approval, size_units, size_value; off by default, the owner's rule
 * that extra approvals start off). Documents::post asks the handler how big a record is when the type's switch is on, and a record
 * moving more than size_units units, or worth more than size_value whole pounds, goes to a reviewer first (review_task reason
 * `over_size`) instead of being posted: nothing is numbered or booked until a reviewer says OK.
 *
 * The figures are the record's own: units are the sum of |qty| of its item lines; the value is in whole pounds, rounded up, from
 * each line's unit cost or agreed price where it has one, else the product's average cost so far (CW\StockOps\CostHints), 0 when
 * nothing is known.
 */
interface SizeApproval
{
    /**
     * @param list<array<string, mixed>> $lines Documents::lines() rows
     * @return array{units: int, value: int}
     */
    public function size(Db $db, Document $doc, array $lines): array;
}
