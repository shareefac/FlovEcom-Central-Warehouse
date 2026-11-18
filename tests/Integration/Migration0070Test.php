<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0070 (who is here on a draft, the live drafts plan step 3; docs/decisions.md LV2, LV8, LV9), applied to a scratch schema of 0069 with
 * documents in every state: each line of a draft or of a document waiting for its OK gets its line id (uid = its line number) and the
 * document its next free one; a final, cancelled or reversed document's lines keep none (posted content is never rewritten); nothing
 * else of a line or a document moves (not their version); one new, empty table for who is here (no foreign keys, the app login's full
 * rights); the four settings, off by default, each with its baseline. A second run changes nothing; a run stopped half way runs again.
 */
final class Migration0070Test extends IntegrationTestCase
{
    private const FILE = '0070_live_presence.sql';
    private const BEFORE = '0069_people_manager.sql';
    private const CHECK_VIOLATED = 3819;
    private const DUPLICATE = 1062;
    private const SETTINGS = ['live.alone_seconds' => ['int', '10'], 'live.gone_seconds' => ['int', '25'], 'live.mode' => ['string', '"off"'],
        'live.poll_seconds' => ['int', '3']];
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m70', $this->dir);
            $this->dir = null;
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private static function statements(): array
    {
        return SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
    }

    /**
     * Documents as they are before 0070: a draft of three lines, one waiting for its OK (two), an empty draft, a final one (two), a
     * cancelled draft (one) and a reversed one (one).
     *
     * @return array<string, int> name => document id
     */
    private static function documents(Db $db): array
    {
        $doc = static function (string $status, int $lines, array $extra = []) use ($db): int {
            $cols = ['doc_type' => 'PO', 'status' => $status, 'version' => 7, 'created_actor' => 'staff:1', 'note' => "a {$status} one"] + $extra;
            $id = $db->insert('INSERT INTO document (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
                array_values($cols));
            for ($n = 1; $n <= $lines; $n++) {
                $db->exec('INSERT INTO document_line (document_id, line_no, sku_id, qty, unit_cost, amount, description) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, $n, 100 + $n, 6 * $n, '1.250000', '7.500000', "line {$n}"]);
            }
            return $id;
        };
        $posted = static fn (string $no): array => ['number' => $no, 'posted_actor' => 'staff:1', 'posted_at' => '2026-11-17 10:00:00.000000',
            'posted_hash' => str_repeat('a', 64), 'review_state' => 'not_required'];
        return [
            'draft' => $doc('draft', 3),
            'waiting' => $doc('awaiting_approval', 2, ['submitted_at' => '2026-11-17 09:00:00.000000']),
            'empty' => $doc('draft', 0),
            'posted' => $doc('posted', 2, $posted('PO-000001')),
            'cancelled' => $doc('cancelled', 1, ['cancelled_at' => '2026-11-17 11:00:00.000000', 'cancel_reason' => 'not needed']),
            'reversed' => $doc('reversed', 1, $posted('PO-000002')),
        ];
    }

    /** @return array<int, list<?int>> document id => its lines' uids, in line order */
    private static function uids(Db $db): array
    {
        $out = [];
        foreach ($db->all('SELECT document_id, uid FROM document_line ORDER BY document_id, line_no') as $r) {
            $out[(int) $r['document_id']][] = $r['uid'] === null ? null : (int) $r['uid'];
        }
        return $out;
    }

    public function testEachDraftLineGetsItsIdThePostedOnesKeepNoneAndNothingElseMoves(): void
    {
        $statements = self::statements();
        // The two ALTERs come first (if the server refuses one, nothing of the file is applied), each run only while its column is not there,
        // in place and online: reads and writes of lines and documents go on while they run.
        self::assertStringContainsString("COLUMN_NAME = 'uid'", $statements[0]);
        self::assertMatchesRegularExpression('/ALTER TABLE document_line ADD COLUMN uid INT UNSIGNED NULL COMMENT .+, ADD UNIQUE KEY uq_document_line_uid '
            . '\(document_id, uid\), ALGORITHM = INPLACE, LOCK = NONE/s', $statements[0]);
        $alters = implode("\n", array_filter($statements, static fn (string $s): bool => stripos($s, 'ALTER TABLE') !== false));
        self::assertMatchesRegularExpression('/ALTER TABLE document ADD COLUMN next_line_uid INT UNSIGNED NOT NULL DEFAULT 1 COMMENT .+, ALGORITHM = INPLACE, '
            . 'LOCK = NONE/s', $alters);
        self::assertSame(0, preg_match('/\bDELETE\s+FROM\b|\bDROP\b|\bTRUNCATE\b|\bFOREIGN\s+KEY\b|ALGORITHM\s*=\s*(COPY|INSTANT)/i', implode(";\n", $statements)),
            'nothing removed, no foreign key, no table copied');

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m70', self::BEFORE);
        $d = self::documents($db);
        $lineCols = 'document_id, line_no, sku_id, warehouse_id, location_id, qty, unit_cost, amount, reason_code, description';
        $lines = $db->all("SELECT {$lineCols} FROM document_line ORDER BY document_id, line_no");
        $docs = $db->all('SELECT * FROM document ORDER BY id');
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));

        $uids = self::uids($db);
        self::assertSame([1, 2, 3], $uids[$d['draft']], 'a draft\'s lines: uid = line number');
        self::assertSame([1, 2], $uids[$d['waiting']], 'waiting for its OK: it may come back as a draft');
        self::assertSame([null, null], $uids[$d['posted']], 'a final document is never rewritten');
        self::assertSame([null], $uids[$d['cancelled']]);
        self::assertSame([null], $uids[$d['reversed']]);
        $next = array_map('intval', array_column($db->all('SELECT id, next_line_uid FROM document ORDER BY id'), 'next_line_uid', 'id'));
        self::assertSame([$d['draft'] => 4, $d['waiting'] => 3, $d['empty'] => 1, $d['posted'] => 1, $d['cancelled'] => 1, $d['reversed'] => 1], $next);
        self::assertSame($lines, $db->all("SELECT {$lineCols} FROM document_line ORDER BY document_id, line_no"), 'nothing else of a line moved');
        $without = static fn (array $rows): array => array_map(static function (array $r): array {
            unset($r['next_line_uid']);
            return $r;
        }, $rows);
        self::assertSame($docs, $without($db->all('SELECT * FROM document ORDER BY id')), 'nothing else of a document moved: not its version, not its time');

        // Line ids are unique within their document (any number of lines without one: the final document's two above), not across documents.
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $db->exec('UPDATE document_line SET uid = 1 WHERE document_id = ? AND line_no = 2',
            [$d['draft']])));
        self::assertSame(['uq_document_line_uid' => ['document_id', 'uid']], array_map(static fn (string $c): array => explode(',', $c), array_column($db->all(
            "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'document_line' AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' GROUP BY INDEX_NAME"), 'cols', 'INDEX_NAME')));

        // Who is here: one new, empty table, no foreign keys, the app login's full rights.
        self::assertSame(['draft_presence'], array_values(array_diff($db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), $tables)));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM draft_presence'));
        self::assertSame(['kind', 'record_id', 'tab', 'staff_user_id', 'session_id', 'focus_uid', 'focus_field', 'state', 'typing', 'edits', 'seen_at'],
            $db->column("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'draft_presence' ORDER BY ORDINAL_POSITION"));
        $indexes = array_column($db->all("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'draft_presence' GROUP BY INDEX_NAME"), 'cols', 'INDEX_NAME');
        ksort($indexes);
        self::assertSame(['PRIMARY' => 'kind,record_id,tab', 'ix_draft_presence_record_seen' => 'kind,record_id,seen_at', 'ix_draft_presence_seen' => 'seen_at',
            'ix_draft_presence_session' => 'session_id'], $indexes, 'a poll reads a draft\'s newest rows by (kind, record_id, seen_at); a sign-out by the session; the clean-up by the time');
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'draft_presence'"), 'a heartbeat never fails on a foreign key');
        self::assertSame(Grants::FULL, Grants::desired('draft_presence'));
        self::assertSame(Grants::FULL, Grants::desired('document_line'), 'a draft\'s lines are still replaced');
        self::assertArrayHasKey('next_line_uid', Grants::desiredColumns('document'), 'the app login moves the next free line id');
        $row = static fn (array $over): mixed => $db->exec('INSERT INTO draft_presence (kind, record_id, tab, staff_user_id, session_id, state, seen_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            array_values(array_replace(['kind' => 'po', 'record_id' => 1, 'tab' => 'abcdef0123456789', 'staff_user_id' => 1, 'session_id' => str_repeat('0123456789abcdef', 4),
                'state' => 'active', 'seen_at' => '2026-11-18 10:00:00'], $over)));
        foreach ([['tab' => 'ABCDEF0123456789'], ['tab' => 'abcdef012345678'], ['session_id' => 'xyz'], ['session_id' => str_repeat('0123456789ABCDEF', 4)],
            ['session_id' => '0123456789abcdef']] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $row($bad)), json_encode($bad));
        }
        $row([]);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec('UPDATE draft_presence SET typing = 2')));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec('UPDATE draft_presence SET edits = 2')));
        $db->exec('DELETE FROM draft_presence');

        // The four settings: off by default (nothing changes on the day of the deploy), each with its baseline.
        $settings = [];
        foreach ($db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key LIKE 'live.%' "
            . 'ORDER BY setting_key') as $s) {
            $settings[(string) $s['setting_key']] = [(string) $s['value_type'], (string) $s['v']];
            self::assertSame([1, 'LV9'], [(int) $s['provisional'], $s['decision']], (string) $s['setting_key']);
        }
        self::assertSame(self::SETTINGS, $settings);
        self::assertSame(array_keys(self::SETTINGS), $db->column("SELECT subject_key FROM config_change WHERE subject_type = 'setting' AND subject_key LIKE 'live.%' "
            . "AND version = 1 AND action = 'baseline' AND actor = 'system:migrate' ORDER BY subject_key"));

        // A second run changes nothing.
        $snapshot = static fn (): array => [$db->all('SELECT * FROM document_line ORDER BY document_id, line_no'), $db->all('SELECT * FROM document ORDER BY id'),
            $db->all("SELECT * FROM app_setting WHERE setting_key LIKE 'live.%' ORDER BY setting_key"), $db->all('SELECT * FROM config_change ORDER BY id'),
            $db->all("SELECT TABLE_NAME, CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")];
        $before = $snapshot();
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame($before, $snapshot(), 'a second run changes nothing');
        // ... also once the new code gave ids past the line numbers (the backfill never moves an id, nor the next free one backwards).
        $db->exec('UPDATE document_line SET uid = uid + 10 WHERE document_id = ?', [$d['draft']]);
        $db->exec('UPDATE document SET next_line_uid = 14 WHERE id = ?', [$d['draft']]);
        $moved = $snapshot();
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame($moved, $snapshot());
    }

    public function testARunStoppedHalfWayRunsAgain(): void
    {
        $statements = self::statements();
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m70', self::BEFORE);
        $d = self::documents($db);
        // Stopped after the columns and the table, before the backfill and the settings (the file is recorded only after its last statement).
        $done = 0;
        foreach ($statements as $sql) {
            if (str_contains($sql, 'UPDATE document_line')) {
                break;
            }
            $db->pdo()->exec($sql);
            $done++;
        }
        self::assertGreaterThan(3, $done);
        self::assertNull($db->value('SELECT version FROM schema_migrations WHERE version = ?', [self::FILE]), 'stopped, not recorded');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE), 'runs again');
        $uids = self::uids($db);
        self::assertSame([[1, 2, 3], [1, 2], [null, null]], [$uids[$d['draft']], $uids[$d['waiting']], $uids[$d['posted']]]);
        self::assertSame(4, (int) $db->value('SELECT next_line_uid FROM document WHERE id = ?', [$d['draft']]));
        self::assertSame(4, (int) $db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key LIKE 'live.%'"));
        self::assertSame(4, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key LIKE 'live.%'"));
    }
}
