<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0082 ("Claude reads the upload", CW-1182; docs/decisions.md AI7-AI9), applied to a scratch schema at the file before it (on this branch
 * 0079, the live release's last: 0080 and 0081 are other branches' and are not needed): it only ADDS (three tables, seven settings with their
 * baselines, switched OFF and the key empty); no existing row changes; its CHECKs; the app login's rights (a call's row is written before the
 * request and finished once after it: who, where, the model asked and the most it may cost are never changed, a row is never removed; the
 * jobs are removed a day after; the reader's one row of its last runs); running every statement again changes nothing, a value set on the
 * screen meanwhile stays.
 */
final class Migration0082Test extends IntegrationTestCase
{
    private const FILE = '0082_ai_reader.sql';
    private const CHECK_VIOLATED = 3819;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m82', $this->dir);
        }
    }

    public function testItOnlyAddsSwitchedOffAndRunsAgainCleanly(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        $statements = SqlSplitter::split($sql);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bDROP\b|\bALTER\b/i', $sql, 'nothing deleted, changed, dropped or altered');
        foreach ($statements as $st) {
            self::assertMatchesRegularExpression('/^(CREATE TABLE IF NOT EXISTS (ai_read|ai_call|ai_reader) |INSERT INTO (app_setting|config_change) )/', $st);
        }
        self::assertCount(5, $statements, 'three tables, the settings and their baselines');
        self::assertSame(['Select', 'Insert'], Grants::desired('ai_call'), 'a call is logged, never removed');
        // AI16: the row is written BEFORE the request (started, at the most it may cost) and finished once after it; who, where, the model asked,
        // the file and that most are frozen
        self::assertSame(['served_model', 'input_tokens', 'output_tokens', 'cost_millipence', 'result', 'reason', 'refusal_category', 'request_id', 'fallback', 'seconds',
            'checked', 'check_code', 'checked_at'], array_keys(Grants::desiredColumns('ai_call')), 'only the call\'s outcome and what CW\'s checks made of it are written later');
        self::assertSame(['Select', 'Insert', 'Update', 'Delete'], Grants::desired('ai_read'), 'the jobs: removed a day after they finish');
        self::assertSame(['Select', 'Insert', 'Update', 'Delete'], Grants::desired('ai_reader'), 'the reader\'s own row of its runs');
        self::assertTrue(Settings::isSecret('ai.anthropic_api_key'), 'the key is a secret: never shown back, the history keeps a fingerprint');

        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m82', $files[$at - 1]);
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $settings = $db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, updated_at FROM app_setting ORDER BY setting_key');
        $history = (int) $db->value('SELECT COUNT(*) FROM config_change');
        $staff = $db->insert("INSERT INTO staff_user (username, email, display_name, password_hash) VALUES ('m82', 'm82@example.com', 'M82', 'x')");

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        $new = array_values(array_diff($db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), $tables));
        sort($new);
        self::assertSame(['ai_call', 'ai_read', 'ai_reader'], $new);
        $want = ['ai.anthropic_api_key' => '""', 'ai.effort' => '"medium"', 'ai.model' => '"claude-opus-5-5"', 'ai.monthly_limit_gbp' => '"30"',
            'ai.price_input_gbp_per_mtok' => '"3.20"', 'ai.price_output_gbp_per_mtok' => '"16.00"', 'ai.read_uploads' => 'false'];
        self::assertSame($want, array_column($db->all("SELECT setting_key, CAST(value_json AS CHAR) AS v FROM app_setting WHERE setting_key LIKE 'ai.%' ORDER BY setting_key"),
            'v', 'setting_key'), 'switched OFF, the key empty, the limit £30');
        self::assertSame(7, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key LIKE 'ai.%' AND version = 1 AND action = 'baseline'"));
        self::assertSame($settings, $db->all("SELECT setting_key, CAST(value_json AS CHAR) AS v, updated_at FROM app_setting WHERE setting_key NOT LIKE 'ai.%' ORDER BY setting_key"),
            'no other setting changes');
        self::assertSame($history + 7, (int) $db->value('SELECT COUNT(*) FROM config_change'));
        foreach (array_keys($want) as $k) {
            self::assertArrayHasKey($k, \CW\Ui\Words::SETTING, "{$k} has a name on the screen");
            self::assertArrayHasKey($k, \CW\Ui\Words::SETTING_HELP, "{$k} says what it does");
            self::assertSame(\CW\Ui\Words::SETTING_TOPIC['ai'], \CW\Ui\Words::settingTopic($k));
        }

        // The CHECKs and the keys.
        $job = static fn (array $over = []): int => $db->insert('INSERT INTO ai_read (staff_user_id, place, kind, context, media_type, file_bytes, file_sha256, spool_name, state, '
            . 'answer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(6))', array_values(array_replace(['staff' => $staff, 'place' => 'expense_new',
                'kind' => 'expense_invoice', 'ctx' => '{}', 'media' => 'application/pdf', 'bytes' => 10, 'sha' => str_repeat('a', 64), 'spool' => null, 'state' => 'waiting',
                'answer' => null], $over)));
        $job();
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $job(['bytes' => 0])), 'a file of nothing');
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $job(['state' => 'done'])), 'done holds its answer');
        $kept = $job(['state' => 'done', 'answer' => '{}', 'spool' => str_repeat('b', 32)]);
        $db->exec('UPDATE ai_read SET file_used_at = NOW(6) WHERE id = ?', [$kept]); // AI15: an invoice's file attached once by Save
        self::assertSame(1062, self::err(static fn () => $job(['spool' => str_repeat('b', 32)])), 'one job a spool file');
        self::assertSame(1452, self::err(static fn () => $job(['staff' => 999999])), 'a person CW has');
        $call = static fn (array $over = []): int => $db->insert('INSERT INTO ai_call (read_id, created_at, staff_user_id, place, kind, model, media_type, file_bytes, result, '
            . 'reason, seconds, fallback) VALUES (NULL, NOW(6), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_values(array_replace(['staff' => $staff, 'place' => 'expense_new',
                'kind' => 'expense_invoice', 'model' => 'claude-opus-5-5', 'media' => 'image/png', 'bytes' => 10, 'result' => 'ok', 'reason' => 'ok', 'seconds' => '1.50',
                'fallback' => 0], $over)));
        $call();
        $call(['result' => 'started', 'reason' => 'started']);
        $call(['result' => 'crashed', 'reason' => 'stopped']);
        self::assertSame(1265, self::err(static fn () => $call(['result' => 'maybe'])), 'started, ok, refused, failed or crashed');
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $call(['fallback' => 2])));
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $call(['seconds' => '-1'])), 'never minus');
        // the reader's runs (AI17): one row
        $db->exec("INSERT INTO ai_reader (id, started_at, ended_at, ok_at) VALUES (1, NOW(6), NOW(6), NOW(6))");
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $db->exec('INSERT INTO ai_reader (id) VALUES (2)')), 'one row');

        // Every statement again: nothing changes; a value set on the screen meanwhile stays.
        $db->exec("UPDATE app_setting SET value_json = CAST('\"15\"' AS JSON) WHERE setting_key = 'ai.monthly_limit_gbp'");
        foreach ($statements as $st) {
            $db->pdo()->exec($st);
        }
        self::assertSame('"15"', (string) $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'ai.monthly_limit_gbp'"));
        self::assertSame(7, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key LIKE 'ai.%'"));
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM ai_read'), 'the two rows the CHECKs let in');
        self::assertSame(3, (int) $db->value('SELECT COUNT(*) FROM ai_call'));
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM ai_reader'));
    }

    private static function err(callable $fn): int
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            return (int) Db::driverCode($e);
        }
        self::fail('expected a MySQL error');
    }
}
