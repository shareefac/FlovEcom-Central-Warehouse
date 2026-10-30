<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Mapping\BulkDecisions;
use CW\Mapping\OneAtATime;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Queries;
use CW\Ui\StoreContext;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Products › Store Products (docs/decisions.md U109): every website product of one store (the stores are the channel table's rows)
 * with its link state: matched (to CW-…, with how many warehouse products one sale uses), suggested (with the match strength), not
 * matched, ignored, on hold, or waiting for a second OK. Search, a state filter, best sellers first by 30-day or 1-year units, 50 a
 * page. A person who may decide ticks rows to ignore them, take them off the ignored list, or send them back for matching (Bulk
 * Controller, BulkDecisions). Undoing a match stays one at a time on the product's own page, with a note: it changes a stock link.
 * A row no bulk action takes (held back, or a match of a spot check: OneAtATime, M63, U139) has no tick box and says so under
 * its name, as on the match-strength lists. A matching lead's bar also has "Create new warehouse products for these" (docs/decisions.md
 * NF1, NF7): it posts the same ticks to its preview (StoreNewItemsController), and the "Not matched" filter (no warehouse product,
 * nothing suggested) with the search finds a family for it. An old product not matched yet that sold nothing in a year is left out of
 * Not matched, Suggested and All, and of their counts; ONE quiet line at the end says how many, with "Show them" (`unsold=1`; HU1-HU4).
 */
final class StoreProductsController
{
    /** The states a row can be ticked in: the ones a store action applies to (a matched, on-hold or waiting one changes on its own page). */
    private const TICKABLE = ['suggested', 'not_matched', 'ignored'];
    /** The board's groups in order (W12.6): still to match, then waiting or held, then settled (every state of Queries::STORE_STATES). */
    private const GROUP_ORDER = ['suggested', 'not_matched', 'waiting', 'quarantined', 'linked', 'ignored', 'landing'];

    public function index(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $q = $ctx->queries();
        $me = $ctx->me();
        $stores = $q->stores();
        $store = ReviewController::store($stores, $req->param('channel')) ?? ($stores[0] ?? null);
        if ($store === null) {
            return $ctx->page('store_products', ['store' => null, 'stores' => [], 'rows' => [], 'groups' => [], 'states' => [], 'state' => null, 'sort' => 'sold_30', 'text' => '',
                'total' => 0, 'page_no' => 1, 'pages' => 1, 'prev_link' => null, 'next_link' => null, 'bulkActions' => [], 'bulkMax' => 0, 'bulkKeep' => [],
                'allLink' => '', 'clear_link' => '/ui/review/store', 'clear_search' => '/ui/review/store', 'lookOnly' => null, 'newHint' => null, 'unsold' => null, 'unsoldShown' => false], 200,
                ['title' => Words::MENU['store_products'], 'active' => 'store_products']);
        }
        $state = in_array($req->param('state'), Queries::STORE_STATES, true) ? (string) $req->param('state') : null;
        $sort = isset(Queries::STORE_SORTS[(string) $req->param('sort')]) ? (string) $req->param('sort') : 'sold_30';
        $text = mb_substr(trim($req->param('q') ?? ''), 0, 100);
        $page = UiRequest::id($req->param('page')) ?? 1;
        $counts = $q->storeStateCounts($store['id']);
        // HU3: an old product not matched yet that sold nothing in a year is left out of the two waiting states and All, and their counts,
        // unless "Show them" was chosen (`unsold=1`, which every link of the list then keeps) or the setting is off.
        $on = $q->unsoldOn();
        $showUnsold = $on && $req->param('unsold') === '1';
        $hidden = $on && !$showUnsold ? $q->unsoldHidden($store['id']) : null;
        if ($hidden !== null) {
            $counts['suggested'] = max(0, $counts['suggested'] - Queries::unsoldOfState($hidden, 'suggested'));
            $counts['not_matched'] = max(0, $counts['not_matched'] - Queries::unsoldOfState($hidden, 'not_matched'));
        }
        $result = $q->storeProducts($store['id'], $state, $text, $sort, $page, $counts, $hidden);
        $base = ['channel' => $store['code'], 'state' => $state, 'sort' => $sort === 'sold_30' ? null : $sort, 'q' => $text === '' ? null : $text,
            'unsold' => $showUnsold ? '1' : null];
        $bulkActions = ReviewController::bulkActions('store', null, $me->roles, []);
        $newItems = BulkDecisions::offered('new_item', BulkDecisions::STORE_NEW, null, $me->roles, []);
        if ($newItems) {
            $bulkActions[] = ['action' => 'new_item', 'label' => Words::STORE_NEW['button'], 'class' => 'primary', 'formaction' => StoreNewItemsController::PREVIEW];
        }
        $all = $req->param('all') === '1';
        // The rows of this page no bulk action takes (the one read of it: OneAtATime), of those a store action applies to.
        $never = OneAtATime::of($ctx->db, array_values(array_map(static fn (array $r): int => (int) $r['listing_id'],
            array_filter($result['rows'], static fn (array $r): bool => in_array((string) $r['state'], self::TICKABLE, true)))));
        // Why each landing page of the page is one (docs/decisions.md LP6): the reason of its mark, and who and when.
        $landing = $q->landingWhy(array_values(array_map(static fn (array $r): int => (int) $r['listing_id'],
            array_filter($result['rows'], static fn (array $r): bool => (string) $r['state'] === 'landing'))));
        // The list as the person has it (StoreContext): the address of a row's Open and of its Check & match fold names it, so the page opened
        // from here leads back to it (its eyebrow, its picks, its answers: docs/decisions.md CM3, W12.21).
        $back = new StoreContext((string) $store['code'], $state, $sort === 'sold_30' ? null : $sort, $text, $result['page'] > 1 ? $result['page'] : null);
        $rows = [];
        foreach ($result['rows'] as $r) {
            $st = (string) $r['state'];
            $why = ReviewController::oneAtATime($never[(int) $r['listing_id']] ?? null);
            // Only the rows a store action applies to can be ticked: matched, on hold and waiting ones change on their own page;
            // and of those not the ones that are decided one at a time.
            $pickable = $bulkActions !== [] && in_array($st, self::TICKABLE, true) && $why === null;
            $rows[] = [
                'listing_id' => (int) $r['listing_id'],
                'title' => self::s($r['product_title']),
                'variant_title' => self::s($r['variant_title']),
                'brand' => self::s($r['brand']),
                'variant' => self::s($r['external_variant_id']),
                'state' => $st,
                'units_30d' => $r['units_30d'],
                'units_365d' => $r['units_365d'],
                'sku_code' => $st === 'linked' || $st === 'quarantined' ? self::s($r['sku_code']) : null,
                'sku_name' => self::s($r['sku_name']),
                'sale_uses' => $r['sku_id'] !== null ? Words::saleUses((int) $r['units_per_item']) : null,
                'band' => $r['proposal_id'] !== null ? (string) $r['band'] : null,
                'proposed_code' => self::s($r['proposed_code']),
                'proposed_name' => self::s($r['proposed_name']),
                'proposed_new' => (bool) ($r['proposed_new_item'] ?? false),
                'pending' => $r['pending_action'] === null ? null : Words::of('ACTION', (string) $r['pending_action']),
                'landing_why' => $st === 'landing' ? self::landingWhy($landing[(int) $r['listing_id']] ?? null) : null,
                'link' => Html::url('/ui/review/listing/' . (int) $r['listing_id'], $back->query()),
                'map_version' => (int) $r['map_version'],
                'proposal_id' => $r['proposal_id'] === null ? null : (int) $r['proposal_id'],
                'pickable' => $pickable,
                'picked' => $pickable && $all,
                'manual' => $why,
                'fold' => $st === 'suggested' && ReviewController::foldOffered($why, $r['proposal_id'] !== null ? (string) $r['band'] : null, $r['proposed_code'] !== null,
                    (bool) ($r['proposed_new_item'] ?? false), $me->canDecide()),
            ];
        }
        // The rows in the board's order (GI_List, docs/decisions.md W12.6): grouped by state, what is still to match first, best sellers first
        // in each (a stable sort keeps the list's order within a group).
        $rank = array_flip(self::GROUP_ORDER);
        usort($rows, static fn (array $a, array $b): int => ($rank[$a['state']] ?? PHP_INT_MAX) <=> ($rank[$b['state']] ?? PHP_INT_MAX));
        // The Check & match fold (docs/decisions.md CM1) of a row found by the check on arrival with a Likely suggestion: its address
        // names the list as the person has it (StoreContext), and the row the board shows after it (W12.19: not the next one sold, which
        // may be in a group further down). Whether the row is clean is asked when it is opened.
        foreach ($rows as $i => $row) {
            $rows[$i]['check'] = $row['fold'] ? ReviewController::foldUrl($row['listing_id'], $back->query(), null, $rows[$i + 1]['listing_id'] ?? null) : null;
        }
        // The board's groups (GI_List, docs/decisions.md W12.6): the page's rows by their state, what is still to match first (best sellers first
        // in each), each its colour.
        $order = [];
        foreach (self::GROUP_ORDER as $st) {
            $order[$st] = [Words::tone('STORE_STATE', $st), Words::STORE_STATE[$st]];
        }
        $groups = Html::groups(array_map(static fn (array $r): array => $r + ['group' => $r['state']], $rows), $order);
        $states = [['key' => null, 'label' => Words::STORE_STATE['all'], 'count' => array_sum($counts), 'href' => Html::url('/ui/review/store', ['state' => null] + $base),
            'current' => $state === null]];
        foreach (Queries::STORE_STATES as $s) {
            if ($s === 'landing' && $counts[$s] === 0 && $state !== $s) {
                continue; // a store with no landing page marked: its page is as before (LP6)
            }
            $states[] = ['key' => $s, 'label' => Words::STORE_STATE[$s], 'count' => $counts[$s], 'href' => Html::url('/ui/review/store', ['state' => $s] + $base),
                'current' => $state === $s, 'tone' => Words::tone('STORE_STATE', $s)];
        }
        // Previous and Next: $base holds no page number, so the one added here is the one the link carries (`+` keeps the left side's
        // key: a base that already held 'page' => null lost every page number, docs/decisions.md SM8).
        return $ctx->page('store_products', [
            'store' => $store,
            'stores' => ReviewController::storeItems($stores, '/ui/review/store', [], $store['code'], $q->productsByStore(), false),
            'states' => $states,
            'groups' => $groups,
            'state' => $state,
            'sort' => $sort,
            'sorts' => array_keys(Queries::STORE_SORTS),
            'text' => $text,
            'rows' => $rows,
            'total' => $result['total'],
            'page_no' => $result['page'],
            'pages' => $result['pages'],
            'prev_link' => $result['page'] > 1 ? Html::url('/ui/review/store', $base + ['page' => $result['page'] - 1]) : null,
            'next_link' => $result['page'] < $result['pages'] ? Html::url('/ui/review/store', $base + ['page' => $result['page'] + 1]) : null,
            'bulkActions' => $bulkActions,
            'bulkMax' => BulkDecisions::maxRows($ctx->db),
            'bulkKeep' => array_filter($base + ['page' => $result['page'] > 1 ? $result['page'] : null], static fn (mixed $v): bool => $v !== null && $v !== ''),
            'allLink' => Html::url('/ui/review/store', $base + ['page' => $result['page'] > 1 ? $result['page'] : null, 'all' => 1]),
            'clear_link' => Html::url('/ui/review/store', ['channel' => $store['code']]),
            // the search alone cleared (docs/decisions.md SX5): the state and the sort stay
            'clear_search' => Html::url('/ui/review/store', array_replace($base, ['q' => null])),
            'lookOnly' => $me->canDecide() ? null : Words::whoCan('mapping.decide'),
            // the line that says how to make a family's new products, where such rows are listed (NF7)
            'newHint' => $newItems && $state === 'not_matched' ? Words::STORE_NEW['hint'] : null,
            // HU3: the one quiet line at the end of the list: how many it hides with "Show them", or, while they are shown, "Hide them".
            'unsold' => self::unsoldLine($hidden === null ? null : $result['hidden'], $showUnsold, $base),
            // ... and the search and sort form keeps "Show them" (Codex's review of d65d1f2, MEDIUM: a search or a sort dropped it)
            'unsoldShown' => $showUnsold,
        // Back from a decision (the Check & match fold or the variant's page opened from it, docs/decisions.md CM3): what was done.
        ], 200, ['title' => Words::MENU['store_products'], 'active' => 'store_products', 'notice' => (new ReviewController())->notice($ctx, null)]);
    }

    /**
     * Store Products' one quiet line (docs/decisions.md HU3; the owner's words: "N products not sold in a year are hidden. Show them"): how
     * many rows of this state and search are hidden ($hidden; nothing when none is) with the link that shows them, or, while they are shown,
     * the link that hides them again. The links are the list as it is (store, state, sort, search), page 1.
     *
     * @param array<string, scalar|null> $base
     * @return array{text: string, link: string, href: string}|null
     */
    public static function unsoldLine(?int $hidden, bool $shown, array $base): ?array
    {
        if ($shown) {
            return ['text' => Words::STORE_PRODUCTS['unsold_shown'], 'link' => Words::STORE_PRODUCTS['unsold_hide'],
                'href' => Html::url('/ui/review/store', array_replace($base, ['unsold' => null]))];
        }
        if ($hidden === null || $hidden <= 0) {
            return null;
        }
        return ['text' => $hidden === 1 ? Words::STORE_PRODUCTS['unsold_one'] : Words::say('STORE_PRODUCTS', 'unsold_many', $hidden),
            'link' => Words::STORE_PRODUCTS[$hidden === 1 ? 'unsold_show_one' : 'unsold_show'], 'href' => Html::url('/ui/review/store', array_replace($base, ['unsold' => '1']))];
    }

    /**
     * "Why: the website itself calls it a landing page. Marked on 22 Oct 2026, 10:26 by the warehouse system." (LP6)
     *
     * @param array{reason: string, by: ?string, at: string}|null $why Queries::landingWhy()'s
     */
    public static function landingWhy(?array $why): string
    {
        if ($why === null) {
            return Words::LANDING['why_unknown'];
        }
        return Words::LANDING['why_' . (isset(Words::LANDING['why_' . $why['reason']]) ? $why['reason'] : 'person')] . ' '
            . ($why['by'] === null ? Words::say('LANDING', 'marked_cw', Html::when($why['at'])) : Words::say('LANDING', 'marked_by', Html::when($why['at']), $why['by']));
    }

    private static function s(mixed $v): ?string
    {
        return $v === null || $v === '' || !is_scalar($v) ? null : mb_substr((string) $v, 0, 500);
    }
}
