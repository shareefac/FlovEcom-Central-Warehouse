<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Api\ApiKey;
use CW\Caller;
use CW\Schema\Grants;

/**
 * Base for the HTTP API tests (tests/Integration/Api*Test.php). They call the real API through
 * Apache + php-fpm on the staging box (http://127.0.0.1:8080, CW_API_URL), which serves the
 * api slot's test schema cw_test_api as the app login cw_app (deploy/staging/). Fixtures are
 * written directly with the admin connection (StockTestCase), answers are checked over HTTP,
 * and the nightly invariant check runs after every test.
 *
 * In any other slot the vhost serves a different schema than the one under test, so these tests
 * are skipped there (CW_API_SCHEMA overrides the served schema's name).
 */
abstract class ApiTestCase extends StockTestCase
{
    public const DEFAULT_URL = 'http://127.0.0.1:8080';
    public const SERVED_SCHEMA = 'cw_test_api';

    private static bool $granted = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $served = getenv('CW_API_SCHEMA') ?: self::SERVED_SCHEMA;
        if (TestDb::name() !== $served) {
            self::markTestSkipped("the API vhost serves {$served}; these tests run in slot api (schema " . TestDb::name() . ')');
        }
        if (!self::$granted) {
            // The API connects as cw_app, whose rights are table grants per schema: converge them
            // on the freshly migrated test schema (grants survive the DROP/CREATE of each run).
            self::restoreAppGrants();
            self::$granted = true;
        }
    }

    protected static function restoreAppGrants(): void
    {
        $user = TestDb::config()->appDbUser();
        self::assertNotNull($user, 'app.env has no db_user');
        Grants::apply(self::$db, TestDb::name(), $user);
    }

    protected static function url(): string
    {
        return rtrim(getenv('CW_API_URL') ?: self::DEFAULT_URL, '/');
    }

    /**
     * A site with an API key and an allowlist (default: this box's loopback address).
     *
     * @param list<string> $ips
     * @return array{0: Caller, 1: string} the caller (for in-process fixtures) and its key
     */
    protected function apiSite(string $code = 'vpg', string $mode = 'live', array $ips = ['127.0.0.1']): array
    {
        $site = $this->site($code, $mode);
        $key = ApiKey::generate();
        self::$db->exec('UPDATE channel SET api_key_hash = ?, allowed_ips = ? WHERE id = ?',
            [ApiKey::hash($key), json_encode($ips, JSON_THROW_ON_ERROR), $site->channelId]);
        return [$site, $key];
    }

    /**
     * One HTTP call. $body (array/object) is sent as JSON unless $raw is given. For POST an
     * Idempotency-Key is generated unless $idem is given ('' = send none).
     *
     * @param array<string, string|null> $headers null values are not sent
     */
    protected function call(string $method, string $path, ?string $key, mixed $body = null, ?string $idem = null,
        array $headers = [], ?string $raw = null): ApiResponse
    {
        $h = [];
        if ($key !== null) {
            $h['Authorization'] = 'Bearer ' . $key;
        }
        if ($method === 'POST') {
            $idem ??= $this->key('http');
            if ($idem !== '') {
                $h['Idempotency-Key'] = $idem;
            }
        } elseif ($idem !== null && $idem !== '') {
            $h['Idempotency-Key'] = $idem;
        }
        if ($raw === null && $body !== null) {
            $raw = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $h['Content-Type'] = 'application/json';
        }
        foreach ($headers as $k => $v) {
            $h[$k] = $v;
        }
        $ch = $this->handle($method, $path, $h, $raw);
        $out = curl_exec($ch);
        self::assertIsString($out, 'HTTP call failed: ' . curl_error($ch) . ' (is the API vhost installed? deploy/staging/install_api.sh)');
        return self::parse($ch, $out);
    }

    /**
     * The same requests fired in parallel (curl_multi).
     *
     * @param list<array{0: string, 1: string, 2: ?string, 3: mixed, 4?: ?string}> $calls [method, path, key, body, idem]
     * @return list<ApiResponse> in call order
     */
    protected function parallel(array $calls): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($calls as $i => $c) {
            $h = ['Content-Type' => 'application/json'];
            if ($c[2] !== null) {
                $h['Authorization'] = 'Bearer ' . $c[2];
            }
            if (($c[4] ?? null) !== null) {
                $h['Idempotency-Key'] = $c[4];
            }
            $handles[$i] = $this->handle($c[0], $c[1], $h, $c[3] === null ? null : json_encode($c[3], JSON_THROW_ON_ERROR));
            curl_multi_add_handle($mh, $handles[$i]);
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);
        $out = [];
        foreach ($handles as $i => $ch) {
            $raw = curl_multi_getcontent($ch);
            self::assertIsString($raw, 'parallel call failed: ' . curl_error($ch));
            $out[$i] = self::parse($ch, $raw);
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /**
     * An ISO-8601 time $seconds before now, in $offset. The API server runs on this box with the
     * real clock, and caller-reported times must be plausible (at most 5 min ahead; a count at
     * most 24 h old, a dispatch 90 days: R5), so HTTP tests use times relative to now.
     */
    protected static function ago(int $seconds, string $offset = '+00:00'): string
    {
        return (new \DateTimeImmutable('@' . (time() - $seconds)))->setTimezone(new \DateTimeZone($offset))->format('Y-m-d\TH:i:sP');
    }

    /** The UTC DB value ('Y-m-d H:i:s.000000') of an ago() time. */
    protected static function dbTime(string $iso): string
    {
        return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.000000');
    }

    /** Asserts the {ok, data, error{code, message}} envelope and returns the response. */
    protected static function assertEnvelope(ApiResponse $r, int $status, ?string $code = null): ApiResponse
    {
        self::assertSame($status, $r->status, 'HTTP status: ' . $r->describe());
        self::assertIsArray($r->json, 'JSON body: ' . $r->describe());
        self::assertSame(['ok', 'data', 'error'], array_keys($r->json), 'envelope keys');
        self::assertSame($status < 300, $r->json['ok']);
        self::assertStringStartsWith('application/json', (string) $r->header('content-type'));
        if ($status < 300) {
            self::assertNull($r->json['error']);
        } else {
            self::assertIsArray($r->json['error']);
            self::assertSame(['code', 'message'], array_keys($r->json['error']));
            self::assertIsString($r->json['error']['message']);
            if ($code !== null) {
                self::assertSame($code, $r->json['error']['code'], $r->describe());
            }
        }
        return $r;
    }

    /** Row counts + balances: equal before and after means "no effect". @return array<string, mixed> */
    protected function fingerprint(): array
    {
        $out = [];
        foreach (['stock_ledger', 'stock_change', 'reservation', 'reservation_unit', 'oversell_event', 'count_review',
            'goods_in_suspense', 'idempotency', 'channel_health', 'listing_profile'] as $t) {
            $out[$t] = (int) self::$db->value("SELECT COUNT(*) FROM {$t}");
        }
        $out['balances'] = self::$db->all('SELECT warehouse_id, sku_id, on_hand, allocated, held FROM stock_balance ORDER BY warehouse_id, sku_id');
        $out['units'] = self::$db->all('SELECT channel_id, unit_id, state FROM reservation_unit ORDER BY channel_id, unit_id');
        return $out;
    }

    /** @param array<string, string|null> $headers */
    private function handle(string $method, string $path, array $headers, ?string $raw): \CurlHandle
    {
        $ch = curl_init(self::url() . $path);
        $list = [];
        foreach ($headers as $k => $v) {
            if ($v !== null) {
                $list[] = $k . ': ' . $v;
            }
        }
        $list[] = 'Expect:';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $list,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 120,
        ]);
        if ($raw !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
        }
        return $ch;
    }

    private static function parse(\CurlHandle $ch, string $out): ApiResponse
    {
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headers = [];
        foreach (preg_split('/\r\n/', substr($out, 0, $headerSize)) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        $raw = substr($out, $headerSize);
        $json = json_decode($raw, true);
        return new ApiResponse($status, $headers, $raw, $json);
    }
}
