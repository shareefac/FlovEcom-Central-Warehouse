<?php

declare(strict_types=1);

namespace CW;

/**
 * Settings for CW, read from two root-only files and overridable from the environment.
 *
 *  /etc/cw/db.env   the managed-MySQL admin connection, DigitalOcean "key = value" format
 *                   (username, password, host, port, database, sslmode; optional ssl_ca).
 *  /etc/cw/app.env  app settings, KEY=value (db_user, db_password, db_name for the cw_app
 *                   login; db_host, db_port, db_sslmode, db_ssl_ca, which win over db.env's;
 *                   plus any other app keys, read with get()). The web service reads only this
 *                   file (loadApp()).
 *
 * Both files are PARSED, never sourced or eval'd: one "key = value" / "KEY=value" per line,
 * optional "export " prefix, "#" or ";" comment lines, optional matching quotes around the
 * value, no variable expansion, no inline comments (a "#" inside a value is kept).
 *
 * Environment overrides (tests, one-off scripts):
 *   CW_DB_ENV, CW_APP_ENV                     file paths
 *   CW_DB_HOST, CW_DB_PORT, CW_DB_SSLMODE, CW_DB_SSL_CA   both profiles
 *   CW_DB_NAME                                target schema for both profiles
 *   CW_DB_USER, CW_DB_PASSWORD                app profile credentials
 *   CW_DB_ADMIN_USER, CW_DB_ADMIN_PASSWORD    admin profile credentials
 *   CW_<KEY>                                  overrides app.env key <key> in get()
 *   CW_SLOT                                   test slot (tests/bootstrap.php)
 */
final class Config
{
    public const DEFAULT_DB_ENV = '/etc/cw/db.env';
    public const DEFAULT_APP_ENV = '/etc/cw/app.env';

    /** db.env key aliases (after lower-casing and stripping a cw_db_/db_/mysql_ prefix). */
    private const DB_KEY_ALIASES = [
        'user' => 'user', 'username' => 'user',
        'password' => 'password', 'pass' => 'password', 'passwd' => 'password',
        'host' => 'host', 'hostname' => 'host',
        'port' => 'port',
        'database' => 'database', 'dbname' => 'database', 'name' => 'database', 'db' => 'database',
        'sslmode' => 'sslmode', 'ssl_mode' => 'sslmode',
        'ssl_ca' => 'ssl_ca', 'sslrootcert' => 'ssl_ca', 'ca' => 'ssl_ca', 'ca_cert' => 'ssl_ca',
    ];

    /**
     * @param array<string, string> $db   normalised db.env (user, password, host, port, database, sslmode, ssl_ca)
     * @param array<string, string> $app  app.env, keys lower-cased
     * @param array<string, string> $env  process environment
     */
    private function __construct(
        private readonly array $db,
        private readonly array $app,
        private readonly array $env,
        public readonly string $dbEnvPath,
        public readonly string $appEnvPath,
    ) {
    }

    /** @param array<string, string>|null $env defaults to the process environment */
    public static function load(?array $env = null): self
    {
        $env ??= getenv();
        $dbPath = self::nonEmpty($env['CW_DB_ENV'] ?? null) ?? self::DEFAULT_DB_ENV;
        $appPath = self::nonEmpty($env['CW_APP_ENV'] ?? null) ?? self::DEFAULT_APP_ENV;

        $db = is_file($dbPath) ? self::normaliseDbKeys(self::parseEnvFile($dbPath)) : [];
        $app = is_file($appPath) ? self::parseEnvFile($appPath) : [];

        return new self($db, $app, $env, $dbPath, $appPath);
    }

    /**
     * The web service's view: app.env only. The admin file (db.env) is never opened, so a
     * php-fpm worker that can read app.env (0640 root:www-data) never needs, or gets, the admin
     * login. app.env must then carry db_host/db_port (and optionally db_sslmode/db_ssl_ca).
     * Unlike load(), a missing or unreadable app.env is an error (fail closed).
     *
     * @param array<string, string>|null $env defaults to the process environment
     */
    public static function loadApp(?array $env = null): self
    {
        $env ??= getenv();
        $appPath = self::nonEmpty($env['CW_APP_ENV'] ?? null) ?? self::DEFAULT_APP_ENV;
        return new self([], self::parseEnvFile($appPath), $env, '(not read: app-only config)', $appPath);
    }

    /** @param array<string, string> $db  @param array<string, string> $app  @param array<string, string> $env */
    public static function fromArrays(array $db, array $app = [], array $env = []): self
    {
        $lower = [];
        foreach ($app as $k => $v) {
            $lower[strtolower($k)] = $v;
        }
        return new self(self::normaliseDbKeys(array_change_key_case($db, CASE_LOWER)), $lower, $env, '(array)', '(array)');
    }

    /**
     * Parses a key/value file without executing anything. Keys are lower-cased.
     *
     * @return array<string, string>
     */
    public static function parseEnvFile(string $path): array
    {
        if (!is_readable($path)) {
            throw new ConfigException("config file {$path} is not readable");
        }
        $text = file_get_contents($path);
        if ($text === false) {
            throw new ConfigException("config file {$path} could not be read");
        }
        return self::parseEnv($text, $path);
    }

    /** @return array<string, string> */
    public static function parseEnv(string $text, string $source = 'config'): array
    {
        $out = [];
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }
            if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s?(.*)$/', $trimmed, $m)) {
                // Never echo the line: it may hold a secret.
                throw new ConfigException(sprintf('%s line %d is not "key = value"', $source, $i + 1));
            }
            $value = trim($m[2]);
            $len = strlen($value);
            if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $out[strtolower($m[1])] = $value;
        }
        return $out;
    }

    /**
     * @param array<string, string> $raw
     * @return array<string, string>
     */
    private static function normaliseDbKeys(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            $k = preg_replace('/^(cw_db_|db_|mysql_)/', '', strtolower($key)) ?? $key;
            if (isset(self::DB_KEY_ALIASES[$k])) {
                $out[self::DB_KEY_ALIASES[$k]] = $value;
            }
        }
        return $out;
    }

    /** Admin profile (doadmin from db.env): migrations, setup, test database resets. */
    public function dbAdmin(): DbSettings
    {
        $user = $this->envOr('CW_DB_ADMIN_USER', $this->db['user'] ?? null);
        $password = $this->envOr('CW_DB_ADMIN_PASSWORD', $this->db['password'] ?? null);
        if ($user === null || $password === null) {
            throw new ConfigException("admin database credentials missing (expected username/password in {$this->dbEnvPath})");
        }
        return $this->settings($user, $password, $this->envOr('CW_DB_NAME', null));
    }

    /** App profile (cw_app from app.env): everything the running service does. */
    public function dbApp(): DbSettings
    {
        $user = $this->envOr('CW_DB_USER', $this->app['db_user'] ?? null);
        $password = $this->envOr('CW_DB_PASSWORD', $this->app['db_password'] ?? null);
        if ($user === null || $password === null) {
            throw new ConfigException("app database credentials missing (expected db_user/db_password in {$this->appEnvPath})");
        }
        $name = $this->appDbName();
        if ($name === null) {
            throw new ConfigException("app database name missing (expected db_name in {$this->appEnvPath} or CW_DB_NAME)");
        }
        return $this->settings($user, $password, $name);
    }

    /** The schema the app uses: CW_DB_NAME, else app.env db_name. Never db.env's "defaultdb". */
    public function appDbName(): ?string
    {
        return $this->envOr('CW_DB_NAME', $this->app['db_name'] ?? null);
    }

    public function appDbUser(): ?string
    {
        return $this->envOr('CW_DB_USER', $this->app['db_user'] ?? null);
    }

    /** Raw app.env value (no environment override), e.g. the configured app schema/login. */
    public function appFile(string $key): ?string
    {
        return self::nonEmpty($this->app[strtolower($key)] ?? null);
    }

    /** App setting: env CW_<KEY> wins over app.env <key>. */
    public function get(string $key, ?string $default = null): ?string
    {
        $k = strtolower($key);
        return $this->envOr('CW_' . strtoupper($k), $this->app[$k] ?? null) ?? $default;
    }

    public function slot(): ?string
    {
        return $this->envOr('CW_SLOT', null);
    }

    private function settings(string $user, #[\SensitiveParameter] string $password, ?string $database): DbSettings
    {
        $host = $this->envOr('CW_DB_HOST', $this->app['db_host'] ?? $this->db['host'] ?? null);
        if ($host === null) {
            throw new ConfigException("database host missing (expected host in {$this->dbEnvPath})");
        }
        $port = $this->envOr('CW_DB_PORT', $this->app['db_port'] ?? $this->db['port'] ?? '3306');
        if (!ctype_digit((string) $port)) {
            throw new ConfigException('database port is not a number');
        }
        $mode = strtoupper($this->envOr('CW_DB_SSLMODE', $this->app['db_sslmode'] ?? $this->db['sslmode'] ?? 'REQUIRED') ?? 'REQUIRED');
        $mode = match ($mode) {
            'REQUIRED', 'REQUIRE', 'PREFERRED', 'PREFER', '' => 'REQUIRED',
            'VERIFY_CA', 'VERIFY-CA' => 'VERIFY_CA',
            'VERIFY_IDENTITY', 'VERIFY-FULL', 'VERIFY_FULL' => 'VERIFY_IDENTITY',
            default => throw new ConfigException("sslmode {$mode} is not allowed: CW always requires TLS"),
        };
        $ca = $this->envOr('CW_DB_SSL_CA', $this->app['db_ssl_ca'] ?? $this->db['ssl_ca'] ?? null);

        return new DbSettings((string) $host, (int) $port, $user, $password, $database, $mode, $ca);
    }

    private function envOr(string $name, ?string $fallback): ?string
    {
        return self::nonEmpty($this->env[$name] ?? null) ?? self::nonEmpty($fallback);
    }

    private static function nonEmpty(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
