<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Documents;

use CW\Caller;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Tests\Support\Documents\FixtureAdjustmentHandler;
use CW\Tests\Support\MappingTestCase;

/**
 * Base of the document-base tests: CW\Documents\Documents with the test-only ADJ type (FixtureAdjustmentHandler; no
 * type is live in I-1, I27), staff with roles (MappingTestCase::staffUser), items with stock (StockTestCase), and the
 * full invariant check after every test (stock, value sequence and DocumentInvariants).
 */
abstract class DocumentTestCase extends MappingTestCase
{
    protected Documents $docs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docs = new Documents(self::$db, ['ADJ' => new FixtureAdjustmentHandler(self::$db)]);
    }

    /**
     * A draft ADJ with these lines, by $who.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $header
     */
    protected function draft(Caller $who, array $lines, array $header = ['external_ref' => 'SUP-1']): Document
    {
        $d = $this->docs->createDraft($who, 'ADJ', $header);
        return $this->docs->setLines($who, $d->id, $d->version, $lines);
    }

    /**
     * A draft ADJ, posted by $who (posted, or awaiting approval when its positive units without a supplier document
     * pass the limit).
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $header
     */
    protected function posted(Caller $who, array $lines, array $header = ['external_ref' => 'SUP-1']): Document
    {
        $d = $this->draft($who, $lines, $header);
        return $this->docs->post($who, $d->id, $d->version);
    }

    /** The open task of a document (0 when none). */
    protected function openTask(int $documentId, string $kind = 'review'): int
    {
        return (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = ? AND state = 'open'",
            [$documentId, $kind]);
    }

    /** @return array<string, mixed> */
    protected function task(int $id): array
    {
        return (array) self::$db->one('SELECT * FROM review_task WHERE id = ?', [$id]);
    }

    /** @return list<string> the audit actions written for a document, oldest first */
    protected function audits(int $documentId): array
    {
        return array_map('strval', self::$db->column("SELECT action FROM audit_log WHERE entity_type = 'document' AND entity_id = ? ORDER BY id", [(string) $documentId]));
    }

    /** @return list<array{int, string, int, ?string, ?string}> [document_line, doc_ref, qty_delta, unit_cost, cost_source] of a document's ledger rows */
    protected function ledger(int $documentId): array
    {
        return array_map(static fn (array $r): array => [(int) $r['document_line'], (string) $r['doc_ref'], (int) $r['qty_delta'], $r['unit_cost'], $r['cost_source']],
            self::$db->all('SELECT document_line, doc_ref, qty_delta, unit_cost, cost_source FROM stock_ledger WHERE document_id = ? ORDER BY id', [$documentId]));
    }
}
