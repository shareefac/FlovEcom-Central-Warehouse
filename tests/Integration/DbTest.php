<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

final class DbTest extends IntegrationTestCase
{
    public function testFetchHelpers(): void
    {
        $id = self::$db->insert("INSERT INTO sku (name, brand) VALUES (:name, :brand)", ['name' => 'Helper item', 'brand' => 'Acme']);
        self::assertGreaterThan(0, $id);
        self::assertSame(1, self::$db->exec('UPDATE sku SET brand = ? WHERE id = ?', ['Acme 2', $id]));
        self::assertSame(['name' => 'Helper item', 'brand' => 'Acme 2'], self::$db->one('SELECT name, brand FROM sku WHERE id = ?', [$id]));
        self::assertNull(self::$db->one('SELECT name FROM sku WHERE id = ?', [$id + 1000]));
        self::assertNull(self::$db->value('SELECT name FROM sku WHERE id = ?', [$id + 1000]));
        self::assertSame($id, self::$db->value('SELECT id FROM sku WHERE id = ?', [$id]), 'native ints, not strings');
        self::assertSame(['MAIN', 'UNSTAMPED', 'VERIFY'], self::$db->column('SELECT code FROM warehouse ORDER BY code'));
        self::assertCount(1, self::$db->all('SELECT id FROM warehouse WHERE is_sellable = ?', [true]));
    }

    public function testTransactionCommitsAndReturnsTheResult(): void
    {
        $id = self::$db->transaction(fn (Db $db) => $db->insert("INSERT INTO sku (name) VALUES ('committed')"));
        self::assertFalse(self::$db->inTransaction());
        self::assertSame('committed', self::$db->value('SELECT name FROM sku WHERE id = ?', [$id]));
    }

    public function testOtherErrorsRollBackWithoutRetry(): void
    {
        $calls = 0;
        try {
            self::$db->transaction(function (Db $db) use (&$calls): void {
                $calls++;
                $db->insert("INSERT INTO sku (name) VALUES ('rolled back')");
                throw new \DomainException('boom');
            });
            self::fail('exception expected');
        } catch (\DomainException) {
        }
        self::assertSame(1, $calls);
        self::assertFalse(self::$db->inTransaction());
        self::assertSame(0, self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'rolled back'"));
    }

    public function testDeadlockIsRetriedThenSucceeds(): void
    {
        $calls = 0;
        $id = self::$db->transaction(function (Db $db) use (&$calls): int {
            $calls++;
            $id = $db->insert("INSERT INTO sku (name) VALUES ('retried')");
            if ($calls < 3) {
                throw self::fakeDeadlock();
            }
            return $id;
        });
        self::assertSame(3, $calls);
        self::assertSame(1, self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'retried'"), 'only the last attempt is kept');
        self::assertSame('retried', self::$db->value('SELECT name FROM sku WHERE id = ?', [$id]));
    }

    public function testDeadlockRetriesAreBounded(): void
    {
        $calls = 0;
        try {
            self::$db->transaction(function () use (&$calls): void {
                $calls++;
                throw self::fakeDeadlock();
            });
            self::fail('exception expected');
        } catch (\PDOException $e) {
            self::assertTrue(Db::isDeadlock($e));
        }
        self::assertSame(1 + Db::DEADLOCK_RETRIES, $calls);
        self::assertFalse(self::$db->inTransaction());
    }

    public function testNestedTransactionJoinsTheOuterOne(): void
    {
        try {
            self::$db->transaction(function (Db $db): void {
                $db->insert("INSERT INTO sku (name) VALUES ('outer')");
                $db->transaction(function (Db $inner): void {
                    $inner->insert("INSERT INTO sku (name) VALUES ('inner')");
                    throw new \DomainException('inner failed');
                });
            });
            self::fail('exception expected');
        } catch (\DomainException) {
        }
        self::assertSame(0, self::$db->value("SELECT COUNT(*) FROM sku WHERE name IN ('outer', 'inner')"));
    }

    /**
     * A real InnoDB deadlock between two sessions: the lighter transaction (ours) is chosen as
     * the victim, Db::transaction() recognises the 1213 and the second attempt succeeds.
     */
    public function testRealDeadlockIsDetectedAndRetried(): void
    {
        if (!extension_loaded('mysqli')) {
            self::markTestSkipped('mysqli needed for the async second session');
        }
        $a = TestDb::connect();
        $b = self::mysqliSession();
        $watch = TestDb::connect();
        $bThread = $b->thread_id;
        $bFailed = null;

        $calls = 0;
        $a->transaction(function (Db $db) use (&$calls, $b, $watch, $bThread, &$bFailed): void {
            $calls++;
            if ($calls === 1) {
                $db->all('SELECT id FROM warehouse WHERE id = 1 FOR UPDATE');
                // B: heavier transaction (5 inserted rows) holding warehouse 2, then waiting for 1.
                $b->begin_transaction();
                for ($i = 0; $i < 5; $i++) {
                    $b->query("INSERT INTO stock_change (reason) VALUES ('deadlock-test')");
                }
                $b->query('SELECT id FROM warehouse WHERE id = 2 FOR UPDATE')->free();
                $b->query('SELECT id FROM warehouse WHERE id = 1 FOR UPDATE', MYSQLI_ASYNC);
                self::waitForLockWait($watch, $bThread);
                // Closes the cycle: InnoDB rolls back the lighter transaction (this one) with 1213.
                $db->all('SELECT id FROM warehouse WHERE id = 2 FOR UPDATE');
                return;
            }
            // Second attempt: B got its lock once we were rolled back; let it finish first.
            try {
                self::reap($b);
                $b->commit();
            } catch (\mysqli_sql_exception $e) {
                $bFailed = $e->getCode();
            }
            $db->all('SELECT id FROM warehouse WHERE id IN (1, 2) ORDER BY id FOR UPDATE');
        });

        self::assertSame(2, $calls, 'first attempt deadlocked, second succeeded');
        self::assertNull($bFailed, 'the heavier session must have survived');
        self::assertSame(5, self::$db->value("SELECT COUNT(*) FROM stock_change WHERE reason = 'deadlock-test'"));
        $b->close();
    }

    private static function fakeDeadlock(): \PDOException
    {
        $e = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
        return $e;
    }

    private static function mysqliSession(): \mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $s = TestDb::config()->dbAdmin();
        $m = mysqli_init();
        $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        $m->ssl_set(null, null, $s->sslCa ?? Db::SYSTEM_CA_BUNDLE, null, null);
        $m->real_connect($s->host, $s->user, $s->password(), TestDb::name(), $s->port, null, MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);
        $m->query("SET SESSION time_zone = '+00:00', SESSION transaction_isolation = 'READ-COMMITTED'");
        return $m;
    }

    private static function waitForLockWait(Db $watch, int $thread): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $waiting = $watch->value(
                "SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ? AND trx_state = 'LOCK WAIT'",
                [$thread],
            );
            if ((int) $waiting > 0) {
                return;
            }
            usleep(20_000);
        }
        self::fail('second session never started waiting for the lock');
    }

    private static function reap(\mysqli $m): void
    {
        $links = $errors = $rejects = [$m];
        if (\mysqli::poll($links, $errors, $rejects, 10) < 1) {
            self::fail('async query did not complete');
        }
        $r = $m->reap_async_query();
        if ($r instanceof \mysqli_result) {
            $r->free();
        }
    }
}
