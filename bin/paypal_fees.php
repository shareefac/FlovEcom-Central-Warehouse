<?php

declare(strict_types=1);

/**
 * PayPal's payment fees in CW's profit (CW-1172; docs/decisions.md PPF1-PPF22; CW\Profit\PaypalFees). Reads PayPal's Transaction Search with
 * the account's key (its key file: app.env paypal_key_file_<account>; never printed), stores every transaction, puts each on its shop by its
 * invoice's order-number form (profit.paypal_shops_<account>, profit.paypal_form_<shop>) and counts its money on its order or on its shop's
 * "not on an order" line in the profit days.
 *
 *   php bin/paypal_fees.php --account=vapeandgo                   the nightly: the last profit.paypal_reread_days days read again (from the
 *                                                                 oldest pending row when one is older, never before the first fee day)
 *   php bin/paypal_fees.php --account=vapeandgo --from=2026-11-01 [--to=2026-11-24]   a backfill of these UK days (to: today when not given)
 *   php bin/paypal_fees.php --account=vapeandgo --decide          no PayPal call: the rows still due decided (after a run that stopped)
 *   php bin/paypal_fees.php --account=vapeandgo --status          read only: the newest runs, the rows by kind and state, the money, what waits
 *
 * Exit codes: 0 done (also: skipped, another run holds the job's lock) · 1 a problem (PayPal could not be read whole, an order was busy:
 * the run is recorded as failed; what was read stays, its rows due) · 2 usage · 3 cannot run (no key, a refused key, no schema).
 */

use CW\Caller;
use CW\Config;
use CW\CwException;
use CW\Ops\Cli;
use CW\Profit\CurlPaypalHttp;
use CW\Profit\PaypalApi;
use CW\Profit\PaypalFees;
use CW\Profit\PaypalKey;
use CW\Profit\ProfitViews;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = 'usage: php bin/paypal_fees.php --account=<name> [--from=YYYY-MM-DD [--to=YYYY-MM-DD] | --decide | --status]';
exit(Cli::main('paypal_fees', ['account:', 'from:', 'to:', 'decide', 'status'], $usage,
    static function (Cli $cli, array $opts) use ($usage): int {
        $account = is_string($opts['account'] ?? null) ? $opts['account'] : '';
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $account) !== 1) {
            throw new InvalidArgumentException("--account=<name> is needed\n{$usage}");
        }
        $modes = (int) isset($opts['from']) + (int) isset($opts['decide']) + (int) isset($opts['status']);
        if ($modes > 1 || (isset($opts['to']) && !isset($opts['from']))) {
            throw new InvalidArgumentException($usage);
        }
        $fees = new PaypalFees($cli->db);
        $caller = Caller::system('paypal_fees');
        if (isset($opts['status'])) {
            $s = PaypalFees::status($cli->db, $account);
            $out = ["PayPal account {$account}; its shops (first fee day): " . implode(', ', array_map(static fn (string $k, string $v): string => "{$k} ({$v})",
                array_keys($s['shops']), array_map('strval', array_values($s['shops']))))];
            foreach ($s['runs'] as $r) {
                $out[] = sprintf('  run %d %s %s..%s %s: %d windows, %d pages, %d rows (%d new, %d changed), %d decided%s; %s - %s', $r['id'], $r['kind'], $r['from_day'],
                    $r['to_day'], $r['state'], $r['windows'], $r['pages'], $r['rows_read'], $r['rows_new'], $r['rows_changed'], $r['allocated'],
                    $r['error'] === null ? '' : ' (' . $r['error'] . ')', $r['started_at'], $r['finished_at'] ?? 'running');
            }
            $out[] = '  rows by kind and state:';
            foreach ($s['states'] as $r) {
                $out[] = sprintf('    %-10s %-12s %6d  cost %s', $r['kind'], $r['count_state'], $r['n'], ProfitViews::gbp((int) $r['cost']));
            }
            $out[] = '  money counted by shop:';
            foreach ($s['by_shop'] as $r) {
                $out[] = sprintf('    %-12s on %-5s %6d rows  %s', $r['code'], $r['alloc_dest'], $r['n'], ProfitViews::gbp((int) $r['pence']));
            }
            $out[] = '  money counted by kind:';
            foreach ($s['money'] as $r) {
                $out[] = sprintf('    %-10s on %-5s %-9s %6d rows  %s', $r['kind'], $r['alloc_dest'], $r['match_state'], $r['n'], ProfitViews::gbp((int) $r['pence']));
            }
            $out[] = sprintf('  waiting: %d due, %d pending (oldest %s), %d sale fees on orders not paid in CW (%s)', $s['due'], $s['pending'], $s['oldest_pending'] ?? '-',
                $s['unpaid']['rows'], ProfitViews::gbp($s['unpaid']['pence']));
            $out[] = '  needs a look: ' . count($s['look']);
            foreach ($s['look'] as $r) {
                $out[] = sprintf('    %s %s status %s %s (%s): %s %s, fee %s', $r['day'], $r['event_code'], $r['status'], $r['kind'], $r['look_reason'], $r['currency'],
                    ProfitViews::gbp((int) $r['amount_pence']), ProfitViews::gbp((int) $r['fee_pence']));
            }
            fwrite(STDOUT, implode("\n", $out) . "\n");
            return Cli::OK;
        }
        // the job's lock (one run of this job at a time): the nightly finding it held is skipped (exit 0); a backfill or --decide asked for by
        // hand is BUSY (exit 4: nothing done, run it again; fix round 1: a go-live gate never takes a skipped run for done)
        if (!$cli->tryLock()) {
            $cli->log('another run of this job holds its lock: nothing done' . (isset($opts['from']) || isset($opts['decide']) ? '; run it again' : ''));
            return isset($opts['from']) || isset($opts['decide']) ? Cli::BUSY : Cli::OK;
        }
        // the overall deadline (below the installer's 6-minute drain for the nightly; fix round 1)
        $deadline = microtime(true) + (isset($opts['from']) ? 1200 : 240);
        if (isset($opts['decide'])) {
            // no PayPal call (no key needed): the rows a stopped run left due, decided under the account's lock
            $n = $fees->locked($account, static fn (): int => $fees->decideAll($caller, $account, null, $deadline));
            $cli->log("{$account}: {$n} PayPal rows decided again");
            return Cli::OK;
        }
        // no shop of the account switched on: nothing to read (exit 0, as a job switched off; before the go-live and on staging)
        $on = array_filter(PaypalFees::accountShops($cli->db, $account), static fn (array $sh): bool => $sh['id'] !== null
            && PaypalFees::feesFrom($cli->db, $sh['code']) !== null);
        if ($on === []) {
            // switched off (also: switched off again, the quick stop): no PayPal call, but every row counted before is decided again, so its
            // money leaves the profit days (before_start); the rows stay
            $n = $fees->locked($account, static fn (): int => $fees->decideAll($caller, $account, null, $deadline));
            $cli->log("{$account}: switched off (no shop of it has profit.paypal_from_<shop> set); nothing read; {$n} rows decided again");
            return Cli::OK;
        }
        // the local decisions first, with no PayPal call (a shop switched off, forms changed, orders that reached CW): made even when the key or
        // PayPal fails below (fix round 1)
        try {
            $local = $fees->locked($account, static fn (): int => $fees->decideAll($caller, $account, null, $deadline));
        } catch (CwException $e) {
            $cli->error("{$account}: the local decisions could not be made now ({$e->getMessage()}); PayPal is read all the same");
            $local = 0;
        }
        $config = Config::load();
        try {
            $key = PaypalKey::load($config->get(PaypalKey::configKey($account)), $account);
        } catch (CwException $e) {
            // never the file's content: the refusal names what is wrong with it; the health alert says the account is not read (PPF14)
            $fees->refused($account, $e->getMessage());
            $cli->error($e->getMessage());
            return Cli::CANNOT_RUN;
        }
        $today = $fees->today();
        if (isset($opts['from'])) {
            $from = (string) $opts['from'];
            $to = is_string($opts['to'] ?? null) ? $opts['to'] : $today;
            $kind = 'backfill';
        } else {
            $from = $fees->nightlyFrom($key->account);
            $to = $today;
            $kind = 'nightly';
        }
        if ($to > $today) {
            $to = $today;
        }
        $api = new PaypalApi($key, new CurlPaypalHttp(), null, $deadline);
        $t = microtime(true);
        try {
            $r = $fees->run($caller, $api, $from, $to, $kind, $deadline);
        } catch (CwException $e) {
            $cli->error("{$account} {$kind} {$from}..{$to}: " . $e->getMessage());
            return $e->errorCode === 'paypal_bad_days' ? Cli::USAGE : Cli::PROBLEM;
        }
        $cli->log(sprintf('%s %s %s..%s: RUN=%d ok, %d windows, %d pages, %d rows (%d new, %d changed), %d decided (+%d before reading), PayPal complete to %s, %.1f s (%s)',
            $account, $kind, $from, $to, $r['run'], $r['windows'], $r['pages'], $r['read'], $r['new'], $r['changed'], $r['allocated'], $local, $r['refreshed'] ?? '-', microtime(true) - $t,
            implode(', ', array_map(static fn (string $k, float $v): string => "{$k} {$v} s", array_keys($r['seconds']), array_values($r['seconds'])))));
        return Cli::OK;
    }, false, true));
