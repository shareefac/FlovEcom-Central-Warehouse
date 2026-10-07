<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Db;
use CW\Matching\Gtin;

/**
 * The read side of the /ui screens (all SELECTs; the only writes of the UI are DecisionService's
 * and the auth tables'). Queue rows are the open proposals of listings that still need a person
 * (unmapped or suggested, no decision waiting for a second person), best sellers first: units in
 * 365 days, then 30 days (the 30-day figure of September 2026 is inflated by stockpiling and a
 * promotion, U6).
 */
final class Queries
{
    public const PER_PAGE = 50;
    /**
     * Lanes a queue can be filtered by. `vpg_duplicate` (merge suggestions between two Vape and Go items)
     * is not one: those proposals sit on mapped listings, which no queue lists; they have their own screen,
     * Duplicates (CW\Ui\Duplicates, M34).
     */
    public const LANES = ['barcode', 'transfer', 'candidates'];
    /**
     * How a band is named on the screens. `Manual` is the run's "Manual (relabel)": listings that differ
     * from a Vape and Go item only by a renamed line or brand (an alias no one has confirmed yet).
     */
    public const BAND_LABELS = ['Manual' => 'Relabel (alias)'];
    /** Queue order, best sellers first (U6). */
    private const ORDER = ' ORDER BY COALESCE(lp.units_365d, 0) DESC, COALESCE(lp.units_30d, 0) DESC, cl.id ASC';
    /** Listings that still need a link decision. */
    private const OPEN_STATUS = "cl.status IN ('unmapped', 'suggested')";
    private const NO_PENDING = 'NOT EXISTS (SELECT 1 FROM match_decision pd WHERE pd.pending_listing_id = cl.id)';

    public function __construct(private readonly Db $db)
    {
    }

    public static function bandLabel(string $band): string
    {
        return self::BAND_LABELS[$band] ?? $band;
    }

    /** @return list<array{id: int, code: string, name: string}> */
    public function channels(): array
    {
        /** @var list<array{id: int, code: string, name: string}> */
        return $this->db->all('SELECT id, code, name FROM channel ORDER BY code');
    }

    /** @return array<string, array<int, int>> band => channel id => open proposals still to decide */
    public function bandCounts(): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT p.band, cl.channel_id, COUNT(*) AS n FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id '
            . 'WHERE p.status = \'open\' AND ' . self::OPEN_STATUS . ' AND ' . self::NO_PENDING . ' GROUP BY p.band, cl.channel_id',
        ) as $r) {
            $out[(string) $r['band']][(int) $r['channel_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<int, int> channel id => unmapped listings no run has proposed anything for */
    public function unproposed(): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT cl.channel_id, COUNT(*) AS n FROM channel_listing cl WHERE cl.status = \'unmapped\' AND ' . self::NO_PENDING
            . ' AND NOT EXISTS (SELECT 1 FROM match_proposal p WHERE p.open_listing_id = cl.id) GROUP BY cl.channel_id',
        ) as $r) {
            $out[(int) $r['channel_id']] = (int) $r['n'];
        }
        return $out;
    }

    public function pendingCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM match_decision WHERE state = 'pending_second'");
    }

    /**
     * Per site: listings by status and the units of the last 30 / 365 days (listing_profile) on
     * linked (mapped, quarantined), ignored and other listings. Listings without a profile have no
     * units. Coverage = linked units / all units.
     *
     * @return array<int, array<string, int>> channel id => figures
     */
    public function coverage(): array
    {
        $linked = "cl.status IN ('mapped', 'quarantined')";
        $out = [];
        foreach ($this->db->all(
            'SELECT cl.channel_id, COUNT(*) AS listings, SUM(' . $linked . ') AS linked_listings, '
            . 'COALESCE(SUM(lp.units_30d), 0) AS u30, COALESCE(SUM(IF(' . $linked . ', lp.units_30d, 0)), 0) AS l30, '
            . "COALESCE(SUM(IF(cl.status = 'ignored', lp.units_30d, 0)), 0) AS i30, "
            . 'COALESCE(SUM(lp.units_365d), 0) AS u365, COALESCE(SUM(IF(' . $linked . ', lp.units_365d, 0)), 0) AS l365, '
            . "COALESCE(SUM(IF(cl.status = 'ignored', lp.units_365d, 0)), 0) AS i365 "
            . 'FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id GROUP BY cl.channel_id',
        ) as $r) {
            $out[(int) $r['channel_id']] = array_map('intval', $r);
        }
        return $out;
    }

    /**
     * One page of a band's queue.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function queue(string $band, ?int $channelId, string $q, ?string $lane, int $min30, int $page): array
    {
        [$where, $params] = $this->queueWhere($band, $channelId, $q, $lane, $min30);
        $from = 'FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id JOIN channel ch ON ch.id = cl.channel_id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id LEFT JOIN sku s ON s.id = p.proposed_sku_id WHERE ' . $where;
        $total = (int) $this->db->value('SELECT COUNT(*) ' . $from, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->all(
            'SELECT p.id AS proposal_id, p.listing_id, p.band, p.lane, p.ai_outcome, p.ai_confidence, p.ai_units_per_item, p.proposed_sku_id, '
            . 'p.proposed_new_item, p.flags, cl.channel_id, ch.code AS channel_code, cl.external_variant_id, cl.status, '
            . 'lp.product_title, lp.variant_title, lp.brand, lp.units_30d, lp.units_365d, s.code AS sku_code, s.name AS sku_name '
            . $from . self::ORDER . ' LIMIT ? OFFSET ?',
            [...$params, self::PER_PAGE, ($page - 1) * self::PER_PAGE],
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * The listing to review after $currentId: the next one in queue order (units 365d, units 30d
     * desc, id), else the first of the queue other than $currentId; null when the queue is empty.
     */
    public function nextInQueue(string $band, ?int $channelId, string $q, ?string $lane, int $min30, int $currentId): ?int
    {
        [$where, $params] = $this->queueWhere($band, $channelId, $q, $lane, $min30);
        $from = 'FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE ';
        $order = self::ORDER . ' LIMIT 1';
        $cur = $this->db->one('SELECT COALESCE(units_30d, 0) AS u30, COALESCE(units_365d, 0) AS u365 FROM listing_profile WHERE listing_id = ?', [$currentId])
            ?? ['u30' => 0, 'u365' => 0];
        $u30 = (int) $cur['u30'];
        $u365 = (int) $cur['u365'];
        $after = $this->db->value(
            'SELECT cl.id ' . $from . $where . ' AND (COALESCE(lp.units_365d, 0) < ? OR (COALESCE(lp.units_365d, 0) = ? AND (COALESCE(lp.units_30d, 0) < ? '
            . 'OR (COALESCE(lp.units_30d, 0) = ? AND cl.id > ?))))' . $order,
            [...$params, $u365, $u365, $u30, $u30, $currentId],
        );
        if ($after !== null) {
            return (int) $after;
        }
        $first = $this->db->value('SELECT cl.id ' . $from . $where . ' AND cl.id <> ?' . $order, [...$params, $currentId]);
        return $first === null ? null : (int) $first;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function queueWhere(string $band, ?int $channelId, string $q, ?string $lane, int $min30): array
    {
        $where = ["p.status = 'open'", 'p.band = ?', self::OPEN_STATUS, self::NO_PENDING];
        $params = [$band];
        if ($channelId !== null) {
            $where[] = 'cl.channel_id = ?';
            $params[] = $channelId;
        }
        if ($lane !== null) {
            $where[] = 'p.lane = ?';
            $params[] = $lane;
        }
        if ($min30 > 0) {
            $where[] = 'COALESCE(lp.units_30d, 0) >= ?';
            $params[] = $min30;
        }
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . self::escapeLike($q) . '%';
            $where[] = '(lp.product_title LIKE ? OR lp.variant_title LIKE ? OR lp.brand LIKE ? OR cl.external_variant_id = ? '
                . 'OR JSON_SEARCH(lp.barcodes, \'one\', ?) IS NOT NULL)';
            array_push($params, $like, $like, $like, $q, self::escapeLike($q));
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * The columns of a waiting decision the approver must see: what it does (action, item, u, the identity
     * card a new_item will mint: detail), why it waits, who decided, and whether it went stale (the
     * listing's map_version or its open proposal changed since; approve() refuses those).
     */
    private const PENDING_COLUMNS = 'd.id, d.listing_id, d.action, d.sku_id, d.units_per_item, d.merge_from_sku_id, d.needs_second, d.reason, '
        . 'd.decided_by, d.created_at, d.expected_map_version, d.proposal_id, d.detail, u.display_name AS decider, cl.map_version, '
        . '(SELECT op.id FROM match_proposal op WHERE op.open_listing_id = d.listing_id) AS open_proposal_id, '
        . 's.code AS sku_code, s.name AS sku_name, mf.code AS merge_from_code, mf.name AS merge_from_name, '
        . 'd.prev_sku_id, ps.code AS prev_sku_code, ps.name AS prev_sku_name ';
    private const PENDING_JOINS = 'JOIN channel_listing cl ON cl.id = d.listing_id LEFT JOIN staff_user u ON u.id = d.decided_by '
        . 'LEFT JOIN sku s ON s.id = d.sku_id LEFT JOIN sku mf ON mf.id = d.merge_from_sku_id LEFT JOIN sku ps ON ps.id = d.prev_sku_id ';

    /** Pending second-approval decisions, oldest first. @return list<array<string, mixed>> */
    public function pendingDecisions(int $limit = 200): array
    {
        return $this->db->all(
            'SELECT ' . self::PENDING_COLUMNS . ', cl.external_variant_id, ch.code AS channel_code, lp.product_title, lp.variant_title, lp.units_30d '
            . 'FROM match_decision d ' . self::PENDING_JOINS . 'JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            . "WHERE d.state = 'pending_second' ORDER BY d.id ASC LIMIT ?",
            [$limit],
        );
    }

    /** The decision waiting for a second person on a listing, if any. @return array<string, mixed>|null */
    public function pendingOf(int $listingId): ?array
    {
        return $this->db->one('SELECT ' . self::PENDING_COLUMNS . 'FROM match_decision d ' . self::PENDING_JOINS . 'WHERE d.pending_listing_id = ?', [$listingId]);
    }

    /** @return array<string, mixed>|null the listing with its site, profile and features */
    public function listing(int $id): ?array
    {
        return $this->db->one(
            'SELECT cl.id, cl.channel_id, ch.code AS channel_code, ch.name AS channel_name, cl.external_variant_id, cl.sku_id, cl.units_per_item, '
            . 'cl.status, cl.map_version, cl.created_at, lp.product_title, lp.variant_title, lp.brand, lp.attributes, lp.barcodes, lp.price, '
            . 'lp.perma_link, lp.units_30d, lp.units_365d, lp.features, lp.pushed_at '
            . 'FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id = ?',
            [$id],
        );
    }

    /** @return array<string, mixed>|null the listing's open proposal (any band) */
    public function openProposal(int $listingId): ?array
    {
        return $this->db->one(
            'SELECT p.id, p.match_run_id, p.proposed_sku_id, p.proposed_new_item, p.band, p.lane, p.ai_outcome, p.ai_confidence, p.ai_units_per_item, '
            . 'p.ai_model, p.closest_sku_id, p.evidence, p.flags, p.created_at, r.run_id FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id '
            . "WHERE p.open_listing_id = ?",
            [$listingId],
        );
    }

    /** @return array<string, mixed>|null a proposal of one listing (open or not) */
    public function proposalOf(int $listingId, int $proposalId): ?array
    {
        return $this->db->one('SELECT id, proposed_sku_id, proposed_new_item, status FROM match_proposal WHERE id = ? AND listing_id = ?', [$proposalId, $listingId]);
    }

    /**
     * Items this listing was rejected for (match_reject), and the items those were merged into (the same
     * product since: DecisionService counts a reject of either). @return list<int>
     */
    public function rejectedOf(int $listingId): array
    {
        return array_map('intval', $this->db->column(
            'WITH RECURSIVE up (id) AS (SELECT sku_id FROM match_reject WHERE listing_id = ? UNION '
            . 'SELECT s.merged_into_sku_id FROM sku s JOIN up ON s.id = up.id WHERE s.merged_into_sku_id IS NOT NULL) SELECT id FROM up',
            [$listingId],
        ));
    }

    /** The last decisions on a listing. @return list<array<string, mixed>> */
    public function decisionsOf(int $listingId, int $limit = 10): array
    {
        return $this->db->all(
            'SELECT d.id, d.action, d.state, d.sku_id, d.units_per_item, d.reason, d.created_at, d.applied_at, u.display_name AS decider, s.code AS sku_code '
            . 'FROM match_decision d LEFT JOIN staff_user u ON u.id = d.decided_by LEFT JOIN sku s ON s.id = d.sku_id WHERE d.listing_id = ? ORDER BY d.id DESC LIMIT ?',
            [$listingId, $limit],
        );
    }

    /** @return array<string, mixed>|null */
    public function sku(int $id): ?array
    {
        return $this->db->one(
            'SELECT id, code, name, brand, sell_policy, strength_mg, nic_type, line, form, flavour, volume_ml, puffs, pack_units, origin, '
            . 'origin_listing_id, merged_into_sku_id, counted_at, created_at FROM sku WHERE id = ?',
            [$id],
        );
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> id => sku row (the columns of sku())
     */
    public function skus(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT s.id, s.code, s.name, s.brand, s.sell_policy, s.strength_mg, s.nic_type, s.line, s.form, s.flavour, s.volume_ml, s.puffs, s.pack_units, '
            . 's.merged_into_sku_id, s.origin, s.origin_listing_id, IF(s.origin = \'vpg_mint\', ol.external_variant_id, NULL) AS vpg_variant_id '
            . 'FROM sku s LEFT JOIN channel_listing ol ON ol.id = s.origin_listing_id WHERE s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        ) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /**
     * Confirmed brand aliases (alias kind brand, confirmed by a mapping lead), term => canonical, both
     * lower-case: the review screen's brand comparison maps both sides through them.
     *
     * @return array<string, string>
     */
    public function brandAliases(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT term, canonical FROM alias WHERE kind = 'brand' AND status = 'confirmed'") as $r) {
            $out[mb_strtolower(trim((string) $r['term']))] = mb_strtolower(trim((string) $r['canonical']));
        }
        return $out;
    }

    /**
     * Where else these barcodes are: items (sku_barcode) and the profiles of other listings, on any site,
     * linked or not (e.g. a Vape and Go variant that was never minted because the site binned it). Uses
     * the multi-valued index on listing_profile.barcodes (0005). At most $limit listings.
     *
     * @param list<string> $barcodes
     * @return array{items: list<array<string, mixed>>, listings: list<array<string, mixed>>}
     */
    public function barcodeElsewhere(int $listingId, array $barcodes, int $limit = 10): array
    {
        $codes = [];
        foreach (array_slice($barcodes, 0, 20) as $b) {
            $codes[$b] = true;
            $key = Gtin::key($b);
            if ($key !== null) {
                $codes[$key] = true;
            }
        }
        $codes = array_map('strval', array_keys($codes));
        if ($codes === []) {
            return ['items' => [], 'listings' => []];
        }
        $in = implode(',', array_fill(0, count($codes), '?'));
        $items = $this->db->all(
            'SELECT b.barcode, s.id, s.code, s.name, s.merged_into_sku_id FROM sku_barcode b JOIN sku s ON s.id = b.sku_id '
            . "WHERE b.barcode IN ({$in}) ORDER BY s.id LIMIT ?",
            [...$codes, $limit],
        );
        $listings = $this->db->all(
            'SELECT cl.id, ch.code AS channel_code, cl.external_variant_id, cl.status, s.code AS sku_code, lp.product_title, lp.variant_title, '
            . "JSON_UNQUOTE(JSON_EXTRACT(lp.features, '$.variant_status')) AS variant_status, lp.units_365d "
            . 'FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN sku s ON s.id = cl.sku_id '
            . 'WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON)) AND lp.listing_id <> ? ORDER BY ch.code, cl.id LIMIT ?',
            [json_encode($codes, JSON_THROW_ON_ERROR), $listingId, $limit],
        );
        return ['items' => $items, 'listings' => $listings];
    }


    /** @return list<array<string, mixed>> */
    public function barcodesOf(int $skuId): array
    {
        return $this->db->all('SELECT barcode, is_usable, units_per_scan, source FROM sku_barcode WHERE sku_id = ? ORDER BY barcode', [$skuId]);
    }

    /**
     * Usable barcodes of several items at once: their sku_barcode rows; for an item that has none, the
     * usable GTINs on the profile of the listing it was minted from (an item minted before its barcodes
     * were seeded, bin/seed_barcodes.php, still shows them).
     *
     * @param list<int> $ids
     * @return array<int, list<string>>
     */
    public function barcodesOfMany(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->db->all("SELECT sku_id, barcode FROM sku_barcode WHERE is_usable = 1 AND sku_id IN ({$in}) ORDER BY barcode", $ids) as $r) {
            $out[(int) $r['sku_id']][] = (string) $r['barcode'];
        }
        $missing = array_values(array_filter($ids, static fn (int $id): bool => !isset($out[$id])));
        if ($missing !== []) {
            foreach ($this->db->all(
                'SELECT s.id, lp.barcodes FROM sku s JOIN listing_profile lp ON lp.listing_id = s.origin_listing_id WHERE s.id IN ('
                . implode(',', array_fill(0, count($missing), '?')) . ') AND NOT EXISTS (SELECT 1 FROM sku_barcode b WHERE b.sku_id = s.id)',
                $missing,
            ) as $r) {
                $codes = [];
                foreach (Html::strings(Html::json($r['barcodes'])) as $b) {
                    $g = Gtin::classify($b);
                    if ($g['usable']) {
                        $codes[] = (string) $g['key'];
                    }
                }
                if ($codes !== []) {
                    sort($codes, SORT_STRING);
                    $out[(int) $r['id']] = array_values(array_unique($codes));
                }
            }
        }
        return $out;
    }

    /**
     * Items matching a typed search: a CW code (CW-000123 or its number), a barcode, or every
     * word in the name / brand / line / flavour. Merged-away items are left out.
     *
     * @return list<array<string, mixed>>
     */
    public function searchSkus(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $ids = [];
        if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $q, $m) === 1) {
            $ids = array_merge($ids, $this->db->column('SELECT id FROM sku WHERE id = ?', [(int) $m[1]]));
        }
        if (preg_match('/^[0-9]{6,64}$/D', $q) === 1) {
            // sku_barcode holds the GTIN key (Gtin::key: digits, no leading zeros), as a scanner may add them.
            $ids = array_merge($ids, $this->db->column('SELECT sku_id FROM sku_barcode WHERE barcode IN (?, ?)', [$q, Gtin::key($q) ?? $q]));
        }
        $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);
        $where = [];
        $params = [];
        foreach ($words as $w) {
            $where[] = "CONCAT_WS(' ', name, brand, line, flavour) LIKE ?";
            $params[] = '%' . self::escapeLike($w) . '%';
        }
        if ($where !== []) {
            $params[] = $limit;
            $ids = array_merge($ids, $this->db->column(
                'SELECT id FROM sku WHERE merged_into_sku_id IS NULL AND ' . implode(' AND ', $where) . ' ORDER BY name LIMIT ?',
                $params,
            ));
        }
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, $limit);
        $rows = $this->skus($ids);
        $out = [];
        foreach ($ids as $id) {
            if (isset($rows[$id]) && $rows[$id]['merged_into_sku_id'] === null) {
                $out[] = $rows[$id];
            }
        }
        return $out;
    }

    /**
     * Listings matching a typed search: site variant id, barcode, or every word in the titles / brand.
     *
     * @return list<array<string, mixed>>
     */
    public function searchListings(string $q, int $limit = 30): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);
        $or = ['cl.external_variant_id = ?', 'JSON_SEARCH(lp.barcodes, \'one\', ?) IS NOT NULL'];
        $params = [$q, self::escapeLike($q)];
        $and = [];
        $andParams = [];
        foreach ($words as $w) {
            $and[] = "CONCAT_WS(' ', lp.product_title, lp.variant_title, lp.brand) LIKE ?";
            $andParams[] = '%' . self::escapeLike($w) . '%';
        }
        $or[] = '(' . implode(' AND ', $and) . ')';
        return $this->db->all(
            'SELECT cl.id, cl.status, cl.sku_id, cl.external_variant_id, ch.code AS channel_code, lp.product_title, lp.variant_title, lp.units_30d, s.code AS sku_code '
            . 'FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id LEFT JOIN sku s ON s.id = cl.sku_id '
            . 'WHERE ' . implode(' OR ', $or) . ' ORDER BY COALESCE(lp.units_30d, 0) DESC, cl.id LIMIT ?',
            [...$params, ...$andParams, $limit],
        );
    }

    /**
     * Listings linked to an item now or in the past, with their site and profile.
     *
     * @return list<array<string, mixed>>
     */
    public function listingsOfSku(int $skuId, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT cl.id, cl.channel_id, ch.code AS channel_code, cl.external_variant_id, cl.sku_id, cl.units_per_item, cl.status, cl.map_version, '
            . 'lp.product_title, lp.variant_title, lp.units_30d, lp.units_365d FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            . 'WHERE cl.sku_id = ? OR cl.id IN (SELECT h.listing_id FROM listing_map_history h WHERE h.sku_id = ?) '
            . 'ORDER BY (cl.sku_id = ?) DESC, ch.code, cl.id LIMIT ?',
            [$skuId, $skuId, $skuId, $limit],
        );
    }

    /**
     * The link periods of some listings, newest first per listing.
     *
     * @param list<int> $listingIds
     * @return array<int, list<array<string, mixed>>> listing id => periods
     */
    public function historyOf(array $listingIds, int $limit = 1000): array
    {
        if ($listingIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT h.listing_id, h.sku_id, h.units_per_item, h.valid_from, h.valid_to, h.decision_id, d.action, u.display_name AS decider, s.code AS sku_code '
            . 'FROM listing_map_history h JOIN match_decision d ON d.id = h.decision_id LEFT JOIN staff_user u ON u.id = d.decided_by JOIN sku s ON s.id = h.sku_id '
            . 'WHERE h.listing_id IN (' . implode(',', array_fill(0, count($listingIds), '?')) . ') ORDER BY h.listing_id, h.valid_from DESC, h.id DESC LIMIT ?',
            [...$listingIds, $limit],
        ) as $r) {
            $out[(int) $r['listing_id']][] = $r;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> stock buckets of an item per warehouse */
    public function stockOf(int $skuId): array
    {
        return $this->db->all(
            'SELECT w.id AS warehouse_id, w.code, w.name, w.is_sellable, sb.on_hand, sb.allocated, sb.held, sb.on_hand - sb.allocated - sb.held AS available, sb.counted_at '
            . 'FROM stock_balance sb JOIN warehouse w ON w.id = sb.warehouse_id WHERE sb.sku_id = ? ORDER BY w.id',
            [$skuId],
        );
    }

    /**
     * The newest ledger rows of an item across warehouses. stock_ledger is indexed by
     * (warehouse, sku, id), so each warehouse is read on its own index range, then merged.
     *
     * @return list<array<string, mixed>>
     */
    public function ledgerOf(int $skuId, int $limit = 30): array
    {
        $rows = [];
        foreach ($this->db->column('SELECT warehouse_id FROM stock_balance WHERE sku_id = ?', [$skuId]) as $wid) {
            array_push($rows, ...$this->db->all(
                'SELECT l.id, w.code AS warehouse, l.bucket, l.qty_delta, l.balance_after, l.movement_type, l.order_ref, l.doc_ref, l.actor, l.note, '
                . 'l.effective_at, l.created_at FROM stock_ledger l JOIN warehouse w ON w.id = l.warehouse_id WHERE l.warehouse_id = ? AND l.sku_id = ? ORDER BY l.id DESC LIMIT ?',
                [(int) $wid, $skuId, $limit],
            ));
        }
        usort($rows, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
        return array_slice($rows, 0, $limit);
    }

    /** Escapes LIKE wildcards for a `LIKE ? ` (MySQL's default escape character is the backslash). */
    public static function escapeLike(string $s): string
    {
        return addcslashes($s, '\\%_');
    }
}
