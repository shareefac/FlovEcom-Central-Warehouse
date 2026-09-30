<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Ui\Context;
use CW\Ui\HtmlResponse;

/** /ui/search?q=: items by CW code, barcode or words; listings by site variant id, barcode or words. */
final class SearchController
{
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
            $found = $q->searchSkus($text);
            $bc = $q->barcodesOfMany(array_map(static fn (array $r): int => (int) $r['id'], $found));
            foreach ($found as $r) {
                $skus[] = [
                    'id' => (int) $r['id'], 'code' => self::s($r['code']), 'name' => self::s($r['name']), 'brand' => self::s($r['brand']),
                    'policy' => self::s($r['sell_policy']), 'barcodes' => $bc[(int) $r['id']] ?? [],
                ];
            }
            foreach ($q->searchListings($text) as $r) {
                $listings[] = [
                    'id' => (int) $r['id'], 'channel' => self::s($r['channel_code']), 'variant' => self::s($r['external_variant_id']),
                    'title' => self::s($r['product_title']), 'variant_title' => self::s($r['variant_title']), 'status' => self::s($r['status']),
                    'units_30d' => $r['units_30d'], 'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'], 'sku_code' => self::s($r['sku_code']),
                ];
            }
        }
        return $ctx->page('search', ['text' => $text, 'short' => $short, 'skus' => $skus, 'listings' => $listings],
            200, ['title' => 'Search', 'active' => 'search']);
    }

    private static function s(mixed $v): ?string
    {
        return is_string($v) || is_int($v) || is_float($v) ? ($v === '' ? null : (string) $v) : null;
    }
}
