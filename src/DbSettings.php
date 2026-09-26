<?php

declare(strict_types=1);

namespace CW;

/**
 * One database connection profile (host, credentials, target schema, TLS mode).
 * The password is kept outside the object (a private static map keyed by object id), so
 * var_dump/print_r/var_export/json_encode/(array) of a DbSettings never reveal it;
 * serialization is refused.
 */
final class DbSettings
{
    /** TLS is always required; these modes additionally verify the server certificate. */
    public const VERIFY_MODES = ['VERIFY_CA', 'VERIFY_IDENTITY'];

    /** @var array<int, string> object id => password */
    private static array $secrets = [];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        #[\SensitiveParameter] string $password,
        public readonly ?string $database = null,
        public readonly string $sslMode = 'REQUIRED',
        public readonly ?string $sslCa = null,
    ) {
        if ($host === '') {
            throw new ConfigException('database host is empty');
        }
        if ($port < 1 || $port > 65535) {
            throw new ConfigException('database port is out of range');
        }
        if ($user === '') {
            throw new ConfigException('database user is empty');
        }
        if ($database !== null && !self::isValidIdentifier($database)) {
            throw new ConfigException('database name must match [A-Za-z0-9_]{1,64}');
        }
        self::$secrets[spl_object_id($this)] = $password;
    }

    public function password(): string
    {
        return self::$secrets[spl_object_id($this)] ?? throw new \LogicException('DbSettings password is unavailable');
    }

    public function __clone()
    {
        throw new \LogicException('DbSettings cannot be cloned; use withDatabase()/withCredentials()');
    }

    public function __destruct()
    {
        unset(self::$secrets[spl_object_id($this)]);
    }

    public function verifiesServerCert(): bool
    {
        return in_array($this->sslMode, self::VERIFY_MODES, true);
    }

    public function withDatabase(?string $database): self
    {
        return new self($this->host, $this->port, $this->user, $this->password(), $database, $this->sslMode, $this->sslCa);
    }

    public function withCredentials(string $user, #[\SensitiveParameter] string $password): self
    {
        return new self($this->host, $this->port, $user, $password, $this->database, $this->sslMode, $this->sslCa);
    }

    public static function isValidIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) === 1;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'password' => $this->password() === '' ? '(empty)' : '(present)',
            'database' => $this->database,
            'sslMode' => $this->sslMode,
            'sslCa' => $this->sslCa,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('DbSettings must not be serialized (it holds a password)');
    }
}
