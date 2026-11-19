<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0072 (each box of a draft order saves by itself, the live drafts plan step 4; docs/decisions.md LS1-LS14), applied to a scratch schema of
 * 0071 with lines in every state: each line gets its own version 1, nobody and no time as who changed it last; nothing else of a line or a
 * document moves; one new, empty table for the line-by-line changes (no foreign keys, the app login's full rights, a key of the page sent
 * twice done once); the clean-up's setting with its baseline; live.mode's words. A second run changes nothing; a run stopped half way runs
 * again.
 */
final class Migration0072Test extends IntegrationTestCase
{
    private const FILE = '0072_live_save.sql';
    private const BEFORE = '0071_po_draft_look.sql';
    private const CHECK_VIOLATED = 3819;
    private const DUPLICATE = 1062;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m72', $this->dir);
            $this->dir = null;
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private static function statements(): array
    {
        return SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
    }

    /** @return array<string, int> a draft of two lines with ids and a posted document of one line without */
    private static function documents(Db $db): array
    {
        $doc = static function (string $status, array $uids, array $extra = []) use ($db): int {
            $cols = ['doc_type' => 'PO', 'status' => $status, 'version' => 5, 'created_actor' => 'staff:1', 'next_line_uid' => count($uids) + 1] + $extra;
            $id = $db->insert('INSERT INTO document (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
                array_values($cols));
            foreach ($uids as $i => $uid) {
                $db->exec('INSERT INTO document_line (document_id, line_no, uid, sku_id, qty, unit_cost, amount, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $i + 1, $uid, 100 + $i, 6, '1.250000', '7.500000', 'line ' . ($i + 1)]);
            }
            return $id;
        };
        return [
            'draft' => $doc('draft', [1, 2]),
            'posted' => $doc('posted', [null], ['number' => 'PO-000001', 'posted_actor' => 'staff:1', 'posted_at' => '2026-11-17 10:00:00.000000',
                'posted_hash' => str_repeat('a', 64), 'review_state' => 'not_required']),
        ];
    }

    public function testEachLineGetsItsVersionTheChangesTableIsEmptyAndNothingElseMoves(): void
    {
        $statements = self::statements();
        // The ALTER first (if the server refuses it, nothing of the file is applied), run only while its column is not there, in place and online.
        self::assertStringContainsString("COLUMN_NAME = 'rev'", $statements[0]);
        self::assertMatchesRegularExpression('/ALTER TABLE document_line ADD COLUMN rev INT UNSIGNED NOT NULL DEFAULT 1 COMMENT .+, ADD COLUMN changed_by INT UNSIGNED NULL '
            . 'COMMENT .+, ADD COLUMN changed_at DATETIME\(6\) NULL COMMENT .+, ALGORITHM = INPLACE, LOCK = NONE/s', $statements[0]);
        self::assertSame(0, preg_match('/\bDELETE\s+FROM\b|\bDROP\b|\bTRUNCATE\b|\bFOREIGN\s+KEY\b|ALGORITHM\s*=\s*(COPY|INSTANT)/i', implode(";\n", $statements)),
            'nothing removed, no foreign key, no table copied');

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m72', self::BEFORE);
        $d = self::documents($db);
        $lineCols = 'document_id, line_no, uid, sku_id, warehouse_id, location_id, qty, unit_cost, amount, reason_code, description';
        $lines = $db->all("SELECT {$lineCols} FROM document_line ORDER BY document_id, line_no");
        $docs = $db->all('SELECT * FROM document ORDER BY id');
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));

        self::assertSame([[1, null, null], [1, null, null], [1, null, null]], array_map(static fn (array $r): array => [(int) $r['rev'], $r['changed_by'], $r['changed_at']],
            $db->all('SELECT rev, changed_by, changed_at FROM document_line ORDER BY document_id, line_no')), 'every line: version 1, nobody, no time');
        self::assertSame($lines, $db->all("SELECT {$lineCols} FROM document_line ORDER BY document_id, line_no"), 'nothing else of a line moved');
        self::assertSame($docs, $db->all('SELECT * FROM document ORDER BY id'), 'nothing of a document moved');
        self::assertSame(Grants::FULL, Grants::desired('document_line'), 'a draft\'s lines are written in place and replaced');

        // The line-by-line changes: one new, empty table, no foreign keys, the app login's full rights.
        self::assertSame(['draft_change'], array_values(array_diff($db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), $tables)));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM draft_change'));
        self::assertSame(['id', 'kind', 'record_id', 'staff_user_id', 'tab', 'op', 'line_uid', 'field', 'before_json', 'after_json', 'op_key', 'doc_version', 'created_at'],
            $db->column("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'draft_change' ORDER BY ORDINAL_POSITION"));
        $indexes = array_column($db->all("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'draft_change' GROUP BY INDEX_NAME"), 'cols', 'INDEX_NAME');
        ksort($indexes);
        self::assertSame(['PRIMARY' => 'id', 'ix_draft_change_created' => 'created_at', 'ix_draft_change_record' => 'kind,record_id,id',
            'uq_draft_change_key' => 'kind,record_id,op_key'], $indexes, 'a page reads a draft\'s newest changes by (kind, record_id, id); the clean-up by the time');
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'draft_change'"), 'no foreign keys');
        self::assertSame(Grants::FULL, Grants::desired('draft_change'), 'written, and removed by the nightly clean-up');
        self::assertSame(['set', 'add', 'remove', 'restore', 'header', 'supplier', 'replace', 'conflict'], array_map(static fn (string $v): string => trim($v, "'"),
            explode(',', substr((string) $db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'draft_change' "
                . "AND COLUMN_NAME = 'op'"), 5, -1))));
        $row = static fn (array $over): mixed => $db->exec('INSERT INTO draft_change (kind, record_id, staff_user_id, tab, op, op_key, doc_version, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            array_values(array_replace(['kind' => 'po', 'record_id' => $d['draft'], 'staff_user_id' => 1, 'tab' => 'abcdef0123456789', 'op' => 'add', 'op_key' => '0123456789abcdef',
                'doc_version' => 6, 'created_at' => '2026-11-19 10:00:00'], $over)));
        foreach ([['tab' => 'ABCDEF0123456789'], ['tab' => 'abc'], ['op_key' => 'XYZ'], ['op_key' => '0123456789ABCDEF']] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $row($bad)), json_encode($bad));
        }
        $row([]);
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $row([])), 'one key of a draft, once: an add sent twice is done once');
        $row(['record_id' => $d['posted']]); // the same key on another draft
        $row(['op_key' => null, 'tab' => null]);
        $row(['op_key' => null, 'op' => 'set']);
        self::assertSame(4, (int) $db->value('SELECT COUNT(*) FROM draft_change'));
        $db->exec('DELETE FROM draft_change');

        // The clean-up's setting: 90 days, provisional, with its baseline. live.mode says what "on" does now.
        $s = $db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = 'housekeeping.draft_change_keep_days'");
        self::assertSame(['int', '90', 1, 'LS11'], [(string) $s['value_type'], (string) $s['v'], (int) $s['provisional'], (string) $s['decision']]);
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'housekeeping.draft_change_keep_days' "
            . "AND version = 1 AND action = 'baseline' AND actor = 'system:migrate'"));
        self::assertStringContainsString('each box saves by itself', (string) $db->value("SELECT description FROM app_setting WHERE setting_key = 'live.mode'"));
        self::assertSame('"off"', (string) $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'live.mode'"), 'its value untouched');

        // A second run changes nothing.
        $snapshot = static fn (): array => [$db->all('SELECT * FROM document_line ORDER BY document_id, line_no'), $db->all('SELECT * FROM document ORDER BY id'),
            $db->all('SELECT * FROM app_setting ORDER BY setting_key'), $db->all('SELECT * FROM config_change ORDER BY id'),
            $db->all("SELECT TABLE_NAME, CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")];
        $db->exec('UPDATE document_line SET rev = 4, changed_by = 1, changed_at = ? WHERE document_id = ?', ['2026-11-19 10:00:00.000000', $d['draft']]);
        $before = $snapshot();
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame($before, $snapshot(), 'a second run changes nothing (not a version given since)');
    }

    public function testARunStoppedHalfWayRunsAgain(): void
    {
        $statements = self::statements();
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m72', self::BEFORE);
        self::documents($db);
        // Stopped after the column and the table, before the setting (the file is recorded only after its last statement).
        $done = 0;
        foreach ($statements as $sql) {
            if (str_contains($sql, 'INSERT INTO app_setting')) {
                break;
            }
            $db->pdo()->exec($sql);
            $done++;
        }
        self::assertGreaterThan(3, $done);
        self::assertNull($db->value('SELECT version FROM schema_migrations WHERE version = ?', [self::FILE]), 'stopped, not recorded');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE), 'runs again');
        self::assertSame(3, (int) $db->value('SELECT COUNT(*) FROM document_line WHERE rev = 1'));
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key = 'housekeeping.draft_change_keep_days'"));
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'housekeeping.draft_change_keep_days'"));
    }
}
