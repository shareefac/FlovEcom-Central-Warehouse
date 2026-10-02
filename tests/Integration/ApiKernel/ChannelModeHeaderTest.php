<?php

declare(strict_types=1);

namespace CW\Tests\Integration\ApiKernel;

use CW\Api\Kernel;
use CW\Api\Request;
use CW\Api\Response;
use CW\ConfigException;
use CW\Tests\Support\ApiKernelTestCase;

/**
 * A13 (F1): every answer to an authenticated caller carries X-CW-Channel-Mode, errors and replays
 * included; no answer before authentication does.
 */
final class ChannelModeHeaderTest extends ApiKernelTestCase
{
    public function testEveryRouteAnswersWithTheModeAfterAuthentication(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'shadow');
        $this->listing($site, 'V1', $this->item('strict', 5));
        $routes = $this->kernel()->router()->routes();
        self::assertGreaterThanOrEqual(16, count($routes));
        foreach (['shadow', 'live', 'off'] as $mode) {
            self::$db->exec('UPDATE channel SET mode = ? WHERE id = ?', [$mode, $site->channelId]);
            foreach ($routes as $route) {
                $path = str_replace('{ref}', 'R-' . $mode, $route->pattern);
                // An empty object: most routes refuse it (400), some accept it; either way the
                // caller is authenticated and learns the mode.
                $r = $this->call($route->method, $path . ($route->method === 'GET' ? $this->query($path) : ''), $key,
                    $route->method === 'GET' ? null : (object) []);
                self::assertSame($mode, self::header($r, Kernel::MODE_HEADER), "{$route->method} {$path} answered {$r->status} without the mode");
                self::assertNotNull(self::header($r, 'X-Request-Id'));
                if ($mode === 'off' && $route->stockWrite) {
                    self::envelope($r, 409, 'channel_off');
                }
            }
        }
        // health's body and the header agree
        self::$db->exec("UPDATE channel SET mode = 'live' WHERE id = ?", [$site->channelId]);
        $r = $this->call('GET', '/v1/health', $key);
        self::assertSame(['vpg', 'live'], [self::data($r)['channel'], self::data($r)['mode']]);
        self::assertSame('live', self::header($r, Kernel::MODE_HEADER));
    }

    public function testErrorsAfterAuthenticationCarryTheMode(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'live');
        $this->listing($site, 'V1', $this->item('strict', 1));
        $cases = [
            'not found' => [$this->call('GET', '/v1/nope', $key), 404, 'not_found'],
            'method' => [$this->call('GET', '/v1/reservations', $key), 405, 'method_not_allowed'],
            'no idempotency key' => [$this->call('POST', '/v1/heartbeat', $key, (object) [], ''), 400, 'idempotency_key_required'],
            'bad key' => [$this->call('POST', '/v1/heartbeat', $key, (object) [], 'bad key'), 400, 'bad_idempotency_key'],
            'media type' => [$this->call('POST', '/v1/heartbeat', $key, null, null, ['Content-Type' => 'text/plain'], '{}'), 415, 'unsupported_media_type'],
            'bad json' => [$this->call('POST', '/v1/reservations', $key, null, null, ['Content-Type' => 'application/json'], '{'), 400, 'bad_json'],
            'bad query' => [$this->call('GET', '/v1/changes?after=x', $key), 400, 'bad_query'],
            'domain refusal' => [$this->call('POST', '/v1/reservations', $key, ['order_ref' => 'R1', 'lines' => [self::line('V1', 'a', 'b')]]), 409, 'refused'],
            'unknown order' => [$this->call('POST', '/v1/reservations/R9/ship', $key, ['unit_ids' => ['x'], 'dispatched_at' => self::isoAgo(60)]), 404, 'unknown_order'],
        ];
        foreach ($cases as $what => [$r, $status, $code]) {
            self::envelope($r, $status, $code);
            self::assertSame('live', self::header($r, Kernel::MODE_HEADER), $what);
        }
        self::assertSame('POST', self::header($cases['method'][0], 'Allow'));

        // An unexpected failure after authentication: 500 without internals, still with the mode.
        $this->kernel()->router()->add('GET', '/v1/boom', static fn (): Response => throw new \RuntimeException('secret detail'));
        $r = $this->call('GET', '/v1/boom', $key);
        self::envelope($r, 500, 'internal');
        self::assertStringNotContainsString('secret', $r->json());
        self::assertSame('live', self::header($r, Kernel::MODE_HEADER));
    }

    public function testNoModeBeforeAuthentication(): void
    {
        [, $key] = $this->apiSite('vpg', 'live');
        [, $noIps] = $this->apiSite('quiet', 'shadow', []);
        $cases = [
            'no key' => [$this->call('GET', '/v1/health', null), 401],
            'malformed' => [$this->call('GET', '/v1/health', null, headers: ['Authorization' => 'Bearer x']), 401],
            'unknown key' => [$this->call('GET', '/v1/health', str_repeat('a', 64)), 401],
            'unknown path, no key' => [$this->call('GET', '/v1/nope', null), 401],
            'wrong address' => [$this->call('GET', '/v1/health', $key, ip: '203.0.113.9'), 403],
            'empty allowlist' => [$this->call('POST', '/v1/heartbeat', $noIps, (object) []), 403],
        ];
        foreach ($cases as $what => [$r, $status]) {
            self::envelope($r, $status);
            self::assertNull(self::header($r, Kernel::MODE_HEADER), $what);
            self::assertNotNull(self::header($r, 'X-Request-Id'), $what);
        }
        // The database is unreachable: 503, and nobody was authenticated.
        $down = new Kernel(static fn () => throw new ConfigException('app database credentials missing'), static function (): void {
        });
        $r = $down->handle(Request::create('GET', '/v1/health', ['Authorization' => 'Bearer ' . $key], '', self::CLIENT_IP));
        self::envelope($r, 503, 'unavailable');
        self::assertNull(self::header($r, Kernel::MODE_HEADER));
    }

    public function testTheHeaderIsTheCurrentModeEvenOnAReplay(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'shadow');
        $first = $this->call('POST', '/v1/heartbeat', $key, ['site_mode' => 'shadow'], 'hb-1');
        self::assertSame('shadow', self::data($first)['mode']);
        self::assertSame('shadow', self::header($first, Kernel::MODE_HEADER));

        // CW switches the site live: the stored answer replays as it was, the header says live.
        self::$db->exec("UPDATE channel SET mode = 'live' WHERE id = ?", [$site->channelId]);
        $again = $this->call('POST', '/v1/heartbeat', $key, ['site_mode' => 'shadow'], 'hb-1');
        self::assertSame('true', self::header($again, 'Idempotent-Replayed'));
        self::assertSame($first->json(), $again->json(), 'the stored answer replays byte for byte');
        self::assertSame('live', self::header($again, Kernel::MODE_HEADER));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM channel_health WHERE channel_id = ?', [$site->channelId]));
    }

    /** A valid query for the GET routes that need one. */
    private function query(string $path): string
    {
        return $path === '/v1/availability' ? '?variant_ids=V1' : '';
    }

    private static function isoAgo(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() - $seconds);
    }
}
