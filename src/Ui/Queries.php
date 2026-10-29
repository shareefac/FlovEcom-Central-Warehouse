<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Clock;
use CW\Db;
use CW\Mapping\BulkDecisions;
use CW\Mapping\KeyHold;
use CW\Mapping\OneAtATime;
use CW\Mapping\SiteState;
use CW\Matching\Gtin;
use CW\Settings;

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
     * What a match-strength list shows (docs/decisions.md U140, U144): every row (`all`, the list as it always was), only the rows a
     * bulk action can take (`ready`), only the ones held back (`held`), only the ones that are a match of a spot check that is not
     * complete and are not held back (`spot`), only the ones found by the check on arrival that are decided one at a time and are
     * neither of the first two (`arrival`). The last three are OneAtATime's three kinds, by its own conditions and in its order:
     * `ready` is the rest, so the four parts never overlap and add up to `all`. It changes what is listed, never what a bulk action
     * does.
     */
    public const SHOW_ALL = 'all';
    public const SHOWS = [self::SHOW_ALL, 'ready', 'held', 'spot', 'arrival'];
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

    /**
     * @return array<string, array<int, int>> band => channel id => open proposals still to decide (the lists' rows: an old product not sold
     *         in a year left out, HU2)
     */
    public function bandCounts(): array
    {
        $out = [];
        [$shown, $shownParams] = $this->unsoldShown(true, null, false);
        foreach ($this->db->all(
            'SELECT p.band, cl.channel_id, COUNT(*) AS n FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id '
            . 'WHERE p.status = \'open\' AND ' . self::OPEN_STATUS . ' AND ' . self::NO_PENDING . ($shown === '' ? '' : ' AND ' . $shown) . ' GROUP BY p.band, cl.channel_id',
            $shownParams,
        ) as $r) {
            $out[(string) $r['band']][(int) $r['channel_id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * How many a match-strength list still holds, with no filter: the open suggestions of this strength (of one store, or of every
     * store) whose website product waits for a decision with none waiting for a second OK (the list's own conditions, as
     * bandCounts()). The progress line of a variant's page opened from the list (docs/decisions.md SX6).
     */
    public function listLeft(string $band, ?int $channelId): int
    {
        [$shown, $shownParams] = $this->unsoldShown(true, $channelId, false); // (the list's rows: HU2)
        return (int) $this->db->value('SELECT COUNT(*) FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id '
            . "WHERE p.status = 'open' AND p.band = ? AND " . self::OPEN_STATUS . ' AND ' . self::NO_PENDING . ($channelId === null ? '' : ' AND cl.channel_id = ?')
            . ($shown === '' ? '' : ' AND ' . $shown), [...($channelId === null ? [$band] : [$band, $channelId]), ...$shownParams]);
    }

    /**
     * How many decisions a person made today, since midnight UK time (every answer of theirs: a match, a new product, an ignore, a
     * "not this product", each row of a ticked list; also one that waits for a second OK): one range of the index
     * ix_match_decision_decided_by (decided_by, created_at). The progress line (docs/decisions.md SX6).
     */
    public function answeredToday(int $staffId): int
    {
        $london = new \DateTimeZone('Europe/London');
        $midnight = Clock::now()->setTimezone($london)->setTime(0, 0);
        return (int) $this->db->value('SELECT COUNT(*) FROM match_decision WHERE decided_by = ? AND created_at >= ?',
            [$staffId, Clock::db($midnight->setTimezone(Clock::utc()))]);
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
     * units. Coverage = linked units / all units. A landing page (docs/decisions.md LP6) is not a product: it is in none of these
     * figures; `landing` says how many a store has.
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
            . "FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.status <> 'landing' GROUP BY cl.channel_id",
        ) as $r) {
            $out[(int) $r['channel_id']] = array_map('intval', $r) + ['landing' => 0];
        }
        foreach ($this->db->all("SELECT channel_id, COUNT(*) AS n FROM channel_listing WHERE status = 'landing' GROUP BY channel_id") as $r) {
            $out[(int) $r['channel_id']] ??= ['channel_id' => (int) $r['channel_id'], 'listings' => 0, 'linked_listings' => 0, 'u30' => 0, 'l30' => 0, 'i30' => 0, 'u365' => 0,
                'l365' => 0, 'i365' => 0];
            $out[(int) $r['channel_id']]['landing'] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * One page of a band's queue, of what $show names (SHOWS; anything else: every row), and how many rows each choice of "Show"
     * holds under the same band, store and filters (`shows`). The list's own count is read once, as before, for every row; the
     * held-back rows, the spot checks' rows and the check on arrival's rows are counted from the small side (queueShows());
     * "ready" is the rest. So the total of the page shown is one of those five numbers, and "all" is the sum of the other four.
     *
     * @param list<int>|null $spotMatches the variants that are a match of a spot check that is not complete (the listing ids of
     *        OneAtATime::spotMatchesToDecide()) when the caller has read them; read here when not given
     * @param list<int>|null $arrivalRuns the runs whose open suggestions are the check on arrival's that go one at a time
     *        (OneAtATime::arrivalRuns()) when the caller has read them; read here when not given
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, shows: array{all: int, ready: int, held: int, spot: int, arrival: int},
     *     arrival_runs: list<int>}
     */
    public function queue(string $band, ?int $channelId, string $q, ?string $lane, int $min30, int $page, string $show = self::SHOW_ALL, ?array $spotMatches = null,
        ?array $arrivalRuns = null): array
    {
        $show = in_array($show, self::SHOWS, true) ? $show : self::SHOW_ALL;
        [$where, $params] = $this->queueWhere($band, $channelId, $q, $lane, $min30);
        // The count joins the profile only when a filter reads it: the site (an inner join on a foreign key), the profile and the
        // item (left joins on a primary key) never change how many rows there are, and the profile's rows are wide (their JSON).
        $profile = $min30 > 0 || trim($q) !== '' ? 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id ' : '';
        $all = (int) $this->db->value('SELECT ' . ($profile !== '' && trim($q) === '' ? self::UNITS_HINT : '')
            . 'COUNT(*) FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id ' . $profile
            . 'WHERE ' . $where, $params);
        // (an empty list has no part to count or to show: the spot checks are not read for it)
        $spotMatches = $all === 0 ? [] : ($spotMatches ?? array_keys(OneAtATime::spotMatchesToDecide($this->db)));
        $arrivalRuns = $all === 0 ? [] : ($arrivalRuns ?? OneAtATime::arrivalRuns($this->db));
        $shows = $this->queueShows($where, $params, $profile, trim($q) === '', $all, $spotMatches, $arrivalRuns);
        $total = $shows[$show];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        // The page's 50 in queue order first, on narrow index columns only (the units come from ix_listing_profile_units, 0020),
        // then their columns: the sort no longer carries every candidate's titles, flags and item.
        $ids = $total === 0 && $show !== self::SHOW_ALL ? [] : array_map('intval', $this->db->column(
            'SELECT ' . (trim($q) === '' ? self::UNITS_HINT : '') . 'p.id FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            . 'WHERE ' . $where . self::showWhere($show, $spotMatches, $arrivalRuns) . self::ORDER . ' LIMIT ? OFFSET ?',
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
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'shows' => $shows, 'arrival_runs' => $arrivalRuns];
    }

    /**
     * How many rows each choice of "Show" holds (SHOWS), for a list whose own conditions are $where and whose every row was
     * counted as $all. The held-back rows, the spot checks' rows and the check on arrival's rows are counted FROM THE SMALL SIDE:
     * the holds in force (key_bulk_hold: a few hundred rows) and the spot checks' matches (a few dozen listings, named by their
     * ids), each joined to its open suggestion on the list's own index (status, band, listing_id) and to its listing by the
     * primary key (the profile, when a filter reads it: its units from their narrow index unless a text is searched); and the open
     * suggestions of the check on arrival's runs (named by their ids), read from the index (run, status, band, lane, listing) of
     * 0027 alone, each with its listing by the primary key. Never a pass over the list, and never a suggestion's flags.
     * A row that is more than one is the first of: held back, in a spot check, found on arrival (OneAtATime's order). "ready" is
     * what is left.
     *
     * @param list<mixed> $params
     * @param string $profile the join of the profile when a filter reads it ('' when none does)
     * @param bool $unitsOnly no text is searched: a filter reads the profile's units only
     * @param list<int> $spotMatches
     * @param list<int> $arrivalRuns
     * @return array{all: int, ready: int, held: int, spot: int, arrival: int}
     */
    private function queueShows(string $where, array $params, string $profile, bool $unitsOnly, int $all, array $spotMatches, array $arrivalRuns): array
    {
        if ($all === 0) {
            return ['all' => 0, 'ready' => 0, 'held' => 0, 'spot' => 0, 'arrival' => 0];
        }
        $units = $profile !== '' && $unitsOnly ? ' INDEX(lp ix_listing_profile_units)' : '';
        $held = (int) $this->db->value('SELECT /*+ JOIN_ORDER(h, p, cl)' . $units . ' */ COUNT(DISTINCT h.listing_id) FROM key_bulk_hold h '
            . 'JOIN match_proposal p ON p.listing_id = h.listing_id JOIN channel_listing cl ON cl.id = p.listing_id ' . $profile
            . 'WHERE ' . KeyHold::IN_FORCE . ' AND ' . $where, $params);
        $spot = $spotMatches === [] ? 0 : (int) $this->db->value('SELECT /*+ JOIN_ORDER(cl, p)' . $units . ' */ COUNT(*) FROM channel_listing cl '
            . 'JOIN match_proposal p ON p.listing_id = cl.id ' . $profile
            . 'WHERE ' . OneAtATime::spotSql('cl.id', $spotMatches) . ' AND NOT ' . OneAtATime::heldSql('cl.id') . ' AND ' . $where, $params);
        $arrival = $arrivalRuns === [] ? 0 : (int) $this->db->value('SELECT /*+ JOIN_ORDER(p, cl) INDEX(p ix_match_proposal_run_open)' . $units . ' */ COUNT(*) '
            . 'FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id ' . $profile
            . 'WHERE ' . OneAtATime::arrivalSql('p.match_run_id', $arrivalRuns) . ' AND NOT ' . OneAtATime::heldSql('cl.id') . ' AND NOT '
            . OneAtATime::spotSql('cl.id', $spotMatches) . ' AND ' . $where, $params);
        return ['all' => $all, 'ready' => max(0, $all - $held - $spot - $arrival), 'held' => $held, 'spot' => $spot, 'arrival' => $arrival];
    }

    /**
     * What a choice of "Show" adds to a list's conditions (on the listing `cl` and its open suggestion `p`): OneAtATime's three
     * conditions, in its order.
     *
     * @param list<int> $spotMatches
     * @param list<int> $arrivalRuns
     */
    private static function showWhere(string $show, array $spotMatches, array $arrivalRuns): string
    {
        $held = OneAtATime::heldSql('cl.id');
        $spot = OneAtATime::spotSql('cl.id', $spotMatches);
        $arrival = OneAtATime::arrivalSql('p.match_run_id', $arrivalRuns);
        return match ($show) {
            // (with no suggestion of the check on arrival in the data the condition is left out: the statement is the one of before)
            'ready' => " AND NOT {$held} AND NOT {$spot}" . ($arrivalRuns === [] ? '' : " AND NOT {$arrival}"),
            'held' => " AND {$held}",
            'spot' => " AND {$spot} AND NOT {$held}",
            'arrival' => " AND {$arrival} AND NOT {$held} AND NOT {$spot}",
            default => '',
        };
    }

    /**
     * The listing to review after $currentId: the next one in queue order (units 365d, units 30d
     * desc, id), else the first of the queue other than $currentId; null when the queue is empty.
     * Of what $show names (SHOWS), as the list itself: "next" from a list of the ready ones is the next ready one.
     *
     * @param list<int>|null $spotMatches as queue()
     * @param list<int>|null $arrivalRuns as queue()
     */
    public function nextInQueue(string $band, ?int $channelId, string $q, ?string $lane, int $min30, int $currentId, string $show = self::SHOW_ALL,
        ?array $spotMatches = null, ?array $arrivalRuns = null): ?int
    {
        [$where, $params] = $this->queueWhere($band, $channelId, $q, $lane, $min30);
        if ($show !== self::SHOW_ALL && in_array($show, self::SHOWS, true)) {
            // (the held-back ones are told without the spot checks and without the check on arrival's runs, so neither is read for
            // that choice; the spot checks' ones without the runs)
            $where .= self::showWhere($show, $spotMatches ?? ($show === 'held' ? [] : array_keys(OneAtATime::spotMatchesToDecide($this->db))),
                $arrivalRuns ?? (in_array($show, ['held', 'spot'], true) ? [] : OneAtATime::arrivalRuns($this->db)));
        }
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
        [$text, $textParams] = $this->listingText($q, $channelId);
        if ($text !== '') {
            $where[] = $text;
            array_push($params, ...$textParams);
        }
        // An old product not sold in a year is left out, unless the search names it exactly (HU2, HU4).
        [$shown, $shownParams] = $this->unsoldShown(true, $channelId, false, $this->unsoldOn() && trim($q) !== '' ? $this->exactIds(SearchText::parse($q), $channelId) : []);
        if ($shown !== '') {
            $where[] = $shown;
            array_push($params, ...$shownParams);
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * The text filter of a list of website products (docs/decisions.md SX1-SX3; alias `cl` the listing, `lp` its profile): the
     * option number as typed; a code by the barcode index (Gtin::forms of a number: also with or without its leading zeros); a CW
     * number ("CW-003906", "cw 3906", or a bare number of up to 6 digits) as the website products matched to that item or with an
     * open suggestion of it (two index reads); or every word, in any order, in the product's title, the option's title and the
     * brand, compared by SearchText (spaces and hyphens left out, "&" as "and"). The codes and the CW number are looked up BEFORE the
     * statement, by their indexes, of the store the list is of ($channelId; null: every store) and with no cap (Codex's review of
     * f46d19b: a cap of every store's could leave out this store's), and named by id: the list's scan never parses a profile's
     * barcodes (the old JSON_SEARCH did, for every row). What the old filter found (the whole text in one of the three, the option
     * number, the barcode as stored) is still found.
     *
     * @return array{0: string, 1: list<mixed>} '' for no text
     */
    private function listingText(string $q, ?int $channelId): array
    {
        $s = SearchText::parse($q);
        if ($s->isEmpty()) {
            return ['', []];
        }
        $or = ['cl.external_variant_id = ?'];
        $params = [$s->text];
        $ids = array_values(array_unique([...$this->listingsByCode($s, $channelId), ...$this->listingsOfItem($s->cwId(6), $channelId)]));
        if ($ids !== []) {
            $or[] = 'cl.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        // (a text written as a CW number, "CW-003906", "cw 3906", is that item's: its words are no name). The words in the stored
        // search text of the row (search_names, 0049: what the forgiving search finds, and more); a row whose text was longer than
        // the column, as before (search_on_row: a handful).
        if (!$s->explicitCw() && $s->words !== []) {
            [$stored, $storedParams] = $s->allStored('lp.search_names');
            [$words, $wordParams] = $s->allWords("CONCAT_WS(' ', lp.product_title, lp.variant_title, lp.brand)", 'lp.brand');
            $or[] = "({$stored} OR (lp.search_on_row = 1 AND {$words}))";
            array_push($params, ...$storedParams, ...$wordParams);
        }
        return ['(' . implode(' OR ', $or) . ')', $params];
    }

    /** The index of the stored names, and of the names with everything else a variant is searched by (0049, docs/decisions.md SI1). */
    private const SEARCH_NAMES_INDEX = 'ix_listing_profile_search_names';
    private const SEARCH_ALL_INDEX = 'ix_listing_profile_search_all';

    /**
     * Every website product a search finds, as ids, read by indexes only (docs/decisions.md SI2-SI4): the option number as typed
     * (uq_channel_listing_variant), a code (the barcode index of 0005), a CW number (the item's website products), and every word in
     * the stored search text: ONE pass over the narrow index of search_names (the lists, Store Products, New Variants, Find) or of
     * search_all (By Item's quick match and picker, $quick: the names, the website's product number, the web address, the barcodes,
     * the attributes and the features, as the old search asked them), never over the profiles' rows; then the handful whose text
     * is not the whole truth (search_on_row: longer than the column, or an escaped JSON value), asked as before on their rows; and for
     * the quick match the variants whose OPTION NUMBER holds a word or the start of one (the option number is in channel_listing, not
     * in the stored text). Of one store, or of every store
     * ($channelId null); the caller's statement keeps its own conditions. What the forgiving search found is found (SI2).
     *
     * @return list<int>
     */
    private function searchedIds(SearchText $s, ?int $channelId, bool $quick): array
    {
        if ($quick && $channelId === null) {
            throw new \LogicException('the quick match searches one store');
        }
        $ids = array_map('intval', $channelId === null
            ? $this->db->column('SELECT cl.id FROM channel ch JOIN channel_listing cl ON cl.channel_id = ch.id AND cl.external_variant_id = ?', [$s->text])
            : $this->db->column('SELECT id FROM channel_listing WHERE channel_id = ? AND external_variant_id = ?', [$channelId, $s->text]));
        array_push($ids, ...$this->listingsByCode($s, $channelId));
        if (!$quick) {
            array_push($ids, ...$this->listingsOfItem($s->cwId(6), $channelId));
        }
        if ($s->words === [] || (!$quick && $s->explicitCw())) {
            return array_values(array_unique($ids));
        }
        if ($quick) {
            // the store's waiting variants (the covering (channel_id, status) index), each one's stored text by the (listing_id,
            // search_all) index: a look-up each, never the profiles, and never another store's entries
            [$stored, $storedParams] = $s->allStored('s.search_all');
            array_push($ids, ...array_map('intval', $this->db->column('SELECT /*+ JOIN_ORDER(cl, s) */ cl.id FROM channel_listing cl FORCE INDEX (ix_channel_listing_status) '
                . 'JOIN listing_profile s FORCE INDEX (' . self::SEARCH_ALL_INDEX . ') ON s.listing_id = cl.id '
                . "WHERE cl.channel_id = ? AND cl.status IN ('unmapped', 'suggested') AND " . $stored, [(int) $channelId, ...$storedParams])));
        } else {
            // one covering scan of the names' index, every store's (cheaper than a look-up for each of a big store's variants)
            [$stored, $storedParams] = $s->allStored('s.search_names');
            array_push($ids, ...array_map('intval', $this->db->column('SELECT s.listing_id FROM listing_profile s FORCE INDEX (' . self::SEARCH_NAMES_INDEX . ') WHERE '
                . $stored, $storedParams)));
        }
        // the rows whose stored text is not the whole truth (longer than the column, an escaped JSON value): the forgiving search's own conditions, on their rows (by search_on_row's index)
        [$old, $oldParams] = $quick ? $this->waitingWords($s) : $s->allWords("CONCAT_WS(' ', lp.product_title, lp.variant_title, lp.brand)", 'lp.brand');
        array_push($ids, ...array_map('intval', $this->db->column('SELECT lp.listing_id FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id '
            . 'WHERE lp.search_on_row = 1' . ($channelId === null ? '' : ' AND cl.channel_id = ?') . ' AND ' . $old, [...($channelId === null ? [] : [$channelId]), ...$oldParams])));
        if ($quick) {
            // A word in the option number (in channel_listing, not in the stored text), or one that runs from the option number into
            // the names, as the old joined text had them (Codex's review of 7307ab1): the store's option numbers by their unique index,
            // kept when one ends with the start of a word or holds a whole word; then each word in the option number joined to the
            // stored text, made normal the same way ("&" as "and"), by the (listing_id, search_all) index; a variant with no profile
            // by its option number alone.
            // (the option number made normal once, in a derived table of the store's option numbers, not again for every pattern.
            // An option number is the website's number of the variant: digits. So a word that runs out of it into the title starts
            // with digits, and only the starts of its leading digits are asked: "27electrofag" asks "2" and "27" (docs/decisions.md SI6))
            $any = [];
            $anyParams = [];
            $each = [];
            $eachParams = [];
            foreach (array_keys($s->words) as $i) {
                $w = $s->words[$i];
                $any[] = 'o.opt LIKE ?';
                $anyParams[] = SearchText::like($w);
                $digits = preg_match('/^[0-9]+/', $w, $lead) === 1 ? $lead[0] : '';
                for ($k = 1, $n = min(strlen($digits), mb_strlen($w) - 1, 12); $k <= $n; $k++) {
                    $any[] = 'o.opt LIKE ?';
                    $anyParams[] = '%' . substr($digits, 0, $k);
                }
                $patterns = $s->storedPatterns($i);
                $each[] = '(' . implode(' OR ', array_fill(0, count($patterns), "CONCAT(o.opt, IFNULL(s.search_all, '')) LIKE ?")) . ')';
                array_push($eachParams, ...$patterns);
            }
            array_push($ids, ...array_map('intval', $this->db->column('SELECT /*+ NO_MERGE(o) JOIN_ORDER(o, s) */ o.id FROM '
                . "(SELECT cl.id, REPLACE(REPLACE(REPLACE(cl.external_variant_id, ' ', ''), '-', ''), '&', 'and') AS opt FROM channel_listing cl "
                . 'FORCE INDEX (uq_channel_listing_variant) WHERE cl.channel_id = ?) o '
                . 'LEFT JOIN listing_profile s FORCE INDEX (' . self::SEARCH_ALL_INDEX . ') ON s.listing_id = o.id '
                . 'WHERE (' . implode(' OR ', $any) . ') AND ' . implode(' AND ', $each), [(int) $channelId, ...$anyParams, ...$eachParams])));
        }
        return array_values(array_unique($ids));
    }

    /** `cl.id IN (…)` of ids this code read (integers, written into the statement: a page of a search may hold thousands), or FALSE. @param list<int> $ids */
    private static function idsIn(array $ids): string
    {
        return $ids === [] ? 'FALSE' : 'cl.id IN (' . implode(',', array_map('intval', $ids)) . ')';
    }

    /**
     * The website products whose barcodes hold one of the search's codes, by the multi-valued index of 0005 (as barcodeElsewhere),
     * of one store ($channelId, each by its primary key) or of every store; a barcode is on a handful of them.
     *
     * @return list<int>
     */
    private function listingsByCode(SearchText $s, ?int $channelId): array
    {
        if ($s->codes === []) {
            return [];
        }
        $codes = json_encode($s->codes, JSON_THROW_ON_ERROR);
        return array_map('intval', $channelId === null
            ? $this->db->column('SELECT listing_id FROM listing_profile WHERE JSON_OVERLAPS(barcodes, CAST(? AS JSON))', [$codes])
            : $this->db->column('SELECT lp.listing_id FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id '
                . 'WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON)) AND cl.channel_id = ?', [$codes, $channelId]));
    }

    /**
     * The website products of an item by its CW number, of one store or of every store: matched to it (`channel_listing (sku_id,
     * channel_id)`, 0024) and with an open suggestion of it (`match_proposal (status, proposed_sku_id, listing_id, band)`, 0024).
     *
     * @return list<int>
     */
    private function listingsOfItem(?int $skuId, ?int $channelId): array
    {
        if ($skuId === null) {
            return [];
        }
        $store = $channelId === null ? '' : ' AND cl.channel_id = ?';
        $values = $channelId === null ? [$skuId] : [$skuId, $channelId];
        return array_map('intval', [
            ...$this->db->column('SELECT cl.id FROM channel_listing cl WHERE cl.sku_id = ?' . $store, $values),
            ...$this->db->column("SELECT p.listing_id FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id WHERE p.status = 'open' AND p.proposed_sku_id = ?"
                . $store, $values),
        ]);
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
    public const STORE_STATES = ['waiting', 'quarantined', 'ignored', 'landing', 'linked', 'suggested', 'not_matched'];
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
            . "WHERE cl.status <> 'landing' AND NOT COALESCE({$dup}, FALSE) AND NOT EXISTS (SELECT 1 FROM match_proposal pn WHERE pn.listing_id = p.listing_id AND pn.id > p.id "
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
        . "WHEN cl.status = 'landing' THEN 'landing' "
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
        'landing' => "cl.status = 'landing' AND " . self::NOT_WAITING,
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
        $by = array_fill_keys(['unmapped', 'suggested', 'mapped', 'ignored', 'quarantined', 'landing'], 0);
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
            'landing' => $by['landing'] - $waiting['landing'],
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
     * With $hidden (unsoldHidden(), the setting on and "Show them" not chosen: HU3) an old product not sold in a year is left out of the
     * two waiting states and of All, unless the search names it exactly (HU4); $counts are then the counts without them, and `hidden`
     * says how many rows of this state and search are left out: from $hidden without a search, else from the search's own one pass
     * (each id it finds says whether it is hidden: one more column, no second pass).
     *
     * @param array<string, int>|null $counts
     * @param array{all: int, open: int, new: int}|null $hidden
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, hidden: int}
     */
    public function storeProducts(int $channelId, ?string $state, string $q, string $sort, int $page, ?array $counts = null, ?array $hidden = null): array
    {
        $where = ['cl.channel_id = ?'];
        $params = [$channelId];
        $state = $state !== null && isset(self::STATE_WHERE[$state]) ? $state : null;
        if ($state !== null) {
            $where[] = self::STATE_WHERE[$state];
        }
        $q = trim($q);
        $s = SearchText::parse($q);
        if ($q !== '') {
            // what the search finds, by the indexes (searchedIds): the statement reads no profile but its units (SI3)
            $where[] = self::idsIn($this->searchedIds($s, $channelId, false));
        }
        // HU3: only the two waiting states (and All) can hold a hidden row
        $hide = $hidden !== null && in_array($state, [null, 'suggested', 'not_matched'], true) && $this->unsoldOn();
        [$shown, $shownParams] = $this->unsoldShown($hide, $channelId, $state === null, $hide && $q !== '' ? $this->exactIds($s, $channelId) : []);
        $col = self::STORE_SORTS[$sort] ?? 'units_30d';
        $other = $col === 'units_30d' ? 'units_365d' : 'units_30d';
        $order = " ORDER BY COALESCE(lp.{$col}, 0) DESC, COALESCE(lp.{$other}, 0) DESC, cl.id ASC";
        $hint = self::UNITS_HINT;
        $from = 'FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE ' . implode(' AND ', $where);
        $left = 0;
        if ($q !== '') {
            // A search reads the profile of every website product of the store: ONE pass gives every id it finds, in the page's
            // order, so the count and the page come from it (two passes before: a COUNT, then the page). Each says whether it is shown.
            $found = $this->db->all('SELECT ' . $hint . 'cl.id' . ($shown === '' ? ', 1' : ', ' . $shown) . ' AS shown ' . $from . $order, [...$shownParams, ...$params]);
            $all = array_map(static fn (array $r): int => (int) $r['id'], array_values(array_filter($found, static fn (array $r): bool => (int) $r['shown'] === 1)));
            $left = count($found) - count($all);
            $total = count($all);
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = min(max(1, $page), $pages);
            $ids = array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        } else {
            if ($shown !== '') {
                $where[] = $shown;
                array_push($params, ...$shownParams);
                $from = 'FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE ' . implode(' AND ', $where);
                $left = self::unsoldOfState($hidden ?? ['all' => 0, 'open' => 0, 'new' => 0], $state);
                // the store's entries of the index of 0051 hold all the listing's columns the conditions read (status, first seen, id)
                $hint = '/*+ INDEX(lp ix_listing_profile_units) INDEX(cl ix_channel_listing_unsold) */ ';
            }
            if ($counts !== null) {
                $total = $state === null ? array_sum($counts) : ($counts[$state] ?? 0);
            } else {
                // Without a text the profile is not read: the store's listings by the (channel_id, status) index.
                $total = (int) $this->db->value('SELECT COUNT(*) FROM channel_listing cl WHERE ' . implode(' AND ', $where), $params);
            }
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = min(max(1, $page), $pages);
            $ids = array_map('intval', $this->db->column('SELECT ' . $hint . 'cl.id ' . $from . $order . ' LIMIT ? OFFSET ?',
                [...$params, self::PER_PAGE, ($page - 1) * self::PER_PAGE]));
        }
        $rows = $ids === [] ? [] : $this->db->all(
            'SELECT cl.id AS listing_id, cl.status, cl.sku_id, cl.units_per_item, cl.map_version, cl.external_variant_id, ' . self::STATE_SQL . ' AS state, '
            . 'lp.product_title, lp.variant_title, lp.brand, lp.units_30d, lp.units_365d, s.code AS sku_code, s.name AS sku_name, '
            . 'p.id AS proposal_id, p.band, p.proposed_sku_id, p.proposed_new_item, ps.code AS proposed_code, ps.name AS proposed_name, '
            . 'pd.id AS pending_id, pd.action AS pending_action '
            . 'FROM channel_listing cl ' . self::STATE_JOINS . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id LEFT JOIN sku s ON s.id = cl.sku_id '
            . 'LEFT JOIN sku ps ON ps.id = p.proposed_sku_id WHERE cl.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' . $order,
            $ids,
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'hidden' => $left];
    }

    // ------------------------------------------------------------------------------------------
    // Products › New Variants (docs/decisions.md U131-U138): one store's website products (variants, in the owner's words) first
    // seen in CW in a period, newest first. "First seen" is channel_listing.created_at: when the row was made (a store's push, or
    // the load of its list), never changed afterwards.
    // ------------------------------------------------------------------------------------------

    /**
     * What a variant of New Variants is (NEW_KIND_SQL), in this order of precedence: waiting for a second OK, on hold, ignored,
     * matched; and of the ones still to match: `not_checked` (no open suggestion: the computer has not looked at it, or what it
     * said was settled since), `new` (its open suggestion says "new product": when the computer checked it against the main
     * store's list it found nothing like it; the only kind a bulk seed takes, and not every one of them: BulkDecisions::seedCheck)
     * and `suggested` (any other open suggestion: a match to an item, or one the computer could not decide).
     */
    public const NEW_KINDS = ['new', 'suggested', 'not_checked', 'linked', 'quarantined', 'waiting', 'ignored', 'landing'];
    /** New Variants' filter (null = every variant): all still to match, then each kind of them. */
    public const NEW_FILTERS = ['unmatched', 'new', 'suggested', 'not_checked'];
    /** The kinds still to match (the filter `unmatched`): the rows a bulk action can be ticked for, and the ones that say what the website says of them. */
    public const NEW_OPEN = ['new', 'suggested', 'not_checked'];
    /** The periods New Variants offers besides its setting's and "any time", in days. */
    public const NEW_PERIODS = [7, 30, 90];
    /** "One store, newest first, since a date": the index of 0026. A hint naming an index that is not there yet is ignored. */
    private const SEEN_HINT = '/*+ INDEX(cl ix_channel_listing_seen) */ ';
    /**
     * The same, with the open suggestion `p` joined after the listing by its unique index (the list keeps the index's order: never
     * a sort, and never a pass over every suggestion, however few rows the tables hold).
     */
    private const SEEN_JOIN_HINT = '/*+ INDEX(cl ix_channel_listing_seen) JOIN_ORDER(cl, p) INDEX(p uq_match_proposal_open) */ ';
    /** An open suggestion `p` that says "new product": what BulkDecisions' new-product check asks for, both marks. */
    private const IS_NEW_ITEM = "(p.proposed_new_item = 1 AND p.band = 'New item')";
    private const NEW_KIND_SQL = "CASE WHEN pd.id IS NOT NULL THEN 'waiting' WHEN cl.status = 'quarantined' THEN 'quarantined' WHEN cl.status = 'ignored' THEN 'ignored' "
        . "WHEN cl.status = 'landing' THEN 'landing' "
        . "WHEN cl.status = 'mapped' THEN 'linked' WHEN p.id IS NULL THEN 'not_checked' WHEN " . self::IS_NEW_ITEM . " THEN 'new' ELSE 'suggested' END";
    /** Still to match: not matched, not ignored, no decision waiting for a second OK. */
    private const NEW_UNMATCHED = "cl.status IN ('unmapped', 'suggested') AND " . self::NOT_WAITING;
    private const NEW_ORDER = ' ORDER BY cl.created_at DESC, cl.id DESC';
    /**
     * "Every suggestion of the strength New product, of every state, then its listing": the suggestions are read from the index of
     * 0020 alone (status, band, listing_id: never a suggestion's row, which holds its evidence), and each one's listing by its
     * primary key, until one is the store's.
     */
    private const EVER_NEW_HINT = '/*+ JOIN_ORDER(p, cl) INDEX(p ix_match_proposal_open_band) */ ';

    /**
     * When a store's website products were first seen: the oldest and the newest (the two ends of the index of 0026), null when the
     * store has none.
     *
     * @return array{first: ?string, last: ?string}
     */
    public function seenRange(int $channelId): array
    {
        $r = $this->db->one('SELECT MIN(created_at) AS first, MAX(created_at) AS last FROM channel_listing WHERE channel_id = ?', [$channelId]) ?? [];
        return ['first' => isset($r['first']) ? (string) $r['first'] : null, 'last' => isset($r['last']) ? (string) $r['last'] : null];
    }

    /**
     * What CW records of a store's link: its mode in CW (channel.mode), and from its connector's latest heartbeat (channel_health,
     * by the index (channel_id, id)) the mode the website itself reported and how many seconds ago that was. `age` is null when the
     * store's connector has never been heard from; a store that is not there reads as `off`.
     *
     * @return array{mode: string, site_mode: ?string, age: ?int}
     */
    public function storeLink(int $channelId): array
    {
        $r = $this->db->one('SELECT CAST(c.mode AS CHAR) AS mode, CAST(h.site_mode AS CHAR) AS site_mode, TIMESTAMPDIFF(SECOND, h.received_at, NOW(6)) AS age '
            . 'FROM channel c LEFT JOIN channel_health h ON h.id = (SELECT MAX(h2.id) FROM channel_health h2 WHERE h2.channel_id = c.id) WHERE c.id = ?', [$channelId]);
        return ['mode' => (string) ($r['mode'] ?? 'off'), 'site_mode' => isset($r['site_mode']) ? (string) $r['site_mode'] : null,
            'age' => isset($r['age']) ? max(0, (int) $r['age']) : null];
    }

    /**
     * Whether a computer check has ever said "new product" of a website product of this store (a suggestion of that strength, open,
     * decided or replaced by a later one). A store where none has is one whose rows "Create as new items" can only skip: the check
     * compares another store's list with the main store's, so it never says "new" of the main store's own variants, and it says
     * nothing of a store no check was run for. New Variants says so on the page (U135). The cost is one index entry and one
     * primary-key read per "new product" suggestion there is, of every store, until the first of this store: all of them when
     * the answer is no. The caller asks only when the page's own counts do not already show one.
     */
    public function everFoundNew(int $channelId): bool
    {
        return $this->db->value('SELECT ' . self::EVER_NEW_HINT . "1 FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id "
            . "WHERE p.status IN ('open', 'decided', 'superseded') AND p.band = 'New item' AND cl.channel_id = ? LIMIT 1", [$channelId]) !== null;
    }

    /**
     * For each of these stores: its website products first seen since $since (null: ever, productsByStore()), the store selector's
     * counts. One range of the index of 0026 per store (the store's entries since the date; about 15 bytes a product, never the
     * rows), in one statement: a grouped read over every store cannot use the date, the index's second part, and reads every entry.
     *
     * @param list<int> $storeIds
     * @return array<int, int> channel id => count
     */
    public function seenSinceByStore(?string $since, array $storeIds): array
    {
        if ($since === null) {
            return $this->productsByStore();
        }
        $parts = [];
        $params = [];
        foreach ($storeIds as $id) {
            $parts[] = '(SELECT ' . self::SEEN_HINT . (int) $id . ' AS id, COUNT(*) AS n FROM channel_listing cl WHERE cl.channel_id = ? AND cl.created_at >= ?)';
            array_push($params, (int) $id, $since);
        }
        $out = [];
        foreach ($parts === [] ? [] : $this->db->all(implode(' UNION ALL ', $parts), $params) as $r) {
            $out[(int) $r['id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * How many of a store's website products first seen since $since (UTC, CW's own format; null: ever) are of each kind (NEW_KINDS).
     * When the period holds every product of the store (its oldest is not older than $since), the store's own counts answer
     * (storeStateCounts: three narrow reads, as Store Products) with one more read that tells its "new product" suggestions from
     * the others (the open suggestions of that strength, by their index); else one grouped read of the period's entries of the
     * index (the open suggestion's row is read only for a product that has one).
     *
     * An old product not sold in a year (HU2) is in none of the counts: it can only be in a period that starts before the store's
     * unsoldBefore() (its first load, or a year ago). For the store's own counts, the hidden ones are read by unsoldHidden() and taken off
     * their kinds; the grouped read leaves them out by unsoldShown().
     *
     * @param array{first: ?string, last: ?string}|null $range seenRange() when the caller has it
     * @return array<string, int>
     */
    public function newVariantCounts(int $channelId, ?string $since, ?array $range = null): array
    {
        $range ??= $this->seenRange($channelId);
        $out = array_fill_keys(self::NEW_KINDS, 0);
        $before = $this->unsoldBefore($channelId);
        $hide = $before !== null && ($since === null || strcmp($since, $before) < 0);
        if ($since === null || $range['first'] === null || strcmp($range['first'], $since) >= 0) {
            $store = $this->storeStateCounts($channelId);
            $new = (int) $this->db->value("SELECT COUNT(*) FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id WHERE p.status = 'open' "
                . "AND p.band = 'New item' AND p.proposed_new_item = 1 AND cl.channel_id = ? AND " . self::NEW_UNMATCHED, [$channelId]);
            $hid = $hide ? $this->unsoldHidden($channelId) : ['all' => 0, 'open' => 0, 'new' => 0];
            return ['new' => max(0, $new - $hid['new']), 'suggested' => max(0, $store['suggested'] - $new - ($hid['open'] - $hid['new'])),
                'not_checked' => max(0, $store['not_matched'] - ($hid['all'] - $hid['open'])), 'linked' => $store['linked'],
                'quarantined' => $store['quarantined'], 'waiting' => $store['waiting'], 'ignored' => $store['ignored'], 'landing' => $store['landing']];
        }
        // The alias is not `state`: GROUP BY would take match_decision's column of that name before it.
        [$shown, $shownParams] = $this->unsoldShown($hide, $channelId, true);
        foreach ($this->db->all('SELECT ' . self::SEEN_JOIN_HINT . self::NEW_KIND_SQL . ' AS kind, COUNT(*) AS n FROM channel_listing cl ' . self::STATE_JOINS
            . 'WHERE cl.channel_id = ? AND cl.created_at >= ?' . ($shown === '' ? '' : ' AND ' . $shown) . ' GROUP BY kind', [$channelId, $since, ...$shownParams]) as $r) {
            $out[(string) $r['kind']] = (int) $r['n'];
        }
        return $out;
    }

    /** How many variants a filter of New Variants holds, from newVariantCounts' figures (null: all of them). @param array<string, int> $counts */
    public static function newVariantTotal(array $counts, ?string $filter): int
    {
        return match ($filter) {
            null => array_sum($counts),
            'unmatched' => ($counts['new'] ?? 0) + ($counts['suggested'] ?? 0) + ($counts['not_checked'] ?? 0),
            default => $counts[$filter] ?? 0,
        };
    }

    /**
     * The ids of a store's website products first seen since $since (null: ever), of $filter (NEW_FILTERS; null: every product),
     * found by $q (Store Products' search; '' for none), newest first (first seen, then id): a page of PER_PAGE ($page from 1), or
     * every one up to $limit when $page is null (the CSV). The total of a list without a text is $counts' (newVariantCounts), so
     * only a search is counted here (it reads the profiles of the period's products, as Store Products' search reads the store's).
     * "Still to match" reads the narrow listing row; "not checked yet" also probes the open suggestion's unique index; "new" and
     * "suggested" join the open suggestion after the listing (JOIN_ORDER), so the list is still read in the index's order.
     *
     * @param array<string, int> $counts
     * @return array{ids: list<int>, total: int, page: int, pages: int}
     */
    public function newVariants(int $channelId, ?string $since, ?string $filter, string $q, ?int $page, array $counts, int $limit = self::PER_PAGE): array
    {
        $where = ['cl.channel_id = ?'];
        $params = [$channelId];
        if ($since !== null) {
            $where[] = 'cl.created_at >= ?';
            $params[] = $since;
        }
        $filter = $filter !== null && in_array($filter, self::NEW_FILTERS, true) ? $filter : null;
        $join = '';
        $hint = self::SEEN_HINT;
        if ($filter !== null) {
            $where[] = self::NEW_UNMATCHED;
        }
        if ($filter === 'not_checked') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM match_proposal po WHERE po.open_listing_id = cl.id)';
        } elseif ($filter === 'new' || $filter === 'suggested') {
            $join = 'JOIN match_proposal p ON p.open_listing_id = cl.id ';
            $where[] = ($filter === 'new' ? '' : 'NOT ') . self::IS_NEW_ITEM;
            $hint = self::SEEN_JOIN_HINT;
        }
        $q = trim($q);
        if ($q !== '') {
            // Store Products' search, by the indexes (searchedIds): the statement reads no profile, and only the rows of those ids
            // (by the primary key: not the period's index, which would walk every variant of the store; SI3)
            $where[] = self::idsIn($this->searchedIds(SearchText::parse($q), $channelId, false));
            $hint = $join === '' ? '' : '/*+ JOIN_ORDER(cl, p) INDEX(p uq_match_proposal_open) */ ';
        }
        // An old product not sold in a year is left out (HU2), unless the search names it exactly (HU4): only a period that starts
        // before the store's unsoldBefore() can hold one, so the statement of a later period is the one of before.
        $before = $this->unsoldBefore($channelId);
        $hide = $before !== null && ($since === null || strcmp($since, $before) < 0);
        [$shown, $shownParams] = $this->unsoldShown($hide, $channelId, $filter === null, $hide && $q !== '' ? $this->exactIds(SearchText::parse($q), $channelId) : []);
        if ($shown !== '') {
            $where[] = $shown;
            array_push($params, ...$shownParams);
        }
        $from = 'FROM channel_listing cl ' . $join . 'WHERE ' . implode(' AND ', $where);
        if ($q !== '' && $page !== null) {
            // A search reads the profile of every variant of the period: ONE pass gives every id it finds, newest first, so the count
            // and the page come from it (two passes before: a COUNT, then the page).
            $all = array_map('intval', $this->db->column('SELECT ' . $hint . 'cl.id ' . $from . self::NEW_ORDER, $params));
            $total = count($all);
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = min(max(1, $page), $pages);
            return ['ids' => array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE), 'total' => $total, 'page' => $page, 'pages' => $pages];
        }
        $total = $q === '' ? self::newVariantTotal($counts, $filter) : (int) $this->db->value('SELECT ' . $hint . 'COUNT(*) ' . $from, $params);
        if ($page === null) {
            $ids = $total === 0 ? [] : array_map('intval', $this->db->column('SELECT ' . $hint . 'cl.id ' . $from . self::NEW_ORDER . ' LIMIT ?', [...$params, $limit]));
            return ['ids' => $ids, 'total' => $total, 'page' => 1, 'pages' => 1];
        }
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $ids = $total === 0 ? [] : array_map('intval', $this->db->column('SELECT ' . $hint . 'cl.id ' . $from . self::NEW_ORDER . ' LIMIT ? OFFSET ?',
            [...$params, self::PER_PAGE, ($page - 1) * self::PER_PAGE]));
        return ['ids' => $ids, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * The rows of New Variants for these website products, in the order given: the product's facts (titles, brand, option number,
     * barcodes, price, units sold, first seen), what it is (`kind`, NEW_KIND_SQL), the item it is matched to, its open suggestion,
     * the decision waiting for a second OK, its state on the website (M66: the stored state, the `site_` columns of 0028 with their
     * date; what the website said of it when its features were stored, `features_status` and `is_placeholder`, NULL when not
     * known; and when the computer checked it, `check_status`: the open suggestion's evidence, a text or NULL), with what
     * CW\Mapping\SiteState makes of them: `site_state` (the three read in their order), `site_doubt` (why the state cannot be
     * relied on, or null: `not_known`, `too_old` by the setting read now) and `site_heading` (whether it is a page heading,
     * worked out now from its stored state as "Create as new items" works it out; null when not asked or not known). The heading
     * is asked for EVERY row still to match (NEW_OPEN: New, a suggested match, no suggestion; the review of build 2a, CS-A: once
     * a store's state is loaded this is what says "a page heading" under a row) whose stored state holds the inputs, unless the
     * row says something of the website before it anyway and so needs no count: the stored state says it is not on sale, or its
     * features or the check's record say a heading or a status that is not Published (SiteState::against()). The cost: one more
     * read for each PRODUCT among those rows (its published variants, by the index of 0028 and each one's listing by its primary
     * key), remembered for the call: at most one a row, so 50 a page and 500 a chunk of the CSV,
     * what the open suggestion was made on and what the product is now (`basis_id`, `basis_identity`,
     * `identity_hash`, and `check_title`, the title the computer judged, from the same evidence: BulkDecisions::changedSinceCheck),
     * and the CW number of the item already made from it (`made_code`,
     * BulkDecisions::madeFrom: one more read, by the index on sku.origin_listing_id); for a suggestion of the check on arrival
     * (`check_kind` = arrival) its first reason and the closest item it names (`check_reason`, `closest_code`: one more primary-key
     * read of an item), and what the check left on the profile (`arrival_state`, `arrival_outcome`: columns of the profile row that
     * is read anyway). Primary-key and unique-index reads besides.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function newVariantRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $by = [];
        foreach ($this->db->all(
            'SELECT cl.id AS listing_id, cl.status, cl.sku_id, cl.units_per_item, cl.map_version, cl.external_variant_id, cl.created_at, ' . self::NEW_KIND_SQL . ' AS kind, '
            . 'lp.product_title, lp.variant_title, lp.brand, lp.barcodes, lp.price, lp.units_30d, lp.units_365d, s.code AS sku_code, s.name AS sku_name, '
            . 'lp.site_variant_status, lp.site_product_status, lp.site_product_type, lp.site_product_id, lp.site_is_landing, lp.site_is_default, lp.site_state_at, cl.channel_id, '
            . "JSON_UNQUOTE(JSON_EXTRACT(lp.features, '$.variant_status')) AS features_status, JSON_EXTRACT(lp.features, '$.is_placeholder') AS is_placeholder, "
            // Only a text counts, as where the seed decodes the same evidence (BulkDecisions::seedCheck).
            . "CASE WHEN JSON_TYPE(JSON_EXTRACT(p.evidence, '$.variant_status')) = 'STRING' THEN JSON_UNQUOTE(JSON_EXTRACT(p.evidence, '$.variant_status')) END AS check_status, "
            . "CASE WHEN JSON_TYPE(JSON_EXTRACT(p.evidence, '$.title')) = 'STRING' THEN JSON_UNQUOTE(JSON_EXTRACT(p.evidence, '$.title')) END AS check_title, "
            // A suggestion of the check on arrival (its evidence says so: `check`), its first reason and the closest item it names;
            // and what the check left on the profile of a variant with no suggestion (U144).
            . "CASE WHEN JSON_TYPE(JSON_EXTRACT(p.evidence, '$.check')) = 'STRING' THEN JSON_UNQUOTE(JSON_EXTRACT(p.evidence, '$.check')) END AS check_kind, "
            . "CASE WHEN JSON_TYPE(JSON_EXTRACT(p.evidence, '$.band_reasons[0]')) = 'STRING' THEN JSON_UNQUOTE(JSON_EXTRACT(p.evidence, '$.band_reasons[0]')) END AS check_reason, "
            . 'cs.code AS closest_code, lp.arrival_state, lp.arrival_outcome, '
            . 'lp.identity_hash, pb.proposal_id AS basis_id, pb.identity_hash AS basis_identity, '
            . 'p.id AS proposal_id, p.band, p.proposed_sku_id, p.proposed_new_item, ps.code AS proposed_code, ps.name AS proposed_name, '
            . 'pd.id AS pending_id, pd.action AS pending_action '
            . 'FROM channel_listing cl ' . self::STATE_JOINS . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id LEFT JOIN sku s ON s.id = cl.sku_id '
            . 'LEFT JOIN sku ps ON ps.id = p.proposed_sku_id LEFT JOIN sku cs ON cs.id = p.closest_sku_id LEFT JOIN match_proposal_basis pb ON pb.proposal_id = p.id '
            . 'WHERE cl.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        ) as $r) {
            $by[(int) $r['listing_id']] = $r;
        }
        foreach (BulkDecisions::madeFrom($this->db, $ids) as $id => $code) {
            if (isset($by[$id])) {
                $by[$id]['made_code'] = $code;
            }
        }
        // The state on the website, as the seed reads it (the same functions, so a row and its skip cannot disagree).
        $maxDays = null; // (the setting is read only when a row has a stored state: only such a state has an age)
        $now = Clock::now();
        $siblings = [];
        foreach ($by as $id => $r) {
            $features = ['variant_status' => $r['features_status'], 'is_placeholder' => $r['is_placeholder']];
            $record = ['variant_status' => $r['check_status']];
            $state = SiteState::read($r, $features, $record);
            $by[$id]['site_state'] = $state;
            $by[$id]['site_doubt'] = SiteState::doubt($state, $state['source'] === SiteState::STORED ? ($maxDays ??= SiteState::maxDays($this->db)) : SiteState::MAX_DAYS, $now);
            $by[$id]['site_heading'] = null;
            // Every row still to match, not only one marked New; none whose row says "not published" or "a heading" without it.
            if (in_array($r['kind'], self::NEW_OPEN, true) && SiteState::headingInputs($state) && SiteState::notOnSale($state) === null
                && SiteState::against($state, $features, $record) === ['heading' => false, 'status' => null]) {
                $key = (int) $r['channel_id'] . ':' . $state['product_id'];
                if (!array_key_exists($key, $siblings)) {
                    $siblings[$key] = SiteState::publishedSiblings($this->db, (int) $r['channel_id'], $state['product_id']);
                }
                $by[$id]['site_heading'] = SiteState::heading($state, $r, $siblings[$key]);
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($by[$id])) {
                $out[] = $by[$id];
            }
        }
        return $out;
    }

    /**
     * The check on arrival for one store (New Variants' box, U144): whether it is switched on for it (the setting), how many of
     * its variants wait for it (the queue index alone) and its last run's counts for the store (the last run's audit row, by the
     * index on the action; null when that run did not look at this store, or there was none).
     *
     * @return array{on: bool, waiting: int, last: array{at: string, looked: int, match: int, conflict: int, not_sure: int, left: int}|null}
     */
    public function arrivalCheck(int $channelId, string $code): array
    {
        $on = isset(\CW\Mapping\ArrivalCheck::stores($this->db)[$channelId]);
        $last = $on ? \CW\Mapping\ArrivalCheck::lastRun($this->db) : null;
        $s = $last['per_store'][$code] ?? null;
        return ['on' => $on, 'waiting' => $on ? \CW\Mapping\ArrivalCheck::waiting($this->db, $channelId) : 0,
            'last' => !is_array($s) || (int) ($s['looked'] ?? 0) === 0 ? null : ['at' => (string) $last['at'], 'looked' => (int) $s['looked'], 'match' => (int) ($s['match'] ?? 0),
                'conflict' => (int) ($s['conflict'] ?? 0), 'not_sure' => (int) ($s['not_sure'] ?? 0),
                'left' => (int) array_sum(array_map('intval', is_array($s['left'] ?? null) ? $s['left'] : []))]];
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
        $total = $this->productTotal();
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
     * How many warehouse products By Item and Simple matching list without a filter: the ones not joined into another (ix_sku_merged_into);
     * for Simple matching ($matchable, HU5) also not the ones not sold any more (their product card): STOPPED_SQL taken off, one range of
     * item_card's index of 0051.
     */
    public function productTotal(bool $matchable = false): int
    {
        $all = (int) $this->db->value('SELECT COUNT(*) FROM sku WHERE merged_into_sku_id IS NULL');
        return $matchable ? max(0, $all - (int) $this->db->value(self::STOPPED_SQL)) : $all;
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
        $s = SearchText::parse($q);
        $q = $s->text;
        if ($q === '') {
            return ['', []];
        }
        if ($s->explicitCw()) {
            // "CW-003906", "cw 3906", "#3906": that item (SearchText)
            return ['s.id = ?', [(int) $s->cwId()]];
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
        // every word, in any order, as SearchText compares them (spaces and hyphens left out, "&" as "and")
        [$words, $wordParams] = $s->allWords("CONCAT_WS(' ', s.name, s.brand, s.line, s.flavour)", 's.brand');
        if ($words !== '') {
            $or[] = $words;
            array_push($params, ...$wordParams);
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
     * Simple matching's list ($matchable, with no coverage filter; HU5) also leaves out a product not sold any more (its product card), by
     * item_card's index of 0051; a CW number written as one ("CW-003906", "cw 3906") is that product whatever it is, joined or not sold
     * any more: the page shows it with no box.
     *
     * @param list<int> $storeIds the stores, the one with the fewest matched products first for `every`
     * @return array{0: string, 1: list<mixed>, 2: string} the SQL after SELECT … , its values, and the product id's column
     */
    private function productFrom(?string $coverage, ?int $storeId, array $storeIds, string $q, bool $matchable = false): array
    {
        if ($matchable && $coverage === null) {
            $s = SearchText::parse($q);
            if ($s->explicitCw()) {
                return ['FROM sku s WHERE s.id = ?', [(int) $s->cwId()], 's.id'];
            }
            [$text, $textParams] = $this->productText($q);
            return ['FROM sku s WHERE ' . self::MATCHABLE_SKU . ($text === '' ? '' : ' AND ' . $text), $textParams, 's.id'];
        }
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
    public function productIds(?string $coverage, ?int $storeId, array $storeIds, string $q, ?int $limit = null, int $offset = 0, bool $matchable = false): array
    {
        [$from, $params, $id] = $this->productFrom($coverage, $storeId, $storeIds, $q, $matchable);
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
     * @return list<array<string, mixed>> id, code, name, brand, flavour (the quick match's first words: ByProductController::firstSearch),
     *         and whether it may be matched to (HU5: `merged_into_sku_id` with `merged_code`, and `discontinued`, 1 when its card says it
     *         is not sold any more)
     */
    public function productRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        // Pack B (PB12): with the item's family (By Item names it), two primary-key joins; HU5: its card and the item it was joined into, two more.
        return $this->db->all('SELECT s.id, s.code, s.name, s.brand, s.flavour, f.name AS family_name, s.merged_into_sku_id, m.code AS merged_code, '
            . 'COALESCE(ic.discontinued, 0) AS discontinued FROM sku s LEFT JOIN item_family_member fm ON fm.sku_id = s.id '
            . 'LEFT JOIN item_family f ON f.id = fm.family_id LEFT JOIN sku m ON m.id = s.merged_into_sku_id LEFT JOIN item_card ic ON ic.sku_id = s.id '
            . 'WHERE s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY s.id', $ids);
    }

    /**
     * What the stores hold for some warehouse items, in three reads: the variants (website products) matched to them (`matched`,
     * the best seller of a store first), the open suggestions that propose them on variants still waiting for a match
     * (`suggested`), and the decisions that would match a variant to them and wait for a second OK (`waiting`). Each row names
     * the item (sku_id), the store (channel_id) and the variant. A matched row carries its parent product's name and its units of
     * a year from the profile (the item's "Product: …" line is the best seller's, U122). A suggested row carries the suggestion's
     * number and the variant's map_version: what By Item's bulk form sends back (U124). $names adds every variant's own names
     * (the page's 50 items need them; the CSV of every item does not, so its suggested and waiting reads leave the profiles alone
     * and its matched rows come in listing order), and to the matched and waiting rows what their status on the website is read from
     * (SITE_STATUS_COLUMNS, with the variant's open suggestion by its unique key: Simple matching's cells, SM11).
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
        $site = $names ? ', ' . self::SITE_STATUS_COLUMNS : '';
        $open = $names ? 'LEFT JOIN match_proposal p ON p.open_listing_id = cl.id ' : '';
        return [
            'matched' => $this->db->all(
                'SELECT cl.sku_id, cl.channel_id, cl.id AS listing_id, cl.external_variant_id, cl.units_per_item, cl.status, lp.product_title, lp.units_365d'
                . ($names ? ', lp.variant_title' : '') . $site . ' FROM channel_listing cl ' . $profile . $open
                . "WHERE cl.sku_id IN ({$in}) ORDER BY cl.sku_id, cl.channel_id, " . ($names ? 'COALESCE(lp.units_30d, 0) DESC, ' : '') . 'cl.id',
                $skuIds,
            ),
            'suggested' => $this->db->all(
                'SELECT p.proposed_sku_id AS sku_id, cl.channel_id, p.listing_id, cl.external_variant_id, p.id AS proposal_id, p.band, cl.map_version' . $cols
                . ' FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id ' . ($names ? $profile : '')
                . "WHERE p.status = 'open' AND p.proposed_sku_id IN ({$in}) AND " . self::WAITING_LISTING . ' ORDER BY p.proposed_sku_id, cl.channel_id, p.listing_id',
                $skuIds,
            ),
            'waiting' => $this->db->all(
                'SELECT d.sku_id, cl.channel_id, d.listing_id, cl.external_variant_id, d.id AS decision_id, d.units_per_item' . $cols . $site
                . ' FROM match_decision d JOIN channel_listing cl ON cl.id = d.listing_id ' . ($names ? $profile . $open : '')
                . "WHERE d.state = 'pending_second' AND d.action = 'link' AND d.sku_id IN ({$in}) ORDER BY d.sku_id, cl.channel_id, d.id",
                $skuIds,
            ),
        ];
    }

    /**
     * The store of each of these variants, for the store choice of By Item's bulk action (U124): the ticked rows send every suggested
     * variant of their items, of every store, and only the chosen store's go on. A read of two numbers a variant by primary key.
     *
     * @param list<int> $listingIds
     * @return array<int, int> listing id => channel id (a number that names no variant is left out)
     */
    public function variantStores(array $listingIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($listingIds)), 500) as $chunk) {
            foreach ($this->db->all('SELECT id, channel_id FROM channel_listing WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $out[(int) $r['id']] = (int) $r['channel_id'];
            }
        }
        return $out;
    }

    /**
     * Variants by id, for the preview of By Item's bulk action (U125): each with its store, its option number and its names. One
     * read by primary key (the profile joined by its own), for at most as many rows as one bulk action takes.
     *
     * @param list<int> $listingIds
     * @return array<int, array<string, mixed>> listing id => id, channel_id, external_variant_id, product_title, variant_title
     */
    public function variantsOf(array $listingIds): array
    {
        $listingIds = array_values(array_unique($listingIds));
        $out = [];
        foreach (array_chunk($listingIds, 500) as $chunk) {
            foreach ($this->db->all('SELECT cl.id, cl.channel_id, cl.external_variant_id, lp.product_title, lp.variant_title FROM channel_listing cl '
                . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $out[(int) $r['id']] = $r;
            }
        }
        return $out;
    }

    /**
     * The website product of one store with exactly this ID (its option number: the unique (channel_id, external_variant_id) index), or
     * null: Simple matching's Apply without the script, where the box holds the store's ID (docs/decisions.md SM5).
     */
    public function listingByOption(int $channelId, string $option): ?int
    {
        $id = $this->db->value('SELECT id FROM channel_listing WHERE channel_id = ? AND external_variant_id = ?', [$channelId, $option]);
        return $id === null ? null : (int) $id;
    }

    /**
     * The picker's first list: the website products of one store whose open suggestion proposes this warehouse product and that
     * still wait for a match; with $hideUnsold (Simple matching's empty box, HU2) not an old one that sold nothing in a year.
     * @return list<int> listing ids
     */
    public function suggestedFor(int $skuId, int $channelId, int $limit = 50, bool $hideUnsold = false): array
    {
        [$shown, $shownParams] = $this->unsoldShown($hideUnsold, $channelId, false);
        return array_map('intval', $this->db->column(
            'SELECT p.listing_id FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id '
            . "WHERE p.status = 'open' AND p.proposed_sku_id = ? AND cl.channel_id = ? AND " . self::WAITING_LISTING . ($shown === '' ? '' : ' AND ' . $shown)
            . ' ORDER BY p.listing_id LIMIT ?',
            [$skuId, $channelId, ...$shownParams, $limit],
        ));
    }

    /**
     * One word of the search of a store's waiting variants (waitingListings), asked of everything the variant has, cheapest first (an
     * OR stops at the first place that has it): its option number, names, brand, the website's number of its product (the stored state
     * of 0028) and web address, in the table's collation; any part of a barcode; any value of its attributes (keys are not words); the
     * flavour, range and range words the matching read (the features). JSON_SEARCH reads string values only (never a key), asked with
     * the word in the table's collation, so a word is found whatever its case or accents, as in the names. A word with a digit is also
     * asked of the numbers (WAITING_NUMBER): an attribute's value that is a number (`$**.value`, as the variant's page reads them), the
     * features' product number, strength, size and range numbers, as text. A variant with no profile has its option number only.
     * Every `?` takes the same `%word%`. (Measured on 10,000 profiles: a word costs about twice the names-only search of before; the
     * JSON values are read only where the names do not have it.)
     */
    /** What a word of the search is asked of besides the names (wordSql() on WAITING_TEXT): the barcodes, attributes and features. */
    private const WAITING_JSON = "OR JSON_SEARCH(lp.barcodes, 'one', ?) IS NOT NULL "
        . "OR JSON_SEARCH(lp.attributes, 'one', CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_ai_ci) IS NOT NULL "
        . "OR JSON_SEARCH(lp.features, 'one', CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_ai_ci, NULL, '$.flavour_tokens', '$.line_tokens', '$.line_modifiers') IS NOT NULL";
    /** The names, numbers and addresses a word is looked for in (compared as SearchText::wordSql() says). */
    private const WAITING_TEXT = "CONCAT_WS(' ', cl.external_variant_id, lp.product_title, lp.variant_title, lp.brand, lp.site_product_id, lp.perma_link)";
    /** How many `?` WAITING_JSON has. */
    private const WAITING_JSON_PARAMS = 3;
    /** The numbers a word with a digit is also asked of (see WAITING_JSON): a number holds nothing but digits and its signs. */
    private const WAITING_NUMBER = "CAST(JSON_EXTRACT(lp.attributes, '$**.value') AS CHAR CHARACTER SET utf8mb4) LIKE ? "
        . "OR CAST(JSON_EXTRACT(lp.features, '$.product_id', '$.strength_mg', '$.volume_ml', '$.line_numbers') AS CHAR CHARACTER SET utf8mb4) LIKE ?";
    /** How many `?` WAITING_NUMBER has. */
    private const WAITING_NUMBER_PARAMS = 2;

    /**
     * The picker's search (and its quick match in a cell of By Item): one small page of a store's variants that still wait for a
     * match, best sellers first (30 days, then a year). A text is up to 6 words, and every word must be somewhere in the variant
     * (WAITING_TEXT and WAITING_JSON: its option number, names, brand, web address, the website's number of its product, any part of a barcode, its
     * attributes, its features; the owner's "search all attribute", 20 Oct 2026), or the whole text is its option number or one of
     * its barcodes as before. The longest word is asked first: a variant it rules out is not read for the others. Every waiting
     * variant of the store is read once (a LIKE scan of about 10,000 profiles, in one statement); no count: one more row than the
     * page says whether there is a next one. With $hideUnsold (Simple matching's choices, HU2) an old product that sold nothing in a
     * year is left out unless the text is its exact ID or barcode (HU4); By Item's picker (a lead's page) lists every one, as before.
     *
     * @return array{ids: list<int>, more: bool}
     */
    public function waitingListings(int $channelId, string $q, int $page, int $perPage = self::PICK_PER_PAGE, bool $hideUnsold = false): array
    {
        $where = ['cl.channel_id = ?', self::WAITING_LISTING];
        $params = [$channelId];
        $s = SearchText::parse($q);
        $q = $s->text;
        if ($q !== '' && $s->words === [] && $s->codes === []) {
            // A text with no usable word (only white space trim() leaves, punctuation, or not valid UTF-8) finds nothing, as on the list.
            return ['ids' => [], 'more' => false];
        }
        if ($q !== '') {
            // what the search finds, by the indexes (searchedIds, the quick match's fields: SI3); the statement reads no profile but its units
            $where[] = self::idsIn($this->searchedIds($s, $channelId, true));
        }
        [$shown, $shownParams] = $this->unsoldShown($hideUnsold, $channelId, false, $hideUnsold && $this->unsoldOn() && $q !== '' ? $this->exactIds($s, $channelId) : []);
        if ($shown !== '') {
            $where[] = $shown;
            array_push($params, ...$shownParams);
        }
        $ids = array_map('intval', $this->db->column(
            'SELECT ' . self::UNITS_HINT . 'cl.id FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE '
            . implode(' AND ', $where) . ' ORDER BY COALESCE(lp.units_30d, 0) DESC, COALESCE(lp.units_365d, 0) DESC, cl.id ASC LIMIT ? OFFSET ?',
            [...$params, $perPage + 1, (max(1, $page) - 1) * $perPage],
        ));
        return ['ids' => array_slice($ids, 0, $perPage), 'more' => count($ids) > $perPage];
    }

    /**
     * The quick match's word search as the forgiving search asks it, on a variant's own rows (`cl`, `lp`): every word in the names, the
     * option number, the web address and the website's product number (SearchText::wordSql on WAITING_TEXT), or any part of a barcode,
     * an attribute, a flavour or range word, or (a word with a digit) a number. The rows whose stored text was longer than the column
     * are still asked this way (searchedIds).
     *
     * @return array{0: string, 1: list<string>}
     */
    private function waitingWords(SearchText $s): array
    {
        $and = [];
        $like = [];
        foreach ($s->order() as $i) {
            // the names compared as SearchText does (a plain word as it is, first; the others with the spaces and hyphens left out);
            // the barcodes, attributes and features with the word as typed (a merged word with what stood between: "20 mg")
            $typed = $s->typed[$i];
            $number = preg_match('/\d/u', $typed) === 1;
            [$text, $textParams] = $s->wordSql($i, self::WAITING_TEXT, 'lp.brand');
            $and[] = '(' . $text . ' ' . self::WAITING_JSON . ($number ? ' OR ' . self::WAITING_NUMBER : '') . ')';
            array_push($like, ...$textParams, ...array_fill(0, self::WAITING_JSON_PARAMS + ($number ? self::WAITING_NUMBER_PARAMS : 0), SearchText::like($typed)));
        }
        return ['(' . implode(' AND ', $and) . ')', $like];
    }

    /**
     * What a row needs for CW\Mapping\SiteState::read() of its status on the website (M66; Simple matching says it, SM11), read from
     * the profile (`lp`) and the open suggestion (`p`) the statement joins anyway: the stored state's two statuses and its date, what
     * a matching run recorded (`features_status`; JSON's null is the text "null", which SiteState::text() reads as none) and what the
     * computer's check recorded (`check_status`: a text only, as New Variants reads it).
     */
    private const SITE_STATUS_COLUMNS = 'lp.site_variant_status, lp.site_product_status, lp.site_state_at, '
        . "JSON_UNQUOTE(JSON_EXTRACT(lp.features, '$.variant_status')) AS features_status, "
        . "CASE WHEN JSON_TYPE(JSON_EXTRACT(p.evidence, '$.variant_status')) = 'STRING' THEN JSON_UNQUOTE(JSON_EXTRACT(p.evidence, '$.variant_status')) END AS check_status";

    /**
     * The picker's rows: website products with their state (STORE_STATES), name, price, units sold, barcodes and open suggestion;
     * for By Item's quick match also what its "Match to …" form sends (the variant's map_version, the open suggestion's number and
     * its units) and the features the matching read (strength, size, flavour, range); and what its status on the website is read
     * from (SITE_STATUS_COLUMNS: Simple matching's list, SM11). One read by primary key for a page of them.
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
            'SELECT cl.id AS listing_id, cl.channel_id, cl.external_variant_id, cl.map_version, ' . self::STATE_SQL . ' AS state, lp.product_title, lp.variant_title, lp.brand, '
            . 'lp.price, lp.barcodes, lp.units_30d, lp.units_365d, lp.features, p.id AS proposal_id, p.band, p.proposed_sku_id, p.ai_units_per_item, '
            . self::SITE_STATUS_COLUMNS . ' FROM channel_listing cl '
            . self::STATE_JOINS
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
            . 's.merged_into_sku_id, s.origin, s.origin_listing_id, IF(s.origin = \'vpg_mint\', ol.external_variant_id, NULL) AS vpg_variant_id, '
            // Pack B (PB12): the item's family, which the matching pages name (two primary-key joins).
            . 'f.name AS family_name, '
            // HU5: whether it may be matched to: its card says it is not sold any more (1), and the code of the item it was joined into.
            . 'COALESCE(ic.discontinued, 0) AS discontinued, mi.code AS merged_code '
            . 'FROM sku s LEFT JOIN channel_listing ol ON ol.id = s.origin_listing_id LEFT JOIN item_family_member fm ON fm.sku_id = s.id '
            . 'LEFT JOIN item_family f ON f.id = fm.family_id LEFT JOIN item_card ic ON ic.sku_id = s.id LEFT JOIN sku mi ON mi.id = s.merged_into_sku_id '
            . 'WHERE s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
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
     * the multi-valued index on listing_profile.barcodes (0005). At most $limit listings. Each listing's
     * `variant_status` is its status on the website as CW\Mapping\SiteState reads it (M66): the stored
     * state first, then what its features recorded; null when not known.
     *
     * @param list<string> $barcodes
     * @return array{items: list<array<string, mixed>>, listings: list<array<string, mixed>>}
     */
    public function barcodeElsewhere(int $listingId, array $barcodes, int $limit = 10): array
    {
        // Every barcode of the listing, in every form it may be stored in elsewhere (Gtin::forms: as it is, its key, and the key
        // padded with zeros up to 14 digits): the same forms as the bulk rule's look-up (BulkDecisions::barcodeElsewhere).
        $codes = Gtin::forms($barcodes);
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
            // Its status on the website (M66): the stored state's when it has one, else what its features recorded (resolved below).
            . 'lp.site_variant_status, lp.site_product_status, lp.site_product_type, lp.site_product_id, lp.site_is_landing, lp.site_is_default, lp.site_state_at, '
            . "JSON_UNQUOTE(JSON_EXTRACT(lp.features, '$.variant_status')) AS features_status, lp.units_365d "
            . 'FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN sku s ON s.id = cl.sku_id '
            . 'WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON)) AND lp.listing_id <> ? ORDER BY ch.code, cl.id LIMIT ?',
            [json_encode($codes, JSON_THROW_ON_ERROR), $listingId, $limit],
        );
        foreach ($listings as $i => $l) {
            // (the status that says it is not on sale, its own or its product's, when one does; else its own)
            $state = SiteState::read($l, ['variant_status' => $l['features_status']]);
            $listings[$i]['variant_status'] = SiteState::notOnSale($state) ?? $state['variant_status'];
        }
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
     * $matching (the website product's "Pick a different product", HU5): an item not sold any more (its product card) is left out too,
     * except that a CW number written as one ("CW-003906", "cw 3906") finds that item whatever it is, joined or not sold any more: the
     * page shows it with no "Use" (skus()' `merged_into_sku_id` / `discontinued` say why).
     *
     * $beginsFirst (the order's and the invoice's item box, docs/decisions.md PV31): the items whose name begins with the words are read
     * first, so a long list of other matches never pushes them out of the $limit.
     *
     * @return list<array<string, mixed>>
     */
    public function searchSkus(string $q, int $limit = 20, bool $matching = false, bool $beginsFirst = false): array
    {
        $s = SearchText::parse($q);
        $q = $s->text;
        if (mb_strlen($q) < 2) {
            return [];
        }
        $ids = [];
        $cw = $s->cwId();
        if ($cw !== null) {
            // "CW-003906", "cw 3906", "CW3906", "#3906", "3906" (SearchText)
            $ids = array_merge($ids, $this->db->column('SELECT id FROM sku WHERE id = ?', [$cw]));
        }
        if (preg_match('/^[0-9]{6,64}$/D', $q) === 1) {
            // sku_barcode holds the GTIN key (Gtin::key: digits, no leading zeros), as a scanner may add them.
            $ids = array_merge($ids, $this->db->column('SELECT sku_id FROM sku_barcode WHERE barcode IN (?, ?)', [$q, Gtin::key($q) ?? $q]));
        }
        // every word, in any order, as SearchText compares them (spaces and hyphens left out); not for a text written as a CW number
        [$words, $params] = $s->explicitCw() ? ['', []] : $s->allWords("CONCAT_WS(' ', name, brand, line, flavour)", 'brand');
        if ($words !== '') {
            if ($beginsFirst) {
                $params[] = addcslashes($q, '\\%_') . '%';
            }
            $params[] = $limit;
            // (for a match, not one that is not sold any more either: the page's 20 are the ones it may offer, HU5)
            $ids = array_merge($ids, $this->db->column(
                'SELECT s.id FROM sku s WHERE ' . ($matching ? self::MATCHABLE_SKU : 's.merged_into_sku_id IS NULL') . ' AND ' . $words
                . ' ORDER BY ' . ($beginsFirst ? 's.name LIKE ? DESC, ' : '') . 's.name LIMIT ?',
                $params,
            ));
        }
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, $limit);
        $rows = $this->skus($ids);
        $exact = $matching && $s->explicitCw() ? $s->cwId() : null;
        $out = [];
        foreach ($ids as $id) {
            if (isset($rows[$id]) && ($id === $exact || ($rows[$id]['merged_into_sku_id'] === null && (!$matching || (int) $rows[$id]['discontinued'] === 0)))) {
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
        if (mb_strlen(trim($q)) < 2) {
            return [];
        }
        // the lists' search of every store, by the indexes (searchedIds); the best sellers among them by the units' index alone, then
        // only those rows (SI3)
        $found = $this->searchedIds(SearchText::parse($q), null, false);
        $page = $found === [] ? [] : array_map('intval', $this->db->column('SELECT ' . self::UNITS_HINT . 'cl.id FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            . 'WHERE ' . self::idsIn($found) . ' ORDER BY COALESCE(lp.units_30d, 0) DESC, cl.id LIMIT ?', [$limit]));
        return $page === [] ? [] : $this->db->all(
            'SELECT cl.id, cl.status, cl.sku_id, cl.external_variant_id, ch.code AS channel_code, lp.product_title, lp.variant_title, lp.units_30d, s.code AS sku_code '
            . 'FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id LEFT JOIN sku s ON s.id = cl.sku_id '
            . 'WHERE ' . self::idsIn($page) . ' ORDER BY COALESCE(lp.units_30d, 0) DESC, cl.id',
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
            'SELECT w.id AS warehouse_id, w.code, w.name, w.is_sellable, sb.on_hand, sb.allocated, sb.held, sb.incoming, '
            . 'sb.on_hand + sb.incoming - sb.allocated - sb.held AS available, sb.counted_at '
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
    /**
     * Why each of some website products is a landing page (docs/decisions.md LP6): the latest applied `landing` decision of each, its
     * reason (LandingRule::REASONS, or `person`), who (null: the system) and when.
     *
     * @param list<int> $listingIds
     * @return array<int, array{reason: string, by: ?string, at: string, source: ?string}>
     */
    public function landingWhy(array $listingIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $listingIds)));
        $out = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            foreach ($this->db->all("SELECT d.listing_id, d.created_at, u.display_name, JSON_UNQUOTE(JSON_EXTRACT(d.detail, '$.landing.reason')) AS reason, "
                . "JSON_UNQUOTE(JSON_EXTRACT(d.detail, '$.landing.source')) AS source FROM match_decision d LEFT JOIN staff_user u ON u.id = d.decided_by "
                . "WHERE d.action = 'landing' AND d.state = 'applied' AND d.listing_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY d.listing_id, d.id', $chunk) as $r) {
                $out[(int) $r['listing_id']] = ['reason' => is_string($r['reason']) && $r['reason'] !== 'null' ? $r['reason'] : 'person',
                    'by' => $r['display_name'] === null ? null : (string) $r['display_name'], 'at' => (string) $r['created_at'],
                    'source' => is_string($r['source']) && $r['source'] !== 'null' ? $r['source'] : null];
            }
        }
        return $out;
    }

    public static function escapeLike(string $s): string
    {
        return addcslashes($s, '\\%_');
    }

    // ------------------------------------------------------------------------------------------
    // HU (hide-unsold, 26 Oct 2026; docs/decisions.md HU1-HU9): the matching lists leave out a website product not matched yet that sold
    // nothing in the last year and was first seen more than a year ago or was on its store at its first load (HU9), and Simple matching
    // and the website product's picker leave out a warehouse product that is not sold any more (its product card) or joined into another
    // one. Nothing in the data changes.
    // ------------------------------------------------------------------------------------------

    /** The setting (0051): 365 = on, 0 = off (Settings::RULES); not there or not a number: off. */
    public const UNSOLD_SETTING = 'mapping.hide_unsold_days';

    /**
     * How many of a store's website products are hidden, read from the index of 0051 alone (store, the two waiting statuses, first
     * seen before the date: one range), each one's units from the narrow index of 0020 and its waiting decision from its unique index:
     * `n_all` of them, `n_open` with an open suggestion (Store Products' "Suggested"; the rest are "Not matched") and `n_new` whose open
     * suggestion says "new product" (New Variants' "New"). Params: the store, its date (unsoldBefore()). The NOT EXISTS of the units
     * asks for a SOLD unit, so a product with no profile, or with no units recorded, counts as not sold (HU1). The waiting decision is
     * looked up for each of those rows (NO_SEMIJOIN: else MySQL reads every decision's entry of that index first, to compare them all).
     */
    public const UNSOLD_HIDDEN_SQL = 'SELECT /*+ INDEX(cl ix_channel_listing_unsold) */ COUNT(*) AS n_all, '
        . 'COALESCE(SUM(EXISTS (SELECT 1 FROM match_proposal po WHERE po.open_listing_id = cl.id)), 0) AS n_open, '
        . "COALESCE(SUM(EXISTS (SELECT 1 FROM match_proposal pn WHERE pn.open_listing_id = cl.id AND pn.proposed_new_item = 1 AND pn.band = 'New item')), 0) AS n_new "
        . "FROM channel_listing cl WHERE cl.channel_id = ? AND cl.status IN ('unmapped', 'suggested') AND cl.created_at < ? "
        . 'AND NOT EXISTS (SELECT /*+ INDEX(lu ix_listing_profile_units) */ 1 FROM listing_profile lu WHERE lu.listing_id = cl.id AND lu.units_365d > 0) '
        . 'AND NOT EXISTS (SELECT /*+ NO_SEMIJOIN() */ 1 FROM match_decision pw WHERE pw.pending_listing_id = cl.id)';

    /** How many warehouse products, not joined into another one, are not sold any more (their product card): the index of 0051 on item_card. */
    public const STOPPED_SQL = 'SELECT /*+ INDEX(c ix_item_card_discontinued) */ COUNT(*) FROM item_card c JOIN sku s ON s.id = c.sku_id '
        . 'WHERE c.discontinued = 1 AND s.merged_into_sku_id IS NULL';

    /** A warehouse product `s` that may be offered to match to: not joined into another one, not "not sold any more" on its card (HU5). */
    private const MATCHABLE_SKU = 's.merged_into_sku_id IS NULL AND NOT EXISTS (SELECT /*+ INDEX(c ix_item_card_discontinued) */ 1 FROM item_card c '
        . 'WHERE c.discontinued = 1 AND c.sku_id = s.id)';

    /**
     * The cut-offs, read once per instance (one request sees one set): null when the setting is off (0, not set, or not there yet: a code
     * before 0051); else `year` (now minus the days, UTC) and, per store, the moment before which its website product first seen is old:
     * the later of the year and just after its first load (`channel.first_load_at`, 0051: a product first seen AT it was on the store
     * then; HU9). false: not read yet.
     *
     * @var array{year: string, stores: array<int, string>}|false|null
     */
    private array|false|null $unsoldCuts = false;

    /** @return array{year: string, stores: array<int, string>}|null */
    private function unsoldCuts(): ?array
    {
        if ($this->unsoldCuts === false) {
            $days = Settings::number($this->db, self::UNSOLD_SETTING, 0);
            $this->unsoldCuts = null;
            if ($days > 0) {
                $year = Clock::db(Clock::now()->modify('-' . $days . ' days'));
                $stores = [];
                foreach ($this->db->all('SELECT id, first_load_at FROM channel') as $r) {
                    $after = $r['first_load_at'] === null ? null : Clock::db(Clock::fromDb((string) $r['first_load_at'])->modify('+1 microsecond'));
                    $stores[(int) $r['id']] = $after !== null && strcmp($after, $year) > 0 ? $after : $year;
                }
                $this->unsoldCuts = ['year' => $year, 'stores' => $stores];
            }
        }
        return $this->unsoldCuts;
    }

    /** Whether the matching lists leave out the old products not sold in a year (the setting is on: HU1). */
    public function unsoldOn(): bool
    {
        return $this->unsoldCuts() !== null;
    }

    /**
     * The moment (UTC, CW's format) before which a website product of this store first seen, not matched yet and with nothing sold in the
     * last year is left out of the matching lists; null when the setting is off. "First seen" is channel_listing.created_at: when the
     * warehouse system made the row (the store's push, or the load of its list), never changed after. Old is: first seen more than a year
     * ago (the stores' own year of units, listing_profile.units_365d: HU1), or at or before the end of the store's first load (it was on the
     * store then: HU9, the owner's answer "b"). One value per store, read once per request: the lists never work it out per row.
     */
    public function unsoldBefore(int $channelId): ?string
    {
        $c = $this->unsoldCuts();
        return $c === null ? null : ($c['stores'][$channelId] ?? $c['year']);
    }

    /**
     * The condition that keeps a website product (`cl`) on a list while the old ones not sold in a year are hidden ($hide and the setting
     * on; HU1, HU9): first seen since its store's unsoldBefore() (one store: one `?`; every store, $channelId null: each store's own value,
     * a CASE of the stores' ids written with their values, a handful), or a unit sold in the last year (the narrow index of 0020: never the
     * profile's row), or found by the search's exact ID or barcode ($exact, exactIds(): HU4). For a list of every state ($anyState: Store
     * Products' All, New Variants' All) also any product that is not waiting for a match (matched, on hold, ignored, a landing page, or with
     * a decision waiting for a second OK). The cheap tests come first: a new product is kept without a look at its units. ['', []] when
     * nothing is hidden.
     *
     * @param list<int> $exact
     * @return array{0: string, 1: list<mixed>}
     */
    private function unsoldShown(bool $hide, ?int $channelId, bool $anyState, array $exact = []): array
    {
        $cuts = $hide ? $this->unsoldCuts() : null;
        if ($cuts === null) {
            return ['', []];
        }
        $or = $anyState ? ["cl.status NOT IN ('unmapped', 'suggested')"] : [];
        if ($channelId !== null) {
            $or[] = 'cl.created_at >= ?';
            $params = [$cuts['stores'][$channelId] ?? $cuts['year']];
        } else {
            $params = [];
            $when = '';
            foreach ($cuts['stores'] as $id => $before) {
                $when .= ' WHEN ? THEN ?';
                array_push($params, $id, $before);
            }
            $or[] = $when === '' ? 'cl.created_at >= ?' : 'cl.created_at >= CAST(CASE cl.channel_id' . $when . ' ELSE ? END AS DATETIME(6))';
            $params[] = $cuts['year'];
        }
        $or[] = 'EXISTS (SELECT /*+ INDEX(lu ix_listing_profile_units) */ 1 FROM listing_profile lu WHERE lu.listing_id = cl.id AND lu.units_365d > 0)';
        if ($anyState) {
            $or[] = 'EXISTS (SELECT 1 FROM match_decision pw WHERE pw.pending_listing_id = cl.id)';
        }
        if ($exact !== []) {
            $or[] = self::idsIn($exact);
        }
        return ['(' . implode(' OR ', $or) . ')', $params];
    }

    /**
     * The website products a search names exactly (HU4): its option number as typed (uq_channel_listing_variant) and its barcodes (the
     * index of 0005, every form of a number: Gtin::forms), of one store or of every store ($channelId null). A hidden product is found by
     * these, never by the words of its name.
     *
     * @return list<int>
     */
    private function exactIds(SearchText $s, ?int $channelId): array
    {
        if ($s->isEmpty()) {
            return [];
        }
        $ids = array_map('intval', $channelId === null
            ? $this->db->column('SELECT cl.id FROM channel ch JOIN channel_listing cl ON cl.channel_id = ch.id AND cl.external_variant_id = ?', [$s->text])
            : $this->db->column('SELECT id FROM channel_listing WHERE channel_id = ? AND external_variant_id = ?', [$channelId, $s->text]));
        return array_values(array_unique([...$ids, ...$this->listingsByCode($s, $channelId)]));
    }

    /**
     * How many of a store's website products are hidden (UNSOLD_HIDDEN_SQL): `all`, `open` (with an open suggestion), `new` (whose open
     * suggestion says "new product"); all 0 when the setting is off.
     *
     * @return array{all: int, open: int, new: int}
     */
    public function unsoldHidden(int $channelId): array
    {
        $before = $this->unsoldBefore($channelId);
        $r = $before === null ? null : $this->db->one(self::UNSOLD_HIDDEN_SQL, [$channelId, $before]);
        return ['all' => (int) ($r['n_all'] ?? 0), 'open' => (int) ($r['n_open'] ?? 0), 'new' => (int) ($r['n_new'] ?? 0)];
    }

    /** How many of Store Products' rows of a state are hidden ($hidden: unsoldHidden()): its two waiting states, and All (null). @param array{all: int, open: int, new: int} $hidden */
    public static function unsoldOfState(array $hidden, ?string $state): int
    {
        return match ($state) {
            null => $hidden['all'],
            'suggested' => $hidden['open'],
            'not_matched' => $hidden['all'] - $hidden['open'],
            default => 0,
        };
    }
}
