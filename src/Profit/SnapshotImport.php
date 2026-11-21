<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;
use CW\Db;

/**
 * The backfill of order snapshots from the connector's day files (bin/import_order_snapshots.php; CONTRACT.md "Bulk" and change 7;
 * docs/decisions.md PR3): one JSON object per line with its `ref`, stored through the SAME service as the API (OrderSnapshots::store,
 * source `bulk`): the same checks, the same "older ignored", one transaction per order, so a backfill and the live feed can never disagree.
 *
 * The file proves its store before anything is written (the Codex review of 92efa82, b13, b14): its name is snapshots_<store>_<day>.jsonl
 * and names the store asked for, and its .sum line (next to it) is there, whole, names the same store and day, and matches the file (its
 * sha256; its orders, lines, goods and delivery when every line could be read). The file is read twice, line by line, from a copy that has
 * no name on disk (PR154): once to check it (nothing written when it does not match), once to store it. A line CW refuses is reported with its field and the rest go on; a line
 * longer than the cap is ONE refused line, whatever follows on it (b15).
 */
final class SnapshotImport
{
    public const MAX_LINE_BYTES = SnapshotBody::MAX_BYTES;
    private const SUM_KEYS = ['channel', 'day', 'orders', 'lines', 'goods_inc', 'delivery_inc', 'file_sha256'];

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null)
    {
    }

    /**
     * @param \Closure(string): void $log
     * @param ?string $notBefore the store's first reservation in CW (UTC): an order placed before it is refused, the file whole, nothing written
     *        (bin/import_order_snapshots.php without --allow-unreserved; the second final review of 3c31d94, c-HIGH3, PR148); null: no such check
     * @return array{lines: int, results: array<string, int>, refused: int, sum: array<string, mixed>, sum_ok: bool, totals_checked: bool, read: array<string, int>,
     *         file_refused: int}
     */
    public function file(string $channelCode, string $path, bool $dryRun, \Closure $log, ?string $notBefore = null): array
    {
        $channel = $this->db->value('SELECT id FROM channel WHERE code = ?', [$channelCode]);
        if ($channel === null) {
            throw new \InvalidArgumentException("no store {$channelCode}");
        }
        $base = basename($path);
        if (preg_match('/^snapshots_([a-z][a-z0-9_]*)_(\d{4}-\d{2}-\d{2})\.jsonl$/D', $base, $m) !== 1) {
            throw new \InvalidArgumentException("{$base}: a day file is named snapshots_<store>_<day>.jsonl (the store it is of cannot be told otherwise)");
        }
        if ($m[1] !== $channelCode) {
            throw new \InvalidArgumentException("{$base} is a file of the store {$m[1]}, not {$channelCode}");
        }
        $sum = $this->sum($path, $channelCode, $m[2]);
        // The file as it is now, copied aside: what is checked is what is stored (the Codex review of 57d98ad, b14). The copy has no name from
        // before a byte of the file is in it: made, opened and unlinked at once, then filled and read (both passes) through the open handle
        // only, so a run killed at any moment leaves no copy of the day file on disk (the third final review, a-M / b-M5, PR154; the
        // second final review's `finally` needed PHP to reach it).
        $copy = $this->privateCopy($path);
        try {
            return $this->checked($copy, $sum, (int) $channel, $dryRun, $log, $m[2], $notBefore);
        } finally {
            fclose($copy);
        }
    }

    /**
     * A copy of $path that exists only as an open handle (its name unlinked before anything is written to it), rewound.
     *
     * @return resource
     */
    private function privateCopy(string $path)
    {
        $name = tempnam(sys_get_temp_dir(), 'cw_snap_');
        if ($name === false) {
            throw new \InvalidArgumentException("cannot make a copy of {$path}");
        }
        $fh = @fopen($name, 'w+b');
        @unlink($name);
        if ($fh === false) {
            throw new \InvalidArgumentException("cannot make a copy of {$path}");
        }
        $src = @fopen($path, 'rb');
        if ($src === false) {
            fclose($fh);
            throw new \InvalidArgumentException("cannot read {$path}");
        }
        try {
            if (stream_copy_to_stream($src, $fh) === false) {
                fclose($fh);
                throw new \InvalidArgumentException("cannot read {$path}");
            }
        } finally {
            fclose($src);
        }
        rewind($fh);
        return $fh;
    }

    /**
     * @param resource $path the copy (privateCopy())
     * @param array<string, mixed> $sum
     * @param \Closure(string): void $log
     * @return array{lines: int, results: array<string, int>, refused: int, sum: array<string, mixed>, sum_ok: bool, read: array<string, int>}
     */
    private function checked($path, array $sum, int $channel, bool $dryRun, \Closure $log, string $day, ?string $notBefore): array
    {
        $now = $this->clock === null ? \CW\Clock::now() : ($this->clock)();
        // Pass 1: every line checked, nothing written.
        $check = $this->pass($path, null, $channel, $now, static fn (string $m) => null);
        // The file holds each order once, each placed on the file's own London day (the connector's export: one file per day the orders were
        // PLACED, CONTRACT change 7), none placed before the store's first reservation when asked: else nothing is written (the second final
        // review of 3c31d94, a-L3, b-M5, PR138; c-HIGH3, PR148): two copies of a £12 order with a .sum of two orders and £24 passed.
        $bad = [];
        foreach ($check['orders'] as $o) {
            if ($o['twice'] !== null) {
                $bad[] = "line {$o['line']}: order {$o['ref']} is in the file twice (line {$o['twice']} has it already)";
            } elseif ($o['day'] !== $day) {
                $bad[] = "line {$o['line']}: order {$o['ref']} was placed on " . ($o['day'] ?? 'no day (no placed_at)') . ", not on the file's day {$day}";
            } elseif ($notBefore !== null && ($o['placed'] === null || $o['placed'] < $notBefore)) {
                $bad[] = "line {$o['line']}: order {$o['ref']} was placed before the store's first reservation in CW ({$notBefore} UTC): CW never held it "
                    . '(--allow-unreserved loads it anyway)';
            }
        }
        if ($bad !== []) {
            foreach (array_slice($bad, 0, 50) as $b) {
                $log($b);
            }
            $log(count($bad) . ' order(s) not of this file as it should be: nothing written');
            return ['lines' => $check['lines'], 'results' => array_fill_keys(OrderSnapshots::RESULTS, 0), 'refused' => $check['refused'], 'sum' => $sum,
                'sum_ok' => false, 'totals_checked' => false, 'read' => $check['read'], 'file_refused' => count($bad)];
        }
        rewind($path);
        $h = hash_init('sha256');
        hash_update_stream($h, $path);
        $sumOk = hash_equals(strtolower((string) $sum['file_sha256']), hash_final($h));
        if (!$sumOk) {
            $log('the .sum names another file (its sha256 differs): nothing written');
        }
        // The money is compared only when every line could be read: with a line refused the day's totals are NOT checked, and that is said
        // apart from the file's own match (the final review of 6d59eda, b-M; PR106).
        $totalsChecked = $sumOk && $check['refused'] === 0;
        if ($sumOk && $check['refused'] > 0) {
            $log("the .sum's orders, lines, goods and delivery are NOT checked: {$check['refused']} line(s) refused");
        }
        if ($totalsChecked) {
            foreach (['orders', 'lines', 'goods_inc', 'delivery_inc'] as $k) {
                if ((int) $sum[$k] !== $check['read'][$k]) {
                    $sumOk = false;
                    $log("the .sum says {$k} {$sum[$k]}, the file holds {$check['read'][$k]}: nothing written");
                }
            }
        }
        if (!$sumOk || $dryRun) {
            if ($dryRun) {
                $this->pass($path, null, $channel, $now, $log); // the refusals, said
            }
            return ['lines' => $check['lines'], 'results' => array_fill_keys(OrderSnapshots::RESULTS, 0), 'refused' => $check['refused'], 'sum' => $sum,
                'sum_ok' => $sumOk, 'totals_checked' => $totalsChecked && $sumOk, 'read' => $check['read'], 'file_refused' => 0];
        }
        // Pass 2: stored, order by order (the first reservation's boundary was kept by pass 1: a file with an order before it never gets here).
        $done = $this->pass($path, new OrderSnapshots($this->db, $this->clock), $channel, $now, $log);
        // The replacements of this file whose first order CW does not hold (its day not loaded, or before the boundary): their cost is on
        // their own day, but no first order shows it until the original's day is loaded (the third final review, c-M7, PR154).
        $orphans = $this->orphans($channel, $done['parents']);
        if ($orphans !== []) {
            $log(count($orphans) . " replacement(s) of this file whose first order is not in CW (load the first order's day file): "
                . implode(', ', array_map(static fn (string $r, string $p): string => "{$r} (first order {$p})", array_keys(array_slice($orphans, 0, 50, true)),
                    array_slice($orphans, 0, 50, true))) . (count($orphans) > 50 ? ', ...' : ''));
        }
        return ['lines' => $done['lines'], 'results' => $done['results'], 'refused' => $done['refused'], 'sum' => $sum, 'sum_ok' => true,
            'totals_checked' => $totalsChecked && $done['refused'] === 0, 'read' => $done['read'], 'file_refused' => 0, 'orphans' => count($orphans)];
    }

    /**
     * Of the replacements stored ($parents: ref => the parent_ref it names), those whose chain's first order is not in CW (no sale_order row
     * of the order its parent_ref chain reaches).
     *
     * @param array<string, string> $parents
     * @return array<string, string> ref => the first order it names
     */
    private function orphans(int $channel, array $parents): array
    {
        $out = [];
        foreach ($parents as $ref => $parent) {
            $root = OrderSnapshots::rootOf($this->db, $channel, $parent);
            if ($this->db->value('SELECT 1 FROM sale_order WHERE channel_id = ? AND order_ref = ?', [$channel, $root]) === null) {
                $out[(string) $ref] = $root;
            }
        }
        return $out;
    }

    /**
     * One pass over the file: each line read (a line past the cap drained to its end and refused once), checked, and stored when $service.
     *
     * @param resource $fh the copy (privateCopy()), read from its start
     * @param \Closure(string): void $log
     * @return array{lines: int, results: array<string, int>, refused: int, read: array<string, int>,
     *         orders: list<array{line: int, ref: string, day: ?string, placed: ?string, twice: ?int}>, parents: array<string, string>}
     */
    private function pass($fh, ?OrderSnapshots $service, int $channel, \DateTimeImmutable $now, \Closure $log): array
    {
        $orders = [];
        $seen = [];
        $parents = [];
        rewind($fh);
        $results = array_fill_keys(OrderSnapshots::RESULTS, 0);
        $read = ['orders' => 0, 'lines' => 0, 'goods_inc' => 0, 'delivery_inc' => 0];
        $refused = 0;
        $n = 0;
        try {
            while (($line = fgets($fh, self::MAX_LINE_BYTES + 2)) !== false) {
                $n++;
                $tooLong = strlen($line) > self::MAX_LINE_BYTES && !str_ends_with($line, "\n");
                while ($tooLong && !str_ends_with((string) $line, "\n") && ($line = fgets($fh, 65536)) !== false) {
                    // the rest of an over-long line: never read as a line of its own
                }
                if ($tooLong) {
                    $refused++;
                    $log("line {$n}: refused body_too_large: the line is larger than " . intdiv(self::MAX_LINE_BYTES, 1024) . ' KB');
                    continue;
                }
                if (trim($line) === '') {
                    continue;
                }
                try {
                    try {
                        $o = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        throw new CwException('bad_json', 'the line is not valid JSON', 400);
                    }
                    if (!is_array($o) || ltrim($line)[0] !== '{') {
                        throw new CwException('bad_json', 'the line is not a JSON object', 400);
                    }
                    $ref = SnapshotBody::orderRef($o['ref'] ?? null, 'ref');
                    if ($service === null) {
                        // Its ref and its placing read before ANY other field of it is checked (its lists' shapes too: a copy with "lines": {} is
                        // still a copy; the third final review, a-M / b-M1, PR154): an order twice (one copy broken), of another day or before
                        // the first reservation refuses the file whole, whatever else is wrong with the line (the Codex check of the fix round, F9 /
                        // F19 PARTLY, PR152).
                        $placed = null;
                        if (is_string($o['placed_at'] ?? null) && preg_match('/(Z|[+-][0-9]{2}:?[0-9]{2})$/D', $o['placed_at']) === 1) {
                            try {
                                $placed = \CW\Clock::db(\CW\Clock::parse($o['placed_at'], 'placed_at'));
                            } catch (CwException) {
                                $placed = null;
                            }
                        }
                        $orders[] = ['line' => $n, 'ref' => $ref, 'day' => $placed === null ? null : \CW\Reports\ReportRefresh::ukDay($placed), 'placed' => $placed,
                            'twice' => $seen[strtolower($ref)] ?? null];
                        $seen[strtolower($ref)] ??= $n;
                    }
                    SnapshotBody::checkJsonShapes($line);
                    $body = SnapshotBody::parse($o, $now);
                    // An order that names itself as its first order: refused in the dry run as in the write (the third final review, b-L1, PR154).
                    if (strcasecmp((string) $body['parent_ref'], $ref) === 0) {
                        throw new CwException('bad_snapshot', 'parent_ref must be another order', 400, ['field' => 'parent_ref']);
                    }
                    $read['orders']++;
                    $read['lines'] += count($body['lines']);
                    foreach ($body['lines'] as $l) {
                        $read['goods_inc'] += $l['price_inc'] - $l['discount_inc'];
                    }
                    $read['delivery_inc'] += $body['delivery']['charged_inc'];
                    if ($service !== null) {
                        $results[$service->store($channel, $ref, $o, 'bulk')['result']]++;
                        if ($body['parent_ref'] !== null) {
                            $parents[$ref] = (string) $body['parent_ref'];
                        }
                    }
                } catch (CwException $e) {
                    $refused++;
                    $field = is_string($e->detail['field'] ?? null) ? ' (' . $e->detail['field'] . ')' : '';
                    $log("line {$n}: refused {$e->errorCode}{$field}: {$e->getMessage()}");
                } catch (\PDOException $e) {
                    // An order whose rows another writer still held after its tries: this line is refused (busy: run the file again), the rest
                    // of the file goes on (the Codex check of the fix round, F8 PARTLY, PR152; a lock wait stopped the whole file).
                    if (!in_array(Db::driverCode($e), [1205, 1213], true)) {
                        throw $e;
                    }
                    $refused++;
                    $log("line {$n}: refused busy: the order is held by another writer (MySQL " . Db::driverCode($e) . '); run the file again');
                }
            }
        } finally {
            rewind($fh);
        }
        return ['lines' => $n, 'results' => $results, 'refused' => $refused, 'read' => $read, 'orders' => $orders, 'parents' => $parents];
    }

    /**
     * The day file's .sum line (<name>.sum, else <name>.jsonl.sum): there, one JSON object with every key, the same store and day.
     *
     * @return array<string, mixed>
     */
    private function sum(string $path, string $channelCode, string $day): array
    {
        foreach ([(string) preg_replace('/\.jsonl$/D', '.sum', $path), $path . '.sum'] as $p) {
            if ($p === $path || !is_file($p)) {
                continue;
            }
            $s = json_decode(trim((string) file_get_contents($p, false, null, 0, 65536)), true);
            if (!is_array($s) || array_diff(self::SUM_KEYS, array_keys($s)) !== [] || !is_string($s['file_sha256']) || !is_string($s['channel'])) {
                throw new \InvalidArgumentException(basename($p) . ': the .sum is not whole (it needs ' . implode(', ', self::SUM_KEYS) . ')');
            }
            foreach (['orders', 'lines', 'goods_inc', 'delivery_inc'] as $k) {
                if (!is_int($s[$k])) {
                    throw new \InvalidArgumentException(basename($p) . ": the .sum's {$k} is not a whole number");
                }
            }
            if ($s['channel'] !== $channelCode || $s['day'] !== $day) {
                throw new \InvalidArgumentException(basename($p) . ": the .sum is of the store {$s['channel']}, day " . (is_string($s['day']) ? $s['day'] : '?')
                    . ", not {$channelCode} {$day}");
            }
            return $s;
        }
        throw new \InvalidArgumentException(basename($path) . ': the day file has no .sum next to it (snapshots_<store>_<day>.sum): it is not read');
    }
}
