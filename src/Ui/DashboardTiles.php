<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\PurchaseOrders\PurchaseOrders;

/**
 * Home's small figures (the approved HM_Home, 29 Oct 2026; docs/decisions.md HV1): a few words and a number, each a link to its page, only
 * for the pages the person may open. The figures of design v4's tiles, in ORDER (the day's work first: what is bought, in stock and coming
 * in; then what the task rows count already), at most MAX of them: a figure after the MAX-th the person may see is never counted. Reads
 * only: single COUNT / SUM queries on small or indexed tables; the matches reuse Home's band counts (HomeCounts::bandCounts, computed once
 * a request) and the approvals the review queue's counts (Context::checks).
 */
final class DashboardTiles
{
    /** How many figures Home shows (HM_Home draws four). */
    public const MAX = 4;
    /** The figures, first first. */
    public const ORDER = ['orders', 'units', 'deliveries', 'products', 'matches', 'approvals'];

    /**
     * @return list<array{key: string, title: string, value: int, href: string}>
     */
    public static function build(Context $ctx, HomeCounts $counts): array
    {
        $me = $ctx->me();
        $db = $ctx->db;
        $out = [];
        foreach (self::ORDER as $key) {
            if (count($out) >= self::MAX) {
                break;
            }
            $figure = match ($key) {
                'orders' => $me->can('purchasing.view') ? [self::openOrders($db), '/ui/purchasing/orders'] : null,
                'units' => $me->can('catalogue.view') ? [(int) $db->value('SELECT COALESCE(SUM(sb.on_hand), 0) FROM stock_balance sb '
                    . 'JOIN warehouse w ON w.id = sb.warehouse_id WHERE w.is_sellable = 1'), '/ui/stock'] : null,
                'deliveries' => $me->can('receiving.view') ? [(int) $db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'GRN' AND status = 'draft'"),
                    '/ui/receiving'] : null,
                'products' => $me->can('catalogue.view') ? [(int) $db->value('SELECT COUNT(*) FROM sku WHERE merged_into_sku_id IS NULL'), '/ui/items/cards'] : null,
                'matches' => $me->can('linking.view') ? [(int) array_sum(array_map(static fn (array $byChannel): int => (int) array_sum($byChannel),
                    $counts->bandCounts())), '/ui/review?queue=Key'] : null,
                'approvals' => $me->can('documents.review') && ($checks = $ctx->checks()) !== null
                    ? [$checks['approval'] + $checks['review'], '/ui/documents/reviews'] : null,
            };
            if ($figure !== null) {
                $out[] = ['key' => $key, 'title' => Words::TILE[$key], 'value' => $figure[0], 'href' => $figure[1]];
            }
        }
        return $out;
    }

    /** The purchase orders not finished: drafts, waiting for an OK, and confirmed or sent ones still open. */
    private static function openOrders(\CW\Db $db): int
    {
        $open = "'" . implode("', '", PurchaseOrders::OPEN_STATES) . "'";
        return (int) $db->value("SELECT COUNT(*) FROM document d LEFT JOIN purchase_order po ON po.document_id = d.id "
            . "WHERE d.doc_type = 'PO' AND d.reverses_id IS NULL AND (d.status IN ('draft', 'awaiting_approval') OR (d.status = 'posted' AND po.state IN ({$open})))");
    }
}
