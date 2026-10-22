<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;
use CW\Ui\Words;

/**
 * 0050 (landing pages; docs/decisions.md LP1-LP12), applied to a scratch schema that already holds website products and decisions:
 * the new status and the two new decisions, the CHECK that lets only a system `suggest` or a system `landing` go without a person,
 * the website-bug list, the setting with its baseline; no row's data changes and no row is classified by the file; and how it is
 * applied: each of the two tables in ONE copy, said out loud (this server refuses the ENUM change of channel_listing in place).
 */
final class Migration0050Test extends IntegrationTestCase
{
    private const FILE = '0050_landing_pages.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m50', $this->dir);
        }
    }

    public function testItAddsTheKindTheDecisionsAndTheBugListWithoutClassifyingAnyRow(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+channel_listing\b.*ALGORITHM\s*=\s*COPY\s*,\s*LOCK\s*=\s*SHARED\s*$/is', $statements[0]);
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+match_decision\b.*ALGORITHM\s*=\s*COPY\s*,\s*LOCK\s*=\s*SHARED\s*$/is', $statements[1]);
        foreach (['channel_listing', 'match_decision'] as $t) {
            self::assertSame(1, preg_match_all('/\bALTER\s+TABLE\s+' . $t . '\b/i', implode(";\n", $statements)), "one copy of {$t}");
        }
        self::assertDoesNotMatchRegularExpression('/\b(INSTANT|INPLACE)\b/i', $statements[0] . $statements[1], 'this server copies both (the measure in the file)');
        self::assertDoesNotMatchRegularExpression('/\bUPDATE\s+channel_listing\b|\bINSERT\s+INTO\s+match_decision\b/i', implode(";\n", $statements),
            'the file classifies no row: bin/landing_pages.php does, after the deploy');

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m50', '0049_search_index.sql');
        $store = $db->insert("INSERT INTO channel (code, name) VALUES ('electrofag', 'Electrofag')");
        $staff = $db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m50', 'M 50', 'm50@test.example', 'x')");
        $sku = $db->insert("INSERT INTO sku (name) VALUES ('Blue Razz Cherry 10mg')");
        $db->exec("UPDATE sku SET code = CONCAT('CW-', LPAD(id, 6, '0')) WHERE id = ?", [$sku]);
        $l = [];
        foreach (['958' => 'mapped', '959' => 'unmapped', '960' => 'ignored'] as $v => $status) {
            $l[$v] = $db->insert('INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, status) VALUES (?, ?, ?, ?)',
                [$store, $v, $status === 'mapped' ? $sku : null, $status]);
        }
        $db->exec("INSERT INTO match_decision (listing_id, action, sku_id, units_per_item, prev_status, decided_by, actor, state, expected_map_version, applied_at) "
            . "VALUES (?, 'link', ?, 1, 'unmapped', ?, 'staff:1', 'applied', 0, UTC_TIMESTAMP(6))", [$l['958'], $sku, $staff]);
        $before = $db->all('SELECT id, channel_id, external_variant_id, sku_id, units_per_item, status, map_version FROM channel_listing ORDER BY id');
        $decisions = $db->all('SELECT * FROM match_decision ORDER BY id');
        $tableId = static fn (string $t): int => (int) $db->value("SELECT TABLE_ID FROM information_schema.INNODB_TABLES WHERE NAME = CONCAT(DATABASE(), '/', ?)", [$t]);
        $ids0 = ['channel_listing' => $tableId('channel_listing'), 'match_decision' => $tableId('match_decision'), 'listing_profile' => $tableId('listing_profile')];

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertNotSame($ids0['channel_listing'], $tableId('channel_listing'), 'channel_listing was copied once (the server refuses the ENUM in place)');
        self::assertSame($ids0['listing_profile'], $tableId('listing_profile'), 'listing_profile is not touched');
        self::assertNotSame($ids0['match_decision'], $tableId('match_decision'), 'match_decision was copied once (its CHECK is checked against every row)');
        self::assertSame($before, $db->all('SELECT id, channel_id, external_variant_id, sku_id, units_per_item, status, map_version FROM channel_listing ORDER BY id'),
            'no website product is classified by the migration');
        self::assertSame($decisions, $db->all('SELECT * FROM match_decision ORDER BY id'));

        // The kind and the decisions.
        $type = static fn (string $t, string $c): string => (string) $db->value('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
        self::assertSame("enum('unmapped','suggested','mapped','quarantined','ignored','landing')", $type('channel_listing', 'status'));
        self::assertSame("enum('unmapped','suggested','mapped','quarantined','ignored','landing')", $type('match_decision', 'prev_status'));
        self::assertSame("enum('link','unlink','new_item','ignore','reject','suggest','merge_skus','split','unignore','send_back','landing','not_landing')",
            $type('match_decision', 'action'));
        $db->exec("UPDATE channel_listing SET status = 'landing' WHERE id = ?", [$l['959']]);
        self::assertSame(3819, self::mysqlErrorOn($db, static fn () => $db->exec("UPDATE channel_listing SET status = 'landing' WHERE id = ?", [$l['958']])),
            'a landing page has no item: the CHECK of 0001 holds');
        $insert = static fn (string $action, ?int $by): int => $db->insert('INSERT INTO match_decision (listing_id, action, prev_status, decided_by, actor, state, '
            . "expected_map_version, applied_at) VALUES (?, ?, 'unmapped', ?, ?, 'applied', 0, UTC_TIMESTAMP(6))", [$l['959'], $action, $by, $by === null ? 'system:landing' : 'staff:1']);
        self::assertGreaterThan(0, $insert('landing', null), 'a system landing mark names no person');
        self::assertGreaterThan(0, $insert('not_landing', $staff));
        self::assertGreaterThan(0, $insert('landing', $staff), 'a person may mark one too');
        self::assertSame(3819, self::mysqlErrorOn($db, static fn () => $insert('not_landing', null)), '"a real product" is always a person\'s');
        self::assertSame(3819, self::mysqlErrorOn($db, static fn () => $insert('ignore', null)), 'every other decision still names its person');

        // The website-bug list.
        $db->exec("INSERT INTO landing_order (channel_id, order_ref, listing_id, seen_in, units) VALUES (?, '5001', ?, 'commit', 2)", [$store, $l['959']]);
        self::assertSame(1062, self::mysqlErrorOn($db, static fn () => $db->exec("INSERT INTO landing_order (channel_id, order_ref, listing_id, seen_in, units) "
            . "VALUES (?, '5001', ?, 'reserve', 1)", [$store, $l['959']])), 'one row per order and landing page');
        self::assertSame(3819, self::mysqlErrorOn($db, static fn () => $db->exec("UPDATE landing_order SET status = 'resolved'")), 'a settled row says when');
        self::assertSame(3819, self::mysqlErrorOn($db, static fn () => $db->exec("INSERT INTO landing_order (channel_id, order_ref, listing_id, seen_in, units) "
            . "VALUES (?, '5002', ?, 'commit', 0)", [$store, $l['959']])));
        self::assertSame(['Select', 'Insert', 'Update'], Grants::desired('landing_order'), 'settled, never removed');

        // The old code's statements still run: a link, an ignore, a system suggest.
        $db->exec("INSERT INTO match_decision (listing_id, action, prev_status, decided_by, actor, state, expected_map_version, applied_at) "
            . "VALUES (?, 'suggest', 'unmapped', NULL, 'system:test', 'applied', 0, UTC_TIMESTAMP(6))", [$l['960']]);
        $db->exec("UPDATE channel_listing SET status = 'ignored', map_version = map_version + 1 WHERE id = ?", [$l['960']]);
    }

    public function testTheSettingItsBaselineAndItsWords(): void
    {
        $r = self::$db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = 'mapping.landing_rule_on_arrival'");
        self::assertSame(['bool', 'true', 1, 'LP4'], [$r['value_type'], $r['v'], (int) $r['provisional'], $r['decision']]);
        self::assertTrue((new Settings(self::$db))->get('mapping.landing_rule_on_arrival'));
        self::assertArrayHasKey('mapping.landing_rule_on_arrival', Words::SETTING);
        self::assertArrayHasKey('mapping.landing_rule_on_arrival', Words::SETTING_HELP);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'mapping.landing_rule_on_arrival' "
            . "AND action = 'baseline' AND version = 1"));
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4 hold');
    }

    private static function mysqlErrorOn(Db $db, callable $fn): int
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            return (int) ($e->errorInfo[1] ?? 0);
        }
        return 0;
    }
}
