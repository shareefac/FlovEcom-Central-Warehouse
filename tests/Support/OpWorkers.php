<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Caller;
use CW\Db;

/**
 * Forced interleavings for lock-order tests: worker processes (tests/Support/op_worker.php, one
 * DB connection each) are started one at a time, and the test waits on
 * performance_schema.data_locks until each one is parked on the lock it should be parked on.
 * Use in a StockTestCase; call stopWorkers() from tearDown().
 */
trait OpWorkers
{
    /** @var array<int, array{0: resource, 1: array<int, resource>}> */
    private array $procs = [];
    /** @var array<int, int> exit codes seen by running() (proc_get_status reports one only once) */
    private array $exitCodes = [];

    protected function stopWorkers(): void
    {
        foreach ($this->procs as [$p, $pipes]) {
            foreach ($pipes as $pp) {
                if (is_resource($pp)) {
                    fclose($pp);
                }
            }
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        $this->procs = [];
        $this->exitCodes = [];
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    protected static function op(Caller $site, string $op, array $fields): array
    {
        return ['op' => $op, 'channel_id' => $site->channelId, 'channel_code' => substr($site->actor, strlen('channel:'))] + $fields;
    }

    /** @param list<array<string, mixed>> $ops */
    protected function spawn(array $ops): int
    {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __DIR__ . '/op_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($p);
        fwrite($pipes[0], json_encode(['ops' => $ops], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        unset($pipes[0]);
        $this->procs[] = [$p, $pipes];
        return array_key_last($this->procs);
    }

    /**
     * Waits (at most $timeoutSec) for worker $i to finish and returns its answer. A worker still
     * running at the deadline fails the test (it is blocked; tearDown terminates it).
     *
     * @return array{results: list<array<string, mixed>>, deadlocks: int}
     */
    protected function collect(int $i, int $timeoutSec = 60): array
    {
        [$p, $pipes] = $this->procs[$i];
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSec;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$this->running($i)) {
                break;
            }
            self::assertLessThan($deadline, microtime(true), "worker {$i} gave no answer within {$timeoutSec}s (blocked?)");
            $r = [$pipes[1], $pipes[2]];
            $w = null;
            $e = null;
            @stream_select($r, $w, $e, 0, 50_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        unset($this->procs[$i]);
        $code = $this->exitCodes[$i];
        unset($this->exitCodes[$i]);
        $out = json_decode(trim($stdout), true);
        self::assertTrue($code === 0 && is_array($out), "worker {$i} failed (exit {$code}): " . substr($stderr . $stdout, 0, 3000));
        return $out;
    }

    /** True while worker $i is still running (e.g. parked on a lock). */
    protected function running(int $i): bool
    {
        if (isset($this->exitCodes[$i])) {
            return false;
        }
        $st = proc_get_status($this->procs[$i][0]);
        if ($st['running']) {
            return true;
        }
        $this->exitCodes[$i] = (int) $st['exitcode']; // reported by the first call after the exit only
        return false;
    }

    protected function waitForLock(string $table, string $status, float $timeoutSec = 30.0): void
    {
        $until = microtime(true) + $timeoutSec;
        while (microtime(true) < $until) {
            if ($this->locks($table, $status) > 0) {
                return;
            }
            usleep(2_000);
        }
        self::fail("no {$status} record lock on {$table} within {$timeoutSec}s");
    }

    /** Record locks on $table (this schema) in $status (GRANTED / WAITING) right now. */
    protected function locks(string $table, string $status): int
    {
        return (int) self::$db->value(
            "SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA = DATABASE() AND OBJECT_NAME = ? "
            . "AND LOCK_TYPE = 'RECORD' AND LOCK_STATUS = ?",
            [$table, $status],
        );
    }

    /** The cycle InnoDB reported last (SHOW ENGINE INNODB STATUS), trimmed. */
    protected function latestDeadlock(): string
    {
        try {
            $st = (string) (self::$db->one('SHOW ENGINE INNODB STATUS')['Status'] ?? '');
        } catch (\Throwable $e) {
            return '(innodb status unavailable: ' . $e->getMessage() . ')';
        }
        if (preg_match('/LATEST DETECTED DEADLOCK\n-+\n(.*?)\n-{10,}\nTRANSACTIONS/s', $st, $m) !== 1) {
            return '(no deadlock section)';
        }
        $keep = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^(\*\*\*|RECORD LOCKS|TABLE LOCK|UPDATE|INSERT|SELECT|MySQL thread|\d{4}-\d\d-\d\d)/', $line) === 1) {
                $keep[] = substr($line, 0, 220);
            }
        }
        return "LATEST DETECTED DEADLOCK:\n" . implode("\n", $keep);
    }

    /** A second connection with an open transaction (what an in-flight call of another session holds). */
    protected static function session(): Db
    {
        $d = TestDb::connect();
        $d->pdo()->beginTransaction();
        return $d;
    }
}
