<?php

declare(strict_types=1);

namespace CW\Expenses;

use CW\Caller;
use CW\CwException;
use CW\Files\FileStore;

/**
 * An expense's invoice file (the brief's point 2; docs/decisions.md EX9): a PDF, a JPEG or a PNG, at most 10 MB, checked by its FIRST BYTES
 * (never its name or what the browser said) and by the content sniffer agreeing; read once into memory and stored from a private copy of
 * those bytes, so what was checked is what is kept. Kept in CW's document store (FileStore: app.env file_store_dir, /srv/cw-docs on the
 * servers: outside every web directory, under its sha256, read-only, 7 years, re-hashed on every read), as kind `expense_invoice`. Served
 * only by ExpensesController::file (signed in, expenses.view) as an attachment of its sniffed type; never listed, never run.
 */
final class ExpenseFiles
{
    /** 10 MiB: the screens' upload limit (UiRequest::MAX_UPLOAD_BYTES). */
    public const MAX_BYTES = 10_485_760;
    public const KIND = 'expense_invoice';
    /** The first bytes of each type kept => its type (the sniffer must say the same). */
    public const MAGIC = ["%PDF-" => 'application/pdf', "\xFF\xD8\xFF" => 'image/jpeg', "\x89PNG\r\n\x1a\n" => 'image/png'];

    /**
     * Checks the file at $path and stores it; returns the stored_file id. Call inside the expense's transaction (the stored row rolls back with
     * it; bytes already put in the store stay there unnamed, which bin/verify_files.php counts as harmless).
     */
    public static function store(FileStore $files, Caller $caller, string $path, string $name): int
    {
        $bytes = self::read($path);
        $mime = self::type($bytes);
        $tmp = @tempnam(sys_get_temp_dir(), 'cw-expense-');
        if ($tmp === false) {
            throw new CwException('file_store_failed', 'the file could not be copied for storing', 500);
        }
        try {
            @chmod($tmp, 0600);
            if (file_put_contents($tmp, $bytes) !== strlen($bytes)) {
                throw new CwException('file_store_failed', 'the file could not be copied for storing', 500);
            }
            $stored = $files->store($caller, $tmp, $name, self::KIND, 'expense invoice');
        } finally {
            @unlink($tmp);
        }
        // What the store kept is exactly what was checked (its own sniff of its own copy, and the fingerprint).
        if ($stored['mime'] !== $mime || !hash_equals($stored['sha256'], hash('sha256', $bytes))) {
            throw new CwException('file_type', 'an expense keeps a PDF, a JPEG or a PNG only', 415);
        }
        return $stored['id'];
    }

    /** The file's bytes, at most MAX_BYTES (413 file_too_large; 400 empty_file / no_file). */
    public static function read(string $path): string
    {
        clearstatcache(true, $path);
        if (!is_file($path) || !is_readable($path)) {
            throw new CwException('no_file', 'there is no file to keep', 400, ['field' => 'file']);
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new CwException('no_file', 'there is no file to keep', 400, ['field' => 'file']);
        }
        try {
            $bytes = (string) stream_get_contents($h, self::MAX_BYTES + 1);
        } finally {
            fclose($h);
        }
        if ($bytes === '') {
            throw new CwException('empty_file', 'the file is empty', 400, ['field' => 'file']);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new CwException('file_too_large', 'the file is larger than ' . intdiv(self::MAX_BYTES, 1_048_576) . ' MB', 413, ['field' => 'file']);
        }
        return $bytes;
    }

    /** The type of the bytes by their first bytes, confirmed by the sniffer (415 file_type otherwise). */
    public static function type(string $bytes): string
    {
        foreach (self::MAGIC as $magic => $mime) {
            if (str_starts_with($bytes, $magic)) {
                $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                if ($sniffed === $mime) {
                    return $mime;
                }
                break;
            }
        }
        throw new CwException('file_type', 'an expense keeps a PDF, a JPEG or a PNG only', 415, ['field' => 'file']);
    }
}
