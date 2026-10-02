<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Files;

use CW\Files\LocalFileStorage;
use PHPUnit\Framework\TestCase;

/**
 * I36: deploy/staging/seal_file_store.sh on a scratch store (never /srv/cw-docs). A file LocalFileStorage linked in is
 * owned by whoever stored it and could be rewritten in place by them (the review's probe: chmod 0640, write, chmod
 * 0440); the sweep makes every stored file root:www-data 0440 and immutable, leaves other names alone, is silent and
 * changes nothing on a second run. Needs root and a file system with chattr (the staging box): skipped elsewhere.
 * No database.
 */
final class SealFileStoreTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../deploy/staging/seal_file_store.sh';

    private string $root;

    protected function setUp(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            self::markTestSkipped('the sweep runs as root');
        }
        exec('command -v chattr >/dev/null 2>&1 && command -v lsattr >/dev/null 2>&1', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('chattr/lsattr are not installed');
        }
        $this->root = '/var/tmp/cw_seal_' . bin2hex(random_bytes(5));
        mkdir($this->root, 0700);
        exec('chattr +i ' . escapeshellarg($this->root) . ' 2>/dev/null && chattr -i ' . escapeshellarg($this->root) . ' 2>/dev/null', $o, $c);
        if ($c !== 0) {
            rmdir($this->root);
            self::markTestSkipped('this file system has no immutable flag');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root) && str_starts_with(basename($this->root), 'cw_seal_')) {
            exec('chattr -R -i ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** @return array{0: int, 1: string} exit code, output */
    private function sweep(): array
    {
        exec('bash ' . escapeshellarg(self::SCRIPT) . ' ' . escapeshellarg($this->root) . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    private static function flags(string $path): string
    {
        return (string) strtok((string) shell_exec('lsattr -d ' . escapeshellarg($path) . ' 2>/dev/null'), ' ');
    }

    public function testStoredFilesAreSealedAndNothingElse(): void
    {
        $storage = new LocalFileStorage($this->root);
        $src = $this->root . '.src';
        mkdir($src, 0700);
        try {
            $keys = [];
            foreach (['invoice 1', 'delivery note 2'] as $bytes) {
                file_put_contents($src . '/f', $bytes);
                $key = hash('sha256', $bytes);
                $storage->put($key, $src . '/f', new \DateTimeImmutable('+7 years'));
                $keys[] = $key;
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($src));
        }
        $stored = $this->root . '/' . substr($keys[0], 0, 2) . '/' . $keys[0];
        // Before the sweep the owner can rewrite the file in place (what the review found): detection only.
        self::assertTrue(chmod($stored, 0640));
        self::assertSame(9, file_put_contents($stored, 'invoice 1'), 'same bytes back, so the content is unchanged');
        self::assertTrue(chmod($stored, 0440));
        $other = $this->root . '/' . substr($keys[0], 0, 2) . '/.not-a-stored-file';
        file_put_contents($other, 'x');

        [$code, $out] = $this->sweep();
        self::assertSame(0, $code, $out);
        self::assertMatchesRegularExpression('/seal_file_store \[' . preg_quote($this->root, '/') . '\] sealed=2 failed=0$/D', $out);
        foreach ($keys as $key) {
            $path = $this->root . '/' . substr($key, 0, 2) . '/' . $key;
            self::assertStringContainsString('i', self::flags($path), "{$key} is immutable");
            clearstatcache(true, $path);
            self::assertSame(['0', '440', 'www-data'], [(string) fileowner($path), substr(sprintf('%o', fileperms($path)), -3),
                (string) (posix_getgrgid((int) filegroup($path))['name'] ?? '')]);
            self::assertSame($key, hash_file('sha256', $path), 'the content is untouched');
        }
        self::assertStringNotContainsString('i', self::flags($other), 'only sha256 names are sealed');

        // Sealed: no in-place rewrite, no chmod, no removal, not even by root.
        self::assertFalse(@chmod($stored, 0640));
        self::assertFalse(@file_put_contents($stored, 'FORGED'));
        self::assertFalse(@unlink($stored));
        self::assertSame($keys[0], hash_file('sha256', $stored));
        // The store still reads it and recognises the same content.
        self::assertTrue($storage->exists($keys[0]));
        $h = $storage->open($keys[0]);
        self::assertSame('invoice 1', stream_get_contents($h));
        fclose($h);
        file_put_contents($this->root . '.again', 'invoice 1');
        try {
            self::assertFalse($storage->put($keys[0], $this->root . '.again', new \DateTimeImmutable('+7 years')), 'already stored, identical');
        } finally {
            unlink($this->root . '.again');
        }

        [$code, $out] = $this->sweep();
        self::assertSame([0, ''], [$code, $out], 'silent when nothing is new');
    }

    public function testNoStoreNothingToDo(): void
    {
        exec('bash ' . escapeshellarg(self::SCRIPT) . ' ' . escapeshellarg($this->root . '/missing') . ' 2>&1', $out, $code);
        self::assertSame([0, []], [$code, $out]);
    }
}
