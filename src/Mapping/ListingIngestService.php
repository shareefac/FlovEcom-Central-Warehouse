<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;

/**
 * Listing intake, shared by PUT /v1/listings (plan §3, D6, A8) and bin/import_listings.php (the
 * first-match exports): a site's listings (titles, brand, attributes, every barcode, price,
 * perma_link, units sold) become `listing_profile` rows — the matching inputs. It never touches a
 * link (sku, status, u): a variant CW has never seen gets its `unmapped` `channel_listing` row
 * from the DecisionService's insert helper (DecisionService::createUnmappedListings), the only
 * place that creates listing rows.
 *
 * Per listing: `created` (new profile), `updated` (profile_hash changed) or `unchanged`
 * (only pushed_at moves). `identity_changed` says the matching-relevant fields changed
 * (titles, brand, attributes, barcodes: identity_hash), which the matcher flags (§7.3); the
 * stored rules features of that listing are then stale and are cleared, and so is whatever people
 * saw or decided on the old identity: DecisionService::identityChanged moves the map_version of a
 * listing that existed before the call on (design I7), so a stale form or a pending_second
 * decision of that listing is refused with 409 instead of being applied to another product.
 * One transaction per call; at most MAX_LISTINGS listings.
 *
 * The check on arrival (0027, docs/decisions.md M64): the intake only leaves a mark, in the statements it runs anyway. A profile is
 * marked "to check" (arrival_state `queued`, arrival_seq + 1, arrival_channel_id = its store) when it is created, when its identity
 * changed, or when its web address (perma_link) changed: the three things the check's rules read. A push that changes none of them
 * (a price, the units sold, or nothing) marks nothing. No suggestion is made here: the check (CW\Mapping\ArrivalCheck, a
 * command-line job) reads the mark later, in its own transactions. Neither hash reads the mark, and the mark is not part of a
 * profile: the same push hashes the same with or without it.
 *
 * The website state (0028, docs/decisions.md M66; CW\Mapping\SiteState): a listing may carry the optional object `website`
 * (variant_status, product_status, product_type, product_id, is_landing, is_default, product_link): whether the variant is on sale
 * on its website and what the page-heading rule reads. It is read leniently (a wrong type is ignored and counted in the audit
 * row, never a 400) and attached to the profile AFTER both hashes are made, so neither hash and nothing of the identity knows it:
 * a state never makes a push look changed, never wipes the features and never moves a map_version. When a state is supplied it is
 * written in the statement the intake runs anyway, in each of its three branches (created, changed, unchanged), whole (what it
 * leaves out is not known from then on), and its date (`site_state_at`: the push's time, or the export's for an import) is moved
 * on even when it says the same. A push with no usable state leaves the stored one, which grows old by itself. A state older
 * than the stored one (an old file loaded after a newer one) is not written. The mark gains two rules: a state that changed FROM
 * A KNOWN VALUE (one of the values the readers use: SiteState::changed()) marks the profile "to check" whatever its mark was; a
 * state that was not known before marks it only when it already carries a mark (the check has looked at it, or is about to,
 * without knowing whether it is on sale). So the first load of the state marks none of the variants that were there before the
 * check. No site sends `website` yet (the stores' connector is not changed by this); bin/import_listings.php does, from the
 * export files (SiteState::fromExport()).
 *
 * The state-only load (stateOnly(); bin/import_listings.php --state-only): the state columns and nothing else, for the variants
 * that are already there, and only where the line is the stored profile (the same profile_hash).
 */
final class ListingIngestService
{
    public const MAX_LISTINGS = 1000;
    public const MAX_BARCODES = 100;
    public const MAX_ATTRIBUTES_BYTES = 65535;
    /** column => max characters (longer values are cut, not refused: this is a data feed). */
    private const TEXT = ['product_title' => 512, 'variant_title' => 512, 'brand' => 128, 'perma_link' => 512];
    private const IDENTITY = ['product_title', 'variant_title', 'brand', 'attributes', 'barcodes'];
    /** The eight columns of the website state (0028): the seven values in SiteState::COLUMNS' order, then their date. */
    private const STATE_NAMES = [...SiteState::COLUMNS, SiteState::DATE];

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
        $r = $this->ingest($caller, (int) $caller->channelId, $listings);
        // Landing pages on arrival (docs/decisions.md LP4): the products whose profile or website state changed, and the other products
        // of their pages, after the intake's transaction has committed; each mark is a decision of its own, and a failure never costs
        // the push (LandingPages::onArrival() logs it).
        (new LandingPages($this->db))->onArrival((int) $caller->channelId, $r['landing_check']);
        // (a site is answered what it always was: the counts of the website state are in the audit row)
        return array_diff_key($r, ['website' => true, 'landing_check' => true]);
    }

    /**
     * The same for any caller (a site for its own channel, or a system import naming the channel). $stateAt: as of when the
     * website state the listings carry was true (an import: its export's time); not given: now (a push says what is true now).
     * `website` counts the states: written, values ignored for their type, states left because the stored one is newer.
     *
     * @return array{received: int, created: int, updated: int, unchanged: int, listings: list<array<string, mixed>>, website: array{written: int, ignored: int, older: int},
     *     landing_check: list<int>}
     */
    public function ingest(Caller $caller, int $channelId, mixed $listings, ?\DateTimeImmutable $stateAt = null): array
    {
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
        $at = Clock::db($stateAt ?? Clock::now());

        return $this->db->transaction(function (Db $db) use ($caller, $channelId, $profiles, $variants, $at): array {
            [$ids, $created, $preexisting] = $this->listingIds($db, $channelId, $variants);
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
                    'SELECT listing_id, profile_hash, identity_hash, perma_link, arrival_state, ' . implode(', ', self::STATE_NAMES) . ' FROM listing_profile WHERE listing_id IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY listing_id FOR UPDATE',
                    $chunk,
                ) as $r) {
                    $existing[(int) $r['listing_id']] = $r;
                }
            }
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            $out = [];
            $stale = [];
            $queued = 0;
            $landing = [];
            $site = ['written' => 0, 'ignored' => 0, 'older' => 0];
            $mark = ", arrival_state = 'queued', arrival_seq = arrival_seq + 1, arrival_channel_id = ?";
            foreach ($variants as $v) {
                $p = $profiles[$v];
                $id = $ids[$v];
                $old = $existing[$id] ?? null;
                // The website state, when the caller supplied one (M66): written whole, with its date, in the statement of whichever
                // branch this profile takes. Not when the stored one is newer (an old file loaded after a newer one).
                $state = $p['website'];
                $before = $old === null ? null : SiteState::stored($old);
                $site['ignored'] += $p['website_ignored'];
                if ($state !== null && $before !== null && $at < (string) $before['as_of']) {
                    $state = null;
                    $site['older']++;
                }
                $site['written'] += $state === null ? 0 : 1;
                $stateSet = $state === null ? '' : ', ' . implode(' = ?, ', self::STATE_NAMES) . ' = ?';
                $stateValues = $state === null ? [] : [...SiteState::values($state), $at];
                // A state that changed from a known value is looked at again by the check on arrival, whatever the profile's mark; a
                // state that was not known before, only on a profile that carries a mark (the first load of the state marks none
                // of the variants that were there before the check).
                $stateQueues = $state !== null && $old !== null && ($before !== null ? SiteState::changed($before, $state) : $old['arrival_state'] !== null);
                if ($old === null) {
                    $db->exec(
                        'INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, attributes, barcodes, price, perma_link, '
                        . 'units_30d, units_365d, profile_hash, identity_hash, pushed_at, arrival_state, arrival_seq, arrival_channel_id'
                        . ($state === null ? '' : ', ' . implode(', ', self::STATE_NAMES)) . ') '
                        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), 'queued', 1, ?" . str_repeat(', ?', count($stateValues)) . ')',
                        [$id, ...self::columns($p), $channelId, ...$stateValues],
                    );
                    $result = 'created';
                    $identityChanged = true;
                    $queued++;
                } elseif ($old['profile_hash'] !== $p['profile_hash']) {
                    $identityChanged = $old['identity_hash'] !== $p['identity_hash'];
                    // The check on arrival looks again when what its rules read has changed: the identity, the web address, or
                    // the website state (the rule above).
                    $toCheck = $identityChanged || $old['perma_link'] !== $p['perma_link'] || $stateQueues;
                    // Features were extracted from the old titles/attributes: stale once the identity changes.
                    $db->exec(
                        'UPDATE listing_profile SET product_title = ?, variant_title = ?, brand = ?, attributes = ?, barcodes = ?, price = ?, '
                        . 'perma_link = ?, units_30d = ?, units_365d = ?, profile_hash = ?, identity_hash = ?, pushed_at = UTC_TIMESTAMP(6)'
                        . ($identityChanged ? ', features = NULL, features_version = NULL' : '') . $stateSet
                        . ($toCheck ? $mark : '') . ' WHERE listing_id = ?',
                        [...self::columns($p), ...$stateValues, ...($toCheck ? [$channelId] : []), $id],
                    );
                    $queued += $toCheck ? 1 : 0;
                    $result = 'updated';
                } else {
                    $db->exec('UPDATE listing_profile SET pushed_at = UTC_TIMESTAMP(6)' . $stateSet . ($stateQueues ? $mark : '') . ' WHERE listing_id = ?',
                        [...$stateValues, ...($stateQueues ? [$channelId] : []), $id]);
                    $queued += $stateQueues ? 1 : 0;
                    $result = 'unchanged';
                    $identityChanged = false;
                }
                $counts[$result]++;
                $out[] = ['variant_id' => $v, 'listing_id' => $id, 'result' => $result, 'identity_changed' => $identityChanged];
                // What the landing-page check looks at again (LP4): a new or changed profile, or a website state that says something new.
                if ($result !== 'unchanged' || ($state !== null && ($before === null || SiteState::changed($before, $state)))) {
                    $landing[] = $id;
                }
                if ($identityChanged && isset($preexisting[$id])) {
                    $stale[] = $id; // a listing people may have looked at or decided on (a row created now: nobody has)
                }
            }
            // Design I7: decisions and screens taken on the old identity are stale (DecisionService, the only
            // writer of channel_listing, moves their map_version on).
            $bumped = $stale === [] ? 0 : DecisionService::identityChanged($db, $stale);
            Audit::write($db, $caller, 'listing.profiles', 'channel', (string) $channelId, null,
                $counts + ['received' => count($variants), 'new_listings' => $created, 'identity_changed' => $bumped, 'arrival_queued' => $queued,
                    'website' => $site['written'], 'website_ignored' => $site['ignored'], 'website_older' => $site['older']]);
            return ['received' => count($variants)] + $counts + ['listings' => $out, 'website' => $site, 'landing_check' => $landing];
        });
    }

    /**
     * The state-only load (M66; bin/import_listings.php --state-only): the website state of variants that are ALREADY THERE, and
     * nothing else. For each listing (validated as a push's):
     *  - the warehouse has no such variant, or it has no profile: `unknown`, left (no listing is ever created here);
     *  - its profile_hash, worked out as the intake works it out, is not the stored one (the line is not the stored profile: a
     *    title, a barcode, a price, the address or the units sold differ): `differs`, left. Writing a state taken from another
     *    version of the variant beside the stored one would date it wrongly, and bringing the profile up to the line is the
     *    ordinary import's work, with everything that follows from an identity change;
     *  - the line carries no usable state: `no_state`, left;
     *  - the stored state is newer than $stateAt: `older`, left;
     *  - else the eight state columns are written, and nothing else of the row: `first` (no state was stored), `changed` (one of
     *    the values the readers use differs) or `same` (the date moves on all the same).
     * It never touches the mark of the check on arrival (it queues nothing), the features, either hash, pushed_at, or a listing.
     * Without $apply it reads (no lock, no transaction) and writes NOTHING: no state and no audit row. With $apply: one transaction,
     * the profiles locked in listing order (the intake's order), one audit row (`listing.website_state`) with the counts.
     *
     * @return array{received: int, written: int, first: int, changed: int, same: int, differs: int, unknown: int, no_state: int, older: int, ignored: int,
     *     listings: list<array{variant_id: string, result: string}>} `written` = first + changed + same (what an apply writes, or would)
     */
    public function stateOnly(Caller $caller, int $channelId, mixed $listings, \DateTimeImmutable $stateAt, bool $apply): array
    {
        if (!is_array($listings) || $listings === [] || !array_is_list($listings)) {
            throw new CwException('bad_listings', 'listings must be a non-empty list', 400);
        }
        if (count($listings) > self::MAX_LISTINGS) {
            throw new CwException('bad_listings', 'send at most ' . self::MAX_LISTINGS . ' listings per call', 413);
        }
        $profiles = [];
        foreach ($listings as $i => $l) {
            $p = self::profile($l, $i);
            if (isset($profiles[$p['variant_id']])) {
                throw new CwException('bad_listings', "variant {$p['variant_id']} appears twice", 400, ['field' => "listings[{$i}].variant_id"]);
            }
            $profiles[$p['variant_id']] = $p;
        }
        $variants = array_map('strval', array_keys($profiles));
        sort($variants, SORT_STRING);
        $at = Clock::db($stateAt);

        $work = function (Db $db) use ($caller, $channelId, $profiles, $variants, $at, $apply): array {
            $ids = self::storedIds($db, $channelId, $variants);
            foreach ($variants as $v) {
                // (a variant stored under another letter case is the same variant: asked of the database, one by one, as the intake does)
                $ids[$v] ??= ($id = $db->value('SELECT id FROM channel_listing WHERE channel_id = ? AND external_variant_id = ?', [$channelId, $v])) === null ? null : (int) $id;
            }
            $existing = [];
            $listingIds = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));
            sort($listingIds);
            foreach (array_chunk($listingIds, 500) as $chunk) {
                foreach ($db->all('SELECT listing_id, profile_hash, ' . implode(', ', self::STATE_NAMES) . ' FROM listing_profile WHERE listing_id IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY listing_id' . ($apply ? ' FOR UPDATE' : ''), $chunk) as $r) {
                    $existing[(int) $r['listing_id']] = $r;
                }
            }
            $n = ['received' => count($variants), 'written' => 0, 'first' => 0, 'changed' => 0, 'same' => 0, 'differs' => 0, 'unknown' => 0, 'no_state' => 0, 'older' => 0, 'ignored' => 0];
            $out = [];
            foreach ($variants as $v) {
                $p = $profiles[$v];
                $old = $ids[$v] === null ? null : ($existing[$ids[$v]] ?? null);
                $before = $old === null ? null : SiteState::stored($old);
                $n['ignored'] += $p['website_ignored'];
                $result = match (true) {
                    $old === null => 'unknown',
                    $old['profile_hash'] !== $p['profile_hash'] => 'differs',
                    $p['website'] === null => 'no_state',
                    $before !== null && $at < (string) $before['as_of'] => 'older',
                    $before === null => 'first',
                    default => SiteState::changed($before, $p['website']) ? 'changed' : 'same',
                };
                $n[$result]++;
                $out[] = ['variant_id' => $v, 'result' => $result];
                if (!in_array($result, ['first', 'changed', 'same'], true)) {
                    continue;
                }
                $n['written']++;
                if ($apply) {
                    // The eight state columns and nothing else, and only while the row is still the profile the line was compared with.
                    $db->exec('UPDATE listing_profile SET ' . implode(' = ?, ', self::STATE_NAMES) . ' = ? WHERE listing_id = ? AND profile_hash = ?',
                        [...SiteState::values($p['website']), $at, (int) $ids[$v], $p['profile_hash']]);
                }
            }
            if ($apply) {
                Audit::write($db, $caller, 'listing.website_state', 'channel', (string) $channelId, null, $n + ['state_at' => $at]);
            }
            return $n + ['listings' => $out];
        };
        return $apply ? $this->db->transaction($work) : $work($this->db);
    }

    /**
     * The listing ids the channel has for these variants, by the text they are stored under (a variant asked for in another letter
     * case than it is stored is not in the answer: the callers ask the database for those one by one).
     *
     * @param list<string> $variants
     * @return array<string, int> stored variant text => listing id
     */
    private static function storedIds(Db $db, int $channelId, array $variants): array
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
        return $stored;
    }

    /**
     * Listing ids by requested variant. A variant CW has never seen gets its `unmapped` row from
     * DecisionService::createUnmappedListings. What counts as "the same variant" is the column's
     * collation (today _ai_ci: 'ABC' is the row 'abc'), so a variant without an exact match is
     * looked up by the database itself, one by one.
     *
     * @param list<string> $variants
     * @return array{0: array<string, int>, 1: int, 2: array<int, true>} variant => listing id, number of rows created,
     *         the ids of the rows that existed before this call
     */
    private function listingIds(Db $db, int $channelId, array $variants): array
    {
        $read = static fn (): array => self::storedIds($db, $channelId, $variants);
        $before = $read();
        $preexisting = array_fill_keys(array_values($before), true);
        $created = DecisionService::createUnmappedListings($db, $channelId, $variants);
        $stored = $created === 0 ? $before : $read();
        $out = [];
        foreach ($variants as $v) {
            $id = $stored[$v] ?? $db->value('SELECT id FROM channel_listing WHERE channel_id = ? AND external_variant_id = ?', [$channelId, $v]);
            if ($id === null) {
                throw new \RuntimeException("listing row for variant {$v} could not be created");
            }
            $out[$v] = (int) $id;
        }
        return [$out, $created, $preexisting];
    }

    /**
     * One line of a first-match export (tools/first_match/export.php) in the PUT /v1/listings shape.
     * The export's attribute list [{attr_id, name, value, is_variable}] is kept whole, as
     * {"items": [...]}: the Normalizer reads exactly that list (is_variable decides between values).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function fromExport(array $row): array
    {
        $attrs = $row['attributes'] ?? null;
        return [
            'variant_id' => $row['variant_id'] ?? null,
            'product_title' => $row['product_title'] ?? null,
            'variant_title' => $row['variant_title'] ?? null,
            'brand' => $row['brand'] ?? null,
            'attributes' => is_array($attrs) && $attrs !== [] ? ['items' => array_values($attrs)] : null,
            'barcodes' => $row['barcodes'] ?? null,
            'price' => $row['price'] ?? null,
            'perma_link' => $row['permalink'] ?? $row['perma_link'] ?? null,
            'units_30d' => $row['units_30d'] ?? null,
            'units_365d' => $row['units_365d'] ?? null,
        ];
    }

    /**
     * Validates one listing (as profile() does inside a push) and returns null or the error.
     *
     * @return array{error: string, message: string}|null
     */
    public static function check(mixed $listing, int $index = 0): ?array
    {
        try {
            self::profile($listing, $index);
            return null;
        } catch (CwException $e) {
            return ['error' => $e->errorCode, 'message' => $e->getMessage()];
        }
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
        // The website state (M66), attached only now: both hashes are made, and neither has read it. Read leniently: a value of
        // the wrong type is ignored and counted, the listing is never refused for it.
        $site = SiteState::parse($l[SiteState::KEY] ?? null);
        $p['website'] = $site['state'];
        $p['website_ignored'] = $site['ignored'];
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
