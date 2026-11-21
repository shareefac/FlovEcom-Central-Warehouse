<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Migrator;
use CW\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

/** Runs the migrator against a scratch schema (<test schema>_mig) and a temporary migrations dir. */
final class MigratorTest extends TestCase
{
    private static string $schema;
    private static Db $db;
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        if (getenv('CW_TEST_DB') === '0') {
            self::markTestSkipped('CW_TEST_DB=0: database tests disabled');
        }
        self::$schema = TestDb::name() . '_mig';
    }

    protected function setUp(): void
    {
        $server = TestDb::server();
        $server->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident(self::$schema));
        $server->pdo()->exec('CREATE DATABASE ' . Db::ident(self::$schema));
        self::$db = Db::connect(TestDb::config()->dbAdmin()->withDatabase(self::$schema));
        $this->dir = sys_get_temp_dir() . '/cw_mig_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$schema)) {
            TestDb::server()->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident(self::$schema));
        }
    }

    public function testAppliesInOrderOnceAndPicksUpNewFiles(): void
    {
        $this->write('0002_b.sql', "INSERT INTO t (id, note) VALUES (2, 'b; with semicolon');");
        $this->write('0001_a.sql', "-- comment; with semicolon\nCREATE TABLE t (id INT PRIMARY KEY, note VARCHAR(50));\n/* block; */ INSERT INTO t VALUES (1, 'it''s');");
        $m = new Migrator(self::$db, $this->dir);
        self::assertSame(['0001_a.sql', '0002_b.sql'], $m->migrate());
        self::assertSame([], $m->migrate());
        self::assertSame(["it's", 'b; with semicolon'], self::$db->column('SELECT note FROM t ORDER BY id'));

        $this->write('0003_c.sql', 'INSERT INTO t VALUES (3, "double quoted is a string");');
        self::assertSame(['0003_c.sql'], $m->migrate());
        self::assertSame('double quoted is a string', self::$db->value('SELECT note FROM t WHERE id = 3'));
    }

    public function testChangedAppliedFileIsRefused(): void
    {
        $this->write('0001_a.sql', 'CREATE TABLE t (id INT PRIMARY KEY);');
        $m = new Migrator(self::$db, $this->dir);
        $m->migrate();
        $this->write('0001_a.sql', 'CREATE TABLE t (id INT PRIMARY KEY, x INT);');
        $this->expectExceptionMessage('was changed after it was applied');
        $m->migrate();
    }

    public function testMissingAppliedFileIsRefused(): void
    {
        $this->write('0001_a.sql', 'CREATE TABLE t (id INT PRIMARY KEY);');
        $m = new Migrator(self::$db, $this->dir);
        $m->migrate();
        unlink($this->dir . '/0001_a.sql');
        $this->expectExceptionMessage('no such file exists');
        $m->migrate();
    }

    public function testFailedFileIsNotRecordedAndNamesTheStatement(): void
    {
        $this->write('0001_a.sql', "CREATE TABLE t (id INT PRIMARY KEY);\nINSERT INTO nope VALUES (1);");
        $m = new Migrator(self::$db, $this->dir);
        try {
            $m->migrate();
            self::fail('exception expected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('0001_a.sql statement 2 failed', $e->getMessage());
        }
        self::assertSame(['0001_a.sql'], $m->pending());
    }

    public function testBadFileNameIsRefused(): void
    {
        $this->write('1_bad.sql', 'SELECT 1;');
        $this->expectExceptionMessage('does not match');
        (new Migrator(self::$db, $this->dir))->migrate();
    }

    /**
     * The third final review, a-HIGH3 (final2/FIX_BRIEF_A4.md H6, PR154): a migration's statement waits about 30 seconds at most for a table's
     * metadata lock (MySQL's own default is a year: a long transaction kept the writers paused for as long as it ran). The session's
     * lock_wait_timeout is LOCK_WAIT_SEC while the files run, and put back after.
     */
    public function testAMigrationWaitsAboutThirtySecondsForATableLock(): void
    {
        $this->write('0001_a.sql', 'CREATE TABLE t (id INT PRIMARY KEY, w BIGINT);');
        $this->write('0002_b.sql', 'INSERT INTO t VALUES (1, @@SESSION.lock_wait_timeout);');
        $before = (int) self::$db->value('SELECT @@SESSION.lock_wait_timeout');
        self::assertSame(['0001_a.sql', '0002_b.sql'], (new Migrator(self::$db, $this->dir))->migrate());
        self::assertSame(30, Migrator::LOCK_WAIT_SEC);
        self::assertSame(30, (int) self::$db->value('SELECT w FROM t WHERE id = 1'), 'the migration session waits 30 s for a metadata lock');
        self::assertSame($before, (int) self::$db->value('SELECT @@SESSION.lock_wait_timeout'), 'put back after');
    }

    /** ... and a migration blocked by an open transaction on its table fails within that wait, names the file and statement, and is not recorded. */
    public function testAMigrationBlockedByAnOpenTransactionFailsAndIsNotRecorded(): void
    {
        $this->write('0001_a.sql', 'CREATE TABLE t (id INT PRIMARY KEY);');
        (new Migrator(self::$db, $this->dir))->migrate();
        $other = Db::connect(TestDb::config()->dbAdmin()->withDatabase(self::$schema));
        $other->pdo()->exec('START TRANSACTION');
        $other->value('SELECT COUNT(*) FROM t'); // a report's long read: its transaction holds the table's metadata lock
        $this->write('0002_b.sql', 'ALTER TABLE t ADD COLUMN x INT;');
        $m = new Migrator(self::$db, $this->dir, null, lockWaitSec: 1);
        $t = microtime(true);
        try {
            $m->migrate();
            self::fail('the ALTER waits for the open transaction');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('0002_b.sql statement 1 failed', $e->getMessage());
            self::assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->pdo()->exec('ROLLBACK');
        }
        self::assertLessThan(15.0, microtime(true) - $t, 'within the wait, never as long as the transaction');
        self::assertSame(['0002_b.sql'], $m->pending(), 'not recorded: the next run applies it');
        self::assertSame(['0002_b.sql'], $m->migrate());
    }

    /**
     * The final re-check of the fix round (build/A/codex_final4_astra_p.txt, p-M; final2/FIX_BRIEF_A5.md F3, PR155): the step migrate() is
     * given (bin/migrate.php: the app login's grants) runs after the files with the session's lock wait still the bound and the migration
     * lock still held; the session's own value is put back only at the end of the run (an outage gap: the grants waited a year for a backup's
     * lock with the writers paused).
     */
    public function testTheStepAfterTheFilesKeepsTheBoundedLockWaitToTheEnd(): void
    {
        $this->write('0001_a.sql', 'CREATE TABLE t (id INT PRIMARY KEY);');
        $before = (int) self::$db->value('SELECT @@SESSION.lock_wait_timeout');
        self::assertNotSame(7, $before);
        $seen = null;
        $m = new Migrator(self::$db, $this->dir, null, lockWaitSec: 7);
        $done = $m->migrate(static function () use (&$seen): void {
            $seen = [(int) self::$db->value('SELECT @@SESSION.lock_wait_timeout'),
                (int) self::$db->value('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', ['cw_migrate:' . self::$schema]),
                (int) self::$db->value('SELECT COUNT(*) FROM schema_migrations')];
        });
        self::assertSame(['0001_a.sql'], $done);
        self::assertSame([7, 1, 1], $seen, 'the step ran after the files, with the bound and the migration lock');
        self::assertSame($before, (int) self::$db->value('SELECT @@SESSION.lock_wait_timeout'), 'put back at the end');
        self::assertSame(1, (int) self::$db->value('SELECT IS_FREE_LOCK(?)', ['cw_migrate:' . self::$schema]), 'the migration lock let go');
        // Nothing pending: the step still runs (bin/migrate.php converges the grants on every run).
        $seen = null;
        self::assertSame([], $m->migrate(static function () use (&$seen): void {
            $seen = (int) self::$db->value('SELECT @@SESSION.lock_wait_timeout');
        }));
        self::assertSame(7, $seen);
        self::assertSame($before, (int) self::$db->value('SELECT @@SESSION.lock_wait_timeout'));
    }

    /** ... and a step that fails: its error passes through, the session's value is put back and the migration lock let go all the same. */
    public function testAStepThatFailsStillPutsTheLockWaitBack(): void
    {
        $this->write('0001_a.sql', 'CREATE TABLE t (id INT PRIMARY KEY);');
        $before = (int) self::$db->value('SELECT @@SESSION.lock_wait_timeout');
        $ran = false;
        try {
            (new Migrator(self::$db, $this->dir))->migrate(static function () use (&$ran): void {
                $ran = (int) self::$db->value('SELECT @@SESSION.lock_wait_timeout') === Migrator::LOCK_WAIT_SEC;
                throw new \RuntimeException('the grants failed');
            });
            self::fail('the step\'s error passes through');
        } catch (\RuntimeException $e) {
            self::assertSame('the grants failed', $e->getMessage());
        }
        self::assertTrue($ran, 'the step ran with LOCK_WAIT_SEC');
        self::assertSame($before, (int) self::$db->value('SELECT @@SESSION.lock_wait_timeout'), 'put back after the error');
        self::assertSame(1, (int) self::$db->value('SELECT IS_FREE_LOCK(?)', ['cw_migrate:' . self::$schema]));
        self::assertSame([], (new Migrator(self::$db, $this->dir))->pending(), 'the files were recorded before the step');
    }

    private function write(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name, $sql);
    }
}
