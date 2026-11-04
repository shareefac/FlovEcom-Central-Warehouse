<?php

declare(strict_types=1);

/**
 * The copies of ERPNext purchase invoices (CW\Invoices\ErpInvoiceCopy; CW-917; docs/decisions.md EC1-EC16; docs/ops.md "Invoices from
 * ERPNext"): reads ERPNext's purchase invoices with CW's own read-only API user (GET only; app.env erp_read_url, erp_read_key,
 * erp_read_secret) and keeps a copy of each as a posted supplier invoice (a debit note as a credit note). A copy never adds stock (the
 * relay booked it) and never feeds the cost.
 *
 *   php bin/copy_erp_invoices.php [--apply] [--from=YYYY-MM-DD] [--limit=<invoices>] [--db=<schema>] [--admin]
 *
 * It does NOTHING while the setting erp_copy.on is off (it says so, reads nothing, exit 0). DRY RUN by default: it reads ERPNext and
 * works every invoice out in a transaction that is rolled back, so nothing is written (not even its run), and prints what a real run would
 * do. --apply writes (one transaction per invoice, one erp_copy_run row). --from reads every invoice posted on or after that date, whatever
 * the last run reached (the back-fill; default: the setting erp_copy.from_date, from where the last run of that date, or of an earlier
 * one, stopped). A run reads at most erp_copy.per_run invoices from ERPNext (--limit overrides it); one that reaches it keeps its cursor and
 * the next run (the cron's, after a --from back-fill too) goes on from there. The cron runs it with --apply every 30 minutes.
 *
 * Exit codes: 0 done (also: off; invoices that wait are work for people, not errors) · 2 usage · 3 cannot run (ERPNext not set up or not
 * reachable: a real run's row says failed, and the next run reads from the same point; or the job's reasons erp_cancelled / erp_changed
 * cannot cancel a copy: switched off, missing, or not offered for cancelling records; nothing read).
 */

use CW\Config;
use CW\Invoices\ErpApiReader;
use CW\Invoices\ErpInvoiceCopy;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('copy_erp_invoices', ['apply', 'from:', 'limit:'], 'usage: php bin/copy_erp_invoices.php [--apply] [--from=YYYY-MM-DD] [--limit=<invoices>]',
    static function (Cli $cli, array $opts): int {
        $apply = array_key_exists('apply', $opts);
        $from = null;
        if (isset($opts['from'])) {
            $from = (string) $opts['from'];
            $at = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
            if ($at === false || $at->format('Y-m-d') !== $from) {
                throw new InvalidArgumentException('--from must be a date, YYYY-MM-DD');
            }
        }
        $limit = isset($opts['limit']) ? Cli::intOpt($opts, 'limit', 0, 1, ErpApiReader::MAX_LIST) : null;
        $copy = new ErpInvoiceCopy($cli->db);
        if (!$copy->settings()['on']) {
            $cli->log('off: the setting ' . ErpInvoiceCopy::SWITCH . ' is off (Settings > Rules and numbers > Invoices from ERPNext): nothing was read or written');
            return Cli::OK;
        }
        $copy->assertReasons(); // before the reader is made: nothing is read while a copy could not be cancelled (exit 3)
        $r = $copy->run(ErpApiReader::fromConfig(Config::load()), $apply, $from, $limit);
        if (($r['state'] ?? null) === 'off') {
            $cli->log('off: the setting ' . ErpInvoiceCopy::SWITCH . ' is off: nothing was read or written');
            return Cli::OK;
        }
        foreach (ErpInvoiceCopy::logLines($r) as $line) {
            $cli->log($line);
        }
        return Cli::OK;
    }, true, true));
