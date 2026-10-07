<?php

declare(strict_types=1);

namespace CW;

/**
 * Per-listing availability and the change feed (plan §3 GET /v1/changes, /v1/availability,
 * snapshot; §4 "values, not deltas").
 *
 * A listing view: link status, central code, policy, u, available, state, version.
 *   available = floor(SUM over the channel's sellable warehouse(s) of (on_hand - allocated - held) / u)
 *               (null when the listing is not linked; 0 when the item has no balance yet)
 *   state     = unlinked | legacy (whatever the listing's status, R8) | stopped (policy stopped,
 *               or the listing is quarantined) | in_stock (available >= 1) | out_of_stock (strict)
 *               | backorder (backorder item)
 *   version   = MAX(seq) of the stock_change rows that affect the listing (D38): rows for the
 *               listing itself, for its item (sku rows), for its channel, and global rows.
 *   site      = what the site stock writer writes for the listing (IM10; SiteWriter\SiteView): writer (the site's switch is on
 *               and the listing linked), qty, mode, backorders, low_stock_threshold, why, meaning; on /v1/changes pages also
 *               moves (the item's non-sale movements behind the change). The switch, a per-site mode, a threshold and a block of
 *               the item card all write feed rows, so a listing's version moves with its `site` values too.
 *
 * Values are read AFTER the versions, so a value is never older than its version; a site applies
 * a value only when its version is newer than the one it holds.
 */
final class Availability
{
    public const STATES = ['in_stock', 'out_of_stock', 'backorder', 'stopped', 'unlinked', 'legacy'];
    public const DEFAULT_OVERLAP_SEC = 10;
    public const MAX_CHANGE_ROWS = 5000;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Current values for some of a channel's variants (the cart pre-check). Unknown variants are
     * returned as unlinked with version 0.
     *
     * @param list<string|int> $variantIds
     * @return list<array<string, mixed>>
     */
    public function forVariants(int $channelId, array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_map('strval', $variantIds)));
        if ($variantIds === []) {
            return [];
        }
        $ids = [];
        $known = [];
        foreach (array_chunk($variantIds, 1000) as $chunk) {
            foreach ($this->db->all(
                'SELECT id, external_variant_id FROM channel_listing WHERE channel_id = ? AND external_variant_id IN ('
                . self::marks($chunk) . ')',
                [$channelId, ...$chunk],
            ) as $r) {
                $ids[] = (int) $r['id'];
                $known[(string) $r['external_variant_id']] = true;
            }
        }
        $out = $this->views($channelId, $ids);
        foreach ($variantIds as $v) {
            if (!isset($known[$v])) {
                $out[] = ['variant_id' => $v, 'listing_id' => null, 'link' => 'unknown', 'sku_code' => null, 'policy' => null,
                    'units_per_item' => 1, 'available' => null, 'state' => 'unlinked', 'version' => 0, 'site' => ['writer' => false, 'why' => 'unlinked']];
            }
        }
        return $out;
    }

    /**
     * One page of every listing of the channel, by listing id (the 15-minute snapshot).
     *
     * @return array{listings: list<array<string, mixed>>, next_after_listing: ?int, seq: int}
     */
    public function snapshot(int $channelId, int $afterListingId = 0, int $limit = 1000): array
    {
        $limit = max(1, min($limit, 5000));
        $seq = $this->headSeq();
        $ids = array_map('intval', $this->db->column(
            'SELECT id FROM channel_listing WHERE channel_id = ? AND id > ? ORDER BY id LIMIT ?',
            [$channelId, $afterListingId, $limit],
        ));
        return [
            'listings' => $this->views($channelId, $ids),
            'next_after_listing' => count($ids) === $limit ? end($ids) : null,
            'seq' => $seq,
        ];
    }

    /**
     * Listings of the channel affected by change rows with seq > $afterSeq, plus the listings and
     * items of every row created in the last $overlapSec seconds (a second line of defence, D16;
     * the feed clock already allocates seq in commit order, D39). A NEW channel-wide or global
     * row (seq > $afterSeq) sets `resync` (the site then pages through snapshot()); the same row
     * seen again in the overlap does not, so one warehouse change means one re-snapshot (R3).
     * `next_after` is the highest seq read; `more` means another call should follow at once.
     * `head_seq` is the feed head, MAX(seq), read after the page (so head_seq >= next_after unless
     * the caller's own `after` is beyond it). A head below the seq a site has already applied
     * means CW's feed went back (a restore): the site then re-snapshots (A15).
     *
     * @return array{listings: list<array<string, mixed>>, next_after: int, more: bool, resync: bool, head_seq: int}
     */
    public function changes(int $channelId, int $afterSeq, int $limit = self::MAX_CHANGE_ROWS, int $overlapSec = self::DEFAULT_OVERLAP_SEC): array
    {
        $limit = max(1, min($limit, self::MAX_CHANGE_ROWS));
        $new = $this->db->all(
            'SELECT seq, sku_id, listing_id, channel_id, created_at FROM stock_change WHERE seq > ? ORDER BY seq LIMIT ?',
            [$afterSeq, $limit],
        );
        $overlap = $overlapSec <= 0 ? [] : $this->db->all(
            'SELECT seq, sku_id, listing_id, channel_id, created_at FROM stock_change '
            . 'WHERE created_at >= UTC_TIMESTAMP(6) - INTERVAL ? SECOND AND seq <= ?',
            [$overlapSec, $afterSeq],
        );
        $next = $afterSeq;
        $resync = false;
        $listingIds = [];
        $skuIds = [];
        $from = null;
        $to = null;
        foreach ([...$overlap, ...$new] as $r) {
            $next = max($next, (int) $r['seq']);
            $at = (string) $r['created_at'];
            $from = $from === null || $at < $from ? $at : $from;
            $to = $to === null || $at > $to ? $at : $to;
            if ($r['listing_id'] !== null) {
                $listingIds[(int) $r['listing_id']] = true;
            } elseif ($r['sku_id'] !== null) {
                $skuIds[(int) $r['sku_id']] = true;
            } elseif ((int) $r['seq'] > $afterSeq && ($r['channel_id'] === null || (int) $r['channel_id'] === $channelId)) {
                $resync = true;
            }
        }
        $ids = [];
        foreach (array_chunk(array_keys($listingIds), 1000) as $chunk) {
            foreach ($this->db->column('SELECT id FROM channel_listing WHERE channel_id = ? AND id IN (' . self::marks($chunk) . ')', [$channelId, ...$chunk]) as $id) {
                $ids[(int) $id] = true;
            }
        }
        foreach (array_chunk(array_keys($skuIds), 1000) as $chunk) {
            foreach ($this->db->column(
                "SELECT id FROM channel_listing WHERE channel_id = ? AND status IN ('mapped', 'quarantined') AND sku_id IN (" . self::marks($chunk) . ')',
                [$channelId, ...$chunk],
            ) as $id) {
                $ids[(int) $id] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);
        $views = $this->views($channelId, $ids);
        if ($from !== null) {
            $views = $this->withMoves($channelId, $views, $from, (string) $to);
        }
        return [
            'listings' => $views,
            'next_after' => $next,
            'more' => count($new) === $limit,
            'resync' => $resync,
            'head_seq' => $this->headSeq(),
        ];
    }

    /**
     * The feed head: the highest seq written (0 on an empty feed). Pruning keeps the newest row of
     * every scope (H2), so the head never moves back except through a restore of the database.
     */
    public function headSeq(): int
    {
        return (int) ($this->db->value('SELECT MAX(seq) FROM stock_change') ?? 0);
    }

    /**
     * Current views of listings of one channel. Versions are read first, values second; if a
     * listing's link changed in between, both are read again (bounded).
     *
     * @param list<int> $listingIds
     * @return list<array<string, mixed>>
     */
    public function views(int $channelId, array $listingIds): array
    {
        $listingIds = array_values(array_unique($listingIds));
        if ($listingIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($listingIds, 1000) as $chunk) {
            for ($pass = 0; ; $pass++) {
                $links = $this->links($channelId, $chunk);
                $versions = $this->versions($channelId, $links);
                $values = $this->values($channelId, $chunk);
                $moved = false;
                foreach ($values as $id => $val) {
                    if (($links[$id]['sku_id'] ?? null) !== $val['sku_id']) {
                        $moved = true;
                    }
                }
                if (!$moved || $pass >= 2) {
                    break;
                }
            }
            $site = SiteWriter\SiteView::blocks($this->db, $channelId, array_map(static fn (array $v): array => ['status' => $v['status'], 'sku_id' => $v['sku_id'],
                'policy' => $v['policy'], 'qty' => $v['sku_id'] === null ? null : Reservations::listingUnits($v['avail'], $v['u'])], $values));
            foreach ($values as $id => $val) {
                $out[] = self::view($val, $versions[$id] ?? 0, $site[$id] ?? ['writer' => false, 'why' => 'writer_off']);
            }
        }
        return $out;
    }

    /**
     * Adds `site.moves` (SiteView::moves) to the views the site writer looks after: the item's movements in the page's window
     * [$from, $to] (the created_at of its feed rows) that the site does not log itself, so its stock-log line can say what changed.
     *
     * @param list<array<string, mixed>> $views
     * @return list<array<string, mixed>>
     */
    private function withMoves(int $channelId, array $views, string $from, string $to): array
    {
        $skus = [];
        $codes = [];
        foreach ($views as $v) {
            if (($v['site']['writer'] ?? false) === true && $v['sku_code'] !== null) {
                $codes[(string) $v['sku_code']] = true;
            }
        }
        if ($codes === []) {
            return $views;
        }
        foreach (array_chunk(array_keys($codes), 1000) as $chunk) {
            foreach ($this->db->all('SELECT id, code FROM sku WHERE code IN (' . self::marks($chunk) . ')', $chunk) as $r) {
                $skus[(string) $r['code']] = (int) $r['id'];
            }
        }
        $moves = SiteWriter\SiteView::moves($this->db, $channelId, array_values($skus), $from, $to);
        foreach ($views as $i => $v) {
            if (($v['site']['writer'] ?? false) === true && $v['sku_code'] !== null) {
                $views[$i]['site']['moves'] = $moves[$skus[(string) $v['sku_code']] ?? 0] ?? [];
            }
        }
        return $views;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{sku_id: ?int}>
     */
    private function links(int $channelId, array $ids): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT id, IF(status IN ('mapped', 'quarantined'), sku_id, NULL) AS sku_id FROM channel_listing "
            . 'WHERE channel_id = ? AND id IN (' . self::marks($ids) . ')',
            [$channelId, ...$ids],
        ) as $r) {
            $out[(int) $r['id']] = ['sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id']];
        }
        return $out;
    }

    /**
     * @param array<int, array{sku_id: ?int}> $links
     * @return array<int, int> listing id => version
     */
    private function versions(int $channelId, array $links): array
    {
        if ($links === []) {
            return [];
        }
        $ids = array_keys($links);
        $byListing = [];
        foreach ($this->db->all(
            'SELECT listing_id, MAX(seq) AS v FROM stock_change WHERE listing_id IN (' . self::marks($ids) . ') GROUP BY listing_id',
            $ids,
        ) as $r) {
            $byListing[(int) $r['listing_id']] = (int) $r['v'];
        }
        $skus = array_values(array_unique(array_filter(array_column($links, 'sku_id'), static fn ($s): bool => $s !== null)));
        $bySku = [];
        if ($skus !== []) {
            foreach ($this->db->all(
                'SELECT sku_id, MAX(seq) AS v FROM stock_change WHERE sku_id IN (' . self::marks($skus) . ') AND listing_id IS NULL GROUP BY sku_id',
                $skus,
            ) as $r) {
                $bySku[(int) $r['sku_id']] = (int) $r['v'];
            }
        }
        $wide = (int) ($this->db->value(
            'SELECT MAX(seq) FROM stock_change WHERE sku_id IS NULL AND listing_id IS NULL AND (channel_id = ? OR channel_id IS NULL)',
            [$channelId],
        ) ?? 0);
        $out = [];
        foreach ($links as $id => $l) {
            $out[$id] = max($wide, $byListing[$id] ?? 0, $l['sku_id'] === null ? 0 : ($bySku[$l['sku_id']] ?? 0));
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function values(int $channelId, array $ids): array
    {
        $rows = $this->db->all(
            'SELECT cl.id, cl.external_variant_id, cl.status, cl.units_per_item, '
            . "IF(cl.status IN ('mapped', 'quarantined'), cl.sku_id, NULL) AS sku_id, s.code, s.sell_policy, "
            . '(SELECT SUM(b.on_hand - b.allocated - b.held) FROM channel_warehouse cw '
            . '   JOIN stock_balance b ON b.warehouse_id = cw.warehouse_id AND b.sku_id = cl.sku_id '
            . '   WHERE cw.channel_id = cl.channel_id AND cw.is_sellable = 1) AS avail '
            . "FROM channel_listing cl LEFT JOIN sku s ON s.id = cl.sku_id AND cl.status IN ('mapped', 'quarantined') "
            . 'WHERE cl.channel_id = ? AND cl.id IN (' . self::marks($ids) . ') ORDER BY cl.id',
            [$channelId, ...$ids],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = [
                'listing_id' => (int) $r['id'],
                'variant_id' => (string) $r['external_variant_id'],
                'status' => (string) $r['status'],
                'u' => (int) $r['units_per_item'],
                'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'],
                'code' => $r['code'] === null ? null : (string) $r['code'],
                'policy' => $r['sell_policy'] === null ? null : (string) $r['sell_policy'],
                'avail' => (int) ($r['avail'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $v
     * @param array<string, mixed> $site SiteView::rule()
     */
    private static function view(array $v, int $version, array $site): array
    {
        $linked = $v['sku_id'] !== null;
        $available = $linked ? Reservations::listingUnits($v['avail'], $v['u']) : null;
        $state = match (true) {
            !$linked => 'unlinked',
            $v['policy'] === 'legacy' => 'legacy', // never refused (§2.3, R8); its quantity (and a mode CW set) reach the site only through the site block (I150)
            $v['status'] === 'quarantined', $v['policy'] === 'stopped' => 'stopped',
            $available >= 1 => 'in_stock',
            $v['policy'] === 'backorder' => 'backorder',
            default => 'out_of_stock',
        };
        return [
            'variant_id' => $v['variant_id'],
            'listing_id' => $v['listing_id'],
            'link' => $v['status'],
            'sku_code' => $v['code'],
            'policy' => $v['policy'],
            'units_per_item' => $v['u'],
            'available' => $available,
            'state' => $state,
            'version' => $version,
            'site' => $site,
        ];
    }

    /** @param array<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
