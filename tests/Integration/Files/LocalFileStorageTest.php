<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Files;

use CW\Files\FileStorage;
use CW\Files\FileStoreException;
use CW\Files\LocalFileStorage;
use PHPUnit\Framework\TestCase;

/**
 * I23, the staging backend: content-addressed, write-once (link() into place, never replaced), read-only files,
 * and a root that can be neither served by a web server nor wiped by a deploy. The interface itself has no way to
 * delete, rename or overwrite. (No database: the file system of the slot's box, in a temp directory.)
 */
final class LocalFileStorageTest extends TestCase
{
    private string $root;
    private string $src;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cw_lfs_' . bin2hex(random_bytes(5));
        mkdir($this->root, 0700);
        $this->src = $this->root . '.src';
        mkdir($this->src, 0700);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->src] as $d) {
            if (is_dir($d) && str_starts_with(basename($d), 'cw_lfs_')) {
                exec('rm -rf ' . escapeshellarg($d));
            }
        }
    }

    private function file(string $bytes): string
    {
        $p = $this->src . '/' . bin2hex(random_bytes(4));
        file_put_contents($p, $bytes);
        return $p;
    }

    public function testPutOpenExistsKeysAndWriteOnce(): void
    {
        $s = new LocalFileStorage($this->root);
        self::assertSame('local', $s->name());
        $a = $this->file('invoice one');
        $key = hash('sha256', 'invoice one');
        $retain = new \DateTimeImmutable('+7 years');
        self::assertFalse($s->exists($key));
        self::assertTrue($s->put($key, $a, $retain));
        self::assertTrue($s->exists($key));
        $final = $this->root . '/' . substr($key, 0, 2) . '/' . $key;
        self::assertFileExists($final);
        self::assertSame(0440, fileperms($final) & 0777, 'read-only');
        self::assertSame(02770, fileperms(dirname($final)) & 07777, 'shard directory: group-shared, setgid');
        self::assertSame([], array_values(array_diff(scandir($this->root . '/tmp') ?: [], ['.', '..'])), 'no copy left behind in tmp/');
        $h = $s->open($key);
        self::assertSame('invoice one', stream_get_contents($h));
        fclose($h);

        $inode = fileinode($final);
        $mtime = filemtime($final);
        self::assertFalse($s->put($key, $this->file('invoice one'), $retain), 'the same content again: nothing written');
        clearstatcache();
        self::assertSame([$inode, $mtime], [fileinode($final), filemtime($final)], 'the stored file is untouched');

        $b = $this->file('photo two');
        $s->put(hash('sha256', 'photo two'), $b, $retain);
        $keys = iterator_to_array($s->keys(), false);
        sort($keys);
        $want = [$key, hash('sha256', 'photo two')];
        sort($want);
        self::assertSame($want, $keys);

        try {
            $s->open(str_repeat('0', 64));
            self::fail('opened a missing key');
        } catch (FileStoreException $e) {
            self::assertSame('missing', $e->reason);
        }
        self::assertFalse($s->exists('../../etc/passwd'));
    }

    public function testAWrongKeyACollisionAndAMalformedKey(): void
    {
        $s = new LocalFileStorage($this->root);
        $f = $this->file('some content');
        foreach ([hash('sha256', 'other content'), strtoupper(hash('sha256', 'some content')), 'abc', '../' . str_repeat('a', 61)] as $wrong) {
            try {
                $s->put($wrong, $f, new \DateTimeImmutable());
                self::fail("stored under {$wrong}");
            } catch (FileStoreException $e) {
                self::assertSame('sha_mismatch', $e->reason);
            }
        }
        self::assertSame([], iterator_to_array($s->keys(), false));
        self::assertSame([], array_values(array_diff(scandir($this->root . '/tmp') ?: [], ['.', '..'])), 'a refused copy is removed');

        // A file already under the key with OTHER content (tampering, a broken disk): never replaced, reported.
        $key = hash('sha256', 'some content');
        mkdir($this->root . '/' . substr($key, 0, 2), 0770);
        file_put_contents($this->root . '/' . substr($key, 0, 2) . '/' . $key, 'tampered');
        try {
            $s->put($key, $f, new \DateTimeImmutable());
            self::fail('a collision was accepted');
        } catch (FileStoreException $e) {
            self::assertSame('collision', $e->reason);
        }
        self::assertSame('tampered', file_get_contents($this->root . '/' . substr($key, 0, 2) . '/' . $key), 'and it was not overwritten');
    }

    public function testRootsAWebServerOrADeployCouldReachAreRefused(): void
    {
        $repo = dirname(__DIR__, 3);
        $public = $this->root . '/public';
        mkdir($public);
        foreach ([
            'relative' => 'tmp/cw-docs',
            'missing' => $this->root . '/nope',
            'a public segment' => $public,
            'under /var/www' => '/var/www',
            'under /var/www, deeper' => '/var/www/cw-docs',
            'the code directory' => $repo,
            'inside the code directory' => $repo . '/tests',
            'the repo public directory' => $repo . '/public',
            'dot segments' => $this->root . '/../' . basename($this->root),
            'the file system root' => '/',
        ] as $what => $root) {
            try {
                new LocalFileStorage($root);
                self::fail("accepted {$what}: {$root}");
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage(), $what);
            }
        }
        // A symlink to a forbidden place is judged by where it points.
        $link = $this->root . '/link';
        symlink($repo . '/tests', $link);
        $this->expectException(\InvalidArgumentException::class);
        new LocalFileStorage($link);
    }

    public function testTheInterfaceCannotDeleteRenameOrOverwrite(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(FileStorage::class))->getMethods());
        sort($methods);
        self::assertSame(['exists', 'extendRetention', 'keys', 'name', 'open', 'put'], $methods, 'extend a retention, never delete, rename or overwrite');
        $public = array_map(static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(LocalFileStorage::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($public);
        self::assertSame(['__construct', 'exists', 'extendRetention', 'keys', 'name', 'open', 'put'], $public);
        // The local backend records retention on the rows only; extending a key it does not hold is a missing file.
        $s = new LocalFileStorage($this->root);
        $key = hash('sha256', 'kept');
        $s->put($key, $this->file('kept'), new \DateTimeImmutable('+7 years'));
        $s->extendRetention($key, new \DateTimeImmutable('+9 years'));
        try {
            $s->extendRetention(hash('sha256', 'never stored'), new \DateTimeImmutable('+9 years'));
            self::fail('extended a key that is not stored');
        } catch (FileStoreException $e) {
            self::assertSame('missing', $e->reason);
        }
    }
}
