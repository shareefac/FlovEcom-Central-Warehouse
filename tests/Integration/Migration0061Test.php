<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Reorder\ReorderLines;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0061 ("Buy for N days"; docs/decisions.md BF3-BF5), applied to a scratch schema that already holds stored reorder lines: the eleven
 * columns at the end of reorder_line (INSTANT, only while none is there), the inputs of the rows already there read from their own stored
 * line (only rows without inputs, only lines that have them), the three settings with their baselines; a second run changes nothing, a run
 * stopped after the columns runs again cleanly, and a reorder_line with some of the columns only stops the migration, naming it.
 */
final class Migration0061Test extends IntegrationTestCase
{
    private const FILE = '0061_reorder_buy_for.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m61', $this->dir);
            $this->dir = null;
        }
        parent::tearDown();
    }

    /** @return list<string> the statements of 0061 */
    private static function statements(): array
    {
        return SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
    }

    /**
     * Three stored lines as pack G wrote them (no inputs yet), and one whose stored line lacks the inputs.
     *
     * @return array<string, int> sku ids
     */
    private static function lines(Db $db): array
    {
        $ids = [];
        $details = [
            // 12 a day, 3 + 5 days, 60 + 120, boxes of 24, the smallest order 2, £45.
            'full' => ['d_e6' => 12_000_000, 'lead' => 3, 'safety' => 5, 'position' => 180, 'min_stock' => null, 'max_stock' => null, 'upp' => 24, 'moq' => 2,
                'mult' => 1, 'rounding' => 'up', 'pack_price' => '45.0000', 'review' => 7, 'packs' => 0],
            // the smallest and largest stock, nearest packs, steps, no price, stock below 0.
            'edges' => ['d_e6' => 350_000, 'lead' => 0, 'safety' => 14, 'position' => -7, 'min_stock' => 3, 'max_stock' => 90, 'upp' => 6, 'moq' => 1, 'mult' => 4,
                'rounding' => 'nearest', 'pack_price' => null, 'review' => 14, 'packs' => 1],
            'cents' => ['d_e6' => 0, 'lead' => 2, 'safety' => 5, 'position' => 0, 'min_stock' => 10, 'max_stock' => null, 'upp' => 1, 'moq' => 1, 'mult' => 1,
                'rounding' => 'up', 'pack_price' => '0.0125', 'review' => 7, 'packs' => 10],
            'no_inputs' => ['code' => 'CW-1'],
            'no_detail' => null,
        ];
        foreach ($details as $key => $line) {
            $id = $db->insert('INSERT INTO sku (name) VALUES (?)', ["Buy for {$key}"]);
            $ids[$key] = $id;
            $db->exec('INSERT INTO reorder_line (sku_id, listed, need, urgent, below_safety, never_suggested, no_demand, cover_now_e1, value_e4, brand, supplier_id, '
                . "safety_stock, available, low_stock_level, line_hash, read_at, changed_at) VALUES (?, 1, 0, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0, NULL, ?, "
                . "'2026-11-04 10:00:00', '2026-11-04 10:00:00')", [$id, sha1($key)]);
            if ($line !== null) {
                $db->exec("INSERT INTO reorder_line_detail (sku_id, line, read_at) VALUES (?, CAST(? AS JSON), '2026-11-04 10:00:00')",
                    [$id, json_encode($line + ['sku_id' => $id], JSON_THROW_ON_ERROR)]);
            }
        }
        return $ids;
    }

    /** @return list<?int> the inputs stored for an item (ReorderLines::INPUTS order) */
    private static function inputs(Db $db, int $id): array
    {
        return array_map(static fn (mixed $v): ?int => $v === null ? null : (int) $v,
            array_values((array) $db->one('SELECT ' . implode(', ', ReorderLines::INPUTS) . ' FROM reorder_line WHERE sku_id = ?', [$id])));
    }

    public function testTheColumnsTheInputsOfTheRowsThereAndTheSettings(): void
    {
        [$db, $this->dir] = MigrationFixture::upTo('m61', '0060_erp_invoice_copy.sql');
        $ids = self::lines($db);
        $before = $db->all('SELECT sku_id, listed, need, urgent, below_safety, never_suggested, no_demand, cover_now_e1, value_e4, safety_stock, available, line_hash, '
            . 'read_at, changed_at FROM reorder_line ORDER BY sku_id');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));

        $cols = [];
        foreach ($db->all("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reorder_line' "
            . 'ORDER BY ORDINAL_POSITION') as $c) {
            $cols[(string) $c['COLUMN_NAME']] = [(string) $c['COLUMN_TYPE'], (string) $c['IS_NULLABLE']];
        }
        self::assertSame(ReorderLines::INPUTS, array_slice(array_keys($cols), -11), 'at the end, in this order');
        self::assertSame([['bigint', 'YES'], ['smallint', 'YES'], ['smallint', 'YES'], ['int', 'YES'], ['int', 'YES'], ['int', 'YES'], ['int', 'YES'], ['int', 'YES'],
            ['int', 'YES'], ['tinyint(1)', 'YES'], ['bigint', 'YES']], array_values(array_slice($cols, -11)), 'signed, NULL = not stored yet');

        self::assertSame([12_000_000, 3, 5, 180, null, null, 24, 2, 1, 0, 450_000], self::inputs($db, $ids['full']));
        self::assertSame([350_000, 0, 14, -7, 3, 90, 6, 1, 4, 1, null], self::inputs($db, $ids['edges']));
        self::assertSame([0, 2, 5, 0, 10, null, 1, 1, 1, 0, 125], self::inputs($db, $ids['cents']), '£0.0125 is 125 e4');
        self::assertSame(array_fill(0, 11, null), self::inputs($db, $ids['no_inputs']), 'a stored line without the inputs: left for the refresh');
        self::assertSame(array_fill(0, 11, null), self::inputs($db, $ids['no_detail']));
        self::assertSame($before, $db->all('SELECT sku_id, listed, need, urgent, below_safety, never_suggested, no_demand, cover_now_e1, value_e4, safety_stock, available, '
            . 'line_hash, read_at, changed_at FROM reorder_line ORDER BY sku_id'), 'nothing else of a row changed');

        $settings = [];
        foreach ($db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key LIKE 'reorder.buy\\_for\\_%' "
            . 'ORDER BY setting_key') as $r) {
            $settings[(string) $r['setting_key']] = [$r['value_type'], $r['v'], (int) $r['provisional'], $r['decision']];
        }
        self::assertSame(['reorder.buy_for_max_days' => ['int', '180', 1, 'BF5'], 'reorder.buy_for_min_days' => ['int', '1', 1, 'BF5'],
            'reorder.buy_for_presets' => ['string', '"7,14,28"', 1, 'BF5']], $settings);
        self::assertSame(3, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key LIKE 'reorder.buy\\_for\\_%' "
            . "AND version = 1 AND action = 'baseline' AND actor = 'system:migrate'"));

        // Every statement again (a run stopped half way, or run twice): nothing added, nothing changed; a row given its inputs is never read again.
        $db->exec('UPDATE reorder_line SET stock_position = 175 WHERE sku_id = ?', [$ids['full']]); // the refresh's newer figure
        $snapshot = static fn (): array => [$db->all('SELECT * FROM reorder_line ORDER BY sku_id'), $db->all("SELECT * FROM app_setting WHERE setting_key LIKE 'reorder.%' ORDER BY setting_key"),
            $db->all('SELECT * FROM config_change ORDER BY id')];
        $was = $snapshot();
        foreach (self::statements() as $sql) {
            $db->pdo()->exec($sql);
        }
        self::assertSame($was, $snapshot());
    }

    /** A run stopped after the columns, before the inputs and the migration's own record: run again, it ends cleanly, nothing twice. */
    public function testARunStoppedAfterTheColumnsRunsAgainCleanly(): void
    {
        $statements = self::statements();
        self::assertMatchesRegularExpression('/^\s*UPDATE\s+reorder_line\b/i', (string) end($statements), 'the inputs last');
        [$db, $this->dir] = MigrationFixture::upTo('m61', '0060_erp_invoice_copy.sql');
        $ids = self::lines($db);
        foreach (array_slice($statements, 0, -1) as $sql) {
            $db->pdo()->exec($sql);
        }
        self::assertNull($db->value('SELECT version FROM schema_migrations WHERE version = ?', [self::FILE]), 'stopped, not recorded');
        self::assertSame(array_fill(0, 11, null), self::inputs($db, $ids['full']));
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame([12_000_000, 3, 5, 180, null, null, 24, 2, 1, 0, 450_000], self::inputs($db, $ids['full']));
        self::assertSame([3, 3], [(int) $db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key LIKE 'reorder.buy\\_for\\_%'"),
            (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key LIKE 'reorder.buy\\_for\\_%'")], 'no row twice');
    }

    /** A reorder_line with some of the columns only (made by hand): the migration stops, naming it, and adds none. */
    public function testSomeOfTheColumnsOnlyStopsTheMigration(): void
    {
        [$db, $this->dir] = MigrationFixture::upTo('m61', '0060_erp_invoice_copy.sql');
        $db->pdo()->exec('ALTER TABLE reorder_line ADD COLUMN d_e6 BIGINT NULL');
        try {
            MigrationFixture::migrateRest($db, $this->dir, self::FILE);
            self::fail('some of the columns only is refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('0061 reorder_line has some of the Buy for columns only', $e->getMessage());
        }
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reorder_line' "
            . "AND COLUMN_NAME = 'lead_days'"));
    }
}
