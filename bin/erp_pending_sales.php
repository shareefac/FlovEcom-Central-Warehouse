<?php

declare(strict_types=1);

/**
 * The website sales ERPNext does not have yet, per variant (pack I, review of 14 Oct 2026, docs/decisions.md PI20): the pending-sales
 * file bin/import_erp_opening.php takes off ERPNext's figures. CW\Ops\SitePendingSales has the rules.
 *
 * READ ONLY. It reads the Vape and Go database, never writes: SELECT statements only, in a READ ONLY session and transaction, each
 * statement capped by MAX_EXECUTION_TIME, the transaction rolled back at the end. It never connects to ERPNext.
 * Run it at the cut-off moment, together with the ERPNext Stock Balance export (docs/ops.md "The opening stock from ERPNext").
 *
 *   php bin/erp_pending_sales.php --site-env=<file> [--out=<pending.csv>] [--max-ms=30000] [--success-grace-minutes=10]
 *
 * --site-env: KEY=VALUE lines (DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME, DB_SSL_MODE, DB_SSL_CA) of a login that can read the
 *   store's data_sync, orders_master and orders_items; parsed, never sourced or printed; chmod 600. A read-only login is best.
 * --out: where the file goes (made 0600); without it the file is printed. The summary goes to stderr.
 * Exit codes: 0 ok · 2 usage · 3 cannot run (connection, a statement over its time cap, a table missing).
 */

use CW\Db;
use CW\Ops\SitePendingSales;

require dirname(__DIR__) . '/vendor/autoload.php';

const USAGE = 'usage: php bin/erp_pending_sales.php --site-env=<file> [--out=<pending.csv>] [--max-ms=30000] [--success-grace-minutes=10]';

$opts = getopt('', ['site-env:', 'out:', 'max-ms:', 'success-grace-minutes:', 'help']);
if (!is_array($opts) || isset($opts['help'])) {
    fwrite(STDOUT, USAGE . "\n");
    exit(0);
}
$say = static fn (string $m): int => (int) fwrite(STDERR, gmdate('Y-m-d\TH:i:s\Z') . " erp_pending_sales {$m}\n");
foreach ($opts as $k => $v) {
    if (!is_string($v)) {
        $say("give --{$k} once, with a value\n" . USAGE);
        exit(2);
    }
}
$int = static function (string $k, int $default) use ($opts): int {
    $v = $opts[$k] ?? null;
    if ($v === null) {
        return $default;
    }
    if (preg_match('/^\d{1,7}$/D', $v) !== 1) {
        throw new InvalidArgumentException("--{$k} is a whole number");
    }
    return (int) $v;
};
try {
    if (!isset($opts['site-env'])) {
        throw new InvalidArgumentException('give --site-env=<the website database\'s env file>');
    }
    $settings = SitePendingSales::settingsFrom($opts['site-env']);
    $reader = new SitePendingSales(Db::connect($settings), $int('max-ms', SitePendingSales::DEFAULT_MAX_MS),
        $int('success-grace-minutes', SitePendingSales::DEFAULT_GRACE_MINUTES));
} catch (InvalidArgumentException $e) {
    $say($e->getMessage() . "\n" . USAGE);
    exit(2);
} catch (Throwable $e) {
    $say('cannot connect: ' . get_class($e) . ' ' . preg_replace('/(password|pwd)\S*/i', '[hidden]', mb_substr($e->getMessage(), 0, 200)));
    exit(3);
}
try {
    $r = $reader->read();
} catch (Throwable $e) {
    $say('cannot read: ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 300) . ' (nothing was written: the session is read only)');
    exit(3);
}
$csv = SitePendingSales::csv($r['lines']);
$rep = $r['report'];
$say(sprintf('read %s at %s (read only, statements capped at %d ms): queue rows still on their way %s (SUCCESS of the last %d minutes '
    . 'counted as on their way); %d units queued, %d units of %d orders not queued yet; %d variants, %d units%s',
    $rep['database'], $rep['read_at'], $rep['max_ms'], json_encode($rep['queue_rows']), $rep['grace_minutes'], $rep['queued_units'],
    $rep['not_queued']['units'], $rep['not_queued']['orders'], $rep['variants'], $rep['units'],
    $rep['fractional_variants'] === [] ? '' : '; fractional quantities rounded for ' . implode(',', $rep['fractional_variants'])));
if ($rep['returns_on_their_way_not_netted'] !== []) {
    $say('NOT NETTED: cancel and refund rows still on their way (they put units back in ERPNext): '
        . json_encode($rep['returns_on_their_way_not_netted']));
}
if (isset($opts['out'])) {
    $out = $opts['out'];
    if (@file_put_contents($out, $csv) === false || !chmod($out, 0600)) {
        $say("cannot write {$out}");
        exit(3);
    }
    $say("written: {$out} (" . count($r['lines']) . ' variants)');
} else {
    fwrite(STDOUT, $csv);
}
exit(0);
