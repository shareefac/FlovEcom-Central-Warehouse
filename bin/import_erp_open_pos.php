<?php

declare(strict_types=1);

/**
 * Brings ERPNext's open purchase orders into CW once, from a CSV exported from the RESTORED BACKUP COPY of ERPNext (owner
 * decision 4; CW never connects to ERPNext): CW\PurchaseOrders\ErpOpenPoImport, docs/decisions.md I56, the format in
 * docs/ops.md ("ERPNext open POs").
 *
 *   php bin/import_erp_open_pos.php --file=<open_pos.csv> --staff=<buyer e-mail> [--vpg-codes=<vapeandgo listings .jsonl.gz>]
 *       [--dry-run] [--report=<path>] [--db=<schema>] [--admin]
 *
 * One CW PO per erp_po holding the outstanding packs, created as --staff (an active person who may post POs: buyer,
 * purchasing_manager), approved through the document base (above the value limit it waits for a reviewer) and marked sent
 * (via imported). A PO with an unresolved line or a supplier that is not active is refused whole (failed); one already in
 * CW (external_ref "ERPNext <erp_po>") or with nothing outstanding is skipped. --dry-run checks everything and writes only
 * the import_run row. --report writes a CSV: row, key (erp_po), status, reason.
 *
 * Exit codes: 0 done (also "already imported") · 1 a PO failed or the file was refused · 2 usage (or --staff may not post
 * POs) · 3 cannot run (database, schema).
 */

use CW\Auth\Permissions;
use CW\Caller;
use CW\CwException;
use CW\Ops\Cli;
use CW\PurchaseOrders\ErpOpenPoImport;
use CW\Staff\StaffRoles;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('import_erp_open_pos', ['file:', 'staff:', 'vpg-codes:', 'dry-run', 'report:'],
    'usage: php bin/import_erp_open_pos.php --file=<open_pos.csv> --staff=<buyer e-mail> [--vpg-codes=<listings .jsonl.gz>] [--dry-run] [--report=<path>]',
    static function (Cli $cli, array $opts): int {
        $one = static function (string $k) use ($opts): ?string {
            $v = $opts[$k] ?? null;
            if (is_array($v)) {
                throw new InvalidArgumentException("give --{$k} once");
            }
            return is_string($v) && $v !== '' ? $v : null;
        };
        $file = $one('file') ?? throw new InvalidArgumentException('give --file=<open_pos.csv>');
        $email = $one('staff') ?? throw new InvalidArgumentException('give --staff=<e-mail of the buyer the orders are created by>');
        $vpg = $one('vpg-codes');
        $report = $one('report');
        foreach (['file' => $file, 'vpg-codes' => $vpg] as $k => $f) {
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
        if ($roles === null || in_array('admin', $roles, true) || !Permissions::can($roles, 'doc.PO.post')) {
            throw new InvalidArgumentException('--staff must name an active person who may post purchase orders (buyer, purchasing_manager)');
        }
        $dry = array_key_exists('dry-run', $opts);
        try {
            $r = (new ErpOpenPoImport($cli->db))->import(Caller::staff((int) $staffId), $file, $vpg, $dry);
        } catch (CwException $e) {
            $cli->error(basename($file) . " refused: {$e->errorCode}: {$e->getMessage()}");
            return Cli::PROBLEM;
        }
        if ($r['already'] !== null) {
            $cli->log(basename($file) . " already imported (run {$r['already']})");
            return Cli::OK;
        }
        $c = $r['counts'];
        $cli->log(sprintf('%s run %d%s: rows=%d orders=%d created=%d skipped=%d failed=%d', basename($file), $r['run_id'], $dry ? ' (dry run, nothing kept)' : '',
            $c['rows_read'], $c['pos'], $c['created'], $c['skipped'], $c['failed']));
        foreach ($r['rows'] as $row) {
            $cli->log("  {$row['key']} (row {$row['row']}): {$row['status']}: {$row['reason']}");
        }
        if ($report !== null) {
            ErpOpenPoImport::writeReport($report, $r['rows']);
            $cli->log('report: ' . count($r['rows']) . " rows written to {$report}");
        }
        return $c['failed'] > 0 ? Cli::PROBLEM : Cli::OK;
    }));
