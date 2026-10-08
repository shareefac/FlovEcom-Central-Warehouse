<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Tests\Support\MigrationFixture;
use CW\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Db::connect(..., persistent: true), which the staff screens' kernel uses in php-fpm: a link reused by the worker's next
 * request starts as clean as a new one. PHP frees the request's PDO object between requests; here unset() does the same
 * (a CLI process keeps its persistent links like a php-fpm worker). Each case first shows the state a request leaves behind
 * on the link, then that the next connect() leaves none of it. Runs on a scratch schema of its own (cw_test_<slot>_pc).
 */
final class DbPersistentTest extends TestCase
{
    private const SUFFIX = 'pc';
    private const LOCK = 'cw_test_persistent_probe';

    private Db $server;
    private string $schema;

    protected function setUp(): void
    {
        if (getenv('CW_TEST_DB') === '0') {
            self::markTestSkipped('no database');
        }
        $this->server = TestDb::server();
        $this->schema = MigrationFixture::schema(self::SUFFIX);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->server->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident($this->schema));
    }

    private function createSchema(): void
    {
        $this->server->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident($this->schema));
        $this->server->pdo()->exec('CREATE DATABASE ' . Db::ident($this->schema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->server->pdo()->exec('CREATE TABLE ' . Db::ident($this->schema) . '.probe (id INT UNSIGNED NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
    }

    private function link(): Db
    {
        return Db::connect(TestDb::config()->dbAdmin()->withDatabase($this->schema), true);
    }

    private function rows(): int
    {
        return (int) $this->server->value('SELECT COUNT(*) FROM ' . Db::ident($this->schema) . '.probe');
    }

    public function testAReusedLinkKeepsNothingOfTheRequestBefore(): void
    {
        $a = $this->link();
        $id = (int) $a->value('SELECT CONNECTION_ID()');
        // What a request that died half way leaves on its link: a transaction PDO began, a named lock, changed session variables.
        $a->pdo()->exec("SET SESSION sql_mode = '', SESSION time_zone = '+05:00', SESSION transaction_isolation = 'SERIALIZABLE'");
        $a->pdo()->beginTransaction();
        $a->exec('INSERT INTO probe (id) VALUES (1)');
        self::assertSame(1, (int) $a->value('SELECT GET_LOCK(?, 0)', [self::LOCK]));
        unset($a); // the end of the request: PHP frees the PDO object, the link stays open in the worker
        self::assertSame($id, (int) $this->server->value('SELECT IS_USED_LOCK(?)', [self::LOCK]), 'without the reset the lock would outlive the request');

        $b = $this->link();
        self::assertSame($id, (int) $b->value('SELECT CONNECTION_ID()'), 'the same link was reused');
        self::assertSame(0, $this->rows(), 'the open transaction was rolled back');
        self::assertNull($this->server->value('SELECT IS_USED_LOCK(?)', [self::LOCK]), 'the named lock was released');
        self::assertSame(Db::SQL_MODE, $b->value('SELECT @@SESSION.sql_mode'));
        self::assertSame('+00:00', $b->value('SELECT @@SESSION.time_zone'));
        self::assertSame('READ-COMMITTED', $b->value('SELECT @@SESSION.transaction_isolation'));
        self::assertSame('utf8mb4_0900_ai_ci', $b->value('SELECT @@SESSION.collation_connection'));
        self::assertNotSame('', (string) ($b->pdo()->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(\PDO::FETCH_NUM)[1] ?? ''), 'still TLS');

        // A transaction begun behind PDO's back (PDO does not roll that back when it frees the object): the next connect() does.
        $b->pdo()->exec('START TRANSACTION');
        $b->exec('INSERT INTO probe (id) VALUES (2)');
        unset($b);
        $c = $this->link();
        self::assertSame($id, (int) $c->value('SELECT CONNECTION_ID()'));
        self::assertSame(0, $this->rows(), 'the raw transaction was rolled back too');
        self::assertFalse($c->inTransaction());
        $c->exec('INSERT INTO probe (id) VALUES (3)');
        self::assertSame(1, $this->rows(), 'autocommit as on a new link');
    }

    public function testAReusedLinkFindsItsSchemaAfterTheSchemaWasMadeAgain(): void
    {
        $a = $this->link();
        $id = (int) $a->value('SELECT CONNECTION_ID()');
        unset($a);
        // A test slot's schema is dropped and re-created by tests/bootstrap.php under the pool's idle links.
        $this->createSchema();
        $b = $this->link();
        self::assertSame($id, (int) $b->value('SELECT CONNECTION_ID()'));
        self::assertSame($this->schema, $b->value('SELECT DATABASE()'));
        self::assertSame(0, (int) $b->value('SELECT COUNT(*) FROM probe'));
    }

    public function testOrdinaryConnectsAreSeparateSessionsAndNotThePersistentLink(): void
    {
        $settings = TestDb::config()->dbAdmin()->withDatabase($this->schema);
        $p = $this->link();
        $one = Db::connect($settings);
        $two = Db::connect($settings);
        $ids = [(int) $p->value('SELECT CONNECTION_ID()'), (int) $one->value('SELECT CONNECTION_ID()'), (int) $two->value('SELECT CONNECTION_ID()')];
        self::assertCount(3, array_unique($ids), 'tests, tools and the API keep one session per connect()');
    }
}
