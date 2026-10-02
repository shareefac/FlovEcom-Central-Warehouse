<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Api\ApiKey;
use CW\Api\Kernel;
use CW\Api\Request;
use CW\Api\Response;
use CW\Caller;
use CW\Db;

/**
 * Base for API tests that drive the real /v1 kernel (CW\Api\Kernel: auth, routing, the off gate,
 * Idempotency-Key, controllers, the envelope and its headers) in-process against this slot's test
 * schema, so they run in EVERY slot (the HTTP tests in tests/Integration/Api*Test.php run in slot api
 * only). The kernel uses the test's admin connection; fixtures are written as in StockTestCase and
 * the invariants are asserted after every test.
 */
abstract class ApiKernelTestCase extends StockTestCase
{
    public const CLIENT_IP = '198.51.100.7';

    /** @var list<string> what the kernel logged */
    protected array $logged = [];
    private ?Kernel $kernel = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kernel = null;
        $this->logged = [];
    }

    protected function kernel(): Kernel
    {
        $db = self::$db;
        return $this->kernel ??= new Kernel(static fn (): Db => $db, function (string $m): void {
            $this->logged[] = $m;
        });
    }

    /**
     * A site with an API key and an allowlist (default: CLIENT_IP).
     *
     * @param list<string> $ips
     * @return array{0: Caller, 1: string} the caller and its key
     */
    protected function apiSite(string $code = 'vpg', string $mode = 'live', array $ips = [self::CLIENT_IP]): array
    {
        $site = $this->site($code, $mode);
        $key = ApiKey::generate();
        self::$db->exec('UPDATE channel SET api_key_hash = ?, allowed_ips = ? WHERE id = ?',
            [ApiKey::hash($key), json_encode($ips, JSON_THROW_ON_ERROR), $site->channelId]);
        return [$site, $key];
    }

    /**
     * One request through the kernel. $body (array/object) is sent as JSON unless $raw is given. A POST
     * gets a fresh Idempotency-Key unless $idem is given ('' = send none).
     *
     * @param array<string, string> $headers
     */
    protected function call(string $method, string $uri, ?string $key, mixed $body = null, ?string $idem = null, array $headers = [],
        ?string $raw = null, string $ip = self::CLIENT_IP): Response
    {
        $h = [];
        if ($key !== null) {
            $h['Authorization'] = 'Bearer ' . $key;
        }
        if ($method === 'POST') {
            $idem ??= $this->key('http');
        }
        if ($idem !== null && $idem !== '') {
            $h['Idempotency-Key'] = $idem;
        }
        if ($raw === null && $body !== null) {
            $raw = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $h['Content-Type'] = 'application/json';
        }
        return $this->kernel()->handle(Request::create($method, $uri, $headers + $h, $raw ?? '', $ip));
    }

    /** The decoded envelope, after checking status and error code. @return array{ok: bool, data: mixed, error: mixed} */
    protected static function envelope(Response $r, int $status, ?string $code = null): array
    {
        $env = json_decode($r->json(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($status, $r->status, 'HTTP status: ' . $r->status . ' ' . substr($r->json(), 0, 600));
        self::assertSame(['ok', 'data', 'error'], array_keys($env));
        if ($code !== null) {
            self::assertSame($code, $env['error']['code'] ?? null, substr($r->json(), 0, 600));
        }
        return $env;
    }

    /** @return array<string, mixed> the envelope's data */
    protected static function data(Response $r, int $status = 200): array
    {
        $d = self::envelope($r, $status)['data'];
        self::assertIsArray($d);
        return $d;
    }

    protected static function header(Response $r, string $name): ?string
    {
        foreach ($r->headers() as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }
}
