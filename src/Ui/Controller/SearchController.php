<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Ui\Context;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * /ui/search?q= (Find a product, plan §6.17): warehouse products by CW number, barcode or words; website products by their
 * option number on the website, barcode or words.
 */
final class SearchController
{
    /** At most this many warehouse products and website products are shown (Queries::searchSkus, searchListings). */
    private const ITEMS = 20;
    private const LISTINGS = 30;

    public function index(Context $ctx): HtmlResponse
    {
        $q = $ctx->queries();
        $text = mb_substr(trim($ctx->req->param('q') ?? ''), 0, 100);
        $skus = [];
        $listings = [];
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
        // (the lists stop at their sizes: the page says so when one is full, docs/decisions.md SX5)
        return $ctx->page('search', ['text' => $text, 'short' => $short, 'skus' => $skus, 'listings' => $listings, 'items_max' => self::ITEMS, 'listings_max' => self::LISTINGS],
            200, ['title' => Words::title('search'), 'active' => 'search']);
    }

    private static function s(mixed $v): ?string
    {
        return is_string($v) || is_int($v) || is_float($v) ? ($v === '' ? null : (string) $v) : null;
    }
}
