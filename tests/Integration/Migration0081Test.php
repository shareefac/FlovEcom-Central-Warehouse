<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\Db;
use CW\Reservations;
use CW\Schema\Migrator;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0081 (CW-1180, "don't restock" = written off at once; docs/decisions.md NR1, NR8), applied to a scratch schema at 0079: it only ADDS
 * one setting with its baseline; nothing else changes; running every statement again changes nothing (a value set on the Settings page
 * meanwhile stays). It does not need 0080 (PayPal fees, built on another branch): the Migrator applies whatever file is pending, in name
 * order, so 0081 goes in with 0080 missing, and an 0080 that comes later is applied after it (the two may go live in either order).
 */
final class Migration0081Test extends IntegrationTestCase
{
    private const FILE = '0081_cancel_not_restockable.sql';
    private const KEY = 'stock.cancel_not_restockable';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m81', $this->dir);
        }
    }

    public function testItOnlyAddsTheSettingAndRunsAgainCleanly(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        $statements = SqlSplitter::split($sql);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bDROP\b|\bALTER\b|\bCREATE\b/i', $sql, 'nothing is deleted, changed, altered or made');
        self::assertCount(2, $statements);
        foreach ($statements as $st) {
            self::assertMatchesRegularExpression('/^INSERT INTO (app_setting|config_change)\b/', $st);
        }

        // on top of the file just before it in this tree (0079 on branch no-restock; 0080 when built on PayPal's fees)
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m81', $files[$at - 1]);
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
        $settings = (int) $db->value('SELECT COUNT(*) FROM app_setting');
        self::assertFalse((new Settings($db))->has(self::KEY));
        self::assertSame('verify', (new Reservations($db))->notRestockableMode(), 'before 0081 the code keeps the way before');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame($tables, $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'));
        self::assertSame($settings + 1, (int) $db->value('SELECT COUNT(*) FROM app_setting'));
        self::assertSame(['string', '"write_off"', 1, 'NR1'], array_values(array_map(static fn (mixed $v): mixed => is_numeric($v) && !is_string($v) ? (int) $v : $v,
            (array) $db->one('SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = ?', [self::KEY]))));
        self::assertSame('write_off', (new Settings($db))->get(self::KEY));
        self::assertSame('write_off', (new Reservations($db))->notRestockableMode());
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ? AND version = 1 "
            . "AND action = 'baseline' AND actor = 'system:migrate'", [self::KEY]));
        self::assertSame([], ConfigInvariants::check($db), 'K1-K4: the new row has its history');

        // Every statement again: nothing changes (a value changed on the Settings page meanwhile stays).
        $db->exec("UPDATE app_setting SET value_json = JSON_QUOTE('verify') WHERE setting_key = ?", [self::KEY]);
        foreach ($statements as $st) {
            $db->pdo()->exec($st);
        }
        self::assertSame($settings + 1, (int) $db->value('SELECT COUNT(*) FROM app_setting'));
        self::assertSame('"verify"', (string) $db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [self::KEY]));
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ?", [self::KEY]));
    }

    public function testItGoesInBeforeOrAfterAnother0080(): void
    {
        // A schema at 0079 with ONLY 0081 added (0080 missing: a gap is allowed), then a stand-in 0080 (the PayPal fees' own file is on its branch;
        // it touches none of 0081's rows): applied AFTER 0081 when it comes later. The files are copied by hand, so the test holds whatever else
        // this tree has.
        $file = dirname(__DIR__, 2) . '/migrations/' . self::FILE;
        [$db, $this->dir] = MigrationFixture::upTo('m81', '0079_expenses.sql');
        copy($file, $this->dir . '/' . self::FILE);
        self::assertSame([self::FILE], (new Migrator($db, $this->dir))->migrate(), 'applied with 0080 missing: a gap is allowed');
        self::assertSame('write_off', (new Settings($db))->get(self::KEY));
        file_put_contents($this->dir . '/0080_paypal_fees.sql', "-- stand-in\nDO 0;\n");
        $m = new Migrator($db, $this->dir);
        self::assertSame(['0080_paypal_fees.sql'], $m->pending(), 'a lower number still pending after a higher one is applied');
        self::assertSame(['0080_paypal_fees.sql'], $m->migrate());
        self::assertSame([], $m->pending());
        unlink($this->dir . '/0080_paypal_fees.sql');
        MigrationFixture::drop('m81', $this->dir);
        $this->dir = null;

        // ... and BEFORE it, when it went live first
        [$db, $this->dir] = MigrationFixture::upTo('m81', '0079_expenses.sql');
        file_put_contents($this->dir . '/0080_paypal_fees.sql', "-- stand-in\nDO 0;\n");
        $m = new Migrator($db, $this->dir);
        self::assertSame(['0080_paypal_fees.sql'], $m->migrate());
        copy($file, $this->dir . '/' . self::FILE);
        self::assertSame([self::FILE], (new Migrator($db, $this->dir))->migrate());
        self::assertSame('write_off', (new Settings($db))->get(self::KEY));
        unlink($this->dir . '/0080_paypal_fees.sql');
    }
}
