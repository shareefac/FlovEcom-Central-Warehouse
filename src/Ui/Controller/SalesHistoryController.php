<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Output\CsvWriter;
use CW\Reorder\DemandMath;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;

/**
 * The imported sales history (IM9 basic, Phase I-2; spec §8.1; docs/decisions.md I68): per channel its coverage, its import
 * batches (counts, unknown and unlinked units), the stock-snapshot days, and the variants that sell but count for no item:
 * the top 50 unknown (no listing) or unlinked (a listing without an item) variants by units in the last 91 days of the
 * history, with the site's titles, and the full list as CSV. A link to the listing's review page only for linking.view.
 * reorder.view; read-only.
 */
final class SalesHistoryController
{
    public const TOP = 50;
    public const DAYS = 91;

    public function index(Context $ctx): HtmlResponse
    {
        $channels = [];
        foreach ($ctx->db->all("SELECT c.id, c.code, c.name, MIN(b.date_from) AS s, MAX(b.date_to) AS e FROM channel c JOIN sales_import_batch b ON b.channel_id = c.id "
            . "AND b.status = 'loaded' GROUP BY c.id, c.code, c.name ORDER BY c.code") as $c) {
            $id = (int) $c['id'];
            $snap = $ctx->db->one('SELECT COUNT(*) AS n, MIN(snapshot_date) AS f, MAX(snapshot_date) AS l FROM channel_snapshot_day WHERE channel_id = ?', [$id]);
            $latest = $ctx->db->one('SELECT MAX(snapshot_date) AS d, COUNT(*) AS n FROM listing_stock_latest WHERE channel_id = ?', [$id]);
            $channels[] = $c + ['snapshot_days' => (int) ($snap['n'] ?? 0), 'snapshot_first' => $snap['f'] ?? null, 'snapshot_last' => $snap['l'] ?? null,
                'latest_date' => $latest['d'] ?? null, 'latest_rows' => (int) ($latest['n'] ?? 0),
                'top' => $this->unlinked($ctx, $id, (string) $c['e'], self::TOP)];
        }
        return $ctx->page('sales_history', [
            'channels' => $channels,
            'batches' => $ctx->db->all('SELECT b.id, c.code AS channel, b.source, b.date_from, b.date_to, b.sales_file, b.stock_file, b.latest_file, b.status, b.rows_read, '
                . 'b.rows_loaded, b.units_loaded, b.unknown_rows, b.unknown_units, b.unlinked_rows, b.unlinked_units, b.stock_rows, b.actor, b.exported_at, b.started_at, '
                . 'b.finished_at, b.error FROM sales_import_batch b JOIN channel c ON c.id = b.channel_id ORDER BY b.id DESC LIMIT 100'),
            'canLink' => $ctx->me()->can('linking.view'),
            'days' => self::DAYS,
            'top' => self::TOP,
        ], 200, ['title' => 'Sales history', 'active' => 'sales_history']);
    }

    /** GET /ui/purchasing/sales-history/unlinked.csv?channel=<code>: every unknown or unlinked variant by units in 91 days. */
    public function unlinkedCsv(Context $ctx): HtmlResponse
    {
        $code = $ctx->req->param('channel') ?? '';
        $c = $ctx->db->one("SELECT c.id, c.code, MAX(b.date_to) AS e FROM channel c JOIN sales_import_batch b ON b.channel_id = c.id AND b.status = 'loaded' "
            . 'WHERE c.code = ? GROUP BY c.id, c.code', [$code]);
        if ($c === null) {
            return $ctx->error(404, 'unknown_channel', 'there is no sales history for that site');
        }
        $csv = new CsvWriter([['channel', 'text'], ['variant_id', 'text'], ['units_91d', 'number'], ['last_sale', 'text'], ['mapping', 'text'], ['listing_id', 'number'],
            ['product_title', 'text'], ['variant_title', 'text'], ['brand', 'text']]);
        foreach ($this->unlinked($ctx, (int) $c['id'], (string) $c['e'], null) as $r) {
            $csv->add([$c['code'], $r['variant'], (int) $r['units'], $r['last_sale'], $r['mapping'], $r['listing_id'], $r['product_title'], $r['variant_title'], $r['brand']]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', "{$c['code']}-unlinked.csv");
    }

    /**
     * The variants of a channel that sold in the last 91 days of its history and count for no item (no listing, no item, or
     * a listing not `mapped`: a quarantined one keeps its item but counts for nothing, I77), most units first.
     *
     * @return list<array{variant: string, units: int, last_sale: string, mapping: string, listing_id: ?int, product_title: ?string, variant_title: ?string, brand: ?string}>
     */
    private function unlinked(Context $ctx, int $channelId, string $end, ?int $limit): array
    {
        $from = DemandMath::date(DemandMath::day($end) - self::DAYS + 1);
        $rows = $ctx->db->all(
            'SELECT h.external_variant_id AS variant, SUM(h.units_online + h.units_office) AS units, MAX(h.sale_date) AS last_sale, l.id AS listing_id, l.status '
            . 'FROM sales_history_day h LEFT JOIN channel_listing l ON l.channel_id = h.channel_id AND l.external_variant_id = h.external_variant_id '
            . "WHERE h.channel_id = ? AND h.sale_date BETWEEN ? AND ? AND (l.sku_id IS NULL OR l.status <> 'mapped') GROUP BY h.external_variant_id, l.id, l.status "
            . 'ORDER BY units DESC, h.external_variant_id' . ($limit === null ? '' : ' LIMIT ' . $limit),
            [$channelId, $from, $end],
        );
        $ids = array_values(array_filter(array_map(static fn (array $r): ?int => $r['listing_id'] === null ? null : (int) $r['listing_id'], $rows)));
        $profiles = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($ctx->db->all('SELECT listing_id, product_title, variant_title, brand FROM listing_profile WHERE listing_id IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $p) {
                $profiles[(int) $p['listing_id']] = $p;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $p = $r['listing_id'] === null ? null : ($profiles[(int) $r['listing_id']] ?? null);
            $out[] = ['variant' => (string) $r['variant'], 'units' => (int) $r['units'], 'last_sale' => (string) $r['last_sale'],
                'mapping' => $r['listing_id'] === null ? 'unknown' : 'unlinked (' . $r['status'] . ')', 'listing_id' => $r['listing_id'] === null ? null : (int) $r['listing_id'],
                'product_title' => $p['product_title'] ?? null, 'variant_title' => $p['variant_title'] ?? null, 'brand' => $p['brand'] ?? null];
        }
        return $out;
    }
}
