<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\ConfigException;
use CW\CwException;
use CW\Db;
use CW\DbSettings;

/**
 * The website sales ERPNext does not have yet (pack I, review of 14 Oct 2026, docs/decisions.md PI20): what the ERPNext opening takes
 * off ERPNext's figures (the pending-sales file of bin/import_erp_opening.php). Read from the Vape and Go database ONLY, by
 * bin/erp_pending_sales.php, never anything else: every statement is a SELECT, run in a READ ONLY session and transaction with a
 * statement time cap (MAX_EXECUTION_TIME, session and per statement), and the transaction is rolled back at the end.
 *
 * How a website sale reaches ERPNext (App/App_proto, read 14 Oct 2026; the copy cron runs is App/):
 *  - payment completed (or an office order saved Completed) sets orders_master.ord_is_syncable = 1 and ord_genarated_date;
 *  - salesorder.php (cron every minute, 5 orders a run) makes ONE data_sync row per order: ds_type 'sales_invoice', status PENDING,
 *    payload items[] = {item_code: ordi_prodt_id (the store's variant id), qty: 1} per orders_items row (a row is a unit; no line is
 *    left out), and sets ord_generate_prepare_to_sync = 1;
 *  - data_sync_processor.php (every 4 minutes) posts it: SUCCESS or FAILED (retried while retry_count < 5, 2 hours apart; after that
 *    a FAILED row stays until a person retries it). ERPNext answers 200 once the invoice is in ITS OWN queue, before the stock moves:
 *    the store's own reconciler (erp_stock_pull_sync.php) counts a SUCCESS of the last 10 minutes as still on its way; so does this.
 *
 * Units on their way, per variant:
 *  1. queued: the items of every 'sales_invoice' row that is PENDING or FAILED (any retry count), or SUCCESS within the grace minutes,
 *     and is its order's newest such row (a re-queued order counts once: rows of one order share source_ord_id);
 *  2. not queued yet: the orders_items rows of orders with ord_is_syncable = 1, a generated date and ord_generate_prepare_to_sync = 0.
 * Not netted, only counted (the summary says so): cancel and refund rows still on their way (they put units BACK in ERPNext).
 */
final class SitePendingSales
{
    public const SALES_TYPE = 'sales_invoice';
    public const RETURN_TYPES = ['cancel_sales_order_with_refund', 'cancel_sales_order_without_refund', 'salesorder_shipment_refund',
        'cancel_sales_order_refund_without_stock_update'];
    public const DEFAULT_MAX_MS = 30_000;
    public const MAX_MAX_MS = 600_000;
    public const DEFAULT_GRACE_MINUTES = 10;
    /** A FAILED row the processor no longer retries by itself (data_sync_processor.php: retry_count < 5). */
    public const STUCK_RETRIES = 5;

    private const CANDIDATE = "ds.ds_type = 'sales_invoice' AND (ds.ds_status IN ('PENDING', 'FAILED') OR (ds.ds_status = 'SUCCESS' "
        . 'AND ds.ds_updated_at >= NOW() - INTERVAL ? MINUTE)) AND NOT EXISTS (SELECT 1 FROM data_sync n WHERE n.ds_type = '
        . "'sales_invoice' AND n.source_ord_id = ds.source_ord_id AND n.ds_id > ds.ds_id)";
    private const NOT_QUEUED = 'om.ord_is_syncable = 1 AND om.ord_genarated_date IS NOT NULL AND om.ord_generate_prepare_to_sync = 0';

    public function __construct(private readonly Db $site, private readonly int $maxMs = self::DEFAULT_MAX_MS,
        private readonly int $graceMinutes = self::DEFAULT_GRACE_MINUTES)
    {
        if ($maxMs < 100 || $maxMs > self::MAX_MAX_MS) {
            throw new \InvalidArgumentException('the statement time cap is 100 to ' . self::MAX_MAX_MS . ' ms');
        }
        if ($graceMinutes < 0 || $graceMinutes > 1440) {
            throw new \InvalidArgumentException('the grace for SUCCESS rows is 0 to 1440 minutes');
        }
    }

    /** The statements read() runs, in order: SELECT only (checked before each runs). @return list<string> */
    public function statements(): array
    {
        $hint = '/*+ MAX_EXECUTION_TIME(' . $this->maxMs . ') */';
        $returns = implode(', ', array_map(static fn (string $t): string => "'{$t}'", self::RETURN_TYPES));
        return [
            "SELECT {$hint} @@session.transaction_read_only AS read_only, @@session.max_execution_time AS max_ms, DATABASE() AS db, NOW() AS at",
            "SELECT {$hint} ds.ds_status AS status, (ds.ds_status = 'FAILED' AND ds.retry_count >= " . self::STUCK_RETRIES . ') AS stuck, '
                . 'COUNT(*) AS n FROM data_sync ds WHERE ' . self::CANDIDATE . ' GROUP BY ds.ds_status, stuck ORDER BY ds.ds_status, stuck',
            "SELECT {$hint} jt.item_code AS variant, SUM(jt.qty) AS qty FROM data_sync ds, "
                . "JSON_TABLE(ds.ds_json_payload, '\$.items[*]' COLUMNS (item_code VARCHAR(64) PATH '\$.item_code', "
                . "qty DECIMAL(14,3) PATH '\$.qty' DEFAULT '1' ON EMPTY)) jt WHERE " . self::CANDIDATE . ' GROUP BY jt.item_code',
            "SELECT {$hint} COUNT(*) AS orders FROM orders_master om WHERE " . self::NOT_QUEUED,
            "SELECT {$hint} oi.ordi_prodt_id AS variant, COUNT(*) AS qty FROM orders_master om JOIN orders_items oi ON oi.ordi_ord_id = om.ord_id "
                . 'WHERE ' . self::NOT_QUEUED . ' GROUP BY oi.ordi_prodt_id',
            "SELECT {$hint} ds_type AS type, ds_status AS status, COUNT(*) AS n FROM data_sync WHERE ds_type IN ({$returns}) "
                . "AND ds_status IN ('PENDING', 'FAILED') GROUP BY ds_type, ds_status ORDER BY ds_type, ds_status",
        ];
    }

    /**
     * @return array{lines: array<string, int>, report: array<string, mixed>}  lines: variant id => units on their way (above zero only)
     */
    public function read(): array
    {
        $pdo = $this->site->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('the read owns its transaction');
        }
        $st = $this->statements();
        foreach ($st as $sql) {
            if (preg_match('/^SELECT\s/', $sql) !== 1) {
                throw new \LogicException('only SELECT statements are run on a website database');
            }
        }
        $pdo->exec('SET SESSION MAX_EXECUTION_TIME = ' . $this->maxMs);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->exec('START TRANSACTION READ ONLY');
        $fractions = [];
        try {
            $session = (array) $this->site->one($st[0]);
            if ((int) $session['read_only'] !== 1) {
                throw new \LogicException('the session did not become read-only: nothing read');
            }
            $status = [];
            foreach ($this->site->all($st[1], [$this->graceMinutes]) as $r) {
                $k = (string) $r['status'] . ((int) $r['stuck'] === 1 ? '_stuck' : '');
                $status[$k] = ($status[$k] ?? 0) + (int) $r['n'];
            }
            $queued = [];
            foreach ($this->site->all($st[2], [$this->graceMinutes]) as $r) {
                $queued[(string) $r['variant']] = (string) $r['qty'];
            }
            $notQueuedOrders = (int) $this->site->value($st[3]);
            $notQueued = [];
            foreach ($this->site->all($st[4]) as $r) {
                $notQueued[(string) $r['variant']] = (int) $r['qty'];
            }
            $returns = [];
            foreach ($this->site->all($st[5]) as $r) {
                $returns[(string) $r['type']][(string) $r['status']] = (int) $r['n'];
            }
        } finally {
            $pdo->exec('ROLLBACK');
        }

        $lines = [];
        $dropped = [];
        foreach ($queued as $v => $q) {
            if (bccomp($q, bcadd($q, '0', 0), 3) !== 0) {
                $fractions[] = $v;
            }
            $lines[$v] = (int) round((float) $q);
        }
        foreach ($notQueued as $v => $q) {
            $lines[$v] = ($lines[$v] ?? 0) + $q;
        }
        foreach ($lines as $v => $q) {
            if ($q <= 0 || $v === '') {
                $dropped[$v] = $q;
                unset($lines[$v]);
            }
        }
        uksort($lines, static fn (string|int $a, string|int $b): int => strnatcmp((string) $a, (string) $b));
        ksort($status);
        return ['lines' => array_map('intval', $lines), 'report' => [
            'database' => (string) $session['db'], 'read_at' => (string) $session['at'], 'read_only' => true, 'max_ms' => (int) $session['max_ms'],
            'grace_minutes' => $this->graceMinutes, 'queue_rows' => $status,
            'queued_units' => array_sum(array_map(static fn (string $q): int => (int) round((float) $q), $queued)),
            'not_queued' => ['orders' => $notQueuedOrders, 'units' => array_sum($notQueued)],
            'variants' => count($lines), 'units' => array_sum($lines), 'fractional_variants' => $fractions, 'dropped' => $dropped,
            'returns_on_their_way_not_netted' => $returns,
        ]];
    }

    /**
     * The website database's connection from an env file (KEY=VALUE lines: DB_HOST, DB_PORT (3306), DB_USER, DB_PASSWORD, DB_NAME,
     * DB_SSL_MODE (REQUIRED), DB_SSL_CA). Parsed, never sourced; nothing in it is ever printed. It must be readable by its owner only.
     */
    public static function settingsFrom(string $path): DbSettings
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('there is no readable env file ' . basename($path));
        }
        if ((fileperms($path) & 0o077) !== 0) {
            throw new \InvalidArgumentException(basename($path) . ' may be read by others: chmod 600 it first');
        }
        $v = [];
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || preg_match('/^(?:export\s+)?([A-Z_][A-Z0-9_]*)=(.*)$/D', $line, $m) !== 1) {
                continue;
            }
            $val = trim($m[2]);
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
                $val = substr($val, 1, -1);
            }
            $v[$m[1]] = $val;
        }
        foreach (['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'] as $k) {
            if (($v[$k] ?? '') === '') {
                throw new \InvalidArgumentException(basename($path) . " has no {$k}");
            }
        }
        try {
            return new DbSettings($v['DB_HOST'], (int) ($v['DB_PORT'] ?? 3306), $v['DB_USER'], $v['DB_PASSWORD'], $v['DB_NAME'],
                ($v['DB_SSL_MODE'] ?? '') === '' ? 'REQUIRED' : $v['DB_SSL_MODE'], ($v['DB_SSL_CA'] ?? '') === '' ? null : $v['DB_SSL_CA']);
        } catch (ConfigException $e) {
            throw new \InvalidArgumentException(basename($path) . ': ' . $e->getMessage());
        }
    }

    /** @param array<string, int> $lines @return string the pending-sales file (variant_id,qty) bin/import_erp_opening.php reads */
    public static function csv(array $lines): string
    {
        $out = "variant_id,qty\n";
        foreach ($lines as $v => $q) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', (string) $v) !== 1) {
                throw new CwException('bad_variant', 'a variant id the file cannot hold plainly: ' . mb_substr((string) $v, 0, 64), 422);
            }
            $out .= "{$v},{$q}\n";
        }
        return $out;
    }
}
