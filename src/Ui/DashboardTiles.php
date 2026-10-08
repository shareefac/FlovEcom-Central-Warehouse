<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\PurchaseOrders\PurchaseOrders;

/**
 * The Dashboard's widget tiles (design v4, owner's approval of 8 Oct 2026): a title, a big number, one line under it and a link,
 * only for the pages the person may open. Reads only: single COUNT / SUM queries on small or indexed tables; the matches reuse
 * Home's band counts (HomeCounts::bandCounts, computed once a request) and the approvals the review queue's counts (Context::checks).
 */
final class DashboardTiles
{
    /**
     * @return list<array{key: string, title: string, value: int, sub: string, href: string, link: string}>
     */
    public static function build(Context $ctx, HomeCounts $counts): array
    {
        $me = $ctx->me();
        $db = $ctx->db;
        $out = [];
        $tile = static function (string $key, int $value, string $sub, string $href) use (&$out): void {
            $out[] = ['key' => $key, 'title' => Words::TILE[$key]['title'], 'value' => $value, 'sub' => $sub, 'href' => $href, 'link' => Words::TILE[$key]['link']];
        };
        if ($me->can('catalogue.view')) {
            $tile('products', (int) $db->value('SELECT COUNT(*) FROM sku WHERE merged_into_sku_id IS NULL'), Words::TILE['products']['sub'], '/ui/items/cards');
            $r = $db->one('SELECT COALESCE(SUM(sb.on_hand), 0) AS units, COALESCE(SUM(sb.on_hand > 0), 0) AS products FROM stock_balance sb '
                . 'JOIN warehouse w ON w.id = sb.warehouse_id WHERE w.is_sellable = 1') ?? [];
            $products = (int) ($r['products'] ?? 0);
            $tile('units', (int) ($r['units'] ?? 0), $products === 1 ? Words::TILE['units']['sub_one'] : sprintf(Words::TILE['units']['sub'], number_format($products)), '/ui/stock');
        }
        if ($me->can('linking.view')) {
            $tile('matches', array_sum(array_map(static fn (array $byChannel): int => (int) array_sum($byChannel), $counts->bandCounts())),
                Words::TILE['matches']['sub'], '/ui/review?queue=Key');
        }
        $checks = $me->can('documents.review') ? $ctx->checks() : null;
        if ($checks !== null) {
            $tile('approvals', $checks['approval'] + $checks['review'], sprintf(Words::TILE['approvals']['sub'], number_format($checks['approval']),
                number_format($checks['review'])), '/ui/documents/reviews');
        }
        if ($me->can('purchasing.view')) {
            $open = "'" . implode("', '", PurchaseOrders::OPEN_STATES) . "'";
            $r = $db->one("SELECT COALESCE(SUM(d.status = 'draft'), 0) AS drafts, COALESCE(SUM(d.status = 'awaiting_approval'), 0) AS waiting, "
                . "COALESCE(SUM(d.status = 'posted' AND po.state IN ({$open})), 0) AS open_n FROM document d LEFT JOIN purchase_order po ON po.document_id = d.id "
                . "WHERE d.doc_type = 'PO' AND d.reverses_id IS NULL AND d.status IN ('draft', 'awaiting_approval', 'posted')") ?? [];
            $drafts = (int) ($r['drafts'] ?? 0);
            $waiting = (int) ($r['waiting'] ?? 0);
            $openN = (int) ($r['open_n'] ?? 0);
            $tile('orders', $drafts + $waiting + $openN, sprintf(Words::TILE['orders']['sub'], number_format($drafts), number_format($waiting), number_format($openN)),
                '/ui/purchasing/orders');
        }
        if ($me->can('receiving.view')) {
            $tile('deliveries', (int) $db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'GRN' AND status = 'draft'"), Words::TILE['deliveries']['sub'], '/ui/receiving');
        }
        return $out;
    }
}
