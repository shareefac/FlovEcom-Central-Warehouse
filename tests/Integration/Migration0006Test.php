<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Invariants;
use CW\Schema\Migrator;
use CW\Schema\SqlSplitter;
use CW\Stock;
use CW\Tests\Support\MigrationFixture;
use PHPUnit\Framework\TestCase;

/**
 * I4: 0006 backfills the value sequence of the ledger rows booked before it (8,199 opening adjustments on
 * cw_staging): per item, every on_hand row gets a seq in ledger id order (across warehouses), no other
 * bucket gets one, and every item gets a clock row (0 when it has no on_hand row). Runs on a scratch schema
 * migrated up to 0005.
 */
final class Migration0006Test extends TestCase
{
    private const SUFFIX = 'm6';
    private const SUFFIX_WINDOW = 'm6w';

    private ?string $suffix = null;

    private ?string $dir = null;

    protected function setUp(): void
    {
        if (getenv('CW_TEST_DB') === '0') {
            self::markTestSkipped('CW_TEST_DB=0: database tests disabled');
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop($this->suffix ?? self::SUFFIX, $this->dir);
        }
    }

    public function testTheBackfillNumbersOnHandRowsPerItemInLedgerIdOrder(): void
    {
        [$db, $this->dir] = MigrationFixture::upTo(self::SUFFIX, '0005_listing_barcodes_index.sql');
        self::assertNull($db->value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_value_seq'"));
        $wh = array_map('intval', array_column($db->all('SELECT code, id FROM warehouse'), 'id', 'code'));
        [$a, $b, $c] = [101, 102, 103];
        foreach ([$a, $b, $c] as $id) {
            $db->exec('INSERT INTO sku (id, code, name) VALUES (?, ?, ?)', [$id, sprintf('CW-%06d', $id), 'Before 0006 ' . $id]);
        }
        $db->exec('INSERT INTO stock_balance (warehouse_id, sku_id, on_hand) VALUES (?, ?, 7), (?, ?, 2), (?, ?, 4)',
            [$wh['MAIN'], $a, $wh['VERIFY'], $a, $wh['MAIN'], $b]);
        // A: on_hand at MAIN (ids 1, 3), at VERIFY (id 2), a hold and its release (ids 4, 6); B: on_hand (id 5); C: nothing.
        foreach ([
            [1, $wh['MAIN'], $a, 'on_hand', 5, 5, 'adjustment'],
            [2, $wh['VERIFY'], $a, 'on_hand', 2, 2, 'transfer_in'],
            [3, $wh['MAIN'], $a, 'on_hand', 2, 7, 'goods_in'],
            [4, $wh['MAIN'], $a, 'held', 1, 1, 'reserve'],
            [5, $wh['MAIN'], $b, 'on_hand', 4, 4, 'adjustment'],
            [6, $wh['MAIN'], $a, 'held', -1, 0, 'release'],
        ] as $r) {
            $db->exec('INSERT INTO stock_ledger (id, warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, actor) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, 'system:opening_estimate')", $r);
        }

        self::assertSame(['0006_value_core.sql'], MigrationFixture::migrateRest($db, $this->dir, '0006_value_core.sql'));

        $seq = static fn (Db $db, int $sku): array => array_map(static fn (array $r): array => [(int) $r['seq'], (int) $r['stock_ledger_id']],
            $db->all('SELECT seq, stock_ledger_id FROM stock_value_seq WHERE sku_id = ? ORDER BY seq', [$sku]));
        self::assertSame([[1, 1], [2, 2], [3, 3]], $seq($db, $a), 'A: ledger id order across MAIN and VERIFY');
        self::assertSame([[1, 5]], $seq($db, $b));
        self::assertSame([], $seq($db, $c));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM stock_value_seq WHERE stock_ledger_id IN (4, 6)'), 'held rows get no seq');
        self::assertSame([$a => 3, $b => 1, $c => 0], array_map('intval', array_column($db->all('SELECT sku_id, last_seq FROM stock_value_clock ORDER BY sku_id'), 'last_seq', 'sku_id')));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM stock_ledger WHERE unit_cost IS NOT NULL OR cost_currency IS NOT NULL '
            . 'OR cost_source IS NOT NULL OR document_id IS NOT NULL OR document_line IS NOT NULL'));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM stock_value_ledger'), 'the value ledger starts empty');
        // The full check includes the document base's (0008, DocumentInvariants), so the scratch schema gets the later
        // migrations first; they leave the backfilled rows as they are.
        MigrationFixture::migrateRest($db, $this->dir);
        self::assertSame([[1, 1], [2, 2], [3, 3]], $seq($db, $a));
        self::assertSame([], Invariants::check($db));
    }

    /**
     * I28 (review finding): the migrator runs each statement on its own, so old code may book an on_hand row between the
     * seq backfill and the clock backfill. The clock is taken from the seq rows, so such a row is a row WITHOUT a seq
     * (invariant 7, which a repair can append) and the next booking continues at n + 1. A clock counted from the ledger
     * again would have been ahead of the seqs: the next booking would have left a gap no later booking can fill.
     */
    public function testARowBookedBetweenTheBackfillStatementsLeavesNoGap(): void
    {
        $this->suffix = self::SUFFIX_WINDOW;
        [$db, $this->dir] = MigrationFixture::upTo(self::SUFFIX_WINDOW, '0005_listing_barcodes_index.sql');
        $main = (int) $db->value("SELECT id FROM warehouse WHERE code = 'MAIN'");
        $a = 201;
        $db->exec('INSERT INTO sku (id, code, name) VALUES (?, ?, ?)', [$a, sprintf('CW-%06d', $a), 'Window item']);
        $db->exec('INSERT INTO stock_balance (warehouse_id, sku_id, on_hand) VALUES (?, ?, 5)', [$main, $a]);
        $ledger = 'INSERT INTO stock_ledger (id, warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, actor) '
            . "VALUES (?, ?, ?, 'on_hand', ?, ?, 'adjustment', 'system:old_code')";
        $db->exec($ledger, [1, $main, $a, 5, 5]);

        $file = Migrator::defaultDir() . '/0006_value_core.sql';
        $sql = (string) file_get_contents($file);
        $statements = SqlSplitter::split($sql);
        $last = array_pop($statements);
        self::assertStringContainsString('INSERT INTO stock_value_clock', (string) $last);
        foreach ($statements as $stmt) {
            $db->pdo()->exec($stmt);
        }
        // Old code books between the two backfill statements.
        $db->exec($ledger, [2, $main, $a, 2, 7]);
        $db->exec('UPDATE stock_balance SET on_hand = 7 WHERE warehouse_id = ? AND sku_id = ?', [$main, $a]);
        $db->pdo()->exec((string) $last);
        copy($file, $this->dir . '/0006_value_core.sql');
        $db->exec('INSERT INTO schema_migrations (version, checksum, applied_at, duration_ms) VALUES (?, ?, UTC_TIMESTAMP(6), 0)',
            ['0006_value_core.sql', Migrator::checksum($sql)]);
        MigrationFixture::migrateRest($db, $this->dir);

        self::assertSame(1, (int) $db->value('SELECT last_seq FROM stock_value_clock WHERE sku_id = ?', [$a]), 'the clock follows the seq rows');
        $stock = new Stock($db);
        $db->transaction(function () use ($stock, $main, $a): void {
            $stock->lock([[$main, $a]]);
            $stock->apply($main, $a, 'on_hand', 1, ['type' => 'adjustment', 'actor' => 'system:test', 'note' => 'new code']);
            $stock->flush();
        });
        $seqs = array_map(static fn (array $r): array => [(int) $r['seq'], (int) $r['stock_ledger_id']],
            $db->all('SELECT seq, stock_ledger_id FROM stock_value_seq WHERE sku_id = ? ORDER BY seq', [$a]));
        self::assertSame(1, $seqs[0][1]);
        self::assertSame([1, 2], array_column($seqs, 0), 'contiguous: the new booking continues at n + 1');
        $v = Invariants::check($db);
        self::assertCount(1, $v, implode("\n", $v));
        self::assertStringContainsString('ledger row 2 (adjustment, on_hand of', $v[0]);
        self::assertStringContainsString('has no value seq', $v[0]);
    }
}
