<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Invoices\ErpInvoiceCopy;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * bin/copy_erp_invoices.php as cron runs it (CW-917; docs/decisions.md EC15): the main switch off, it says so and reads nothing (exit 0);
 * on without CW's read-only ERPNext user in app.env, it stops naming the missing keys and nothing else (exit 3), and writes no run; a
 * mistyped command line ends with the usage before anything is read (exit 2); a job's reason that cannot cancel a copy stops it before it
 * reads anything (exit 3, naming the reason and where to put it right).
 */
final class ErpCopyJobTest extends IntegrationTestCase
{
    private string $was = '';
    private string $env = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->was = (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [ErpInvoiceCopy::SWITCH]);
        // An app.env with no ERPNext keys (the job reads only those three from it: the database is the admin login of db.env).
        $this->env = (string) tempnam(sys_get_temp_dir(), 'cw_ec_env');
        file_put_contents($this->env, "# no ERPNext read-only user here\nui_secret_key=unused\n");
    }

    protected function tearDown(): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$this->was, ErpInvoiceCopy::SWITCH]);
        @unlink($this->env);
        parent::tearDown();
    }

    /** @param list<string> $args @return array{code: int, out: string, err: string} */
    private function job(array $args): array
    {
        $root = dirname(__DIR__, 3);
        $env = getenv();
        foreach (['CW_ERP_READ_URL', 'CW_ERP_READ_KEY', 'CW_ERP_READ_SECRET'] as $k) {
            unset($env[$k]);
        }
        $env['CW_APP_ENV'] = $this->env;
        $p = proc_open([PHP_BINARY, "{$root}/bin/copy_erp_invoices.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root,
            $env);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    public function testOffItSaysSoUnconfiguredItStopsAndAMistypedLineIsRefused(): void
    {
        $r = $this->job(['--apply']);
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('off: the setting erp_copy.on is off', $r['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM erp_copy_run'));

        self::$db->exec("UPDATE app_setting SET value_json = CAST('true' AS JSON) WHERE setting_key = ?", [ErpInvoiceCopy::SWITCH]);
        $r = $this->job([]);
        self::assertSame(3, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('missing in app.env: erp_read_url, erp_read_key, erp_read_secret', $r['err']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM erp_copy_run'), 'nothing read, no run');

        self::$db->exec("UPDATE reason_code SET is_active = 0 WHERE code = 'erp_cancelled'");
        try {
            $r = $this->job(['--apply']);
        } finally {
            self::$db->exec("UPDATE reason_code SET is_active = 1 WHERE code = 'erp_cancelled'");
        }
        self::assertSame(3, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('cannot run: the reason erp_cancelled is switched off', $r['err']);
        self::assertStringNotContainsString('erp_read_unconfigured', $r['err'], 'stopped before the reader is even made');

        $r = $this->job(['--from=2026-13-01']);
        self::assertSame(2, $r['code']);
        self::assertStringContainsString('--from must be a date', $r['err']);
        $r = $this->job(['--apply=yes']);
        self::assertSame(2, $r['code']);
        self::assertStringContainsString('--apply takes no value', $r['err']);
    }
}
