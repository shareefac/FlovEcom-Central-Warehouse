<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0076 (CW-1155 "Receive the rest"; docs/decisions.md RR1, RR16), applied to a scratch schema at 0075: it only ADDS (one new, empty table,
 * one setting with its baseline); no existing row changes; its CHECKs; the app login may only add rows to the table; running every
 * statement again changes nothing.
 */
final class Migration0076Test extends IntegrationTestCase
{
    private const FILE = '0076_receive_rest.sql';
    private const CHECK_VIOLATED = 3819;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m76', $this->dir);
        }
    }

    public function testItOnlyAddsAndRunsAgainCleanly(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        $statements = SqlSplitter::split($sql);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bDROP\b|\bALTER\b/i', $sql, 'nothing is deleted, changed, altered or dropped');
        foreach ($statements as $st) {
            self::assertMatchesRegularExpression('/^(CREATE TABLE IF NOT EXISTS invoice_arrival_rest|INSERT INTO (app_setting|config_change))\b/', $st);
        }
        self::assertSame(['Select', 'Insert'], Grants::desired('invoice_arrival_rest'), 'append-only');

        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m76', $files[$at - 1]);
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $settings = (int) $db->value('SELECT COUNT(*) FROM app_setting');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame(['invoice_arrival_rest'], array_values(array_diff($db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'),
            $tables)));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM invoice_arrival_rest'));
        self::assertSame($settings + 1, (int) $db->value('SELECT COUNT(*) FROM app_setting'));
        self::assertSame(['int', '14', 1, 'RR16'], array_values(array_map(static fn (mixed $v): mixed => is_numeric($v) && !is_string($v) ? (int) $v : $v,
            (array) $db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = 'arrivals.rest_late_days'"))));
        self::assertSame(14, (new Settings($db))->get('arrivals.rest_late_days'));
        self::assertSame(['min' => 1, 'max' => 365], Settings::RULES['arrivals.rest_late_days']);
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'arrivals.rest_late_days' AND version = 1 "
            . "AND action = 'baseline' AND actor = 'system:migrate'"));
        self::assertSame("enum('rest','claim','waive')", (string) $db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'invoice_arrival_rest' AND COLUMN_NAME = 'kind'"));
        $checks = $db->column("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_arrival_rest' "
            . "AND CONSTRAINT_TYPE = 'CHECK' ORDER BY CONSTRAINT_NAME");
        self::assertSame(['ck_invoice_arrival_rest_kind', 'ck_invoice_arrival_rest_units'], $checks);

        // Every statement again: nothing changes (a value changed on the Settings page meanwhile stays).
        $db->exec("UPDATE app_setting SET value_json = CAST('21' AS JSON) WHERE setting_key = 'arrivals.rest_late_days'");
        foreach ($statements as $st) {
            $db->pdo()->exec($st);
        }
        self::assertSame($settings + 1, (int) $db->value('SELECT COUNT(*) FROM app_setting'));
        self::assertSame('21', (string) $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'arrivals.rest_late_days'"));
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'arrivals.rest_late_days'"));
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $db->exec('INSERT INTO invoice_arrival_rest (sinv_document_id, line_no, first_grn_document_id, kind, '
            . "units, created_actor, created_at) VALUES (1, 1, 1, 'waive', 0, 'x', NOW(6))")), 'units > 0 (checked before the keys)');
    }

    private static function err(callable $fn): int
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            return (int) ($e->errorInfo[1] ?? 0);
        }
        return 0;
    }
}
