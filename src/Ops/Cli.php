<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Config;
use CW\Db;
use CW\DbSettings;
use CW\Schema\Migrator;

/**
 * Shared plumbing of the bin/ jobs run by cron (docs/ops.md): options, connection, schema
 * check, one run at a time, log lines.
 *
 * Every job accepts:
 *   --db=<schema>  target schema (default: CW_DB_NAME, else app.env db_name)
 *   --admin        use the admin login of db.env instead of the app login (test schemas, break-glass)
 *   --help
 * Exit codes: 0 ok (also: skipped because another run of the job holds its lock), 1 the job
 * found a problem (invariant mismatch, stale channel, a reservation it could not expire),
 * 2 usage, 3 cannot run (config, connection, schema not migrated, unexpected error).
 * Log lines go to stdout ("<UTC time> <job> [<schema>] message"), errors to stderr; secrets never.
 */
final class Cli
{
    public const OK = 0;
    public const PROBLEM = 1;
    public const USAGE = 2;
    public const CANNOT_RUN = 3;

    private function __construct(public readonly string $job, public readonly Db $db, public readonly string $schema)
    {
    }

    /**
     * Runs a job: parse options, connect, check the schema is current, take the job's lock
     * (unless $singleRun is false), call $fn and turn every failure into an exit code.
     *
     * With $strict the command line is read whole or not at all: a word unreadable() names ends the job with the usage and exit 2
     * before anything is connected to or read (a job that writes on `--apply` asks for it: bin/check_arrivals.php).
     *
     * @param list<string> $longopts the job's own getopt() long options
     * @param callable(self, array<string, mixed>): int $fn returns the exit code
     */
    public static function main(string $job, array $longopts, string $usage, callable $fn, bool $singleRun = true, bool $strict = false): int
    {
        $all = array_merge(['db:', 'admin', 'help'], $longopts);
        if ($strict) {
            $argv = is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
            $bad = self::unreadable(array_map(static fn (mixed $a): string => is_string($a) ? $a : '', array_slice($argv, 1)), $all);
            if ($bad !== null) {
                self::stderr($job, "{$bad}\n{$usage}\n  common: [--db=<schema>] [--admin]");
                return self::USAGE;
            }
        }
        $opts = getopt('', $all);
        if (!is_array($opts)) {
            $opts = [];
        }
        if (isset($opts['help'])) {
            fwrite(STDOUT, $usage . "\n  common: [--db=<schema>] [--admin]\n");
            return self::OK;
        }
        try {
            $cli = self::connect($job, $opts);
        } catch (\InvalidArgumentException $e) {
            self::stderr($job, $e->getMessage());
            return self::USAGE;
        } catch (\Throwable $e) {
            self::stderr($job, 'cannot connect: ' . self::describe($e));
            return self::CANNOT_RUN;
        }
        try {
            $cli->requireCurrentSchema();
            if ($singleRun && !$cli->tryLock()) {
                $cli->log('skipped: another run of this job holds its lock');
                return self::OK;
            }
            return $fn($cli, $opts);
        } catch (\InvalidArgumentException $e) {
            self::stderr($job, $e->getMessage());
            return self::USAGE;
        } catch (\Throwable $e) {
            self::stderr($job, "[{$cli->schema}] " . self::describe($e));
            return self::CANNOT_RUN;
        }
    }

    /**
     * The first word of a command line that getopt() would drop or misread without saying so, as a sentence; null when every
     * word is one of the job's options, written `--name` (a flag) or `--name=value`. What it names: a word that is no option
     * (getopt() stops reading there, so everything after it is lost), an option the job does not have (a misspelt one: dropped),
     * a value on a flag (`--apply=false` is `--apply` to getopt()), an option that takes a value given none or an empty one (an
     * unset shell variable: dropped), the value as a word of its own, and an option given twice (getopt() gives a list).
     *
     * @param list<string> $args the words after the script's name
     * @param list<string> $longopts getopt() long options: `name` a flag, `name:` takes a value
     */
    public static function unreadable(array $args, array $longopts): ?string
    {
        $takesValue = [];
        foreach ($longopts as $o) {
            $takesValue[rtrim($o, ':')] = str_ends_with($o, ':');
        }
        $seen = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z][a-z0-9-]*)(=.*)?$/sD', $arg, $m) !== 1) {
                return "not an option: {$arg}";
            }
            $name = $m[1];
            $value = isset($m[2]) ? substr($m[2], 1) : null;
            if (!isset($takesValue[$name])) {
                return "unknown option --{$name}";
            }
            if (isset($seen[$name])) {
                return "--{$name} is given twice";
            }
            $seen[$name] = true;
            if ($takesValue[$name] && ($value === null || $value === '')) {
                return "--{$name} needs a value: --{$name}=<value>";
            }
            if (!$takesValue[$name] && $value !== null) {
                return "--{$name} takes no value";
            }
        }
        return null;
    }

    /** @param array<string, mixed> $opts */
    public static function connect(string $job, array $opts): self
    {
        $config = Config::load();
        $schema = is_string($opts['db'] ?? null) ? $opts['db'] : $config->appDbName();
        if ($schema === null || !DbSettings::isValidIdentifier($schema)) {
            throw new \InvalidArgumentException('give --db=<schema> (or set CW_DB_NAME / app.env db_name)');
        }
        $settings = array_key_exists('admin', $opts) ? $config->dbAdmin() : $config->dbApp();
        return new self($job, Db::connect($settings->withDatabase($schema)), $schema);
    }

    /**
     * Refuses to run this code against a schema that is behind it (pending migrations) or ahead
     * of it (migrations this code does not have). Reads schema_migrations only (the app login
     * has SELECT on it and nothing more).
     */
    public function requireCurrentSchema(): void
    {
        $files = array_keys((new Migrator($this->db, Migrator::defaultDir()))->files());
        try {
            $applied = array_map('strval', $this->db->column('SELECT version FROM schema_migrations'));
        } catch (\PDOException $e) {
            throw new \RuntimeException("schema {$this->schema} has no readable schema_migrations table: " . self::describe($e), 0, $e);
        }
        $pending = array_values(array_diff($files, $applied));
        if ($pending !== []) {
            throw new \RuntimeException("schema {$this->schema} is not migrated (pending: " . implode(', ', $pending) . '); run bin/migrate.php first');
        }
        $unknown = array_values(array_diff($applied, $files));
        if ($unknown !== []) {
            throw new \RuntimeException("schema {$this->schema} has migrations this code does not have (" . implode(', ', $unknown) . '); deploy the matching code');
        }
    }

    /** One run of this job per schema at a time: a server-wide named lock, freed on disconnect. */
    public function tryLock(): bool
    {
        $name = "cw_job:{$this->job}:{$this->schema}";
        if (strlen($name) > 64) {
            $name = 'cw_job:' . sha1($this->job . "\0" . $this->schema);
        }
        return (int) $this->db->value('SELECT GET_LOCK(?, 0)', [$name]) === 1;
    }

    public function log(string $message): void
    {
        fwrite(STDOUT, self::stamp() . " {$this->job} [{$this->schema}] {$message}\n");
    }

    public function error(string $message): void
    {
        self::stderr($this->job, "[{$this->schema}] {$message}");
    }

    /**
     * An integer option within bounds, or the default.
     *
     * @param array<string, mixed> $opts
     */
    public static function intOpt(array $opts, string $name, int $default, int $min, int $max): int
    {
        $v = $opts[$name] ?? null;
        if ($v === null) {
            return $default;
        }
        if (!is_string($v) || preg_match('/^\d+$/', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
            throw new \InvalidArgumentException("--{$name} must be an integer from {$min} to {$max}");
        }
        return (int) $v;
    }

    public static function describe(\Throwable $e): string
    {
        $code = Db::driverCode($e);
        return get_class($e) . ($code !== null ? " (MySQL {$code})" : '') . ': ' . $e->getMessage();
    }

    private static function stderr(string $job, string $message): void
    {
        fwrite(STDERR, self::stamp() . " {$job} ERROR {$message}\n");
    }

    private static function stamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
