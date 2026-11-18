<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Documents\Documents;

/**
 * The line ids of a document (document_line.uid, document.next_line_uid; 0070, docs/decisions.md LV8), for the tests of every type whose
 * lines go through Documents::setLines(): what a save gave, and that a posting's fingerprint (posted_hash, document_posting) never covers
 * them. Uses the test's admin connection (IntegrationTestCase::$db).
 */
trait LineUids
{
    /** @return list<?int> the uids of a document's lines, line order */
    protected static function uidsOf(int $documentId): array
    {
        return array_map(static fn (mixed $v): ?int => $v === null ? null : (int) $v,
            self::$db->column('SELECT uid FROM document_line WHERE document_id = ? ORDER BY line_no', [$documentId]));
    }

    protected static function nextUidOf(int $documentId): int
    {
        return (int) self::$db->value('SELECT next_line_uid FROM document WHERE id = ?', [$documentId]);
    }

    /**
     * A posted document's fingerprint is that of its lines without their ids: posted_hash is the fingerprint of the lines as they are, the
     * posting record's content names no id, and giving every line another id changes neither (the ids are put back afterwards).
     */
    protected static function assertPostingIgnoresUids(Documents $docs, int $documentId): void
    {
        $doc = $docs->get($documentId);
        self::assertContains($doc->status, ['posted', 'reversed'], 'a posted document');
        $lines = $docs->lines($documentId);
        self::assertNotSame([], $lines);
        self::assertSame(['line_no', 'sku_id', 'warehouse_id', 'qty', 'unit_cost', 'amount', 'reason_code', 'description'], array_keys($lines[0]),
            'the fingerprinted line names no id');
        self::assertSame($doc->postedHash, Documents::fingerprint($doc, $lines));
        $content = (string) self::$db->value('SELECT content FROM document_posting WHERE document_id = ?', [$documentId]);
        self::assertSame(Documents::canonical($doc, $lines), $content);
        self::assertStringNotContainsString('uid', $content);
        $uids = self::uidsOf($documentId);
        self::$db->exec('UPDATE document_line SET uid = COALESCE(uid, line_no) + 100000 WHERE document_id = ?', [$documentId]);
        self::assertSame($doc->postedHash, Documents::fingerprint($docs->get($documentId), $docs->lines($documentId)), 'other ids, the same fingerprint');
        foreach ($uids as $i => $uid) {
            self::$db->exec('UPDATE document_line SET uid = ? WHERE document_id = ? AND line_no = ?', [$uid, $documentId, $i + 1]);
        }
        self::assertSame($uids, self::uidsOf($documentId));
    }
}
