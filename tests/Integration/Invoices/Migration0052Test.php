<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;

/**
 * 0052 (the supplier invoice on one page; docs/decisions.md PV): supplier_invoice.due_date, a date or nothing, added in place (no table
 * copy, no row changed, nothing deleted); the app connection writes it with the rest of the row.
 */
final class Migration0052Test extends IntegrationTestCase
{
    public function testTheDueDateColumnIsAddedInPlace(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 3) . '/migrations/0052_invoice_due_date.sql'));
        self::assertCount(1, $statements);
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+supplier_invoice\s+ADD\s+COLUMN\s+due_date\s+DATE\s+NULL\b.*ALGORITHM\s*=\s*INSTANT\s*$/is', $statements[0]);
        self::assertDoesNotMatchRegularExpression('/\b(UPDATE|DELETE|DROP)\b/i', $statements[0], 'no row changed, nothing deleted');
        $c = self::$db->one("SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_invoice' "
            . "AND COLUMN_NAME = 'due_date'");
        self::assertSame(['date', 'YES', null], [$c['DATA_TYPE'] ?? null, $c['IS_NULLABLE'] ?? null, $c['COLUMN_DEFAULT'] ?? null]);
    }
}
