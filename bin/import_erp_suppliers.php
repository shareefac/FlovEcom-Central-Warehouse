<?php

declare(strict_types=1);

/**
 * Seeds CW's suppliers and supplier items from CSV files exported from the RESTORED BACKUP COPY of ERPNext (owner decision 4;
 * CW never connects to ERPNext): CW\Suppliers\ErpSeedImport, docs/decisions.md I45, the formats in docs/ops.md
 * ("ERPNext supplier seed").
 *
 *   php bin/import_erp_suppliers.php [--suppliers=<suppliers.csv>] [--items=<supplier_items.csv>] --staff=<buyer e-mail>
 *       [--vpg-codes=<vapeandgo listings export .jsonl.gz>] [--update-blank] [--request-activation] [--dry-run] [--report=<path>]
 *       [--db=<schema>] [--admin]
 *
 * At least one of --suppliers / --items (suppliers first when both). --staff is the buyer the rows are created by (active,
 * suppliers.manage): that person can then never approve them. New suppliers are drafts; an existing ERPNext name is
 * skipped, or with --update-blank its empty fields are filled while it is draft or inactive (an active supplier is never
 * changed: the differences are reported). --request-activation asks a second person to activate every supplier of the
 * file that is complete. --vpg-codes resolves vpg_code item rows. --dry-run checks everything and writes only the
 * import_run rows. A file already imported is reported "already imported (run N)" and skipped. --report writes a CSV of
 * every row: row, key, status (created / updated / skipped / failed), reason.
 *
 * Exit codes: 0 done (also "already imported") · 1 a row failed or a file was refused · 2 usage (or --staff cannot manage
 * suppliers) · 3 cannot run (database, schema).
 */

use CW\Auth\Permissions;
use CW\Caller;
use CW\CwException;
use CW\Ops\Cli;
use CW\Staff\StaffRoles;
use CW\Suppliers\ErpSeedImport;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('import_erp_suppliers', ['suppliers:', 'items:', 'staff:', 'vpg-codes:', 'update-blank', 'request-activation', 'dry-run', 'report:'],
    'usage: php bin/import_erp_suppliers.php [--suppliers=<suppliers.csv>] [--items=<supplier_items.csv>] --staff=<buyer e-mail> '
    . '[--vpg-codes=<listings .jsonl.gz>] [--update-blank] [--request-activation] [--dry-run] [--report=<path>]',
    static function (Cli $cli, array $opts): int {
        $one = static function (string $k) use ($opts): ?string {
            $v = $opts[$k] ?? null;
            if (is_array($v)) {
                throw new InvalidArgumentException("give --{$k} once");
            }
            return is_string($v) && $v !== '' ? $v : null;
        };
        $suppliers = $one('suppliers');
        $items = $one('items');
        $email = $one('staff');
        $vpg = $one('vpg-codes');
        $report = $one('report');
        if ($suppliers === null && $items === null) {
            throw new InvalidArgumentException('give --suppliers=<file> and/or --items=<file>');
        }
        if ($email === null) {
            throw new InvalidArgumentException('give --staff=<e-mail of the buyer the rows are created by>');
        }
        foreach (['suppliers' => $suppliers, 'items' => $items, 'vpg-codes' => $vpg] as $k => $f) {
            if ($f !== null && (!is_file($f) || !is_readable($f))) {
                throw new InvalidArgumentException("--{$k}: there is no readable file {$f}");
            }
        }
        $staffId = $cli->db->value('SELECT id FROM staff_user WHERE email = ?', [$email]);
        try {
            $roles = $staffId === null ? null : StaffRoles::active($cli->db, (int) $staffId);
        } catch (CwException) {
            $roles = null;
        }
        if ($roles === null || !Permissions::can($roles, 'suppliers.manage')) {
            throw new InvalidArgumentException('--staff must name an active person who may manage suppliers (buyer, purchasing_manager)');
        }
        $staff = Caller::staff((int) $staffId);
        $dry = array_key_exists('dry-run', $opts);
        $import = new ErpSeedImport($cli->db);
        $all = [];
        $failed = false;
        foreach (['suppliers' => $suppliers, 'items' => $items] as $what => $file) {
            if ($file === null) {
                continue;
            }
            try {
                $r = $what === 'suppliers'
                    ? $import->suppliers($staff, $file, array_key_exists('update-blank', $opts), array_key_exists('request-activation', $opts), $dry)
                    : $import->items($staff, $file, $vpg, $dry);
            } catch (CwException $e) {
                $cli->error("{$what}: " . basename($file) . " refused: {$e->errorCode}: {$e->getMessage()}");
                $failed = true;
                continue;
            }
            if ($r['already'] !== null) {
                $cli->log("{$what}: " . basename($file) . " already imported (run {$r['already']})");
                continue;
            }
            $c = $r['counts'];
            $cli->log(sprintf('%s: %s run %d%s: rows=%d created=%d updated=%d skipped=%d failed=%d', $what, basename($file), $r['run_id'], $dry ? ' (dry run, nothing kept)' : '',
                $c['rows_read'], $c['created'], $c['updated'], $c['skipped'], $c['failed']));
            foreach ($r['rows'] as $row) {
                if ($row['status'] === 'failed') {
                    $cli->log("  row {$row['row']} {$row['key']}: failed: {$row['reason']}");
                    $failed = true;
                }
            }
            array_push($all, ...$r['rows']);
        }
        if ($report !== null) {
            ErpSeedImport::writeReport($report, $all);
            $cli->log('report: ' . count($all) . " rows written to {$report}");
        }
        return $failed ? Cli::PROBLEM : Cli::OK;
    }));
