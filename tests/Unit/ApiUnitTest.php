<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Api\ApiKey;
use CW\Api\IpAllowlist;
use CW\Api\Kernel;
use CW\Api\Request;
use CW\Api\Response;
use CW\Api\Router;
use CW\Config;
use CW\ConfigException;
use CW\CwException;
use CW\OpResult;
use PHPUnit\Framework\TestCase;

/** The API pieces that need no database or web server. */
final class ApiUnitTest extends TestCase
{
    public function testIpAllowlist(): void
    {
        $list = ['203.0.113.7', '198.51.100.0/24', '10.0.0.0/9', '2001:db8::/48', 'junk', '0.0.0.0/0', '::/0', 7];
        foreach (['203.0.113.7', '198.51.100.0', '198.51.100.255', '10.127.255.255', '2001:db8:0:ffff::1', '::ffff:203.0.113.7'] as $ip) {
            self::assertTrue(IpAllowlist::allows($ip, $list), $ip);
        }
        foreach (['203.0.113.8', '198.51.101.1', '10.128.0.0', '2001:db8:1::1', '127.0.0.1', '', 'localhost', '::1', '1.2.3.4/32'] as $ip) {
            self::assertFalse(IpAllowlist::allows($ip, $list), $ip);
        }
        self::assertFalse(IpAllowlist::allows('127.0.0.1', []), 'an empty list allows nobody');
        foreach (['127.0.0.1', '10.0.0.0/8', '::1', '2001:db8::/128', ' 10.1.2.3 '] as $ok) {
            self::assertTrue(IpAllowlist::isValidEntry($ok), $ok);
        }
        foreach (['0.0.0.0/0', '::/0', '10.0.0.0/33', '10.0.0.0/', '10.0.0.0/8/8', '10.0.0/8', 'host', '10.0.0.0/-1', '10.0.0.0/08x'] as $bad) {
            self::assertFalse(IpAllowlist::isValidEntry($bad), $bad);
        }
    }

    public function testRouter(): void
    {
        $r = new Router();
        $r->add('POST', '/v1/reservations/{ref}/commit', static fn () => Response::ok(null), true);
        $r->add('GET', '/v1/health', static fn () => Response::ok(null));
        [$route, $params] = $r->match('POST', '/v1/reservations/A%2F1.x/commit');
        self::assertTrue($route->stockWrite);
        self::assertSame(['ref' => 'A%2F1.x'], $params);
        foreach (['/v1/reservations//commit', '/v1/reservations/a/b/commit', '/v1/health/', '/v1/healthz', '/v1/health/../health'] as $path) {
            try {
                $r->match('GET', $path);
                self::fail("{$path} matched");
            } catch (CwException $e) {
                self::assertSame(404, $e->httpStatus, $path);
            }
        }
        try {
            $r->match('PUT', '/v1/health');
            self::fail('405 expected');
        } catch (CwException $e) {
            self::assertSame([405, ['GET']], [$e->httpStatus, $e->detail['allow']]);
        }
    }

    public function testEnvelopeIsCanonicalAndCarriesNoInternals(): void
    {
        $a = Response::fromOpResult(OpResult::of(201, ['z' => 1, 'a' => ['y' => 2, 'b' => [3, 1]]]));
        $b = Response::fromOpResult(new OpResult(201, ['a' => ['b' => [3, 1], 'y' => 2], 'z' => 1], true));
        self::assertSame('{"ok":true,"data":{"a":{"b":[3,1],"y":2},"z":1},"error":null}', $a->json());
        self::assertSame($a->json(), $b->json(), 'a replay (keys re-ordered by MySQL) is byte-identical');
        self::assertSame('true', $b->headers()['Idempotent-Replayed']);
        self::assertArrayNotHasKey('Idempotent-Replayed', $a->headers());

        $e = Response::fromOpResult(OpResult::of(409, ['error' => 'refused', 'reasons' => ['short']]));
        self::assertSame('{"ok":false,"data":{"reasons":["short"]},"error":{"code":"refused","message":"the order cannot be held; see data.lines"}}', $e->json());
        $x = Response::fromException(new CwException('bad_lines', 'lines must be a non-empty list', 400));
        self::assertSame([400, '{"ok":false,"data":null,"error":{"code":"bad_lines","message":"lines must be a non-empty list"}}'], [$x->status, $x->json()]);
        self::assertSame('{"ok":true,"data":{},"error":null}', Response::ok([])->json());
    }

    public function testKernelFailsClosedWithoutLeaking(): void
    {
        $logged = [];
        $log = static function (string $m) use (&$logged): void {
            $logged[] = $m;
        };
        // No database: 503, with the reason only in the log.
        $k = new Kernel(static fn () => throw new ConfigException('app database credentials missing (/etc/cw/app.env)'), $log);
        $r = $k->handle(Request::create('GET', '/v1/health', ['Authorization' => 'Bearer ' . ApiKey::generate()]));
        self::assertSame(503, $r->status);
        self::assertStringNotContainsString('app.env', $r->json());
        self::assertStringContainsString('app.env', $logged[0]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $r->headers()['X-Request-Id']);

        // An unexpected error: 500 "internal" + request id, details in the log only.
        $k = new Kernel(static fn () => throw new \LogicException('secret detail in /opt/cw/src/Thing.php'), $log);
        $r = $k->handle(Request::create('GET', '/v1/health', ['Authorization' => 'Bearer ' . ApiKey::generate()]));
        self::assertSame(500, $r->status);
        self::assertSame('internal', $r->envelope()['error']['code']);
        self::assertStringNotContainsString('secret', $r->json());
        self::assertStringContainsString('secret detail', end($logged));
    }

    public function testMissingKeyIs401WithoutTouchingTheDatabase(): void
    {
        $k = new Kernel(static fn () => throw new \LogicException('must not connect'), static function (string $m): void {
        });
        foreach ([[], ['Authorization' => 'Basic abc'], ['Authorization' => 'Bearer short']] as $h) {
            $r = $k->handle(Request::create('POST', '/v1/reservations', $h));
            self::assertSame(401, $r->status);
            self::assertSame('Bearer', $r->headers()['WWW-Authenticate']);
        }
    }

    public function testKeysAndAppOnlyConfig(): void
    {
        $k = ApiKey::generate();
        self::assertMatchesRegularExpression('/^cwk_[0-9a-f]{64}$/', $k);
        self::assertNotSame($k, ApiKey::generate());
        self::assertSame(hash('sha256', $k), ApiKey::hash($k));

        $dir = sys_get_temp_dir() . '/cw_api_cfg_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            file_put_contents("{$dir}/app.env", "db_user=cw_app\ndb_password=pw\ndb_name=cw_staging\ndb_host=db.internal\ndb_port=25060\ndb_sslmode=VERIFY_CA\ndb_ssl_ca={$dir}/ca.crt\n");
            file_put_contents("{$dir}/db.env", "username = doadmin\npassword = adminpw\nhost = other.host\nport = 1\n");
            $c = Config::loadApp(['CW_APP_ENV' => "{$dir}/app.env", 'CW_DB_ENV' => "{$dir}/db.env", 'CW_DB_NAME' => 'cw_test_api']);
            $s = $c->dbApp();
            self::assertSame(['db.internal', 25060, 'cw_app', 'cw_test_api', 'VERIFY_CA', "{$dir}/ca.crt"],
                [$s->host, $s->port, $s->user, $s->database, $s->sslMode, $s->sslCa]);
            try {
                $c->dbAdmin();
                self::fail('the app-only config must not know the admin login');
            } catch (ConfigException) {
                self::assertTrue(true);
            }
            try {
                Config::loadApp(['CW_APP_ENV' => "{$dir}/missing.env"]);
                self::fail('a missing app.env must fail');
            } catch (ConfigException) {
                self::assertTrue(true);
            }
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
    }
}
