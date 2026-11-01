<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Queues;

use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0056 (the fix lookup's index; docs/decisions.md CA20 (a), CW-913) on the migrated test schema: stock_ledger has ix_stock_ledger_doc_ref
 * (doc_ref, warehouse_id, sku_id, created_at), built in place with no lock; the file runs again without failing (a run stopped after the
 * ALTER and before the migration's own record, or a second run). The lookup reads it: QueueListPlansTest.
 */
final class Migration0056Test extends IntegrationTestCase
{
    private const FILE = '0056_stock_ledger_doc_ref_index.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m56', $this->dir);
        }
    }

    public function testTheIndexAndItRunsAgain(): void
    {
        $columns = static fn (\CW\Db $db): array => array_column($db->all("SELECT column_name AS c FROM information_schema.statistics WHERE table_schema = DATABASE() "
            . "AND table_name = 'stock_ledger' AND index_name = 'ix_stock_ledger_doc_ref' ORDER BY seq_in_index"), 'c');
        self::assertSame(['doc_ref', 'warehouse_id', 'sku_id', 'created_at'], $columns(self::$db));
        $file = (string) file_get_contents(dirname(__DIR__, 3) . '/migrations/' . self::FILE);
        self::assertStringContainsString('ALGORITHM = INPLACE, LOCK = NONE', $file, 'bookings go on while it builds');
        $statements = SqlSplitter::split($file);
        [$db, $this->dir] = MigrationFixture::upTo('m56', '0055_nightly_confirm.sql');
        self::assertSame([], $columns($db), 'not there before 0056');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame(['doc_ref', 'warehouse_id', 'sku_id', 'created_at'], $columns($db));
        // Again (stopped before its own record, or run twice): nothing fails, one index.
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame(['doc_ref', 'warehouse_id', 'sku_id', 'created_at'], $columns($db));
    }
}
