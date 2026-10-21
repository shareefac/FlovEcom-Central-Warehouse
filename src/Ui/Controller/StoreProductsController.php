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
 * its name, as on the match-strength lists.
 */
final class StoreProductsController
{
    /** The states a row can be ticked in: the ones a store action applies to (a matched, on-hold or waiting one changes on its own page). */
    private const TICKABLE = ['suggested', 'not_matched', 'ignored'];

    public function index(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $q = $ctx->queries();
        $me = $ctx->me();
        $stores = $q->stores();
        $store = ReviewController::store($stores, $req->param('channel')) ?? ($stores[0] ?? null);
        if ($store === null) {
            return $ctx->page('store_products', ['store' => null, 'stores' => [], 'rows' => [], 'states' => [], 'state' => null, 'sort' => 'sold_30', 'text' => '',
                'total' => 0, 'page_no' => 1, 'pages' => 1, 'prev_link' => null, 'next_link' => null, 'bulkActions' => [], 'bulkMax' => 0, 'bulkKeep' => [],
                'allLink' => '', 'clear_link' => '/ui/review/store', 'lookOnly' => null], 200,
                ['title' => Words::MENU['store_products'], 'active' => 'store_products']);
        }
        $state = in_array($req->param('state'), Queries::STORE_STATES, true) ? (string) $req->param('state') : null;
        $sort = isset(Queries::STORE_SORTS[(string) $req->param('sort')]) ? (string) $req->param('sort') : 'sold_30';
        $text = mb_substr(trim($req->param('q') ?? ''), 0, 100);
        $page = UiRequest::id($req->param('page')) ?? 1;
        $counts = $q->storeStateCounts($store['id']);
        $result = $q->storeProducts($store['id'], $state, $text, $sort, $page, $counts);
        $base = ['channel' => $store['code'], 'state' => $state, 'sort' => $sort === 'sold_30' ? null : $sort, 'q' => $text === '' ? null : $text];
        $bulkActions = ReviewController::bulkActions('store', null, $me->roles, []);
        $all = $req->param('all') === '1';
        // The rows of this page no bulk action takes (the one read of it: OneAtATime), of those a store action applies to.
        $never = OneAtATime::of($ctx->db, array_values(array_map(static fn (array $r): int => (int) $r['listing_id'],
            array_filter($result['rows'], static fn (array $r): bool => in_array((string) $r['state'], self::TICKABLE, true)))));
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
                'link' => '/ui/review/listing/' . (int) $r['listing_id'],
                'map_version' => (int) $r['map_version'],
                'proposal_id' => $r['proposal_id'] === null ? null : (int) $r['proposal_id'],
                'pickable' => $pickable,
                'picked' => $pickable && $all,
                'manual' => $why,
                'fold' => $st === 'suggested' && ReviewController::foldOffered($why, $r['proposal_id'] !== null ? (string) $r['band'] : null, $r['proposed_code'] !== null,
                    (bool) ($r['proposed_new_item'] ?? false), $me->canDecide()),
            ];
        }
        // The Check & match fold (docs/decisions.md CM1) of a row found by the check on arrival with a Likely suggestion: its address
        // names the list as the person has it (StoreContext), and the row after it. Whether the row is clean is asked when it is opened.
        $back = new StoreContext((string) $store['code'], $state, $sort === 'sold_30' ? null : $sort, $text, $result['page'] > 1 ? $result['page'] : null);
        foreach ($rows as $i => $row) {
            $rows[$i]['check'] = $row['fold'] ? ReviewController::foldUrl($row['listing_id'], $back->query(), null, $rows[$i + 1]['listing_id'] ?? null) : null;
        }
        $states = [['key' => null, 'label' => Words::STORE_STATE['all'], 'count' => array_sum($counts), 'href' => Html::url('/ui/review/store', ['state' => null] + $base),
            'current' => $state === null]];
        foreach (Queries::STORE_STATES as $s) {
            $states[] = ['key' => $s, 'label' => Words::STORE_STATE[$s], 'count' => $counts[$s], 'href' => Html::url('/ui/review/store', ['state' => $s] + $base),
                'current' => $state === $s, 'tone' => Words::tone('STORE_STATE', $s)];
        }
        $pageQuery = $base + ['page' => null];
        return $ctx->page('store_products', [
            'store' => $store,
            'stores' => ReviewController::storeItems($stores, '/ui/review/store', [], $store['code'], $q->productsByStore(), false),
            'states' => $states,
            'state' => $state,
            'sort' => $sort,
            'sorts' => array_keys(Queries::STORE_SORTS),
            'text' => $text,
            'rows' => $rows,
            'total' => $result['total'],
            'page_no' => $result['page'],
            'pages' => $result['pages'],
            'prev_link' => $result['page'] > 1 ? Html::url('/ui/review/store', $pageQuery + ['page' => $result['page'] - 1]) : null,
            'next_link' => $result['page'] < $result['pages'] ? Html::url('/ui/review/store', $pageQuery + ['page' => $result['page'] + 1]) : null,
            'bulkActions' => $bulkActions,
            'bulkMax' => BulkDecisions::maxRows($ctx->db),
            'bulkKeep' => array_filter($base + ['page' => $result['page'] > 1 ? $result['page'] : null], static fn (mixed $v): bool => $v !== null && $v !== ''),
            'allLink' => Html::url('/ui/review/store', $base + ['page' => $result['page'] > 1 ? $result['page'] : null, 'all' => 1]),
            'clear_link' => Html::url('/ui/review/store', ['channel' => $store['code']]),
            'lookOnly' => $me->canDecide() ? null : Words::whoCan('mapping.decide'),
        // Back from a decision (the Check & match fold or the variant's page opened from it, docs/decisions.md CM3): what was done.
        ], 200, ['title' => Words::MENU['store_products'], 'active' => 'store_products', 'notice' => (new ReviewController())->notice($ctx, null)]);
    }

    private static function s(mixed $v): ?string
    {
        return $v === null || $v === '' || !is_scalar($v) ? null : mb_substr((string) $v, 0, 500);
    }
}
