<?php

declare(strict_types=1);

namespace CW\Tests\Integration\ApiKernel;

use CW\Tests\Support\ApiKernelTestCase;

/**
 * PUT /v1/orders/{ref}/snapshot through the real /v1 kernel (profit step 1; docs/decisions.md PR1-PR3; CONTRACT.md): stored, same and
 * older_ignored (200) in every store mode (a snapshot moves no stock, like a listing push); a bad field 400 with `field`; a body over
 * 256 KB 413; no key 401; another method 405; not JSON 415. Nothing that was refused is written.
 */
final class OrderSnapshotRouteTest extends ApiKernelTestCase
{
    /** @return array<string, mixed> */
    private static function body(array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1763586245123, 'order_no' => 'VPG1567469', 'status' => 'completed', 'placed_at' => '2026-11-19T21:04:05Z',
            'paid_at' => '2026-11-19T21:05:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => '8812345', 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1299, 'discount_inc' => 200, 'cancelled' => false,
                'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '12', 'method' => 'Royal Mail Tracked 48', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0,
            'grand_inc' => 1498, 'parcels' => []];
    }

    public function testEveryStoreModeTakesItsSnapshotsOnceInOrder(): void
    {
        foreach (['off', 'shadow', 'live'] as $i => $mode) {
            [$site, $key] = $this->apiSite('shop' . $i, $mode);
            $uri = '/v1/orders/1567469/snapshot';
            self::assertSame(['result' => 'stored', 'version' => 1763586245123], self::data($this->call('PUT', $uri, $key, self::body())), $mode);
            self::assertSame(['result' => 'same', 'version' => 1763586245123], self::data($this->call('PUT', $uri, $key, self::body())), $mode);
            self::assertSame(['result' => 'older_ignored', 'version' => 1763586245123],
                self::data($this->call('PUT', $uri, $key, self::body(['version' => 5, 'grand_inc' => 1]))), $mode);
            self::assertSame(['result' => 'stored', 'version' => 1763586245200],
                self::data($this->call('PUT', $uri, $key, self::body(['version' => 1763586245200, 'grand_inc' => 1]))), $mode);
            self::assertSame($mode, self::header($this->call('PUT', $uri, $key, self::body()), 'X-CW-Channel-Mode'));
            self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sale_order WHERE channel_id = ?', [$site->channelId]));
            self::assertSame('1', (string) self::$db->value('SELECT grand_inc FROM sale_order WHERE channel_id = ?', [$site->channelId]));
        }
    }

    public function testABadSnapshotIsRefusedWithItsFieldAndNothingIsWritten(): void
    {
        [, $key] = $this->apiSite('vapeandgo', 'live');
        $uri = '/v1/orders/77/snapshot';
        $bad = static function (array $env): ?string {
            return $env['data']['field'] ?? null;
        };
        self::assertSame('lines[0].price_inc', $bad(self::envelope($this->call('PUT', $uri, $key,
            self::body(['lines' => [['price_inc' => '12.99'] + self::body()['lines'][0]]])), 400, 'bad_snapshot')));
        self::assertSame('currency', $bad(self::envelope($this->call('PUT', $uri, $key, self::body(['currency' => 'USD'])), 400, 'bad_snapshot')));
        self::assertSame('parent_ref', $bad(self::envelope($this->call('PUT', $uri, $key, self::body(['parent_ref' => '77'])), 400, 'bad_snapshot')),
            'an order is not a re-send of itself');
        self::assertSame('ref', $bad(self::envelope($this->call('PUT', '/v1/orders/' . str_repeat('9', 65) . '/snapshot', $key, self::body()), 400, 'bad_snapshot')));
        self::envelope($this->call('PUT', $uri, $key, null, raw: '[1,2]', headers: ['Content-Type' => 'application/json']), 400, 'bad_json');
        self::envelope($this->call('PUT', $uri, $key, null, raw: '{"v":1}', headers: ['Content-Type' => 'text/plain']), 415, 'unsupported_media_type');
        $big = self::body(['padding' => str_repeat('x', 270_000)]);
        self::envelope($this->call('PUT', $uri, $key, $big), 413, 'body_too_large');
        self::envelope($this->call('PUT', $uri, null, self::body()), 401);
        self::envelope($this->call('POST', $uri, $key, self::body()), 405, 'method_not_allowed');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM order_snapshot'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM sale_order'));
    }
}
