<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0058 (a store's choice at Add product; docs/decisions.md SC1, SC4), applied to a scratch schema that already holds decisions: the CHECK
 * that names who decides lets the store choice job's new item or link through with no warehouse person and nothing else new; every
 * decision there before still passes it; store_choice is new and keeps one choice per store variant.
 */
final class Migration0058Test extends IntegrationTestCase
{
    private const FILE = '0058_store_choice.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m58', $this->dir);
        }
    }

    public function testTheCheckLetsTheStoreChoiceJobDecideAndNothingElseNew(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+match_decision\s+DROP\s+CHECK\s+ck_match_decision_decider\s*,/is', $statements[0],
            'the ALTER first: if the server refuses it, nothing is applied');
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bINSERT\s+INTO\b/i', implode(";\n", $statements), 'no row is written or changed');

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m58', '0057_report_stock_value.sql');
        $store = $db->insert("INSERT INTO channel (code, name) VALUES ('vapeandgo', 'Vape and Go')");
        $sku = $db->insert("INSERT INTO sku (name) VALUES ('Mr Blue 10mg')");
        $listing = $db->insert("INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, '51001')", [$store]);
        $person = $db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m58', 'M58', 'm58@test.invalid', 'x')");
        $decision = static fn (string $action, ?int $by, string $actor): int => $db->insert('INSERT INTO match_decision (listing_id, action, sku_id, units_per_item, '
            . "decided_by, actor, state, expected_map_version, applied_at) VALUES (?, ?, ?, ?, ?, ?, 'applied', 0, UTC_TIMESTAMP(6))",
            [$listing, $action, in_array($action, ['link', 'new_item'], true) ? $sku : null, in_array($action, ['link', 'new_item'], true) ? 1 : null, $by, $actor]);
        $decision('link', $person, 'staff:' . $person);
        $decision('suggest', null, 'system:arrival');
        $refused = static function (callable $fn): bool {
            try {
                $fn();
                return false;
            } catch (\PDOException $e) {
                return str_contains($e->getMessage(), 'ck_match_decision_decider');
            }
        };
        self::assertTrue($refused(fn () => $decision('new_item', null, 'system:store_choice')), 'before 0058 no job makes an item');

        $before = $db->all('SELECT * FROM match_decision ORDER BY id');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame($before, $db->all('SELECT * FROM match_decision ORDER BY id'), 'no decision changed');
        $decision('new_item', null, 'system:store_choice');
        $decision('link', null, 'system:store_choice');
        self::assertTrue($refused(fn () => $decision('ignore', null, 'system:store_choice')), 'the job ignores nothing');
        self::assertTrue($refused(fn () => $decision('link', null, 'system:landing_pages')), 'another job links nothing');
        self::assertTrue($refused(fn () => $decision('unlink', null, 'system:store_choice')));

        $db->exec("INSERT INTO store_choice (channel_id, external_variant_id, choice, staff_ref, staff_name, chosen_at) VALUES (?, '51001', 'new', '17', 'Aswin', UTC_TIMESTAMP(6))",
            [$store]);
        foreach ([
            "INSERT INTO store_choice (channel_id, external_variant_id, choice, staff_ref, staff_name, chosen_at) VALUES ({$store}, '51001', 'none', '17', 'Aswin', UTC_TIMESTAMP(6))",
            "INSERT INTO store_choice (channel_id, external_variant_id, choice, staff_ref, staff_name, chosen_at) VALUES ({$store}, '51002', 'pack', '17', 'Aswin', UTC_TIMESTAMP(6))",
            "INSERT INTO store_choice (channel_id, external_variant_id, choice, pack_of_code, units, staff_ref, staff_name, chosen_at) VALUES ({$store}, '51003', 'pack', 'CW-000001', 1, '17', 'A', UTC_TIMESTAMP(6))",
            "INSERT INTO store_choice (channel_id, external_variant_id, choice, pack_of_code, units, staff_ref, staff_name, chosen_at) VALUES ({$store}, '51004', 'new', 'CW-000001', 5, '17', 'A', UTC_TIMESTAMP(6))",
        ] as $sql) {
            try {
                $db->exec($sql);
                self::fail('store_choice let through: ' . $sql);
            } catch (\PDOException) {
                // a second choice of one variant, a pack without its item or units, a pack of one, units on a new item
            }
        }
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM store_choice'));
    }
}
