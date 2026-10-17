<?php

declare(strict_types=1);

/**
 * The rollback file (pack RB, IM15; docs/decisions.md RB1-RB19, docs/ops.md "The way back to ERPNext: the rollback file"): what CW
 * booked since the switch that ERPNext does not have, in ERPNext's Data Import layout, and the lists ERPNext needs, so that ERPNext can
 * be made level if the switch is rolled back. CW\Ops\RollbackExport has the rules.
 *
 * READ ONLY: everything is read in one consistent snapshot, in a READ ONLY transaction; it never writes to the database and never
 * connects to ERPNext. It makes a NEW directory (one that exists is refused: nothing is ever overwritten), 0700, each file 0600, and
 * writes manifest.json last (the cut-off, the settings used, each file's rows, bytes and sha256, the counts). A run stopped halfway
 * leaves a directory without manifest.json: use a new one.
 *
 *   php bin/rollback_export.php --out=<new directory> [--since=<UTC time>] [--item-list=<ERPNext item list .csv>] [--db=<schema>] [--admin]
 *
 * --since: rows booked at or after it, in UTC (2026-11-01T22:00:00Z, or 2026-11-01 22:00:00); without it, the as-of moment of the
 *   EARLIEST finished ERPNext opening (bin/import_erp_opening.php; RB13): a later run never moves the cut-off.
 * --item-list: the ERPNext item list (ID or Item Code, and Product ID) the opening used; without it only CW's own ERPNext links
 *   (sku_erp_item) name ERPNext items, and every other item is listed as having none.
 * Exit codes: 0 ok · 2 usage · 3 cannot run (connection, settings, a file that cannot be written).
 */

use CW\Clock;
use CW\Ops\Cli;
use CW\Ops\ErpOpeningFiles;
use CW\Ops\RollbackExport;

require dirname(__DIR__) . '/vendor/autoload.php';

const USAGE = 'usage: php bin/rollback_export.php --out=<new directory> [--since=<UTC time>] [--item-list=<ERPNext item list .csv>]';

exit(Cli::main('rollback_export', ['out:', 'since:', 'item-list:'], USAGE, static function (Cli $cli, array $opts): int {
    $out = (string) ($opts['out'] ?? '');
    if ($out === '') {
        throw new InvalidArgumentException("give --out=<a new directory>\n" . USAGE);
    }
    $out = rtrim($out, '/');
    if (file_exists($out) || is_link($out)) {
        throw new InvalidArgumentException("{$out} exists already: give a new directory (nothing is overwritten)");
    }
    if (!is_dir(dirname($out))) {
        throw new InvalidArgumentException('the directory ' . dirname($out) . ' does not exist');
    }
    $since = null;
    if (isset($opts['since'])) {
        $raw = (string) $opts['since'];
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?Z?$/D', $raw, $m) !== 1) {
            throw new InvalidArgumentException("--since is a UTC time like 2026-11-01T22:00:00Z\n" . USAGE);
        }
        $text = "{$m[1]} {$m[2]}." . str_pad($m[3] ?? '', 6, '0');
        $t = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $text, Clock::utc());
        if ($t === false || $t->format('Y-m-d H:i:s.u') !== $text) {
            throw new InvalidArgumentException("--since is not a real time: {$raw}");
        }
        $since = Clock::db($t);
    }
    $itemMap = null;
    if (isset($opts['item-list'])) {
        $path = (string) $opts['item-list'];
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("cannot read the item list {$path}");
        }
        $itemMap = ErpOpeningFiles::itemMap($path);
    }
    // The settings, the cut-off and the stock: all of one moment (RB17).
    $result = RollbackExport::run($cli->db, $itemMap, $since);

    umask(0077);
    if (!mkdir($out, 0700) || !chmod($out, 0700)) {
        throw new RuntimeException("cannot make the directory {$out}");
    }
    $write = static function (string $name, string $content) use ($out): array {
        $path = $out . '/' . $name;
        $fh = fopen($path, 'xb');
        if ($fh === false || !chmod($path, 0600) || fwrite($fh, $content) !== strlen($content) || !fflush($fh) || !fclose($fh)) {
            throw new RuntimeException("cannot write {$path}");
        }
        return ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
    };
    $files = [];
    foreach ($result['files'] as $name => $content) {
        $files[$name] = ['rows' => $result['rows'][$name]] + $write($name, $content);
    }
    $cut = $result['cut'];
    $manifest = [
        'format' => RollbackExport::FORMAT,
        'made_at' => Clock::iso($result['made_at']),
        'schema' => $cli->schema,
        'since' => Clock::iso($cut['since']),
        'since_from' => $cut['from'],
        'opening_runs' => array_map(static fn (array $r): array => ['id' => $r['id'], 'doc_ref' => $r['doc_ref'], 'as_of' => Clock::iso($r['as_of'])], $cut['runs']),
        'store' => $result['settings']['store'],
        'cw_warehouses' => $result['settings']['cw_warehouses'],
        'erp_warehouse' => $result['settings']['erp_warehouse'],
        'erp_company' => $result['settings']['erp_company'],
        'item_list' => $itemMap === null ? null : ['file' => $itemMap['file'], 'sha256' => $itemMap['sha256'], 'rows' => $itemMap['rows']],
        'files' => $files,
        'counts' => $result['counts'],
        // How big the job is getting (RB22): the nightly runs of the window show it.
        'peak_memory_bytes' => memory_get_peak_usage(true),
    ];
    $write('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

    $c = $result['counts'];
    $cli->log(sprintf('since %s (%s): %d rows read, %d carried; %d receipts, %d issues, %d sales invoices (%d receipt lines without a rate); not carried: %s',
        Clock::iso($cut['since']), $cut['from'], $c['rows_read'], $c['rows_carried'], $c['entries']['material_receipt'], $c['entries']['material_issue'],
        $c['entries']['sales_invoice'], $c['entries']['receipt_lines_without_rate'],
        implode(', ', array_map(static fn (string $k, int $n): string => "{$k} {$n}", array_keys(array_filter($c['not_carried'])), array_filter($c['not_carried']))) ?: 'none'));
    if (count($cut['runs']) > 1 && $since === null) {
        $cli->log(count($cut['runs']) . ' ERPNext openings finished: the cut-off is the earliest; manifest.json names them all');
    }
    if ($c['to_set_by_hand'] > 0) {
        $cli->log($c['to_set_by_hand'] . ' items entries cannot make level are in to_set_by_hand.csv: set them in ERPNext by hand after the import');
    }
    if ($c['erp_corrections_not_taken'] !== []) {
        $cli->log(count($c['erp_corrections_not_taken']) . ' ERPNext corrections CW did not take: ERPNext\'s figure of those items is not CW\'s (manifest.json)');
    }
    $cli->log("written to {$out} (manifest.json last)");
    return Cli::OK;
}, true, true));
