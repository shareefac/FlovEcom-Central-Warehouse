<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;

/**
 * 0016 (IM3; docs/decisions.md I101, I107): the item card, its history and the barcode review hold their rules in SQL too (the
 * CHECKs refuse what CW\Catalogue never writes), the open-review key allows one open row per barcode and item, import_run takes
 * `item_cards`, and the file applies again without an error (each statement finds its work done).
 */
final class Migration0016Test extends IntegrationTestCase
{
    private const CHECK_VIOLATED = 3819;
    private const DUPLICATE = 1062;

    public function testTheCardsChecks(): void
    {
        $sku = self::makeSku('Card item');
        $ok = static fn (array $over): array => $over + ['sku_id' => $sku, 'updated_actor' => 'system:test'];
        $insert = static function (array $row): void {
            self::$db->exec('INSERT INTO item_card (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
        };
        foreach ([
            ['liquid_ml' => '0.0'], ['liquid_ml' => '5000.1'], ['nicotine_mg' => '-0.01'], ['nicotine_mg' => '100.01'], ['duty_liable' => 2], ['discontinued' => 2],
            ['flavour' => 'Mint'], ['flavour_status' => 'confirmed'], ['ecid' => '12345 16'], ['ecid' => 'abc-12'], ['confirmed_at' => '2026-10-07 10:00:00', 'confirmed_actor' => 'staff:1'],
            ['confirmed_actor' => 'staff:1'], ['product_type' => 'single_use'], ['product_type' => 'single_use', 'single_use' => 0], ['version' => 0],
            ['confirmed_breaches' => '["single_use"]'], ['liquid_ml' => '0.0', 'product_type' => 'tank'],
            ['confirmed_breaches' => '[]', 'first_confirmed_at' => '2026-10-07 10:00:00'], ['confirmed_breaches' => '{"a": 1}', 'first_confirmed_at' => '2026-10-07 10:00:00'],
        ] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $insert($ok($bad))), json_encode($bad));
        }
        $insert($ok(['liquid_ml' => '10.0', 'nicotine_mg' => '20.00', 'product_type' => 'single_use', 'single_use' => 1, 'flavour' => 'Mint', 'flavour_status' => 'proposed',
            'ecid' => '12345-16-12345', 'confirmed_at' => '2026-10-07 10:00:00', 'confirmed_actor' => 'staff:1', 'first_confirmed_at' => '2026-10-07 10:00:00']));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_card'));
        // A kit without a tank (0 ml), and the rules of the last confirmation kept while the card is changed since (I113, I115).
        $kit = self::makeSku('Kit');
        $insert(['sku_id' => $kit, 'updated_actor' => 'system:test', 'product_type' => 'device_kit', 'liquid_ml' => '0.0', 'confirmed_breaches' => '["single_use"]',
            'first_confirmed_at' => '2026-10-07 10:00:00']);
        self::$db->exec('DELETE FROM item_card WHERE sku_id = ?', [$kit]);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => self::$db->exec(
            "INSERT INTO item_card_change (sku_id, version, kind, changes, card, actor) VALUES (?, 1, 'confirm', '{}', '{}', 'x')", [$sku])), 'a confirm has no changes');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => self::$db->exec(
            "INSERT INTO item_card_change (sku_id, version, kind, changes, card, actor) VALUES (?, 1, 'change', NULL, '{}', 'x')", [$sku])), 'a change says what changed');
        self::$db->exec('DELETE FROM item_card');
    }

    public function testTheReviewsChecksAndOneOpenRowPerBarcodeAndItem(): void
    {
        $a = self::makeSku('A');
        $b = self::makeSku('B');
        $open = static fn (string $barcode, int $claimant, string $reason = 'on_another_item'): int => self::$db->insert(
            'INSERT INTO barcode_review (barcode, reason, claimant_sku_id, holder_sku_id, opened_actor) VALUES (?, ?, ?, NULL, ?)', [$barcode, $reason, $claimant, 'system:test']);
        $first = $open('5012345678900', $a);
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $open('5012345678900', $a)), 'one open row per barcode and item');
        $open('5012345678900', $b);
        foreach (['0501234567890', '1234567', 'ABC12345', '123456789012345'] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $open($bad, $a)), $bad);
        }
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $open('96385074', $a, 'removed')), 'a removal is recorded decided');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => self::$db->exec("INSERT INTO barcode_review (barcode, reason, claimant_sku_id, status, decision, "
            . "decided_at, decided_actor, opened_actor) VALUES ('96385074', 'multipack_listing', ?, 'decided', 'moved_away', NOW(6), 'x', 'x')", [$a])),
            'moved_away is written for an item a barcode left (on another item)');
        foreach ([
            "UPDATE barcode_review SET status = 'decided' WHERE id = ?",
            "UPDATE barcode_review SET decision = 'move' WHERE id = ?",
            "UPDATE barcode_review SET status = 'decided', decision = 'move', decided_at = NOW(6) WHERE id = ?",
            "UPDATE barcode_review SET status = 'decided', decision = 'removed', decided_at = NOW(6), decided_actor = 'x' WHERE id = ?",
            "UPDATE barcode_review SET decided_units = 0 WHERE id = ?",
        ] as $sql) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => self::$db->exec($sql, [$first])), $sql);
        }
        self::$db->exec("UPDATE barcode_review SET status = 'decided', decision = 'move', decided_at = NOW(6), decided_actor = 'x' WHERE id = ?", [$first]);
        $open('5012345678900', $a);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM barcode_review WHERE barcode = '5012345678900' AND claimant_sku_id = ?", [$a]),
            'a decided row frees the open key');
        self::assertSame(['erp_suppliers', 'erp_supplier_items', 'erp_open_pos', 'item_cards'], self::enumOf('import_run', 'kind'));
    }

    public function testTheFileAppliesAgain(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0016_item_cards.sql');
        foreach (SqlSplitter::split($sql) as $statement) {
            self::$db->pdo()->exec($statement);
        }
        self::assertSame(['item_card', 'item_card_change', 'barcode_review'], array_values(array_filter(['item_card', 'item_card_change', 'barcode_review'],
            static fn (string $t): bool => self::$db->value('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) !== null)));
    }

    /** @return list<string> */
    private static function enumOf(string $table, string $column): array
    {
        $type = (string) self::$db->value('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]);
        preg_match_all("/'([^']*)'/", $type, $m);
        return $m[1];
    }
}
