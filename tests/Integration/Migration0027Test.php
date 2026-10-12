<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0027 (the check on arrival, build 1; docs/decisions.md M64, U145), applied to a scratch schema that already holds profiles and the
 * multi-valued barcode index of 0005: the mark's five columns, the address key (a VIRTUAL generated column) and the two indexes, the
 * six settings with their baselines.
 *
 * And how it is applied. The design wrote ALGORITHM = INSTANT. On the staging database server (MySQL 8.4.8) that word is accepted
 * and the table is copied all the same, and with the 0005 index in place nothing but a copy adds a column to this table at all (the
 * second test shows both on a table of the same shape). So the file copies the table ONCE and says so: one ALTER, ALGORITHM = COPY,
 * LOCK = SHARED. The first test holds the file to that: a second ALTER of the table, or the word INSTANT or INPLACE, fails it.
 */
final class Migration0027Test extends IntegrationTestCase
{
    private const NOT_SUPPORTED = 1846; // ER_ALTER_OPERATION_NOT_SUPPORTED_REASON
    private const FILE = '0027_arrival_check.sql';
    private const SETTINGS = ['mapping.arrival_stores' => ['string', '""'], 'mapping.arrival_main_store' => ['string', '"vapeandgo"'],
        'mapping.arrival_bulk_confirm' => ['bool', 'false'], 'mapping.arrival_max_per_run' => ['int', '200'], 'mapping.arrival_max_seconds' => ['int', '45'],
        'mapping.arrival_queue_alarm' => ['int', '300']];
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m27', $this->dir);
        }
    }

    public function testItAddsTheMarkTheAddressKeyAndBothIndexesInOneCopyThatIsSaidOutLoud(): void
    {
        // What the file asks the server for: the table is altered once, as a copy that lets reads go on, and nothing in it promises
        // "instant" or "in place" (comments are not statements: the splitter drops them).
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        $alters = array_values(array_filter($statements, static fn (string $s): bool => preg_match('/^ALTER\s+TABLE\s+listing_profile\b/i', $s) === 1));
        self::assertCount(1, $alters, 'one statement, so one copy of the table');
        self::assertMatchesRegularExpression('/,\s*ALGORITHM\s*=\s*COPY\s*,\s*LOCK\s*=\s*SHARED\s*$/i', $alters[0]);
        self::assertSame($alters[0], $statements[0], 'the ALTER is the first statement: a refusal leaves nothing of the file applied');
        self::assertDoesNotMatchRegularExpression('/\b(INSTANT|INPLACE)\b/i', $alters[0]);
        // The one other ALTER adds an index to match_proposal, in place and online: a table with no multi-valued index (below: its id stays).
        $others = array_values(array_filter($statements, static fn (string $s): bool => preg_match('/^ALTER\s+TABLE\s+(?!listing_profile\b)/i', $s) === 1));
        self::assertCount(1, $others);
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+match_proposal\s+ADD\s+INDEX\s+ix_match_proposal_run_open\s*\(match_run_id, status, band, lane, listing_id\)\s*,'
            . '\s*ALGORITHM\s*=\s*INPLACE\s*,\s*LOCK\s*=\s*NONE\s*$/i', $others[0]);
        foreach ($statements as $s) {
            self::assertDoesNotMatchRegularExpression('/\bINSTANT\b/i', $s, 'the word is no promise on this server');
        }

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m27', '0026_new_variants.sql');
        $main = $db->insert("INSERT INTO channel (code, name) VALUES ('vapeandgo', 'Vape and Go')");
        $other = $db->insert("INSERT INTO channel (code, name) VALUES ('electrofag', 'Electrofag')");
        $links = ['page-a/', ' page-a ', 'Page-A', 'page-b//', '', null, ' / '];
        $ids = [];
        foreach ($links as $i => $link) {
            $ids[$i] = $db->insert('INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, ?)', [$i === 0 ? $main : $other, 'v' . $i]);
            $db->exec('INSERT INTO listing_profile (listing_id, product_title, barcodes, perma_link, profile_hash, identity_hash) VALUES (?, ?, ?, ?, ?, ?)',
                [$ids[$i], 'Product ' . $i, '["50000000000' . $i . '"]', $link, str_repeat('a', 64), str_repeat('b', 64)]);
        }
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'listing_profile' "
            . "AND INDEX_NAME = 'ix_listing_profile_barcodes'"), 'the multi-valued index of 0005 is in place before 0027');

        $tableId = static fn (string $t): int => (int) $db->value("SELECT TABLE_ID FROM information_schema.INNODB_TABLES WHERE NAME = CONCAT(DATABASE(), '/', ?)", [$t]);
        $ids0 = [$tableId('listing_profile'), $tableId('match_proposal')];
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertGreaterThan($ids0[0], $tableId('listing_profile'), 'listing_profile was copied (once, as the file says)');
        self::assertSame($ids0[1], $tableId('match_proposal'), 'match_proposal was NOT rebuilt: its index was added in place');
        self::assertSame(['match_run_id', 'status', 'band', 'lane', 'listing_id'], array_map('strval', $db->column("SELECT COLUMN_NAME FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'match_proposal' AND INDEX_NAME = 'ix_match_proposal_run_open' ORDER BY SEQ_IN_INDEX")));

        // The columns, exactly.
        $cols = [];
        foreach ($db->all("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'listing_profile' AND (COLUMN_NAME LIKE 'arrival\\_%' OR COLUMN_NAME = 'perma_key')") as $c) {
            $cols[(string) $c['COLUMN_NAME']] = [(string) $c['COLUMN_TYPE'], (string) $c['IS_NULLABLE'], $c['COLUMN_DEFAULT'], (string) $c['EXTRA']];
        }
        ksort($cols);
        self::assertSame([
            'arrival_channel_id' => ['smallint unsigned', 'YES', null, ''],
            'arrival_checked_at' => ['datetime(6)', 'YES', null, ''],
            'arrival_outcome' => ['varchar(32)', 'YES', null, ''],
            'arrival_seq' => ['int unsigned', 'NO', '0', ''],
            'arrival_state' => ["enum('queued','names','done')", 'YES', null, ''],
            'perma_key' => ['varchar(512)', 'YES', null, 'VIRTUAL GENERATED'],
        ], $cols);
        self::assertSame((string) $db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channel' AND COLUMN_NAME = 'id'"),
            $cols['arrival_channel_id'][0], 'the store column has the type of channel.id');
        self::assertSame('utf8mb4_0900_ai_ci', (string) $db->value("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'listing_profile' AND COLUMN_NAME = 'perma_key'"), 'the key compares as the table does: no letter case (PHP decides)');

        // The profiles that were there: no mark (NULL = "was there before the check"), nothing lost, the key worked out for each.
        self::assertSame(count($links), (int) $db->value('SELECT COUNT(*) FROM listing_profile WHERE arrival_state IS NULL AND arrival_seq = 0 AND arrival_channel_id IS NULL '
            . 'AND arrival_checked_at IS NULL AND arrival_outcome IS NULL'));
        $keys = [];
        foreach ($db->all('SELECT listing_id, perma_key, product_title, CAST(barcodes AS CHAR) AS barcodes FROM listing_profile ORDER BY listing_id') as $r) {
            $i = array_search((int) $r['listing_id'], $ids, true);
            $keys[$i] = $r['perma_key'];
            self::assertSame(['Product ' . $i, '["50000000000' . $i . '"]'], [(string) $r['product_title'], (string) $r['barcodes']]);
        }
        self::assertSame(['page-a', 'page-a', 'Page-A', 'page-b', null, null, null], $keys, 'surrounding spaces and every closing slash go; nothing left is no key');

        // The two indexes, and the reads they are for.
        $indexes = [];
        foreach ($db->all("SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'listing_profile' "
            . "AND INDEX_NAME IN ('ix_listing_profile_arrival', 'ix_listing_profile_perma_key') ORDER BY INDEX_NAME, SEQ_IN_INDEX") as $x) {
            $indexes[(string) $x['INDEX_NAME']][] = (string) $x['COLUMN_NAME'];
        }
        self::assertSame(['ix_listing_profile_arrival' => ['arrival_state', 'arrival_channel_id'], 'ix_listing_profile_perma_key' => ['perma_key']], $indexes);
        $found = array_map('intval', $db->column('SELECT lp.listing_id FROM listing_profile lp FORCE INDEX (ix_listing_profile_perma_key) WHERE lp.perma_key = ? ORDER BY lp.listing_id', ['PAGE-A']));
        self::assertSame([$ids[0], $ids[1], $ids[2]], $found, 'the index finds the address whatever its letter case: a superset');
        $plan = $db->one('EXPLAIN SELECT lp.listing_id FROM listing_profile lp FORCE INDEX (ix_listing_profile_perma_key) WHERE lp.perma_key = ?', ['page-a']);
        self::assertSame(['ref', 'ix_listing_profile_perma_key'], [(string) $plan['type'], (string) $plan['key']]);
        $db->exec("UPDATE listing_profile SET arrival_state = 'queued', arrival_seq = arrival_seq + 1, arrival_channel_id = ? WHERE listing_id IN (?, ?)", [$other, $ids[1], $ids[3]]);
        $queue = "SELECT lp.listing_id FROM listing_profile lp FORCE INDEX (ix_listing_profile_arrival) WHERE lp.arrival_state = 'queued' AND lp.arrival_channel_id = ? "
            . 'AND lp.listing_id > ? ORDER BY lp.listing_id LIMIT 200';
        self::assertSame([$ids[1], $ids[3]], array_map('intval', $db->column($queue, [$other, 0])));
        $plan = $db->one('EXPLAIN ' . $queue, [$other, 0]);
        self::assertSame('ix_listing_profile_arrival', (string) $plan['key']);
        self::assertStringContainsString('Using index', (string) $plan['Extra'], 'the queue of a store is read from the index alone, in listing order');
        self::assertStringNotContainsString('filesort', (string) $plan['Extra']);
        // The barcode index of 0005 came through the copy and still answers.
        $plan = $db->one('EXPLAIN SELECT lp.listing_id FROM listing_profile lp FORCE INDEX (ix_listing_profile_barcodes) WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON))',
            ['["500000000003"]']);
        self::assertSame('ix_listing_profile_barcodes', (string) $plan['key']);
        self::assertSame([$ids[3]], array_map('intval', $db->column('SELECT lp.listing_id FROM listing_profile lp WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON))', ['["500000000003"]'])));

        // The states, and a write as the code before 0027 makes it (it names its columns, none of the new ones).
        $db->exec("UPDATE listing_profile SET arrival_state = 'names' WHERE listing_id = ?", [$ids[1]]);
        $db->exec("UPDATE listing_profile SET arrival_state = 'done', arrival_checked_at = UTC_TIMESTAMP(6), arrival_outcome = 'no_clue' WHERE listing_id = ?", [$ids[3]]);
        self::assertSame(1265, self::mysqlError(static fn () => $db->exec("UPDATE listing_profile SET arrival_state = 'new' WHERE listing_id = ?", [$ids[3]])), 'no other state');
        self::assertSame(3105, self::mysqlError(static fn () => $db->exec("UPDATE listing_profile SET perma_key = 'x' WHERE listing_id = ?", [$ids[3]])), 'the key is worked out, never written');
        $late = $db->insert('INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, ?)', [$other, 'late']);
        $db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, attributes, barcodes, price, perma_link, units_30d, units_365d, profile_hash, identity_hash, '
            . 'pushed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$late, 'Late', null, null, null, null, null, 'late-page/', null, null, str_repeat('c', 64), str_repeat('d', 64)]);
        self::assertSame([null, 0, 'late-page'], array_values((array) $db->one('SELECT arrival_state, arrival_seq, perma_key FROM listing_profile WHERE listing_id = ?', [$late])));
        $db->exec("UPDATE listing_profile SET perma_link = 'moved' WHERE listing_id = ?", [$late]);
        self::assertSame([$late], array_map('intval', $db->column("SELECT listing_id FROM listing_profile FORCE INDEX (ix_listing_profile_perma_key) WHERE perma_key = 'moved'")),
            'the index follows the address');

        // The settings: off until someone names a store, each with its baseline and its bounds.
        foreach (self::SETTINGS as $key => [$type, $value]) {
            self::assertSame([$type, $value, 1, 'U145'], array_values((array) $db->one('SELECT value_type, CAST(value_json AS CHAR), provisional, decision FROM app_setting '
                . 'WHERE setting_key = ?', [$key])), $key);
            self::assertSame(['baseline', 'system:migrate'], array_values((array) $db->one("SELECT action, actor FROM config_change WHERE subject_type = 'setting' "
                . 'AND subject_key = ? AND version = 1', [$key])), $key);
            self::assertSame($value, (string) $db->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(state, '$.value')) FROM config_change WHERE subject_type = 'setting' AND subject_key = ?", [$key]));
        }
        self::assertSame(count(self::SETTINGS), (int) $db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key LIKE 'mapping.arrival\\_%'"));
        $settings = new Settings($db);
        self::assertSame(['', 'vapeandgo', false, 200, 45, 300], array_map(static fn (string $k): mixed => $settings->get($k), array_keys(self::SETTINGS)));
        foreach (['mapping.arrival_max_per_run' => [1, 5000], 'mapping.arrival_max_seconds' => [5, 600], 'mapping.arrival_queue_alarm' => [1, 100000]] as $key => [$min, $max]) {
            self::assertSame(['min' => $min, 'max' => $max], Settings::RULES[$key], $key);
        }
        foreach (['mapping.arrival_stores', 'mapping.arrival_main_store'] as $key) {
            self::assertArrayHasKey('pattern', Settings::RULES[$key], $key);
        }
        self::assertSame(1, preg_match(Settings::RULES['mapping.arrival_stores']['pattern'], 'vapebig,electrofag'));
        self::assertSame(0, preg_match(Settings::RULES['mapping.arrival_stores']['pattern'], 'Vape Big'));
        self::assertSame(1, preg_match(Settings::RULES['mapping.arrival_main_store']['pattern'], 'vapeandgo'));
        self::assertSame(0, preg_match(Settings::RULES['mapping.arrival_main_store']['pattern'], 'vapeandgo,electrofag'), 'one main store');
    }

    /**
     * Why the file says COPY (its header), shown on a table of listing_profile's shape before 0027, with rows and the 0005 index:
     *  - a column cannot be added in place: MySQL refuses it for a table with a multi-valued index, a plain column as much as a
     *    generated one;
     *  - asked for INSTANT, this server does not refuse, and does not do it instantly either: the table is a new one afterwards (a
     *    new table id), and for the generated column every row was copied. A server that refuses the word outright is as good a
     *    reason; one that really adds the column in an instant makes the header out of date, and the test says so without failing
     *    (a later migration may then ask for INSTANT).
     */
    public function testOnThisServerNothingButACopyAddsAColumnToThisTableWhateverTheStatementSays(): void
    {
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m27', '0026_new_variants.sql');
        $channel = $db->insert("INSERT INTO channel (code, name) VALUES ('electrofag', 'Electrofag')");
        for ($i = 1; $i <= 5; $i++) {
            $id = $db->insert('INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, ?)', [$channel, 'v' . $i]);
            $db->exec('INSERT INTO listing_profile (listing_id, product_title, barcodes, perma_link) VALUES (?, ?, ?, ?)', [$id, 'Product ' . $i, '["50000000000' . $i . '"]', 'page-' . $i]);
        }
        $tableId = static fn (): int => (int) $db->value("SELECT TABLE_ID FROM information_schema.INNODB_TABLES WHERE NAME = CONCAT(DATABASE(), '/listing_profile')");
        $plain = 'ADD COLUMN arrival_seq INT UNSIGNED NOT NULL DEFAULT 0';
        $generated = "ADD COLUMN perma_key VARCHAR(512) GENERATED ALWAYS AS (NULLIF(TRIM(TRAILING '/' FROM TRIM(perma_link)), '')) VIRTUAL";

        foreach ([$plain, $generated] as $add) {
            self::assertSame(self::NOT_SUPPORTED, self::mysqlError(static fn () => $db->pdo()->exec("ALTER TABLE listing_profile {$add}, ALGORITHM = INPLACE, LOCK = NONE")),
                'no column is added in place to a table with a multi-valued index: ' . $add);
        }
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'listing_profile' "
            . "AND COLUMN_NAME IN ('arrival_seq', 'perma_key')"), 'a refused statement changed nothing');

        $instantly = [];
        foreach (['a plain column' => $plain, 'the generated column' => $generated] as $what => $add) {
            $before = $tableId();
            try {
                $copied = (int) $db->pdo()->exec("ALTER TABLE listing_profile {$add}, ALGORITHM = INSTANT");
            } catch (\PDOException $e) {
                self::assertContains(Db::driverCode($e), [1845, self::NOT_SUPPORTED], $e->getMessage()); // refused outright: no promise broken
                continue;
            }
            if ($tableId() === $before) {
                $instantly[] = $what;
                continue;
            }
            // Accepted, and the table is another one afterwards: it was rebuilt or copied under the word INSTANT.
            self::assertGreaterThan($before, $tableId(), $what);
            if ($what === 'the generated column') {
                self::assertSame(5, $copied, 'every row was copied to add a column that stores nothing');
            }
        }
        if ($instantly !== []) {
            self::markTestSkipped('this server now adds ' . implode(' and ', $instantly) . ' to listing_profile instantly: the header of 0027 is out of date, '
                . 'and a later migration may ask for ALGORITHM = INSTANT');
        }
    }
}
