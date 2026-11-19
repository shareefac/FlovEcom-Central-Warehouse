<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0073 (a store's choice "Existing item"; docs/decisions.md SX1; tracker CW-1138), applied to a scratch schema that already holds choices:
 * one statement; every choice there before passes the new CHECK unchanged; an existing item names its item and no units; the other
 * choices are checked exactly as before; running it again changes nothing.
 */
final class Migration0073Test extends IntegrationTestCase
{
    private const FILE = '0073_store_choice_existing.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m73', $this->dir);
        }
    }

    public function testAnExistingItemNamesItsItemAndNoUnitsAndEveryOtherChoiceIsCheckedAsBefore(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        $statements = SqlSplitter::split($sql);
        self::assertCount(1, $statements, 'one statement: applied whole or not at all');
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bINSERT\s+INTO\b/i', $sql, 'no row is written or changed');

        /** @var Db $db */
        // up to the file before it, whatever its number (a branch merged with this one may add one in between)
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        [$db, $this->dir] = MigrationFixture::upTo('m73', $files[$at - 1]);
        $store = $db->insert("INSERT INTO channel (code, name) VALUES ('vapebig', 'Vape Big')");
        $add = static fn (string $variant, string $choice, ?string $packOf, ?int $units): int => $db->insert('INSERT INTO store_choice (channel_id, external_variant_id, '
            . 'choice, pack_of_code, units, staff_ref, staff_name, chosen_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$store, $variant, $choice, $packOf, $units, '17', 'A']);
        $add('1', 'new', null, null);
        $add('2', 'pack', 'CW-000123', 10);
        $add('3', 'none', null, null);
        $refused = static function (callable $fn): bool {
            try {
                $fn();
                return false;
            } catch (\PDOException) {
                return true;
            }
        };
        self::assertTrue($refused(fn () => $add('4', 'existing', 'CW-000123', null)), 'before 0073 there is no existing item');

        $before = $db->all('SELECT * FROM store_choice ORDER BY id');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame($before, $db->all('SELECT * FROM store_choice ORDER BY id'), 'no choice changed');
        $add('5', 'existing', 'CW-011468', null);
        self::assertSame('existing', (string) $db->value("SELECT CAST(choice AS CHAR) FROM store_choice WHERE external_variant_id = '5'"));
        foreach ([
            ['6', 'existing', null, null],          // which item
            ['7', 'existing', 'CW-011468', 2],      // one for one: no units
            ['8', 'pack', 'CW-000123', null],       // a pack names its units (as before)
            ['9', 'pack', null, 10],
            ['10', 'new', 'CW-000123', 5],          // a new item never names both (as before)
            ['11', 'pack', 'CW-000123', 1],         // a pack of one (ck_store_choice_units, as before)
        ] as [$v, $choice, $packOf, $units]) {
            self::assertTrue($refused(fn () => $add($v, $choice, $packOf, $units)), "{$v} {$choice} let through");
        }
        // the old CHECK let a new item or not stock name one of the two (receive() never writes it): still let through, so no row there fails
        $add('12', 'none', 'CW-000123', null);

        // again: nothing changes
        $db->pdo()->exec($statements[0]);
        self::assertSame(5, (int) $db->value('SELECT COUNT(*) FROM store_choice'));
        $add('13', 'existing', 'CW-000001', null);
    }
}
