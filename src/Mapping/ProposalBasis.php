<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Db;
use CW\Idempotency;

/**
 * What a proposal was made against (docs/decisions.md M27): the listing (its map_version, which moves on every link
 * change and every identity change, M20, and its profile's identity_hash), the proposed item (a fingerprint of its
 * identity card, sell policy, count and merge state), the identity of the listing that item was minted from, and the
 * LINK OF THE LANE TARGET: the Vape and Go listing the barcode/transfer key named (its map_version, status, item and
 * units per item). Stored once per proposal in match_proposal_basis (append-only) and compared with the same parts read
 * now: a bulk step (KeyBulk) and the re-banding (Reband) act only on a proposal whose listing, link, item and key target
 * are exactly as they were when it was made.
 *
 * The lane target is found the way bin/import_proposals.php named the item: the listing with the target's variant id
 * (evidence.lane_target `CWP-<id>`) that is linked to the proposed item (on the item's origin channel when more than one
 * is). Once recorded it is followed by its listing id, so a relink, an ignore, a unit change or a rename of it shows.
 *
 *  - recorded: written by Proposals::add in the transaction that made the proposal, after its `suggest`.
 *  - backfill: a proposal made before 0010 has no recorded basis. Its basis is written only when it can be PROVED that
 *    nothing changed since it was made (prove()): the listing is still at the map_version its own `suggest` left
 *    (no link, status or identity change since), the item row was not updated after the proposal (sku.updated_at), and
 *    the origin listing's and the lane target's map_versions are the ones their last decisions before the proposal left.
 *    Otherwise none is written and the proposal is never acted on in bulk (a person decides it).
 */
final class ProposalBasis
{
    public const VERSION = 1;
    /** The item columns the fingerprint covers (identity card, protection, merge, provenance). */
    public const ITEM_COLUMNS = ['code', 'name', 'brand', 'sell_policy', 'counted_at', 'strength_mg', 'nic_type', 'line', 'form', 'flavour',
        'volume_ml', 'puffs', 'pack_units', 'merged_into_sku_id', 'origin_listing_id'];
    /** The parts compared, in report order. */
    public const PARTS = ['listing_profile', 'listing_link', 'item', 'item_origin', 'target_link'];
    /** The lane target's link as the basis holds it (target_<column>). */
    public const TARGET = ['target_listing_id', 'target_map_version', 'target_status', 'target_sku_id', 'target_units_per_item'];
    private const HASHED = ['v', 'listing_id', 'map_version', 'identity_hash', 'sku_id', 'item_hash', 'origin_identity_hash',
        'target_listing_id', 'target_map_version', 'target_status', 'target_sku_id', 'target_units_per_item'];
    /** Decisions that move a listing's map_version (merge_skus moves the moved listings without a decision of their own). */
    private const VERSION_ACTIONS = "('link', 'unlink', 'new_item', 'ignore', 'suggest')";

    /**
     * The basis of a listing, its proposed item and the proposal's lane target as they are now (read in the caller's
     * transaction).
     *
     * @param mixed $evidence the proposal's evidence (its lane target)
     * @return array<string, mixed> v, listing_id, map_version, identity_hash, sku_id, item_hash, origin_identity_hash, target_*
     */
    public static function current(Db $db, int $listingId, ?int $skuId, mixed $evidence = null): array
    {
        $l = $db->one('SELECT cl.map_version, lp.identity_hash FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id = ?', [$listingId]);
        $item = null;
        $origin = null;
        if ($skuId !== null) {
            $item = $db->one('SELECT ' . implode(', ', array_map(static fn (string $c): string => 's.' . $c, self::ITEM_COLUMNS))
                . ', olp.identity_hash AS origin_identity_hash FROM sku s LEFT JOIN listing_profile olp ON olp.listing_id = s.origin_listing_id WHERE s.id = ?', [$skuId]);
            $origin = $item['origin_identity_hash'] ?? null;
        }
        return self::fromParts($listingId, (int) ($l['map_version'] ?? 0), $l['identity_hash'] ?? null, $skuId, $item, $origin,
            self::findTarget($db, $evidence, $skuId));
    }

    /**
     * @param array<string, mixed>|null $item a sku row holding ITEM_COLUMNS (null: no such item)
     * @param array<string, mixed>|null $target the lane target's link (targetRow(); null: none)
     * @return array<string, mixed>
     */
    public static function fromParts(int $listingId, int $mapVersion, mixed $identityHash, ?int $skuId, ?array $item, mixed $originIdentityHash, ?array $target = null): array
    {
        return ['v' => self::VERSION, 'listing_id' => $listingId, 'map_version' => $mapVersion,
            'identity_hash' => is_string($identityHash) ? $identityHash : null, 'sku_id' => $skuId,
            'item_hash' => $skuId === null ? null : ($item === null ? 'missing' : self::itemHash($item)),
            'origin_identity_hash' => is_string($originIdentityHash) ? $originIdentityHash : null,
            'target_listing_id' => $target['listing_id'] ?? null, 'target_map_version' => $target['map_version'] ?? null,
            'target_status' => $target['status'] ?? null, 'target_sku_id' => $target['sku_id'] ?? null,
            'target_units_per_item' => $target['units_per_item'] ?? null];
    }

    /** @param array<string, mixed> $item a sku row holding ITEM_COLUMNS */
    public static function itemHash(array $item): string
    {
        $v = [];
        foreach (self::ITEM_COLUMNS as $c) {
            $x = $item[$c] ?? null;
            $v[$c] = $x === null ? null : (string) $x;
        }
        return hash('sha256', Idempotency::canonicalJson($v));
    }

    /**
     * The Vape and Go variant id of the evidence's lane target (the digits of `CWP-<id>`, else lane_target.vpg_variant_id),
     * or null when the evidence names none.
     */
    public static function targetVariant(mixed $evidence): ?string
    {
        $t = is_array($evidence) && is_array($evidence['lane_target'] ?? null) ? $evidence['lane_target'] : null;
        if ($t === null) {
            return null;
        }
        if (is_string($t['cw_id'] ?? null) && preg_match('/^CWP-(\d{1,20})$/D', $t['cw_id'], $m) === 1) {
            return $m[1];
        }
        $v = $t['vpg_variant_id'] ?? null;
        return (is_int($v) && $v > 0) || (is_string($v) && preg_match('/^\d{1,20}$/D', $v) === 1) ? (string) $v : null;
    }

    /**
     * The lane target's listing now, found as bin/import_proposals.php named the item: the listing with the target's variant
     * id linked to the proposed item (on the item's origin channel when more than one is). Null when the evidence names no
     * lane target, or no such listing is linked to the item now (relinked away, or never found).
     *
     * @return array{listing_id: int, map_version: int, status: string, sku_id: ?int, units_per_item: int}|null
     */
    public static function findTarget(Db $db, mixed $evidence, ?int $skuId): ?array
    {
        $vid = self::targetVariant($evidence);
        if ($vid === null || $skuId === null) {
            return null;
        }
        $rows = $db->all(
            'SELECT t.id, t.map_version, t.status, t.sku_id, t.units_per_item, (o.id IS NOT NULL AND o.channel_id = t.channel_id) AS on_origin '
            . 'FROM channel_listing t JOIN sku s ON s.id = t.sku_id LEFT JOIN channel_listing o ON o.id = s.origin_listing_id '
            . 'WHERE t.sku_id = ? AND t.external_variant_id = ? ORDER BY t.id',
            [$skuId, $vid],
        );
        if (count($rows) > 1) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['on_origin'] === 1));
        }
        return count($rows) === 1 ? self::targetRow($rows[0]) : null;
    }

    /**
     * A recorded lane target's link now, by its listing id (null: none recorded, or no such listing). $lock: FOR SHARE,
     * so no decision on it commits before the caller's transaction ends (KeyBulk).
     *
     * @return array{listing_id: int, map_version: int, status: string, sku_id: ?int, units_per_item: int}|null
     */
    public static function targetNow(Db $db, ?int $targetListingId, bool $lock = false): ?array
    {
        if ($targetListingId === null) {
            return null;
        }
        $r = $db->one('SELECT id, map_version, status, sku_id, units_per_item FROM channel_listing WHERE id = ?' . ($lock ? ' FOR SHARE' : ''), [$targetListingId]);
        return $r === null ? null : self::targetRow($r);
    }

    /**
     * @param array<string, mixed> $r a channel_listing row (id, map_version, status, sku_id, units_per_item)
     * @return array{listing_id: int, map_version: int, status: string, sku_id: ?int, units_per_item: int}
     */
    public static function targetRow(array $r): array
    {
        return ['listing_id' => (int) $r['id'], 'map_version' => (int) $r['map_version'], 'status' => (string) $r['status'],
            'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'], 'units_per_item' => (int) $r['units_per_item']];
    }

    /** sha256 of the basis (the "hash" of the hash check). @param array<string, mixed> $b */
    public static function hash(array $b): string
    {
        $v = [];
        foreach (self::HASHED as $k) {
            $v[$k] = $b[$k] ?? null;
        }
        return hash('sha256', Idempotency::canonicalJson($v));
    }

    /**
     * Which parts differ between a stored basis and now (PARTS order); [] = unchanged.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $now
     * @return list<string>
     */
    public static function changes(array $stored, array $now): array
    {
        $out = [];
        if (($stored['identity_hash'] ?? null) !== ($now['identity_hash'] ?? null)) {
            $out[] = 'listing_profile';
        }
        if ((int) ($stored['map_version'] ?? -1) !== (int) ($now['map_version'] ?? -2)) {
            $out[] = 'listing_link';
        }
        if (($stored['sku_id'] ?? null) !== ($now['sku_id'] ?? null) || ($stored['item_hash'] ?? null) !== ($now['item_hash'] ?? null)) {
            $out[] = 'item';
        }
        if (($stored['origin_identity_hash'] ?? null) !== ($now['origin_identity_hash'] ?? null)) {
            $out[] = 'item_origin';
        }
        foreach (self::TARGET as $k) {
            $a = $stored[$k] ?? null;
            $b = $now[$k] ?? null;
            if (($a === null) !== ($b === null) || ($a !== null && (string) $a !== (string) $b)) {
                $out[] = 'target_link';
                break;
            }
        }
        return $out;
    }

    /**
     * The stored basis of a proposal, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function of(Db $db, int $proposalId): ?array
    {
        $b = $db->one('SELECT * FROM match_proposal_basis WHERE proposal_id = ?', [$proposalId]);
        return $b === null ? null : self::row($b);
    }

    /**
     * Stored bases of many proposals.
     *
     * @param list<int> $proposalIds
     * @return array<int, array<string, mixed>> proposal id => basis
     */
    public static function ofMany(Db $db, array $proposalIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($proposalIds)), 500) as $chunk) {
            foreach ($db->all('SELECT * FROM match_proposal_basis WHERE proposal_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $b) {
                $out[(int) $b['proposal_id']] = self::row($b);
            }
        }
        return $out;
    }

    /**
     * Records the basis of a proposal (append-only; a second write for the same proposal is ignored). Called inside the
     * transaction that holds the listing's row lock.
     *
     * @param array<string, mixed> $basis current() of the listing, the proposal's item and its lane target
     * @param array<string, mixed>|null $detail backfill: how it was proved
     */
    public static function record(Db $db, int $proposalId, array $basis, string $source = 'recorded', ?array $detail = null): bool
    {
        try {
            $db->exec(
                'INSERT INTO match_proposal_basis (proposal_id, listing_id, map_version, identity_hash, sku_id, item_hash, origin_identity_hash, '
                . 'target_listing_id, target_map_version, target_status, target_sku_id, target_units_per_item, basis_hash, source, detail) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$proposalId, $basis['listing_id'], $basis['map_version'], $basis['identity_hash'], $basis['sku_id'], $basis['item_hash'],
                    $basis['origin_identity_hash'], $basis['target_listing_id'] ?? null, $basis['target_map_version'] ?? null,
                    $basis['target_status'] ?? null, $basis['target_sku_id'] ?? null, $basis['target_units_per_item'] ?? null,
                    self::hash($basis), $source, $detail === null ? null : Idempotency::json($detail)],
            );
            return true;
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Whether a proposal made before bases were recorded can be shown unchanged since it was made, and its basis then.
     * Reads the listing FOR UPDATE and the items FOR SHARE: call it inside a transaction (backfill()).
     *
     * @param array<string, mixed> $p a match_proposal row (id, listing_id, proposed_sku_id, created_at, status, evidence)
     * @return array{basis: ?array<string, mixed>, reason: ?string, detail: array<string, mixed>}
     */
    public static function prove(Db $db, array $p): array
    {
        $pid = (int) $p['id'];
        $listingId = (int) $p['listing_id'];
        $skuId = $p['proposed_sku_id'] === null ? null : (int) $p['proposed_sku_id'];
        $evidence = json_decode((string) ($p['evidence'] ?? 'null'), true);
        $l = $db->one('SELECT map_version FROM channel_listing WHERE id = ? FOR UPDATE', [$listingId]);
        if ($l === null) {
            return ['basis' => null, 'reason' => 'no_listing', 'detail' => []];
        }
        // 1. the listing: still at the version this proposal's own suggest left it at (a proposal made on an unmapped
        //    listing suggests it in the same transaction); any link, status or identity change since moved it on.
        $s = $db->one("SELECT id, expected_map_version FROM match_decision WHERE proposal_id = ? AND listing_id = ? AND action = 'suggest' "
            . "AND state = 'applied' ORDER BY id LIMIT 1", [$pid, $listingId]);
        if ($s === null) {
            return ['basis' => null, 'reason' => 'no_suggest_record', 'detail' => []];
        }
        if ((int) $l['map_version'] !== (int) $s['expected_map_version'] + 1) {
            return ['basis' => null, 'reason' => 'listing_changed_since', 'detail' => ['suggest_decision_id' => (int) $s['id']]];
        }
        $detail = ['suggest_decision_id' => (int) $s['id'], 'proposal_created_at' => (string) $p['created_at']];
        // 2. the item row was not written after the proposal (mint, policy, count and merge all update it).
        if ($skuId !== null) {
            $item = $db->one('SELECT updated_at, origin_listing_id FROM sku WHERE id = ? FOR SHARE', [$skuId]);
            if ($item === null) {
                return ['basis' => null, 'reason' => 'no_item', 'detail' => $detail];
            }
            if ((string) $item['updated_at'] > (string) $p['created_at']) {
                return ['basis' => null, 'reason' => 'item_changed_since', 'detail' => $detail + ['item_updated_at' => (string) $item['updated_at']]];
            }
            $detail['item_updated_at'] = (string) $item['updated_at'];
            // 3. the origin listing's identity: its map_version is the one its last decision left, and that decision is older
            //    than the proposal (an identity change moves the version without a decision).
            if ($item['origin_listing_id'] !== null) {
                $od = self::unchangedSince($db, (int) $item['origin_listing_id'], (string) $p['created_at']);
                if ($od === null) {
                    return ['basis' => null, 'reason' => 'item_origin_unproved', 'detail' => $detail];
                }
                $detail['origin_decision_id'] = $od;
            }
        }
        // 4. the lane target: still linked to the item, and its link and identity as its last decision before the proposal
        //    left them (a relink, an ignore, a unit change or a rename since moved its map_version on).
        if (self::targetVariant($evidence) !== null) {
            $t = self::findTarget($db, $evidence, $skuId);
            if ($t === null) {
                return ['basis' => null, 'reason' => 'target_unproved', 'detail' => $detail];
            }
            $td = self::unchangedSince($db, $t['listing_id'], (string) $p['created_at']);
            if ($td === null) {
                return ['basis' => null, 'reason' => 'target_unproved', 'detail' => $detail];
            }
            $detail['target_decision_id'] = $td;
        }
        return ['basis' => self::current($db, $listingId, $skuId, $evidence), 'reason' => null, 'detail' => $detail];
    }

    /**
     * The id of a listing's last applied decision when its map_version is still the one that decision left and the decision
     * is older than $before; null otherwise (changed since, or no decision).
     */
    private static function unchangedSince(Db $db, int $listingId, string $before): ?int
    {
        $v = $db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$listingId]);
        $d = $db->one('SELECT id, expected_map_version, created_at FROM match_decision WHERE listing_id = ? AND state = \'applied\' '
            . 'AND action IN ' . self::VERSION_ACTIONS . ' ORDER BY id DESC LIMIT 1', [$listingId]);
        if ($v === null || $d === null || (int) $v !== (int) $d['expected_map_version'] + 1 || (string) $d['created_at'] > $before) {
            return null;
        }
        return (int) $d['id'];
    }

    /**
     * Writes the proved basis of every OPEN proposal among $proposalIds that has none (one transaction per proposal).
     * $apply false: nothing is written, the counts say what would be.
     *
     * @param list<int> $proposalIds
     * @return array{recorded: int, backfilled: int, unproved: array<string, int>, proved: array<int, array<string, mixed>>}
     *         proved: proposal id => the basis written (or that would be)
     */
    public static function backfill(Db $db, array $proposalIds, bool $apply): array
    {
        $have = self::ofMany($db, $proposalIds);
        $out = ['recorded' => count($have), 'backfilled' => 0, 'unproved' => [], 'proved' => []];
        foreach ($proposalIds as $pid) {
            if (isset($have[$pid])) {
                continue;
            }
            $r = $db->transaction(static function (Db $db) use ($pid, $apply): array {
                $p = $db->one('SELECT id, listing_id, proposed_sku_id, created_at, status, evidence FROM match_proposal WHERE id = ?', [$pid]);
                if ($p === null || $p['status'] !== 'open') {
                    return ['basis' => null, 'reason' => 'not_open', 'detail' => []];
                }
                $proof = self::prove($db, $p);
                if ($proof['basis'] !== null && $apply) {
                    self::record($db, $pid, $proof['basis'], 'backfill', $proof['detail']);
                }
                return $proof;
            });
            if ($r['basis'] === null) {
                $out['unproved'][(string) $r['reason']] = ($out['unproved'][(string) $r['reason']] ?? 0) + 1;
                continue;
            }
            $out['backfilled']++;
            $out['proved'][$pid] = $r['basis'] + ['source' => 'backfill'];
        }
        ksort($out['unproved']);
        return $out;
    }

    /** @param array<string, mixed> $b a match_proposal_basis row @return array<string, mixed> */
    private static function row(array $b): array
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $str = static fn (mixed $v): ?string => $v === null ? null : (string) $v;
        return ['v' => self::VERSION, 'listing_id' => (int) $b['listing_id'], 'map_version' => (int) $b['map_version'],
            'identity_hash' => $str($b['identity_hash']), 'sku_id' => $int($b['sku_id']), 'item_hash' => $str($b['item_hash']),
            'origin_identity_hash' => $str($b['origin_identity_hash']),
            'target_listing_id' => $int($b['target_listing_id']), 'target_map_version' => $int($b['target_map_version']),
            'target_status' => $str($b['target_status']), 'target_sku_id' => $int($b['target_sku_id']),
            'target_units_per_item' => $int($b['target_units_per_item']),
            'basis_hash' => (string) $b['basis_hash'], 'source' => (string) $b['source'], 'created_at' => (string) $b['created_at']];
    }
}
