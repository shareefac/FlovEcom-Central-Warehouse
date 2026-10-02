<?php

declare(strict_types=1);

namespace CW\Tests\Support\Documents;

use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Documents\NumberSeries;

/**
 * Real `document` rows for tests that book stock with Movements::bookForDocument / reverseDocument directly (c0's
 * DocumentBookingTest, GrantsTest): DocumentInvariants checks that every ledger row's document_id names a posted
 * document numbered as its doc_ref, that numbers are gapless, that reversal pairs are whole and that every posted
 * document has its posting record (document_posting). Written with the admin connection; the number series of the
 * type is moved up to the number used.
 */
final class FixtureDocuments
{
    /**
     * A posted document with id $id and number $no of $type (no lines); when $reversesId is given it is that
     * document's reversal and the original becomes `reversed`. Returns the number ("ADJ-000001").
     */
    public static function posted(Db $db, int $id, string $type, int $no, ?int $reversesId = null): string
    {
        $prefix = (string) $db->value('SELECT prefix FROM document_type WHERE code = ?', [$type]);
        $number = NumberSeries::format($prefix, $no, (int) $db->value('SELECT pad FROM number_series WHERE prefix = ?', [$prefix]));
        $db->exec(
            'INSERT INTO document (id, doc_type, number, status, created_actor, posted_actor, posted_at, posted_hash, review_state, reverses_id) '
            . "VALUES (?, ?, ?, 'posted', 'staff:1', 'staff:1', UTC_TIMESTAMP(6), ?, 'not_required', ?)",
            [$id, $type, $number, str_repeat('0', 64), $reversesId],
        );
        $row = (array) $db->one('SELECT * FROM document WHERE id = ?', [$id]);
        $content = Documents::canonical(Document::fromRow($row), []);
        $db->exec('UPDATE document SET posted_hash = ? WHERE id = ?', [hash('sha256', $content), $id]);
        // The write-once posting record DocumentInvariants D7 checks the document against (I33).
        $db->exec('INSERT INTO document_posting (document_id, number, posted_hash, posted_by, posted_actor, posted_at, content) '
            . 'SELECT id, number, posted_hash, posted_by, posted_actor, posted_at, ? FROM document WHERE id = ?', [$content, $id]);
        if ($reversesId !== null) {
            $db->exec("UPDATE document SET status = 'reversed' WHERE id = ?", [$reversesId]);
        }
        $db->exec('UPDATE number_series SET last_no = GREATEST(last_no, ?) WHERE prefix = ?', [$no, $prefix]);
        return $number;
    }
}
