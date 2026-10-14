<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ops;

use CW\Clock;
use CW\Db;
use CW\Ops\Housekeeping;
use CW\Schema\Grants;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * The nightly clean-up (pack F; docs/decisions.md PF11): each table keeps its rows for its retention setting, each store's newest
 * heartbeat always stays, the audit log is kept at least 7 years and only the admin login removes older rows (the app login is refused
 * by the database: audit_log is append-only for it), in chunks; and the app login may not delete a queue row or a relay line.
 */
final class HousekeepingTest extends IntegrationTestCase
{
    private const DENIED = 1142;

    private static string $user;
    private static string $password;
    /** @var array<string, string> */
    private array $saved = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$user = substr('cw_h_' . preg_replace('/[^a-z0-9_]/', '', substr(TestDb::name(), strlen('cw_test_'))), 0, 32);
        self::$password = bin2hex(random_bytes(16)) . 'Aa1-';
        $server = TestDb::server();
        $account = Grants::account(self::$user);
        $server->pdo()->exec("DROP USER IF EXISTS {$account}");
        $server->pdo()->exec("CREATE USER {$account} IDENTIFIED BY " . $server->pdo()->quote(self::$password) . ' REQUIRE SSL');
        Grants::apply(self::$db, TestDb::name(), self::$user);
    }

    public static function tearDownAfterClass(): void
    {
        TestDb::server()->pdo()->exec('DROP USER IF EXISTS ' . Grants::account(self::$user));
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->saved = [];
    }

    public function testEachTableKeepsItsRetentionAndEachStoresNewestHeartbeat(): void
    {
        [$vpg, $alt] = $this->fill();
        $this->set('housekeeping.chunk_rows', '100');
        $now = Clock::now();
        $app = new Housekeeping($this->app());

        $dry = $app->run(new Settings(self::$db), true);
        self::assertSame([250, 0 + 2 + 1, 0], [$dry['idempotency']['removed'], $dry['channel_health']['removed'], $dry['audit_log']['removed']]);
        self::assertSame(254, (int) self::$db->value('SELECT COUNT(*) FROM idempotency'), 'a dry run removes nothing');
        self::assertArrayHasKey('kept_reason', $dry['audit_log'], 'the app login is told it may not delete audit rows');

        $r = $app->run(new Settings(self::$db));
        self::assertSame(250, $r['idempotency']['removed'], 'in chunks of 100: three statements');
        self::assertTrue($r['idempotency']['done']);
        self::assertSame(4, (int) self::$db->value('SELECT COUNT(*) FROM idempotency'), 'the four younger than 30 days stay');
        self::assertSame(3, $r['channel_health']['removed']);
        // vpg: its two recent rows; alt: only its newest, although it is 40 days old (it went quiet: "last contact" stays readable).
        self::assertSame([[$vpg, 2], [$alt, 1]], array_map(static fn (array $x): array => [(int) $x['channel_id'], (int) $x['n']],
            self::$db->all('SELECT channel_id, COUNT(*) AS n FROM channel_health GROUP BY channel_id ORDER BY channel_id')));
        self::assertSame(['removed' => 0, 'kept' => true], ['removed' => $r['audit_log']['removed'], 'kept' => isset($r['audit_log']['kept_reason'])]);
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM audit_log'), 'the app login never removes audit rows');

        // The admin login removes audit rows older than the retention (7 years at the least), and nothing younger.
        $this->set('housekeeping.audit_keep_years', '7');
        $admin = (new Housekeeping(self::$db))->run(new Settings(self::$db));
        self::assertSame(1, $admin['audit_log']['removed']);
        self::assertSame(['six years ago', 'today'], array_map('strval', self::$db->column('SELECT action FROM audit_log ORDER BY created_at')));
        self::assertGreaterThan(Clock::db($now->modify('-7 years -1 day')), (string) self::$db->value('SELECT MIN(created_at) FROM audit_log'));
        // A retention below its floor is never used, even if the row were changed behind the screens' back.
        $this->set('housekeeping.audit_keep_years', '1');
        $this->set('housekeeping.idempotency_keep_days', '1');
        $floor = (new Housekeeping(self::$db))->run(new Settings(self::$db));
        self::assertSame([0, 0], [$floor['audit_log']['removed'], $floor['idempotency']['removed']]);
    }

    /** Review finding 9 (Codex, 14 Oct 2026): the time limit is checked before every chunk, so nothing starts once it has passed. */
    public function testTheTimeLimitIsCheckedBeforeEveryChunk(): void
    {
        $this->fill();
        $this->set('housekeeping.chunk_rows', '100');
        $calls = 0;
        // The clock: the start, the first chunk's check, then far past any limit.
        $tick = static function () use (&$calls): int {
            return $calls++ < 2 ? 0 : PHP_INT_MAX;
        };
        $r = (new Housekeeping(self::$db, null, $tick))->run(new Settings(self::$db));
        self::assertSame([100, false], [$r['idempotency']['removed'], $r['idempotency']['done']], 'one chunk, then the limit');
        self::assertSame([0, false], [$r['channel_health']['removed'], $r['channel_health']['done']], 'no chunk starts after the limit');
        self::assertSame([0, false], [$r['audit_log']['removed'], $r['audit_log']['done']]);
        self::assertSame(154, (int) self::$db->value('SELECT COUNT(*) FROM idempotency'));
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM audit_log'));
    }

    public function testTheAppLoginMayNotRemoveQueueRowsRelayLinesOrNotes(): void
    {
        $app = $this->app();
        foreach (['DELETE FROM goods_in_suspense', 'DELETE FROM count_review', 'DELETE FROM policy_review', 'DELETE FROM oversell_event',
            'DELETE FROM relay_line', 'DELETE FROM queue_note', "UPDATE queue_note SET note = 'changed'", 'UPDATE relay_line SET doc_ref = \'x\'',
            'DELETE FROM audit_log'] as $sql) {
            $code = self::mysqlError(static fn () => $app->exec($sql));
            self::assertContains($code, [self::DENIED, 1143], $sql);
        }
        self::assertSame(0, $app->exec("UPDATE relay_line SET outcome = 'booked', sku_id = NULL, units = NULL, queue_id = NULL, reason = NULL WHERE 1 = 0"));
        self::assertSame(0, $app->exec("UPDATE goods_in_suspense SET status = 'resolved' WHERE 1 = 0"), 'a row is settled in place');
        self::assertSame(0, $app->exec('DELETE FROM alert_state WHERE 1 = 0'));
    }

    /** @return array{0: int, 1: int} the two channels */
    private function fill(): array
    {
        $vpg = self::makeChannel('vpg', 'live');
        $alt = self::makeChannel('alt', 'shadow');
        $now = Clock::now();
        $old = Clock::db($now->modify('-40 days'));
        $young = Clock::db($now->modify('-2 days'));
        $values = [];
        for ($i = 0; $i < 250; $i++) {
            $values[] = sprintf("(%d, '', 'old-%d', 'POST', '/v1/heartbeat', REPEAT('0', 64), 200, JSON_OBJECT(), '%s')", $vpg, $i, $old);
        }
        for ($i = 0; $i < 4; $i++) {
            $values[] = sprintf("(%d, '', 'young-%d', 'POST', '/v1/heartbeat', REPEAT('0', 64), 200, JSON_OBJECT(), '%s')", $vpg, $i, $young);
        }
        self::$db->exec('INSERT INTO idempotency (channel_id, source, idem_key, method, path, request_hash, response_status, response_body, created_at) VALUES '
            . implode(', ', $values));
        $hb = 'INSERT INTO channel_health (channel_id, received_at, site_mode) VALUES (?, ?, ?)';
        self::$db->exec($hb, [$vpg, $old, 'live']);
        self::$db->exec($hb, [$vpg, $old, 'live']);
        self::$db->exec($hb, [$vpg, $young, 'live']);
        self::$db->exec($hb, [$vpg, Clock::db($now), 'live']);
        self::$db->exec($hb, [$alt, Clock::db($now->modify('-45 days')), 'shadow']);
        self::$db->exec($hb, [$alt, $old, 'shadow']);
        $audit = 'INSERT INTO audit_log (actor, action, created_at) VALUES (\'system:test\', ?, ?)';
        self::$db->exec($audit, ['eight years ago', Clock::db($now->modify('-8 years'))]);
        self::$db->exec($audit, ['six years ago', Clock::db($now->modify('-6 years'))]);
        self::$db->exec($audit, ['today', Clock::db($now)]);
        return [$vpg, $alt];
    }

    private function app(): Db
    {
        return Db::connect(TestDb::config()->dbAdmin()->withCredentials(self::$user, self::$password)->withDatabase(TestDb::name()));
    }

    private function set(string $key, string $json): void
    {
        $this->saved[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }
}
