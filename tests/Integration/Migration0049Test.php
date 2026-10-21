<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;
use CW\Ui\SearchText;

/**
 * 0049 (the website products' search by an index; docs/decisions.md SI1-SI6), applied to a scratch schema that already holds profiles
 * (with the multi-valued barcode index of 0005, the mark of 0027 and the state of 0028): the three stored generated columns, exactly,
 * and their indexes; the profiles that were there get them in the copy (the backfill: no tool); MySQL keeps them in step with every
 * later INSERT and UPDATE, also the old code's statements, which still run; and they hold the text made normal as SearchText makes a
 * typed word, so a word found in a profile's text is found in them.
 *
 * And how it is applied, as 0027 and 0028 (Migration0027Test showed what this server does): ONE statement, ALGORITHM = COPY,
 * LOCK = SHARED, first in the file; a second ALTER (a second copy), the word INSTANT or INPLACE, or a new table fails this test.
 */
final class Migration0049Test extends IntegrationTestCase
{
    private const FILE = '0049_search_index.sql';
    private const COLUMNS = [
        'search_all' => ['varchar(760)', 'YES', null, 'STORED GENERATED'],
        'search_names' => ['varchar(760)', 'YES', null, 'STORED GENERATED'],
        'search_on_row' => ['tinyint(1)', 'YES', null, 'STORED GENERATED'],
    ];
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m49', $this->dir);
        }
    }

    public function testItAddsTheStoredSearchColumnsAndTheirIndexesInOneCopyAndFillsThemForEveryProfile(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        self::assertCount(1, $statements, 'one statement: one copy of one table, nothing else');
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+listing_profile\b/i', $statements[0]);
        self::assertMatchesRegularExpression('/,\s*ALGORITHM\s*=\s*COPY\s*,\s*LOCK\s*=\s*SHARED\s*$/i', $statements[0]);
        self::assertDoesNotMatchRegularExpression('/\b(INSTANT|INPLACE)\b/i', $statements[0], 'neither word is a promise for this table on this server (Migration0027Test)');
        self::assertSame(3, preg_match_all('/\bADD\s+COLUMN\s+search_[a-z_]+\s+[a-z]+(\(\d+\))?\s+GENERATED\s+ALWAYS\s+AS\b/i', $statements[0]));
        self::assertSame(3, preg_match_all('/\bADD\s+INDEX\b/i', $statements[0]));

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m49', '0048_totp_off_switch.sql');
        $store = $db->insert("INSERT INTO channel (code, name) VALUES ('electrofag', 'Electrofag')");
        $texts = [
            ['Hayati Pro Max Plus 6000 Prefilled Pod Kit', "Hayati Pro Max Plus 6000 Prefilled Pod Kit - 20\u{00A0}mg | Strawberry Kiwi", 'Hayati'],
            ['R&M Tornado 7000', 'R&M Tornado 7000 - Cola', 'R&M'],
            ['Crème Brûlée E-Liquid', null, null],
            [null, null, null],
        ];
        $ids = [];
        foreach ($texts as $i => [$title, $option, $brand]) {
            $ids[$i] = $db->insert('INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, ?)', [$store, 'v' . $i]);
            $db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, barcodes, attributes, perma_link, features, site_product_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$ids[$i], $title, $option, $brand, $i === 0 ? '["05056168800073"]' : null,
                    $i === 1 ? '[{"name": "Flavour", "value": "Cola Ice"}]' : null, $i === 0 ? 'hayati-pro-max-plus/' : null,
                    $i === 0 ? '{"flavour_tokens": ["strawberry", "kiwi"], "strength_mg": 20}' : null, $i === 1 ? '4711' : null]);
        }
        $before = $db->all('SELECT listing_id, product_title, variant_title, brand, CAST(barcodes AS CHAR) AS b, CAST(attributes AS CHAR) AS a, perma_link, CAST(features AS CHAR) AS f '
            . 'FROM listing_profile ORDER BY listing_id');
        $tableId = static fn (string $t): int => (int) $db->value("SELECT TABLE_ID FROM information_schema.INNODB_TABLES WHERE NAME = CONCAT(DATABASE(), '/', ?)", [$t]);
        $ids0 = [$tableId('listing_profile'), $tableId('channel_listing'), $tableId('sku')];
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertGreaterThan($ids0[0], $tableId('listing_profile'), 'listing_profile was copied (once: the file has one statement)');
        self::assertSame([$ids0[1], $ids0[2]], [$tableId('channel_listing'), $tableId('sku')], 'no other table was rebuilt');

        // The columns, exactly, and their indexes.
        $cols = [];
        foreach ($db->all("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'listing_profile' AND COLUMN_NAME LIKE 'search\\_%'") as $c) {
            $cols[(string) $c['COLUMN_NAME']] = [(string) $c['COLUMN_TYPE'], (string) $c['IS_NULLABLE'], $c['COLUMN_DEFAULT'], (string) $c['EXTRA']];
        }
        ksort($cols);
        self::assertSame(self::COLUMNS, $cols);
        foreach (['ix_listing_profile_search_names' => ['search_names'], 'ix_listing_profile_search_all' => ['listing_id', 'search_all'],
            'ix_listing_profile_search_on_row' => ['search_on_row']] as $ix => $col) {
            self::assertSame($col, array_map('strval', $db->column("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME = 'listing_profile' AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX", [$ix])), $ix);
        }

        // The backfill: every profile that was there has its search text, nothing it held changed.
        self::assertSame($before, $db->all('SELECT listing_id, product_title, variant_title, brand, CAST(barcodes AS CHAR) AS b, CAST(attributes AS CHAR) AS a, perma_link, '
            . 'CAST(features AS CHAR) AS f FROM listing_profile ORDER BY listing_id'));
        $row = static fn (int $id): array => (array) $db->one('SELECT search_names, search_all, search_on_row FROM listing_profile WHERE listing_id = ?', [$id]);
        self::assertSame(["HayatiProMaxPlus6000PrefilledPodKitHayatiProMaxPlus6000PrefilledPodKit20\u{00A0}mg|StrawberryKiwiHayati", 0],
            [$row($ids[0])['search_names'], (int) $row($ids[0])['search_on_row']]);
        self::assertSame(['RandMTornado7000RandMTornado7000ColaRandM', ''], [$row($ids[1])['search_names'], $row($ids[3])['search_names']]);
        // the stored text is the text made normal as SearchText makes a word: a phrase of the text, made normal, is in it
        $holds = static fn (int $id, string $col, string $typed): bool => (int) $db->value("SELECT COUNT(*) FROM listing_profile WHERE listing_id = ? AND {$col} LIKE ?",
            [$id, SearchText::like(SearchText::norm($typed))]) === 1;
        foreach (['pro max plus', 'ProMax', 'pod kit -', "20\u{00A0}mg |", 'kiwi hayati'] as $phrase) {
            self::assertTrue($holds($ids[0], 'search_names', $phrase), $phrase);
        }
        self::assertTrue($holds($ids[1], 'search_names', 'R and M'), '"&" is "and"');
        self::assertTrue($holds($ids[2], 'search_names', 'creme brulee e liquid'), 'case and accents: the column\'s collation');
        foreach (['05056168800073', 'hayati-pro-max', 'strawberry', '20'] as $part) {
            self::assertTrue($holds($ids[0], 'search_all', $part), "search_all: {$part}");
        }
        self::assertFalse($holds($ids[0], 'search_names', '05056168800073'), 'a barcode is not one of the names');
        self::assertTrue($holds($ids[1], 'search_all', 'cola ice') && $holds($ids[1], 'search_all', '4711'), 'the attributes and the product number');
        self::assertTrue($holds($ids[1], 'search_all', 'flavour'), 'an attribute\'s name is a word of it');
        self::assertFalse($holds($ids[1], 'search_all', 'value'), 'the JSON\'s own keys are not (JSON_SEARCH did not read them)');

        // MySQL keeps them in step: the old code's statements (the listing intake's, commit 5e68ee8, word for word) still run.
        $db->exec('UPDATE listing_profile SET product_title = ?, variant_title = ?, brand = ?, attributes = ?, barcodes = ?, price = ?, '
            . 'perma_link = ?, units_30d = ?, units_365d = ?, profile_hash = ?, identity_hash = ?, pushed_at = UTC_TIMESTAMP(6) WHERE listing_id = ?',
            ['Elf Bar 600', 'Elf Bar 600 - Blue Razz', 'Elf Bar', null, '["5000000000099"]', '4.99', 'elf-bar-600/', 1, 2, str_repeat('c', 64), str_repeat('d', 64), $ids[3]]);
        self::assertSame('ElfBar600ElfBar600BlueRazzElfBar', $row($ids[3])['search_names']);
        $late = $db->insert('INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, ?)', [$store, 'late']);
        $db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, attributes, barcodes, price, perma_link, '
            . 'units_30d, units_365d, profile_hash, identity_hash, pushed_at, arrival_state, arrival_seq, arrival_channel_id) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), 'queued', 1, ?)",
            [$late, 'Lost Mary BM600', null, 'Lost Mary', null, null, null, null, 0, 0, str_repeat('e', 64), str_repeat('f', 64), $store]);
        self::assertSame('LostMaryBM600LostMary', $row($late)['search_names']);
        self::assertSame(3105, self::mysqlError(static fn () => $db->exec('UPDATE listing_profile SET search_names = ? WHERE listing_id = ?', ['x', $late])),
            'no code may write them');

        // A text longer than the column is cut, and says so (it is searched on its row, docs/decisions.md SI2).
        $db->exec('UPDATE listing_profile SET product_title = ?, variant_title = ? WHERE listing_id = ?', [str_repeat('Long name ', 50), str_repeat('Longer still ', 39), $ids[2]]);
        self::assertSame([760, 1], [mb_strlen((string) $row($ids[2])['search_names']), (int) $row($ids[2])['search_on_row']]);

        // A JSON value with an escape (a backslash, a quote, a tab: JSON_SEARCH read it decoded) is searched on its row too.
        $db->exec('UPDATE listing_profile SET attributes = ? WHERE listing_id = ?', [json_encode(['Note' => 'a "quoted" word']), $late]);
        self::assertSame(1, (int) $row($late)['search_on_row']);

        // The reads they are for: one narrow index, never the profiles' rows.
        $plan = $db->one('EXPLAIN SELECT listing_id FROM listing_profile FORCE INDEX (ix_listing_profile_search_names) WHERE search_names LIKE ?', ['%kiwi%']);
        self::assertSame(['ix_listing_profile_search_names', 'index'], [(string) $plan['key'], (string) $plan['type']]);
        self::assertStringContainsString('Using index', (string) $plan['Extra'], 'a covering scan of the index alone');
        $plan = $db->one('EXPLAIN SELECT listing_id FROM listing_profile WHERE search_on_row = 1');
        self::assertStringContainsString('ix_listing_profile_search_on_row', (string) $plan['possible_keys']);
        // the barcode index of 0005 came through the copy
        self::assertSame([$ids[0]], array_map('intval', $db->column('SELECT listing_id FROM listing_profile WHERE JSON_OVERLAPS(barcodes, CAST(? AS JSON))', ['["05056168800073"]'])));
    }
}
