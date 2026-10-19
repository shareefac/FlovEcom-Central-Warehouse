<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The production box's own checks (deploy/production; docs/ops.md "The production server"): the restore test's comparison
 * (restore_check.php), the alert test (alert_test.php), the database password's prompt (set_db_password.sh) and what
 * provision.sh installs for them. What needs the two database
 * clusters was proved on the box (19 Oct 2026); these hold the refusals that come before any connection.
 */
final class ProductionOpsToolsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../deploy/production';
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw-prodops-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    /** No host, a host that is not a name, or --self-test without one: usage (exit 2), and nothing is read. */
    public function testTheRestoreCheckRefusesWhatIsNotAHostName(): void
    {
        foreach ([[], ['x; rm -rf /'], ['--self-test'], ['https://restore-test.example']] as $args) {
            $r = $this->run_(self::DIR . '/restore_check.php', $args);
            self::assertSame(2, $r['code'], implode(' ', $args) . ': ' . $r['err']);
            self::assertStringContainsString('usage', $r['err']);
        }
    }

    /** Production's own host (what db.env names) as "the copy": refused before any connection. */
    public function testTheRestoreCheckRefusesProductionsOwnHostBeforeConnecting(): void
    {
        $env = $this->files('db.prod.example.test');
        $r = $this->run_(self::DIR . '/restore_check.php', ['db.prod.example.test'], $env + ['CW_DB_HOST' => 'restore.example.test']);
        self::assertSame(2, $r['code'], $r['err']);
        self::assertStringContainsString("REFUSING: db.prod.example.test is production's own host", $r['err']);
        self::assertSame('', $r['out'], 'nothing compared');
    }

    /** The tables the running jobs write between the backup and the check are real tables of the schema. */
    public function testTheRestoreChecksBusyTablesAreTablesOfTheSchema(): void
    {
        $src = (string) file_get_contents(self::DIR . '/restore_check.php');
        self::assertSame(1, preg_match('/const BUSY = \[(.*?)\];/s', $src, $m));
        preg_match_all("/'([a-z_]+)'/", $m[1], $names);
        self::assertNotSame([], $names[1]);
        $sql = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob(__DIR__ . '/../../migrations/*.sql') ?: []));
        foreach ($names[1] as $t) {
            self::assertMatchesRegularExpression('/CREATE TABLE (IF NOT EXISTS )?`?' . $t . '`?\s*\(/i', $sql, "{$t} is a table of the schema");
        }
    }

    /** The alert test never prints the address (a secret setting): only its server. */
    public function testTheAlertTestNeverPrintsTheAddress(): void
    {
        $src = (string) file_get_contents(self::DIR . '/alert_test.php');
        self::assertSame(1, preg_match_all('/->webhook\(\$url,/', $src), 'it sends through CW\Ops\AlertSender');
        preg_match_all('/(echo|fwrite)\b[^;]*;/', $src, $m);
        self::assertNotSame([], $m[0]);
        foreach ($m[0] as $out) {
            self::assertStringNotContainsString('$url', $out, $out);
        }
    }

    /** provision.sh installs qrencode: a TOTP secret is shown as a QR in the DigitalOcean console, never copied anywhere. */
    public function testProvisionInstallsQrencode(): void
    {
        $src = (string) file_get_contents(self::DIR . '/provision.sh');
        self::assertSame(1, preg_match('/^PACKAGES=\((.*?)\)$/ms', $src, $m));
        self::assertContains('qrencode', preg_split('/\s+/', trim($m[1])) ?: []);
    }

    /**
     * set_db_password.sh: production's box only; the password read twice without echo and checked against the database (the new
     * file read as CW reads db.env, nothing inherited pointing it elsewhere) before it replaces db.env; a refused one leaves nothing.
     */
    public function testSetDbPasswordChecksThePasswordBeforeItReplacesTheFile(): void
    {
        $src = (string) file_get_contents(self::DIR . '/set_db_password.sh');
        self::assertStringContainsString('[[ $(hostname) == cw-prod-lon1-01 ]] || refuse', $src);
        self::assertSame(2, preg_match_all('/^read -rsp /m', $src), 'both reads hide what is typed');
        self::assertSame(0, preg_match('/^read -r?p /m', $src), 'no read shows it');
        self::assertSame(0, preg_match('/^set -[a-z]*x/m', $src), 'no trace prints it');
        $check = strpos($src, 'env -u CW_DB_HOST -u CW_DB_PORT -u CW_DB_ADMIN_USER -u CW_DB_ADMIN_PASSWORD CW_DB_ENV="$tmp"');
        $move = strpos($src, 'mv "$tmp" /etc/cw/db.env');
        self::assertIsInt($check);
        self::assertIsInt($move);
        self::assertLessThan($move, $check, 'checked before it replaces db.env');
        self::assertStringContainsString("trap 'rm -f \"\$tmp\"' EXIT", $src, 'a refused password leaves no file');
    }

    public function testThePhpToolsAreValidPhp(): void
    {
        foreach (['restore_check.php', 'alert_test.php'] as $f) {
            $r = $this->run_(null, ['-l', self::DIR . '/' . $f]);
            self::assertSame(0, $r['code'], $f . ': ' . $r['out'] . $r['err']);
        }
    }

    /** @return array<string, string> a db.env naming $host and an app.env, for Config::load */
    private function files(string $host): array
    {
        file_put_contents("{$this->dir}/db.env", "username = doadmin\npassword = not-a-real-one\nhost = {$host}\nport = 25060\nsslmode = REQUIRED\n");
        file_put_contents("{$this->dir}/app.env", "db_user = cw_app\ndb_password = not-a-real-one\ndb_name = cw_prod\ndb_host = {$host}\ndb_port = 25060\n");
        return ['CW_DB_ENV' => "{$this->dir}/db.env", 'CW_APP_ENV' => "{$this->dir}/app.env"];
    }

    /**
     * @param list<string> $args  @param array<string, string> $env added to this process's environment
     * @return array{code: int, out: string, err: string}
     */
    private function run_(?string $script, array $args, array $env = []): array
    {
        $cmd = $script === null ? [PHP_BINARY, ...$args] : [PHP_BINARY, $script, ...$args];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
        self::assertIsResource($p);
        $o = (string) stream_get_contents($pipes[1]);
        $e = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $o, 'err' => $e];
    }
}
