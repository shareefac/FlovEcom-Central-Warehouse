<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Clock;
use CW\Output\CsvWriter;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\ReservationViews;
use CW\Ui\SalesViews;
use CW\Ui\StockViews;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Stock › Sales › Orders (pack D, docs/decisions.md PD1-PD5, PD8-PD12): every order of each store, READ ONLY, for the people who look
 * at sales (sales.view). The stores and the engine (CW\Reservations) are the only ones who change an order, so this controller has
 * GET routes only and no form that posts.
 *
 *   /ui/stock/sales        the orders, newest first, for every store or one (the channel table's rows), placed in a period (the last
 *                          `sales.default_days` days unless asked), of a state (the engine's order states, or "with a unit sent,
 *                          cancelled or returned") and a way in (reservation.origin); `sales.page_size` a page, older pages by key
 *   /ui/stock/sales.csv    the same filtered list as a file (at most `sales.csv_max_rows` rows)
 *   /ui/stock/sales/{id}   one order: its facts, its sold units (a page of them) with the warehouse item and the store variant of each,
 *                          and each unit's stock changes from the ledger, each with a link to it among the item's movements
 */
final class SalesController
{
    public const PATH = '/ui/stock/sales';

    public function index(Context $ctx): HtmlResponse
    {
        $views = new SalesViews($ctx->db);
        $set = SalesViews::settings($ctx->db);
        $stores = (new ReservationViews($ctx->db))->stores();
        $f = self::filters($ctx->req, $stores, $set['days']);
        $storeIds = array_column($stores, 'id');
        $page = $views->orderIds($f['q'], $storeIds, $set['page'], $set['scan_max']);
        $names = array_column($stores, 'name', 'id');
        $rows = array_map(static fn (array $r): array => self::row($r, $names), $views->rows($page['ids']));
        $waiting = $views->waitingCounts($storeIds);
        $code = $f['store'] === null ? null : (string) $f['store']['code'];
        $keep = $f['address'];
        $query = ['channel' => $code] + $keep;
        return $ctx->page('sales_orders', [
            'f' => ['channel' => $code, 'state' => $f['q']['state'], 'origin' => $f['q']['origin'], 'from' => $f['period']['kind'] === 'range' ? $f['period']['from_day'] : null,
                'to' => $f['period']['kind'] === 'range' ? $f['period']['to_day'] : null],
            'storeItems' => ReviewController::storeItems($stores, self::PATH, $keep, $code, array_map(static fn (array $w): int => $w['orders'], $waiting), true),
            'states' => Words::SALE_STATE,
            'origins' => Words::SALE_ORIGIN,
            'periodLine' => self::periodLine($f['period'], $set['days']),
            'badDates' => $f['period']['bad'] ? Words::say('SALE', 'bad_dates', $set['days']) : null,
            'periodLinks' => [
                ['label' => Words::SALE['show_all'], 'href' => Html::url(self::PATH, ['channel' => $code, 'state' => $f['q']['state'], 'origin' => $f['q']['origin'], 'period' => 'all']),
                    'current' => $f['period']['kind'] === 'all'],
                ['label' => Words::say('SALE', 'show_default', $set['days']), 'href' => Html::url(self::PATH, ['channel' => $code, 'state' => $f['q']['state'], 'origin' => $f['q']['origin']]),
                    'current' => $f['period']['kind'] === 'default'],
            ],
            'rows' => $rows,
            'page_size' => $set['page'],
            'filtered' => $f['q']['state'] !== null || $f['q']['origin'] !== null || $f['period']['kind'] !== 'default',
            // An empty list says why: nothing at all yet (any time, no filter), or nothing in the days and filters chosen (with the way
            // to every order of the store).
            'narrowed' => $f['q']['state'] !== null || $f['q']['origin'] !== null || $f['q']['before'] !== null || $f['period']['kind'] !== 'all',
            'clearAll' => Html::url(self::PATH, ['channel' => $code, 'period' => 'all']),
            'stopped' => $page['stopped'] === null ? null : ['text' => Words::say('SALE', 'stopped', $page['read']),
                'href' => Html::url(self::PATH, $query + ['before' => $page['stopped']])],
            'clear' => Html::url(self::PATH, ['channel' => $code]),
            'csv' => Html::url(self::PATH . '.csv', $query),
            'older_link' => $page['next'] === null ? null : Html::url(self::PATH, $query + ['before' => $page['next']]),
            'newest_link' => $f['q']['before'] === null ? null : Html::url(self::PATH, $query),
        ], 200, ['title' => Words::MENU['sales_orders']]);
    }

    /** The filtered list as CSV (the same store, period, state and way in; newest first; formula-safe, CsvWriter). Times are UTC, as in every export. */
    public function csv(Context $ctx): HtmlResponse
    {
        $views = new SalesViews($ctx->db);
        $set = SalesViews::settings($ctx->db);
        $stores = (new ReservationViews($ctx->db))->stores();
        $names = array_column($stores, 'name', 'id');
        $f = self::filters($ctx->req, $stores, $set['days']);
        $csv = new CsvWriter([['store', 'text'], ['order_reference', 'text'], ['state', 'text'], ['what_became_of_it', 'text'], ['how_it_came_in', 'text'],
            ['products', 'number'], ['units', 'number'], ['items_not_linked', 'number'], ['placed_utc', 'text'], ['paid_utc', 'text'], ['ended_utc', 'text'], ['tries', 'number']]);
        $out = $views->exportOrders($f['q'], array_column($stores, 'id'), $set['csv_max'], $set['scan_max']);
        $utc = static fn (?string $t): ?string => $t === null ? null : substr($t, 0, 19);
        foreach ($out['rows'] as $r) {
            $csv->add([(string) ($names[$r['channel_id']] ?? ''), $r['ref'], Words::RESV_STATE[$r['status']], ReservationsController::outcome($r)[0],
                $r['status'] === 'committed' ? Words::SALE_ORIGIN[$r['origin']] ?? null : null, $r['products'], $r['units'], $r['unlinked'],
                $utc($r['created_at']), $utc($r['committed_at']), $utc($r['released_at']), $r['attempt']]);
        }
        if ($out['more']) {
            $csv->add([null, null, $out['stopped'] !== null ? Words::say('SALE', 'csv_stopped', $out['stopped']) : Words::say('SALE', 'csv_more', count($out['rows'])),
                null, null, null, null, null, null, null, null, null]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'store-orders.csv');
    }

    public function show(Context $ctx): HtmlResponse
    {
        $views = new SalesViews($ctx->db);
        $set = SalesViews::settings($ctx->db);
        $r = $views->rows([$ctx->id()])[0] ?? null;
        if ($r === null) {
            return $ctx->error(404, 'not_found', 'there is no such order', [self::PATH, Words::MENU['sales_orders']]);
        }
        $store = null;
        foreach ((new ReservationViews($ctx->db))->stores() as $s) {
            if ($s['id'] === $r['channel_id']) {
                $store = $s;
            }
        }
        $status = (string) $r['status'];
        $group = (string) $r['group'];
        [$outcome] = ReservationsController::outcome($r);
        $facts = [
            [Words::SALE_ORDER['f_store'], (string) ($store['name'] ?? '')],
            [Words::SALE_ORDER['f_ref'], (string) $r['ref']],
            [Words::SALE_ORDER['f_state'], Words::RESV_STATE[$status] . ($outcome === Words::RESV_STATE[$status] ? '' : ' · ' . $outcome)],
            [Words::SALE_ORDER['f_placed'], Html::when($r['created_at'])],
        ];
        if ($status === 'committed') {
            $facts[] = [Words::SALE_ORDER['f_paid'], Html::when($r['committed_at'])];
            $facts[] = [Words::SALE_ORDER['f_origin'], Words::of('RESV_ORIGIN', (string) $r['origin'])];
            if ($r['by_state']['allocated'] > 0 && $r['committed_at'] !== null) {
                $late = DispatchController::isLate((string) $r['committed_at'], $set['late_hours']);
                $facts[] = [Words::SALE_ORDER['f_waiting'], DispatchController::age((string) $r['committed_at']) . ($late ? ' (' . Words::SALE_ORDER['late'] . ')' : '')];
            }
        }
        $facts[] = [Words::SALE_ORDER['f_products'], number_format((int) $r['products'])];
        $facts[] = [Words::SALE_ORDER['f_units'], number_format((int) $r['units'])];

        $after = self::unitKey($ctx->req->param('unit'));
        $units = $views->units((int) $r['id'], (int) $r['channel_id'], $after, $set['page']);
        $warehouses = $views->warehouses();
        $unitRows = [];
        foreach ($units['rows'] as $u) {
            $unitRows[] = self::unitRow($u, $warehouses);
        }
        // Each unit's stock changes (the ledger; a unit not linked at the sale has none), grouped per unit, oldest first.
        $linked = array_values(array_map(static fn (array $u): string => (string) $u['unit_id'], array_filter($units['rows'], static fn (array $u): bool => $u['sku_id'] !== null)));
        $steps = $views->steps((int) $r['channel_id'], $linked, $set['scan_max']);
        $actors = [];
        foreach ($steps['steps'] as $list) {
            foreach ($list as $s) {
                $actors[$s['actor']] = true;
            }
        }
        $who = (new StockViews($ctx->db))->actors(array_keys($actors));
        $codes = [];
        foreach ($units['rows'] as $u) {
            if ($u['sku_id'] !== null) {
                $codes[(int) $u['sku_id']] = (string) $u['code'];
            }
        }
        $history = [];
        foreach ($units['rows'] as $u) {
            $list = [];
            foreach ($steps['steps'][(string) $u['unit_id']] ?? [] as $s) {
                $figures = [];
                foreach (['held', 'allocated', 'on_hand'] as $bucket) {
                    if ($s[$bucket] !== 0) {
                        $figures[] = Words::say('RESV', 'h_figure', Words::STOCK[$bucket], ($s[$bucket] > 0 ? '+' : '−') . number_format(abs($s[$bucket])));
                    }
                }
                $list[] = ['what' => Words::of('MOVEMENT', $s['type']), 'at' => $s['at'], 'where' => $warehouses[$s['warehouse_id']]['name'] ?? '',
                    'figures' => implode(' · ', $figures), 'who' => str_starts_with($s['actor'], 'system:') ? Words::SALE_ORDER['s_cw'] : ($who[$s['actor']] ?? $s['actor']),
                    'href' => Html::url('/ui/stock/movements', ['q' => $codes[$s['sku_id']] ?? (string) $s['sku_id'], 'figure' => 'all', 'before' => $s['last_id'] + 1])];
            }
            $history[] = ['title' => Words::say('SALE_ORDER', 'steps_unit', (string) $u['unit_id']), 'sub' => $unitRows[count($history)]['name'],
                'empty' => $u['sku_id'] === null ? Words::SALE_ORDER['steps_unlinked'] : ($list === [] ? Words::SALE_ORDER['steps_none'] : null), 'steps' => $list];
        }
        $title = Words::say('SALE_ORDER', 'title', (string) $r['ref']);
        $here = self::PATH . '/' . (int) $r['id'];
        return $ctx->page('sales_order', [
            'title' => $title,
            'tone' => Words::tone('RESV_GROUP', $group),
            'chipWord' => Words::RESV_CHIP[$group],
            'back' => Html::url(self::PATH, ['channel' => $store['code'] ?? null]),
            'facts' => $facts,
            'kept' => ReservationsController::PATH . '/' . (int) $r['id'],
            'units' => $unitRows,
            'history' => $history,
            'capped' => $steps['capped'] ? Words::say('SALE_ORDER', 'steps_capped', $set['scan_max']) : null,
            'more_units' => $units['next'] === null ? null : Html::url($here, ['unit' => $units['next']]),
            'first_units' => $after === null ? null : $here,
        ], 200, ['title' => $title]);
    }

    /**
     * One row of the list.
     *
     * @param array<string, mixed> $r ReservationViews row
     * @param array<int, string> $names store id => name
     * @return array<string, mixed>
     */
    private static function row(array $r, array $names): array
    {
        $group = (string) $r['group'];
        [$outcome, $at] = ReservationsController::outcome($r);
        return [
            'href' => self::PATH . '/' . (int) $r['id'], 'ref' => (string) $r['ref'], 'store' => (string) ($names[$r['channel_id']] ?? ''),
            'tone' => Words::tone('RESV_GROUP', $group), 'chipWord' => Words::RESV_CHIP[$group],
            'placed' => (string) $r['created_at'], 'paid' => $r['committed_at'], 'origin' => $r['status'] === 'committed' ? (Words::SALE_ORIGIN[$r['origin']] ?? null) : null,
            'units' => (int) $r['units'], 'outcome' => $outcome, 'outcome_at' => $at,
            'unlinked' => $r['unlinked'] === 0 ? null : ($r['unlinked'] === 1 ? Words::RESV['unlinked_one'] : Words::say('RESV', 'unlinked_many', (int) $r['unlinked'])),
            'try' => $r['attempt'] > 1 ? Words::say('RESV', 'try', (int) $r['attempt']) : null,
        ];
    }

    /**
     * One sold unit as the order's page shows it: the warehouse item (or the store variant, when the unit was not linked at the sale),
     * the line under it, the warehouse, the state and when it was sent.
     *
     * @param array<string, mixed> $u SalesViews unit
     * @param array<int, array{name: string, code: string}> $warehouses
     * @return array<string, mixed>
     */
    public static function unitRow(array $u, array $warehouses): array
    {
        $variant = Words::say('SALE_ORDER', 'u_variant_text', (string) ($u['variant'] ?? ''));
        $sub = $u['sku_id'] !== null ? [(string) $u['code'], $u['per_item'] > 1 ? Words::say('SALE_ORDER', 'u_per_item', $u['per_item']) : null]
            : [Words::SALE_ORDER['u_unlinked']];
        return [
            'unit' => (string) $u['unit_id'], 'sku_id' => $u['sku_id'],
            'name' => $u['name'] ?? $u['title'] ?? $variant,
            'sub' => implode(' · ', array_filter($sub, static fn (?string $t): bool => $t !== null && $t !== '')),
            'variant' => trim($variant . ($u['title'] !== null && $u['sku_id'] !== null ? ' · ' . $u['title'] : '')),
            'warehouse' => $warehouses[$u['warehouse_id']]['name'] ?? '',
            'tone' => Words::tone('RESV_UNIT', $u['state']), 'state' => Words::of('RESV_UNIT', $u['state']),
            'sent' => $u['sent_at'],
        ];
    }

    /** "Placed in the last 7 days", "Placed from 1 Oct 2026 to 7 Oct 2026", "Placed any time". @param array<string, mixed> $p SalesViews::period */
    private static function periodLine(array $p, int $days): string
    {
        if ($p['kind'] === 'all') {
            return Words::SALE['period_all'];
        }
        if ($p['kind'] === 'default') {
            return $days === 1 ? Words::SALE['period_today'] : Words::say('SALE', 'period_default', $days);
        }
        $from = $p['from_day'] === null ? null : Html::day((string) $p['from_day']);
        $to = $p['to_day'] === null ? null : Html::day((string) $p['to_day']);
        return match (true) {
            $from !== null && $to !== null => Words::say('SALE', 'period_range', $from, $to),
            $from !== null => Words::say('SALE', 'period_from', $from),
            default => Words::say('SALE', 'period_to', (string) $to),
        };
    }

    /** A unit number from the address (the store's, 1 to 64 printable characters: Reservations::id), else null. */
    private static function unitKey(?string $v): ?string
    {
        return $v !== null && preg_match('/^[\x21-\x7e]{1,64}$/D', $v) === 1 ? $v : null;
    }

    /**
     * The list's filters from the address: a store (its code), a period (`from` / `to` as UK days, or `period=all`; else the last
     * `sales.default_days` days), a state, a way in, and the order the page starts after. Anything else is ignored. `address`: the
     * values the store selector and the pager carry.
     *
     * @param list<array{id: int, code: string, name: string}> $stores
     * @return array{store: ?array{id: int, code: string, name: string}, period: array<string, mixed>, q: array{channel: ?int, state: ?string, origin: ?string, from: ?string, to: ?string, before: ?int}, address: array<string, ?string>}
     */
    private static function filters(UiRequest $req, array $stores, int $days): array
    {
        $store = ReviewController::store($stores, $req->param('channel'));
        $state = $req->param('state');
        $origin = $req->param('origin');
        $period = SalesViews::period($req->param('from'), $req->param('to'), $req->param('period') === 'all', $days, Clock::now());
        $state = in_array($state, SalesViews::STATES, true) ? $state : null;
        $origin = in_array($origin, SalesViews::ORIGINS, true) ? $origin : null;
        return [
            'store' => $store,
            'period' => $period,
            'q' => ['channel' => $store === null ? null : (int) $store['id'], 'state' => $state, 'origin' => $origin, 'from' => $period['from'], 'to' => $period['to'],
                'before' => UiRequest::id($req->param('before'))],
            'address' => ['state' => $state, 'origin' => $origin, 'from' => $period['kind'] === 'range' ? $period['from_day'] : null,
                'to' => $period['kind'] === 'range' ? $period['to_day'] : null, 'period' => $period['kind'] === 'all' ? 'all' : null],
        ];
    }
}
