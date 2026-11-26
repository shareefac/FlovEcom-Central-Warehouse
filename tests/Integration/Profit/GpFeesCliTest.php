<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1174 (docs/decisions.md GPF8): bin/gp_fees.php as the go-live kit and cron run it. A backfill asked for by hand while another run holds
 * the job's lock does nothing and says BUSY (exit 4: a kit gate never takes a skipped run for done); the 10-minute run finding it held is
 * skipped (exit 0); an unknown option is refused (exit 2); --status reads only; a backfill done says what it did.
 */
final class GpFeesCliTest extends IntegrationTestCase
{
    /** @return array{code: int, out: string, err: string} */
    private static function cli(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', "{$root}/bin/gp_fees.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    public function testTheLockTheBackfillAndTheStatus(): void
    {
        self::$db->exec("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'live')");
        (new Settings(self::$db))->set(Caller::system('test'), 'profit.gp_from_vpg_online', '2026-11-01', 'switched on for the test');
        self::assertSame(2, self::cli('--no-such-option')['code'], 'an unknown option is refused');
        $name = 'cw_job:gp_fees:' . TestDb::name();
        $name = strlen($name) > 64 ? 'cw_job:' . sha1("gp_fees\0" . TestDb::name()) : $name;
        self::assertSame(1, (int) self::$db->value('SELECT GET_LOCK(?, 0)', [$name]), 'this test holds the job\'s lock');
        try {
            $r = self::cli('--backfill', '--account=vpg_online', '--from=2026-11-01', '--to=2026-11-02');
            self::assertSame(4, $r['code'], 'a backfill asked for by hand while the lock is held: BUSY, nothing done: ' . $r['out'] . $r['err']);
            self::assertSame(0, self::cli()['code'], 'the 10-minute run finding it held: skipped');
            self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_run WHERE kind = 'backfill'"));
        } finally {
            self::$db->value('SELECT RELEASE_LOCK(?)', [$name]);
        }
        $r = self::cli('--backfill', '--account=vpg_online', '--from=2026-11-01', '--to=2026-11-02');
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('vpg_online (backfill): 0 months queued taken, 2 days gone over', $r['out'] . $r['err']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM gp_run WHERE kind = 'backfill' AND state = 'ok'"));
        $s = self::cli('--status');
        self::assertSame(0, $s['code']);
        self::assertStringContainsString('vpg_online: switched on, from 2026-11-01', $s['out']);
    }
}
