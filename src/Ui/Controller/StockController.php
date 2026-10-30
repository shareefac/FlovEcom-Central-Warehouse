<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\StockOps\StockOps;
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
            // The redesign's one On hand column (docs/decisions.md RD-A7): the warehouse chosen under More, else every warehouse together.
            $row['on_hand'] = $f['warehouse'] === null ? array_sum($row['by_warehouse'])
                : (int) ($r['w_' . $f['warehouse']] ?? 0);
            $groups[$row['available'] > 0 ? 'in' : 'out'][] = $row;
        }
        $keep = ['q' => $f['q'] === '' ? null : $f['q'], 'warehouse' => $f['warehouse']];
        return $ctx->page('stock', [
            // The redesign's simple list (docs/decisions.md RD-A7): what to show is the ONE Show choice; the warehouse and Clear are under More.
            'show' => array_map(static fn (string $v, string $label): array => ['label' => $label, 'href' => Html::url('/ui/stock', $keep + ['show' => $v === 'stock' ? null : $v]),
                'current' => $f['show'] === $v], ['stock', 'all', 'negative'], [Words::STOCK_VIEW['show_stock'], Words::STOCK_VIEW['show_all'], Words::STOCK_VIEW['show_negative']]),
            'bar' => ['filters' => ['action' => '/ui/stock', 'keep' => array_filter(['q' => $f['q'], 'show' => $f['show'] === 'stock' ? '' : $f['show']],
                static fn (string $v): bool => $v !== ''), 'open' => $f['warehouse'] !== null, 'selects' => [['name' => 'warehouse', 'label' => Words::STOCK_VIEW['warehouse'],
                'value' => (string) $f['warehouse'], 'options' => ['' => Words::STOCK_VIEW['all_warehouses']] + array_column($warehouses, 'name', 'id')]]],
                'links' => $f['warehouse'] !== null || $f['q'] !== '' || $f['show'] !== 'stock' ? [['label' => Words::STOCK_VIEW['clear'], 'href' => '/ui/stock']] : []],
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
        $deltas = array_map(static fn (array $r): int => (int) $r['qty_delta'], $result['rows']);
        $keep = ['q' => $f['q'] === '' ? null : $f['q'], 'warehouse' => $f['warehouse'], 'figure' => $f['figure'] === 'on_hand' ? null : $f['figure']];
        $filtered = $f['warehouse'] !== null || $f['q'] !== '' || $f['type'] !== null || $f['figure'] !== 'on_hand';
        return $ctx->page('stock_movements', [
            // The redesign's simple list (docs/decisions.md RD-A7): the kind of change is the ONE Show choice; the warehouse and the figure (a GET
            // form) and Clear are under the head's More.
            'show' => [['label' => Words::STOCK_VIEW['all_types'], 'href' => Html::url('/ui/stock/movements', $keep), 'current' => $f['type'] === null],
                ...array_map(static fn (string $t, string $label): array => ['label' => $label, 'href' => Html::url('/ui/stock/movements', $keep + ['type' => $t]),
                    'current' => $f['type'] === $t], array_keys(Words::MOVEMENT), array_values(Words::MOVEMENT))],
            'bar' => ['filters' => ['action' => '/ui/stock/movements', 'keep' => array_filter(['q' => $f['q'], 'type' => (string) $f['type']], static fn (string $v): bool => $v !== ''),
                'open' => $f['warehouse'] !== null || $f['figure'] !== 'on_hand', 'selects' => [
                    ['name' => 'warehouse', 'label' => Words::STOCK_VIEW['warehouse'], 'value' => (string) $f['warehouse'],
                        'options' => ['' => Words::STOCK_VIEW['all_warehouses']] + array_column($warehouses, 'name', 'id')],
                    ['name' => 'figure', 'label' => Words::STOCK_VIEW['figure'], 'value' => $f['figure'],
                        'options' => ['on_hand' => Words::STOCK_VIEW['figure_on_hand'], 'all' => Words::STOCK_VIEW['figure_all']]]]],
                'links' => $filtered ? [['label' => Words::STOCK_VIEW['clear'], 'href' => '/ui/stock/movements']] : []],
            'f' => $f,
            // The report's figures strip (docs/decisions.md W8): the changes this page shows, the units they added and took away.
            'figures' => ['moves' => count($deltas), 'in' => array_sum(array_filter($deltas, static fn (int $d): bool => $d > 0)),
                'out' => -array_sum(array_filter($deltas, static fn (int $d): bool => $d < 0))],
            'warehouses' => $warehouses,
            'types' => Words::MOVEMENT,
            'rows' => array_map(static function (array $r) use ($who): array {
                $note = $r['note'] === null ? null : (string) $r['note'];
                return [
                    'sku_id' => (int) $r['sku_id'], 'code' => (string) ($r['code'] ?? ''), 'name' => (string) $r['name'], 'warehouse' => (string) $r['warehouse'],
                    'bucket' => (string) $r['bucket'], 'delta' => (int) $r['qty_delta'], 'after' => (int) $r['balance_after'], 'type' => (string) $r['movement_type'],
                    'ref' => $r['order_ref'] ?? $r['doc_ref'], 'who' => $who[(string) $r['actor']] ?? (string) $r['actor'],
                    'note' => $note !== null && (preg_match('/^#\d+$/D', $note) === 1 || $r['doc_type'] !== null) ? null : $note, 'at' => (string) $r['at'],
                    // A row booked by a stock record (pack A1): the record's kind, its reason, who it was given to, and its page.
                    'record' => self::record($r),
                    // The board's groups are days in UK time (design v4), each row's time beside its product.
                    'day' => Html::day((string) $r['at']), 'time' => substr(Html::when((string) $r['at']), -5),
                ];
            }, $result['rows']),
            'filtered' => $filtered,
            'older_link' => $result['next'] === null ? null : Html::url('/ui/stock/movements', $query + ['before' => $result['next']]),
            'newest_link' => $f['before'] === null ? null : Html::url('/ui/stock/movements', $query),
        ], 200, ['title' => Words::MENU['movements']]);
    }

    /**
     * What a stock record says about a ledger row it booked: [kind words, reason, "given to", its page], or null for a row no stock
     * record booked.
     *
     * @param array<string, mixed> $r StockViews::movements() row
     * @return array{what: string, why: ?string, given: ?string, href: string}|null
     */
    private static function record(array $r): ?array
    {
        $kind = $r['doc_type'] === null ? null : StockOps::kindOf((string) $r['doc_type']);
        if ($kind === null) {
            return null;
        }
        return ['what' => Words::STOCK_KIND[$kind]['one'], 'why' => $r['reason_label'] === null ? null : (string) $r['reason_label'],
            'given' => $r['given_to'] === null ? null : Words::say('STOCK_OPS', 'given', (string) $r['given_to']),
            'href' => StockOps::PATHS[$kind] . '/' . (int) $r['document_id']];
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
