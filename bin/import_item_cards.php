<?php

declare(strict_types=1);

/**
 * Imports item cards from a CSV file (IM3; CW\Catalogue\ItemCardCsv, docs/decisions.md I110, I120): the same file and rules as
 * the screen (Items > Item cards > Import a CSV file), for files over the screen's 2 MiB or 2,000 changed items (up to 32 MiB /
 * 20,000 rows).
 *
 *   php bin/import_item_cards.php --file=<cards.csv> --staff=<e-mail> [--apply] [--max-changes=<n>] [--report=<path.csv>] [--db=<schema>] [--admin]
 *
 * --staff is the person the changes are made by (active, holding catalogue.edit: mapping_lead, stock_controller,
 * purchasing_manager; never admin). DRY RUN by default: checks every row and prints what would change; --apply saves. A file
 * with any refused row changes nothing (fix it and run again). An empty cell changes nothing; a flavour from a file is
 * "proposed"; a file never confirms a card. --report writes every refused row and every change as CSV. --max-changes (default
 * 5,000, at most 20,000): a file that would change more cards is refused; the cards a real run changes stay locked until it ends
 * (about 14 ms a card), so a bigger job goes in parts or at a quiet time. The run records via=cli and the OS user who ran it.
 *
 * Exit codes: 0 done (a dry run with no problem, or an applied file) · 1 the file has refused rows, or was refused · 2 usage
 * (or --staff cannot change item cards) · 3 cannot run.
 */

use CW\Auth\Permissions;
use CW\Caller;
use CW\Catalogue\ItemCardCsv;
use CW\CwException;
use CW\Ops\Cli;
use CW\Staff\StaffRoles;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('import_item_cards', ['file:', 'staff:', 'apply', 'report:', 'max-changes:'],
    'usage: php bin/import_item_cards.php --file=<cards.csv> --staff=<e-mail> [--apply] [--max-changes=<n>] [--report=<path.csv>]',
    static function (Cli $cli, array $opts): int {
        $file = $opts['file'] ?? null;
        $email = $opts['staff'] ?? null;
        $report = $opts['report'] ?? null;
        if (!is_string($file) || $file === '' || !is_file($file) || !is_readable($file)) {
            throw new InvalidArgumentException('give --file=<a readable CSV file>');
        }
        if (!is_string($email) || $email === '') {
            throw new InvalidArgumentException('give --staff=<e-mail of the person the changes are made by>');
        }
        if ($report !== null && (!is_string($report) || $report === '')) {
            throw new InvalidArgumentException('give --report=<path>');
        }
        $staffId = $cli->db->value('SELECT id FROM staff_user WHERE email = ?', [strtolower($email)]);
        try {
            $roles = $staffId === null ? null : StaffRoles::active($cli->db, (int) $staffId);
        } catch (CwException) {
            $roles = null;
        }
        if ($roles === null || in_array('admin', $roles, true) || !Permissions::can($roles, 'catalogue.edit')) {
            throw new InvalidArgumentException('--staff must name an active person who may change item cards (mapping_lead, stock_controller, purchasing_manager; not admin)');
        }
        $apply = array_key_exists('apply', $opts);
        $maxChanges = Cli::intOpt($opts, 'max-changes', ItemCardCsv::CLI_MAX_CHANGES, 1, ItemCardCsv::CLI_MAX_ROWS);
        $osUser = function_exists('posix_geteuid') ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user()) : get_current_user();
        try {
            $r = (new ItemCardCsv($cli->db))->import(Caller::staff((int) $staffId), $file, basename($file), $apply, ItemCardCsv::CLI_MAX_BYTES, ItemCardCsv::CLI_MAX_ROWS,
                $maxChanges, ['via' => 'cli', 'os_user' => mb_substr($osUser, 0, 64)]);
        } catch (CwException $e) {
            $cli->error(basename($file) . " refused: {$e->errorCode}: {$e->getMessage()}");
            return Cli::PROBLEM;
        }
        $cli->log(sprintf('%s%s run %d: rows=%d %s=%d unchanged=%d refused=%d%s', $apply ? '' : 'DRY RUN (nothing written) ', basename($file), $r['run_id'], $r['rows'],
            $r['applied'] ? 'changed' : 'would_change', $r['changed'], $r['unchanged'], $r['errors_total'],
            $apply && !$r['applied'] ? ' NOTHING SAVED: correct the refused rows and run again' : ''));
        foreach ($r['errors'] as $e) {
            $cli->log("  row {$e['row']}" . ($e['code'] !== null ? " ({$e['code']})" : '') . ($e['column'] !== null ? ", {$e['column']}" : '') . ": {$e['message']}");
        }
        if ($r['errors_total'] > count($r['errors'])) {
            $cli->log('  ... and ' . ($r['errors_total'] - count($r['errors'])) . ' more');
        }
        if (is_string($report)) {
            if (file_put_contents($report, ItemCardCsv::reportCsv($r)) === false) {
                $cli->error("cannot write the report to {$report}");
                return Cli::CANNOT_RUN;
            }
            $cli->log("report: {$report}");
        }
        return $r['errors_total'] > 0 ? Cli::PROBLEM : Cli::OK;
    }));
