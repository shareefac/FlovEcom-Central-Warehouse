<?php

declare(strict_types=1);

namespace CW\Files;

/**
 * The staging backend of the file store (I23): content-addressed files in a local, non-web directory.
 *
 *   <root>/<first 2 hex of the sha256>/<sha256>     the content, mode 0440, never rewritten
 *   <root>/tmp/<random>                             a copy being written (fsync'd, then hard-linked into place)
 *
 * put() copies into tmp/ (fwrite + fflush + fsync), checks the copy hashes to the key, makes it read-only and
 * link()s it to its final name: link() fails when the name exists, so a stored file is never replaced, not even by a
 * racing put of the same key (that one finds the file, checks it holds the same content and returns false). On staging
 * the shard directories 00..ff are append-only (`chattr +a`, deploy/staging/install_file_store.sh): entries can be
 * added, never removed or renamed, by anyone including root, until the attribute is taken off.
 *
 * DETECT-only for content until sealed (I36): a file is linked in owned by the process that stored it (php-fpm's
 * www-data, or root for the CLI), mode 0440, and its owner can chmod it back and rewrite it in place; `chattr +a` on
 * the shard stops rm and mv only. deploy/staging/seal_file_store.sh (root, every minute) chowns new files to root and
 * makes them immutable (`chattr +i`); until then, and for root, read() and bin/verify_files.php find a changed file
 * (re-hash), they do not prevent it. The Object Lock backend prevents it.
 *
 * The root must be absolute and outside anything a web server or a deploy touches: no `public` path segment, not under
 * /var/www, not inside the code directory (a deploy rsyncs it with --delete). The retention dates are recorded on the
 * rows (stored_file.retain_until, document_file.retain_until) and not enforced here; the Object Lock backend enforces
 * them.
 */
final class LocalFileStorage implements FileStorage
{
    private const DIR_MODE = 02770;
    private const FILE_MODE = 0440;
    private const CHUNK = 1_048_576;

    private readonly string $root;

    public function __construct(string $root)
    {
        if ($root === '' || $root[0] !== '/') {
            throw new \InvalidArgumentException('the file store root must be an absolute path');
        }
        $given = rtrim($root, '/');
        self::refuseLocation($given === '' ? '/' : $given);
        $real = realpath($given === '' ? '/' : $given);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException("the file store root {$root} does not exist (deploy/staging/install_file_store.sh creates it)");
        }
        self::refuseLocation($real);
        $this->root = $real;
    }

    public function name(): string
    {
        return 'local';
    }

    public function put(string $key, string $path, \DateTimeImmutable $retainUntil): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $key) !== 1) {
            throw new FileStoreException('sha_mismatch', 'a file store key is the sha256 of the content in lower-case hex');
        }
        $final = $this->path($key);
        if (is_file($final)) {
            return $this->same($final, $key);
        }
        $this->dir(dirname($final));
        $this->dir($this->root . '/tmp');
        $tmp = $this->root . '/tmp/' . bin2hex(random_bytes(16));
        $in = @fopen($path, 'rb');
        if ($in === false) {
            throw new FileStoreException('io', 'cannot read the file to store');
        }
        $out = @fopen($tmp, 'xb');
        if ($out === false) {
            fclose($in);
            throw new FileStoreException('io', 'cannot write in the file store');
        }
        $ctx = hash_init('sha256');
        $ok = true;
        try {
            while ($ok && !feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) {
                    $ok = false;
                    break;
                }
                hash_update($ctx, $chunk);
                $ok = fwrite($out, $chunk) === strlen($chunk);
            }
            $ok = $ok && fflush($out) && fsync($out);
        } finally {
            fclose($in);
            fclose($out);
        }
        // The copy is what gets kept, so the copy is what must hash to the key (the source may change meanwhile).
        $sha = hash_final($ctx);
        if (!$ok) {
            @unlink($tmp);
            throw new FileStoreException('io', 'writing the file store copy failed');
        }
        if (!hash_equals($key, $sha)) {
            @unlink($tmp);
            throw new FileStoreException('sha_mismatch', 'the content does not hash to its key');
        }
        @chmod($tmp, self::FILE_MODE);
        if (!@link($tmp, $final)) {
            @unlink($tmp);
            if (is_file($final)) {
                return $this->same($final, $key); // a concurrent put of the same content won
            }
            throw new FileStoreException('io', 'cannot link the stored file into place');
        }
        @unlink($tmp);
        return true;
    }

    /** @return resource */
    public function open(string $key)
    {
        $h = preg_match('/^[0-9a-f]{64}$/D', $key) === 1 ? @fopen($this->path($key), 'rb') : false;
        if ($h === false) {
            throw new FileStoreException('missing', 'the stored file is missing');
        }
        return $h;
    }

    public function exists(string $key): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $key) === 1 && is_file($this->path($key));
    }

    /** Retention is recorded on the rows and not enforced by this backend (class docblock); the key must be stored. */
    public function extendRetention(string $key, \DateTimeImmutable $until): void
    {
        if (!$this->exists($key)) {
            throw new FileStoreException('missing', 'the stored file is missing');
        }
    }

    /** @return \Generator<int, string> */
    public function keys(): iterable
    {
        foreach (glob($this->root . '/[0-9a-f][0-9a-f]', GLOB_ONLYDIR) ?: [] as $shard) {
            $prefix = basename($shard);
            foreach (scandir($shard) ?: [] as $f) {
                if (preg_match('/^[0-9a-f]{64}$/D', $f) === 1 && str_starts_with($f, $prefix)) {
                    yield $f;
                }
            }
        }
    }

    private function path(string $key): string
    {
        return $this->root . '/' . substr($key, 0, 2) . '/' . $key;
    }

    /** An existing stored file must be exactly the key's content: false (nothing written), else collision. */
    private function same(string $file, string $key): bool
    {
        if (!hash_equals($key, (string) hash_file('sha256', $file))) {
            throw new FileStoreException('collision', 'the file store already holds other content under this key');
        }
        return false;
    }

    private function dir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, self::DIR_MODE) && !is_dir($dir)) {
            throw new FileStoreException('io', 'cannot create a file store directory');
        }
        @chmod($dir, self::DIR_MODE);
    }

    /** Refuses a root a web server could serve or a deploy could wipe. */
    private static function refuseLocation(string $p): void
    {
        $segments = array_values(array_filter(explode('/', $p), static fn (string $s): bool => $s !== ''));
        if ($segments === [] || in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new \InvalidArgumentException("the file store root {$p} is not a plain absolute directory");
        }
        if (in_array('public', $segments, true)) {
            throw new \InvalidArgumentException("the file store root {$p} has a `public` path segment: stored files are never in a web directory");
        }
        if ($p === '/var/www' || str_starts_with($p . '/', '/var/www/')) {
            throw new \InvalidArgumentException("the file store root {$p} is under /var/www: stored files are never in a web directory");
        }
        $code = dirname(__DIR__, 2);
        foreach (array_unique([$code, realpath($code) ?: $code]) as $c) {
            if ($p === $c || str_starts_with($p . '/', $c . '/')) {
                throw new \InvalidArgumentException("the file store root {$p} is inside the code directory {$c}: a deploy would wipe it");
            }
        }
    }
}
