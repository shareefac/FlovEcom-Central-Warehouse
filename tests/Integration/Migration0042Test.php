<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;

/**
 * 0042 (pack PO; docs/decisions.md PO9, PO10) on the migrated test schema: the order's terms as approved (a column, NULL by default) and the
 * part of a supplier invoice a delivery is (1 by default, between 1 and 999), the UNIQUE key of a live invoice number being (supplier,
 * invoice key, part). Each table is altered in one statement, and the file removes nothing but the key it replaces.
 */
final class Migration0042Test extends IntegrationTestCase
{
    private const FILE = '0042_po_select_or_add.sql';

    public function testTheTwoColumnsAndTheKey(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        $alters = array_values(array_filter($statements, static fn (string $s): bool => preg_match('/^ALTER\s+TABLE\b/i', $s) === 1));
        self::assertCount(2, $alters, 'one statement a table');
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+purchase_order\s+ADD\s+COLUMN\s+terms_snapshot\b.*ALGORITHM\s*=\s*INPLACE\s*,\s*LOCK\s*=\s*NONE\s*$/is', $alters[0]);
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+goods_receipt\s+ADD\s+COLUMN\s+invoice_part\b.*ALGORITHM\s*=\s*COPY\s*,\s*LOCK\s*=\s*SHARED\s*$/is', $alters[1]);
        foreach ($statements as $s) {
            self::assertDoesNotMatchRegularExpression('/\bINSTANT\b/i', $s);
            self::assertDoesNotMatchRegularExpression('/^\s*(DROP|DELETE|TRUNCATE|UPDATE|INSERT)\b/i', $s, 'nothing is removed or rewritten');
        }

        $col = static fn (string $table, string $column): ?array => self::$db->one('SELECT IS_NULLABLE AS n, COLUMN_DEFAULT AS d, DATA_TYPE AS t FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
        self::assertSame(['YES', null, 'text'], array_values((array) $col('purchase_order', 'terms_snapshot')));
        self::assertSame(['NO', '1', 'smallint'], array_values((array) $col('goods_receipt', 'invoice_part')));
        $key = array_map('strval', self::$db->column('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . 'AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', ['goods_receipt', 'uq_goods_receipt_invoice']));
        self::assertSame(['supplier_id', 'invoice_key', 'invoice_part'], $key);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() "
            . "AND CONSTRAINT_NAME = 'ck_goods_receipt_invoice_part'"));
    }
}
