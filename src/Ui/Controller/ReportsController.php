<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Clock;
use CW\Output\CsvWriter;
use CW\Reports\ReportRefresh;
use CW\Reports\ReportViews;
use CW\Settings;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * The Reports tabs (pack E; docs/decisions.md PE1, PE8-PE11): Stock by Store, Sales by Store, Low Stock, Stock Value (money: values.view)
 * and Slow Stock, each a read-only page and the same list as a CSV (FilesController::download, the CsvWriter's safe cells). The
 * figures: Reports\ReportViews; the numbers that decide a list (the low level, the slow and dead days, the longest period) are
 * settings (reports.*), read on every request. Nothing here writes.
 */
final class ReportsController
{
    /** path slug => the permission its page and CSV need (Ui\Kernel adds both routes; Ui\Sections names the same permission). */
    public const PAGES = [
        'stock-by-store' => 'reports.view',
        'sales-by-store' => 'reports.view',
        'low-stock' => 'reports.view',
        'stock-value' => 'values.view',
        'slow-stock' => 'reports.view',
    ];
    /** The periods of Sales by Store (`period`), besides "choose the days". */
    public const PERIODS = ['last_7', 'last_30', 'this_month', 'last_month'];

    public function page(Context $ctx): HtmlResponse
    {
        return match (self::slug($ctx)) {
            'stock-by-store' => $this->stockByStore($ctx, false),
            'sales-by-store' => $this->salesByStore($ctx, false),
            'low-stock' => $this->lowStock($ctx, false),
            'stock-value' => $this->stockValue($ctx, false),
            default => $this->slowStock($ctx, false),
        };
    }

    public function csv(Context $ctx): HtmlResponse
    {
        return match (self::slug($ctx)) {
            'stock-by-store' => $this->stockByStore($ctx, true),
            'sales-by-store' => $this->salesByStore($ctx, true),
            'low-stock' => $this->lowStock($ctx, true),
            'stock-value' => $this->stockValue($ctx, true),
            default => $this->slowStock($ctx, true),
        };
    }

    private function stockByStore(Context $ctx, bool $csv): HtmlResponse
    {
        $req = $ctx->req;
        $views = new ReportViews($ctx->db);
        $stores = $views->stores();
        $figures = $views->storeFigures($stores);
        $id = UiRequest::id($req->param('store'));
        $store = null;
        foreach ($stores as $s) {
            if ($store === null || $s['id'] === $id) {
                $store = $s;
            }
            if ($s['id'] === $id) {
                break;
            }
        }
        $f = ['store' => $store['id'] ?? null, 'q' => self::text($req->param('q')),
            'show' => in_array($req->param('show'), ReportViews::STORE_SHOW, true) ? (string) $req->param('show') : 'all',
            'page' => UiRequest::id($req->param('page')) ?? 1];
        $result = $store === null ? ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1]
            : $views->storeItems($store['id'], $store['warehouse_id'], $f['q'], $f['show'], $f['page'], $csv ? null : ReportViews::PAGE);
        if ($csv) {
            $out = new CsvWriter([['store', 'text'], ['cw_code', 'text'], ['product', 'text'], ['brand', 'text'], ['warehouse_units_it_can_sell', 'number'],
                ['units_per_sale', 'number'], ['website_uses_its_own_stock', 'text']]);
            foreach ($result['rows'] as $r) {
                $out->add([$store['name'] ?? '', (string) $r['code'], (string) $r['name'], $r['brand'] === null ? null : (string) $r['brand'], (int) $r['available'],
                    (int) $r['pack'], $r['sell_policy'] === 'legacy' ? 'yes' : 'no']);
            }
            return FilesController::download($out->output(), 'text/csv; charset=utf-8', 'stock-by-store-' . ($store['code'] ?? 'none') . '.csv');
        }
        $query = ['store' => $f['store'], 'q' => $f['q'], 'show' => $f['show'] === 'all' ? null : $f['show']];
        return $ctx->page('report_store', [
            'f' => $f,
            'stores' => array_map(static fn (array $s): array => $s + ['figures' => $figures[$s['id']]], $stores),
            'store' => $store,
            'rows' => array_map(static fn (array $r): array => ['sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
                'brand' => $r['brand'] === null ? null : (string) $r['brand'], 'available' => (int) $r['available'], 'pack' => (int) $r['pack'],
                'policy' => (string) $r['sell_policy']], $result['rows']),
            'total' => $result['total'], 'page_no' => $result['page'], 'pages' => $result['pages'],
            'filtered' => $f['q'] !== '' || $f['show'] !== 'all',
            'csv_link' => Html::url('/ui/reports/stock-by-store.csv', $query),
            'clear_link' => Html::url('/ui/reports/stock-by-store', ['store' => $f['store']]),
            'prev_link' => $result['page'] > 1 ? Html::url('/ui/reports/stock-by-store', $query + ['page' => $result['page'] - 1]) : null,
            'next_link' => $result['page'] < $result['pages'] ? Html::url('/ui/reports/stock-by-store', $query + ['page' => $result['page'] + 1]) : null,
        ], 200, ['title' => Words::MENU['stock_by_store']]);
    }

    private function salesByStore(Context $ctx, bool $csv): HtmlResponse
    {
        $req = $ctx->req;
        $views = new ReportViews($ctx->db);
        $stores = $views->stores();
        $max = Settings::number($ctx->db, 'reports.sales_max_days', 366);
        $today = ReportRefresh::ukDay(Clock::db(Clock::now()));
        [$period, $from, $to, $warn] = self::period($req, $today, $max);
        $storeId = UiRequest::id($req->param('store'));
        $storeId = in_array($storeId, array_column($stores, 'id'), true) ? $storeId : null;
        $sales = $views->sales($from, $to, $storeId);
        $zero = ['orders' => 0, 'units_sold' => 0, 'units_sent' => 0, 'units_cancelled' => 0, 'units_returned' => 0];
        $byStore = [];
        $total = $zero;
        foreach ($stores as $s) {
            $x = $sales['stores'][$s['id']] ?? $zero;
            $byStore[] = ['id' => $s['id'], 'name' => $s['name']] + $x;
            foreach ($x as $k => $v) {
                $total[$k] += $v;
            }
        }
        if ($csv) {
            $out = new CsvWriter([['day', 'text'], ['store', 'text'], ['orders', 'number'], ['units_sold', 'number'], ['units_sent', 'number'],
                ['units_cancelled', 'number'], ['units_returned', 'number']]);
            $name = $storeId === null ? Words::REPORTS['all_stores'] : (string) (array_column($stores, 'name', 'id')[$storeId] ?? '');
            foreach ($sales['days'] as $d) {
                $out->add([$d['day'], $name, $d['orders'], $d['units_sold'], $d['units_sent'], $d['units_cancelled'], $d['units_returned']]);
            }
            return FilesController::download($out->output(), 'text/csv; charset=utf-8', "sales-by-store-{$from}-{$to}.csv");
        }
        $query = ['period' => $period, 'from' => $period === 'choose' ? $from : null, 'to' => $period === 'choose' ? $to : null, 'store' => $storeId];
        return $ctx->page('report_sales', [
            'period' => $period, 'periods' => self::PERIODS, 'from' => $from, 'to' => $to, 'warn' => $warn, 'max' => $max,
            'stores' => $stores, 'storeId' => $storeId, 'byStore' => $byStore, 'total' => $total, 'days' => $sales['days'],
            'any' => $total !== $zero,
            'csv_link' => Html::url('/ui/reports/sales-by-store.csv', $query),
            'asOf' => self::asOf($ctx),
        ], 200, ['title' => Words::MENU['sales_by_store']]);
    }

    private function lowStock(Context $ctx, bool $csv): HtmlResponse
    {
        $req = $ctx->req;
        $default = Settings::number($ctx->db, 'reports.low_stock_default', 5);
        $f = ['q' => self::text($req->param('q')), 'show' => in_array($req->param('show'), ReportViews::LOW_SHOW, true) ? (string) $req->param('show') : 'low',
            'page' => UiRequest::id($req->param('page')) ?? 1];
        $result = (new ReportViews($ctx->db))->lowStock($f['show'], $f['q'], $default, $f['page'], $csv ? null : ReportViews::PAGE);
        if ($csv) {
            $out = new CsvWriter([['cw_code', 'text'], ['product', 'text'], ['brand', 'text'], ['units_the_stores_can_sell', 'number'], ['low_at', 'number'],
                ['own_low_level', 'text']]);
            foreach ($result['rows'] as $r) {
                $out->add([(string) $r['code'], (string) $r['name'], $r['brand'] === null ? null : (string) $r['brand'], (int) $r['available'], (int) $r['low_at'],
                    $r['min_stock'] === null ? 'no' : 'yes']);
            }
            return FilesController::download($out->output(), 'text/csv; charset=utf-8', 'low-stock-' . $f['show'] . '.csv');
        }
        $query = ['q' => $f['q'], 'show' => $f['show'] === 'low' ? null : $f['show']];
        return $ctx->page('report_low', [
            'f' => $f, 'default' => $default, 'counts' => ['low' => $result['low'], 'out' => $result['out']],
            'rows' => array_map(static fn (array $r): array => ['sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
                'brand' => $r['brand'] === null ? null : (string) $r['brand'], 'available' => (int) $r['available'], 'low_at' => (int) $r['low_at'],
                'own' => $r['min_stock'] !== null], $result['rows']),
            'total' => $result['total'], 'page_no' => $result['page'], 'pages' => $result['pages'],
            'canReorder' => $ctx->me()->can('reorder.view'),
            'csv_link' => Html::url('/ui/reports/low-stock.csv', $query),
            'prev_link' => $result['page'] > 1 ? Html::url('/ui/reports/low-stock', $query + ['page' => $result['page'] - 1]) : null,
            'next_link' => $result['page'] < $result['pages'] ? Html::url('/ui/reports/low-stock', $query + ['page' => $result['page'] + 1]) : null,
        ], 200, ['title' => Words::MENU['low_stock']]);
    }

    private function stockValue(Context $ctx, bool $csv): HtmlResponse
    {
        $req = $ctx->req;
        $views = new ReportViews($ctx->db);
        $f = ['by' => in_array($req->param('by'), ReportViews::VALUE_BY, true) ? (string) $req->param('by') : 'product', 'q' => self::text($req->param('q')),
            'nocost' => $req->param('nocost') === '1', 'page' => UiRequest::id($req->param('page')) ?? 1];
        $products = $f['by'] === 'product' ? $views->valueByProduct($f['q'], $f['nocost'], $f['page'], $csv ? null : ReportViews::PAGE) : null;
        // The one search finds a brand or an item (the approved TP_Report, W13.3): grouped by brand or by warehouse, the rows whose name
        // holds the words (the page and its file alike); by item, the item search as before.
        $holds = static fn (?string $name): bool => $f['q'] === '' || mb_stripos((string) $name, $f['q']) !== false;
        $brands = $f['by'] === 'brand' ? array_values(array_filter($views->valueByBrand(),
            static fn (array $b): bool => $holds($b['brand'] ?? Words::REPORTS['no_brand']))) : [];
        $warehouses = $f['by'] === 'warehouse' ? array_values(array_filter($views->valueByWarehouse(), static fn (array $w): bool => $holds($w['name']))) : [];
        if ($csv) {
            if ($products !== null) {
                $out = new CsvWriter([['cw_code', 'text'], ['product', 'text'], ['brand', 'text'], ['units', 'number'], ['average_cost_gbp', 'number'], ['value_gbp', 'number']]);
                foreach ($products['rows'] as $r) {
                    $out->add([(string) $r['code'], (string) $r['name'], $r['brand'] === null ? null : (string) $r['brand'], (int) $r['units'],
                        $r['avg_cost'] === null ? null : (string) $r['avg_cost'], $r['avg_cost'] === null ? null : (string) $r['value']]);
                }
            } elseif ($f['by'] === 'brand') {
                $out = new CsvWriter([['brand', 'text'], ['products', 'number'], ['units', 'number'], ['value_gbp', 'number'], ['units_without_a_cost', 'number']]);
                foreach ($brands as $b) {
                    $out->add([$b['brand'], $b['products'], $b['units'], $b['value'], $b['not_costed']]);
                }
            } else {
                $out = new CsvWriter([['warehouse', 'text'], ['ours', 'text'], ['products', 'number'], ['units', 'number'], ['value_gbp', 'number'],
                    ['units_without_a_cost', 'number']]);
                foreach ($warehouses as $w) {
                    $out->add([$w['name'], $w['ours'] ? 'yes' : 'no', $w['products'], $w['units'], $w['value'], $w['not_costed']]);
                }
            }
            return FilesController::download($out->output(), 'text/csv; charset=utf-8', 'stock-value-by-' . $f['by'] . '.csv');
        }
        $query = ['by' => $f['by'] === 'product' ? null : $f['by'], 'q' => $f['q'], 'nocost' => $f['nocost'] && $f['by'] === 'product' ? '1' : null];
        $slowDays = Settings::number($ctx->db, 'reports.slow_after_days', 60);
        $figures = $views->valueFigures();
        // Each brand's or our warehouse's share of our stock value (whole percent, for the bar), and the shown rows added up (W13.3; the
        // total row of the approved TP_Report). Pennies, so the total is exact; another account's warehouse has no share and no part in it.
        $pence = static fn (string $v): int => (int) round((float) $v * 100);
        $whole = $pence($figures['value']);
        $share = static fn (array $r): ?int => ($r['ours'] ?? true) && $whole > 0 ? (int) round($pence($r['value']) * 100 / $whole) : null;
        $sum = static function (array $rows) use ($pence): array {
            $t = ['products' => 0, 'units' => 0, 'pence' => 0];
            foreach ($rows as $r) {
                if ($r['ours'] ?? true) {
                    $t = ['products' => $t['products'] + $r['products'], 'units' => $t['units'] + $r['units'], 'pence' => $t['pence'] + $pence($r['value'])];
                }
            }
            return ['products' => $t['products'], 'units' => $t['units'], 'value' => number_format($t['pence'] / 100, 2, '.', '')];
        };
        $brands = array_map(static fn (array $b): array => $b + ['share' => $share($b)], $brands);
        $warehouses = array_map(static fn (array $w): array => $w + ['share' => $share($w)], $warehouses);
        return $ctx->page('report_value', [
            'f' => $f, 'figures' => $figures,
            'sum' => $f['by'] === 'brand' ? $sum($brands) : $sum($warehouses),
            // "Not sold in N days" (W13.3): N is Slow Stock's own setting, so the figure and that report's lists agree.
            'slowDays' => $slowDays, 'notSold' => $views->notSoldValue(ReportRefresh::ukDay(Clock::db(Clock::now())), $slowDays),
            'rows' => $products === null ? [] : array_map(static fn (array $r): array => ['sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'],
                'name' => (string) $r['name'], 'brand' => $r['brand'] === null ? null : (string) $r['brand'], 'units' => (int) $r['units'],
                'avg' => $r['avg_cost'] === null ? null : (string) $r['avg_cost'], 'value' => $r['avg_cost'] === null ? null : (string) $r['value']], $products['rows']),
            'brands' => $brands, 'warehouses' => $warehouses,
            'total' => $products['total'] ?? 0, 'page_no' => $products['page'] ?? 1, 'pages' => $products['pages'] ?? 1,
            'groupings' => ReportViews::VALUE_BY,
            'filtered' => $f['q'] !== '' || $f['nocost'],
            'csv_link' => Html::url('/ui/reports/stock-value.csv', $query),
            'nocost_link' => Html::url('/ui/reports/stock-value', ['nocost' => '1']),
            'all_link' => Html::url('/ui/reports/stock-value'),
            'prev_link' => ($products['page'] ?? 1) > 1 ? Html::url('/ui/reports/stock-value', $query + ['page' => $products['page'] - 1]) : null,
            'next_link' => ($products['page'] ?? 1) < ($products['pages'] ?? 1) ? Html::url('/ui/reports/stock-value', $query + ['page' => $products['page'] + 1]) : null,
            'asOf' => self::asOf($ctx),
        ], 200, ['title' => Words::MENU['stock_value']]);
    }

    private function slowStock(Context $ctx, bool $csv): HtmlResponse
    {
        $req = $ctx->req;
        $slowDays = Settings::number($ctx->db, 'reports.slow_after_days', 60);
        $deadDays = max($slowDays, Settings::number($ctx->db, 'reports.dead_after_days', 180));
        $today = ReportRefresh::ukDay(Clock::db(Clock::now()));
        $f = ['q' => self::text($req->param('q')), 'show' => in_array($req->param('show'), ReportViews::SLOW_SHOW, true) ? (string) $req->param('show') : 'slow',
            'page' => UiRequest::id($req->param('page')) ?? 1];
        $result = (new ReportViews($ctx->db))->slow($f['show'], $f['q'], $today, $slowDays, $deadDays, $f['page'], $csv ? null : ReportViews::PAGE);
        $money = $ctx->me()->can('values.view');
        $days = static fn (string $since): int => (int) (new \DateTimeImmutable($since))->diff(new \DateTimeImmutable($today))->days;
        if ($csv) {
            $cols = [['cw_code', 'text'], ['product', 'text'], ['brand', 'text'], ['units', 'number'], ['last_sale_uk_day', 'text'], ['stock_since_uk_day', 'text'],
                ['days_without_a_sale', 'number']];
            if ($money) {
                $cols[] = ['value_gbp', 'number'];
            }
            $out = new CsvWriter($cols);
            foreach ($result['rows'] as $r) {
                $row = [(string) $r['code'], (string) $r['name'], $r['brand'] === null ? null : (string) $r['brand'], (int) $r['units'],
                    $r['last_sale_on'] === null ? null : (string) $r['last_sale_on'], $r['first_in_on'] === null ? null : (string) $r['first_in_on'], $days((string) $r['since'])];
                if ($money) {
                    $row[] = (string) $r['value'];
                }
                $out->add($row);
            }
            return FilesController::download($out->output(), 'text/csv; charset=utf-8', 'slow-stock-' . $f['show'] . '.csv');
        }
        $query = ['q' => $f['q'], 'show' => $f['show'] === 'slow' ? null : $f['show']];
        return $ctx->page('report_slow', [
            'f' => $f, 'slowDays' => $slowDays, 'deadDays' => $deadDays, 'showValue' => $money, 'counts' => ['slow' => $result['slow'], 'dead' => $result['dead']],
            'rows' => array_map(static fn (array $r): array => ['sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
                'brand' => $r['brand'] === null ? null : (string) $r['brand'], 'units' => (int) $r['units'],
                'last_sale' => $r['last_sale_on'] === null ? null : (string) $r['last_sale_on'], 'since' => $r['first_in_on'] === null ? null : (string) $r['first_in_on'],
                'days' => $days((string) $r['since']), 'value' => (string) $r['value']], $result['rows']),
            'total' => $result['total'], 'page_no' => $result['page'], 'pages' => $result['pages'],
            'csv_link' => Html::url('/ui/reports/slow-stock.csv', $query),
            'prev_link' => $result['page'] > 1 ? Html::url('/ui/reports/slow-stock', $query + ['page' => $result['page'] - 1]) : null,
            'next_link' => $result['page'] < $result['pages'] ? Html::url('/ui/reports/slow-stock', $query + ['page' => $result['page'] + 1]) : null,
            'asOf' => self::asOf($ctx),
        ], 200, ['title' => Words::MENU['slow_stock']]);
    }

    /**
     * The days Sales by Store shows: a named period, or "choose" with from/to (UK dates, both included; swapped when given the wrong
     * way round; at most $max days, the last ones kept). An unreadable choice falls back to the last 30 days, and says so.
     *
     * @return array{0: string, 1: string, 2: string, 3: ?string} period, from, to, a warning in words
     */
    public static function period(UiRequest $req, string $today, int $max): array
    {
        $t = new \DateTimeImmutable($today);
        $period = (string) ($req->param('period') ?? 'last_30');
        $warn = null;
        if ($period === 'choose') {
            $from = self::date($req->param('from'));
            $to = self::date($req->param('to'));
            if ($from === null || $to === null) {
                $period = 'last_30';
                $warn = Words::REPORTS['bad_dates'];
            } else {
                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }
                return self::limitPeriod('choose', $from, $to, $warn, $max);
            }
        }
        [$period, $from, $to, $warn] = match ($period) {
            'last_7' => ['last_7', $t->modify('-6 days')->format('Y-m-d'), $today, $warn],
            'this_month' => ['this_month', $t->format('Y-m-01'), $today, $warn],
            'last_month' => ['last_month', $t->modify('first day of last month')->format('Y-m-d'), $t->modify('last day of last month')->format('Y-m-d'), $warn],
            default => ['last_30', $t->modify('-29 days')->format('Y-m-d'), $today, $warn],
        };
        return self::limitPeriod($period, $from, $to, $warn, $max);
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?string} */
    private static function limitPeriod(string $period, string $from, string $to, ?string $warn, int $max): array
    {
        $first = (new \DateTimeImmutable($to))->modify('-' . ($max - 1) . ' days')->format('Y-m-d');
        if ($from < $first) {
            $from = $first;
            $limit = Words::say('REPORTS', 'too_long', number_format($max));
            $warn = $warn === null ? $limit : $warn . ' ' . $limit;
        }
        return [$period, $from, $to, $warn];
    }

    /** "Sales and costs were last brought up to date …", or why not. */
    private static function asOf(Context $ctx): string
    {
        $state = (new ReportRefresh($ctx->db))->state();
        $off = !Settings::flag($ctx->db, 'reports.refresh_on', true);
        $line = $state['ran_at'] === null ? Words::REPORTS['never_run'] : Words::say('REPORTS', 'figures_at', Html::when((string) $state['ran_at']));
        return $off ? $line . ' ' . Words::REPORTS['job_off'] : $line;
    }

    private static function slug(Context $ctx): string
    {
        $path = $ctx->req->path;
        $slug = substr($path, strlen('/ui/reports/'));
        return str_ends_with($slug, '.csv') ? substr($slug, 0, -4) : $slug;
    }

    private static function text(?string $v): string
    {
        return mb_substr(trim((string) $v), 0, 100);
    }

    private static function date(?string $v): ?string
    {
        if ($v === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
    }
}
