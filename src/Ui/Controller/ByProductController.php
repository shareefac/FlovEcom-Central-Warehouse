<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Mapping\Proposals;
use CW\Matching\Gtin;
use CW\Output\CsvWriter;
use CW\Ui\ByProductContext;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Queries;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Products › By Item (docs/decisions.md U113-U122; the owner's request of 10 Oct 2026: "list all product and show each product
 * with which store mapped and this same place easy map option", and then: "we need variant mapping not product ... each variant we
 * have treating item"). In the owner's words, which this screen and its picker use (U122): a store's sellable option is a VARIANT
 * (a channel_listing row), the warehouse record it is matched to is an ITEM (a sku row), and a PRODUCT is the parent page that has
 * many variants. The paths and keys keep `products` / `by_product`.
 *
 * One row per warehouse item, one column per store (the stores are the channel table's rows, by name: none is named or counted
 * here), each cell saying whether the item is matched there (and to which variant), waits for a second OK, has a suggestion, or is
 * not on that store.
 *
 * "Match…" on a cell opens the picker of that item and that store (map()): the variants the computer suggests for it, then a search
 * of the store's variants that still wait for a match. "Choose" opens the EXISTING variant's page (the website product's page of the
 * other matching screens) with the item picked (`?pick=`), and the person says yes there: every rule, veto, spot check and second OK
 * stays in that page and in DecisionService. This class only reads; it has no POST. ByProductContext carries "came from By Item" to
 * that page and back.
 */
final class ByProductController
{
    /** The most items a CSV holds (every item of the filter: the page shows 50 of them). */
    public const CSV_MAX = 100_000;
    /** The picker never pages further than this (its list is best sellers first: nobody reads that far). */
    private const PICK_MAX_PAGE = 200;
    /** Words an item's name ends with that each website writes its own way (a strength, a size): left out of the first search. */
    private const UNIT_WORDS = ['mg', 'ml', 'pk', 'pcs', 'pack', 'x'];

    public function index(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $q = $ctx->queries();
        $me = $ctx->me();
        $stores = $q->everyStore();
        $layout = ['title' => Words::MENU['by_product'], 'active' => 'by_product', 'notice' => (new ReviewController())->notice($ctx, null)];
        $lookOnly = $me->canDecide() ? null : Words::whoCan('mapping.decide');
        if ($stores === []) {
            return $ctx->page('by_product', ['stores' => [], 'rows' => [], 'pills' => [], 'text' => '', 'coverage' => null, 'total' => 0, 'shown' => 0, 'page_no' => 1, 'pages' => 1,
                'prev_link' => null, 'next_link' => null, 'clear_link' => ByProductContext::LIST_PATH, 'csv_link' => '', 'filtered' => false, 'lookOnly' => $lookOnly],
                200, $layout);
        }
        $cov = ByProductContext::coverage($req->param('cov'), $stores);
        if ($cov['unknown']) {
            return $ctx->error(404, 'unknown_store', 'no such store', [ByProductContext::LIST_PATH, Words::MENU['by_product']]);
        }
        $text = ByProductContext::text($req->param('q') ?? '');
        $storeIds = array_map(static fn (array $s): int => (int) $s['id'], $stores);
        $storeId = $cov['store'] === null ? null : (int) $cov['store']['id'];
        $counts = $q->productCoverage($storeIds);
        // "On every store" asks for each store in turn: the store with the fewest matched items first.
        $ordered = $storeIds;
        usort($ordered, static fn (int $a, int $b): int => [$counts['matched'][$a], $a] <=> [$counts['matched'][$b], $b]);
        // A search, and the items with a suggestion waiting, are few: their ids are read in one pass, which also says how many
        // there are. Every other list is long: its total comes from the filter entries' counts and only its page is read.
        $onePass = $text !== '' || $cov['kind'] === 'suggested';
        $all = $onePass ? $q->productIds($cov['kind'], $storeId, $ordered, $text) : [];
        $total = $onePass ? count($all) : self::totalOf($counts, $cov['kind'], $storeId);
        $pages = max(1, (int) ceil($total / Queries::PER_PAGE));
        // Back from a variant's page (`at`): the page of this list that holds that item. A page asked for is that page,
        // as the list is (the pager's links carry no `at`).
        $page = UiRequest::id($req->param('page'));
        $at = $page === null ? UiRequest::id($req->param('at')) : null;
        $came = $at === null ? null : $q->sku($at);
        if ($came === null || $came['merged_into_sku_id'] !== null) {
            $at = null; // a number that names no item (or one joined away since) marks nothing
        }
        if ($page === null && $at !== null) {
            $before = $onePass ? count(array_filter($all, static fn (int $id): bool => $id < $at)) : $q->productsBefore($cov['kind'], $storeId, $ordered, $text, $at);
            $page = intdiv($before, Queries::PER_PAGE) + 1;
        }
        $page = min(max(1, $page ?? 1), $pages);
        $offset = ($page - 1) * Queries::PER_PAGE;
        $ids = $onePass ? array_slice($all, $offset, Queries::PER_PAGE) : $q->productIds($cov['kind'], $storeId, $ordered, $text, Queries::PER_PAGE, $offset);
        // That item's row is marked; when the filter no longer holds it (it is matched now), it is still shown, first.
        $pinned = $at !== null && !in_array($at, $ids, true);
        $products = $q->productRows($pinned ? [$at, ...$ids] : $ids);
        if ($pinned) {
            usort($products, static fn (array $a, array $b): int => [(int) $a['id'] !== $at, (int) $a['id']] <=> [(int) $b['id'] !== $at, (int) $b['id']]);
        }
        $base = ['cov' => $cov['value'], 'q' => $text === '' ? null : $text];
        $rows = self::rows($products, $stores, $q->productCells($pinned ? [$at, ...$ids] : $ids), $me->canDecide(), $cov['value'], $text);
        foreach ($rows as $i => $r) {
            $rows[$i]['here'] = $r['id'] === $at;
            $rows[$i]['pinned'] = $pinned && $r['id'] === $at;
        }
        $pills = [['label' => Words::BY_PRODUCT['all'], 'tone' => null, 'count' => $counts['total'], 'current' => $cov['kind'] === null,
            'href' => Html::url(ByProductContext::LIST_PATH, ['cov' => null] + $base)],
            ['label' => Words::BY_PRODUCT['every'], 'tone' => Words::tone('PRODUCT_STORE', 'matched'), 'count' => $counts['every'], 'current' => $cov['kind'] === 'every',
                'href' => Html::url(ByProductContext::LIST_PATH, ['cov' => 'every'] + $base)]];
        foreach ($stores as $s) {
            $pills[] = ['label' => Words::say('BY_PRODUCT', 'missing', (string) $s['name']), 'tone' => Words::tone('PRODUCT_STORE', 'none'),
                'count' => max(0, $counts['total'] - $counts['matched'][(int) $s['id']]), 'current' => $cov['kind'] === 'missing' && $storeId === (int) $s['id'],
                'href' => Html::url(ByProductContext::LIST_PATH, ['cov' => 'missing-' . $s['code']] + $base)];
        }
        $pills[] = ['label' => Words::BY_PRODUCT['suggested'], 'tone' => Words::tone('PRODUCT_STORE', 'suggested'), 'count' => $counts['suggested'],
            'current' => $cov['kind'] === 'suggested', 'href' => Html::url(ByProductContext::LIST_PATH, ['cov' => 'suggested'] + $base)];
        return $ctx->page('by_product', [
            'stores' => array_map(static fn (array $s): string => (string) $s['name'], $stores),
            'rows' => $rows,
            'pills' => $pills,
            'text' => $text,
            'coverage' => $cov['value'],
            'total' => $total,
            // The list's own rows: the item a person came back to is extra when the filter no longer holds it.
            'shown' => count($ids),
            'page_no' => $page,
            'pages' => $pages,
            'prev_link' => $page > 1 ? Html::url(ByProductContext::LIST_PATH, $base + ['page' => $page - 1]) : null,
            'next_link' => $page < $pages ? Html::url(ByProductContext::LIST_PATH, $base + ['page' => $page + 1]) : null,
            'clear_link' => ByProductContext::LIST_PATH,
            'csv_link' => Html::url(ByProductContext::LIST_PATH . '.csv', $base),
            'filtered' => $cov['kind'] !== null || $text !== '',
            'lookOnly' => $lookOnly,
        ], 200, $layout);
    }

    /**
     * The list as a CSV: every item of the filter (not only the page's 50), its parent product, one column per store, each cell in
     * the page's words with the option numbers of the variants it is about.
     */
    public function csv(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $q = $ctx->queries();
        $stores = $q->everyStore();
        $cov = ByProductContext::coverage($req->param('cov'), $stores);
        if ($cov['unknown']) {
            return $ctx->error(404, 'unknown_store', 'no such store', [ByProductContext::LIST_PATH, Words::MENU['by_product']]);
        }
        $text = ByProductContext::text($req->param('q') ?? '');
        $storeIds = array_map(static fn (array $s): int => (int) $s['id'], $stores);
        $columns = [['cw_number', 'text'], ['name', 'text'], ['product', 'text'], ['brand', 'text']];
        foreach ($stores as $s) {
            $columns[] = [(string) $s['name'], 'text'];
        }
        $csv = new CsvWriter([...$columns, ['stores_matched', 'number'], ['stores', 'number']]);
        $ids = $stores === [] ? [] : array_slice($q->productIds($cov['kind'], $cov['store'] === null ? null : (int) $cov['store']['id'], $storeIds, $text), 0, self::CSV_MAX);
        // The rows and their cells in slices of 500 items. Of the profiles (the widest rows there are) only the matched variants'
        // are read, for the parent product's name; the variants are named by their option numbers.
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (self::rows($q->productRows($chunk), $stores, $q->productCells($chunk, false), false, null, '') as $r) {
                $line = [$r['code'], $r['name'], $r['parent'], $r['brand']];
                foreach ($r['cells'] as $c) {
                    $line[] = self::csvCell($c);
                }
                $csv->add([...$line, $r['on'], count($stores)]);
            }
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'products-by-store-' . gmdate('Ymd') . '.csv');
    }

    /**
     * The picker ("Match…"): the variants of ONE store to choose from for ONE warehouse item. Nothing is decided here: "Choose" is
     * a link to the variant's own page with this item picked.
     *
     * The search box is filled in from the item's own words. On a plain opening that search RUNS only when the computer suggests
     * nothing for the item on this store: it reads the profile of every waiting variant of the store, which is too much to do on
     * every opening (U117). With suggestions on the page the person presses Search to look for others. A search the person sent
     * (also an emptied one: every waiting variant) always runs.
     */
    public function map(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $q = $ctx->queries();
        $id = $ctx->id();
        $list = [ByProductContext::LIST_PATH, Words::MENU['by_product']];
        $sku = $q->skus([$id])[$id] ?? null;
        if ($sku === null) {
            return $ctx->error(404, 'unknown_item', 'no such item', $list);
        }
        if ($sku['merged_into_sku_id'] !== null) {
            // An item that was joined into another one is matched nowhere on its own: the list holds the one it was joined into.
            return $ctx->error(404, 'item_joined', 'the item was merged into another one',
                [Html::url(ByProductContext::LIST_PATH, ['at' => (int) $sku['merged_into_sku_id']]) . '#p-' . (int) $sku['merged_into_sku_id'], Words::MENU['by_product']]);
        }
        $stores = $q->everyStore();
        $store = ByProductContext::store($stores, $req->param('channel'));
        if ($store === null) {
            return $ctx->error(404, 'unknown_store', 'no such store', $list);
        }
        $bp = new ByProductContext($id, (string) $store['code'], (int) $store['id'], (string) $store['name'],
            ByProductContext::coverage($req->param('cov'), $stores)['value'], ByProductContext::text($req->param('lq') ?? ''));
        $barcodes = $q->barcodesOfMany([$id])[$id] ?? [];
        // The first search comes from the item's own words; once the person has searched, it is theirs (also an empty one).
        $typed = $req->param('q');
        $searched = $typed !== null;
        $text = $searched ? ByProductContext::text($typed) : self::firstSearch($sku);
        $page = min(UiRequest::id($req->param('page')) ?? 1, self::PICK_MAX_PAGE);
        $suggestedIds = $q->suggestedFor($id, (int) $store['id']);
        $ran = $searched || $suggestedIds === [];
        $found = $ran ? $q->waitingListings((int) $store['id'], $text, $page) : ['ids' => [], 'more' => false];
        $foundIds = array_values(array_diff($found['ids'], $suggestedIds));
        $data = $q->pickerRows([...$suggestedIds, ...$foundIds]);
        $key = static fn (string $b): string => Gtin::key($b) ?? $b;
        $own = array_map($key, $barcodes);
        // "Choose" carries the picker as the person has it (their own search, also an emptied one, and their page), so Back on the
        // variant's page opens this picker again as it is (U119). The redirect after a decision does not use them.
        $choose = ['pick' => $id] + $bp->withPicker($searched ? $text : null, $page)->query();
        $row = static function (int $lid) use ($data, $own, $key, $id, $choose): ?array {
            $r = $data[$lid] ?? null;
            if ($r === null) {
                return null;
            }
            $theirs = array_map($key, Html::strings(Html::json($r['barcodes'])));
            return [
                'listing_id' => $lid,
                'title' => self::s($r['product_title']),
                'variant_title' => self::s($r['variant_title']),
                'brand' => self::s($r['brand']),
                'variant' => self::s($r['external_variant_id']),
                'price' => self::s($r['price']),
                'units_30d' => $r['units_30d'],
                'units_365d' => $r['units_365d'],
                'state' => (string) $r['state'],
                // The strength of its suggestion, when the suggestion is of THIS item (another item's is only "Suggested").
                'band' => $r['band'] !== null && (int) $r['proposed_sku_id'] === $id ? (string) $r['band'] : null,
                'barcode' => $own === [] || $theirs === [] ? 'unknown' : (array_intersect($own, $theirs) !== [] ? 'same' : 'differs'),
                'choose' => Html::url('/ui/review/listing/' . $lid, $choose),
            ];
        };
        $suggested = array_values(array_filter(array_map($row, $suggestedIds)));
        usort($suggested, static fn (array $a, array $b): int => [array_search($a['band'], Proposals::BANDS, true), $a['listing_id']]
            <=> [array_search($b['band'], Proposals::BANDS, true), $b['listing_id']]);
        // What the store holds for the item now: a second variant can still be matched (one item on two pages).
        $now = self::rows([['id' => $id, 'code' => $sku['code'], 'name' => $sku['name'], 'brand' => $sku['brand']]], [$store], $q->productCells([$id]), false, null, '')[0]['cells'][0];
        // Previous and Next always name the search they page through: an emptied search too, which a link built from its values
        // alone would lose (the next page would fall back to the first search).
        $pager = $bp->withPicker($text, 1);
        return $ctx->page('by_product_map', [
            'sku' => ['id' => $id, 'code' => self::s($sku['code']), 'name' => self::s($sku['name']), 'brand' => self::s($sku['brand']),
                'strength' => $sku['strength_mg'] === null ? null : Html::dec($sku['strength_mg']), 'size' => $sku['volume_ml'] === null ? null : Html::dec($sku['volume_ml']),
                'flavour' => self::s($sku['flavour']), 'line' => self::s($sku['line'])],
            'barcodes' => $barcodes,
            'store' => ['code' => $bp->channel, 'name' => $bp->channelName],
            'now' => $now,
            'suggested' => $suggested,
            'found' => array_values(array_filter(array_map($row, $foundIds))),
            'ran' => $ran,
            'text' => $text,
            'keep' => ['channel' => $bp->channel, 'cov' => $bp->coverage, 'lq' => $bp->text === '' ? null : $bp->text],
            'action' => ByProductContext::LIST_PATH . '/' . $id . '/map',
            'page_no' => $page,
            'prev_link' => $ran && $page > 1 ? $pager->pickerUrl($page - 1) : null,
            'next_link' => $found['more'] && $page < self::PICK_MAX_PAGE ? $pager->pickerUrl($page + 1) : null,
            'back' => $bp->listUrl(),
        ], 200, ['title' => Words::say('BY_PRODUCT', 'pick_title', (string) $sku['code'], $bp->channelName), 'active' => 'by_product', 'notice' => null]);
    }

    /**
     * The page's rows: each item with its parent product's name and one cell per store, in the stores' order. A cell's state is the
     * first of matched, waiting for a second OK, suggested, none that the store has for the item; its title is the variant it names
     * (the best seller of the matched ones on the page, the strongest suggestion), `more` how many others of that state there are,
     * `also_…` the other states the store has for it as well, and `items` every variant of the state with its OWN notes (units per
     * sale, on hold, the suggestion's strength): the CSV writes those, so it never borrows one variant's note for another.
     *
     * The parent product (U122) is the product name of the item's best-selling matched variant on any store (units of a year; a
     * tie: the lowest listing id), left out when that name is empty or is the item's own name.
     *
     * @param list<array<string, mixed>> $products
     * @param list<array{id: int, code: string, name: string}> $stores
     * @param array{matched: list<array<string, mixed>>, suggested: list<array<string, mixed>>, waiting: list<array<string, mixed>>} $cells
     * @return list<array<string, mixed>>
     */
    private static function rows(array $products, array $stores, array $cells, bool $canDecide, ?string $coverage, string $text): array
    {
        $by = [];
        foreach (['matched', 'waiting', 'suggested'] as $state) {
            foreach ($cells[$state] as $r) {
                $by[(int) $r['sku_id']][(int) $r['channel_id']][$state][] = $r;
            }
        }
        $parents = [];
        foreach ($cells['matched'] as $r) {
            $rank = [-(int) ($r['units_365d'] ?? 0), (int) $r['listing_id']];
            if (!isset($parents[(int) $r['sku_id']]) || $rank < $parents[(int) $r['sku_id']][0]) {
                $parents[(int) $r['sku_id']] = [$rank, trim((string) ($r['product_title'] ?? ''))];
            }
        }
        $rows = [];
        foreach ($products as $p) {
            $id = (int) $p['id'];
            $on = 0;
            $out = [];
            foreach ($stores as $s) {
                $has = $by[$id][(int) $s['id']] ?? [];
                $suggested = $has['suggested'] ?? [];
                usort($suggested, static fn (array $a, array $b): int => [array_search($a['band'], Proposals::BANDS, true), (int) $a['listing_id']]
                    <=> [array_search($b['band'], Proposals::BANDS, true), (int) $b['listing_id']]);
                $groups = ['matched' => $has['matched'] ?? [], 'waiting' => $has['waiting'] ?? [], 'suggested' => $suggested];
                $state = 'none';
                foreach ($groups as $name => $list) {
                    if ($list !== []) {
                        $state = $name;
                        break;
                    }
                }
                $first = $state === 'none' ? null : $groups[$state][0];
                $bp = new ByProductContext($id, (string) $s['code'], (int) $s['id'], (string) $s['name'], $coverage, $text);
                $own = $first === null ? null : '/ui/review/listing/' . (int) $first['listing_id'];
                // A suggestion is answered on the variant's own page; from here that page leads back here.
                $review = $state === 'suggested' && $canDecide ? Html::url((string) $own, $bp->query()) : null;
                $units = $first !== null && isset($first['units_per_item']) ? (int) $first['units_per_item'] : 1;
                $on += $state === 'matched' ? 1 : 0;
                $out[] = [
                    'store' => (string) $s['name'],
                    'state' => $state,
                    'title' => $first === null ? null : self::title($first),
                    'items' => array_map(static fn (array $r): array => ['variant' => (string) ($r['external_variant_id'] ?? ''), 'notes' => self::notes($state, $r)],
                        $state === 'none' ? [] : $groups[$state]),
                    'link' => $review ?? $own,
                    'more' => $state === 'none' ? 0 : count($groups[$state]) - 1,
                    'sale' => $units === 1 ? null : Words::saleUses($units),
                    'band' => $state === 'suggested' ? (string) $first['band'] : null,
                    'hold' => $state === 'matched' && ($first['status'] ?? null) === 'quarantined',
                    'also_waiting' => $state === 'matched' ? count($groups['waiting']) : 0,
                    'also_suggested' => in_array($state, ['matched', 'waiting'], true) ? count($groups['suggested']) : 0,
                    'review' => $review,
                    // "Match…" for the people who may decide: the picker of this item on this store.
                    'map' => $canDecide && $state !== 'waiting' ? $bp->pickerUrl() : null,
                ];
            }
            $name = self::s($p['name']);
            $parent = $parents[$id][1] ?? '';
            $rows[] = ['id' => $id, 'code' => self::s($p['code']), 'name' => $name, 'brand' => self::s($p['brand']),
                'parent' => $parent === '' || mb_strtolower($parent) === mb_strtolower(trim((string) $name)) ? null : mb_substr($parent, 0, 200),
                'cells' => $out, 'on' => $on, 'tone' => $on === count($stores) ? 'done' : ($on > 0 ? 'needs' : 'waiting'), 'here' => false, 'pinned' => false];
        }
        return $rows;
    }

    /**
     * One variant's own notes in a cell of the given state: "1 sale = N products" when N is not 1 (a match, or a decision waiting),
     * "On hold" for a matched one on hold, the strength of a suggestion.
     *
     * @param array<string, mixed> $r a productCells row
     * @return list<string>
     */
    private static function notes(string $state, array $r): array
    {
        $notes = [];
        if ($state !== 'suggested' && isset($r['units_per_item']) && (int) $r['units_per_item'] !== 1) {
            $notes[] = Words::saleUses((int) $r['units_per_item']);
        }
        if ($state === 'matched' && ($r['status'] ?? null) === 'quarantined') {
            $notes[] = Words::BY_PRODUCT['on_hold'];
        }
        if ($state === 'suggested') {
            $notes[] = Words::of('BAND', (string) $r['band']);
        }
        return $notes;
    }

    /**
     * A cell in the CSV: its state in the page's words, then the option number of each variant it is about, each with its own
     * notes ("Matched: 4711, 4712 (1 sale = 10 products)"). The order of the variants decides nothing.
     *
     * @param array<string, mixed> $c
     */
    private static function csvCell(array $c): string
    {
        $parts = [];
        foreach ($c['items'] as $item) {
            $parts[] = $item['variant'] . ($item['notes'] === [] ? '' : ' (' . implode('; ', $item['notes']) . ')');
        }
        return Words::of('PRODUCT_STORE', $c['state']) . ($parts === [] ? '' : ': ' . implode(', ', $parts));
    }

    /**
     * How many items a filter without a text holds, from the filter entries' counts (productCoverage).
     *
     * @param array{total: int, every: int, matched: array<int, int>, suggested: int} $counts
     */
    private static function totalOf(array $counts, ?string $kind, ?int $storeId): int
    {
        return match ($kind) {
            'every' => $counts['every'],
            'missing' => max(0, $counts['total'] - ($counts['matched'][$storeId ?? 0] ?? 0)),
            'suggested' => $counts['suggested'],
            default => $counts['total'],
        };
    }

    /**
     * The picker's first search, from the item's own words: its brand and flavour when its details have them, else the first
     * words of its name. A strength or a size is left out (each website writes "20mg" its own way); the person can change the words.
     *
     * @param array<string, mixed> $sku
     */
    public static function firstSearch(array $sku): string
    {
        $words = static function (mixed $s, int $max): array {
            $out = [];
            foreach (preg_split('/[^\p{L}\p{N}]+/u', is_string($s) ? $s : '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                $lower = mb_strtolower($w);
                if (mb_strlen($w) < 2 || in_array($lower, self::UNIT_WORDS, true) || preg_match('/^\d{1,2}$|^\d+(?:mg|ml|pk|pcs|x)$|^x\d+$/D', $lower) === 1) {
                    continue;
                }
                $out[$lower] = $w;
            }
            return array_slice(array_values($out), 0, $max);
        };
        $flavour = $words($sku['flavour'] ?? null, 3);
        $picked = $flavour !== [] ? [...$words($sku['brand'] ?? null, 2), ...$flavour] : $words($sku['name'] ?? null, 4);
        return mb_substr(implode(' ', $picked), 0, ByProductContext::MAX_TEXT);
    }

    /** A variant's name as a cell shows it: its product's name and its own option name, else its option number, else "(no name)". @param array<string, mixed> $r */
    private static function title(array $r): string
    {
        $name = trim((string) ($r['product_title'] ?? '') . ' ' . (string) ($r['variant_title'] ?? ''));
        if ($name !== '') {
            return mb_substr($name, 0, 200);
        }
        $variant = self::s($r['external_variant_id'] ?? null);
        return $variant === null ? Words::LISTING['no_title'] : Words::say('QUEUE', 'option', $variant);
    }

    private static function s(mixed $v): ?string
    {
        return $v === null || $v === '' || !is_scalar($v) ? null : mb_substr((string) $v, 0, 500);
    }
}
