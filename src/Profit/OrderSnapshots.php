<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Reports\ReportRefresh;

/**
 * Stores a shop's order snapshot and works out the order's money and buying cost (profit step 1; docs/decisions.md PR1-PR11). ONE
 * service for the API (PUT /v1/orders/{ref}/snapshot), the bulk files (bin/import_order_snapshots.php), the ERPNext history import and
 * the deliberate re-cost, so the live feed, a backfill and a correction can never disagree. One transaction per order; nothing here
 * touches stock, reservations or documents (it only reads reservation_unit and channel_listing, without locks).
 *
 *  accept()     THE API's path (PUT /v1/orders/{ref}/snapshot; the final review of 6d59eda, a-HIGH2, PR95): the snapshot checked and
 *               stored whole in ONE short transaction as the order's row of order_snapshot_queue (the snapshot waiting), under the order's
 *               own intake lock only: no day summary, no cost read, no row of order_snapshot, sale_order, sale_line or profit_day is touched,
 *               so a store's checkout (the stock API in the same PHP workers) never waits behind the money work. Compared with the newest
 *               snapshot CW holds (the one waiting, else the one worked out): the same content (whatever its version): "same" (a newer
 *               version is remembered); an older version: "older_ignored"; else "stored".
 *  applyQueued() the money work of what accept() stored (bin/apply_order_snapshots.php, every minute; bounded by orders and seconds):
 *               each order worked out in a transaction of its own under its guards, its snapshot then written to order_snapshot (which
 *               holds the latest snapshot WORKED OUT, the one the order's rows say) and its queue row taken away only while that snapshot
 *               is still the newest (a newer one stays queued). A refused snapshot never reaches order_snapshot.
 *  store()      accept() and the order's apply at once (the bulk files, tests): the same result as the API, worked out now.
 *  apply()      the order (sale_order), its lines (sale_line) and parcels (sale_parcel), the re-send figures of the order it is a re-send
 *               of, and the day summaries (profit_day, profit_item_day: by the difference between what the order counted before and
 *               now, in key order).
 *  recompute()  apply() again with the stored snapshot (the returns sweep; the ERPNext import and the re-cost with their costs).
 *
 * The cost of a line is fixed ONCE, the first time it is stored on a paid order: the item's buying price at the time it was paid
 * (ItemCosts::at), with where it came from. Later snapshots keep it; only the ERPNext history import and bin/recost_sales.php change
 * it, each writing its own costed_by. A line's item is fixed with its cost: the unit's own item at the sale (reservation_unit), else
 * the listing's link when the line is first stored.
 *
 * Lock order (the money work): the order's guards (named locks), the profit_day rows of its days, its sale_order and sale_line rows, its
 * sale_parcel rows, the sale_order row of the order it is a re-send of, profit_item_day and profit_day rows in key order, and
 * last its order_snapshot row, then its order_snapshot_queue row (both at the very end). The API locks only the queue row.
 */
final class OrderSnapshots
{
    public const RESULTS = ['stored', 'same', 'older_ignored'];
    public const SOURCES = ['api', 'bulk'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private readonly ProfitSettings $settings;
    private readonly ItemCosts $costs;

    /** How long a snapshot waits for another one of the same order (or of its first order) before it answers 503 busy. */
    public const LOCK_WAIT_SEC = 10;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null, ?ProfitSettings $settings = null,
        private readonly int $lockWaitSec = self::LOCK_WAIT_SEC)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->settings = $settings ?? new ProfitSettings($db);
        $this->costs = new ItemCosts($db, $this->settings);
    }

    /** Whether the last recompute() left a batch tool's costs for later (a newer snapshot of the order waits for the money job). */
    public bool $deferred = false;

    /** How long accept() waits for another snapshot of the same order being stored (milliseconds of work): then 503 busy. */
    public const INTAKE_WAIT_SEC = 5;
    /**
     * The API's own waits (a web request in the PHP workers the stock calls share: the second final review of 3c31d94, a-M1, b-M10, PR134):
     * at most about a second for the intake lock and a couple of seconds for the order's queue row (that request's session only), each then
     * 503 busy (retryable); the bulk files keep the longer waits above.
     */
    public const API_INTAKE_WAIT_SEC = 1;
    public const API_ROW_WAIT_SEC = 2;
    /** A bulk file's order whose guards the minute job holds is tried again this many times, a second apart, before it is said busy (PR137). */
    public const STORE_TRIES = 3;
    /** The most orders and seconds one run of the money work takes (bin/apply_order_snapshots.php, every minute). */
    public const QUEUE_MAX_ORDERS = 2000;
    public const QUEUE_MAX_SECONDS = 45;
    /** The money job's lock waits (named guards and rows), in seconds: an order it cannot have soon is left for the next run. */
    public const QUEUE_LOCK_WAIT_SEC = 3;

    /**
     * The snapshot stored and its order worked out now (the bulk files, the tests): accept(), then the order's money work at once (and the
     * money work of a snapshot that waited); a refusal of the money work (a line another order stored meanwhile) is thrown as bad_snapshot and
     * its queue row says it.
     *
     * @param array<string, mixed> $raw the decoded JSON object (a bulk line's `ref` is ignored here)
     * @return array{result: string, version: int}
     */
    public function store(int $channelId, string $ref, array $raw, string $source): array
    {
        $r = $this->accept($channelId, $ref, $raw, $source);
        $ref = SnapshotBody::orderRef($ref, 'ref');
        $q = $this->db->one('SELECT failed, error FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ?', [$channelId, $ref]);
        if ($q !== null && (int) $q['failed'] === 0) {
            // The minute job working the order out right now holds its guards: waited for and tried again a few times before it is said busy
            // (the second final review of 3c31d94, Fable L3, PR137).
            for ($try = 1; ; $try++) {
                try {
                    $done = $this->applyOne($channelId, $ref, true);
                    break;
                } catch (\Throwable $e) {
                    if (!self::isBusy($e) || $try >= self::STORE_TRIES) {
                        throw $e; // still held: the bulk file says it on this order's line and goes on (SnapshotImport, PR152)
                    }
                    usleep(1_000_000);
                }
            }
            // Nothing waiting any more: worked out by the minute job meanwhile, or refused by it (its mark came after the read above): a
            // refusal is said, never "stored" (F8 PARTLY, PR152).
            if ($done === 'none') {
                $q = $this->db->one('SELECT failed, error FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ?', [$channelId, $ref]);
                if ($q !== null && (int) $q['failed'] === 1) {
                    throw new CwException('bad_snapshot', 'refused by the money job: ' . (string) $q['error'], 400);
                }
            }
        } elseif ($q !== null) {
            // What CW holds of the order was refused by the money job (this call stored it and the job ran in between: the Codex check of
            // 2b718e7; or the same snapshot sent again, "same": the second final review of 3c31d94, a-M7, PR137): a refusal, never counted
            // "stored" or "same" by the bulk file.
            throw new CwException('bad_snapshot', 'refused by the money job: ' . (string) $q['error'], 400);
        }
        unset($r['queued']);
        return $r;
    }

    /**
     * THE API's path: checked, compared with the newest snapshot CW holds of the order (the one waiting in order_snapshot_queue, else the one
     * worked out in order_snapshot) and stored in the queue in one short transaction under the order's intake lock. Nothing else is written or
     * locked (no day summary, no cost read, no row of order_snapshot or the money tables, no foreign key): the money work is applyQueued()'s
     * (the final review of 6d59eda, a-HIGH2, PR95).
     *
     * @param array<string, mixed> $raw the decoded JSON object
     * @return array{result: string, version: int, queued?: bool}
     */
    public function accept(int $channelId, string $ref, array $raw, string $source): array
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("unknown snapshot source {$source}");
        }
        $ref = SnapshotBody::orderRef($ref, 'ref');
        $now = ($this->clock)();
        $body = SnapshotBody::parse($raw, $now);
        if (strcasecmp((string) $body['parent_ref'], $ref) === 0) {
            throw new CwException('bad_snapshot', 'parent_ref must be another order', 400, ['field' => 'parent_ref']);
        }
        // A line of another order (or a unit of another order in CW) is refused here, by reads that lock nothing; the money work checks
        // again under its locks (two orders storing the same line at once).
        self::foreignLines($this->db, $channelId, $ref, array_map(static fn (array $l): string => (string) $l['line_id'], $body['lines']));
        $hash = SnapshotBody::hash($body);
        $at = Clock::db($now);
        $lock = self::intakeLockName($channelId, $ref);
        $api = $source === 'api';
        // The intake lock and the session's own row wait read in one statement (the Codex check of the fix round, LOW 1: one statement fewer).
        $got = $this->db->one('SELECT GET_LOCK(?, ?) AS got, @@SESSION.innodb_lock_wait_timeout AS wait',
            [$lock, min($this->lockWaitSec, $api ? self::API_INTAKE_WAIT_SEC : self::INTAKE_WAIT_SEC)]);
        if ((int) ($got['got'] ?? 0) !== 1) {
            throw new CwException('busy', 'another snapshot of this order is being stored; retry shortly', 503);
        }
        $wait = null;
        try {
            if ($api && !$this->db->inTransaction()) {
                // The order's queue row waited for a couple of seconds at most, in this request's session only (put back after).
                $wait = (int) $got['wait'];
                $this->db->exec('SET SESSION innodb_lock_wait_timeout = ' . self::API_ROW_WAIT_SEC);
            }
            return $this->db->transaction(function (Db $db) use ($channelId, $ref, $body, $hash, $source, $at): array {
                // The newest CW holds: the one waiting (its row locked: the job takes it away only at the very end of its transaction), else
                // the one worked out (read as committed; only the job writes it, from a queue row this read would have found).
                $q = $db->one('SELECT version, body_hash FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $ref]);
                $cur = $q ?? $db->one('SELECT version, body_hash FROM order_snapshot WHERE channel_id = ? AND order_ref = ?', [$channelId, $ref]);
                if ($cur !== null) {
                    $stored = (int) $cur['version'];
                    if ($body['version'] < $stored) {
                        return ['result' => 'older_ignored', 'version' => $stored];
                    }
                    if (hash_equals((string) $cur['body_hash'], $hash)) {
                        if ($body['version'] <= $stored) {
                            return ['result' => 'same', 'version' => $stored];
                        }
                        // A newer version of the same content: remembered (the order's row takes it on the next money work). Its seq moves on and
                        // its refusal is forgotten, as for any newer snapshot: a job working the older version out meanwhile never takes the
                        // row away with this version on it (the Codex check of 2b718e7: 1012 acknowledged, then lost, then a late 1011 taken).
                        if ($q !== null) {
                            $db->exec("UPDATE order_snapshot_queue SET seq = seq + 1, version = ?, body = JSON_SET(body, '$.version', ?), failed = 0, error = NULL "
                                . 'WHERE channel_id = ? AND order_ref = ?', [$body['version'], $body['version'], $channelId, $ref]);
                        } else {
                            self::enqueue($db, $channelId, $ref, $body, $hash, $source, $at, false);
                        }
                        return ['result' => 'same', 'version' => $body['version'], 'queued' => true];
                    }
                }
                self::enqueue($db, $channelId, $ref, $body, $hash, $source, $at, $q !== null);
                return ['result' => 'stored', 'version' => $body['version'], 'queued' => true];
            });
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1205) {
                throw new CwException('busy', 'another snapshot of this order is being worked out; retry shortly', 503);
            }
            throw $e;
        } finally {
            try {
                if ($wait !== null) {
                    $this->db->exec('SET SESSION innodb_lock_wait_timeout = ' . $wait);
                }
            } catch (\Throwable) {
                // the connection is gone: the session's settings went with it
            }
            try {
                $this->db->value('SELECT RELEASE_LOCK(?)', [$lock]);
            } catch (\Throwable) {
                // the connection is gone: MySQL released it with the session
            }
        }
    }

    /** The order's queue row made, or its snapshot replaced and its seq moved on (a newer snapshot to work out), its refusal forgotten. */
    private static function enqueue(Db $db, int $channelId, string $ref, array $body, string $hash, string $source, string $at, bool $exists): void
    {
        $json = Idempotency::canonicalJson($body);
        if ($exists) {
            $db->exec('UPDATE order_snapshot_queue SET seq = seq + 1, version = ?, body_hash = ?, body = ?, source = ?, queued_at = ?, failed = 0, error = NULL '
                . 'WHERE channel_id = ? AND order_ref = ?', [$body['version'], $hash, $json, $source, $at, $channelId, $ref]);
            return;
        }
        $db->exec('INSERT INTO order_snapshot_queue (channel_id, order_ref, seq, version, body_hash, body, source, first_at, queued_at) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)',
            [$channelId, $ref, $body['version'], $hash, $json, $source, $at, $at]);
    }

    /** The name of an order's intake lock (accept() only; never the money work's guard, so the API never waits for it). Case as the key's. */
    public static function intakeLockName(int $channelId, string $ref): string
    {
        $ref = strtolower($ref);
        $name = 'cw_profit_in:' . $channelId . ':' . $ref;
        return strlen($name) <= 64 ? $name : 'cw_profit_in:' . $channelId . ':' . sha1($ref);
    }

    /**
     * The money work of the snapshots accept() stored (bin/apply_order_snapshots.php): the oldest first, at most $maxOrders orders and about
     * $maxSeconds seconds, each order in a transaction of its own under its guards. A snapshot the work refuses (a line another order stored
     * at the same moment, or any error working it out) is marked on its queue row (failed, the reason) and left until a newer snapshot of the
     * order comes; the next orders go on. An order whose guards or rows another writer holds is left for the next run.
     *
     * @return array{applied: int, refused: int, busy: int, left: int}
     */
    public function applyQueued(int $maxOrders = self::QUEUE_MAX_ORDERS, float $maxSeconds = self::QUEUE_MAX_SECONDS): array
    {
        $started = microtime(true);
        $out = ['applied' => 0, 'refused' => 0, 'busy' => 0, 'left' => 0];
        $rows = $this->db->all('SELECT channel_id, order_ref FROM order_snapshot_queue WHERE failed = 0 ORDER BY queued_at, channel_id, order_ref LIMIT ' . max(1, $maxOrders));
        foreach ($rows as $q) {
            if (microtime(true) - $started > $maxSeconds) {
                break;
            }
            try {
                $r = $this->applyOne((int) $q['channel_id'], (string) $q['order_ref'], false);
                if ($r !== 'none') {
                    $out[$r]++;
                }
            } catch (\Throwable) {
                $out['refused']++;
            }
        }
        $out['left'] = (int) $this->db->value('SELECT COUNT(*) FROM order_snapshot_queue WHERE failed = 0');
        return $out;
    }

    /**
     * One order's money work from its waiting snapshot, under its guards (chosen with the parent that snapshot names), in a transaction of its
     * own: the snapshot is worked out, written to order_snapshot (the latest worked out) and its queue row taken away only while its seq is
     * still the one read with it (a newer one stored meanwhile keeps the row). A snapshot replaced between the choice of the guards and the
     * work is read again (at most three times). A refusal is written on the queue row of exactly that snapshot (failed) and thrown; another
     * writer holding the order's guards or rows: `busy` (thrown when $throw).
     *
     * @return string applied | none (nothing waiting) | busy
     */
    private function applyOne(int $channelId, string $ref, bool $throw): string
    {
        for ($try = 0; $try < 3; $try++) {
            $pre = $this->db->one("SELECT seq, JSON_UNQUOTE(JSON_EXTRACT(body, '$.parent_ref')) AS parent FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ? "
                . 'AND failed = 0', [$channelId, $ref]);
            if ($pre === null) {
                return 'none';
            }
            $parent = $pre['parent'] === null || $pre['parent'] === 'null' ? null : (string) $pre['parent'];
            $seq = (int) $pre['seq'];
            $marked = false;
            try {
                $r = $this->guarded($channelId, $ref, $parent, function () use ($channelId, $ref, $seq, &$marked): string {
                    try {
                        return $this->db->transaction(function (Db $db) use ($channelId, $ref, $seq): string {
                            $q = $db->one('SELECT seq, version, body_hash, source, first_at, CAST(body AS CHAR) AS body FROM order_snapshot_queue '
                                . 'WHERE channel_id = ? AND order_ref = ? AND failed = 0', [$channelId, $ref]);
                            if ($q === null) {
                                return 'none';
                            }
                            if ((int) $q['seq'] !== $seq) {
                                return 'changed'; // replaced since its guards were chosen: read again
                            }
                            $body = json_decode((string) $q['body'], true, 64, JSON_THROW_ON_ERROR);
                            $now = Clock::db(($this->clock)());
                            $this->apply($db, $channelId, $ref, $body, $now);
                            $db->exec('INSERT INTO order_snapshot (channel_id, order_ref, version, body_hash, body, source, first_at, stored_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
                                . 'AS n ON DUPLICATE KEY UPDATE version = n.version, body_hash = n.body_hash, body = n.body, source = n.source, stored_at = n.stored_at',
                                [$channelId, $ref, (int) $q['version'], (string) $q['body_hash'], (string) $q['body'], (string) $q['source'], (string) $q['first_at'], $now]);
                            $db->exec('DELETE FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ? AND seq = ?', [$channelId, $ref, $seq]);
                            return 'applied';
                        });
                    } catch (\Throwable $e) {
                        // Marked while the order's guards are still held: no other money writer can work a newer snapshot out and take its row
                        // away (and the API then make a new row with seq 1 again) before the mark (the Codex check of 2b718e7). A lock wait of
                        // the mark itself is `busy` (the row stays waiting).
                        if (!self::isBusy($e)) {
                            $this->markRefused($channelId, $ref, $seq, $e);
                            $marked = true;
                        }
                        throw $e;
                    }
                });
            } catch (\Throwable $e) {
                if (self::isBusy($e)) {
                    if ($throw) {
                        throw $e;
                    }
                    return 'busy';
                }
                if (!$marked) {
                    $this->markRefused($channelId, $ref, $seq, $e); // an error before the work (the guards' reads)
                }
                throw $e;
            }
            if ($r !== 'changed') {
                return $r;
            }
        }
        if ($throw) {
            throw new CwException('busy', 'the order\'s snapshot keeps changing; retry shortly', 503);
        }
        return 'busy';
    }

    /**
     * Refuses a snapshot whose line ids are lines of another order of the store (sale_line) or units of another order in CW
     * (reservation_unit), 400 bad_snapshot naming the line. Reads only.
     *
     * @param list<string> $ids
     */
    private static function foreignLines(Db $db, int $channelId, string $ref, array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $other = $db->value('SELECT line_id FROM sale_line WHERE channel_id = ? AND line_id IN (' . self::marks($chunk) . ') AND order_ref <> ? LIMIT 1',
                [$channelId, ...$chunk, $ref]);
            if ($other !== null) {
                $i = self::indexOf($ids, (string) $other);
                throw new CwException('bad_snapshot', "line {$other} belongs to another order", 400, ['field' => "lines[{$i}].line_id"]);
            }
            $unit = $db->value('SELECT u.unit_id FROM reservation_unit u JOIN reservation r ON r.id = u.reservation_id WHERE u.channel_id = ? AND u.unit_id IN ('
                . self::marks($chunk) . ') AND r.order_ref <> ? LIMIT 1', [$channelId, ...$chunk, $ref]);
            if ($unit !== null) {
                $i = self::indexOf($ids, (string) $unit);
                throw new CwException('bad_snapshot', "line {$unit} is a unit of another order", 400, ['field' => "lines[{$i}].line_id"]);
            }
        }
    }

    /** @param list<string> $ids */
    private static function indexOf(array $ids, string $id): int
    {
        foreach ($ids as $i => $x) {
            if (strcasecmp($x, $id) === 0) {
                return $i;
            }
        }
        return 0;
    }

    /**
     * The name of an order's guard (a MySQL named lock, at most 64 characters): every write of an order's figures holds its own and the one
     * of the order it is (or was) a re-send of, so a re-send and its first order, and two first snapshots of one order, wait for each other
     * (the Codex review of 92efa82, a8, b8, b9; no row exists to lock before an order's first snapshot).
     */
    public static function lockName(int $channelId, string $ref): string
    {
        $ref = strtolower($ref); // the key compares without case: ORD1 and ord1 are one order, one guard (the Codex check of c48683e)
        $name = 'cw_profit:' . $channelId . ':' . $ref;
        return strlen($name) <= 64 ? $name : 'cw_profit:' . $channelId . ':' . sha1($ref);
    }

    /** A lock another writer holds (the order's guards, a row): left for the next run, never a refusal. */
    private static function isBusy(\Throwable $e): bool
    {
        return ($e instanceof CwException && $e->errorCode === 'busy') || in_array(Db::driverCode($e), [1205, 1213], true);
    }

    /** The refusal written on the queue row only while it is still the snapshot that was refused (seq): a newer one stored meanwhile is worked out next. */
    private function markRefused(int $channelId, string $ref, int $seq, \Throwable $e): void
    {
        $this->db->exec('UPDATE order_snapshot_queue SET failed = 1, tries = tries + 1, error = ? WHERE channel_id = ? AND order_ref = ? AND seq = ?',
            [mb_substr(($e instanceof CwException ? $e->errorCode : get_class($e)) . ': ' . $e->getMessage(), 0, 255), $channelId, $ref, $seq]);
    }

    /**
     * Runs $fn holding the guards of $ref, of $parent and of the parent the stored order names, taken in name order; 503 busy when one is not
     * free within lockWaitSec. Released whatever happens.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function guarded(int $channelId, string $ref, ?string $parent, \Closure $fn): mixed
    {
        // The parents to hold: the one asked for, the one the stored order names, and (recompute) the one its stored body names. Read
        // before the locks, so read again once they are held: a parent changed meanwhile means the locks are taken again with it
        // (the Codex review of 57d98ad, N9).
        $parentsNow = function () use ($channelId, $ref, $parent): array {
            $out = [];
            foreach ([$parent, $this->db->value('SELECT parent_ref FROM sale_order WHERE channel_id = ? AND order_ref = ?', [$channelId, $ref]),
                $this->db->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(body, '$.parent_ref')) FROM order_snapshot WHERE channel_id = ? AND order_ref = ?", [$channelId, $ref]),
                $this->db->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(body, '$.parent_ref')) FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ?", [$channelId, $ref])] as $p) {
                if ($p !== null && $p !== 'null' && strcasecmp((string) $p, $ref) !== 0) {
                    $out[] = (string) $p;
                    // ... and the first order of its chain, whose re-send figures this work changes (a reparent elsewhere in the chain holds
                    // it too: the Codex check of the fix round, F14 PARTLY, PR152).
                    $root = self::rootOf($this->db, $channelId, (string) $p);
                    if (strcasecmp($root, $ref) !== 0) {
                        $out[] = $root;
                    }
                }
            }
            return $out;
        };
        $refs = [$ref, ...$parentsNow()];
        for ($try = 0; ; $try++) {
            $names = array_values(array_unique(array_map(static fn (string $r): string => self::lockName($channelId, $r), $refs)));
            sort($names, SORT_STRING);
            $held = [];
            try {
                foreach ($names as $n) {
                    if ((int) $this->db->value('SELECT GET_LOCK(?, ?)', [$n, $this->lockWaitSec]) !== 1) {
                        throw new CwException('busy', 'another snapshot of this order (or of the order it is a re-send of) is being stored; retry shortly', 503);
                    }
                    $held[] = $n;
                }
                $missing = array_diff(array_map(static fn (string $r): string => self::lockName($channelId, $r), $parentsNow()), $names);
                if ($missing === []) {
                    return $fn();
                }
                if ($try >= 3) {
                    throw new CwException('busy', 'the order\'s first order keeps changing; retry shortly', 503);
                }
                $refs = array_values(array_unique([...$refs, ...$parentsNow()]));
            } finally {
                foreach (array_reverse($held) as $n) {
                    try {
                        $this->db->value('SELECT RELEASE_LOCK(?)', [$n]);
                    } catch (\Throwable) {
                        // the connection is gone: MySQL released it with the session
                    }
                }
            }
        }
    }

    /**
     * apply() again with the stored snapshot, in a transaction of its own (or the caller's), holding the order's guards. False when the order
     * has none, or when $override is a function that returns null (nothing to do). A batch tool's function ($override a Closure) is only
     * written while no newer snapshot of the order waits, checked again at its commit under the order's intake lock (else `deferred`, false);
     * the batch tools call it outside any transaction of their own, so that check is at the real commit.
     *
     * @param array<string, array{e4: ?int, source: string, by: string}>|\Closure(Db, array<string, mixed>): ?array<string, array<string, mixed>> $override
     *        line id => the cost to fix on it now; or a function that works it out under the locks from the stored body
     */
    public function recompute(int $channelId, string $ref, array|\Closure $override = []): bool
    {
        $this->deferred = false;
        $intake = null;
        try {
            return $this->guarded($channelId, $ref, null, function () use ($channelId, $ref, $override, &$intake): bool {
                return $this->db->transaction(function (Db $db) use ($channelId, $ref, $override, &$intake): bool {
                    // The snapshot worked out last (the one the order's rows say): a batch tool checks and writes against the same version (a newer one
                    // waiting in the queue is worked out after it by the money job; the Codex check of c48683e). Only money writers, each holding the
                    // order's guards, write order_snapshot; the API never touches it.
                    $json = $db->value('SELECT CAST(body AS CHAR) FROM order_snapshot WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $ref]);
                    if ($json === null) {
                        return false;
                    }
                    // A batch tool's costs wait while a newer snapshot of the order waits for the money job: they are worked out against the version the
                    // order's rows say, and a newer one could move its payment out of what the tool checked (the Codex check of c48683e). The tool
                    // says so; run it again after the job.
                    if ($override instanceof \Closure && $db->value('SELECT 1 FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ? AND failed = 0',
                        [$channelId, $ref]) !== null) {
                        $this->deferred = true;
                        return false;
                    }
                    $body = json_decode((string) $json, true, 64, JSON_THROW_ON_ERROR);
                    // A batch tool works its costs out here, under the order's locks, from what is stored now (the Codex review of 92efa82, a11/b11).
                    $o = $override instanceof \Closure ? $override($db, $body) : $override;
                    if ($o === null) {
                        return false;
                    }
                    $this->apply($db, $channelId, $ref, $body, Clock::db(($this->clock)()), $o);
                    // The commit boundary of a batch tool's costs (the Codex check of 2b718e7): the order's intake lock taken without a wait
                    // (the API storing a snapshot of it right now: left for later) and the queue read again under it, so a correction the API
                    // stored while the tool worked is seen, and one it stores from now waits for this commit only (never for the work).
                    if ($override instanceof \Closure) {
                        if ($intake === null) {
                            $name = self::intakeLockName($channelId, $ref);
                            if ((int) $db->value('SELECT GET_LOCK(?, 0)', [$name]) !== 1) {
                                throw new CwException('deferred', 'the API is storing a snapshot of this order', 409);
                            }
                            $intake = $name;
                        }
                        if ($db->value('SELECT 1 FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ? AND failed = 0', [$channelId, $ref]) !== null) {
                            throw new CwException('deferred', 'a newer snapshot of this order waits for the money job', 409);
                        }
                    }
                    return true;
                });
            });
        } catch (CwException $e) {
            if ($e->errorCode !== 'deferred') {
                throw $e;
            }
            $this->deferred = true;
            return false;
        } finally {
            if ($intake !== null) {
                try {
                    $this->db->value('SELECT RELEASE_LOCK(?)', [$intake]);
                } catch (\Throwable) {
                    // the connection is gone: MySQL released it with the session
                }
            }
        }
    }

    /**
     * Works one order out from its (normalised) snapshot, inside the caller's transaction, with the order_snapshot row locked.
     *
     * @param array<string, mixed> $body SnapshotBody::parse()'s shape
     * @param array<string, array{e4: ?int, source: string, by: string, sku?: int, upi?: int}> $override line id => the cost to fix on it now
     *        (and, for a line that had no item, the item it is costed as)
     */
    public function apply(Db $db, int $channelId, string $ref, array $body, string $now, array $override = []): void
    {
        // A batch tool's costs by line id without case (as the line's key compares).
        $override = array_change_key_case($override, CASE_LOWER);
        // The day guards first (ProfitDays::guard: before any row of the order, the lock order a rebuild keeps too): the day the order counted
        // on (only this order's writer, holding its guard, changes it) and the day it counts on now.
        $oldDay = $db->value('SELECT day FROM sale_order WHERE channel_id = ? AND order_ref = ?', [$channelId, $ref]);
        $newDay = $body['paid_at'] === null ? null : ReportRefresh::ukDay((string) $body['paid_at']);
        ProfitDays::guard($db, $channelId, array_values(array_filter([$oldDay === null ? null : (string) $oldDay, $newDay], static fn (?string $d): bool => $d !== null)));
        $old = $db->one('SELECT * FROM sale_order WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $ref]);
        if ($old !== null) {
            // The order's own ref as stored (the key compares without case): a snapshot sent as ord1 of ORD1 writes its new lines under ORD1, so
            // the rebuild and every PHP comparison find them with their order (the second final review of 3c31d94, a-M8, c-M7, PR132).
            $ref = (string) $old['order_ref'];
        }
        $oldLines = [];
        // Keyed without case, as the table's key compares (L1 then l1 is the same line, never one removed and one new: the Codex check of
        // c48683e).
        foreach ($db->all('SELECT * FROM sale_line WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $ref]) as $r) {
            $oldLines[strtolower((string) $r['line_id'])] = $r;
        }
        $ids = array_map(static fn (array $l): string => (string) $l['line_id'], $body['lines']);
        self::foreignLines($db, $channelId, $ref, $ids);
        $vatBp = $old !== null ? (int) $old['vat_bp'] : $this->settings->vatBp();
        $paid = $body['paid_at'];
        $day = $paid === null ? null : ReportRefresh::ukDay((string) $paid);

        // The CW item of each line: the unit's own at the sale, else the listing's link now (an item fixed with its cost stays).
        $units = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($db->all('SELECT u.unit_id, u.listing_id, u.sku_id, u.units_per_item, u.state, r.order_ref FROM reservation_unit u JOIN reservation r ON r.id = u.reservation_id '
                . 'WHERE u.channel_id = ? AND u.unit_id IN (' . self::marks($chunk) . ')', [$channelId, ...$chunk]) as $r) {
                if (strcasecmp((string) $r['order_ref'], $ref) !== 0) {
                    // The unit id is a unit of another order of the store in CW (the Codex review of 92efa82, b6): refused whole. Compared without
                    // case, as the database does (a reservation of vpg42 is order VPG42's: the second final review of 3c31d94, a-HIGH4, PR132).
                    $i = self::indexOf($ids, (string) $r['unit_id']);
                    throw new CwException('bad_snapshot', "line {$r['unit_id']} is a unit of another order", 400, ['field' => "lines[{$i}].line_id"]);
                }
                $units[strtolower((string) $r['unit_id'])] = $r;
            }
        }
        $variants = array_values(array_unique(array_map(static fn (array $l): string => (string) $l['variant_id'], $body['lines'])));
        $listings = [];
        foreach (array_chunk($variants, 500) as $chunk) {
            foreach ($db->all('SELECT id, external_variant_id, sku_id, units_per_item, status FROM channel_listing WHERE channel_id = ? AND external_variant_id IN ('
                . self::marks($chunk) . ')', [$channelId, ...$chunk]) as $r) {
                $listings[strtolower((string) $r['external_variant_id'])] = $r;
            }
        }
        $lines = [];
        $toCost = [];
        foreach ($body['lines'] as $l) {
            $id = (string) $l['line_id'];
            $prev = $oldLines[strtolower($id)] ?? null;
            $fixed = $prev !== null && $prev['cost_source'] !== null;
            // A line stored on a paid order keeps the item, listing and units it was resolved to then, also while its cost waits for the
            // cost job (the Codex review of 57d98ad, N7).
            $resolved = $fixed || ($prev !== null && $prev['day'] !== null && $prev['removed_at'] === null);
            $u = $units[strtolower($id)] ?? null;
            $li = $listings[strtolower((string) $l['variant_id'])] ?? null;
            if (isset($override[strtolower($id)]['sku'])) {
                // A deliberate re-cost of a line that had no item: the item its listing is linked to now (RecostSales).
                $sku = (int) $override[strtolower($id)]['sku'];
                $upi = (int) ($override[strtolower($id)]['upi'] ?? 1);
                $listing = $li === null ? ($prev['listing_id'] ?? null) : (int) $li['id'];
            } elseif ($resolved) {
                $sku = $prev['sku_id'] === null ? null : (int) $prev['sku_id'];
                $upi = (int) $prev['units_per_item'];
                $listing = $prev['listing_id'] === null ? null : (int) $prev['listing_id'];
            } elseif ($u !== null) {
                $sku = $u['sku_id'] === null ? null : (int) $u['sku_id'];
                $upi = (int) $u['units_per_item'];
                $listing = (int) $u['listing_id'];
            } else {
                $linked = $li !== null && in_array((string) $li['status'], ['mapped', 'quarantined'], true) && $li['sku_id'] !== null;
                $sku = $linked ? (int) $li['sku_id'] : null;
                $upi = $li === null ? 1 : (int) $li['units_per_item'];
                $listing = $li === null ? null : (int) $li['id'];
            }
            $restocked = $u !== null && (string) $u['state'] === 'returned';
            $lines[strtolower($id)] = ['l' => $l, 'prev' => $prev, 'fixed' => $fixed, 'sku' => $sku, 'upi' => max(1, $upi), 'listing' => $listing, 'restocked' => $restocked];
            if ($paid !== null && !$fixed && !isset($override[strtolower($id)]) && $sku !== null) {
                $toCost[] = $sku;
            }
        }
        $costs = $paid === null || $toCost === [] ? [] : $this->costs->at($toCost, (string) $paid);

        $newLines = [];
        foreach ($lines as $key => $x) {
            $l = $x['l'];
            $id = (string) $l['line_id'];
            $m = ProfitMath::line($l, $x['upi'], $x['restocked'], $vatBp);
            if ($body['parent_ref'] !== null) {
                // A clone (a re-send, a replacement): the price staff left on it is never money in; its units and cost count as before (the
                // owner, 21 Nov 2026, PR129).
                $m['sale_inc'] = 0;
                $m['sale_ex'] = 0;
            }
            $prev = $x['prev'];
            $e4 = null;
            $source = null;
            $by = null;
            $costAt = null;
            if (isset($override[$key]) && $paid !== null) {
                $e4 = $override[$key]['e4'];
                $source = $override[$key]['source'];
                $by = $override[$key]['by'];
                $costAt = $now;
            } elseif ($x['fixed']) {
                $e4 = $prev['unit_cost_e4'] === null ? null : (int) $prev['unit_cost_e4'];
                $source = (string) $prev['cost_source'];
                $by = (string) $prev['costed_by'];
                $costAt = (string) $prev['cost_at'];
            } elseif ($paid !== null) {
                $c = $x['sku'] === null ? ['e4' => null, 'source' => 'none'] : ($costs[$x['sku']] ?? ['e4' => null, 'source' => 'none']);
                if ($c['source'] !== ItemCosts::PENDING) {
                    // Fixed now. Pending (the cost job has not reached the paid time): left open for PendingCosts.
                    $e4 = $c['e4'];
                    $source = $c['e4'] === null ? 'none' : $c['source'];
                    $by = 'sale';
                    $costAt = $now;
                }
            }
            if ($source === 'none') {
                $e4 = null;
            }
            $newLines[$key] = [
                'channel_id' => $channelId, 'line_id' => $id, 'order_ref' => $ref, 'variant_id' => (string) $l['variant_id'], 'listing_id' => $x['listing'],
                'sku_id' => $x['sku'], 'units_per_item' => $x['upi'], 'qty' => (int) $l['qty'], 'units' => $m['units'], 'price_inc' => (int) $l['price_inc'],
                'discount_inc' => (int) $l['discount_inc'], 'refund_inc' => (int) $l['refund_inc'], 'cancelled' => $l['cancelled'] ? 1 : 0, 'is_free' => $l['free'] ? 1 : 0,
                'restocked' => $x['restocked'] ? 1 : 0, 'sale_inc' => $m['sale_inc'], 'sale_ex' => $m['sale_ex'], 'unit_cost_e4' => $e4,
                'cost' => ProfitMath::lineCost($m['counts'], $m['units'], $e4, $x['restocked']), 'cost_source' => $source, 'costed_by' => $by,
                'cost_at' => $costAt, 'day' => $day, 'removed_at' => null, 'updated_at' => $now,
            ];
        }
        // A line gone from this snapshot: kept, counts nothing.
        foreach ($oldLines as $id => $r) {
            if (isset($newLines[$id])) {
                continue;
            }
            $newLines[$id] = ['units' => 0, 'sale_inc' => 0, 'sale_ex' => 0, 'cost' => 0, 'day' => $day, 'updated_at' => $now,
                'removed_at' => $r['removed_at'] ?? $now] + $r;
        }

        $order = self::totals($body, $newLines, $vatBp) + [
            'channel_id' => $channelId, 'order_ref' => $ref, 'order_no' => (string) $body['order_no'], 'status' => (string) $body['status'],
            'placed_at' => $body['placed_at'], 'paid_at' => $paid, 'day' => $day, 'gateway' => (string) $body['gateway'], 'currency' => 'GBP',
            'parent_ref' => $body['parent_ref'], 'sub_of' => $body['sub_of'] ?? null, 'vat_bp' => $vatBp, 'snapshot_version' => (int) $body['version'],
            'first_at' => $old === null ? $now : (string) $old['first_at'], 'updated_at' => $now,
        ];
        // Its re-sends (the cost that belongs to it), as stored now: every replacement of its chain (a replacement of a replacement too) when it
        // is a first order; none when it is a replacement itself: theirs go on the first order of the chain (the owner's rule; the second final
        // review of 3c31d94, a-M9, c-M6, PR143).
        $rs = $body['parent_ref'] !== null ? ['n' => 0, 'c' => 0, 'nc' => 0, 'open' => 0] : self::resendFigures($db, $channelId, $ref);
        $order['resends'] = (int) $rs['n'];
        $order['resend_cost'] = (int) $rs['c'];
        // No margin while some units have no buying price or wait for it (a figure that is not known is never ranked: the Codex review of
        // 57d98ad, c6); the screens say it is incomplete.
        $open = array_filter($newLines, static fn (array $r): bool => $r['removed_at'] === null && $r['cost_source'] === null && $r['day'] !== null
            && (int) $r['units'] > 0 && (int) $r['restocked'] === 0);
        $order['margin_bp'] = $order['units_no_cost'] > 0 || $open !== [] || (int) $rs['nc'] > 0 || (int) $rs['open'] > 0 ? null
            : ProfitMath::marginBp($order['sale_ex'] - $order['cost'] - $order['resend_cost'], $order['sale_ex']);

        self::upsert($db, 'sale_order', [$order], ['channel_id', 'order_ref', 'first_at']);
        foreach (array_chunk(array_values($newLines), 200) as $chunk) {
            self::upsert($db, 'sale_line', array_map(static fn (array $r): array => self::lineRow($r), $chunk), ['channel_id', 'line_id', 'order_ref']);
        }
        // A line id another order of the store stored at the same moment (its row was new to both checks above): never taken over (the
        // Codex review of 92efa82, b7): the upsert kept its order, and the whole snapshot is refused (rolled back).
        foreach (array_chunk($ids, 500) as $chunk) {
            $other = $db->value('SELECT line_id FROM sale_line WHERE channel_id = ? AND line_id IN (' . self::marks($chunk) . ') AND order_ref <> ? LIMIT 1',
                [$channelId, ...$chunk, $ref]);
            if ($other !== null) {
                $i = self::indexOf($ids, (string) $other);
                throw new CwException('bad_snapshot', "line {$other} belongs to another order", 400, ['field' => "lines[{$i}].line_id"]);
            }
        }
        $this->parcels($db, $channelId, $ref, $body['parcels'], $now);
        // The re-send figures of the first order(s) of the chain(s) this one is (or was) in: its parent followed up to the order that is none's
        // re-send (PR143).
        $parents = [];
        foreach ([$old['parent_ref'] ?? null, $body['parent_ref']] as $p) {
            if ($p !== null) {
                $root = self::rootOf($db, $channelId, (string) $p);
                $parents[strtolower($root)] = $root;
            }
        }
        ksort($parents, SORT_STRING);
        foreach ($parents as $p) {
            self::refreshParent($db, $channelId, $p);
        }
        // The day summaries: what the order counts now less what it counted before.
        ProfitDays::change($db, $channelId, ProfitDays::orderShare($old, $oldLines), ProfitDays::orderShare($order, $newLines));
    }

    /**
     * The order's totals from its lines (the ones that count) and its delivery.
     *
     * @param array<string, mixed> $body
     * @param array<string, array<string, mixed>> $lines
     * @return array<string, int|string|null>
     */
    public static function totals(array $body, array $lines, int $vatBp): array
    {
        $t = ['line_count' => 0, 'units' => 0, 'goods_inc' => 0, 'discount_inc' => 0, 'line_refund_inc' => 0, 'sale_inc' => 0, 'sale_ex' => 0, 'cost' => 0,
            'units_no_cost' => 0];
        foreach ($lines as $r) {
            if ($r['removed_at'] !== null) {
                continue;
            }
            $t['line_count']++;
            $t['goods_inc'] += (int) $r['price_inc'];
            $t['discount_inc'] += (int) $r['discount_inc'];
            $t['line_refund_inc'] += (int) $r['refund_inc'];
            $t['units'] += (int) $r['units'];
            $t['sale_inc'] += (int) $r['sale_inc'];
            $t['sale_ex'] += (int) $r['sale_ex'];
            $t['cost'] += (int) $r['cost'];
            $t['units_no_cost'] += self::unitsNoCost($r);
        }
        // An order-level refund comes off at the lines' own ex-VAT rate (each line rounded on its own): taken off the lines' sale in
        // proportion, so a refund of everything the lines sold takes their ex-VAT sale off exactly (ten 99p lines: 830p, not 825p; the final
        // review of 6d59eda, c-LOW1, PR105); what is beyond the lines' sale comes off at the order's rate.
        // A clone (parent_ref: a re-send) counts no money at all (the owner, 21 Nov 2026, PR129): its lines sell nothing (apply()), its refunds
        // take nothing off (never below 0) and its delivery charge is none; what the shop sent stays in the _inc fields as it sent it.
        $clone = ($body['parent_ref'] ?? null) !== null;
        $refund = (int) $body['order_refund_inc'];
        $within = $t['sale_inc'] > 0 ? min($refund, $t['sale_inc']) : 0;
        $refundEx = $clone ? 0 : ($within > 0 ? ProfitMath::mulDivRound($t['sale_ex'], $within, $t['sale_inc']) : 0) + ProfitMath::exVat($refund - $within, $vatBp);
        $t['sale_inc'] -= $clone ? 0 : $refund;
        $t['sale_ex'] -= $refundEx;
        $d = $body['delivery'];
        return $t + [
            'order_refund_inc' => $refund,
            'order_refund_ex' => $refundEx,
            'delivery_method_id' => $d['method_id'],
            'delivery_method' => $d['method'],
            'delivery_charged_inc' => (int) $d['charged_inc'],
            'delivery_refund_inc' => (int) $d['refund_inc'],
            'delivery_ex' => $clone ? 0 : ProfitMath::exVat((int) $d['charged_inc'] - (int) $d['refund_inc'], $vatBp),
            'grand_inc' => (int) $body['grand_inc'],
        ];
    }

    /** Units of a line that count and have no buying price (a unit back in stock has none by rule: not missing). */
    public static function unitsNoCost(array $r): int
    {
        return $r['removed_at'] === null && (int) $r['units'] > 0 && (int) $r['restocked'] === 0 && ($r['cost_source'] ?? null) === 'none' ? (int) $r['units'] : 0;
    }

    /** How far a chain of replacements is followed (a replacement of a replacement ...), up and down: bad data never loops. */
    public const CHAIN_DEPTH = 10;

    /**
     * The first order of the chain $ref is in: its parent_ref followed up while the order is stored and names one, at most CHAIN_DEPTH steps,
     * never round a loop (PR143). An order not stored yet is its own first order for now (its replacements' cost goes on it when it comes).
     */
    public static function rootOf(Db $db, int $channelId, string $ref): string
    {
        $seen = [strtolower($ref) => true];
        for ($i = 0; $i < self::CHAIN_DEPTH; $i++) {
            $p = $db->value('SELECT parent_ref FROM sale_order WHERE channel_id = ? AND order_ref = ?', [$channelId, $ref]);
            if ($p === null || isset($seen[strtolower((string) $p)])) {
                return $ref;
            }
            $ref = (string) $p;
            $seen[strtolower($ref)] = true;
        }
        return $ref;
    }

    /**
     * Every replacement of $root's chain (stored): the orders naming it, the orders naming those, ... at most CHAIN_DEPTH levels, each once
     * (PR143).
     *
     * @return list<string>
     */
    public static function descendants(Db $db, int $channelId, string $root): array
    {
        $seen = [strtolower($root) => true];
        $out = [];
        $level = [$root];
        for ($depth = 0; $level !== [] && $depth < self::CHAIN_DEPTH; $depth++) {
            $next = [];
            foreach (array_chunk($level, 500) as $chunk) {
                foreach ($db->column('SELECT order_ref FROM sale_order WHERE channel_id = ? AND parent_ref IN (' . self::marks($chunk) . ') ORDER BY order_ref',
                    [$channelId, ...$chunk]) as $r) {
                    if (!isset($seen[strtolower((string) $r)])) {
                        $seen[strtolower((string) $r)] = true;
                        $out[] = (string) $r;
                        $next[] = (string) $r;
                    }
                }
            }
            $level = $next;
        }
        return $out;
    }

    /** The re-sends of $parent's chain (paid, stored) and their cost on its row, and its margin with them; a replacement holds none (PR143). */
    public static function refreshParent(Db $db, int $channelId, string $parent): void
    {
        $p = $db->one('SELECT sale_ex, cost, units_no_cost, margin_bp, parent_ref FROM sale_order WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $parent]);
        if ($p === null || $p['parent_ref'] !== null) {
            return;
        }
        $rs = self::resendFigures($db, $channelId, $parent);
        // A line waits for a cost only when it has one to wait for: units that count and not back in stock (those cost 0, known: the Codex
        // check of 51417a3, L6).
        $ownOpen = (int) $db->value('SELECT COUNT(*) FROM sale_line WHERE channel_id = ? AND order_ref = ? AND cost_source IS NULL AND day IS NOT NULL AND removed_at IS NULL '
            . 'AND units > 0 AND restocked = 0',
            [$channelId, $parent]);
        $complete = (int) $p['units_no_cost'] === 0 && $ownOpen === 0 && (int) $rs['nc'] === 0 && (int) $rs['open'] === 0;
        $db->exec('UPDATE sale_order SET resends = ?, resend_cost = ?, margin_bp = ? WHERE channel_id = ? AND order_ref = ?',
            [(int) $rs['n'], (int) $rs['c'], $complete ? ProfitMath::marginBp((int) $p['sale_ex'] - (int) $p['cost'] - (int) $rs['c'], (int) $p['sale_ex']) : null,
                $channelId, $parent]);
    }

    /**
     * The paid re-sends of $parent's chain as stored now (descendants(): a replacement of a replacement too, PR143): how many, their cost,
     * their units with no price and their lines waiting for a cost.
     *
     * @return array{n: int|string, c: int|string, nc: int|string, open: int|string}
     */
    public static function resendFigures(Db $db, int $channelId, string $parent): array
    {
        $refs = self::descendants($db, $channelId, $parent);
        $r = ['n' => 0, 'c' => 0, 'nc' => 0, 'open' => 0];
        foreach (array_chunk($refs, 500) as $chunk) {
            $f = $db->one('SELECT COUNT(*) AS n, COALESCE(SUM(o.cost), 0) AS c, COALESCE(SUM(o.units_no_cost), 0) AS nc FROM sale_order o '
                . 'WHERE o.channel_id = ? AND o.order_ref IN (' . self::marks($chunk) . ') AND o.paid_at IS NOT NULL', [$channelId, ...$chunk]) ?? [];
            $r['n'] += (int) ($f['n'] ?? 0);
            $r['c'] += (int) ($f['c'] ?? 0);
            $r['nc'] += (int) ($f['nc'] ?? 0);
            $r['open'] += (int) ($db->value('SELECT COUNT(*) FROM sale_line l JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
                . 'WHERE o.channel_id = ? AND o.order_ref IN (' . self::marks($chunk) . ') AND o.paid_at IS NOT NULL AND l.cost_source IS NULL AND l.removed_at IS NULL '
                . 'AND l.units > 0 AND l.restocked = 0', [$channelId, ...$chunk]) ?? 0);
        }
        return $r;
    }

    /** @param list<array<string, mixed>> $parcels */
    private function parcels(Db $db, int $channelId, string $ref, array $parcels, string $now): void
    {
        $have = [];
        foreach ($db->all('SELECT parcel_ref, removed_at FROM sale_parcel WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $ref]) as $r) {
            $have[strtolower((string) $r['parcel_ref'])] = (string) $r['parcel_ref'];
        }
        $rows = [];
        foreach ($parcels as $p) {
            $rows[] = ['channel_id' => $channelId, 'order_ref' => $ref, 'parcel_ref' => (string) $p['parcel_ref'], 'tracking_no' => $p['tracking_no'],
                'carrier' => (string) $p['carrier'], 'service_code' => (string) $p['service_code'], 'service' => (string) $p['service'], 'status' => (string) $p['status'],
                'label_at' => $p['label_at'], 'shipped_at' => $p['shipped_at'], 'is_manual' => $p['manual'] ? 1 : 0, 'is_partial' => $p['partial'] ? 1 : 0,
                'first_at' => $now, 'updated_at' => $now, 'removed_at' => null];
            unset($have[strtolower((string) $p['parcel_ref'])]);
        }
        if ($rows !== []) {
            self::upsert($db, 'sale_parcel', $rows, ['channel_id', 'order_ref', 'parcel_ref', 'first_at']);
        }
        foreach ($have as $pref) {
            // A parcel gone from the snapshot keeps its codes and times and no text at all (its service name is cleared: the final review of
            // 6d59eda, b-HIGH2, PR96).
            $db->exec("UPDATE sale_parcel SET removed_at = COALESCE(removed_at, ?), service = '', updated_at = ? WHERE channel_id = ? AND order_ref = ? AND parcel_ref = ? "
                . "AND (removed_at IS NULL OR service <> '')", [$now, $now, $channelId, $ref, (string) $pref]);
        }
    }

    /** @param array<string, mixed> $r @return array<string, mixed> the sale_line columns */
    private static function lineRow(array $r): array
    {
        $cols = ['channel_id', 'line_id', 'order_ref', 'variant_id', 'listing_id', 'sku_id', 'units_per_item', 'qty', 'units', 'price_inc', 'discount_inc', 'refund_inc',
            'cancelled', 'is_free', 'restocked', 'sale_inc', 'sale_ex', 'unit_cost_e4', 'cost', 'cost_source', 'costed_by', 'cost_at', 'day', 'removed_at', 'updated_at'];
        $out = [];
        foreach ($cols as $c) {
            $out[$c] = $r[$c] ?? null;
        }
        return $out;
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE of whole rows (the same columns each); $keep are never changed on an existing row.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $keep
     */
    private static function upsert(Db $db, string $table, array $rows, array $keep): void
    {
        $cols = array_keys($rows[0]);
        $params = [];
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $params[] = $r[$c];
            }
        }
        $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = n.`{$c}`", array_values(array_diff($cols, $keep))));
        $db->exec('INSERT INTO ' . $table . ' (' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $cols)) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), '(' . self::marks($cols) . ')')) . ' AS n ON DUPLICATE KEY UPDATE ' . $set, $params);
    }

    /** @param list<mixed> $a */
    private static function marks(array $a): string
    {
        return implode(', ', array_fill(0, count($a), '?'));
    }
}
