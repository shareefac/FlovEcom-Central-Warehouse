<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Output\CsvWriter;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\ReservationViews;
use CW\Ui\SalesViews;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Stock › Sales › Returns (pack D, docs/decisions.md PD7, PD9-PD12): the sold units the customers sent back and the paid units the
 * stores cancelled before sending, per store, newest order first, READ ONLY (sales.view). The owner's answer to Q12 (8 Oct 2026): no
 * new returns process: the store's own return page books a return, and CW puts the unit back in stock. So this is a list: each unit
 * with whether it went back on sale and where its stock is now; a cancel the store marked not restockable is in VERIFY, waiting for a
 * recount (the engine opens one), and the list says whether that recount is done.
 *
 *   /ui/stock/sales/returns       for every store or one; returned, cancelled or both; `sales.page_size` units a page, older by key
 *   /ui/stock/sales/returns.csv   the same filtered list as a file (at most `sales.csv_max_rows` rows)
 */
final class SalesReturnsController
{
    public const PATH = '/ui/stock/sales/returns';

    public function index(Context $ctx): HtmlResponse
    {
        $views = new SalesViews($ctx->db);
        $set = SalesViews::settings($ctx->db);
        $stores = (new ReservationViews($ctx->db))->stores();
        $f = self::filters($ctx->req, $stores);
        $storeIds = array_column($stores, 'id');
        $chosen = $f['store'] === null ? $storeIds : [(int) $f['store']['id']];
        $page = $views->returns($chosen, $f['kind'] === null ? SalesViews::KINDS : [$f['kind']], $f['before'], $set['page']);
        $warehouses = $views->warehouses();
        $names = array_column($stores, 'name', 'id');
        $verify = $views->verifyOpen();
        $verifyName = '';
        foreach ($warehouses as $w) {
            if ($w['code'] === SalesViews::VERIFY) {
                $verifyName = $w['name'];
            }
        }
        $code = $f['store'] === null ? null : (string) $f['store']['code'];
        $query = ['channel' => $code, 'kind' => $f['kind']];
        return $ctx->page('sales_returns', [
            'f' => ['channel' => $code, 'kind' => $f['kind']],
            'storeItems' => ReviewController::storeItems($stores, self::PATH, ['kind' => $f['kind']], $code, $verify, true),
            'kinds' => Words::SALE_RETURN_KIND,
            'verifyUnits' => array_sum(array_intersect_key($verify, array_flip($chosen))),
            'verifyName' => $verifyName,
            'rows' => array_map(static fn (array $u): array => self::row($u, $names, $warehouses), $page['rows']),
            'page_size' => $set['page'],
            'filtered' => $f['kind'] !== null,
            'clear' => Html::url(self::PATH, ['channel' => $code]),
            'csv' => Html::url(self::PATH . '.csv', $query),
            'older_link' => $page['next'] === null ? null : Html::url(self::PATH, $query + ['before' => $page['next'][0], 'bu' => $page['next'][1]]),
            'newest_link' => $f['before'] === null ? null : Html::url(self::PATH, $query),
        ], 200, ['title' => Words::MENU['sales_returns']]);
    }

    /** The filtered list as CSV, newest order first, read a page at a time by key (formula-safe, CsvWriter). Times are UTC. */
    public function csv(Context $ctx): HtmlResponse
    {
        $views = new SalesViews($ctx->db);
        $set = SalesViews::settings($ctx->db);
        $stores = (new ReservationViews($ctx->db))->stores();
        $f = self::filters($ctx->req, $stores);
        $chosen = $f['store'] === null ? array_column($stores, 'id') : [(int) $f['store']['id']];
        $kinds = $f['kind'] === null ? SalesViews::KINDS : [$f['kind']];
        $warehouses = $views->warehouses();
        $names = array_column($stores, 'name', 'id');
        $csv = new CsvWriter([['store', 'text'], ['order_reference', 'text'], ['unit', 'text'], ['cw_number', 'text'], ['item', 'text'], ['store_variant', 'text'],
            ['what_happened', 'text'], ['recorded_utc', 'text'], ['back_on_sale', 'text'], ['where_the_stock_is', 'text']]);
        $n = 0;
        $before = null;
        $more = false;
        while (true) {
            $page = $views->returns($chosen, $kinds, $before, min(500, $set['csv_max'] - $n));
            foreach ($page['rows'] as $u) {
                $row = self::row($u, $names, $warehouses);
                $csv->add([$row['store'], $row['ref'], $row['unit'], $u['code'], $u['name'], $u['variant'], $row['what'], substr((string) $u['changed_at'], 0, 19),
                    $row['restockWord'], $row['where']]);
                $n++;
            }
            if ($page['next'] === null) {
                break;
            }
            if ($n >= $set['csv_max']) {
                $more = true;
                break;
            }
            $before = $page['next'];
        }
        if ($more) {
            $csv->add([null, null, null, null, null, null, Words::say('SALE_RETURNS', 'csv_more', $n), null, null, null]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'returns-and-cancels.csv');
    }

    /**
     * One returned or cancelled unit as the list shows it.
     *
     * @param array<string, mixed> $u SalesViews::returns row
     * @param array<int, string> $names
     * @param array<int, array{name: string, code: string}> $warehouses
     * @return array<string, mixed>
     */
    private static function row(array $u, array $names, array $warehouses): array
    {
        $unit = SalesController::unitRow($u, $warehouses);
        $where = $u['where_id'] === null ? '' : ($warehouses[$u['where_id']]['name'] ?? '');
        $text = Words::SALE_RESTOCK_TEXT[$u['outcome']] ?? Words::SALE_RESTOCK_TEXT['unknown'];
        return [
            'href' => SalesController::PATH . '/' . (int) $u['reservation_id'], 'ref' => (string) $u['ref'], 'store' => (string) ($names[$u['channel_id']] ?? ''),
            'unit' => (string) $u['unit_id'], 'sku_id' => $u['sku_id'], 'name' => $unit['name'], 'sub' => $unit['sub'],
            'what' => Words::of('SALE_RETURN_KIND', (string) $u['state']), 'when' => (string) $u['changed_at'],
            'restockTone' => Words::tone('SALE_RESTOCK', $u['restock']), 'restockWord' => Words::of('SALE_RESTOCK', $u['restock']),
            'where' => str_contains($text, '%s') ? sprintf($text, $where) : $text,
        ];
    }

    /**
     * The list's filters from the address: a store (its code), a kind (returned or cancelled; both by default) and the key the page
     * starts after (an order id and a unit number). Anything else is ignored.
     *
     * @param list<array{id: int, code: string, name: string}> $stores
     * @return array{store: ?array{id: int, code: string, name: string}, kind: ?string, before: ?array{0: int, 1: string}}
     */
    private static function filters(UiRequest $req, array $stores): array
    {
        $kind = $req->param('kind');
        $before = UiRequest::id($req->param('before'));
        $unit = $req->param('bu');
        return [
            'store' => ReviewController::store($stores, $req->param('channel')),
            'kind' => in_array($kind, SalesViews::KINDS, true) ? $kind : null,
            'before' => $before !== null && $unit !== null && preg_match('/^[\x21-\x7e]{1,64}$/D', $unit) === 1 ? [$before, $unit] : null,
        ];
    }
}
