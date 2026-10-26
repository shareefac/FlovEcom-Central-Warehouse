<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;
use CW\Ui\Queries;
use CW\Ui\Words;

/**
 * 0051 (the matching lists leave out website products not sold in a year, and warehouse products not sold any more; docs/decisions.md
 * HU1-HU9), applied to a scratch schema that already holds website products and item cards: the two indexes, each added in place (no
 * table is copied: the tables keep their InnoDB id), the setting with its baseline; no row's data changes.
 */
final class Migration0051Test extends IntegrationTestCase
{
    private const FILE = '0051_hide_unsold.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m51', $this->dir);
        }
    }

    public function testItAddsTwoIndexesInPlaceAndTheSettingWithoutChangingARow(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+channel_listing\s+ADD\s+INDEX\s+ix_channel_listing_unsold\s*\(channel_id,\s*status,\s*created_at\)\s*,'
            . '\s*ALGORITHM\s*=\s*INPLACE\s*,\s*LOCK\s*=\s*NONE\s*$/is', $statements[0], 'the ALTERs first: if the server refuses one, nothing after it is applied');
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+item_card\s+ADD\s+INDEX\s+ix_item_card_discontinued\s*\(discontinued\)\s*,'
            . '\s*ALGORITHM\s*=\s*INPLACE\s*,\s*LOCK\s*=\s*NONE\s*$/is', $statements[1]);
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+channel\s+ADD\s+COLUMN\s+first_load_at\s+DATETIME\(6\)\s+NULL\b.*ALGORITHM\s*=\s*INPLACE\s*,\s*LOCK\s*=\s*NONE\s*$/is',
            $statements[2], 'the store\'s first load (HU9)');
        self::assertDoesNotMatchRegularExpression('/\bDELETE\b|\bUPDATE\s+(channel_listing|listing_profile|item_card|sku)\b|\bINSERT\s+INTO\s+(channel_listing|listing_profile|item_card|sku)\b/i',
            implode(";\n", $statements), 'no row of the data is changed: only each store\'s first load is written');

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m51', '0050_landing_pages.sql');
        $store = $db->insert("INSERT INTO channel (code, name) VALUES ('electrofag', 'Electrofag')");
        $sku = $db->insert("INSERT INTO sku (name) VALUES ('Blue Razz 10mg')");
        $db->exec("UPDATE sku SET code = CONCAT('CW-', LPAD(id, 6, '0')) WHERE id = ?", [$sku]);
        $db->exec("INSERT INTO item_card (sku_id, discontinued, updated_actor) VALUES (?, 1, 'm51')", [$sku]);
        foreach (['958' => 'mapped', '959' => 'unmapped', '960' => 'suggested'] as $v => $status) {
            $id = $db->insert('INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, status, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6) - INTERVAL 400 DAY)',
                [$store, $v, $status === 'mapped' ? $sku : null, $status]);
            $db->exec("INSERT INTO listing_profile (listing_id, product_title, units_30d, units_365d) VALUES (?, 'P', 0, 0)", [$id]);
        }
        $rows = static fn (): array => [$db->all('SELECT * FROM channel_listing ORDER BY id'), $db->all('SELECT * FROM item_card ORDER BY sku_id'),
            $db->all('SELECT listing_id, units_30d, units_365d, updated_at FROM listing_profile ORDER BY listing_id')];
        $before = $rows();
        $tableId = static fn (string $t): int => (int) $db->value("SELECT TABLE_ID FROM information_schema.INNODB_TABLES WHERE NAME = CONCAT(DATABASE(), '/', ?)", [$t]);
        $ids = ['channel_listing' => $tableId('channel_listing'), 'item_card' => $tableId('item_card')];

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame($ids, ['channel_listing' => $tableId('channel_listing'), 'item_card' => $tableId('item_card')], 'in place: no table was copied');
        self::assertSame($before, $rows(), 'no row changed');
        $cols = static fn (string $t, string $ix): array => array_map('strval', $db->column('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [$t, $ix]));
        self::assertSame(['channel_id', 'status', 'created_at'], $cols('channel_listing', 'ix_channel_listing_unsold'));
        self::assertSame(['discontinued'], $cols('item_card', 'ix_item_card_discontinued'));
        self::assertSame('1', (string) $db->value("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'ix_channel_listing_unsold' LIMIT 1"),
            'not unique: no new duplicate-key locks for a listing push');
        // The old code's statements still run on the new schema (a push of a new variant, a decision's status change, a card's change).
        $db->insert("INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, '961')", [$store]);
        $db->exec("UPDATE channel_listing SET status = 'ignored', map_version = map_version + 1 WHERE external_variant_id = '959'");
        $db->exec('UPDATE item_card SET discontinued = 0, version = version + 1 WHERE sku_id = ?', [$sku]);
        // The hidden count runs on it, by the new index.
        $hidden = $db->one(Queries::UNSOLD_HIDDEN_SQL, [$store, \CW\Clock::db(\CW\Clock::now()->modify('-365 days'))]);
        self::assertSame(1, (int) ($hidden['n_all'] ?? -1), 'the suggested one first seen 400 days ago, nothing sold: the matched, the ignored and the new one are not');
    }

    /**
     * HU9 (the owner's answer "b"): each store's first load is recorded once, by the migration, from the stores' own rows: the end of the
     * first run of website products made one after another (no gap of more than 30 minutes, the import's batches), at most a day after the
     * store's first product. A product made after it is new; a store with no product has none (the import tool records it later).
     */
    public function testTheMigrationRecordsEachStoresFirstLoad(): void
    {
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m51', '0050_landing_pages.sql');
        $t0 = '2026-10-10 09:00:00.000000';
        $at = static fn (int $seconds): string => \CW\Clock::db(\CW\Clock::fromDb($t0)->modify("+{$seconds} seconds"));
        $stores = [];
        foreach (['one', 'slow', 'none', 'long'] as $code) {
            $stores[$code] = $db->insert('INSERT INTO channel (code, name) VALUES (?, ?)', [$code, ucfirst($code)]);
        }
        $n = 0;
        $listing = static function (string $code, int $seconds) use ($db, $stores, $at, &$n): void {
            $db->insert('INSERT INTO channel_listing (channel_id, external_variant_id, created_at) VALUES (?, ?, ?)', [$stores[$code], 'v' . ++$n, $at($seconds)]);
        };
        // one: a load of three batches a second apart, then a product two hours later (new).
        foreach ([0, 1, 2, 7200] as $s) {
            $listing('one', $s);
        }
        // slow: batches 20 minutes apart (one load), then 40 minutes of quiet, then a product (new).
        foreach ([0, 1200, 2400, 4800] as $s) {
            $listing('slow', $s);
        }
        // long: products every 20 minutes for two days: the load is the first day at most.
        for ($s = 0; $s <= 2 * 86400; $s += 1200) {
            $listing('long', $s);
        }
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        $first = [];
        foreach ($db->all('SELECT code, first_load_at FROM channel ORDER BY id') as $r) {
            $first[(string) $r['code']] = $r['first_load_at'] === null ? null : (string) $r['first_load_at'];
        }
        self::assertSame(['one' => $at(2), 'slow' => $at(2400), 'none' => null, 'long' => $at(86400)], $first);
    }

    public function testTheSettingItsBaselineAndItsWords(): void
    {
        $r = self::$db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = 'mapping.hide_unsold_days'");
        self::assertSame(['int', '365', 1, 'HU1'], [$r['value_type'], $r['v'], (int) $r['provisional'], $r['decision']]);
        self::assertSame(365, (new Settings(self::$db))->get(Queries::UNSOLD_SETTING));
        self::assertSame('mapping.hide_unsold_days', Queries::UNSOLD_SETTING);
        self::assertSame(['in' => [0, 365]], Settings::RULES['mapping.hide_unsold_days']);
        self::assertArrayHasKey('mapping.hide_unsold_days', Words::SETTING);
        self::assertArrayHasKey('mapping.hide_unsold_days', Words::SETTING_HELP);
        self::assertSame(Words::SETTING_TOPIC['mapping'], Words::settingTopic('mapping.hide_unsold_days'), 'listed under Matching products');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'mapping.hide_unsold_days' "
            . "AND action = 'baseline' AND version = 1"));
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4 hold');
    }
}
