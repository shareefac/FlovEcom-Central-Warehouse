<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Db;

/**
 * The selling mode a website gives one of its own products, as the website says it (T-556 step 2, option A: the owner, 16 Nov 2026;
 * migration 0068). VIEW ONLY: CW shows it on the item page beside the mode CW itself writes ("Selling mode it gets"), so staff see
 * what each store does now. Nothing in CW decides anything by it: not the feed, not the selling-mode switch, not a receipt.
 *
 * WHERE IT LIVES. One row per website product in `listing_selling` (KEY of a pushed listing: `selling`): the website's selling mode
 * (In-Stock, From-Warehouse, Out-Of-Stock: the website's own labels, kept as the website says them), whether it takes back-orders,
 * its low-stock warning, `as_of` (when the website said so: the push's time) and `changed_at` (when one of the three last changed,
 * as far as CW heard). No row: not known yet (a store whose connector does not send it, or a product not sent since).
 *
 * WHO WRITES IT. Only the listing intake (ListingIngestService::ingest), in its own transaction, for the listings of a push that carry
 * `selling`: read leniently by parse() (never a refusal: a value of the wrong type is ignored and counted, the listing is never
 * refused), stored whole (what it leaves out is "none set there"), outside both profile hashes, so it never makes a push look
 * changed, never marks a profile "to check" and never moves a map_version. A push without `selling` (an older connector, an import
 * of an export file) leaves the stored row as it is. The website state of M66 (`website`, SiteState) is a different key and is not
 * touched by it.
 */
final class SiteSelling
{
    /** The key of a pushed listing that carries it. */
    public const KEY = 'selling';
    /** The longest selling mode kept (the websites' labels are 14 characters at most). */
    public const MODE_MAX = 32;
    private const INT_MIN = -2147483648;
    private const INT_MAX = 2147483647;
    /** Rows a statement writes at most. */
    private const CHUNK = 500;

    /**
     * A listing's `selling` value, read leniently (this is a data feed: a value that cannot be read must not cost the listing its push).
     * An object that names `stock_mode` (null included: the website has none set) is a state, stored whole; each of its three values
     * that is of the wrong type is IGNORED AND COUNTED and is then null. Anything else (no object, an object without `stock_mode`) is
     * no state: the stored one stays (counted once unless it is null or empty). Keys other than the three are left alone.
     *
     *  stock_mode           a text of at most MODE_MAX characters (trimmed; empty = none)
     *  allow_backorders     true / false / 1 / 0 / "1" / "0"
     *  low_stock_threshold  a whole number (or a text of digits, an optional minus first) the website's column can hold
     *
     * @return array{state: array{stock_mode: ?string, allow_backorders: ?int, low_stock_threshold: ?int}|null, ignored: int}
     */
    public static function parse(mixed $selling): array
    {
        if ($selling === null || $selling === []) {
            return ['state' => null, 'ignored' => 0];
        }
        if (!is_array($selling) || array_is_list($selling) || !array_key_exists('stock_mode', $selling)) {
            return ['state' => null, 'ignored' => 1];
        }
        $state = ['stock_mode' => null, 'allow_backorders' => null, 'low_stock_threshold' => null];
        $ignored = 0;
        $mode = $selling['stock_mode'];
        if ($mode !== null) {
            if (!is_string($mode) || !mb_check_encoding($mode, 'UTF-8') || mb_strlen(trim($mode)) > self::MODE_MAX) {
                $ignored++;
            } else {
                $state['stock_mode'] = trim($mode) === '' ? null : trim($mode);
            }
        }
        $bo = $selling['allow_backorders'] ?? null;
        if ($bo !== null) {
            if (!in_array($bo, [true, false, 1, 0, '1', '0'], true)) {
                $ignored++;
            } else {
                $state['allow_backorders'] = $bo === true || $bo === 1 || $bo === '1' ? 1 : 0;
            }
        }
        $th = $selling['low_stock_threshold'] ?? null;
        if ($th !== null) {
            $n = is_int($th) ? $th : (is_string($th) && preg_match('/^-?\d{1,10}$/D', trim($th)) === 1 ? (int) trim($th) : null);
            if ($n === null || $n < self::INT_MIN || $n > self::INT_MAX) {
                $ignored++;
            } else {
                $state['low_stock_threshold'] = $n;
            }
        }
        return ['state' => $state, 'ignored' => $ignored];
    }

    /**
     * Writes the states of one intake (listing id => parse()'s state) as of $at (the push's time), in listing id order, a few hundred
     * rows a statement. A stored row whose `as_of` is newer than $at is left as it is (an older state never overwrites a newer one);
     * `changed_at` moves only when one of the three values differs from the stored ones.
     *
     * @param array<int, array{stock_mode: ?string, allow_backorders: ?int, low_stock_threshold: ?int}> $states
     */
    public static function write(Db $db, array $states, string $at): void
    {
        if ($states === []) {
            return;
        }
        ksort($states);
        foreach (array_chunk($states, self::CHUNK, true) as $chunk) {
            $params = [];
            foreach ($chunk as $id => $s) {
                array_push($params, (int) $id, $s['stock_mode'], $s['allow_backorders'], $s['low_stock_threshold'], $at, $at);
            }
            // The assignments run left to right and read the row as already updated: `changed_at` first, while the stored values are
            // still the old ones; then the three values; `as_of` last, which the three read too.
            $older = 'new.as_of < listing_selling.as_of';
            $db->exec(
                'INSERT INTO listing_selling (listing_id, stock_mode, allow_backorders, low_stock_threshold, as_of, changed_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)')) . ' AS new ON DUPLICATE KEY UPDATE '
                . "changed_at = IF({$older} OR (listing_selling.stock_mode <=> new.stock_mode AND listing_selling.allow_backorders <=> new.allow_backorders "
                . 'AND listing_selling.low_stock_threshold <=> new.low_stock_threshold), listing_selling.changed_at, new.as_of), '
                . "stock_mode = IF({$older}, listing_selling.stock_mode, new.stock_mode), "
                . "allow_backorders = IF({$older}, listing_selling.allow_backorders, new.allow_backorders), "
                . "low_stock_threshold = IF({$older}, listing_selling.low_stock_threshold, new.low_stock_threshold), "
                . "as_of = IF({$older}, listing_selling.as_of, new.as_of)",
                $params,
            );
        }
    }

    /**
     * What the item page shows of one website product (a row of channel_listing LEFT JOIN listing_selling: stock_mode,
     * allow_backorders, low_stock_threshold, as_of): null = not known yet (no row).
     *
     * @param array<string, mixed> $row
     * @return array{mode: ?string, backorders: ?int, threshold: ?int, as_of: string}|null
     */
    public static function shown(array $row): ?array
    {
        if (!is_string($row['as_of'] ?? null) || $row['as_of'] === '') {
            return null;
        }
        return [
            'mode' => $row['stock_mode'] === null ? null : (string) $row['stock_mode'],
            'backorders' => $row['allow_backorders'] === null ? null : (int) $row['allow_backorders'],
            'threshold' => $row['low_stock_threshold'] === null ? null : (int) $row['low_stock_threshold'],
            'as_of' => (string) $row['as_of'],
        ];
    }
}
