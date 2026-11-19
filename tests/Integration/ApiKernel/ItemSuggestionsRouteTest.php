<?php

declare(strict_types=1);

namespace CW\Tests\Integration\ApiKernel;

use CW\Tests\Support\ApiKernelTestCase;

/**
 * GET /v1/item-suggestions through the real /v1 kernel (docs/decisions.md SX2, SX3; tracker CW-1138): a store's key asks which items a new
 * variant may be and what a typed CW number is; every query field is checked (400 bad_query, naming the field), the answer is read only and
 * works in any mode, a store sees only codes, names and its own free to sell (no other store's name), no key no answer (401).
 */
final class ItemSuggestionsRouteTest extends ApiKernelTestCase
{
    public function testAStoreAsksWhatANewVariantMayBeAndWhatACwNumberIs(): void
    {
        [$site, $key] = $this->apiSite('vapebig', 'off');
        $item = $this->item('legacy', 9, 'Elux Legend Nic Salt - Lemon & Lime - 10ml - 5mg');
        self::$db->exec("UPDATE sku SET brand = 'Elux Nic Salt (Legend Salts) E-Liquids', flavour = 'lemon lime', strength_mg = 5, volume_ml = 10, form = 'nic_salt', "
            . "nic_type = 'salt', line = 'elux legend' WHERE id = ?", [$item]);
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$item]);
        $other = $this->site('vapeandgo');
        $this->listing($other, '42423', $item);
        $q = http_build_query(['title' => 'Elux Legend Lemon & Lime Nic Salt E-liquid 10ml', 'option' => '5mg', 'brand' => 'Elux Legend',
            'attrs' => [['name' => 'Nicotine Strength', 'value' => '5mg']], 'code' => 'cw-' . (int) substr($code, 3)], '', '&', PHP_QUERY_RFC3986);
        $d = self::data($this->call('GET', '/v1/item-suggestions?' . $q, $key));
        $keys = array_keys($d);
        sort($keys);
        self::assertSame(['item', 'strong', 'suggestions'], $keys);
        self::assertSame(['code' => $code, 'name' => 'Elux Legend Nic Salt - Lemon & Lime - 10ml - 5mg', 'free' => 9, 'why' => 'code'], $d['item'],
            'a store switched off may ask (nothing moves); free to sell from its own warehouse');
        self::assertSame([$code, 'same_details'], [$d['suggestions'][0]['code'], $d['suggestions'][0]['why']]);
        self::assertTrue($d['strong']);
        self::assertStringNotContainsString('vapeandgo', json_encode($d, JSON_THROW_ON_ERROR), 'no other store in the answer');

        self::assertNull(self::data($this->call('GET', '/v1/item-suggestions?code=CW-999999', $key))['item'], 'no such item');
        self::assertSame([], self::data($this->call('GET', '/v1/item-suggestions?barcodes=5000000000001,5000000000002&limit=2', $key))['suggestions']);

        foreach ([
            '' => 'title',                                                       // nothing to look for
            '?title=' => 'title',
            '?code=AB-1' => 'code',
            '?code=CW-000000' => 'code',
            '?code[]=1' => 'code',
            '?title=x&limit=6' => 'limit',
            '?title=x&limit=0' => 'limit',
            '?title=x&limit=a' => 'limit',
            '?title[]=x' => 'title',
            '?title=' . str_repeat('a', 513) => 'title',
            '?title=a%00b' => 'title',
            '?title=a%C3' => 'title',                                         // not UTF-8
            '?brand=' . str_repeat('b', 129) => 'brand',
            '?title=x&barcodes=' . implode(',', range(1, 21)) => 'barcodes',
            '?title=x&barcodes=' . str_repeat('1', 65) => 'barcodes',
            '?title=x&barcodes=a%20b' => 'barcodes',
            '?title=x&attrs=x' => 'attrs',
            '?title=x&attrs[0][name]=a&attrs[0][oops]=b' => 'attrs',
            '?title=x&attrs[0][value]=' . str_repeat('v', 256) => 'attrs[0].value',
        ] as $query => $field) {
            $env = self::envelope($this->call('GET', '/v1/item-suggestions' . $query, $key), 400, 'bad_query');
            self::assertSame($field, $env['data']['field'] ?? null, $query);
        }
        $many = http_build_query(['title' => 'x', 'attrs' => array_fill(0, 21, ['name' => 'a', 'value' => 'b'])]);
        self::envelope($this->call('GET', '/v1/item-suggestions?' . $many, $key), 400, 'bad_query');

        self::envelope($this->call('GET', '/v1/item-suggestions?title=x', null), 401);
        self::envelope($this->call('POST', '/v1/item-suggestions', $key, (object) []), 405, 'method_not_allowed');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM store_choice'), 'nothing written');
    }
}
