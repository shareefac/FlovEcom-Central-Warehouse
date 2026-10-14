<?php

declare(strict_types=1);

namespace CW\Counts;

use CW\Clock;
use CW\Db;
use CW\Movements;
use CW\Settings;
use CW\Stock;
use DateTimeImmutable;

/**
 * What posting a count sheet would do, worked out from what the database holds now (pack A2a; docs/decisions.md CT3, CT5, CT6). Reads
 * only. The same answer serves three callers, so they cannot disagree:
 *
 *   the page "Before you post" (CountSheets::differences): every counted line with its difference, what moved since it was counted,
 *   which lines a later count leaves out, which differences are above the tolerance;
 *   the handler's validate(): a line whose stock moved after it was counted, and that the person has not chosen to post as counted,
 *   refuses the posting (decision 6 of the module plan: warn first);
 *   the handler's post(): the movements it hands to the engine, and what it expects the engine to answer for each line. After the
 *   booking it compares: a difference means stock moved in the instant between this read and the engine's locks, and the posting is
 *   refused whole.
 *
 * Per counted line it mirrors the engine's own rule (Movements::count, read-only here):
 *   stale    the item's balance was counted LATER than the time the line is booked under: the engine leaves the line out
 *   delta    counted + (dispatches and their resets already booked after that time) - on hand now
 * and adds what a person needs to decide:
 *   moved    the on-hand movements booked after the line's own count time (dispatches: the engine takes them off the count; anything
 *            else: the count overwrites it, and the engine opens its own review)
 *   over     the limits the difference is above (CountTolerance), against what the system holds now.
 *
 * The times the lines are booked under are CountTimes' groups: a line shares an earlier line's time only when nothing happened to
 * its stock in between, and at most the setting counts.time_window_max_minutes before its own time (review I-1, 14 Oct 2026: never
 * wider). When the lines need more times than a posting has room for, the plan says so (too_many_times) and marks the lines that
 * started the earliest times (`retime`): counted again close together, they share one time and the sheet fits (review M-2).
 */
final class CountPosting
{
    /** The movements shown for a line that moved (the newest ones). */
    public const MOVED_ROWS = 5;
    /** The counted lines one statement reads the ledger for. */
    public const LINES_A_READ = 100;
    /** The limit of the count times' window when the setting is missing (minutes: the engine's "near a count" time). */
    public const WINDOW_MINUTES = 10;

    /**
     * @return array{warehouse_id: int, warehouse: string, window: int, limit_minutes: int, too_many_times: bool, units_limit: int, percent_limit: int,
     *               groups: list<array{at: string, backdated: bool, lines: list<int>}>, lines: list<array<string, mixed>>}
     *         window: the seconds the times were found with (the limit itself when there are too many); limit_minutes: the setting.
     *         lines in line order: line_no, sku_id, code, name, counted, counted_at, counted_by, expected, held, delta (null: stale),
     *         stale, last_counted_at, ships_after, moved {n, ships, others, max_id, rows}, acked, needs_choice, too_old, over, group,
     *         retime (too many times: this line started one of the earliest times, count it again)
     */
    public static function plan(Db $db, int $documentId, DateTimeImmutable $now): array
    {
        $doc = $db->one('SELECT d.warehouse_id, w.code FROM document d JOIN warehouse w ON w.id = d.warehouse_id WHERE d.id = ?', [$documentId])
            ?? throw new \LogicException("count sheet {$documentId} has no warehouse");
        $wh = (int) $doc['warehouse_id'];
        $units = max(0, Settings::number($db, 'counts.review_over_units', 5));
        $percent = max(0, Settings::number($db, 'counts.review_over_percent', 10));
        $minutes = max(0, Settings::number($db, 'counts.time_window_max_minutes', self::WINDOW_MINUTES));
        $limit = $minutes * 60;
        $out = ['warehouse_id' => $wh, 'warehouse' => (string) $doc['code'], 'window' => min(Stock::NEAR_COUNT_SEC, $limit), 'limit_minutes' => $minutes,
            'too_many_times' => false, 'units_limit' => $units, 'percent_limit' => $percent, 'groups' => [], 'lines' => []];
        // The counted lines with the balance as it is.
        $rows = $db->all(
            'SELECT l.line_no, l.sku_id, l.counted_qty, l.counted_at, l.counted_by, l.expected_qty, l.ack_ledger_id, s.code, s.name, b.on_hand, '
            . 'b.counted_at AS balance_counted_at FROM count_sheet_line l JOIN sku s ON s.id = l.sku_id LEFT JOIN stock_balance b ON b.warehouse_id = ? AND b.sku_id = l.sku_id '
            . 'WHERE l.document_id = ? AND l.counted_qty IS NOT NULL ORDER BY l.line_no',
            [$wh, $documentId],
        );
        if ($rows === []) {
            return $out;
        }
        // Per line, with its own count time as a value (so each read is a range of the ledger's (warehouse, item, bucket, time) index,
        // never every row of a busy item: CT13): the last on-hand movement at or before that time, and what was booked on hand after it.
        // One statement for up to LINES_A_READ lines.
        $moved = [];
        $lastMove = [];
        foreach (array_chunk($rows, self::LINES_A_READ) as $chunk) {
            $parts = [];
            $params = [];
            foreach ($chunk as $r) {
                $parts[] = 'SELECT a.sku_id, a.last_move, m.ships, m.others, m.n, m.max_id FROM (SELECT ? AS sku_id, (SELECT MAX(x.effective_at) FROM stock_ledger x '
                    . "FORCE INDEX (ix_stock_ledger_effective) WHERE x.warehouse_id = ? AND x.sku_id = ? AND x.bucket = 'on_hand' AND x.effective_at <= ?) AS last_move) a "
                    . "CROSS JOIN (SELECT COALESCE(SUM(IF(g.movement_type IN ('ship', 'unship'), g.qty_delta, 0)), 0) AS ships, "
                    . "COALESCE(SUM(IF(g.movement_type NOT IN ('ship', 'unship', 'count'), g.qty_delta, 0)), 0) AS others, COUNT(*) AS n, COALESCE(MAX(g.id), 0) AS max_id "
                    . "FROM stock_ledger g FORCE INDEX (ix_stock_ledger_effective) WHERE g.warehouse_id = ? AND g.sku_id = ? AND g.bucket = 'on_hand' AND g.effective_at > ?) m";
                array_push($params, (int) $r['sku_id'], $wh, (int) $r['sku_id'], (string) $r['counted_at'], $wh, (int) $r['sku_id'], (string) $r['counted_at']);
            }
            foreach ($db->all(implode(' UNION ALL ', $parts), $params) as $m) {
                $sku = (int) $m['sku_id'];
                $lastMove[$sku] = $m['last_move'] === null ? null : (string) $m['last_move'];
                $moved[$sku] = ['n' => (int) $m['n'], 'ships' => (int) $m['ships'], 'others' => (int) $m['others'], 'max_id' => (int) $m['max_id'], 'rows' => []];
            }
        }
        $oldest = Clock::db($now->modify('-' . Movements::BACKDATED_MAX_AGE_SEC . ' seconds'));
        $dayAgo = Clock::db($now->modify('-' . Movements::COUNT_MAX_AGE_SEC . ' seconds'));
        $times = [];
        $lines = [];
        foreach ($rows as $r) {
            $no = (int) $r['line_no'];
            $at = (string) $r['counted_at'];
            $last = $r['balance_counted_at'] === null ? null : (string) $r['balance_counted_at'];
            // A later count of the item exists whatever time the line is booked under (that time is never after its own).
            $stale = $last !== null && $last > $at;
            $since = $lastMove[(int) $r['sku_id']] ?? null;
            if (!$stale && $last !== null && ($since === null || $last > $since)) {
                $since = $last;
            }
            $times[] = ['line' => $no, 'at' => Clock::fromDb($at), 'since' => $since === null ? null : Clock::fromDb($since)];
            $m = $moved[(int) $r['sku_id']] ?? ['n' => 0, 'ships' => 0, 'others' => 0, 'max_id' => 0, 'rows' => []];
            $held = $r['on_hand'] === null ? 0 : (int) $r['on_hand'];
            $counted = (int) $r['counted_qty'];
            $delta = $stale ? null : $counted + $m['ships'] - $held;
            $acked = $r['ack_ledger_id'] !== null && (int) $r['ack_ledger_id'] >= $m['max_id'];
            $lines[$no] = [
                'line_no' => $no, 'sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'counted' => $counted,
                'counted_at' => $at, 'counted_by' => $r['counted_by'] === null ? null : (int) $r['counted_by'], 'expected' => (int) $r['expected_qty'], 'held' => $held,
                'delta' => $delta, 'stale' => $stale, 'last_counted_at' => $last, 'ships_after' => $m['ships'], 'moved' => $m,
                'acked' => !$stale && $m['n'] > 0 && $acked, 'needs_choice' => !$stale && $m['n'] > 0 && !$acked, 'too_old' => $at < $oldest,
                'over' => $delta === null ? [] : CountTolerance::over($held, $delta, $units, $percent), 'group' => 0, 'retime' => false,
            ];
        }
        $grouped = CountTimes::groups($times, Stock::NEAR_COUNT_SEC, Movements::MAX_DOCUMENT_MOVEMENTS, $limit);
        if ($grouped === null) {
            // Never wider than the limit: refused (validate), and the lines of the earliest times are marked. Counted again close together
            // they share one new time, which leaves room: the latest MAX - 1 times stay as they are.
            $out['too_many_times'] = true;
            $out['window'] = $limit;
            $within = CountTimes::groupsWithin($times, $limit);
            foreach (array_slice($within, 0, count($within) - (Movements::MAX_DOCUMENT_MOVEMENTS - 1)) as $g) {
                foreach ($g['lines'] as $no) {
                    $lines[$no]['retime'] = true;
                }
            }
        } else {
            $out['window'] = $grouped['window'];
            foreach ($grouped['groups'] as $i => $g) {
                $at = Clock::db($g['at']);
                $out['groups'][] = ['at' => $at, 'backdated' => $at < $dayAgo, 'lines' => $g['lines']];
                foreach ($g['lines'] as $no) {
                    $lines[$no]['group'] = $i;
                    // The engine judges the time the line is booked under (never after its own, at most the window before it).
                    $lines[$no]['too_old'] = $at < $oldest;
                }
            }
        }
        // The newest movements of the lines that moved, for the page (a handful of lines, a handful of rows each).
        foreach ($lines as $no => $l) {
            if ($l['moved']['n'] > 0) {
                $lines[$no]['moved']['rows'] = array_map(static fn (array $g): array => ['id' => (int) $g['id'], 'movement_type' => (string) $g['movement_type'],
                    'qty_delta' => (int) $g['qty_delta'], 'at' => (string) $g['effective_at'], 'ref' => $g['order_ref'] ?? $g['doc_ref']],
                    array_reverse($db->all("SELECT id, movement_type, qty_delta, effective_at, order_ref, doc_ref FROM stock_ledger WHERE warehouse_id = ? AND sku_id = ? "
                        . "AND bucket = 'on_hand' AND effective_at > ? ORDER BY effective_at DESC, id DESC LIMIT " . self::MOVED_ROWS, [$wh, $l['sku_id'], $l['counted_at']])));
            }
        }
        $out['lines'] = array_values($lines);
        return $out;
    }
}
