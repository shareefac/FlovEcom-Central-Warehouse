<?php

declare(strict_types=1);

/**
 * A Royal Mail account's labels (delivery cost, phase 1; docs/decisions.md DL48, DL49; CW\Profit\RoyalMailBills::pull / importReport). Run ON
 * THE CW SERVER as root: the Click & Drop key of each account is read from /etc/cw/carrier_keys.env (root only; CarrierKeys), by the NAME its
 * account row holds; the key is never printed, logged or stored. Only GET calls (ClickAndDropClient): nothing is ever written to Click & Drop.
 *
 *   php bin/rm_labels.php --pull [--account=<account number>] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]
 *        every active Royal Mail account with a key (or the one named): the orders from the account's checkpoint less 3 days (a first pull: 14
 *        days back) to yesterday, then the days of its bills planned again. Nightly (03:20, before the bills' nightly at 03:35).
 *   php bin/rm_labels.php --report=<file.xls> --account=<account number>
 *        Click & Drop's "Manifested Orders" report read (the screen's upload as a server tool): labels added, empty fields filled.
 *   php bin/rm_labels.php --status
 *        per account: labels in CW, read up to which day, the last pull and its problem.
 *
 * Prints counts only (never a label, a reference, a tracking number or an answer of the API). Exit codes: 0 done · 1 a pull or the file
 * refused (the reason printed) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Ops\Cli;
use CW\Profit\CarrierKeys;
use CW\Profit\CarrierSettings;
use CW\Profit\ClickAndDropClient;
use CW\Profit\CurlHttpGet;
use CW\Profit\RoyalMailBills;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('rm_labels', ['pull', 'report:', 'status', 'account:', 'from:', 'to:'],
    'usage: php bin/rm_labels.php --pull [--account=<no>] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] | --report=<file.xls> --account=<no> | --status',
    static function (Cli $cli, array $opts): int {
        $modes = array_values(array_filter(['pull', 'report', 'status'], static fn (string $m): bool => array_key_exists($m, $opts)));
        if (count($modes) !== 1) {
            throw new InvalidArgumentException('give one of --pull, --report, --status');
        }
        $accounts = CarrierSettings::pooledAccounts($cli->db);
        $named = is_string($opts['account'] ?? null) ? $opts['account'] : null;
        if ($named !== null) {
            $accounts = array_values(array_filter($accounts, static fn (array $a): bool => $a['account_no'] === $named));
            if ($accounts === []) {
                throw new InvalidArgumentException('no Royal Mail account of that number in CW');
            }
        }
        if ($modes[0] === 'status') {
            foreach ($accounts as $a) {
                $p = $cli->db->one('SELECT read_to, last_ok_at, last_error FROM carrier_label_pull WHERE account_id = ?', [$a['id']]) ?? [];
                $cli->log(sprintf('%s (%s): %d labels; read up to %s; last read %s%s', $a['account_no'], $a['state'],
                    (int) $cli->db->value('SELECT COUNT(*) FROM carrier_label WHERE account_id = ?', [$a['id']]), $p['read_to'] ?? '-', $p['last_ok_at'] ?? '-',
                    ($p['last_error'] ?? null) === null ? '' : '; last problem: ' . $p['last_error']));
            }
            return Cli::OK;
        }
        $rm = new RoyalMailBills($cli->db);
        $caller = Caller::system('rm_labels');
        if ($modes[0] === 'report') {
            if ($named === null || count($accounts) !== 1) {
                throw new InvalidArgumentException('--report needs --account=<account number>');
            }
            $file = (string) $opts['report'];
            if (!is_file($file)) {
                throw new InvalidArgumentException('--report must name a file');
            }
            try {
                $r = $rm->importReport($caller, $accounts[0]['id'], (string) file_get_contents($file));
            } catch (CwException $e) {
                $cli->error($e->getMessage());
                return Cli::PROBLEM;
            }
            $cli->log(sprintf('%s: %d rows, %d labels: %d new, %d filled, %d differ (kept as read first), %d rows skipped; %s allocations changed%s',
                $accounts[0]['account_no'], $r['rows'], $r['labels'], $r['added'], $r['filled'], $r['differs'], $r['skipped'], $r['replanned'] ?? '-',
                $r['replan_error'] === null ? '' : '; ' . $r['replan_error']));
            return Cli::OK;
        }
        $failed = 0;
        foreach ($accounts as $a) {
            if ($a['state'] !== 'active' && $named === null) {
                continue; // an account off (Vape Big's until its first bill ties) is read only when named
            }
            if ($a['key_name'] === null) {
                $cli->log("{$a['account_no']}: no Click & Drop key named: not read");
                continue;
            }
            try {
                $client = new ClickAndDropClient(new CurlHttpGet(), CarrierKeys::key($a['key_name']));
                $r = $rm->pull($caller, $a['id'], $client, is_string($opts['from'] ?? null) ? $opts['from'] : null, is_string($opts['to'] ?? null) ? $opts['to'] : null);
            } catch (CwException $e) {
                $cli->error("{$a['account_no']}: not read: " . $e->getMessage());
                $failed++;
                continue;
            }
            $cli->log(sprintf('%s: %s to %s: %d orders, %d labels: %d new, %d filled, %d differ; %s allocations changed%s', $a['account_no'], $r['from'], $r['to'],
                $r['orders'], $r['labels'], $r['added'], $r['filled'], $r['differs'], $r['replanned'] ?? '-', $r['replan_error'] === null ? '' : '; ' . $r['replan_error']));
            if ($r['unread'] !== [] || $r['replan_error'] !== null) {
                // a day not read whole, or a plan that could not run: a problem said as one (exit 1), the other accounts read all the same (the
                // Codex check r7, M4; DL83)
                $cli->error("{$a['account_no']}: " . count($r['unread']) . ' day(s) not read whole (read again next time)' . ($r['replan_error'] === null ? '' : '; the plan did not run'));
                $failed++;
            }
        }
        return $failed === 0 ? Cli::OK : Cli::PROBLEM;
    }, true, true));
