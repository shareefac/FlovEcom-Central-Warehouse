<?php

declare(strict_types=1);

namespace CW;

/**
 * Runs an operation at most once per (scope, Idempotency-Key) and stores its result (D14, D29).
 *
 * Inside one transaction: claim the key (INSERT of a placeholder row), run the effect, store
 * its OpResult, write the audit row, commit. Calls with the same key first queue on a named
 * lock (GET_LOCK, H1), so they run one after another: the second finds the stored result and
 * replays it (one effect), or, when the first stored nothing, claims the key alone. Without the
 * queue, copies waiting on a claim that rolled back deadlocked each other on the freed key.
 * The same key with a different request gets 422 idempotency_key_reused.
 *
 * Scope: a channel caller -> (channel_id, ''); staff/system -> (NULL, source).
 * Thrown CwExceptions and any other error roll everything back and store nothing, so the
 * caller may retry the same key (e.g. a ship that arrived before its commit).
 */
final class Idempotency
{
    public const MAX_KEY_LENGTH = 191;
    /** How long a call waits for an earlier call with the same key before answering 409. */
    public const KEY_LOCK_WAIT_SEC = 30;
    private const MAX_RETRIES = 3;
    private const ER_DUP_ENTRY = 1062;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string, mixed> $request canonical request (the stored hash covers it)
     * @param callable(Db): OpResult $effect runs inside the transaction; no side effects outside the DB
     */
    public function run(
        Caller $caller,
        string $idemKey,
        string $action,
        string $path,
        array $request,
        ?string $entityType,
        ?string $entityId,
        callable $effect,
    ): OpResult {
        self::checkKey($idemKey);
        if ($this->db->inTransaction()) {
            throw new \LogicException('idempotent operations own their transaction; do not call them inside one');
        }
        $hash = hash('sha256', $action . "\n" . $path . "\n" . self::canonicalJson($request));

        $stored = $this->lookup($caller, $idemKey);
        if ($stored !== null) {
            return self::replay($stored, $hash);
        }

        // Same-key calls queue here, one at a time (H1). A waiter holds no row lock, so it cannot
        // deadlock; when the call ahead stored nothing, the next one claims the key on its own.
        $lock = $this->lockKey($caller, $idemKey);
        try {
            $stored = $this->lookup($caller, $idemKey);
            if ($stored !== null) {
                return self::replay($stored, $hash);
            }
            return $this->runClaimed($caller, $idemKey, $action, $path, $hash, $entityType, $entityId, $effect);
        } finally {
            $this->unlockKey($lock);
        }
    }

    /** @param callable(Db): OpResult $effect */
    private function runClaimed(Caller $caller, string $idemKey, string $action, string $path, string $hash,
        ?string $entityType, ?string $entityId, callable $effect): OpResult
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->db->transaction(function (Db $db) use ($caller, $idemKey, $action, $path, $hash, $entityType, $entityId, $effect): OpResult {
                    $this->claim($caller, $idemKey, $path, $hash);
                    $result = $effect($db);
                    $db->exec(
                        'UPDATE idempotency SET response_status = ?, response_body = ? '
                        . 'WHERE scope_channel_id = ? AND source = ? AND idem_key = ?',
                        [$result->status, self::json($result->body), $caller->channelId ?? 0, $caller->source, $idemKey],
                    );
                    Audit::write($db, $caller, $action, $entityType, $entityId, $idemKey, [
                        'status' => $result->status,
                        'result' => $result->body['result'] ?? $result->body['error'] ?? null,
                    ]);
                    return $result;
                });
            } catch (KeyAlreadyClaimed) {
                $stored = $this->lookup($caller, $idemKey);
                if ($stored !== null) {
                    return self::replay($stored, $hash);
                }
                if ($attempt >= self::MAX_RETRIES) {
                    throw new \RuntimeException('idempotency key claimed but no stored result');
                }
            } catch (RetryOperation $e) {
                if ($attempt >= self::MAX_RETRIES) {
                    throw new \RuntimeException('operation kept racing: ' . $e->getMessage(), 0, $e);
                }
                usleep(random_int(2_000, 10_000) * ($attempt + 1));
            }
        }
    }

    /**
     * Takes the named lock of (schema, scope, key). User-level lock names are server-wide and at
     * most 64 characters, hence the hash; the schema is part of it so test schemas never contend.
     */
    private function lockKey(Caller $caller, string $idemKey): string
    {
        $name = 'cw_idem:' . sha1(implode("\0", [$this->db->settings->database ?? '', (string) ($caller->channelId ?? 0), $caller->source, $idemKey]));
        if ((int) $this->db->value('SELECT GET_LOCK(?, ?)', [$name, self::KEY_LOCK_WAIT_SEC]) !== 1) {
            throw new CwException('idempotency_key_busy', 'another request with this Idempotency-Key is still being processed; retry later', 409);
        }
        return $name;
    }

    private function unlockKey(string $name): void
    {
        try {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (\Throwable) {
            // The connection is gone, and the lock with it.
        }
    }

    public static function checkKey(string $key): void
    {
        if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH || preg_match('/^[\x21-\x7e]+$/', $key) !== 1) {
            throw new CwException('bad_idempotency_key', 'Idempotency-Key must be 1-191 printable ASCII characters', 400);
        }
    }

    /** JSON with object keys sorted recursively (lists keep their order). */
    public static function canonicalJson(mixed $v): string
    {
        return self::json(self::canonical($v));
    }

    public static function canonical(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (!array_is_list($v)) {
            ksort($v, SORT_STRING);
        }
        return array_map(self::canonical(...), $v);
    }

    public static function json(mixed $v): string
    {
        if (is_array($v) && $v === []) {
            return '{}';
        }
        return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function claim(Caller $caller, string $idemKey, string $path, string $hash): void
    {
        try {
            $this->db->exec(
                'INSERT INTO idempotency (channel_id, source, idem_key, method, path, request_hash, response_status, response_body) '
                . "VALUES (?, ?, ?, 'POST', ?, ?, 102, JSON_OBJECT())",
                [$caller->channelId, $caller->source, $idemKey, substr($path, 0, 255), $hash],
            );
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === self::ER_DUP_ENTRY) {
                throw new KeyAlreadyClaimed();
            }
            throw $e;
        }
    }

    /** @return array{request_hash: string, response_status: int, response_body: string}|null */
    private function lookup(Caller $caller, string $idemKey): ?array
    {
        /** @var array{request_hash: string, response_status: int, response_body: string}|null $row */
        $row = $this->db->one(
            'SELECT request_hash, response_status, response_body FROM idempotency '
            . 'WHERE scope_channel_id = ? AND source = ? AND idem_key = ?',
            [$caller->channelId ?? 0, $caller->source, $idemKey],
        );
        return $row;
    }

    /** @param array{request_hash: string, response_status: int, response_body: string} $row */
    private static function replay(array $row, string $hash): OpResult
    {
        if (!hash_equals((string) $row['request_hash'], $hash)) {
            return new OpResult(422, [
                'error' => 'idempotency_key_reused',
                'message' => 'this Idempotency-Key was used for a different request',
            ]);
        }
        $body = json_decode((string) $row['response_body'], true, 512, JSON_THROW_ON_ERROR);
        return new OpResult((int) $row['response_status'], is_array($body) ? $body : [], true);
    }
}

