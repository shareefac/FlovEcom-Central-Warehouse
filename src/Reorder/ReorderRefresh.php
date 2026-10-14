<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Settings;

/**
 * The reorder list worked out ahead of the page (pack G; docs/decisions.md PG3, PG4; plan E11: "the monthly average-sales and
 * safety-stock job"): every item of the list (an item with demand, a minimum stock or a low-stock figure) gets its line
 * (ReorderList::lines(), CW's stock) stored in reorder_line (the compact columns the list filters and sorts by) and
 * reorder_line_detail (the whole line). The screens and the download read those, so a page view never works out 17,600 lines.
 *
 *  all()    every item, in chunks of reorder.refresh_chunk_items (sku id order); one at a time: GET_LOCK('cw_reorder_lines:<schema>',
 *           0), busy -> 409 refresh_running. Each chunk is read, then written in ONE short transaction, and only the lines that
 *           changed are written (a sha1 of the line). An item that left the list is unlisted (listed = 0), never deleted. The run is
 *           recorded in reorder_job 'lines' (the list's "Figures from ...").
 *  items()  some items at once (after "create draft orders", a settings save, a low-stock click): the same chunks, no named lock.
 *
 * A later read never loses to an earlier one: each row keeps the time its figures were last read (read_at, moved on even when the line
 * did not change), and figures read earlier than the stored ones change nothing (an all() that read a chunk before a draft was made
 * cannot undo items()' newer line; review finding 1). The full run's clean-up unlists only what it read the list's ids for, and only
 * items still off the list (review finding 2).
 * Reads stock_balance, the purchase orders and the reorder tables; writes only reorder_line, reorder_line_detail and reorder_job.
 */
final class ReorderRefresh
{
    public const LOCK_PREFIX = 'cw_reorder_lines:';
    public const JOB = 'lines';

    private readonly Settings $settings;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private readonly ReorderList $list;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, PurchaseOrders $pos, ?Settings $settings = null, ?\Closure $clock = null)
    {
        $this->settings = $settings ?? new Settings($db);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->list = new ReorderList($db, $pos, $this->settings, $this->clock);
    }

    /**
     * Every item of the list (class docblock).
     *
     * @return array{items: int, changed: int, unlisted: int, chunks: int, ms: int}
     */
    public function all(): array
    {
        $lock = self::LOCK_PREFIX . (string) $this->db->value('SELECT DATABASE()');
        if ((int) $this->db->value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            throw new CwException('refresh_running', 'the reorder list is being worked out right now: try again in a minute', 409);
        }
        try {
            return ReorderJobs::record($this->db, self::JOB, $this->clock, function (): array {
                $t0 = hrtime(true);
                $chunk = ReorderSettings::engine($this->settings)['chunk'];
                $baseAt = Clock::db(($this->clock)()); // the time the run read the list's ids: its clean-up never unlists a line read after it
                $base = $this->base();
                $changed = 0;
                $chunks = 0;
                foreach (array_chunk($base, $chunk) as $ids) {
                    $changed += $this->chunk($ids);
                    $chunks++;
                }
                $in = array_fill_keys($base, true);
                $gone = array_values(array_filter(array_map('intval', $this->db->column('SELECT sku_id FROM reorder_line WHERE listed = 1')),
                    static fn (int $id): bool => !isset($in[$id])));
                $unlisted = 0;
                foreach (array_chunk($gone, $chunk) as $ids) {
                    $unlisted += $this->unlist($ids, $baseAt);
                }
                if ($changed * 10 >= max(1, count($base))) {
                    // A tenth or more rewritten (the nightly run after the demand): fresh statistics for the pages' reads (PG12).
                    $this->db->all('ANALYZE TABLE reorder_line, reorder_line_detail');
                }
                return ['items' => count($base), 'changed' => $changed, 'unlisted' => $unlisted, 'chunks' => $chunks, 'ms' => intdiv(hrtime(true) - $t0, 1_000_000)];
            });
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * Some items at once (class docblock). Inside a caller's transaction it joins it (the lines read what that transaction wrote).
     *
     * @param list<int> $skus
     * @return array{items: int, changed: int}
     */
    public function items(array $skus): array
    {
        $ids = array_values(array_unique(array_map('intval', $skus)));
        sort($ids);
        $changed = 0;
        foreach (array_chunk($ids, ReorderSettings::engine($this->settings)['chunk']) as $chunk) {
            $changed += $this->chunk($chunk);
        }
        return ['items' => count($ids), 'changed' => $changed];
    }

    /** The items of the list now, sku id order: with demand, a minimum stock or a low-stock figure. @return list<int> */
    public function base(): array
    {
        $ids = array_map('intval', $this->db->column('SELECT sku_id FROM reorder_demand UNION SELECT sku_id FROM item_reorder WHERE min_stock IS NOT NULL '
            . 'OR low_stock_level IS NOT NULL'));
        sort($ids);
        return array_values(array_unique($ids));
    }

    /**
     * Works out and stores the lines of some items; an item no longer on the list is unlisted. Returns how many rows changed.
     *
     * The figures are read first (outside any transaction); then, in ONE short transaction, the items' stored rows are locked in item
     * order and each line is decided against what is stored (review finding 1, Codex 14 Oct 2026):
     *  - a stored row read LATER than these figures wins: this line is dropped;
     *  - a line equal to the stored one writes nothing but its read time (read_at moves on, changed_at stays), so a slower refresh that
     *    read earlier can never put its older line over it;
     *  - anything else is written, both tables together (a new row: the guarded upsert, so the later read wins there too);
     *  - an item no longer on the list is unlisted when its stored row was read no later than these figures.
     *
     * @param list<int> $ids
     */
    private function chunk(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $readAt = Clock::db(($this->clock)());
        $lines = [];
        foreach ($this->list->lines(ReorderList::filters(['show' => 'all', 'stock' => 'cw']), $ids) as $l) {
            $lines[(int) $l['sku_id']] = $l;
        }
        $encoded = [];
        foreach ($lines as $id => $l) {
            $json = json_encode($l, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $encoded[$id] = [$l, $json, sha1($json)];
        }
        return $this->db->transaction(function (Db $db) use ($ids, $encoded, $readAt): int {
            $have = [];
            foreach ($db->all('SELECT sku_id, line_hash, listed, read_at FROM reorder_line FORCE INDEX (PRIMARY) WHERE sku_id IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY sku_id FOR UPDATE', $ids) as $r) {
                $have[(int) $r['sku_id']] = ['hash' => (string) $r['line_hash'], 'listed' => (int) $r['listed'] === 1, 'read_at' => (string) $r['read_at']];
            }
            $write = [];
            $seen = [];
            foreach ($encoded as $id => $e) {
                $h = $have[$id] ?? null;
                if ($h !== null && $h['read_at'] > $readAt) {
                    continue; // read later by another refresh: its line stands
                }
                if ($h !== null && $h['listed'] && $h['hash'] === $e[2]) {
                    $seen[] = $id;
                    continue;
                }
                $write[$id] = $e;
            }
            $gone = array_values(array_filter($ids, static fn (int $id): bool => !isset($encoded[$id]) && ($have[$id]['listed'] ?? false)
                && $have[$id]['read_at'] <= $readAt));
            if ($seen !== []) {
                $db->exec('UPDATE reorder_line FORCE INDEX (PRIMARY) SET read_at = ? WHERE read_at < ? AND sku_id IN (' . implode(', ', array_fill(0, count($seen), '?')) . ')',
                    [$readAt, $readAt, ...$seen]);
            }
            $n = 0;
            foreach (array_chunk($write, 200, true) as $part) {
                $rows = [];
                $details = [];
                foreach ($part as $id => [$l, $json, $hash]) {
                    array_push($rows, $id, 1, (int) $l['packs'] > 0 ? 1 : 0, $l['urgent'] ? 1 : 0, $l['below_safety'] ? 1 : 0, $l['never'] !== null ? 1 : 0,
                        $l['cover_now_e1'] === null ? 1 : 0, $l['cover_now_e1'] ?? 0, $l['value_e4'] ?? 0, $l['brand'], $l['supplier_id'], (int) $l['safety_stock'],
                        (int) $l['available'], $l['low_stock_level'], $hash, $readAt, $readAt);
                    array_push($details, $id, $json, $readAt);
                }
                $newer = static fn (string $c): string => "{$c} = IF(new.read_at >= reorder_line.read_at, new.{$c}, reorder_line.{$c})";
                $db->exec('INSERT INTO reorder_line (sku_id, listed, need, urgent, below_safety, never_suggested, no_demand, cover_now_e1, value_e4, brand, supplier_id, '
                    . 'safety_stock, available, low_stock_level, line_hash, read_at, changed_at) VALUES '
                    . implode(', ', array_fill(0, count($part), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')) . ' AS new ON DUPLICATE KEY UPDATE '
                    . implode(', ', array_map($newer, ['listed', 'need', 'urgent', 'below_safety', 'never_suggested', 'no_demand', 'cover_now_e1', 'value_e4', 'brand',
                        'supplier_id', 'safety_stock', 'available', 'low_stock_level', 'line_hash', 'changed_at']))
                    . ', read_at = GREATEST(new.read_at, reorder_line.read_at)', $rows); // read_at LAST: the IFs above compare with the stored one
                $db->exec('INSERT INTO reorder_line_detail (sku_id, line, read_at) VALUES ' . implode(', ', array_fill(0, count($part), '(?, CAST(? AS JSON), ?)'))
                    . ' AS new ON DUPLICATE KEY UPDATE line = IF(new.read_at >= reorder_line_detail.read_at, new.line, reorder_line_detail.line), '
                    . 'read_at = GREATEST(new.read_at, reorder_line_detail.read_at)', $details);
                $n += count($part);
            }
            if ($gone !== []) {
                $n += $db->exec('UPDATE reorder_line FORCE INDEX (PRIMARY) SET listed = 0, changed_at = ?, read_at = ? WHERE listed = 1 AND read_at <= ? AND sku_id IN ('
                    . implode(', ', array_fill(0, count($gone), '?')) . ')', [$readAt, $readAt, $readAt, ...$gone]);
            }
            return $n;
        });
    }

    /**
     * The full run's clean-up (review finding 2, Codex 14 Oct 2026): of the listed items that were not in the ids the run read at
     * $baseAt, unlists those that are still off the list now (no demand, no smallest stock, no low-stock figure) and whose line was read
     * no later than $baseAt. An item added while the run worked (its own refresh wrote its line after $baseAt) stays listed.
     *
     * @param list<int> $ids
     */
    private function unlist(array $ids, string $baseAt): int
    {
        if ($ids === []) {
            return 0;
        }
        $now = Clock::db(($this->clock)());
        return $this->db->transaction(function (Db $db) use ($ids, $baseAt, $now): int {
            $in = implode(', ', array_fill(0, count($ids), '?'));
            $old = array_map('intval', $db->column("SELECT sku_id FROM reorder_line FORCE INDEX (PRIMARY) WHERE sku_id IN ({$in}) AND listed = 1 AND read_at <= ? "
                . 'ORDER BY sku_id FOR UPDATE', [...$ids, $baseAt]));
            if ($old === []) {
                return 0;
            }
            $in = implode(', ', array_fill(0, count($old), '?'));
            $still = array_fill_keys(array_map('intval', $db->column("SELECT sku_id FROM reorder_demand WHERE sku_id IN ({$in}) UNION SELECT sku_id FROM item_reorder "
                . "WHERE sku_id IN ({$in}) AND (min_stock IS NOT NULL OR low_stock_level IS NOT NULL)", [...$old, ...$old])), true);
            $off = array_values(array_filter($old, static fn (int $id): bool => !isset($still[$id])));
            if ($off === []) {
                return 0;
            }
            return $db->exec('UPDATE reorder_line FORCE INDEX (PRIMARY) SET listed = 0, changed_at = ?, read_at = ? WHERE listed = 1 AND read_at <= ? AND sku_id IN ('
                . implode(', ', array_fill(0, count($off), '?')) . ')', [$now, $baseAt, $baseAt, ...$off]);
        });
    }
}
