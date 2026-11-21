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
 * sha256; its orders, lines, goods and delivery when every line could be read). The file is read twice, line by line: once to check it
 * (nothing written when it does not match), once to store it. A line CW refuses is reported with its field and the rest go on; a line
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
        // The file as it is now, copied aside: what is checked is what is stored (the Codex review of 57d98ad, b14). The copy is removed whatever
        // happens, a copy that failed half way too (the second final review of 3c31d94, b-M9, PR151).
        $copy = tempnam(sys_get_temp_dir(), 'cw_snap_');
        if ($copy === false) {
            throw new \InvalidArgumentException("cannot make a copy of {$path}");
        }
        try {
            if (!@copy($path, $copy)) {
                throw new \InvalidArgumentException("cannot read {$path}");
            }
            return $this->checked($copy, $sum, (int) $channel, $dryRun, $log, $m[2], $notBefore);
        } finally {
            @unlink($copy);
        }
    }

    /**
     * @param array<string, mixed> $sum
     * @param \Closure(string): void $log
     * @return array{lines: int, results: array<string, int>, refused: int, sum: array<string, mixed>, sum_ok: bool, read: array<string, int>}
     */
    private function checked(string $path, array $sum, int $channel, bool $dryRun, \Closure $log, string $day, ?string $notBefore): array
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
        $sumOk = hash_equals(strtolower((string) $sum['file_sha256']), (string) hash_file('sha256', $path));
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
        // Pass 2: stored, order by order.
        $done = $this->pass($path, new OrderSnapshots($this->db, $this->clock), $channel, $now, $log);
        return ['lines' => $done['lines'], 'results' => $done['results'], 'refused' => $done['refused'], 'sum' => $sum, 'sum_ok' => true,
            'totals_checked' => $totalsChecked && $done['refused'] === 0, 'read' => $done['read'], 'file_refused' => 0];
    }

    /**
     * One pass over the file: each line read (a line past the cap drained to its end and refused once), checked, and stored when $service.
     *
     * @param \Closure(string): void $log
     * @return array{lines: int, results: array<string, int>, refused: int, read: array<string, int>,
     *         orders: list<array{line: int, ref: string, day: ?string, placed: ?string, twice: ?int}>}
     */
    private function pass(string $path, ?OrderSnapshots $service, int $channel, \DateTimeImmutable $now, \Closure $log): array
    {
        $orders = [];
        $seen = [];
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new \InvalidArgumentException("cannot read {$path}");
        }
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
                    SnapshotBody::checkJsonShapes($line);
                    $ref = SnapshotBody::orderRef($o['ref'] ?? null, 'ref');
                    $body = SnapshotBody::parse($o, $now);
                    if ($service === null) {
                        $placed = $body['placed_at'] === null ? null : (string) $body['placed_at'];
                        $orders[] = ['line' => $n, 'ref' => $ref, 'day' => $placed === null ? null : \CW\Reports\ReportRefresh::ukDay($placed), 'placed' => $placed,
                            'twice' => $seen[strtolower($ref)] ?? null];
                        $seen[strtolower($ref)] ??= $n;
                    }
                    $read['orders']++;
                    $read['lines'] += count($body['lines']);
                    foreach ($body['lines'] as $l) {
                        $read['goods_inc'] += $l['price_inc'] - $l['discount_inc'];
                    }
                    $read['delivery_inc'] += $body['delivery']['charged_inc'];
                    if ($service !== null) {
                        $results[$service->store($channel, $ref, $o, 'bulk')['result']]++;
                    }
                } catch (CwException $e) {
                    $refused++;
                    $field = is_string($e->detail['field'] ?? null) ? ' (' . $e->detail['field'] . ')' : '';
                    $log("line {$n}: refused {$e->errorCode}{$field}: {$e->getMessage()}");
                }
            }
        } finally {
            fclose($fh);
        }
        return ['lines' => $n, 'results' => $results, 'refused' => $refused, 'read' => $read, 'orders' => $orders];
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
