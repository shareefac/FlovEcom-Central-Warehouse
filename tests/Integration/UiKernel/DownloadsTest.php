<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Controller\FilesController;
use CW\Ui\Kernel;

/**
 * GET /ui/files/{id} (documents.view; I23): the stored bytes as a hardened attachment (filename with its ASCII
 * fallback and UTF-8 form, the sandbox policy next to the kernel's, nosniff, no-store); 404 for an unknown file, 403
 * without documents.view, and a file whose bytes no longer match its sha256 is a 500 page with the request id (logged
 * with it), never sent. The store is CW_FILE_STORE_DIR = a temporary directory.
 */
final class DownloadsTest extends KernelUiTestCase
{
    private string $root;
    private string $src;
    private string|false $prevEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cw_dlt_' . bin2hex(random_bytes(5));
        $this->src = $this->root . '.src';
        mkdir($this->root, 0700);
        mkdir($this->src, 0700);
        $this->prevEnv = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->root);
        self::$log = [];
    }

    protected function tearDown(): void
    {
        putenv($this->prevEnv === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevEnv);
        foreach ([$this->root, $this->src] as $d) {
            if (is_dir($d) && str_starts_with(basename($d), 'cw_dlt_')) {
                exec('rm -rf ' . escapeshellarg($d));
            }
        }
        parent::tearDown();
    }

    /** @return array{id: int, sha256: string} */
    private function stored(string $bytes, string $name): array
    {
        $p = $this->src . '/' . bin2hex(random_bytes(3));
        file_put_contents($p, $bytes);
        $r = (new FileStore(self::$db, new LocalFileStorage($this->root)))->store(Caller::system('dl_test'), $p, $name, 'supplier_invoice', null);
        return ['id' => $r['id'], 'sha256' => $r['sha256']];
    }

    public function testAStoredFileIsAHardenedAttachment(): void
    {
        $f = $this->stored("Invoice 77\nTotal: 12.50 GBP\n", 'Facture été "77".txt');
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $r = $web->get('/ui/files/' . $f['id']);
        self::assertSame(200, $r->status, $r->describe());
        self::assertSame("Invoice 77\nTotal: 12.50 GBP\n", $r->body);
        self::assertSame('text/plain', $r->header('content-type'));
        self::assertSame("attachment; filename=\"Facture _t_ _77_.txt\"; filename*=UTF-8''Facture%20%C3%A9t%C3%A9%20%2277%22.txt", $r->header('content-disposition'));
        self::assertSame(['sandbox', Kernel::CSP], $r->headerValues('content-security-policy'));
        self::assertSame('nosniff', $r->header('x-content-type-options'));
        self::assertSame('no-store', $r->header('cache-control'));
        self::assertSame('DENY', $r->header('x-frame-options'));

        // I36: the saved file carries the SNIFFED type's extension, and no bidi override survives in its name.
        $hta = $this->stored(str_repeat("pallet count\n", 400) . "<script>alert(1)</script>\n", "duty\u{202E}atx.hta");
        $r = $web->get('/ui/files/' . $hta['id']);
        self::assertSame(['text/plain', "attachment; filename=\"dutyatx.hta.txt\"; filename*=UTF-8''dutyatx.hta.txt"],
            [$r->header('content-type'), $r->header('content-disposition')]);

        self::assertSame(404, $web->get('/ui/files/999999')->status);
        self::assertSame(403, $this->signIn($this->uiUser('viewer'))->get('/ui/files/' . $f['id'])->status, 'no documents.view');
        self::assertSame('attachment; filename="download"; filename*=UTF-8\'\'%E4%B8%AD%E6%96%87', FilesController::disposition('中文'));
        self::assertSame('attachment; filename="passwd"; filename*=UTF-8\'\'passwd', FilesController::disposition("../../etc/pass\r\nwd"));
    }

    public function testAChangedFileIsNeverSent(): void
    {
        $f = $this->stored('%PDF-1.4 not really', 'x.pdf');
        $path = $this->root . '/' . substr($f['sha256'], 0, 2) . '/' . $f['sha256'];
        chmod($path, 0640);
        file_put_contents($path, '%PDF-1.4 changed');
        $web = $this->signIn($this->uiUser('accountant'));
        $r = $web->get('/ui/files/' . $f['id']);
        self::assertSame(500, $r->status);
        $rid = (string) $r->header('x-request-id');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $rid);
        self::assertStringContainsString('file_corrupt', $r->text());
        self::assertStringContainsString($rid, $r->text(), 'the page names the request id');
        self::assertStringNotContainsString('changed', $r->body, 'the bytes are not sent');
        self::assertSame('text/html; charset=utf-8', $r->header('content-type'));
        self::assertNull($r->header('content-disposition'));
        $logged = array_values(array_filter(self::$log, static fn (string $m): bool => str_starts_with($m, $rid . ' ')));
        self::assertCount(1, $logged);
        self::assertStringContainsString("file {$f['id']} ({$f['sha256']}) does not match its sha256", $logged[0]);
    }
}
