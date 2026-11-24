<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ui\UiRequest;
use PHPUnit\Framework\TestCase;

/**
 * The Codex check r1 of bb5c0a5, M12 (docs/decisions.md EX21-): an upload PHP could not take (no temporary folder, a disk that could not be
 * written, an extension that stopped it) reaches the screens as a failed upload, never as "no file chosen", so an expense is never saved
 * without the PDF that was sent, and a change never keeps the old PDF silently.
 */
final class ExpensesCodexR1UnitTest extends TestCase
{
    public function testAnUploadPhpCouldNotTakeIsAFailedUploadNotNoFile(): void
    {
        $files = $_FILES;
        $server = $_SERVER;
        try {
            $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/ui/profit/expenses/new', 'REMOTE_ADDR' => '198.51.100.20'];
            foreach ([UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION] as $err) {
                $_FILES = ['file' => ['name' => 'invoice.pdf', 'type' => 'application/pdf', 'tmp_name' => '', 'error' => $err, 'size' => 0]];
                $f = UiRequest::fromGlobals()->file('file');
                self::assertNotNull($f, "error {$err}: the upload is there, failed");
                self::assertSame([$err, ''], [$f['error'], $f['path']]);
            }
            $_FILES = ['file' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]];
            self::assertNull(UiRequest::fromGlobals()->file('file'), 'no file chosen');
        } finally {
            $_FILES = $files;
            $_SERVER = $server;
        }
    }
}
