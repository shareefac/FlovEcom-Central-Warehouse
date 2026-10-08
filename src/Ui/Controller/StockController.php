<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\StockViews;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Stock › Overview (/ui/stock) and Stock › Movements (/ui/stock/movements): the first, honest version of the owner's stock pages
 * (8 Oct 2026, design v4), read only, for everyone who sees a product's stock on its page (catalogue.view). Overview: four figures,
 * then one row per product (its on-hand figure in each warehouse, reserved, what the websites can sell), grouped "Out of stock" /
 * "In stock", paged and filtered by warehouse, product and "with stock". Movements: the stock ledger, newest first, grouped by day,
 * filtered by warehouse, product, kind of change and figure. Stock per store, the VPG 2 room and overflow come in a later pack.
 */
final class StockController
{
    public function overview(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $views = new StockViews($ctx->db);
        $warehouses = $views->warehouses();
        $f = [
            'warehouse' => self::warehouse($req->param('warehouse'), $warehouses),
            'q' => self::text($req->param('q')),
            'show' => in_array($req->param('show'), StockViews::SHOW, true) ? (string) $req->param('show') : 'stock',
            'page' => UiRequest::id($req->param('page')) ?? 1,
        ];
        $result = $views->products($f, $warehouses);
        $query = ['warehouse' => $f['warehouse'], 'q' => $f['q'], 'show' => $f['show'] === 'stock' ? null : $f['show']];
        $groups = ['out' => [], 'in' => []];
        foreach ($result['rows'] as $r) {
            $row = ['sku_id' => (int) $r['sku_id'], 'code' => (string) ($r['code'] ?? ''), 'name' => (string) $r['name'],
                'brand' => $r['brand'] === null ? null : (string) $r['brand'], 'reserved' => (int) $r['reserved'], 'available' => (int) $r['available'],
                'by_warehouse' => array_map(static fn (array $w): int => (int) ($r['w_' . $w['id']] ?? 0), $warehouses)];
            $groups[$row['available'] > 0 ? 'in' : 'out'][] = $row;
        }
        return $ctx->page('stock', [
            'f' => $f,
            'warehouses' => $warehouses,
            'figures' => $views->figures(),
            'groups' => array_filter($groups, static fn (array $g): bool => $g !== []),
            'counts' => ['in' => $result['in'], 'out' => $result['out']],
            'rows_n' => count($result['rows']),
            'total' => $result['total'],
            'page_no' => $result['page'],
            'pages' => $result['pages'],
            'filtered' => $f['warehouse'] !== null || $f['q'] !== '' || $f['show'] !== 'stock',
            'prev_link' => $result['page'] > 1 ? Html::url('/ui/stock', $query + ['page' => $result['page'] - 1]) : null,
            'next_link' => $result['page'] < $result['pages'] ? Html::url('/ui/stock', $query + ['page' => $result['page'] + 1]) : null,
        ], 200, ['title' => Words::MENU['stock']]);
    }

    public function movements(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $views = new StockViews($ctx->db);
        $warehouses = $views->warehouses();
        $type = $req->param('type');
        $f = [
            'warehouse' => self::warehouse($req->param('warehouse'), $warehouses),
            'q' => self::text($req->param('q')),
            'type' => $type !== null && isset(Words::MOVEMENT[$type]) ? $type : null,
            'figure' => in_array($req->param('figure'), StockViews::FIGURES, true) ? (string) $req->param('figure') : 'on_hand',
            'before' => UiRequest::id($req->param('before')),
        ];
        $result = $views->movements($f);
        $who = $views->actors(array_values(array_unique(array_map(static fn (array $r): string => (string) $r['actor'], $result['rows']))));
        $query = ['warehouse' => $f['warehouse'], 'q' => $f['q'], 'type' => $f['type'], 'figure' => $f['figure'] === 'on_hand' ? null : $f['figure']];
        return $ctx->page('stock_movements', [
            'f' => $f,
            'warehouses' => $warehouses,
            'types' => Words::MOVEMENT,
            'rows' => array_map(static function (array $r) use ($who): array {
                $note = $r['note'] === null ? null : (string) $r['note'];
                return [
                    'sku_id' => (int) $r['sku_id'], 'code' => (string) ($r['code'] ?? ''), 'name' => (string) $r['name'], 'warehouse' => (string) $r['warehouse'],
                    'bucket' => (string) $r['bucket'], 'delta' => (int) $r['qty_delta'], 'after' => (int) $r['balance_after'], 'type' => (string) $r['movement_type'],
                    'ref' => $r['order_ref'] ?? $r['doc_ref'], 'who' => $who[(string) $r['actor']] ?? (string) $r['actor'],
                    'note' => $note !== null && preg_match('/^#\d+$/D', $note) === 1 ? null : $note, 'at' => (string) $r['at'],
                    // The board's groups are days in UK time (design v4), each row's time beside its product.
                    'day' => Html::day((string) $r['at']), 'time' => substr(Html::when((string) $r['at']), -5),
                ];
            }, $result['rows']),
            'filtered' => $f['warehouse'] !== null || $f['q'] !== '' || $f['type'] !== null || $f['figure'] !== 'on_hand',
            'older_link' => $result['next'] === null ? null : Html::url('/ui/stock/movements', $query + ['before' => $result['next']]),
            'newest_link' => $f['before'] === null ? null : Html::url('/ui/stock/movements', $query),
        ], 200, ['title' => Words::MENU['movements']]);
    }

    /** @param list<array{id: int}> $warehouses */
    private static function warehouse(?string $v, array $warehouses): ?int
    {
        $id = UiRequest::id($v);
        return $id !== null && in_array($id, array_column($warehouses, 'id'), true) ? $id : null;
    }

    /** A search text: trimmed, at most 100 characters. */
    private static function text(?string $v): string
    {
        return mb_substr(trim((string) $v), 0, 100);
    }
}
