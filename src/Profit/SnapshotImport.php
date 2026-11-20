<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;
use CW\Db;

/**
 * The backfill of order snapshots from the connector's day files (bin/import_order_snapshots.php; CONTRACT.md "Bulk"; docs/decisions.md
 * PR3): one JSON object per line with its `ref`, stored through the SAME service as the API (OrderSnapshots::store, source `bulk`):
 * the same checks, the same "older ignored", one transaction per order, so a backfill and the live feed can never disagree. A line CW
 * refuses is reported with its field and the rest go on. The file's `.sum` line (orders, lines, goods_inc, delivery_inc, file_sha256),
 * when it is there, is checked against the file as read.
 */
final class SnapshotImport
{
    public const MAX_LINE_BYTES = SnapshotBody::MAX_BYTES;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null)
    {
    }

    /**
     * @param \Closure(string): void $log
     * @return array{lines: int, results: array<string, int>, refused: int, sum: ?array<string, mixed>, sum_ok: ?bool, read: array<string, int|string>}
     */
    public function file(string $channelCode, string $path, bool $dryRun, \Closure $log): array
    {
        $channel = $this->db->value('SELECT id FROM channel WHERE code = ?', [$channelCode]);
        if ($channel === null) {
            throw new \InvalidArgumentException("no store {$channelCode}");
        }
        $base = basename($path);
        if (preg_match('/^snapshots_([a-z][a-z0-9_]*)_\d{4}-\d{2}-\d{2}\.jsonl$/D', $base, $m) === 1 && $m[1] !== $channelCode) {
            throw new \InvalidArgumentException("{$base} is a file of the store {$m[1]}, not {$channelCode}");
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new \InvalidArgumentException("cannot read {$path}");
        }
        $service = new OrderSnapshots($this->db, $this->clock);
        $now = $this->clock === null ? \CW\Clock::now() : ($this->clock)();
        $results = array_fill_keys(OrderSnapshots::RESULTS, 0);
        $read = ['orders' => 0, 'lines' => 0, 'goods_inc' => 0, 'delivery_inc' => 0];
        $refused = 0;
        $n = 0;
        try {
            while (($line = fgets($fh, self::MAX_LINE_BYTES + 2)) !== false) {
                $n++;
                if (trim($line) === '') {
                    continue;
                }
                try {
                    if (strlen($line) > self::MAX_LINE_BYTES) {
                        throw new CwException('body_too_large', 'the line is larger than ' . intdiv(self::MAX_LINE_BYTES, 1024) . ' KB', 413);
                    }
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
                    if (!$dryRun) {
                        $results[$service->store((int) $channel, $ref, $o, 'bulk')['result']]++;
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
        $sum = $this->sum($path);
        $sumOk = null;
        if ($sum !== null) {
            $sumOk = true;
            foreach (['orders', 'lines', 'goods_inc', 'delivery_inc'] as $k) {
                if (isset($sum[$k]) && (int) $sum[$k] !== $read[$k] && $refused === 0) {
                    $sumOk = false;
                    $log("the .sum says {$k} {$sum[$k]}, the file holds {$read[$k]}");
                }
            }
            if (is_string($sum['file_sha256'] ?? null) && !hash_equals(strtolower($sum['file_sha256']), (string) hash_file('sha256', $path))) {
                $sumOk = false;
                $log('the .sum names another file (its sha256 differs)');
            }
        }
        return ['lines' => $n, 'results' => $results, 'refused' => $refused, 'sum' => $sum, 'sum_ok' => $sumOk, 'read' => $read];
    }

    /** @return array<string, mixed>|null the day file's `.sum` line (next to it: <name>.sum or <name>.jsonl.sum) */
    private function sum(string $path): ?array
    {
        foreach ([preg_replace('/\.jsonl$/D', '.sum', $path), $path . '.sum'] as $p) {
            if (is_string($p) && $p !== $path && is_file($p)) {
                $s = json_decode(trim((string) file_get_contents($p, false, null, 0, 65536)), true);
                return is_array($s) ? $s : null;
            }
        }
        return null;
    }
}
