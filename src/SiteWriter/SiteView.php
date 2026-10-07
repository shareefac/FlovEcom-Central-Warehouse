<?php

declare(strict_types=1);

namespace CW\SiteWriter;

use CW\Catalogue\ItemCompliance;
use CW\Catalogue\ItemRules;
use CW\Db;

/**
 * What a site writes for one of its listings (IM10 site stock writer; docs/decisions.md I148-I166): the `site` block of every
 * listing view of the change feed (CW\Availability: GET /v1/changes, /v1/snapshot, /v1/availability).
 *
 *   writer               CW looks after this listing's stock, selling mode and low-stock threshold on the site: the site's switch is on
 *                        (channel.site_writer, off by default; I-Day turns it on) AND the listing is linked (mapped or quarantined).
 *                        false: the site writes nothing from CW for it (`why` says why: writer_off, unlinked).
 *   qty                  prodt_stock in listing units: floor(available / u), as the feed's `available` (may be negative: a backorder
 *                        item's "arrange" signal).
 *   mode / backorders    the site's prodt_stock_mode / prodt_allow_backorders, matching the site's own stock_check():
 *                          blocked by its item card (ItemCompliance 'sell')   Out-Of-Stock, 0      why blocked
 *                          a quarantined listing                              Out-Of-Stock, 0      why quarantined
 *                          policy stopped                                     Out-Of-Stock, 0      why stopped
 *                          policy strict                                      From-Warehouse, 0    why strict
 *                          policy backorder                                   From-Warehouse, 1    why backorder
 *                          legacy, CW set a mode for this site                its mode, null       why site_mode
 *                          legacy, no mode set for this site                  null, null           why site_own
 *                        (plan §6.5 for the counted items, one policy for every site, §7.4; IM10 "mode is per site for legacy
 *                        items"). null = leave the site's own value; after a forced Out-Of-Stock (FORCED) that is the value the
 *                        site had before the force, which the site's writer restores (I178).
 *   low_stock_threshold  prodt_low_stock_threshold from the site's item_channel_mode row; null = leave the site's own.
 *   meaning              CW's meaning of the label, for the screens ("sold while there is stock", ...).
 *   moves                (changes pages only, writer on) the item's stock movements behind this change that the site does not log
 *                        itself: type, ref (document or order), units (effect on availability, central units), last_id (the newest
 *                        stock_ledger id of the group: the site logs a group once). Sales (reserve, release, expire, commit, ship,
 *                        unship, opening orders) are left out: the site logs its own orders.
 */
final class SiteView
{
    public const WHY = ['writer_off', 'unlinked', 'blocked', 'quarantined', 'stopped', 'strict', 'backorder', 'site_mode', 'site_own'];
    /**
     * The reasons CW forces a listing Out-Of-Stock whatever its own mode. When one ends and the listing is legacy without a mode CW set
     * for the site (why site_own: mode null, "leave the site's"), the value to leave is not the Out-Of-Stock CW wrote: the site's
     * writer puts back the mode (and back-order flag) it overwrote when the force began (connector SC15; CW I178). CW does not know
     * that mode, the site does.
     */
    public const FORCED = ['blocked', 'quarantined', 'stopped'];
    /** Movement types whose rows the site logs itself (its own orders), so a site's stock log never shows them twice. */
    public const SALE_TYPES = ['reserve', 'release', 'expire', 'commit', 'commit_release', 'ship', 'unship', 'opening', 'adopt'];
    public const MOVES_PER_ITEM = 5;
    public const MOVES_MAX_ROWS = 2000;
    /** How far before the first feed row of a page its ledger rows may lie (the rows of one transaction come before its feed row). */
    public const MOVES_MARGIN_SEC = 120;

    /**
     * The block of one listing (pure).
     *
     * @param array{writer_on: bool, linked: bool, status: string, policy: ?string, qty: ?int, blocked: list<string>,
     *     site: ?array{mode: string, threshold: ?int}} $l
     * @return array{writer: bool, why: string, qty?: ?int, mode?: ?string, backorders?: ?int, low_stock_threshold?: ?int, meaning?: string}
     */
    public static function rule(array $l): array
    {
        if (!$l['writer_on']) {
            return ['writer' => false, 'why' => 'writer_off'];
        }
        if (!$l['linked']) {
            return ['writer' => false, 'why' => 'unlinked'];
        }
        $site = $l['site'];
        $threshold = $site['threshold'] ?? null;
        [$mode, $backorders, $why] = match (true) {
            $l['blocked'] !== [] => ['Out-Of-Stock', 0, 'blocked'],
            $l['status'] === 'quarantined' => ['Out-Of-Stock', 0, 'quarantined'],
            $l['policy'] === 'stopped' => ['Out-Of-Stock', 0, 'stopped'],
            $l['policy'] === 'strict' => ['From-Warehouse', 0, 'strict'],
            $l['policy'] === 'backorder' => ['From-Warehouse', 1, 'backorder'],
            $site !== null => [$site['mode'], null, 'site_mode'],
            default => [null, null, 'site_own'],
        };
        return ['writer' => true, 'why' => $why, 'qty' => $l['qty'], 'mode' => $mode, 'backorders' => $backorders, 'low_stock_threshold' => $threshold,
            'meaning' => self::meaning($mode, $backorders, $why, $l['blocked'])];
    }

    /** CW's meaning of a mode as the site writes it (IM10: "with CW's meaning shown beside each"). @param list<string> $blocked */
    public static function meaning(?string $mode, ?int $backorders, string $why, array $blocked = []): string
    {
        return match (true) {
            $why === 'blocked' => 'not sold: blocked by its item card (' . ItemRules::labels($blocked) . ')',
            $why === 'quarantined' => 'not sold: the link to CW is being checked',
            $mode === null => 'the site keeps its own selling mode',
            $mode === 'Out-Of-Stock' => 'not sold',
            $mode === 'In-Stock' => 'sold whatever the figure',
            $backorders === 1 => 'sold whatever the figure (back-ordered below zero)',
            $backorders === 0 => 'sold while there is stock',
            default => 'sold while there is stock (unless the site allows backorders)',
        };
    }

    /**
     * The blocks of a channel's listings. $rows: listing id => ['status', 'sku_id' (null: not linked), 'policy', 'qty'].
     * One query for the switch; while it is off nothing else is read.
     *
     * @param array<int, array{status: string, sku_id: ?int, policy: ?string, qty: ?int}> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function blocks(Db $db, int $channelId, array $rows): array
    {
        $on = (int) ($db->value('SELECT site_writer FROM channel WHERE id = ?', [$channelId]) ?? 0) === 1;
        $out = [];
        if (!$on) {
            foreach (array_keys($rows) as $id) {
                $out[$id] = ['writer' => false, 'why' => 'writer_off'];
            }
            return $out;
        }
        $skus = array_values(array_unique(array_filter(array_column($rows, 'sku_id'), static fn ($s): bool => $s !== null)));
        $blocked = $skus === [] ? [] : (new ItemCompliance($db))->selling($skus); // the 'sell' check (I160): blocked -> Out-Of-Stock
        $modes = $skus === [] ? [] : (new SiteModes($db))->current($skus, [$channelId]);
        foreach ($rows as $id => $r) {
            $sku = $r['sku_id'];
            $m = $sku === null ? null : ($modes[SiteModes::key($sku, $channelId)] ?? null);
            $out[$id] = self::rule([
                'writer_on' => true, 'linked' => $sku !== null, 'status' => $r['status'], 'policy' => $r['policy'], 'qty' => $r['qty'],
                'blocked' => $sku === null ? [] : ($blocked[$sku] ?? []),
                'site' => $m === null ? null : ['mode' => $m['mode'], 'threshold' => $m['threshold']],
            ]);
        }
        return $out;
    }

    /**
     * The movements behind a page of changes (class docblock, `moves`): per item, the ledger rows of the channel's sellable
     * warehouses created from $from - MOVES_MARGIN_SEC to $to that the site does not log itself, grouped by (type, ref), at most
     * MOVES_PER_ITEM groups per item (the newest), at most MOVES_MAX_ROWS ledger rows read.
     *
     * @param list<int> $skuIds
     * @return array<int, list<array{type: string, ref: ?string, units: int, last_id: int}>>
     */
    public static function moves(Db $db, int $channelId, array $skuIds, string $from, string $to): array
    {
        if ($skuIds === []) {
            return [];
        }
        $out = [];
        $sale = implode(', ', array_fill(0, count(self::SALE_TYPES), '?'));
        foreach (array_chunk($skuIds, 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $rows = $db->all(
                'SELECT l.id, l.sku_id, l.movement_type, COALESCE(l.doc_ref, l.order_ref) AS ref, '
                . "IF(l.bucket = 'on_hand', l.qty_delta, -l.qty_delta) AS effect FROM stock_ledger l "
                . 'JOIN channel_warehouse cw ON cw.warehouse_id = l.warehouse_id AND cw.channel_id = ? AND cw.is_sellable = 1 '
                . 'WHERE l.created_at >= CAST(? AS DATETIME(6)) - INTERVAL ' . self::MOVES_MARGIN_SEC . " SECOND AND l.created_at <= CAST(? AS DATETIME(6)) AND l.sku_id IN ({$in}) "
                . "AND l.movement_type NOT IN ({$sale}) ORDER BY l.id LIMIT " . self::MOVES_MAX_ROWS,
                [$channelId, $from, $to, ...$chunk, ...self::SALE_TYPES],
            );
            $groups = [];
            foreach ($rows as $r) {
                $sku = (int) $r['sku_id'];
                $ref = $r['ref'] === null ? null : (string) $r['ref'];
                $type = (string) $r['movement_type'];
                // Orders' cancels and returns: one group per type (their refs are order numbers, many per page).
                $k = in_array($type, ['cancel', 'uncancel', 'return'], true) ? $type : $type . "\0" . ($ref ?? '');
                $g = $groups[$sku][$k] ?? ['type' => $type, 'ref' => in_array($type, ['cancel', 'uncancel', 'return'], true) ? null : $ref, 'units' => 0, 'last_id' => 0];
                $g['units'] += (int) $r['effect'];
                $g['last_id'] = max($g['last_id'], (int) $r['id']);
                $groups[$sku][$k] = $g;
            }
            foreach ($groups as $sku => $gs) {
                $gs = array_values(array_filter($gs, static fn (array $g): bool => $g['units'] !== 0));
                usort($gs, static fn (array $a, array $b): int => $a['last_id'] <=> $b['last_id']);
                $out[$sku] = array_slice($gs, -self::MOVES_PER_ITEM);
            }
        }
        return $out;
    }
}
