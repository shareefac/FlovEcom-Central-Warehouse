<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;

/**
 * 0060 (CW-917; docs/decisions.md EC1-EC16): supplier_invoice.origin added in place (first, INSTANT, as 0052; 'cw' for every record before
 * it); the three new tables; the two reasons of the job (CW's own, for cancellations) and the five settings (the main switch OFF, the
 * company, the first date, make suppliers on, last prices on), each with its version 1; nothing existing rewritten or deleted; every insert
 * safe to run again.
 */
final class Migration0060Test extends IntegrationTestCase
{
    private const FILE = __DIR__ . '/../../../migrations/0060_erp_invoice_copy.sql';

    public function testTheColumnTablesReasonsAndSettings(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(self::FILE));
        self::assertMatchesRegularExpression("/^ALTER\\s+TABLE\\s+supplier_invoice\\s+ADD\\s+COLUMN\\s+origin\\s+ENUM\\('cw','erpnext'\\)\\s+NOT\\s+NULL\\s+DEFAULT\\s+'cw'.*ALGORITHM\\s*=\\s*INSTANT\\s*$/is",
            $statements[0]);
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
            'erp_copy.on' => ['bool', 'false', 1], 'erp_copy.update_prices' => ['bool', 'true', 1]], $settings);
        $reasons = self::$db->all("SELECT code, CAST(applies_to AS CHAR) AS applies_to, system_only, is_active FROM reason_code WHERE code IN ('erp_cancelled', 'erp_changed') ORDER BY code");
        self::assertSame([['code' => 'erp_cancelled', 'applies_to' => 'reversal', 'system_only' => 1, 'is_active' => 1],
            ['code' => 'erp_changed', 'applies_to' => 'reversal', 'system_only' => 1, 'is_active' => 1]], $reasons);
        self::assertSame(7, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE version = 1 AND action = 'baseline' AND actor = 'system:migrate' "
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
