<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Db;

/**
 * Runs read-only work against ONE consistent snapshot of the database.
 *
 * CW sessions run READ COMMITTED (D20), so a check made of several queries (Invariants::check)
 * can see half of a concurrent transaction and report a mismatch that never existed. Inside
 * Snapshot::read() every query sees the same instant: REPEATABLE READ, START TRANSACTION WITH
 * CONSISTENT SNAPSHOT, READ ONLY. It takes no locks, so it never blocks the service; the
 * session's own isolation level is unchanged afterwards (SET TRANSACTION without SESSION applies
 * to the next transaction only).
 */
final class Snapshot
{
    /**
     * @template T
     * @param callable(Db): T $fn read-only work
     * @return T
     */
    public static function read(Db $db, callable $fn): mixed
    {
        if ($db->inTransaction()) {
            throw new \LogicException('Snapshot::read() starts its own transaction');
        }
        $pdo = $db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $result = $fn($db);
            $pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // the connection is gone; nothing to undo
            }
            throw $e;
        }
    }
}
