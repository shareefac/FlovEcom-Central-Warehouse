<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Db;
use CW\PurchaseOrders\PurchaseOrders;

/**
 * The buying flow strip above Purchasing's tabs (design v3): Reorder → Purchase Order → Goods In → Stock updated. Each step names its
 * tab and says where the work is; a step whose page the person cannot open is drawn without a link and without a figure (they still
 * see how buying works). The figures are single COUNTs on indexed columns (document (doc_type, status, id)), read only on the
 * Purchasing pages. Reorder has no figure: what to order is worked out per product by the list itself (Reorder\ReorderList), too
 * much work for a strip on every Purchasing page.
 */
final class FlowCounts
{
    /** "Stock updated": deliveries booked in during this many days. */
    public const RECENT_DAYS = 7;

    /**
     * @param array<string, array<string, array<string, mixed>>> $visible Sections::visible() of the person
     * @param string|null $currentTab the Purchasing tab open now
     * @return list<array{n: int, key: string, label: string, text: string, href: ?string, query: array<string, string>, current: bool}>
     */
    public static function steps(Db $db, array $visible, ?string $currentTab): array
    {
        $tabs = $visible['purchasing'] ?? [];
        $stock = $visible['stock']['movements'] ?? null;
        $steps = [];
        $n = 0;
        foreach (['reorder', 'orders', 'goods_in', 'stock'] as $key) {
            $n++;
            $tab = $key === 'stock' ? $stock : ($tabs[$key] ?? null);
            $open = $tab !== null && !$tab['soon'];
            $first = $open ? $tab['pages'][0] : null;
            $steps[] = [
                'n' => $n,
                'key' => $key,
                'label' => Words::FLOW[$key],
                'text' => $open ? self::text($db, $key) : '',
                'href' => $key === 'stock' ? ($open ? '/ui/stock/movements' : null) : ($first === null ? null : (string) $first['path']),
                'query' => $key === 'stock' ? ['type' => 'goods_in'] : ($first['query'] ?? []),
                'current' => $currentTab !== null && $currentTab === $key,
            ];
        }
        return $steps;
    }

    /** The step's figure in words. */
    private static function text(Db $db, string $key): string
    {
        switch ($key) {
            case 'reorder':
                return Words::FLOW['reorder_text'];
            case 'orders':
                $open = "'" . implode("', '", PurchaseOrders::OPEN_STATES) . "'";
                $r = $db->one("SELECT COALESCE(SUM(d.status = 'posted' AND po.state IN ({$open})), 0) AS open_n, "
                    . "COALESCE(SUM(d.status = 'awaiting_approval'), 0) AS waiting_n FROM document d LEFT JOIN purchase_order po ON po.document_id = d.id "
                    . "WHERE d.doc_type = 'PO' AND d.status IN ('posted', 'awaiting_approval')") ?? [];
                $waiting = (int) ($r['waiting_n'] ?? 0);
                $text = Words::say('FLOW', 'orders_open', (int) ($r['open_n'] ?? 0));
                return $waiting > 0 ? $text . ' · ' . Words::say('FLOW', 'orders_waiting', $waiting) : $text;
            case 'goods_in':
                return Words::say('FLOW', 'goods_in_text', (int) $db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'GRN' AND status = 'draft'"));
            default:
                return Words::say('FLOW', 'stock_text', (int) $db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'GRN' AND status = 'posted' "
                    . 'AND posted_at >= NOW(6) - INTERVAL ' . self::RECENT_DAYS . ' DAY'));
        }
    }
}
