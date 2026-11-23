<?php

declare(strict_types=1);

/**
 * The lines of the live carrier bills that are not matched yet, or match two parcels, tried again (delivery cost in profit, step 2
 * part 1; docs/decisions.md DL10; CW\Profit\DeliveryBills::rematch): a parcel that reached CW after its bill was uploaded (a late
 * snapshot, a backfill) takes its line now, and the profit days follow. Nightly, before the rebuild of the profit days; each upload does
 * the same at once. A line already on a parcel or an order is never moved to another one.
 *
 *   php bin/match_delivery_bills.php
 *
 * Exit codes: 0 done · 2 usage · 3 cannot run · 4 not run: another run holds the job's lock (an upload holds the bills' own lock: the
 * run waits for it up to DeliveryBills::LOCK_WAIT_SEC, then says busy and ends with 1).
 */

use CW\Caller;
use CW\CwException;
use CW\Ops\Cli;
use CW\Profit\DeliveryBills;
use CW\Profit\RoyalMailBills;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('match_delivery_bills', [], 'usage: php bin/match_delivery_bills.php',
    static function (Cli $cli, array $opts): int {
        try {
            $n = (new DeliveryBills($cli->db))->rematch(Caller::system('delivery_match'));
        } catch (CwException $e) {
            $cli->error('not run: ' . $e->getMessage());
            return Cli::PROBLEM;
        }
        $cli->log("{$n} bill lines put on a parcel or an order (or moved between not matched, ambiguous and the others)");
        // Royal Mail's (pooled) bills (0078, DL66): every key of the live rows of the last RoyalMailBills::REPLAN_DAYS days planned again (a label or a
        // CW parcel that came since takes its price), and the allocations of bills no longer counting ended.
        try {
            $m = (new RoyalMailBills($cli->db))->replan(Caller::system('delivery_match'));
        } catch (CwException $e) {
            $cli->error('Royal Mail bills not planned again: ' . $e->getMessage());
            return Cli::PROBLEM;
        }
        $cli->log("{$m} Royal Mail allocations ended or added");
        return Cli::OK;
    }, true, true));
