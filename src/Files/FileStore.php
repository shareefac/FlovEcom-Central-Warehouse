<?php

declare(strict_types=1);

namespace CW\Files;

use CW\Audit;
use CW\Caller;
use CW\Config;
use CW\CwException;
use CW\Db;

/**
 * The document store (IM1, I23): every file CW keeps (a supplier invoice, a delivery note, a duty-stamp photo, a
 * generated PDF) is stored once per distinct content, under its sha256, for at least RETENTION_YEARS years, and is
 * re-hashed every time it is read.
 *
 *   store()   copies the file ONCE into a private temporary file and checks only that copy (I36: a source swapped
 *             between the checks cannot slip past them): size 1..MAX_BYTES (400 empty_file / 413 too_large); the type
 *             is sniffed from the content (finfo), never taken from the name or a client (415 type_not_allowed outside
 *             ALLOWED_MIME); the same content stored again returns the existing row (deduped; its bytes are put back if
 *             the storage lost them); audit file.store
 *   attach()  links a stored file to a document (any status but cancelled), never removed, kept RETENTION_YEARS from
 *             the attachment (document_file.retain_until; the storage's retention is extended to it, I36); audit
 *             document.attach
 *   read()    the bytes, re-hashed: a mismatch is 500 file_corrupt (logged), never served
 *   downloadName()  the name a download carries: the stored name plus the extension of the SNIFFED type when its own
 *             extension is not one of that type's (a text file named duty.hta downloads as duty.hta.txt, I36)
 *   verify()  what bin/verify_files.php reports: rows whose bytes are missing or changed, keys no row names
 *
 * `stored_file` and `document_file` are append-only for the app login; the storage interface has no delete or
 * overwrite. Configured by app.env `file_store_dir` (env CW_FILE_STORE_DIR); unset: 503 file_store_unconfigured.
 * Browser uploads come with the I-2/I-3 screens (the UI pool's post_max_size is 2M); until then files arrive through
 * bin/store_file.php.
 */
final class FileStore
{
    public const MAX_BYTES = 26_214_400;
    public const ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png', 'text/csv', 'text/plain',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    public const KINDS = ['supplier_invoice', 'delivery_note', 'packing_list', 'photo', 'duty_evidence', 'generated_pdf', 'other'];
    /** document_file.role */
    public const ROLES = ['supplier_invoice', 'delivery_note', 'photo', 'evidence', 'generated_pdf'];
    public const RETENTION_YEARS = 7;
    public const CONFIG_KEY = 'file_store_dir';
    /**
     * The extensions a download of each allowed type may carry; the first is appended to a name with any other (I36):
     * the saved file opens as what was sniffed, never as what the name claimed (an HTA, a batch file, an SVG).
     */
    public const EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'text/csv' => ['csv'],
        'text/plain' => ['txt'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
    ];

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /** @param (\Closure(string): void)|null $log where integrity failures are reported (default: error_log) */
    public function __construct(private readonly Db $db, private readonly FileStorage $storage, ?\Closure $log = null)
    {
        $this->log = $log ?? static function (string $m): void {
            error_log('[cw-files] ' . $m);
        };
    }

    /** The store app.env `file_store_dir` (CW_FILE_STORE_DIR) names; 503 file_store_unconfigured without one. */
    public static function fromConfig(Config $config, Db $db, ?\Closure $log = null): self
    {
        $dir = $config->get(self::CONFIG_KEY);
        $no = static fn (string $why): CwException => new CwException('file_store_unconfigured',
            "the file store is not set up on this server ({$why}; deploy/staging/install_file_store.sh)", 503);
        if ($dir === null || trim($dir) === '') {
            throw $no('no ' . self::CONFIG_KEY . ' in app.env');
        }
        try {
            $storage = new LocalFileStorage(trim($dir));
        } catch (\InvalidArgumentException $e) {
            ($log ?? static function (string $m): void {
                error_log('[cw-files] ' . $m);
            })('file store refused: ' . $e->getMessage());
            throw $no('its directory is missing or not allowed');
        }
        return new self($db, $storage, $log);
    }

    public function storage(): FileStorage
    {
        return $this->storage;
    }

    /**
     * Stores the file at $path (a server-side path: bin/store_file.php, later the upload screens).
     *
     * @return array{id: int, sha256: string, size: int, mime: string, deduped: bool}
     */
    public function store(Caller $caller, string $path, string $originalName, string $kind, ?string $note): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new CwException('bad_kind', 'kind must be one of ' . implode(', ', self::KINDS), 400);
        }
        if ($note !== null) {
            $note = trim($note);
            if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > 255) {
                throw new CwException('bad_field', 'note must be text of at most 255 characters', 400, ['field' => 'note']);
            }
            $note = $note === '' ? null : $note;
        }
        clearstatcache(true, $path);
        if (!is_file($path) || !is_readable($path)) {
            throw new CwException('no_file', 'there is no readable file to store', 400);
        }
        $claimed = (int) filesize($path);
        if ($claimed === 0) {
            throw new CwException('empty_file', 'the file is empty', 400);
        }
        if ($claimed > self::MAX_BYTES) {
            throw new CwException('too_large', 'the file is larger than ' . intdiv(self::MAX_BYTES, 1_048_576) . ' MiB', 413, ['size' => $claimed]);
        }
        $copy = $this->privateCopy($path);
        try {
            return $this->storeCopy($caller, $copy, $originalName, $kind, $note);
        } finally {
            @unlink($copy);
        }
    }

    /**
     * store() of the private copy: every check and the put are on these bytes, which nobody else can change.
     *
     * @return array{id: int, sha256: string, size: int, mime: string, deduped: bool}
     */
    private function storeCopy(Caller $caller, string $path, string $originalName, string $kind, ?string $note): array
    {
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        if ($size === 0) {
            throw new CwException('empty_file', 'the file is empty', 400);
        }
        if ($size > self::MAX_BYTES) {
            throw new CwException('too_large', 'the file is larger than ' . intdiv(self::MAX_BYTES, 1_048_576) . ' MiB', 413, ['size' => $size]);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new CwException('type_not_allowed', "files of type {$mime} are not kept (PDF, JPEG, PNG, CSV, text and XLSX are)", 415, ['mime' => $mime]);
        }
        $sha = (string) hash_file('sha256', $path);
        $name = self::cleanName($originalName);
        return $this->db->transaction(function (Db $db) use ($caller, $path, $name, $kind, $note, $sha, $size, $mime): array {
            $id = null;
            if ($db->value('SELECT id FROM stored_file WHERE sha256 = ?', [$sha]) === null) {
                try {
                    $id = $db->insert(
                        'INSERT INTO stored_file (sha256, size_bytes, mime, original_name, kind, note, backend, storage_key, retain_until, stored_by, stored_actor) '
                        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(CURRENT_DATE(), INTERVAL ' . self::RETENTION_YEARS . ' YEAR), ?, ?)',
                        [$sha, $size, $mime, $name, $kind, $note, $this->storage->name(), $sha, $caller->staffUserId, $caller->actor],
                    );
                } catch (\PDOException $e) {
                    if (Db::driverCode($e) !== 1062) {
                        throw $e;
                    }
                    // the same content was stored by someone else a moment ago: theirs is the row
                }
            }
            $row = $db->one('SELECT id, sha256, size_bytes, mime, storage_key, retain_until FROM stored_file WHERE sha256 = ?', [$sha])
                ?? throw new \LogicException('stored_file row vanished');
            $deduped = $id === null;
            // The bytes before the commit: a row never names content the storage does not hold (a put that fails rolls
            // the row back; a commit that fails leaves an orphan, which verify() counts and which does no harm).
            if (!$deduped || !$this->storage->exists((string) $row['storage_key'])) {
                $this->put((string) $row['storage_key'], $path, (string) $row['retain_until']);
            }
            Audit::write($db, $caller, 'file.store', 'stored_file', (string) $row['id'], null,
                ['sha256' => $sha, 'size' => (int) $row['size_bytes'], 'mime' => $row['mime'], 'kind' => $kind, 'name' => $name, 'deduped' => $deduped]);
            return ['id' => (int) $row['id'], 'sha256' => $sha, 'size' => (int) $row['size_bytes'], 'mime' => (string) $row['mime'], 'deduped' => $deduped];
        });
    }

    /**
     * Attaches a stored file to a document under $role (ROLES), for good: false when it already is. A cancelled
     * document takes no files (409 document_cancelled).
     */
    public function attach(Caller $caller, int $documentId, int $fileId, string $role): bool
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new CwException('bad_file_role', 'role must be one of ' . implode(', ', self::ROLES), 400);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $documentId, $fileId, $role): bool {
            $doc = $db->one('SELECT id, status, number FROM document WHERE id = ? FOR SHARE', [$documentId])
                ?? throw new CwException('unknown_document', 'there is no such document', 404);
            if ($doc['status'] === 'cancelled') {
                throw new CwException('document_cancelled', 'a cancelled document takes no files', 409);
            }
            $key = $db->value('SELECT storage_key FROM stored_file WHERE id = ?', [$fileId]);
            if ($key === null) {
                throw new CwException('unknown_file', 'there is no such file', 404);
            }
            if ($db->value('SELECT 1 FROM document_file WHERE document_id = ? AND file_id = ? AND role = ?', [$documentId, $fileId, $role]) !== null) {
                return false;
            }
            // Kept RETENTION_YEARS from this attachment, whatever the file's first store (the same certificate attached to a
            // document years later is kept for that document too, I36).
            $until = (string) $db->value('SELECT DATE_ADD(CURRENT_DATE(), INTERVAL ' . self::RETENTION_YEARS . ' YEAR)');
            try {
                $db->exec('INSERT INTO document_file (document_id, file_id, role, attached_by, attached_actor, retain_until) VALUES (?, ?, ?, ?, ?, ?)',
                    [$documentId, $fileId, $role, $caller->staffUserId, $caller->actor, $until]);
            } catch (\PDOException $e) {
                if (Db::driverCode($e) === 1062) {
                    return false;
                }
                throw $e;
            }
            try {
                $this->storage->extendRetention((string) $key, new \DateTimeImmutable($until . ' 00:00:00', new \DateTimeZone('UTC')));
            } catch (FileStoreException $e) {
                ($this->log)("file {$fileId} ({$key}): extending its retention failed: {$e->reason}: {$e->getMessage()}");
                throw new CwException('file_missing', 'the stored file is missing, so it cannot be attached: tell an engineer (bin/verify_files.php)', 500);
            }
            Audit::write($db, $caller, 'document.attach', 'document', (string) $documentId, null,
                ['file_id' => $fileId, 'role' => $role, 'number' => $doc['number'], 'retain_until' => $until]);
            return true;
        });
    }

    /** @return array<string, mixed>|null the stored_file row */
    public function meta(int $id): ?array
    {
        return $this->db->one('SELECT * FROM stored_file WHERE id = ?', [$id]);
    }

    /**
     * The content of a stored file, re-hashed before it is handed out: 404 unknown_file, 500 file_missing, 500
     * file_corrupt (both logged; never served).
     *
     * @return array{meta: array<string, mixed>, bytes: string}
     */
    public function read(int $id): array
    {
        $meta = $this->meta($id) ?? throw new CwException('unknown_file', 'there is no such file', 404);
        try {
            $h = $this->storage->open((string) $meta['storage_key']);
        } catch (FileStoreException) {
            ($this->log)("file {$id} ({$meta['sha256']}) is missing from the {$meta['backend']} store");
            throw new CwException('file_missing', 'the stored file is missing: tell an engineer (bin/verify_files.php)', 500);
        }
        try {
            $bytes = (string) stream_get_contents($h);
        } finally {
            fclose($h);
        }
        if (!hash_equals((string) $meta['sha256'], hash('sha256', $bytes))) {
            ($this->log)("file {$id} ({$meta['sha256']}) does not match its sha256: file_corrupt, not served");
            throw new CwException('file_corrupt', 'the stored file does not match its fingerprint, so it was not sent: tell an engineer (bin/verify_files.php)', 500);
        }
        return ['meta' => $meta, 'bytes' => $bytes];
    }

    /**
     * Re-hashes every stored file through the storage (oldest first, at most $limit rows) and counts the stored keys no
     * row names (orphans: a commit that failed after the put; harmless, kept).
     *
     * @return array{checked: int, ok: int, missing: list<array{id: int, sha256: string}>, mismatch: list<array{id: int, sha256: string}>, orphans: int}
     */
    public function verify(?int $limit = null): array
    {
        $out = ['checked' => 0, 'ok' => 0, 'missing' => [], 'mismatch' => [], 'orphans' => 0];
        $after = 0;
        while ($limit === null || $out['checked'] < $limit) {
            $batch = $this->db->all('SELECT id, sha256, storage_key FROM stored_file WHERE id > ? ORDER BY id LIMIT ' . min(500, $limit === null ? 500 : $limit - $out['checked']),
                [$after]);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $r) {
                $after = (int) $r['id'];
                $out['checked']++;
                $item = ['id' => (int) $r['id'], 'sha256' => (string) $r['sha256']];
                try {
                    $h = $this->storage->open((string) $r['storage_key']);
                } catch (FileStoreException) {
                    $out['missing'][] = $item;
                    continue;
                }
                try {
                    $ctx = hash_init('sha256');
                    hash_update_stream($ctx, $h);
                    $sha = hash_final($ctx);
                } finally {
                    fclose($h);
                }
                if (hash_equals((string) $r['sha256'], $sha)) {
                    $out['ok']++;
                } else {
                    $out['mismatch'][] = $item;
                }
            }
        }
        $keys = [];
        $flush = function () use (&$keys, &$out): void {
            if ($keys === []) {
                return;
            }
            $known = $this->db->column('SELECT storage_key FROM stored_file WHERE storage_key IN (' . implode(', ', array_fill(0, count($keys), '?')) . ')', $keys);
            $out['orphans'] += count($keys) - count(array_unique($known));
            $keys = [];
        };
        foreach ($this->storage->keys() as $k) {
            $keys[] = $k;
            if (count($keys) >= 500) {
                $flush();
            }
        }
        $flush();
        return $out;
    }

    /**
     * The name a file is kept under: its base name, without control characters, Unicode format characters (\p{Cf}:
     * the bidi overrides U+202A-202E and isolates U+2066-2069 that make `invoice<RLO>fdp.bat` read as invoice...pdf,
     * zero-width characters, I36) or path separators, at most 255 bytes.
     */
    public static function cleanName(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        $name = (string) preg_replace('#^.*[/\\\\]#su', '', $name);
        $name = trim((string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\p{Cf}\/\\\\]/u', '', $name));
        $name = mb_strcut($name, 0, 255, 'UTF-8');
        return $name === '' || $name === '.' || $name === '..' ? 'file' : $name;
    }

    /**
     * The name a download of a stored file carries (I36): cleanName(), plus the first extension of the sniffed type
     * when the name's own extension is not one of that type's, so a saved file never opens as something else than what
     * was sniffed (a text file named duty.hta is saved as duty.hta.txt).
     */
    public static function downloadName(string $originalName, string $mime): string
    {
        $name = self::cleanName($originalName);
        $exts = self::EXTENSIONS[$mime] ?? ['bin'];
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, $exts, true) ? $name : $name . '.' . $exts[0];
    }

    /**
     * A private copy of $path (mode 0600 in the temporary directory, at most MAX_BYTES + 1 bytes read), which store()
     * checks and stores instead of the source.
     */
    private function privateCopy(string $path): string
    {
        $in = @fopen($path, 'rb');
        if ($in === false) {
            throw new CwException('no_file', 'there is no readable file to store', 400);
        }
        $tmp = @tempnam(sys_get_temp_dir(), 'cw-store-');
        $out = $tmp === false ? false : @fopen($tmp, 'wb');
        if ($tmp === false || $out === false) {
            fclose($in);
            if ($tmp !== false) {
                @unlink($tmp);
            }
            throw new CwException('file_store_failed', 'the file could not be copied for storing', 500);
        }
        @chmod($tmp, 0600);
        $n = stream_copy_to_stream($in, $out, self::MAX_BYTES + 1);
        $ok = $n !== false && fflush($out);
        fclose($in);
        fclose($out);
        if (!$ok) {
            @unlink($tmp);
            throw new CwException('file_store_failed', 'the file could not be copied for storing', 500);
        }
        return $tmp;
    }

    private function put(string $key, string $path, string $retainUntil): void
    {
        try {
            $this->storage->put($key, $path, new \DateTimeImmutable($retainUntil . ' 00:00:00', new \DateTimeZone('UTC')));
        } catch (FileStoreException $e) {
            ($this->log)("storing {$key} failed: {$e->reason}: {$e->getMessage()}");
            throw match ($e->reason) {
                'sha_mismatch' => new CwException('file_changed', 'the file changed while it was being stored: store it again', 409),
                'collision' => new CwException('file_corrupt', 'the file store holds other content under this fingerprint: tell an engineer (bin/verify_files.php)', 500),
                default => new CwException('file_store_failed', 'the file could not be written to the file store', 500),
            };
        }
    }
}
