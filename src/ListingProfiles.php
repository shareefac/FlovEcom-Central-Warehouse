<?php

declare(strict_types=1);

namespace CW;

/**
 * PUT /v1/listings (plan §3, D6): a site pushes what it knows about its listings (titles,
 * brand, attributes, every barcode, price, perma_link, units sold). This writes
 * `listing_profile` ONLY — the matching inputs. It never touches a link (sku, status, u):
 * that is the DecisionService's job. A variant CW has never seen gets its `unmapped`
 * `channel_listing` row first (the profile's FK needs it; the same thing a sale of an unknown
 * variant does, D32).
 *
 * Per listing: `created` (new profile), `updated` (profile_hash changed) or `unchanged`
 * (only pushed_at moves). `identity_changed` says the matching-relevant fields changed
 * (titles, brand, attributes, barcodes: identity_hash), which the matcher flags (§7.3).
 * One transaction per call; at most MAX_LISTINGS listings.
 */
final class ListingProfiles
{
    public const MAX_LISTINGS = 1000;
    public const MAX_BARCODES = 100;
    public const MAX_ATTRIBUTES_BYTES = 65535;
    /** column => max characters (longer values are cut, not refused: this is a data feed). */
    private const TEXT = ['product_title' => 512, 'variant_title' => 512, 'brand' => 128, 'perma_link' => 512];
    private const IDENTITY = ['product_title', 'variant_title', 'brand', 'attributes', 'barcodes'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array{received: int, created: int, updated: int, unchanged: int, listings: list<array<string, mixed>>}
     */
    public function push(Caller $caller, mixed $listings): array
    {
        if (!$caller->isChannel()) {
            throw new CwException('channel_required', 'listings are pushed by a site', 403);
        }
        if (!is_array($listings) || $listings === [] || !array_is_list($listings)) {
            throw new CwException('bad_listings', 'listings must be a non-empty list', 400);
        }
        if (count($listings) > self::MAX_LISTINGS) {
            throw new CwException('bad_listings', 'send at most ' . self::MAX_LISTINGS . ' listings per call', 413);
        }
        $profiles = [];
        foreach ($listings as $i => $l) {
            $p = self::profile($l, $i);
            $v = $p['variant_id'];
            if (isset($profiles[$v])) {
                throw new CwException('bad_listings', "variant {$v} appears twice", 400, ['field' => "listings[{$i}].variant_id"]);
            }
            $profiles[$v] = $p;
        }
        $variants = array_map('strval', array_keys($profiles));
        sort($variants, SORT_STRING);
        $channelId = (int) $caller->channelId;

        return $this->db->transaction(function (Db $db) use ($caller, $channelId, $profiles, $variants): array {
            [$ids, $created] = $this->listingIds($db, $channelId, $variants);
            $seen = [];
            foreach ($ids as $v => $id) {
                if (isset($seen[$id])) {
                    throw new CwException('bad_listings', "variants {$seen[$id]} and {$v} are the same listing", 400);
                }
                $seen[$id] = (string) $v;
            }
            $existing = [];
            $listingIds = array_values($ids);
            sort($listingIds);
            foreach (array_chunk($listingIds, 500) as $chunk) {
                foreach ($db->all(
                    'SELECT listing_id, profile_hash, identity_hash FROM listing_profile WHERE listing_id IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY listing_id FOR UPDATE',
                    $chunk,
                ) as $r) {
                    $existing[(int) $r['listing_id']] = $r;
                }
            }
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            $out = [];
            foreach ($variants as $v) {
                $p = $profiles[$v];
                $id = $ids[$v];
                $old = $existing[$id] ?? null;
                if ($old === null) {
                    $db->exec(
                        'INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, attributes, barcodes, price, perma_link, '
                        . 'units_30d, units_365d, profile_hash, identity_hash, pushed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
                        [$id, ...self::columns($p)],
                    );
                    $result = 'created';
                    $identityChanged = true;
                } elseif ($old['profile_hash'] !== $p['profile_hash']) {
                    $db->exec(
                        'UPDATE listing_profile SET product_title = ?, variant_title = ?, brand = ?, attributes = ?, barcodes = ?, price = ?, '
                        . 'perma_link = ?, units_30d = ?, units_365d = ?, profile_hash = ?, identity_hash = ?, pushed_at = UTC_TIMESTAMP(6) '
                        . 'WHERE listing_id = ?',
                        [...self::columns($p), $id],
                    );
                    $result = 'updated';
                    $identityChanged = $old['identity_hash'] !== $p['identity_hash'];
                } else {
                    $db->exec('UPDATE listing_profile SET pushed_at = UTC_TIMESTAMP(6) WHERE listing_id = ?', [$id]);
                    $result = 'unchanged';
                    $identityChanged = false;
                }
                $counts[$result]++;
                $out[] = ['variant_id' => $v, 'listing_id' => $id, 'result' => $result, 'identity_changed' => $identityChanged];
            }
            Audit::write($db, $caller, 'listing.profiles', 'channel', (string) $channelId, null,
                $counts + ['received' => count($variants), 'new_listings' => $created]);
            return ['received' => count($variants)] + $counts + ['listings' => $out];
        });
    }

    /**
     * Listing ids by requested variant, creating the `unmapped` row of a variant CW has never
     * seen. What counts as "the same variant" is the column's collation (today _ai_ci: 'ABC' is
     * the row 'abc'), so a variant without an exact match is resolved by the database itself:
     * INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) returns the existing row's id
     * or the new one (affected rows 1 = created).
     *
     * @param list<string> $variants
     * @return array{0: array<string, int>, 1: int} variant => listing id, number of rows created
     */
    private function listingIds(Db $db, int $channelId, array $variants): array
    {
        $stored = [];
        foreach (array_chunk($variants, 500) as $chunk) {
            foreach ($db->all(
                'SELECT id, external_variant_id FROM channel_listing WHERE channel_id = ? AND external_variant_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')',
                [$channelId, ...$chunk],
            ) as $r) {
                $stored[(string) $r['external_variant_id']] = (int) $r['id'];
            }
        }
        $out = [];
        $created = 0;
        foreach ($variants as $v) {
            if (isset($stored[$v])) {
                $out[$v] = $stored[$v];
                continue;
            }
            $st = $db->run(
                'INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                [$channelId, $v],
            );
            $created += $st->rowCount() === 1 ? 1 : 0;
            $out[$v] = (int) $db->pdo()->lastInsertId();
        }
        return [$out, $created];
    }

    /** @return array<string, mixed> the normalised profile + its two hashes */
    private static function profile(mixed $l, int $i): array
    {
        if (!is_array($l) || array_is_list($l) && $l !== []) {
            throw new CwException('bad_listings', "listings[{$i}] must be an object", 400);
        }
        $f = "listings[{$i}]";
        $v = $l['variant_id'] ?? null;
        $v = is_int($v) ? (string) $v : $v;
        if (!is_string($v) || $v === '' || strlen($v) > 64 || preg_match('/^[\x21-\x7e]+$/', $v) !== 1) {
            throw new CwException('bad_listings', "{$f}.variant_id must be 1-64 printable characters", 400, ['field' => "{$f}.variant_id"]);
        }
        $p = ['variant_id' => $v];
        foreach (self::TEXT as $k => $max) {
            $s = $l[$k] ?? null;
            if ($s !== null && !is_string($s)) {
                throw new CwException('bad_listings', "{$f}.{$k} must be a string", 400, ['field' => "{$f}.{$k}"]);
            }
            $s = $s === null ? null : trim($s);
            $p[$k] = $s === null || $s === '' ? null : mb_substr($s, 0, $max);
        }
        $attrs = $l['attributes'] ?? null;
        if ($attrs !== null && (!is_array($attrs) || (array_is_list($attrs) && $attrs !== []))) {
            throw new CwException('bad_listings', "{$f}.attributes must be an object", 400, ['field' => "{$f}.attributes"]);
        }
        $p['attributes'] = $attrs === null || $attrs === [] ? null : Idempotency::canonical($attrs);
        if ($p['attributes'] !== null && strlen(Idempotency::json($p['attributes'])) > self::MAX_ATTRIBUTES_BYTES) {
            throw new CwException('bad_listings', "{$f}.attributes is too large", 400, ['field' => "{$f}.attributes"]);
        }
        $codes = $l['barcodes'] ?? null;
        if ($codes !== null) {
            if (!is_array($codes) || !array_is_list($codes) || count($codes) > self::MAX_BARCODES) {
                throw new CwException('bad_listings', "{$f}.barcodes must be a list of at most " . self::MAX_BARCODES . ' strings', 400, ['field' => "{$f}.barcodes"]);
            }
            $set = [];
            foreach ($codes as $c) {
                $c = is_int($c) ? (string) $c : $c;
                if (!is_string($c) || strlen(trim($c)) > 64 || preg_match('/^[\x21-\x7e]*$/', trim($c)) !== 1) {
                    throw new CwException('bad_listings', "{$f}.barcodes must hold printable codes of at most 64 characters", 400, ['field' => "{$f}.barcodes"]);
                }
                if (trim($c) !== '') {
                    $set[trim($c)] = true;
                }
            }
            $codes = array_map('strval', array_keys($set));
            sort($codes, SORT_STRING);
        }
        $p['barcodes'] = $codes === null || $codes === [] ? null : $codes;
        $price = $l['price'] ?? null;
        if ($price !== null) {
            if (is_string($price) && is_numeric($price)) {
                $price = (float) $price;
            }
            // Rounded to the column's 2 dp first, then checked: 99999999.999 rounds to
            // 100000000.00, which DECIMAL(10,2) cannot hold (a strict-mode 500 before, R16).
            $rounded = is_int($price) || is_float($price) ? round((float) $price, 2) : null;
            if ($rounded === null || !is_finite($rounded) || $price < 0 || $rounded > 99_999_999.99) {
                throw new CwException('bad_listings', "{$f}.price must be a number between 0 and 99999999.99", 400, ['field' => "{$f}.price"]);
            }
            $price = number_format($rounded, 2, '.', '');
        }
        $p['price'] = $price;
        foreach (['units_30d', 'units_365d'] as $k) {
            $n = $l[$k] ?? null;
            if ($n !== null && (!is_int($n) || $n < 0 || $n > 4_294_967_295)) {
                throw new CwException('bad_listings', "{$f}.{$k} must be an integer >= 0", 400, ['field' => "{$f}.{$k}"]);
            }
            $p[$k] = $n;
        }
        $identity = array_intersect_key($p, array_flip(self::IDENTITY));
        $p['identity_hash'] = hash('sha256', Idempotency::canonicalJson($identity));
        $p['profile_hash'] = hash('sha256', Idempotency::canonicalJson(array_diff_key($p, ['identity_hash' => true])));
        return $p;
    }

    /** @param array<string, mixed> $p @return list<mixed> in column order */
    private static function columns(array $p): array
    {
        return [
            $p['product_title'], $p['variant_title'], $p['brand'],
            $p['attributes'] === null ? null : Idempotency::json($p['attributes']),
            $p['barcodes'] === null ? null : Idempotency::json($p['barcodes']),
            $p['price'], $p['perma_link'], $p['units_30d'], $p['units_365d'], $p['profile_hash'], $p['identity_hash'],
        ];
    }
}
