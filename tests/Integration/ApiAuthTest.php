<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Api\ApiKey;
use CW\Tests\Support\ApiTestCase;

/** §11 CW API security over HTTP: Bearer key AND REMOTE_ADDR allowlist, fail-closed, no leaks. */
final class ApiAuthTest extends ApiTestCase
{
    public function testMissingMalformedAndUnknownKeysAre401(): void
    {
        [, $key] = $this->apiSite();
        $bad = [
            'none' => null,
            'basic' => 'Basic ' . base64_encode('vpg:' . $key),
            'empty bearer' => 'Bearer',
            'short' => 'Bearer ' . substr($key, 0, 12),
            'truncated' => 'Bearer ' . substr($key, 0, -1),
            'extended' => 'Bearer ' . $key . '0',
            'the stored hash itself' => 'Bearer ' . ApiKey::hash($key),
            'two tokens' => 'Bearer ' . $key . ' ' . $key,
        ];
        foreach ($bad as $what => $auth) {
            $r = $this->call('GET', '/v1/health', null, headers: ['Authorization' => $auth]);
            self::assertEnvelope($r, 401, 'unauthorized');
            self::assertNull($r->data(), $what);
            self::assertSame('Bearer', $r->header('www-authenticate'), $what);
        }
        // The scheme is case-insensitive; the key is not.
        self::assertEnvelope($this->call('GET', '/v1/health', null, headers: ['Authorization' => 'bearer ' . $key]), 200);
        self::assertEnvelope($this->call('GET', '/v1/health', null, headers: ['Authorization' => 'Bearer ' . strtoupper($key)]), 401);
    }

    public function testUnconfiguredChannelsAreFailClosed(): void
    {
        // No key hash: nothing authenticates as this channel.
        $this->site('nokey', 'live');
        self::assertEnvelope($this->call('GET', '/v1/health', str_repeat('a', 64)), 401, 'unauthorized');
        // A key but an empty allowlist: refused even with the right key.
        [, $key] = $this->apiSite('noips', 'live', []);
        self::assertEnvelope($this->call('GET', '/v1/health', $key), 403, 'forbidden');
        // A malformed allowlist entry and a /0 block allow nobody.
        self::$db->exec("UPDATE channel SET allowed_ips = JSON_ARRAY('127.0.0.1/33', 'localhost', '0.0.0.0/0', 42) WHERE code = 'noips'");
        self::assertEnvelope($this->call('GET', '/v1/health', $key), 403, 'forbidden');
    }

    public function testAllowlistUsesRemoteAddrOnlyAndNeverForwardingHeaders(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'live', ['10.20.30.40', '192.0.2.0/24']);
        $this->listing($site, 'V1', $this->item('strict', 5));
        $spoofs = [
            [],
            ['X-Forwarded-For' => '10.20.30.40'],
            ['X-Forwarded-For' => '127.0.0.1, 10.20.30.40'],
            ['CF-Connecting-IP' => '10.20.30.40'],
            ['X-Real-IP' => '192.0.2.9'],
            ['True-Client-IP' => '10.20.30.40'],
            ['Forwarded' => 'for=10.20.30.40'],
            ['Client-IP' => '10.20.30.40'],
        ];
        $before = $this->fingerprint();
        foreach ($spoofs as $h) {
            self::assertEnvelope($this->call('GET', '/v1/health', $key, headers: $h), 403, 'forbidden');
            $r = $this->call('POST', '/v1/reservations', $key, ['order_ref' => '1', 'lines' => [self::line('V1', 'u1')]], headers: $h);
            self::assertEnvelope($r, 403, 'forbidden');
        }
        self::assertSame($before, $this->fingerprint(), 'a refused caller changed something');

        // The allowlist is the AND half: CIDR containing the real client address passes...
        self::$db->exec("UPDATE channel SET allowed_ips = JSON_ARRAY('10.0.0.0/8', '127.0.0.0/8') WHERE id = ?", [$site->channelId]);
        self::assertEnvelope($this->call('GET', '/v1/health', $key), 200);
        // ...an IPv6-only list does not (the call arrives over IPv4 loopback)...
        self::$db->exec("UPDATE channel SET allowed_ips = JSON_ARRAY('::1', '::ffff:10.0.0.1') WHERE id = ?", [$site->channelId]);
        self::assertEnvelope($this->call('GET', '/v1/health', $key), 403, 'forbidden');
        // ...and the right address without the key is still nothing.
        self::$db->exec("UPDATE channel SET allowed_ips = JSON_ARRAY('127.0.0.1') WHERE id = ?", [$site->channelId]);
        self::assertEnvelope($this->call('GET', '/v1/health', null), 401, 'unauthorized');
    }

    public function testEachKeyActsAsItsOwnChannelAndHealthReportsTheMode(): void
    {
        [, $a] = $this->apiSite('vpg', 'live');
        [, $b] = $this->apiSite('vapebig', 'shadow');
        [, $c] = $this->apiSite('alecto', 'off');
        foreach ([[$a, 'vpg', 'live'], [$b, 'vapebig', 'shadow'], [$c, 'alecto', 'off']] as [$key, $code, $mode]) {
            $r = self::assertEnvelope($this->call('GET', '/v1/health', $key), 200);
            self::assertSame($code, $r->data()['channel']);
            self::assertSame($mode, $r->data()['mode']);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $r->data()['time']);
        }
        // A mode change is visible on the next probe (the site's circuit breaker reads it).
        self::$db->exec("UPDATE channel SET mode = 'shadow' WHERE code = 'alecto'");
        self::assertSame('shadow', $this->call('GET', '/v1/health', $c)->data()['mode']);
    }

    public function testOffChannelCannotChangeStockAndMayRetryTheSameKeyLater(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'off');
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $body = ['order_ref' => '77', 'lines' => [self::line('V1', 'u1')]];
        $before = $this->fingerprint();
        foreach ([
            ['/v1/reservations', $body],
            ['/v1/reservations/77/commit', ['lines' => [self::line('V1', 'u1')]]],
            ['/v1/reservations/77/release', (object) []],
            ['/v1/opening_orders', ['orders' => [['order_ref' => '5', 'lines' => [self::line('V1', 'o1')]]], 'final' => true]],
            ['/v1/movements', ['type' => 'goods_in', 'doc_ref' => 'PINV-1', 'lines' => [['variant_id' => 'V1', 'qty' => 1]]]],
        ] as [$path, $b]) {
            self::assertEnvelope($this->call('POST', $path, $key, $b, 'off-' . md5($path)), 409, 'channel_off');
        }
        self::assertSame($before, $this->fingerprint(), 'an off channel changed the books');

        // Reads, listings and heartbeats are allowed while off.
        self::assertEnvelope($this->call('GET', '/v1/changes?after=0', $key), 200);
        self::assertEnvelope($this->call('PUT', '/v1/listings', $key, ['listings' => [['variant_id' => 'V1', 'product_title' => 'Item']]]), 200);
        self::assertEnvelope($this->call('POST', '/v1/heartbeat', $key, ['site_mode' => 'off']), 200);

        // Nothing was stored under the refused key: after the switch to shadow it works.
        self::$db->exec("UPDATE channel SET mode = 'shadow' WHERE id = ?", [$site->channelId]);
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $body, 'off-' . md5('/v1/reservations')), 201);
        self::assertFalse($r->replayed());
        $this->assertBal(5, 0, 1, $sku);
    }

    public function testRoutingAnswersOnlyAfterAuthentication(): void
    {
        [, $key] = $this->apiSite();
        // Without a key nothing is revealed, not even whether a path exists.
        self::assertEnvelope($this->call('GET', '/v1/nope', null), 401, 'unauthorized');
        self::assertEnvelope($this->call('GET', '/', null), 401, 'unauthorized');
        self::assertEnvelope($this->call('GET', '/index.php', null), 401, 'unauthorized');
        // With one: 404 and 405 (+ Allow).
        self::assertEnvelope($this->call('GET', '/v1/nope', $key), 404, 'not_found');
        self::assertEnvelope($this->call('GET', '/v1/health/', $key), 404, 'not_found');
        self::assertEnvelope($this->call('GET', '/v2/health', $key), 404, 'not_found');
        $r = self::assertEnvelope($this->call('GET', '/v1/reservations', $key), 405, 'method_not_allowed');
        self::assertSame('POST', $r->header('allow'));
        $r = self::assertEnvelope($this->call('DELETE', '/v1/listings', $key), 405, 'method_not_allowed');
        self::assertSame('PUT', $r->header('allow'));
        self::assertEnvelope($this->call('POST', '/v1/health', $key, (object) []), 405, 'method_not_allowed');
    }
}
