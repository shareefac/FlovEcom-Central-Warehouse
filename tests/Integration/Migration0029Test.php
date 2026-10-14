<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\CwException;
use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0029 (pack A2a, stock counting; docs/decisions.md CT1-CT18), applied to a scratch schema that already holds items, balances and
 * count reviews: the two tables of a count sheet with their rules, the two indexes for the lists, the four settings with their
 * baselines and ranges, and the reviewer check of a stock count switched off with its history.
 *
 * And how it is applied (docs/dev.md "Writing a migration"): the two indexes go on tables that are in use all day (every sale moves
 * stock_balance), so the file adds each in place and online and never says INSTANT; the first test holds the file to it and shows on
 * the scratch schema that neither table was rebuilt.
 */
final class Migration0029Test extends IntegrationTestCase
{
    private const FILE = '0029_stock_counts.sql';
    private const SETTINGS = ['counts.plan_days' => ['int', '120'], 'counts.sheet_shows_stock' => ['bool', 'true'], 'counts.review_over_units' => ['int', '5'],
        'counts.review_over_percent' => ['int', '10'], 'counts.time_window_max_minutes' => ['int', '10']];
    private const BAD_CHECK = 3819; // ER_CHECK_CONSTRAINT_VIOLATED
    private const DUPLICATE = 1062;
    /** @var array<string, string> scratch suffix => its migrations directory */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $suffix => $dir) {
            MigrationFixture::drop($suffix, $dir);
        }
        $this->dirs = [];
    }

    /** The migration applied just before 0029 (0027 on this branch; another branch's 0028 once it is merged). */
    private static function before(): string
    {
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files);
        $i = array_search(self::FILE, $files, true);
        self::assertIsInt($i, self::FILE . ' is in migrations/');
        self::assertGreaterThan(0, $i);
        return $files[$i - 1];
    }

    public function testItAddsTheSheetTablesTheIndexesInPlaceTheSettingsAndSwitchesTheCountCheckOff(): void
    {
        // What the file asks the server for: two ALTERs, each ONE index added in place while reads and writes go on.
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        $alters = array_values(array_filter($statements, static fn (string $s): bool => preg_match('/^ALTER\s+TABLE\b/i', $s) === 1));
        self::assertCount(2, $alters);
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+stock_balance\s+ADD\s+INDEX\s+ix_stock_balance_counted\s*\(warehouse_id, counted_at, sku_id\)\s*,'
            . '\s*ALGORITHM\s*=\s*INPLACE\s*,\s*LOCK\s*=\s*NONE\s*$/i', $alters[0]);
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+count_review\s+ADD\s+INDEX\s+ix_count_review_open\s*\(status, source, id\)\s*,'
            . '\s*ALGORITHM\s*=\s*INPLACE\s*,\s*LOCK\s*=\s*NONE\s*$/i', $alters[1]);
        foreach ($statements as $s) {
            self::assertDoesNotMatchRegularExpression('/\bINSTANT\b/i', $s, 'the word is no promise on this server');
            self::assertDoesNotMatchRegularExpression('/^\s*(DROP|DELETE|TRUNCATE)\b/i', $s, 'nothing is removed');
        }

        /** @var Db $db */
        [$db, $dir] = MigrationFixture::upTo('m29', self::before());
        $this->dirs['m29'] = $dir;
        // Rows as they are before 0029: items with balances (one counted), a count review of each kind the engine opens.
        $sku = [];
        foreach (['Alpha 10ml', 'Beta 20mg', 'Gamma kit'] as $i => $name) {
            $sku[$i] = $db->insert('INSERT INTO sku (name) VALUES (?)', [$name]);
            $db->exec('UPDATE sku SET code = ? WHERE id = ?', [sprintf('CW-%06d', $sku[$i]), $sku[$i]]);
        }
        $db->exec('INSERT INTO stock_balance (warehouse_id, sku_id, on_hand) VALUES (1, ?, 7), (1, ?, 0), (2, ?, 3)', [$sku[0], $sku[1], $sku[0]]);
        $db->exec("UPDATE stock_balance SET counted_at = '2026-06-01 10:00:00' WHERE warehouse_id = 1 AND sku_id = ?", [$sku[0]]);
        $db->exec("INSERT INTO count_review (warehouse_id, sku_id, source, proposed_qty, ref) VALUES (1, ?, 'count_after_movements', 7, 'DOC-1'), "
            . "(1, ?, 'negative_on_hand', NULL, 'ORD-1'), (1, ?, 'count_near_ship', 4, 'DOC-2')", [$sku[0], $sku[1], $sku[1]]);
        self::assertSame(['over_limit', 10], array_values((array) $db->one("SELECT CAST(review_rule AS CHAR) AS r, review_limit_units FROM document_type WHERE code = 'CNT'")),
            'the reviewer check of a count as 0008 seeded it');
        $rulesBefore = (int) $db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'document_rule' AND subject_key = 'CNT'");

        $tableId = static fn (string $t): int => (int) $db->value("SELECT TABLE_ID FROM information_schema.INNODB_TABLES WHERE NAME = CONCAT(DATABASE(), '/', ?)", [$t]);
        $ids0 = [$tableId('stock_balance'), $tableId('count_review')];
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $dir, self::FILE));
        self::assertSame($ids0, [$tableId('stock_balance'), $tableId('count_review')], 'neither table was rebuilt: each index was added in place');
        $indexes = [];
        foreach ($db->all("SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND INDEX_NAME IN ('ix_stock_balance_counted', 'ix_count_review_open') ORDER BY INDEX_NAME, SEQ_IN_INDEX") as $x) {
            $indexes[$x['TABLE_NAME'] . '.' . $x['INDEX_NAME']][] = (string) $x['COLUMN_NAME'];
        }
        self::assertSame(['count_review.ix_count_review_open' => ['status', 'source', 'id'],
            'stock_balance.ix_stock_balance_counted' => ['warehouse_id', 'counted_at', 'sku_id']], $indexes);
        // Nothing that was there changed.
        self::assertSame([[1, $sku[0], 7, '2026-06-01 10:00:00.000000'], [1, $sku[1], 0, null], [2, $sku[0], 3, null]], array_map(
            static fn (array $r): array => [(int) $r['warehouse_id'], (int) $r['sku_id'], (int) $r['on_hand'], $r['counted_at']],
            $db->all('SELECT warehouse_id, sku_id, on_hand, counted_at FROM stock_balance ORDER BY warehouse_id, sku_id')));
        self::assertSame(3, (int) $db->value("SELECT COUNT(*) FROM count_review WHERE status = 'open'"));

        // The reads the indexes are for, from the index alone.
        $never = 'SELECT b.sku_id FROM stock_balance b FORCE INDEX (ix_stock_balance_counted) WHERE b.warehouse_id = ? AND b.counted_at IS NULL ORDER BY b.sku_id LIMIT 51';
        self::assertSame([$sku[1]], array_map('intval', $db->column($never, [1])));
        $plan = (array) $db->one('EXPLAIN ' . $never, [1]);
        self::assertSame('ix_stock_balance_counted', (string) $plan['key']);
        self::assertStringContainsString('Using index', (string) $plan['Extra']);
        self::assertStringNotContainsString('filesort', (string) $plan['Extra']);
        $oldest = 'SELECT b.sku_id FROM stock_balance b FORCE INDEX (ix_stock_balance_counted) WHERE b.warehouse_id = ? AND b.counted_at IS NOT NULL '
            . 'ORDER BY b.counted_at, b.sku_id LIMIT 51';
        self::assertSame([$sku[0]], array_map('intval', $db->column($oldest, [1])));
        $plan = (array) $db->one('EXPLAIN ' . $oldest, [1]);
        self::assertSame('ix_stock_balance_counted', (string) $plan['key']);
        self::assertContains((string) $plan['type'], ['range', 'ref', 'index'], 'read along the index (a handful of rows here: the filled schema of runs/a2_explain.txt shows the range)');
        self::assertStringNotContainsString('filesort', (string) $plan['Extra']);
        $open = "SELECT r.id FROM count_review r FORCE INDEX (ix_count_review_open) WHERE r.status = 'open' AND r.source = ? ORDER BY r.id DESC LIMIT 51";
        self::assertCount(1, $db->column($open, ['count_near_ship']));
        $plan = (array) $db->one('EXPLAIN ' . $open, ['count_near_ship']);
        self::assertSame(['ref', 'ix_count_review_open'], [(string) $plan['type'], (string) $plan['key']]);
        self::assertStringNotContainsString('filesort', (string) $plan['Extra']);
        // The engine's own writes go on as before: a balance made the way Stock::lock() makes it, a count time set, a review opened.
        $db->exec('INSERT INTO stock_balance (warehouse_id, sku_id) VALUES (1, ?) ON DUPLICATE KEY UPDATE on_hand = on_hand', [$sku[2]]);
        $db->exec("UPDATE stock_balance SET counted_at = '2026-10-01 09:00:00' WHERE warehouse_id = 1 AND sku_id = ?", [$sku[2]]);
        self::assertSame([$sku[0], $sku[2]], array_map('intval', $db->column($oldest, [1])), 'the oldest count first');

        // The five settings, their baselines, and their ranges (the plan's days have a range of their own: a day count stops at 120; the
        // count times' limit is review I-1's of 14 Oct 2026, never hard-coded).
        foreach (self::SETTINGS as $key => [$type, $json]) {
            self::assertSame([$type, $json, 1], array_values((array) $db->one('SELECT value_type, CAST(value_json AS CHAR) AS v, provisional FROM app_setting WHERE setting_key = ?', [$key])), $key);
            self::assertSame(['baseline', 'system:migrate', 1], array_values((array) $db->one("SELECT action, actor, version FROM config_change WHERE subject_type = 'setting' "
                . 'AND subject_key = ?', [$key])), "{$key}: its history starts with what the migration set");
        }
        $settings = new Settings($db);
        self::assertSame([120, true, 5, 10, 10], [$settings->get('counts.plan_days'), $settings->get('counts.sheet_shows_stock'), $settings->get('counts.review_over_units'),
            $settings->get('counts.review_over_percent'), $settings->get('counts.time_window_max_minutes')]);
        foreach ([['counts.plan_days', 1, 730, 0, 731], ['counts.review_over_units', 0, 1_000_000, -1, 1_000_001], ['counts.review_over_percent', 0, 1000, -1, 1001],
            ['counts.time_window_max_minutes', 0, 60, -1, 61]] as [$key, $lo, $hi, $under, $over]) {
            $settings->checkRule($key, $lo);
            $settings->checkRule($key, $hi);
            foreach ([$under, $over] as $bad) {
                try {
                    $settings->checkRule($key, $bad);
                    self::fail("{$key} = {$bad} should be refused");
                } catch (CwException $e) {
                    self::assertSame('bad_value', $e->errorCode, $key);
                }
            }
        }

        // The reviewer check of a stock count: off, its limit kept, and its Not OK only records (review M-4 of 14 Oct 2026: a count is
        // put right by counting again), as ONE next version of its history; the nightly K checks hold.
        self::assertSame(['none', 10, 'record'], array_values((array) $db->one("SELECT CAST(review_rule AS CHAR) AS r, review_limit_units, CAST(reject_action AS CHAR) AS a "
            . "FROM document_type WHERE code = 'CNT'")));
        $v = (array) $db->one("SELECT version, action, actor, reason, JSON_UNQUOTE(JSON_EXTRACT(state, '$.review_rule')) AS after_rule, "
            . "JSON_EXTRACT(state, '$.review_limit_units') AS after_limit, JSON_UNQUOTE(JSON_EXTRACT(before_state, '$.review_rule')) AS before_rule, "
            . "JSON_UNQUOTE(JSON_EXTRACT(state, '$.reject_action')) AS after_reject, JSON_UNQUOTE(JSON_EXTRACT(before_state, '$.reject_action')) AS before_reject "
            . "FROM config_change WHERE subject_type = 'document_rule' AND subject_key = 'CNT' ORDER BY version DESC LIMIT 1");
        self::assertSame([$rulesBefore + 1, 'change', 'system:migrate', 'none', 10, 'over_limit', 'record', 'reverse'],
            [(int) $v['version'], $v['action'], $v['actor'], $v['after_rule'], (int) $v['after_limit'], $v['before_rule'], $v['after_reject'], $v['before_reject']]);
        self::assertGreaterThanOrEqual(3, mb_strlen((string) $v['reason']));
        self::assertSame([], ConfigInvariants::check($db), 'every changed row equals the latest version of its history');
        // No other kind of record was touched.
        self::assertSame(['all'], array_map('strval', $db->column("SELECT CAST(review_rule AS CHAR) FROM document_type WHERE code = 'ADJ'")));

        // A sheet's rows and their rules.
        $staff = $db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m29', 'Counter', 'm29@test.example', 'x')");
        $doc = static fn (): int => $db->insert("INSERT INTO document (doc_type, status, version, warehouse_id, created_actor) VALUES ('CNT', 'draft', 1, 1, 'staff:1')");
        $sheet = $doc();
        $db->exec('INSERT INTO count_sheet (document_id) VALUES (?)', [$sheet]);
        self::assertSame('sheet', $db->value('SELECT kind FROM count_sheet WHERE document_id = ?', [$sheet]));
        $line = 'INSERT INTO count_sheet_line (document_id, line_no, sku_id, expected_qty, added_at, counted_qty, counted_at, counted_by, entered_at, entered_how) '
            . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?, ?, ?, ?, ?)';
        $db->exec($line, [$sheet, 1, $sku[0], 7, null, null, null, null, null]);
        $db->exec($line, [$sheet, 2, $sku[1], 0, 0, '2026-10-12 10:00:00', $staff, '2026-10-12 10:00:00', 'typed']);
        self::assertSame(['none', 0, 0], array_values((array) $db->one('SELECT review, counted_qty, expected_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 2', [$sheet])),
            'a count of nothing is a count; a line starts with no check');
        self::assertNull($db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$sheet]), 'not counted yet');
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $db->exec($line, [$sheet, 3, $sku[0], 7, null, null, null, null, null])), 'an item is on a sheet once');
        self::assertSame(self::BAD_CHECK, self::mysqlError(static fn () => $db->exec($line, [$sheet, 3, $sku[2], 0, 4, null, $staff, null, null])), 'a quantity has its time');
        self::assertSame(self::BAD_CHECK, self::mysqlError(static fn () => $db->exec($line, [$sheet, 3, $sku[2], 0, -1, '2026-10-12 10:00:00', $staff, '2026-10-12 10:00:00', 'typed'])),
            'never a negative count');
        self::assertSame(self::BAD_CHECK, self::mysqlError(static fn () => $db->exec('UPDATE count_sheet_line SET ack_ledger_id = 5, ack_at = UTC_TIMESTAMP(6) WHERE document_id = ? AND line_no = 1',
            [$sheet])), 'only a counted line is posted as counted');
        self::assertSame(self::BAD_CHECK, self::mysqlError(static fn () => $db->exec("UPDATE count_sheet_line SET review = 'open' WHERE document_id = ? AND line_no = 2", [$sheet])),
            'a check is of a line that was booked');
        $db->exec("UPDATE count_sheet_line SET doc_line_no = 1, system_qty = 0, booked_delta = 0, outcome = 'counted' WHERE document_id = ? AND line_no = 2", [$sheet]);
        $db->exec("UPDATE count_sheet_line SET review = 'open', review_units = 5, review_percent = 10 WHERE document_id = ? AND line_no = 2", [$sheet]);
        self::assertSame(self::BAD_CHECK, self::mysqlError(static fn () => $db->exec("UPDATE count_sheet_line SET review = 'rejected', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(6) "
            . 'WHERE document_id = ? AND line_no = 2', [$staff, $sheet])), 'a rejected difference names the record that put the count back');
        // The record that puts one line back: it names the sheet and the line, once.
        $back = $doc();
        self::assertSame(self::BAD_CHECK, self::mysqlError(static fn () => $db->exec("INSERT INTO count_sheet (document_id, kind) VALUES (?, 'line_reversal')", [$back])));
        $db->exec("INSERT INTO count_sheet (document_id, kind, reverses_document_id, reverses_line_no) VALUES (?, 'line_reversal', ?, 2)", [$back, $sheet]);
        $again = $doc();
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $db->exec("INSERT INTO count_sheet (document_id, kind, reverses_document_id, reverses_line_no) "
            . "VALUES (?, 'line_reversal', ?, 2)", [$again, $sheet])), 'a line is put back once');
        $db->exec("UPDATE count_sheet_line SET review = 'rejected', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(6), reversal_document_id = ? WHERE document_id = ? AND line_no = 2",
            [$staff, $back, $sheet]);
        // The app login never deletes a sheet or a line; a header is never changed, and a line's item, its place on the sheet and what
        // the system held when it was put there are never changed either (review M-8 of 14 Oct 2026: GrantsTest).
        self::assertContains('count_sheet', Grants::APPEND_ONLY);
        self::assertArrayHasKey('count_sheet_line', Grants::UPDATE_COLUMNS);
    }

    public function testACountCheckAlreadySwitchedOffOnTheScreenGetsNoNewVersion(): void
    {
        /** @var Db $db */
        [$db, $dir] = MigrationFixture::upTo('m29b', self::before());
        $this->dirs['m29b'] = $dir;
        // As the Approval Rules page writes it: the row, and the next version of its history (the check off, Not OK only records).
        $db->exec("UPDATE document_type SET review_rule = 'none', reject_action = 'record' WHERE code = 'CNT'");
        $db->exec("INSERT INTO config_change (subject_type, subject_key, version, action, state, before_state, reason, actor) "
            . "SELECT 'document_rule', 'CNT', c.version + 1, 'change', JSON_SET(c.state, '$.review_rule', 'none', '$.reject_action', 'record'), c.state, 'one person works here', "
            . "'system:document_rules' FROM config_change c WHERE c.subject_type = 'document_rule' AND c.subject_key = 'CNT' ORDER BY c.version DESC LIMIT 1");
        $before = (int) $db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'document_rule' AND subject_key = 'CNT'");
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $dir, self::FILE));
        self::assertSame($before, (int) $db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'document_rule' AND subject_key = 'CNT'"),
            'nothing changed, so nothing is added to the history');
        self::assertSame('none', (string) $db->value("SELECT CAST(review_rule AS CHAR) FROM document_type WHERE code = 'CNT'"));
        self::assertSame([], ConfigInvariants::check($db));
    }
}
