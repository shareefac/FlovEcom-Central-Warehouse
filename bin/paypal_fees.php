<?php

declare(strict_types=1);

/**
 * PayPal's payment fees in CW's profit (CW-1172; docs/decisions.md PF1-PF20; CW\Profit\PaypalFees). Reads PayPal's Transaction Search with
 * the shop's key (its key file: app.env paypal_key_file_<shop>; never printed), stores every transaction, and counts its money on its order
 * or on the shop's "not on an order" line in the profit days.
 *
 *   php bin/paypal_fees.php --shop=vapeandgo                      the nightly: the last profit.paypal_reread_days days read again (from the
 *                                                                 oldest pending row when one is older, never before the shop's first fee day)
 *   php bin/paypal_fees.php --shop=vapeandgo --from=2026-11-01 [--to=2026-11-24]   a backfill of these UK days (to: today when not given)
 *   php bin/paypal_fees.php --shop=vapeandgo --decide             no PayPal call: the rows still due decided (after a run that stopped)
 *   php bin/paypal_fees.php --shop=vapeandgo --status             read only: the newest runs, the rows by kind and state, the money, what waits
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

$usage = 'usage: php bin/paypal_fees.php --shop=<code> [--from=YYYY-MM-DD [--to=YYYY-MM-DD] | --decide | --status]';
exit(Cli::main('paypal_fees', ['shop:', 'from:', 'to:', 'decide', 'status'], $usage,
    static function (Cli $cli, array $opts) use ($usage): int {
        $shop = is_string($opts['shop'] ?? null) ? $opts['shop'] : '';
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $shop) !== 1) {
            throw new InvalidArgumentException("--shop=<code> is needed\n{$usage}");
        }
        $modes = (int) isset($opts['from']) + (int) isset($opts['decide']) + (int) isset($opts['status']);
        if ($modes > 1 || (isset($opts['to']) && !isset($opts['from']))) {
            throw new InvalidArgumentException($usage);
        }
        $fees = new PaypalFees($cli->db);
        $caller = Caller::system('paypal_fees');
        if (isset($opts['status'])) {
            $account = (string) ($cli->db->value('SELECT account FROM paypal_run r JOIN channel c ON c.id = r.channel_id WHERE c.code = ? ORDER BY r.id DESC LIMIT 1', [$shop])
                ?? $shop);
            $s = PaypalFees::status($cli->db, $account);
            $out = ["PayPal account {$account} of " . ($s['shop'] ?? $shop) . '; fees count from ' . ($s['from'] ?? 'not switched on')];
            foreach ($s['runs'] as $r) {
                $out[] = sprintf('  run %d %s %s..%s %s: %d windows, %d pages, %d rows (%d new, %d changed), %d decided%s; %s - %s', $r['id'], $r['kind'], $r['from_day'],
                    $r['to_day'], $r['state'], $r['windows'], $r['pages'], $r['rows_read'], $r['rows_new'], $r['rows_changed'], $r['allocated'],
                    $r['error'] === null ? '' : ' (' . $r['error'] . ')', $r['started_at'], $r['finished_at'] ?? 'running');
            }
            $out[] = '  rows by kind and state:';
            foreach ($s['states'] as $r) {
                $out[] = sprintf('    %-10s %-12s %6d  cost %s', $r['kind'], $r['count_state'], $r['n'], ProfitViews::gbp((int) $r['cost']));
            }
            $out[] = '  money counted (pence as pounds):';
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
        $config = Config::load();
        try {
            $key = PaypalKey::load($config->get(PaypalKey::configKey($shop)), $shop);
        } catch (CwException $e) {
            // never the file's content: the refusal names what is wrong with it; the health alert says the shop is not read (PF14)
            $fees->refused($shop, $e->getMessage());
            $cli->error($e->getMessage());
            return Cli::CANNOT_RUN;
        }
        if (isset($opts['decide'])) {
            $n = $fees->locked($key->account, static fn (): int => $fees->decideAll($caller, $key->account, null));
            $cli->log("{$shop}: {$n} PayPal rows decided again");
            return Cli::OK;
        }
        if (PaypalFees::feesFrom($cli->db, $shop) === null) {
            $cli->error("{$shop}: its PayPal fees are not switched on (Settings › Profit: profit.paypal_from_{$shop}); nothing read");
            return Cli::CANNOT_RUN;
        }
        $today = $fees->today();
        if (isset($opts['from'])) {
            $from = (string) $opts['from'];
            $to = is_string($opts['to'] ?? null) ? $opts['to'] : $today;
            $kind = 'backfill';
        } else {
            $from = $fees->nightlyFrom($key->account, $shop);
            $to = $today;
            $kind = 'nightly';
        }
        if ($to > $today) {
            $to = $today;
        }
        $api = new PaypalApi($key, new CurlPaypalHttp());
        $t = microtime(true);
        try {
            $r = $fees->run($caller, $api, $from, $to, $kind);
        } catch (CwException $e) {
            $cli->error("{$shop} {$kind} {$from}..{$to}: " . $e->getMessage());
            return $e->httpStatus === 422 ? Cli::USAGE : Cli::PROBLEM;
        }
        $cli->log(sprintf('%s %s %s..%s: run %d, %d windows, %d pages, %d rows (%d new, %d changed), %d decided, PayPal complete to %s, %.1f s', $shop, $kind, $from, $to,
            $r['run'], $r['windows'], $r['pages'], $r['read'], $r['new'], $r['changed'], $r['allocated'], $r['refreshed'] ?? '-', microtime(true) - $t));
        return Cli::OK;
    }, true, true));
