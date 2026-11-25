<?php

declare(strict_types=1);

namespace CW\Ai;

use CW\Caller;
use CW\Clock;
use CW\Config;
use CW\CwException;
use CW\Db;
use CW\Expenses\ExpenseAccess;
use CW\Profit\DeliveryBills;
use CW\Ui\UiRequest;

/**
 * "Claude reads the upload" (CW-1182 phase 1; docs/decisions.md AI1, AI3, AI9-AI11, AI15-AI17): one service for every upload place. A person drops a PDF
 * or a photo where they upload today; start() checks they may upload there (the place's own permission, re-read now), that reading is on and
 * within the month's limit, and what the file is (sniffed from its bytes); it keeps the file in the spool folder and makes a job (ai_read,
 * `waiting`). The runner (bin/ai_read.php, every minute, never a web worker: a five-page bill takes minutes) takes the jobs one at a time
 * (runNext()): Claude reads the file; a Royal Mail bill's file is deleted right after (read or not), an invoice's is kept for its expense's
 * Save (AI15: useFile(), its person, once, checked again; else an hour after its page took the reading); CW checks the answer's form (the
 * kind's shape()) and keeps it on the job; each call is logged without any content (ai_call: when, who, place, kind, model, tokens, cost,
 * result, seconds), its row written BEFORE the request at the most it may cost (AI16: a reader that dies mid-call still counts against the
 * month's limit; the next run, or an hour, closes it as crashed). The person's page, which looks again every few seconds (view()), takes the
 * answer once (take()) and runs the place's own checks: a Royal Mail bill goes through RoyalMailBills::check (the check page, then Store as
 * today), an expense fills its form (Save as today, the invoice attached). A person always stores; Claude never does. Each run of the reader
 * is recorded on its one row (AI17: runBegins(), runEnds()); health() is what bin/health_alert.php says of it.
 *
 * Nothing is kept longer than it is needed: the file until it is read or saved (and never more than an hour after: sweep(), every minute in
 * the runner, whatever the switch), the answer until it is shown (and never more than an hour), the job row's content a day (then emptied
 * in place: M10); the log row stays (no content).
 */
final class DocumentReader
{
    /** The upload places => the kind of document they take. */
    public const PLACES = ['delivery_upload' => RoyalMailBillKind::NAME, 'delivery_replace' => RoyalMailBillKind::NAME, 'expense_new' => ExpenseInvoiceKind::NAME,
        'expense_change' => ExpenseInvoiceKind::NAME];
    /** What Claude reads: a PDF, or a photo the API takes (a HEIC photo from an iPhone is refused in words: save it as JPEG). */
    public const MEDIA = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    public const HEIC = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];
    /** The largest file read: the upload's own limit (10 MiB; base64 of it stays under the API's 32 MB). */
    public const MAX_BYTES = UiRequest::MAX_UPLOAD_BYTES;
    /** The spool folder when app.env names none (ai_spool_dir / CW_AI_SPOOL_DIR): made by the go-live kit, www-data's, 0700. */
    public const DEFAULT_SPOOL = '/var/lib/cw/ai-read';
    /** A file, or an answer not shown, is never kept longer than this (minutes): an invoice kept for its Save, from when its page took it. */
    public const KEEP_MINUTES = 60;
    /** The places of a kind (M5: a reading is seen and taken only where it was made). */
    public const BILL_PLACES = ['delivery_upload', 'delivery_replace'];
    /** The Expenses places: their file is kept for the person's Save (AI15: it becomes the expense's own file), and what such a file may be. */
    public const EXPENSE_PLACES = ['expense_new', 'expense_change'];
    public const EXPENSE_MEDIA = ['application/pdf', 'image/jpeg', 'image/png'];
    /** Why a job ends whose kept file goes even so: its person may no longer use it, it waited too long, it was gone. */
    private const DROPS_FILE = ['not_allowed', 'too_old', 'file_gone'];
    /** A call still `started` after this many hours was left by a reader that stopped (no call lasts that long): crashed (AI16). */
    public const STARTED_HOURS = 1;
    /** A document waiting longer than this (minutes) is a health alert while reading is on (AI17). */
    public const WAIT_ALERT_MINUTES = 10;
    /** A finished job's row keeps its content this long (hours), then is emptied in place (M10: never removed); its log row stays. */
    public const KEEP_ROW_HOURS = 24;
    /** The form fields a job keeps of what the person had typed (an expense form read over what was typed): their most characters each. */
    private const CONTEXT_TEXT_MAX = 500;

    private readonly string $spool;
    /** @var \Closure(AiSettings): Claude */
    private readonly \Closure $claude;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param string|null $spool the spool folder (null: app.env ai_spool_dir, CW_AI_SPOOL_DIR, else DEFAULT_SPOOL)
     * @param (\Closure(AiSettings): Claude)|null $claude how the runner reaches Claude (null: the real API with the settings' key, model, effort)
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(private readonly Db $db, ?string $spool = null, ?\Closure $claude = null, ?\Closure $clock = null)
    {
        $this->spool = rtrim($spool ?? self::spoolDir(), '/');
        $this->claude = $claude ?? static fn (AiSettings $s): Claude => new Claude($s->key, $s->model, $s->effort);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /** The spool folder the configuration names (CW_AI_SPOOL_DIR, app.env ai_spool_dir), else DEFAULT_SPOOL. */
    public static function spoolDir(): string
    {
        $dir = getenv('CW_AI_SPOOL_DIR');
        if (!is_string($dir) || $dir === '') {
            try {
                $dir = Config::loadApp()->get('ai_spool_dir'); // app.env only: the web's workers cannot read db.env
            } catch (\Throwable) {
                $dir = null;
            }
        }
        return is_string($dir) && $dir !== '' ? $dir : self::DEFAULT_SPOOL;
    }

    /**
     * The most a call may cost (AI16, M2), in thousandths of a penny: every attempt that may be billed (Claude::ATTEMPTS: the request and the
     * server-side fallback's; no SDK retry), each at its most input (Claude::maxInputTokens) and the kind's max_tokens of output.
     */
    public static function most(Kind $kind, string $bytes, string $mediaType, string $priceIn, string $priceOut): int
    {
        return AiUsage::millipence(Claude::ATTEMPTS * Claude::maxInputTokens($kind, $bytes, $mediaType), Claude::ATTEMPTS * $kind->maxTokens(), $priceIn, $priceOut);
    }

    /**
     * Whether the installer has paused the writers (M4: its markers, /etc/cw or CW_PAUSE_DIR): the reader then starts no reading, so the
     * installer's drain never waits on one (a reading already going ends by Claude::DEADLINE_SEC, inside the drain's 6 minutes).
     */
    public static function paused(): bool
    {
        $dir = getenv('CW_PAUSE_DIR');
        $dir = is_string($dir) && $dir !== '' ? rtrim($dir, '/') : '/etc/cw';
        return (glob($dir . '/*.cron.paused') ?: []) !== [] || file_exists($dir . '/cw-https.paused') || file_exists($dir . '/cw-fpm.stopped');
    }

    public static function kind(string $name): Kind
    {
        return match ($name) {
            RoyalMailBillKind::NAME => new RoyalMailBillKind(),
            ExpenseInvoiceKind::NAME => new ExpenseInvoiceKind(),
            default => throw new \LogicException("unknown document kind {$name}"),
        };
    }

    /** The media type of a file's bytes (finfo), never its name or what the browser said. */
    public static function mediaType(string $path): string
    {
        // an iPhone's HEIC photo told apart by its own box ("ftypheic" ...), whatever the server's magic file knows
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        if (preg_match('/^.{4}ftyp(heic|heix|hevc|hevx|heim|heis|mif1|msf1)/s', $head) === 1) {
            return 'image/heic';
        }
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    }

    /** Whether a file is one Claude would be asked to read (a PDF or a photo, HEIC included: refused by start() in words). */
    public static function isDocument(string $path): bool
    {
        $m = self::mediaType($path);
        return in_array($m, self::MEDIA, true) || in_array($m, self::HEIC, true);
    }

    /**
     * A reading started: the person may upload in this place (its permission re-read now), reading is on, the month's limit not reached, the
     * file a PDF or a photo of at most MAX_BYTES; the file copied into the spool (0600) and the job made, `waiting`. Refused in words (409
     * ai_off, ai_limit; 415 ai_type, ai_heic; 413 too_large; 503 ai_spool), nothing kept.
     *
     * @param array<string, mixed> $context what the page needs back (the account, the bill replaced, the expense and its version, what was typed)
     * @return int the job's id
     */
    public function start(Caller $caller, string $place, string $path, array $context): int
    {
        if (!isset(self::PLACES[$place])) {
            throw new \LogicException("unknown upload place {$place}");
        }
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'a document is read for a person, on the screen', 403);
        }
        $this->authorise($caller, $place);
        $s = AiSettings::read($this->db);
        if (!$s->on()) {
            throw new CwException('ai_off', (string) $s->off(), 409);
        }
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0) {
            throw new CwException('no_file', 'no file was chosen', 400, ['field' => 'file']);
        }
        if ($size > self::MAX_BYTES) {
            throw new CwException('too_large', (string) intdiv(self::MAX_BYTES, 1_048_576), 413, ['field' => 'file']);
        }
        $media = self::mediaType($path);
        if (in_array($media, self::HEIC, true)) {
            throw new CwException('ai_heic', 'a HEIC photo', 415, ['field' => 'file']);
        }
        if (!in_array($media, self::MEDIA, true)) {
            throw new CwException('ai_type', 'not a PDF or a photo', 415, ['field' => 'file']);
        }
        if (in_array($place, self::EXPENSE_PLACES, true) && !in_array($media, self::EXPENSE_MEDIA, true)) {
            // AI15: the file read becomes the expense's own, and an expense keeps a PDF, a JPEG or a PNG (ExpenseFiles)
            throw new CwException('ai_type_expense', 'an expense keeps a PDF, a JPEG or a PNG', 415, ['field' => 'file']);
        }
        $used = AiUsage::month($this->db, ($this->clock)());
        if ($used['pence'] >= $s->limitPence) {
            throw new CwException('ai_limit', 'the month\'s limit', 409, ['used' => $used['pence'], 'limit' => $s->limitPence]);
        }
        if (!is_dir($this->spool) || !is_writable($this->spool)) {
            throw new CwException('ai_spool', 'the folder CW keeps a document in while it is read is missing', 503);
        }
        // One effect per form (checklist 10): the same file sent again from the same place by the same person (a double click, a form sent again)
        // while its reading is waiting, going or not yet shown is that reading, never a second call; under the person's own lock, so two sends
        // at once never both miss it.
        $sha = (string) hash_file('sha256', $path);
        $ctx = json_encode(self::context($context), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $lock = 'cw_ai_start:' . substr(sha1((string) $this->db->value('SELECT DATABASE()') . ':' . $caller->staffUserId), 0, 40); // MySQL's locks are server-wide
        if ((int) $this->db->value('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
            throw new CwException('busy', 'another document of yours is being sent: try again in a moment', 409);
        }
        try {
            foreach ($this->db->all("SELECT id, context FROM ai_read WHERE staff_user_id = ? AND place = ? AND file_sha256 = ? AND state IN ('waiting', 'reading', 'done') "
                . 'AND created_at > ? ORDER BY id DESC', [$caller->staffUserId, $place, $sha, Clock::db(($this->clock)()->modify('-' . self::KEEP_MINUTES . ' minutes'))]) as $r) {
                if (json_decode((string) $r['context'], true) == json_decode($ctx, true)) {
                    return (int) $r['id'];
                }
            }
            return $this->keep($caller, $place, $path, $size, $media, $sha, $ctx);
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** The file copied into the spool (0600, a random name) and its job made, `waiting`; nothing left behind when either fails. */
    private function keep(Caller $caller, string $place, string $path, int $size, string $media, string $sha, string $ctx): int
    {
        $name = bin2hex(random_bytes(16));
        $dest = $this->spool . '/' . $name;
        $in = fopen($path, 'rb');
        $out = $in === false ? false : @fopen($dest, 'xb');
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            throw new CwException('ai_spool', 'the document could not be kept to be read', 503);
        }
        try {
            chmod($dest, 0600);
            $copied = stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
        if ($copied !== $size || hash_file('sha256', $dest) !== $sha) {
            @unlink($dest);
            throw new CwException('ai_spool', 'the document could not be kept to be read', 503);
        }
        try {
            return $this->db->insert('INSERT INTO ai_read (staff_user_id, place, kind, context, media_type, file_bytes, file_sha256, spool_name, state, created_at) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'waiting', ?)", [$caller->staffUserId, $place, self::PLACES[$place], $ctx, $media, $size, $sha, $name,
                    Clock::db(($this->clock)())]);
        } catch (\Throwable $e) {
            @unlink($dest);
            throw $e;
        }
    }

    /**
     * The runner: the oldest waiting job taken (once: claimed by its state); its call's row written at the most it may cost (AI16), then read
     * by Claude; a Royal Mail bill's file deleted right after (an invoice's kept for its Save, AI15); the answer's form checked, the job
     * finished and the call's row finished (one transaction). A job whose person may no longer upload there, or that meets reading switched
     * off or the month's limit reached, finishes as failed with no call. Null: nothing waiting.
     *
     * @return array{id: int, state: string, reason: string}|null
     */
    public function runNext(): ?array
    {
        $job = null;
        foreach ($this->db->all("SELECT * FROM ai_read WHERE state = 'waiting' ORDER BY id LIMIT 5") as $row) {
            if ($this->db->exec("UPDATE ai_read SET state = 'reading', started_at = ? WHERE id = ? AND state = 'waiting'", [Clock::db(($this->clock)()), (int) $row['id']]) === 1) {
                $job = $row;
                break;
            }
        }
        if ($job === null) {
            return null;
        }
        $id = (int) $job['id'];
        try {
            $s = AiSettings::read($this->db);
            try {
                $this->authorise(Caller::staff((int) $job['staff_user_id']), (string) $job['place']);
            } catch (CwException) {
                return $this->finish($id, 'failed', 'not_allowed', null, null);
            }
            if (!$s->on()) {
                return $this->finish($id, 'failed', 'off', null, null);
            }
            $month = AiUsage::month($this->db, ($this->clock)());
            if ($month['pence'] >= $s->limitPence) {
                return $this->finish($id, 'failed', 'limit', null, null);
            }
            // H1: only the job's OWN spool file (its 32-hex name inside the spool, a regular file and no link, of the size and sha256 recorded)
            $bytes = $this->readOwn($job);
            if ($bytes === null) {
                return $this->finish($id, 'failed', 'file_gone', null, null);
            }
            $kind = self::kind((string) $job['kind']);
            $most = self::most($kind, $bytes, (string) $job['media_type'], $s->priceIn, $s->priceOut);
            if ($month['millipence'] + $most > $s->limitPence * 1000) {
                // M3: a hard cap, to the thousandth of a penny: a call starts only when the month's spend plus its most (a bound: every billable
                // attempt, each input at a token a byte and a page a 2,000 bytes, each output at max_tokens, rounded up) stays within the limit
                return $this->finish($id, 'failed', 'limit', null, null);
            }
            // AI16: the call's row BEFORE the request, counted at the most it may cost: a reader that dies mid-call still counts (the next run
            // closes it as crashed at that most)
            $callId = $this->db->insert('INSERT INTO ai_call (read_id, created_at, staff_user_id, place, kind, model, media_type, file_bytes, cost_millipence, max_millipence, '
                . "result, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'started', 'started')", [$id, Clock::db(($this->clock)()), (int) $job['staff_user_id'],
                    (string) $job['place'], (string) $job['kind'], mb_substr($s->model, 0, 64), (string) $job['media_type'], (int) $job['file_bytes'], $most, $most]);
            try {
                $r = ($this->claude)($s)->read($kind, $bytes, (string) $job['media_type']);
            } catch (\Throwable $e) {
                // whatever went wrong, the job ends now (never left `reading`); what the call used is not known: its most. The class is said,
                // never a message (it could quote)
                return $this->finish($id, 'failed', 'error', null, mb_substr(get_class($e), 0, 200), ['id' => $callId, 'r' => null, 'cost' => $most]);
            }
            unset($bytes);
            if (!in_array((string) $job['place'], self::EXPENSE_PLACES, true)) {
                $this->dropName($job['spool_name']);
            }
            $call = ['id' => $callId, 'r' => $r, 'cost' => AiUsage::charged($r['reason'], $r['input_tokens'], $r['output_tokens'], $s->priceIn, $s->priceOut, $most)];
            if ($r['reason'] !== 'ok' || !is_array($r['json'])) {
                return $this->finish($id, 'failed', $r['reason'], null, null, $call, $r['reason'] === 'refusal' ? 'refused' : 'failed');
            }
            try {
                $shaped = $kind->shape($r['json']);
            } catch (CwException $e) {
                $reason = match ($e->errorCode) {
                    'ai_not_readable' => 'not_readable',
                    'ai_count' => 'count',
                    'ai_not_gbp' => 'not_gbp',
                    default => 'bad_answer',
                };
                return $this->finish($id, 'failed', $reason, null, mb_substr($e->getMessage(), 0, 200), $call, $reason === 'not_readable' ? 'refused' : 'failed');
            }
            return $this->finish($id, 'done', 'ok', $shaped, null, $call, 'ok');
        } finally {
            // a Royal Mail bill's file never outlives its reading (AI11); an invoice's is kept for its Save while its job names it (finish())
            if (!in_array((string) $job['place'], self::EXPENSE_PLACES, true)) {
                $this->dropName($job['spool_name']);
            }
        }
    }

    /**
     * A job as its person's page sees it (another person's, one of another place than the page's (M5), or none: 404): its state, place, what
     * the page needs back, why it failed.
     *
     * @param list<string> $places the page's own places (BILL_PLACES, EXPENSE_PLACES)
     * @return array{id: int, place: string, kind: string, state: string, reason: ?string, detail: ?string, context: array<string, mixed>, created_at: string,
     *   started_at: ?string, file_bytes: int, media_type: string}
     */
    public function view(Caller $caller, int $id, array $places): array
    {
        $r = $this->db->one('SELECT id, place, kind, state, reason, detail, context, created_at, started_at, file_bytes, media_type FROM ai_read WHERE id = ? AND staff_user_id = ?',
            [$id, (int) $caller->staffUserId]);
        if ($r === null || $caller->staffUserId === null || !in_array((string) $r['place'], $places, true)
            || (self::PLACES[(string) $r['place']] ?? null) !== (string) $r['kind']) {
            // M5: a reading is seen only where it was made (a bill's never at the Expenses page, nor an invoice's at Delivery bills), and only when
            // its kind is its place's
            throw new CwException('not_found', 'there is no such reading', 404);
        }
        return ['id' => (int) $r['id'], 'place' => (string) $r['place'], 'kind' => (string) $r['kind'], 'state' => (string) $r['state'],
            'reason' => $r['reason'] === null ? null : (string) $r['reason'], 'detail' => $r['detail'] === null ? null : (string) $r['detail'],
            'context' => (array) json_decode((string) $r['context'], true, 8, JSON_THROW_ON_ERROR), 'created_at' => (string) $r['created_at'],
            'started_at' => $r['started_at'] === null ? null : (string) $r['started_at'], 'file_bytes' => (int) $r['file_bytes'], 'media_type' => (string) $r['media_type']];
    }

    /**
     * The answer of a finished job, taken ONCE by its person at the page of its place (the job becomes `taken`, its answer gone): a second look,
     * an answer past its hour (M6: expired then), another person or another place gets nothing (409 ai_taken / 404).
     *
     * @param list<string> $places the page's own places
     * @return array<string, mixed> the kind's shape()
     */
    public function take(Caller $caller, int $id, array $places): array
    {
        $this->authorise($caller, $this->view($caller, $id, $places)['place']);
        $expired = false;
        $answer = $this->db->transaction(function (Db $db) use ($caller, $id, $places, &$expired): ?array {
            $r = $db->one('SELECT place, kind, state, answer, finished_at FROM ai_read WHERE id = ? AND staff_user_id = ? FOR UPDATE', [$id, (int) $caller->staffUserId]);
            if ($r === null || !in_array((string) $r['place'], $places, true) || (self::PLACES[(string) $r['place']] ?? null) !== (string) $r['kind']) {
                throw new CwException('not_found', 'there is no such reading', 404);
            }
            if ($r['state'] !== 'done' || $r['answer'] === null) {
                throw new CwException('ai_taken', (string) $r['state'], 409);
            }
            if ((string) $r['finished_at'] < Clock::db(($this->clock)()->modify('-' . self::KEEP_MINUTES . ' minutes'))) {
                // M6: past its hour (the clean-up not run yet): expired now, under the row lock, never taken
                $db->exec("UPDATE ai_read SET state = 'expired', reason = 'expired', answer = NULL WHERE id = ?", [$id]);
                $expired = true;
                return null;
            }
            $db->exec("UPDATE ai_read SET state = 'taken', answer = NULL, taken_at = ? WHERE id = ?", [Clock::db(($this->clock)()), $id]);
            return (array) json_decode((string) $r['answer'], true, 16, JSON_THROW_ON_ERROR);
        });
        if ($expired || $answer === null) {
            throw new CwException('ai_taken', 'expired', 409);
        }
        return $answer;
    }

    /**
     * The answer given back to its job when the person's own check could not run just now (the bills' lock busy, the kit's hold): the reading is
     * `done` again for its person's page to take, never read by Claude a second time (a busy Store keeps its check open the same way, DL79).
     *
     * @param array<string, mixed> $answer what take() gave
     */
    public function giveBack(Caller $caller, int $id, array $answer): void
    {
        $this->db->exec("UPDATE ai_read SET state = 'done', answer = ?, taken_at = NULL WHERE id = ? AND staff_user_id = ? AND state = 'taken'",
            [json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id, (int) $caller->staffUserId]);
    }

    /**
     * Whether the invoice a job read is kept for its person's Save (AI15): an expense's place, its file still in the spool and not attached,
     * the job finished, within KEEP_MINUTES of when its page took the reading (or, not taken, of when it finished).
     */
    public function kept(Caller $caller, int $id): bool
    {
        if ($caller->staffUserId === null) {
            return false;
        }
        $r = $this->db->one('SELECT place, state, spool_name, file_used_at, finished_at, taken_at FROM ai_read WHERE id = ? AND staff_user_id = ?',
            [$id, $caller->staffUserId]);
        return $r !== null && $this->keptRow($r) && $this->ownFile($r['spool_name']) !== null;
    }

    /**
     * The invoice a job read, for its expense's Save (AI15), inside the expense's own transaction (a refused Save uses nothing): only its
     * person, from the place it was read for (the add form; a Change: that expense only), while it is kept, checked again (its size and
     * sha256 as it was sent), and ONCE (its row locked, file_used_at written). Anything else: 422 ai_file_gone, nothing changed. The expense
     * keeps it through ExpenseFiles::store, as a file chosen on the form (its type and size checked there too), from the private copy of the
     * verified bytes returned here (the caller deletes it; the stored file's sha256 must be this sha256 before the expense commits);
     * dropUsed() deletes the spool's file after.
     *
     * @return array{path: string, name: string, sha256: string}
     */
    public function useFile(Caller $caller, int $id, string $place, ?int $expense): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('the invoice Claude read is attached inside the expense\'s transaction');
        }
        $gone = static fn (): CwException => new CwException('ai_file_gone', 'the invoice Claude read is no longer kept for this form', 422, ['field' => 'file']);
        if ($caller->staffUserId === null || !in_array($place, self::EXPENSE_PLACES, true)) {
            throw $gone();
        }
        $r = $this->db->one('SELECT * FROM ai_read WHERE id = ? AND staff_user_id = ? FOR UPDATE', [$id, $caller->staffUserId]);
        if ($r === null || $r['place'] !== $place || !$this->keptRow($r)) {
            throw $gone();
        }
        if ($place === 'expense_change') {
            $c = json_decode((string) $r['context'], true);
            if (!is_array($c) || !is_int($c['expense'] ?? null) || $c['expense'] !== $expense) {
                throw $gone();
            }
        }
        // H1: its own spool file only, as it was sent (the same check as the reader's before it sends one); High 3 (the re-check of ca8987b):
        // the VERIFIED bytes are what Save stores, from a private copy (0600, outside the spool), never the spool's path read again
        $bytes = $this->readOwn($r);
        if ($bytes === null) {
            throw $gone();
        }
        $copy = @tempnam(sys_get_temp_dir(), 'cw-ai-invoice-');
        if ($copy === false || !@chmod($copy, 0600) || file_put_contents($copy, $bytes) !== strlen($bytes) || !hash_equals((string) $r['file_sha256'], (string) hash_file('sha256', $copy))) {
            if (is_string($copy)) {
                @unlink($copy);
            }
            throw new CwException('ai_spool', 'the invoice Claude read could not be copied for its Save', 503);
        }
        $this->db->exec('UPDATE ai_read SET file_used_at = ? WHERE id = ? AND file_used_at IS NULL', [Clock::db(($this->clock)()), $id]);
        return ['path' => $copy, 'name' => 'invoice', 'sha256' => (string) $r['file_sha256']];
    }

    /** The file a Save attached (AI15), deleted from the spool once its expense is stored (the clean-up does it otherwise). */
    public function dropUsed(int $id): void
    {
        $name = $this->db->transaction(function (Db $db) use ($id): ?string {
            $r = $db->one('SELECT spool_name FROM ai_read WHERE id = ? AND file_used_at IS NOT NULL AND spool_name IS NOT NULL FOR UPDATE', [$id]);
            if ($r === null) {
                return null;
            }
            $db->exec('UPDATE ai_read SET spool_name = NULL WHERE id = ?', [$id]);
            return (string) $r['spool_name'];
        });
        $this->dropName($name);
    }

    /**
     * A run of the reader begins (AI17; its lock held, so no other run goes): recorded on its one row. A run that began before and never
     * ended (its process died) is recorded as failed now ("did not end"): true when it was so.
     */
    public function runBegins(): bool
    {
        $now = Clock::db(($this->clock)());
        return $this->db->transaction(static function (Db $db) use ($now): bool {
            $r = $db->one('SELECT started_at, ended_at FROM ai_reader WHERE id = 1 FOR UPDATE');
            if ($r === null) {
                $db->exec('INSERT INTO ai_reader (id, started_at) VALUES (1, ?)', [$now]);
                return false;
            }
            $died = $r['started_at'] !== null && $r['ended_at'] === null;
            if ($died) {
                $db->exec("UPDATE ai_reader SET started_at = ?, ended_at = NULL, failed_at = ?, error = 'did not end' WHERE id = 1", [$now, $now]);
            } else {
                $db->exec('UPDATE ai_reader SET started_at = ?, ended_at = NULL WHERE id = 1', [$now]);
            }
            return $died;
        });
    }

    /** The run ends (AI17): well (null), or failed with an error's class (never its message: it could quote). */
    public function runEnds(?string $error): void
    {
        $now = Clock::db(($this->clock)());
        if ($error === null) {
            $this->db->exec('UPDATE ai_reader SET ended_at = ?, ok_at = ? WHERE id = 1', [$now, $now]);
        } else {
            $this->db->exec('UPDATE ai_reader SET ended_at = ?, failed_at = ?, error = ? WHERE id = 1', [$now, $now, mb_substr($error, 0, 255)]);
        }
    }

    /**
     * What bin/health_alert.php says of the reading (AI17; Ops\Alerts), only while reading is switched on (ai.read_uploads): documents waiting
     * more than WAIT_ALERT_MINUTES (the reader is not running, or is stuck), and the reader's last run failed (an error, or it never ended),
     * until a run ends well.
     *
     * @return list<array{key: string, kind: string, detail: string, data: array<string, int|string|null>}>
     */
    public static function health(Db $db, \DateTimeImmutable $now): array
    {
        if (!AiSettings::read($db)->switch) {
            return [];
        }
        $out = [];
        $w = $db->one("SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM ai_read WHERE state = 'waiting' AND created_at < ?",
            [Clock::db($now->modify('-' . self::WAIT_ALERT_MINUTES . ' minutes'))]);
        if ($w !== null && (int) $w['n'] > 0) {
            $age = max(0, $now->getTimestamp() - Clock::fromDb((string) $w['oldest'])->getTimestamp());
            $out[] = ['key' => 'ai_waiting', 'kind' => 'ai_waiting', 'detail' => sprintf('%d document(s) waiting more than %d minutes to be read by Claude (the oldest '
                . '%d seconds): is bin/ai_read.php running?', (int) $w['n'], self::WAIT_ALERT_MINUTES, $age),
                'data' => ['count' => (int) $w['n'], 'limit' => self::WAIT_ALERT_MINUTES, 'age' => $age]];
        }
        $r = $db->one('SELECT ok_at, failed_at, error FROM ai_reader WHERE id = 1');
        if ($r !== null && $r['failed_at'] !== null && ($r['ok_at'] === null || (string) $r['failed_at'] > (string) $r['ok_at'])) {
            $age = max(0, $now->getTimestamp() - Clock::fromDb((string) $r['failed_at'])->getTimestamp());
            $out[] = ['key' => 'ai_reader_failed', 'kind' => 'ai_reader_failed', 'detail' => sprintf('the reader\'s last run failed %d seconds ago (%s)', $age,
                (string) $r['error']), 'data' => ['age' => $age, 'error' => (string) $r['error']]];
        }
        return $out;
    }

    /**
     * What CW's own checks made of a reading its person took (ai_call.checked, once): passed (a Royal Mail bill's check page shown; an expense
     * form filled), or refused with the refusal's code (the bill's lines did not add up, an unknown product, ...).
     */
    public function checked(int $id, bool $passed, ?string $code = null): void
    {
        $this->db->exec('UPDATE ai_call SET checked = ?, check_code = ?, checked_at = ? WHERE read_id = ? AND checked IS NULL', [$passed ? 'passed' : 'refused',
            $code === null ? null : mb_substr($code, 0, 32), Clock::db(($this->clock)()), $id]);
    }

    /**
     * The clean-up (every minute in the runner, whatever the switch): with the runner's lock held, a job still `reading` was left by a runner
     * that stopped (failed: stopped; its call, still `started`, closed as crashed at its most: AI16); a call still `started` after
     * STARTED_HOURS is closed so too (no lock needed: no call lasts that long); a job waiting more than KEEP_MINUTES is failed (too_old); an
     * answer not shown within KEEP_MINUTES is dropped (expired); the file of every finished job deleted unless it is an invoice kept for its
     * Save (AI15; its row locked, so a Save attaching it at that moment wins or finds it gone), and any file of the spool no job names that is
     * older than KEEP_MINUTES (a crash between the copy and the job's row); job rows finished more than KEEP_ROW_HOURS ago emptied of their
     * content in place (M10: never removed; their log rows stay).
     *
     * @return array{stopped: int, too_old: int, expired: int, crashed: int, files: int, rows: int}
     */
    public function sweep(bool $runnerLock): array
    {
        $now = ($this->clock)();
        $out = ['stopped' => 0, 'too_old' => 0, 'expired' => 0, 'crashed' => 0, 'files' => 0, 'rows' => 0];
        if ($runnerLock) {
            foreach ($this->db->column("SELECT id FROM ai_read WHERE state = 'reading'") as $id) {
                $out['crashed'] += (int) $this->db->value("SELECT COUNT(*) FROM ai_call WHERE read_id = ? AND result = 'started'", [(int) $id]);
                $this->finish((int) $id, 'failed', 'stopped', null, null);
                $out['stopped']++;
            }
        }
        $out['crashed'] += $this->db->exec("UPDATE ai_call SET result = 'crashed', reason = 'stopped' WHERE result = 'started' AND created_at < ?",
            [Clock::db($now->modify('-' . self::STARTED_HOURS . ' hours'))]);
        $old = Clock::db($now->modify('-' . self::KEEP_MINUTES . ' minutes'));
        foreach ($this->db->column("SELECT id FROM ai_read WHERE state = 'waiting' AND created_at < ?", [$old]) as $id) {
            if ($this->db->exec("UPDATE ai_read SET state = 'reading' WHERE id = ? AND state = 'waiting'", [(int) $id]) === 1) {
                $this->finish((int) $id, 'failed', 'too_old', null, null);
                $out['too_old']++;
            }
        }
        $out['expired'] = $this->db->exec("UPDATE ai_read SET state = 'expired', reason = 'expired', answer = NULL WHERE state = 'done' AND finished_at < ?", [$old]);
        foreach ($this->db->column("SELECT id FROM ai_read WHERE spool_name IS NOT NULL AND state NOT IN ('waiting', 'reading')") as $id) {
            $name = $this->db->transaction(function (Db $db) use ($id): ?string {
                $r = $db->one('SELECT * FROM ai_read WHERE id = ? FOR UPDATE', [(int) $id]);
                if ($r === null || $r['spool_name'] === null || in_array($r['state'], ['waiting', 'reading'], true) || $this->keptRow($r)) {
                    return null;
                }
                $db->exec('UPDATE ai_read SET spool_name = NULL WHERE id = ?', [(int) $id]);
                return (string) $r['spool_name'];
            });
            if ($this->dropName($name)) {
                $out['files']++;
            }
        }
        if (is_dir($this->spool)) {
            $cut = $now->getTimestamp() - self::KEEP_MINUTES * 60;
            $named = array_flip(array_map('strval', $this->db->column('SELECT spool_name FROM ai_read WHERE spool_name IS NOT NULL')));
            foreach (scandir($this->spool) ?: [] as $f) {
                // only CW's own spool names (32 hex) that no job names: nothing else in the folder is ever touched (a link: the link itself)
                $st = preg_match('/^[0-9a-f]{32}$/D', $f) === 1 && !isset($named[$f]) ? @lstat($this->spool . '/' . $f) : false;
                if (is_array($st) && (int) $st['mtime'] < $cut && $this->dropName($f)) {
                    $out['files']++;
                }
            }
        }
        // M10 (the re-check of ca8987b): the app login never removes a job row (so a job can never be replaced); a day after it finished, what its
        // page had typed (context) and any answer or detail are emptied in place, the row kept with no content
        $out['rows'] = $this->db->exec("UPDATE ai_read SET context = JSON_OBJECT(), answer = NULL, detail = NULL WHERE state NOT IN ('waiting', 'reading') "
            . "AND spool_name IS NULL AND COALESCE(finished_at, created_at) < ? AND (JSON_LENGTH(context) > 0 OR answer IS NOT NULL OR detail IS NOT NULL)",
            [Clock::db($now->modify('-' . self::KEEP_ROW_HOURS . ' hours'))]);
        return $out;
    }

    /** The place's own permission, re-read now (the route checked it too): the Delivery bills job's upload, the Expenses job's edit. */
    private function authorise(Caller $caller, string $place): void
    {
        if (str_starts_with($place, 'delivery_')) {
            (new DeliveryBills($this->db))->authoriseWrite($caller);
        } else {
            ExpenseAccess::mayEdit($this->db, $caller);
        }
    }

    /**
     * A job finished (once: only a job `reading`): its state and reason, the shaped answer (done) or the failure's words (field names only); its
     * file deleted, unless it is an invoice kept for its Save (AI15); its call's row (written before the request) finished with what the call
     * used and why, in the same transaction; a call of the job still `started` without one (its reader stopped mid-call) closed as crashed at
     * its most (AI16).
     *
     * @param array<string, mixed>|null $answer
     * @param array{id: int, r: array<string, mixed>|null, cost: int}|null $call
     * @return array{id: int, state: string, reason: string}
     */
    private function finish(int $id, string $state, string $reason, ?array $answer, ?string $detail, ?array $call = null, string $result = 'failed'): array
    {
        $now = Clock::db(($this->clock)());
        $file = $this->db->transaction(function (Db $db) use ($id, $state, $reason, $answer, $detail, $call, $result, $now): ?string {
            $job = $db->one('SELECT * FROM ai_read WHERE id = ? FOR UPDATE', [$id]);
            if ($job === null || $job['state'] !== 'reading') {
                return null;
            }
            $keep = in_array((string) $job['place'], self::EXPENSE_PLACES, true) && !in_array($reason, self::DROPS_FILE, true) && $job['spool_name'] !== null;
            $db->exec('UPDATE ai_read SET state = ?, reason = ?, detail = ?, answer = ?, finished_at = ?, spool_name = ? WHERE id = ?', [$state, $reason, $detail,
                $answer === null ? null : json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $now, $keep ? (string) $job['spool_name'] : null, $id]);
            if ($call !== null) {
                $r = $call['r'] ?? ['served_model' => null, 'input_tokens' => 0, 'output_tokens' => 0, 'refusal_category' => null, 'request_id' => null,
                    'fallback' => false, 'seconds' => 0.0];
                $db->exec("UPDATE ai_call SET served_model = ?, input_tokens = ?, output_tokens = ?, cost_millipence = ?, result = ?, reason = ?, refusal_category = ?, "
                    . "request_id = ?, fallback = ?, seconds = ? WHERE id = ? AND result = 'started'", [$r['served_model'] === null ? null : mb_substr((string) $r['served_model'], 0, 64),
                        (int) $r['input_tokens'], (int) $r['output_tokens'], $call['cost'], $result, $reason, $r['refusal_category'], $r['request_id'], $r['fallback'] ? 1 : 0,
                        number_format(min(99999.0, max(0.0, (float) $r['seconds'])), 2, '.', ''), $call['id']]);
            }
            $db->exec("UPDATE ai_call SET result = 'crashed', reason = ? WHERE read_id = ? AND result = 'started'", [mb_substr($reason, 0, 32), $id]);
            return $keep || $job['spool_name'] === null ? null : (string) $job['spool_name'];
        });
        $this->dropName($file);
        return ['id' => $id, 'state' => $state, 'reason' => $reason];
    }

    /**
     * A job's row says its invoice is kept for its person's Save (AI15): an expense's place, a file named and not attached, the job finished,
     * within KEEP_MINUTES of when its page took the reading (else of when it finished).
     *
     * @param array<string, mixed> $r
     */
    private function keptRow(array $r): bool
    {
        if (!in_array((string) $r['place'], self::EXPENSE_PLACES, true) || $r['spool_name'] === null || $r['file_used_at'] !== null
            || in_array((string) $r['state'], ['waiting', 'reading'], true)) {
            return false;
        }
        $from = $r['taken_at'] ?? $r['finished_at'];
        return $from !== null && (string) $from >= Clock::db(($this->clock)()->modify('-' . self::KEEP_MINUTES . ' minutes'));
    }

    /**
     * The path of one of the spool's OWN files (H1, the Codex final review of 86aebbd): a name of 32 hex (a job row can never name a path),
     * directly inside the spool folder (its real path), the entry itself a regular file and not a link (lstat). Null otherwise.
     */
    private function ownFile(mixed $name): ?string
    {
        if (!is_string($name) || preg_match('/^[0-9a-f]{32}$/D', $name) !== 1) {
            return null;
        }
        $dir = realpath($this->spool);
        if ($dir === false || !is_dir($dir)) {
            return null;
        }
        $path = $dir . '/' . $name;
        clearstatcache(true, $path);
        $st = @lstat($path);
        return is_array($st) && ($st['mode'] & 0170000) === 0100000 ? $path : null;
    }

    /**
     * The bytes of a job's own spool file as it was sent (H1): ownFile(), opened without following a link (the opened file is the one lstat
     * saw: the same device and inode), at most MAX_BYTES, of the size and sha256 its job recorded. Null otherwise: nothing is sent.
     *
     * @param array<string, mixed> $job
     */
    private function readOwn(array $job): ?string
    {
        $path = $this->ownFile($job['spool_name'] ?? null);
        $size = (int) ($job['file_bytes'] ?? 0);
        if ($path === null || $size <= 0 || $size > self::MAX_BYTES) {
            return null;
        }
        $before = @lstat($path);
        $h = @fopen($path, 'rb');
        if ($h === false || !is_array($before)) {
            return null;
        }
        try {
            $st = fstat($h);
            if (!is_array($st) || $st['ino'] !== $before['ino'] || $st['dev'] !== $before['dev'] || ($st['mode'] & 0170000) !== 0100000 || $st['size'] !== $size) {
                return null;
            }
            $bytes = (string) stream_get_contents($h, self::MAX_BYTES + 1);
        } finally {
            fclose($h);
        }
        return strlen($bytes) === $size && hash_equals((string) ($job['file_sha256'] ?? ''), hash('sha256', $bytes)) ? $bytes : null;
    }

    /** One of the spool's own entries deleted (H1: the same rule as ownFile(); a link inside the spool: the link itself, never its target). */
    private function dropName(mixed $name): bool
    {
        if (!is_string($name) || preg_match('/^[0-9a-f]{32}$/D', $name) !== 1) {
            return false;
        }
        $dir = realpath($this->spool);
        if ($dir === false) {
            return false;
        }
        $path = $dir . '/' . $name;
        clearstatcache(true, $path);
        $st = @lstat($path);
        if (!is_array($st) || !in_array($st['mode'] & 0170000, [0100000, 0120000], true)) {
            return false;
        }
        return @unlink($path);
    }

    /**
     * What a job keeps of the page: ids as ints, typed text squashed and cut (an expense form's fields), nothing else.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private static function context(array $c): array
    {
        $out = [];
        foreach ($c as $k => $v) {
            if (!is_string($k) || preg_match('/^[a-z_]{1,32}$/D', $k) !== 1) {
                continue;
            }
            if (is_int($v) || is_bool($v) || $v === null) {
                $out[$k] = $v;
            } elseif (is_string($v) && mb_check_encoding($v, 'UTF-8')) {
                $out[$k] = mb_substr($v, 0, self::CONTEXT_TEXT_MAX);
            }
        }
        return $out;
    }
}
