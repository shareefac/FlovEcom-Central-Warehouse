<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Profit\ProfitDays;
use CW\Profit\ProfitViews;
use CW\Settings;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * The Profit section (profit step 1; docs/decisions.md PR23-PR28): read only, profit.view (the Finance job). The summary (today, yesterday,
 * the last 7 and 30 days, this month and last month), by day / week / month, by shop / product / brand / supplier, the orders (a period
 * and a store, an order number, lowest margin first) with each order's page, and the items sold with no buying price. Every figure comes
 * from CW\Profit\ProfitViews; the days are UK days; the longest period is the reports' own setting (reports.sales_max_days).
 */
final class ProfitController
{
    public const PATH = '/ui/profit';
    public const PERM = 'profit.view';

    public function summary(Context $ctx): HtmlResponse
    {
        $views = new ProfitViews($ctx->db);
        $stores = $views->stores();
        $store = self::store($ctx, $stores);
        $today = ProfitViews::today();
        $rows = array_map(static fn (array $p): array => $p + ['label' => Words::PROFIT[$p['key']],
            'no_cost_href' => Html::url(self::PATH . '/no-cost', ['period' => 'choose', 'from' => $p['from'], 'to' => $p['to'], 'store' => $store]),
            'orders_href' => Html::url(self::PATH . '/orders', ['period' => 'choose', 'from' => $p['from'], 'to' => $p['to'], 'store' => $store])],
            $views->summary($today, $store));
        return $ctx->page('profit_summary', ['rows' => $rows, 'stores' => $stores, 'storeId' => $store, 'today' => $today], 200,
            ['title' => Words::MENU['profit_summary']]);
    }

    public function periods(Context $ctx): HtmlResponse
    {
        $views = new ProfitViews($ctx->db);
        $stores = $views->stores();
        $store = self::store($ctx, $stores);
        [$period, $from, $to, $warn] = self::period($ctx);
        $by = in_array($ctx->req->param('by'), ProfitViews::GROUPS, true) ? (string) $ctx->req->param('by') : 'day';
        // The rows and the footer from ONE moment of the database (the final review of 6d59eda, c-M9, PR103).
        [$rows, $total] = ProfitDays::snapshot($ctx->db, static fn (): array => [$views->periods($from, $to, $store, $by), $views->totals($from, $to, $store)]);
        return $ctx->page('profit_periods', ['rows' => $rows, 'total' => $total, 'by' => $by,
            'groups' => ProfitViews::GROUPS, 'period' => $period, 'periods' => ReportsController::PERIODS, 'from' => $from, 'to' => $to, 'warn' => $warn,
            'stores' => $stores, 'storeId' => $store], 200, ['title' => Words::MENU['profit_periods']]);
    }

    public function breakdown(Context $ctx): HtmlResponse
    {
        $views = new ProfitViews($ctx->db);
        $stores = $views->stores();
        $store = self::store($ctx, $stores);
        [$period, $from, $to, $warn] = self::period($ctx);
        $by = in_array($ctx->req->param('by'), ProfitViews::BY, true) ? (string) $ctx->req->param('by') : 'product';
        $sort = in_array($ctx->req->param('sort'), ProfitViews::SORTS, true) ? (string) $ctx->req->param('sort') : 'profit';
        $query = ['by' => $by, 'period' => $period, 'from' => $period === 'choose' ? $from : null, 'to' => $period === 'choose' ? $to : null, 'store' => $store];
        [$result, $orderRefunds, $pending] = ProfitDays::snapshot($ctx->db, static fn (): array => [$views->breakdown($from, $to, $store, $by, $sort),
            $by === 'shop' ? 0 : $views->orderRefunds($from, $to, $store), array_sum($views->pending($from, $to, $store))]);
        $rows = array_map(static fn (array $r): array => $r + ['href' => match (true) {
            $by === 'product' && $r['id'] !== null && $r['id'] > 0 => '/ui/items/' . $r['id'],
            $by === 'shop' && $r['id'] !== null => Html::url(self::PATH . '/orders', ['period' => $query['period'], 'from' => $query['from'], 'to' => $query['to'],
                'store' => $r['id']]),
            default => null,
        }], $result['rows']);
        return $ctx->page('profit_breakdown', ['rows' => $rows, 'more' => $result['more'], 'by' => $by, 'bys' => ProfitViews::BY, 'sort' => $sort,
            'orderRefunds' => $orderRefunds,
            'pending' => $pending,
            'sorts' => ProfitViews::SORTS, 'sortLinks' => array_map(static fn (string $s): array => ['key' => $s, 'href' => Html::url(self::PATH . '/by', $query + ['sort' => $s]),
                'current' => $s === $sort], ProfitViews::SORTS),
            'period' => $period, 'periods' => ReportsController::PERIODS, 'from' => $from, 'to' => $to, 'warn' => $warn, 'stores' => $stores, 'storeId' => $store,
            'limit' => ProfitViews::ROW_LIMIT], 200, ['title' => Words::MENU['profit_breakdown']]);
    }

    public function orders(Context $ctx): HtmlResponse
    {
        $views = new ProfitViews($ctx->db);
        $stores = $views->stores();
        $store = self::store($ctx, $stores);
        [$period, $from, $to, $warn] = self::period($ctx);
        $q = trim((string) ($ctx->req->param('q') ?? ''));
        $q = preg_match('/^[\x21-\x7e]{1,64}$/D', $q) === 1 ? $q : '';
        $low = $ctx->req->param('low') === '1';
        if ($low && (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days >= ProfitViews::LOWEST_MAX_DAYS) {
            $from = (new \DateTimeImmutable($to))->modify('-' . (ProfitViews::LOWEST_MAX_DAYS - 1) . ' days')->format('Y-m-d');
            $warn = trim(($warn ?? '') . ' ' . Words::say('PROFIT', 'lowest_max', ProfitViews::LOWEST_MAX_DAYS));
        }
        $after = self::cursor($ctx, $low);
        $r = $views->orders($from, $to, $store, $q, $low, $after);
        $query = ['period' => $period, 'from' => $period === 'choose' ? $from : null, 'to' => $period === 'choose' ? $to : null, 'store' => $store, 'q' => $q === '' ? null : $q,
            'low' => $low ? '1' : null];
        $rows = array_map(static fn (array $o): array => $o + ['href' => Html::url(self::PATH . '/orders/view', ['store' => $o['store_id'], 'ref' => $o['ref']]),
            'gateway_words' => self::gateway($o['gateway'])], $r['rows']);
        return $ctx->page('profit_orders', ['rows' => $rows, 'total' => $r['total'], 'q' => $q, 'low' => $low,
            'period' => $period, 'periods' => ReportsController::PERIODS, 'from' => $from, 'to' => $to, 'warn' => $warn, 'stores' => $stores, 'storeId' => $store,
            'first_link' => $after !== null ? Html::url(self::PATH . '/orders', $query) : null,
            'next_link' => $r['next'] !== null ? Html::url(self::PATH . '/orders', $query + ['ak' => $r['next'][0], 'ac' => $r['next'][1], 'ar' => $r['next'][2]]) : null],
            200, ['title' => Words::MENU['profit_orders']]);
    }

    public function order(Context $ctx): HtmlResponse
    {
        $store = UiRequest::id($ctx->req->param('store'));
        $ref = (string) ($ctx->req->param('ref') ?? '');
        $o = $store === null || preg_match('/^[\x21-\x7e]{1,64}$/D', $ref) !== 1 ? null : (new ProfitViews($ctx->db))->order($store, $ref);
        if ($o === null) {
            return $ctx->error(404, 'not_found', 'there is no such order', [self::PATH . '/orders', Words::MENU['profit_orders']]);
        }
        $link = static fn (?string $r): ?string => $r === null ? null : Html::url(self::PATH . '/orders/view', ['store' => $store, 'ref' => $r]);
        return $ctx->page('profit_order', $o + ['parent_href' => $link($o['order']['parent_ref']), 'sub_href' => $link($o['order']['sub_of']),
            'gateway_words' => self::gateway((string) $o['order']['gateway']),
            'resend_href' => array_map(static fn (array $r): string => (string) $link((string) $r['order_ref']), $o['resends'])],
            200, ['title' => Words::say('PROFIT', 'order_title', (string) ($o['order']['order_no'] !== '' ? $o['order']['order_no'] : $o['order']['order_ref']))]);
    }

    public function noCost(Context $ctx): HtmlResponse
    {
        $views = new ProfitViews($ctx->db);
        $stores = $views->stores();
        $store = self::store($ctx, $stores);
        [$period, $from, $to, $warn] = self::period($ctx);
        [$noCost, $pending] = ProfitDays::snapshot($ctx->db, static fn (): array => [$views->noCost($from, $to, $store), array_sum($views->pending($from, $to, $store))]);
        return $ctx->page('profit_no_cost', ['rows' => $noCost, 'pending' => $pending,
            'limit' => ProfitViews::ROW_LIMIT, 'period' => $period,
            'periods' => ReportsController::PERIODS, 'from' => $from, 'to' => $to, 'warn' => $warn, 'stores' => $stores, 'storeId' => $store],
            200, ['title' => Words::MENU['profit_no_cost']]);
    }

    /** A payment way in words: the shop's code (CONTRACT change 3) named, an unknown one as it came. */
    public static function gateway(string $code): string
    {
        return $code === '' ? Words::PROFIT['gateway_none'] : (Words::PROFIT['gateway_' . $code] ?? $code);
    }

    /**
     * The page cursor of Profit by order (the last row of the page before: ak = its paid time, or its margin with low=1; ac = its store;
     * ar = its order ref); null when there is none or it is not one.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private static function cursor(Context $ctx, bool $low): ?array
    {
        $k = (string) ($ctx->req->param('ak') ?? '');
        $c = UiRequest::id($ctx->req->param('ac'));
        $r = (string) ($ctx->req->param('ar') ?? '');
        if ($low) {
            $keyOk = preg_match('/^-?[0-9]{1,18}$/D', $k) === 1;
        } else {
            // A real time, as the database writes it (the Codex review of 57d98ad, N11): anything else starts again from the first page.
            // Its shape first (a NUL or any other character never reaches the parser: the Codex re-check of 37a2cf0, R7).
            $t = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $k) === 1
                ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $k, new \DateTimeZone('UTC')) : false;
            $keyOk = $t !== false && $t->format('Y-m-d H:i:s.u') === $k;
        }
        return $keyOk && $c !== null && preg_match('/^[\x21-\x7e]{1,64}$/D', $r) === 1 ? [$k, $c, $r] : null;
    }

    /** @param list<array{id: int}> $stores */
    private static function store(Context $ctx, array $stores): ?int
    {
        $id = UiRequest::id($ctx->req->param('store'));
        return in_array($id, array_column($stores, 'id'), true) ? $id : null;
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?string} the period asked for (the reports' choices), at most reports.sales_max_days */
    private static function period(Context $ctx): array
    {
        return ReportsController::period($ctx->req, ProfitViews::today(), Settings::number($ctx->db, 'reports.sales_max_days', 366));
    }
}
