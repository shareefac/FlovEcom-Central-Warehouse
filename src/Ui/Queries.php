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
     * How a band is named on the screens: Words::BAND for all six (plan F170; the URL values queue=Key ... stay). `Manual` is
     * the run's "Manual (relabel)": listings that differ from a Vape and Go item only by a renamed range ("Renamed range").
     */
    public const BAND_LABELS = Words::BAND;
    /** Queue order, best sellers first (U6). */
    private const ORDER = ' ORDER BY COALESCE(lp.units_365d, 0) DESC, COALESCE(lp.units_30d, 0) DESC, cl.id ASC';
    /** Listings that still need a link decision. */
    private const OPEN_STATUS = "cl.status IN ('unmapped', 'suggested')";
    private const NO_PENDING = 'NOT EXISTS (SELECT 1 FROM match_decision pd WHERE pd.pending_listing_id = cl.id)';
    /** For a query that reads only the units of listing_profile `lp`: read them from ix_listing_profile_units (0020), not the wide row. */
    private const UNITS_HINT = '/*+ INDEX(lp ix_listing_profile_units) */ ';

    public function __construct(private readonly Db $db)
    {
    }

    public static function bandLabel(string $band): string
    {
        return Words::of('BAND', $band);
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

    /** Decisions waiting for a second approval that $staffId may give: not their own (the menu badge of a matching lead). */
    public function pendingCountFor(int $staffId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM match_decision WHERE state = 'pending_second' AND (decided_by IS NULL OR decided_by <> ?)",
            [$staffId],
        );
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
            // The units from the narrow index (0020): the optimizer would read every profile row (its JSON) by the primary key. A hint
            // naming an index that is not there yet is ignored (a warning), so this runs before the migration too.
            'SELECT ' . self::UNITS_HINT . 'cl.channel_id, COUNT(*) AS listings, SUM(' . $linked . ') AS linked_listings, '
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
        // The count joins the profile only when a filter reads it: the site (an inner join on a foreign key), the profile and the
        // item (left joins on a primary key) never change how many rows there are, and the profile's rows are wide (their JSON).
        $profile = $min30 > 0 || trim($q) !== '' ? 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id ' : '';
        $total = (int) $this->db->value('SELECT ' . ($profile !== '' && trim($q) === '' ? self::UNITS_HINT : '')
            . 'COUNT(*) FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id ' . $profile
            . 'WHERE ' . $where, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        // The page's 50 in queue order first, on narrow index columns only (the units come from ix_listing_profile_units, 0020),
        // then their columns: the sort no longer carries every candidate's titles, flags and item.
        $ids = array_map('intval', $this->db->column(
            'SELECT ' . (trim($q) === '' ? self::UNITS_HINT : '') . 'p.id FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            . 'WHERE ' . $where . self::ORDER . ' LIMIT ? OFFSET ?',
            [...$params, self::PER_PAGE, ($page - 1) * self::PER_PAGE],
        ));
        $rows = $ids === [] ? [] : $this->db->all(
            'SELECT p.id AS proposal_id, p.listing_id, p.band, p.lane, p.ai_outcome, p.ai_confidence, p.ai_units_per_item, p.proposed_sku_id, '
            . 'p.proposed_new_item, p.flags, cl.channel_id, ch.code AS channel_code, cl.external_variant_id, cl.status, cl.map_version, '
            . 'lp.product_title, lp.variant_title, lp.brand, lp.units_30d, lp.units_365d, s.code AS sku_code, s.name AS sku_name '
            . 'FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id JOIN channel ch ON ch.id = cl.channel_id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id LEFT JOIN sku s ON s.id = p.proposed_sku_id WHERE ' . $where
            . ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' . self::ORDER,
            [...$params, ...$ids],
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
        $hint = trim($q) === '' ? self::UNITS_HINT : ''; // a text filter reads the titles: the wide row anyway
        $after = $this->db->value(
            'SELECT ' . $hint . 'cl.id ' . $from . $where . ' AND (COALESCE(lp.units_365d, 0) < ? OR (COALESCE(lp.units_365d, 0) = ? AND (COALESCE(lp.units_30d, 0) < ? '
            . 'OR (COALESCE(lp.units_30d, 0) = ? AND cl.id > ?))))' . $order,
            [...$params, $u365, $u365, $u30, $u30, $currentId],
        );
        if ($after !== null) {
            return (int) $after;
        }
        $first = $this->db->value('SELECT ' . $hint . 'cl.id ' . $from . $where . ' AND cl.id <> ?' . $order, [...$params, $currentId]);
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

    /** Pending second-approval decisions, oldest first; of one store's website products when $channelId is given. @return list<array<string, mixed>> */
    public function pendingDecisions(int $limit = 200, ?int $channelId = null): array
    {
        return $this->db->all(
            'SELECT ' . self::PENDING_COLUMNS . ', d.bulk_batch_id, cl.external_variant_id, ch.code AS channel_code, lp.product_title, lp.variant_title, lp.units_30d '
            . 'FROM match_decision d ' . self::PENDING_JOINS . 'JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            . "WHERE d.state = 'pending_second'" . ($channelId !== null ? ' AND cl.channel_id = ?' : '') . ' ORDER BY d.id ASC LIMIT ?',
            $channelId !== null ? [$channelId, $limit] : [$limit],
        );
    }

    // ------------------------------------------------------------------------------------------
    // Store-wise review (docs/decisions.md U106-U110): the store selector, the "By store" overview, Store Products
    // ------------------------------------------------------------------------------------------

    /** Store Products' state filter (a website product's link state, in this order of precedence). */
    public const STORE_STATES = ['waiting', 'quarantined', 'ignored', 'linked', 'suggested', 'not_matched'];
    /** Store Products' sorts: units in 30 days or in 1 year, best sellers first. */
    public const STORE_SORTS = ['sold_30' => 'units_30d', 'sold_365' => 'units_365d'];

    /**
     * The stores: the websites (channel rows) that have website products in CW, by name. A website with none has nothing to match
     * (no store is named in the code: the channel table says which there are).
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function stores(): array
    {
        /** @var list<array{id: int, code: string, name: string}> */
        return $this->db->all('SELECT c.id, c.code, c.name FROM channel c WHERE EXISTS (SELECT 1 FROM channel_listing cl WHERE cl.channel_id = c.id) ORDER BY c.name, c.id');
    }

    /**
     * Per store and match strength: the website products whose latest suggestion (a merge suggestion between two warehouse products
     * left out) is of that strength, how many of them are matched now, and the units they sold in 30 days (all, and on the matched
     * ones). The rest of a store's products (no suggestion ever) is the store's coverage() minus these.
     *
     * @return array<int, array<string, array{listings: int, linked: int, u30: int, l30: int}>> channel id => band => figures
     */
    public function storeBands(): array
    {
        $linked = "cl.status IN ('mapped', 'quarantined')";
        $dup = \CW\Mapping\DecisionService::duplicateLaneSql('p.lane');
        $dup2 = \CW\Mapping\DecisionService::duplicateLaneSql('pn.lane');
        $out = [];
        foreach ($this->db->all(
            'SELECT ' . self::UNITS_HINT . 'cl.channel_id, p.band, COUNT(*) AS listings, SUM(' . $linked . ') AS linked, '
            . 'COALESCE(SUM(lp.units_30d), 0) AS u30, COALESCE(SUM(IF(' . $linked . ', lp.units_30d, 0)), 0) AS l30 '
            . 'FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            // A proposal without a lane is no merge suggestion (COALESCE: NOT NULL would drop it).
            . "WHERE NOT COALESCE({$dup}, FALSE) AND NOT EXISTS (SELECT 1 FROM match_proposal pn WHERE pn.listing_id = p.listing_id AND pn.id > p.id "
            . "AND NOT COALESCE({$dup2}, FALSE)) "
            . 'GROUP BY cl.channel_id, p.band',
        ) as $r) {
            $out[(int) $r['channel_id']][(string) $r['band']] = ['listings' => (int) $r['listings'], 'linked' => (int) $r['linked'],
                'u30' => (int) $r['u30'], 'l30' => (int) $r['l30']];
        }
        return $out;
    }

    /** @return array<int, int> channel id => its website products (the (channel_id, status) index alone) */
    public function productsByStore(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT channel_id, COUNT(*) AS n FROM channel_listing GROUP BY channel_id') as $r) {
            $out[(int) $r['channel_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<int, int> channel id => decisions waiting for a second OK on its website products */
    public function pendingByStore(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT cl.channel_id, COUNT(*) AS n FROM match_decision d JOIN channel_listing cl ON cl.id = d.listing_id "
            . "WHERE d.state = 'pending_second' GROUP BY cl.channel_id") as $r) {
            $out[(int) $r['channel_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<int, int> channel id => matches of spot checks still to answer (their website product not matched yet) */
    public function spotOpenByStore(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT cl.channel_id, COUNT(DISTINCT m.listing_id) AS n FROM key_sample_member m JOIN channel_listing cl ON cl.id = m.listing_id "
            . "WHERE m.position IS NOT NULL AND cl.status IN ('unmapped', 'suggested') GROUP BY cl.channel_id") as $r) {
            $out[(int) $r['channel_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** The spot checks that have a match on a store's website products. @return list<int> sample ids */
    public function samplesOfStore(int $channelId): array
    {
        return array_map('intval', $this->db->column('SELECT DISTINCT m.sample_id FROM key_sample_member m JOIN channel_listing cl ON cl.id = m.listing_id '
            . 'WHERE m.position IS NOT NULL AND cl.channel_id = ?', [$channelId]));
    }

    /** The SQL of a website product's link state (STORE_STATES; `pd` the pending decision, `p` the open suggestion, joined): the page's rows. */
    private const STATE_SQL = "CASE WHEN pd.id IS NOT NULL THEN 'waiting' WHEN cl.status = 'quarantined' THEN 'quarantined' WHEN cl.status = 'ignored' THEN 'ignored' "
        . "WHEN cl.status = 'mapped' THEN 'linked' WHEN p.id IS NOT NULL THEN 'suggested' ELSE 'not_matched' END";
    private const STATE_JOINS = 'LEFT JOIN match_decision pd ON pd.pending_listing_id = cl.id LEFT JOIN match_proposal p ON p.open_listing_id = cl.id ';
    private const NOT_WAITING = 'NOT EXISTS (SELECT 1 FROM match_decision pw WHERE pw.pending_listing_id = cl.id)';
    /**
     * One state as a filter that the (channel_id, status) index narrows: no CASE over every product of the store (the store's
     * products are tens of thousands; a pending decision or an open suggestion is looked up only for the ones the status leaves).
     */
    private const STATE_WHERE = [
        'waiting' => 'EXISTS (SELECT 1 FROM match_decision pw WHERE pw.pending_listing_id = cl.id)',
        'quarantined' => "cl.status = 'quarantined' AND " . self::NOT_WAITING,
        'ignored' => "cl.status = 'ignored' AND " . self::NOT_WAITING,
        'linked' => "cl.status = 'mapped' AND " . self::NOT_WAITING,
        'suggested' => "cl.status IN ('unmapped', 'suggested') AND EXISTS (SELECT 1 FROM match_proposal po WHERE po.open_listing_id = cl.id) AND " . self::NOT_WAITING,
        'not_matched' => "cl.status IN ('unmapped', 'suggested') AND NOT EXISTS (SELECT 1 FROM match_proposal po WHERE po.open_listing_id = cl.id) AND "
            . self::NOT_WAITING,
    ];

    /**
     * How many of a store's website products are in each state (STORE_STATES), from three narrow reads: the listings by status (the
     * (channel_id, status) index alone), the pending decisions by the status of their listing, and the open suggestions of the
     * products waiting for a decision without one pending.
     *
     * @return array<string, int>
     */
    public function storeStateCounts(int $channelId): array
    {
        $by = array_fill_keys(['unmapped', 'suggested', 'mapped', 'ignored', 'quarantined'], 0);
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM channel_listing WHERE channel_id = ? GROUP BY status', [$channelId]) as $r) {
            $by[(string) $r['status']] = (int) $r['n'];
        }
        $waiting = array_fill_keys(array_keys($by), 0);
        foreach ($this->db->all("SELECT cl.status, COUNT(*) AS n FROM match_decision pd JOIN channel_listing cl ON cl.id = pd.listing_id "
            . "WHERE pd.state = 'pending_second' AND cl.channel_id = ? GROUP BY cl.status", [$channelId]) as $r) {
            $waiting[(string) $r['status']] = (int) $r['n'];
        }
        $suggested = (int) $this->db->value("SELECT COUNT(*) FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id WHERE p.status = 'open' "
            . "AND cl.channel_id = ? AND cl.status IN ('unmapped', 'suggested') AND " . self::NOT_WAITING, [$channelId]);
        return [
            'waiting' => array_sum($waiting),
            'quarantined' => $by['quarantined'] - $waiting['quarantined'],
            'ignored' => $by['ignored'] - $waiting['ignored'],
            'linked' => $by['mapped'] - $waiting['mapped'],
            'suggested' => $suggested,
            'not_matched' => $by['unmapped'] + $by['suggested'] - $waiting['unmapped'] - $waiting['suggested'] - $suggested,
        ];
    }

    /**
     * One page of a store's website products with their link state (Store Products): the matched product and its units per sale,
     * the open suggestion and its strength, the decision waiting for a second OK. Best sellers first by $sort (STORE_SORTS), then
     * id; filtered by state and by a text (title, brand, option number, barcode). $counts (storeStateCounts) gives the total of an
     * unfiltered or state-filtered page without a count of its own.
     *
     * @param array<string, int>|null $counts
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function storeProducts(int $channelId, ?string $state, string $q, string $sort, int $page, ?array $counts = null): array
    {
        $where = ['cl.channel_id = ?'];
        $params = [$channelId];
        $state = $state !== null && isset(self::STATE_WHERE[$state]) ? $state : null;
        if ($state !== null) {
            $where[] = self::STATE_WHERE[$state];
        }
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . self::escapeLike($q) . '%';
            $where[] = '(lp.product_title LIKE ? OR lp.variant_title LIKE ? OR lp.brand LIKE ? OR cl.external_variant_id = ? '
                . 'OR JSON_SEARCH(lp.barcodes, \'one\', ?) IS NOT NULL)';
            array_push($params, $like, $like, $like, $q, self::escapeLike($q));
        }
        $col = self::STORE_SORTS[$sort] ?? 'units_30d';
        $other = $col === 'units_30d' ? 'units_365d' : 'units_30d';
        $order = " ORDER BY COALESCE(lp.{$col}, 0) DESC, COALESCE(lp.{$other}, 0) DESC, cl.id ASC";
        $hint = $q === '' ? self::UNITS_HINT : '';
        $from = 'FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE ' . implode(' AND ', $where);
        if ($q === '' && $counts !== null) {
            $total = $state === null ? array_sum($counts) : ($counts[$state] ?? 0);
        } else {
            // Without a text the profile is not read: the store's listings by the (channel_id, status) index.
            $total = (int) $this->db->value('SELECT COUNT(*) ' . ($q === '' ? 'FROM channel_listing cl WHERE ' . implode(' AND ', $where) : $from), $params);
        }
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $ids = array_map('intval', $this->db->column('SELECT ' . $hint . 'cl.id ' . $from . $order . ' LIMIT ? OFFSET ?',
            [...$params, self::PER_PAGE, ($page - 1) * self::PER_PAGE]));
        $rows = $ids === [] ? [] : $this->db->all(
            'SELECT cl.id AS listing_id, cl.status, cl.sku_id, cl.units_per_item, cl.map_version, cl.external_variant_id, ' . self::STATE_SQL . ' AS state, '
            . 'lp.product_title, lp.variant_title, lp.brand, lp.units_30d, lp.units_365d, s.code AS sku_code, s.name AS sku_name, '
            . 'p.id AS proposal_id, p.band, p.proposed_sku_id, p.proposed_new_item, ps.code AS proposed_code, ps.name AS proposed_name, '
            . 'pd.id AS pending_id, pd.action AS pending_action '
            . 'FROM channel_listing cl ' . self::STATE_JOINS . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id LEFT JOIN sku s ON s.id = cl.sku_id '
            . 'LEFT JOIN sku ps ON ps.id = p.proposed_sku_id WHERE cl.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' . $order,
            $ids,
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    // ------------------------------------------------------------------------------------------
    // Products › By Item (docs/decisions.md U113-U122): one row per warehouse product, one column per store, and the picker.
    // The screen itself speaks of ITEMS and VARIANTS (the owner's words, U122); these reads say warehouse product and website
    // product, as the rest of this class does: an item is a sku row, a variant a channel_listing row.
    // ------------------------------------------------------------------------------------------

    /** By Item's coverage filters: matched on every store, missing on one store, with a suggestion waiting. */
    public const COVERAGE = ['every', 'missing', 'suggested'];
    /** The picker's page (By Item › "Match…"): a small one, best sellers first. */
    public const PICK_PER_PAGE = 20;
    /** A website product that still waits for a match: not matched, not ignored, no decision waiting for a second OK. */
    private const WAITING_LISTING = "cl.status IN ('unmapped', 'suggested') AND " . self::NOT_WAITING;
    /**
     * The two indexes of 0024, named where the optimizer would otherwise read rows it does not need (a store's listing rows to
     * learn their product; a proposal's row, with its evidence, to learn its product). A hint naming an index that is not there
     * yet is ignored, as UNITS_HINT: the code may run a moment before the migration.
     */
    private const STORE_INDEX_HINT = '/*+ INDEX(cl ix_channel_listing_sku_channel) */ ';
    private const OPEN_SKU_HINT = '/*+ INDEX(p ix_match_proposal_open_sku) */ ';
    /** The stores a product is matched on are read as bits of one number, one bit per store: as many stores as fit (else by name list). */
    private const STORE_BITS = 62;

    /**
     * By Item's columns: every website of the channel table, by name. Unlike stores() (the store selector, U106) a store whose
     * product list is not loaded yet is a column too: every product is "Not on this store" there. No store is named in the code.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function everyStore(): array
    {
        /** @var list<array{id: int, code: string, name: string}> */
        return $this->db->all('SELECT id, code, name FROM channel ORDER BY name, id');
    }

    /**
     * By Item's filter entries: how many warehouse products there are (a product joined into another one is left out
     * everywhere on this page), on how many of them each store has a matched website product, how many are matched on every store,
     * and how many have a suggestion waiting. Three narrow reads: the products (ix_sku_merged_into), the matched website products
     * grouped by product and then by their set of stores (the (sku_id, channel_id) index of 0024 alone; a website product is never
     * matched to a product that was joined away: DecisionService moves it with the join), and the open suggestions (the
     * (status, proposed_sku_id, …) index of 0024).
     *
     * @param list<int> $storeIds the stores (everyStore())
     * @return array{total: int, every: int, matched: array<int, int>, suggested: int} matched: store id => products matched on it
     */
    public function productCoverage(array $storeIds): array
    {
        $total = (int) $this->db->value('SELECT COUNT(*) FROM sku WHERE merged_into_sku_id IS NULL');
        $matched = array_fill_keys($storeIds, 0);
        $every = 0;
        $bits = count($storeIds) <= self::STORE_BITS;
        // Per product the set of its stores: a number with one bit per store (the store's place in $storeIds), or, for more
        // stores than a number has bits, the list of their ids. Then how many products have each set: a handful of rows.
        $sets = $storeIds === [] ? [] : $this->db->all(
            'SELECT t.stores, COUNT(*) AS n FROM (SELECT cl.sku_id, ' . ($bits
                ? 'BIT_OR(1 << FIELD(cl.channel_id, ' . implode(', ', array_fill(0, count($storeIds), '?')) . '))'
                : 'GROUP_CONCAT(DISTINCT cl.channel_id ORDER BY cl.channel_id)')
            . ' AS stores FROM channel_listing cl WHERE cl.sku_id IS NOT NULL GROUP BY cl.sku_id) t GROUP BY t.stores',
            $bits ? $storeIds : [],
        );
        foreach ($sets as $r) {
            $on = [];
            foreach ($bits ? $storeIds : array_map('intval', explode(',', (string) $r['stores'])) as $place => $id) {
                if (!$bits || (((int) $r['stores']) >> ($place + 1) & 1) === 1) {
                    $on[] = $id;
                }
            }
            foreach ($on as $id) {
                if (isset($matched[$id])) {
                    $matched[$id] += (int) $r['n'];
                }
            }
            if (array_diff($storeIds, $on) === []) {
                $every += (int) $r['n'];
            }
        }
        $suggested = (int) $this->db->value(
            'SELECT ' . self::OPEN_SKU_HINT . 'COUNT(DISTINCT p.proposed_sku_id) FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id '
            . "JOIN sku s ON s.id = p.proposed_sku_id WHERE p.status = 'open' AND p.proposed_sku_id IS NOT NULL AND s.merged_into_sku_id IS NULL AND " . self::WAITING_LISTING,
        );
        return ['total' => $total, 'every' => $every, 'matched' => $matched, 'suggested' => $suggested];
    }

    /**
     * The text filter of By Item's list (alias `s`): a CW number as it is written ("CW-000123": that product, by its id), a
     * number of six digits or more as a barcode or a CW number (two index reads here, then the products by id), else what
     * searchSkus() looks for: every word in the name, brand, range or flavour, or a short number as a CW number (the words read
     * the product rows, as the Find page does).
     *
     * @return array{0: string, 1: list<mixed>} '' for no text, 'FALSE' for a text with nothing to look for
     */
    private function productText(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return ['', []];
        }
        if (preg_match('/^cw-?0*([0-9]{1,10})$/iD', $q, $m) === 1) {
            return ['s.id = ?', [(int) $m[1]]];
        }
        if (preg_match('/^[0-9]{6,64}$/D', $q) === 1) {
            // sku_barcode holds the GTIN key (digits, no leading zeros), as a scanner may add them.
            $ids = array_map('intval', $this->db->column('SELECT sku_id FROM sku_barcode WHERE barcode IN (?, ?)', [$q, Gtin::key($q) ?? $q]));
            if (preg_match('/^0*([0-9]{1,10})$/D', $q, $m) === 1) {
                $ids[] = (int) $m[1];
            }
            $ids = array_values(array_unique($ids));
            return $ids === [] ? ['FALSE', []] : ['s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
        }
        $or = [];
        $params = [];
        if (preg_match('/^0*([0-9]{1,5})$/D', $q, $m) === 1) {
            $or[] = 's.id = ?';
            $params[] = (int) $m[1];
        }
        // No word at all is possible: trim() leaves white space that is not ASCII (a pasted no-break or full-width space, a form
        // feed), which the split below takes as a separator, and a text that is not valid UTF-8 cannot be split. Such a text finds
        // nothing: it must never leave an empty group in the SQL.
        $and = [];
        foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $w) {
            $and[] = "CONCAT_WS(' ', s.name, s.brand, s.line, s.flavour) LIKE ?";
            $params[] = '%' . self::escapeLike($w) . '%';
        }
        if ($and !== []) {
            $or[] = '(' . implode(' AND ', $and) . ')';
        }
        return $or === [] ? ['FALSE', []] : ['(' . implode(' OR ', $or) . ')', $params];
    }

    /**
     * The FROM and WHERE of By Item's list (the products not joined into another one; alias `s`), by coverage filter (COVERAGE):
     *  - none: the products in id order (ix_sku_merged_into, whose entries end with the id);
     *  - `every`: one EXISTS per store, the store with the fewest matched products first; the optimizer starts from the rarest store
     *    when there is one;
     *  - `missing`: an anti-join on the (sku_id, channel_id) index of 0024, product by product (the hint: without it the optimizer
     *    reads every listing row of the store first);
     *  - `suggested`: from the open suggestions (the (status, proposed_sku_id, …) index of 0024), not from every product.
     *
     * @param list<int> $storeIds the stores, the one with the fewest matched products first for `every`
     * @return array{0: string, 1: list<mixed>, 2: string} the SQL after SELECT … , its values, and the product id's column
     */
    private function productFrom(?string $coverage, ?int $storeId, array $storeIds, string $q): array
    {
        [$text, $textParams] = $this->productText($q);
        if ($coverage === 'suggested') {
            return ['FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id JOIN sku s ON s.id = p.proposed_sku_id '
                . "WHERE p.status = 'open' AND p.proposed_sku_id IS NOT NULL AND s.merged_into_sku_id IS NULL AND " . self::WAITING_LISTING
                . ($text === '' ? '' : ' AND ' . $text), $textParams, 'p.proposed_sku_id'];
        }
        $where = ['s.merged_into_sku_id IS NULL'];
        $params = [];
        if ($coverage === 'every') {
            foreach ($storeIds as $i => $id) {
                $where[] = "EXISTS (SELECT 1 FROM channel_listing c{$i} WHERE c{$i}.sku_id = s.id AND c{$i}.channel_id = ?)";
                $params[] = $id;
            }
        } elseif ($coverage === 'missing' && $storeId !== null) {
            $where[] = 'NOT EXISTS (SELECT ' . self::STORE_INDEX_HINT . '1 FROM channel_listing cl WHERE cl.sku_id = s.id AND cl.channel_id = ?)';
            $params[] = $storeId;
        }
        if ($text !== '') {
            $where[] = $text;
            array_push($params, ...$textParams);
        }
        return ['FROM sku s WHERE ' . implode(' AND ', $where), $params, 's.id'];
    }

    /**
     * The ids of By Item's list, in CW-number order: all of them, or $limit from $offset.
     *
     * @param list<int> $storeIds
     * @return list<int>
     */
    public function productIds(?string $coverage, ?int $storeId, array $storeIds, string $q, ?int $limit = null, int $offset = 0): array
    {
        [$from, $params, $id] = $this->productFrom($coverage, $storeId, $storeIds, $q);
        return array_map('intval', $this->db->column(
            'SELECT ' . ($coverage === 'suggested' ? self::OPEN_SKU_HINT . 'DISTINCT ' : '') . "{$id} {$from} ORDER BY {$id}" . ($limit !== null ? ' LIMIT ? OFFSET ?' : ''),
            $limit !== null ? [...$params, $limit, $offset] : $params,
        ));
    }

    /**
     * How many products of By Item's list come before one product (the list is in CW-number order): the page it is on.
     *
     * @param list<int> $storeIds
     */
    public function productsBefore(?string $coverage, ?int $storeId, array $storeIds, string $q, int $skuId): int
    {
        [$from, $params, $id] = $this->productFrom($coverage, $storeId, $storeIds, $q);
        return (int) $this->db->value('SELECT ' . ($coverage === 'suggested' ? self::OPEN_SKU_HINT : '') . "COUNT(DISTINCT {$id}) {$from} AND {$id} < ?", [...$params, $skuId]);
    }

    /**
     * Products by id, in id order: the rows of a page of By Item (and of a slice of its CSV).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>> id, code, name, brand
     */
    public function productRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return $this->db->all('SELECT s.id, s.code, s.name, s.brand FROM sku s WHERE s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY s.id', $ids);
    }

    /**
     * What the stores hold for some warehouse items, in three reads: the variants (website products) matched to them (`matched`,
     * the best seller of a store first), the open suggestions that propose them on variants still waiting for a match
     * (`suggested`), and the decisions that would match a variant to them and wait for a second OK (`waiting`). Each row names
     * the item (sku_id), the store (channel_id) and the variant. A matched row carries its parent product's name and its units of
     * a year from the profile (the item's "Product: …" line is the best seller's, U122). $names adds every variant's own names
     * (the page's 50 items need them; the CSV of every item does not, so its suggested and waiting reads leave the profiles alone
     * and its matched rows come in listing order).
     *
     * @param list<int> $skuIds
     * @return array{matched: list<array<string, mixed>>, suggested: list<array<string, mixed>>, waiting: list<array<string, mixed>>}
     */
    public function productCells(array $skuIds, bool $names = true): array
    {
        $skuIds = array_values(array_unique($skuIds));
        if ($skuIds === []) {
            return ['matched' => [], 'suggested' => [], 'waiting' => []];
        }
        $in = implode(',', array_fill(0, count($skuIds), '?'));
        $cols = $names ? ', lp.product_title, lp.variant_title' : '';
        $profile = 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id ';
        return [
            'matched' => $this->db->all(
                'SELECT cl.sku_id, cl.channel_id, cl.id AS listing_id, cl.external_variant_id, cl.units_per_item, cl.status, lp.product_title, lp.units_365d'
                . ($names ? ', lp.variant_title' : '') . ' FROM channel_listing cl ' . $profile
                . "WHERE cl.sku_id IN ({$in}) ORDER BY cl.sku_id, cl.channel_id, " . ($names ? 'COALESCE(lp.units_30d, 0) DESC, ' : '') . 'cl.id',
                $skuIds,
            ),
            'suggested' => $this->db->all(
                'SELECT p.proposed_sku_id AS sku_id, cl.channel_id, p.listing_id, cl.external_variant_id, p.id AS proposal_id, p.band' . $cols
                . ' FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id ' . ($names ? $profile : '')
                . "WHERE p.status = 'open' AND p.proposed_sku_id IN ({$in}) AND " . self::WAITING_LISTING . ' ORDER BY p.proposed_sku_id, cl.channel_id, p.listing_id',
                $skuIds,
            ),
            'waiting' => $this->db->all(
                'SELECT d.sku_id, cl.channel_id, d.listing_id, cl.external_variant_id, d.id AS decision_id, d.units_per_item' . $cols
                . ' FROM match_decision d JOIN channel_listing cl ON cl.id = d.listing_id ' . ($names ? $profile : '')
                . "WHERE d.state = 'pending_second' AND d.action = 'link' AND d.sku_id IN ({$in}) ORDER BY d.sku_id, cl.channel_id, d.id",
                $skuIds,
            ),
        ];
    }

    /**
     * The picker's first list: the website products of one store whose open suggestion proposes this warehouse product and that
     * still wait for a match. @return list<int> listing ids
     */
    public function suggestedFor(int $skuId, int $channelId, int $limit = 50): array
    {
        return array_map('intval', $this->db->column(
            'SELECT p.listing_id FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id '
            . "WHERE p.status = 'open' AND p.proposed_sku_id = ? AND cl.channel_id = ? AND " . self::WAITING_LISTING . ' ORDER BY p.listing_id LIMIT ?',
            [$skuId, $channelId, $limit],
        ));
    }

    /**
     * The picker's search: one small page of a store's website products that still wait for a match, best sellers first (30
     * days, then a year). The text is what Store Products searches (name, option name, brand, the option number, a barcode), word
     * by word as the Find page does: every word must be in the name, option name or brand. No count: one more row than the page
     * says whether there is a next one (with a text every waiting product of the store is read once, not twice).
     *
     * @return array{ids: list<int>, more: bool}
     */
    public function waitingListings(int $channelId, string $q, int $page, int $perPage = self::PICK_PER_PAGE): array
    {
        $where = ['cl.channel_id = ?', self::WAITING_LISTING];
        $params = [$channelId];
        $q = trim($q);
        $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);
        if ($q !== '' && $words === []) {
            // A text with no usable word (only white space trim() leaves, or not valid UTF-8) finds nothing, as on the list.
            return ['ids' => [], 'more' => false];
        }
        if ($words !== []) {
            $and = [];
            $like = [];
            foreach ($words as $w) {
                $and[] = "CONCAT_WS(' ', lp.product_title, lp.variant_title, lp.brand) LIKE ?";
                $like[] = '%' . self::escapeLike($w) . '%';
            }
            $where[] = '(cl.external_variant_id = ? OR JSON_SEARCH(lp.barcodes, \'one\', ?) IS NOT NULL OR (' . implode(' AND ', $and) . '))';
            array_push($params, $q, self::escapeLike($q), ...$like);
        }
        $ids = array_map('intval', $this->db->column(
            'SELECT ' . ($words === [] ? self::UNITS_HINT : '') . 'cl.id FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE '
            . implode(' AND ', $where) . ' ORDER BY COALESCE(lp.units_30d, 0) DESC, COALESCE(lp.units_365d, 0) DESC, cl.id ASC LIMIT ? OFFSET ?',
            [...$params, $perPage + 1, (max(1, $page) - 1) * $perPage],
        ));
        return ['ids' => array_slice($ids, 0, $perPage), 'more' => count($ids) > $perPage];
    }

    /**
     * The picker's rows: website products with their state (STORE_STATES), name, price, units sold, barcodes and open suggestion.
     *
     * @param list<int> $listingIds
     * @return array<int, array<string, mixed>> listing id => row
     */
    public function pickerRows(array $listingIds): array
    {
        $listingIds = array_values(array_unique($listingIds));
        if ($listingIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT cl.id AS listing_id, cl.channel_id, cl.external_variant_id, ' . self::STATE_SQL . ' AS state, lp.product_title, lp.variant_title, lp.brand, '
            . 'lp.price, lp.barcodes, lp.units_30d, lp.units_365d, p.band, p.proposed_sku_id FROM channel_listing cl ' . self::STATE_JOINS
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id IN (' . implode(',', array_fill(0, count($listingIds), '?')) . ')',
            $listingIds,
        ) as $r) {
            $out[(int) $r['listing_id']] = $r;
        }
        return $out;
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
            . 'lp.product_title, lp.variant_title, lp.units_30d, lp.units_365d '
            // One set of ids from two index lookups (the link now, the link periods; UNION: each once): "WHERE cl.sku_id = ? OR cl.id IN
            // (...)" made MySQL read every listing of every site.
            . 'FROM (SELECT l.id FROM channel_listing l WHERE l.sku_id = ? UNION SELECT h.listing_id FROM listing_map_history h WHERE h.sku_id = ?) ids '
            . 'JOIN channel_listing cl ON cl.id = ids.id JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
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
                . 'l.effective_at, l.created_at, l.document_id, d.doc_type, r.label AS reason_label, op.given_to '
                . 'FROM stock_ledger l JOIN warehouse w ON w.id = l.warehouse_id LEFT JOIN document d ON d.id = l.document_id '
                . 'LEFT JOIN document_line dl ON dl.document_id = l.document_id AND dl.line_no = l.document_line LEFT JOIN stock_op op ON op.document_id = l.document_id '
                . 'LEFT JOIN reason_code r ON r.code = COALESCE(dl.reason_code, d.reason_code) WHERE l.warehouse_id = ? AND l.sku_id = ? ORDER BY l.id DESC LIMIT ?',
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
