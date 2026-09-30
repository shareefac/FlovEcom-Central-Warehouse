<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Reservations;
use CW\Stock;

/**
 * THE ONLY code that changes channel_listing.sku_id / units_per_item / status (plan §2.2 "changed
 * only by DecisionService", §7.1; design A.1 I1). Every change is one transaction:
 *
 *   (link outcomes) lock the reservations of the listing's unlinked held/allocated units
 *   -> SELECT channel_listing ... FOR UPDATE, check the expected map_version (409 on mismatch)
 *   -> read the items involved FOR SHARE (policy, merged)
 *   -> write match_decision (append-only), then, if it applies now: update channel_listing
 *      (map_version + 1), close/open listing_map_history, settle the listing's open proposal,
 *      audit_log, Reservations::adoptUnlinkedUnits (R4: units sold while unlinked enter the
 *      buckets) and Stock::listingChanged (the feed; Stock stays the only writer of stock_change).
 *
 * Actions: link, unlink, new_item (mints an item from the listing's profile + features, then
 * links), ignore, reject (match_reject; the proposal stays open for another choice), suggest
 * (unmapped -> suggested, the only system action) and merge_skus (moves every listing of one item
 * to another and marks it merged). mintAndLink() is the Vape and Go seed: mint + an applied link.
 *
 * Two-person rule (plan §7.1): a decision that links/unlinks/ignores a listing on a protected item
 * (sell_policy <> legacy) or links one to it, any units_per_item <> 1 (and any change of a listing
 * linked with u <> 1: relink, new item, unlink, ignore), a link to an item this listing was rejected
 * for (or to an item such an item was merged into), and every merge_skus is stored `pending_second`;
 * a DIFFERENT staff user with role mapping_lead approves (applies) or withdraws it. A merge that would
 * contradict a match_reject is refused (409 rejected_pair). Proposals in the Conflict band (or a
 * listing whose open proposal is Conflict) may only be decided by a mapping_lead. Roles: mapper and
 * mapping_lead decide; bulk decisions (bulk_batch_id) are mapping_lead only; a system caller may
 * only suggest. At most one pending decision per listing.
 *
 * What the person saw (design I7): the listing's map_version (which also moves when the site changes
 * the listing's identity, identityChanged()) and the proposal (a decision on a listing with an open
 * proposal must name it; approving is refused once another proposal is open): 409 otherwise. A
 * decision settles only the proposal it named. Relinking a listing whose units are in flight on a
 * counted item queues a recount of both items (remapCorrection, design A.9 rule 5).
 *
 * Lock order (docs/decisions.md M4): reservation rows -> channel_listing (X) -> sku (S, X only
 * for the item merged away) -> stock_balance (adoption) -> feed clock. Nothing is locked after
 * the feed clock: every decision, history, proposal and audit row is written before it.
 */
final class DecisionService
{
    public const ACTIONS = ['link', 'unlink', 'new_item', 'ignore', 'reject', 'suggest', 'merge_skus'];
    public const ROLES = ['viewer', 'mapper', 'mapping_lead', 'warehouse', 'manager', 'admin'];
    public const DECIDERS = ['mapper', 'mapping_lead'];
    public const LEAD = 'mapping_lead';
    public const LINKED = ['mapped', 'quarantined'];
    /** Largest units_per_item a link may carry ("N x" listings; the column is SMALLINT). */
    public const MAX_UNITS = 1000;
    public const MAX_REASON = 500;
    /** Identity-card fields of a minted item (plan §2.1) => max length (strings) or kind. */
    public const CARD_FIELDS = ['name', 'brand', 'strength_mg', 'nic_type', 'line', 'form', 'flavour', 'volume_ml', 'puffs', 'pack_units'];

    private const LINK_OUTCOMES = ['link', 'new_item'];
    /**
     * CTE `fam`: the ids of an item's family, the item and every item merged into it (merged_into_sku_id
     * chains; a merged item is never merged again, so there are no cycles).
     */
    private const FAMILY = 'WITH RECURSIVE fam (id) AS (SELECT CAST(? AS UNSIGNED) UNION ALL '
        . 'SELECT s.id FROM sku s JOIN fam ON s.merged_into_sku_id = fam.id) ';

    private readonly Stock $stock;
    private readonly Reservations $res;

    public function __construct(private readonly Db $db, ?Stock $stock = null, ?Reservations $res = null)
    {
        $this->stock = $stock ?? new Stock($db);
        $this->res = $res ?? new Reservations($db, $this->stock);
    }

    // ==========================================================================================
    // Public API
    // ==========================================================================================

    /**
     * One decision on one listing.
     *
     * @param array<string, mixed> $req {action, listing_id, expected_map_version, sku_id?, units_per_item?,
     *        proposal_id?, reason?, card?: array (new_item overrides), merge_from_sku_id?, bulk_batch_id?}
     * @return array<string, mixed> {decision_id, action, state, listing_id, map_version, status, sku_id,
     *         units_per_item, needs_second, adopted}
     */
    public function decide(Caller $caller, array $req): array
    {
        $r = self::request($req);
        return $this->db->transaction(fn (Db $db): array => $this->run($caller, $r, null));
    }

    /**
     * The seed mint (plan §7.1 "one central ID per published, non-placeholder Vape and Go listing"):
     * mints an item from $card (origin vpg_mint) and links the listing to it with an applied `link`
     * decision, in one transaction. mapping_lead only; the listing must not be linked.
     *
     * @param array<string, mixed> $card identity card (see card())
     * @return array<string, mixed> as decide(), plus sku_code
     */
    public function mintAndLink(Caller $caller, int $listingId, int $expectedMapVersion, array $card, ?string $bulkBatchId, ?string $reason = null): array
    {
        $r = self::request(['action' => 'link', 'listing_id' => $listingId, 'expected_map_version' => $expectedMapVersion,
            'units_per_item' => 1, 'bulk_batch_id' => $bulkBatchId, 'reason' => $reason, 'card' => $card, 'sku_id' => null], true);
        return $this->db->transaction(fn (Db $db): array => $this->run($caller, $r, 'vpg_mint'));
    }

    /**
     * A mapping_lead other than the decider applies a pending_second decision. The listing must
     * still be at the map_version the decider saw (else 409: withdraw it and decide again).
     *
     * @return array<string, mixed> as decide()
     */
    public function approve(Caller $caller, int $decisionId, ?string $reason = null): array
    {
        $reason = self::reason($reason);
        return $this->db->transaction(function (Db $db) use ($caller, $decisionId, $reason): array {
            $staff = $this->staff($caller);
            if ($staff === null || $staff['role'] !== self::LEAD) {
                throw new CwException('lead_required', 'only a mapping_lead can approve a pending decision', 403);
            }
            $d = $this->pendingDecision($decisionId);
            if ((int) $d['decided_by'] === $staff['id']) {
                throw new CwException('same_person', 'the second approval must come from another person', 403);
            }
            if (in_array($d['action'], self::LINK_OUTCOMES, true)) {
                $this->lockUnlinkedUnitReservations((int) $d['listing_id']);
            }
            $l = $this->lockListing((int) $d['listing_id']);
            $d = $this->pendingDecision($decisionId); // re-read under the listing lock
            if ($d['action'] === 'merge_skus') {
                $this->lockListingsOf((int) $d['merge_from_sku_id']);
            }
            if ($l['map_version'] !== (int) $d['expected_map_version']) {
                throw new CwException('map_version_conflict', 'the listing changed since this decision was made; withdraw it and decide again', 409,
                    ['current_map_version' => $l['map_version'], 'expected_map_version' => (int) $d['expected_map_version']]);
            }
            // I7: the decision was taken against the proposal it names (or against none). A later run may have
            // superseded it (Proposals::add does not change map_version): the approver would apply a decision
            // nobody took against the proposal that is open now, and settle that one unseen.
            $open = $this->openProposal($l['id']);
            $named = $d['proposal_id'] === null ? null : (int) $d['proposal_id'];
            if (($open['id'] ?? null) !== $named) {
                throw new CwException('proposal_changed', 'the listing has another proposal since this decision was made; withdraw it and decide again', 409,
                    ['decision_proposal_id' => $named, 'open_proposal_id' => $open['id'] ?? null]);
            }
            $r = [
                'action' => (string) $d['action'], 'listing_id' => (int) $d['listing_id'],
                'expected_map_version' => (int) $d['expected_map_version'],
                'sku_id' => $d['sku_id'] === null ? null : (int) $d['sku_id'],
                'units_per_item' => $d['units_per_item'] === null ? null : (int) $d['units_per_item'],
                'merge_from_sku_id' => $d['merge_from_sku_id'] === null ? null : (int) $d['merge_from_sku_id'],
                'proposal_id' => $d['proposal_id'] === null ? null : (int) $d['proposal_id'],
                'card' => $d['detail'] === null ? [] : (array) (json_decode((string) $d['detail'], true)['card'] ?? []),
                'reason' => $reason, 'bulk_batch_id' => $d['bulk_batch_id'],
            ];
            $target = $this->validate($r, $l, null, false);
            $now = $this->now();
            if ($r['action'] === 'new_item') {
                $target['sku'] = $this->mint($this->card($l['id'], $r['card']), 'new_item', $l['id']);
            }
            $n = $this->db->exec(
                "UPDATE match_decision SET state = 'applied', applied_at = ?, second_by = ? WHERE id = ? AND state = 'pending_second'",
                [$now, $staff['id'], $decisionId],
            );
            if ($n !== 1) {
                throw new CwException('not_pending', 'the decision is no longer pending', 409);
            }
            Audit::write($db, $caller, 'mapping.approve', 'listing', (string) $l['id'], null, [
                'decision_id' => $decisionId, 'action' => $r['action'], 'decided_by' => (int) $d['decided_by'], 'reason' => $reason,
                'needs_second' => json_decode((string) ($d['needs_second'] ?? '[]'), true),
            ]);
            return $this->effect($caller, $decisionId, $r, $l, $target, $now);
        });
    }

    /**
     * Withdraws a pending_second decision: by a mapping_lead other than the decider, or by the
     * decider. Nothing else changes; the listing is free for a new decision.
     *
     * @return array{decision_id: int, state: string, listing_id: int}
     */
    public function withdraw(Caller $caller, int $decisionId, ?string $reason = null): array
    {
        $reason = self::reason($reason);
        return $this->db->transaction(function (Db $db) use ($caller, $decisionId, $reason): array {
            $staff = $this->staff($caller);
            if ($staff === null) {
                throw new CwException('staff_required', 'a person withdraws a decision', 403);
            }
            $d = $this->pendingDecision($decisionId);
            $own = (int) $d['decided_by'] === $staff['id'];
            if (!$own && $staff['role'] !== self::LEAD) {
                throw new CwException('lead_required', 'only the decider or a mapping_lead can withdraw a pending decision', 403);
            }
            $this->lockListing((int) $d['listing_id']);
            $n = $db->exec("UPDATE match_decision SET state = 'withdrawn', second_by = ? WHERE id = ? AND state = 'pending_second'", [$staff['id'], $decisionId]);
            if ($n !== 1) {
                throw new CwException('not_pending', 'the decision is no longer pending', 409);
            }
            Audit::write($db, $caller, 'mapping.withdraw', 'listing', (string) $d['listing_id'], null,
                ['decision_id' => $decisionId, 'action' => (string) $d['action'], 'decided_by' => (int) $d['decided_by'], 'own' => $own, 'reason' => $reason]);
            return ['decision_id' => $decisionId, 'state' => 'withdrawn', 'listing_id' => (int) $d['listing_id']];
        });
    }

    /**
     * Creates the `unmapped` channel_listing rows of variants CW has never seen (plan §3: "the
     * DecisionService creates the unmapped listing row"). INSERT IGNORE: a row that exists (or is
     * created concurrently) is left as it is. Used by PUT /v1/listings, the import tool and sales of
     * unknown variants (Reservations). Runs inside the caller's transaction. Returns rows created.
     *
     * @param list<string> $variantIds
     */
    public static function createUnmappedListings(Db $db, int $channelId, array $variantIds): int
    {
        $created = 0;
        foreach (array_chunk(array_values(array_unique($variantIds)), 1000) as $chunk) {
            $have = $db->column(
                'SELECT external_variant_id FROM channel_listing WHERE channel_id = ? AND external_variant_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')',
                [$channelId, ...$chunk],
            );
            foreach (array_diff($chunk, array_map('strval', $have)) as $v) {
                $created += $db->exec('INSERT IGNORE INTO channel_listing (channel_id, external_variant_id) VALUES (?, ?)', [$channelId, $v]);
            }
        }
        return $created;
    }

    /**
     * The identity the people deciding on these listings saw has changed (listing_profile.identity_hash:
     * titles, brand, attributes or barcodes), so every screen drawn and every decision taken before is
     * stale (design I7): their map_version moves on, which the existing checks turn into 409s (decide()
     * refuses a stale form; approve() refuses a pending_second decision, whose approver would otherwise
     * apply e.g. u = 10 to a listing the site has since renamed to a 5-pack). The link itself does not
     * change. Called by ListingIngestService inside its transaction, after it has locked the profiles;
     * listing rows are updated in id order. Returns the rows changed.
     *
     * @param list<int> $listingIds
     */
    public static function identityChanged(Db $db, array $listingIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $listingIds)));
        sort($ids);
        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $n += $db->exec('UPDATE channel_listing SET map_version = map_version + 1 WHERE id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id', $chunk);
        }
        return $n;
    }

    /**
     * The identity card of an item minted from a listing: name and brand from its profile, identity
     * fields from its features (listing_profile.features, CW\Matching\Normalizer), then $overrides
     * (what a person typed on the review screen; a key present with null clears the field).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> CARD_FIELDS => value
     */
    public function card(int $listingId, array $overrides = []): array
    {
        $p = $this->db->one('SELECT product_title, variant_title, brand, features FROM listing_profile WHERE listing_id = ?', [$listingId]);
        $features = $p !== null && $p['features'] !== null ? (array) json_decode((string) $p['features'], true) : [];
        return self::cardFrom($p ?? [], $features, $overrides);
    }

    /**
     * @param array<string, mixed> $profile listing_profile columns (product_title, variant_title, brand)
     * @param array<string, mixed> $features Normalizer features
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function cardFrom(array $profile, array $features, array $overrides = []): array
    {
        $str = static function (mixed $v, int $max): ?string {
            if (!is_string($v) && !is_int($v) && !is_float($v)) {
                return null;
            }
            $v = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        $num = static function (mixed $v, float $min, float $max, bool $exclusiveMin = false): ?float {
            if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
                return null;
            }
            $f = round((float) $v, 2);
            return (!is_finite($f) || $f < $min || ($exclusiveMin && $f <= $min) || $f > $max) ? null : $f;
        };
        $int = static function (mixed $v, int $min, int $max): ?int {
            if (is_float($v) && floor($v) === $v) {
                $v = (int) $v;
            }
            if (is_string($v) && ctype_digit($v)) {
                $v = (int) $v;
            }
            return is_int($v) && $v >= $min && $v <= $max ? $v : null;
        };
        $tokens = static function (mixed ...$lists): ?string {
            $out = [];
            foreach ($lists as $l) {
                foreach (is_array($l) ? $l : [] as $t) {
                    if (is_string($t) || is_int($t) || is_float($t)) {
                        $out[(string) $t] = true;
                    }
                }
            }
            return $out === [] ? null : implode(' ', array_keys($out));
        };
        $card = [
            'name' => $str($profile['variant_title'] ?? null, 255) ?? $str($profile['product_title'] ?? null, 255) ?? $str($features['title'] ?? null, 255),
            'brand' => $str($profile['brand'] ?? null, 128) ?? $str($features['brand_raw'] ?? null, 128),
            'strength_mg' => $num($features['strength_mg'] ?? null, 0, 9999.99),
            'nic_type' => $str($features['nic_type'] ?? null, 32),
            'line' => $str($tokens($features['line_tokens'] ?? null, $features['line_numbers'] ?? null, $features['line_modifiers'] ?? null), 128),
            'form' => $str($features['form'] ?? null, 32),
            'flavour' => $str($tokens($features['flavour_tokens'] ?? null), 255),
            'volume_ml' => $num($features['volume_ml'] ?? null, 0, 999999.99, true),
            'puffs' => $int($features['puffs'] ?? null, 1, 4_294_967_295),
            'pack_units' => $int($features['pack_units'] ?? null, 1, 65_535),
        ];
        foreach ($overrides as $k => $v) {
            if (!in_array($k, self::CARD_FIELDS, true)) {
                throw new CwException('bad_card', "unknown identity field {$k}", 400, ['field' => "card.{$k}"]);
            }
            $clean = match ($k) {
                'name' => $str($v, 255),
                'brand', 'line' => $str($v, 128),
                'nic_type', 'form' => $str($v, 32),
                'flavour' => $str($v, 255),
                'strength_mg' => $num($v, 0, 9999.99),
                'volume_ml' => $num($v, 0, 999999.99, true),
                'puffs' => $int($v, 1, 4_294_967_295),
                'pack_units' => $int($v, 1, 65_535),
            };
            if ($v !== null && $clean === null) {
                throw new CwException('bad_card', "identity field {$k} is not valid", 400, ['field' => "card.{$k}"]);
            }
            $card[$k] = $clean;
        }
        if ($card['name'] === null) {
            throw new CwException('name_required', 'the new item needs a name (the listing has no title)', 422);
        }
        return $card;
    }

    // ==========================================================================================
    // The decision transaction
    // ==========================================================================================

    /**
     * @param array<string, mixed> $r normalised request
     * @param string|null $mint 'vpg_mint': mint an item from $r['card'] and link to it (mintAndLink)
     * @return array<string, mixed>
     */
    private function run(Caller $caller, array $r, ?string $mint): array
    {
        $staff = $this->staff($caller);
        $action = $r['action'];
        if ($staff === null && $action !== 'suggest') {
            throw new CwException('staff_required', 'only a person can make this decision', 403);
        }
        if ($staff !== null && !in_array($staff['role'], self::DECIDERS, true)) {
            throw new CwException('role_not_allowed', "role {$staff['role']} cannot make mapping decisions", 403);
        }
        if (($r['bulk_batch_id'] !== null || $mint !== null) && ($staff === null || $staff['role'] !== self::LEAD)) {
            throw new CwException('lead_required', 'bulk decisions are made by a mapping_lead', 403);
        }

        if ($mint !== null || in_array($action, self::LINK_OUTCOMES, true)) {
            $this->lockUnlinkedUnitReservations($r['listing_id']);
        }
        $l = $this->lockListing($r['listing_id']);
        if ($l['map_version'] !== $r['expected_map_version']) {
            throw new CwException('map_version_conflict', 'the listing changed since it was shown; reload it', 409,
                ['current_map_version' => $l['map_version'], 'expected_map_version' => $r['expected_map_version'],
                    'status' => $l['status'], 'sku_id' => $l['sku_id']]);
        }
        $pending = $this->db->value('SELECT id FROM match_decision WHERE pending_listing_id = ?', [$l['id']]);
        if ($pending !== null) {
            throw new CwException('pending_second_exists', 'a decision on this listing is waiting for a second person', 409,
                ['decision_id' => (int) $pending]);
        }
        if ($mint !== null && in_array($l['status'], self::LINKED, true)) {
            throw new CwException('already_linked', 'the listing is already linked', 409);
        }
        $open = $this->openProposal($l['id']);
        if ($r['proposal_id'] !== null && ($open === null || $open['id'] !== $r['proposal_id'])) {
            $p = $this->db->one('SELECT listing_id, status FROM match_proposal WHERE id = ?', [$r['proposal_id']]);
            if ($p === null || (int) $p['listing_id'] !== $l['id']) {
                throw new CwException('proposal_mismatch', 'the proposal is not one of this listing', 422);
            }
            throw new CwException('proposal_closed', "the proposal is {$p['status']}", 409);
        }
        if ($action !== 'suggest' && $open !== null && $open['band'] === 'Conflict' && $staff !== null && $staff['role'] !== self::LEAD) {
            throw new CwException('lead_required', 'a listing with a Conflict proposal is decided by a mapping_lead', 403);
        }
        if ($action !== 'suggest' && $r['proposal_id'] === null && $open !== null) {
            // I7: a person decides against the proposal they saw. A screen drawn before this proposal arrived
            // (a proposal on a mapped listing does not change map_version) must not settle it unseen.
            throw new CwException('proposal_changed', 'the listing has an open proposal this decision does not name; reload it', 409,
                ['open_proposal_id' => $open['id'], 'band' => $open['band']]);
        }
        if ($action === 'merge_skus' && $l['linked'] && $l['sku_id'] === $r['merge_from_sku_id']) {
            $this->lockListingsOf($l['sku_id']);
        }

        $target = $this->validate($r, $l, $open, true);
        $needs = $target['needs_second'];
        $state = $needs === [] ? 'applied' : 'pending_second';
        if ($mint !== null && $state !== 'applied') {
            throw new CwException('mint_needs_second', 'a seed mint must be a plain one-person link', 409, ['needs_second' => $needs]);
        }
        $now = $this->now();
        $card = null;
        if ($mint !== null || $action === 'new_item') {
            $card = $mint !== null ? self::cardFrom([], [], $r['card']) : $this->card($l['id'], $r['card']);
            if ($state === 'applied') {
                $target['sku'] = $this->mint($card, $mint ?? 'new_item', $l['id']);
            }
        }
        $skuId = match ($action) {
            'link', 'new_item' => $target['sku']['id'] ?? null,
            'reject', 'merge_skus' => $r['sku_id'],
            default => null,
        };
        $units = in_array($action, self::LINK_OUTCOMES, true) ? $target['units'] : null;
        $decisionId = $this->db->insert(
            'INSERT INTO match_decision (listing_id, proposal_id, action, sku_id, units_per_item, merge_from_sku_id, prev_sku_id, '
            . 'prev_units_per_item, prev_status, decided_by, actor, needs_second, state, reason, expected_map_version, bulk_batch_id, detail, applied_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $l['id'], $r['proposal_id'], $action, $skuId, $units, $r['merge_from_sku_id'],
                $l['linked'] ? $l['sku_id'] : null, $l['linked'] ? $l['units_per_item'] : null, $l['status'],
                $staff['id'] ?? null, $caller->actor, $needs === [] ? null : Idempotency::json($needs), $state, $r['reason'],
                $r['expected_map_version'], $r['bulk_batch_id'],
                $card === null ? null : Idempotency::json(['card' => $card] + ($mint !== null ? ['origin' => $mint] : [])),
                $state === 'applied' ? $now : null,
            ],
        );
        if ($state === 'pending_second') {
            Audit::write($this->db, $caller, 'mapping.' . $action, 'listing', (string) $l['id'], null, [
                'decision_id' => $decisionId, 'state' => $state, 'needs_second' => $needs, 'map_version' => $l['map_version'],
                'from' => self::linkOf($l), 'sku_id' => $r['sku_id'], 'units_per_item' => $units, 'merge_from_sku_id' => $r['merge_from_sku_id'],
                'proposal_id' => $r['proposal_id'], 'reason' => $r['reason'],
            ]);
            return ['decision_id' => $decisionId, 'action' => $action, 'state' => $state, 'listing_id' => $l['id'],
                'map_version' => $l['map_version'], 'status' => $l['status'], 'sku_id' => $l['linked'] ? $l['sku_id'] : null,
                'units_per_item' => $l['units_per_item'], 'needs_second' => $needs, 'adopted' => 0];
        }
        return $this->effect($caller, $decisionId, $r, $l, $target, $now);
    }

    /**
     * Checks what the action needs and works out the two-person reasons. Reads the items FOR SHARE
     * (in id order; the item merged away FOR UPDATE).
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $l locked listing
     * @param array<string, mixed>|null $open the listing's open proposal
     * @return array{needs_second: list<string>, sku: ?array{id: int, code: string, policy: string}, units: int, current: ?array{id: int, code: string, policy: string}, from: ?array{id: int, code: string, policy: string}}
     */
    private function validate(array $r, array $l, ?array $open, bool $fresh): array
    {
        $action = $r['action'];
        $ids = [];
        if ($l['linked']) {
            $ids[] = $l['sku_id'];
        }
        if (in_array($action, ['link', 'reject', 'merge_skus'], true) && $r['sku_id'] !== null) {
            $ids[] = $r['sku_id'];
        }
        if ($action === 'merge_skus') {
            $ids[] = $r['merge_from_sku_id'];
        }
        $skus = $this->lockSkus($ids, $action === 'merge_skus' ? $r['merge_from_sku_id'] : null);
        $current = $l['linked'] ? ($skus[$l['sku_id']] ?? null) : null;
        $needs = [];
        $target = null;
        $from = null;
        $units = $r['units_per_item'] ?? 1;
        // A verified multiple (the listing is linked with u <> 1, which took two people) is not undone by one:
        // relinking it (to u = 1 or another item), minting a new item for it, unlinking or ignoring it needs a
        // second person too (plan §7.1 "units_per_item <> 1"; design A.9 rule 4 "multiples decisions").
        $multiple = $l['linked'] && $l['units_per_item'] !== 1;

        switch ($action) {
            case 'link':
                if ($r['sku_id'] === null) { // mintAndLink: the item is minted after these checks
                    if ($l['linked']) {
                        throw new CwException('already_linked', 'the listing is already linked', 409);
                    }
                    break;
                }
                $target = $skus[$r['sku_id']] ?? throw new CwException('unknown_sku', 'no such item', 404);
                if ($target['merged_into'] !== null) {
                    throw new CwException('sku_merged', "item {$target['code']} was merged into another item", 409, ['merged_into_sku_id' => $target['merged_into']]);
                }
                if ($fresh && $l['linked'] && $l['sku_id'] === $target['id'] && $l['units_per_item'] === $units && $l['status'] === 'mapped') {
                    throw new CwException('no_change', 'the listing is already linked to this item', 409);
                }
                if ($target['policy'] !== 'legacy' || ($current !== null && $current['id'] !== $target['id'] && $current['policy'] !== 'legacy')) {
                    $needs[] = 'protected_sku';
                }
                if ($units !== 1 || $multiple) {
                    $needs[] = 'units_per_item';
                }
                if ($this->rejectedAs($l['id'], $target['id']) !== []) {
                    $needs[] = 'previously_rejected';
                }
                break;
            case 'new_item':
                if ($current !== null && $current['policy'] !== 'legacy') {
                    $needs[] = 'protected_sku';
                }
                if ($units !== 1 || $multiple) {
                    $needs[] = 'units_per_item';
                }
                break;
            case 'unlink':
                if (!$l['linked']) {
                    throw new CwException('not_linked', 'the listing is not linked', 409);
                }
                if ($current !== null && $current['policy'] !== 'legacy') {
                    $needs[] = 'protected_sku';
                }
                if ($multiple) {
                    $needs[] = 'units_per_item';
                }
                break;
            case 'ignore':
                if ($fresh && $l['status'] === 'ignored') {
                    throw new CwException('no_change', 'the listing is already ignored', 409);
                }
                if ($current !== null && $current['policy'] !== 'legacy') {
                    $needs[] = 'protected_sku';
                }
                if ($multiple) {
                    $needs[] = 'units_per_item';
                }
                break;
            case 'reject':
                $target = $skus[$r['sku_id']] ?? throw new CwException('unknown_sku', 'no such item', 404);
                if ($l['linked'] && $l['sku_id'] === $target['id']) {
                    throw new CwException('reject_current_link', 'the listing is linked to this item: unlink it instead', 409);
                }
                break;
            case 'suggest':
                if ($l['status'] !== 'unmapped') {
                    throw new CwException('no_change', "only an unmapped listing can be suggested (it is {$l['status']})", 409);
                }
                if ($open === null) {
                    throw new CwException('no_open_proposal', 'the listing has no open proposal', 409);
                }
                break;
            case 'merge_skus':
                $target = $skus[$r['sku_id']] ?? throw new CwException('unknown_sku', 'no such item (kept)', 404);
                $from = $skus[$r['merge_from_sku_id']] ?? throw new CwException('unknown_sku', 'no such item (merged away)', 404);
                foreach ([$target, $from] as $s) {
                    if ($s['merged_into'] !== null) {
                        throw new CwException('sku_merged', "item {$s['code']} was already merged", 409);
                    }
                    if ($s['policy'] !== 'legacy') {
                        // Its counted stock would have to move with it: v1 merges uncounted (legacy) items only.
                        throw new CwException('protected_merge', "item {$s['code']} is protected ({$s['policy']}): merge it after a recount", 409);
                    }
                }
                if (!$l['linked'] || $l['sku_id'] !== $from['id']) {
                    throw new CwException('anchor_not_on_item', 'the listing named must be linked to the item merged away', 409);
                }
                // match_reject says "this listing is not this item". A merge must not link a listing to an item it
                // was rejected for (a listing of the merged item that rejected the kept one), nor leave a listing
                // of the kept item linked to the item it rejected (now the same one): a person sorts those first.
                $rejected = $this->mergeRejects($from['id'], $target['id']);
                if ($rejected !== []) {
                    throw new CwException('rejected_pair', 'listing(s) ' . implode(', ', $rejected) . ' were rejected for one of these items: '
                        . 'relink or unlink them before merging', 409, ['listing_ids' => $rejected]);
                }
                $needs[] = 'merge';
                break;
        }
        if (in_array($action, self::LINK_OUTCOMES, true) && ($units < 1 || $units > self::MAX_UNITS)) {
            throw new CwException('bad_units', 'units_per_item must be 1..' . self::MAX_UNITS, 400);
        }
        return ['needs_second' => $needs, 'sku' => $target, 'units' => $units, 'current' => $current, 'from' => $from];
    }

    /**
     * Applies a decision whose row exists and is `applied`.
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $l locked listing (before)
     * @param array<string, mixed> $target validate() result (+ minted sku)
     * @return array<string, mixed>
     */
    private function effect(Caller $caller, int $decisionId, array $r, array $l, array $target, string $now): array
    {
        $action = $r['action'];
        $id = $l['id'];
        $adopted = 0;
        $after = ['status' => $l['status'], 'sku_id' => $l['linked'] ? $l['sku_id'] : null, 'units_per_item' => $l['units_per_item']];
        $version = $l['map_version'];
        $detail = ['decision_id' => $decisionId, 'from' => self::linkOf($l), 'proposal_id' => $r['proposal_id'], 'reason' => $r['reason']]
            + ($r['bulk_batch_id'] !== null ? ['bulk_batch_id' => $r['bulk_batch_id']] : []);

        switch ($action) {
            case 'link':
            case 'new_item':
                $sku = $target['sku'];
                $u = $target['units'];
                $correction = $this->remapCorrection($decisionId, $l, $target['current'], $sku, $u);
                $this->closePeriod($id, $decisionId, $now);
                $this->setListing($id, $sku['id'], $u, 'mapped');
                $this->openPeriod($id, $sku['id'], $u, $decisionId, $now);
                $this->settleProposal($r['proposal_id']);
                $after = ['status' => 'mapped', 'sku_id' => $sku['id'], 'units_per_item' => $u];
                $version++;
                Audit::write($this->db, $caller, 'mapping.' . $action, 'listing', (string) $id, null,
                    $detail + ['to' => $after + ['sku_code' => $sku['code']], 'map_version' => $version] + $correction);
                $adopted = $this->res->adoptUnlinkedUnits($caller, $id)['adopted'];
                $this->stock->listingChanged($id, 'link');
                break;
            case 'unlink':
            case 'ignore':
                $correction = $this->remapCorrection($decisionId, $l, $target['current'], null, null);
                $status = $action === 'ignore' ? 'ignored' : ($this->openProposal($id) !== null ? 'suggested' : 'unmapped');
                if ($l['linked']) {
                    $this->closePeriod($id, $decisionId, $now);
                }
                $this->setListing($id, null, 1, $status);
                if ($action === 'ignore') {
                    $this->settleProposal($r['proposal_id']);
                }
                $after = ['status' => $status, 'sku_id' => null, 'units_per_item' => 1];
                $version++;
                Audit::write($this->db, $caller, 'mapping.' . $action, 'listing', (string) $id, null,
                    $detail + ['to' => $after, 'map_version' => $version] + $correction);
                $this->stock->listingChanged($id, $l['linked'] ? 'link' : 'status');
                break;
            case 'suggest':
                $this->setListing($id, null, $l['units_per_item'], 'suggested');
                $after['status'] = 'suggested';
                $version++;
                Audit::write($this->db, $caller, 'mapping.suggest', 'listing', (string) $id, null, $detail + ['to' => $after, 'map_version' => $version]);
                $this->stock->listingChanged($id, 'status');
                break;
            case 'reject':
                $staffId = $caller->staffUserId;
                if ($this->db->value('SELECT id FROM match_reject WHERE listing_id = ? AND sku_id = ?', [$id, $r['sku_id']]) === null) {
                    $this->db->exec('INSERT INTO match_reject (listing_id, sku_id, decided_by, decision_id, `at`) VALUES (?, ?, ?, ?, ?)',
                        [$id, $r['sku_id'], $staffId, $decisionId, $now]);
                }
                Audit::write($this->db, $caller, 'mapping.reject', 'listing', (string) $id, null,
                    $detail + ['sku_id' => $r['sku_id'], 'sku_code' => $target['sku']['code'] ?? null]);
                break;
            case 'merge_skus':
                $keep = $target['sku'];
                $from = $target['from'];
                $moved = $this->merge($decisionId, $from['id'], $keep['id'], $now);
                $this->settleProposal($r['proposal_id']);
                $version = $moved[$id] ?? $version;
                $after = ['status' => $l['status'], 'sku_id' => $keep['id'], 'units_per_item' => $l['units_per_item']];
                Audit::write($this->db, $caller, 'mapping.merge_skus', 'sku', (string) $from['id'], null, $detail + [
                    'kept_sku_id' => $keep['id'], 'kept_code' => $keep['code'], 'merged_sku_id' => $from['id'], 'merged_code' => $from['code'],
                    'listings' => array_keys($moved),
                ]);
                foreach (array_keys($moved) as $lid) {
                    $this->stock->listingChanged((int) $lid, 'link');
                }
                break;
        }
        return ['decision_id' => $decisionId, 'action' => $action, 'state' => 'applied', 'listing_id' => $id, 'map_version' => $version,
            'status' => $after['status'], 'sku_id' => $after['sku_id'], 'units_per_item' => $after['units_per_item'], 'needs_second' => [],
            'adopted' => $adopted] + (isset($target['sku']['code']) && in_array($action, self::LINK_OUTCOMES, true) ? ['sku_code' => $target['sku']['code']] : []);
    }

    /**
     * Moves every listing linked to $fromSku to $keepSku (same u and status), one history period
     * each, and marks $fromSku merged. Listings are locked in id order (the anchor is already
     * held). Their sold units keep their sale-time snapshot (the old item), like any relink.
     *
     * @return array<int, int> listing id => its new map_version
     */
    private function merge(int $decisionId, int $fromSku, int $keepSku, string $now): array
    {
        $ids = array_map('intval', $this->db->column(
            "SELECT id FROM channel_listing WHERE sku_id = ? AND status IN ('mapped', 'quarantined') ORDER BY id",
            [$fromSku],
        ));
        $moved = [];
        foreach ($ids as $lid) {
            $row = $this->lockListing($lid);
            if (!$row['linked'] || $row['sku_id'] !== $fromSku) {
                continue;
            }
            if ($this->db->value('SELECT id FROM match_decision WHERE pending_listing_id = ? AND id <> ?', [$lid, $decisionId]) !== null) {
                throw new CwException('pending_second_exists', "listing {$lid} of the merged item has a pending decision", 409, ['listing_id' => $lid]);
            }
            $this->closePeriod($lid, $decisionId, $now);
            $this->db->exec('UPDATE channel_listing SET sku_id = ?, map_version = map_version + 1 WHERE id = ?', [$keepSku, $lid]);
            $this->openPeriod($lid, $keepSku, $row['units_per_item'], $decisionId, $now);
            $moved[$lid] = $row['map_version'] + 1;
        }
        $this->db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$keepSku, $fromSku]);
        return $moved;
    }

    // ==========================================================================================
    // helpers
    // ==========================================================================================

    /** @return array{id: int, role: string}|null null for a system caller */
    private function staff(Caller $caller): ?array
    {
        if ($caller->staffUserId === null) {
            if ($caller->isChannel()) {
                throw new CwException('staff_required', 'mapping decisions are made by staff', 403);
            }
            return null;
        }
        $s = $this->db->one('SELECT id, role, is_active FROM staff_user WHERE id = ?', [$caller->staffUserId]);
        if ($s === null || (int) $s['is_active'] !== 1) {
            throw new CwException('staff_not_allowed', 'unknown or inactive staff user', 403);
        }
        return ['id' => (int) $s['id'], 'role' => (string) $s['role']];
    }

    /**
     * Locks, before the listing row, the reservations of the units the listing sold while it was
     * unlinked, in the order adoptUnlinkedUnits locks them (the sale path locks its reservation
     * before the listing row, so the link transaction must not hold the listing while it waits
     * for a reservation). A sale that commits between this read and the listing lock is picked up
     * by adoptUnlinkedUnits itself; it no longer holds a lock by then.
     */
    private function lockUnlinkedUnitReservations(int $listingId): void
    {
        $channelId = $this->db->value('SELECT channel_id FROM channel_listing WHERE id = ?', [$listingId]);
        if ($channelId === null) {
            return;
        }
        $refs = array_map('strval', $this->db->column(
            "SELECT DISTINCT r.order_ref FROM reservation_unit ru JOIN reservation r ON r.id = ru.reservation_id "
            . "WHERE ru.listing_id = ? AND ru.sku_id IS NULL AND ru.state IN ('held', 'allocated')",
            [$listingId],
        ));
        sort($refs, SORT_STRING);
        foreach ($refs as $ref) {
            $this->db->one('SELECT id FROM reservation WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [(int) $channelId, $ref]);
        }
    }

    /** @return array{id: int, channel_id: int, variant_id: string, sku_id: ?int, units_per_item: int, status: string, map_version: int, linked: bool} */
    private function lockListing(int $listingId): array
    {
        $l = $this->db->one(
            'SELECT id, channel_id, external_variant_id, sku_id, units_per_item, status, map_version FROM channel_listing WHERE id = ? FOR UPDATE',
            [$listingId],
        );
        if ($l === null) {
            throw new CwException('unknown_listing', 'no such listing', 404);
        }
        return ['id' => (int) $l['id'], 'channel_id' => (int) $l['channel_id'], 'variant_id' => (string) $l['external_variant_id'],
            'sku_id' => $l['sku_id'] === null ? null : (int) $l['sku_id'], 'units_per_item' => (int) $l['units_per_item'],
            'status' => (string) $l['status'], 'map_version' => (int) $l['map_version'],
            'linked' => in_array($l['status'], self::LINKED, true) && $l['sku_id'] !== null];
    }

    /**
     * Locks (in id order, before any item row: listing -> sku) every listing linked to $skuId, for
     * a merge. merge() re-reads the set under the item's X lock, so a link made in between is
     * seen (and locked) there.
     */
    private function lockListingsOf(int $skuId): void
    {
        foreach ($this->db->column("SELECT id FROM channel_listing WHERE sku_id = ? AND status IN ('mapped', 'quarantined') ORDER BY id", [$skuId]) as $lid) {
            $this->lockListing((int) $lid);
        }
    }

    /**
     * @param list<int|null> $ids
     * @return array<int, array{id: int, code: string, policy: string, merged_into: ?int}>
     */
    private function lockSkus(array $ids, ?int $exclusive): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($v): bool => $v !== null)));
        sort($ids);
        $out = [];
        foreach ($ids as $sid) {
            $s = $this->db->one(
                'SELECT id, code, sell_policy, merged_into_sku_id FROM sku WHERE id = ? ' . ($sid === $exclusive ? 'FOR UPDATE' : 'FOR SHARE'),
                [$sid],
            );
            if ($s !== null) {
                $out[(int) $s['id']] = ['id' => (int) $s['id'], 'code' => (string) $s['code'], 'policy' => (string) $s['sell_policy'],
                    'merged_into' => $s['merged_into_sku_id'] === null ? null : (int) $s['merged_into_sku_id']];
            }
        }
        return $out;
    }

    /** @return array{id: int, band: string, proposed_sku_id: ?int}|null */
    private function openProposal(int $listingId): ?array
    {
        $p = $this->db->one('SELECT id, band, proposed_sku_id FROM match_proposal WHERE open_listing_id = ?', [$listingId]);
        return $p === null ? null : ['id' => (int) $p['id'], 'band' => (string) $p['band'],
            'proposed_sku_id' => $p['proposed_sku_id'] === null ? null : (int) $p['proposed_sku_id']];
    }

    /**
     * The match_reject rows that say listing $listingId is not item $skuId: a reject of the item itself or
     * of any item merged into it (the same product since the merge).
     *
     * @return list<int> the rejected item ids
     */
    private function rejectedAs(int $listingId, int $skuId): array
    {
        return array_map('intval', $this->db->column(
            self::FAMILY . 'SELECT r.sku_id FROM match_reject r WHERE r.listing_id = ? AND r.sku_id IN (SELECT id FROM fam) ORDER BY r.sku_id',
            [$skuId, $listingId],
        ));
    }

    /**
     * Listings a merge of $fromSku into $keepSku would contradict: linked to one side and rejected for the
     * other side's family.
     *
     * @return list<int> listing ids
     */
    private function mergeRejects(int $fromSku, int $keepSku): array
    {
        $out = [];
        foreach ([[$fromSku, $keepSku], [$keepSku, $fromSku]] as [$linkedTo, $rejectedFamily]) {
            array_push($out, ...array_map('intval', $this->db->column(
                self::FAMILY . 'SELECT DISTINCT r.listing_id FROM match_reject r JOIN channel_listing cl ON cl.id = r.listing_id '
                . "WHERE cl.sku_id = ? AND cl.status IN ('mapped', 'quarantined') AND r.sku_id IN (SELECT id FROM fam)",
                [$rejectedFamily, $linkedTo],
            )));
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** @return array<string, mixed> */
    private function pendingDecision(int $decisionId): array
    {
        $d = $this->db->one('SELECT * FROM match_decision WHERE id = ?', [$decisionId]);
        if ($d === null) {
            throw new CwException('unknown_decision', 'no such decision', 404);
        }
        if ($d['state'] !== 'pending_second') {
            throw new CwException('not_pending', "the decision is {$d['state']}", 409);
        }
        return $d;
    }

    /**
     * Inserts a new item and sets its code in the same transaction (D4).
     *
     * @param array<string, mixed> $card
     * @return array{id: int, code: string, policy: string, merged_into: null}
     */
    private function mint(array $card, string $origin, int $listingId): array
    {
        $id = $this->db->insert(
            'INSERT INTO sku (name, brand, strength_mg, nic_type, line, form, flavour, volume_ml, puffs, pack_units, origin, origin_listing_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$card['name'], $card['brand'], self::dec($card['strength_mg']), $card['nic_type'], $card['line'], $card['form'], $card['flavour'],
                self::dec($card['volume_ml']), $card['puffs'], $card['pack_units'], $origin, $listingId],
        );
        $code = sprintf('CW-%06d', $id);
        $this->db->exec('UPDATE sku SET code = ? WHERE id = ?', [$code, $id]);
        return ['id' => $id, 'code' => $code, 'policy' => 'legacy', 'merged_into' => null];
    }

    private function setListing(int $id, ?int $sku, int $units, string $status): void
    {
        $this->db->exec(
            'UPDATE channel_listing SET sku_id = ?, units_per_item = ?, status = ?, map_version = map_version + 1 WHERE id = ?',
            [$sku, $units, $status, $id],
        );
    }

    private function closePeriod(int $listingId, int $decisionId, string $now): void
    {
        $this->db->exec(
            'UPDATE listing_map_history SET valid_to = ?, closed_by_decision_id = ? WHERE open_listing_id = ?',
            [$now, $decisionId, $listingId],
        );
    }

    private function openPeriod(int $listingId, int $sku, int $units, int $decisionId, string $now): void
    {
        $this->db->exec(
            'INSERT INTO listing_map_history (listing_id, sku_id, units_per_item, valid_from, decision_id) VALUES (?, ?, ?, ?, ?)',
            [$listingId, $sku, $units, $now, $decisionId],
        );
    }

    /**
     * Settles the proposal the decision named (run() and approve() checked that it is the listing's open
     * one); a decision that named none settles nothing (there was no open proposal to see).
     */
    private function settleProposal(?int $proposalId): void
    {
        if ($proposalId !== null) {
            $this->db->exec("UPDATE match_proposal SET status = 'decided' WHERE id = ? AND status = 'open'", [$proposalId]);
        }
    }

    /**
     * Design A.9 rule 5 / A.12 "Reversal": units that moved under the link being closed keep their sale-time
     * item (I14), so when the link changes (another item, another u, or none) while units of the listing are
     * held or allocated on the old item, or were shipped from it under this link, and either item is
     * counted (sell_policy <> legacy), a recount of both items is queued: one count_review row per item and
     * warehouse, source `remap_correction`, deduplicated per decision. Legacy items are uncounted estimates
     * (their next count settles them), so nothing is queued for them. The paired correction movement itself
     * is the count gate's job (not built yet).
     *
     * @param array<string, mixed> $l the locked listing (before)
     * @param array{id: int, code: string, policy: string}|null $old the item it is linked to now
     * @param array{id: int, code: string, policy: string}|null $new the item it will be linked to (null: none)
     * @return array{remap_correction?: array<string, mixed>} for the audit row
     */
    private function remapCorrection(int $decisionId, array $l, ?array $old, ?array $new, ?int $newUnits): array
    {
        if (!$l['linked'] || $old === null || ($new !== null && $new['id'] === $old['id'] && $newUnits === $l['units_per_item'])) {
            return [];
        }
        if ($old['policy'] === 'legacy' && ($new === null || $new['policy'] === 'legacy')) {
            return [];
        }
        $period = $this->db->one('SELECT id, valid_from FROM listing_map_history WHERE open_listing_id = ?', [$l['id']]);
        // Shipped under this link: sold since the period began (CW's clock) or dispatched since (the site's).
        $since = $period['valid_from'] ?? '1970-01-01';
        $units = $this->db->all(
            'SELECT channel_id, unit_id, warehouse_id, state, units_per_item FROM reservation_unit WHERE listing_id = ? AND sku_id = ? '
            . "AND (state IN ('held', 'allocated') OR (state = 'shipped' AND (created_at >= ? OR dispatched_at >= ?))) ORDER BY warehouse_id, channel_id, unit_id",
            [$l['id'], $old['id'], $since, $since],
        );
        if ($units === []) {
            return [];
        }
        $byWarehouse = [];
        foreach ($units as $u) {
            $w = (int) $u['warehouse_id'];
            $byWarehouse[$w]['units'][] = (string) $u['unit_id'];
            $byWarehouse[$w]['qty'] = ($byWarehouse[$w]['qty'] ?? 0) + (int) $u['units_per_item'];
            $byWarehouse[$w]['states'][(string) $u['state']] = ($byWarehouse[$w]['states'][(string) $u['state']] ?? 0) + 1;
        }
        foreach ($byWarehouse as &$x) {
            ksort($x['states']);
        }
        unset($x);
        $items = array_values(array_filter([$old, $new], static fn (?array $s): bool => $s !== null));
        $items = array_values(array_unique(array_map(static fn (array $s): int => $s['id'], $items)));
        sort($items);
        $opened = 0;
        foreach ($byWarehouse as $w => $x) {
            foreach ($items as $sku) {
                $this->stock->openCountReview($w, $sku, 'remap_correction', null, "listing {$l['id']}", [
                    'decision_id' => $decisionId, 'listing_id' => $l['id'], 'map_history_id' => $period === null ? null : (int) $period['id'],
                    'from_sku_id' => $old['id'], 'from_units_per_item' => $l['units_per_item'], 'to_sku_id' => $new['id'] ?? null,
                    'to_units_per_item' => $new === null ? null : $newUnits, 'central_units' => $x['qty'], 'states' => $x['states'],
                    'unit_ids' => array_slice($x['units'], 0, 100), 'unit_count' => count($x['units']),
                ], "remap:{$decisionId}:{$sku}:{$w}");
                $opened++;
            }
        }
        return ['remap_correction' => ['units' => count($units), 'count_reviews' => $opened, 'sku_ids' => $items]];
    }

    private function now(): string
    {
        return (string) $this->db->value('SELECT UTC_TIMESTAMP(6)');
    }

    /** @param array<string, mixed> $l @return array<string, mixed> */
    private static function linkOf(array $l): array
    {
        return ['status' => $l['status'], 'sku_id' => $l['linked'] ? $l['sku_id'] : null, 'units_per_item' => $l['units_per_item'],
            'map_version' => $l['map_version']];
    }

    private static function dec(?float $v): ?string
    {
        return $v === null ? null : number_format($v, 2, '.', '');
    }

    private static function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > self::MAX_REASON) {
            throw new CwException('bad_reason', 'reason is at most ' . self::MAX_REASON . ' characters', 400);
        }
        return $reason === '' ? null : $reason;
    }

    /**
     * Validates and normalises a request.
     *
     * @param array<string, mixed> $req
     * @return array{action: string, listing_id: int, expected_map_version: int, sku_id: ?int, units_per_item: ?int, proposal_id: ?int,
     *               reason: ?string, card: array<string, mixed>, merge_from_sku_id: ?int, bulk_batch_id: ?string}
     */
    private static function request(array $req, bool $mint = false): array
    {
        $action = $req['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            throw new CwException('bad_action', 'action must be one of ' . implode(', ', self::ACTIONS), 400);
        }
        $id = static function (string $k, bool $required, int $min = 1) use ($req): ?int {
            $v = $req[$k] ?? null;
            if ($v === null) {
                if ($required) {
                    throw new CwException('bad_request', "{$k} is required", 400, ['field' => $k]);
                }
                return null;
            }
            if (is_string($v) && preg_match('/^\d{1,10}$/', $v) === 1) {
                $v = (int) $v;
            }
            if (!is_int($v) || $v < $min || $v > 4_294_967_295) {
                throw new CwException('bad_request', "{$k} must be an integer >= {$min}", 400, ['field' => $k]);
            }
            return $v;
        };
        $r = [
            'action' => $action,
            'listing_id' => (int) $id('listing_id', true),
            'expected_map_version' => (int) $id('expected_map_version', true, 0),
            'sku_id' => $id('sku_id', !$mint && in_array($action, ['link', 'reject', 'merge_skus'], true)),
            'units_per_item' => $id('units_per_item', false),
            'proposal_id' => $id('proposal_id', false),
            'merge_from_sku_id' => $id('merge_from_sku_id', $action === 'merge_skus'),
            'reason' => null,
            'card' => [],
            'bulk_batch_id' => null,
        ];
        $reason = $req['reason'] ?? null;
        if ($reason !== null && !is_string($reason)) {
            throw new CwException('bad_reason', 'reason must be a string', 400);
        }
        $r['reason'] = self::reason($reason);
        $card = $req['card'] ?? [];
        if (!is_array($card) || (array_is_list($card) && $card !== [])) {
            throw new CwException('bad_card', 'card must be an object', 400);
        }
        if ($card !== [] && $action !== 'new_item' && !$mint) {
            throw new CwException('bad_card', 'card is for new_item only', 400);
        }
        $r['card'] = $card;
        $bulk = $req['bulk_batch_id'] ?? null;
        if ($bulk !== null && (!is_string($bulk) || preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $bulk) !== 1)) {
            throw new CwException('bad_request', 'bulk_batch_id must be 1-64 of [A-Za-z0-9._:-]', 400, ['field' => 'bulk_batch_id']);
        }
        $r['bulk_batch_id'] = $bulk;
        if (!in_array($action, self::LINK_OUTCOMES, true) && $r['units_per_item'] !== null) {
            throw new CwException('bad_request', 'units_per_item is for link / new_item only', 400, ['field' => 'units_per_item']);
        }
        if ($action === 'merge_skus' && $r['sku_id'] === $r['merge_from_sku_id']) {
            throw new CwException('bad_request', 'an item cannot be merged into itself', 400);
        }
        if ($r['units_per_item'] !== null && $r['units_per_item'] > self::MAX_UNITS) {
            throw new CwException('bad_units', 'units_per_item must be 1..' . self::MAX_UNITS, 400);
        }
        return $r;
    }
}
