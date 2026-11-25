<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ai;

use CW\Ai\AiSettings;
use CW\Ai\AiUsage;
use CW\Ai\DocumentReader;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Tests\Support\AiAnswers;
use CW\Tests\Support\AiFixtures;
use CW\Tests\Support\FakeClaude;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\RmBill;

/**
 * CW-1182 phase 1 (docs/decisions.md AI3, AI7-AI11): the one service that reads an upload, with a FAKE Claude (made-up answers at the HTTP
 * level; the network is never reached). Only who may upload in that place starts a reading, only while reading is on (the switch AND a key),
 * within the month's limit, a PDF or a photo (sniffed; a HEIC photo said apart) of at most 10 MiB; the file is kept 0600 in the spool until
 * it is read and deleted right after, read or not; every call is logged without content (tokens, cost, result, reason); the answer is taken
 * once by its person; the clean-up leaves no file, answer or job older than it may be.
 */
final class DocumentReaderTest extends IntegrationTestCase
{
    private string $spool = '';
    private string $files = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->spool = AiFixtures::dir('spool');
        $this->files = AiFixtures::dir('files');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->spool) . ' ' . escapeshellarg($this->files));
        parent::tearDown();
    }

    private static function person(string $role, string $name = 'ai1'): Caller
    {
        $p = Caller::staff(self::$db->insert('INSERT INTO staff_user (username, email, display_name, password_hash) VALUES (?, ?, ?, \'x\')',
            [$name, $name . '@example.com', 'Test ' . $name]));
        self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$p->staffUserId, $role]);
        return $p;
    }

    /** @return list<string> the spool folder's files */
    private function spooled(): array
    {
        return array_values(array_diff(scandir($this->spool) ?: [], ['.', '..']));
    }

    private static function refused(callable $fn): CwException
    {
        try {
            $fn();
        } catch (CwException $e) {
            return $e;
        }
        self::fail('refused');
    }

    public function testOnlyWhoMayUploadThereStartsAReadingAndOnlyWhileItIsOn(): void
    {
        $job = self::person('delivery_bills');
        $fin = self::person('finance', 'ai2');
        $f = new FakeClaude();
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        $pdf = AiFixtures::pdf($this->files);
        // switched off (as installed): refused, nothing kept
        self::assertSame(['ai_off', 'off_switch'], [($e = self::refused(fn () => $r->start($job, 'delivery_upload', $pdf, ['account' => 1])))->errorCode, $e->getMessage()]);
        AiFixtures::on(self::$db, [AiSettings::KEY => '""']);
        self::assertSame(['ai_off', 'off_key'], [($e = self::refused(fn () => $r->start($job, 'delivery_upload', $pdf, ['account' => 1])))->errorCode, $e->getMessage()],
            'on without a key is off');
        AiFixtures::on(self::$db);
        // another job's person, a system tool: refused before anything
        self::assertSame('role_not_allowed', self::refused(fn () => $r->start($fin, 'delivery_upload', $pdf, []))->errorCode);
        self::assertSame('role_not_allowed', self::refused(fn () => $r->start($job, 'expense_new', $pdf, []))->errorCode, 'the expenses\' own job only');
        self::assertSame('staff_required', self::refused(fn () => $r->start(Caller::system('test'), 'delivery_upload', $pdf, []))->errorCode);
        // what the file is: sniffed, never its name
        file_put_contents($this->files . '/bill.pdf.txt', "Customer Account Number: 0706703000\n");
        self::assertSame('ai_type', self::refused(fn () => $r->start($job, 'delivery_upload', $this->files . '/bill.pdf.txt', []))->errorCode);
        self::assertSame('ai_heic', self::refused(fn () => $r->start($job, 'delivery_upload', AiFixtures::heic($this->files), []))->errorCode);
        $big = $this->files . '/big.pdf';
        file_put_contents($big, "%PDF-1.4\n" . str_repeat('0', DocumentReader::MAX_BYTES));
        self::assertSame('too_large', self::refused(fn () => $r->start($job, 'delivery_upload', $big, []))->errorCode);
        self::assertSame('no_file', self::refused(fn () => $r->start($job, 'delivery_upload', $this->files . '/none.pdf', []))->errorCode);
        // the spool folder missing: said, nothing kept
        $gone = new DocumentReader(self::$db, $this->spool . '/missing');
        self::assertSame('ai_spool', self::refused(fn () => $gone->start($job, 'delivery_upload', $pdf, []))->errorCode);
        self::assertSame([], $this->spooled());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM ai_read'));
        // the month's limit reached: refused (uploading as before still works)
        self::$db->exec("INSERT INTO ai_call (read_id, created_at, staff_user_id, place, kind, model, media_type, file_bytes, input_tokens, output_tokens, cost_millipence, "
            . "result, reason, seconds) VALUES (NULL, ?, ?, 'delivery_upload', 'royal_mail_bill', 'claude-opus-5-5', 'application/pdf', 10, 1, 1, 3000000, 'ok', 'ok', 1)",
            [Clock::db(Clock::now()), $job->staffUserId]);
        $e = self::refused(fn () => $r->start($job, 'delivery_upload', $pdf, []));
        self::assertSame(['ai_limit', 3000, 3000], [$e->errorCode, $e->detail['used'], $e->detail['limit']], '£30.00 of £30.00');
        self::$db->exec('DELETE FROM ai_call');
        // started: the file kept 0600 under a name of its own, the job waiting with what the page needs back (ids, typed text: nothing else)
        $id = $r->start($job, 'delivery_upload', $pdf, ['account' => 7, 'nested' => ['x' => 1], 'Bad Key' => 'x', 'note' => str_repeat('n', 600)]);
        $row = self::$db->one('SELECT * FROM ai_read WHERE id = ?', [$id]);
        self::assertSame(['waiting', 'delivery_upload', 'royal_mail_bill', 'application/pdf', (string) filesize($pdf), hash_file('sha256', $pdf)],
            [$row['state'], $row['place'], $row['kind'], $row['media_type'], (string) $row['file_bytes'], $row['file_sha256']]);
        self::assertSame(['account' => 7, 'note' => str_repeat('n', 500)], json_decode((string) $row['context'], true));
        self::assertSame([(string) $row['spool_name']], $this->spooled());
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->spool . '/' . $row['spool_name'])), -4));
        self::assertSame(file_get_contents($pdf), file_get_contents($this->spool . '/' . $row['spool_name']));
        self::assertSame([], $f->sent, 'nothing sent by the upload itself: the runner reads');
    }

    public function testTheRunnerReadsOnceDeletesTheFileAndLogsWithoutContent(): void
    {
        $job = self::person('delivery_bills');
        $other = self::person('delivery_bills', 'ai3');
        AiFixtures::on(self::$db);
        $lines = [RmBill::line('2026-11-09', 'TPL', 2, 508, '943'), RmBill::line('2026-11-08', 'RW4', 1, 105, 'Rectification Week 45')];
        $f = (new FakeClaude())->push(FakeClaude::answer(AiAnswers::royalMail($lines), 15_000, 13_000));
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        self::assertNull($r->runNext(), 'nothing waiting');
        $id = $r->start($job, 'delivery_upload', AiFixtures::pdf($this->files), ['account' => 3]);
        self::assertSame('waiting', $r->view($job, $id)['state']);
        self::assertSame(['id' => $id, 'state' => 'done', 'reason' => 'ok'], $r->runNext());
        self::assertSame([], $this->spooled(), 'the file deleted as soon as it was read');
        self::assertNull(self::$db->value('SELECT spool_name FROM ai_read WHERE id = ?', [$id]));
        self::assertNull($r->runNext(), 'read once');
        self::assertCount(1, $f->sent);
        $call = self::$db->one('SELECT * FROM ai_call WHERE read_id = ?', [$id]);
        self::assertSame([(string) $job->staffUserId, 'delivery_upload', 'royal_mail_bill', 'claude-opus-5-5', 'claude-opus-5-5', '15000', '13000', '25600', 'ok', 'ok', '0', null],
            [(string) $call['staff_user_id'], $call['place'], $call['kind'], $call['model'], $call['served_model'], (string) $call['input_tokens'],
                (string) $call['output_tokens'], (string) $call['cost_millipence'], $call['result'], $call['reason'], (string) $call['fallback'], $call['checked']]);
        self::assertSame('msg_01FakeAnswer', $call['request_id']);
        $log = json_encode(self::$db->all('SELECT * FROM ai_call'), JSON_THROW_ON_ERROR);
        foreach ([AiFixtures::KEY, 'Rectification', 'DESCRIPTION', '0706703000', 'made up'] as $never) {
            self::assertStringNotContainsString($never, $log, 'the log holds no content and never the key');
        }
        self::assertSame(['calls' => 1, 'documents' => 1, 'pence' => 26], array_diff_key(AiUsage::month(self::$db), ['from' => 1]), '25.6p, rounded up');
        // the answer: another person never sees it; its person takes it once
        self::assertSame('not_found', self::refused(fn () => $r->view($other, $id))->errorCode);
        self::assertSame('not_found', self::refused(fn () => $r->take($other, $id))->errorCode);
        $a = $r->take($job, $id);
        self::assertSame(['0706703000', '9070000001', 2, '5.08'], [$a['account'], $a['invoice_number'], count($a['lines']), $a['lines'][0]['net']]);
        self::assertSame('ai_taken', self::refused(fn () => $r->take($job, $id))->errorCode);
        self::assertSame(['taken', null], [$r->view($job, $id)['state'], self::$db->value('SELECT answer FROM ai_read WHERE id = ?', [$id])], 'the answer gone once shown');
        $r->checked($id, false, 'bad_file');
        $r->checked($id, true);
        self::assertSame(['refused', 'bad_file'], array_values(self::$db->one('SELECT checked, check_code FROM ai_call WHERE read_id = ?', [$id]) ?? []), 'written once');
    }

    public function testEveryFailureDeletesTheFileAndSaysWhy(): void
    {
        $job = self::person('expenses');
        AiFixtures::on(self::$db);
        $f = new FakeClaude();
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        $cases = [
            [FakeClaude::answer(null, 1200, 0, ['stop' => 'refusal', 'category' => 'cyber']), 'refusal', 'refused', null],
            [FakeClaude::answer('{"readable": true', 1200, 8000, ['stop' => 'max_tokens']), 'max_tokens', 'failed', null],
            [FakeClaude::answer(AiAnswers::invoice(['readable' => false, 'not_readable_reason' => 'not_an_invoice']), 1200, 90), 'not_readable', 'refused',
                'not_an_invoice'],
            [FakeClaude::answer(AiAnswers::invoice(['document_kind' => 'statement']), 1200, 90), 'bad_answer', 'failed', 'the kind of document'],
            [FakeClaude::status(401, 'authentication_error'), 'auth', 'failed', null],
            [FakeClaude::status(529, 'overloaded_error'), 'overloaded', 'failed', null],
        ];
        foreach ($cases as [$answer, $reason, $result, $detail]) {
            $f->push($answer);
            $id = $r->start($job, 'expense_new', AiFixtures::png($this->files), []);
            self::assertSame(['id' => $id, 'state' => 'failed', 'reason' => $reason], $r->runNext(), $reason);
            self::assertSame([], $this->spooled(), "{$reason}: the file deleted");
            $v = $r->view($job, $id);
            self::assertSame([$reason, $detail], [$v['reason'], $v['detail']]);
            self::assertSame([$result, $reason], array_values(self::$db->one('SELECT result, reason FROM ai_call WHERE read_id = ?', [$id]) ?? []));
            self::assertSame('ai_taken', self::refused(fn () => $r->take($job, $id))->errorCode, 'nothing to take');
        }
        self::assertSame('cyber', self::$db->value("SELECT refusal_category FROM ai_call WHERE reason = 'refusal'"));
    }

    public function testAJobMeetsTheSettingsAndThePersonAsTheyAreWhenItIsRead(): void
    {
        $job = self::person('expenses');
        AiFixtures::on(self::$db);
        $f = new FakeClaude();
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        // switched off while it waited: failed, no call made
        $id = $r->start($job, 'expense_new', AiFixtures::pdf($this->files), []);
        self::$db->exec("UPDATE app_setting SET value_json = CAST('false' AS JSON) WHERE setting_key = 'ai.read_uploads'");
        self::assertSame('off', $r->runNext()['reason'] ?? null);
        AiFixtures::on(self::$db);
        // the limit reached meanwhile by another document
        $id = $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'two'), []);
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"0\"' AS JSON) WHERE setting_key = 'ai.monthly_limit_gbp'");
        self::assertSame('limit', $r->runNext()['reason'] ?? null);
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"30\"' AS JSON) WHERE setting_key = 'ai.monthly_limit_gbp'");
        // the person's job taken away meanwhile
        $id = $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'three'), []);
        self::$db->exec('UPDATE staff_role SET revoked_at = NOW(6) WHERE staff_user_id = ?', [$job->staffUserId]);
        self::assertSame('not_allowed', $r->runNext()['reason'] ?? null);
        self::assertSame([], $f->sent, 'no call made');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM ai_call'));
        self::assertSame([], $this->spooled());
        // the model, effort and prices as set now
        self::$db->exec('UPDATE staff_role SET revoked_at = NULL WHERE staff_user_id = ?', [$job->staffUserId]);
        AiFixtures::on(self::$db, [AiSettings::MODEL => '"claude-sonnet-5-5"', AiSettings::EFFORT => '"high"', AiSettings::PRICE_IN => '"1.60"', AiSettings::PRICE_OUT => '"8.00"']);
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 2000, 1000, ['model' => 'claude-sonnet-5-5']));
        $id = $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'four'), []);
        self::assertSame('done', $r->runNext()['state'] ?? null);
        self::assertSame(['claude-sonnet-5-5', 'high'], [$f->sent[0]['body']['model'], $f->sent[0]['body']['output_config']['effort']]);
        self::assertSame(1120, (int) self::$db->value('SELECT cost_millipence FROM ai_call WHERE read_id = ?', [$id]), '2,000 x 1.60 / 10 + 1,000 x 8 / 10');
    }

    public function testTheCleanUpLeavesNothingOlderThanItMayBe(): void
    {
        $job = self::person('expenses');
        AiFixtures::on(self::$db);
        $now = Clock::now();
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };
        $f = new FakeClaude();
        $r = AiFixtures::reader(self::$db, $this->spool, $f, $clock);
        // a job left `reading` by a runner that stopped; a job waiting for more than an hour; an answer not taken for more than an hour
        $stopped = $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'a'), []);
        self::$db->exec("UPDATE ai_read SET state = 'reading', started_at = ? WHERE id = ?", [Clock::db($now), $stopped]);
        $old = $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'b'), []);
        self::$db->exec('UPDATE ai_read SET created_at = ? WHERE id = ?', [Clock::db($now->modify('-61 minutes')), $old]);
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 100, 10));
        $done = $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'c'), []);
        self::$db->exec("UPDATE ai_read SET state = 'waiting' WHERE id IN (?, ?)", [$old, $done]);
        self::$db->exec("UPDATE ai_read SET state = 'failed' WHERE id = ?", [$old]); // held aside while $done is read
        self::assertSame($done, $r->runNext()['id'] ?? null);
        self::$db->exec("UPDATE ai_read SET state = 'waiting' WHERE id = ?", [$old]);
        self::$db->exec('UPDATE ai_read SET finished_at = ? WHERE id = ?', [Clock::db($now->modify('-61 minutes')), $done]);
        // a stray file of a crash (older than an hour), a young one, and a file that is not CW's
        file_put_contents($this->spool . '/' . str_repeat('c', 32), 'stray');
        touch($this->spool . '/' . str_repeat('c', 32), $now->getTimestamp() - 3700);
        file_put_contents($this->spool . '/' . str_repeat('d', 32), 'young');
        file_put_contents($this->spool . '/README', 'not CW\'s');
        touch($this->spool . '/README', $now->getTimestamp() - 9999);
        // without the runner's lock a `reading` job is left alone (a runner may be reading it)
        $s = $r->sweep(false);
        self::assertSame(['stopped' => 0, 'too_old' => 1, 'expired' => 1], array_intersect_key($s, ['stopped' => 1, 'too_old' => 1, 'expired' => 1]));
        self::assertSame('reading', $r->view($job, $stopped)['state']);
        self::assertSame(1, $r->sweep(true)['stopped'], 'with the lock held, no runner is reading it: it stopped');
        self::assertSame(['failed', 'stopped'], [$r->view($job, $stopped)['state'], $r->view($job, $stopped)['reason']]);
        self::assertSame(['failed', 'too_old'], [$r->view($job, $old)['state'], $r->view($job, $old)['reason']]);
        self::assertSame(['expired', null], [$r->view($job, $done)['state'], self::$db->value('SELECT answer FROM ai_read WHERE id = ?', [$done])]);
        $left = $this->spooled();
        sort($left);
        self::assertSame(['README', str_repeat('d', 32)], $left, 'every file of a finished job and the stray one gone; the young one and what is not CW\'s stay');
        // a day later the job rows go; the log stays
        $now = $now->modify('+25 hours');
        self::assertSame(3, $r->sweep(true)['rows']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM ai_read'));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM ai_call'));
    }

    public function testTheMonthIsTheUkMonth(): void
    {
        $job = self::person('expenses');
        $at = static fn (string $utc, int $mp) => self::$db->exec("INSERT INTO ai_call (read_id, created_at, staff_user_id, place, kind, model, media_type, file_bytes, "
            . "input_tokens, output_tokens, cost_millipence, result, reason, seconds) VALUES (NULL, ?, ?, 'expense_new', 'expense_invoice', 'm', 'image/png', 1, 1, 1, ?, "
            . "?, 'x', 1)", [$utc, $job->staffUserId, $mp, $mp > 1000 ? 'ok' : 'failed']);
        // July in the UK (BST) began at 23:00 UTC on 30 June: 23:30 UTC that day is July's, 22:59 UTC is June's
        $at('2026-06-30 23:30:00', 5000);
        $at('2026-06-30 22:59:00', 7000);
        $at('2026-07-15 10:00:00', 500);
        self::assertSame(['calls' => 2, 'documents' => 1, 'pence' => 6], array_diff_key(AiUsage::month(self::$db, new \DateTimeImmutable('2026-07-20 12:00:00',
            new \DateTimeZone('UTC'))), ['from' => 1]), 'July in the UK began at 23:00 UTC on 30 June');
    }
}
