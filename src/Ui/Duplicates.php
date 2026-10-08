<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Db;
use CW\Mapping\DecisionService;
use CW\Matching\DuplicateSweep;
use CW\Matching\DuplicateSweepRun;
use CW\Matching\Gtin;

/**
 * The read side of the Duplicates screen (docs/decisions.md M34): Vape and Go's own duplicate listings (the same product on
 * two pages), as the merge suggestions of a duplicate lane (mint_vpg's `vpg_duplicate`, any later `<source>_duplicate`) left
 * them. All SELECTs; DecisionService decides.
 *
 * A GROUP is the suggestions of one run that carry the same `evidence.group` (mint_vpg writes one per duplicate group, one
 * suggestion per non-keeper listing, each proposing the run's keeper's item); a suggestion without a group number is a group
 * of its own. Its id on the screens is its lowest proposal id. Its LISTINGS are the suggestions' listings, the listings the
 * evidence names (`keeper`, `members`, by variant id on the suggestion's site) and, for a suggestion that names none, the
 * listings linked to the item it proposes on that site. A group is open while one of its suggestions is open.
 *
 * What the rules say (M44): every page against the kept one is judged live with the sweep's rules (CW\Matching\DuplicateSweep,
 * the features re-normalised from the listing profile), so the person sees, in plain
 * words, every reason the two may be different products (VG/PG, barcodes, an option, a word on one page only, ...), for run2's
 * suggestions as for the sweep's.
 */
final class Duplicates
{
    /** The product page of a site's listing: base URL + perma_link (a site not listed shows no link). */
    public const PRODUCT_PAGE = ['vapeandgo' => 'https://www.vapeandgo.co.uk/product/'];
    /** The identity fields compared side by side (listing_profile.features, CW\Matching\Normalizer), label => feature keys. */
    public const FIELDS = [
        'strength' => ['label' => 'Strength (mg)', 'keys' => ['strength_mg'], 'set' => false],
        'nic_type' => ['label' => 'Nicotine type', 'keys' => ['nic_type'], 'set' => false],
        'volume' => ['label' => 'Volume (ml)', 'keys' => ['volume_ml'], 'set' => false],
        'puffs' => ['label' => 'Puffs', 'keys' => ['puffs'], 'set' => false],
        'pack' => ['label' => 'Pack (units)', 'keys' => ['pack_units'], 'set' => false],
        'ohm' => ['label' => 'Resistance (ohm)', 'keys' => ['resistance_ohm'], 'set' => false],
        'colour' => ['label' => 'Colour', 'keys' => ['colour'], 'set' => false],
        'form' => ['label' => 'Form', 'keys' => ['form'], 'set' => false],
        'flavour' => ['label' => 'Flavour words', 'keys' => ['flavour_tokens'], 'set' => true],
        'models' => ['label' => 'Model numbers', 'keys' => ['line_numbers', 'line_models'], 'set' => true],
        'modifiers' => ['label' => 'Range words (Plus, Pro, Max ...)', 'keys' => ['line_modifiers'], 'set' => true],
    ];

    /** The identity fields the sweep explains a pair with (CW\Matching\DuplicateSweep::judge), in the screen's words. */
    public const SWEEP_FIELDS = [
        'strength' => 'strength', 'nic_type' => 'nicotine type', 'form' => 'form', 'brand_line' => 'brand and line', 'flavour' => 'flavour',
        'volume' => 'ml', 'pack' => 'pack', 'puffs' => 'puffs', 'colour' => 'colour', 'resistance' => 'ohm', 'vgpg' => 'VG/PG',
    ];
    /**
     * The reasons of DuplicateSweep::judge() in the screen's words: code => [short tag for the list, sentence for the group page].
     * A code not listed here shows as "other".
     */
    public const REASONS = [
        'veto_strength' => ['strength', 'Different strength'], 'strength_one_side' => ['strength', 'A strength on one page only'],
        'flag_strength_missing' => ['strength', 'A strength on one page only'],
        'veto_nic_type' => ['nicotine type', 'Different nicotine type'], 'nic_type_one_side' => ['nicotine type', 'A nicotine type on one page only'],
        'veto_form' => ['form', 'A different kind of product'], 'form_label' => ['form', 'A different kind of product'],
        'form_unknown' => ['form', 'The kind of product is not clear on a page'], 'form_sub_one_side' => ['form', 'Prefilled or refillable on one page only'],
        'veto_line_number' => ['model number', 'Different model numbers'], 'flag_line_number_extra' => ['model number', 'A model number on one page only'],
        'flag_line_number_one_side' => ['model number', 'A model number on one page only'], 'model_code' => ['model code', 'Different model codes'],
        'multi_n' => ['model number', 'A different N-in-1 count'],
        'veto_line_modifier' => ['range word', 'A range word (Pro, Max, Plus ...) on one page only'], 'veto_line_word' => ['range name', 'Different range names'],
        'flag_modifier_extra' => ['range name', 'A range word on one page only'], 'flag_relabelled_line_unconfirmed' => ['range name', 'Different brand or range'],
        'flag_line_alias_pending' => ['range name', 'Different range names (maybe an alias)'],
        'veto_flavour_superset' => ['flavour', 'One flavour has more words than the other'], 'veto_flavour_diff' => ['flavour', 'Different flavours'],
        'flag_flavour_extra' => ['flavour', 'A flavour word on one page only'], 'flavour_not_agreed' => ['flavour', 'The flavours are not shown to be the same'],
        'veto_liquid_ml' => ['ml', 'A different size (ml)'], 'volume_one_side' => ['ml', 'A size (ml) on one page only'],
        'flag_volume_diff_attr' => ['ml', 'Different sizes (ml) in the options'],
        'veto_puffs' => ['puffs', 'Different puff counts'], 'puffs_one_side' => ['puffs', 'A puff count on one page only'],
        'veto_colour' => ['colour', 'Different colours'], 'colour_one_side' => ['colour', 'A colour on one page only'], 'flag_colour_extra' => ['colour', 'A colour word on one page only'],
        'veto_ohm' => ['ohm', 'Different resistance (ohm)'], 'resistance_one_side' => ['ohm', 'A resistance (ohm) on one page only'],
        'veto_pack' => ['pack', 'A different pack size'], 'veto_multipack' => ['pack', 'A multipack against a single'], 'pack_one_side' => ['pack', 'A pack size on one page only'],
        'flag_pack_one_side' => ['pack', 'A pack size on one page only'], 'flag_listing_multiplier' => ['pack', 'A multipack on one page'],
        'vgpg' => ['VG/PG', 'Different VG/PG ratios'], 'vgpg_one_side' => ['VG/PG', 'A VG/PG ratio on one page only'],
        'brand' => ['brand', 'Different brands'], 'brand_text' => ['brand', 'The brand or range is written differently'],
        'price' => ['price', 'The prices are far apart'], 'price_unknown' => ['price', 'A price is not known'], 'flag_price_outlier' => ['price', 'The prices are far apart'],
        'words' => ['words', 'Words in one title only'], 'part_word' => ['part', 'A part (coil, glass, kit, pod ...) named on one page only'],
        'option_differs' => ['option', 'Two options of one product page with different option text'],
        'barcodes_differ' => ['barcodes', 'Different barcodes (Vape and Go gives each product its own)'],
        'unreadable' => ['stated twice', 'A page states a field twice with two values'], 'flag_internal_conflict' => ['stated twice', 'A page states a field twice with two values'],
    ];
    /** The reasons that name a real difference (not "cannot be compared"): with one of them a merge is very likely wrong. */
    public const STRONG = ['veto_strength', 'veto_nic_type', 'veto_form', 'veto_line_number', 'model_code', 'veto_line_modifier', 'veto_line_word',
        'veto_flavour_superset', 'veto_flavour_diff', 'veto_liquid_ml', 'veto_puffs', 'veto_colour', 'veto_ohm', 'veto_pack', 'veto_multipack', 'vgpg',
        'option_differs', 'barcodes_differ', 'brand', 'part_word', 'multi_n'];

    /** Where a pair's barcodes stand. */
    public const SWEEP_BARCODE = [
        'shared' => 'the same barcode on both pages', 'one_side' => 'a barcode on one page only', 'none' => 'no barcode on either page',
    ];

    /** @var array<int, array<string, mixed>> listing id => sweepFeatures() (per request) */
    private array $featureCache = [];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Every open group, the biggest sellers first (units in 365 days over the group's listings, then 30 days, then id).
     *
     * @param bool $withRules judge each page against the suggested keeper (`against`: the reasons' tags, `checked`), M44
     * @return list<array<string, mixed>> {id, run_id, group, kind, listings: list<int>, open: int, decided: int, units_30d, units_365d,
     *         keeper (the suggested keeper's listing row), differs: list<string> (field labels), against: list<string>, checked: bool,
     *         waiting: bool}
     */
    public function openGroups(bool $withRules = true): array
    {
        $open = $this->db->all('SELECT p.id, p.match_run_id, p.listing_id, p.proposed_sku_id, p.evidence, p.lane FROM match_proposal p '
            . "WHERE p.status = 'open' AND " . DecisionService::duplicateLaneSql('p.lane') . ' ORDER BY p.id');
        if ($open === []) {
            return [];
        }
        $keys = [];
        foreach ($open as $p) {
            $keys[self::groupKey($p)] = true;
        }
        $groups = $this->groupsByKey(array_keys($keys));
        $all = [];
        foreach ($groups as $g) {
            array_push($all, ...$g['listings']);
        }
        $listings = $this->listings(array_values(array_unique($all)));
        $pending = $this->pendingListings(array_keys($listings));
        if ($withRules) {
            $this->sweepFeatures(array_values($listings)); // every page's features once
        }
        $out = [];
        foreach ($groups as $g) {
            $ls = array_values(array_filter(array_map(static fn (int $id): ?array => $listings[$id] ?? null, $g['listings'])));
            if ($ls === []) {
                continue;
            }
            $u30 = array_sum(array_map(static fn (array $l): int => (int) $l['units_30d'], $ls));
            $u365 = array_sum(array_map(static fn (array $l): int => (int) $l['units_365d'], $ls));
            $keeper = self::suggestKeeper($ls);
            $against = [];
            $checked = false;
            if ($keeper !== null && $withRules) {
                foreach ($this->verdicts($ls, $keeper['id']) as $v) {
                    $checked = $checked || $v['checked'];
                    foreach ($v['tags'] as $t) {
                        $against[$t] = true;
                    }
                }
            }
            // The compared row's own label: compare() also has rows that are not FIELDS (VG/PG, barcodes).
            $out[] = $g + ['units_30d' => $u30, 'units_365d' => $u365, 'keeper' => $keeper, 'differs' => array_values(array_map(
                static fn (array $row): string => (string) $row['label'],
                array_filter(self::compare($ls), static fn (array $row): bool => $row['differs']),
            )), 'against' => array_keys($against), 'checked' => $checked, 'waiting' => array_intersect($g['listings'], $pending) !== []];
        }
        usort($out, static fn (array $a, array $b): int => [$b['units_365d'], $b['units_30d'], $a['id']] <=> [$a['units_365d'], $a['units_30d'], $b['id']]);
        return $out;
    }

    /** How many groups are open (the menu's badge). */
    public function openCount(): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(DISTINCT p.match_run_id, COALESCE(CAST(JSON_EXTRACT(p.evidence, '$.group') AS CHAR), CONCAT('p', p.id))) FROM match_proposal p "
            . "WHERE p.status = 'open' AND " . DecisionService::duplicateLaneSql('p.lane'),
        );
    }

    /**
     * The group of proposal $proposalId (any status), or null when it is not a merge suggestion.
     *
     * @return array<string, mixed>|null {id, run_id, run, group, kind, key, proposals: list<array>, listings: list<int>, open: int, decided: int}
     */
    public function group(int $proposalId): ?array
    {
        $p = $this->db->one('SELECT id, match_run_id, listing_id, proposed_sku_id, evidence, lane FROM match_proposal WHERE id = ?', [$proposalId]);
        if ($p === null || !DecisionService::isDuplicateLane($p['lane'] === null ? null : (string) $p['lane'])) {
            return null;
        }
        return $this->groupsByKey([self::groupKey($p)])[0] ?? null;
    }

    /**
     * Units sold from the imported sales history (spec §7.3), the one source of a website product's sales on Possible duplicates and
     * on the website product's own page (behaviour item 11, F207): per listing, the units of the 30 and 365 days to the last day
     * loaded for its site, and that day. A listing of a site with no sales loaded is left out: the page then shows the website's own
     * figures (listing_profile), and says so.
     *
     * @param array<int, array<string, mixed>> $listings listing id => a row with its channel_id and external_variant_id
     * @return array<int, array{u30: int, u365: int, to: string}> listing id => units and the last day loaded
     */
    public function soldFromHistory(array $listings): array
    {
        if ($listings === []) {
            return [];
        }
        $ends = [];
        foreach ($this->db->all("SELECT channel_id, MAX(date_to) AS e FROM sales_import_batch WHERE status = 'loaded' GROUP BY channel_id") as $b) {
            $ends[(int) $b['channel_id']] = (string) $b['e'];
        }
        $sold = [];
        foreach ($listings as $r) {
            $c = (int) $r['channel_id'];
            if (isset($ends[$c])) {
                $sold[$c][(string) $r['external_variant_id']] = true;
            }
        }
        $units = [];
        foreach ($sold as $c => $set) {
            $variants = array_map('strval', array_keys($set));
            foreach ($this->db->all(
                'SELECT external_variant_id, SUM(IF(sale_date > DATE_SUB(?, INTERVAL 30 DAY), units_online + units_office, 0)) AS u30, '
                . 'SUM(units_online + units_office) AS u365 FROM sales_history_day WHERE channel_id = ? AND external_variant_id IN ('
                . implode(',', array_fill(0, count($variants), '?')) . ') AND sale_date > DATE_SUB(?, INTERVAL 365 DAY) AND sale_date <= ? GROUP BY external_variant_id',
                [$ends[$c], $c, ...$variants, $ends[$c], $ends[$c]],
            ) as $h) {
                $units[$c][(string) $h['external_variant_id']] = [(int) $h['u30'], (int) $h['u365']];
            }
        }
        $out = [];
        foreach ($listings as $id => $r) {
            $c = (int) $r['channel_id'];
            if (isset($ends[$c])) {
                [$u30, $u365] = $units[$c][(string) $r['external_variant_id']] ?? [0, 0];
                $out[(int) $id] = ['u30' => $u30, 'u365' => $u365, 'to' => $ends[$c]];
            }
        }
        return $out;
    }

    /**
     * The listings of a group, side by side: site, titles, brand, attributes, barcodes, price, units from the sales history
     * (else the profile), the site's latest stock and mode, the product page, the item it is linked to (policy, counted,
     * merged), its open merge suggestion and a decision waiting for a second person.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> listing id => row
     */
    public function listings(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = [];
        foreach ($this->db->all(
            'SELECT cl.id, cl.channel_id, ch.code AS channel_code, cl.external_variant_id, cl.sku_id, cl.units_per_item, cl.status, cl.map_version, '
            . 'lp.product_title, lp.variant_title, lp.brand, lp.attributes, lp.barcodes, lp.price, lp.perma_link, lp.units_30d, lp.units_365d, lp.features, '
            . 'x.stock AS site_stock, x.stock_mode AS site_mode, x.sellable AS site_sellable, x.snapshot_date AS site_date '
            . 'FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            . 'LEFT JOIN listing_stock_latest x ON x.channel_id = cl.channel_id AND x.external_variant_id = cl.external_variant_id '
            . "WHERE cl.id IN ({$in})",
            $ids,
        ) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        // Units from the imported sales history: the 30 and 365 days to the last day loaded for the site (spec §7.3).
        $hist = $this->soldFromHistory($rows);
        $skuIds = array_values(array_unique(array_filter(array_map(static fn (array $r): ?int => $r['sku_id'] === null ? null : (int) $r['sku_id'], $rows))));
        $skus = [];
        $counted = [];
        $available = [];
        if ($skuIds !== []) {
            $sin = implode(',', array_fill(0, count($skuIds), '?'));
            foreach ($this->db->all("SELECT s.id, s.code, s.name, s.sell_policy, s.merged_into_sku_id, s.origin, ol.external_variant_id AS vpg_variant_id FROM sku s "
                . "LEFT JOIN channel_listing ol ON ol.id = s.origin_listing_id WHERE s.id IN ({$sin})", $skuIds) as $s) {
                $skus[(int) $s['id']] = $s;
            }
            $counted = array_flip((new DecisionService($this->db))->counted($skuIds));
            // What CW holds for each item now (available: on_hand - allocated - held, over every warehouse): what a merge would move.
            foreach ($this->db->all("SELECT sku_id, SUM(on_hand - allocated - held) AS a FROM stock_balance WHERE sku_id IN ({$sin}) GROUP BY sku_id", $skuIds) as $b) {
                $available[(int) $b['sku_id']] = (int) $b['a'];
            }
        }
        $proposals = [];
        foreach ($this->db->all('SELECT id, listing_id, proposed_sku_id, lane FROM match_proposal WHERE open_listing_id IN (' . $in . ')', $ids) as $p) {
            $proposals[(int) $p['listing_id']] = $p;
        }
        $pending = [];
        foreach ($this->db->all('SELECT id, listing_id, action, needs_second FROM match_decision WHERE pending_listing_id IN (' . $in . ')', $ids) as $d) {
            $pending[(int) $d['listing_id']] = ['id' => (int) $d['id'], 'action' => (string) $d['action'], 'needs' => Html::strings(Html::json($d['needs_second']))];
        }
        $other = [];
        if ($skuIds !== []) {
            // The other listings of these items (another site's, or a listing outside the group): they move with a merge.
            foreach ($this->db->all('SELECT cl.id, cl.sku_id, ch.code AS channel_code, cl.external_variant_id FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id '
                . "WHERE cl.status IN ('mapped', 'quarantined') AND cl.sku_id IN (" . implode(',', array_fill(0, count($skuIds), '?')) . ') ORDER BY ch.code, cl.id',
                $skuIds) as $o) {
                $other[(int) $o['sku_id']][] = ['id' => (int) $o['id'], 'channel' => (string) $o['channel_code'], 'variant' => (string) $o['external_variant_id']];
            }
        }
        $out = [];
        foreach ($rows as $id => $r) {
            $c = (int) $r['channel_id'];
            $v = (string) $r['external_variant_id'];
            [$u30, $u365] = isset($hist[$id]) ? [$hist[$id]['u30'], $hist[$id]['u365']] : [(int) ($r['units_30d'] ?? 0), (int) ($r['units_365d'] ?? 0)];
            $sku = $r['sku_id'] !== null && in_array($r['status'], DecisionService::LINKED, true) ? (int) $r['sku_id'] : null;
            $s = $sku !== null ? ($skus[$sku] ?? null) : null;
            $attrs = [];
            foreach (is_array(Html::json($r['attributes'])['items'] ?? null) ? array_slice(Html::json($r['attributes'])['items'], 0, 40) : [] as $a) {
                if (is_array($a) && (is_scalar($a['name'] ?? null) || is_scalar($a['value'] ?? null))) {
                    $attrs[] = ['name' => self::s($a['name'] ?? null), 'value' => self::s($a['value'] ?? null)];
                }
            }
            $barcodes = Html::strings(Html::json($r['barcodes']));
            $usable = array_values(array_filter($barcodes, static fn (string $b): bool => Gtin::classify($b)['usable']));
            $out[$id] = [
                'id' => $id, 'channel' => (string) $r['channel_code'], 'channel_id' => $c, 'variant' => $v, 'status' => (string) $r['status'],
                'map_version' => (int) $r['map_version'], 'units_per_item' => (int) $r['units_per_item'],
                'title' => self::s($r['product_title']), 'variant_title' => self::s($r['variant_title']), 'brand' => self::s($r['brand']),
                'attributes' => $attrs, 'barcodes' => $barcodes, 'usable_barcodes' => count($usable), 'price' => self::s($r['price']),
                'units_30d' => $u30, 'units_365d' => $u365, 'units_from' => isset($hist[$id]) ? 'sales history to ' . $hist[$id]['to'] : 'listing profile',
                'units_to' => $hist[$id]['to'] ?? null,
                'site_stock' => $r['site_stock'] === null ? null : (int) $r['site_stock'], 'site_mode' => self::s($r['site_mode']),
                'site_sellable' => $r['site_sellable'] === null ? null : (int) $r['site_sellable'] === 1, 'site_date' => self::s($r['site_date']),
                'page' => self::productPage((string) $r['channel_code'], is_string($r['perma_link']) ? $r['perma_link'] : null),
                'features' => Html::json($r['features']),
                // The profile as the sweep reads it (DuplicateSweepRun::row): what verdicts() judges.
                'profile' => $r['product_title'] === null ? null : ['variant' => $v, 'brand' => $r['brand'], 'product_title' => $r['product_title'],
                    'variant_title' => $r['variant_title'], 'attributes' => self::profileAttributes(Html::json($r['attributes'])['items'] ?? null),
                    'barcodes' => $barcodes, 'price' => $r['price'] === null ? null : (string) $r['price'], 'units_30d' => $u30, 'features' => Html::json($r['features'])],
                'sku' => $s === null ? null : [
                    'id' => (int) $s['id'], 'code' => (string) $s['code'], 'name' => self::s($s['name']), 'policy' => (string) $s['sell_policy'],
                    'counted' => isset($counted[(int) $s['id']]), 'merged_into' => $s['merged_into_sku_id'] === null ? null : (int) $s['merged_into_sku_id'],
                    'available' => $available[(int) $s['id']] ?? 0,
                    'cwp' => $s['origin'] === 'vpg_mint' && $s['vpg_variant_id'] !== null ? 'CWP-' . $s['vpg_variant_id'] : null,
                    'others' => array_values(array_filter($other[(int) $s['id']] ?? [], static fn (array $o): bool => $o['id'] !== $id)),
                ],
                'proposal' => isset($proposals[$id]) && DecisionService::isDuplicateLane($proposals[$id]['lane'] === null ? null : (string) $proposals[$id]['lane'])
                    ? ['id' => (int) $proposals[$id]['id'], 'proposed_sku_id' => $proposals[$id]['proposed_sku_id'] === null ? null : (int) $proposals[$id]['proposed_sku_id']]
                    : null,
                'other_proposal' => isset($proposals[$id]) && !DecisionService::isDuplicateLane($proposals[$id]['lane'] === null ? null : (string) $proposals[$id]['lane'])
                    ? (int) $proposals[$id]['id'] : null,
                'pending' => $pending[$id] ?? null,
            ];
        }
        return $out;
    }


    /**
     * What the rules say about each page against the kept one (M44): DuplicateSweep::judge() of the pair, live, from the listing
     * profiles (re-normalised as the sweep does, sweepFeatures()). `checked` is false when a page has
     * no profile. The reasons are in the screen's words (REASONS), with the rules' detail ("70 vs 50").
     *
     * @param array<int|string, array<string, mixed>> $listings rows of listings()
     * @return array<int, array{checked: bool, ok: bool, strong: bool, tags: list<string>, reasons: list<array{code: string, text: string, detail: string, strong: bool}>,
     *         barcode: ?string}> listing id => verdict, for every listing other than the keeper
     */
    public function verdicts(array $listings, int $keeperId): array
    {
        $byId = [];
        foreach ($listings as $l) {
            $byId[(int) $l['id']] = $l;
        }
        $features = $this->sweepFeatures(array_values($byId));
        $out = [];
        foreach ($byId as $id => $l) {
            if ($id === $keeperId) {
                continue;
            }
            if (!isset($features[$id], $features[$keeperId])) {
                $out[$id] = ['checked' => false, 'ok' => false, 'strong' => false, 'tags' => [], 'reasons' => [], 'barcode' => null];
                continue;
            }
            $j = DuplicateSweep::judge($features[$keeperId], $features[$id]);
            $reasons = [];
            $tags = [];
            foreach ($j['blocks'] as $b) {
                [$tag, $text] = self::REASONS[$b['code']] ?? ['other', 'Another reason (' . $b['code'] . ')'];
                $strong = in_array($b['code'], self::STRONG, true);
                $reasons[] = ['code' => (string) $b['code'], 'text' => $text, 'detail' => mb_substr((string) $b['detail'], 0, 200), 'strong' => $strong,
                    'shown' => self::readableDetail(mb_substr((string) $b['detail'], 0, 200))];
                $tags[$tag] = true;
            }
            $out[$id] = ['checked' => true, 'ok' => $j['ok'], 'strong' => array_filter($reasons, static fn (array $r): bool => $r['strong']) !== [],
                'tags' => array_map('strval', array_keys($tags)), 'reasons' => $reasons, 'barcode' => $j['barcode']];
        }
        return $out;
    }

    /**
     * The sweep's features of these listings (DuplicateSweep::features over DuplicateSweepRun::row of the profile). Without the
     * brand's line lexicon on the site (TitlePattern::lexicon): building it means scanning every listing profile of the site (about
     * 2 s on cw_staging), and on the 198 pairs of 6 Oct 2026 (run2's 165 suggestions and the sweep's 33 accepted pairs) the verdicts
     * are the same with or without it (one refused pair lists one reason more). Listings without a profile are left out.
     *
     * @param list<array<string, mixed>> $listings rows of listings()
     * @return array<int, array<string, mixed>> listing id => features
     */
    public function sweepFeatures(array $listings): array
    {
        $out = [];
        foreach ($listings as $l) {
            if (($l['profile'] ?? null) === null) {
                continue;
            }
            $out[(int) $l['id']] = $this->featureCache[(int) $l['id']] ??= DuplicateSweep::features(DuplicateSweepRun::row($l['profile']), []);
        }
        return $out;
    }

    /**
     * The decided groups (no suggestion open), the most recently decided first (M44: found through the decisions on ANY listing of
     * the group, so a group decided with another keeper than the run's, whose merge sits on the run keeper's listing, is found too).
     *
     * @return array{rows: list<array<string, mixed>>, total: int} {id, size, title, items, at}
     */
    public function decidedGroups(int $offset, int $limit): array
    {
        $keys = [];
        $open = [];
        foreach ($this->db->all('SELECT p.id, p.match_run_id, p.evidence, p.status FROM match_proposal p WHERE ' . DecisionService::duplicateLaneSql('p.lane')) as $p) {
            $k = self::groupKey($p);
            $keys[$k] = true;
            if ($p['status'] === 'open') {
                $open[$k] = true;
            }
        }
        $groups = $this->groupsByKey(array_keys(array_diff_key($keys, $open)));
        $all = [];
        foreach ($groups as $g) {
            array_push($all, ...$g['listings']);
        }
        $last = [];
        foreach (array_chunk(array_values(array_unique($all)), 500) as $chunk) {
            foreach ($this->db->all("SELECT listing_id, MAX(id) AS d, MAX(applied_at) AS at FROM match_decision WHERE state = 'applied' "
                . "AND action IN ('merge_skus', 'reject', 'split') AND listing_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY listing_id', $chunk) as $d) {
                $last[(int) $d['listing_id']] = [(int) $d['d'], (string) $d['at']];
            }
        }
        $rows = [];
        foreach ($groups as $g) {
            $d = [0, null];
            foreach ($g['listings'] as $lid) {
                if (isset($last[$lid]) && $last[$lid][0] > $d[0]) {
                    $d = $last[$lid];
                }
            }
            $rows[] = ['g' => $g, 'decision' => $d[0], 'at' => $d[1]];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['decision'], $b['g']['id']] <=> [$a['decision'], $a['g']['id']]);
        $page = array_slice($rows, $offset, $limit);
        $ls = $this->listings(array_values(array_unique(array_merge(...array_map(static fn (array $r): array => $r['g']['listings'], $page ?: [['g' => ['listings' => []]]])))));
        $out = [];
        foreach ($page as $r) {
            $g = $r['g'];
            $first = $ls[$g['listings'][0] ?? 0] ?? null;
            $items = [];
            foreach ($g['listings'] as $lid) {
                if (isset($ls[$lid]['sku']['id'])) {
                    $items[(new DecisionService($this->db))->rootOf($ls[$lid]['sku']['id'])] = true;
                }
            }
            $out[] = ['id' => $g['id'], 'size' => count($g['listings']), 'title' => $first === null ? null : trim(($first['title'] ?? '') . ' ' . ($first['variant_title'] ?? '')),
                'items' => count($items), 'at' => $r['at']];
        }
        return ['rows' => $out, 'total' => count($rows)];
    }

    /**
     * The duplicate groups these listings belong to (any status): through their own suggestions, or named by a suggestion's
     * evidence (keeper, members) on their site. For the links from the item and listing pages to the group page (and its undo).
     *
     * @param list<int> $listingIds
     * @return array<int, list<int>> listing id => group ids
     */
    public function groupsOfListings(array $listingIds): array
    {
        return array_map(static fn (array $refs): array => array_map(static fn (array $r): int => $r['id'], $refs), $this->groupNumbersOfListings($listingIds));
    }

    /**
     * The same groups with the number people see on the group page ("Group 7", plan F126, F236, F254: one number for one group;
     * the run's group number, else the group's id).
     *
     * @param list<int> $listingIds
     * @return array<int, list<array{id: int, number: string}>> listing id => groups, by id
     */
    public function groupNumbersOfListings(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_map('intval', $listingIds)));
        if ($listingIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($listingIds), '?'));
        $ls = $this->db->all("SELECT id, channel_id, external_variant_id FROM channel_listing WHERE id IN ({$in})", $listingIds);
        $props = [];
        foreach ($this->db->all("SELECT p.id, p.match_run_id, p.listing_id, p.evidence FROM match_proposal p WHERE p.listing_id IN ({$in}) AND "
            . DecisionService::duplicateLaneSql('p.lane'), $listingIds) as $p) {
            $props[(int) $p['id']] = $p;
        }
        foreach ($ls as $l) {
            $v = (string) $l['external_variant_id'];
            $json = ctype_digit($v) && strlen($v) < 16 ? [$v, json_encode($v)] : [json_encode($v), json_encode($v)];
            foreach ($this->db->all('SELECT p.id, p.match_run_id, p.listing_id, p.evidence FROM match_proposal p JOIN channel_listing pl ON pl.id = p.listing_id '
                . 'WHERE pl.channel_id = ? AND ' . DecisionService::duplicateLaneSql('p.lane') . " AND (JSON_UNQUOTE(JSON_EXTRACT(p.evidence, '$.keeper.vpg_variant_id')) = ? "
                . "OR JSON_CONTAINS(JSON_EXTRACT(p.evidence, '$.members[*].vpg_variant_id'), CAST(? AS JSON)) "
                . "OR JSON_CONTAINS(JSON_EXTRACT(p.evidence, '$.members[*].vpg_variant_id'), CAST(? AS JSON))) LIMIT 50",
                [(int) $l['channel_id'], $v, $json[0], $json[1]]) as $p) {
                $props[(int) $p['id']] = $p;
            }
        }
        if ($props === []) {
            return [];
        }
        $keys = [];
        foreach ($props as $p) {
            $keys[self::groupKey($p)] = true;
        }
        $out = [];
        foreach ($this->groupsByKey(array_keys($keys)) as $g) {
            foreach (array_intersect($g['listings'], $listingIds) as $lid) {
                $out[(int) $lid][$g['id']] = ['id' => $g['id'], 'number' => $g['group'] ?? (string) $g['id']];
            }
        }
        foreach ($out as &$refs) {
            ksort($refs);
            $refs = array_values($refs);
        }
        unset($refs);
        return $out;
    }

    /**
     * The identity fields side by side: field => {label, set, values: listing id => value | list of {word, odd}, differs, partial}.
     * `differs`: two listings state different values (a set: a word some listings have and others do not); `partial`: some
     * listings do not state it at all (the features of a listing whose site titles changed are cleared until the next run), or
     * a page states it twice with two values (shown "unclear: stated twice", M44).
     *
     * M44 rows besides the stored features (listing_profile.features): first the option text of each page (what its variant
     * title adds to the product title; shown, never highlighted: two pages are worded differently), then VG/PG (the sweep's
     * reading of the titles and the PG/VG option row) and the barcodes (shared or not), from $sweep, the live features of
     * sweepFeatures(). The flavour words leave out every brand and range word of the group's pages ("xros corex" is a range).
     *
     * @param list<array<string, mixed>> $listings rows of listings()
     * @param array<int, array<string, mixed>> $sweep listing id => sweepFeatures()
     * @return array<string, array<string, mixed>>
     */
    public static function compare(array $listings, array $sweep = []): array
    {
        $out = [];
        $out['option'] = ['label' => 'Option (what the page adds to the product name)', 'set' => false, 'differs' => false, 'partial' => false,
            'values' => array_combine(array_map(static fn (array $l): int => (int) $l['id'], $listings), array_map(static fn (array $l): ?string => self::optionText($l), $listings))];
        // Brand and range words of every page: never "flavour" words.
        $notFlavour = [];
        foreach ($listings as $l) {
            foreach ([$l['features'] ?? [], $sweep[(int) $l['id']] ?? []] as $f) {
                if (!is_array($f)) {
                    continue;
                }
                foreach ([...Html::strings($f['brand_family'] ?? null), ...Html::strings($f['line_tokens'] ?? null)] as $w) {
                    $notFlavour[mb_strtolower(trim($w))] = true;
                }
            }
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) ($l['brand'] ?? ''))) ?: [] as $w) {
                if (mb_strlen($w) > 1) {
                    $notFlavour[$w] = true;
                }
            }
        }
        $conflict = ['strength' => 'strength', 'volume' => 'volume', 'puffs' => 'puffs', 'pack' => 'pack', 'ohm' => 'resistance', 'colour' => 'colour',
            'form' => 'form', 'flavour' => 'flavour'];
        foreach (self::FIELDS as $key => $f) {
            $vals = [];
            $unclear = [];
            foreach ($listings as $l) {
                $feat = is_array($l['features'] ?? null) ? $l['features'] : [];
                if (isset($conflict[$key]) && in_array($conflict[$key], Html::strings($feat['conflict_fields'] ?? null), true)) {
                    $unclear[(int) $l['id']] = true;
                }
                if ($f['set']) {
                    $words = [];
                    foreach ($f['keys'] as $k) {
                        foreach (Html::strings($feat[$k] ?? null) as $w) {
                            $w = mb_strtolower(trim($w));
                            if ($key !== 'flavour' || !isset($notFlavour[$w])) {
                                $words[$w] = true;
                            }
                        }
                    }
                    unset($words['']);
                    $vals[$l['id']] = $feat === [] ? null : array_map('strval', array_keys($words));
                } else {
                    $v = $feat[$f['keys'][0]] ?? null;
                    $vals[$l['id']] = is_scalar($v) && $v !== '' ? Html::dec(is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : (string) $v) : null;
                }
            }
            $known = array_filter($vals, static fn (mixed $v): bool => $v !== null && $v !== []);
            if ($f['set']) {
                $lists = array_filter($vals, static fn (mixed $v): bool => $v !== null);
                $union = $lists === [] ? [] : array_values(array_unique(array_merge(...array_values($lists))));
                $common = $lists === [] ? [] : (count($lists) === 1 ? array_values($lists)[0] : array_values(array_intersect(...array_values($lists))));
                $differs = count($lists) > 1 && count($union) !== count($common);
                $shown = [];
                foreach ($vals as $id => $words) {
                    $shown[$id] = $words === null ? null : array_map(static fn (string $w): array => ['word' => $w, 'odd' => !in_array($w, $common, true)], $words);
                }
                $out[$key] = ['label' => $f['label'], 'set' => true, 'values' => $shown, 'differs' => $differs,
                    'partial' => !$differs && ((count($known) > 0 && count($known) < count($vals)) || $unclear !== [])];
            } else {
                $distinct = array_values(array_unique(array_map('strval', $known)));
                foreach (array_keys($unclear) as $id) {
                    $vals[$id] ??= 'unclear: stated twice';
                }
                $out[$key] = ['label' => $f['label'], 'set' => false, 'values' => $vals, 'differs' => count($distinct) > 1,
                    'partial' => count($distinct) <= 1 && ((count($distinct) === 1 && count($known) < count($vals)) || $unclear !== [])];
            }
            if ($key === 'nic_type' && $sweep !== []) {
                // VG/PG (the sweep's reading: a ratio in the title, else the PG/VG option row)
                $vg = [];
                $vgUnclear = false;
                foreach ($listings as $l) {
                    $sf = $sweep[(int) $l['id']] ?? null;
                    $r = is_array($sf) && is_string($sf['vgpg'] ?? null) ? $sf['vgpg'] : null;
                    $vg[$l['id']] = $r === null ? null : match ($r) { 'max_vg' => 'Max VG', 'max_pg' => 'Max PG', default => 'VG ' . $r . ' / PG ' . (100 - (int) $r) };
                    if (is_array($sf) && in_array('vgpg', Html::strings($sf['conflict_fields'] ?? null), true)) {
                        $vg[$l['id']] = 'unclear: stated twice';
                        $vgUnclear = true;
                    }
                }
                $known = array_values(array_unique(array_filter($vg, static fn (?string $v): bool => $v !== null && $v !== 'unclear: stated twice')));
                $out['vgpg'] = ['label' => 'VG/PG', 'set' => false, 'values' => $vg, 'differs' => count($known) > 1,
                    'partial' => count($known) <= 1 && ($vgUnclear || (count($known) === 1 && count(array_filter($vg, static fn (?string $v): bool => $v !== null)) < count($vg)))];
            }
        }
        // Barcodes: pages with usable barcodes that share none are different products on Vape and Go (each its own EAN).
        $codes = [];
        foreach ($listings as $l) {
            $sf = $sweep[(int) $l['id']] ?? null;
            $codes[(int) $l['id']] = is_array($sf) ? Html::strings($sf['gtins'] ?? null) : array_values(array_filter(Html::strings($l['barcodes'] ?? null),
                static fn (string $b): bool => Gtin::classify($b)['usable']));
        }
        $with = array_filter($codes, static fn (array $c): bool => $c !== []);
        $shared = $with === [] ? [] : (count($with) === 1 ? [] : array_values(array_intersect(...array_values($with))));
        $bc = [];
        foreach ($codes as $id => $c) {
            $bc[$id] = $c === [] ? null : (count($with) < 2 ? 'has one' : ($shared !== [] ? 'the same as the others' : 'its own'));
        }
        $out['barcodes'] = ['label' => 'Barcode', 'set' => false, 'values' => $bc, 'differs' => count($with) > 1 && $shared === [],
            'partial' => count($with) > 0 && count($with) < count($codes)];
        return $out;
    }

    /** The option text of a page: what its variant title adds to the product title ("0.4 ohm", "Pink Fizz (Bar Favourites)"). @param array<string, mixed> $l */
    public static function optionText(array $l): ?string
    {
        $pt = trim((string) ($l['title'] ?? ''));
        $vt = trim((string) ($l['variant_title'] ?? ''));
        if ($vt === '' || $vt === $pt) {
            return null;
        }
        if ($pt !== '' && stripos($vt, $pt) === 0) {
            $vt = trim(ltrim(substr($vt, strlen($pt)), " \t-|:,/"));
        }
        return $vt === '' ? null : mb_substr($vt, 0, 200);
    }

    /**
     * The keeper the screen suggests (the person may pick another): more units sold in 365 days, then usable barcodes, then the
     * older page (the lower site variant id), among the listings linked to a live item.
     *
     * @param list<array<string, mixed>> $listings rows of listings()
     * @return array<string, mixed>|null
     */
    public static function suggestKeeper(array $listings): ?array
    {
        $c = array_values(array_filter($listings, static fn (array $l): bool => $l['sku'] !== null && $l['sku']['merged_into'] === null));
        if ($c === []) {
            return null;
        }
        $age = static fn (array $l): array => ctype_digit($l['variant']) ? [0, strlen($l['variant']), $l['variant']] : [1, 0, $l['variant']];
        usort($c, static fn (array $a, array $b): int => [$b['units_365d'], $b['usable_barcodes'] > 0, $age($a), $a['id']]
            <=> [$a['units_365d'], $a['usable_barcodes'] > 0, $age($b), $b['id']]);
        return $c[0];
    }

    /** The product page of a listing: an absolute http(s) perma_link as it is, else the site's base URL + the slug; null when unknown. */
    public static function productPage(string $channel, ?string $permaLink): ?string
    {
        if ($permaLink === null || trim($permaLink) === '') {
            return null;
        }
        $permaLink = trim($permaLink);
        if (preg_match('#^https?://#iD', $permaLink) === 1) {
            return Html::safeUrl($permaLink);
        }
        $base = self::PRODUCT_PAGE[$channel] ?? null;
        if ($base === null) {
            return null;
        }
        return $base . implode('/', array_map('rawurlencode', explode('/', trim($permaLink, '/'))));
    }

    /**
     * Groups by key (run id + group number, or `p<proposal id>`): their proposals (any status) and listings.
     *
     * @param list<string> $keys
     * @return list<array<string, mixed>>
     */
    private function groupsByKey(array $keys): array
    {
        $byRun = [];
        $single = [];
        foreach ($keys as $k) {
            if (str_starts_with($k, 'p')) {
                $single[] = (int) substr($k, 1);
            } else {
                [$run, $grp] = explode(':', $k, 2);
                $byRun[(int) $run][] = $grp;
            }
        }
        $props = [];
        foreach ($byRun as $run => $grps) {
            foreach ($this->db->all(
                'SELECT p.id, p.match_run_id, p.listing_id, p.proposed_sku_id, p.status, p.evidence, p.lane, r.run_id FROM match_proposal p '
                . 'JOIN match_run r ON r.id = p.match_run_id WHERE p.match_run_id = ? AND ' . DecisionService::duplicateLaneSql('p.lane')
                . " AND CAST(JSON_EXTRACT(p.evidence, '$.group') AS CHAR) IN (" . implode(',', array_fill(0, count($grps), '?')) . ') ORDER BY p.id',
                [$run, ...$grps],
            ) as $p) {
                $props[self::groupKey($p)][] = $p;
            }
        }
        if ($single !== []) {
            foreach ($this->db->all('SELECT p.id, p.match_run_id, p.listing_id, p.proposed_sku_id, p.status, p.evidence, p.lane, r.run_id FROM match_proposal p '
                . 'JOIN match_run r ON r.id = p.match_run_id WHERE p.id IN (' . implode(',', array_fill(0, count($single), '?')) . ')', $single) as $p) {
                $props['p' . (int) $p['id']][] = $p;
            }
        }
        // The listings the evidence names, by variant id on the suggestion's site.
        $channelOf = [];
        $pl = array_values(array_unique(array_map(static fn (array $p): int => (int) $p['listing_id'], array_merge(...array_values($props ?: [[]])))));
        foreach (array_chunk($pl, 500) as $chunk) {
            foreach ($this->db->all('SELECT id, channel_id FROM channel_listing WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $l) {
                $channelOf[(int) $l['id']] = (int) $l['channel_id'];
            }
        }
        $want = [];
        foreach ($props as $ps) {
            foreach ($ps as $p) {
                $ev = Html::json($p['evidence']);
                $c = $channelOf[(int) $p['listing_id']] ?? 0;
                foreach ([$ev['keeper'] ?? null, ...(is_array($ev['members'] ?? null) ? $ev['members'] : [])] as $m) {
                    if (is_array($m) && (is_string($m['vpg_variant_id'] ?? null) || is_int($m['vpg_variant_id'] ?? null))) {
                        $want[$c][(string) $m['vpg_variant_id']] = true;
                    }
                }
            }
        }
        $byVariant = [];
        foreach ($want as $c => $vs) {
            foreach (array_chunk(array_keys($vs), 500) as $chunk) {
                foreach ($this->db->all('SELECT id, external_variant_id FROM channel_listing WHERE channel_id = ? AND external_variant_id IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ')', [$c, ...array_map('strval', $chunk)]) as $l) {
                    $byVariant[$c][(string) $l['external_variant_id']] = (int) $l['id'];
                }
            }
        }
        $out = [];
        foreach ($props as $key => $ps) {
            $first = $ps[0];
            $ev = Html::json($first['evidence']);
            $listings = [];
            foreach ($ps as $p) {
                $listings[(int) $p['listing_id']] = true;
                $pev = Html::json($p['evidence']);
                $c = $channelOf[(int) $p['listing_id']] ?? 0;
                $named = 0;
                foreach ([$pev['keeper'] ?? null, ...(is_array($pev['members'] ?? null) ? $pev['members'] : [])] as $m) {
                    $vid = is_array($m) && (is_string($m['vpg_variant_id'] ?? null) || is_int($m['vpg_variant_id'] ?? null)) ? (string) $m['vpg_variant_id'] : null;
                    if ($vid !== null && isset($byVariant[$c][$vid])) {
                        $listings[$byVariant[$c][$vid]] = true;
                        $named++;
                    }
                }
                if ($named === 0 && $p['proposed_sku_id'] !== null) {
                    // A suggestion that names no listings: the listings of the item it proposes (now), on its site.
                    $root = (new DecisionService($this->db))->rootOf((int) $p['proposed_sku_id']);
                    foreach ($this->db->column("SELECT id FROM channel_listing WHERE channel_id = ? AND sku_id = ? AND status IN ('mapped', 'quarantined') ORDER BY id LIMIT 20",
                        [$c, $root]) as $lid) {
                        $listings[(int) $lid] = true;
                    }
                }
            }
            $ids = array_map('intval', array_keys($listings));
            sort($ids);
            $statuses = array_count_values(array_map(static fn (array $p): string => (string) $p['status'], $ps));
            $out[] = [
                'id' => (int) $first['id'], 'key' => $key, 'run_id' => (int) $first['match_run_id'], 'run' => (string) $first['run_id'],
                'group' => is_int($ev['group'] ?? null) || is_string($ev['group'] ?? null) ? (string) $ev['group'] : null,
                'kind' => is_string($ev['kind'] ?? null) ? $ev['kind'] : null,
                'proposals' => array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'listing_id' => (int) $p['listing_id'], 'status' => (string) $p['status'],
                    'proposed_sku_id' => $p['proposed_sku_id'] === null ? null : (int) $p['proposed_sku_id']], $ps),
                'listings' => $ids, 'open' => $statuses['open'] ?? 0, 'decided' => count($ps) - ($statuses['open'] ?? 0),
                'sweep' => self::sweep($ev['sweep'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * A rule's detail as people read it (plan F125; the rules write it for the matching files): "listing + / item +lemonade" ->
     * "extra word: lemonade", "+a+b / +c" -> "only on one page: a, b, c", "pod_kit/prefilled vs -" -> "Pod kit (prefilled) vs not
     * stated"; '' for none.
     */
    public static function readableDetail(string $detail): string
    {
        $d = trim($detail);
        if ($d === '') {
            return '';
        }
        $words = static fn (string ...$sides): array => array_values(array_unique(array_filter(array_map('trim', explode('+', implode('+', $sides))),
            static fn (string $w): bool => $w !== '' && $w !== '-')));
        if (preg_match('#^listing \+(.*) / item \+(.*)$#D', $d, $m) === 1) {
            $extra = $words($m[1], $m[2]);
            return $extra === [] ? '' : (count($extra) === 1 ? 'extra word: ' : 'extra words: ') . implode(', ', $extra);
        }
        if (preg_match('#^\+(.*) / \+(.*)$#D', $d, $m) === 1) {
            $only = $words($m[1], $m[2]);
            return $only === [] ? '' : 'only on one page: ' . implode(', ', $only);
        }
        $side = static function (string $v): string {
            $v = trim($v);
            if ($v === '' || $v === '-') {
                return Words::FORM_VALUE['unknown'];
            }
            if (preg_match('#^([a-z]+(?:_[a-z]+)*)(?:/([a-z_]+))?$#D', $v, $f) === 1 && (Words::has('FORM_VALUE', $f[1]))) {
                return Words::of('FORM_VALUE', $f[1]) . (isset($f[2]) && $f[2] !== '' ? ' (' . Words::of('FORM_VALUE', $f[2]) . ')' : '');
            }
            return str_replace('+', ', ', $v);
        };
        $sides = array_map($side, explode(' vs ', $d));
        // "not stated vs not stated" says nothing the reason does not say already ("The kind of product is not clear on a page").
        return array_unique($sides) === [Words::FORM_VALUE['unknown']] ? '' : implode(' vs ', $sides);
    }

    /**
     * Why the wider duplicate sweep suggested a group (bin/import_vpg_duplicates.php writes `evidence.sweep`, M37), as the group
     * page shows it: the engine, the group's score and, per pair of pages (by site variant id), its score, the identity fields
     * both pages state alike, those neither states, where the barcodes stand, the price ratio and whether the two are options of
     * one product page. Null for a suggestion of another kind. Only known values pass (the evidence is data).
     *
     * @return array{engine: ?string, score: ?int, pairs: list<array<string, mixed>>}|null
     */
    public static function sweep(mixed $sweep): ?array
    {
        if (!is_array($sweep)) {
            return null;
        }
        $fields = static fn (mixed $v): array => array_values(array_filter(is_array($v) ? $v : [],
            static fn (mixed $f): bool => is_string($f) && isset(self::SWEEP_FIELDS[$f])));
        $pairs = [];
        foreach (array_slice(is_array($sweep['pairs'] ?? null) ? $sweep['pairs'] : [], 0, 30) as $p) {
            if (!is_array($p) || !is_scalar($p['a'] ?? null) || !is_scalar($p['b'] ?? null)) {
                continue;
            }
            $pairs[] = ['a' => mb_substr((string) $p['a'], 0, 64), 'b' => mb_substr((string) $p['b'], 0, 64),
                'score' => is_int($p['score'] ?? null) ? $p['score'] : null,
                'agree' => array_map(static fn (string $f): string => self::SWEEP_FIELDS[$f], $fields($p['agree'] ?? null)),
                'unknown' => array_map(static fn (string $f): string => self::SWEEP_FIELDS[$f], $fields($p['unknown'] ?? null)),
                'barcode' => self::SWEEP_BARCODE[is_string($p['barcode'] ?? null) ? $p['barcode'] : ''] ?? null,
                'price_ratio' => is_int($p['price_ratio'] ?? null) || is_float($p['price_ratio'] ?? null) ? round((float) $p['price_ratio'], 2) : null,
                'same_product_page' => ($p['same_product_page'] ?? false) === true];
        }
        return ['engine' => is_string($sweep['engine'] ?? null) ? mb_substr($sweep['engine'], 0, 16) : null,
            'score' => is_int($sweep['score'] ?? null) ? $sweep['score'] : null, 'pairs' => $pairs];
    }

    /** @param array<string, mixed> $p a match_proposal row (match_run_id, id, evidence) */
    private static function groupKey(array $p): string
    {
        $g = Html::json($p['evidence'])['group'] ?? null;
        return is_int($g) || (is_string($g) && $g !== '') ? (int) $p['match_run_id'] . ':' . $g : 'p' . (int) $p['id'];
    }

    /** @param list<int> $ids @return list<int> the listings with a decision waiting for a second person */
    private function pendingListings(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return array_map('intval', $this->db->column('SELECT pending_listing_id FROM match_decision WHERE pending_listing_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')', $ids));
    }

    /**
     * The attribute rows as the Normalizer takes them: name and value as strings, is_variable as 0/1; anything else is dropped
     * (the profile is the site's data).
     *
     * @return list<array{name: string, value: string, is_variable: int}>
     */
    private static function profileAttributes(mixed $items): array
    {
        $out = [];
        foreach (is_array($items) ? array_slice($items, 0, 200) : [] as $a) {
            if (is_array($a) && is_scalar($a['name'] ?? null) && is_scalar($a['value'] ?? null)) {
                $out[] = ['name' => mb_substr((string) $a['name'], 0, 200), 'value' => mb_substr((string) $a['value'], 0, 500),
                    'is_variable' => (int) (is_scalar($a['is_variable'] ?? null) ? $a['is_variable'] : 0) === 1 ? 1 : 0];
            }
        }
        return $out;
    }

    private static function s(mixed $v, int $max = 500): ?string
    {
        if ($v === null || $v === '' || !(is_string($v) || is_int($v) || is_float($v))) {
            return null;
        }
        return mb_substr((string) $v, 0, $max);
    }
}
