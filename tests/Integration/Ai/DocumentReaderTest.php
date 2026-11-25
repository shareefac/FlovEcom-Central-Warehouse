<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ai;

use CW\Ai\AiSettings;
use CW\Ai\AiUsage;
use CW\Ai\Claude;
use CW\Ai\DocumentReader;
use CW\Ai\ExpenseInvoiceKind;
use CW\Ai\RoyalMailBillKind;
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
 * it is read: a Royal Mail bill's is deleted right after, read or not; an invoice's is kept for its expense's Save (AI15: attached once, by its
 * person, checked again; else deleted after an hour); every call is logged without content (tokens, cost, result, reason), its row written
 * BEFORE the request at the most it may cost (AI16: a reader that dies mid-call still counts); the answer is taken once by its person; the
 * clean-up leaves no file, answer or job older than it may be.
 */
final class DocumentReaderTest extends IntegrationTestCase
{
    /** Every upload place (the screens pass their own: M5); a list, so a class without the places fails its tests, not the run. */
    private const ALL = ['delivery_upload', 'delivery_replace', 'expense_new', 'expense_change'];

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
        $context = json_decode((string) $row['context'], true);
        ksort($context);
        self::assertSame(['account' => 7, 'note' => str_repeat('n', 500)], $context, 'ids and typed text only (MySQL keeps a JSON object\'s keys in its own order)');
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
        $seen = null;
        $f->onSend = static function () use (&$seen): void {
            $seen = self::$db->one('SELECT result, reason, cost_millipence, max_millipence FROM ai_call');
        };
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        self::assertNull($r->runNext(), 'nothing waiting');
        $pdf = AiFixtures::pdf($this->files);
        $most = DocumentReader::most(new RoyalMailBillKind(), (string) file_get_contents($pdf), 'application/pdf', '3.20', '16.00');
        $id = $r->start($job, 'delivery_upload', $pdf, ['account' => 3]);
        self::assertSame('waiting', $r->view($job, $id, self::ALL)['state']);
        self::assertSame(['id' => $id, 'state' => 'done', 'reason' => 'ok'], $r->runNext());
        self::assertSame(['started', 'started', (string) $most, (string) $most], array_map('strval', array_values($seen ?? [])),
            'AI16: the call\'s row written before the request, at the most it may cost');
        self::assertSame((string) $most, (string) self::$db->value('SELECT max_millipence FROM ai_call WHERE read_id = ?', [$id]), 'that most kept');
        self::assertSame([], $this->spooled(), 'a Royal Mail bill\'s file deleted as soon as it was read');
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
        self::assertSame(['calls' => 1, 'documents' => 1, 'pence' => 26], array_diff_key(AiUsage::month(self::$db), ['from' => 1, 'millipence' => 1]), '25.6p, rounded up');
        // the answer: another person never sees it; its person takes it once
        self::assertSame('not_found', self::refused(fn () => $r->view($other, $id, self::ALL))->errorCode);
        self::assertSame('not_found', self::refused(fn () => $r->take($other, $id, self::ALL))->errorCode);
        $a = $r->take($job, $id, self::ALL);
        self::assertSame(['0706703000', '9070000001', 2, '5.08'], [$a['account'], $a['invoice_number'], count($a['lines']), $a['lines'][0]['net']]);
        self::assertSame('ai_taken', self::refused(fn () => $r->take($job, $id, self::ALL))->errorCode);
        // the person's own check busy just now (the bills' lock): the answer given back, taken again, never read a second time
        $r->giveBack($other, $id, $a);
        self::assertSame('taken', $r->view($job, $id, self::ALL)['state'], 'another person never gives a reading back');
        $r->giveBack($job, $id, $a);
        self::assertSame('done', $r->view($job, $id, self::ALL)['state']);
        self::assertSame($a, $r->take($job, $id, self::ALL));
        self::assertCount(1, $f->sent);
        self::assertSame(['taken', null], [$r->view($job, $id, self::ALL)['state'], self::$db->value('SELECT answer FROM ai_read WHERE id = ?', [$id])], 'the answer gone once shown');
        $r->checked($id, false, 'bad_file');
        $r->checked($id, true);
        self::assertSame(['refused', 'bad_file'], array_values(self::$db->one('SELECT checked, check_code FROM ai_call WHERE read_id = ?', [$id]) ?? []), 'written once');
    }

    /** Checklist 10: one effect per form: the same file sent again while its reading waits, goes or is not shown yet is that reading. */
    public function testTheSameFileSentAgainIsTheSameReading(): void
    {
        $job = self::person('expenses');
        AiFixtures::on(self::$db);
        $f = new FakeClaude();
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        $pdf = AiFixtures::pdf($this->files);
        $id = $r->start($job, 'expense_new', $pdf, ['shop' => '1']);
        self::assertSame($id, $r->start($job, 'expense_new', $pdf, ['shop' => '1']), 'a double click: the same reading');
        self::assertCount(1, $this->spooled(), 'one file kept');
        $other = $r->start($job, 'expense_new', $pdf, ['shop' => '2']);
        self::assertNotSame($id, $other, 'the same file with other typed fields is another reading');
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 100, 10), FakeClaude::answer(AiAnswers::invoice(), 100, 10));
        $r->runNext();
        $r->runNext();
        self::assertSame($id, $r->start($job, 'expense_new', $pdf, ['shop' => '1']), 'read, not shown yet: the same reading (no second call)');
        $r->take($job, $id, self::ALL);
        $again = $r->start($job, 'expense_new', $pdf, ['shop' => '1']);
        self::assertNotSame($id, $again, 'once shown, sending it again reads it again');
        self::assertCount(2, $f->sent);
    }

    /**
     * A read that breaks in a way no reason code names ends the job at once (never left `reading`), the class said; its call counted at its
     * most (AI16: what it used is not known); the invoice kept for its Save (AI15).
     */
    public function testAnUnexpectedFailureEndsTheJob(): void
    {
        $job = self::person('expenses');
        AiFixtures::on(self::$db);
        $r = new \CW\Ai\DocumentReader(self::$db, $this->spool, static function (): never {
            throw new \Error('made up');
        });
        $pdf = AiFixtures::pdf($this->files);
        $most = DocumentReader::most(new ExpenseInvoiceKind(), (string) file_get_contents($pdf), 'application/pdf', '3.20', '16.00');
        $id = $r->start($job, 'expense_new', $pdf, []);
        self::assertSame(['id' => $id, 'state' => 'failed', 'reason' => 'error'], $r->runNext());
        self::assertSame('Error', $r->view($job, $id, self::ALL)['detail']);
        self::assertSame(\CW\Ui\Words::say('AIREAD', 'failed', \CW\Ui\Words::AIREAD['why_error']) . ' ' . \CW\Ui\Words::AIREAD['next_expense_invoice'],
            \CW\Ui\AiReadWords::failure('expense_invoice', 'error', 'Error'), 'said in words, never the class');
        self::assertSame(['failed', 'error', (string) $most, (string) $most], array_map('strval', array_values(self::$db->one('SELECT result, reason, cost_millipence, '
            . 'max_millipence FROM ai_call WHERE read_id = ?', [$id]) ?? [])), 'counted at its most');
        self::assertCount(1, $this->spooled(), 'the invoice kept for its Save');
        self::assertTrue($r->kept($job, $id));
        // a stream that breaks while it is read (Guzzle's read timeout): a connection failure, said as busy; what it used is not known: its most
        $f = (new FakeClaude())->push(new \RuntimeException('Read timed out (made up)'));
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        $id = $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'other'), []);
        self::assertSame('connection', $r->runNext()['reason'] ?? null);
        self::assertSame(['failed', (string) $most], array_map('strval', array_values(self::$db->one('SELECT result, cost_millipence FROM ai_call WHERE read_id = ?', [$id]) ?? [])));
    }

    /**
     * AI16: a call's row is written BEFORE the request, at the most it may cost (its words, its pages, the kind's max_tokens), and the month
     * counts it so while it goes; its answer's usage replaces it; a call refused before Claude worked costs nothing. A reader that dies
     * mid-call leaves the row `started`: the next run (the runner's lock held) closes it as `crashed` at that most, and any row still
     * `started` after an hour is closed so too (no lock needed: no call lasts an hour).
     */
    public function testACallCountsAtItsMostUntilItsAnswerAndACrashStillCounts(): void
    {
        $job = self::person('expenses');
        AiFixtures::on(self::$db);
        $f = new FakeClaude();
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        $png = AiFixtures::png($this->files);
        $most = DocumentReader::most(new ExpenseInvoiceKind(), (string) file_get_contents($png), 'image/png', '3.20', '16.00');
        $month = null;
        $f->onSend = static function () use (&$month): void {
            $month = AiUsage::month(self::$db)['pence'];
        };
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 2_100, 640));
        $id = $r->start($job, 'expense_new', $png, []);
        self::assertSame('done', $r->runNext()['state'] ?? null);
        self::assertSame(AiUsage::pence($most), $month, 'while it went, the month counted its most');
        self::assertSame(['ok', '1696', (string) $most], array_map('strval', array_values(self::$db->one('SELECT result, cost_millipence, max_millipence FROM ai_call '
            . 'WHERE read_id = ?', [$id]) ?? [])), 'its answer said what it used');
        // refused before Claude worked (the key, the account): nothing
        $f->onSend = null;
        $f->push(FakeClaude::status(401, 'authentication_error'));
        $id = $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'auth'), []);
        self::assertSame('auth', $r->runNext()['reason'] ?? null);
        self::assertSame(['failed', '0'], array_map('strval', array_values(self::$db->one('SELECT result, cost_millipence FROM ai_call WHERE read_id = ?', [$id]) ?? [])));

        // the reader died mid-call: its job `reading`, its call `started`
        $dead = $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'dead'), []);
        self::$db->exec("UPDATE ai_read SET state = 'reading', started_at = NOW(6) WHERE id = ?", [$dead]);
        self::$db->exec("INSERT INTO ai_call (read_id, created_at, staff_user_id, place, kind, model, media_type, file_bytes, cost_millipence, max_millipence, result, reason) "
            . "VALUES (?, NOW(6), ?, 'expense_new', 'expense_invoice', 'claude-opus-5-5', 'application/pdf', 9, 120000, 120000, 'started', 'started')", [$dead, $job->staffUserId]);
        // an older one whose job is gone, started more than an hour ago
        $old = self::$db->insert("INSERT INTO ai_call (read_id, created_at, staff_user_id, place, kind, model, media_type, file_bytes, cost_millipence, max_millipence, result, "
            . "reason) VALUES (NULL, ?, ?, 'delivery_upload', 'royal_mail_bill', 'claude-opus-5-5', 'application/pdf', 9, 90000, 90000, 'started', 'started')",
            [Clock::db(Clock::now()->modify('-61 minutes')), $job->staffUserId]);
        $s = $r->sweep(false);
        self::assertSame([0, 1], [$s['stopped'], $s['crashed']], 'without the lock: only the row older than an hour');
        self::assertSame('started', self::$db->value('SELECT result FROM ai_call WHERE read_id = ?', [$dead]), 'a runner may still be reading it');
        $s = $r->sweep(true);
        self::assertSame([1, 1], [$s['stopped'], $s['crashed']], 'with the lock held: no runner is reading it');
        self::assertSame([['crashed', 'stopped', '120000'], ['crashed', 'stopped', '90000']], array_map(static fn (array $c): array => array_map('strval', array_values($c)),
            self::$db->all('SELECT result, reason, cost_millipence FROM ai_call WHERE id = ? OR read_id = ? ORDER BY cost_millipence DESC', [$old, $dead])), 'closed at their most');
        self::assertSame(['failed', 'stopped'], [$r->view($job, $dead, self::ALL)['state'], $r->view($job, $dead, self::ALL)['reason']]);
        self::assertSame(AiUsage::pence(1_696 + 120_000 + 90_000), AiUsage::month(self::$db)['pence'], 'the month counts them');
        // the limit counts them: £2.12 used, a limit of £2 starts nothing
        AiFixtures::on(self::$db, [AiSettings::LIMIT => '"2"']);
        self::assertSame('ai_limit', self::refused(fn () => $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'over'), []))->errorCode);
    }

    /** Every failure says why; an invoice is kept for its Save even so (AI15), a Royal Mail bill's file deleted at once. */
    public function testEveryFailureSaysWhyAndKeepsOnlyAnInvoice(): void
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
        foreach ($cases as $n => [$answer, $reason, $result, $detail]) {
            $f->push($answer);
            $id = $r->start($job, 'expense_new', AiFixtures::png($this->files), ['case' => $n]);
            self::assertSame(['id' => $id, 'state' => 'failed', 'reason' => $reason], $r->runNext(), $reason);
            self::assertCount($n + 1, $this->spooled(), "{$reason}: the invoice kept for its Save");
            self::assertTrue($r->kept($job, $id), $reason);
            $v = $r->view($job, $id, self::ALL);
            self::assertSame([$reason, $detail], [$v['reason'], $v['detail']]);
            self::assertSame([$result, $reason], array_values(self::$db->one('SELECT result, reason FROM ai_call WHERE read_id = ?', [$id]) ?? []));
            self::assertSame('ai_taken', self::refused(fn () => $r->take($job, $id, self::ALL))->errorCode, 'nothing to take');
        }
        self::assertSame('cyber', self::$db->value("SELECT refusal_category FROM ai_call WHERE reason = 'refusal'"));
        self::assertSame(['384', '0', '0'], array_map('strval', self::$db->column("SELECT cost_millipence FROM ai_call WHERE reason IN ('refusal', 'auth', 'overloaded') "
            . 'ORDER BY id')), 'what the answer said it used (1,200 in); nothing when Claude did not work');
        // a Royal Mail bill that fails: its file deleted at once
        $bills = self::person('delivery_bills', 'ai2');
        $f->push(FakeClaude::status(401, 'authentication_error'));
        $id = $r->start($bills, 'delivery_upload', AiFixtures::pdf($this->files), []);
        self::assertSame('auth', $r->runNext()['reason'] ?? null);
        self::assertCount(count($cases), $this->spooled(), 'the bill\'s file gone');
        self::assertFalse($r->kept($bills, $id));
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
        self::assertCount(2, $this->spooled(), 'switched off or at the limit: the invoice kept for its Save; the person\'s job taken away: deleted');
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
        $stoppedFile = (string) self::$db->value('SELECT spool_name FROM ai_read WHERE id = ?', [$stopped]);
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
        self::assertSame('reading', $r->view($job, $stopped, self::ALL)['state']);
        self::assertSame(1, $r->sweep(true)['stopped'], 'with the lock held, no runner is reading it: it stopped');
        self::assertSame(['failed', 'stopped'], [$r->view($job, $stopped, self::ALL)['state'], $r->view($job, $stopped, self::ALL)['reason']]);
        self::assertSame(['failed', 'too_old'], [$r->view($job, $old, self::ALL)['state'], $r->view($job, $old, self::ALL)['reason']]);
        self::assertSame(['expired', null], [$r->view($job, $done, self::ALL)['state'], self::$db->value('SELECT answer FROM ai_read WHERE id = ?', [$done])]);
        $left = $this->spooled();
        sort($left);
        $want = ['README', str_repeat('d', 32), $stoppedFile];
        sort($want);
        self::assertSame($want, $left, 'the files of the job too old and of the answer expired gone, and the stray one; the young one, what is not CW\'s and the '
            . 'stopped invoice (kept an hour for its Save) stay');
        // a day later the job rows are emptied of what the page had typed (M10, the re-check of ca8987b: the app login removes no row), the last
        // files go; the log stays
        $now = $now->modify('+25 hours');
        self::assertSame(3, $r->sweep(true)['rows']);
        self::assertSame(['README'], $this->spooled());
        self::assertSame([3, 0, 0], [(int) self::$db->value('SELECT COUNT(*) FROM ai_read'), (int) self::$db->value('SELECT COUNT(*) FROM ai_read WHERE JSON_LENGTH(context) > 0 '
            . 'OR answer IS NOT NULL'), $r->sweep(true)['rows']], 'kept, empty; emptied once');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM ai_call'));
    }

    /**
     * AI15: an invoice Claude read is kept for its expense's Save: only its person attaches it, from the place it was read for (the add form;
     * a Change: that expense), checked again (its size and sha256 as sent), once, inside the expense's transaction (a refused Save uses
     * nothing); an hour after its page took it with no Save, deleted. An expense keeps a PDF, a JPEG or a PNG: another photo is refused before
     * it is read.
     */
    public function testAnInvoiceIsKeptForItsSaveOnlyForItsPersonOnceAndForAnHour(): void
    {
        $job = self::person('expenses');
        $other = self::person('expenses', 'ai4');
        AiFixtures::on(self::$db);
        $now = Clock::now();
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };
        $f = new FakeClaude();
        $r = AiFixtures::reader(self::$db, $this->spool, $f, $clock);
        $use = static fn (Caller $who, int $id, string $place, ?int $expense): array => self::$db->transaction(static fn (): array => $r->useFile($who, $id, $place, $expense));
        $pdf = AiFixtures::pdf($this->files, 'kept');
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 2_100, 640));
        $id = $r->start($job, 'expense_new', $pdf, ['shop' => '1']);
        self::assertSame('done', $r->runNext()['state'] ?? null);
        self::assertCount(1, $this->spooled(), 'read, and kept for its Save');
        self::assertTrue($r->kept($job, $id));
        self::assertFalse($r->kept($other, $id), 'for its person only');
        $r->take($job, $id, self::ALL);
        $now = $now->modify('+50 minutes');
        $r->sweep(true);
        self::assertCount(1, $this->spooled(), 'kept an hour from when its page took it');
        foreach ([[$other, 'expense_new', null], [$job, 'expense_change', 7]] as [$who, $place, $expense]) {
            self::assertSame('ai_file_gone', self::refused(fn () => $use($who, $id, $place, $expense))->errorCode, 'another person, or another place');
        }
        $e = self::refused(fn () => self::$db->transaction(static function () use ($r, $job, $id): void {
            $r->useFile($job, $id, 'expense_new', null);
            throw new CwException('same_invoice', 'made up', 409);
        }));
        self::assertSame('same_invoice', $e->errorCode);
        self::assertNull(self::$db->value('SELECT file_used_at FROM ai_read WHERE id = ?', [$id]), 'a refused Save uses nothing');
        $file = $use($job, $id, 'expense_new', null);
        self::assertSame(hash_file('sha256', $pdf), hash_file('sha256', $file['path']), 'the file as it was sent');
        // High 3 (the re-check of ca8987b): Save stores the VERIFIED bytes: a private copy (0600, outside the spool), whatever the spool holds after
        self::assertStringStartsNotWith($this->spool, $file['path']);
        self::assertSame(['0600', hash_file('sha256', $pdf)], [substr(sprintf('%o', fileperms($file['path'])), -4), $file['sha256']]);
        file_put_contents($this->spool . '/' . (string) self::$db->value('SELECT spool_name FROM ai_read WHERE id = ?', [$id]), "%PDF-1.4\n% swapped after the check\n");
        self::assertSame(hash_file('sha256', $pdf), hash_file('sha256', $file['path']), 'the copy is what was checked');
        unlink($file['path']);
        self::assertSame('ai_file_gone', self::refused(fn () => $use($job, $id, 'expense_new', null))->errorCode, 'attached once');
        self::assertFalse($r->kept($job, $id));
        $r->dropUsed($id);
        self::assertSame([], $this->spooled(), 'attached: deleted from the spool');
        // a file changed in the spool is never attached
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 2_100, 640), FakeClaude::answer(AiAnswers::invoice(), 2_100, 640));
        $changed = $r->start($job, 'expense_new', AiFixtures::pdf($this->files, 'second'), []);
        $r->runNext();
        file_put_contents($this->spool . '/' . (string) self::$db->value('SELECT spool_name FROM ai_read WHERE id = ?', [$changed]), "%PDF-1.4\n% another\n");
        self::assertSame('ai_file_gone', self::refused(fn () => $use($job, $changed, 'expense_new', null))->errorCode);
        // a Change's invoice: for that expense only
        $change = $r->start($job, 'expense_change', AiFixtures::png($this->files), ['expense' => 41, 'seen' => 2]);
        $r->runNext();
        self::assertSame('ai_file_gone', self::refused(fn () => $use($job, $change, 'expense_change', 42))->errorCode);
        self::assertSame('ai_file_gone', self::refused(fn () => $use($job, $change, 'expense_new', null))->errorCode);
        // an hour with no Save: deleted, no longer kept
        $now = $now->modify('+61 minutes');
        $r->sweep(true);
        self::assertSame([], $this->spooled());
        self::assertFalse($r->kept($job, $change));
        self::assertSame('ai_file_gone', self::refused(fn () => $use($job, $change, 'expense_change', 41))->errorCode);
        // an expense keeps a PDF, a JPEG or a PNG: a GIF is refused before it is read
        self::assertSame('ai_type_expense', self::refused(fn () => $r->start($job, 'expense_new', AiFixtures::gif($this->files), []))->errorCode);
        self::assertNotSame('', (string) \CW\Ui\AiReadWords::refusal(new CwException('ai_type_expense', 'x', 415)));
    }

    /**
     * H1 (Codex final of 86aebbd): the reader sends only its OWN spool file: a name of 32 hex, inside the spool, a regular file that is not a
     * link, of the size and sha256 the job recorded; anything else ends the job (file_gone) with no call, and nothing outside the spool is
     * ever read or deleted.
     */
    public function testTheReaderSendsAndDeletesOnlyItsOwnSpoolFile(): void
    {
        $bills = self::person('delivery_bills');
        AiFixtures::on(self::$db);
        $f = new FakeClaude();
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        $outside = AiFixtures::pdf($this->files, 'outside', 'o.pdf');
        $keep = file_get_contents($outside);
        $cases = [
            'a path, not a spool name' => static fn (string $name): string => '../' . basename(dirname($outside)) . '/o.pdf',
            'a link to a file outside' => function (string $name) use ($outside): string {
                unlink($this->spool . '/' . $name);
                symlink($outside, $this->spool . '/' . $name);
                return $name;
            },
            'the bytes changed (same size)' => function (string $name): string {
                $p = $this->spool . '/' . $name;
                file_put_contents($p, strrev((string) file_get_contents($p)));
                return $name;
            },
        ];
        foreach ($cases as $what => $tamper) {
            $id = $r->start($bills, 'delivery_upload', $outside, ['case' => $what]);
            $name = (string) self::$db->value('SELECT spool_name FROM ai_read WHERE id = ?', [$id]);
            self::$db->exec('UPDATE ai_read SET spool_name = ? WHERE id = ?', [$tamper($name), $id]);
            self::assertSame(['id' => $id, 'state' => 'failed', 'reason' => 'file_gone'], $r->runNext(), $what);
            self::assertSame([], $f->sent, "{$what}: nothing sent");
            self::assertSame($keep, @file_get_contents($outside), "{$what}: the file outside the spool untouched");
            $r->sweep(true);
            self::assertSame($keep, @file_get_contents($outside), "{$what}: the clean-up never deletes outside the spool");
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM ai_call'));
    }

    /**
     * M3 (Codex final of 86aebbd): the month's limit is a HARD cap: a call starts only when the month's spend plus its most stays within the
     * limit (the runner checks, under its lock); prices must be above 0 (a price of 0 would count paid readings as nothing).
     */
    public function testTheLimitIsAHardCapAndPricesAreNeverZero(): void
    {
        $job = self::person('expenses');
        AiFixtures::on(self::$db, [AiSettings::LIMIT => '"0.30"']);
        $f = new FakeClaude();
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        $png = AiFixtures::png($this->files);
        $most = DocumentReader::most(new ExpenseInvoiceKind(), (string) file_get_contents($png), 'image/png', '3.20', '16.00');
        self::assertGreaterThan(3_000 * 10, $most, 'a photo\'s most is above 30p');
        $id = $r->start($job, 'expense_new', $png, []);
        self::assertSame(['id' => $id, 'state' => 'failed', 'reason' => 'limit'], $r->runNext(), '0p + its most > 30p: not sent');
        self::assertSame([], $f->sent);
        AiFixtures::on(self::$db, [AiSettings::LIMIT => '"30"']);
        foreach ([AiSettings::PRICE_IN, AiSettings::PRICE_OUT] as $k) {
            try {
                (new \CW\Settings(self::$db))->set(Caller::system('test'), $k, '0', 'made-up test');
                self::fail("{$k} 0 refused");
            } catch (CwException $e) {
                self::assertSame('bad_value', $e->errorCode, $k);
            }
        }
        // a price of 0 in the database all the same (by hand): reading is off, said, nothing sent
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"0\"' AS JSON) WHERE setting_key = ?", [AiSettings::PRICE_OUT]);
        self::assertSame(['ai_off', 'off_price'], (static function () use ($r, $job, $png): array {
            try {
                $r->start($job, 'expense_new', $png, ['again' => 1]);
            } catch (CwException $e) {
                return [$e->errorCode, $e->getMessage()];
            }
            return ['started', ''];
        })());
        self::assertNotSame('', \CW\Ui\AiReadWords::refusal(new CwException('ai_off', 'off_price', 409)));
    }

    /**
     * M5, M6 (Codex final of 86aebbd): a reading is seen and taken only where it was made (a Royal Mail bill's never at the Expenses page, which
     * would consume it), and an answer past its hour is never taken (expired then, under the row lock), whatever the clean-up's turn.
     */
    public function testAReadingIsTakenOnlyWhereItWasMadeAndWithinItsHour(): void
    {
        $both = self::person('delivery_bills');
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'expenses')", [$both->staffUserId]);
        AiFixtures::on(self::$db);
        $now = Clock::now();
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };
        $f = (new FakeClaude())->push(FakeClaude::answer(AiAnswers::royalMail([RmBill::line('2026-11-09', 'TPL', 2, 508, '943')]), 100, 10),
            FakeClaude::answer(AiAnswers::invoice(), 100, 10));
        $r = AiFixtures::reader(self::$db, $this->spool, $f, $clock);
        $bill = $r->start($both, 'delivery_upload', AiFixtures::pdf($this->files, 'bill'), []);
        $r->runNext();
        $expenses = DocumentReader::EXPENSE_PLACES;
        self::assertSame('not_found', self::refused(fn () => $r->view($both, $bill, $expenses))->errorCode);
        self::assertSame('not_found', self::refused(fn () => $r->take($both, $bill, $expenses))->errorCode);
        self::assertSame('done', $r->view($both, $bill, DocumentReader::BILL_PLACES)['state'], 'not consumed');
        self::assertSame('9070000001', $r->take($both, $bill, DocumentReader::BILL_PLACES)['invoice_number']);
        // an answer finished 61 minutes ago, the clean-up not run yet: expired now, never taken
        $inv = $r->start($both, 'expense_new', AiFixtures::pdf($this->files, 'inv'), []);
        $r->runNext();
        $now = $now->modify('+61 minutes');
        self::assertSame('ai_taken', self::refused(fn () => $r->take($both, $inv, $expenses))->errorCode);
        self::assertSame(['expired', null], [$r->view($both, $inv, $expenses)['state'], self::$db->value('SELECT answer FROM ai_read WHERE id = ?', [$inv])]);
    }

    /** M5 (the re-check of ca8987b): a job whose kind is not its place's (a bill's reading recorded at an Expenses place) is never shown or taken. */
    public function testAJobOfAnotherKindThanItsPlaceIsNeverShown(): void
    {
        $both = self::person('expenses');
        $id = self::$db->insert("INSERT INTO ai_read (staff_user_id, place, kind, context, media_type, file_bytes, file_sha256, state, answer, created_at, finished_at) "
            . "VALUES (?, 'expense_new', 'royal_mail_bill', '{}', 'application/pdf', 9, ?, 'done', '{}', NOW(6), NOW(6))", [$both->staffUserId, str_repeat('0', 64)]);
        $r = AiFixtures::reader(self::$db, $this->spool, new FakeClaude());
        self::assertSame('not_found', self::refused(fn () => $r->view($both, $id, DocumentReader::EXPENSE_PLACES))->errorCode);
        self::assertSame('not_found', self::refused(fn () => $r->take($both, $id, DocumentReader::EXPENSE_PLACES))->errorCode);
        self::assertSame('done', self::$db->value('SELECT state FROM ai_read WHERE id = ?', [$id]), 'not consumed');
    }

    /**
     * M3 (the re-check of ca8987b): the cap compares the month's spend and the call's most to the thousandth of a penny: exactly at the limit
     * a call starts, a thousandth over it does not.
     */
    public function testTheCapIsExactToTheThousandthOfAPenny(): void
    {
        $job = self::person('expenses');
        AiFixtures::on(self::$db);
        $f = new FakeClaude();
        $r = AiFixtures::reader(self::$db, $this->spool, $f);
        $png = AiFixtures::png($this->files);
        $most = DocumentReader::most(new ExpenseInvoiceKind(), (string) file_get_contents($png), 'image/png', '3.20', '16.00');
        $spent = static fn (int $mp) => self::$db->exec("INSERT INTO ai_call (read_id, created_at, staff_user_id, place, kind, model, media_type, file_bytes, "
            . "cost_millipence, max_millipence, result, reason) VALUES (NULL, NOW(6), ?, 'expense_new', 'expense_invoice', 'm', 'image/png', 1, ?, ?, 'ok', 'ok')",
            [$job->staffUserId, $mp, $mp]);
        $spent(3_000_000 - $most + 1);
        $id = $r->start($job, 'expense_new', $png, ['n' => 1]);
        self::assertSame(['id' => $id, 'state' => 'failed', 'reason' => 'limit'], $r->runNext(), 'a thousandth of a penny over: not sent');
        self::$db->exec('UPDATE ai_call SET cost_millipence = cost_millipence - 1, max_millipence = max_millipence - 1');
        $f->push(FakeClaude::answer(AiAnswers::invoice(), 100, 10));
        $id = $r->start($job, 'expense_new', $png, ['n' => 2]);
        self::assertSame('done', $r->runNext()['state'] ?? null, 'exactly at the limit with its most: sent');
        self::assertCount(1, $f->sent);
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
            new \DateTimeZone('UTC'))), ['from' => 1, 'millipence' => 1]), 'July in the UK began at 23:00 UTC on 30 June');
    }
}
