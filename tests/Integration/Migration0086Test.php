<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Migrator;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0086 (CW-1152, goods in without the supplier invoice, per supplier; docs/decisions.md NI1, NI8), applied to a scratch schema at the file
 * before it: it only ADDS two columns, INSTANTLY: supplier.goods_in_without_invoice, 0 for every supplier (nothing changes for anyone until a
 * person ticks a supplier), and supplier_invoice.separate_delivery (NULL: no invoice confirmed as a separate delivery); no row changed or deleted; a second run changes nothing (a tick set meanwhile stays). It does not need 0085 (Parcel2Go, built on another branch):
 * 0086 goes in with 0085 missing, and an 0085 that comes later is applied after it.
 */
final class Migration0086Test extends IntegrationTestCase
{
    private const FILE = '0086_goods_in_without_invoice.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m86', $this->dir);
        }
    }

    public function testItOnlyAddsTheSupplierColumnOffForEveryoneAndRunsAgainCleanly(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bDROP\b|\bCREATE\b|\bINSERT\b/i', $sql, 'nothing deleted, changed, made or added but the column');
        self::assertSame(2, substr_count($sql, "ALGORITHM = INSTANT'"), 'each ALTER is instant (a copy fails instead of running)');
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m86', $files[$at - 1]);
        $db->exec("INSERT INTO supplier (code, name, status, country, currency, is_overseas, version, created_actor, updated_actor) "
            . "VALUES ('OLDSUP', 'Old Supplier', 'draft', 'GB', 'GBP', 0, 1, 'test', 'test')");
        $before = $db->one("SELECT * FROM supplier WHERE code = 'OLDSUP'");
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame($tables, $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'), 'no table made');
        $col = (array) $db->one("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supplier' "
            . "AND column_name = 'goods_in_without_invoice'");
        self::assertSame(['tinyint(1)', 'NO', '0'], array_values(array_map('strval', $col)));
        $after = (array) $db->one("SELECT * FROM supplier WHERE code = 'OLDSUP'");
        self::assertSame('0', (string) $after['goods_in_without_invoice'], 'every supplier off at install');
        self::assertSame(['varchar(500)', 'YES', ''], array_values(array_map('strval', (array) $db->one("SELECT COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT, '') "
            . "FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supplier_invoice' AND column_name = 'separate_delivery'"))));
        unset($after['goods_in_without_invoice']);
        self::assertSame($before, $after, 'nothing else of the supplier changed');

        // Every statement again: nothing changes (a tick set meanwhile stays).
        $db->exec("UPDATE supplier SET goods_in_without_invoice = 1 WHERE code = 'OLDSUP'");
        $cols = $db->column("SELECT CONCAT(table_name, '.', column_name) FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY 1");
        foreach (SqlSplitter::split($sql) as $st) {
            $db->pdo()->exec($st);
        }
        self::assertSame($cols, $db->column("SELECT CONCAT(table_name, '.', column_name) FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY 1"));
        self::assertSame('1', (string) $db->value("SELECT goods_in_without_invoice FROM supplier WHERE code = 'OLDSUP'"));
    }

    public function testItGoesInBeforeOrAfterAnother0085(): void
    {
        // A schema at 0084 with ONLY 0086 added (0085 missing: a gap is allowed), then a stand-in 0085 applied AFTER it; and the other order.
        $file = dirname(__DIR__, 2) . '/migrations/' . self::FILE;
        [$db, $this->dir] = MigrationFixture::upTo('m86', '0084_gp_fees.sql');
        copy($file, $this->dir . '/' . self::FILE);
        self::assertSame([self::FILE], (new Migrator($db, $this->dir))->migrate(), 'applied with 0085 missing: a gap is allowed');
        file_put_contents($this->dir . '/0085_parcel2go.sql', "-- stand-in\nDO 0;\n");
        $m = new Migrator($db, $this->dir);
        self::assertSame(['0085_parcel2go.sql'], $m->pending(), 'a lower number still pending after a higher one is applied');
        self::assertSame(['0085_parcel2go.sql'], $m->migrate());
        self::assertSame([], $m->pending());
        unlink($this->dir . '/0085_parcel2go.sql');
        MigrationFixture::drop('m86', $this->dir);
        $this->dir = null;

        [$db, $this->dir] = MigrationFixture::upTo('m86', '0084_gp_fees.sql');
        file_put_contents($this->dir . '/0085_parcel2go.sql', "-- stand-in\nDO 0;\n");
        self::assertSame(['0085_parcel2go.sql'], (new Migrator($db, $this->dir))->migrate());
        copy($file, $this->dir . '/' . self::FILE);
        self::assertSame([self::FILE], (new Migrator($db, $this->dir))->migrate());
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supplier' "
            . "AND column_name = 'goods_in_without_invoice'"));
        unlink($this->dir . '/0085_parcel2go.sql');
    }
}
