<?php

declare(strict_types=1);

/**
 * Makes final the supplier invoices whose time has come (pack J, docs/decisions.md PJ10, PJ11): the time a person set on each one
 * (Purchasing › Invoices, "Make it final at"); there is no fixed time. Cron: every minute (deploy/staging/cw-staging.cron).
 *
 *   php bin/submit_invoices.php [--db=cw_staging] [--limit=100] [--admin]
 *
 * CW\Arrivals\InvoiceSchedule::runDue: each due draft is made final through SupplierInvoices::submit as the person who set its time, in
 * its own transactions; one that fails keeps its reason on the invoice, is listed with the timed invoices and named by the alerts, and is
 * not tried again until a person sets its time again. One run at a time per schema (named lock; an overlapping run logs "skipped").
 * Exit codes: 0 ok, 1 some invoices failed, 2 usage, 3 cannot run (see CW\Ops\Cli).
 */

use CW\Arrivals\InvoiceSchedule;
use CW\Clock;
use CW\Config;
use CW\Files\FileStore;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(
    'submit_invoices',
    ['limit:'],
    'usage: php bin/submit_invoices.php [--limit=100]',
    static function (Cli $cli, array $opts): int {
        $limit = Cli::intOpt($opts, 'limit', InvoiceSchedule::BATCH, 1, 1_000);
        $started = hrtime(true);
        $files = static fn (): FileStore => FileStore::fromConfig(Config::load(), $cli->db);
        $r = (new InvoiceSchedule($cli->db, $files))->runDue(Clock::now(), $limit, static function (int $id, \Throwable $e) use ($cli): void {
            $cli->error("supplier invoice {$id} could not be made final: " . Cli::describe($e));
        });
        if ($r['posted'] !== [] || $r['failed'] !== [] || $r['skipped'] !== []) { // every minute: a line only when something was due
            $cli->log(sprintf('posted=%d failed=%d skipped=%d ms=%d', count($r['posted']), count($r['failed']), count($r['skipped']), intdiv(hrtime(true) - $started, 1_000_000)));
        }
        return $r['failed'] === [] ? Cli::OK : Cli::PROBLEM;
    },
));
