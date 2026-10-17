<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Ops\OpeningLineage;
use CW\Schema\Grants;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/** XA2: an existing, partially completed replacement is recovered without granting ancestry to unbooked items. */
final class Migration0043Test extends IntegrationTestCase
{
    public function testExistingReplacementAncestryIsBackfilledOnlyForBookedItems(): void
    {
        [$db, $dir] = MigrationFixture::upTo('m43', '0042_po_select_or_add.sql');
        try {
            $main = (int) $db->value("SELECT id FROM warehouse WHERE code = 'MAIN'");
            $verify = (int) $db->value("SELECT id FROM warehouse WHERE code = 'VERIFY'");
            foreach ([101, 102, 103] as $sku) {
                $db->exec('INSERT INTO sku (id, code, name) VALUES (?, ?, ?)', [$sku, sprintf('CW-%06d', $sku), 'Before XA ' . $sku]);
                $db->exec('INSERT INTO stock_balance (warehouse_id, sku_id) VALUES (?, ?)', [$main, $sku]);
            }
            foreach ([[101, 'opening:old', 8], [101, 'erp:current', 11], [101, 'erp:current', -8],
                [102, 'opening:old', 5], [103, 'ERP:CURRENT', 1]] as [$sku, $doc, $qty]) {
                $db->exec("INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, doc_ref, actor) "
                    . "VALUES (?, ?, 'on_hand', ?, ?, 'adjustment', ?, 'system:opening_estimate')", [$main, $sku, $qty, $qty, $doc]);
            }
            $db->exec("INSERT INTO erp_opening_run (doc_ref, as_of, file_name, file_sha256, status, summary, actor, finished_at) "
                . "VALUES ('erp:current', '2000-01-01', 'made-up.csv', REPEAT('a', 64), 'failed', ?, 'system:opening_estimate', NOW(6))",
                [json_encode(['report' => ['replaces' => 'opening:old']], JSON_THROW_ON_ERROR)]);

            self::assertSame(['0043_xhigh_fixes_a.sql'], MigrationFixture::migrateRest($db, $dir, '0043_xhigh_fixes_a.sql'));
            self::assertSame([['warehouse_id' => $main, 'sku_id' => 101, 'doc_ref' => 'erp:current', 'replaces_doc_ref' => 'opening:old']],
                $db->all('SELECT warehouse_id, sku_id, doc_ref, replaces_doc_ref FROM opening_replacement'));
            self::assertSame([101 => ['opening:old' => true]], OpeningLineage::documents($db, $main, ['erp:current']));
            self::assertSame([], OpeningLineage::documents($db, $verify, ['erp:current']));
            self::assertSame(5, (int) $db->value('SELECT COUNT(*) FROM stock_ledger'), 'no stock history is rewritten or removed');
            self::assertSame(['Select', 'Insert'], Grants::desired('opening_replacement'));
            self::assertSame([], Grants::desiredColumns('opening_replacement'));
            self::assertSame(['Select', 'Insert'], Grants::desired('relay_mode_seen'));
            self::assertSame(['changed_at' => ['Update']], Grants::desiredColumns('relay_mode_seen'));
        } finally {
            MigrationFixture::drop('m43', $dir);
        }
    }
}
