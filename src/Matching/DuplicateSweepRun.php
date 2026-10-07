<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * One run of the wider duplicate sweep over a site's export (tools/vpg_duplicates/export.php; docs/decisions.md M37): the
 * pairs of DIFFERENT CW items whose listings describe the same physical product (DuplicateSweep::judge), what keeps a pair
 * out, and the groups to suggest on the Duplicates screen. Pure: the caller reads the export and writes the outputs.
 *
 * 1. Listings: the site's MAPPED listings with a profile; each stands for the item it is linked to now (merges followed).
 * 2. Candidates: the matcher's blocking and prescore (CW\Matching\Candidates, top TOP_K per listing) plus a signature block
 *    (the same brand family, form class, strength, ml, puffs, ohm, pack and title words in any order: differently worded
 *    titles), at most MAX_BLOCK listings per block.
 * 3. Each candidate pair of two different items is judged; an accepted pair is then kept out when the pair was suggested
 *    before (any merge suggestion of a duplicate lane, any status: `already_suggested`), a person kept them separate
 *    (a match_reject either way, M22: `rejected`), or either item is protected (`protected`), has a quarantined or ignored
 *    listing (`quarantined`), a decision waiting for a second person (`pending`), a listing in an OPEN merge suggestion group
 *    (`in_open_group`: decide that group first, the next run picks the pair up) or another open proposal (`open_proposal`);
 *    `unknown_item`: merged into an item the export does not hold.
 * 4. Groups: the accepted pairs, best score first, joined greedily into groups of at most MAX_GROUP items in which EVERY two
 *    items are an accepted pair (a pair that would join two groups otherwise is left out: `not_clique` / `group_cap`).
 *    The keeper: the most units in 365 days, then usable barcodes, then the older page (the Duplicates screen's rule).
 * Deterministic: the same export gives the same groups, in the same order, with the same numbers.
 */
final class DuplicateSweepRun
{
    public const TOP_K = 25;
    public const MAX_BLOCK = 60;
    public const MAX_GROUP = 6;
    public const EXCLUSIONS = ['already_suggested', 'rejected', 'protected', 'unknown_item', 'quarantined', 'pending', 'in_open_group', 'open_proposal'];

    /** @var array<int, array<string, mixed>> listing id => export record */
    private array $listings = [];
    /** @var array<int, array<string, mixed>> sku id => export record */
    private array $skus = [];
    /** @var array<int, int> sku id => merged into */
    private array $mergedInto = [];
    /** @var array<int, list<array<string, mixed>>> sku id => its links (any site, any status) */
    private array $links = [];
    /** @var array<int, int> listing id => the sku it is linked to (any site) */
    private array $linkedTo = [];
    /** @var array<int, array<string, mixed>> features by listing id */
    private array $features = [];
    /** @var array<string, array<string, mixed>> "a-b" (listing ids, a < b) => judgement */
    private array $judged = [];
    /** @var array{proposal: list<array<string, mixed>>, open: list<array<string, mixed>>, reject: list<array<string, mixed>>, pending: list<array<string, mixed>>, meta: ?array<string, mixed>} */
    private array $records;

    /**
     * @param iterable<array<string, mixed>> $records the export's lines (decoded)
     */
    public function __construct(iterable $records)
    {
        $this->records = ['proposal' => [], 'open' => [], 'reject' => [], 'pending' => [], 'meta' => null];
        foreach ($records as $r) {
            switch ($r['type'] ?? null) {
                case 'listing':
                    $this->listings[(int) $r['id']] = $r;
                    break;
                case 'sku':
                    $this->skus[(int) $r['id']] = $r;
                    if ($r['merged_into_sku_id'] !== null) {
                        $this->mergedInto[(int) $r['id']] = (int) $r['merged_into_sku_id'];
                    }
                    break;
                case 'merged':
                    $this->mergedInto[(int) $r['id']] = (int) $r['merged_into_sku_id'];
                    break;
                case 'link':
                    $this->links[(int) $r['sku_id']][] = $r;
                    if (in_array($r['status'], ['mapped', 'quarantined'], true)) {
                        $this->linkedTo[(int) $r['listing_id']] = (int) $r['sku_id'];
                    }
                    break;
                case 'meta':
                    $this->records['meta'] = $r;
                    break;
                case 'proposal':
                case 'open':
                case 'reject':
                case 'pending':
                    $this->records[$r['type']][] = $r;
                    break;
            }
        }
        ksort($this->listings);
        foreach ($this->listings as $id => $l) {
            if (in_array($l['status'], ['mapped', 'quarantined'], true)) {
                $this->linkedTo[$id] = (int) $l['sku_id'];
            }
        }
    }

    /** The item $sku is now (merged_into followed). */
    public function root(int $sku): int
    {
        $seen = [];
        while (isset($this->mergedInto[$sku]) && !isset($seen[$sku]) && count($seen) < 100) {
            $seen[$sku] = true;
            $sku = $this->mergedInto[$sku];
        }
        return $sku;
    }

    /**
     * @param array{top?: int, max_block?: int, max_group?: int} $opts
     * @return array{groups: list<array<string, mixed>>, pairs: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(array $opts = []): array
    {
        $top = (int) ($opts['top'] ?? self::TOP_K);
        $maxBlock = (int) ($opts['max_block'] ?? self::MAX_BLOCK);
        $maxGroup = (int) ($opts['max_group'] ?? self::MAX_GROUP);
        $s = ['listings' => count($this->listings), 'eligible' => 0, 'not_mapped' => 0, 'no_profile' => 0];

        // 1. Listings and their features (the site's line lexicon over every exported listing, as tools/first_match/run.php).
        $rows = [];
        foreach ($this->listings as $id => $l) {
            $rows[$id] = self::row($l);
        }
        $lex = TitlePattern::lexicon($rows);
        $repOf = [];   // item => its representative listing (the lowest id)
        foreach ($this->listings as $id => $l) {
            if ($l['status'] !== 'mapped') {
                $s['not_mapped']++;
                continue;
            }
            if ($l['product_title'] === null) {
                $s['no_profile']++;
                continue;
            }
            $this->features[$id] = DuplicateSweep::features($rows[$id], $lex[TitlePattern::brandKey((string) $l['brand'])] ?? []);
            $item = $this->root((int) $l['sku_id']);
            $repOf[$item] ??= $id;
            $s['eligible']++;
        }

        // 2. Candidates.
        $cand = [];
        $c = new Candidates($this->features);
        foreach ($this->features as $id => $f) {
            foreach ($c->generate($f, $top, [$id => true]) as $x) {
                $cand[self::key($id, (int) $x['id'])] = 'block';
            }
        }
        $s['candidates_block'] = count($cand);
        $sig = [];
        foreach ($this->features as $id => $f) {
            $sig[self::signature($f)][] = $id;
        }
        $s['signature_blocks_over_cap'] = 0;
        foreach ($sig as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            if (count($ids) > $maxBlock) {
                $s['signature_blocks_over_cap']++;
                continue;
            }
            foreach ($ids as $i => $a) {
                foreach (array_slice($ids, $i + 1) as $b) {
                    $cand[self::key($a, $b)] ??= 'signature';
                }
            }
        }
        ksort($cand, SORT_STRING);
        $s['candidates'] = count($cand);
        $s['candidates_signature_only'] = count(array_filter($cand, static fn (string $v): bool => $v === 'signature'));

        // 3. Judge; keep out what must not be suggested.
        $state = $this->state();
        $blocks = [];
        $sole = [];
        $accepted = [];
        $excluded = array_fill_keys(self::EXCLUSIONS, 0);
        $s['same_item'] = 0;
        foreach ($cand as $k => $via) {
            [$a, $b] = array_map('intval', explode('-', $k));
            $ia = $this->root((int) $this->listings[$a]['sku_id']);
            $ib = $this->root((int) $this->listings[$b]['sku_id']);
            if ($ia === $ib) {
                $s['same_item']++;
                continue;
            }
            // Not cached: a refused pair is never needed again (hundreds of thousands of them); an accepted one is.
            $j = DuplicateSweep::judge($this->features[$a], $this->features[$b]);
            if ($j['ok']) {
                $this->judged[$k] = $j;
            }
            foreach ($j['blocks'] as $x) {
                $blocks[$x['code']] = ($blocks[$x['code']] ?? 0) + 1;
            }
            if (count($j['blocks']) === 1) {
                $sole[$j['blocks'][0]['code']] = ($sole[$j['blocks'][0]['code']] ?? 0) + 1;
            }
            if (!$j['ok']) {
                continue;
            }
            $why = $this->excluded($ia, $ib, $state);
            if ($why !== null) {
                $excluded[$why]++;
            }
            $accepted[] = ['a' => $a, 'b' => $b, 'item_a' => $ia, 'item_b' => $ib, 'via' => $via, 'judge' => $j, 'excluded' => $why];
        }
        $s['judged'] = count($cand) - $s['same_item'];
        $s['accepted'] = count($accepted);
        $s['excluded'] = $excluded;
        arsort($blocks);
        arsort($sole);
        $s['blocks'] = $blocks;
        $s['sole_blocks'] = $sole;

        // What the rules make of the merge suggestions made before (a suggestion's listing against its keeper's listing): for the
        // person deciding them, a reason against is worth knowing.
        $byVariant = [];
        foreach ($this->listings as $id => $l) {
            $byVariant[(string) $l['variant']] = $id;
        }
        $ex = ['judged' => 0, 'accepted' => 0, 'refused_by' => []];
        foreach ($this->records['proposal'] as $p) {
            $kv = is_array($p['evidence']['keeper'] ?? null) ? (string) ($p['evidence']['keeper']['vpg_variant_id'] ?? '') : '';
            $x = (int) $p['listing_id'];
            $y = $byVariant[$kv] ?? null;
            if ($y === null || $x === $y || !isset($this->features[$x], $this->features[$y])) {
                continue;
            }
            $j = $this->judge($x, $y);
            $ex['judged']++;
            $ex['accepted'] += $j['ok'] ? 1 : 0;
            foreach ($j['blocks'] as $b) {
                $ex['refused_by'][$b['code']] = ($ex['refused_by'][$b['code']] ?? 0) + 1;
            }
        }
        arsort($ex['refused_by']);
        $s['earlier_suggestions'] = $ex;

        // 4. Groups: greedy cliques of items, best pairs first.
        $edges = array_values(array_filter($accepted, static fn (array $p): bool => $p['excluded'] === null));
        usort($edges, static fn (array $x, array $y): int => [$y['judge']['score'], min($x['item_a'], $x['item_b']), max($x['item_a'], $x['item_b'])]
            <=> [$x['judge']['score'], min($y['item_a'], $y['item_b']), max($y['item_a'], $y['item_b'])]);
        $groupOf = [];
        $groups = [];
        $nextId = 0;
        $s['not_clique'] = 0;
        $s['group_cap'] = 0;
        $edgeState = [];
        foreach ($edges as $e) {
            $ga = $groupOf[$e['item_a']] ?? null;
            $gb = $groupOf[$e['item_b']] ?? null;
            $k = self::key($e['a'], $e['b']);
            if ($ga !== null && $ga === $gb) {
                $edgeState[$k] = 'grouped';
                continue;
            }
            $ma = $ga === null ? [$e['item_a']] : $groups[$ga];
            $mb = $gb === null ? [$e['item_b']] : $groups[$gb];
            if (count($ma) + count($mb) > $maxGroup) {
                $s['group_cap']++;
                $edgeState[$k] = 'group_cap';
                continue;
            }
            $ok = true;
            foreach ($ma as $x) {
                foreach ($mb as $y) {
                    if ($this->excluded($x, $y, $state) !== null || !$this->judge($repOf[$x], $repOf[$y])['ok']) {
                        $ok = false;
                        break 2;
                    }
                }
            }
            if (!$ok) {
                $s['not_clique']++;
                $edgeState[$k] = 'not_clique';
                continue;
            }
            $id = $ga ?? $gb ?? $nextId++;
            $groups[$id] = array_values(array_unique(array_merge($ma, $mb)));
            if ($ga !== null && $gb !== null && $ga !== $gb) {
                unset($groups[$gb]);
            }
            foreach ($groups[$id] as $item) {
                $groupOf[$item] = $id;
            }
            $edgeState[$k] = 'grouped';
        }

        // 5. The groups as the importer takes them: keeper, members, every pair within the group explained.
        $out = [];
        foreach ($groups as $items) {
            sort($items);
            $members = array_map(fn (int $item): array => $this->member($repOf[$item], $item), $items);
            usort($members, static fn (array $x, array $y): int => [$y['units_365d'], $y['barcodes'] > 0, strlen($x['vpg_variant_id']), $x['vpg_variant_id'], $x['listing_id']]
                <=> [$x['units_365d'], $x['barcodes'] > 0, strlen($y['vpg_variant_id']), $y['vpg_variant_id'], $y['listing_id']]);
            $pairs = [];
            foreach ($members as $i => $m) {
                foreach (array_slice($members, $i + 1) as $n) {
                    $j = $this->judge($m['listing_id'], $n['listing_id']);
                    $pairs[] = ['a' => $m['vpg_variant_id'], 'b' => $n['vpg_variant_id'], 'score' => $j['score'], 'agree' => $j['agree'], 'unknown' => $j['unknown'],
                        'n_a' => $j['n_a'], 'barcode' => $j['barcode'], 'price_ratio' => $j['price_ratio'], 'name' => $j['name'], 'flags' => $j['flags'],
                        'same_product_page' => $j['same_product']];
                }
            }
            $out[] = [
                'kind' => 'sweep', 'key' => DuplicateSweep::VERSION . ':' . implode('-', $items),
                'score' => min(array_column($pairs, 'score')), 'units_365d' => array_sum(array_column($members, 'units_365d')),
                'keeper' => $members[0], 'members' => $members, 'pairs' => $pairs,
                'no_barcode' => count(array_filter($members, static fn (array $m): bool => $m['barcodes'] === 0)),
                'counted' => array_values(array_column(array_filter($members, static fn (array $m): bool => $m['counted']), 'code')),
                'units_per_item_not_1' => array_values(array_column(array_filter($members, static fn (array $m): bool => $m['u_not_1']), 'code')),
            ];
        }
        usort($out, static fn (array $x, array $y): int => [$y['units_365d'], $x['key']] <=> [$x['units_365d'], $y['key']]);
        foreach ($out as $i => $g) {
            $out[$i] = ['group' => $i + 1] + $g;
        }

        // 6. Every accepted pair with what became of it.
        $pairsOut = [];
        foreach ($accepted as $p) {
            $k = self::key($p['a'], $p['b']);
            $pairsOut[] = ['a' => $p['a'], 'b' => $p['b'], 'variant_a' => (string) $this->listings[$p['a']]['variant'], 'variant_b' => (string) $this->listings[$p['b']]['variant'],
                'item_a' => $p['item_a'], 'item_b' => $p['item_b'], 'via' => $p['via'], 'score' => $p['judge']['score'], 'barcode' => $p['judge']['barcode'],
                'outcome' => $p['excluded'] ?? ($edgeState[$k] ?? 'grouped')];
        }
        $sizes = array_count_values(array_map(static fn (array $g): int => count($g['members']), $out));
        ksort($sizes);
        $s['groups'] = count($out);
        $s['group_sizes'] = $sizes;
        $s['proposals'] = array_sum(array_map(static fn (array $g): int => count($g['members']) - 1, $out));
        $s['groups_with_a_listing_without_barcode'] = count(array_filter($out, static fn (array $g): bool => $g['no_barcode'] > 0));
        $s['pairs_in_groups'] = array_sum(array_map(static fn (array $g): int => count($g['pairs']), $out));
        $s['pairs_in_groups_by_barcode'] = array_count_values(array_merge(...array_map(static fn (array $g): array => array_column($g['pairs'], 'barcode'), $out ?: [['pairs' => []]])));
        $s['groups_on_one_product_page'] = count(array_filter($out, static fn (array $g): bool => array_filter($g['pairs'], static fn (array $p): bool => !$p['same_product_page']) === []));
        $s['groups_counted'] = count(array_filter($out, static fn (array $g): bool => $g['counted'] !== []));
        return ['groups' => $out, 'pairs' => $pairsOut, 'summary' => $s];
    }

    /** The judgement of two listings (cached: the accepted pairs and those the grouping asks for). @return array<string, mixed> */
    public function judge(int $a, int $b): array
    {
        $k = self::key($a, $b);
        return $this->judged[$k] ??= DuplicateSweep::judge($this->features[min($a, $b)], $this->features[max($a, $b)]);
    }

    /** @return array<string, mixed> */
    public function features(int $listingId): array
    {
        return $this->features[$listingId] ?? [];
    }

    /** A listing as the group members show it (any exported listing with features). @return array<string, mixed> */
    public function memberOf(int $listingId): array
    {
        return $this->member($listingId, $this->root((int) $this->listings[$listingId]['sku_id']));
    }

    /** The Normalizer input row of an exported listing. @param array<string, mixed> $l @return array<string, mixed> */
    public static function row(array $l): array
    {
        $stored = is_array($l['features'] ?? null) ? $l['features'] : [];
        return ['site' => 'vapeandgo', 'variant_id' => ctype_digit((string) $l['variant']) ? (int) $l['variant'] : 0,
            'product_id' => (int) ($stored['product_id'] ?? 0), 'product_title' => $l['product_title'], 'variant_title' => $l['variant_title'],
            'brand' => $l['brand'], 'attributes' => is_array($l['attributes'] ?? null) ? $l['attributes'] : [],
            'barcodes' => is_array($l['barcodes'] ?? null) ? $l['barcodes'] : [], 'price' => $l['price'], 'units_30d' => $l['units_30d'] ?? null];
    }

    /** The signature block of a listing: what a duplicate must share, the title's words in any order. */
    public static function signature(array $f): string
    {
        $fam = array_values(array_diff($f['brand_family'] ?? [], array_merge(Veto::GENERIC_LINE_WORDS, Veto::UMBRELLA_LINE_WORDS)));
        sort($fam, SORT_STRING);
        $words = array_map(static fn (string $w): string => Text::stem($w), DuplicateSweep::words($f, $f));
        $words = array_values(array_unique($words));
        sort($words, SORT_STRING);
        $n = static fn (mixed $v): string => $v === null ? '-' : Text::num((float) $v);
        return implode('|', [implode(' ', $fam), $f['form_class'] ?? '-', $n($f['strength_mg'] ?? null), $n($f['volume_ml'] ?? null),
            $n($f['puffs'] ?? null), $n($f['resistance_ohm'] ?? null), $n($f['pack_units'] ?? null), implode(' ', $words)]);
    }

    private static function key(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    /**
     * What the export says about items and listings beyond their links.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        // The site's listings by variant id (the evidence names a group's pages by variant id on the suggestion's site).
        $byVariant = [];
        foreach ($this->listings as $id => $l) {
            $byVariant[(string) $l['variant']] = $id;
        }
        $site = isset($this->records['meta']['channel_id']) ? (int) $this->records['meta']['channel_id'] : null;
        // Existing merge-suggestion groups (any status): their items; the open ones.
        $groups = [];
        foreach ($this->records['proposal'] as $p) {
            $ev = is_array($p['evidence'] ?? null) ? $p['evidence'] : [];
            $g = $ev['group'] ?? null;
            $key = is_int($g) || (is_string($g) && $g !== '') ? $p['match_run_id'] . ':' . $g : 'p' . $p['id'];
            $groups[$key]['open'] = ($groups[$key]['open'] ?? false) || $p['status'] === 'open';
            $items = [];
            if (isset($this->linkedTo[(int) $p['listing_id']])) {
                $items[] = $this->root($this->linkedTo[(int) $p['listing_id']]);
            }
            if ($p['proposed_sku_id'] !== null) {
                $items[] = $this->root((int) $p['proposed_sku_id']);
            }
            $named = $site === null || (int) ($p['channel_id'] ?? 0) === $site ? [$ev['keeper'] ?? null, ...(is_array($ev['members'] ?? null) ? $ev['members'] : [])] : [];
            foreach ($named as $m) {
                $v = is_array($m) && isset($m['vpg_variant_id']) ? (string) $m['vpg_variant_id'] : null;
                if ($v !== null && isset($byVariant[$v], $this->linkedTo[$byVariant[$v]])) {
                    $items[] = $this->root($this->linkedTo[$byVariant[$v]]);
                }
            }
            foreach ($items as $i) {
                $groups[$key]['items'][$i] = true;
            }
        }
        $suggested = [];
        $openItems = [];
        foreach ($groups as $g) {
            $items = array_keys($g['items'] ?? []);
            foreach ($items as $x) {
                foreach ($items as $y) {
                    if ($x < $y) {
                        $suggested[$x . '-' . $y] = true;
                    }
                }
                if ($g['open']) {
                    $openItems[$x] = true;
                }
            }
        }
        $rejected = [];
        foreach ($this->records['reject'] as $r) {
            $from = $this->linkedTo[(int) $r['listing_id']] ?? null;
            if ($from !== null) {
                $x = $this->root($from);
                $y = $this->root((int) $r['sku_id']);
                $rejected[min($x, $y) . '-' . max($x, $y)] = true;
            }
        }
        $itemOf = fn (int $listing): ?int => isset($this->linkedTo[$listing]) ? $this->root($this->linkedTo[$listing]) : null;
        $pending = [];
        foreach ($this->records['pending'] as $d) {
            if (($i = $itemOf((int) $d['listing_id'])) !== null) {
                $pending[$i] = true;
            }
        }
        $openOther = [];
        foreach ($this->records['open'] as $o) {
            $lane = is_string($o['lane'] ?? null) ? $o['lane'] : null;
            if (($lane === 'duplicate' || ($lane !== null && str_ends_with($lane, '_duplicate')))) {
                continue;   // its group is open: in_open_group
            }
            if (($i = $itemOf((int) $o['listing_id'])) !== null) {
                $openOther[$i] = true;
            }
        }
        $quarantined = [];
        foreach ($this->links as $sku => $ls) {
            foreach ($ls as $l) {
                if (in_array($l['status'], ['quarantined', 'ignored'], true)) {
                    $quarantined[$this->root((int) $sku)] = true;
                }
            }
        }
        foreach ($this->listings as $l) {
            if ($l['status'] === 'quarantined') {
                $quarantined[$this->root((int) $l['sku_id'])] = true;
            }
        }
        return ['suggested' => $suggested, 'open_items' => $openItems, 'rejected' => $rejected, 'pending' => $pending,
            'open_other' => $openOther, 'quarantined' => $quarantined];
    }

    /** Why a pair of items must not be suggested now, or null. @param array<string, mixed> $state */
    private function excluded(int $x, int $y, array $state): ?string
    {
        $k = min($x, $y) . '-' . max($x, $y);
        foreach ([$x, $y] as $i) {
            $sku = $this->skus[$i] ?? null;
            if ($sku === null) {
                return 'unknown_item';   // merged into an item the export does not hold
            }
            if ($sku['sell_policy'] !== 'legacy') {
                return 'protected';      // cannot be merged (M31)
            }
        }
        return match (true) {
            isset($state['suggested'][$k]) => 'already_suggested',
            isset($state['rejected'][$k]) => 'rejected',
            isset($state['quarantined'][$x]) || isset($state['quarantined'][$y]) => 'quarantined',
            isset($state['pending'][$x]) || isset($state['pending'][$y]) => 'pending',
            isset($state['open_items'][$x]) || isset($state['open_items'][$y]) => 'in_open_group',
            isset($state['open_other'][$x]) || isset($state['open_other'][$y]) => 'open_proposal',
            default => null,
        };
    }

    /** A group member as the importer and the screen read it. @return array<string, mixed> */
    private function member(int $listingId, int $item): array
    {
        $l = $this->listings[$listingId];
        $sku = $this->skus[$item] ?? [];
        $f = $this->features[$listingId];
        $title = trim((string) $l['product_title'] . ((string) $l['variant_title'] !== '' && $l['variant_title'] !== $l['product_title'] ? ' | ' . $l['variant_title'] : ''));
        $uNot1 = false;
        foreach ($this->links[$item] ?? [] as $x) {
            if (in_array($x['status'], ['mapped', 'quarantined'], true) && (int) $x['units_per_item'] !== 1) {
                $uNot1 = true;
            }
        }
        return ['vpg_variant_id' => (string) $l['variant'], 'listing_id' => $listingId, 'sku_id' => $item, 'code' => $sku['code'] ?? null,
            'title' => mb_substr($title, 0, 300), 'status' => is_array($l['features'] ?? null) ? ($l['features']['variant_status'] ?? null) : null,
            'units_30d' => (int) ($l['units_30d'] ?? 0), 'units_365d' => (int) ($l['units_365d'] ?? 0), 'barcodes' => count($f['gtins'] ?? []),
            'price' => $l['price'], 'counted' => (bool) ($sku['counted'] ?? false), 'u_not_1' => $uNot1];
    }
}
