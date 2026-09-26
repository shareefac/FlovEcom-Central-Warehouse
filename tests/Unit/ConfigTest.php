<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Config;
use CW\ConfigException;
use CW\DbSettings;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private const DO_FORMAT = <<<'ENV'
        username = doadmin
        password = s3cr#t=value
        host = private-db.example.ondigitalocean.com
        port = 25060
        database = defaultdb
        sslmode = REQUIRED
        ENV;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw_cfg_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testParsesDigitalOceanFormatWithoutExecutingAnything(): void
    {
        $parsed = Config::parseEnv(self::DO_FORMAT . "\n# comment\n; also comment\n\nexport X_Y=\"quoted value\"\nZ='$(touch /tmp/pwned)'\n");
        self::assertSame('doadmin', $parsed['username']);
        self::assertSame('s3cr#t=value', $parsed['password'], 'no inline comments, only the first = splits');
        self::assertSame('25060', $parsed['port']);
        self::assertSame('quoted value', $parsed['x_y']);
        self::assertSame('$(touch /tmp/pwned)', $parsed['z'], 'kept literally, never expanded');
    }

    public function testMalformedLineFailsWithoutEchoingIt(): void
    {
        try {
            Config::parseEnv("host = x\nthis-line-holds-a-secret-XYZZY\n", 'db.env');
            self::fail('exception expected');
        } catch (ConfigException $e) {
            self::assertStringContainsString('db.env line 2', $e->getMessage());
            self::assertStringNotContainsString('XYZZY', $e->getMessage());
        }
    }

    public function testAdminProfileFromDoFileAndAppProfileFromAppEnv(): void
    {
        file_put_contents($this->dir . '/db.env', self::DO_FORMAT);
        file_put_contents($this->dir . '/app.env', "DB_USER=cw_app\nDB_PASSWORD=app-pass\nDB_NAME=cw_staging\nFEED_OVERLAP_SEC=30\n");
        $c = Config::load(['CW_DB_ENV' => $this->dir . '/db.env', 'CW_APP_ENV' => $this->dir . '/app.env']);

        $admin = $c->dbAdmin();
        self::assertSame('doadmin', $admin->user);
        self::assertSame('s3cr#t=value', $admin->password());
        self::assertSame('private-db.example.ondigitalocean.com', $admin->host);
        self::assertSame(25060, $admin->port);
        self::assertSame('REQUIRED', $admin->sslMode);
        self::assertNull($admin->database, 'admin never defaults to defaultdb');

        $app = $c->dbApp();
        self::assertSame('cw_app', $app->user);
        self::assertSame('app-pass', $app->password());
        self::assertSame('cw_staging', $app->database);
        self::assertSame($admin->host, $app->host);
        self::assertSame('30', $c->get('feed_overlap_sec'));
        self::assertSame('cw_staging', $c->appFile('db_name'));
    }

    public function testEnvironmentOverrides(): void
    {
        file_put_contents($this->dir . '/db.env', self::DO_FORMAT);
        file_put_contents($this->dir . '/app.env', "db_user=cw_app\ndb_password=app-pass\ndb_name=cw_staging\nfeed_overlap_sec=30\n");
        $c = Config::load([
            'CW_DB_ENV' => $this->dir . '/db.env',
            'CW_APP_ENV' => $this->dir . '/app.env',
            'CW_DB_NAME' => 'cw_test_base',
            'CW_DB_HOST' => 'other-host',
            'CW_DB_PORT' => '3307',
            'CW_DB_USER' => 'someone',
            'CW_DB_PASSWORD' => 'pw',
            'CW_FEED_OVERLAP_SEC' => '45',
            'CW_SLOT' => 'base',
        ]);
        self::assertSame('cw_test_base', $c->dbAdmin()->database);
        self::assertSame('cw_test_base', $c->dbApp()->database);
        self::assertSame('cw_staging', $c->appFile('db_name'), 'appFile ignores env overrides');
        self::assertSame('other-host', $c->dbApp()->host);
        self::assertSame(3307, $c->dbAdmin()->port);
        self::assertSame('someone', $c->dbApp()->user);
        self::assertSame('doadmin', $c->dbAdmin()->user);
        self::assertSame('45', $c->get('FEED_OVERLAP_SEC'));
        self::assertSame('base', $c->slot());
    }

    public function testAcceptsKeyEqualsValueAliasesForTheDbFile(): void
    {
        $c = Config::fromArrays(['DB_USERNAME' => 'u', 'DB_PASSWORD' => 'p', 'DB_HOST' => 'h', 'DB_PORT' => '3306', 'SSL_MODE' => 'verify_ca', 'SSLROOTCERT' => '/etc/cw/ca.crt']);
        $s = $c->dbAdmin();
        self::assertSame(['u', 'h', 3306, 'VERIFY_CA', '/etc/cw/ca.crt'], [$s->user, $s->host, $s->port, $s->sslMode, $s->sslCa]);
        self::assertTrue($s->verifiesServerCert());
    }

    public function testTlsCannotBeSwitchedOff(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArrays(['user' => 'u', 'password' => 'p', 'host' => 'h', 'sslmode' => 'DISABLED'])->dbAdmin();
    }

    public function testMissingAppCredentialsAreReported(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArrays(['user' => 'u', 'password' => 'p', 'host' => 'h'])->dbApp();
    }

    public function testPasswordNeverAppearsInDumps(): void
    {
        $s = new DbSettings('h', 3306, 'u', 'TOPSECRET-123', 'cw_x');
        foreach ([print_r($s, true), var_export($s, true), (string) json_encode($s), print_r((array) $s, true)] as $dump) {
            self::assertStringNotContainsString('TOPSECRET-123', $dump);
        }
        ob_start();
        var_dump($s);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('TOPSECRET-123', $dump);
        self::assertStringContainsString('(present)', $dump);
        $this->expectException(\LogicException::class);
        serialize($s);
    }

    public function testDerivedSettingsKeepTheirOwnPassword(): void
    {
        $a = new DbSettings('h', 3306, 'u', 'first', null);
        $b = $a->withDatabase('cw_x');
        $c = $b->withCredentials('v', 'second');
        unset($a);
        self::assertSame('first', $b->password());
        self::assertSame('cw_x', $b->database);
        self::assertSame(['v', 'second', 'cw_x'], [$c->user, $c->password(), $c->database]);
    }

    public function testRejectsBadSchemaNames(): void
    {
        $this->expectException(ConfigException::class);
        new DbSettings('h', 3306, 'u', 'p', 'cw_x; DROP DATABASE y');
    }
}
