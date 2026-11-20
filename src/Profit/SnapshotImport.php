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
     * @return array{lines: int, results: array<string, int>, refused: int, sum: array<string, mixed>, sum_ok: bool, read: array<string, int>}
     */
    public function file(string $channelCode, string $path, bool $dryRun, \Closure $log): array
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
        $now = $this->clock === null ? \CW\Clock::now() : ($this->clock)();
        // Pass 1: every line checked, nothing written.
        $check = $this->pass($path, null, (int) $channel, $now, static fn (string $m) => null);
        $sumOk = hash_equals(strtolower((string) $sum['file_sha256']), (string) hash_file('sha256', $path));
        if (!$sumOk) {
            $log('the .sum names another file (its sha256 differs): nothing written');
        }
        if ($sumOk && $check['refused'] === 0) {
            foreach (['orders', 'lines', 'goods_inc', 'delivery_inc'] as $k) {
                if ((int) $sum[$k] !== $check['read'][$k]) {
                    $sumOk = false;
                    $log("the .sum says {$k} {$sum[$k]}, the file holds {$check['read'][$k]}: nothing written");
                }
            }
        }
        if (!$sumOk || $dryRun) {
            if ($dryRun) {
                $this->pass($path, null, (int) $channel, $now, $log); // the refusals, said
            }
            return ['lines' => $check['lines'], 'results' => array_fill_keys(OrderSnapshots::RESULTS, 0), 'refused' => $check['refused'], 'sum' => $sum,
                'sum_ok' => $sumOk, 'read' => $check['read']];
        }
        // Pass 2: stored, order by order.
        $done = $this->pass($path, new OrderSnapshots($this->db, $this->clock), (int) $channel, $now, $log);
        return ['lines' => $done['lines'], 'results' => $done['results'], 'refused' => $done['refused'], 'sum' => $sum, 'sum_ok' => true, 'read' => $done['read']];
    }

    /**
     * One pass over the file: each line read (a line past the cap drained to its end and refused once), checked, and stored when $service.
     *
     * @param \Closure(string): void $log
     * @return array{lines: int, results: array<string, int>, refused: int, read: array<string, int>}
     */
    private function pass(string $path, ?OrderSnapshots $service, int $channel, \DateTimeImmutable $now, \Closure $log): array
    {
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
                    $ref = SnapshotBody::orderRef($o['ref'] ?? null, 'ref');
                    $body = SnapshotBody::parse($o, $now);
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
        return ['lines' => $n, 'results' => $results, 'refused' => $refused, 'read' => $read];
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
