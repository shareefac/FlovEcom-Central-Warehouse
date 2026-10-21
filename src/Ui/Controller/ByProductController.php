<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Mapping\BulkDecisions;
use CW\Mapping\DecisionService;
use CW\Mapping\OneAtATime;
use CW\Mapping\Proposals;
use CW\Matching\Gtin;
use CW\Output\CsvWriter;
use CW\Settings;
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
 *
 * Since M55 / U123-U130 (the owner's "bulk preview and update option ... need more item ... based on selection"): a page shows 50,
 * 100 or 200 items (the setting mapping.by_item_page_size, or the person's choice `n`), and a person who may decide ticks items
 * and presses an action of the bar under the list, for one store or all of them. The list only draws that form (each ticked row
 * sends the suggested variants it showed, every one of them: `cells_<item>`); ByItemBulkController shows the preview and applies
 * it. A cell whose every suggestion was marked "not a match" for the item before says so (M59).
 *
 * The quick match (the owner's request of 20 Oct 2026: "directly add dropdown with full details, need search all attribute for
 * product"): in a cell of a store that has no variant for the item, "Match…" is a fold with the picker's search box. Without app.js
 * its Search opens the picker (a GET form of its own after the list: the list sits in the bulk form, and a form inside a form is no
 * form). With it the fold shows options(): the picker's two lists as a fragment, each variant with its details and a "Match to …"
 * form that posts to the variant's own decide route (ReviewController::decide) with the way back to the row (ByProductContext): the
 * decision, its rules and its refusals stay there, and a refusal lands on the variant's page with its words. A variant decided one
 * at a time (OneAtATime) or one only a matching lead may decide gets "Choose" (its page) instead. Still no POST of this class.
 */
final class ByProductController
{
    /** The most items a CSV holds (every item of the filter: the page shows 50 to 200 of them). */
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
                'prev_link' => null, 'next_link' => null, 'clear_link' => ByProductContext::LIST_PATH, 'clear_search' => ByProductContext::LIST_PATH, 'csv_link' => '', 'filtered' => false, 'lookOnly' => $lookOnly,
                'per_page' => null, 'sizes' => [], 'manualNote' => false, 'bulkActions' => [], 'bulkKeep' => [], 'bulkWords' => [], 'bulkChoice' => null, 'bulkMax' => 0, 'allLink' => ''],
                200, $layout);
        }
        $cov = ByProductContext::coverage($req->param('cov'), $stores);
        if ($cov['unknown']) {
            return $ctx->error(404, 'unknown_store', 'no such store', [ByProductContext::LIST_PATH, Words::MENU['by_product']]);
        }
        $text = ByProductContext::text($req->param('q') ?? '');
        // How many items a page shows (U123): the number the person chose on the page (`n`), else the setting's. The links carry
        // `n` only when it is not the setting's, so a list nobody resized keeps its plain addresses.
        $default = self::pageSize($ctx->db);
        $per = ByProductContext::pageSize($req->param('n')) ?? $default;
        $n = $per === $default ? null : $per;
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
        $pages = max(1, (int) ceil($total / $per));
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
            $page = intdiv($before, $per) + 1;
        }
        $page = min(max(1, $page ?? 1), $pages);
        $offset = ($page - 1) * $per;
        $ids = $onePass ? array_slice($all, $offset, $per) : $q->productIds($cov['kind'], $storeId, $ordered, $text, $per, $offset);
        // That item's row is marked; when the filter no longer holds it (it is matched now), it is still shown, first.
        $pinned = $at !== null && !in_array($at, $ids, true);
        $products = $q->productRows($pinned ? [$at, ...$ids] : $ids);
        if ($pinned) {
            usort($products, static fn (array $a, array $b): int => [(int) $a['id'] !== $at, (int) $a['id']] <=> [(int) $b['id'] !== $at, (int) $b['id']]);
        }
        $base = ['cov' => $cov['value'], 'q' => $text === '' ? null : $text, 'n' => $n];
        $cells = self::marked($ctx->db, $q->productCells($pinned ? [$at, ...$ids] : $ids));
        $rows = self::rows($products, $stores, $cells, $me->canDecide(), $cov['value'], $text, $n);
        // The bulk action (M55, U124): the actions this person may take on the suggested variants of ticked items. A row can be
        // ticked when a store suggests a variant for its item; it sends back what the page showed of each (the variant, its
        // suggestion and its version: `cells_<item>`), so the preview and the apply know what the person saw. EVERY suggestion
        // the row shows is sent, never a part of them: an item with more than one bulk action can ever take has no tick box.
        $actions = [];
        if ($me->canDecide()) {
            $bands = BulkDecisions::confirmBands($ctx->db);
            foreach (BulkDecisions::ITEM_ACTIONS as $a) {
                if (BulkDecisions::offered($a, 'item', null, $me->roles, $bands)) {
                    $actions[] = ['action' => $a, 'label' => Words::BY_ITEM_BULK[$a], 'class' => in_array($a, BulkDecisions::NEGATIVE, true) ? 'secondary' : 'primary'];
                }
            }
        }
        $shown = [];
        $live = [];
        foreach ($cells['suggested'] as $r) {
            $shown[(int) $r['sku_id']][] = (int) $r['listing_id'] . '.' . (int) $r['proposal_id'] . '.' . (int) $r['map_version'];
            $live[(int) $r['sku_id']] = ($live[(int) $r['sku_id']] ?? 0) + ($r['rejected'] ? 0 : 1);
        }
        // Ticks drawn by the page itself: "tick all on this page" without the script (`all=1`), and the way back from a preview
        // (`ticked`, with the store that was chosen: `on`).
        $tickAll = $req->param('all') === '1';
        $ticked = array_fill_keys(ByProductContext::ticked($req->param('ticked')), true);
        $on = ByProductContext::store($stores, $req->param('on'));
        foreach ($rows as $i => $r) {
            $rows[$i]['here'] = $r['id'] === $at;
            $rows[$i]['pinned'] = $pinned && $r['id'] === $at;
            $own = $actions === [] ? [] : ($shown[$r['id']] ?? []);
            $rows[$i]['too_many'] = count($own) > ByItemBulkController::MAX_PER_ITEM ? count($own) : null;
            $rows[$i]['cells_field'] = $own === [] || $rows[$i]['too_many'] !== null ? null : implode(' ', $own);
            // "Tick all" leaves an item whose every suggestion was marked "not a match" before to the person (M59): it can still
            // be ticked by hand (to send that suggestion back for matching, say).
            $rows[$i]['manual'] = $rows[$i]['cells_field'] !== null && ($live[$r['id']] ?? 0) === 0;
            $rows[$i]['ticked'] = $rows[$i]['cells_field'] !== null && (($tickAll && !$rows[$i]['manual']) || isset($ticked[$r['id']]));
        }
        $sizes = [];
        foreach (ByProductContext::PAGE_SIZES as $size) {
            // Another number of rows keeps the place: the page of that size that holds this page's first item.
            $to = intdiv($offset, $size) + 1;
            $sizes[] = ['size' => $size, 'current' => $size === $per,
                'href' => Html::url(ByProductContext::LIST_PATH, ['n' => $size === $default ? null : $size, 'page' => $to > 1 ? $to : null] + $base)];
        }
        $choice = [['value' => '', 'label' => Words::BY_ITEM_BULK['all_stores']]];
        foreach ($stores as $s) {
            $choice[] = ['value' => (string) $s['code'], 'label' => (string) $s['name']];
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
            'clear_link' => Html::url(ByProductContext::LIST_PATH, ['n' => $n]),
            // the search alone cleared (docs/decisions.md SX5): the filter and the number of rows stay
            'clear_search' => Html::url(ByProductContext::LIST_PATH, ['q' => null] + $base),
            'csv_link' => Html::url(ByProductContext::LIST_PATH . '.csv', ['n' => null] + $base),
            'filtered' => $cov['kind'] !== null || $text !== '',
            'lookOnly' => $lookOnly,
            // The person's own number of rows, when it is not the setting's: the search form keeps it.
            'per_page' => $n,
            'sizes' => $sizes,
            'bulkActions' => $actions,
            'bulkKeep' => array_filter($base + ['page' => $page > 1 ? $page : null], static fn (mixed $v): bool => $v !== null && $v !== ''),
            'bulkWords' => ['bar' => Words::BY_ITEM_BULK['bar'], 'none' => Words::BY_ITEM_BULK['selected_none'], 'one' => Words::BY_ITEM_BULK['selected_one'],
                'many' => Words::BY_ITEM_BULK['selected_many'], 'too_many' => Words::BY_ITEM_BULK['too_many']],
            'bulkChoice' => ['name' => 'on', 'label' => Words::BY_ITEM_BULK['on'], 'options' => $choice, 'selected' => $on === null ? '' : (string) $on['code']],
            'bulkMax' => BulkDecisions::maxRows($ctx->db),
            'manualNote' => array_filter($rows, static fn (array $r): bool => $r['manual']) !== [],
            'allLink' => Html::url(ByProductContext::LIST_PATH, $base + ['page' => $page > 1 ? $page : null, 'all' => 1]),
        ], 200, $layout);
    }

    /** How many items a page shows unless the person chose (setting mapping.by_item_page_size, read now; 50 on a schema before 0025). */
    public static function pageSize(Db $db): int
    {
        $n = Settings::number($db, 'mapping.by_item_page_size', Queries::PER_PAGE);
        return in_array($n, ByProductContext::PAGE_SIZES, true) ? $n : Queries::PER_PAGE;
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
            foreach (self::rows($q->productRows($chunk), $stores, self::marked($ctx->db, $q->productCells($chunk, false)), false, null, '') as $r) {
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
        $subject = self::subject($ctx, $id);
        if ($subject instanceof HtmlResponse) {
            return $subject;
        }
        ['sku' => $sku, 'store' => $store, 'bp' => $bp] = $subject;
        $barcodes = $q->barcodesOfMany([$id])[$id] ?? [];
        $page = min(UiRequest::id($req->param('page')) ?? 1, self::PICK_MAX_PAGE);
        $c = self::choices($ctx, $id, $sku, $store, $bp, $barcodes, $req->param('q'), $page);
        // What the store holds for the item now: a second variant can still be matched (one item on two pages).
        $now = self::rows([['id' => $id, 'code' => $sku['code'], 'name' => $sku['name'], 'brand' => $sku['brand']]], [$store], self::marked($ctx->db, $q->productCells([$id])),
            false, null, '')[0]['cells'][0];
        // Previous and Next always name the search they page through: an emptied search too, which a link built from its values
        // alone would lose (the next page would fall back to the first search).
        $pager = $bp->withPicker($c['text'], 1);
        return $ctx->page('by_product_map', [
            'sku' => ['id' => $id, 'code' => self::s($sku['code']), 'name' => self::s($sku['name']), 'brand' => self::s($sku['brand']),
                'strength' => $sku['strength_mg'] === null ? null : Html::dec($sku['strength_mg']), 'size' => $sku['volume_ml'] === null ? null : Html::dec($sku['volume_ml']),
                'flavour' => self::s($sku['flavour']), 'line' => self::s($sku['line'])],
            'barcodes' => $barcodes,
            'store' => ['code' => $bp->channel, 'name' => $bp->channelName],
            'now' => $now,
            'suggested' => $c['suggested'],
            'found' => $c['found'],
            'ran' => $c['ran'],
            'text' => $c['text'],
            'keep' => ['channel' => $bp->channel, 'cov' => $bp->coverage, 'lq' => $bp->text === '' ? null : $bp->text, 'ln' => $bp->rows],
            'action' => ByProductContext::LIST_PATH . '/' . $id . '/map',
            'page_no' => $page,
            'prev_link' => $c['ran'] && $page > 1 ? $pager->pickerUrl($page - 1) : null,
            'next_link' => $c['more'] && $page < self::PICK_MAX_PAGE ? $pager->pickerUrl($page + 1) : null,
            'back' => $bp->listUrl(),
        ], 200, ['title' => Words::say('BY_PRODUCT', 'pick_title', (string) $sku['code'], $bp->channelName), 'active' => 'by_product', 'notice' => null]);
    }

    /**
     * The quick match of a cell (GET …/map/options, read only, for whoever may open the picker): the picker's two lists of ONE store
     * for ONE item as a fragment for app.js, without the layout, at most the picker's first page. No `q` is the picker's plain opening
     * (the suggestions; the item's own words only when nothing is suggested), a `q` the person's search (an empty one: the suggestions
     * and the store's best sellers). Each variant carries its details and, unless OneAtATime names it or its clues disagree and the
     * person is no matching lead (the variant's page would not let them answer at once), a "Match to …" form: every field the decide
     * route needs (the variant's map_version, its open suggestion, the units the suggestion gives for THIS item, else 1) and the way
     * back to the row (ByProductContext, with the person's search for Back from the variant's page). An error is the picker's page
     * in words (app.js shows its own line and the way to the picker for anything that is not the fragment).
     */
    public function options(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $subject = self::subject($ctx, $id);
        if ($subject instanceof HtmlResponse) {
            return $subject;
        }
        ['sku' => $sku, 'store' => $store, 'bp' => $bp] = $subject;
        $typed = $ctx->req->param('q');
        $c = self::choices($ctx, $id, $sku, $store, $bp, $ctx->queries()->barcodesOfMany([$id])[$id] ?? [], $typed, 1);
        $lead = $ctx->me()->isLead();
        // One read for every row of the fragment: the variants no one answers from a list (OneAtATime: held back, a spot check's
        // match, found on arrival), with the line the lists say for them. Every row still waits for a match (suggestedFor and
        // waitingListings), so ofWaiting() answers in the same few statements however many spot checks the rows are in.
        $once = OneAtATime::ofWaiting($ctx->db, array_column([...$c['suggested'], ...$c['found']], 'listing_id'));
        $mark = static function (array $r) use ($once, $lead): array {
            $why = ReviewController::oneAtATime($once[$r['listing_id']] ?? null);
            $r['why'] = $why !== null ? ['text' => $why['text'], 'link' => $why['link']]
                : ($r['open_band'] === 'Conflict' && !$lead ? ['text' => Words::LISTING['lead_only'], 'link' => null] : null);
            $r['match'] = $r['why'] === null;
            return $r;
        };
        $suggested = array_map($mark, $c['suggested']);
        $found = array_map($mark, $c['found']);
        $opening = $typed === null;
        $notes = [];
        if (!$c['ran']) {
            $notes[] = Words::BY_PRODUCT['quick_search_first'];
        } else {
            if ($opening && $c['text'] !== '') {
                $notes[] = Words::say('BY_PRODUCT', 'quick_first', $c['text']);
            }
            if ($found === []) {
                $notes[] = $suggested === [] ? Words::BY_PRODUCT['pick_found_none'] : Words::BY_PRODUCT['pick_found_no_more'];
            }
        }
        return $ctx->fragment('by_product_options', [
            'sku' => ['id' => $id, 'code' => (string) $sku['code']],
            'store' => ['code' => $bp->channel, 'name' => $bp->channelName],
            'lists' => [
                ['id' => 'suggested', 'title' => $suggested === [] ? null : Words::BY_PRODUCT['pick_suggested'],
                    'notes' => $suggested === [] && $opening ? [Words::BY_PRODUCT['quick_suggested_none']] : [], 'rows' => $suggested],
                ['id' => 'found', 'title' => $c['ran'] ? Words::say('BY_PRODUCT', 'pick_search', $bp->channelName) : null, 'notes' => $notes, 'rows' => $found],
            ],
            // The way back from the variant's page after a decision is the list at this row; Back on that page is the picker with
            // the person's search (none on a plain opening).
            'back' => $bp->withPicker($opening ? null : $c['text'], 1)->query(),
            'more' => $c['more'] ? $bp->withPicker($c['text'], 1)->pickerUrl() : null,
            'max_units' => DecisionService::MAX_UNITS,
        ]);
    }

    /**
     * The item and the store a picker or a quick match is about (`{id}`, `channel`), with the context it carries (the list's filter,
     * search and rows); else the page that says why in words: an item that is not one or was joined into another one, a store that
     * is not one.
     *
     * @return array{sku: array<string, mixed>, store: array{id: int, code: string, name: string}, bp: ByProductContext}|HtmlResponse
     */
    private static function subject(Context $ctx, int $id): array|HtmlResponse
    {
        $req = $ctx->req;
        $q = $ctx->queries();
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
            ByProductContext::coverage($req->param('cov'), $stores)['value'], ByProductContext::text($req->param('lq') ?? ''), null, null,
            ByProductContext::pageSize($req->param('ln')));
        return ['sku' => $sku, 'store' => $store, 'bp' => $bp];
    }

    /**
     * The variants of the store to choose from for the item: the ones the computer suggests for it (the strongest first), then, when
     * the search runs, a page of the store's waiting variants with every word (Queries::waitingListings; not the suggested ones
     * again). The first search comes from the item's own words; once the person has searched (`q`, also an empty one) it is theirs.
     * Each row is a variant as the picker and the quick match show it, in one read of their profiles (Queries::pickerRows) and one
     * of the item's barcodes (given).
     *
     * @param array<string, mixed> $sku
     * @param array{id: int, code: string, name: string} $store
     * @param list<string> $barcodes the item's own
     * @return array{suggested: list<array<string, mixed>>, found: list<array<string, mixed>>, ran: bool, text: string, more: bool}
     */
    private static function choices(Context $ctx, int $id, array $sku, array $store, ByProductContext $bp, array $barcodes, ?string $typed, int $page): array
    {
        $q = $ctx->queries();
        $searched = $typed !== null;
        $text = $searched ? ByProductContext::text($typed) : self::firstSearch($sku);
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
            $codes = Html::strings(Html::json($r['barcodes']));
            $theirs = array_map($key, $codes);
            // A suggestion of THIS item gives its strength and its units; another item's is only "Suggested".
            $mine = $r['proposed_sku_id'] !== null && (int) $r['proposed_sku_id'] === $id;
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
                'band' => $r['band'] !== null && $mine ? (string) $r['band'] : null,
                'barcode' => $own === [] || $theirs === [] ? 'unknown' : (array_intersect($own, $theirs) !== [] ? 'same' : 'differs'),
                'barcodes' => array_values(array_filter(array_map(static fn (string $b): ?string => self::s($b), array_slice($codes, 0, 10)))),
                'facts' => self::facts($r),
                'choose' => Html::url('/ui/review/listing/' . $lid, $choose),
                // What the quick match's "Match to …" sends, as the variant's page would: its version, its open suggestion (of any
                // item: the decision must name it, I7) and how many one sale uses.
                'map_version' => (int) $r['map_version'],
                'proposal_id' => $r['proposal_id'] === null ? null : (int) $r['proposal_id'],
                'open_band' => $r['band'] === null ? null : (string) $r['band'],
                'units' => $mine && $r['ai_units_per_item'] !== null ? max(1, min(DecisionService::MAX_UNITS, (int) $r['ai_units_per_item'])) : 1,
            ];
        };
        $suggested = array_values(array_filter(array_map($row, $suggestedIds)));
        usort($suggested, static fn (array $a, array $b): int => [array_search($a['band'], Proposals::BANDS, true), $a['listing_id']]
            <=> [array_search($b['band'], Proposals::BANDS, true), $b['listing_id']]);
        return ['suggested' => $suggested, 'found' => array_values(array_filter(array_map($row, $foundIds))), 'ran' => $ran, 'text' => $text, 'more' => $found['more']];
    }

    /**
     * What the matching read from a variant (its features), as the variant's page shows a new item's details (DecisionService::cardFrom):
     * the strength, the size, the flavour and the range where they are known, by their field (Words::FIELD), in that order.
     *
     * @param array<string, mixed> $r a Queries::pickerRows row
     * @return array<string, string>
     */
    private static function facts(array $r): array
    {
        try {
            $card = DecisionService::cardFrom(['product_title' => $r['product_title'], 'variant_title' => $r['variant_title'], 'brand' => $r['brand']],
                Html::json($r['features']));
        } catch (CwException) {
            return []; // no name at all: nothing the matching read either
        }
        $out = [];
        foreach (['strength_mg', 'volume_ml', 'flavour', 'line'] as $field) {
            $v = $card[$field] ?? null;
            $v = is_float($v) || is_int($v) ? Html::dec($v) : self::s($v);
            if ($v !== null && $v !== '') {
                $out[$field] = mb_substr($v, 0, 200);
            }
        }
        return $out;
    }

    /**
     * The page's rows: each item with its parent product's name and one cell per store, in the stores' order. A cell's state is the
     * first of matched, waiting for a second OK, suggested, none that the store has for the item; its title is the variant it names
     * (the best seller of the matched ones on the page, the strongest suggestion), `more` how many others of that state there are,
     * `also_…` the other states the store has for it as well, and `items` every variant of the state with its OWN notes (units per
     * sale, on hold, the suggestion's strength): the CSV writes those, so it never borrows one variant's note for another. `chip` is
     * the word the cell reads: its state, or "Marked not a match" for a suggested cell whose every suggestion was said no to for
     * the item before (marked(), M59; such a suggestion comes after the others and carries that note in the CSV).
     *
     * The parent product (U122) is the product name of the item's best-selling matched variant on any store (units of a year; a
     * tie: the lowest listing id), left out when that name is empty or is the item's own name.
     *
     * @param list<array<string, mixed>> $products
     * @param list<array{id: int, code: string, name: string}> $stores
     * @param array{matched: list<array<string, mixed>>, suggested: list<array<string, mixed>>, waiting: list<array<string, mixed>>} $cells
     * @param int|null $n how many items the person's list shows, when it is not the setting's (the links to a variant's page carry it)
     * @return list<array<string, mixed>>
     */
    private static function rows(array $products, array $stores, array $cells, bool $canDecide, ?string $coverage, string $text, ?int $n = null): array
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
            foreach ($stores as $si => $s) {
                $has = $by[$id][(int) $s['id']] ?? [];
                $suggested = $has['suggested'] ?? [];
                // The strongest suggestion first; one whose pair was marked "not a match" before comes after the others (M59).
                usort($suggested, static fn (array $a, array $b): int => [(bool) ($a['rejected'] ?? false), array_search($a['band'], Proposals::BANDS, true), (int) $a['listing_id']]
                    <=> [(bool) ($b['rejected'] ?? false), array_search($b['band'], Proposals::BANDS, true), (int) $b['listing_id']]);
                $groups = ['matched' => $has['matched'] ?? [], 'waiting' => $has['waiting'] ?? [], 'suggested' => $suggested];
                $state = 'none';
                foreach ($groups as $name => $list) {
                    if ($list !== []) {
                        $state = $name;
                        break;
                    }
                }
                $first = $state === 'none' ? null : $groups[$state][0];
                $bp = new ByProductContext($id, (string) $s['code'], (int) $s['id'], (string) $s['name'], $coverage, $text, null, null, $n);
                $own = $first === null ? null : '/ui/review/listing/' . (int) $first['listing_id'];
                // A suggestion is answered on the variant's own page; from here that page leads back here.
                $review = $state === 'suggested' && $canDecide ? Html::url((string) $own, $bp->query()) : null;
                $units = $first !== null && isset($first['units_per_item']) ? (int) $first['units_per_item'] : 1;
                $on += $state === 'matched' ? 1 : 0;
                $out[] = [
                    'store' => (string) $s['name'],
                    'state' => $state,
                    // The word of the cell: a suggested cell whose every suggestion was marked "not a match" before says so.
                    'chip' => $state === 'suggested' && array_filter($suggested, static fn (array $r): bool => !($r['rejected'] ?? false)) === [] ? 'rejected' : $state,
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
                    'channel' => (string) $s['code'],
                    // ... and where the store has nothing for the item, the quick match: the fold's search box (filled in with the
                    // item's own words, as the picker's first search), the GET form it names (drawn after the list, outside the bulk
                    // form), and the fragment app.js shows in the cell.
                    'quick' => $canDecide && $state === 'none' ? [
                        'form' => 'qp-' . $id . '-' . $si,
                        'action' => ByProductContext::LIST_PATH . '/' . $id . '/map',
                        'keep' => ['channel' => (string) $s['code'], 'cov' => $coverage, 'lq' => $text === '' ? null : $text, 'ln' => $n],
                        'options' => $bp->optionsUrl(),
                        'text' => self::firstSearch($p),
                        'label' => Words::say('BY_PRODUCT', 'quick_label', (string) $s['name'], (string) $p['code']),
                    ] : null,
                ];
            }
            $name = self::s($p['name']);
            $parent = $parents[$id][1] ?? '';
            $rows[] = ['id' => $id, 'code' => self::s($p['code']), 'name' => $name, 'brand' => self::s($p['brand']), 'family' => self::s($p['family_name'] ?? null),
                'parent' => $parent === '' || mb_strtolower($parent) === mb_strtolower(trim((string) $name)) ? null : mb_substr($parent, 0, 200),
                'cells' => $out, 'on' => $on, 'tone' => $on === count($stores) ? 'done' : ($on > 0 ? 'needs' : 'waiting'), 'here' => false, 'pinned' => false];
        }
        return $rows;
    }

    /**
     * The cells' suggested rows, each marked `rejected` when its variant was marked "not a match" for that item before (M59,
     * BulkDecisions::rejectedBefore: one read of match_reject by its unique index, for the suggestions of the page).
     *
     * @param array{matched: list<array<string, mixed>>, suggested: list<array<string, mixed>>, waiting: list<array<string, mixed>>} $cells
     * @return array{matched: list<array<string, mixed>>, suggested: list<array<string, mixed>>, waiting: list<array<string, mixed>>}
     */
    private static function marked(Db $db, array $cells): array
    {
        $pairs = [];
        foreach ($cells['suggested'] as $r) {
            $pairs[(int) $r['listing_id']] = (int) $r['sku_id'];
        }
        $rejected = $pairs === [] ? [] : BulkDecisions::rejectedBefore($db, $pairs);
        foreach ($cells['suggested'] as $i => $r) {
            $cells['suggested'][$i]['rejected'] = isset($rejected[(int) $r['listing_id']]);
        }
        return $cells;
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
            if (($r['rejected'] ?? false) === true) {
                $notes[] = Words::PRODUCT_STORE['rejected'];
            }
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
        return Words::of('PRODUCT_STORE', $c['chip']) . ($parts === [] ? '' : ': ' . implode(', ', $parts));
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
