<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ai;

use CW\Clock;
use CW\Tests\Support\AiFixtures;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * bin/ai_read.php (CW-1182; docs/decisions.md AI3, AI11), the job cron runs every minute: one run at a time (its lock), the clean-up first
 * whatever the switch, then the documents waiting; silent when there is nothing to do; never a call while reading is off (the job fails; an
 * invoice's file is kept for its Save, AI15); each run recorded on the reader's own row (AI17: the health alert reads it), a run that never
 * ended said by the next. No test here reaches Claude: the switch stays off.
 */
final class AiReadCliTest extends IntegrationTestCase
{
    private string $spool = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // the job runs as the app login, as cron runs it: its grants on this schema as bin/migrate.php converges them
        $user = TestDb::config()->appDbUser();
        self::assertNotNull($user, 'app.env has no db_user');
        \CW\Schema\Grants::apply(self::$db, TestDb::name(), $user);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->spool = AiFixtures::dir('clispool');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->spool));
        parent::tearDown();
    }

    /** @return array{code: int, out: string, err: string} */
    private function cli(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $env = getenv();
        $env['CW_AI_SPOOL_DIR'] = $this->spool;
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', "{$root}/bin/ai_read.php", '--db=' . TestDb::name(), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $root, $env);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    public function testItCleansUpReadsWhatWaitsAndIsSilentOtherwise(): void
    {
        $staff = self::$db->insert("INSERT INTO staff_user (username, email, display_name, password_hash) VALUES ('cli1', 'cli1@example.com', 'Cli', 'x')");
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'expenses')", [$staff]);
        $r = $this->cli('--once');
        self::assertSame([0, '', ''], [$r['code'], $r['out'], $r['err']], 'nothing to do: silent');
        $run = self::$db->one('SELECT started_at, ended_at, ok_at, failed_at, error FROM ai_reader WHERE id = 1');
        self::assertNotNull($run, 'the run recorded');
        self::assertSame([true, true, null, null], [$run['ended_at'] !== null, $run['ok_at'] === $run['ended_at'], $run['failed_at'], $run['error']], 'ended well');
        // a job waiting while reading is off: failed with no call, its file gone; a stray file of a crash cleaned up
        file_put_contents($this->spool . '/' . str_repeat('a', 32), "%PDF-1.4\n");
        file_put_contents($this->spool . '/' . str_repeat('e', 32), 'stray');
        touch($this->spool . '/' . str_repeat('e', 32), time() - 3700);
        $id = self::$db->insert("INSERT INTO ai_read (staff_user_id, place, kind, context, media_type, file_bytes, file_sha256, spool_name, state, created_at) VALUES "
            . "(?, 'expense_new', 'expense_invoice', '{}', 'application/pdf', 9, ?, ?, 'waiting', ?)", [$staff, str_repeat('0', 64), str_repeat('a', 32), Clock::db(Clock::now())]);
        // one run at a time: another holds the lock, nothing is read
        $lock = TestDb::db();
        self::assertSame(1, (int) $lock->value('SELECT GET_LOCK(?, 0)', ['cw_job:ai_read:' . TestDb::name()]));
        $r = $this->cli('--once');
        self::assertSame(0, $r['code']);
        self::assertStringContainsString('skipped: another run of this job holds its lock', $r['out']);
        self::assertSame('waiting', self::$db->value('SELECT state FROM ai_read WHERE id = ?', [$id]));
        $lock->value('SELECT RELEASE_LOCK(?)', ['cw_job:ai_read:' . TestDb::name()]);
        $r = $this->cli('--once');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('cleaned up: stopped=0 too_old=0 expired=0 crashed=0 files=1 rows=0', $r['out']);
        self::assertStringContainsString("read {$id}: failed (off)", $r['out']);
        self::assertSame(['failed', 'off'], array_values(self::$db->one('SELECT state, reason FROM ai_read WHERE id = ?', [$id]) ?? []));
        self::assertSame(['.', '..', str_repeat('a', 32)], scandir($this->spool), 'the stray file gone; the invoice kept for its Save (an hour)');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM ai_call'), 'no call while off');
        self::assertSame(2, $this->cli('--seconds=x')['code'], 'usage');
        self::assertSame(2, $this->cli('--bogus')['code']);
        $r = $this->cli('--seconds=0');
        self::assertSame([0, ''], [$r['code'], $r['out']], 'a run of no time: nothing waits, silent');
        // a run that never ended (its process died): the next run says so and records it; it ended well itself
        self::$db->exec('UPDATE ai_reader SET started_at = NOW(6), ended_at = NULL WHERE id = 1');
        $r = $this->cli('--seconds=0');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('the last run did not end', $r['out']);
        $run = self::$db->one('SELECT ended_at, ok_at, failed_at, error FROM ai_reader WHERE id = 1') ?? [];
        self::assertSame(['did not end', true, true], [$run['error'], $run['failed_at'] !== null, (string) $run['ok_at'] >= (string) $run['failed_at']]);
    }
}
