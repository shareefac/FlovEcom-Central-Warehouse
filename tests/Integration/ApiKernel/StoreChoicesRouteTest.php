<?php

declare(strict_types=1);

namespace CW\Tests\Integration\ApiKernel;

use CW\Tests\Support\ApiKernelTestCase;

/**
 * PUT /v1/store-choices through the real /v1 kernel (docs/decisions.md SC1, SC2): a store sends what its staff chose at Add product; the
 * first choice of each variant is kept, a second send of it is answered `already_received`; a malformed call is refused whole (400); a
 * store that is switched off may still send (a choice moves no stock, like a listing push); no key, no call (401).
 */
final class StoreChoicesRouteTest extends ApiKernelTestCase
{
    public function testAStoreSendsItsChoicesOnceEvenWhileItIsOff(): void
    {
        [$site, $key] = $this->apiSite('vapeandgo', 'off');
        $body = ['choices' => [
            ['variant_id' => '51001', 'choice' => 'new', 'staff' => ['id' => 17, 'name' => 'Aswin'], 'chosen_at' => '2026-11-03T10:42:00Z'],
            ['variant_id' => '51002', 'choice' => 'pack', 'pack_of' => 'CW-003167', 'units' => 10, 'staff' => ['id' => '17', 'name' => 'Aswin'],
                'chosen_at' => '2026-11-03T10:42:00+00:00'],
        ]];
        self::assertSame(['accepted' => 2, 'ignored' => []], self::data($this->call('PUT', '/v1/store-choices', $key, $body)));
        self::assertSame(['accepted' => 0, 'ignored' => [['variant_id' => '51001', 'why' => 'already_received'], ['variant_id' => '51002', 'why' => 'already_received']]],
            self::data($this->call('PUT', '/v1/store-choices', $key, $body)), 'a second send changes nothing');
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM store_choice WHERE channel_id = ? AND state = 'waiting'", [$site->channelId]));

        self::envelope($this->call('PUT', '/v1/store-choices', $key, ['choices' => [['variant_id' => '51003', 'choice' => 'pack', 'staff' => ['id' => 1, 'name' => 'A'],
            'chosen_at' => '2026-11-03T10:42:00Z']]]), 400, 'bad_request');
        self::envelope($this->call('PUT', '/v1/store-choices', $key, ['nothing' => true]), 400, 'bad_request');
        self::envelope($this->call('PUT', '/v1/store-choices', null, $body), 401);
        self::envelope($this->call('POST', '/v1/store-choices', $key, $body), 405, 'method_not_allowed');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM store_choice'));
    }
}
