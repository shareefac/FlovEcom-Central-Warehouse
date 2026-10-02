<?php

declare(strict_types=1);

/**
 * CW concurrency hammer (plan §14 "CW (automated)"): real processes, real connections, real races.
 *
 *   scripts/remote.sh hammer php tests/concurrency/hammer.php [--workers=24] [--seconds=30] [--only=1,4,5] [--seed=N]
 *
 * Scenarios (each prints PASS/FAIL rows; the exit status is 0 only when every row passes):
 *   1  200 reserve attempts from 3 live channels race for a strict item with on_hand = 10:
 *      exactly 10 held, 190 refused (409 short), availability never negative at any observed
 *      point (a polling observer, every response, and a replay of the item's ledger row by row).
 *   2  100 multi-line orders (2-5 lines in random order, 5 strict items, a duplicate listing and a
 *      "10 x" listing) placed at once: no deadlock error surfaces, each order is all-or-nothing,
 *      held = the exact sum of the accepted orders; then half are paid and half abandoned at once.
 *   3  20 processes send the SAME Idempotency-Key at the same instant, for every operation type
 *      (reserve, commit, ship, unship, return, goods_in, release, cancel x2), plus the same order
 *      under 20 different keys and one key with two different bodies: exactly one effect each;
 *      and 20 copies of a ship before its commit: all 409 not_committed, nothing stored, no
 *      deadlock (this round found decisions.md H1).
 *   4  reserve / extend / re-reserve / commit (with and without a hold, other lines) / release
 *      (current and stale attempt) / tombstones / replays / cancel / ship / goods-in, interleaved
 *      with two expiry crons for --seconds: every consistent snapshot taken during the run
 *      satisfies CW\Invariants, every negative availability of a strict item is a flagged
 *      oversell_event, and nothing fails.
 *   5  the per-item value sequence (C0, I3) under cross-warehouse races for --seconds: staff
 *      movements of 1-4 items at MAIN and VERIFY in random line order (goods-in with a cost,
 *      write-offs, adjustments), MAIN -> VERIFY transfers, reserve -> commit -> ship, commit ->
 *      cancel to VERIFY and VERIFY counts. An observer polls the seqs every 100 ms (one statement:
 *      no item ever shows a gap) and checks CW\Invariants on a consistent snapshot every ~2 s; at
 *      the end every item is numbered 1..N and replaying its on_hand rows in seq order gives every
 *      row's balance_after and the item's total on_hand.
 *
 * Processes: pcntl_fork. Every worker opens its OWN connection after the fork; the parent holds
 * NO connection while children live (a TLS socket shared across fork would be corrupted when a
 * child exits). Workers connect, wait on a barrier, then start within a millisecond of each other.
 * Connection budget: the staging cluster allows 76 connections in total, shared by every slot;
 * the hammer never holds more than 30 at once (workers + observer + parent), and checks the
 * cluster's headroom before each round.
 * Schema: cw_test_<CW_SLOT> (or CW_DB_NAME=cw_test_*), dropped and re-created at the start, as the
 * admin login of /etc/cw/db.env. Scenario 4 gives holds a 3 s life: channel TTLs cannot go below
 * 60 s (CHECK), so its traders run on a clock 57 s behind the expiry crons' real clock.
 */

namespace CW\Tests\Concurrency;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Invariants;
use CW\Movements;
use CW\OpResult;
use CW\Ops\Snapshot;
use CW\Reservations;
use CW\Schema\Migrator;
use CW\Stock;
use CW\Tests\Support\TestDb;
use DateTimeImmutable;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// =============================================================================================
// Process pool
// =============================================================================================

final class Pool
{
    /** Never hold more connections than this at once (workers + observer + the parent). */
    public const MAX_CONNECTIONS = 30;
    /** Leave at least this many connections of the cluster free for everybody else. */
    public const CLUSTER_SPARE = 8;

    public static int $seed = 1;

    /**
     * Forks $n workers (+ an optional observer). Each child connects, reports ready, waits for
     * the common start, runs its body and writes its JSON-able result to a file.
     *
     * @param callable(int, Db): array<string, mixed> $work
     * @param null|callable(Db, callable(): bool): array<string, mixed> $observe  stops when its 2nd argument returns true
     * @return array{workers: array<int, array<string, mixed>>, observer: ?array<string, mixed>, failed: list<string>,
     *               deadlocks_retried: int, wall_ms: int}
     */
    public static function run(int $n, callable $work, ?callable $observe = null, int $timeoutSec = 300): array
    {
        $children = $n + ($observe !== null ? 1 : 0);
        if ($n < 1 || $children + 1 > self::MAX_CONNECTIONS) {
            throw new \InvalidArgumentException("{$children} children + the parent exceed the budget of " . self::MAX_CONNECTIONS . ' connections');
        }
        $dir = sys_get_temp_dir() . '/cw-hammer-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        fflush(STDOUT);
        fflush(STDERR);
        $kids = [];
        for ($i = 0; $i < $children; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                self::killAll($kids);
                throw new \RuntimeException('stream_socket_pair failed');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::killAll($kids);
                throw new \RuntimeException('pcntl_fork failed');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                foreach ($kids as $k) {
                    fclose($k['sock']);
                }
                $isObserver = $observe !== null && $i === $n;
                exit(self::child($i, $pair[1], $dir, $isObserver ? null : $work, $isObserver ? $observe : null));
            }
            fclose($pair[1]);
            $kids[$i] = ['pid' => $pid, 'sock' => $pair[0], 'done' => false, 'status' => null];
        }

        // Barrier: every child is connected before any starts.
        $failed = [];
        foreach ($kids as $i => $k) {
            stream_set_timeout($k['sock'], 60);
            $line = fgets($k['sock']);
            if ($line !== "R\n") {
                $failed[] = "child {$i} did not get ready";
            }
        }
        if ($failed !== []) {
            self::killAll($kids);
            self::reap($kids, time() + 10);
            $out = self::collect($dir, $kids);
            throw new \RuntimeException(implode('; ', $failed) . ' ' . json_encode(array_filter(array_column($out, '_fatal'))));
        }
        $t0 = hrtime(true);
        foreach ($kids as $k) {
            fwrite($k['sock'], "G\n");
        }

        // Wait for the workers, then stop the observer.
        $deadline = time() + $timeoutSec;
        $workerIdx = range(0, $n - 1);
        self::reap($kids, $deadline, $workerIdx);
        foreach ($workerIdx as $i) {
            if (!$kids[$i]['done']) {
                $failed[] = "worker {$i} still running after {$timeoutSec} s (killed)";
                posix_kill($kids[$i]['pid'], SIGKILL);
            }
        }
        self::reap($kids, time() + 5, $workerIdx);
        $wallMs = intdiv(hrtime(true) - $t0, 1_000_000);
        if ($observe !== null) {
            @fwrite($kids[$n]['sock'], "S\n");
            self::reap($kids, time() + 60, [$n]);
            if (!$kids[$n]['done']) {
                posix_kill($kids[$n]['pid'], SIGKILL);
                self::reap($kids, time() + 5, [$n]);
                $failed[] = 'observer did not stop (killed)';
            }
        }
        foreach ($kids as $k) {
            fclose($k['sock']);
        }
        $out = self::collect($dir, $kids);
        @rmdir($dir);

        $workers = [];
        $deadlocks = 0;
        foreach ($kids as $i => $k) {
            $r = $out[$i] ?? null;
            if ($r === null || isset($r['_fatal']) || $k['status'] !== 0) {
                $failed[] = ($i === $n ? 'observer' : "worker {$i}") . ' failed: exit ' . json_encode($k['status']) . ' ' . ($r['_fatal'] ?? 'no result');
                continue;
            }
            $deadlocks += (int) ($r['_deadlocks_retried'] ?? 0);
            if ($i < $n) {
                $workers[$i] = $r;
            }
        }
        return ['workers' => $workers, 'observer' => $observe !== null ? ($out[$n] ?? null) : null, 'failed' => $failed,
            'deadlocks_retried' => $deadlocks, 'wall_ms' => $wallMs];
    }

    /** @param resource $sock */
    private static function child(int $i, $sock, string $dir, ?callable $work, ?callable $observe): int
    {
        try {
            mt_srand(self::$seed + 7919 * ($i + 1));
            $db = TestDb::connect();
            $deadlocksBefore = Db::deadlockRetries();
            fwrite($sock, "R\n");
            stream_set_timeout($sock, 120);
            if (fgets($sock) !== "G\n") {
                return 3;
            }
            if ($observe !== null) {
                stream_set_blocking($sock, false);
                $stop = static function () use ($sock): bool {
                    $l = fgets($sock);
                    return $l !== false && trim($l) === 'S';
                };
                $out = $observe($db, $stop);
            } else {
                assert($work !== null);
                $out = $work($i, $db);
            }
            $out['_deadlocks_retried'] = Db::deadlockRetries() - $deadlocksBefore;
            file_put_contents("{$dir}/{$i}.json", json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            return 0;
        } catch (\Throwable $e) {
            @file_put_contents("{$dir}/{$i}.json", json_encode(['_fatal' => Errors::describe($e)]));
            return 1;
        }
    }

    /**
     * @param array<int, array{pid: int, sock: resource, done: bool, status: ?int}> $kids
     * @param list<int>|null $only
     */
    private static function reap(array &$kids, int $deadline, ?array $only = null): void
    {
        $want = $only ?? array_keys($kids);
        while (true) {
            $pending = array_filter($want, static fn (int $i): bool => !$kids[$i]['done']);
            if ($pending === [] || time() > $deadline) {
                return;
            }
            foreach ($pending as $i) {
                $status = 0;
                $r = pcntl_waitpid($kids[$i]['pid'], $status, WNOHANG);
                if ($r === $kids[$i]['pid'] || $r === -1) {
                    $kids[$i]['done'] = true;
                    $kids[$i]['status'] = $r === -1 ? -1 : (pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -pcntl_wtermsig($status));
                }
            }
            usleep(5_000);
        }
    }

    /** @param array<int, array{pid: int}> $kids */
    private static function killAll(array $kids): void
    {
        foreach ($kids as $k) {
            posix_kill($k['pid'], SIGKILL);
        }
    }

    /**
     * @param array<int, mixed> $kids
     * @return array<int, array<string, mixed>>
     */
    private static function collect(string $dir, array $kids): array
    {
        $out = [];
        foreach (array_keys($kids) as $i) {
            $f = "{$dir}/{$i}.json";
            if (is_file($f)) {
                $v = json_decode((string) file_get_contents($f), true);
                $out[$i] = is_array($v) ? $v : ['_fatal' => 'unreadable result'];
                @unlink($f);
            }
        }
        return $out;
    }

    /**
     * Opens a parent connection, runs $fn, and guarantees the connection is closed before
     * anything is forked (the WeakReference check catches a Db kept alive by accident).
     *
     * @template T
     * @param callable(Db): T $fn
     * @return T
     */
    public static function withDb(callable $fn): mixed
    {
        $db = TestDb::connect();
        $ref = \WeakReference::create($db);
        try {
            return $fn($db);
        } finally {
            unset($db);
            gc_collect_cycles();
            if ($ref->get() !== null) {
                throw new \LogicException('a parent connection is still referenced; it would be shared with forked children');
            }
        }
    }

    /** Free connections on the cluster right now, minus the spare we leave to others. */
    public static function headroom(): int
    {
        $db = TestDb::server(); // no default schema: it may not exist yet
        $max = (int) $db->value('SELECT @@GLOBAL.max_connections');
        $used = (int) $db->value('SELECT COUNT(*) FROM information_schema.PROCESSLIST');
        return $max - $used - self::CLUSTER_SPARE;
    }
}

final class Errors
{
    public static function describe(\Throwable $e): string
    {
        $code = Db::driverCode($e);
        $kind = match (true) {
            $e instanceof CwException => 'cw:' . $e->errorCode,
            $code === 1213 || Db::isDeadlock($e) => 'DEADLOCK',
            $code === 1205 => 'LOCK_WAIT_TIMEOUT',
            $code !== null => 'mysql:' . $code,
            default => get_class($e),
        };
        return $kind . ': ' . substr($e->getMessage(), 0, 300);
    }

    /** @return array{status: int, result: ?string, error: string} */
    public static function asResult(\Throwable $e): array
    {
        if ($e instanceof CwException) {
            return ['status' => $e->httpStatus, 'result' => $e->errorCode, 'error' => 'cw:' . $e->errorCode];
        }
        return ['status' => 500, 'result' => null, 'error' => self::describe($e)];
    }

    public static function bodyHash(OpResult $r): string
    {
        return hash('sha256', Idempotency::canonicalJson($r->body));
    }
}

// =============================================================================================
// Report
// =============================================================================================

final class Report
{
    /** @var list<array{scenario: string, check: string, expected: string, observed: string, result: string}> */
    private array $rows = [];

    public function check(string $scenario, string $check, bool $ok, string $expected, string $observed): bool
    {
        $this->rows[] = ['scenario' => $scenario, 'check' => $check, 'expected' => $expected, 'observed' => $observed, 'result' => $ok ? 'PASS' : 'FAIL'];
        self::say(sprintf('  %s  %s: %s', $ok ? 'PASS' : 'FAIL', $check, $observed));
        return $ok;
    }

    public function info(string $scenario, string $check, string $observed): void
    {
        $this->rows[] = ['scenario' => $scenario, 'check' => $check, 'expected' => '', 'observed' => $observed, 'result' => 'info'];
        self::say("  info  {$check}: {$observed}");
    }

    public function fail(string $scenario, string $check, string $observed): void
    {
        $this->check($scenario, $check, false, 'no error', $observed);
    }

    public static function say(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
        fflush(STDOUT);
    }

    public function passed(): bool
    {
        foreach ($this->rows as $r) {
            if ($r['result'] === 'FAIL') {
                return false;
            }
        }
        return $this->rows !== [];
    }

    public function print(): void
    {
        $w = ['scenario' => 18, 'check' => 46, 'expected' => 22, 'observed' => 58, 'result' => 6];
        $line = '+' . implode('+', array_map(static fn (int $n): string => str_repeat('-', $n + 2), $w)) . '+';
        $row = static function (array $cells) use ($w): string {
            $wrapped = [];
            $height = 1;
            foreach ($w as $k => $n) {
                $wrapped[$k] = explode("\n", wordwrap((string) $cells[$k], $n, "\n", true));
                $height = max($height, count($wrapped[$k]));
            }
            $out = [];
            for ($l = 0; $l < $height; $l++) {
                $parts = [];
                foreach ($w as $k => $n) {
                    $parts[] = ' ' . str_pad($wrapped[$k][$l] ?? '', $n) . ' ';
                }
                $out[] = '|' . implode('|', $parts) . '|';
            }
            return implode("\n", $out);
        };
        $out = [$line, $row(['scenario' => 'scenario', 'check' => 'check', 'expected' => 'expected', 'observed' => 'observed', 'result' => 'result']), $line];
        $last = null;
        foreach ($this->rows as $r) {
            if ($last !== null && $last !== $r['scenario']) {
                $out[] = $line;
            }
            $last = $r['scenario'];
            $out[] = $row($r);
        }
        $out[] = $line;
        $pass = count(array_filter($this->rows, static fn (array $r): bool => $r['result'] === 'PASS'));
        $fail = count(array_filter($this->rows, static fn (array $r): bool => $r['result'] === 'FAIL'));
        $out[] = sprintf('RESULT: %s  (%d checks passed, %d failed)', $fail === 0 && $pass > 0 ? 'PASS' : 'FAIL', $pass, $fail);
        self::say(implode("\n", $out));
    }
}

// =============================================================================================
// Fixtures and helpers (parent side, sequential)
// =============================================================================================

final class Fx
{
    private readonly Stock $stock;
    private readonly Movements $moves;
    private int $seq = 0;

    public function __construct(private readonly Db $db)
    {
        $this->stock = new Stock($db);
        $this->moves = new Movements($db, $this->stock);
    }

    public function channel(string $code, string $mode = 'live', int $ttl = 2400): Caller
    {
        $id = $this->db->insert('INSERT INTO channel (code, name, mode, reserve_ttl_sec) VALUES (?, ?, ?, ?)',
            [$code, strtoupper($code) . ' hammer site', $mode, $ttl]);
        $this->db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$id, self::main($this->db)]);
        return Caller::channel($id, $code);
    }

    public function sku(string $policy, int $onHand, string $name): int
    {
        $id = $this->db->transaction(static function (Db $db) use ($name): int {
            $id = $db->insert('INSERT INTO sku (name) VALUES (?)', [$name]);
            $db->exec('UPDATE sku SET code = ? WHERE id = ?', [sprintf('CW-%06d', $id), $id]);
            return $id;
        });
        if ($policy !== 'legacy') {
            self::must($this->stock->setPolicy(Caller::staff(1), $id, $policy, 'fx-policy-' . $id . '-' . (++$this->seq)));
        }
        if ($onHand > 0) {
            $this->goodsIn($id, $onHand);
        }
        return $id;
    }

    public function goodsIn(int $sku, int $qty): void
    {
        self::must($this->moves->record(Caller::staff(1), ['type' => 'goods_in', 'warehouse' => 'MAIN',
            'doc_ref' => 'FX-GI-' . $sku . '-' . (++$this->seq), 'lines' => [['sku_id' => $sku, 'qty' => $qty]]], 'fx-gi-' . $sku . '-' . $this->seq));
    }

    public function listing(Caller $ch, string $variant, ?int $sku, int $u = 1): int
    {
        return $this->db->insert(
            'INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, ?, ?, ?, ?)',
            [$ch->channelId, $variant, $sku, $u, $sku === null ? 'unmapped' : 'mapped'],
        );
    }

    public static function main(Db $db): int
    {
        return (int) $db->value("SELECT id FROM warehouse WHERE code = 'MAIN'");
    }

    /** @return array{on_hand: int, allocated: int, held: int} */
    public static function bal(Db $db, int $sku, string $wh = 'MAIN'): array
    {
        $r = $db->one('SELECT b.on_hand, b.allocated, b.held FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id '
            . 'WHERE w.code = ? AND b.sku_id = ?', [$wh, $sku]);
        return $r === null ? ['on_hand' => 0, 'allocated' => 0, 'held' => 0]
            : ['on_hand' => (int) $r['on_hand'], 'allocated' => (int) $r['allocated'], 'held' => (int) $r['held']];
    }

    public static function must(OpResult $r): OpResult
    {
        if (!$r->ok()) {
            throw new \RuntimeException('fixture call failed: ' . $r->status . ' ' . json_encode($r->body));
        }
        return $r;
    }

    /**
     * Replays one balance's ledger in id order (the balance's own lock order) and returns the
     * lowest availability after any row. Rows of one transaction never dip below the true value
     * (reserve adds held; commit/ship/release lower a bucket before raising another).
     *
     * @return array{rows: int, min: int}
     */
    public static function ledgerMin(Db $db, int $sku): array
    {
        $b = ['on_hand' => 0, 'allocated' => 0, 'held' => 0];
        $min = PHP_INT_MAX;
        $rows = 0;
        foreach ($db->all('SELECT bucket, balance_after FROM stock_ledger WHERE warehouse_id = ? AND sku_id = ? ORDER BY id', [self::main($db), $sku]) as $r) {
            $b[(string) $r['bucket']] = (int) $r['balance_after'];
            $min = min($min, $b['on_hand'] - $b['allocated'] - $b['held']);
            $rows++;
        }
        return ['rows' => $rows, 'min' => $rows === 0 ? 0 : $min];
    }
}

/** Polls the availability of some items as fast as it can until stopped. @param list<int> $skus */
function observeAvailability(Db $db, array $skus, callable $stop): array
{
    $main = Fx::main($db);
    $marks = implode(',', array_fill(0, count($skus), '?'));
    $sql = "SELECT sku_id, on_hand - allocated - held AS a FROM stock_balance WHERE warehouse_id = ? AND sku_id IN ({$marks})";
    $min = [];
    $negative = [];
    $samples = 0;
    $last = false;
    while (true) {
        foreach ($db->all($sql, [$main, ...$skus]) as $r) {
            $s = (int) $r['sku_id'];
            $a = (int) $r['a'];
            $min[$s] = min($min[$s] ?? PHP_INT_MAX, $a);
            if ($a < 0 && count($negative) < 10) {
                $negative[] = "sku {$s} available {$a}";
            }
        }
        $samples++;
        if ($last) {
            break;
        }
        $last = $stop();
    }
    return ['samples' => $samples, 'min' => $min, 'negative' => $negative];
}

function tally(array $rows, callable $key): array
{
    $out = [];
    foreach ($rows as $r) {
        $k = $key($r);
        $out[$k] = ($out[$k] ?? 0) + 1;
    }
    ksort($out);
    return $out;
}

function fmt(array $counts): string
{
    $parts = [];
    foreach ($counts as $k => $v) {
        $parts[] = "{$k} x{$v}";
    }
    return $parts === [] ? '(none)' : implode(', ', $parts);
}

/** @return list<array<string, mixed>> */
function allRows(array $pool, string $field = 'rows'): array
{
    $rows = [];
    foreach ($pool['workers'] as $w) {
        array_push($rows, ...($w[$field] ?? []));
    }
    return $rows;
}

function poolProblems(Report $rep, string $s, array $pool): bool
{
    if ($pool['failed'] === []) {
        return true;
    }
    $rep->fail($s, 'every worker process completed', implode(' | ', array_slice($pool['failed'], 0, 5)));
    return false;
}

function line(string $variant, string ...$units): array
{
    return ['variant_id' => $variant, 'qty' => count($units), 'unit_ids' => array_values($units)];
}

// =============================================================================================
// Scenario 1: 200 reserves, 3 channels, 10 units
// =============================================================================================

function scenario1(Report $rep, int $workers): void
{
    $S = '1 last ten';
    Report::say("\n== Scenario 1: 200 reserve attempts from 3 channels, strict item with on_hand = 10 ==");
    [$chs, $sku] = Pool::withDb(static function (Db $db): array {
        TestDb::clean($db);
        $fx = new Fx($db);
        $chs = [$fx->channel('vpg'), $fx->channel('alt'), $fx->channel('vbig')];
        $sku = $fx->sku('strict', 10, 'Hammer last ten');
        foreach ($chs as $c) {
            $fx->listing($c, 'LAST10', $sku);
        }
        return [$chs, $sku];
    });

    $w = max(3, $workers - $workers % 3); // an equal number of workers per channel
    $plan = [];
    for ($i = 0; $i < 200; $i++) {
        $c = $i % 3;
        $plan[$c + 3 * (intdiv($i, 3) % intdiv($w, 3))][] = $i;
    }
    $pool = Pool::run($w, static function (int $wi, Db $db) use ($plan, $chs): array {
        $res = new Reservations($db);
        $rows = [];
        foreach ($plan[$wi] ?? [] as $i) {
            $ch = $chs[$i % 3];
            $t = hrtime(true);
            try {
                $r = $res->reserve($ch, "h1-{$i}", [line('LAST10', "h1u{$i}")], "h1-{$i}");
                $rows[] = ['i' => $i, 'ch' => $i % 3, 'status' => $r->status, 'result' => $r->body['result'] ?? $r->body['error'] ?? null,
                    'reasons' => $r->body['reasons'] ?? null, 'available' => $r->body['lines'][0]['available'] ?? null,
                    'ms' => intdiv(hrtime(true) - $t, 1_000_000)];
            } catch (\Throwable $e) {
                $rows[] = ['i' => $i, 'ch' => $i % 3] + Errors::asResult($e);
            }
        }
        return ['rows' => $rows];
    }, static fn (Db $db, callable $stop): array => observeAvailability($db, [$sku], $stop));
    poolProblems($rep, $S, $pool);

    $rows = allRows($pool);
    $by = tally($rows, static fn (array $r): string => $r['status'] . ' ' . ($r['result'] ?? $r['error'] ?? ''));
    $held = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 201));
    $refused = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 409));
    $other = array_values(array_filter($rows, static fn (array $r): bool => !in_array($r['status'], [201, 409], true)));
    $rep->info($S, 'pool', sprintf('%d workers (%d per channel) + 1 observer; %d ms wall; outcomes: %s', $w, intdiv($w, 3), $pool['wall_ms'], fmt($by)));
    $rep->check($S, 'attempts answered', count($rows) === 200, '200', (string) count($rows));
    $rep->check($S, 'held (201)', count($held) === 10, '10', (string) count($held));
    $rep->check($S, 'refused (409 short)', count($refused) === 190
        && array_filter($refused, static fn (array $r): bool => $r['reasons'] !== ['short']) === [], '190, all short', count($refused) . ' (' . fmt(tally($refused, static fn (array $r): string => implode('+', $r['reasons'] ?? ['?']))) . ')');
    $rep->check($S, 'errors / deadlocks surfaced', $other === [], '0', count($other) === 0 ? '0' : count($other) . ': ' . json_encode(array_slice($other, 0, 3)));
    $after = array_map(static fn (array $r): int => (int) $r['available'], $held);
    sort($after);
    $rep->check($S, 'each hold saw its own state (serialised)', $after === range(0, 9), 'available after = 0..9', implode(',', $after));
    $rep->check($S, 'refusals reported available 0',
        array_filter($refused, static fn (array $r): bool => $r['available'] !== 0) === [], 'all 0',
        fmt(tally($refused, static fn (array $r): string => 'available=' . json_encode($r['available']))));
    $obs = $pool['observer'] ?? ['samples' => 0, 'min' => [], 'negative' => []];
    $minObs = $obs['min'][$sku] ?? null;
    $rep->check($S, 'observer: availability never negative', $minObs !== null && $minObs >= 0 && $obs['negative'] === [],
        'min >= 0', "min {$minObs} over {$obs['samples']} samples during the race");
    $minResp = min([...$after, ...array_map(static fn (array $r): int => (int) $r['available'], $refused)] ?: [0]);
    $rep->check($S, 'responses: availability never negative', $minResp >= 0, 'min >= 0', "min {$minResp} over " . (count($held) + count($refused)) . ' responses');
    $rep->info($S, 'holds per channel', fmt(tally($held, static fn (array $r): string => ['vpg', 'alt', 'vbig'][$r['ch']])));
    $rep->info($S, 'latency', sprintf('p50 %d ms, max %d ms per reserve', self_median(array_column($rows, 'ms')), max(array_column($rows, 'ms') ?: [0])));
    $rep->info($S, 'deadlocks retried inside CW', (string) $pool['deadlocks_retried']);

    Pool::withDb(static function (Db $db) use ($rep, $S, $sku, $chs): void {
        $lm = Fx::ledgerMin($db, $sku);
        $rep->check($S, 'ledger replay: availability never negative', $lm['min'] >= 0, 'min >= 0', "min {$lm['min']} over {$lm['rows']} ledger rows");
        $b = Fx::bal($db, $sku);
        $rep->check($S, 'final balance', $b === ['on_hand' => 10, 'allocated' => 0, 'held' => 10], 'on_hand 10, allocated 0, held 10',
            "on_hand {$b['on_hand']}, allocated {$b['allocated']}, held {$b['held']}");
        $res = (int) $db->value('SELECT COUNT(*) FROM reservation');
        $heldRes = (int) $db->value("SELECT COUNT(*) FROM reservation WHERE status = 'held'");
        $units = (int) $db->value("SELECT COUNT(*) FROM reservation_unit WHERE state = 'held'");
        $rep->check($S, 'rows: refused orders leave nothing', $res === 10 && $heldRes === 10 && $units === 10,
            '10 reservations, all held; 10 held units', "{$res} reservations ({$heldRes} held), {$units} held units");
        $views = [];
        $v = new \CW\Availability($db);
        foreach ($chs as $c) {
            $views[] = $v->forVariants((int) $c->channelId, ['LAST10'])[0]['available'] ?? null;
        }
        $rep->check($S, 'every site now sees 0 available', $views === [0, 0, 0], '0,0,0', implode(',', array_map('json_encode', $views)));
        $inv = Invariants::check($db);
        $rep->check($S, 'invariants (CW\\Invariants)', $inv === [], 'none violated', $inv === [] ? 'none violated' : implode(' | ', array_slice($inv, 0, 3)));
    });
}

function self_median(array $v): int
{
    if ($v === []) {
        return 0;
    }
    sort($v);
    return (int) $v[intdiv(count($v), 2)];
}

// =============================================================================================
// Scenario 2: 100 multi-line orders in random line order
// =============================================================================================

function scenario2(Report $rep, int $workers, int $seed): void
{
    $S = '2 multi-line';
    Report::say("\n== Scenario 2: 100 parallel multi-line orders (random line order, 5 strict items) ==");
    mt_srand($seed);
    // Variant names sort in a different order than the item ids, so request order, variant order
    // and lock order all differ.
    $stockPlan = [1 => 70, 2 => 260, 3 => 45, 4 => 55, 5 => 40];
    [$chs, $skus, $variants] = Pool::withDb(static function (Db $db) use ($stockPlan): array {
        TestDb::clean($db);
        $fx = new Fx($db);
        $chs = [$fx->channel('vpg'), $fx->channel('alt'), $fx->channel('vbig')];
        $skus = [];
        foreach ($stockPlan as $n => $q) {
            $skus[$n] = $fx->sku('strict', $q, "Hammer multi {$n}");
        }
        /** variant => [item n, u] */
        $variants = ['Z-ITEM1' => [1, 1], 'A-ITEM1-DUP' => [1, 1], 'Q-ITEM2' => [2, 1], 'B-ITEM2-10X' => [2, 10],
            'M-ITEM3' => [3, 1], 'C-ITEM4' => [4, 1], 'K-ITEM5' => [5, 1]];
        foreach ($chs as $c) {
            foreach ($variants as $v => [$n, $u]) {
                $fx->listing($c, $v, $skus[$n], $u);
            }
        }
        return [$chs, $skus, $variants];
    });

    $orders = [];
    $unit = 0;
    for ($i = 0; $i < 100; $i++) {
        $names = array_keys($variants);
        shuffle($names);
        $lines = [];
        foreach (array_slice($names, 0, mt_rand(2, 5)) as $v) {
            $units = [];
            for ($q = $variants[$v][1] === 10 ? 1 : mt_rand(1, 3); $q > 0; $q--) {
                $units[] = 'h2u' . (++$unit);
            }
            $lines[] = line($v, ...$units);
        }
        shuffle($lines);
        $orders["h2-{$i}"] = ['ch' => mt_rand(0, 2), 'lines' => $lines];
    }
    $plan = [];
    $i = 0;
    foreach ($orders as $ref => $o) {
        $plan[$i++ % $workers][] = $ref;
    }
    $pool = Pool::run($workers, static function (int $wi, Db $db) use ($plan, $orders, $chs): array {
        $res = new Reservations($db);
        $rows = [];
        foreach ($plan[$wi] ?? [] as $ref) {
            try {
                $r = $res->reserve($chs[$orders[$ref]['ch']], $ref, $orders[$ref]['lines'], "{$ref}-reserve");
                $short = array_values(array_filter($r->body['lines'] ?? [], static fn (array $l): bool => ($l['result'] ?? '') === 'short'));
                $rows[] = ['ref' => $ref, 'status' => $r->status, 'result' => $r->body['result'] ?? $r->body['error'] ?? null,
                    'reasons' => $r->body['reasons'] ?? null, 'short_lines' => count($short),
                    'available' => array_map(static fn (array $l): ?int => $l['available'] ?? null, $r->body['lines'] ?? [])];
            } catch (\Throwable $e) {
                $rows[] = ['ref' => $ref] + Errors::asResult($e);
            }
        }
        return ['rows' => $rows];
    }, static fn (Db $db, callable $stop): array => observeAvailability($db, array_values($skus), $stop));
    poolProblems($rep, $S, $pool);

    $rows = allRows($pool);
    $accepted = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 201));
    $refused = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 409));
    $other = array_values(array_filter($rows, static fn (array $r): bool => !in_array($r['status'], [201, 409], true)));
    $rep->info($S, 'pool', sprintf('%d workers + 1 observer, seed %d; %d ms wall; %d accepted, %d refused', $workers, $seed, $pool['wall_ms'], count($accepted), count($refused)));
    $rep->check($S, 'orders answered', count($rows) === 100, '100', (string) count($rows));
    $rep->check($S, 'deadlock errors / other errors surfaced', $other === [], '0', $other === [] ? '0' : count($other) . ': ' . json_encode(array_slice($other, 0, 3)));
    $rep->info($S, 'deadlocks retried inside CW', (string) $pool['deadlocks_retried']);
    $rep->check($S, 'contention happened', count($accepted) > 0 && count($refused) > 0, 'some accepted, some refused',
        count($accepted) . ' accepted, ' . count($refused) . ' refused');
    $rep->check($S, 'every refusal names a short strict line', array_filter($refused, static fn (array $r): bool => $r['short_lines'] < 1 || $r['reasons'] !== ['short']) === [],
        'all', fmt(tally($refused, static fn (array $r): string => implode('+', $r['reasons'] ?? ['?']))));
    $obs = $pool['observer'] ?? ['samples' => 0, 'min' => [], 'negative' => []];
    $obsMin = $obs['min'] === [] ? null : min($obs['min']);
    $rep->check($S, 'observer: availability never negative', $obsMin !== null && $obsMin >= 0, 'min >= 0', "min {$obsMin} over {$obs['samples']} samples x 5 items");

    $acceptedRefs = array_column($accepted, 'ref');
    Pool::withDb(static function (Db $db) use ($rep, $S, $orders, $accepted, $refused, $skus, $variants, $stockPlan, $chs): void {
        // all-or-nothing, order by order
        $bad = [];
        foreach ([...$accepted, ...$refused] as $r) {
            $o = $orders[$r['ref']];
            $ids = array_merge(...array_column($o['lines'], 'unit_ids'));
            $states = $db->column('SELECT state FROM reservation_unit WHERE channel_id = ? AND unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                [$chs[$o['ch']]->channelId, ...$ids]);
            $res = $db->one('SELECT status, attempt FROM reservation WHERE channel_id = ? AND order_ref = ?', [$chs[$o['ch']]->channelId, $r['ref']]);
            $ok = $r['status'] === 201
                ? ($states === array_fill(0, count($ids), 'held') && $res !== null && $res['status'] === 'held')
                : ($states === [] && $res === null);
            if (!$ok) {
                $bad[] = $r['ref'] . ' (' . $r['status'] . ': ' . count($states) . '/' . count($ids) . ' units, reservation ' . json_encode($res) . ')';
            }
        }
        $rep->check($S, 'all-or-nothing per order', $bad === [], 'accepted: every unit held; refused: nothing',
            $bad === [] ? (count($accepted) + count($refused)) . ' orders checked' : count($bad) . ' broken: ' . implode(', ', array_slice($bad, 0, 3)));

        // exact accounting: held = sum of accepted orders' central units
        $need = array_fill_keys(array_keys($skus), 0);
        foreach ($accepted as $r) {
            foreach ($orders[$r['ref']]['lines'] as $l) {
                [$n, $u] = $variants[$l['variant_id']];
                $need[$n] += $l['qty'] * $u;
            }
        }
        $got = [];
        $okHeld = true;
        $okCap = true;
        $ledgerMin = PHP_INT_MAX;
        foreach ($skus as $n => $sku) {
            $b = Fx::bal($db, $sku);
            $got[] = "item{$n} {$b['held']}/{$need[$n]} of {$stockPlan[$n]}";
            $okHeld = $okHeld && $b['held'] === $need[$n] && $b['on_hand'] === $stockPlan[$n];
            $okCap = $okCap && $b['held'] <= $b['on_hand'];
            $ledgerMin = min($ledgerMin, Fx::ledgerMin($db, $sku)['min']);
        }
        $rep->check($S, 'held = sum of accepted orders (qty x u)', $okHeld, 'exact per item', implode('; ', $got));
        $rep->check($S, 'no strict item over-held', $okCap, 'held <= on_hand', $okCap ? 'held <= on_hand for all 5' : implode('; ', $got));
        $rep->check($S, 'ledger replay: availability never negative', $ledgerMin >= 0, 'min >= 0', "min {$ledgerMin}");
        $inv = Invariants::check($db);
        $rep->check($S, 'invariants after the race', $inv === [], 'none violated', $inv === [] ? 'none violated' : implode(' | ', array_slice($inv, 0, 3)));
    });

    // Phase 2: pay half of the accepted orders and abandon the rest, all at once.
    $ops = [];
    foreach ($acceptedRefs as $k => $ref) {
        $ops[] = ['ref' => $ref, 'op' => $k % 2 === 0 ? 'commit' : 'release'];
    }
    $plan = [];
    foreach ($ops as $k => $op) {
        $plan[$k % $workers][] = $op;
    }
    $pool2 = Pool::run($workers, static function (int $wi, Db $db) use ($plan, $orders, $chs): array {
        $res = new Reservations($db);
        $rows = [];
        foreach ($plan[$wi] ?? [] as $op) {
            $o = $orders[$op['ref']];
            try {
                $r = $op['op'] === 'commit'
                    ? $res->commit($chs[$o['ch']], $op['ref'], $o['lines'], 'reserved', "{$op['ref']}-commit")
                    : $res->release($chs[$o['ch']], $op['ref'], 1, "{$op['ref']}-release");
                $rows[] = ['ref' => $op['ref'], 'op' => $op['op'], 'status' => $r->status, 'result' => $r->body['result'] ?? $r->body['error'] ?? null];
            } catch (\Throwable $e) {
                $rows[] = ['ref' => $op['ref'], 'op' => $op['op']] + Errors::asResult($e);
            }
        }
        return ['rows' => $rows];
    });
    poolProblems($rep, $S, $pool2);
    $rows2 = allRows($pool2);
    $by2 = tally($rows2, static fn (array $r): string => $r['op'] . ' ' . $r['status'] . ' ' . ($r['result'] ?? $r['error'] ?? ''));
    $okStatus = array_filter($rows2, static fn (array $r): bool => $r['status'] !== 200 || !in_array($r['result'], ['committed', 'released'], true)) === [];
    $rep->check($S, 'pay half / abandon half at once', $okStatus && count($rows2) === count($ops), 'all 200 committed/released',
        fmt($by2) . "; deadlocks retried {$pool2['deadlocks_retried']}");
    Pool::withDb(static function (Db $db) use ($rep, $S, $orders, $ops, $skus, $variants): void {
        $alloc = array_fill_keys(array_keys($skus), 0);
        foreach ($ops as $op) {
            if ($op['op'] === 'commit') {
                foreach ($orders[$op['ref']]['lines'] as $l) {
                    [$n, $u] = $variants[$l['variant_id']];
                    $alloc[$n] += $l['qty'] * $u;
                }
            }
        }
        $ok = true;
        $got = [];
        foreach ($skus as $n => $sku) {
            $b = Fx::bal($db, $sku);
            $ok = $ok && $b['held'] === 0 && $b['allocated'] === $alloc[$n];
            $got[] = "item{$n} held {$b['held']} alloc {$b['allocated']}/{$alloc[$n]}";
        }
        $rep->check($S, 'after settling: held 0, allocated = paid', $ok, 'exact per item', implode('; ', $got));
        $ov = (int) $db->value('SELECT COUNT(*) FROM oversell_event');
        $rep->check($S, 'held units never oversell', $ov === 0, '0 oversell_event', (string) $ov);
        $inv = Invariants::check($db);
        $rep->check($S, 'invariants after settling', $inv === [], 'none violated', $inv === [] ? 'none violated' : implode(' | ', array_slice($inv, 0, 3)));
    });
}

// =============================================================================================
// Scenario 3: 20 processes, one Idempotency-Key
// =============================================================================================

function scenario3(Report $rep): void
{
    $S = '3 same key x20';
    $N = 20;
    Report::say("\n== Scenario 3: {$N} processes replay the same Idempotency-Key at the same instant ==");
    [$ch, $sku] = Pool::withDb(static function (Db $db): array {
        TestDb::clean($db);
        $fx = new Fx($db);
        $ch = $fx->channel('vpg');
        $sku = $fx->sku('strict', 50, 'Hammer idempotency');
        $fx->listing($ch, 'A', $sku);
        $fx->listing($ch, 'A10', $sku, 10);
        return [$ch, $sku];
    });
    $staff = Caller::staff(1);
    $now = Clock::iso(Clock::db(Clock::now()));
    // a reset must be later than the dispatch it reverses (R10): ship, reset, ship again
    $shipAt = Clock::iso(Clock::db(Clock::now()->modify('-60 seconds')));
    $resetAt = Clock::iso(Clock::db(Clock::now()->modify('-30 seconds')));
    $i1 = [line('A', 'i1a', 'i1b'), line('A10', 'i1c')];
    $i1units = ['i1a', 'i1b', 'i1c'];

    // name, key, call(w, Reservations, Movements), expected ledger rows for the key, expected MAIN balance after, prep
    $rounds = [
        ['reserve', 'k-reserve-i1', static fn (int $w, Reservations $r, Movements $m): OpResult => $r->reserve($ch, 'i1', $i1, 'k-reserve-i1'), 3, [50, 0, 12]],
        ['commit', 'k-commit-i1', static fn (int $w, Reservations $r, Movements $m): OpResult => $r->commit($ch, 'i1', $i1, 'reserved', 'k-commit-i1'), 6, [50, 12, 0]],
        ['ship', 'k-ship-i1', static fn (int $w, Reservations $r, Movements $m): OpResult => $r->ship($ch, 'i1', $i1units, $shipAt, 'k-ship-i1'), 6, [38, 0, 0]],
        ['unship', 'k-unship-i1', static fn (int $w, Reservations $r, Movements $m): OpResult => $r->unship($ch, 'i1', $i1units, $resetAt, 'k-unship-i1'), 6, [50, 12, 0]],
        ['ship again', 'k-ship2-i1', static fn (int $w, Reservations $r, Movements $m): OpResult => $r->ship($ch, 'i1', $i1units, $now, 'k-ship2-i1'), 6, [38, 0, 0]],
        ['return', 'k-return-i1', static fn (int $w, Reservations $r, Movements $m): OpResult => $r->returnUnits($ch, 'i1', $i1units, 'k-return-i1'), 3, [50, 0, 0]],
        ['goods_in (staff)', 'k-goods-in', static fn (int $w, Reservations $r, Movements $m): OpResult => $m->record($staff,
            ['type' => 'goods_in', 'warehouse' => 'MAIN', 'doc_ref' => 'PINV-HAMMER-1', 'lines' => [['sku_id' => $sku, 'qty' => 7]]], 'k-goods-in'), 1, [57, 0, 0]],
        ['reserve i2', 'k-reserve-i2', static fn (int $w, Reservations $r, Movements $m): OpResult => $r->reserve($ch, 'i2', [line('A', 'i2a', 'i2b', 'i2c')], 'k-reserve-i2'), 3, [57, 0, 3]],
        ['release', 'k-release-i2', static fn (int $w, Reservations $r, Movements $m): OpResult => $r->release($ch, 'i2', 1, 'k-release-i2'), 3, [57, 0, 0]],
        ['cancel restockable', 'k-cancel-i3', static fn (int $w, Reservations $r, Movements $m): OpResult => $r->cancel($ch, 'i3', ['i3a', 'i3b'], true, 'k-cancel-i3'), 2, [57, 0, 0],
            static function (Reservations $r) use ($ch): void {
                Fx::must($r->reserve($ch, 'i3', [line('A', 'i3a', 'i3b')], 'prep-reserve-i3'));
                Fx::must($r->commit($ch, 'i3', [line('A', 'i3a', 'i3b')], 'reserved', 'prep-commit-i3'));
            }],
        ['cancel to VERIFY', 'k-cancel-i4', static fn (int $w, Reservations $r, Movements $m): OpResult => $r->cancel($ch, 'i4', ['i4a', 'i4b'], false, 'k-cancel-i4'), 6, [55, 0, 0],
            static function (Reservations $r) use ($ch): void {
                Fx::must($r->reserve($ch, 'i4', [line('A', 'i4a', 'i4b')], 'prep-reserve-i4'));
                Fx::must($r->commit($ch, 'i4', [line('A', 'i4a', 'i4b')], 'reserved', 'prep-commit-i4'));
            }],
    ];

    foreach ($rounds as $round) {
        [$name, $key, $call, $ledgerRows, $expBal] = $round;
        $prep = $round[5] ?? null;
        if ($prep !== null) {
            Pool::withDb(static function (Db $db) use ($prep): void {
                $prep(new Reservations($db));
            });
        }
        $pool = Pool::run($N, static function (int $w, Db $db) use ($call): array {
            try {
                $r = $call($w, new Reservations($db), new Movements($db));
                return ['r' => ['status' => $r->status, 'replayed' => $r->replayed, 'hash' => Errors::bodyHash($r),
                    'result' => $r->body['result'] ?? $r->body['error'] ?? null]];
            } catch (\Throwable $e) {
                return ['r' => Errors::asResult($e) + ['replayed' => false, 'hash' => null]];
            }
        });
        $rs = array_column($pool['workers'], 'r');
        $fresh = array_values(array_filter($rs, static fn (array $r): bool => !$r['replayed'] && !isset($r['error'])));
        $errors = array_values(array_filter($rs, static fn (array $r): bool => isset($r['error'])));
        $replays = array_values(array_filter($rs, static fn (array $r): bool => $r['replayed']));
        $f = $fresh[0] ?? null;
        $sameAsFresh = $f !== null && array_filter($replays, static fn (array $r): bool => $r['status'] !== $f['status'] || $r['hash'] !== $f['hash']) === [];
        $db = Pool::withDb(static function (Db $db) use ($key, $sku): array {
            return [
                'ledger' => (int) $db->value('SELECT COUNT(*) FROM stock_ledger WHERE idem_key = ?', [$key]),
                'idem' => (int) $db->value('SELECT COUNT(*) FROM idempotency WHERE idem_key = ?', [$key]),
                'audit' => (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE idem_key = ? AND action NOT LIKE '%lines_differ'", [$key]),
                'bal' => Fx::bal($db, $sku),
                'verify' => Fx::bal($db, $sku, 'VERIFY')['on_hand'],
                'reviews' => (int) $db->value("SELECT COUNT(*) FROM count_review WHERE source = 'verify_recount'"),
            ];
        });
        $bal = array_values($db['bal']);
        $ok = count($rs) === $N && count($fresh) === 1 && $errors === [] && count($replays) === $N - 1 && $sameAsFresh
            && $f !== null && $f['status'] >= 200 && $f['status'] < 300
            && $db['ledger'] === $ledgerRows && $db['idem'] === 1 && $db['audit'] === 1 && $bal === $expBal;
        $extra = $name === 'cancel to VERIFY' ? ", VERIFY on_hand {$db['verify']}, recount tasks {$db['reviews']}" : '';
        if ($name === 'cancel to VERIFY') {
            $ok = $ok && $db['verify'] === 2 && $db['reviews'] === 2;
        }
        $rep->check($S, "{$name}: one effect", $ok,
            '1 fresh 2xx, ' . ($N - 1) . " identical replays, {$ledgerRows} ledger rows, bal " . implode('/', $expBal),
            sprintf('%d fresh (%s), %d replays%s, %d errors%s; ledger %d, idempotency %d, audit %d; bal %s%s; %d ms',
                count($fresh), $f === null ? '-' : $f['status'] . ' ' . $f['result'], count($replays), $sameAsFresh ? ' identical' : ' DIFFERENT',
                count($errors), $errors === [] ? '' : ' ' . json_encode(array_slice($errors, 0, 2)),
                $db['ledger'], $db['idem'], $db['audit'], implode('/', $bal), $extra, $pool['wall_ms']));
        poolProblems($rep, $S, $pool);
    }

    // The same order under 20 different keys (the site retried with fresh keys): one hold.
    $pool = Pool::run($N, static function (int $w, Db $db) use ($ch): array {
        try {
            $r = (new Reservations($db))->reserve($ch, 'i5', [line('A', 'i5a')], "k-i5-{$w}");
            return ['r' => ['status' => $r->status, 'replayed' => $r->replayed, 'result' => $r->body['result'] ?? $r->body['error'] ?? null]];
        } catch (\Throwable $e) {
            return ['r' => Errors::asResult($e) + ['replayed' => false]];
        }
    });
    $rs = array_column($pool['workers'], 'r');
    $by = tally($rs, static fn (array $r): string => $r['status'] . ' ' . ($r['result'] ?? $r['error'] ?? ''));
    $db = Pool::withDb(static fn (Db $db): array => [
        'ledger' => (int) $db->value("SELECT COUNT(*) FROM stock_ledger WHERE order_ref = 'i5' AND movement_type = 'reserve'"),
        'bal' => array_values(Fx::bal($db, $sku)),
        'res' => (int) $db->value("SELECT COUNT(*) FROM reservation WHERE order_ref = 'i5'"),
    ]);
    $rep->check($S, 'same order, 20 different keys: one hold', $by === ['200 extended' => $N - 1, '201 held' => 1] && $db['ledger'] === 1 && $db['res'] === 1 && $db['bal'] === [55, 0, 1],
        '1 x 201 held, 19 x 200 extended, 1 ledger row', fmt($by) . "; ledger {$db['ledger']}, reservations {$db['res']}, bal " . implode('/', $db['bal']));
    poolProblems($rep, $S, $pool);

    // One key, two different bodies: one effect; the other body gets 422 idempotency_key_reused.
    $pool = Pool::run($N, static function (int $w, Db $db) use ($ch): array {
        $lines = $w % 2 === 0 ? [line('A', 'i6a')] : [line('A', 'i6a', 'i6b')];
        try {
            $r = (new Reservations($db))->reserve($ch, 'i6', $lines, 'k-mixed-i6');
            return ['r' => ['w' => $w, 'status' => $r->status, 'replayed' => $r->replayed, 'hash' => Errors::bodyHash($r),
                'result' => $r->body['result'] ?? $r->body['error'] ?? null]];
        } catch (\Throwable $e) {
            return ['r' => ['w' => $w] + Errors::asResult($e) + ['replayed' => false, 'hash' => null]];
        }
    });
    $rs = array_column($pool['workers'], 'r');
    $fresh = array_values(array_filter($rs, static fn (array $r): bool => !$r['replayed'] && $r['status'] === 201));
    $winnerOdd = ($fresh[0]['w'] ?? 0) % 2;
    $okOthers = count($fresh) === 1 && count($rs) === $N;
    foreach ($okOthers ? $rs : [] as $r) {
        if ($r === $fresh[0]) {
            continue;
        }
        $okOthers = $okOthers && ($r['w'] % 2 === $winnerOdd
            ? ($r['replayed'] && $r['hash'] === $fresh[0]['hash'])
            : ($r['status'] === 422 && $r['result'] === 'idempotency_key_reused'));
    }
    $db = Pool::withDb(static fn (Db $db): array => [
        'ledger' => (int) $db->value("SELECT COUNT(*) FROM stock_ledger WHERE idem_key = 'k-mixed-i6'"),
        'bal' => array_values(Fx::bal($db, $sku)),
        'inv' => Invariants::check($db),
    ]);
    $expUnits = $winnerOdd === 1 ? 2 : 1;
    $rep->check($S, 'one key, two bodies: one effect', $okOthers && $db['ledger'] === $expUnits && $db['bal'] === [55, 0, 1 + $expUnits],
        'winner applied once; same body replays, other body 422',
        fmt(tally($rs, static fn (array $r): string => $r['status'] . ' ' . ($r['replayed'] ? 'replay' : ($r['result'] ?? '')))) . "; ledger {$db['ledger']}, bal " . implode('/', $db['bal']));
    poolProblems($rep, $S, $pool);
    // A ship that arrives before its commit is refused WITHOUT storing the answer (D36), so the
    // key stays free: 20 copies racing must each get 409 not_committed, store nothing, and never
    // surface a deadlock (each waiter on the rolled-back key claim retries the claim itself).
    Pool::withDb(static function (Db $db) use ($ch): void {
        Fx::must((new Reservations($db))->reserve($ch, 'i7', [line('A', 'i7a')], 'prep-reserve-i7'));
    });
    $pool = Pool::run($N, static function (int $w, Db $db) use ($ch, $now): array {
        try {
            $r = (new Reservations($db))->ship($ch, 'i7', ['i7a'], $now, 'k-ship-i7');
            return ['r' => ['status' => $r->status, 'result' => $r->body['result'] ?? $r->body['error'] ?? null]];
        } catch (\Throwable $e) {
            return ['r' => Errors::asResult($e)];
        }
    });
    $rs = array_column($pool['workers'], 'r');
    $by = tally($rs, static fn (array $r): string => $r['status'] . ' ' . ($r['error'] ?? $r['result'] ?? ''));
    $db = Pool::withDb(static fn (Db $db): array => [
        'idem' => (int) $db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key = 'k-ship-i7'"),
        'ledger' => (int) $db->value("SELECT COUNT(*) FROM stock_ledger WHERE idem_key = 'k-ship-i7'"),
        'bal' => array_values(Fx::bal($db, $sku)),
        'inv' => Invariants::check($db),
    ]);
    $rep->check($S, 'ship before commit x20: nothing stored', $by === ['409 cw:not_committed' => $N] && $db['idem'] === 0 && $db['ledger'] === 0,
        "{$N} x 409 not_committed, key not stored", fmt($by) . "; stored keys {$db['idem']}, ledger {$db['ledger']}; deadlocks retried {$pool['deadlocks_retried']}");
    poolProblems($rep, $S, $pool);
    $rep->check($S, 'invariants after all rounds', $db['inv'] === [], 'none violated', $db['inv'] === [] ? 'none violated' : implode(' | ', array_slice($db['inv'], 0, 3)));
}

// =============================================================================================
// Scenario 4: interleavings with the expiry cron
// =============================================================================================

final class Trader
{
    private const TTL = 60;
    public const HOLD_SEC = 3;

    private readonly Reservations $res;
    private readonly Movements $moves;
    /** @var array<string, array{lines: list<array<string, mixed>>, attempt: int, units: array<string, string>}> */
    private array $orders = [];
    /** @var array<string, true> */
    private array $held = [];
    /** @var array<string, true> */
    private array $released = [];
    /** @var array<string, true> */
    private array $committed = [];
    /** @var list<array{kind: string, args: array<int, mixed>, key: string, status: int, hash: string}> */
    private array $calls = [];
    /** @var array<string, int> */
    public array $counts = [];
    /** @var list<string> */
    public array $unexpected = [];
    private int $n = 0;

    /** @param array<string, int> $variants variant => u @param list<int> $restockSkus */
    public function __construct(private readonly Db $db, private readonly int $w, private readonly Caller $ch,
        private readonly array $variants, private readonly Caller $staff, private readonly array $restockSkus)
    {
        // Traders run TTL - HOLD_SEC seconds behind the crons: a hold made "now" expires HOLD_SEC seconds from now.
        $skew = self::TTL - self::HOLD_SEC;
        $this->res = new Reservations($db, null, static fn (): DateTimeImmutable => Clock::now()->modify("-{$skew} seconds"));
        $this->moves = new Movements($db);
    }

    public function step(): void
    {
        $roll = mt_rand(1, 100);
        match (true) {
            $roll <= 26 => $this->newOrder(),
            $roll <= 44 => $this->commit(),
            $roll <= 54 => $this->release(),
            $roll <= 61 => $this->rehold(),
            $roll <= 68 => $this->extend(),
            $roll <= 71 => $this->outage(),
            $roll <= 73 => $this->tombstone(),
            $roll <= 83 => $this->replay(),
            $roll <= 88 => $this->cancel(),
            $roll <= 93 => $this->ship(),
            $roll <= 95 => $this->restock(),
            default => $this->newOrder(),
        };
    }

    private function ref(): string
    {
        return "t{$this->w}-" . (++$this->n);
    }

    /** @return list<array<string, mixed>> */
    private function randomLines(string $ref): array
    {
        $names = array_keys($this->variants);
        shuffle($names);
        $lines = [];
        $u = 0;
        foreach (array_slice($names, 0, mt_rand(1, 3)) as $v) {
            $units = [];
            for ($q = $this->variants[$v] > 1 ? 1 : mt_rand(1, 2); $q > 0; $q--) {
                $units[] = $ref . 'u' . (++$u);
            }
            $lines[] = line($v, ...$units);
        }
        shuffle($lines);
        return $lines;
    }

    private function key(string $what, string $ref): string
    {
        return "{$ref}-{$what}-" . mt_rand(1, 1_000_000_000);
    }

    /**
     * Runs one call, checks its status against $expect, remembers it for replays.
     *
     * @param list<string> $expect "status result" pairs that are acceptable ("200 *" = any 200)
     */
    private function call(string $label, string $kind, array $args, string $key, array $expect): ?OpResult
    {
        try {
            $r = $this->invoke($kind, $args, $key);
        } catch (\Throwable $e) {
            $err = Errors::asResult($e);
            $this->count("{$label} {$err['status']} {$err['error']}");
            if (!in_array($err['status'] . ' ' . $err['result'], $expect, true)) {
                $this->bad("{$label}: " . $err['error'] . ' args ' . json_encode($args));
            }
            return null;
        }
        $result = (string) ($r->body['result'] ?? $r->body['error'] ?? '');
        $this->count("{$label} {$r->status} {$result}");
        if (!in_array("{$r->status} {$result}", $expect, true) && !in_array("{$r->status} *", $expect, true)) {
            $this->bad("{$label}: got {$r->status} {$result}, expected " . implode('|', $expect) . ' ' . json_encode($args) . ' -> ' . json_encode($r->body));
        }
        if (count($this->calls) >= 64) {
            array_shift($this->calls);
        }
        $this->calls[] = ['kind' => $kind, 'args' => $args, 'key' => $key, 'status' => $r->status, 'hash' => Errors::bodyHash($r)];
        return $r;
    }

    /** @param array<int, mixed> $a */
    private function invoke(string $kind, array $a, string $key): OpResult
    {
        return match ($kind) {
            'reserve' => $this->res->reserve($this->ch, $a[0], $a[1], $key),
            'commit' => $this->res->commit($this->ch, $a[0], $a[1], $a[2], $key),
            'release' => $this->res->release($this->ch, $a[0], $a[1], $key),
            'cancel' => $this->res->cancel($this->ch, $a[0], $a[1], $a[2], $key),
            'ship' => $this->res->ship($this->ch, $a[0], $a[1], $a[2], $key),
            'move' => $this->moves->record($this->staff, $a[0], $key),
        };
    }

    private function count(string $k): void
    {
        $this->counts[$k] = ($this->counts[$k] ?? 0) + 1;
    }

    private function bad(string $m): void
    {
        $this->count('UNEXPECTED');
        if (count($this->unexpected) < 20) {
            $this->unexpected[] = "w{$this->w} " . substr($m, 0, 700);
        }
    }

    private function setState(string $ref, string $state): void
    {
        unset($this->held[$ref], $this->released[$ref], $this->committed[$ref]);
        match ($state) {
            'held' => $this->held[$ref] = true,
            'released' => $this->released[$ref] = true,
            'committed' => $this->committed[$ref] = true,
            default => null,
        };
    }

    /** After a reserve: 201 held / 200 extended keep it held, 409 short means it is not held. */
    private function afterReserve(string $ref, ?OpResult $r): void
    {
        if ($r === null) {
            return;
        }
        if ($r->status === 201 || ($r->status === 200 && ($r->body['result'] ?? '') === 'extended')) {
            $this->orders[$ref]['attempt'] = (int) ($r->body['attempt'] ?? 1);
            $this->setState($ref, 'held');
        } elseif ($r->status === 409 && ($r->body['error'] ?? '') === 'refused') {
            $this->setState($ref, isset($this->orders[$ref]['attempt']) && $this->orders[$ref]['attempt'] > 0 ? 'released' : 'refused');
        }
    }

    private function newOrder(): void
    {
        $ref = $this->ref();
        $lines = $this->randomLines($ref);
        $this->orders[$ref] = ['lines' => $lines, 'attempt' => 0, 'units' => []];
        $r = $this->call('reserve.new', 'reserve', [$ref, $lines], $this->key('reserve', $ref), ['201 held', '409 refused']);
        $this->afterReserve($ref, $r);
    }

    private function commit(): void
    {
        $pool = mt_rand(1, 100) <= 80 ? $this->held : $this->released;
        if ($pool === []) {
            $this->newOrder();
            return;
        }
        $ref = (string) array_rand($pool);
        $lines = $this->orders[$ref]['lines'];
        $label = isset($this->held[$ref]) ? 'commit.held' : 'commit.no_hold';
        if (mt_rand(1, 100) <= 15) {
            // The payment carries other lines than the hold: the body wins.
            if (count($lines) > 1 && mt_rand(0, 1) === 0) {
                array_pop($lines);
            } else {
                $lines[0]['unit_ids'][] = $ref . 'x' . mt_rand(1, 1_000_000);
                $lines[0]['qty']++;
            }
            $label .= '.other_lines';
        }
        $r = $this->call($label, 'commit', [$ref, $lines, 'reserved'], $this->key('commit', $ref), ['200 committed']);
        if ($r !== null) {
            $this->orders[$ref]['lines'] = $lines;
            foreach ($lines as $l) {
                foreach ($l['unit_ids'] as $u) {
                    $this->orders[$ref]['units'][$u] = 'allocated';
                }
            }
            $this->setState($ref, 'committed');
        }
    }

    private function release(): void
    {
        if ($this->held === []) {
            $this->newOrder();
            return;
        }
        $ref = (string) array_rand($this->held);
        $stale = false;
        if (mt_rand(1, 100) <= 40) {
            // A late release of an earlier attempt: pick a held order that has had more than one.
            foreach (array_keys($this->held) as $r) {
                if ($this->orders[(string) $r]['attempt'] > 1) {
                    $ref = (string) $r;
                    $stale = true;
                    break;
                }
            }
        }
        $attempt = $this->orders[$ref]['attempt'];
        $r = $this->call($stale ? 'release.stale' : 'release', 'release', [$ref, $stale ? $attempt - 1 : $attempt], $this->key('release', $ref),
            $stale ? ['200 stale_attempt', '200 already_released'] : ['200 released', '200 already_released']);
        if ($r !== null && in_array($r->body['result'] ?? '', ['released', 'already_released'], true)) {
            $this->setState($ref, 'released');
        }
    }

    private function rehold(): void
    {
        if ($this->released === []) {
            $this->newOrder();
            return;
        }
        $ref = (string) array_rand($this->released);
        $r = $this->call('reserve.again', 'reserve', [$ref, $this->orders[$ref]['lines']], $this->key('rehold', $ref), ['201 held', '409 refused']);
        $this->afterReserve($ref, $r);
    }

    private function extend(): void
    {
        if ($this->held === []) {
            $this->newOrder();
            return;
        }
        $ref = (string) array_rand($this->held);
        // Still held -> extended; expired by the cron meanwhile -> a new attempt (201) or 409 short.
        $r = $this->call('reserve.extend', 'reserve', [$ref, $this->orders[$ref]['lines']], $this->key('extend', $ref),
            ['200 extended', '201 held', '409 refused']);
        $this->afterReserve($ref, $r);
    }

    private function outage(): void
    {
        $ref = $this->ref();
        $lines = $this->randomLines($ref);
        $this->orders[$ref] = ['lines' => $lines, 'attempt' => 0, 'units' => []];
        $r = $this->call('commit.outage', 'commit', [$ref, $lines, 'unreserved'], $this->key('outage', $ref), ['200 committed']);
        if ($r !== null) {
            foreach ($lines as $l) {
                foreach ($l['unit_ids'] as $u) {
                    $this->orders[$ref]['units'][$u] = 'allocated';
                }
            }
            $this->setState($ref, 'committed');
        }
    }

    private function tombstone(): void
    {
        $ref = $this->ref();
        $lines = $this->randomLines($ref);
        $this->orders[$ref] = ['lines' => $lines, 'attempt' => 0, 'units' => []];
        $this->call('release.unknown', 'release', [$ref, null], $this->key('tomb', $ref), ['200 tombstoned']);
        $this->call('reserve.tombstone', 'reserve', [$ref, $lines], $this->key('late', $ref), ['409 tombstone']);
        if (mt_rand(0, 1) === 0) {
            // A payment on a tombstoned order is still taken (D34).
            $r = $this->call('commit.tombstone', 'commit', [$ref, $lines, 'reserved'], $this->key('commit', $ref), ['200 committed']);
            if ($r !== null) {
                foreach ($lines as $l) {
                    foreach ($l['unit_ids'] as $u) {
                        $this->orders[$ref]['units'][$u] = 'allocated';
                    }
                }
                $this->setState($ref, 'committed');
            }
        }
    }

    private function replay(): void
    {
        if ($this->calls === []) {
            $this->newOrder();
            return;
        }
        $c = $this->calls[array_rand($this->calls)];
        try {
            $r = $this->invoke($c['kind'], $c['args'], $c['key']);
        } catch (\Throwable $e) {
            $this->count('replay 500 ' . Errors::describe($e));
            $this->bad('replay threw ' . Errors::describe($e));
            return;
        }
        $same = $r->replayed && $r->status === $c['status'] && Errors::bodyHash($r) === $c['hash'];
        $this->count('replay ' . ($same ? 'identical' : 'DIFFERENT'));
        if (!$same) {
            $this->bad("replay of {$c['kind']} {$c['key']} gave {$r->status} replayed=" . json_encode($r->replayed) . ' ' . json_encode($r->body));
        }
    }

    /** @return list<string> */
    private function allocatedUnits(string $ref): array
    {
        return array_keys(array_filter($this->orders[$ref]['units'], static fn (string $s): bool => $s === 'allocated'));
    }

    private function cancel(): void
    {
        if ($this->committed === []) {
            $this->newOrder();
            return;
        }
        $ref = (string) array_rand($this->committed);
        $units = $this->allocatedUnits($ref);
        if ($units === []) {
            return;
        }
        $pick = array_slice($units, 0, mt_rand(1, count($units)));
        $r = $this->call('cancel', 'cancel', [$ref, $pick, true], $this->key('cancel', $ref), ['200 *']);
        if ($r !== null) {
            foreach ($r->body['units'] ?? [] as $u) {
                if (($u['result'] ?? '') !== 'cancelled') {
                    $this->bad("cancel {$ref} unit {$u['unit_id']}: " . ($u['result'] ?? '?'));
                }
                $this->orders[$ref]['units'][$u['unit_id']] = 'cancelled';
            }
        }
    }

    private function ship(): void
    {
        if ($this->committed === []) {
            $this->newOrder();
            return;
        }
        $ref = (string) array_rand($this->committed);
        $units = $this->allocatedUnits($ref);
        if ($units === []) {
            return;
        }
        $r = $this->call('ship', 'ship', [$ref, $units, Clock::iso(Clock::db(Clock::now()))], $this->key('ship', $ref), ['200 *']);
        if ($r !== null) {
            foreach ($r->body['units'] ?? [] as $u) {
                if (($u['result'] ?? '') !== 'shipped') {
                    $this->bad("ship {$ref} unit {$u['unit_id']}: " . ($u['result'] ?? '?'));
                }
                $this->orders[$ref]['units'][$u['unit_id']] = 'shipped';
            }
        }
    }

    private function restock(): void
    {
        $sku = $this->restockSkus[array_rand($this->restockSkus)];
        $doc = "PINV-T{$this->w}-" . (++$this->n);
        $this->call('goods_in', 'move', [['type' => 'goods_in', 'warehouse' => 'MAIN', 'doc_ref' => $doc,
            'lines' => [['sku_id' => $sku, 'qty' => mt_rand(5, 20)]]]], $this->key('gi', $doc), ['200 recorded']);
    }
}

function scenario4(Report $rep, int $workers, int $seconds, int $seed): void
{
    $S = '4 interleavings';
    $expirers = 2;
    $traders = $workers - $expirers;
    Report::say("\n== Scenario 4: {$traders} traders + {$expirers} expiry crons for {$seconds} s, invariants checked on live snapshots ==");
    [$chs, $skus, $variants, $strict] = Pool::withDb(static function (Db $db): array {
        TestDb::clean($db);
        $fx = new Fx($db);
        $chs = [$fx->channel('vpg', 'live', 60), $fx->channel('alt', 'live', 60), $fx->channel('vbig', 'live', 60)];
        $skus = ['S1' => $fx->sku('strict', 60, 'Mix strict 1'), 'S2' => $fx->sku('strict', 40, 'Mix strict 2'),
            'S3' => $fx->sku('strict', 15, 'Mix strict 3'), 'B1' => $fx->sku('backorder', 5, 'Mix backorder'),
            'L1' => $fx->sku('legacy', 0, 'Mix legacy')];
        $variants = ['S1' => 1, 'S1-DUP' => 1, 'S2' => 1, 'S2-5X' => 5, 'S3' => 1, 'B1' => 1, 'L1' => 1, 'U1' => 1];
        $map = ['S1' => 'S1', 'S1-DUP' => 'S1', 'S2' => 'S2', 'S2-5X' => 'S2', 'S3' => 'S3', 'B1' => 'B1', 'L1' => 'L1'];
        foreach ($chs as $c) {
            foreach ($variants as $v => $u) {
                $fx->listing($c, $v, isset($map[$v]) ? $skus[$map[$v]] : null, $u);
            }
        }
        return [$chs, $skus, $variants, [$skus['S1'], $skus['S2'], $skus['S3']]];
    });

    $pool = Pool::run($workers, static function (int $wi, Db $db) use ($expirers, $chs, $variants, $strict, $seconds): array {
        $end = microtime(true) + $seconds;
        if ($wi < $expirers) {
            // The expiry cron, twice over (overlapping runs must be harmless).
            $res = new Reservations($db);
            $expired = 0;
            $runs = 0;
            $errors = [];
            while (microtime(true) < $end) {
                try {
                    $expired += $res->expireDue(50);
                } catch (\Throwable $e) {
                    $errors[] = Errors::describe($e);
                }
                $runs++;
                usleep(mt_rand(50_000, 250_000));
            }
            return ['expirer' => ['expired' => $expired, 'runs' => $runs, 'errors' => array_slice($errors, 0, 5), 'error_count' => count($errors)]];
        }
        $t = new Trader($db, $wi, $chs[$wi % 3], $variants, Caller::staff(1), $strict);
        $steps = 0;
        while (microtime(true) < $end) {
            $t->step();
            $steps++;
        }
        return ['trader' => ['steps' => $steps, 'counts' => $t->counts, 'unexpected' => $t->unexpected]];
    }, static function (Db $db, callable $stop) use ($strict): array {
        // Invariants on consistent snapshots while everything runs.
        $snapshots = 0;
        $violations = [];
        $unexplained = [];
        $minStrict = PHP_INT_MAX;
        $ms = [];
        $marks = implode(',', array_fill(0, count($strict), '?'));
        $last = false;
        while (true) {
            $t = hrtime(true);
            [$inv, $neg] = Snapshot::read($db, static function (Db $db) use ($strict, $marks): array {
                $neg = $db->all(
                    'SELECT b.sku_id, b.on_hand - b.allocated - b.held AS a, '
                    . '(SELECT COUNT(*) FROM oversell_event o WHERE o.warehouse_id = b.warehouse_id AND o.sku_id = b.sku_id) AS events '
                    . "FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id AND w.code = 'MAIN' WHERE b.sku_id IN ({$marks})",
                    $strict,
                );
                return [Invariants::check($db), $neg];
            });
            $ms[] = intdiv(hrtime(true) - $t, 1_000_000);
            $snapshots++;
            foreach ($inv as $v) {
                if (count($violations) < 10) {
                    $violations[] = "snapshot {$snapshots}: {$v}";
                }
            }
            foreach ($neg as $r) {
                $minStrict = min($minStrict, (int) $r['a']);
                if ((int) $r['a'] < 0 && (int) $r['events'] === 0 && count($unexplained) < 10) {
                    $unexplained[] = "snapshot {$snapshots}: sku {$r['sku_id']} available {$r['a']} with no oversell_event";
                }
            }
            if ($last) {
                break;
            }
            usleep(300_000);
            $last = $stop();
        }
        return ['snapshots' => $snapshots, 'violations' => $violations, 'unexplained' => $unexplained, 'min_strict' => $minStrict,
            'check_ms_max' => $ms === [] ? 0 : max($ms)];
    }, $seconds + 240);
    poolProblems($rep, $S, $pool);

    $counts = [];
    $unexpected = [];
    $steps = 0;
    $expired = 0;
    $expirerRuns = 0;
    $expirerErrors = [];
    foreach ($pool['workers'] as $w) {
        if (isset($w['expirer'])) {
            $expired += $w['expirer']['expired'];
            $expirerRuns += $w['expirer']['runs'];
            array_push($expirerErrors, ...$w['expirer']['errors']);
            continue;
        }
        $steps += $w['trader']['steps'];
        foreach ($w['trader']['counts'] as $k => $v) {
            $counts[$k] = ($counts[$k] ?? 0) + $v;
        }
        array_push($unexpected, ...$w['trader']['unexpected']);
    }
    ksort($counts);
    $rep->info($S, 'run', sprintf('%d traders + %d expiry crons + 1 snapshot observer, seed %d, %d s; %d operations (%.0f/s); holds live %d s',
        $traders, $expirers, $seed, $seconds, $steps, $steps / max(1, $seconds), Trader::HOLD_SEC));
    $rep->info($S, 'outcomes', fmt(array_filter($counts, static fn (string $k): bool => $k !== 'UNEXPECTED', ARRAY_FILTER_USE_KEY)));
    $rep->check($S, 'every answer as the state machine says', ($counts['UNEXPECTED'] ?? 0) === 0, '0 unexpected',
        ($counts['UNEXPECTED'] ?? 0) === 0 ? '0 unexpected' : ($counts['UNEXPECTED'] . ': ' . implode(' | ', array_slice($unexpected, 0, 4))));
    $errs = array_filter($counts, static fn (string $k): bool => (bool) preg_match('/ 5\d\d /', $k), ARRAY_FILTER_USE_KEY);
    $rep->check($S, 'no errors / deadlocks surfaced', $errs === [] && $expirerErrors === [], '0',
        $errs === [] && $expirerErrors === [] ? '0' : fmt($errs) . ' ' . implode(' | ', array_slice($expirerErrors, 0, 3)));
    $rep->info($S, 'deadlocks retried inside CW', (string) $pool['deadlocks_retried']);
    $obs = $pool['observer'] ?? ['snapshots' => 0, 'violations' => ['observer missing'], 'unexplained' => [], 'min_strict' => 0, 'check_ms_max' => 0];
    $rep->check($S, 'invariants on every live snapshot', $obs['snapshots'] >= 10 && $obs['violations'] === [], '>= 10 snapshots, 0 violations',
        "{$obs['snapshots']} snapshots (slowest check {$obs['check_ms_max']} ms), " . ($obs['violations'] === [] ? '0 violations' : implode(' | ', array_slice($obs['violations'], 0, 3))));
    $rep->check($S, 'negative strict availability is always flagged', $obs['unexplained'] === [], 'every negative has an oversell_event',
        $obs['unexplained'] === [] ? "ok (lowest strict availability seen {$obs['min_strict']})" : implode(' | ', array_slice($obs['unexplained'], 0, 3)));
    $rep->info($S, 'expiry crons', "{$expired} holds expired in {$expirerRuns} runs");

    Pool::withDb(static function (Db $db) use ($rep, $S, $counts, $expired, $skus, $strict): void {
        $inv = Invariants::check($db);
        $rep->check($S, 'invariants at the end', $inv === [], 'none violated', $inv === [] ? 'none violated' : implode(' | ', array_slice($inv, 0, 3)));
        $revived = (int) $db->value("SELECT COUNT(DISTINCT r.id) FROM reservation r JOIN audit_log a ON a.action = 'reservation.expire' AND a.entity_id = r.order_ref "
            . "WHERE r.status = 'committed'");
        $reattempt = (int) $db->value('SELECT COUNT(*) FROM reservation WHERE attempt > 1');
        $oversell = fmt(array_column($db->all('SELECT kind, COUNT(*) n FROM oversell_event GROUP BY kind ORDER BY kind'), 'n', 'kind'));
        $has = static fn (string $prefix): int => array_sum(array_filter($counts, static fn (string $k): bool => str_starts_with($k, $prefix), ARRAY_FILTER_USE_KEY));
        $cover = [
            'held' => $has('reserve.new 201'), 'refused' => $has('reserve.new 409'), 'extended' => $has('reserve.extend 200 extended'),
            'expired by cron' => $expired, 'extend after expiry' => $has('reserve.extend 201') + $has('reserve.extend 409'),
            're-reserve (attempt > 1)' => $reattempt, 'commit with hold' => $has('commit.held'), 'commit without hold' => $has('commit.no_hold'),
            'commit after expiry' => $revived, 'commit with other lines' => $has('commit.held.other_lines') + $has('commit.no_hold.other_lines'),
            'release' => $has('release 200 released'), 'release lost to cron' => $has('release 200 already_released') + $has('release.stale 200 already_released'),
            'stale attempt' => $has('release.stale 200 stale_attempt'), 'tombstone' => $has('reserve.tombstone 409'), 'outage order' => $has('commit.outage'),
            'replay' => $has('replay identical'), 'cancel' => $has('cancel 200'), 'ship' => $has('ship 200'), 'goods_in' => $has('goods_in 200'),
        ];
        $missing = array_keys(array_filter($cover, static fn (int $n): bool => $n === 0));
        $rep->check($S, 'every interleaving happened', $missing === [], 'each >= 1', ($missing === [] ? '' : 'MISSING ' . implode(', ', $missing) . '; ')
            . fmt($cover));
        $rep->info($S, 'oversell events (residual windows, flagged)', $oversell);

        // Drain: a cron far in the future expires every remaining hold; nothing may stay held.
        $late = new Reservations($db, null, static fn (): DateTimeImmutable => Clock::now()->modify('+1 day'));
        $drained = 0;
        do {
            $n = $late->expireDue(500);
            $drained += $n;
        } while ($n > 0);
        $heldSum = (int) $db->value('SELECT COALESCE(SUM(held), 0) FROM stock_balance');
        $heldUnits = (int) $db->value("SELECT COUNT(*) FROM reservation_unit WHERE state = 'held'");
        $heldRes = (int) $db->value("SELECT COUNT(*) FROM reservation WHERE status = 'held'");
        $inv = Invariants::check($db);
        $rep->check($S, 'after expiring the rest: nothing held', $heldSum === 0 && $heldUnits === 0 && $heldRes === 0 && $inv === [],
            'held 0, invariants hold', "expired {$drained} more; held {$heldSum}, held units {$heldUnits}, held reservations {$heldRes}; "
            . ($inv === [] ? 'invariants hold' : implode(' | ', array_slice($inv, 0, 2))));
        $neg = [];
        foreach ($strict as $sku) {
            $b = Fx::bal($db, $sku);
            $a = $b['on_hand'] - $b['allocated'] - $b['held'];
            $ev = (int) $db->value('SELECT COUNT(*) FROM oversell_event WHERE sku_id = ?', [$sku]);
            if ($a < 0 && $ev === 0) {
                $neg[] = "sku {$sku} available {$a}";
            }
        }
        $rep->check($S, 'final strict availability explained', $neg === [], '>= 0 or flagged', $neg === [] ? 'ok' : implode(', ', $neg));
    });
}

// =============================================================================================
// Scenario 5: the per-item value sequence under cross-warehouse races (C0, I3)
// =============================================================================================

/** One trader of scenario 5: staff movements, transfers, sales and VERIFY counts on 6 legacy items. */
final class ValueTrader
{
    private readonly Reservations $res;
    private readonly Movements $moves;
    private readonly Caller $staff;
    /** @var array<string, int> outcome label => count */
    public array $counts = [];
    /** @var list<string> */
    public array $unexpected = [];
    private int $n = 0;

    /** @param array<string, int> $variants variant => sku_id */
    public function __construct(private readonly Db $db, private readonly int $w, private readonly Caller $ch, private readonly array $variants)
    {
        $this->res = new Reservations($db);
        $this->moves = new Movements($db);
        $this->staff = Caller::staff(1);
    }

    public function step(): void
    {
        $roll = mt_rand(1, 100);
        match (true) {
            $roll <= 30 => $this->staffMove(),
            $roll <= 50 => $this->transfer(),
            $roll <= 80 => $this->sale(),
            $roll <= 90 => $this->cancelToVerify(),
            default => $this->count(),
        };
    }

    /** @return list<int> 1..$max distinct items in random order */
    private function items(int $max): array
    {
        $skus = array_values($this->variants);
        shuffle($skus);
        return array_slice($skus, 0, mt_rand(1, $max));
    }

    private function key(string $what): string
    {
        return "v5-{$this->w}-" . (++$this->n) . "-{$what}";
    }

    private function staffMove(): void
    {
        $type = ['goods_in', 'write_off', 'adjustment'][mt_rand(0, 2)];
        $lines = [];
        foreach ($this->items(4) as $i => $sku) {
            $qty = mt_rand(1, 5) * ($type === 'adjustment' && mt_rand(0, 1) === 0 ? -1 : 1);
            $pence = mt_rand(50, 999); // GBP 0.50-9.99
            $lines[] = ['sku_id' => $sku, 'qty' => $qty, 'line_index' => $i, 'warehouse' => mt_rand(0, 1) === 0 ? 'MAIN' : 'VERIFY']
                + ($type === 'goods_in' ? ['unit_cost' => sprintf('%d.%02d', intdiv($pence, 100), $pence % 100)] : []);
        }
        $key = $this->key($type);
        $this->call("move.{$type}", fn (): OpResult => $this->moves->record($this->staff, ['type' => $type, 'doc_ref' => strtoupper($key), 'lines' => $lines], $key), ['200 recorded']);
    }

    private function transfer(): void
    {
        $skus = $this->items(3);
        $doc = strtoupper($this->key('trf'));
        $out = array_map(static fn (int $sku, int $i): array => ['sku_id' => $sku, 'qty' => mt_rand(1, 3), 'line_index' => $i], $skus, array_keys($skus));
        $in = array_map(static fn (array $l): array => ['warehouse' => 'VERIFY'] + $l, $out);
        $this->call('transfer_out', fn (): OpResult => $this->moves->record($this->staff, ['type' => 'transfer_out', 'warehouse' => 'MAIN', 'doc_ref' => $doc, 'lines' => $out], $doc . '-out'), ['200 recorded']);
        $this->call('transfer_in', fn (): OpResult => $this->moves->record($this->staff, ['type' => 'transfer_in', 'doc_ref' => $doc, 'lines' => $in], $doc . '-in'), ['200 recorded']);
    }

    /** @return list<array{variant_id: string, qty: int, unit_ids: list<string>}> */
    private function lines(string $ref, int $max): array
    {
        $names = array_keys($this->variants);
        shuffle($names);
        $lines = [];
        $u = 0;
        foreach (array_slice($names, 0, mt_rand(1, $max)) as $v) {
            $units = [];
            for ($q = mt_rand(1, 2); $q > 0; $q--) {
                $units[] = $ref . 'u' . (++$u);
            }
            $lines[] = line($v, ...$units);
        }
        return $lines;
    }

    private function sale(): void
    {
        $ref = "v5s{$this->w}-" . (++$this->n);
        $lines = $this->lines($ref, 2);
        $units = array_merge(...array_column($lines, 'unit_ids'));
        if ($this->call('reserve', fn (): OpResult => $this->res->reserve($this->ch, $ref, $lines, $ref . '-r'), ['201 held']) === null) {
            return;
        }
        if ($this->call('commit', fn (): OpResult => $this->res->commit($this->ch, $ref, $lines, 'reserved', $ref . '-c'), ['200 committed']) === null) {
            return;
        }
        $at = Clock::iso(Clock::db(Clock::now()->modify('-60 seconds')));
        $this->call('ship', fn (): OpResult => $this->res->ship($this->ch, $ref, $units, $at, $ref . '-s'), ['200 *']);
    }

    private function cancelToVerify(): void
    {
        $ref = "v5c{$this->w}-" . (++$this->n);
        $lines = $this->lines($ref, 2);
        $units = array_merge(...array_column($lines, 'unit_ids'));
        if ($this->call('commit.unreserved', fn (): OpResult => $this->res->commit($this->ch, $ref, $lines, 'unreserved', $ref . '-c'), ['200 committed']) === null) {
            return;
        }
        $r = $this->call('cancel.to_verify', fn (): OpResult => $this->res->cancel($this->ch, $ref, $units, false, $ref . '-x'), ['200 *']);
        foreach ($r?->body['units'] ?? [] as $u) {
            if (($u['result'] ?? '') !== 'cancelled_to_verify') {
                $this->bad("cancel {$ref} unit {$u['unit_id']}: " . ($u['result'] ?? '?'));
            }
        }
    }

    private function count(): void
    {
        $skus = array_values($this->variants);
        $sku = $skus[mt_rand(0, count($skus) - 1)];
        $now = (int) $this->db->value("SELECT b.on_hand FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id WHERE w.code = 'VERIFY' AND b.sku_id = ?", [$sku]);
        $qty = max(0, $now + mt_rand(-3, 3));
        $key = $this->key('count');
        $at = Clock::iso(Clock::db(Clock::now()->modify('-1 second')));
        $this->call('count.verify', fn (): OpResult => $this->moves->record($this->staff, ['type' => 'count', 'warehouse' => 'VERIFY', 'counted_at' => $at,
            'lines' => [['sku_id' => $sku, 'qty' => $qty]]], $key), ['200 recorded']);
    }

    /**
     * @param callable(): OpResult $fn
     * @param list<string> $expect "status result" pairs ("200 *" = any 200)
     */
    private function call(string $label, callable $fn, array $expect): ?OpResult
    {
        try {
            $r = $fn();
        } catch (\Throwable $e) {
            $err = Errors::asResult($e);
            $this->counts["{$label} {$err['status']} {$err['error']}"] = ($this->counts["{$label} {$err['status']} {$err['error']}"] ?? 0) + 1;
            $this->bad("{$label}: " . $err['error']);
            return null;
        }
        $result = (string) ($r->body['result'] ?? $r->body['error'] ?? '');
        $k = "{$label} {$r->status} {$result}";
        $this->counts[$k] = ($this->counts[$k] ?? 0) + 1;
        if (!in_array("{$r->status} {$result}", $expect, true) && !in_array("{$r->status} *", $expect, true)) {
            $this->bad("{$label}: got {$r->status} {$result}, expected " . implode('|', $expect) . ' -> ' . json_encode($r->body));
            return null;
        }
        return $r;
    }

    private function bad(string $m): void
    {
        $this->counts['UNEXPECTED'] = ($this->counts['UNEXPECTED'] ?? 0) + 1;
        if (count($this->unexpected) < 20) {
            $this->unexpected[] = "w{$this->w} " . substr($m, 0, 700);
        }
    }
}

function scenario5(Report $rep, int $workers, int $seconds, int $seed): void
{
    $S = '5 value sequence';
    $traders = min(20, $workers - 2);
    Report::say("\n== Scenario 5: {$traders} traders for {$seconds} s at MAIN and VERIFY, value seqs polled every 100 ms, invariants on live snapshots ==");
    [$ch, $variants] = Pool::withDb(static function (Db $db): array {
        TestDb::clean($db);
        $fx = new Fx($db);
        $ch = $fx->channel('vs5', 'live');
        $moves = new Movements($db);
        $variants = [];
        for ($i = 1; $i <= 6; $i++) {
            $sku = $fx->sku('legacy', 0, "Value seq item {$i}");
            $fx->listing($ch, "VS{$i}", $sku);
            $variants["VS{$i}"] = $sku;
            // The opening stock, with a cost: it also creates each item's clock row before the race.
            Fx::must($moves->record(Caller::staff(1), ['type' => 'goods_in', 'warehouse' => 'MAIN', 'doc_ref' => "VS5-OPEN-{$i}",
                'lines' => [['sku_id' => $sku, 'qty' => 100_000, 'unit_cost' => '2.50']]], "vs5-open-{$i}"));
        }
        return [$ch, $variants];
    });
    $skus = array_values($variants);
    $rowsBefore = Pool::withDb(static fn (Db $db): int => (int) $db->value("SELECT COUNT(*) FROM stock_ledger WHERE bucket = 'on_hand'"));

    $pool = Pool::run($traders, static function (int $wi, Db $db) use ($ch, $variants, $seconds): array {
        $end = microtime(true) + $seconds;
        $t = new ValueTrader($db, $wi, $ch, $variants);
        $steps = 0;
        while (microtime(true) < $end) {
            $t->step();
            $steps++;
        }
        return ['steps' => $steps, 'counts' => $t->counts, 'unexpected' => $t->unexpected];
    }, static function (Db $db, callable $stop) use ($skus): array {
        $marks = implode(',', array_fill(0, count($skus), '?'));
        $sql = "SELECT sku_id, COUNT(*) AS n, MIN(seq) AS lo, MAX(seq) AS hi FROM stock_value_seq WHERE sku_id IN ({$marks}) GROUP BY sku_id";
        $polls = 0;
        $gaps = 0;
        $gapSamples = [];
        $snapshots = 0;
        $violations = [];
        $ms = [];
        $nextSnapshot = microtime(true) + 2.0;
        $last = false;
        while (true) {
            foreach ($db->all($sql, $skus) as $r) {
                if ((int) $r['lo'] !== 1 || (int) $r['hi'] !== (int) $r['n']) {
                    $gaps++;
                    if (count($gapSamples) < 10) {
                        $gapSamples[] = "poll {$polls}: item {$r['sku_id']} seqs {$r['lo']}..{$r['hi']} in {$r['n']} rows";
                    }
                }
            }
            $polls++;
            if ($last || microtime(true) >= $nextSnapshot) {
                $t = hrtime(true);
                $inv = Snapshot::read($db, static fn (Db $db): array => Invariants::check($db));
                $ms[] = intdiv(hrtime(true) - $t, 1_000_000);
                $snapshots++;
                foreach ($inv as $v) {
                    if (count($violations) < 10) {
                        $violations[] = "snapshot {$snapshots}: {$v}";
                    }
                }
                $nextSnapshot = microtime(true) + 2.0;
            }
            if ($last) {
                break;
            }
            usleep(100_000);
            $last = $stop();
        }
        return ['polls' => $polls, 'gaps' => $gaps, 'gap_samples' => $gapSamples, 'snapshots' => $snapshots, 'violations' => $violations,
            'check_ms_max' => $ms === [] ? 0 : max($ms)];
    }, $seconds + 240);
    poolProblems($rep, $S, $pool);

    $counts = [];
    $unexpected = [];
    $steps = 0;
    foreach ($pool['workers'] as $w) {
        $steps += $w['steps'];
        foreach ($w['counts'] as $k => $v) {
            $counts[$k] = ($counts[$k] ?? 0) + $v;
        }
        array_push($unexpected, ...$w['unexpected']);
    }
    ksort($counts);
    $calls = array_sum(array_filter($counts, static fn (string $k): bool => $k !== 'UNEXPECTED', ARRAY_FILTER_USE_KEY));
    $rowsDuring = Pool::withDb(static fn (Db $db): int => (int) $db->value("SELECT COUNT(*) FROM stock_ledger WHERE bucket = 'on_hand'")) - $rowsBefore;
    $rep->info($S, 'run', sprintf('%d traders + 1 observer, seed %d, %d s; %d steps, %d calls (%.0f/s); %d on_hand rows (%.0f/s)',
        $traders, $seed, $seconds, $steps, $calls, $calls / max(1, $seconds), $rowsDuring, $rowsDuring / max(1, $seconds)));
    $rep->info($S, 'outcomes', fmt(array_filter($counts, static fn (string $k): bool => $k !== 'UNEXPECTED', ARRAY_FILTER_USE_KEY)));
    $errs = array_filter($counts, static fn (string $k): bool => (bool) preg_match('/ 5\d\d /', $k), ARRAY_FILTER_USE_KEY);
    $rep->check($S, 'no errors / deadlocks surfaced', $errs === [] && ($counts['UNEXPECTED'] ?? 0) === 0, '0',
        $errs === [] && ($counts['UNEXPECTED'] ?? 0) === 0 ? '0' : fmt($errs) . ' ' . implode(' | ', array_slice($unexpected, 0, 3)));
    $rep->info($S, 'deadlocks retried inside CW', (string) $pool['deadlocks_retried']);
    $need = ['move.goods_in 200', 'move.write_off 200', 'move.adjustment 200', 'transfer_out 200', 'transfer_in 200', 'ship 200', 'cancel.to_verify 200', 'count.verify 200'];
    $has = static fn (string $prefix): int => array_sum(array_filter($counts, static fn (string $k): bool => str_starts_with($k, $prefix), ARRAY_FILTER_USE_KEY));
    $missing = array_values(array_filter($need, static fn (string $p): bool => $has($p) === 0));
    $rep->check($S, 'every path happened', $missing === [], 'each >= 1', $missing === [] ? 'yes' : 'MISSING ' . implode(', ', $missing));
    $obs = $pool['observer'] ?? ['polls' => 0, 'gaps' => -1, 'gap_samples' => ['observer missing'], 'snapshots' => 0, 'violations' => ['observer missing'], 'check_ms_max' => 0];
    $rep->check($S, 'no seq gap ever visible', $obs['polls'] >= 50 && $obs['gaps'] === 0, '>= 50 polls, 0 gaps',
        "{$obs['polls']} polls, {$obs['gaps']} gaps" . ($obs['gap_samples'] === [] ? '' : ': ' . implode(' | ', array_slice($obs['gap_samples'], 0, 3))));
    $rep->check($S, 'invariants on every live snapshot', $obs['snapshots'] >= 5 && $obs['violations'] === [], '>= 5 snapshots, 0 violations',
        "{$obs['snapshots']} snapshots (slowest check {$obs['check_ms_max']} ms), " . ($obs['violations'] === [] ? '0 violations' : implode(' | ', array_slice($obs['violations'], 0, 3))));

    Pool::withDb(static function (Db $db) use ($rep, $S, $skus): void {
        $bad = [];
        $replayBad = [];
        $rows = 0;
        foreach ($skus as $sku) {
            $n = (int) $db->value("SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand'", [$sku]);
            $seqs = array_map('intval', $db->column('SELECT seq FROM stock_value_seq WHERE sku_id = ? ORDER BY seq', [$sku]));
            if ($seqs !== range(1, $n)) {
                $bad[] = "item {$sku}: " . count($seqs) . " seqs for {$n} on_hand rows";
            }
            // IM8's consumer: value the item's rows strictly in seq order. Per location the running quantity must
            // reproduce every row's balance_after, and the total must end at the item's on_hand.
            $run = [];
            foreach ($db->all('SELECT s.seq, l.warehouse_id, l.qty_delta, l.balance_after FROM stock_value_seq s '
                . 'JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE s.sku_id = ? ORDER BY s.seq', [$sku]) as $r) {
                $wh = (int) $r['warehouse_id'];
                $run[$wh] = ($run[$wh] ?? 0) + (int) $r['qty_delta'];
                if ($run[$wh] !== (int) $r['balance_after'] && count($replayBad) < 5) {
                    $replayBad[] = "item {$sku} seq {$r['seq']}: replay {$run[$wh]} vs balance_after {$r['balance_after']}";
                }
                $rows++;
            }
            $total = (int) $db->value('SELECT COALESCE(SUM(on_hand), 0) FROM stock_balance WHERE sku_id = ?', [$sku]);
            if (array_sum($run) !== $total && count($replayBad) < 5) {
                $replayBad[] = "item {$sku}: replay ends at " . array_sum($run) . ", on_hand is {$total}";
            }
        }
        $rep->check($S, 'every item numbered 1..N (N = on_hand rows)', $bad === [], 'all ' . count($skus) . ' items', $bad === [] ? 'yes' : implode(' | ', $bad));
        $rep->check($S, 'replay in seq order = balances', $replayBad === [], 'every balance_after, every total',
            $replayBad === [] ? "{$rows} rows replayed" : implode(' | ', $replayBad));
        $inv = Invariants::check($db);
        $rep->check($S, 'invariants at the end', $inv === [], 'none violated', $inv === [] ? 'none violated' : implode(' | ', array_slice($inv, 0, 3)));
        $order = (int) $db->value('SELECT COUNT(*) FROM (SELECT s.sku_id, s.seq, s.stock_ledger_id, '
            . 'LAG(s.stock_ledger_id) OVER (PARTITION BY s.sku_id ORDER BY s.seq) AS prev FROM stock_value_seq s) t WHERE stock_ledger_id < prev');
        $rep->info($S, 'seq order differs from ledger id order (commit order across warehouses)', "{$order} times");
    });
}

// =============================================================================================
// main
// =============================================================================================

function main(array $argv): int
{
    $opts = getopt('', ['workers:', 'seconds:', 'only:', 'seed:', 'help']);
    if (isset($opts['help'])) {
        fwrite(STDOUT, "usage: php tests/concurrency/hammer.php [--workers=24] [--seconds=30] [--only=1,2,3,4,5] [--seed=N]\n");
        return 0;
    }
    if (!function_exists('pcntl_fork')) {
        fwrite(STDERR, "hammer: the pcntl extension is required (CLI)\n");
        return 2;
    }
    date_default_timezone_set('UTC');
    $workers = (int) ($opts['workers'] ?? 24);
    $seconds = (int) ($opts['seconds'] ?? 30);
    $seed = isset($opts['seed']) ? (int) $opts['seed'] : random_int(1, 2_000_000_000);
    $only = isset($opts['only']) ? array_map('intval', explode(',', (string) $opts['only'])) : [1, 2, 3, 4, 5];
    if ($workers < 6 || $workers + 2 > Pool::MAX_CONNECTIONS || $seconds < 5) {
        fwrite(STDERR, 'hammer: --workers must be 6..' . (Pool::MAX_CONNECTIONS - 2) . " and --seconds >= 5\n");
        return 2;
    }
    Pool::$seed = $seed;
    mt_srand($seed);

    $name = TestDb::name(); // refuses anything but cw_test_*
    Report::say(sprintf('CW hammer on %s, %d workers (+1 observer, budget %d connections), seed %d, %s UTC',
        $name, $workers, Pool::MAX_CONNECTIONS, $seed, gmdate('Y-m-d H:i:s')));
    $free = Pool::headroom();
    if ($free < $workers + 2) {
        $shrunk = max(6, $free - 2);
        if ($free < 8) {
            fwrite(STDERR, "hammer: only {$free} connections free on the cluster (after a spare of " . Pool::CLUSTER_SPARE . "); try later\n");
            return 3;
        }
        Report::say("cluster headroom is {$free} connections: using {$shrunk} workers");
        $workers = $shrunk;
    }

    $t = hrtime(true);
    $server = TestDb::server();
    $server->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident($name));
    $server->pdo()->exec('CREATE DATABASE ' . Db::ident($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    unset($server);
    Pool::withDb(static function (Db $db): void {
        (new Migrator($db, Migrator::defaultDir()))->migrate();
    });
    Report::say(sprintf('schema %s re-created and migrated in %d ms', $name, intdiv(hrtime(true) - $t, 1_000_000)));

    $rep = new Report();
    $scenarios = [
        1 => static fn () => scenario1($rep, $workers),
        2 => static fn () => scenario2($rep, $workers, $seed),
        3 => static fn () => scenario3($rep),
        4 => static fn () => scenario4($rep, $workers, $seconds, $seed),
        5 => static fn () => scenario5($rep, $workers, $seconds, $seed),
    ];
    foreach ($only as $n) {
        if (!isset($scenarios[$n])) {
            fwrite(STDERR, "hammer: no scenario {$n}\n");
            return 2;
        }
        try {
            $scenarios[$n]();
        } catch (\Throwable $e) {
            $rep->fail("{$n} (aborted)", 'scenario ran to the end', Errors::describe($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }
    Report::say('');
    $rep->print();
    Report::say(sprintf('total %d s', intdiv(hrtime(true) - $t, 1_000_000_000)));
    return $rep->passed() ? 0 : 1;
}

exit(main($argv));
