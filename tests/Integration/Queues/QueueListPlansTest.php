<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Queues;

use CW\Tests\Support\IntegrationTestCase;

/**
 * The reads of pack F on a filled schema (docs/decisions.md PF13): every queue list, its badge count, the notes, the alert's latest
 * heartbeat and feed-lag reads, the correction's ledger reads and the clean-up's chunked deletes keep to an index (no full scan, no
 * file sort on a list of open rows). The plans are written to the test's output for the record.
 */
final class QueueListPlansTest extends IntegrationTestCase
{
    public function testTheQueueReadsKeepToTheirIndexes(): void
    {
        [$ch, $main, $sku] = $this->fill();
        foreach (['goods_in_suspense', 'count_review', 'policy_review', 'oversell_event', 'queue_note', 'channel_health', 'stock_change', 'stock_ledger',
            'idempotency', 'audit_log', 'relay_line'] as $t) {
            self::$db->all("ANALYZE TABLE {$t}");
        }
        $plans = [
            // [the read, its params, the index it must use]
            'suspense list' => ['SELECT g.id FROM goods_in_suspense g LEFT JOIN sku s ON s.id = g.resolved_sku_id WHERE g.status = ? ORDER BY g.created_at, g.id LIMIT 51',
                ['open'], 'ix_goods_in_suspense_status'],
            'suspense count' => ["SELECT COUNT(*) FROM goods_in_suspense WHERE status = 'open'", [], 'ix_goods_in_suspense_status'],
            'corrections list' => ['SELECT STRAIGHT_JOIN r.id FROM count_review r FORCE INDEX (ix_count_review_open) JOIN sku s ON s.id = r.sku_id JOIN warehouse w ON w.id = r.warehouse_id '
                . 'WHERE r.source = ? AND r.status = ? ORDER BY r.id LIMIT 51', ['erp_reconciliation', 'open'], 'ix_count_review_open'],
            'corrections count' => ["SELECT COUNT(*) FROM count_review FORCE INDEX (ix_count_review_open) WHERE status = 'open' AND source = ?", ['erp_reconciliation'],
                'ix_count_review_open'],
            'modes list' => ['SELECT p.id FROM policy_review p LEFT JOIN sku s ON s.id = p.sku_id LEFT JOIN channel_listing l ON l.id = p.listing_id '
                . 'WHERE p.source = ? AND p.status = ? ORDER BY p.created_at, p.id LIMIT 51', ['erp_update_product', 'open'], 'ix_policy_review_status'],
            'modes count' => ["SELECT COUNT(*) FROM policy_review WHERE status = 'open' AND source = ?", ['erp_update_product'], 'ix_policy_review_status'],
            'oversold list' => ['SELECT STRAIGHT_JOIN o.id FROM oversell_event o JOIN sku s ON s.id = o.sku_id JOIN warehouse w ON w.id = o.warehouse_id WHERE o.status = ? '
                . 'ORDER BY o.created_at, o.id LIMIT 51', ['open'], 'ix_oversell_status'],
            'oversold count' => ["SELECT COUNT(*) FROM oversell_event WHERE status = 'open'", [], 'ix_oversell_status'],
            'notes' => ['SELECT id FROM queue_note WHERE queue_name = ? AND row_id IN (1, 2, 3) ORDER BY row_id, id', ['oversell_event'], 'ix_queue_note_row'],
            'feed lag' => ['SELECT sc.created_at FROM stock_change sc WHERE sc.seq > ? ORDER BY sc.seq LIMIT 1', [100], 'PRIMARY'],
            'latest heartbeat' => ['SELECT MAX(h2.id) FROM channel_health h2 WHERE h2.channel_id = ?', [$ch], 'ix_channel_health_latest'],
            'ledger at a moment' => ['SELECT id FROM stock_ledger WHERE created_at <= ? ORDER BY created_at DESC, id DESC LIMIT 1', ['2026-10-01 00:00:00'], 'ix_stock_ledger_created'],
            'bucket at a moment' => ['SELECT balance_after FROM stock_ledger FORCE INDEX (ix_stock_ledger_balance) WHERE warehouse_id = ? AND sku_id = ? AND id <= ? '
                . 'AND bucket = ? ORDER BY id DESC LIMIT 1', [$main, $sku, PHP_INT_MAX, 'on_hand'], 'ix_stock_ledger_balance'],
            'prune idempotency' => ['DELETE FROM idempotency WHERE created_at < ? ORDER BY created_at LIMIT 1000', ['2026-09-01 00:00:00'], 'ix_idempotency_created'],
            // A time-ordered index serves it: (channel_id, id), received_at, or the primary key's range below the store's newest row (ids
            // grow with time, so the oldest rows come first and the chunk stops at its LIMIT); a single-table DELETE takes no index hint.
            'prune heartbeats' => ['DELETE FROM channel_health WHERE channel_id = ? AND id < ? AND received_at < ? ORDER BY id LIMIT 1000',
                [$ch, PHP_INT_MAX, '2026-09-02 00:00:00'], ['ix_channel_health_latest', 'ix_channel_health_received', 'PRIMARY']],
            'prune audit' => ['DELETE FROM audit_log WHERE created_at < ? ORDER BY created_at LIMIT 1000', ['2019-01-01 00:00:00'], 'ix_audit_created'],
            'relay line' => ['SELECT id FROM relay_line WHERE channel_id = ? AND kind = ? AND doc_ref = ? AND line_index = ?', [$ch, 'goods_in', 'PINV-5', 0], 'uq_relay_line'],
        ];
        $out = [];
        foreach ($plans as $name => [$sql, $params, $index]) {
            $rows = self::$db->all('EXPLAIN ' . $sql, $params);
            // The queue's own table (the first one the read names), wherever the optimizer put it in the join.
            preg_match('/\b(?:FROM|UPDATE)\s+([a-z_]+)(?:\s+(?:FORCE INDEX \([a-z_]+\)\s+)?([a-z][a-z0-9]?)\b)?/i', $sql, $m);
            $alias = isset($m[2]) && !in_array(strtoupper($m[2]), ['WHERE', 'ORDER', 'JOIN', 'LEFT'], true) ? $m[2] : $m[1];
            $own = array_values(array_filter($rows, static fn (array $r): bool => in_array($r['table'] ?? null, [$alias, $m[1]], true)))[0] ?? $rows[0];
            $extra = implode(' | ', array_map(static fn (array $r): string => (string) ($r['Extra'] ?? ''), $rows));
            $out[] = sprintf('%-20s table=%s type=%s key=%s rows=%s extra=%s', $name, $own['table'] ?? '', $own['type'] ?? '', $own['key'] ?? '', $own['rows'] ?? '', $extra);
            if (str_contains($extra, 'Select tables optimized away')) {
                continue; // answered from the index itself (MAX of an index prefix)
            }
            self::assertContains($own['key'], (array) $index, "{$name}: " . json_encode($rows));
            self::assertNotSame('ALL', $own['type'], "{$name}: a full scan");
            if (str_contains($name, 'list')) {
                self::assertStringNotContainsString('filesort', $extra, "{$name}: a file sort of the open rows: " . json_encode($rows));
            }
        }
        fwrite(STDERR, "\n[pack F plans]\n" . implode("\n", $out) . "\n");
    }

    /**
     * About 3,000 rows per queue, few of them open, as on a busy staging day; the notes, heartbeats, feed, ledger, keys and audit around them.
     *
     * @return array{0: int, 1: int, 2: int} the store, MAIN, an item
     */
    private function fill(): array
    {
        $db = self::$db;
        $ch = self::makeChannel('vpg', 'live');
        $main = self::warehouseId('MAIN');
        $skus = [];
        for ($i = 0; $i < 40; $i++) {
            $skus[] = self::makeSku('Plan item ' . $i);
        }
        $db->exec('INSERT INTO stock_balance (warehouse_id, sku_id) VALUES ' . implode(', ', array_map(static fn (int $s): string => "({$main}, {$s})", $skus)));
        $chunks = static function (int $n, \Closure $row): \Generator {
            $buf = [];
            for ($i = 0; $i < $n; $i++) {
                $buf[] = $row($i);
                if (count($buf) === 500) {
                    yield implode(', ', $buf);
                    $buf = [];
                }
            }
            if ($buf !== []) {
                yield implode(', ', $buf);
            }
        };
        $status = static fn (int $i): string => $i % 60 === 0 ? 'open' : ($i % 2 === 0 ? 'resolved' : 'dismissed');
        $at = static fn (int $i): string => sprintf("TIMESTAMP('2026-09-01') + INTERVAL %d MINUTE", $i);
        $done = static fn (int $i): string => $status($i) === 'open' ? 'NULL' : 'NOW(6)';
        foreach ($chunks(3000, static fn (int $i): string => sprintf("('erp_relay', %d, 'purchase_invoice', 'PINV-%d', 0, 'goods_in', 2, 'V%d', 'unknown_listing', 'goods_in:PINV-%d#0', '%s', %s, %s)",
            $ch, $i, $i, $i, $status($i), $done($i), $at($i))) as $v) {
            $db->exec('INSERT INTO goods_in_suspense (source, channel_id, doc_type, doc_ref, line_index, movement_type, qty, external_variant_id, reason, dedupe_key, status, resolved_at, created_at) VALUES ' . $v);
        }
        $sources = ['erp_reconciliation', 'count_after_movements', 'negative_on_hand', 'count_near_ship'];
        foreach ($chunks(3000, static fn (int $i): string => sprintf("(%d, %d, '%s', 5, 'R-%d', 'cr-%d', '%s', %s, %s)", $main, $skus[$i % 40], $sources[$i % 4], $i, $i,
            $status($i), $done($i), $at($i))) as $v) {
            $db->exec('INSERT INTO count_review (warehouse_id, sku_id, source, proposed_qty, ref, dedupe_key, status, resolved_at, created_at) VALUES ' . $v);
        }
        foreach ($chunks(3000, static fn (int $i): string => sprintf("(%d, '%s', 'In-Stock', 'strict', 'backorder', 'pr-%d', '%s', %s, %s)", $skus[$i % 40],
            $i % 3 === 0 ? 'erp_update_product' : 'count_gate', $i, $status($i), $done($i), $at($i))) as $v) {
            $db->exec('INSERT INTO policy_review (sku_id, source, site_mode, current_policy, proposed_policy, dedupe_key, status, resolved_at, created_at) VALUES ' . $v);
        }
        foreach ($chunks(3000, static fn (int $i): string => sprintf("(%d, %d, %d, 'O-%d', 'commit_short', 1, -1, 'ov-%d', '%s', %s, %s)", $main, $skus[$i % 40], $ch, $i, $i,
            $status($i), $done($i), $at($i))) as $v) {
            $db->exec('INSERT INTO oversell_event (warehouse_id, sku_id, channel_id, order_ref, kind, shortfall, available_after, dedupe_key, status, resolved_at, created_at) VALUES ' . $v);
        }
        foreach ($chunks(1000, static fn (int $i): string => sprintf("('oversell_event', %d, NULL, 'staff:1', 'a note %d')", 1 + $i % 300, $i)) as $v) {
            $db->exec('INSERT INTO queue_note (queue_name, row_id, staff_user_id, actor, note) VALUES ' . $v);
        }
        foreach ($chunks(3000, static fn (int $i): string => sprintf("(%d, %s, 'live')", $ch, $at($i))) as $v) {
            $db->exec('INSERT INTO channel_health (channel_id, received_at, site_mode) VALUES ' . $v);
        }
        foreach ($chunks(3000, static fn (int $i): string => sprintf("('stock', %s)", $at($i))) as $v) {
            $db->exec('INSERT INTO stock_change (reason, created_at) VALUES ' . $v);
        }
        foreach ($chunks(6000, static fn (int $i): string => sprintf("(%d, %d, '%s', 1, %d, 'adjustment', 'system:test', %s)", $main, $skus[$i % 40],
            ['on_hand', 'allocated', 'held'][$i % 3], $i, $at($i))) as $v) {
            $db->exec('INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, actor, created_at) VALUES ' . $v);
        }
        foreach ($chunks(3000, static fn (int $i): string => sprintf("(%d, '', 'k-%d', 'POST', '/v1/heartbeat', REPEAT('0', 64), 200, JSON_OBJECT(), %s)", $ch, $i, $at($i))) as $v) {
            $db->exec('INSERT INTO idempotency (channel_id, source, idem_key, method, path, request_hash, response_status, response_body, created_at) VALUES ' . $v);
        }
        foreach ($chunks(3000, static fn (int $i): string => sprintf("('system:test', 'test.plan', %s)", $at($i))) as $v) {
            $db->exec('INSERT INTO audit_log (actor, action, created_at) VALUES ' . $v);
        }
        foreach ($chunks(3000, static fn (int $i): string => sprintf("(%d, 'goods_in', 'PINV-%d', 0, REPEAT('0', 64), 'booked', 'k-%d')", $ch, $i, $i)) as $v) {
            $db->exec('INSERT INTO relay_line (channel_id, kind, doc_ref, line_index, line_hash, outcome, idem_key) VALUES ' . $v);
        }
        return [$ch, $main, $skus[0]];
    }
}
