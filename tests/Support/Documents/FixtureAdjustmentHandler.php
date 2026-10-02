<?php

declare(strict_types=1);

namespace CW\Tests\Support\Documents;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\DocumentHandler;
use CW\Movements;

/**
 * A stock adjustment document type FOR TESTS ONLY (I27: no type is live in Phase I-1; IM2 builds the real ADJ in I-4).
 * It is what the document base needs to be exercised end to end: every line is one signed `adjustment` of its item
 * (at the line's warehouse, else the header's, else MAIN), with its unit cost when given, booked in ONE
 * Movements::bookForDocument call; the review units are the sum of |qty|; the approval units (decision 11's rule
 * "positive without a supplier document") are the positive units when the header has no external_ref; a reversal
 * adds no module rows (the stock is reversed generically).
 */
final class FixtureAdjustmentHandler implements DocumentHandler
{
    private readonly Movements $moves;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(Db $db, ?\Closure $clock = null)
    {
        $this->moves = new Movements($db, null, $clock);
    }

    public function type(): string
    {
        return 'ADJ';
    }

    public function validate(Db $db, Document $doc, array $lines): void
    {
        foreach ($lines as $l) {
            if ($l['sku_id'] === null || $l['qty'] === null || $l['qty'] === 0) {
                throw new CwException('bad_line', "line {$l['line_no']}: an adjustment line names an item and a non-zero quantity", 422, ['line' => $l['line_no']]);
            }
        }
    }

    public function approvalUnits(Db $db, Document $doc, array $lines): int
    {
        if ($doc->externalRef !== null) {
            return 0;
        }
        return array_sum(array_map(static fn (array $l): int => max(0, (int) $l['qty']), $lines));
    }

    public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int
    {
        $codes = [];
        foreach ($db->all('SELECT id, code FROM warehouse') as $w) {
            $codes[(int) $w['id']] = (string) $w['code'];
        }
        $out = [];
        foreach ($lines as $l) {
            $wh = $l['warehouse_id'] ?? $doc->warehouseId;
            $out[] = ['document_line' => $l['line_no'], 'sku_id' => $l['sku_id'], 'qty' => $l['qty']]
                + ($wh === null ? [] : ['warehouse' => $codes[$wh]])
                + ($l['unit_cost'] === null ? [] : ['unit_cost' => $l['unit_cost']]);
        }
        $this->moves->bookForDocument($caller, ['document_id' => $doc->id, 'doc_ref' => (string) $doc->number], $opKey,
            [['type' => 'adjustment', 'note' => $doc->note, 'lines' => $out]]);
        return array_sum(array_map(static fn (array $l): int => abs((int) $l['qty']), $lines));
    }

    public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void
    {
    }
}
