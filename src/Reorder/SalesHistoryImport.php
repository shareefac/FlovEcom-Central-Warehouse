<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;

/**
 * Loads one sales-history export (tools/sales_history/export.php: a manifest and its gzipped CSV files) into
 * sales_history_day, channel_snapshot_day, listing_stock_day and listing_stock_latest (spec §7.2; docs/decisions.md I61).
 *
 *  - Checks: the manifest's site is the channel (else 400 site_mismatch); every file's sha256 is the manifest's (422
 *    sha_mismatch); the same sales file already loaded for the channel -> `already` (nothing done).
 *  - Coverage: a channel's coverage is [min date_from, max date_to] over its loaded batches. A batch that starts more than
 *    one day after the coverage ends, or ends more than one day before it starts, is refused (409 history_gap) unless
 *    $allowGap; an overlapping batch REPLACES its date range.
 *  - Load: a sales_import_batch row (loading), then per 7-day slice ONE transaction: delete the channel's rows of the slice
 *    from sales_history_day, listing_stock_day and channel_snapshot_day, then multi-row INSERTs of 500. Then
 *    listing_stock_latest is replaced (one transaction) unless the stored snapshot is newer than the file's (I77), and the
 *    batch is `loaded` with its counts. On a failure the batch is
 *    `failed` with the error; the slices loaded stay (running the same file again finishes it: the slices are idempotent).
 *  - Mapping report: each sales row's (channel, variant) is looked up in channel_listing: no row -> unknown, a row without an
 *    item or not `mapped` (quarantined keeps its item, I77) -> unlinked (rows and listing units, per batch). Rows are kept whatever the mapping: the history is mapped to
 *    items when the demand is built, so a listing linked later makes its history count.
 *  - Report: units a day per month, and for each active anomaly window the units a day in the window against the Jul-Aug
 *    baseline of its year (+x %).
 * A dry run checks and counts everything and writes nothing. Audit sales.import.
 */
final class SalesHistoryImport
{
    public const SLICE_DAYS = 7;
    public const INSERT_CHUNK = 500;
    public const SALES_HEADER = ['site', 'variant_id', 'sale_date', 'units_online', 'orders_online', 'net_online', 'gross_online', 'units_office', 'orders_office'];
    public const STOCKDAYS_HEADER = ['site', 'variant_id', 'stock_date', 'stock', 'stock_mode', 'allow_backorders'];
    public const LATEST_HEADER = ['site', 'variant_id', 'snapshot_date', 'stock', 'stock_mode', 'sellable'];
    public const MAX_MANIFEST_BYTES = 4_194_304;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    /** @var array<string, array{status: string, sku: ?int, brand: ?string}> variant => mapping of the channel being loaded */
    private array $mapping = [];

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * @return array{status: string, batch_id: ?int, channel: string, from: string, to: string, counts: array<string, int>,
     *   months: list<array{month: string, days: int, units: int, per_day: string}>,
     *   anomalies: list<array{label: string, from: string, to: string, per_day: string, baseline_per_day: ?string, uplift: ?string}>, ms: int}
     */
    public function import(Caller $caller, string $channelCode, string $manifestPath, bool $dryRun = false, bool $allowGap = false): array
    {
        $t0 = hrtime(true);
        $channel = $this->db->one('SELECT id, code FROM channel WHERE code = ?', [$channelCode])
            ?? throw new CwException('unknown_channel', 'there is no channel ' . mb_substr($channelCode, 0, 32), 400);
        $channelId = (int) $channel['id'];
        $m = self::manifest($manifestPath);
        if ($m['site'] !== $channelCode) {
            throw new CwException('site_mismatch', "the export is of site {$m['site']}, not {$channelCode}", 400);
        }
        $files = self::checkFiles($manifestPath, $m['files']);
        $result = ['channel' => $channelCode, 'from' => $m['from'], 'to' => $m['to']];
        $existing = $this->db->one('SELECT id, status FROM sales_import_batch WHERE channel_id = ? AND sales_sha256 = ?', [$channelId, $files['sales']['sha256']]);
        if ($existing !== null && $existing['status'] === 'loaded') {
            return ['status' => 'already', 'batch_id' => (int) $existing['id'], 'counts' => [], 'months' => [], 'anomalies' => [],
                'ms' => intdiv(hrtime(true) - $t0, 1_000_000)] + $result;
        }
        $cov = $this->db->one("SELECT MIN(date_from) AS s, MAX(date_to) AS e FROM sales_import_batch WHERE channel_id = ? AND status = 'loaded'", [$channelId]);
        if ($cov !== null && $cov['s'] !== null && !$allowGap) {
            $s = DemandMath::day((string) $cov['s']);
            $e = DemandMath::day((string) $cov['e']);
            if (DemandMath::day($m['from']) > $e + 1 || DemandMath::day($m['to']) < $s - 1) {
                throw new CwException('history_gap', "the loaded history of {$channelCode} covers {$cov['s']} to {$cov['e']}: an export of {$m['from']} to {$m['to']} "
                    . 'would leave days without history between them (export the days between, or pass --allow-gap)', 409);
            }
        }
        $this->mapping = [];
        $acc = ['rows_read' => 0, 'rows_loaded' => 0, 'units_loaded' => 0, 'unknown_rows' => 0, 'unknown_units' => 0, 'unlinked_rows' => 0, 'unlinked_units' => 0,
            'stock_rows' => 0, 'latest_rows' => 0, 'latest_kept_older' => 0, 'snapshot_days' => 0];
        $daily = [];
        $brandDaily = [];
        $anomalies = $this->anomalies($channelId, $m['from'], $m['to']);
        $brands = array_values(array_unique(array_filter(array_map(static fn (array $a): ?string => $a['brand'], $anomalies))));
        $batchId = null;
        if (!$dryRun) {
            $batchId = $this->startBatch($caller, $channelId, $m, $files, $existing === null ? null : (int) $existing['id']);
        }
        try {
            $sales = self::rows($files['sales']['path'], self::SALES_HEADER, $m['site'], 'sale_date');
            $stock = isset($files['stockdays']) ? self::rows($files['stockdays']['path'], self::STOCKDAYS_HEADER, $m['site'], 'stock_date') : null;
            $snapshots = [];
            foreach ($m['snapshot_days'] as $sd) {
                if ($sd['date'] >= $m['from'] && $sd['date'] <= $m['to']) {
                    $snapshots[$sd['date']] = $sd;
                }
            }
            $from = DemandMath::day($m['from']);
            $to = DemandMath::day($m['to']);
            for ($d0 = $from; $d0 <= $to; $d0 += self::SLICE_DAYS) {
                $d1 = min($d0 + self::SLICE_DAYS, $to + 1);
                $end = DemandMath::date($d1);
                $sliceSales = [];
                while ($sales->valid() && $sales->current()['sale_date'] < $end) {
                    $sliceSales[] = $sales->current();
                    $sales->next();
                }
                $sliceStock = [];
                while ($stock !== null && $stock->valid() && $stock->current()['stock_date'] < $end) {
                    $sliceStock[] = $stock->current();
                    $stock->next();
                }
                foreach ([[$sliceSales, 'sale_date', 'sales'], [$sliceStock, 'stock_date', 'stockdays']] as [$rows, $field, $kind]) {
                    if ($rows !== [] && $rows[0][$field] < $m['from']) {
                        throw new CwException('bad_file', "{$files[$kind]['name']}: a row lies before {$m['from']}, the start of the export", 422);
                    }
                }
                $sliceSnap = array_values(array_filter($snapshots, static fn (array $s): bool => $s['date'] >= DemandMath::date($d0) && $s['date'] < $end));
                $this->lookup($channelId, array_map(static fn (array $r): string => $r['variant_id'], $sliceSales));
                foreach ($sliceSales as $r) {
                    $u = $r['units_online'] + $r['units_office'];
                    $acc['rows_read']++;
                    $acc['units_loaded'] += $u;
                    $map = $this->mapping[mb_strtolower($r['variant_id'])] ?? null;
                    if ($map === null) {
                        $acc['unknown_rows']++;
                        $acc['unknown_units'] += $u;
                    } elseif ($map['sku'] === null || $map['status'] !== 'mapped') {
                        // Not counted as demand (DemandBuilder reads mapped listings only): no item, or a quarantined /
                        // otherwise unmapped listing that keeps its sku_id (review finding, I77).
                        $acc['unlinked_rows']++;
                        $acc['unlinked_units'] += $u;
                    }
                    $daily[$r['sale_date']] = ($daily[$r['sale_date']] ?? 0) + $u;
                    if ($map !== null && $map['brand'] !== null && in_array($map['brand'], $brands, true)) {
                        $brandDaily[$map['brand']][$r['sale_date']] = ($brandDaily[$map['brand']][$r['sale_date']] ?? 0) + $u;
                    }
                }
                $acc['rows_loaded'] += count($sliceSales);
                $acc['stock_rows'] += count($sliceStock);
                $acc['snapshot_days'] += count($sliceSnap);
                if (!$dryRun) {
                    $this->loadSlice($channelId, (int) $batchId, DemandMath::date($d0), $end, $sliceSales, $sliceStock, $sliceSnap);
                }
            }
            if ($sales->valid()) {
                throw new CwException('bad_file', "{$files['sales']['name']}: a row lies after {$m['to']}, the end of the export", 422);
            }
            if ($stock !== null && $stock->valid()) {
                throw new CwException('bad_file', "{$files['stockdays']['name']}: a row lies after {$m['to']}, the end of the export", 422);
            }
            if (isset($files['latest'])) {
                $latest = iterator_to_array(self::rows($files['latest']['path'], self::LATEST_HEADER, $m['site'], 'snapshot_date'), false);
                $acc['latest_rows'] = count($latest);
                // Only a snapshot as new as the stored one replaces it (review finding, I77): re-exporting an earlier week
                // must not put older site stock back.
                $fileDate = $latest === [] ? null : max(array_column($latest, 'snapshot_date'));
                $stored = $this->db->value('SELECT MAX(snapshot_date) FROM listing_stock_latest WHERE channel_id = ?', [$channelId]);
                if ($fileDate !== null && $stored !== null && $fileDate < (string) $stored) {
                    $acc['latest_rows'] = 0;
                    $acc['latest_kept_older'] = 1;
                    $result['latest_kept'] = (string) $stored;
                } elseif (!$dryRun) {
                    $this->loadLatest($channelId, (int) $batchId, $latest);
                }
            }
            if (!$dryRun) {
                $this->db->exec("UPDATE sales_import_batch SET status = 'loaded', rows_read = ?, rows_loaded = ?, units_loaded = ?, unknown_rows = ?, unknown_units = ?, "
                    . 'unlinked_rows = ?, unlinked_units = ?, stock_rows = ?, finished_at = ?, error = NULL WHERE id = ?',
                    [$acc['rows_read'], $acc['rows_loaded'], $acc['units_loaded'], $acc['unknown_rows'], $acc['unknown_units'], $acc['unlinked_rows'], $acc['unlinked_units'],
                        $acc['stock_rows'], Clock::db(($this->clock)()), $batchId]);
            }
        } catch (\Throwable $e) {
            if ($batchId !== null) {
                $this->db->exec("UPDATE sales_import_batch SET status = 'failed', error = ?, finished_at = ? WHERE id = ?",
                    [mb_substr(($e instanceof CwException ? $e->errorCode . ': ' : get_class($e) . ': ') . $e->getMessage(), 0, 500), Clock::db(($this->clock)()), $batchId]);
            }
            throw $e;
        }
        $report = ['months' => self::months($daily, $m['from'], $m['to']), 'anomalies' => self::uplift($anomalies, $daily, $brandDaily, $m['from'], $m['to'])];
        if (!$dryRun) {
            Audit::write($this->db, $caller, 'sales.import', 'sales_import_batch', (string) $batchId, null, ['channel' => $channelCode, 'from' => $m['from'], 'to' => $m['to'],
                'files' => array_map(static fn (array $f): array => ['name' => $f['name'], 'sha256' => $f['sha256']], array_values($files)), 'counts' => $acc]);
        }
        return ['status' => $dryRun ? 'dry_run' : 'loaded', 'batch_id' => $batchId, 'counts' => $acc, 'ms' => intdiv(hrtime(true) - $t0, 1_000_000)] + $report + $result;
    }

    /**
     * The manifest, checked: site, source, from, to, exported_at, files (name, sha256, rows, kind), snapshot_days.
     *
     * @return array{site: string, source: string, from: string, to: string, exported_at: string, files: list<array{name: string, sha256: string, rows: int, kind: string}>,
     *   snapshot_days: list<array{date: string, variants: int, unsellable: int}>, raw: array<string, mixed>}
     */
    public static function manifest(string $path): array
    {
        if (!is_file($path) || !is_readable($path) || (int) filesize($path) > self::MAX_MANIFEST_BYTES) {
            throw new CwException('bad_manifest', 'there is no readable manifest (at most 4 MiB) at ' . basename($path), 400);
        }
        $raw = json_decode((string) file_get_contents($path), true);
        $bad = static fn (string $why): CwException => new CwException('bad_manifest', basename($path) . ": {$why}", 422);
        if (!is_array($raw)) {
            throw $bad('not a JSON object');
        }
        $site = $raw['site'] ?? null;
        if (!is_string($site) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $site) !== 1) {
            throw $bad('site is missing');
        }
        if (!in_array($raw['source'] ?? null, ['cps', 'orders'], true)) {
            throw $bad('source is cps or orders');
        }
        foreach (['from', 'to'] as $k) {
            if (!is_string($raw[$k] ?? null) || !self::isDate($raw[$k])) {
                throw $bad("{$k} is a date, YYYY-MM-DD");
            }
        }
        if ($raw['from'] > $raw['to']) {
            throw $bad('from is after to');
        }
        $exported = is_string($raw['exported_at'] ?? null) ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $raw['exported_at'], Clock::utc()) : false;
        if ($exported === false) {
            throw $bad('exported_at is a UTC time, YYYY-MM-DDTHH:MM:SSZ');
        }
        $files = [];
        foreach (is_array($raw['files'] ?? null) ? $raw['files'] : [] as $f) {
            if (!is_array($f) || !is_string($f['name'] ?? null) || preg_match('/^[A-Za-z0-9._-]{1,200}$/D', $f['name']) !== 1 || !is_string($f['sha256'] ?? null)
                || preg_match('/^[0-9a-f]{64}$/D', $f['sha256']) !== 1) {
                throw $bad('every file has a plain name and a sha256');
            }
            $kind = is_string($f['kind'] ?? null) ? $f['kind'] : match (true) {
                str_contains($f['name'], '_sales_') => 'sales',
                str_contains($f['name'], '_stockdays_') => 'stockdays',
                str_contains($f['name'], '_stocklatest_') => 'latest',
                default => '',
            };
            if (!in_array($kind, ['sales', 'stockdays', 'latest'], true)) {
                throw $bad("{$f['name']} is not a sales, stockdays or stocklatest file");
            }
            $files[] = ['name' => $f['name'], 'sha256' => $f['sha256'], 'rows' => is_int($f['rows'] ?? null) ? $f['rows'] : 0, 'kind' => $kind];
        }
        if (count(array_filter($files, static fn (array $f): bool => $f['kind'] === 'sales')) !== 1) {
            throw $bad('there is exactly one sales file');
        }
        $snap = [];
        foreach (is_array($raw['snapshot_days'] ?? null) ? $raw['snapshot_days'] : [] as $s) {
            if (!is_array($s) || !is_string($s['date'] ?? null) || !self::isDate($s['date']) || !is_int($s['variants'] ?? null) || !is_int($s['unsellable'] ?? null)
                || $s['variants'] < 0 || $s['unsellable'] < 0) {
                throw $bad('snapshot_days are {date, variants, unsellable}');
            }
            $snap[] = ['date' => $s['date'], 'variants' => $s['variants'], 'unsellable' => $s['unsellable']];
        }
        return ['site' => $site, 'source' => (string) $raw['source'], 'from' => $raw['from'], 'to' => $raw['to'], 'exported_at' => Clock::db($exported), 'files' => $files,
            'snapshot_days' => $snap, 'raw' => $raw];
    }

    /**
     * The files next to the manifest, by kind, after checking each one's sha256.
     *
     * @param list<array{name: string, sha256: string, rows: int, kind: string}> $files
     * @return array<string, array{name: string, sha256: string, rows: int, kind: string, path: string}>
     */
    private static function checkFiles(string $manifestPath, array $files): array
    {
        $dir = dirname($manifestPath);
        $out = [];
        foreach ($files as $f) {
            $path = $dir . '/' . $f['name'];
            if (!is_file($path) || !is_readable($path)) {
                throw new CwException('missing_file', "{$f['name']} is not next to the manifest", 422);
            }
            $sha = hash_file('sha256', $path);
            if ($sha !== $f['sha256']) {
                throw new CwException('sha_mismatch', "{$f['name']}: its sha256 is not the manifest's (the file changed or was copied incompletely)", 422);
            }
            if (isset($out[$f['kind']])) {
                throw new CwException('bad_manifest', "two {$f['kind']} files", 422);
            }
            $out[$f['kind']] = $f + ['path' => $path];
        }
        return $out;
    }

    /**
     * The rows of an export file (gzip CSV with exactly $header), checked and typed, in file order; dates never go back.
     *
     * @param list<string> $header
     * @return \Generator<int, array<string, mixed>>
     */
    public static function rows(string $path, array $header, string $site, string $dateField): \Generator
    {
        $fh = @gzopen($path, 'rb');
        if ($fh === false) {
            throw new CwException('bad_file', basename($path) . ' cannot be read', 422);
        }
        try {
            $name = basename($path);
            $head = fgetcsv($fh, 0, ',', '"', '');
            if (!is_array($head) || array_map(static fn (?string $h): string => trim((string) $h, "\xEF\xBB\xBF \t\r\n"), $head) !== $header) {
                throw new CwException('bad_file', "{$name}: the header is not " . implode(',', $header), 422);
            }
            $n = 1;
            $prev = '';
            while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                $n++;
                if ($r === [null] || $r === ['']) {
                    continue;
                }
                if (count($r) !== count($header)) {
                    throw new CwException('bad_file', "{$name} line {$n}: " . count($r) . ' cells, the header has ' . count($header), 422);
                }
                $row = array_combine($header, array_map('strval', $r));
                $bad = static fn (string $why): CwException => new CwException('bad_file', "{$name} line {$n}: {$why}", 422);
                if ($row['site'] !== $site) {
                    throw $bad("site {$row['site']} is not {$site}");
                }
                if ($row['variant_id'] === '' || strlen($row['variant_id']) > 64 || preg_match('/^[\x21-\x7e]+$/D', $row['variant_id']) !== 1) {
                    throw $bad('variant_id is 1 to 64 printable characters');
                }
                if (!self::isDate($row[$dateField])) {
                    throw $bad("{$dateField} is a date, YYYY-MM-DD");
                }
                if ($row[$dateField] < $prev) {
                    throw $bad("{$dateField} goes back ({$row[$dateField]} after {$prev}): the file is sorted by date");
                }
                $prev = $row[$dateField];
                yield $n => self::typed($row, $header, $bad);
            }
        } finally {
            gzclose($fh);
        }
    }

    /**
     * @param array<string, string> $row
     * @param list<string> $header
     * @param \Closure(string): CwException $bad
     * @return array<string, mixed>
     */
    private static function typed(array $row, array $header, \Closure $bad): array
    {
        $int = static function (string $k, bool $nullable = false) use ($row, $bad): ?int {
            $v = trim($row[$k]);
            if ($v === '' && $nullable) {
                return null;
            }
            if (preg_match('/^-?\d{1,9}(\.0+)?$/D', $v) !== 1) {
                throw $bad("{$k} is a whole number");
            }
            return (int) $v;
        };
        if ($header === self::SALES_HEADER) {
            $out = ['variant_id' => $row['variant_id'], 'sale_date' => $row['sale_date']];
            foreach (['units_online', 'orders_online', 'units_office', 'orders_office'] as $k) {
                $out[$k] = (int) $int($k);
                if ($out[$k] < 0) {
                    throw $bad("{$k} is negative");
                }
            }
            if ($out['units_online'] + $out['units_office'] <= 0) {
                throw $bad('a row sells at least one unit');
            }
            foreach (['net_online', 'gross_online'] as $k) {
                $out[$k] = self::money($row[$k]) ?? throw $bad("{$k} is an amount");
            }
            return $out;
        }
        if ($header === self::STOCKDAYS_HEADER) {
            $b = $int('allow_backorders', true);
            return ['variant_id' => $row['variant_id'], 'stock_date' => $row['stock_date'], 'stock' => $int('stock', true),
                'stock_mode' => $row['stock_mode'] === '' ? null : mb_substr($row['stock_mode'], 0, 16), 'allow_backorders' => $b === null ? null : max(-128, min(127, $b))];
        }
        $sellable = $int('sellable');
        return ['variant_id' => $row['variant_id'], 'snapshot_date' => $row['snapshot_date'], 'stock' => (int) $int('stock'),
            'stock_mode' => $row['stock_mode'] === '' ? null : mb_substr($row['stock_mode'], 0, 16), 'sellable' => $sellable === 1 ? 1 : 0];
    }

    /** An amount of money as a DECIMAL(14,2) string, half up ('12.345' -> '12.35'); null when it is not one. */
    public static function money(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return '0.00';
        }
        if (preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,6}))?$/D', $v, $m) !== 1) {
            return null;
        }
        $e6 = (int) $m[2] * 1_000_000 + (int) str_pad($m[3] ?? '', 6, '0');
        $e2 = intdiv($e6 + 5_000, 10_000);
        $s = intdiv($e2, 100) . '.' . str_pad((string) ($e2 % 100), 2, '0', STR_PAD_LEFT);
        return $m[1] === '-' && $e2 > 0 ? '-' . $s : $s;
    }

    private static function isDate(string $v): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @param array<string, mixed> $m the manifest
     * @param array<string, array<string, mixed>> $files
     */
    private function startBatch(Caller $caller, int $channelId, array $m, array $files, ?int $existing): int
    {
        $now = Clock::db(($this->clock)());
        $args = [$m['source'], $m['from'], $m['to'], $files['sales']['name'], $files['stockdays']['name'] ?? null, $files['stockdays']['sha256'] ?? null,
            $files['latest']['name'] ?? null, $files['latest']['sha256'] ?? null, Idempotency::json($m['raw']), $m['exported_at'], $caller->actor, $now];
        if ($existing !== null) {
            $this->db->exec("UPDATE sales_import_batch SET source = ?, date_from = ?, date_to = ?, sales_file = ?, stock_file = ?, stock_sha256 = ?, latest_file = ?, "
                . "latest_sha256 = ?, manifest = CAST(? AS JSON), exported_at = ?, actor = ?, started_at = ?, status = 'loading', rows_read = 0, rows_loaded = 0, "
                . 'units_loaded = 0, unknown_rows = 0, unknown_units = 0, unlinked_rows = 0, unlinked_units = 0, stock_rows = 0, finished_at = NULL, error = NULL WHERE id = ?',
                [...$args, $existing]);
            return $existing;
        }
        return $this->db->insert('INSERT INTO sales_import_batch (channel_id, sales_sha256, source, date_from, date_to, sales_file, stock_file, stock_sha256, latest_file, '
            . 'latest_sha256, manifest, exported_at, actor, started_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS JSON), ?, ?, ?)',
            [$channelId, $files['sales']['sha256'], ...$args]);
    }

    /**
     * One slice [from, to): its rows replace the channel's rows of those days, in one transaction.
     *
     * @param list<array<string, mixed>> $sales
     * @param list<array<string, mixed>> $stock
     * @param list<array{date: string, variants: int, unsellable: int}> $snap
     */
    private function loadSlice(int $channelId, int $batchId, string $from, string $to, array $sales, array $stock, array $snap): void
    {
        // Inserted in primary-key order (variant, then day): a 500-row statement touches ~70 neighbouring variants, not 500
        // scattered ones (the file is sorted by day).
        usort($sales, static fn (array $a, array $b): int => strcmp($a['variant_id'], $b['variant_id']) ?: strcmp($a['sale_date'], $b['sale_date']));
        usort($stock, static fn (array $a, array $b): int => strcmp($a['variant_id'], $b['variant_id']) ?: strcmp($a['stock_date'], $b['stock_date']));
        $this->db->transaction(function (Db $db) use ($channelId, $batchId, $from, $to, $sales, $stock, $snap): void {
            $db->exec('DELETE FROM sales_history_day WHERE channel_id = ? AND sale_date >= ? AND sale_date < ?', [$channelId, $from, $to]);
            $db->exec('DELETE FROM listing_stock_day WHERE channel_id = ? AND stock_date >= ? AND stock_date < ?', [$channelId, $from, $to]);
            $db->exec('DELETE FROM channel_snapshot_day WHERE channel_id = ? AND snapshot_date >= ? AND snapshot_date < ?', [$channelId, $from, $to]);
            foreach (array_chunk($sales, self::INSERT_CHUNK) as $chunk) {
                $params = [];
                foreach ($chunk as $r) {
                    array_push($params, $channelId, $r['variant_id'], $r['sale_date'], $r['units_online'], $r['orders_online'], $r['net_online'], $r['gross_online'],
                        $r['units_office'], $r['orders_office'], $batchId);
                }
                $db->exec('INSERT INTO sales_history_day (channel_id, external_variant_id, sale_date, units_online, orders_online, net_online, gross_online, units_office, '
                    . 'orders_office, batch_id) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
            }
            foreach (array_chunk($stock, self::INSERT_CHUNK) as $chunk) {
                $params = [];
                foreach ($chunk as $r) {
                    array_push($params, $channelId, $r['variant_id'], $r['stock_date'], $r['stock'], $r['stock_mode'], $r['allow_backorders'], $batchId);
                }
                $db->exec('INSERT INTO listing_stock_day (channel_id, external_variant_id, stock_date, stock, stock_mode, allow_backorders, batch_id) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?)')), $params);
            }
            if ($snap !== []) {
                $params = [];
                foreach ($snap as $s) {
                    array_push($params, $channelId, $s['date'], $s['variants'], $s['unsellable'], $batchId);
                }
                $db->exec('INSERT INTO channel_snapshot_day (channel_id, snapshot_date, variants, unsellable, batch_id) VALUES '
                    . implode(', ', array_fill(0, count($snap), '(?, ?, ?, ?, ?)')), $params);
            }
        });
    }

    /** @param list<array<string, mixed>> $rows */
    private function loadLatest(int $channelId, int $batchId, array $rows): void
    {
        $this->db->transaction(function (Db $db) use ($channelId, $batchId, $rows): void {
            $db->exec('DELETE FROM listing_stock_latest WHERE channel_id = ?', [$channelId]);
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                $params = [];
                foreach ($chunk as $r) {
                    array_push($params, $channelId, $r['variant_id'], $r['snapshot_date'], $r['stock'], $r['stock_mode'], $r['sellable'], $batchId);
                }
                $db->exec('INSERT INTO listing_stock_latest (channel_id, external_variant_id, snapshot_date, stock, stock_mode, sellable, batch_id) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?)')), $params);
            }
        });
    }

    /**
     * Looks up the mapping of variants not seen yet (channel_listing, then the item's brand).
     *
     * @param list<string> $variants
     */
    private function lookup(int $channelId, array $variants): void
    {
        $new = [];
        foreach ($variants as $v) {
            $k = mb_strtolower($v);
            if (!isset($this->mapping[$k]) && !array_key_exists($k, $new)) {
                $new[$k] = $v;
            }
        }
        foreach (array_chunk(array_values($new), 1000) as $chunk) {
            foreach ($this->db->all('SELECT l.external_variant_id, l.status, l.sku_id, s.brand FROM channel_listing l LEFT JOIN sku s ON s.id = l.sku_id '
                . 'WHERE l.channel_id = ? AND l.external_variant_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$channelId, ...$chunk]) as $r) {
                $this->mapping[mb_strtolower((string) $r['external_variant_id'])] = ['status' => (string) $r['status'],
                    'sku' => $r['sku_id'] === null ? null : (int) $r['sku_id'], 'brand' => $r['brand'] === null ? null : DemandBuilder::brandKey((string) $r['brand'])];
            }
        }
        foreach (array_keys($new) as $k) {
            if (!isset($this->mapping[$k])) {
                $this->mapping[$k] = null;
            }
        }
    }

    /**
     * The active anomaly windows of this channel that touch the export.
     *
     * @return list<array{label: string, from: string, to: string, brand: ?string}>
     */
    private function anomalies(int $channelId, string $from, string $to): array
    {
        return array_map(static fn (array $r): array => ['label' => (string) $r['label'], 'from' => (string) $r['date_from'], 'to' => (string) $r['date_to'],
            'brand' => $r['brand'] === null ? null : DemandBuilder::brandKey((string) $r['brand'])],
            $this->db->all('SELECT label, date_from, date_to, brand FROM demand_anomaly WHERE is_active = 1 AND (channel_id IS NULL OR channel_id = ?) '
                . 'AND date_from <= ? AND date_to >= ? ORDER BY id', [$channelId, $to, $from]));
    }

    /**
     * Units a day per calendar month of the export.
     *
     * @param array<string, int> $daily date => listing units
     * @return list<array{month: string, days: int, units: int, per_day: string}>
     */
    public static function months(array $daily, string $from, string $to): array
    {
        $out = [];
        for ($d = DemandMath::day($from); $d <= DemandMath::day($to); $d++) {
            $date = DemandMath::date($d);
            $m = substr($date, 0, 7);
            $out[$m] ??= ['month' => $m, 'days' => 0, 'units' => 0];
            $out[$m]['days']++;
            $out[$m]['units'] += $daily[$date] ?? 0;
        }
        return array_values(array_map(static fn (array $x): array => $x + ['per_day' => self::perDay($x['units'], $x['days'])], $out));
    }

    /**
     * Each anomaly window against the Jul-Aug baseline of its year (both clipped to the export): units a day and the uplift.
     *
     * @param list<array{label: string, from: string, to: string, brand: ?string}> $anomalies
     * @param array<string, int> $daily
     * @param array<string, array<string, int>> $brandDaily
     * @return list<array{label: string, from: string, to: string, per_day: string, baseline_per_day: ?string, uplift: ?string}>
     */
    public static function uplift(array $anomalies, array $daily, array $brandDaily, string $from, string $to): array
    {
        $out = [];
        foreach ($anomalies as $a) {
            $series = $a['brand'] === null ? $daily : ($brandDaily[$a['brand']] ?? []);
            $sum = static function (string $f, string $t) use ($series, $from, $to): array {
                $u = 0;
                $n = 0;
                for ($d = DemandMath::day(max($f, $from)); $d <= DemandMath::day(min($t, $to)); $d++) {
                    $u += $series[DemandMath::date($d)] ?? 0;
                    $n++;
                }
                return [$u, $n];
            };
            [$wu, $wn] = $sum($a['from'], $a['to']);
            $year = substr($a['from'], 0, 4);
            [$bu, $bn] = $sum("{$year}-07-01", "{$year}-08-31");
            $uplift = null;
            if ($wn > 0 && $bn > 0 && $bu > 0) {
                $pct = DemandMath::halfUpDiv(($wu * $bn - $bu * $wn) * 1000, $bu * $wn);
                $uplift = ($pct >= 0 ? '+' : '-') . intdiv(abs($pct), 10) . '.' . (abs($pct) % 10) . ' %';
            }
            $out[] = ['label' => $a['label'], 'from' => $a['from'], 'to' => $a['to'], 'brand' => $a['brand'], 'per_day' => $wn > 0 ? self::perDay($wu, $wn) : '-',
                'baseline_per_day' => $bn > 0 ? self::perDay($bu, $bn) : null, 'uplift' => $uplift];
        }
        return $out;
    }

    /** units / days with one decimal, half up. */
    public static function perDay(int $units, int $days): string
    {
        if ($days <= 0) {
            return '-';
        }
        $v = DemandMath::halfUpDiv($units * 10, $days);
        return number_format(intdiv($v, 10)) . '.' . ($v % 10);
    }
}
