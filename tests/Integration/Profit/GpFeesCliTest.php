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
            self::assertSame(4, self::cli()['code'], 'the 10-minute run finding it held: BUSY too (never a silent 0: the Codex review of 6043729, H4)');
            self::assertSame(4, self::cli('--stop', '--account=vpg_online', '--reason=the owner said stop')['code'], 'the quick stop finding it held: BUSY');
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

    /** bin/gp_fees.php started, not waited for (its pipes and process: finish() reads them). @return array{0: resource, 1: array<int, resource>} */
    private static function start(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', "{$root}/bin/gp_fees.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $root);
        self::assertIsResource($p);
        return [$p, $pipes];
    }

    /** @param array{0: resource, 1: array<int, resource>} $run @return array{code: int, out: string, err: string} */
    private static function finish(array $run): array
    {
        [$p, $pipes] = $run;
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    private static function store(\CW\Db $db, int $ch, string $ref, string $paid, int $grand, bool $open = false): void
    {
        $body = ['v' => 1, 'version' => 1, 'order_no' => '', 'status' => 'completed', 'placed_at' => $paid, 'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP',
            'parent_ref' => null, 'lines' => [], 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => $grand, 'parcels' => []];
        if (!$open) {
            (new \CW\Profit\OrderSnapshots($db))->store($ch, $ref, $body, 'api');
            return;
        }
        $now = \CW\Clock::now();
        (new \CW\Profit\OrderSnapshots($db))->apply($db, $ch, $ref, \CW\Profit\SnapshotBody::parse($body, $now), \CW\Clock::db($now));
    }

    /**
     * The final review of 7332729 (Codex H2, Fable F1): the 10-minute job ran while the account was off (it recorded the off settings), the account
     * was switched on and the November backfill counted its estimates, and no job ran after it: the quick stop still goes over every counted row
     * of the account (whatever the last run recorded) and says done only when nothing of it counts.
     */
    public function testTheQuickStopAfterABackfillWithNoGoodRunStopsEverything(): void
    {
        $ch = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'live')");
        self::assertSame(0, self::cli()['code'], 'the 10-minute job while the account is off');
        $s = new Settings(self::$db);
        $s->set(Caller::system('test'), 'profit.gp_from_vpg_online', '2026-11-01', 'switched on for the test');
        $s->set(Caller::system('test'), 'profit.gp_merchant_vpg_online', '55829291', 'the merchant number');
        self::store(self::$db, $ch, '9100001', '2026-11-19T10:00:00Z', 2999);
        $r = self::cli('--backfill', '--account=vpg_online', '--from=2026-11-01', '--to=2026-11-20');
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE account = 'vpg_online' AND state <> 'off'"));
        $r = self::cli('--stop', '--account=vpg_online', '--reason=the owner said stop');
        self::assertSame(0, $r['code'], 'stopped and checked: ' . $r['out'] . $r['err']);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE account = 'vpg_online' AND state <> 'off'"));
        self::assertSame(0, (int) self::$db->value('SELECT COALESCE(SUM(gp_fee) + SUM(gp_fee_est) + SUM(gp_other), 0) FROM profit_day'));
    }

    /**
     * The final review of 7332729 (Codex H4): the first card snapshot after the switch read the account switched on and has not committed when
     * the quick stop runs: the stop waits for it (the first day's change waits for every snapshot that read the old one), then goes over the
     * days and checks: nothing of the account counts once it says done.
     */
    public function testTheQuickStopWaitsForASnapshotThatReadTheOldSetting(): void
    {
        $ch = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'live')");
        $s = new Settings(self::$db);
        $s->set(Caller::system('test'), 'profit.gp_from_vpg_online', '2026-11-01', 'switched on for the test');
        $s->set(Caller::system('test'), 'profit.gp_merchant_vpg_online', '55829291', 'the merchant number');
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        try {
            self::store($other, $ch, '9100001', '2026-11-19T10:00:00Z', 2999, true); // read "switched on", its estimate written, not committed
            $run = self::start('--stop', '--account=vpg_online', '--reason=the owner said stop');
            usleep(1_500_000);
            $other->pdo()->commit();
            $r = self::finish($run);
        } finally {
            if ($other->inTransaction()) {
                $other->pdo()->rollBack();
            }
        }
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('vpg_online: stopped and checked: no card fee of it counts', $r['out']);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE account = 'vpg_online' AND state <> 'off'"),
            'the snapshot that read "on" is gone over too: nothing counts once the stop said done');
        self::assertSame(0, (int) self::$db->value('SELECT COALESCE(SUM(gp_fee) + SUM(gp_fee_est), 0) FROM profit_day'));
    }

    public function testTheQuickStopClearsTheAccountAndProvesIt(): void
    {
        $ch = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'live')");
        $s = new Settings(self::$db);
        $s->set(Caller::system('test'), 'profit.gp_from_vpg_online', '2026-10-01', 'switched on for the test');
        $s->set(Caller::system('test'), 'profit.gp_merchant_vpg_online', '55829291', 'the merchant number');
        (new \CW\Profit\OrderSnapshots(self::$db))->store($ch, '9100001', ['v' => 1, 'version' => 1, 'order_no' => '', 'status' => 'completed',
            'placed_at' => '2026-11-19T10:00:00Z', 'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [],
            'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 2999, 'parcels' => []], 'api');
        (new \CW\Profit\GpInvoices(self::$db))->add(Caller::system('test'), ['account' => 'vpg_online', 'month' => '2026-10', 'merchant_no' => '55829291',
            'invoice_date' => '2026-10-31', 'sales' => '0', 'net' => '25.00', 'vat' => '0', 'total' => '25.00']);
        self::assertSame(2530, (int) self::$db->value('SELECT SUM(gp_fee) + SUM(gp_other) FROM profit_day'), '30p estimated + the October invoice on no order');
        // the RUNBOOK's quick stop, as it is written there (with this schema)
        $r = self::cli('--stop', '--account=vpg_online', '--reason=the owner said stop');
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('vpg_online: stopped and checked: no card fee of it counts', $r['out']);
        self::assertSame('""', (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'profit.gp_from_vpg_online'"));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE account = 'vpg_online' AND state <> 'off'"));
        self::assertSame(0, (int) self::$db->value("SELECT SUM(other_pence) FROM gp_invoice WHERE account = 'vpg_online'"));
        self::assertSame(0, (int) self::$db->value('SELECT COALESCE(SUM(gp_fee) + SUM(gp_other), 0) FROM profit_day'));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_key = 'profit.gp_from_vpg_online' AND reason = 'the owner said stop'"));
    }
}
