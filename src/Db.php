<?php

declare(strict_types=1);

namespace CW;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO factory and small helpers. Every CW connection:
 *  - uses TLS (connect fails if the session is not encrypted; VERIFY_* modes also check the cert),
 *  - times out after 5 s while connecting,
 *  - runs with time_zone '+00:00', READ COMMITTED, a fixed sql_mode (no ANSI_QUOTES) and
 *    utf8mb4_0900_ai_ci, whatever the server defaults are (DO managed MySQL defaults to ANSI),
 *  - throws PDOException on every error, uses native prepares and returns native ints.
 */
final class Db
{
    public const CONNECT_TIMEOUT_SEC = 5;
    public const SYSTEM_CA_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';
    public const SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,'
        . 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
    public const DEADLOCK_RETRIES = 3;

    private const ER_LOCK_DEADLOCK = 1213;

    /** Deadlocks retried by transaction() in this process (tests assert "no deadlocks" with it). */
    private static int $deadlockRetries = 0;

    /** Transactions transaction() has begun on this connection (I7). */
    private int $txSerial = 0;

    private function __construct(private readonly PDO $pdo, public readonly DbSettings $settings)
    {
    }

    public static function connect(DbSettings $s): self
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $s->host, $s->port);
        if ($s->database !== null) {
            $dsn .= ';dbname=' . $s->database;
        }
        $ca = $s->sslCa ?? self::SYSTEM_CA_BUNDLE;
        if ($s->verifiesServerCert() && ($s->sslCa === null || !is_readable($s->sslCa))) {
            throw new ConfigException("sslmode {$s->sslMode} needs a readable ssl_ca file");
        }
        $pdo = new PDO($dsn, $s->user, $s->password(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SEC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            // Any SSL option makes mysqlnd negotiate TLS; the check below makes it mandatory.
            PDO::MYSQL_ATTR_SSL_CA => $ca,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => $s->verifiesServerCert(),
        ]);

        $pdo->exec("SET SESSION time_zone = '+00:00', SESSION transaction_isolation = 'READ-COMMITTED', "
            . "SESSION sql_mode = '" . self::SQL_MODE . "', SESSION collation_connection = 'utf8mb4_0900_ai_ci'");

        $cipher = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
        if (!is_array($cipher) || ($cipher[1] ?? '') === '') {
            throw new ConfigException('database connection is not encrypted (TLS is required)');
        }

        return new self($pdo, $s);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $v = (int) $v;
            }
            $st->bindValue(is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k), $v, self::pdoType($v));
        }
        $st->execute();
        return $st;
    }

    /** @param array<int|string, mixed> $params @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int|string, mixed> $params @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** First column of the first row, or null when there is no row. @param array<int|string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<int|string, mixed> $params @return list<mixed> */
    public function column(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Executes a write; returns the affected-row count. @param array<int|string, mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** Executes an INSERT; returns the new AUTO_INCREMENT id (0 if none). @param array<int|string, mixed> $params */
    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Number of transactions transaction() has begun on this connection: it moves on at every real
     * beginTransaction() (a deadlock retry included), never when a call joins an open transaction.
     * It lets per-operation state tell this transaction from a rolled-back one (Stock's pending value
     * sequence, I7).
     */
    public function transactionSerial(): int
    {
        return $this->txSerial;
    }

    /**
     * Runs $fn($this) inside a transaction and returns its result.
     *
     * On a deadlock (MySQL 1213) the transaction is rolled back and $fn is run again, up to
     * DEADLOCK_RETRIES more times, with a short random back-off; any other exception rolls back
     * and is rethrown. $fn must therefore have no side effects outside the database.
     * Called while a transaction is already open, $fn simply joins it (no retry at this level:
     * the deadlock propagates to the outermost transaction(), which retries the whole unit).
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn, int $retries = self::DEADLOCK_RETRIES): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        for ($attempt = 0; ; $attempt++) {
            $this->pdo->beginTransaction();
            $this->txSerial++;
            try {
                $result = $fn($this);
                $this->pdo->commit();
                return $result;
            } catch (\Throwable $e) {
                $this->rollBackQuietly();
                if ($attempt < $retries && self::isDeadlock($e)) {
                    self::$deadlockRetries++;
                    usleep(random_int(5_000, 25_000) * ($attempt + 1));
                    continue;
                }
                throw $e;
            }
        }
    }

    /** Number of deadlocks transaction() has retried in this process. */
    public static function deadlockRetries(): int
    {
        return self::$deadlockRetries;
    }

    public static function isDeadlock(\Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if (self::driverCode($cur) === self::ER_LOCK_DEADLOCK) {
                return true;
            }
        }
        return false;
    }

    /** MySQL driver error code of a PDOException (e.g. 1062 duplicate key), or null. */
    public static function driverCode(\Throwable $e): ?int
    {
        if ($e instanceof PDOException && isset($e->errorInfo[1]) && is_int($e->errorInfo[1])) {
            return $e->errorInfo[1];
        }
        if ($e instanceof PDOException) {
            // "SQLSTATE[42000]: Syntax error or access violation: 1142 ..." or, from connect,
            // "SQLSTATE[HY000] [1045] Access denied ...".
            $msg = $e->getMessage();
            if (preg_match('/^SQLSTATE\[\w{5}\]: [^:]*: (\d{4,5}) /', $msg, $m) === 1
                || preg_match('/^SQLSTATE\[\w{5}\] \[(\d{4,5})\] /', $msg, $m) === 1) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /** Quotes an identifier (schema/table/column) after validating it. */
    public static function ident(string $name): string
    {
        if (!DbSettings::isValidIdentifier($name)) {
            throw new \InvalidArgumentException('invalid SQL identifier');
        }
        return '`' . $name . '`';
    }

    private function rollBackQuietly(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (\Throwable) {
            // The server already rolled back (deadlock victim) or the link is gone; nothing to add.
        }
    }

    private static function pdoType(mixed $v): int
    {
        return match (true) {
            is_int($v) => PDO::PARAM_INT,
            $v === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }
}
