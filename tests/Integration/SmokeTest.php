<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Migrator;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/** Connect, migrate, seed a channel + sku + balance, read it back. */
final class SmokeTest extends IntegrationTestCase
{
    public const CORE_TABLES = [
        'audit_log', 'channel', 'channel_health', 'channel_listing', 'channel_warehouse', 'count_review',
        'goods_in_suspense', 'idempotency', 'listing_profile', 'oversell_event', 'policy_review',
        'reservation', 'reservation_unit', 'schema_migrations', 'sku', 'sku_barcode', 'sku_erp_item',
        'staff_user', 'stock_balance', 'stock_change', 'stock_ledger', 'warehouse',
    ];

    public function testConnectionIsEncryptedUtcReadCommitted(): void
    {
        $row = self::$db->one(
            'SELECT @@session.time_zone AS tz, @@session.transaction_isolation AS iso, @@session.sql_mode AS mode,'
            . ' @@session.collation_connection AS coll, DATABASE() AS db,'
            . ' TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS skew',
        );
        self::assertNotNull($row);
        self::assertSame('+00:00', $row['tz']);
        self::assertSame('READ-COMMITTED', $row['iso']);
        self::assertSame('utf8mb4_0900_ai_ci', $row['coll']);
        self::assertSame(TestDb::name(), $row['db']);
        self::assertSame(0, (int) $row['skew'], 'NOW() must equal UTC_TIMESTAMP()');
        self::assertStringNotContainsString('ANSI_QUOTES', (string) $row['mode']);
        self::assertStringContainsString('STRICT_ALL_TABLES', (string) $row['mode']);

        $cipher = self::$db->pdo()->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(\PDO::FETCH_NUM);
        self::assertIsArray($cipher);
        self::assertNotSame('', $cipher[1], 'connection must be TLS');
    }

    public function testEveryCoreTableExistsAndMigrationIsRecorded(): void
    {
        $tables = self::$db->column(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
        );
        foreach (self::CORE_TABLES as $t) {
            self::assertContains($t, $tables, "table {$t} missing");
        }
        $engines = self::$db->column(
            "SELECT DISTINCT CONCAT(ENGINE, '/', TABLE_COLLATION) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()",
        );
        self::assertSame(['InnoDB/utf8mb4_0900_ai_ci'], $engines);

        $applied = self::$db->column('SELECT version FROM schema_migrations ORDER BY version');
        self::assertContains('0001_core.sql', $applied);
        self::assertSame([], (new Migrator(self::$db, Migrator::defaultDir()))->migrate(), 'second run applies nothing');
    }

    public function testSeedChannelSkuBalanceAndReadBack(): void
    {
        $main = self::warehouseId('MAIN');
        self::assertGreaterThan(0, $main);

        [$channelId, $skuId] = self::$db->transaction(static function (Db $db) use ($main): array {
            $channelId = $db->insert(
                "INSERT INTO channel (code, name, mode, api_key_hash, allowed_ips) VALUES ('vpg', 'Vape and Go', 'shadow', ?, JSON_ARRAY('161.35.163.191'))",
                [hash('sha256', 'test-key')],
            );
            $db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$channelId, $main]);
            $skuId = $db->insert("INSERT INTO sku (name, brand, strength_mg, nic_type) VALUES ('Elux Legend 3500 Blue Razz 20mg', 'Elux', 20, 'salt')");
            $db->exec('UPDATE sku SET code = ? WHERE id = ?', [sprintf('CW-%06d', $skuId), $skuId]);
            $db->exec('INSERT INTO stock_balance (warehouse_id, sku_id, on_hand, allocated, held) VALUES (?, ?, 10, 2, 1)', [$main, $skuId]);
            $db->exec(
                "INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, actor) VALUES (?, ?, 'on_hand', 10, 10, 'opening', 'system:test')",
                [$main, $skuId],
            );
            $db->exec("INSERT INTO stock_change (sku_id, reason) VALUES (?, 'stock')", [$skuId]);
            return [$channelId, $skuId];
        });

        $row = self::$db->one(
            'SELECT c.code AS channel, c.mode, w.code AS warehouse, s.code AS sku, s.sell_policy,'
            . ' b.on_hand, b.allocated, b.held, b.on_hand - b.allocated - b.held AS available'
            . ' FROM channel c'
            . ' JOIN channel_warehouse cw ON cw.channel_id = c.id AND cw.is_sellable = 1'
            . ' JOIN warehouse w ON w.id = cw.warehouse_id'
            . ' JOIN stock_balance b ON b.warehouse_id = w.id AND b.sku_id = ?'
            . ' JOIN sku s ON s.id = b.sku_id'
            . ' WHERE c.id = ?',
            [$skuId, $channelId],
        );
        self::assertSame([
            'channel' => 'vpg', 'mode' => 'shadow', 'warehouse' => 'MAIN', 'sku' => sprintf('CW-%06d', $skuId),
            'sell_policy' => 'legacy', 'on_hand' => 10, 'allocated' => 2, 'held' => 1, 'available' => 7,
        ], $row);

        self::assertSame(['161.35.163.191'], json_decode((string) self::$db->value('SELECT allowed_ips FROM channel WHERE id = ?', [$channelId]), true));
        self::assertSame(2400, self::$db->value('SELECT reserve_ttl_sec FROM channel WHERE id = ?', [$channelId]));
        self::assertSame(1, self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ?', [$skuId]));
        self::assertGreaterThan(0, (int) self::$db->value('SELECT MAX(seq) FROM stock_change'));

        $created = (string) self::$db->value('SELECT created_at FROM sku WHERE id = ?', [$skuId]);
        $age = abs(time() - (new \DateTimeImmutable($created, new \DateTimeZone('UTC')))->getTimestamp());
        self::assertLessThan(120, $age, 'created_at must be stored in UTC');
    }
}
