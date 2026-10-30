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
    /** How many figures Home shows (HM_Home draws four; the approved Main artboard of 30 Oct 2026 too). */
    public const MAX = 4;
    /**
     * The figures, first first (the approved Main artboard of 30 Oct 2026; docs/decisions.md RD-C15): the units on the shelf, what waits for
     * this person's OK and the store listings to match; then, for whoever may see fewer of those, the figures Home showed before (open purchase
     * orders, deliveries to receive, products). Each in its tile's tint (`tone`) with its icon and a line under it. The artboard's first tile,
     * the stock value at cost, is not drawn: it is the whole valuation (every balance, its average cost), too heavy for every Home load, and CW
     * keeps no stored total of it (the fix round, docs/decisions.md RD-F8; the valuation report has it; needs a build: a total kept by the
     * reports' refresh).
     */
    public const ORDER = ['units', 'inbox', 'matches', 'orders', 'deliveries', 'products'];
    /** A figure's tint and icon (the artboard's: blue, purple, magenta; the fill-ins grey). */
    public const LOOK = ['units' => ['blue', 'stock'], 'inbox' => ['purple', 'approvals'], 'matches' => ['magenta', 'matching'],
        'orders' => ['grey', 'orders'], 'deliveries' => ['grey', 'goods-in'], 'products' => ['grey', 'products']];
    /** The kinds of Approvals' badges, for the inbox tile's line (its biggest kind), as Ui\Context::badges counts them. */
    private const INBOX_BADGES = ['queue_corrections' => 'kind_corrections', 'queue_suspense' => 'kind_suspense', 'counts_open' => 'kind_counts',
        'queue_oversold' => 'kind_oversold', 'queue_modes' => 'kind_modes', 'queue_landing' => 'kind_landing', 'reviews_open' => 'kind_documents'];

    /**
     * @return list<array{key: string, title: string, value: int, shown: string, sub: string, href: string, tone: string, icon: string}>
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
            if ($key === 'inbox' && Controller\InboxController::kinds($me->roles) !== []) {
                $b = $ctx->badges();
                $n = (int) ($b['approvals_inbox'] ?? 0);
                $top = null;
                foreach (self::INBOX_BADGES as $badge => $kind) {
                    if ((int) ($b[$badge] ?? 0) > 0 && ($top === null || (int) $b[$badge] > $top[0])) {
                        $top = [(int) $b[$badge], Words::INBOX[$kind]];
                    }
                }
                $out[] = self::tile('inbox', $n, Html::int($n), $top === null ? '' : Words::say('RD_HOME', 'inbox_sub', Html::int($top[0]), $top[1]), '/ui/approvals');
                continue;
            }
            $figure = match ($key) {
                'inbox' => null,
                'orders' => $me->can('purchasing.view') ? [self::openOrders($db), '/ui/purchasing/orders'] : null,
                'units' => $me->can('catalogue.view') ? [(int) $db->value('SELECT COALESCE(SUM(sb.on_hand), 0) FROM stock_balance sb '
                    . 'JOIN warehouse w ON w.id = sb.warehouse_id WHERE w.is_sellable = 1'), '/ui/stock'] : null,
                'deliveries' => $me->can('receiving.view') ? [(int) $db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'GRN' AND status = 'draft'"),
                    '/ui/receiving'] : null,
                'products' => $me->can('catalogue.view') ? [(int) $db->value('SELECT COUNT(*) FROM sku WHERE merged_into_sku_id IS NULL'), '/ui/items/cards'] : null,
                'matches' => $me->can('linking.view') ? [(int) array_sum(array_map(static fn (array $byChannel): int => (int) array_sum($byChannel),
                    $counts->bandCounts())), '/ui/review?queue=Key'] : null,
            };
            if ($figure !== null) {
                $out[] = self::tile($key, $figure[0], Html::int($figure[0]), Words::RD_HOME['sub_' . $key], $figure[1]);
            }
        }
        return $out;
    }

    /** @return array{key: string, title: string, value: int, shown: string, sub: string, href: string, tone: string, icon: string} */
    private static function tile(string $key, int $value, string $shown, string $sub, string $href): array
    {
        return ['key' => $key, 'title' => Words::RD_HOME['tile_' . $key], 'value' => $value, 'shown' => $shown, 'sub' => $sub, 'href' => $href,
            'tone' => self::LOOK[$key][0], 'icon' => self::LOOK[$key][1]];
    }

    /** The purchase orders not finished: drafts, waiting for an OK, and confirmed or sent ones still open. */
    private static function openOrders(\CW\Db $db): int
    {
        $open = "'" . implode("', '", PurchaseOrders::OPEN_STATES) . "'";
        return (int) $db->value("SELECT COUNT(*) FROM document d LEFT JOIN purchase_order po ON po.document_id = d.id "
            . "WHERE d.doc_type = 'PO' AND d.reverses_id IS NULL AND (d.status IN ('draft', 'awaiting_approval') OR (d.status = 'posted' AND po.state IN ({$open})))");
    }
}
