<?php

declare(strict_types=1);

namespace CW\Catalogue;

use CW\Audit;
use CW\Caller;
use CW\Db;
use CW\Matching\Gtin;

/**
 * The barcode sync (IM3; docs/decisions.md I106, I108; plan change 16): the barcodes the sites hold for LINKED listings
 * (channel_listing status `mapped`; listing_profile.barcodes, written by PUT /v1/listings and the listing import) into the
 * item's sku_barcode rows, because the sites add about 600 barcodes every 90 days, many at dispatch.
 *
 * For each usable GTIN K (Gtin::classify: 8-14 digits, valid check digit) of a listing L linked to item S with units per item u:
 *   already          K is a barcode of S                                     nothing
 *   skipped_decided  the latest decided review of (K, S) keeps K away from S nothing (a person said so: keep_holder,
 *                    (BarcodeReviews::KEEPS_AWAY)                             unusable, dismiss, removed)
 *   in_review        a review of (K, S) is open                              nothing
 *   added            no item has K and u = 1                                 sku_barcode (K, S, usable, 1 unit, source listing_sync)
 *   review           no item has K and u <> 1                                barcode_review multipack_listing (pack or unit?)
 *   review           item H <> S has K                                       barcode_review on_another_item, and H's row made
 *                                                                            unusable (the existing rule), never moved
 * Unusable codes are counted and skipped: shop codes and bad check digits (Gtin::classify), and, stricter than the matcher
 * (I118), a value with anything but digits, spaces and hyphens ("SKU-12345670" is not the GTIN-8 12345670: `junk_codes`) and a
 * GS1 restricted-circulation number (in-store and internal codes, GTIN-13 prefixes 020-029, 040-049, 200-299, GTIN-8 2...:
 * `restricted_codes`). Text without a digit ("Black Grey") is not a code at all and is not counted.
 * Also counted, never changed: `review_holder_merged` (of the on_another_item reviews, those whose holder was merged into the
 * claimant: moving is the usual answer) and `source_unlinked` (barcodes this sync or the seeder added from a listing that is no
 * longer linked to their item: a person checks them on the item page; unlinkedSources() lists them).
 *
 * Dry run by default: reads only, and reports what a real run would do (keys claimed twice within the run included). A real
 * run ($apply) writes one transaction per chunk of listings, re-checking each change under the barcode row's lock (the row,
 * the open reviews and the latest decision of the pair are read again there, so a person's removal committed meanwhile is
 * honoured), and one audit row (sku_barcode.sync, the counts). Idempotent: a second run finds everything already, in review or
 * decided.
 */
final class BarcodeSync
{
    public const SOURCE = 'listing_sync';
    public const CHUNK = 1000;
    public const COUNTS = ['listings', 'codes', 'unusable_codes', 'junk_codes', 'restricted_codes', 'already', 'skipped_decided', 'in_review', 'added',
        'review_on_another_item', 'review_holder_merged', 'review_multipack_listing', 'made_unusable', 'raced'];
    /** The sku_barcode sources whose note names the listing a barcode came from ("listing 123"). */
    public const LISTING_SOURCES = [self::SOURCE, \CW\Mapping\BarcodeSeeder::SOURCE];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param ?int $channelId only the listings of this channel
     * @param ?int $limit at most this many listings (a canary run)
     * @return array<string, int|string> COUNTS plus ms and mode
     */
    public function run(Caller $caller, bool $apply = false, ?int $channelId = null, ?int $limit = null): array
    {
        $t0 = hrtime(true);
        $c = array_fill_keys(self::COUNTS, 0);
        // As found before the run (a dry run and a real run say the same): a barcode the run then sends to review leaves this list
        // for the review queue.
        $unlinked = $this->unlinkedSources(0)['count'];
        $overlay = ['rows' => [], 'open' => []]; // dry run: what this run would have written so far
        $after = 0;
        while (true) {
            $take = $limit === null ? self::CHUNK : min(self::CHUNK, $limit - $c['listings']);
            if ($take <= 0) {
                break;
            }
            $listings = $this->db->all(
                'SELECT cl.id, cl.sku_id, cl.units_per_item, lp.barcodes FROM channel_listing cl JOIN listing_profile lp ON lp.listing_id = cl.id '
                . "WHERE cl.status = 'mapped' AND cl.sku_id IS NOT NULL AND cl.id > ?" . ($channelId === null ? '' : ' AND cl.channel_id = ?')
                . ' ORDER BY cl.id LIMIT ' . $take,
                $channelId === null ? [$after] : [$after, $channelId],
            );
            if ($listings === []) {
                break;
            }
            $after = (int) $listings[count($listings) - 1]['id'];
            $c['listings'] += count($listings);
            $pairs = [];
            foreach ($listings as $l) {
                $codes = json_decode((string) $l['barcodes'], true);
                $k = self::keys(is_array($codes) ? array_values($codes) : []);
                $c['unusable_codes'] += $k['unusable'];
                $c['junk_codes'] += $k['junk'];
                $c['restricted_codes'] += $k['restricted'];
                foreach ($k['usable'] as $key) {
                    $pairs[] = ['listing' => (int) $l['id'], 'sku' => (int) $l['sku_id'], 'u' => (int) $l['units_per_item'], 'key' => (string) $key];
                }
            }
            $c['codes'] += count($pairs);
            if ($pairs === []) {
                continue;
            }
            if ($apply) {
                $done = $this->db->transaction(function (Db $db) use ($caller, $pairs): array {
                    $n = array_fill_keys(self::COUNTS, 0);
                    $state = $this->prefetch($db, $pairs);
                    foreach ($pairs as $p) {
                        $plan = self::plan($p, $state);
                        if ($plan['action'] === 'write') {
                            $plan = $this->writeLocked($db, $caller, $p, $state);
                        }
                        foreach ($plan['count'] as $k) {
                            $n[$k]++;
                        }
                    }
                    return $n;
                });
                foreach ($done as $k => $v) {
                    $c[$k] += $v;
                }
            } else {
                // Dry run: the database's state, plus what this run would have written in earlier chunks (the overlay).
                $state = $this->prefetch($this->db, $pairs);
                foreach ($overlay['rows'] as $k => $v) {
                    $state['rows'][$k] = $v;
                }
                $state['open'] += $overlay['open'];
                foreach ($pairs as $p) {
                    $plan = self::plan($p, $state);
                    if ($plan['action'] === 'write') {
                        $plan = self::simulate($p, $state);
                        if (isset($state['rows'][$p['key']])) {
                            $overlay['rows'][$p['key']] = $state['rows'][$p['key']];
                        }
                        if (isset($state['open'][$p['key'] . ':' . $p['sku']])) {
                            $overlay['open'][$p['key'] . ':' . $p['sku']] = true;
                        }
                    }
                    foreach ($plan['count'] as $k) {
                        $c[$k]++;
                    }
                }
            }
        }
        $out = $c + ['source_unlinked' => $unlinked, 'ms' => intdiv(hrtime(true) - $t0, 1_000_000), 'mode' => $apply ? 'apply' : 'dry_run'];
        if ($apply) {
            Audit::write($this->db, $caller, 'sku_barcode.sync', 'sku_barcode', null, null, $out + ['channel_id' => $channelId, 'limit' => $limit]);
        }
        return $out;
    }

    /**
     * The usable GTIN keys of one listing's barcodes, stricter than Gtin::listingKeys (I118): a value with anything but digits,
     * spaces and hyphens is junk, a restricted-circulation number is not an item's barcode. Counts: unusable (every skipped
     * value that has a digit), of which junk and restricted.
     *
     * @param list<mixed> $codes
     * @return array{usable: list<string>, unusable: int, junk: int, restricted: int}
     */
    public static function keys(array $codes): array
    {
        $out = ['usable' => [], 'unusable' => 0, 'junk' => 0, 'restricted' => 0];
        $usable = [];
        foreach ($codes as $raw) {
            if (is_string($raw) && preg_match('/[0-9]/', $raw) === 1 && preg_match('/^[0-9\s\-]+$/D', trim($raw)) !== 1) {
                $out['unusable']++;
                $out['junk']++;
                continue;
            }
            if (is_float($raw)) {
                $out['unusable']++; // a number with a decimal point is not a barcode (Gtin::key would read its digits)
                $out['junk']++;
                continue;
            }
            if (is_bool($raw) || !is_scalar($raw)) {
                continue;
            }
            $c = Gtin::classify($raw);
            if (!$c['usable']) {
                if ($c['reason'] !== 'empty') {
                    $out['unusable']++;
                }
                continue;
            }
            if (self::restricted((string) $c['key'])) {
                $out['unusable']++;
                $out['restricted']++;
                continue;
            }
            $usable[(string) $c['key']] = true;
        }
        $out['usable'] = array_map('strval', array_keys($usable));
        return $out;
    }

    /**
     * A GS1 restricted-circulation number (in-store, variable-measure or company-internal codes): a GTIN-8 starting with 2, or a
     * GTIN-13 (GTIN-12 / GTIN-14 read through their GTIN-13) with the prefix 020-029, 040-049 or 200-299. $key: Gtin::key, usable.
     */
    public static function restricted(string $key): bool
    {
        if (strlen($key) === 8) {
            return $key[0] === '2';
        }
        $p = substr(str_pad($key, 14, '0', STR_PAD_LEFT), 1, 3); // the GTIN-13's first three digits
        return $p[0] === '2' || ($p[0] === '0' && ($p[1] === '2' || $p[1] === '4'));
    }

    /**
     * The barcodes this sync or the seeder added from a listing (their note "listing N") that is no longer linked to their item
     * (unlinked, linked elsewhere, or the item merged away): a count and the first $limit, for a person to check (I118). Never
     * changed by the sync.
     *
     * @return array{count: int, rows: list<array{barcode: string, code: string, listing_id: int, listing_status: ?string, listing_code: ?string}>}
     */
    public function unlinkedSources(int $limit = 20): array
    {
        $from = 'FROM sku_barcode b JOIN sku s ON s.id = b.sku_id LEFT JOIN channel_listing cl ON cl.id = CAST(SUBSTRING_INDEX(SUBSTRING(b.note, 9), \' \', 1) AS UNSIGNED) '
            . 'LEFT JOIN sku ls ON ls.id = cl.sku_id WHERE b.source IN (' . implode(', ', array_fill(0, count(self::LISTING_SOURCES), '?')) . ") AND b.note LIKE 'listing %' "
            . "AND (cl.id IS NULL OR cl.status <> 'mapped' OR cl.sku_id IS NULL OR cl.sku_id <> b.sku_id)";
        $count = (int) $this->db->value("SELECT COUNT(*) {$from}", self::LISTING_SOURCES);
        $rows = $limit <= 0 || $count === 0 ? [] : array_map(static fn (array $r): array => ['barcode' => (string) $r['barcode'], 'code' => (string) $r['code'],
            'listing_id' => (int) $r['listing_id'], 'listing_status' => $r['listing_status'] === null ? null : (string) $r['listing_status'],
            'listing_code' => $r['listing_code'] === null ? null : (string) $r['listing_code']], $this->db->all(
            'SELECT b.barcode, s.code, CAST(SUBSTRING_INDEX(SUBSTRING(b.note, 9), \' \', 1) AS UNSIGNED) AS listing_id, cl.status AS listing_status, ls.code AS listing_code '
            . "{$from} ORDER BY b.barcode LIMIT " . min(1000, $limit), self::LISTING_SOURCES));
        return ['count' => $count, 'rows' => $rows];
    }

    /**
     * What the database says about the chunk's barcodes: rows (key => [sku, usable, merged_into of that item]), the latest
     * decision per (key, claimant) and the open reviews (key:claimant).
     *
     * @param list<array{listing: int, sku: int, u: int, key: string}> $pairs
     * @return array{rows: array<string, array{sku: int, usable: int, merged_into: ?int}>, decided: array<string, string>, open: array<string, true>}
     */
    private function prefetch(Db $db, array $pairs): array
    {
        $keys = array_values(array_unique(array_column($pairs, 'key')));
        $state = ['rows' => [], 'decided' => [], 'open' => []];
        foreach (array_chunk($keys, 1000) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($db->all("SELECT b.barcode, b.sku_id, b.is_usable, s.merged_into_sku_id FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.barcode IN ({$in})", $chunk) as $r) {
                $state['rows'][(string) $r['barcode']] = ['sku' => (int) $r['sku_id'], 'usable' => (int) $r['is_usable'],
                    'merged_into' => $r['merged_into_sku_id'] === null ? null : (int) $r['merged_into_sku_id']];
            }
            foreach ($db->all("SELECT barcode, claimant_sku_id, status, decision FROM barcode_review WHERE barcode IN ({$in}) ORDER BY id", $chunk) as $r) {
                $pk = $r['barcode'] . ':' . $r['claimant_sku_id'];
                if ($r['status'] === 'open') {
                    $state['open'][$pk] = true;
                } else {
                    $state['decided'][$pk] = (string) $r['decision']; // ORDER BY id: the latest wins
                }
            }
        }
        return $state;
    }

    /**
     * The outcome of one (listing, barcode) from the state: a count, or `write` (something to add or open).
     *
     * @param array{listing: int, sku: int, u: int, key: string} $p
     * @param array{rows: array<string, array{sku: int, usable: int, merged_into: ?int}>, decided: array<string, string>, open: array<string, true>} $state
     * @return array{action: string, count: list<string>}
     */
    private static function plan(array $p, array $state): array
    {
        $row = $state['rows'][$p['key']] ?? null;
        $pk = $p['key'] . ':' . $p['sku'];
        if ($row !== null && $row['sku'] === $p['sku']) {
            return ['action' => 'none', 'count' => ['already']];
        }
        if (in_array($state['decided'][$pk] ?? null, BarcodeReviews::KEEPS_AWAY, true)) {
            return ['action' => 'none', 'count' => ['skipped_decided']];
        }
        if (isset($state['open'][$pk])) {
            return ['action' => 'none', 'count' => ['in_review']];
        }
        return ['action' => 'write', 'count' => []];
    }

    /**
     * Dry run: what writeLocked() would do, applied to $state only.
     *
     * @param array{listing: int, sku: int, u: int, key: string} $p
     * @param array{rows: array<string, array{sku: int, usable: int, merged_into: ?int}>, decided: array<string, string>, open: array<string, true>} $state
     * @return array{action: string, count: list<string>}
     */
    private static function simulate(array $p, array &$state): array
    {
        $row = $state['rows'][$p['key']] ?? null;
        if ($row === null && $p['u'] === 1) {
            $state['rows'][$p['key']] = ['sku' => $p['sku'], 'usable' => 1, 'merged_into' => null];
            return ['action' => 'added', 'count' => ['added']];
        }
        $state['open'][$p['key'] . ':' . $p['sku']] = true;
        if ($row === null) {
            return ['action' => 'review', 'count' => ['review_multipack_listing']];
        }
        $count = ['review_on_another_item'];
        if ($row['merged_into'] === $p['sku']) {
            $count[] = 'review_holder_merged';
        }
        if ($row['usable'] === 1) {
            $count[] = 'made_unusable';
            $state['rows'][$p['key']]['usable'] = 0;
        }
        return ['action' => 'review', 'count' => $count];
    }

    /**
     * A real run's change for one (listing, barcode), re-checked under the barcode row's lock; $state follows what was written.
     *
     * @param array{listing: int, sku: int, u: int, key: string} $p
     * @param array{rows: array<string, array{sku: int, usable: int, merged_into: ?int}>, decided: array<string, string>, open: array<string, true>} $state
     * @return array{action: string, count: list<string>}
     */
    private function writeLocked(Db $db, Caller $caller, array $p, array &$state): array
    {
        $key = $p['key'];
        $pk = $key . ':' . $p['sku'];
        $r = $db->one('SELECT b.sku_id, b.is_usable, s.merged_into_sku_id FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.barcode = ? FOR UPDATE', [$key]);
        $state['rows'][$key] = $r === null ? null : ['sku' => (int) $r['sku_id'], 'usable' => (int) $r['is_usable'],
            'merged_into' => $r['merged_into_sku_id'] === null ? null : (int) $r['merged_into_sku_id']];
        if ($state['rows'][$key] === null) {
            unset($state['rows'][$key]);
        }
        // The pair's latest decision, read again under the lock: a person's removal (or decision) committed after the chunk's
        // prefetch must not be undone by re-adding the barcode (I118).
        $latest = $db->value("SELECT decision FROM barcode_review WHERE barcode = ? AND claimant_sku_id = ? AND status = 'decided' ORDER BY id DESC LIMIT 1",
            [$key, $p['sku']]);
        if ($latest !== null) {
            $state['decided'][$pk] = (string) $latest;
        }
        $again = self::plan($p, $state);
        if ($again['action'] !== 'write') {
            return $again;
        }
        if ($db->value('SELECT 1 FROM barcode_review WHERE open_key = ?', [$pk]) !== null) {
            $state['open'][$pk] = true;
            return ['action' => 'none', 'count' => ['in_review']];
        }
        if ($r === null && $p['u'] === 1) {
            $usable = $db->value("SELECT 1 FROM barcode_review WHERE barcode = ? AND status = 'open' LIMIT 1", [$key]) === null ? 1 : 0;
            try {
                $db->exec('INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source, note) VALUES (?, ?, ?, 1, ?, ?)',
                    [$key, $p['sku'], $usable, self::SOURCE, "listing {$p['listing']}" . ($usable === 0 ? ' (in barcode review)' : '')]);
            } catch (\PDOException $e) {
                if (Db::driverCode($e) === 1062) {
                    return ['action' => 'none', 'count' => ['raced']];
                }
                throw $e;
            }
            $state['rows'][$key] = ['sku' => $p['sku'], 'usable' => $usable, 'merged_into' => null];
            return ['action' => 'added', 'count' => ['added']];
        }
        $reason = $r === null ? 'multipack_listing' : 'on_another_item';
        try {
            $db->exec('INSERT INTO barcode_review (barcode, reason, claimant_sku_id, holder_sku_id, listing_id, units_per_item, opened_actor) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$key, $reason, $p['sku'], $r === null ? null : (int) $r['sku_id'], $p['listing'], max(1, $p['u']), $caller->actor]);
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1062) {
                return ['action' => 'none', 'count' => ['in_review']];
            }
            throw $e;
        }
        $state['open'][$pk] = true;
        $count = ['review_' . $reason];
        if ($r !== null && $r['merged_into_sku_id'] !== null && (int) $r['merged_into_sku_id'] === $p['sku']) {
            $count[] = 'review_holder_merged';
        }
        if ($r !== null && (int) $r['is_usable'] === 1) {
            $claimant = (string) ($db->value('SELECT code FROM sku WHERE id = ?', [$p['sku']]) ?? "item {$p['sku']}");
            $db->exec('UPDATE sku_barcode SET is_usable = 0, note = ? WHERE barcode = ?', [mb_substr("also on {$claimant} (listing {$p['listing']}): in barcode review", 0, 255), $key]);
            $state['rows'][$key]['usable'] = 0;
            $count[] = 'made_unusable';
        }
        return ['action' => 'review', 'count' => $count];
    }
}
