<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ops;

use CW\Caller;
use CW\Config;
use CW\CwException;
use CW\Db;
use CW\Heartbeat;
use CW\Ops\TestRefPurge;
use CW\Reservations;
use CW\Schema\Grants;
use CW\Stock;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use DateTimeImmutable;

/**
 * D47 (F9): bin/purge_test_refs.php removes what tests left on a staging channel, by order_ref prefix: open
 * reservations are neutralised through the normal paths (so the ledger records it), reservations and units
 * nothing refers to are deleted, the refs' idempotency rows go, ledger and audit rows never do.
 */
final class PurgeTestRefsTest extends StockTestCase
{
    private Caller $stg;
    private Caller $oth;
    private int $sku;

    /**
     * The fixture on channel `stg` with prefix tst-, plus rows that must survive. stg is live while the tests run (so a
     * reserve can be refused) and lowered to shadow at the end: the purge refuses a live channel.
     * tst-1 held unlinked · tst-2 committed unlinked · tst-3 committed, shipped, returned, unlinked · tst-4 a tombstone ·
     * tst-5 a refused reserve (no reservation, only its stored answer) · tst-6 held linked · tst-7 committed linked.
     */
    private function fixture(): void
    {
        $this->stg = $this->site('stg', 'live');
        $this->oth = $this->site('oth', 'live');
        $this->sku = $this->item('strict', 10);
        $this->listing($this->stg, 'U1', null);
        $this->listing($this->stg, 'L1', $this->sku);
        $this->listing($this->oth, 'U1', null);

        $this->ok($this->reserve($this->stg, 'tst-1', [self::line('U1', 't1a', 't1b')]));
        $this->ok($this->commit($this->stg, 'tst-2', [self::line('U1', 't2a', 't2b')]));
        $this->ok($this->commit($this->stg, 'tst-3', [self::line('U1', 't3a')]));
        $this->ship($this->stg, 'tst-3', ['t3a']);
        $this->ok($this->res->returnUnits($this->stg, 'tst-3', ['t3a'], $this->key('return')));
        $this->ok($this->release($this->stg, 'tst-4'));
        $refused = $this->reserve($this->stg, 'tst-5', [['variant_id' => 'L1', 'qty' => 11, 'unit_ids' => array_map(static fn (int $i): string => "t5-{$i}", range(1, 11))]]);
        self::assertSame(409, $refused->status);
        $this->ok($this->reserve($this->stg, 'tst-6', [self::line('L1', 't6a')]));
        $this->ok($this->commit($this->stg, 'tst-7', [self::line('L1', 't7a')]));
        // Must survive: another prefix, another channel, a staff key that looks like a test key.
        $this->ok($this->reserve($this->stg, 'ord-1', [self::line('U1', 'o1')]));
        $this->ok($this->reserve($this->oth, 'tst-1', [self::line('U1', 'x1')]));
        $this->ok($this->moves->record(self::staff(), ['type' => 'adjustment', 'lines' => [['sku_id' => $this->sku, 'qty' => 1]]], 'tst-staff-1'));
        // Heartbeats: one old one of stg (the test's), a fresh one of stg, an old one of oth.
        $hb = new Heartbeat(self::$db);
        foreach ([[$this->stg, 'hb-old'], [$this->stg, 'hb-new'], [$this->oth, 'hb-old']] as [$site, $key]) {
            $this->ok($hb->record($site, ['site_mode' => 'shadow', 'outbox_depth' => 0], $key));
        }
        foreach ([$this->stg, $this->oth] as $site) {
            self::$db->exec("UPDATE channel_health SET received_at = '2026-09-01 00:00:00' WHERE channel_id = ? ORDER BY id LIMIT 1", [$site->channelId]);
            self::$db->exec("UPDATE idempotency SET created_at = '2026-09-01 00:00:00' WHERE channel_id = ? AND idem_key = 'hb-old'", [$site->channelId]);
        }
        self::$db->exec("UPDATE channel SET mode = 'shadow' WHERE id = ?", [$this->stg->channelId]);
    }

    private function purge(): TestRefPurge
    {
        return new TestRefPurge(self::$db, $this->res);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $n = static fn (string $sql): int => (int) self::$db->value($sql);
        return ['reservation' => $n('SELECT COUNT(*) FROM reservation'), 'unit' => $n('SELECT COUNT(*) FROM reservation_unit'),
            'idempotency' => $n('SELECT COUNT(*) FROM idempotency'), 'ledger' => $n('SELECT COUNT(*) FROM stock_ledger'),
            'audit' => $n('SELECT COUNT(*) FROM audit_log'), 'health' => $n('SELECT COUNT(*) FROM channel_health')];
    }

    public function testDryRunThenApplyNeutralisesDeletesAndKeepsWhatTheLedgerNames(): void
    {
        $this->fixture();
        $until = new DateTimeImmutable('2026-09-02T00:00:00Z');
        $before = $this->counts();
        $auditIds = array_map('intval', self::$db->column('SELECT id FROM audit_log'));

        $plan = $this->purge()->plan('stg', 'tst-', $until);
        self::assertSame($before, $this->counts(), 'a dry run writes nothing');
        self::assertSame(['reservations' => 6, 'to_release' => 2, 'to_cancel_units' => 3, 'to_delete_reservations' => 4, 'to_delete_units' => 5,
            'kept_reservations' => 2, 'idempotency_rows' => 7, 'idempotency_rows_kept' => 2], $plan['totals'],
            'the keys of tst-1..5 go; those of the kept tst-6 and tst-7 stay');
        $by = array_column($plan['reservations'], null, 'order_ref');
        self::assertSame(['release', 'cancel', null, null, 'release', 'cancel'],
            array_map(static fn (string $r): ?string => $by[$r]['neutralise'], ['tst-1', 'tst-2', 'tst-3', 'tst-4', 'tst-6', 'tst-7']));
        self::assertSame([true, true, true, true, false, false],
            array_map(static fn (string $r): bool => $by[$r]['delete'], ['tst-1', 'tst-2', 'tst-3', 'tst-4', 'tst-6', 'tst-7']));
        self::assertSame(['ledger'], $by['tst-6']['keep_why']);
        self::assertSame(['returned' => 1], $by['tst-3']['by_state']);
        self::assertSame(['rows' => 1, 'idempotency_rows' => 1], array_intersect_key($plan['heartbeats'], ['rows' => 0, 'idempotency_rows' => 0]));

        $r = $this->purge()->apply('stg', 'tst-', $until, 'tester');
        $d = $r['done'];
        self::assertSame([2, 3, 4, 5, 7 + 2, 2 + 2, 1, 1], [$d['released'], $d['cancelled_units'], $d['deleted_reservations'], $d['deleted_units'],
            $d['idempotency_rows'], $d['idempotency_rows_kept'], $d['channel_health_rows'], $d['heartbeat_idempotency_rows']],
            'idempotency: 7 test rows and 2 of the purge\'s own go; the kept refs keep theirs (2 test, 2 purge)');
        self::assertSame([['order_ref' => 'tst-6', 'why' => ['ledger']], ['order_ref' => 'tst-7', 'why' => ['ledger']]], $d['kept']);
        self::assertSame([], $d['failed']);

        // What is left of the prefix on stg: the two linked orders, neutralised through the normal paths.
        self::assertSame([['tst-6', 'released'], ['tst-7', 'committed']], array_map(static fn (array $x): array => [$x['order_ref'], $x['status']],
            self::$db->all("SELECT order_ref, status FROM reservation WHERE channel_id = ? AND order_ref LIKE 'tst-%' ORDER BY order_ref", [$this->stg->channelId])));
        self::assertSame(['released', 'cancelled'], [$this->unitState($this->stg, 't6a'), $this->unitState($this->stg, 't7a')]);
        $this->assertBal(11, 0, 0, $this->sku);
        self::assertSame(['release', 'cancel'], self::$db->column("SELECT movement_type FROM stock_ledger WHERE actor = 'system:purge_test_refs' ORDER BY id"));
        // Ledger and audit rows are only ever added.
        $after = $this->counts();
        self::assertSame($before['ledger'] + 2, $after['ledger']);
        self::assertSame($auditIds, array_values(array_intersect($auditIds, array_map('intval', self::$db->column('SELECT id FROM audit_log')))));
        self::assertSame($before['audit'] + 4 + 4 + 1, $after['audit'], '4 release/cancel calls, 4 reservation.purged, 1 purge.test_refs');
        self::assertSame(4, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'reservation.purged' AND actor = 'system:purge_test_refs'"));
        $summary = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'purge.test_refs'"), true);
        self::assertSame(['tst-', 'tester', 4, 9, 4], [$summary['prefix'], $summary['by'], $summary['deleted_reservations'], $summary['idempotency_rows'],
            $summary['idempotency_rows_kept']]);
        // Untouched: another prefix, another channel, staff keys, the fresh heartbeat, the other channel's heartbeat.
        self::assertSame('held', $this->reservation($this->stg, 'ord-1')['status']);
        self::assertSame('held', $this->reservation($this->oth, 'tst-1')['status']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key = 'tst-staff-1'"));
        self::assertSame(['purge:cancel:tst-7:', 'purge:release:tst-6:1'], array_map(static fn (string $k): string => preg_replace('/[0-9a-f]{16}$/', '', $k),
            self::$db->column("SELECT idem_key FROM idempotency WHERE channel_id = ? AND idem_key LIKE 'purge:%' ORDER BY idem_key", [$this->stg->channelId])),
            'only the purge\'s keys of the kept refs stay');
        self::assertSame([1, 1], [(int) self::$db->value('SELECT COUNT(*) FROM channel_health WHERE channel_id = ?', [$this->stg->channelId]),
            (int) self::$db->value('SELECT COUNT(*) FROM channel_health WHERE channel_id = ?', [$this->oth->channelId])]);
        self::assertSame(['hb-new'], self::$db->column("SELECT idem_key FROM idempotency WHERE channel_id = ? AND path = '/v1/heartbeat'", [$this->stg->channelId]));
        self::assertSame(['ord-1', 'tst-1', 'tst-6', 'tst-7'], self::$db->column("SELECT DISTINCT entity_id FROM audit_log WHERE entity_type = 'reservation' AND idem_key IN "
            . '(SELECT idem_key FROM idempotency WHERE idempotency.channel_id = audit_log.channel_id) ORDER BY 1'));
        // A late retry under a kept ref's old key replays its answer: the cancelled unit is not allocated again (review fix).
        $commitKey = (string) self::$db->value("SELECT idem_key FROM audit_log WHERE entity_id = 'tst-7' AND action = 'reservation.commit' AND channel_id = ?",
            [$this->stg->channelId]);
        $retry = $this->res->commit($this->stg, 'tst-7', [self::line('L1', 't7a')], 'reserved', $commitKey);
        self::assertTrue($retry->replayed);
        self::assertSame('cancelled', $this->unitState($this->stg, 't7a'));
        $this->assertBal(11, 0, 0, $this->sku);

        // A second run finds only the two kept orders and changes nothing.
        $mid = $this->counts();
        $again = $this->purge()->apply('stg', 'tst-', $until, 'tester');
        self::assertSame([0, 0, 0, 0, 0], [$again['done']['released'], $again['done']['cancelled_units'], $again['done']['deleted_reservations'],
            $again['done']['idempotency_rows'], $again['done']['channel_health_rows']]);
        self::assertSame(['ledger' => $mid['ledger'], 'reservation' => $mid['reservation']], ['ledger' => $this->counts()['ledger'], 'reservation' => $this->counts()['reservation']]);
    }

    public function testTheAppLoginHasTheRightsItNeeds(): void
    {
        $this->fixture();
        $user = TestDb::config()->appDbUser();
        self::assertNotNull($user, 'app.env has no db_user');
        Grants::apply(self::$db, TestDb::name(), $user);
        $app = Db::connect(TestDb::config()->dbApp()->withDatabase(TestDb::name()));
        $r = (new TestRefPurge($app, new Reservations($app, new Stock($app))))->apply('stg', 'tst-', new DateTimeImmutable('2026-09-02T00:00:00Z'), 'tester');
        self::assertSame([2, 4, 9, 1, []], [$r['done']['released'], $r['done']['deleted_reservations'], $r['done']['idempotency_rows'],
            $r['done']['channel_health_rows'], $r['done']['failed']]);
    }

    public function testGuards(): void
    {
        $staging = Config::fromArrays([], ['environment' => 'staging']);
        self::assertNull(TestRefPurge::stagingRefusal($staging, 'cw_staging'));
        self::assertNull(TestRefPurge::stagingRefusal($staging, 'cw_test_cfu3'));
        self::assertStringContainsString('not a staging schema', (string) TestRefPurge::stagingRefusal($staging, 'cw_live'));
        self::assertStringContainsString('environment=staging', (string) TestRefPurge::stagingRefusal(Config::fromArrays([], ['environment' => 'production']), 'cw_staging'));
        self::assertStringContainsString('environment=staging', (string) TestRefPurge::stagingRefusal(Config::fromArrays([], []), 'cw_staging'));
        // An environment variable cannot stand in for the file.
        self::assertNotNull(TestRefPurge::stagingRefusal(Config::fromArrays([], [], ['CW_ENVIRONMENT' => 'staging']), 'cw_staging'));

        self::assertNull(TestRefPurge::prefixProblem('proto1-'));
        foreach (['', 'ab-', '1234', '0042-', 'tst%', 'tst_', "tst'", str_repeat('a', 33)] as $bad) {
            self::assertNotNull(TestRefPurge::prefixProblem($bad), $bad);
        }
        $this->site('stg', 'live');
        try {
            $this->purge()->plan('nosuch', 'tst-');
            self::fail('unknown channel');
        } catch (CwException $e) {
            self::assertSame('unknown_channel', $e->errorCode);
        }
        // A live channel's orders are real, whatever the staging marker says (review fix).
        try {
            $this->purge()->apply('stg', 'tst-', null, 'tester');
            self::fail('live channel');
        } catch (CwException $e) {
            self::assertSame('channel_live', $e->errorCode);
        }
        $r = self::tool("environment=staging\n", '--channel=stg', '--prefix=tst-');
        self::assertSame(1, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('REFUSED: channel_live', $r['err']);
    }

    public function testTheToolRefusesOffStagingAndRunsOnIt(): void
    {
        $this->fixture();
        $before = $this->counts();
        foreach (["environment=production\n", "# nothing\n"] as $env) {
            $r = self::tool($env, '--channel=stg', '--prefix=tst-', '--apply');
            self::assertSame(1, $r['code'], $r['out'] . $r['err']);
            self::assertStringContainsString('REFUSED', $r['err']);
        }
        foreach ([
            ['--channel=stg', '--prefix=ab-'], ['--channel=stg', '--prefix=1234'], ['--channel=stg', '--prefix=tst%'], ['--prefix=tst-'],
            ['--channel=stg', '--prefix=', '--apply'], ['--channel=stg', '--prefix', '--apply'], ['--channel=stg', '--prefix=tst-', '--prefix=ord-'],
            ['--channel=stg', '--prefix=tst-', '--heartbeats-until=2999-01-01T00:00:00Z'], ['--channel=stg', '--prefix=tst-', '--heartbeats-until=yesterday'],
        ] as $args) {
            $r = self::tool("environment=staging\n", ...$args);
            self::assertSame(2, $r['code'], implode(' ', $args) . ": {$r['out']} {$r['err']}");
        }
        self::assertSame(1, self::tool("environment=staging\n", '--channel=nosuch', '--prefix=tst-')['code']);
        self::assertSame($before, $this->counts());

        $dry = self::tool("environment = staging\n", '--channel=stg', '--prefix=tst-', '--heartbeats-until=2026-09-02T00:00:00Z');
        self::assertSame(0, $dry['code'], $dry['err']);
        self::assertStringContainsString('reservations: 6 (release 2, cancel 3 units); delete 4 with 5 units; keep 2', $dry['out']);
        self::assertStringContainsString('tst-6 held origin=reserved units=1 {"held":1} linked=1 ledger=1 oversell=0 then release -> keep (ledger)', $dry['out']);
        self::assertStringContainsString('idempotency rows of these refs: 7 (plus the rows of the release/cancel calls above); 2 kept (their reservation stays)', $dry['out']);
        self::assertStringContainsString('heartbeats until 2026-09-02T00:00:00.000000Z: 1 channel_health rows', $dry['out']);
        self::assertStringContainsString('dry run: nothing written', $dry['out']);
        self::assertSame($before, $this->counts());

        $run = self::tool("environment=staging\n", '--channel=stg', '--prefix=tst-', '--heartbeats-until=2026-09-02T00:00:00Z', '--actor=Hari', '--apply');
        self::assertSame(0, $run['code'], $run['out'] . $run['err']);
        self::assertStringContainsString('done: released 2, cancelled 3 units; deleted 4 reservations (5 units), 9 idempotency rows, 1 channel_health rows, '
            . '1 heartbeat idempotency rows; audited purge.test_refs by Hari', $run['out']);
        self::assertStringContainsString('kept: tst-7 (ledger)', $run['out']);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM reservation WHERE order_ref LIKE 'tst-%' AND channel_id = ?", [$this->stg->channelId]));
    }

    /**
     * Runs bin/purge_test_refs.php against this slot's schema with the admin login, and an app.env that holds only
     * $appEnv (the staging marker under test; the database host comes from db.env or the environment).
     *
     * @return array{code: int, out: string, err: string}
     */
    private static function tool(string $appEnv, string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $file = tempnam(sys_get_temp_dir(), 'cw_app_env_');
        file_put_contents($file, $appEnv);
        $admin = TestDb::config()->dbAdmin();
        $env = ['CW_APP_ENV' => $file, 'CW_DB_HOST' => $admin->host, 'CW_DB_PORT' => (string) $admin->port]
            + array_filter(getenv(), static fn (string $k): bool => !in_array($k, ['CW_APP_ENV', 'SUDO_USER'], true), ARRAY_FILTER_USE_KEY);
        try {
            $p = proc_open([PHP_BINARY, "{$root}/bin/purge_test_refs.php", '--db=' . TestDb::name(), '--admin', ...$args],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
            self::assertIsResource($p);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
        } finally {
            unlink($file);
        }
    }
}
