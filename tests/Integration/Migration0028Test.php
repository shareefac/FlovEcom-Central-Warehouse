<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0028 (the check on arrival, build 2a: the website state; docs/decisions.md M66), applied to a scratch schema that already holds
 * profiles with the mark and the address key of 0027 and the multi-valued barcode index of 0005: the eight columns of the state,
 * exactly; the index on the website's own number of the product; the one setting with its baseline.
 *
 * And how it is applied. Migration0027Test showed what this database server does (its second test still holds it to that): the
 * word INSTANT is accepted and the table is copied all the same, and with the 0005 index nothing but a copy adds a column to this
 * table. So 0028 does what 0027 did: ONE statement, ALGORITHM = COPY, LOCK = SHARED, written first in the file. A second ALTER of
 * the table (a second copy), or the word INSTANT or INPLACE, fails this test. The profiles that were there come through the copy
 * with no state (every new column NULL: "not known"), and the statements the code of before 0028 runs still work on the new
 * schema: they name their columns, and none of the new ones.
 */
final class Migration0028Test extends IntegrationTestCase
{
    private const FILE = '0028_arrival_site_state.sql';
    private const COLUMNS = [
        'site_is_default' => ['tinyint(1)', 'YES', null, ''],
        'site_is_landing' => ['tinyint(1)', 'YES', null, ''],
        'site_product_id' => ['varchar(64)', 'YES', null, ''],
        'site_product_link' => ['varchar(512)', 'YES', null, ''],
        'site_product_status' => ['varchar(32)', 'YES', null, ''],
        'site_product_type' => ['varchar(32)', 'YES', null, ''],
        'site_state_at' => ['datetime(6)', 'YES', null, ''],
        'site_variant_status' => ['varchar(32)', 'YES', null, ''],
    ];
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m28', $this->dir);
        }
    }

    public function testItAddsTheEightColumnsOfTheStateAndTheirIndexInOneCopyAndTheOldCodeStillRuns(): void
    {
        // What the file asks the server for: the table is altered once, as a copy that lets reads go on, first in the file, and
        // nothing in it promises "instant" or "in place" (comments are not statements: the splitter drops them).
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        $alters = array_values(array_filter($statements, static fn (string $s): bool => preg_match('/^ALTER\s+TABLE\b/i', $s) === 1));
        self::assertCount(1, $alters, 'one ALTER in the whole file: one copy of one table, and no other table is touched');
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+listing_profile\b/i', $alters[0]);
        self::assertMatchesRegularExpression('/,\s*ALGORITHM\s*=\s*COPY\s*,\s*LOCK\s*=\s*SHARED\s*$/i', $alters[0]);
        self::assertSame($alters[0], $statements[0], 'the ALTER is the first statement: a refusal leaves nothing of the file applied');
        foreach ($statements as $s) {
            self::assertDoesNotMatchRegularExpression('/\b(INSTANT|INPLACE)\b/i', $s, 'neither word is a promise for this table on this server (Migration0027Test)');
            self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|DROP|RENAME)\s+TABLE\b/i', $s, 'no new table: the grants are untouched');
        }
        self::assertSame(8, preg_match_all('/\bADD\s+COLUMN\s+(site_[a-z_]+)\b/i', $alters[0], $added));
        $names = array_map('strval', $added[1]);
        sort($names);
        self::assertSame(array_keys(self::COLUMNS), $names);
        self::assertSame(1, preg_match_all('/\bADD\s+INDEX\b/i', $alters[0]));

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m28', '0027_arrival_check.sql');
        $main = $db->insert("INSERT INTO channel (code, name) VALUES ('vapeandgo', 'Vape and Go')");
        $other = $db->insert("INSERT INTO channel (code, name) VALUES ('electrofag', 'Electrofag')");
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[$i] = $db->insert('INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, ?)', [$i === 0 ? $main : $other, 'v' . $i]);
            $db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, barcodes, perma_link, units_30d, features, features_version, profile_hash, identity_hash, '
                . 'arrival_state, arrival_seq, arrival_channel_id, arrival_outcome) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$ids[$i], 'Product ' . $i, 'Variant ' . $i, '["50000000000' . $i . '"]', 'page-' . $i . '/', $i, $i === 1 ? '{"variant_status": "Published", "is_placeholder": false}' : null,
                    $i === 1 ? 'n2.1' : null, str_repeat((string) $i, 64), str_repeat('b', 64), $i < 2 ? null : ($i < 4 ? 'queued' : 'done'), $i < 2 ? 0 : $i,
                    $i < 2 ? null : $other, $i < 4 ? null : 'no_clue']);
        }
        $before = $db->all('SELECT listing_id, product_title, variant_title, CAST(barcodes AS CHAR) AS barcodes, perma_link, perma_key, units_30d, CAST(features AS CHAR) AS features, '
            . 'features_version, profile_hash, identity_hash, arrival_state, arrival_seq, arrival_channel_id, arrival_outcome FROM listing_profile ORDER BY listing_id');
        self::assertCount(6, $before);

        $tableId = static fn (string $t): int => (int) $db->value("SELECT TABLE_ID FROM information_schema.INNODB_TABLES WHERE NAME = CONCAT(DATABASE(), '/', ?)", [$t]);
        $ids0 = [$tableId('listing_profile'), $tableId('channel_listing'), $tableId('match_proposal')];
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertGreaterThan($ids0[0], $tableId('listing_profile'), 'listing_profile was copied (once: the file has one ALTER)');
        self::assertSame([$ids0[1], $ids0[2]], [$tableId('channel_listing'), $tableId('match_proposal')], 'no other table was rebuilt');

        // The columns, exactly.
        $cols = [];
        foreach ($db->all("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'listing_profile' AND COLUMN_NAME LIKE 'site\\_%'") as $c) {
            $cols[(string) $c['COLUMN_NAME']] = [(string) $c['COLUMN_TYPE'], (string) $c['IS_NULLABLE'], $c['COLUMN_DEFAULT'], (string) $c['EXTRA']];
        }
        ksort($cols);
        self::assertSame(self::COLUMNS, $cols);

        // The profiles that were there: every column they had as it was (the mark, the address key, the features), and no state.
        self::assertSame($before, $db->all('SELECT listing_id, product_title, variant_title, CAST(barcodes AS CHAR) AS barcodes, perma_link, perma_key, units_30d, '
            . 'CAST(features AS CHAR) AS features, features_version, profile_hash, identity_hash, arrival_state, arrival_seq, arrival_channel_id, arrival_outcome '
            . 'FROM listing_profile ORDER BY listing_id'), 'nothing a profile held was lost or changed by the copy');
        self::assertSame(6, (int) $db->value('SELECT COUNT(*) FROM listing_profile WHERE site_variant_status IS NULL AND site_product_status IS NULL AND site_product_type IS NULL '
            . 'AND site_product_id IS NULL AND site_is_landing IS NULL AND site_is_default IS NULL AND site_product_link IS NULL AND site_state_at IS NULL'),
            'a profile that was there has no state: not known');

        // The new index, and the read it is for: the variants of one product of the website.
        self::assertSame(['site_product_id'], array_map('strval', $db->column("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'listing_profile' AND INDEX_NAME = 'ix_listing_profile_site_product' ORDER BY SEQ_IN_INDEX")));
        $db->exec("UPDATE listing_profile SET site_variant_status = 'Published', site_product_status = 'Published', site_product_type = 'variable', site_product_id = '77', "
            . "site_is_landing = 0, site_is_default = 0, site_product_link = 'product-77/', site_state_at = '2026-09-26 09:41:52.000000' WHERE listing_id IN (?, ?, ?)",
            [$ids[0], $ids[2], $ids[3]]);
        $db->exec("UPDATE listing_profile SET site_variant_status = 'Bin', site_product_id = '77', site_is_default = 1 WHERE listing_id = ?", [$ids[4]]);
        $siblings = 'SELECT /*+ JOIN_ORDER(lp, cl) INDEX(lp ix_listing_profile_site_product) */ COUNT(*) FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id '
            . "WHERE lp.site_product_id = ? AND cl.channel_id = ? AND lp.site_variant_status = 'Published'";
        self::assertSame([2, 1, 0], [(int) $db->value($siblings, ['77', $other]), (int) $db->value($siblings, ['77', $main]), (int) $db->value($siblings, ['78', $other])],
            'the published variants of one product of one store (the same number on another store is another product)');
        $plan = $db->all('EXPLAIN ' . $siblings, ['77', $other]);
        self::assertSame(['lp', 'ref', 'ix_listing_profile_site_product'], [(string) $plan[0]['table'], (string) $plan[0]['type'], (string) $plan[0]['key']]);
        // What the columns hold: a state is a text as the website says it, a yes or no, or nothing.
        self::assertSame(1406, self::mysqlError(static fn () => $db->exec('UPDATE listing_profile SET site_variant_status = ? WHERE listing_id = ?', [str_repeat('x', 33), $ids[5]])));
        self::assertSame(1406, self::mysqlError(static fn () => $db->exec('UPDATE listing_profile SET site_product_id = ? WHERE listing_id = ?', [str_repeat('9', 65), $ids[5]])));

        // The indexes of 0005 and 0027 came through the copy and still answer.
        $plan = $db->one('EXPLAIN SELECT lp.listing_id FROM listing_profile lp FORCE INDEX (ix_listing_profile_barcodes) WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON))',
            ['["500000000003"]']);
        self::assertSame('ix_listing_profile_barcodes', (string) $plan['key']);
        self::assertSame([$ids[3]], array_map('intval', $db->column('SELECT lp.listing_id FROM listing_profile lp WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON))', ['["500000000003"]'])));
        self::assertSame([$ids[2], $ids[3]], array_map('intval', $db->column("SELECT lp.listing_id FROM listing_profile lp FORCE INDEX (ix_listing_profile_arrival) "
            . "WHERE lp.arrival_state = 'queued' AND lp.arrival_channel_id = ? ORDER BY lp.listing_id", [$other])));
        self::assertSame([$ids[5]], array_map('intval', $db->column('SELECT lp.listing_id FROM listing_profile lp FORCE INDEX (ix_listing_profile_perma_key) WHERE lp.perma_key = ?', ['page-5'])));

        // The old code on the new schema: the statements the listing intake and the check on arrival ran before 0028 (commit
        // 99b9e47), word for word. They name their columns and none of the new ones, so they run, and a profile they make has no state.
        $late = $db->insert('INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, ?)', [$other, 'late']);
        $columns = ['Late', null, 'Acme', null, '["5000000000099"]', '4.99', 'late-page/', 1, 2, str_repeat('c', 64), str_repeat('d', 64)];
        $db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, attributes, barcodes, price, perma_link, '
            . 'units_30d, units_365d, profile_hash, identity_hash, pushed_at, arrival_state, arrival_seq, arrival_channel_id) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), 'queued', 1, ?)", [$late, ...$columns, $other]);
        $columns[0] = 'Late, renamed';
        $db->exec('UPDATE listing_profile SET product_title = ?, variant_title = ?, brand = ?, attributes = ?, barcodes = ?, price = ?, '
            . 'perma_link = ?, units_30d = ?, units_365d = ?, profile_hash = ?, identity_hash = ?, pushed_at = UTC_TIMESTAMP(6)'
            . ', features = NULL, features_version = NULL' . ", arrival_state = 'queued', arrival_seq = arrival_seq + 1, arrival_channel_id = ?" . ' WHERE listing_id = ?',
            [...$columns, $other, $late]);
        $db->exec('UPDATE listing_profile SET pushed_at = UTC_TIMESTAMP(6) WHERE listing_id = ?', [$late]);
        $db->exec("UPDATE listing_profile SET arrival_state = 'done', arrival_checked_at = UTC_TIMESTAMP(6), arrival_outcome = ?, "
            . 'arrival_channel_id = COALESCE(arrival_channel_id, ?) WHERE listing_id = ? AND arrival_seq = ?', ['no_clue', $other, $late, 2]);
        $p = $db->one('SELECT lp.product_title, lp.variant_title, lp.brand, lp.attributes, lp.barcodes, lp.price, lp.perma_link, lp.units_30d, lp.units_365d, '
            . "lp.identity_hash, lp.arrival_state, lp.arrival_seq, JSON_UNQUOTE(JSON_EXTRACT(lp.features, '$.variant_status')) AS variant_status "
            . 'FROM listing_profile lp WHERE lp.listing_id = ?', [$late]);
        self::assertSame(['Late, renamed', 'done', 2, null], [$p['product_title'], $p['arrival_state'], (int) $p['arrival_seq'], $p['variant_status']]);
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM listing_profile WHERE listing_id = ? AND site_variant_status IS NULL AND site_product_status IS NULL '
            . 'AND site_product_type IS NULL AND site_product_id IS NULL AND site_is_landing IS NULL AND site_is_default IS NULL AND site_product_link IS NULL '
            . 'AND site_state_at IS NULL', [$late]), 'a profile the old code makes has no state');

        // The setting: how old a stored state may be, with its baseline and its bounds.
        self::assertSame(['int', '30', 1, 'M66'], array_values((array) $db->one('SELECT value_type, CAST(value_json AS CHAR), provisional, decision FROM app_setting '
            . "WHERE setting_key = 'mapping.arrival_state_max_days'")));
        self::assertSame(['baseline', 'system:migrate', '30'], array_values((array) $db->one("SELECT action, actor, JSON_UNQUOTE(JSON_EXTRACT(state, '$.value')) FROM config_change "
            . "WHERE subject_type = 'setting' AND subject_key = 'mapping.arrival_state_max_days' AND version = 1")));
        self::assertSame(7, (int) $db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key LIKE 'mapping.arrival\\_%'"), 'the six of 0027 and this one');
        self::assertSame(30, (new Settings($db))->get('mapping.arrival_state_max_days'));
        self::assertSame(['min' => 1, 'max' => 365], Settings::RULES['mapping.arrival_state_max_days'] ?? null, 'a day at the least, a year at the most (not the 0 to 120 of other day counts)');
        self::assertSame(30, Settings::number($db, 'mapping.arrival_state_max_days', 0));
    }
}
