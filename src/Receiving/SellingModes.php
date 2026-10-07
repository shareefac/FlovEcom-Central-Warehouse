<?php

declare(strict_types=1);

namespace CW\Receiving;

use CW\Caller;
use CW\Clock;
use CW\Db;
use CW\SiteWriter\SiteModes;

/**
 * The selling mode a receipt gives an item (IM6 "selling mode on receipt"; docs/decisions.md I136-I138), kept for the site stock
 * writer (IM10), which sends quantity and mode together as ERPNext does today, so the sites' back-in-stock e-mails fire.
 *
 * The labels stay the sites' own: In-Stock (sold whatever the figure), From-Warehouse (sold while there is stock), Out-Of-Stock.
 *
 *  current()  the mode CW knows for each item: the newest of its item_selling_mode row (set by an earlier receipt) and its
 *             item_channel_mode rows on the receipt sites (IM10's switch, I157), else the mode its linked listings had on the sites' last stock snapshot (listing_stock_latest: the newest snapshot first, then the lowest
 *             channel id), else none. With the previous mode of an Out-Of-Stock item when CW set it.
 *  resolve()  the mode of a receipt line: the desk's choice when it made one; otherwise the item's last mode, except that an
 *             Out-Of-Stock item goes back to its previous mode, and an item with no known mode (or an Out-Of-Stock one whose
 *             previous mode is not known) gets receiving.mode_after_out_of_stock (From-Warehouse until the owner says).
 *  write()    the posting's writes: one item_selling_mode row per item (version + 1) and one item_selling_mode_log row each, in
 *             sku_id order, inside the posting's transaction and BEFORE its stock locks (I21), timed by the posting's clock. A
 *             receipt writes the mode of every item it accepts something of into MAIN; an item it accepts nothing of keeps its
 *             mode (I169: a refused, short or quarantined delivery never puts an item back on sale).
 *
 * item_selling_mode is NO_DELETE for the app login, its log APPEND_ONLY; G6 compares each row with its last log row.
 */
final class SellingModes
{
    public const MODES = ['In-Stock', 'From-Warehouse', 'Out-Of-Stock'];
    public const OUT = 'Out-Of-Stock';
    /** What the screens print beside each label (IM10: "CW's meaning shown beside each"). */
    public const MEANING = [
        'In-Stock' => 'sold whatever the figure',
        'From-Warehouse' => 'sold while there is stock',
        'Out-Of-Stock' => 'not sold',
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param list<int> $skuIds
     * @return array<int, array{mode: ?string, previous: ?string, from: ?string, version: int}> from: cw | site | null
     */
    public function current(array $skuIds, bool $lock = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $skuIds)));
        sort($ids);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['mode' => null, 'previous' => null, 'from' => null, 'version' => 0];
        }
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $at = [];
            foreach ($this->db->all("SELECT sku_id, mode, previous_mode, version, updated_at FROM item_selling_mode WHERE sku_id IN ({$in}) ORDER BY sku_id"
                . ($lock ? ' FOR UPDATE' : ''), $chunk) as $r) {
                $out[(int) $r['sku_id']] = ['mode' => (string) $r['mode'], 'previous' => $r['previous_mode'] === null ? null : (string) $r['previous_mode'],
                    'from' => 'cw', 'version' => (int) $r['version']];
                $at[(int) $r['sku_id']] = (string) $r['updated_at'];
            }
            // IM10 (I157): the selling-mode switch writes the receipt sites' own rows (item_channel_mode); the newest write of either
            // kind is the item's last mode, so a switch to Out-Of-Stock on Vape and Go is what the next receipt brings back from.
            $sites = new SiteModes($this->db);
            $channels = array_keys($sites->receiptChannels());
            if ($channels !== []) {
                foreach ($sites->current($chunk, $channels, $lock) as $row) {
                    $sku = $row['sku_id'];
                    if ($row['updated_at'] > ($at[$sku] ?? '')) {
                        $at[$sku] = $row['updated_at'];
                        $out[$sku]['mode'] = $row['mode'];
                        $out[$sku]['previous'] = $row['previous'];
                        $out[$sku]['from'] = 'cw';
                    }
                }
            }
            $missing = array_values(array_filter($chunk, static fn (int $id): bool => $out[$id]['from'] === null));
            if ($missing === []) {
                continue;
            }
            $in = implode(', ', array_fill(0, count($missing), '?'));
            foreach ($this->db->all(
                'SELECT l.sku_id, x.stock_mode FROM channel_listing l JOIN listing_stock_latest x ON x.channel_id = l.channel_id '
                . "AND x.external_variant_id = l.external_variant_id WHERE l.status = 'mapped' AND l.sku_id IN ({$in}) "
                . 'ORDER BY x.snapshot_date DESC, l.channel_id, l.id',
                $missing,
            ) as $r) {
                $sku = (int) $r['sku_id'];
                $mode = self::label($r['stock_mode']);
                if ($mode !== null && $out[$sku]['mode'] === null) {
                    $out[$sku]['mode'] = $mode;
                    $out[$sku]['from'] = 'site';
                }
            }
        }
        return $out;
    }

    /**
     * The mode of a receipt line (class docblock): ['mode' => one of MODES, 'source' => chosen | last | previous | fallback].
     *
     * @param array{mode: ?string, previous: ?string} $current
     * @return array{mode: string, source: string}
     */
    public static function resolve(array $current, string $choice, string $fallback): array
    {
        if (in_array($choice, self::MODES, true)) {
            return ['mode' => $choice, 'source' => 'chosen'];
        }
        $fallback = in_array($fallback, ['In-Stock', 'From-Warehouse'], true) ? $fallback : 'From-Warehouse';
        $mode = $current['mode'];
        if ($mode === null) {
            return ['mode' => $fallback, 'source' => 'fallback'];
        }
        if ($mode === self::OUT) {
            $prev = $current['previous'];
            return $prev !== null && $prev !== self::OUT ? ['mode' => $prev, 'source' => 'previous'] : ['mode' => $fallback, 'source' => 'fallback'];
        }
        return ['mode' => $mode, 'source' => 'last'];
    }

    /**
     * The previous mode to keep with $new: an item going (or staying) Out-Of-Stock remembers the mode it had before.
     *
     * @param array{mode: ?string, previous: ?string} $current
     */
    public static function previousFor(array $current, string $new): ?string
    {
        if ($new !== self::OUT) {
            return null;
        }
        $mode = $current['mode'];
        if ($mode !== null && $mode !== self::OUT) {
            return $mode;
        }
        return $current['previous'];
    }

    /**
     * The posting's writes (class docblock). $writes: sku_id => {mode, source, line_no, current}; the rows were read with
     * current($ids, true) in this transaction. $now: the posting's time (DB format; default the wall clock).
     *
     * @param array<int, array{mode: string, source: string, line_no: int, current: array{mode: ?string, previous: ?string, from: ?string, version: int}}> $writes
     */
    public function write(Caller $caller, int $documentId, array $writes, ?string $now = null): void
    {
        ksort($writes);
        $now ??= Clock::db(Clock::now()); // the posting's clock (current() compares these times, I157)
        foreach ($writes as $sku => $w) {
            $prev = self::previousFor($w['current'], $w['mode']);
            $this->db->exec(
                'INSERT INTO item_selling_mode (sku_id, mode, previous_mode, version, document_id, updated_by, updated_actor, updated_at) '
                . 'VALUES (?, ?, ?, 1, ?, ?, ?, ?) AS new ON DUPLICATE KEY UPDATE mode = new.mode, previous_mode = new.previous_mode, '
                . 'version = item_selling_mode.version + 1, document_id = new.document_id, updated_by = new.updated_by, updated_actor = new.updated_actor, '
                . 'updated_at = new.updated_at',
                [$sku, $w['mode'], $prev, $documentId, $caller->staffUserId, $caller->actor, $now],
            );
            $version = (int) $this->db->value('SELECT version FROM item_selling_mode WHERE sku_id = ?', [$sku]);
            $this->db->exec(
                'INSERT INTO item_selling_mode_log (sku_id, version, mode_before, mode_after, previous_mode, mode_source, reason, document_id, line_no, actor, '
                . "staff_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, 'receipt', ?, ?, ?, ?, ?)",
                [$sku, $version, $w['current']['mode'], $w['mode'], $prev, $w['source'], $documentId, $w['line_no'], $caller->actor, $caller->staffUserId, $now],
            );
        }
    }

    /** A site's stock_mode as one of MODES (case and spaces forgiven), or null. */
    public static function label(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $k = strtolower((string) preg_replace('/[\s_-]+/', '', $v));
        return match ($k) {
            'instock' => 'In-Stock',
            'fromwarehouse' => 'From-Warehouse',
            'outofstock' => 'Out-Of-Stock',
            default => null,
        };
    }
}
