<?php

declare(strict_types=1);

namespace CW\Counts;

use CW\Db;

/**
 * The nightly checks of stock counting (pack A2a; docs/decisions.md CT16), called by CW\Invariants::check, so bin/invariants.php,
 * the Safety checks page and every stock test run them. Read only; at most MAX_PER_CHECK problems per check. Every read starts from
 * the CNT records or the count tables and reaches the ledger only by its document index (CT13: measured in runs/a2_explain.txt).
 *
 *  C1. every final stock count record (CNT, posted or reversed) has its count header, of the right kind: a cancellation
 *      (document.reverses_id) is of the kind `reversal` and nothing else is.
 *  C2. a final count SHEET's document lines are exactly its counted lines, in line order (line k of the record is the k-th counted
 *      line: the item and the quantity counted), and every counted line says what the posting did with it (booked or left out);
 *      a line not counted says nothing, and a sheet that is not final has no document lines and no outcome on any line.
 *  C3. what a final sheet booked is what its lines say: a line booked as `counted` has exactly one `count` movement of its item on
 *      on hand from that record, of the difference the line names; a line left out (`stale`) has none; the record books nothing
 *      else.
 *  C4. a difference waits (review open) only on a sheet that is final and not cancelled; a rejected difference names the record that
 *      put its count back, and that record is final.
 *  C5. a record that puts ONE line back (kind line_reversal, final) is named by exactly that line, has one line (the item and minus
 *      what the count changed), and booked exactly that as one adjustment of on hand; a sheet with a line put back that way was never
 *      cancelled as a whole as well.
 */
final class CountInvariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::headers($db), ...self::lines($db), ...self::booked($db), ...self::reviews($db), ...self::putBack($db)];
    }

    /** @return list<string> C1 */
    private static function headers(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT d.id, d.number, d.reverses_id, CAST(cs.kind AS CHAR) AS kind FROM document d LEFT JOIN count_sheet cs ON cs.document_id = d.id "
            . "WHERE d.doc_type = 'CNT' AND d.status IN ('posted', 'reversed') AND (cs.document_id IS NULL OR (cs.kind = 'reversal') <> (d.reverses_id IS NOT NULL)) "
            . 'LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "stock count {$r['number']}: " . ($r['kind'] === null ? 'it has no count header' : "its count header is of the kind {$r['kind']} but it "
                . ($r['reverses_id'] === null ? 'cancels nothing' : 'is a cancellation'));
        }
        return $v;
    }

    /** @return list<string> C2 */
    private static function lines(Db $db): array
    {
        $v = [];
        // A final sheet: each counted line has its document line, in order (k counts the counted lines of the sheet so far).
        $k = [];
        foreach ($db->all("SELECT l.document_id, d.number, l.line_no, l.sku_id, l.counted_qty, l.doc_line_no, CAST(l.outcome AS CHAR) AS outcome, dl.sku_id AS dl_sku, dl.qty AS dl_qty "
            . "FROM count_sheet cs JOIN document d ON d.id = cs.document_id JOIN count_sheet_line l ON l.document_id = cs.document_id "
            . 'LEFT JOIN document_line dl ON dl.document_id = l.document_id AND dl.line_no = l.doc_line_no '
            . "WHERE cs.kind = 'sheet' AND d.status IN ('posted', 'reversed') AND (l.counted_qty IS NOT NULL OR l.outcome IS NOT NULL) "
            . 'ORDER BY l.document_id, l.line_no') as $r) {
            if (count($v) >= self::MAX_PER_CHECK) {
                return $v;
            }
            $doc = (int) $r['document_id'];
            $k[$doc] = ($k[$doc] ?? 0) + ($r['counted_qty'] === null ? 0 : 1);
            $where = "count sheet {$r['number']} line {$r['line_no']}";
            if ($r['counted_qty'] === null) {
                $v[] = "{$where}: it was not counted but says the posting {$r['outcome']} it";
            } elseif ($r['outcome'] === null || $r['doc_line_no'] === null) {
                $v[] = "{$where}: it was counted but says nothing of what the posting did with it";
            } elseif ((int) $r['doc_line_no'] !== $k[$doc] || $r['dl_sku'] === null || (int) $r['dl_sku'] !== (int) $r['sku_id']
                || (int) $r['dl_qty'] !== (int) $r['counted_qty']) {
                $v[] = "{$where}: its record line {$r['doc_line_no']} is not the item and the quantity counted (expected record line {$k[$doc]})";
            }
        }
        foreach ($db->all("SELECT d.number, COUNT(dl.line_no) AS n, (SELECT COUNT(*) FROM count_sheet_line x WHERE x.document_id = d.id AND x.counted_qty IS NOT NULL) AS counted "
            . "FROM count_sheet cs JOIN document d ON d.id = cs.document_id LEFT JOIN document_line dl ON dl.document_id = d.id "
            . "WHERE cs.kind = 'sheet' AND d.status IN ('posted', 'reversed') GROUP BY d.id, d.number HAVING n <> counted LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "count sheet {$r['number']}: {$r['n']} record lines for {$r['counted']} counted lines";
        }
        // A sheet that is not final: nothing of a posting on it.
        foreach ($db->all("SELECT d.id, d.status, (SELECT COUNT(*) FROM document_line dl WHERE dl.document_id = d.id) AS dl_n, "
            . '(SELECT COUNT(*) FROM count_sheet_line x WHERE x.document_id = d.id AND (x.outcome IS NOT NULL OR x.doc_line_no IS NOT NULL)) AS done_n '
            . "FROM count_sheet cs JOIN document d ON d.id = cs.document_id WHERE cs.kind = 'sheet' AND d.status NOT IN ('posted', 'reversed') "
            . 'HAVING dl_n > 0 OR done_n > 0 LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "count sheet {$r['id']} ({$r['status']}): {$r['dl_n']} record lines and {$r['done_n']} lines with an outcome, but it was never made final";
        }
        return $v;
    }

    /**
     * C3, record by record: the ledger rows of ONE final sheet (by the ledger's document index, named: on staging most ledger rows have
     * no record, and MySQL 8.4 then chose to read the whole ledger for every line: CT13) against its lines. Linear in the sheets' lines.
     *
     * @return list<string>
     */
    private static function booked(Db $db): array
    {
        $v = [];
        foreach ($db->column("SELECT cs.document_id FROM count_sheet cs JOIN document d ON d.id = cs.document_id WHERE cs.kind = 'sheet' AND d.status IN ('posted', 'reversed') "
            . 'ORDER BY cs.document_id') as $id) {
            $id = (int) $id;
            $lines = [];
            $number = '';
            foreach ($db->all('SELECT d.number, l.line_no, l.sku_id, CAST(l.outcome AS CHAR) AS outcome, l.booked_delta FROM count_sheet_line l JOIN document d ON d.id = l.document_id '
                . 'WHERE l.document_id = ? AND l.outcome IS NOT NULL', [$id]) as $l) {
                $number = (string) $l['number'];
                $lines[(int) $l['sku_id']] = $l + ['n' => 0, 'q' => 0];
            }
            foreach ($db->all('SELECT sku_id, movement_type, CAST(bucket AS CHAR) AS bucket, qty_delta FROM stock_ledger FORCE INDEX (ix_stock_ledger_document) WHERE document_id = ?',
                [$id]) as $g) {
                $sku = (int) $g['sku_id'];
                if (isset($lines[$sku]) && $lines[$sku]['outcome'] === 'counted' && $g['movement_type'] === 'count' && $g['bucket'] === 'on_hand') {
                    $lines[$sku]['n']++;
                    $lines[$sku]['q'] += (int) $g['qty_delta'];
                } elseif (isset($lines[$sku]) && $g['movement_type'] === 'count' && $g['bucket'] === 'on_hand') {
                    $lines[$sku]['n']++;
                } else {
                    $number = $number !== '' ? $number : (string) $db->value('SELECT number FROM document WHERE id = ?', [$id]);
                    $v[] = "count sheet {$number} booked a {$g['movement_type']} of {$g['bucket']} for item {$sku}, which is no line it counted";
                }
            }
            foreach ($lines as $l) {
                $bad = $l['outcome'] === 'counted' ? $l['n'] !== 1 || $l['q'] !== (int) $l['booked_delta'] : $l['n'] !== 0;
                if ($bad) {
                    $v[] = "count sheet {$l['number']} line {$l['line_no']} ({$l['outcome']}" . ($l['booked_delta'] === null ? '' : ", {$l['booked_delta']}")
                        . "): the record booked {$l['n']} count movements of its item, {$l['q']} in all";
                }
            }
            if (count($v) >= self::MAX_PER_CHECK) {
                return array_slice($v, 0, self::MAX_PER_CHECK);
            }
        }
        return $v;
    }

    /** @return list<string> C4 */
    private static function reviews(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT d.number, d.status, l.line_no, CAST(l.review AS CHAR) AS review, l.reversal_document_id, rd.status AS r_status, rd.doc_type AS r_type "
            . 'FROM count_sheet_line l FORCE INDEX (ix_count_sheet_line_review) JOIN document d ON d.id = l.document_id '
            . 'LEFT JOIN document rd ON rd.id = l.reversal_document_id '
            . "WHERE l.review IN ('open', 'rejected') AND ((l.review = 'open' AND d.status <> 'posted') "
            . "OR (l.review = 'rejected' AND (rd.id IS NULL OR rd.doc_type <> 'CNT' OR rd.status <> 'posted'))) LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "count sheet {$r['number']} line {$r['line_no']}: " . ($r['review'] === 'open' ? "a difference waits on a sheet that is {$r['status']}"
                : 'a rejected difference names ' . ($r['r_status'] === null ? 'no record' : "a {$r['r_status']} {$r['r_type']} record") . ' as the one that put it back');
        }
        return $v;
    }

    /** @return list<string> C5 */
    private static function putBack(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT d.id, d.number, d.warehouse_id, cs.reverses_document_id, cs.reverses_line_no, l.sku_id, l.booked_delta, l.reversal_document_id, "
            . 's.warehouse_id AS s_wh, s.status AS s_status, '
            . '(SELECT COUNT(*) FROM count_sheet_line x FORCE INDEX (ix_count_sheet_line_reversal) WHERE x.reversal_document_id = d.id) AS named, '
            . '(SELECT COUNT(*) FROM document_line dl WHERE dl.document_id = d.id) AS dl_n, '
            . '(SELECT COUNT(*) FROM document_line dl WHERE dl.document_id = d.id AND dl.sku_id = l.sku_id AND dl.qty = -l.booked_delta) AS dl_ok, '
            . '(SELECT COUNT(*) FROM stock_ledger g FORCE INDEX (ix_stock_ledger_document) WHERE g.document_id = d.id) AS g_n, '
            . "(SELECT COUNT(*) FROM stock_ledger g FORCE INDEX (ix_stock_ledger_document) WHERE g.document_id = d.id AND g.sku_id = l.sku_id AND g.bucket = 'on_hand' AND g.movement_type = 'adjustment' "
            . 'AND g.warehouse_id = d.warehouse_id AND g.qty_delta = -l.booked_delta) AS g_ok '
            . "FROM count_sheet cs JOIN document d ON d.id = cs.document_id LEFT JOIN count_sheet_line l ON l.document_id = cs.reverses_document_id AND l.line_no = cs.reverses_line_no "
            . "LEFT JOIN document s ON s.id = cs.reverses_document_id WHERE cs.kind = 'line_reversal' AND d.status = 'posted' "
            . 'HAVING sku_id IS NULL OR named <> 1 OR NOT (reversal_document_id <=> id) OR NOT (s_status <=> \'posted\') OR NOT (s_wh <=> warehouse_id) '
            . 'OR dl_n <> 1 OR dl_ok <> 1 OR g_n <> 1 OR g_ok <> 1 LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $what = "the record {$r['number']} that puts back line {$r['reverses_line_no']} of count sheet {$r['reverses_document_id']}";
            if ($r['sku_id'] === null) {
                $v[] = "{$what}: there is no such line";
                continue;
            }
            if ((int) $r['named'] !== 1 || (int) ($r['reversal_document_id'] ?? 0) !== (int) $r['id']) {
                $v[] = "{$what}: " . (int) $r['named'] . ' sheet lines name it, expected exactly that one';
            }
            if ($r['s_status'] !== 'posted' || (int) $r['s_wh'] !== (int) $r['warehouse_id']) {
                $v[] = "{$what}: the sheet is {$r['s_status']} or in another warehouse (a sheet with a line put back is never cancelled as a whole)";
            }
            if ((int) $r['dl_n'] !== 1 || (int) $r['dl_ok'] !== 1 || (int) $r['g_n'] !== 1 || (int) $r['g_ok'] !== 1) {
                $v[] = "{$what}: it holds {$r['dl_n']} lines and booked {$r['g_n']} movements, expected one adjustment of " . (-(int) $r['booked_delta']) . " of item {$r['sku_id']}";
            }
        }
        return $v;
    }
}
