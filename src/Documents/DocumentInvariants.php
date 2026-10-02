<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\Db;

/**
 * The nightly checks of the document base (0008; called at the end of CW\Invariants::check, so bin/invariants.php,
 * the hammer and every stock test run them). Read-only; at most MAX_PER_CHECK violations per check.
 *
 *  D1. per number series, the posted and reversed documents of its prefix carry exactly the numbers 1..last_no, each
 *      in its canonical form (PREFIX-000123): no gap, no number outside the series (I20).
 *  D2. a reversal is of its original's type and its original is not itself a reversal; a posted reversal's original is
 *      `reversed`, a reversal request waiting for approval has a `posted` original, a cancelled request any (I32); a
 *      reversal is never a draft or reversed; every `reversed` document has its posted reversal; together they net to
 *      zero on the stock ledger per balance and bucket (I18).
 *  D3. every stock_ledger.document_id names a posted or reversed document, and the row's doc_ref is its number (I2).
 *  D4. open review tasks only on posted documents whose review is pending (and every such document has exactly one);
 *      open approval tasks only on documents awaiting approval (and every such document has exactly one) (I19).
 *  D5. a decided task was decided by someone other than its document's creator, submitter and poster, and the staff
 *      ids of tasks (no foreign keys: written after the stock locks, I21) exist.
 *  D6. every document_line.sku_id exists (no foreign key, D15).
 *  D7. the posting record (I33): every posted or reversed document has its write-once document_posting row and its
 *      number, posted_hash, posted_by, posted_actor and posted_at still equal it (a document row rewritten by the app
 *      login, posted_hash or posted_at included, is found); no unposted document has one; each record's content hashes
 *      to its posted_hash; and the header and lines of EVERY posted document, recomputed (by id, in batches of
 *      HASH_BATCH), hash to its record's posted_hash, so a line changed after posting is found whatever the posted_at.
 */
final class DocumentInvariants
{
    private const MAX_PER_CHECK = 50;
    public const HASH_BATCH = 500;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::numbers($db), ...self::reversals($db), ...self::ledger($db), ...self::tasks($db), ...self::lines($db), ...self::hashes($db)];
    }

    /** @return list<string> D1 */
    private static function numbers(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT n.prefix, n.last_no, n.pad, COUNT(d.id) AS docs, '
            . 'MIN(CAST(SUBSTRING(d.number, CHAR_LENGTH(n.prefix) + 2) AS UNSIGNED)) AS lo, MAX(CAST(SUBSTRING(d.number, CHAR_LENGTH(n.prefix) + 2) AS UNSIGNED)) AS hi '
            . 'FROM number_series n LEFT JOIN document_type t ON t.prefix = n.prefix '
            . "LEFT JOIN document d ON d.doc_type = t.code AND d.status IN ('posted', 'reversed') "
            . 'GROUP BY n.prefix, n.last_no, n.pad ORDER BY n.prefix',
        ) as $r) {
            $n = (int) $r['last_no'];
            if ((int) $r['docs'] !== $n || ($n > 0 && ((int) $r['lo'] !== 1 || (int) $r['hi'] !== $n))) {
                $v[] = "number series {$r['prefix']}: last_no {$n} but its posted documents carry {$r['docs']} numbers"
                    . ((int) $r['docs'] > 0 ? " from {$r['lo']} to {$r['hi']}" : '') . ' (expected exactly 1..' . $n . ')';
            }
        }
        foreach ($db->all(
            'SELECT d.id, d.number, t.prefix, n.pad FROM document d JOIN document_type t ON t.code = d.doc_type JOIN number_series n ON n.prefix = t.prefix '
            . "WHERE d.number IS NOT NULL ORDER BY d.id",
        ) as $r) {
            $no = NumberSeries::parse((string) $r['prefix'], (string) $r['number']);
            if ($no === null || NumberSeries::format((string) $r['prefix'], $no, (int) $r['pad']) !== $r['number']) {
                $v[] = "document {$r['id']}: number {$r['number']} is not a {$r['prefix']} number in its canonical form";
                if (count($v) >= self::MAX_PER_CHECK) {
                    break;
                }
            }
        }
        return $v;
    }

    /** @return list<string> D2 */
    private static function reversals(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT r.id, r.number, r.status, r.doc_type, o.id AS o_id, o.status AS o_status, o.doc_type AS o_type, o.reverses_id AS o_reverses '
            . 'FROM document r LEFT JOIN document o ON o.id = r.reverses_id WHERE r.reverses_id IS NOT NULL '
            . "AND (r.status IN ('draft', 'reversed') OR o.id IS NULL OR o.doc_type <> r.doc_type OR o.reverses_id IS NOT NULL "
            . "  OR (r.status = 'posted' AND o.status <> 'reversed') OR (r.status = 'awaiting_approval' AND o.status <> 'posted')) "
            . 'ORDER BY r.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "reversal {$r['id']} (" . ($r['number'] ?? 'unnumbered') . ", {$r['status']} {$r['doc_type']}) of document {$r['o_id']} ("
                . ($r['o_id'] === null ? 'missing' : "{$r['o_status']} {$r['o_type']}" . ($r['o_reverses'] !== null ? ', itself a reversal' : '')) . ')';
        }
        foreach ($db->all(
            "SELECT o.id, o.number FROM document o LEFT JOIN document r ON r.reverses_id = o.id AND r.status = 'posted' "
            . "WHERE o.status = 'reversed' AND r.id IS NULL ORDER BY o.id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "document {$r['id']} ({$r['number']}) is reversed but has no reversal";
        }
        foreach ($db->all(
            'SELECT r.id, r.reverses_id, l.warehouse_id, l.sku_id, l.bucket, SUM(l.qty_delta) AS net FROM document r '
            . "JOIN stock_ledger l ON l.document_id IN (r.id, r.reverses_id) WHERE r.reverses_id IS NOT NULL AND r.status = 'posted' "
            . 'GROUP BY r.id, r.reverses_id, l.warehouse_id, l.sku_id, l.bucket HAVING SUM(l.qty_delta) <> 0 ORDER BY r.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "reversal {$r['id']} of document {$r['reverses_id']}: {$r['bucket']} of {$r['warehouse_id']}:{$r['sku_id']} nets to {$r['net']}, not 0";
        }
        return $v;
    }

    /** @return list<string> D3 */
    private static function ledger(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT l.document_id, l.doc_ref, d.id AS d_id, d.status, d.number FROM '
            . '(SELECT DISTINCT document_id, doc_ref FROM stock_ledger WHERE document_id IS NOT NULL) l LEFT JOIN document d ON d.id = l.document_id '
            . "WHERE d.id IS NULL OR d.status NOT IN ('posted', 'reversed') OR NOT (l.doc_ref <=> d.number) "
            . 'ORDER BY l.document_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "stock_ledger rows of document {$r['document_id']} (doc_ref " . ($r['doc_ref'] ?? 'NULL') . ') '
                . ($r['d_id'] === null ? 'name a document that does not exist' : "name a {$r['status']} document numbered " . ($r['number'] ?? 'NULL'));
        }
        return $v;
    }

    /** @return list<string> D4, D5 */
    private static function tasks(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            "SELECT t.id, t.kind, d.id AS d_id, d.status, d.review_state FROM review_task t LEFT JOIN document d ON d.id = t.subject_id "
            . "WHERE t.subject_type = 'document' AND t.state = 'open' AND (d.id IS NULL "
            . "  OR (t.kind = 'review' AND (d.status <> 'posted' OR NOT (d.review_state <=> 'pending'))) "
            . "  OR (t.kind = 'approval' AND d.status <> 'awaiting_approval')) ORDER BY t.id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "open {$r['kind']} task {$r['id']} on document {$r['d_id']}, which is "
                . ($r['d_id'] === null ? 'missing' : "{$r['status']} (review " . ($r['review_state'] ?? 'none') . ')');
        }
        foreach ($db->all(
            "SELECT d.id, d.status, (SELECT COUNT(*) FROM review_task t WHERE t.subject_type = 'document' AND t.subject_id = d.id AND t.state = 'open' "
            . "AND t.kind = IF(d.status = 'posted', 'review', 'approval')) AS n FROM document d "
            . "WHERE (d.status = 'posted' AND d.review_state = 'pending') OR d.status = 'awaiting_approval' HAVING n <> 1 ORDER BY d.id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "document {$r['id']} is " . ($r['status'] === 'posted' ? 'waiting for its review' : 'awaiting approval') . " but has {$r['n']} open tasks for it";
        }
        foreach ($db->all(
            "SELECT t.id, t.decided_by, d.id AS d_id FROM review_task t JOIN document d ON d.id = t.subject_id WHERE t.subject_type = 'document' "
            . 'AND t.decided_by IS NOT NULL AND (t.decided_by <=> d.created_by OR t.decided_by <=> d.submitted_by OR t.decided_by <=> d.posted_by) '
            . 'ORDER BY t.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "review task {$r['id']} was decided by staff {$r['decided_by']}, who created, submitted or posted document {$r['d_id']}";
        }
        foreach ($db->all(
            'SELECT t.id, t.opened_by, t.decided_by, o.id AS o_id, x.id AS x_id FROM review_task t '
            . 'LEFT JOIN staff_user o ON o.id = t.opened_by LEFT JOIN staff_user x ON x.id = t.decided_by '
            . 'WHERE (t.opened_by IS NOT NULL AND o.id IS NULL) OR (t.decided_by IS NOT NULL AND x.id IS NULL) ORDER BY t.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "review task {$r['id']} names staff " . ($r['o_id'] === null && $r['opened_by'] !== null ? $r['opened_by'] : $r['decided_by']) . ', who does not exist';
        }
        return $v;
    }

    /** @return list<string> D6 */
    private static function lines(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT l.document_id, l.line_no, l.sku_id FROM document_line l LEFT JOIN sku s ON s.id = l.sku_id '
            . 'WHERE l.sku_id IS NOT NULL AND s.id IS NULL ORDER BY l.document_id, l.line_no LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "document {$r['document_id']} line {$r['line_no']} names item {$r['sku_id']}, which does not exist";
        }
        return $v;
    }

    /** @return list<string> D7 */
    private static function hashes(Db $db): array
    {
        $v = [];
        $fields = ['number', 'posted_hash', 'posted_by', 'posted_actor', 'posted_at'];
        foreach ($db->all(
            'SELECT d.id, d.number, d.status, p.document_id AS p_id, '
            . implode(', ', array_map(static fn (string $f): string => "d.{$f} <=> p.{$f} AS same_{$f}", $fields)) . ' '
            . 'FROM document d LEFT JOIN document_posting p ON p.document_id = d.id '
            . "WHERE (d.status IN ('posted', 'reversed') AND (p.document_id IS NULL OR NOT ("
            . implode(' AND ', array_map(static fn (string $f): string => "d.{$f} <=> p.{$f}", $fields)) . '))) '
            . "OR (d.status NOT IN ('posted', 'reversed') AND p.document_id IS NOT NULL) ORDER BY d.id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $label = "document {$r['id']} (" . ($r['number'] ?? $r['status']) . ')';
            if ($r['p_id'] === null) {
                $v[] = "{$label} is {$r['status']} but has no posting record (document_posting)";
            } elseif (!in_array($r['status'], ['posted', 'reversed'], true)) {
                $v[] = "{$label} is {$r['status']} but has a posting record";
            } else {
                $diff = array_values(array_filter($fields, static fn (string $f): bool => (int) $r["same_{$f}"] !== 1));
                $v[] = "{$label}: " . implode(', ', $diff) . ' changed after posting (they differ from its write-once posting record)';
            }
        }
        foreach ($db->all('SELECT document_id, number FROM document_posting WHERE SHA2(content, 256) <> posted_hash ORDER BY document_id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "posting record of document {$r['document_id']} ({$r['number']}): its content does not hash to its posted_hash";
        }
        $after = 0;
        while (count($v) < self::MAX_PER_CHECK) {
            $docs = $db->all(
                'SELECT d.*, p.posted_hash AS record_hash FROM document d JOIN document_posting p ON p.document_id = d.id '
                . 'WHERE d.id > ? ORDER BY d.id LIMIT ' . self::HASH_BATCH,
                [$after],
            );
            if ($docs === []) {
                break;
            }
            $ids = array_map(static fn (array $d): int => (int) $d['id'], $docs);
            $lines = [];
            foreach ($db->all(
                'SELECT document_id, line_no, sku_id, warehouse_id, qty, unit_cost, amount, reason_code, description FROM document_line '
                . 'WHERE document_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY document_id, line_no',
                $ids,
            ) as $l) {
                $lines[(int) $l['document_id']][] = $l;
            }
            foreach ($docs as $d) {
                $doc = Document::fromRow($d);
                if (!hash_equals((string) $d['record_hash'], Documents::fingerprint($doc, $lines[$doc->id] ?? []))) {
                    $v[] = "document {$doc->id} ({$doc->number}): its header or lines changed after posting (they no longer hash to its posting record)";
                    if (count($v) >= self::MAX_PER_CHECK) {
                        break;
                    }
                }
            }
            $after = (int) end($ids);
        }
        return $v;
    }
}
