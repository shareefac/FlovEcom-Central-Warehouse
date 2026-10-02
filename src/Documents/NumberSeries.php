<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\CwException;
use CW\Db;

/**
 * Document numbers (I20): one continuous series per prefix (PO-000001, GRN-000001, ...), no yearly reset, no gaps.
 *
 * A number is taken INSIDE the posting transaction with one statement,
 *   UPDATE number_series SET last_no = LAST_INSERT_ID(last_no + 1) WHERE prefix = ?
 * which X-locks the series row until commit and hands the new value back through this connection's
 * LAST_INSERT_ID() (no second read of a row another session could move). A rolled-back posting restores last_no
 * with everything else, so the next posting gets the same number: 1..last_no are always all on posted documents.
 * The cost is that postings of one type serialise from their numbering to their commit (acceptable at about 15
 * postings a day; I21 puts the series row after the document rows and before every stock lock).
 */
final class NumberSeries
{
    public function __construct(private readonly Db $db)
    {
    }

    /** The next number of $prefix ("ADJ-000001"; wider when the series outgrows its pad: "ADJ-1000000"). */
    public function next(string $prefix): string
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('NumberSeries::next() runs inside the posting transaction (a number outside it could be lost)');
        }
        if ($this->db->exec('UPDATE number_series SET last_no = LAST_INSERT_ID(last_no + 1) WHERE prefix = ?', [$prefix]) !== 1) {
            throw new CwException('unknown_series', "there is no number series {$prefix}", 404, ['prefix' => $prefix]);
        }
        $r = $this->db->one('SELECT LAST_INSERT_ID() AS no, pad FROM number_series WHERE prefix = ?', [$prefix]);
        if ($r === null) {
            throw new \LogicException("number series {$prefix} vanished inside its own transaction");
        }
        return self::format($prefix, (int) $r['no'], (int) $r['pad']);
    }

    public static function format(string $prefix, int $no, int $pad): string
    {
        return sprintf('%s-%0' . max(1, $pad) . 'd', $prefix, $no);
    }

    /** The number a document number carries ("ADJ-000123" -> 123), or null when it is not of $prefix. */
    public static function parse(string $prefix, string $number): ?int
    {
        return preg_match('/^' . preg_quote($prefix, '/') . '-([0-9]{1,19})$/D', $number, $m) === 1 ? (int) $m[1] : null;
    }
}
