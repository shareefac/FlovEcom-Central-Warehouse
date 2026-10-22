<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Settings;

/**
 * Which website products of a store are landing pages, and marking them (docs/decisions.md LP2-LP5). The rule is LandingRule's;
 * the mark is a DecisionService decision (`landing`), each in its own transaction; this class reads the rows and decides who is
 * asked.
 *
 *   plan()      READ ONLY: every website product of a store (or some of them and the other products of their pages), what the rule
 *               says, and what would be done: `mark` (with why), or left with why (OUTCOMES). Nothing is locked or written.
 *   apply()     marks the `mark` rows of a plan (actor: the caller, a system job): a refusal under the lock (the row changed since,
 *               a person said real, a decision waiting) leaves the row and is counted. The classifying tool writes one audit row per
 *               run (`landing.run`) besides each decision's own.
 *   onArrival() after a listing push (ListingIngestService::push()): the pushed products whose profile or website state changed and
 *               the other products of their pages; the website's flag always, the rule while mapping.landing_rule_on_arrival is on.
 *               Never throws: the push has already been answered for (a failure is logged and the next push or the tool tries again).
 *
 * NEVER BACK AND FORTH (LP3). The system only ever marks; it never unmarks. It never marks a product a person said is a real
 * product (DecisionService::saidReal()), whatever the rule or the website says later (`said_real`); a landing page the rule or the
 * website's word no longer finds stays one (`no_longer`); a linked one (`linked`), one decided one at a time (`one_at_a_time`) and
 * one waiting for a second OK (`waiting`) are left for a person; an ignored one is marked only when asked (`ignored`).
 */
final class LandingPages
{
    public const SYSTEM = 'system:landing_pages';
    public const SETTING = 'mapping.landing_rule_on_arrival';
    /** What plan() does with a website product it looks at. */
    public const OUTCOMES = ['mark', 'already', 'no_longer', 'linked', 'said_real', 'one_at_a_time', 'waiting', 'ignored', 'not_landing'];
    /** The statuses the rule looks at as candidates (the rest is only reported). */
    private const OPEN = ['unmapped', 'suggested'];
    private const COLUMNS = 'cl.id AS listing_id, cl.external_variant_id, cl.status, cl.map_version, lp.product_title, lp.variant_title, lp.attributes, lp.barcodes, '
        . 'lp.perma_link, lp.perma_key, lp.site_variant_status, lp.site_product_status, lp.site_product_type, lp.site_product_id, lp.site_is_landing, lp.site_is_default, '
        . 'lp.site_product_link, lp.site_state_at';

    public function __construct(private readonly Db $db, private readonly ?DecisionService $decisions = null)
    {
    }

    /**
     * @param list<int>|null $only these products and the other products of their pages (null: every product of the store)
     * @param bool $rule false: only the website's own flag marks (the rule is asked for the report, never for a mark)
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, reasons: array<string, int>, not: array<string, int>}
     *         rows: every product looked at that is or would be a landing page, or is one already, with its outcome
     */
    public function plan(int $channelId, ?array $only = null, bool $includeIgnored = false, bool $rule = true): array
    {
        $rows = $only === null ? $this->storeRows($channelId) : $this->pageRows($channelId, $only);
        $byProduct = [];
        $byParent = [];
        foreach ($rows as $id => $r) {
            if ($r['site_product_id'] !== null && $r['site_product_id'] !== '') {
                $byProduct[(string) $r['site_product_id']][] = $id;
            }
            $parent = LandingRule::parentAddress($r['perma_link']);
            if ($parent !== null) {
                $byParent[$parent][] = $id;
            }
        }
        $maxDays = SiteState::maxDays($this->db);
        $now = Clock::now();
        $ids = array_keys($rows);
        $pending = $this->pending($ids);
        $real = $this->saidReal($ids);
        $counts = array_fill_keys(self::OUTCOMES, 0);
        $reasons = [];
        $not = [];
        $out = [];
        $candidates = [];
        foreach ($rows as $id => $r) {
            if (!$r['in_scope']) {
                continue; // the third ring of pageRows(): it only feeds the rule of its neighbours
            }
            $state = SiteState::read($r);
            $fresh = $state['source'] === SiteState::STORED && !SiteState::tooOld($state['as_of'], $maxDays, $now);
            // The other products of its page: the same product number, and the options whose address hangs under its own (both: a
            // store's state may be loaded for some of its products only). The rule still asks each for the same title and an option.
            $pid = $r['site_product_id'];
            $sibIds = array_unique([...($pid !== null && $pid !== '' ? ($byProduct[(string) $pid] ?? []) : []), ...($byParent[LandingRule::address($r['perma_link']) ?? "\0"] ?? [])]);
            $sibs = [];
            foreach ($sibIds as $s) {
                if ($s !== $id) {
                    $sibs[] = $rows[$s];
                }
            }
            $d = LandingRule::decide($r + ['website' => $state, 'state_fresh' => $fresh], $sibs);
            if (!$rule && $d['reason'] === LandingRule::SAME_PAGE) {
                $d = ['landing' => false, 'reason' => null, 'not' => ['rule_off'], 'evidence' => []];
            }
            $status = (string) $r['status'];
            if (!$d['landing']) {
                if ($status === 'landing') {
                    $out[] = $this->row($r, 'no_longer', $d);
                    $counts['no_longer']++;
                } else {
                    $counts['not_landing']++;
                    foreach ($d['not'] as $why) {
                        $not[$why] = ($not[$why] ?? 0) + 1;
                    }
                }
                continue;
            }
            $outcome = match (true) {
                $status === 'landing' => 'already',
                in_array($status, ['mapped', 'quarantined'], true) => 'linked',
                isset($pending[$id]) => 'waiting',
                isset($real[$id]) => 'said_real',
                $status === 'ignored' && !$includeIgnored => 'ignored',
                default => 'mark',
            };
            if ($outcome === 'mark') {
                $candidates[] = $id;
            }
            $out[] = $this->row($r, $outcome, $d);
            $counts[$outcome]++;
        }
        // A product decided one at a time is a person's (the same reads as the lists: OneAtATime).
        $one = OneAtATime::of($this->db, $candidates);
        foreach ($out as $i => $row) {
            if ($row['outcome'] === 'mark' && isset($one[$row['listing_id']])) {
                $out[$i]['outcome'] = 'one_at_a_time';
                $counts['mark']--;
                $counts['one_at_a_time']++;
            }
        }
        foreach ($out as $row) {
            if ($row['outcome'] === 'mark') {
                $reasons[$row['reason']] = ($reasons[$row['reason']] ?? 0) + 1;
            }
        }
        return ['rows' => $out, 'counts' => $counts, 'reasons' => $reasons, 'not' => $not];
    }

    /**
     * Marks the `mark` rows of a plan, each a decision of its own.
     *
     * @param array{rows: list<array<string, mixed>>} $plan
     * @return array{marked: int, refused: array<string, int>, ids: list<int>}
     */
    public function apply(Caller $caller, array $plan, string $source): array
    {
        $ds = $this->decisions ?? new DecisionService($this->db);
        $marked = 0;
        $refused = [];
        $ids = [];
        foreach ($plan['rows'] as $row) {
            if ($row['outcome'] !== 'mark') {
                continue;
            }
            try {
                $ds->decide($caller, ['action' => 'landing', 'listing_id' => $row['listing_id'], 'expected_map_version' => $row['map_version'],
                    'landing' => ['reason' => $row['reason'], 'source' => $source] + ($row['rule'] !== null ? ['rule' => $row['rule']] : []) + ['evidence' => $row['evidence']]]);
                $marked++;
                $ids[] = $row['listing_id'];
            } catch (CwException $e) {
                $refused[$e->errorCode] = ($refused[$e->errorCode] ?? 0) + 1;
            }
        }
        return ['marked' => $marked, 'refused' => $refused, 'ids' => $ids];
    }

    /**
     * After a listing push: classifies these products and the other products of their pages (LP4). Never throws.
     *
     * @param list<int> $listingIds
     * @return array{marked: int, refused: array<string, int>, ids: list<int>}|null null when it could not run (logged)
     */
    public function onArrival(int $channelId, array $listingIds): ?array
    {
        if ($listingIds === []) {
            return ['marked' => 0, 'refused' => [], 'ids' => []];
        }
        try {
            $plan = $this->plan($channelId, $listingIds, false, Settings::flag($this->db, self::SETTING, true));
            return $this->apply(Caller::system('landing_on_arrival'), $plan, 'arrival');
        } catch (\Throwable $e) {
            error_log('[cw] landing pages on arrival, store ' . $channelId . ': ' . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
    }

    /** One audit row for a run of the classifying tool (LP5), besides each decision's own. @param array<string, mixed> $summary */
    public function audit(Caller $caller, int $channelId, array $summary): void
    {
        Audit::write($this->db, $caller, 'landing.run', 'channel', (string) $channelId, null, $summary);
    }

    // ------------------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $r @param array<string, mixed> $d @return array<string, mixed> */
    private function row(array $r, string $outcome, array $d): array
    {
        return ['listing_id' => (int) $r['listing_id'], 'variant' => (string) $r['external_variant_id'], 'status' => (string) $r['status'],
            'map_version' => (int) $r['map_version'], 'product_title' => $r['product_title'], 'variant_title' => $r['variant_title'], 'outcome' => $outcome,
            'reason' => $d['reason'], 'rule' => $d['reason'] === LandingRule::SAME_PAGE ? LandingRule::VERSION : null, 'evidence' => $d['evidence'],
            'not' => $d['not']];
    }

    /** Every website product of a store with its profile. @return array<int, array<string, mixed>> */
    private function storeRows(int $channelId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT ' . self::COLUMNS . ' FROM channel_listing cl JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.channel_id = ? ORDER BY cl.id',
            [$channelId]) as $r) {
            $out[(int) $r['listing_id']] = $r + ['in_scope' => true];
        }
        return $out;
    }

    /**
     * These products, the other products of their pages (the same product number; the same web address above or below), and THEIR
     * page's products: what the rule needs to answer for each of the first two. `in_scope`: one of the products asked about or of
     * their pages (the third ring only feeds the rule).
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function pageRows(int $channelId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $rows = $this->byIds($channelId, $ids);
        $ring = $this->pagesOf($channelId, $rows);
        foreach ($ring as $id => $r) {
            $rows[$id] ??= $r;
        }
        foreach ($rows as $id => $r) {
            $rows[$id]['in_scope'] = true;
        }
        foreach ($this->pagesOf($channelId, $ring) as $id => $r) {
            $rows[$id] ??= $r + ['in_scope' => false];
        }
        return $rows;
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    private function byIds(int $channelId, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->db->all('SELECT ' . self::COLUMNS . ' FROM channel_listing cl JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.channel_id = ? AND cl.id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')', [$channelId, ...$chunk]) as $r) {
                $out[(int) $r['listing_id']] = $r;
            }
        }
        return $out;
    }

    /**
     * The products of the pages of $rows: the same product number, the page above (the address without its last part) and the
     * options below (the address followed by "/"), on the same store. The indexes of 0028 and 0027.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function pagesOf(int $channelId, array $rows): array
    {
        $products = [];
        $addresses = [];
        foreach ($rows as $r) {
            if ($r['site_product_id'] !== null && $r['site_product_id'] !== '') {
                $products[(string) $r['site_product_id']] = true;
            }
            foreach ([LandingRule::address($r['perma_link']), LandingRule::parentAddress($r['perma_link'])] as $a) {
                if ($a !== null) {
                    $addresses[$a] = true;
                }
            }
        }
        $out = [];
        foreach (array_chunk(array_keys($products), 200) as $chunk) {
            foreach ($this->db->all('SELECT /*+ INDEX(lp ix_listing_profile_site_product) */ ' . self::COLUMNS . ' FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id '
                . 'WHERE cl.channel_id = ? AND lp.site_product_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', [$channelId, ...array_map('strval', $chunk)]) as $r) {
                $out[(int) $r['listing_id']] = $r;
            }
        }
        // The address itself and every address below it: ranges of one index, 100 addresses a statement.
        foreach (array_chunk(array_map('strval', array_keys($addresses)), 100) as $chunk) {
            $params = [];
            foreach ($chunk as $a) {
                array_push($params, $a, addcslashes($a, '\\%_') . '/%');
            }
            foreach ($this->db->all('SELECT /*+ INDEX(lp ix_listing_profile_perma_key) */ ' . self::COLUMNS . ' FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id '
                . 'WHERE (' . implode(' OR ', array_fill(0, count($chunk), '(lp.perma_key = ? OR lp.perma_key LIKE ?)')) . ') AND cl.channel_id = ? LIMIT 5000',
                [...$params, $channelId]) as $r) {
                $out[(int) $r['listing_id']] = $r;
            }
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int, true> the products with a decision waiting for a second OK */
    private function pending(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->column('SELECT pending_listing_id FROM match_decision WHERE pending_listing_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $id) {
                $out[(int) $id] = true;
            }
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int, true> the products a person said are real products (LP3) */
    private function saidReal(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->column("SELECT DISTINCT listing_id FROM match_decision WHERE action = 'not_landing' AND state = 'applied' AND listing_id IN ("
                . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $id) {
                $out[(int) $id] = true;
            }
        }
        return $out;
    }
}
