<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Files;

use CW\Files\LocalFileStorage;
use CW\Output\PdfWriter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * bin/store_file.php and bin/verify_files.php end to end, as separate processes against the test schema (admin login)
 * with CW_FILE_STORE_DIR = a temporary directory: the stdout format, deduplication, the summary, exit 1 on a changed
 * file, orphans counted (exit 0), usage and refusals.
 */
final class FileToolsTest extends IntegrationTestCase
{
    private string $root;
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cw_ftt_' . bin2hex(random_bytes(5));
        $this->src = $this->root . '.src';
        mkdir($this->root, 0700);
        mkdir($this->src, 0700);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->src] as $d) {
            if (is_dir($d) && str_starts_with(basename($d), 'cw_ftt_')) {
                exec('rm -rf ' . escapeshellarg($d));
            }
        }
    }

    /** @return array{code: int, out: string, err: string} */
    private function tool(string $name, string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $env = getenv();
        $env['CW_FILE_STORE_DIR'] = $this->root;
        $p = proc_open([PHP_BINARY, "{$root}/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    public function testStoreThenVerify(): void
    {
        $pdf = (new PdfWriter('Duty evidence sample'))->output();
        $path = $this->src . '/duty-evidence.pdf';
        file_put_contents($path, $pdf);
        $sha = hash('sha256', $pdf);

        $r = $this->tool('store_file', "--file={$path}", '--kind=duty_evidence', '--note=sample');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(sprintf("id=%d sha256=%s size=%d mime=application/pdf deduped=0\n",
            (int) self::$db->value('SELECT id FROM stored_file'), $sha, strlen($pdf)), $r['out']);
        self::assertSame(['duty-evidence.pdf', 'duty_evidence', 'sample', 'system:store_file'],
            array_values((array) self::$db->one('SELECT original_name, kind, note, stored_actor FROM stored_file')));
        self::assertSame($pdf, file_get_contents($this->root . '/' . substr($sha, 0, 2) . '/' . $sha));
        self::assertStringEndsWith(" deduped=1\n", $this->tool('store_file', "--file={$path}", '--kind=other')['out']);

        $v = $this->tool('verify_files');
        self::assertSame(0, $v['code'], $v['err']);
        self::assertMatchesRegularExpression('/ verify_files \[cw_test_[a-z0-9_]+\] ok: checked=1 ok=1 missing=0 mismatch=0 orphans=0 ms=\d+\n$/D', $v['out']);

        // An orphan (bytes no row names) is counted, not a problem.
        $other = $this->src . '/other.txt';
        file_put_contents($other, 'orphan bytes');
        (new LocalFileStorage($this->root))->put(hash('sha256', 'orphan bytes'), $other, new \DateTimeImmutable('+7 years'));
        $v = $this->tool('verify_files');
        self::assertSame(0, $v['code']);
        self::assertStringContainsString('ok: checked=1 ok=1 missing=0 mismatch=0 orphans=1', $v['out']);
        self::assertStringContainsString('checked=1', $this->tool('verify_files', '--limit=1')['out']);

        // A changed file: reported with its id, exit 1.
        $stored = $this->root . '/' . substr($sha, 0, 2) . '/' . $sha;
        chmod($stored, 0640);
        file_put_contents($stored, $pdf . 'tampered');
        $v = $this->tool('verify_files');
        self::assertSame(1, $v['code']);
        self::assertStringContainsString('PROBLEM: checked=1 ok=0 missing=0 mismatch=1 orphans=1', $v['out']);
        self::assertStringContainsString('mismatch id=' . self::$db->value('SELECT id FROM stored_file') . " sha256={$sha}", $v['err']);
        unlink($stored);
        $v = $this->tool('verify_files');
        self::assertSame(1, $v['code']);
        self::assertStringContainsString("missing id=", $v['err']);
    }

    public function testUsageAndRefusals(): void
    {
        $txt = $this->src . '/page.html';
        file_put_contents($txt, "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>\n");
        self::assertSame(2, $this->tool('store_file', '--kind=other')['code'], 'no --file');
        self::assertSame(2, $this->tool('store_file', "--file={$txt}")['code'], 'no --kind');
        $r = $this->tool('store_file', "--file={$txt}", '--kind=other');
        self::assertSame(1, $r['code']);
        self::assertStringContainsString('type_not_allowed', $r['err']);
        self::assertSame('', $r['out']);
        $r = $this->tool('store_file', "--file={$txt}", '--kind=selfie');
        self::assertSame(1, $r['code']);
        self::assertStringContainsString('bad_kind', $r['err']);
        self::assertSame(2, $this->tool('verify_files', '--limit=0')['code']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stored_file'));
    }
}
