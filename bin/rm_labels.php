<?php

declare(strict_types=1);

/**
 * A Royal Mail account's labels (delivery cost, phase 1; docs/decisions.md DL48, DL84; CW\Profit\RoyalMailBills::importReport). Phase 1 is
 * REPORT-ONLY: labels come from Click & Drop's "Manifested Orders" report, uploaded with the From and To days it was run for; a day is covered
 * (may move money) only by such an import. The Click & Drop API pull is phase 2: --pull refuses. Nothing here calls Royal Mail or Click & Drop.
 *
 *   php bin/rm_labels.php --report=<file.xls> --account=<account number> --from=YYYY-MM-DD --to=YYYY-MM-DD
 *        the report read (the screen's upload as a server tool): labels added, empty fields filled, its From..To covered, the days planned again.
 *        Refused (nothing saved) when a row is outside From..To, or To is today or later.
 *   php bin/rm_labels.php --status
 *        per account: labels in CW, the latest day covered, the last import, the days waiting.
 *
 * Prints counts only (never a label, a reference or a tracking number). Exit codes: 0 done · 1 refused or a problem (the reason printed; the
 * other accounts done all the same) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Ops\Cli;
use CW\Profit\CarrierSettings;
use CW\Profit\RoyalMailBills;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('rm_labels', ['pull', 'report:', 'status', 'account:', 'from:', 'to:'],
    'usage: php bin/rm_labels.php --report=<file.xls> --account=<no> --from=YYYY-MM-DD --to=YYYY-MM-DD | --status',
    static function (Cli $cli, array $opts): int {
        $modes = array_values(array_filter(['pull', 'report', 'status'], static fn (string $m): bool => array_key_exists($m, $opts)));
        if (count($modes) !== 1) {
            throw new InvalidArgumentException('give one of --report, --status');
        }
        if ($modes[0] === 'pull') {
            // DL84: the API pull is phase 2; nothing of it moves money in phase 1
            $cli->error('the Click & Drop API pull is phase 2: in phase 1 labels come from the "Manifested Orders" report only (--report). Nothing was read.');
            return Cli::PROBLEM;
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
            $failed = 0;
            foreach ($accounts as $a) {
                try {
                    $last = $cli->db->one('SELECT from_day, to_day, imported_at FROM carrier_report_import WHERE account_id = ? ORDER BY id DESC LIMIT 1', [$a['id']]);
                    $cli->log(sprintf('%s (%s): %d labels; covered up to %s; last report %s; %d day(s) waiting', $a['account_no'], $a['state'],
                        (int) $cli->db->value('SELECT COUNT(*) FROM carrier_label WHERE account_id = ?', [$a['id']]),
                        (string) ($cli->db->value('SELECT MAX(day) FROM carrier_day_cover WHERE account_id = ?', [$a['id']]) ?? '-'),
                        $last === null ? '-' : $last['from_day'] . ' to ' . $last['to_day'] . ' (imported ' . $last['imported_at'] . ')',
                        count(RoyalMailBills::waitingDays($cli->db, $a['id']))));
                } catch (\PDOException $e) {
                    // one account's database error is said, never fatal to the others (the Codex check r8)
                    $cli->error("{$a['account_no']}: not read (database error " . (Db::driverCode($e) ?? 'unknown') . ')');
                    $failed++;
                }
            }
            return $failed === 0 ? Cli::OK : Cli::PROBLEM;
        }
        if ($named === null || count($accounts) !== 1) {
            throw new InvalidArgumentException('--report needs --account=<account number>');
        }
        $from = is_string($opts['from'] ?? null) ? $opts['from'] : null;
        $to = is_string($opts['to'] ?? null) ? $opts['to'] : null;
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('--report needs --from and --to: the days the report was run for in Click & Drop');
        }
        $file = (string) $opts['report'];
        if (!is_file($file)) {
            throw new InvalidArgumentException('--report must name a file');
        }
        try {
            $r = (new RoyalMailBills($cli->db))->importReport(Caller::system('rm_labels'), $accounts[0]['id'], (string) file_get_contents($file), $from, $to);
        } catch (CwException $e) {
            $cli->error($e->getMessage());
            return Cli::PROBLEM;
        }
        $cli->log(sprintf('%s: %s to %s covered (import %d): %d rows, %d labels: %d new, %d filled, %d differ (kept as read first), %d rows skipped; %s allocations changed%s',
            $accounts[0]['account_no'], $from, $to, (int) $r['import_id'], $r['rows'], $r['labels'], $r['added'], $r['filled'], $r['differs'], $r['skipped'],
            $r['replanned'] ?? '-', $r['replan_error'] === null ? '' : '; ' . $r['replan_error']));
        return $r['replan_error'] === null ? Cli::OK : Cli::PROBLEM;
    }, true, true));
