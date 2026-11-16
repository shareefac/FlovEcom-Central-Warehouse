<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0068 (each website product's own selling mode, T-556 step 2 option A), applied to a scratch schema: one new, empty table, nothing else
 * changed (no table altered, no row written); its CHECKs (a yes-or-no back-order flag, the change no later than the "as of"); the app
 * login never deletes a row (NO_DELETE). A second run changes nothing; a run stopped before its record runs again cleanly.
 */
final class Migration0068Test extends IntegrationTestCase
{
    private const FILE = '0068_listing_selling.sql';
    private const CHECK_VIOLATED = 3819;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m68', $this->dir);
            $this->dir = null;
        }
        parent::tearDown();
    }

    public function testOneNewEmptyTableWithItsChecks(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        self::assertCount(1, $statements);
        self::assertMatchesRegularExpression('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+listing_selling\b/i', $statements[0]);
        self::assertDoesNotMatchRegularExpression('/\bALTER\s+TABLE\b|\bDROP\b|\bDELETE\b|\bUPDATE\b|\bINSERT\b|\bFOREIGN\s+KEY\b/i', $statements[0]);
        self::assertSame(['Select', 'Insert', 'Update'], Grants::desired('listing_selling'), 'never deleted by the app login');

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m68', '0067_site_mode_website.sql');
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame(['listing_selling'], array_values(array_diff($db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), $tables)));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM listing_selling'));
        self::assertSame(['listing_id', 'stock_mode', 'allow_backorders', 'low_stock_threshold', 'as_of', 'changed_at'],
            $db->column("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'listing_selling' ORDER BY ORDINAL_POSITION"));

        $at = '2026-11-16 16:20:00.000000';
        $db->exec('INSERT INTO listing_selling (listing_id, stock_mode, allow_backorders, low_stock_threshold, as_of, changed_at) VALUES (1, ?, 1, -2, ?, ?)', ['From-Warehouse', $at, $at]);
        $db->exec('INSERT INTO listing_selling (listing_id, as_of, changed_at) VALUES (2, ?, ?)', [$at, $at]);
        foreach ([
            [3, 'In-Stock', 2, null, $at, $at],
            [4, 'In-Stock', 0, null, $at, '2026-11-16 16:20:01.000000'],
        ] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec('INSERT INTO listing_selling (listing_id, stock_mode, allow_backorders, '
                . 'low_stock_threshold, as_of, changed_at) VALUES (?, ?, ?, ?, ?, ?)', $bad)), json_encode($bad));
        }
        // A second run of the statement changes nothing.
        $db->exec($statements[0]);
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM listing_selling'));
        MigrationFixture::drop('m68', $this->dir);
        $this->dir = null;

        // A run stopped after the statement, before the migration's own record (the Codex review of option A, MEDIUM 1): runs again cleanly.
        [$db, $this->dir] = MigrationFixture::upTo('m68', '0067_site_mode_website.sql');
        $db->pdo()->exec($statements[0]);
        self::assertNull($db->value('SELECT version FROM schema_migrations WHERE version = ?', [self::FILE]), 'stopped, not recorded');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE), 'runs again');
    }
}
