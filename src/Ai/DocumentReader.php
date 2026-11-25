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
 * "Claude reads the upload" (CW-1182 phase 1; docs/decisions.md AI1, AI3, AI9-AI11): one service for every upload place. A person drops a PDF
 * or a photo where they upload today; start() checks they may upload there (the place's own permission, re-read now), that reading is on and
 * within the month's limit, and what the file is (sniffed from its bytes); it keeps the file in the spool folder and makes a job (ai_read,
 * `waiting`). The runner (bin/ai_read.php, every minute, never a web worker: a five-page bill takes minutes) takes the jobs one at a time
 * (runNext()): Claude reads the file, the file is deleted right after (read or not), CW checks the answer's form (the kind's shape()) and keeps
 * it on the job; each call is logged without any content (ai_call: when, who, place, kind, model, tokens, cost, result, seconds). The
 * person's page, which looks again every few seconds (view()), takes the answer once (take()) and runs the place's own checks: a Royal Mail
 * bill goes through RoyalMailBills::check (the check page, then Store as today), an expense fills its form (Save as today). A person always
 * stores; Claude never does.
 *
 * Nothing is kept longer than it is needed: the file until it is read (and never more than an hour: sweep(), every minute in the runner,
 * whatever the switch), the answer until it is shown (and never more than an hour), the job row a day; the log row stays (no content).
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
    /** A file, or an answer not shown, is never kept longer than this (minutes). */
    public const KEEP_MINUTES = 60;
    /** A finished job's row is kept this long (hours); its log row stays. */
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
        $used = AiUsage::month($this->db, ($this->clock)());
        if ($used['pence'] >= $s->limitPence) {
            throw new CwException('ai_limit', 'the month\'s limit', 409, ['used' => $used['pence'], 'limit' => $s->limitPence]);
        }
        if (!is_dir($this->spool) || !is_writable($this->spool)) {
            throw new CwException('ai_spool', 'the folder CW keeps a document in while it is read is missing', 503);
        }
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
        if ($copied !== $size) {
            @unlink($dest);
            throw new CwException('ai_spool', 'the document could not be kept to be read', 503);
        }
        try {
            return $this->db->insert('INSERT INTO ai_read (staff_user_id, place, kind, context, media_type, file_bytes, file_sha256, spool_name, state, created_at) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'waiting', ?)", [$caller->staffUserId, $place, self::PLACES[$place], json_encode(self::context($context),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $media, $size, (string) hash_file('sha256', $dest), $name, Clock::db(($this->clock)())]);
        } catch (\Throwable $e) {
            @unlink($dest);
            throw $e;
        }
    }

    /**
     * The runner: the oldest waiting job taken (once: claimed by its state), read by Claude, its file deleted right after, the answer's form
     * checked, the job finished and the call logged (one transaction). A job whose person may no longer upload there, or that meets reading
     * switched off or the month's limit reached, finishes as failed with no call. Null: nothing waiting.
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
        $path = $job['spool_name'] === null ? null : $this->spool . '/' . (string) $job['spool_name'];
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
            if (AiUsage::month($this->db, ($this->clock)())['pence'] >= $s->limitPence) {
                return $this->finish($id, 'failed', 'limit', null, null);
            }
            $bytes = $path !== null && is_file($path) ? file_get_contents($path) : false;
            if ($bytes === false || strlen($bytes) !== (int) $job['file_bytes']) {
                return $this->finish($id, 'failed', 'file_gone', null, null);
            }
            $kind = self::kind((string) $job['kind']);
            $r = ($this->claude)($s)->read($kind, $bytes, (string) $job['media_type']);
            unset($bytes);
            $this->drop($path);
            $call = ['model' => $s->model, 'r' => $r, 'cost' => AiUsage::millipence($r['input_tokens'], $r['output_tokens'], $s->priceIn, $s->priceOut)];
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
            $this->drop($path);
        }
    }

    /**
     * A job as its person's page sees it (another person's, or none: 404): its state, place, what the page needs back, why it failed.
     *
     * @return array{id: int, place: string, kind: string, state: string, reason: ?string, detail: ?string, context: array<string, mixed>, created_at: string,
     *   started_at: ?string, file_bytes: int, media_type: string}
     */
    public function view(Caller $caller, int $id): array
    {
        $r = $this->db->one('SELECT id, place, kind, state, reason, detail, context, created_at, started_at, file_bytes, media_type FROM ai_read WHERE id = ? AND staff_user_id = ?',
            [$id, (int) $caller->staffUserId]);
        if ($r === null || $caller->staffUserId === null) {
            throw new CwException('not_found', 'there is no such reading', 404);
        }
        return ['id' => (int) $r['id'], 'place' => (string) $r['place'], 'kind' => (string) $r['kind'], 'state' => (string) $r['state'],
            'reason' => $r['reason'] === null ? null : (string) $r['reason'], 'detail' => $r['detail'] === null ? null : (string) $r['detail'],
            'context' => (array) json_decode((string) $r['context'], true, 8, JSON_THROW_ON_ERROR), 'created_at' => (string) $r['created_at'],
            'started_at' => $r['started_at'] === null ? null : (string) $r['started_at'], 'file_bytes' => (int) $r['file_bytes'], 'media_type' => (string) $r['media_type']];
    }

    /**
     * The answer of a finished job, taken ONCE by its person (the job becomes `taken`, its answer gone): a second look, or another person, gets
     * nothing (409 ai_taken / 404).
     *
     * @return array<string, mixed> the kind's shape()
     */
    public function take(Caller $caller, int $id): array
    {
        $this->authorise($caller, $this->view($caller, $id)['place']);
        return $this->db->transaction(function (Db $db) use ($caller, $id): array {
            $r = $db->one('SELECT state, answer FROM ai_read WHERE id = ? AND staff_user_id = ? FOR UPDATE', [$id, (int) $caller->staffUserId])
                ?? throw new CwException('not_found', 'there is no such reading', 404);
            if ($r['state'] !== 'done' || $r['answer'] === null) {
                throw new CwException('ai_taken', (string) $r['state'], 409);
            }
            $db->exec("UPDATE ai_read SET state = 'taken', answer = NULL, taken_at = ? WHERE id = ?", [Clock::db(($this->clock)()), $id]);
            return (array) json_decode((string) $r['answer'], true, 16, JSON_THROW_ON_ERROR);
        });
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
     * that stopped (failed: stopped); a job waiting more than KEEP_MINUTES is failed (too_old); an answer not shown within KEEP_MINUTES is
     * dropped (expired); every file of a finished job, and any file of the spool older than KEEP_MINUTES (a crash), deleted; job rows finished
     * more than KEEP_ROW_HOURS ago removed (their log rows stay).
     *
     * @return array{stopped: int, too_old: int, expired: int, files: int, rows: int}
     */
    public function sweep(bool $runnerLock): array
    {
        $now = ($this->clock)();
        $out = ['stopped' => 0, 'too_old' => 0, 'expired' => 0, 'files' => 0, 'rows' => 0];
        if ($runnerLock) {
            foreach ($this->db->column("SELECT id FROM ai_read WHERE state = 'reading'") as $id) {
                $this->finish((int) $id, 'failed', 'stopped', null, null);
                $out['stopped']++;
            }
        }
        $old = Clock::db($now->modify('-' . self::KEEP_MINUTES . ' minutes'));
        foreach ($this->db->column("SELECT id FROM ai_read WHERE state = 'waiting' AND created_at < ?", [$old]) as $id) {
            if ($this->db->exec("UPDATE ai_read SET state = 'reading' WHERE id = ? AND state = 'waiting'", [(int) $id]) === 1) {
                $this->finish((int) $id, 'failed', 'too_old', null, null);
                $out['too_old']++;
            }
        }
        $out['expired'] = $this->db->exec("UPDATE ai_read SET state = 'expired', reason = 'expired', answer = NULL WHERE state = 'done' AND finished_at < ?", [$old]);
        foreach ($this->db->all("SELECT id, spool_name FROM ai_read WHERE spool_name IS NOT NULL AND state NOT IN ('waiting', 'reading')") as $r) {
            $this->drop($this->spool . '/' . (string) $r['spool_name']);
            $this->db->exec('UPDATE ai_read SET spool_name = NULL WHERE id = ?', [(int) $r['id']]);
            $out['files']++;
        }
        if (is_dir($this->spool)) {
            $cut = $now->getTimestamp() - self::KEEP_MINUTES * 60;
            foreach (scandir($this->spool) ?: [] as $f) {
                // only CW's own spool names (32 hex): nothing else in the folder is ever touched
                $p = $this->spool . '/' . $f;
                if (preg_match('/^[0-9a-f]{32}$/D', $f) === 1 && is_file($p) && (int) filemtime($p) < $cut) {
                    $this->drop($p);
                    $this->db->exec("UPDATE ai_read SET spool_name = NULL WHERE spool_name = ?", [$f]);
                    $out['files']++;
                }
            }
        }
        $out['rows'] = $this->db->exec("DELETE FROM ai_read WHERE state NOT IN ('waiting', 'reading') AND COALESCE(finished_at, created_at) < ?",
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
     * A job finished: its state and reason, the shaped answer (done) or the failure's words (field names only), the file gone; with a call, its
     * log row in the same transaction.
     *
     * @param array<string, mixed>|null $answer
     * @param array{model: string, r: array<string, mixed>, cost: int}|null $call
     * @return array{id: int, state: string, reason: string}
     */
    private function finish(int $id, string $state, string $reason, ?array $answer, ?string $detail, ?array $call = null, string $result = 'failed'): array
    {
        $now = Clock::db(($this->clock)());
        $file = $this->db->transaction(function (Db $db) use ($id, $state, $reason, $answer, $detail, $call, $result, $now): ?string {
            $job = $db->one('SELECT * FROM ai_read WHERE id = ? FOR UPDATE', [$id]);
            if ($job === null) {
                return null;
            }
            $db->exec('UPDATE ai_read SET state = ?, reason = ?, detail = ?, answer = ?, finished_at = ?, spool_name = NULL WHERE id = ?', [$state, $reason, $detail,
                $answer === null ? null : json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $now, $id]);
            if ($call !== null) {
                $r = $call['r'];
                $db->insert('INSERT INTO ai_call (read_id, created_at, staff_user_id, place, kind, model, served_model, media_type, file_bytes, input_tokens, output_tokens, '
                    . 'cost_millipence, result, reason, refusal_category, request_id, fallback, seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $now, (int) $job['staff_user_id'], (string) $job['place'], (string) $job['kind'], mb_substr($call['model'], 0, 64),
                        $r['served_model'] === null ? null : mb_substr((string) $r['served_model'], 0, 64), (string) $job['media_type'], (int) $job['file_bytes'],
                        (int) $r['input_tokens'], (int) $r['output_tokens'], $call['cost'], $result, $reason, $r['refusal_category'], $r['request_id'], $r['fallback'] ? 1 : 0,
                        number_format((float) $r['seconds'], 2, '.', '')]);
            }
            return $job['spool_name'] === null ? null : (string) $job['spool_name'];
        });
        if (is_string($file) && preg_match('/^[0-9a-f]{32}$/D', $file) === 1) {
            $this->drop($this->spool . '/' . $file);
        }
        return ['id' => $id, 'state' => $state, 'reason' => $reason];
    }

    private function drop(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
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
