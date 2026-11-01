<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Ui\Context;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * /ui/search?q= (Find a product, plan §6.17): warehouse products by CW number, barcode or words; website products by their
 * option number on the website, barcode or words; for who may look at sales, the stores' orders by their order number (the
 * approved Sidebar.dc.html's "Find an item or an order", docs/decisions.md SS5).
 */
final class SearchController
{
    /** At most this many warehouse products and website products are shown (Queries::searchSkus, searchListings). */
    private const ITEMS = 20;
    private const LISTINGS = 30;
    /** At most this many orders of one number (one a store: uq_reservation_order). */
    private const ORDERS = 10;

    public function index(Context $ctx): HtmlResponse
    {
        $q = $ctx->queries();
        $text = mb_substr(trim($ctx->req->param('q') ?? ''), 0, 100);
        $skus = [];
        $listings = [];
        $orders = [];
        $short = false;
        if ($text !== '') {
            $short = mb_strlen($text) < 2;
        }
        if ($text !== '' && !$short) {
            $names = [];
            foreach ($q->channels() as $c) {
                $names[(string) $c['code']] = (string) $c['name'];
            }
            $found = $q->searchSkus($text, self::ITEMS);
            $bc = $q->barcodesOfMany(array_map(static fn (array $r): int => (int) $r['id'], $found));
            foreach ($found as $r) {
                $skus[] = [
                    'id' => (int) $r['id'], 'code' => self::s($r['code']), 'name' => self::s($r['name']), 'brand' => self::s($r['brand']),
                    'policy' => self::s($r['sell_policy']), 'barcodes' => $bc[(int) $r['id']] ?? [],
                ];
            }
            foreach ($q->searchListings($text, self::LISTINGS) as $r) {
                $listings[] = [
                    'id' => (int) $r['id'], 'channel' => $names[(string) $r['channel_code']] ?? self::s($r['channel_code']), 'variant' => self::s($r['external_variant_id']),
                    'title' => self::s($r['product_title']), 'variant_title' => self::s($r['variant_title']), 'status' => self::s($r['status']),
                    'units_30d' => $r['units_30d'], 'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'], 'sku_code' => self::s($r['sku_code']),
                ];
            }
        }
        // (an order number of one character too: Reservations::ref allows it; the item search needs two)
        if ($text !== '' && $ctx->me()->can('sales.view')) {
            // The store's order number as typed (a leading # left out), on every store: one read of uq_reservation_order a store.
            $ref = ltrim($text, '#');
            $stores = $q->channels();
            $names = array_column($stores, 'name', 'id');
            if ($ref !== '' && strlen($ref) <= 64 && $stores !== []) {
                $ids = array_column($stores, 'id');
                foreach ($ctx->db->all('SELECT id, channel_id, order_ref, CAST(status AS CHAR) AS status, created_at FROM reservation WHERE channel_id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ') AND order_ref = ? ORDER BY id DESC LIMIT ' . self::ORDERS, [...$ids, $ref]) as $r) {
                    $orders[] = ['id' => (int) $r['id'], 'ref' => (string) $r['order_ref'], 'store' => (string) ($names[(int) $r['channel_id']] ?? ''),
                        'state' => Words::RESV_STATE[(string) $r['status']] ?? (string) $r['status'], 'placed' => (string) $r['created_at']];
                }
            }
        }
        // (the lists stop at their sizes: the page says so when one is full, docs/decisions.md SX5)
        return $ctx->page('search', ['text' => $text, 'short' => $short, 'skus' => $skus, 'listings' => $listings, 'orders' => $orders, 'items_max' => self::ITEMS, 'listings_max' => self::LISTINGS],
            200, ['title' => Words::title('search'), 'active' => 'search']);
    }

    private static function s(mixed $v): ?string
    {
        return is_string($v) || is_int($v) || is_float($v) ? ($v === '' ? null : (string) $v) : null;
    }
}
