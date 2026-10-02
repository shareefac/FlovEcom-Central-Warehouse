<?php
/**
 * Sales history — read-only export of ONE site's daily sales per variant, for CW's reorder list (Phase I-2, IM9 basic;
 * docs/decisions.md I60-I61, docs/ops.md "Sales history"). Standalone (plain mysqli, no CW code), run ON THE VAPE AND GO BOX
 * (the Vape and Go database is VPC-private). It writes files only, under CW_SALES_EXPORT_ROOT (default
 * /root/cw_work/sales_history); nothing is ever written to any database.
 *
 *   nice -n 10 php tools/sales_history/export.php --site=vapeandgo --source=cps \
 *       --global-config=/var/www/html/vpg_ecom/App/global_config.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--out=/root/cw_work/sales_history]
 *   nice -n 10 php tools/sales_history/export.php --site=electrofag --source=orders \
 *       --dbtransfer-dest=/var/www/html/vpg_ecom/App/db-transfer/config.php
 *   (tests: the connection from CW_EXPORT_DB_HOST / _PORT / _USER / _PASSWORD / _NAME / _SSL instead)
 *
 * Window: --to defaults to yesterday (Europe/London), --from to --to minus 364 days (12 months). Before 04:00 UK it warns
 * that yesterday may not be settled (the consolidate_product_sale rebuild runs at 03:40).
 *
 * Safety, all in this file:
 *  1. --out must resolve inside CW_SALES_EXPORT_ROOT (else exit 2); files are 0640; credentials and the host are never
 *     printed (every error message is scrubbed of them).
 *  2. The session: MAX_EXECUTION_TIME = 30000, READ COMMITTED, READ ONLY; then one START TRANSACTION READ ONLY ... COMMIT
 *     per 7-day slice [d0, d1): no long read view is ever held on live. 200 ms pause between slices.
 *  3. Before each slice SHOW GLOBAL STATUS LIKE 'Threads_running': above 30 (--max-threads-running) it sleeps 5 s and
 *     tries again, at most 12 times, then exit 3.
 *  4. The EXPLAIN gate: every statement of a slice is EXPLAINed with that slice's bounds before any of them runs; exit 3
 *     (nothing further run) when a row has type ALL, a table's key is not in its allow-list (or the EXPLAIN fails, e.g. a
 *     forced index that is gone), or the driving estimate is above 500,000 rows.
 *  5. Only the SELECTs below are sent (V1 or O1, O2, S1, S2, S3). Their date parameters are dates this tool computed and
 *     checked (YYYY-MM-DD), inlined as quoted literals so that the EXPLAIN and the query are the same text.
 *
 * Output in <out>/<site>/ (written as .part, renamed when everything succeeded):
 *   <site>_sales_<from>_<to>_<ts>.csv.gz        site,variant_id,sale_date,units_online,orders_online,net_online,gross_online,units_office,orders_office
 *   <site>_stockdays_<from>_<to>_<ts>.csv.gz    site,variant_id,stock_date,stock,stock_mode,allow_backorders (unsellable days of the
 *                                               variants sold in the 365 days to --to: a window shorter than that reads the
 *                                               earlier sales too, ids only, with the same statements and gate; I76)
 *   <site>_stocklatest_<date>_<ts>.csv.gz       site,variant_id,snapshot_date,stock,stock_mode,sellable (the last snapshot day)
 *   <site>_sales_<from>_<to>_<ts>.manifest.json the run: rule, window, per-month units, snapshot days, files and sha256s,
 *                                               the SQL's sha256, the first slice's plans, Threads_running before/max/after
 * The stock files exist only when the site's product_stock_snapshot has a day on or before --to.
 *
 * Exit codes: 0 done · 1 failed (connection, query, file) · 2 usage · 3 refused by the EXPLAIN gate or the Threads_running guard.
 */

declare(strict_types=1);

const TOOL_VERSION = 'sales_history/export 1.1 (2026-10-02)';
const SLICE_DAYS = 7;
const PAUSE_US = 200_000;
const THREADS_RETRIES = 12;
const THREADS_SLEEP_S = 5;
const MAX_DRIVING_ROWS = 500_000;
const DEFAULT_ROOT = '/root/cw_work/sales_history';
const MAX_DAYS = 732;

const EXIT_OK = 0;
const EXIT_FAILED = 1;
const EXIT_USAGE = 2;
const EXIT_REFUSED = 3;

/** The statements (spec §7.1), `?` = a date or datetime literal. */
const SQL = [
    'V1' => 'SELECT cps_date AS sale_date, cps_prodt_id AS variant_id, cps_sale_qty AS units, cps_order_qty AS orders, '
        . 'cps_net_sale AS net_sale, cps_gross_sale AS gross_sale '
        . 'FROM consolidate_product_sale '
        . 'WHERE cps_date >= ? AND cps_date < ? AND cps_prodt_id > 0 AND cps_sale_qty > 0 '
        . 'ORDER BY cps_date, cps_prodt_id',
    'O1' => 'SELECT DATE(om.ord_date) AS sale_date, oi.ordi_prodt_id AS variant_id, COUNT(*) AS units, '
        . 'COUNT(DISTINCT oi.ordi_ord_id) AS orders, '
        . 'ROUND(SUM(COALESCE(oi.ordi_sale_price, 0) - COALESCE(oi.ordi_discount_amount, 0)), 2) AS net_sale, '
        . 'ROUND(SUM(COALESCE(oi.ordi_sale_price, 0)), 2) AS gross_sale '
        . 'FROM orders_master om FORCE INDEX (idx_orders_report) '
        . 'JOIN orders_items oi FORCE INDEX (idx_ordi_ord_cancelled_prodt) ON oi.ordi_ord_id = om.ord_id '
        . "WHERE om.ord_date >= ? AND om.ord_date < ? AND om.ord_status = 'Completed' AND om.ord_type = 'Online' AND oi.ordi_prodt_id > 0 "
        . 'AND (oi.ordi_iscancelled = 0 OR EXISTS (SELECT 1 FROM order_return_items r WHERE r.orti_ordi_id = oi.ordi_id)) '
        . 'GROUP BY sale_date, oi.ordi_prodt_id '
        . 'ORDER BY sale_date, variant_id',
    'O2' => 'SELECT DATE(om.ord_date) AS sale_date, oi.ordi_prodt_id AS variant_id, COUNT(*) AS units, '
        . 'COUNT(DISTINCT oi.ordi_ord_id) AS orders '
        . 'FROM orders_master om FORCE INDEX (idx_ord_type_status_date) '
        . 'JOIN orders_items oi FORCE INDEX (idx_ordi_ord_cancelled_prodt) ON oi.ordi_ord_id = om.ord_id '
        . "WHERE om.ord_type = 'office' AND om.ord_status = 'Completed' AND om.ord_date >= ? AND om.ord_date < ? "
        . 'AND (om.ord_parent_Id IS NULL OR om.ord_parent_Id = 0) AND oi.ordi_iscancelled = 0 AND oi.ordi_prodt_id > 0 '
        . 'GROUP BY sale_date, oi.ordi_prodt_id '
        . 'ORDER BY sale_date, variant_id',
    'S1' => 'SELECT pss_date, COUNT(*) AS variants, SUM(pss_sellable = 0) AS unsellable '
        . 'FROM product_stock_snapshot WHERE pss_date >= ? AND pss_date < ? GROUP BY pss_date ORDER BY pss_date',
    'S2' => 'SELECT pss_date, pss_prodt_id AS variant_id, pss_stock, pss_stock_mode, pss_allow_backorders '
        . 'FROM product_stock_snapshot WHERE pss_date >= ? AND pss_date < ? AND pss_sellable = 0 ORDER BY pss_date, pss_prodt_id',
    'S3a' => 'SELECT MAX(pss_date) AS d FROM product_stock_snapshot WHERE pss_date <= ?',
    'S3b' => 'SELECT pss_prodt_id AS variant_id, pss_stock, pss_stock_mode, pss_sellable FROM product_stock_snapshot WHERE pss_date = ? ORDER BY pss_prodt_id',
];

/** The keys each table (or alias) may use. */
const ALLOW = [
    'V1' => ['consolidate_product_sale' => ['cps_date', 'cps_date_2']],
    'O1' => ['om' => ['idx_orders_report'], 'oi' => ['idx_ordi_ord_cancelled_prodt'], 'r' => ['idx_orti_ordi']],
    'O2' => ['om' => ['idx_ord_type_status_date'], 'oi' => ['idx_ordi_ord_cancelled_prodt']],
    'S1' => ['product_stock_snapshot' => ['PRIMARY']],
    'S2' => ['product_stock_snapshot' => ['PRIMARY']],
    'S3a' => ['product_stock_snapshot' => ['PRIMARY']],
    'S3b' => ['product_stock_snapshot' => ['PRIMARY']],
];

const RULES = [
    'cps' => 'online: consolidate_product_sale (rebuilt nightly at 03:40 from Completed Online orders: returned items still count as sold, '
        . 'items cancelled before dispatch do not); office: Completed office orders that are not re-ship children (ord_parent_Id), lines not cancelled',
    'orders' => 'online: order lines of Completed Online orders, a line counted unless cancelled without a return (ordi_iscancelled = 0 or a return '
        . 'row exists), net = sale price - discount; office: Completed office orders that are not re-ship children, lines not cancelled',
];

final class Refused extends RuntimeException
{
}

final class Usage extends RuntimeException
{
}

/** @var list<string> values never printed */
$SECRETS = [];

function scrub(string $s): string
{
    global $SECRETS;
    foreach ($SECRETS as $x) {
        if ($x !== '' && strlen($x) >= 3) {
            $s = str_replace($x, '[redacted]', $s);
        }
    }
    return $s;
}

function say(string $line): void
{
    fwrite(STDOUT, scrub($line) . "\n");
}

function warn(string $line): void
{
    fwrite(STDERR, scrub($line) . "\n");
}

function isDate(string $v): bool
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function day(string $ymd): int
{
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    return intdiv(gmmktime(0, 0, 0, $m, $d, $y), 86_400);
}

function date_of(int $day): string
{
    return gmdate('Y-m-d', $day * 86_400);
}

/** The statement with its `?` replaced by the checked literals, in order. */
function bind(string $name, array $params): string
{
    $sql = SQL[$name];
    foreach ($params as $p) {
        if (!is_string($p) || preg_match('/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/D', $p) !== 1 || !isDate(substr($p, 0, 10))) {
            throw new LogicException('a parameter is a checked date');
        }
        $at = strpos($sql, '?');
        if ($at === false) {
            throw new LogicException("{$name}: more parameters than placeholders");
        }
        $sql = substr($sql, 0, $at) . "'" . $p . "'" . substr($sql, $at + 1);
    }
    if (str_contains($sql, '?')) {
        throw new LogicException("{$name}: a placeholder without a parameter");
    }
    return $sql;
}

/**
 * EXPLAIN gate of one statement; returns the plan rows (for the manifest). Throws Refused.
 *
 * @return list<array<string, mixed>>
 */
function gate(mysqli $db, string $name, string $sql): array
{
    try {
        $res = $db->query('EXPLAIN ' . $sql);
        $plan = $res instanceof mysqli_result ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } catch (mysqli_sql_exception $e) {
        throw new Refused("{$name}: EXPLAIN failed (MySQL {$e->getCode()}: " . $e->getMessage() . ')');
    }
    $allow = ALLOW[$name];
    $driving = null;
    foreach ($plan as $row) {
        $table = $row['table'] ?? null;
        $extra = (string) ($row['Extra'] ?? '');
        if ($table === null || $table === '') {
            if (preg_match('/optimized away|No tables used|Impossible WHERE|no matching row|No matching min\/max row/i', $extra) !== 1) {
                throw new Refused("{$name}: a plan row without a table ({$extra})");
            }
            continue;
        }
        if (($row['type'] ?? '') === 'ALL') {
            throw new Refused("{$name}: {$table} is read by a full scan (type ALL)");
        }
        $key = $row['key'] ?? null;
        if (!isset($allow[$table])) {
            throw new Refused("{$name}: table {$table} is not one this statement may read");
        }
        if ($key === null || !in_array($key, $allow[$table], true)) {
            throw new Refused("{$name}: {$table} would use key " . ($key ?? 'NULL') . ', not ' . implode(' / ', $allow[$table]));
        }
        $driving ??= (int) ($row['rows'] ?? 0);
    }
    if ($driving !== null && $driving > MAX_DRIVING_ROWS) {
        throw new Refused("{$name}: the driving table is estimated at {$driving} rows (more than " . MAX_DRIVING_ROWS . ')');
    }
    return array_map(static fn (array $r): array => array_intersect_key($r, array_flip(['id', 'select_type', 'table', 'type', 'key', 'rows', 'filtered', 'Extra'])), $plan);
}

function threadsRunning(mysqli $db): int
{
    $r = $db->query("SHOW GLOBAL STATUS LIKE 'Threads_running'");
    $row = $r instanceof mysqli_result ? $r->fetch_row() : null;
    return is_array($row) ? (int) $row[1] : 0;
}

/** Waits for a quiet server before a slice (Threads_running guard); records before / max. */
function guard(mysqli $db, int $limit, array &$threads): void
{
    for ($i = 0; ; $i++) {
        $n = threadsRunning($db);
        $threads['before'] ??= $n;
        $threads['max'] = max($threads['max'] ?? 0, $n);
        if ($n <= $limit) {
            return;
        }
        if ($i >= THREADS_RETRIES) {
            throw new Refused("Threads_running is {$n} (above {$limit}) after " . THREADS_RETRIES . ' waits of ' . THREADS_SLEEP_S . ' s: the server is busy, try later');
        }
        warn("Threads_running {$n} > {$limit}: waiting " . THREADS_SLEEP_S . ' s');
        sleep(THREADS_SLEEP_S);
    }
}

/** @return resource a gzip CSV stream */
function open_csv(string $path)
{
    $fh = fopen('compress.zlib://' . $path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('cannot write ' . basename($path));
    }
    return $fh;
}

function put_csv($fh, array $row): void
{
    if (fputcsv($fh, $row, ',', '"', '') === false) {
        throw new RuntimeException('cannot write a CSV row');
    }
}

function main(): int
{
    global $SECRETS;
    umask(0027);
    $o = getopt('', ['site:', 'source:', 'global-config:', 'dbtransfer-dest:', 'from:', 'to:', 'out:', 'max-threads-running:', 'help']);
    if (!is_array($o) || isset($o['help'])) {
        say('usage: php tools/sales_history/export.php --site=<code> --source=cps|orders (--global-config=<file> | --dbtransfer-dest=<file> | CW_EXPORT_DB_* env) '
            . '[--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--out=<dir under CW_SALES_EXPORT_ROOT>] [--max-threads-running=30]');
        return is_array($o) ? EXIT_OK : EXIT_USAGE;
    }
    foreach ($o as $k => $v) {
        if (is_array($v)) {
            throw new Usage("give --{$k} once");
        }
    }
    $site = (string) ($o['site'] ?? '');
    if (preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $site) !== 1) {
        throw new Usage('--site is the CW channel code (vapeandgo, electrofag)');
    }
    $source = (string) ($o['source'] ?? '');
    if (!isset(RULES[$source])) {
        throw new Usage('--source is cps (Vape and Go) or orders (Electrofag)');
    }
    $limit = (string) ($o['max-threads-running'] ?? '30');
    if (preg_match('/^\d{1,3}$/D', $limit) !== 1 || (int) $limit < 5) {
        throw new Usage('--max-threads-running is a number from 5 to 999');
    }
    $limit = (int) $limit;

    // Window (UK dates).
    $uk = new DateTimeZone('Europe/London');
    $now = new DateTimeImmutable('now', $uk);
    $today = $now->format('Y-m-d');
    $warnings = [];
    $to = (string) ($o['to'] ?? $now->modify('-1 day')->format('Y-m-d'));
    if (!isDate($to)) {
        throw new Usage('--to is a date, YYYY-MM-DD');
    }
    if ($to > $today) {
        throw new Usage("--to {$to} is in the future");
    }
    $from = (string) ($o['from'] ?? date_of(day($to) - 364));
    if (!isDate($from) || $from > $to) {
        throw new Usage('--from is a date on or before --to');
    }
    if (day($to) - day($from) + 1 > MAX_DAYS) {
        throw new Usage('at most ' . MAX_DAYS . ' days in one export');
    }
    if ((int) $now->format('G') < 4) {
        $warnings[] = 'yesterday may not be settled (cps rebuild 03:40)';
    }
    if ($to === $today) {
        $warnings[] = 'today is not settled: its sales are incomplete';
    }

    // Output directory: inside the root, whatever symlinks say.
    $rootRaw = getenv('CW_SALES_EXPORT_ROOT');
    $rootRaw = is_string($rootRaw) && $rootRaw !== '' ? $rootRaw : DEFAULT_ROOT;
    if (!is_dir($rootRaw) && !@mkdir($rootRaw, 0750, true)) {
        throw new Usage('the export root does not exist and cannot be made');
    }
    $root = realpath($rootRaw);
    $outRaw = (string) ($o['out'] ?? $root);
    $lexical = static function (string $p): string {
        $parts = [];
        foreach (explode('/', str_starts_with($p, '/') ? $p : getcwd() . '/' . $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
    };
    $inside = static fn (string $p): bool => $p === $root || str_starts_with($p, rtrim((string) $root, '/') . '/');
    if ($root === false || !$inside($lexical($outRaw)) && !$inside($lexical((string) (realpath($outRaw) ?: $outRaw)))) {
        throw new Usage('--out must be inside CW_SALES_EXPORT_ROOT (' . ($root ?: $rootRaw) . ')');
    }
    $dir = rtrim($outRaw, '/') . '/' . $site;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new RuntimeException('cannot make the output directory');
    }
    $real = realpath($dir);
    if ($real === false || !$inside($real)) {
        throw new Usage('--out must be inside CW_SALES_EXPORT_ROOT (it resolves elsewhere)');
    }

    // Connection (credentials never printed).
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $ssl = false;
    if (!empty($o['global-config'])) {
        if (!is_readable((string) $o['global-config'])) {
            throw new Usage('--global-config: no readable file');
        }
        $cfg = (static function (string $file): array {
            ob_start();
            try {
                require $file;
            } finally {
                ob_end_clean();
            }
            return [$global_servername ?? null, $global_username ?? null, $global_password ?? null, $global_dbname ?? null, $global_dbport ?? 3306];
        })((string) $o['global-config']);
        [$host, $user, $pass, $name, $port] = $cfg;
    } elseif (!empty($o['dbtransfer-dest'])) {
        if (!is_readable((string) $o['dbtransfer-dest'])) {
            throw new Usage('--dbtransfer-dest: no readable file');
        }
        ob_start();
        try {
            require (string) $o['dbtransfer-dest'];
        } finally {
            ob_end_clean();
        }
        $host = defined('DEST_HOST') ? constant('DEST_HOST') : null;
        $user = defined('DEST_USER') ? constant('DEST_USER') : null;
        $pass = defined('DEST_PASS') ? constant('DEST_PASS') : null;
        $name = defined('DEST_DB') ? constant('DEST_DB') : null;
        $port = defined('DEST_PORT') ? constant('DEST_PORT') : 3306;
        $ssl = defined('DEST_SSL') && constant('DEST_SSL');
    } elseif (getenv('CW_EXPORT_DB_HOST') !== false) {
        $host = getenv('CW_EXPORT_DB_HOST');
        $user = getenv('CW_EXPORT_DB_USER');
        $pass = getenv('CW_EXPORT_DB_PASSWORD');
        $name = getenv('CW_EXPORT_DB_NAME');
        $port = getenv('CW_EXPORT_DB_PORT') ?: 3306;
        $ssl = in_array(getenv('CW_EXPORT_DB_SSL'), ['1', 'true', 'yes'], true);
    } else {
        throw new Usage('give --global-config, --dbtransfer-dest or the CW_EXPORT_DB_* environment');
    }
    foreach ([$host, $user, $name] as $v) {
        if (!is_string($v) || $v === '') {
            throw new Usage('the connection settings are incomplete');
        }
    }
    $SECRETS = array_values(array_filter([(string) $pass, (string) $host, (string) $user], static fn (string $s): bool => $s !== ''));
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    if ($ssl) {
        $db->ssl_set(null, null, null, null, null);
    }
    try {
        $db->real_connect((string) $host, (string) $user, (string) $pass, (string) $name, (int) $port, null, $ssl ? MYSQLI_CLIENT_SSL : 0);
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException("cannot connect to the database (MySQL {$e->getCode()})");
    }
    $db->set_charset('utf8mb4');
    $db->query('SET SESSION MAX_EXECUTION_TIME = 30000');
    $db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->query('SET SESSION TRANSACTION READ ONLY');
    $ro = $db->query('SELECT @@SESSION.transaction_read_only AS ro, @@SESSION.max_execution_time AS t')->fetch_assoc();
    if ((int) ($ro['ro'] ?? 0) !== 1 || (int) ($ro['t'] ?? 0) !== 30000) {
        throw new RuntimeException('the session is not read-only with a 30 s statement limit');
    }

    $t0 = hrtime(true);
    $ts = gmdate('Ymd\THis\Z');
    $exportedAt = gmdate('Y-m-d\TH:i:s\Z');
    $base = "{$dir}/{$site}_sales_{$from}_{$to}_{$ts}";
    $salesPath = "{$base}.csv.gz";
    $stockPath = "{$dir}/{$site}_stockdays_{$from}_{$to}_{$ts}.csv.gz";
    $parts = [];
    $threads = [];
    $explain = [];
    $dataQueries = 0;
    $months = [];
    $sold = [];
    $salesRows = 0;
    $slices = 0;
    $online = $source === 'cps' ? 'V1' : 'O1';

    $cleanup = static function () use (&$parts): void {
        foreach ($parts as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
    };
    try {
        // Pass 1: the sales, slice by slice.
        $parts[] = "{$salesPath}.part";
        $out = open_csv("{$salesPath}.part");
        put_csv($out, ['site', 'variant_id', 'sale_date', 'units_online', 'orders_online', 'net_online', 'gross_online', 'units_office', 'orders_office']);
        for ($d0 = day($from); $d0 <= day($to); $d0 += SLICE_DAYS) {
            $d1 = min($d0 + SLICE_DAYS, day($to) + 1);
            $a = date_of($d0);
            $b = date_of($d1);
            guard($db, $limit, $threads);
            $sqlOnline = $source === 'cps' ? bind('V1', [$a, $b]) : bind('O1', ["{$a} 00:00:00", "{$b} 00:00:00"]);
            $sqlOffice = bind('O2', ["{$a} 00:00:00", "{$b} 00:00:00"]);
            $db->query('START TRANSACTION READ ONLY');
            $planOnline = gate($db, $online, $sqlOnline);
            $planOffice = gate($db, 'O2', $sqlOffice);
            if ($slices === 0) {
                $explain[$online] = $planOnline;
                $explain['O2'] = $planOffice;
            }
            $rows = [];
            $dataQueries++;
            foreach ($db->query($sqlOnline)->fetch_all(MYSQLI_ASSOC) as $r) {
                $k = $r['sale_date'] . '|' . $r['variant_id'];
                $rows[$k] = ['date' => (string) $r['sale_date'], 'variant' => (int) $r['variant_id'], 'uo' => (int) $r['units'], 'oo' => (int) $r['orders'],
                    'net' => (string) $r['net_sale'], 'gross' => (string) $r['gross_sale'], 'uf' => 0, 'of' => 0];
            }
            $dataQueries++;
            foreach ($db->query($sqlOffice)->fetch_all(MYSQLI_ASSOC) as $r) {
                $k = $r['sale_date'] . '|' . $r['variant_id'];
                $rows[$k] ??= ['date' => (string) $r['sale_date'], 'variant' => (int) $r['variant_id'], 'uo' => 0, 'oo' => 0, 'net' => '0.00', 'gross' => '0.00', 'uf' => 0, 'of' => 0];
                $rows[$k]['uf'] = (int) $r['units'];
                $rows[$k]['of'] = (int) $r['orders'];
            }
            $db->query('COMMIT');
            usort($rows, static fn (array $x, array $y): int => [$x['date'], $x['variant']] <=> [$y['date'], $y['variant']]);
            foreach ($rows as $r) {
                if ($r['uo'] + $r['uf'] <= 0) {
                    continue;
                }
                put_csv($out, [$site, (string) $r['variant'], $r['date'], $r['uo'], $r['oo'], $r['net'], $r['gross'], $r['uf'], $r['of']]);
                $salesRows++;
                $sold[$r['variant']] = true;
                $m = substr($r['date'], 0, 7);
                $months[$m] ??= ['units_online' => 0, 'units_office' => 0, 'rows' => 0];
                $months[$m]['units_online'] += $r['uo'];
                $months[$m]['units_office'] += $r['uf'];
                $months[$m]['rows']++;
            }
            $slices++;
            usleep(PAUSE_US);
        }
        fclose($out);

        // Pass 2: the site's stock snapshot (only when it has a day on or before --to).
        $snapDays = [];
        $stockRows = 0;
        $latestRows = 0;
        $latestPath = null;
        $latestDate = null;
        guard($db, $limit, $threads);
        $db->query('START TRANSACTION READ ONLY');
        $hasTable = true;
        try {
            $explain['S3a'] = gate($db, 'S3a', bind('S3a', [$to]));
        } catch (Refused $e) {
            if (!str_contains($e->getMessage(), 'MySQL 1146')) {
                throw $e;
            }
            $hasTable = false;
            $warnings[] = 'the site has no product_stock_snapshot table: no stock files';
        }
        if ($hasTable) {
            $dataQueries++;
            $latestDate = $db->query(bind('S3a', [$to]))->fetch_assoc()['d'] ?? null;
        }
        $db->query('COMMIT');
        if ($hasTable && $latestDate === null) {
            $warnings[] = 'the site\'s product_stock_snapshot has no day on or before ' . $to . ': no stock files';
        }
        $lookback = null;
        $soldInWindow = count($sold);
        if ($latestDate !== null && day($to) - 364 < day($from) && day((string) $latestDate) >= day($from)) {
            // Pass 1b (review finding, I76): an out-of-stock day counts only for a variant that did NOT sell that day, so
            // the unsellable days are kept for every variant sold in the 365 days to --to, not only inside this window (a
            // nightly one-day export would otherwise keep none, and its import would drop the earlier ones). The same
            // statements and gate as pass 1 over [--to - 364, --from), keeping only the variant ids.
            $lookSlices = 0;
            for ($d0 = day($to) - 364; $d0 < day($from); $d0 += SLICE_DAYS) {
                $d1 = min($d0 + SLICE_DAYS, day($from));
                $a = date_of($d0);
                $b = date_of($d1);
                guard($db, $limit, $threads);
                $sqlOnline = $source === 'cps' ? bind('V1', [$a, $b]) : bind('O1', ["{$a} 00:00:00", "{$b} 00:00:00"]);
                $sqlOffice = bind('O2', ["{$a} 00:00:00", "{$b} 00:00:00"]);
                $db->query('START TRANSACTION READ ONLY');
                gate($db, $online, $sqlOnline);
                gate($db, 'O2', $sqlOffice);
                $read = 0;
                foreach ([$sqlOnline, $sqlOffice] as $q) {
                    $dataQueries++;
                    $res = $db->query($q, MYSQLI_USE_RESULT);
                    while ($r = $res->fetch_assoc()) {
                        $read++;
                        if ((int) $r['units'] > 0) {
                            $sold[(int) $r['variant_id']] = true;
                        }
                    }
                    $res->free();
                }
                $db->query('COMMIT');
                $lookSlices++;
                if ($read > 0) {
                    usleep(PAUSE_US); // a slice that read nothing (before the site's history starts) put no load on the server
                }
            }
            $lookback = ['from' => date_of(day($to) - 364), 'to' => date_of(day($from) - 1), 'slices' => $lookSlices,
                'variants_in_window' => $soldInWindow, 'variants' => count($sold)];
        }
        if ($latestDate !== null) {
            $parts[] = "{$stockPath}.part";
            $sout = open_csv("{$stockPath}.part");
            put_csv($sout, ['site', 'variant_id', 'stock_date', 'stock', 'stock_mode', 'allow_backorders']);
            $first = true;
            for ($d0 = day($from); $d0 <= min(day($to), day((string) $latestDate)); $d0 += SLICE_DAYS) {
                $d1 = min($d0 + SLICE_DAYS, day($to) + 1);
                $a = date_of($d0);
                $b = date_of($d1);
                guard($db, $limit, $threads);
                $s1 = bind('S1', [$a, $b]);
                $s2 = bind('S2', [$a, $b]);
                $db->query('START TRANSACTION READ ONLY');
                $p1 = gate($db, 'S1', $s1);
                $p2 = gate($db, 'S2', $s2);
                if ($first) {
                    $explain['S1'] = $p1;
                    $explain['S2'] = $p2;
                    $first = false;
                }
                $dataQueries++;
                foreach ($db->query($s1)->fetch_all(MYSQLI_ASSOC) as $r) {
                    $snapDays[] = ['date' => (string) $r['pss_date'], 'variants' => (int) $r['variants'], 'unsellable' => (int) $r['unsellable']];
                }
                $dataQueries++;
                $res = $db->query($s2, MYSQLI_USE_RESULT);
                while ($r = $res->fetch_assoc()) {
                    if (!isset($sold[(int) $r['variant_id']])) {
                        continue;
                    }
                    put_csv($sout, [$site, (string) (int) $r['variant_id'], (string) $r['pss_date'], $r['pss_stock'] === null ? '' : (string) $r['pss_stock'],
                        (string) ($r['pss_stock_mode'] ?? ''), $r['pss_allow_backorders'] === null ? '' : (string) $r['pss_allow_backorders']]);
                    $stockRows++;
                }
                $res->free();
                $db->query('COMMIT');
                usleep(PAUSE_US);
            }
            fclose($sout);
            $latestPath = "{$dir}/{$site}_stocklatest_{$latestDate}_{$ts}.csv.gz";
            $parts[] = "{$latestPath}.part";
            $lout = open_csv("{$latestPath}.part");
            put_csv($lout, ['site', 'variant_id', 'snapshot_date', 'stock', 'stock_mode', 'sellable']);
            guard($db, $limit, $threads);
            $s3 = bind('S3b', [(string) $latestDate]);
            $db->query('START TRANSACTION READ ONLY');
            $explain['S3b'] = gate($db, 'S3b', $s3);
            $dataQueries++;
            $res = $db->query($s3, MYSQLI_USE_RESULT);
            while ($r = $res->fetch_assoc()) {
                put_csv($lout, [$site, (string) (int) $r['variant_id'], (string) $latestDate, (string) (int) $r['pss_stock'], (string) ($r['pss_stock_mode'] ?? ''),
                    (string) (int) $r['pss_sellable']]);
                $latestRows++;
            }
            $res->free();
            $db->query('COMMIT');
            fclose($lout);
        }
        $threads['after'] = threadsRunning($db);
        $db->close();

        // Files in place, then the manifest.
        $files = [];
        $final = [[$salesPath, 'sales', $salesRows]];
        if ($latestDate !== null) {
            $final[] = [$stockPath, 'stockdays', $stockRows];
            $final[] = [(string) $latestPath, 'latest', $latestRows];
        }
        foreach ($final as [$path, $kind, $n]) {
            if (!rename("{$path}.part", $path)) {
                throw new RuntimeException('cannot rename ' . basename($path));
            }
            chmod($path, 0640);
            $files[] = ['name' => basename($path), 'kind' => $kind, 'sha256' => hash_file('sha256', $path), 'rows' => $n];
        }
        $parts = [];
        ksort($months);
        $manifest = [
            'site' => $site,
            'database' => (string) $name,
            'source' => $source,
            'rule' => RULES[$source],
            'from' => $from,
            'to' => $to,
            'exported_at' => $exportedAt,
            'tool_version' => TOOL_VERSION,
            'seconds' => round((hrtime(true) - $t0) / 1e9, 1),
            'slices' => $slices,
            'months' => $months,
            'snapshot_days' => $snapDays,
            'stock_filter' => 'unsellable days of the variants sold (online or office) in the 365 days to --to',
            'lookback' => $lookback,
            'files' => $files,
            'query_sha256' => hash('sha256', implode("\n", SQL)),
            'queries' => SQL,
            'explain' => $explain,
            'threads_running' => ['before' => $threads['before'] ?? null, 'max' => $threads['max'] ?? null, 'after' => $threads['after'] ?? null],
            'warnings' => $warnings,
        ];
        $mpath = "{$base}.manifest.json";
        if (file_put_contents($mpath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) {
            throw new RuntimeException('cannot write the manifest');
        }
        chmod($mpath, 0640);
        foreach ($warnings as $w) {
            warn("warning: {$w}");
        }
        say(sprintf('exported %s (%s, database %s) %s to %s: %d sales rows, %d stock-day rows, %d latest rows (snapshot %s), %d slices, %.1f s, '
            . 'Threads_running before %s max %s after %s', $site, $source, $name, $from, $to, $salesRows, $stockRows, $latestRows, $latestDate ?? 'none', $slices,
            $manifest['seconds'], $manifest['threads_running']['before'] ?? '-', $manifest['threads_running']['max'] ?? '-', $manifest['threads_running']['after'] ?? '-'));
        say('manifest: ' . $mpath);
        return EXIT_OK;
    } catch (Refused $e) {
        $cleanup();
        try {
            $db->query('ROLLBACK');
        } catch (Throwable) {
        }
        warn('refused: ' . $e->getMessage() . "; nothing further was run (data queries run before: {$dataQueries}); no files kept");
        return EXIT_REFUSED;
    } catch (Throwable $e) {
        $cleanup();
        warn('failed: ' . ($e instanceof mysqli_sql_exception ? "MySQL {$e->getCode()}: " : '') . $e->getMessage() . '; no files kept');
        return EXIT_FAILED;
    }
}

try {
    exit(main());
} catch (Usage $e) {
    warn('usage: ' . $e->getMessage());
    exit(EXIT_USAGE);
} catch (Refused $e) {
    warn('refused: ' . $e->getMessage());
    exit(EXIT_REFUSED);
} catch (Throwable $e) {
    warn('failed: ' . ($e instanceof mysqli_sql_exception ? "MySQL {$e->getCode()}: " : '') . $e->getMessage());
    exit(EXIT_FAILED);
}
