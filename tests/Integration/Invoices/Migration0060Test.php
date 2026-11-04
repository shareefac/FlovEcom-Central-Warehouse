<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0060 (CW-917; docs/decisions.md EC1-EC16): the three new tables; the two reasons of the job (CW's own, for cancellations) and the six
 * settings (the main switch OFF, the company, the first date, make suppliers on, last prices on, 200 invoices a run), each with its
 * version 1; LAST, supplier_invoice.origin added in place (INSTANT, as 0052; 'cw' for every record before it); nothing existing rewritten
 * or deleted; a run stopped anywhere before the last statement runs again cleanly (the Codex review of d8af941, MEDIUM 1).
 */
final class Migration0060Test extends IntegrationTestCase
{
    private const FILE = __DIR__ . '/../../../migrations/0060_erp_invoice_copy.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m60', $this->dir);
            $this->dir = null;
        }
        parent::tearDown();
    }

    /**
     * A stop after every statement but the last (the column), and a stop after the last one before the migration's own record: in both the
     * migrator runs the file again cleanly, nothing twice (the Codex reviews of d8af941 and fac1ea9, MEDIUM 1).
     */
    public function testARunStoppedAnywhereRunsAgainCleanly(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(self::FILE));
        $prepared = array_slice($statements, -4);
        self::assertMatchesRegularExpression('/^SET\s+@cw_0060\s*=.*ALTER TABLE supplier_invoice ADD COLUMN origin/is', $prepared[0], 'the column is the last step');
        self::assertSame(['PREPARE cw_0060 FROM @cw_0060', 'EXECUTE cw_0060', 'DEALLOCATE PREPARE cw_0060'], array_map('trim', array_slice($prepared, 1)));
        foreach (['before the column' => array_slice($statements, 0, -4), 'after the column' => $statements] as $where => $done) {
            [$db, $this->dir] = MigrationFixture::upTo('m60', '0059_mode_review_off.sql');
            foreach ($done as $s) {
                $db->pdo()->exec($s);
            }
            self::assertNull($db->value("SELECT version FROM schema_migrations WHERE version = '0060_erp_invoice_copy.sql'"), "{$where}: stopped, not recorded");
            self::assertSame(['0060_erp_invoice_copy.sql'], MigrationFixture::migrateRest($db, $this->dir, '0060_erp_invoice_copy.sql'), $where);
            self::assertSame([6, 2, 8], [(int) $db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key LIKE 'erp\\_copy.%'"),
                (int) $db->value("SELECT COUNT(*) FROM reason_code WHERE code IN ('erp_cancelled', 'erp_changed')"),
                (int) $db->value("SELECT COUNT(*) FROM config_change WHERE (subject_type = 'setting' AND subject_key LIKE 'erp\\_copy.%') "
                    . "OR (subject_type = 'reason' AND subject_key IN ('erp_cancelled', 'erp_changed'))")], "{$where}: no row twice");
            self::assertSame("enum('cw','erpnext')", $db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME = 'supplier_invoice' AND COLUMN_NAME = 'origin'"), $where);
            MigrationFixture::drop('m60', $this->dir);
            $this->dir = null;
        }
        // A column of that name with another definition: the migration stops, naming it.
        [$db, $this->dir] = MigrationFixture::upTo('m60', '0059_mode_review_off.sql');
        $db->pdo()->exec("ALTER TABLE supplier_invoice ADD COLUMN origin VARCHAR(10) NULL");
        try {
            MigrationFixture::migrateRest($db, $this->dir, '0060_erp_invoice_copy.sql');
            self::fail('another definition is refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('supplier_invoice.origin has another definition', $e->getMessage());
        }
    }

    public function testTheColumnTablesReasonsAndSettings(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(self::FILE));
        self::assertMatchesRegularExpression("/^SET\\s+@cw_0060\\s*=.*'ALTER\\s+TABLE\\s+supplier_invoice\\s+ADD\\s+COLUMN\\s+origin\\s+ENUM\\(''cw'',''erpnext''\\)\\s+NOT\\s+NULL\\s+DEFAULT\\s+''cw''.*ALGORITHM\\s*=\\s*INSTANT'/is",
            (string) $statements[count($statements) - 4], 'the column, last, only while it is not there');
        foreach ($statements as $s) {
            if (preg_match('/^\s*CREATE\s+TABLE\b/i', $s) === 1) {
                self::assertMatchesRegularExpression('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $s, 'a stopped run makes it again cleanly');
            }
        }
        foreach ($statements as $s) {
            self::assertDoesNotMatchRegularExpression('/^\s*(UPDATE|DELETE|DROP|TRUNCATE)\b/i', $s, 'no row changed, nothing deleted');
        }
        $c = self::$db->one("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_invoice' "
            . "AND COLUMN_NAME = 'origin'");
        self::assertSame(["enum('cw','erpnext')", 'NO', 'cw'], [$c['COLUMN_TYPE'] ?? null, $c['IS_NULLABLE'] ?? null, $c['COLUMN_DEFAULT'] ?? null]);
        foreach (['erp_pi', 'erp_pi_line', 'erp_copy_run'] as $t) {
            self::assertNotNull(self::$db->value('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]), $t);
        }
        $settings = [];
        foreach (self::$db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional FROM app_setting WHERE setting_key LIKE 'erp\\_copy.%' ORDER BY setting_key") as $r) {
            $settings[$r['setting_key']] = [$r['value_type'], $r['v'], (int) $r['provisional']];
        }
        self::assertSame(['erp_copy.company' => ['string', '"VAPE AND GO"', 1], 'erp_copy.create_suppliers' => ['bool', 'true', 1], 'erp_copy.from_date' => ['date', '"2026-09-01"', 1],
            'erp_copy.on' => ['bool', 'false', 1], 'erp_copy.per_run' => ['int', '200', 1], 'erp_copy.update_prices' => ['bool', 'true', 1]], $settings);
        $reasons = self::$db->all("SELECT code, CAST(applies_to AS CHAR) AS applies_to, system_only, is_active FROM reason_code WHERE code IN ('erp_cancelled', 'erp_changed') ORDER BY code");
        self::assertSame([['code' => 'erp_cancelled', 'applies_to' => 'reversal', 'system_only' => 1, 'is_active' => 1],
            ['code' => 'erp_changed', 'applies_to' => 'reversal', 'system_only' => 1, 'is_active' => 1]], $reasons);
        self::assertSame(8, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE version = 1 AND action = 'baseline' AND actor = 'system:migrate' "
            . "AND ((subject_type = 'setting' AND subject_key LIKE 'erp\\_copy.%') OR (subject_type = 'reason' AND subject_key IN ('erp_cancelled', 'erp_changed')))"));

        // Every INSERT again (a run stopped half way, or run twice): nothing is added.
        $count = static fn (): array => [(int) self::$db->value('SELECT COUNT(*) FROM app_setting'), (int) self::$db->value('SELECT COUNT(*) FROM reason_code'),
            (int) self::$db->value('SELECT COUNT(*) FROM config_change')];
        $before = $count();
        foreach ($statements as $s) {
            if (preg_match('/^\s*INSERT\b/i', $s) === 1) {
                self::$db->exec($s);
            }
        }
        self::assertSame($before, $count());
    }
}
