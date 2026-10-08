<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Admin\ApprovalRules;
use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Reservations;
use CW\Staff\StaffRoles;
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
 * links), ignore, reject (match_reject; the proposal stays open for another choice, except a merge
 * suggestion it answers, M34), suggest (unmapped -> suggested, the only system action), merge_skus
 * (moves every listing of one item to another, marks it merged and moves its stock there, M31-M32)
 * and split (the undo of a merge: back to the former item with every listing the merge moved and the
 * stock that came with it, or the listing alone to a new item, M33, M40). mintAndLink() is the Vape
 * and Go seed: mint + an applied link.
 * decideGroup() takes the decisions of one duplicate group (the Duplicates screen) in one transaction.
 *
 * Two-person rule (plan §7.1; M31 for merges): a decision that links/unlinks/ignores a listing on a
 * protected item (sell_policy <> legacy) or links one to it, any units_per_item <> 1 (and any change of
 * a listing linked with u <> 1: relink, new item, unlink, ignore, split; a merge moving one), a link to
 * an item this listing was rejected for (or to an item such an item was merged into), a merge or split
 * touching a counted item (or one with a counted item merged into it, or a recount open, M39), and a merge
 * asked for by a mapper is stored `pending_second`; a DIFFERENT
 * staff user with role mapping_lead approves (applies) or withdraws it. A mapping lead's merge of two
 * uncounted legacy items applies at once (the owner's decision of 6 Oct 2026, M31). Protected items are
 * never merged or split (409). A merge that would contradict a match_reject is refused (409
 * rejected_pair). Proposals in the Conflict band (or a listing whose open proposal is Conflict) may only
 * be decided by a mapping_lead. Roles: mapper and mapping_lead decide; bulk decisions (bulk_batch_id)
 * and splits are mapping_lead only; a system caller may only suggest. At most one pending decision per
 * listing.
 *
 * What the person saw (design I7): the listing's map_version (which also moves when the site changes
 * the listing's identity, identityChanged()) and the proposal (a decision on a listing with an open
 * proposal must name it; approving is refused once another proposal is open): 409 otherwise. A
 * decision settles only the proposal it named. Relinking a listing whose units are in flight on a
 * counted item queues a recount of both items (remapCorrection, design A.9 rule 5).
 *
 * Lock order (docs/decisions.md M4): reservation rows -> channel_listing (X) -> sku (S, X only
 * for the item merged away or revived by a split) -> stock_balance (adoption; the stock of a merge
 * or split) -> the item value clocks -> feed clock. Nothing is locked after the feed clock: every
 * decision, history, proposal and audit row is written before it.
 */
final class DecisionService
{
    public const ACTIONS = ['link', 'unlink', 'new_item', 'ignore', 'reject', 'suggest', 'merge_skus', 'split'];
    /** Where a split sends the listing (M33): back to the item it had before the merge, or to an item minted from it. */
    public const SPLIT_TO = ['former', 'new'];
    /**
     * The stock_ledger movement types of a merge (the merged item's stock out, into the kept item) and of a split (back), all
     * under doc_ref `merge:<merge decision id>` (M32, M33). Not movements anyone can post (CW\Movements::TYPES): only a
     * decision books them.
     */
    public const MERGE_MOVEMENTS = ['merge_out', 'merge_in', 'split_out', 'split_in'];
    /** count_review source of a merge or split that two people applied to a counted item (M32). */
    public const RECOUNT_SOURCE = 'merge_recount';
    /** Kept as an alias: the roles live in Auth\Permissions (I11). */
    public const ROLES = Permissions::ROLES;
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
    /**
     * Inside decideGroup(): the stock moves and the listings for the feed that the group's merges leave for its end (one
     * Stock::lock() and one flush() per transaction, I7); null otherwise.
     *
     * @var array{moves: list<array<string, mixed>>, listings: list<int>}|null
     */
    private ?array $deferred = null;

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
     * The decisions of one duplicate group (the Duplicates screen's one POST, M34): merges into the item kept and rejects
     * ("different products"), in ONE transaction, so a refusal of any of them (a stale form: 409 map_version_conflict, a
     * reject in the way: 409 rejected_pair, ...) leaves the whole group as it was. Every listing of the group is locked
     * first, in id order; each request then runs as decide() would, except that the stock of every merge is moved at the
     * end with one Stock::lock() and one flush() (I7), and the feed rows follow it. Afterwards, whatever the order of the
     * requests, every open merge suggestion of the group's listings that the group's answers leave nothing to decide for is
     * settled (M34, M41): the listing and the item it proposes are one item now (`same_item`), or a person said "different
     * products" to that item's family or the merge would contradict a reject (`rejected_before`; DecisionService::duplicateBlocked).
     *
     * @param list<int> $groupListings the listings of the group (locked first)
     * @param list<array<string, mixed>> $requests decide() requests: merge_skus and reject only
     * @param array<int, int> $expect listing id => the map_version the person saw, for listings the requests do not name (e.g. the
     *        keeper's, or a listing that moves with another's merge): checked under the lock, 409 map_version_conflict
     * @param list<int>|null $groupProposals the group's own suggestions: only these are settled at the end (null: any merge
     *        suggestion of the group's listings)
     * @return list<array<string, mixed>> decide()'s result per request, in order
     */
    public function decideGroup(Caller $caller, array $groupListings, array $requests, ?string $groupRef = null, array $expect = [],
        ?array $groupProposals = null): array
    {
        if ($requests === [] || !array_is_list($requests) || count($requests) > 50) {
            throw new CwException('bad_request', 'a group decision has 1 to 50 decisions', 400);
        }
        $rs = [];
        foreach ($requests as $req) {
            $r = self::request($req);
            if (!in_array($r['action'], ['merge_skus', 'reject'], true)) {
                throw new CwException('bad_action', 'a group decision merges or rejects', 400);
            }
            $rs[] = $r;
        }
        return $this->db->transaction(function (Db $db) use ($caller, $rs, $groupListings, $groupRef, $expect, $groupProposals): array {
            // Every listing the group's decisions lock, in one id order and before any item row (M4): the group's, and those of
            // the items its merges fold away (a link made in between is seen and locked again by merge()).
            $from = array_values(array_filter(array_map(static fn (array $r): ?int => $r['action'] === 'merge_skus' ? $r['merge_from_sku_id'] : null, $rs)));
            $more = $from === [] ? [] : $this->db->column("SELECT id FROM channel_listing WHERE status IN ('mapped', 'quarantined') AND sku_id IN ("
                . implode(',', array_fill(0, count($from), '?')) . ')', $from);
            $ids = array_values(array_unique(array_map('intval', [...$groupListings, ...array_column($rs, 'listing_id'), ...$more, ...array_keys($expect)])));
            sort($ids);
            foreach ($ids as $lid) {
                $l = $this->lockListing($lid);
                if (isset($expect[$lid]) && $expect[$lid] !== $l['map_version']) {
                    throw new CwException('map_version_conflict', "listing {$lid} changed since it was shown; reload it", 409,
                        ['listing_id' => $lid, 'current_map_version' => $l['map_version'], 'expected_map_version' => $expect[$lid]]);
                }
            }
            $this->deferred = ['moves' => [], 'listings' => []];
            try {
                $out = [];
                foreach ($rs as $r) {
                    $out[] = $this->run($caller, $r, null);
                }
                $settled = $this->settleAnswered(array_values(array_unique(array_map('intval', [...$groupListings, ...array_column($rs, 'listing_id')]))),
                    $groupProposals);
                $stock = $this->deferred['moves'] === [] ? [] : $this->moveStock($caller, $this->deferred['moves'], $this->now());
                Audit::write($this->db, $caller, 'mapping.duplicates', 'listing', (string) $ids[0], null, [
                    'group' => $groupRef, 'listings' => $ids,
                    'decisions' => array_map(static fn (array $x): array => ['decision_id' => $x['decision_id'], 'action' => $x['action'], 'state' => $x['state'],
                        'listing_id' => $x['listing_id']], $out),
                    'settled_proposals' => $settled, 'stock' => $stock,
                ]);
                if ($this->deferred['moves'] !== []) {
                    $this->stock->flush();
                }
                $feed = array_values(array_unique($this->deferred['listings']));
                sort($feed);
                foreach ($feed as $lid) {
                    $this->stock->listingChanged($lid, 'link');
                }
                return $out;
            } finally {
                $this->deferred = null;
            }
        });
    }

    /** A lane of merge suggestions between two items (mint_vpg's `vpg_duplicate`, and any later `<source>_duplicate` lane, M34). */
    public static function isDuplicateLane(?string $lane): bool
    {
        return $lane !== null && ($lane === 'duplicate' || str_ends_with($lane, '_duplicate'));
    }

    /** The same test in SQL, on the lane column $col (e.g. `p.lane`). */
    public static function duplicateLaneSql(string $col): string
    {
        if (preg_match('/^[a-z_]+(\.[a-z_]+)?$/D', $col) !== 1) {
            throw new \InvalidArgumentException('bad column');
        }
        return "({$col} = 'duplicate' OR RIGHT({$col}, 10) = '_duplicate')";
    }

    /**
     * Why a merge suggestion of listing $listingId for item $proposedSku must not be (re)made (M34): `same_item` when the
     * listing is linked to that item already (or to the item it was merged into), `rejected_before` when a person said
     * "different products" (a match_reject of the listing against that item's family, or one the merge would contradict,
     * M22), else null. Read in the caller's transaction.
     */
    public function duplicateBlocked(int $listingId, int $proposedSku): ?string
    {
        $l = $this->db->one('SELECT sku_id, status FROM channel_listing WHERE id = ?', [$listingId]);
        $root = $this->rootOf($proposedSku);
        if ($l !== null && $l['sku_id'] !== null && in_array($l['status'], self::LINKED, true) && $this->rootOf((int) $l['sku_id']) === $root) {
            return 'same_item';
        }
        if ($this->rejectedAs($listingId, $root) !== [] || $this->rejectedAs($listingId, $proposedSku) !== []) {
            return 'rejected_before';
        }
        if ($l !== null && $l['sku_id'] !== null && in_array($l['status'], self::LINKED, true) && $this->mergeRejects((int) $l['sku_id'], $root) !== []) {
            return 'rejected_before';
        }
        return null;
    }

    /** The item $skuId is now: itself, or the live item it was merged into (merged_into_sku_id chains). */
    public function rootOf(int $skuId): int
    {
        $root = $this->db->value(
            'WITH RECURSIVE up (id, nxt, depth) AS (SELECT id, merged_into_sku_id, 0 FROM sku WHERE id = ? '
            . 'UNION ALL SELECT s.id, s.merged_into_sku_id, up.depth + 1 FROM sku s JOIN up ON s.id = up.nxt WHERE up.depth < 100) '
            . 'SELECT id FROM up WHERE nxt IS NULL LIMIT 1',
            [$skuId],
        );
        return $root === null ? $skuId : (int) $root;
    }

    /**
     * Whether an item is counted (M31: a merge or split touching one needs two people): sku.counted_at, a count time on one
     * of its balances, or a `count` movement (as KeyEligibility and the opening tools read it), on the item OR on any item of
     * its family (the items merged into it, merged_into_sku_id chains: their counted stock is in its figure now), or an open
     * count_review of a merge or a relink (`merge_recount`, `remap_correction`) on one of them: a counted figure waiting for its
     * recount (M39).
     *
     * @param list<int> $skuIds
     * @return list<int> the counted ones
     */
    public function counted(array $skuIds): array
    {
        $skuIds = array_values(array_unique(array_map('intval', $skuIds)));
        if ($skuIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($skuIds), '?'));
        $out = array_map('intval', $this->db->column(
            "WITH RECURSIVE fam (root, id, depth) AS (SELECT id, id, 0 FROM sku WHERE id IN ({$in}) UNION ALL "
            . 'SELECT fam.root, s.id, fam.depth + 1 FROM sku s JOIN fam ON s.merged_into_sku_id = fam.id WHERE fam.depth < 100) '
            . 'SELECT DISTINCT fam.root FROM fam JOIN sku s ON s.id = fam.id WHERE s.counted_at IS NOT NULL '
            . 'OR EXISTS (SELECT 1 FROM stock_balance b WHERE b.sku_id = fam.id AND (b.counted_at IS NOT NULL OR EXISTS ('
            . "SELECT 1 FROM stock_ledger l WHERE l.warehouse_id = b.warehouse_id AND l.sku_id = b.sku_id AND l.movement_type = 'count'))) "
            . "OR EXISTS (SELECT 1 FROM count_review r WHERE r.sku_id = fam.id AND r.status = 'open' AND r.source IN ('" . self::RECOUNT_SOURCE . "', 'remap_correction'))",
            $skuIds,
        ));
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
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
            if ($staff === null || !self::isLead($staff)) {
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
            if ($d['action'] === 'split' && (json_decode((string) $d['detail'], true)['split_to'] ?? 'former') === 'former') {
                $this->lockSplitListings($l['id']);
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
            $detail = $d['detail'] === null ? [] : (array) json_decode((string) $d['detail'], true);
            $r = [
                'action' => (string) $d['action'], 'listing_id' => (int) $d['listing_id'],
                'expected_map_version' => (int) $d['expected_map_version'],
                'sku_id' => $d['sku_id'] === null ? null : (int) $d['sku_id'],
                'units_per_item' => $d['units_per_item'] === null ? null : (int) $d['units_per_item'],
                'merge_from_sku_id' => $d['merge_from_sku_id'] === null ? null : (int) $d['merge_from_sku_id'],
                'proposal_id' => $d['proposal_id'] === null ? null : (int) $d['proposal_id'],
                'card' => (array) ($detail['card'] ?? []),
                'reason' => $reason, 'bulk_batch_id' => $d['bulk_batch_id'],
                'split_to' => is_string($detail['split_to'] ?? null) ? $detail['split_to'] : null,
            ];
            if ($r['action'] === 'split' && (int) ($detail['undoes_decision_id'] ?? 0) !== ($this->mergeOpening($l['id'])['id'] ?? null)) {
                // The listing's link changed since (it can only through a decision, which needs this one withdrawn first; kept as a guard).
                throw new CwException('map_version_conflict', 'the listing changed since this decision was made; withdraw it and decide again', 409);
            }
            $target = $this->validate($r, $l, null, false, true);
            $now = $this->now();
            if ($r['action'] === 'new_item' || ($r['action'] === 'split' && $r['split_to'] === 'new')) {
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
            return $this->effect($caller, $decisionId, $r, $l, $target, $now, false);
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
            if (!$own && !self::isLead($staff)) {
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
        if ($staff !== null && !Permissions::can($staff['roles'], 'mapping.decide')) {
            throw new CwException('role_not_allowed', (count($staff['roles']) === 1 ? 'role ' : 'roles ')
                . (implode(', ', $staff['roles']) ?: 'none') . ' cannot make mapping decisions', 403);
        }
        if (($r['bulk_batch_id'] !== null || $mint !== null) && ($staff === null || !self::isLead($staff))) {
            throw new CwException('lead_required', 'bulk decisions are made by a mapping_lead', 403);
        }
        if ($action === 'split' && ($staff === null || !self::isLead($staff))) {
            throw new CwException('lead_required', 'a merge is undone (split) by a mapping lead', 403);
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
        if ($action !== 'suggest' && $open !== null && $open['band'] === 'Conflict' && $staff !== null && !self::isLead($staff)) {
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
        if ($action === 'split' && ($r['split_to'] ?? 'former') === 'former' && $l['linked']) {
            $this->lockSplitListings($l['id']);
        }

        $target = $this->validate($r, $l, $open, true, $staff !== null && self::isLead($staff));
        $needs = $target['needs_second'];
        $state = $needs === [] ? 'applied' : 'pending_second';
        if ($mint !== null && $state !== 'applied') {
            throw new CwException('mint_needs_second', 'a seed mint must be a plain one-person link', 409, ['needs_second' => $needs]);
        }
        $now = $this->now();
        $card = null;
        $splitNew = $action === 'split' && $r['split_to'] === 'new';
        if ($mint !== null || $action === 'new_item' || $splitNew) {
            $card = $mint !== null ? self::cardFrom([], [], $r['card']) : $this->card($l['id'], $r['card']);
            if ($state === 'applied') {
                $target['sku'] = $this->mint($card, $mint ?? 'new_item', $l['id']);
            }
        }
        $skuId = match ($action) {
            'link', 'new_item', 'split' => $target['sku']['id'] ?? null,
            'reject', 'merge_skus' => $r['sku_id'],
            default => null,
        };
        $units = in_array($action, self::LINK_OUTCOMES, true) ? $target['units'] : null;
        $detail = $card === null ? null : ['card' => $card] + ($mint !== null ? ['origin' => $mint] : []);
        if ($action === 'split') {
            $detail = ['split_to' => $r['split_to'], 'undoes_decision_id' => $target['merge']['id']] + ($detail ?? []);
        }
        $decisionId = $this->db->insert(
            'INSERT INTO match_decision (listing_id, proposal_id, action, sku_id, units_per_item, merge_from_sku_id, prev_sku_id, '
            . 'prev_units_per_item, prev_status, decided_by, actor, needs_second, state, reason, expected_map_version, bulk_batch_id, detail, applied_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $l['id'], $r['proposal_id'], $action, $skuId, $units, $r['merge_from_sku_id'],
                $l['linked'] ? $l['sku_id'] : null, $l['linked'] ? $l['units_per_item'] : null, $l['status'],
                $staff['id'] ?? null, $caller->actor, $needs === [] ? null : Idempotency::json($needs), $state, $r['reason'],
                $r['expected_map_version'], $r['bulk_batch_id'], $detail === null ? null : Idempotency::json($detail),
                $state === 'applied' ? $now : null,
            ],
        );
        if ($state === 'pending_second') {
            Audit::write($this->db, $caller, 'mapping.' . $action, 'listing', (string) $l['id'], null, [
                'decision_id' => $decisionId, 'state' => $state, 'needs_second' => $needs, 'map_version' => $l['map_version'],
                'from' => self::linkOf($l), 'sku_id' => $r['sku_id'], 'units_per_item' => $units, 'merge_from_sku_id' => $r['merge_from_sku_id'],
                'proposal_id' => $r['proposal_id'], 'reason' => $r['reason'],
            ] + ($action === 'split' ? ['split_to' => $r['split_to'], 'undoes_decision_id' => $target['merge']['id'], 'to_sku_id' => $skuId] : []));
            return ['decision_id' => $decisionId, 'action' => $action, 'state' => $state, 'listing_id' => $l['id'],
                'map_version' => $l['map_version'], 'status' => $l['status'], 'sku_id' => $l['linked'] ? $l['sku_id'] : null,
                'units_per_item' => $l['units_per_item'], 'needs_second' => $needs, 'adopted' => 0];
        }
        return $this->effect($caller, $decisionId, $r, $l, $target, $now, true);
    }

    /**
     * Checks what the action needs and works out the two-person reasons. Reads the items FOR SHARE
     * (in id order; the item merged away FOR UPDATE).
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $l locked listing
     * @param array<string, mixed>|null $open the listing's open proposal
     * @param bool $lead the decider holds mapping_lead (a merge by anyone else waits for one, M31)
     * @return array{needs_second: list<string>, sku: ?array{id: int, code: string, policy: string, merged_into: ?int, counted_at: ?string},
     *               units: int, current: ?array<string, mixed>, from: ?array<string, mixed>, counted: list<int>, merge: ?array<string, mixed>}
     */
    private function validate(array $r, array $l, ?array $open, bool $fresh, bool $lead): array
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
        $merge = null;
        $exact = null;
        if ($action === 'split') {
            // The merge that put the listing on its item now (it opened the listing's current link period): the one undone.
            $merge = $l['linked'] ? $this->mergeOpening($l['id']) : null;
            if ($merge !== null) {
                $ids[] = $merge['from'];
            }
        }
        $exclusive = match ($action) {
            'merge_skus' => $r['merge_from_sku_id'],
            'split' => $merge !== null && ($r['split_to'] ?? 'former') === 'former' ? $merge['from'] : null,
            default => null,
        };
        $skus = $this->lockSkus($ids, $exclusive);
        $current = $l['linked'] ? ($skus[$l['sku_id']] ?? null) : null;
        $needs = [];
        $target = null;
        $from = null;
        $counted = [];
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
                // M31 (the owner, 6 Oct 2026): a mapping lead merges two uncounted legacy items alone; a counted item (or one with a
                // counted item merged into it, M39), a listing of either item linked with u <> 1 (M21, M39) or a merge asked for by a
                // mapper waits for a (second) mapping lead.
                if (!$lead) {
                    $needs[] = 'merge';
                }
                $counted = $this->counted([$target['id'], $from['id']]);
                if ($counted !== []) {
                    $needs[] = 'counted_item';
                }
                // A verified multiple on EITHER item (M39): a pack listing of the kept item is the strongest sign that a pack item is
                // being folded into a single-unit one (or the other way round), and it would sell the other item's units afterwards.
                if ((int) $this->db->value("SELECT COUNT(*) FROM channel_listing WHERE sku_id IN (?, ?) AND status IN ('mapped', 'quarantined') "
                    . 'AND units_per_item <> 1', [$from['id'], $target['id']]) > 0) {
                    $needs[] = 'units_per_item';
                }
                break;
            case 'split':
                if (!$l['linked']) {
                    throw new CwException('not_linked', 'the listing is not linked', 409);
                }
                if ($merge === null || $merge['to'] !== $l['sku_id'] || $current === null) {
                    throw new CwException('not_merged', 'this listing was not moved to its item by a merge (or was relinked since): relink it on the '
                        . 'review screen instead', 409);
                }
                $from = $skus[$merge['from']] ?? throw new CwException('unknown_sku', 'the item merged away is missing', 404);
                foreach ([$current, $from] as $s) {
                    if ($s['policy'] !== 'legacy') {
                        throw new CwException('protected_split', "item {$s['code']} is protected ({$s['policy']}): a split needs the recount flow", 409);
                    }
                }
                // M40. `former` undoes the merge: EVERY listing it moved that is still where it put it goes back to the merged item,
                // with the merge's stock (exact: the merge's inverse). Refused when this listing came through two merges (its link
                // before this merge was itself made by a merge): which merge was wrong cannot be told, so it goes to a new item.
                // `new` takes this listing alone, with the merge's stock only when the merge moved it alone (else none moves).
                $split = $this->splitPlan($l['id'], $merge['id']);
                $back = [$l['id']];
                if (($r['split_to'] ?? 'former') === 'former') {
                    if ($from['merged_into'] !== null && $from['merged_into'] !== $current['id']) {
                        throw new CwException('former_merged_elsewhere', "item {$from['code']} was merged into another item since: split this listing "
                            . 'to a new item instead', 409, ['merged_into_sku_id' => $from['merged_into']]);
                    }
                    if ($split['chain']) {
                        throw new CwException('split_chain', "this listing came onto {$current['code']} through two merges: split it to a new item instead "
                            . '(no stock moves)', 409);
                    }
                    $target = $from;
                    $back = $split['back'];
                    foreach ($back as $lid) {
                        if ($lid !== $l['id'] && $this->db->value('SELECT id FROM match_decision WHERE pending_listing_id = ?', [$lid]) !== null) {
                            throw new CwException('pending_second_exists', "listing {$lid}, which goes back with this one, has a pending decision", 409, ['listing_id' => $lid]);
                        }
                        if ($this->rejectedAs($lid, $from['id']) !== []) {
                            $needs[] = 'previously_rejected';
                        }
                    }
                    $exact = true;
                } else {
                    $exact = count($split['moved']) === 1 && !$split['chain'];
                }
                $counted = $this->counted([$current['id'], $from['id']]);
                if ($counted !== []) {
                    $needs[] = 'counted_item';
                }
                if ($multiple || (int) $this->db->value('SELECT COUNT(*) FROM channel_listing WHERE id IN (' . implode(',', array_fill(0, count($back), '?'))
                    . ') AND units_per_item <> 1', $back) > 0) {
                    $needs[] = 'units_per_item';
                }
                $needs = array_values(array_unique($needs));
                break;
        }
        // The owner's approval switches (Approval rules page, 0019, Y11): a two-person case switched off is decided by one.
        if ($needs !== [] && !ApprovalRules::on($this->db, 'approvals.match_multiple')) {
            $needs = array_values(array_diff($needs, ['units_per_item']));
        }
        if ($needs !== [] && !ApprovalRules::on($this->db, 'approvals.match_counted')) {
            $needs = array_values(array_diff($needs, ['counted_item']));
        }
        if (in_array($action, self::LINK_OUTCOMES, true) && ($units < 1 || $units > self::MAX_UNITS)) {
            throw new CwException('bad_units', 'units_per_item must be 1..' . self::MAX_UNITS, 400);
        }
        return ['needs_second' => $needs, 'sku' => $target, 'units' => $units, 'current' => $current, 'from' => $from, 'counted' => $counted,
            'merge' => $merge, 'exact' => $exact ?? true, 'back' => $back ?? [$l['id']], 'moved' => $split['moved'] ?? [$l['id']]];
    }

    /**
     * Applies a decision whose row exists and is `applied`.
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $l locked listing (before)
     * @param array<string, mixed> $target validate() result (+ minted sku)
     * @param bool $onePerson applied by its decider alone (not by an approval): the stock of a merge or split re-checks under
     *        the balance locks that neither item was counted meanwhile (M31)
     * @return array<string, mixed>
     */
    private function effect(Caller $caller, int $decisionId, array $r, array $l, array $target, string $now, bool $onePerson): array
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
                $this->recordReject($caller, $id, (int) $r['sku_id'], $decisionId, $now);
                // A merge suggestion (a duplicate lane, M34) is answered by "different products": a reject of the item it proposes
                // (or of the item that one was merged into) settles it, so it is never suggested again. Any other proposal stays
                // open for another choice (M8).
                $answered = $r['proposal_id'] !== null && $this->answersSuggestion((int) $r['proposal_id'], (int) $r['sku_id']);
                if ($answered) {
                    $this->settleProposal($r['proposal_id']);
                }
                Audit::write($this->db, $caller, 'mapping.reject', 'listing', (string) $id, null,
                    $detail + ['sku_id' => $r['sku_id'], 'sku_code' => $target['sku']['code'] ?? null] + ($answered ? ['settled_suggestion' => true] : []));
                break;
            case 'merge_skus':
                $keep = $target['sku'];
                $from = $target['from'];
                $moved = $this->merge($decisionId, $from['id'], $keep['id'], $now);
                $this->settleProposal($r['proposal_id']);
                // The other merge suggestions this merge fulfilled (a listing of the merged item that proposed the kept item): nothing
                // is left to merge for them (M34). Only listings this decision holds locked.
                $settled = $this->settleFulfilled(array_keys($moved));
                $version = $moved[$id] ?? $version;
                $after = ['status' => $l['status'], 'sku_id' => $keep['id'], 'units_per_item' => $l['units_per_item']];
                $move = ['kind' => 'merge', 'decision_id' => $decisionId, 'merge_id' => $decisionId, 'from' => $from, 'to' => $keep,
                    'recount' => $target['counted'] !== [], 'one_person' => $onePerson];
                $stock = null;
                if ($this->deferred !== null) {
                    $this->deferred['moves'][] = $move;
                    array_push($this->deferred['listings'], ...array_map('intval', array_keys($moved)));
                } else {
                    $stock = $this->moveStock($caller, [$move], $now);
                }
                Audit::write($this->db, $caller, 'mapping.merge_skus', 'sku', (string) $from['id'], null, $detail + [
                    'kept_sku_id' => $keep['id'], 'kept_code' => $keep['code'], 'merged_sku_id' => $from['id'], 'merged_code' => $from['code'],
                    'listings' => array_keys($moved), 'settled_proposals' => $settled,
                    'stock' => $stock === null ? 'booked with its group (mapping.duplicates)' : $stock[$decisionId],
                ]);
                if ($this->deferred === null) {
                    $this->stock->flush();
                    foreach (array_keys($moved) as $lid) {
                        $this->stock->listingChanged((int) $lid, 'link');
                    }
                }
                break;
            case 'split':
                $cur = $target['current'];
                $to = $target['sku'];
                $merge = $target['merge'];
                $revived = $to['id'] === $merge['from'] && $to['merged_into'] !== null;
                if ($revived) {
                    // Back to its former item: the item lives again (it can be linked, merged and counted like any other).
                    $this->db->exec('UPDATE sku SET merged_into_sku_id = NULL WHERE id = ? AND merged_into_sku_id = ?', [$to['id'], $cur['id']]);
                }
                $this->closePeriod($id, $decisionId, $now);
                $this->setListing($id, $to['id'], $l['units_per_item'], $l['status']);
                $this->openPeriod($id, $to['id'], $l['units_per_item'], $decisionId, $now);
                // The other listings the merge moved go back with it (M40: the merge undone), each its own period and feed row.
                $others = [];
                foreach ($target['back'] as $lid) {
                    if ($lid === $id) {
                        continue;
                    }
                    $o = $this->lockListing($lid);
                    if (!$o['linked'] || $o['sku_id'] !== $cur['id']) {
                        continue;
                    }
                    $this->closePeriod($lid, $decisionId, $now);
                    $this->db->exec('UPDATE channel_listing SET sku_id = ?, map_version = map_version + 1 WHERE id = ?', [$to['id'], $lid]);
                    $this->openPeriod($lid, $to['id'], $o['units_per_item'], $decisionId, $now);
                    $others[] = $lid;
                }
                // "This listing is not that item": a later merge of the two is refused (M22) and no run suggests it again (M34).
                $this->recordReject($caller, $id, $cur['id'], $decisionId, $now);
                $after = ['status' => $l['status'], 'sku_id' => $to['id'], 'units_per_item' => $l['units_per_item']];
                $version++;
                $stock = $this->moveStock($caller, [['kind' => 'split', 'decision_id' => $decisionId, 'merge_id' => $merge['id'], 'from' => $cur, 'to' => $to,
                    'listing_ids' => $target['moved'], 'since' => $merge['applied_at'], 'recount' => $target['counted'] !== [], 'one_person' => $onePerson,
                    'exact' => $target['exact']]], $now);
                Audit::write($this->db, $caller, 'mapping.split', 'listing', (string) $id, null, $detail + [
                    'undoes_decision_id' => $merge['id'], 'split_to' => $r['split_to'], 'to' => $after + ['sku_code' => $to['code']],
                    'revived' => $revived, 'map_version' => $version, 'stock' => $stock[$decisionId], 'with_listings' => $others,
                ]);
                $this->stock->flush();
                foreach ([$id, ...$others] as $lid) {
                    $this->stock->listingChanged($lid, 'link');
                }
                break;
        }
        return ['decision_id' => $decisionId, 'action' => $action, 'state' => 'applied', 'listing_id' => $id, 'map_version' => $version,
            'status' => $after['status'], 'sku_id' => $after['sku_id'], 'units_per_item' => $after['units_per_item'], 'needs_second' => [],
            'adopted' => $adopted] + (isset($target['sku']['code']) && in_array($action, [...self::LINK_OUTCOMES, 'split'], true) ? ['sku_code' => $target['sku']['code']] : []);
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

    /**
     * The stock of merges (M32) and splits (M33), booked through CW\Stock, the only writer: ONE lock() of every balance the
     * moves touch, then per move and warehouse one on_hand row out of one item and one into the other, under doc_ref
     * `merge:<merge decision>` (so a split finds what its merge moved), actor the decider. No flush(): the caller writes its
     * audit row first, then flushes (the value seqs and the feed, I3, D39), then the listings' feed rows.
     *
     *  - merge: the merged item's AVAILABLE stock per warehouse (on_hand - allocated - held, any sign) goes to the kept item, so
     *    the kept item's availability is the sum of both. What the merged item's own orders in flight still need stays on it:
     *    their units keep their sale-time item (reservation_unit.sku_id, I14), and their ship / cancel / release moves its
     *    buckets as before. It ends at available 0 (on_hand 0 when nothing was in flight).
     *  - split: the stock that came with the merge back to where the listing goes: per warehouse, what the merge moved onto
     *    the kept item, less the units this listing sold from the kept item since the merge (shipped or still to ship: their
     *    stock left, or will leave, the kept item). Once per merge: when an earlier split of the same merge took the merge's
     *    stock back, nothing more moves.
     *  - A move that two people applied to a counted item (`recount`) also opens a count_review (source merge_recount) on the
     *    items whose figure changed: a counted figure plus an estimate is settled by counting again.
     *  - A move applied by one person re-checks, under the balance locks, that neither item was counted meanwhile (409
     *    counted_meanwhile: nothing is written).
     *
     * @param list<array{kind: string, decision_id: int, merge_id: int, from: array<string, mixed>, to: array<string, mixed>, recount: bool,
     *                   one_person: bool, listing_id?: int, since?: string}> $moves
     * @return array<int, array<string, mixed>> decision id => {kind, from, to, moved: warehouse code => units, consumed?: units}
     */
    private function moveStock(Caller $caller, array $moves, string $now): array
    {
        $whs = [];
        $pairs = [];
        foreach ($moves as $i => $m) {
            if ($m['kind'] === 'merge') {
                // Every balance of the merged item is locked; the kept item's only where there is something to move (no empty
                // rows at VERIFY, say). A warehouse that gains stock between this read and the lock keeps it (a residual, as a
                // movement booked on the merged item after the merge would be).
                $w = [];
                foreach ($this->db->all('SELECT warehouse_id, on_hand - allocated - held AS a FROM stock_balance WHERE sku_id = ? ORDER BY warehouse_id',
                    [$m['from']['id']]) as $b) {
                    $pairs[] = [(int) $b['warehouse_id'], (int) $m['from']['id']];
                    if ((int) $b['a'] !== 0) {
                        $w[] = (int) $b['warehouse_id'];
                    }
                }
            } else {
                $w = $this->splitWarehouses((int) $m['from']['id'], (int) $m['merge_id'], $m['listing_ids'], (string) $m['since']);
            }
            $whs[$i] = array_map('intval', $w);
            foreach ($whs[$i] as $wh) {
                $pairs[] = [$wh, (int) $m['from']['id']];
                $pairs[] = [$wh, (int) $m['to']['id']];
            }
        }
        $out = [];
        if ($pairs === []) {
            foreach ($moves as $m) {
                $out[$m['decision_id']] = ['kind' => $m['kind'], 'from' => $m['from']['code'], 'to' => $m['to']['code'], 'moved' => []];
            }
            return $out;
        }
        $this->stock->lock($pairs);
        $codes = [];
        foreach ($this->db->all('SELECT id, code FROM warehouse') as $w) {
            $codes[(int) $w['id']] = (string) $w['code'];
        }
        foreach ($moves as $i => $m) {
            $fromId = (int) $m['from']['id'];
            $toId = (int) $m['to']['id'];
            if ($m['one_person'] && ApprovalRules::on($this->db, 'approvals.match_counted') && $this->counted([$fromId, $toId]) !== []) {
                throw new CwException('counted_meanwhile', 'one of the items was counted a moment ago: reload the page (a counted item needs a second person)', 409);
            }
            $doc = 'merge:' . $m['merge_id'];
            $res = ['kind' => $m['kind'], 'from' => $m['from']['code'], 'to' => $m['to']['code'], 'moved' => []];
            $qty = [];
            if ($m['kind'] === 'merge') {
                foreach ($whs[$i] as $wh) {
                    $qty[$wh] = $this->stock->available($wh, $fromId);
                }
                [$outType, $inType] = ['merge_out', 'merge_in'];
            } else {
                $u = $this->splitUnits($whs[$i], $fromId, (int) $m['merge_id'], $m['listing_ids'], (string) $m['since']);
                $qty = ($m['exact'] ?? true) ? $u['qty'] : array_map(static fn (int $q): int => 0, $u['qty']);
                $res['consumed'] = $u['consumed'];
                $res['earlier_split'] = $u['earlier'];
                if (!($m['exact'] ?? true)) {
                    $res['not_exact'] = true; // M40: one page of several (or through two merges) to a new item: no stock moves
                }
                [$outType, $inType] = ['split_out', 'split_in'];
            }
            if ($m['kind'] === 'merge' && $m['recount']) {
                // M39: a recount the merged item still waits for (a counted figure of an earlier merge or relink) is the kept item's
                // now, where the merged item's stock went: opened again there, so it is not left behind on an item with no listings.
                foreach ($this->db->all("SELECT id, warehouse_id, source FROM count_review WHERE sku_id = ? AND status = 'open' AND source IN (?, 'remap_correction') "
                    . 'ORDER BY id', [$fromId, self::RECOUNT_SOURCE]) as $cr) {
                    $this->stock->openCountReview((int) $cr['warehouse_id'], $toId, self::RECOUNT_SOURCE, null, $doc, [
                        'decision_id' => $m['decision_id'], 'kind' => 'merge', 'merge_decision_id' => $m['merge_id'], 'from_sku_id' => $fromId,
                        'to_sku_id' => $toId, 'carried_review_id' => (int) $cr['id'], 'carried_source' => (string) $cr['source'],
                    ], "merge:{$m['decision_id']}:{$toId}:{$cr['warehouse_id']}");
                    $res['recount'] = true;
                }
            }
            foreach ($qty as $wh => $q) {
                if ($q === 0) {
                    continue;
                }
                $base = ['actor' => $caller->actor, 'doc_ref' => $doc, 'idem_key' => "{$m['kind']}:{$m['decision_id']}", 'effective_at' => $now];
                $this->stock->apply($wh, $fromId, 'on_hand', -$q, $base + ['type' => $outType,
                    'note' => mb_substr("{$m['kind']} decision {$m['decision_id']}: to {$m['to']['code']}", 0, 255)]);
                $this->stock->apply($wh, $toId, 'on_hand', $q, $base + ['type' => $inType,
                    'note' => mb_substr("{$m['kind']} decision {$m['decision_id']}: from {$m['from']['code']}", 0, 255)]);
                $res['moved'][$codes[$wh] ?? (string) $wh] = $q;
                if ($m['recount']) {
                    foreach ($m['kind'] === 'merge' ? [$toId] : [$fromId, $toId] as $sku) {
                        $this->stock->openCountReview($wh, $sku, self::RECOUNT_SOURCE, null, $doc, [
                            'decision_id' => $m['decision_id'], 'kind' => $m['kind'], 'merge_decision_id' => $m['merge_id'], 'from_sku_id' => $fromId,
                            'to_sku_id' => $toId, 'central_units' => $q,
                        ], "{$m['kind']}:{$m['decision_id']}:{$sku}:{$wh}");
                    }
                    $res['recount'] = true;
                }
            }
            $out[$m['decision_id']] = $res;
        }
        return $out;
    }

    /** match_reject(listing, item) once (append-only, M8): a second reject of the pair adds nothing. */
    private function recordReject(Caller $caller, int $listingId, int $skuId, int $decisionId, string $now): void
    {
        if ($this->db->value('SELECT id FROM match_reject WHERE listing_id = ? AND sku_id = ?', [$listingId, $skuId]) === null) {
            $this->db->exec('INSERT INTO match_reject (listing_id, sku_id, decided_by, decision_id, `at`) VALUES (?, ?, ?, ?, ?)',
                [$listingId, $skuId, $caller->staffUserId, $decisionId, $now]);
        }
    }

    /** Whether rejecting item $skuId answers proposal $proposalId: an open merge suggestion (a duplicate lane) of that same item now. */
    private function answersSuggestion(int $proposalId, int $skuId): bool
    {
        $p = $this->db->one("SELECT lane, proposed_sku_id FROM match_proposal WHERE id = ? AND status = 'open'", [$proposalId]);
        return $p !== null && self::isDuplicateLane($p['lane'] === null ? null : (string) $p['lane']) && $p['proposed_sku_id'] !== null
            && $this->rootOf((int) $p['proposed_sku_id']) === $this->rootOf($skuId);
    }

    /**
     * Settles the open merge suggestions (duplicate lanes) of these listings that a merge fulfilled: the listing is linked to
     * the item the suggestion proposes, or to the item that one was merged into (M34). The caller holds the listings locked.
     *
     * @param list<int> $listingIds
     * @return list<int> the proposals settled
     */
    private function settleFulfilled(array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }
        $settled = [];
        foreach ($this->db->all(
            'SELECT p.id, p.proposed_sku_id, cl.sku_id FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id '
            . "WHERE p.open_listing_id IN (" . implode(',', array_fill(0, count($listingIds), '?')) . ") AND cl.status IN ('mapped', 'quarantined') "
            . 'AND p.proposed_sku_id IS NOT NULL AND ' . self::duplicateLaneSql('p.lane') . ' ORDER BY p.id',
            $listingIds,
        ) as $p) {
            if ($this->rootOf((int) $p['proposed_sku_id']) === $this->rootOf((int) $p['sku_id'])) {
                $this->settleProposal((int) $p['id']);
                $settled[] = (int) $p['id'];
            }
        }
        return $settled;
    }

    /**
     * What undoing merge $mergeId means for listing $listingId (M40): `moved`, every listing the merge moved (the periods it
     * opened, whatever became of them since: their sales since the merge count against the stock that goes back); `back`, those
     * still where the merge put them (their current period is the merge's), the anchor first: a split to the former item takes
     * them all back; `chain`, the anchor came onto the merged item through an earlier merge (its link before this merge was made
     * by a merge): which of the two merges was wrong cannot be told.
     *
     * @return array{moved: list<int>, back: list<int>, chain: bool}
     */
    public function splitPlan(int $listingId, int $mergeId): array
    {
        $moved = array_map('intval', $this->db->column('SELECT listing_id FROM listing_map_history WHERE decision_id = ? ORDER BY listing_id', [$mergeId]));
        $back = array_map('intval', $this->db->column('SELECT open_listing_id FROM listing_map_history WHERE decision_id = ? AND open_listing_id IS NOT NULL '
            . 'ORDER BY open_listing_id', [$mergeId]));
        $back = [$listingId, ...array_values(array_diff($back, [$listingId]))];
        $chain = $this->db->value(
            "SELECT 1 FROM listing_map_history h JOIN match_decision d ON d.id = h.decision_id WHERE h.listing_id = ? AND h.closed_by_decision_id = ? "
            . "AND d.action = 'merge_skus' AND d.state = 'applied' LIMIT 1",
            [$listingId, $mergeId],
        ) !== null;
        return ['moved' => $moved, 'back' => $back, 'chain' => $chain];
    }

    /**
     * What a split of listing $listingId would do (M33, M40), read without locks for the screen: the merge it undoes, the item it
     * leaves and the one it had, whether it can go back there (`former_ok`) and with which other listings (`with`), and the units
     * that would move back per warehouse code: `former` (the merge undone) and `new` (this listing alone to a new item: the
     * merge's stock only when the merge moved it alone; `new_exact`). Null when the listing was not moved onto its item by a merge.
     *
     * @return array{merge_id: int, from: int, to: int, former_ok: bool, chain: bool, with: list<int>, former_units: int, new_units: int,
     *               new_exact: bool, by_warehouse: array<string, int>}|null
     */
    public function splitPreview(int $listingId): ?array
    {
        $l = $this->db->one('SELECT sku_id, status FROM channel_listing WHERE id = ?', [$listingId]);
        $m = $this->mergeOpening($listingId);
        if ($l === null || $l['sku_id'] === null || !in_array($l['status'], self::LINKED, true) || $m === null || $m['to'] !== (int) $l['sku_id']) {
            return null;
        }
        $plan = $this->splitPlan($listingId, $m['id']);
        $formerInto = $this->db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$m['from']]);
        $u = $this->splitUnits($this->splitWarehouses($m['to'], $m['id'], $plan['moved'], $m['applied_at']), $m['to'], $m['id'], $plan['moved'], $m['applied_at']);
        $codes = [];
        foreach ($this->db->all('SELECT id, code FROM warehouse') as $w) {
            $codes[(int) $w['id']] = (string) $w['code'];
        }
        $by = [];
        foreach ($u['qty'] as $wh => $q) {
            if ($q !== 0) {
                $by[$codes[$wh] ?? (string) $wh] = $q;
            }
        }
        $newExact = count($plan['moved']) === 1 && !$plan['chain'];
        return ['merge_id' => $m['id'], 'from' => $m['from'], 'to' => $m['to'], 'chain' => $plan['chain'],
            'former_ok' => !$plan['chain'] && ($formerInto === null || (int) $formerInto === $m['to']),
            'with' => array_values(array_diff($plan['back'], [$listingId])), 'former_units' => array_sum($by), 'new_units' => $newExact ? array_sum($by) : 0,
            'new_exact' => $newExact, 'by_warehouse' => $by];
    }

    /** Where a split of merge $mergeId touches the kept item: where the merge put stock on it, and where the moved listings' units since sit. @param list<int> $listingIds @return list<int> */
    private function splitWarehouses(int $keptSku, int $mergeId, array $listingIds, string $since): array
    {
        $listingIds = $listingIds === [] ? [0] : $listingIds;
        return array_map('intval', $this->db->column(
            'SELECT b.warehouse_id FROM stock_balance b WHERE b.sku_id = ? AND (EXISTS (SELECT 1 FROM stock_ledger l WHERE l.warehouse_id = b.warehouse_id '
            . 'AND l.sku_id = b.sku_id AND l.doc_ref = ?) OR EXISTS (SELECT 1 FROM reservation_unit ru WHERE ru.listing_id IN ('
            . implode(',', array_fill(0, count($listingIds), '?')) . ') AND ru.sku_id = b.sku_id AND ru.warehouse_id = b.warehouse_id AND ru.created_at >= ?)) '
            . 'ORDER BY b.warehouse_id',
            [$keptSku, 'merge:' . $mergeId, ...$listingIds, $since],
        ));
    }

    /**
     * The stock a split of merge $mergeId takes back, per warehouse (M33, M40): what the merge moved onto the kept item ($keptSku;
     * its merge_in rows under `merge:<id>`) less the units the listings it moved sold from it since (held, allocated or shipped),
     * or nothing when an earlier split of the same merge took it back already.
     *
     * @param list<int> $warehouses
     * @param list<int> $listingIds the listings the merge moved
     * @return array{qty: array<int, int>, consumed: int, earlier: bool}
     */
    private function splitUnits(array $warehouses, int $keptSku, int $mergeId, array $listingIds, string $since): array
    {
        $doc = 'merge:' . $mergeId;
        $listingIds = $listingIds === [] ? [0] : $listingIds;
        $in = implode(',', array_fill(0, count($listingIds), '?'));
        $earlier = $warehouses !== [] && $this->db->value(
            'SELECT 1 FROM stock_ledger WHERE warehouse_id IN (' . implode(',', array_fill(0, count($warehouses), '?')) . ') AND sku_id = ? AND doc_ref = ? '
            . "AND movement_type = 'split_out' LIMIT 1",
            [...$warehouses, $keptSku, $doc],
        ) !== null;
        $consumed = 0;
        $qty = [];
        foreach ($warehouses as $wh) {
            $came = (int) $this->db->value('SELECT COALESCE(SUM(qty_delta), 0) FROM stock_ledger WHERE warehouse_id = ? AND sku_id = ? AND doc_ref = ? '
                . "AND bucket = 'on_hand' AND movement_type = 'merge_in'", [$wh, $keptSku, $doc]);
            $used = (int) $this->db->value("SELECT COALESCE(SUM(units_per_item), 0) FROM reservation_unit WHERE listing_id IN ({$in}) AND sku_id = ? AND warehouse_id = ? "
                . "AND created_at >= ? AND state IN ('held', 'allocated', 'shipped')", [...$listingIds, $keptSku, $wh, $since]);
            $consumed += $used;
            $qty[$wh] = $earlier ? 0 : $came - $used;
        }
        return ['qty' => $qty, 'consumed' => $consumed, 'earlier' => $earlier];
    }

    /** Locks (after the anchor, in id order, before any item row) the other listings a split to the former item takes back (M40). */
    private function lockSplitListings(int $listingId): void
    {
        $m = $this->mergeOpening($listingId);
        if ($m === null) {
            return;
        }
        foreach ($this->splitPlan($listingId, $m['id'])['back'] as $lid) {
            if ($lid !== $listingId) {
                $this->lockListing($lid);
            }
        }
    }

    /**
     * Settles the open merge suggestions (duplicate lanes) of these listings that nothing is left to decide for (M41): the
     * listing and the proposed item are one item now, or a person answered "different products" for that pair (or the merge
     * would contradict a reject): DecisionService::duplicateBlocked() not null. The caller holds the listings locked.
     *
     * @param list<int> $listingIds
     * @param list<int>|null $only settle only these proposals (null: any)
     * @return list<int> the proposals settled
     */
    private function settleAnswered(array $listingIds, ?array $only): array
    {
        if ($listingIds === []) {
            return [];
        }
        $settled = [];
        foreach ($this->db->all(
            'SELECT p.id, p.listing_id, p.proposed_sku_id FROM match_proposal p WHERE p.open_listing_id IN (' . implode(',', array_fill(0, count($listingIds), '?'))
            . ') AND p.proposed_sku_id IS NOT NULL AND ' . self::duplicateLaneSql('p.lane') . ' ORDER BY p.id',
            $listingIds,
        ) as $p) {
            if ($only !== null && !in_array((int) $p['id'], array_map('intval', $only), true)) {
                continue;
            }
            if ($this->duplicateBlocked((int) $p['listing_id'], (int) $p['proposed_sku_id']) !== null) {
                $this->settleProposal((int) $p['id']);
                $settled[] = (int) $p['id'];
            }
        }
        return $settled;
    }

    /**
     * The applied merge that opened the listing's current link period (the merge a split undoes), or null.
     *
     * @return array{id: int, from: int, to: int, applied_at: string}|null
     */
    public function mergeOpening(int $listingId): ?array
    {
        $m = $this->db->one(
            "SELECT d.id, d.merge_from_sku_id, d.sku_id, d.applied_at FROM listing_map_history h JOIN match_decision d ON d.id = h.decision_id "
            . "WHERE h.open_listing_id = ? AND d.action = 'merge_skus' AND d.state = 'applied'",
            [$listingId],
        );
        return $m === null ? null : ['id' => (int) $m['id'], 'from' => (int) $m['merge_from_sku_id'], 'to' => (int) $m['sku_id'], 'applied_at' => (string) $m['applied_at']];
    }

    // ==========================================================================================
    // helpers
    // ==========================================================================================

    /**
     * The acting person and their live roles, re-read inside the decision's transaction (I11): a role taken away a
     * moment ago no longer decides. 403 staff_not_allowed for an unknown or inactive person.
     *
     * @return array{id: int, roles: list<string>}|null null for a system caller
     */
    private function staff(Caller $caller): ?array
    {
        if ($caller->staffUserId === null) {
            if ($caller->isChannel()) {
                throw new CwException('staff_required', 'mapping decisions are made by staff', 403);
            }
            return null;
        }
        return ['id' => $caller->staffUserId, 'roles' => StaffRoles::active($this->db, $caller->staffUserId)];
    }

    /** Holds mapping_lead (Permissions: mapping.approve). @param array{id: int, roles: list<string>} $staff */
    private static function isLead(array $staff): bool
    {
        return Permissions::can($staff['roles'], 'mapping.approve');
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

    /** @return array{id: int, band: string, lane: ?string, proposed_sku_id: ?int}|null */
    private function openProposal(int $listingId): ?array
    {
        $p = $this->db->one('SELECT id, band, lane, proposed_sku_id FROM match_proposal WHERE open_listing_id = ?', [$listingId]);
        return $p === null ? null : ['id' => (int) $p['id'], 'band' => (string) $p['band'], 'lane' => $p['lane'] === null ? null : (string) $p['lane'],
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
     *               reason: ?string, card: array<string, mixed>, merge_from_sku_id: ?int, bulk_batch_id: ?string, split_to: ?string}
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
            'split_to' => null,
        ];
        if ($action === 'split') {
            $to = $req['split_to'] ?? 'former';
            if (!is_string($to) || !in_array($to, self::SPLIT_TO, true)) {
                throw new CwException('bad_request', 'split_to must be one of ' . implode(', ', self::SPLIT_TO), 400, ['field' => 'split_to']);
            }
            $r['split_to'] = $to;
        } elseif (array_key_exists('split_to', $req) && $req['split_to'] !== null) {
            throw new CwException('bad_request', 'split_to is for split only', 400, ['field' => 'split_to']);
        }
        $reason = $req['reason'] ?? null;
        if ($reason !== null && !is_string($reason)) {
            throw new CwException('bad_reason', 'reason must be a string', 400);
        }
        $r['reason'] = self::reason($reason);
        $card = $req['card'] ?? [];
        if (!is_array($card) || (array_is_list($card) && $card !== [])) {
            throw new CwException('bad_card', 'card must be an object', 400);
        }
        if ($card !== [] && $action !== 'new_item' && !$mint && !($action === 'split' && $r['split_to'] === 'new')) {
            throw new CwException('bad_card', 'card is for new_item (and a split to a new item) only', 400);
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
