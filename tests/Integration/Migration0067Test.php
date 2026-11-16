<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0067 ("Let the website decide", T-556 step 2 option A), applied to a scratch schema that holds per-site modes as 0018 wrote them: the
 * rows and their log are unchanged; a mode (and a log row's mode after) may now be NULL = the website decides, but only as the switch
 * writes it (no "mode before Out-Of-Stock", source switch: the new CHECKs), every 0018 CHECK still holds; the old code's statements
 * still run. How it is applied: two statements, the log first, each ONE copy of its table (ALGORITHM = COPY, LOCK = SHARED), said
 * out loud (never INSTANT or INPLACE: Migration0027Test).
 */
final class Migration0067Test extends IntegrationTestCase
{
    private const FILE = '0067_site_mode_website.sql';
    private const CHECK_VIOLATED = 3819;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m67', $this->dir);
            $this->dir = null;
        }
        parent::tearDown();
    }

    public function testAModeMayBeGivenBackToTheWebsiteAndNothingElseChanges(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        self::assertCount(2, $statements, 'one statement a table');
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+item_channel_mode_log\b/i', $statements[0], 'the log first');
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+item_channel_mode\b(?!_)/i', $statements[1]);
        foreach ($statements as $st) {
            self::assertMatchesRegularExpression('/,\s*ALGORITHM\s*=\s*COPY\s*,\s*LOCK\s*=\s*SHARED\s*$/i', $st);
            self::assertDoesNotMatchRegularExpression('/\b(INSTANT|INPLACE|DROP|DELETE|UPDATE)\b/i', $st);
        }

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m67', '0066_stock_doubt.sql');
        $ch = $db->insert("INSERT INTO channel (code, name) VALUES ('vapeandgo', 'Vape and Go')");
        $sku = $db->insert("INSERT INTO sku (name) VALUES ('Migration 67 item')");
        $db->exec("INSERT INTO item_channel_mode (sku_id, channel_id, mode, previous_mode, low_stock_threshold, version, source, updated_actor) VALUES (?, ?, 'Out-Of-Stock', 'In-Stock', 3, 2, 'switch', 'x')",
            [$sku, $ch]);
        foreach ([[1, 'In-Stock', null, null, 3], [2, 'Out-Of-Stock', 'In-Stock', 3, 3]] as [$v, $after, $prev, $thBefore, $thAfter]) {
            $db->exec("INSERT INTO item_channel_mode_log (sku_id, channel_id, version, mode_before, mode_after, previous_mode, threshold_before, threshold_after, source, reason, actor) "
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'switch', 'why', 'x')", [$sku, $ch, $v, $v === 1 ? null : 'In-Stock', $after, $prev, $thBefore, $thAfter]);
        }
        $rows = static fn (): array => [$db->all('SELECT * FROM item_channel_mode ORDER BY sku_id, channel_id'), $db->all('SELECT * FROM item_channel_mode_log ORDER BY id')];
        $before = $rows();
        $tableId = static fn (string $t): int => (int) $db->value("SELECT TABLE_ID FROM information_schema.INNODB_TABLES WHERE NAME = CONCAT(DATABASE(), '/', ?)", [$t]);
        $ids = [$tableId('item_channel_mode'), $tableId('item_channel_mode_log'), $tableId('item_selling_mode')];
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame($before, $rows(), 'no row changed');
        self::assertGreaterThan($ids[0], $tableId('item_channel_mode'), 'copied once');
        self::assertGreaterThan($ids[1], $tableId('item_channel_mode_log'), 'copied once');
        self::assertSame($ids[2], $tableId('item_selling_mode'), 'no other table was rebuilt');
        $col = static fn (string $t, string $c): array => array_values((array) $db->one('SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]));
        self::assertSame(["enum('In-Stock','From-Warehouse','Out-Of-Stock')", 'YES'], $col('item_channel_mode', 'mode'));
        self::assertSame(["enum('In-Stock','From-Warehouse','Out-Of-Stock')", 'YES'], $col('item_channel_mode_log', 'mode_after'));

        // NULL = the website decides, only as the switch writes it.
        $row = static fn (array $over): mixed => $db->exec('INSERT INTO item_channel_mode (' . implode(', ', array_keys($over)) . ') VALUES ('
            . implode(', ', array_fill(0, count($over), '?')) . ')', array_values($over));
        $ok = ['sku_id' => $sku, 'channel_id' => $ch + 1, 'mode' => null, 'source' => 'switch', 'updated_actor' => 'x'];
        foreach ([['previous_mode' => 'In-Stock'], ['source' => 'receipt']] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $row($bad + $ok)), json_encode($bad));
        }
        $row(['low_stock_threshold' => 4] + $ok);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $row(['mode' => 'In-Stock', 'previous_mode' => 'In-Stock', 'channel_id' => $ch + 2] + $ok)),
            '0018\'s CHECKs still hold');
        $log = static fn (array $over): mixed => $db->exec('INSERT INTO item_channel_mode_log (' . implode(', ', array_keys($over)) . ') VALUES ('
            . implode(', ', array_fill(0, count($over), '?')) . ')', array_values($over));
        $okLog = ['sku_id' => $sku, 'channel_id' => $ch + 1, 'version' => 1, 'mode_after' => null, 'source' => 'switch', 'reason' => 'the website decides', 'actor' => 'x'];
        foreach ([['previous_mode' => 'In-Stock'], ['source' => 'receipt', 'reason' => null]] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $log($bad + $okLog)), json_encode($bad));
        }
        $log($okLog);
        // The old code's statements (0018's SiteModes::write, a mode always named) still run.
        $db->exec("INSERT INTO item_channel_mode (sku_id, channel_id, mode, previous_mode, low_stock_threshold, version, source, document_id, updated_by, updated_actor, updated_at) "
            . "VALUES (?, ?, 'From-Warehouse', NULL, NULL, 1, 'switch', NULL, NULL, 'x', UTC_TIMESTAMP(6)) AS new ON DUPLICATE KEY UPDATE mode = new.mode, previous_mode = new.previous_mode, "
            . 'version = item_channel_mode.version + 1', [$sku, $ch + 1]);
        self::assertSame(['From-Warehouse', 2], array_values((array) $db->one('SELECT mode, version FROM item_channel_mode WHERE sku_id = ? AND channel_id = ?', [$sku, $ch + 1])));
    }
}
