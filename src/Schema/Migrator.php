<?php

declare(strict_types=1);

namespace CW\Schema;

use CW\Db;

/**
 * Applies migrations/NNNN_name.sql files in order to the schema the given connection points at.
 *
 * - Bookkeeping in `schema_migrations` (version = file name, sha256 checksum, applied_at, duration).
 * - A file already applied whose content changed is an error (never edit an applied migration;
 *   add a new one). A recorded version with no file is an error (code older than the schema).
 * - MySQL DDL is not transactional: a file is recorded only after every statement succeeded;
 *   a failure stops the run and names the file and statement number.
 * - Concurrent runs on one schema are serialised with GET_LOCK.
 */
final class Migrator
{
    public const FILE_PATTERN = '/^\d{4}_[a-z0-9_]+\.sql$/';

    /** @var \Closure(string): void */
    private \Closure $log;

    public function __construct(private readonly Db $db, private readonly string $dir, ?callable $log = null)
    {
        $this->log = $log !== null ? \Closure::fromCallable($log) : static function (string $m): void {
        };
    }

    public static function defaultDir(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    /** @return array<string, string> version => absolute path, in apply order */
    public function files(): array
    {
        if (!is_dir($this->dir)) {
            throw new \RuntimeException("migrations directory {$this->dir} not found");
        }
        $files = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            if (!str_ends_with($name, '.sql')) {
                continue;
            }
            if (preg_match(self::FILE_PATTERN, $name) !== 1) {
                throw new \RuntimeException("migration file name {$name} does not match NNNN_lower_snake.sql");
            }
            $files[$name] = $this->dir . '/' . $name;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string, array{checksum: string, applied_at: string}> */
    public function applied(): array
    {
        $this->ensureTable();
        $out = [];
        foreach ($this->db->all('SELECT version, checksum, applied_at FROM schema_migrations ORDER BY version') as $r) {
            $out[(string) $r['version']] = ['checksum' => (string) $r['checksum'], 'applied_at' => (string) $r['applied_at']];
        }
        return $out;
    }

    /** @return list<string> versions not yet applied */
    public function pending(): array
    {
        $applied = $this->applied();
        $files = $this->files();
        $this->verify($files, $applied);
        return array_values(array_diff(array_keys($files), array_keys($applied)));
    }

    /** Applies every pending file. @return list<string> the versions applied by this call */
    public function migrate(): array
    {
        $schema = (string) $this->db->value('SELECT DATABASE()');
        if ($schema === '') {
            throw new \RuntimeException('migrator connection has no default database');
        }
        $lockName = 'cw_migrate:' . $schema;
        if ((int) $this->db->value('SELECT GET_LOCK(?, 120)', [$lockName]) !== 1) {
            throw new \RuntimeException("could not take the migration lock for {$schema}");
        }
        try {
            $done = [];
            $files = $this->files();
            foreach ($this->pending() as $version) {
                $this->applyFile($version, $files[$version]);
                $done[] = $version;
            }
            return $done;
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    public static function checksum(string $sql): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $sql));
    }

    private function applyFile(string $version, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("cannot read {$path}");
        }
        $statements = SqlSplitter::split($sql);
        $started = hrtime(true);
        ($this->log)("applying {$version} (" . count($statements) . ' statements)');
        foreach ($statements as $n => $stmt) {
            try {
                $this->db->pdo()->exec($stmt);
            } catch (\PDOException $e) {
                $head = preg_replace('/\s+/', ' ', substr($stmt, 0, 100));
                throw new \RuntimeException(sprintf(
                    '%s statement %d failed (%s...): %s',
                    $version,
                    $n + 1,
                    $head,
                    $e->getMessage(),
                ), 0, $e);
            }
        }
        $ms = intdiv(hrtime(true) - $started, 1_000_000);
        $this->db->exec(
            'INSERT INTO schema_migrations (version, checksum, applied_at, duration_ms) VALUES (?, ?, UTC_TIMESTAMP(6), ?)',
            [$version, self::checksum($sql), $ms],
        );
        ($this->log)("applied {$version} in {$ms} ms");
    }

    /**
     * @param array<string, string> $files
     * @param array<string, array{checksum: string, applied_at: string}> $applied
     */
    private function verify(array $files, array $applied): void
    {
        foreach ($applied as $version => $row) {
            if (!isset($files[$version])) {
                throw new \RuntimeException("schema has migration {$version} but no such file exists (deploy the matching code)");
            }
            $sql = file_get_contents($files[$version]);
            if ($sql === false || !hash_equals($row['checksum'], self::checksum($sql))) {
                throw new \RuntimeException("migration {$version} was changed after it was applied; add a new migration instead");
            }
        }
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . ' version VARCHAR(191) NOT NULL,'
            . ' checksum CHAR(64) NOT NULL,'
            . ' applied_at DATETIME(6) NOT NULL,'
            . ' duration_ms INT UNSIGNED NOT NULL,'
            . ' PRIMARY KEY (version)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }
}
