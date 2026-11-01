<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reports;

use CW\Tests\Support\IntegrationTestCase;

/**
 * 0057 (Home's stock value tile; docs/decisions.md DG5) on the migrated test schema: report_refresh has the three NULL columns the reports'
 * refresh fills (stock_value, items_with_stock, value_at); nothing else changed.
 */
final class Migration0057Test extends IntegrationTestCase
{
    public function testTheValuationColumns(): void
    {
        $cols = [];
        foreach (self::$db->all("SELECT COLUMN_NAME AS c, COLUMN_TYPE AS t, IS_NULLABLE AS n, COLUMN_DEFAULT AS d FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_refresh' AND COLUMN_NAME IN ('stock_value', 'items_with_stock', 'value_at') ORDER BY ORDINAL_POSITION") as $r) {
            $cols[(string) $r['c']] = [(string) $r['t'], (string) $r['n'], $r['d']];
        }
        self::assertSame(['stock_value' => ['decimal(24,2)', 'YES', null], 'items_with_stock' => ['int unsigned', 'YES', null], 'value_at' => ['datetime(6)', 'YES', null]], $cols);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM schema_migrations WHERE version = '0057_report_stock_value.sql'"), 'recorded as applied');
    }
}
