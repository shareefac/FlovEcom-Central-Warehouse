<?php

declare(strict_types=1);

/**
 * CW's opening stock and opening average cost, migrated FROM ERPNext (pack I; the owner's decision of 14 Oct 2026; docs/decisions.md
 * PI1-PI16; the steps for staff: docs/ops.md "The opening stock from ERPNext"). CW\Ops\ErpOpening.
 *
 * It reads FILES staff downloaded from ERPNext. It never connects to ERPNext and never calls its stock feed (get_product_stock_sync
 * marks pending purchase invoices Synced without sending them).
 *
 *   php bin/import_erp_opening.php --file=<Stock Balance .csv|.xlsx> --as-of=<ISO time> --doc-ref=<id> --source=<text>
 *       [--item-list=<ERPNext Item list .csv|.xlsx: ID, Product ID>] [--pending-sales=<.csv|.xlsx: Variant ID or Item Code, Qty>]
 *       [--replaces=<the earlier opening's doc_ref>] [--report=<lines.csv>] [--db=<schema>] [--admin]
 *       DRY RUN (the default): reads, plans and prints; writes NOTHING (no stock, no run row) except the --report file.
 *   ... --apply --sha256=<hex of --file> [--approved-by=<who>]
 *       books it: one `adjustment` per item at erp_opening.cw_warehouse (actor system:opening_estimate, cost source `estimate`,
 *       Idempotency-Key "<doc_ref>:<sku_id>"), and records the run with its summary (erp_opening_run). Run it only after the owner said
 *       yes to the dry run's summary. A second --apply of the same files books nothing.
 *
 * --as-of: the moment the ERPNext figures describe (when the export was taken, on the report's To Date), with an offset, e.g.
 *   2026-10-31T23:59:00+00:00. On-hand movements CW booked on an item before it make the item skipped (ERPNext's figure has them).
 * --doc-ref: the opening's name (up to 160 of A-Z a-z 0-9 : . _ + -), e.g. erp-opening:vapeandgo:2026-10-31; compared byte for byte.
 * --replaces: an earlier opening (the stores' estimate, D40a) the ERPNext figure replaces: its rows are taken back in the same row.
 * The rules (warehouses, companies, the blocked names such as "VPG 2", below zero, placeholder costs, the store, the CW warehouse,
 * whether the pending-sales file is required) are the erp_opening.* settings, printed at the top of the summary.
 * A real run takes the one opening lock the stores' estimate and its rebase take too (PI17), and each item is checked again inside its
 * booking: another opening or a count since the plan stops the run at that item (409 opening_changed; run it again to see it skipped).
 * At the store's T0, `bin/import_opening_estimate.php --rebase --units-only --estimate-doc-ref=<this doc_ref>` keeps this opening and
 * adds the open paid units (PI14).
 * Exit codes: 0 ok · 1 refused or failed · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Movements;
use CW\Ops\Cli;
use CW\Ops\ErpOpening;
use CW\Ops\ErpOpeningFiles;
use CW\Ops\ErpOpeningRules;
use CW\Ops\OpeningLock;
use CW\Output\CsvWriter;
use CW\Settings;
use CW\Stock;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1024M');

const USAGE = "usage: php bin/import_erp_opening.php --file=<stock balance> --as-of=<ISO time> --doc-ref=<id> --source=<text> "
    . "[--item-list=<file>] [--pending-sales=<file>] [--replaces=<doc_ref>] [--report=<path.csv>] [--apply --sha256=<hex> [--approved-by=<who>]]";

exit(Cli::main('import_erp_opening',
    ['file:', 'as-of:', 'doc-ref:', 'source:', 'item-list:', 'pending-sales:', 'replaces:', 'report:', 'apply', 'dry-run', 'sha256:', 'approved-by:'],
    USAGE,
    static function (Cli $cli, array $opts): int {
        $one = static function (string $k) use ($opts): ?string {
            $v = $opts[$k] ?? null;
            if (is_array($v)) {
                throw new InvalidArgumentException("give --{$k} once");
            }
            return is_string($v) && trim($v) !== '' ? $v : null;
        };
        $apply = array_key_exists('apply', $opts);
        if ($apply && array_key_exists('dry-run', $opts)) {
            throw new InvalidArgumentException('--apply and --dry-run together: choose one');
        }
        $file = $one('file') ?? throw new InvalidArgumentException('give --file=<the Stock Balance export>');
        $docRef = $one('doc-ref') ?? throw new InvalidArgumentException('give --doc-ref=<the opening\'s name>');
        $source = $one('source') ?? throw new InvalidArgumentException('give --source=<what the file is: report, company, To Date, who exported it>');
        $asOfRaw = $one('as-of') ?? throw new InvalidArgumentException('give --as-of=<the moment the ERPNext figures describe, with an offset>');
        $replaces = $one('replaces');
        foreach (['doc-ref' => $docRef, 'replaces' => $replaces] as $k => $ref) {
            if ($ref !== null && (strlen($ref) > 160 || preg_match('/^[A-Za-z0-9][A-Za-z0-9:._+-]*\z/', $ref) !== 1)) {
                throw new InvalidArgumentException("--{$k}: up to 160 of A-Z a-z 0-9 : . _ + -");
            }
        }
        try {
            $asOf = Clock::parse($asOfRaw, 'as-of');
        } catch (CwException $e) {
            throw new InvalidArgumentException($e->getMessage() . ', with an offset, e.g. 2026-10-31T23:59:00+00:00');
        }
        if ($asOf > Clock::now()) {
            throw new InvalidArgumentException('--as-of is in the future');
        }
        $itemList = $one('item-list');
        $pendingFile = $one('pending-sales');
        $reportPath = $one('report');
        foreach (['file' => $file, 'item-list' => $itemList, 'pending-sales' => $pendingFile] as $k => $f) {
            if ($f !== null && (!is_file($f) || !is_readable($f))) {
                throw new InvalidArgumentException("--{$k}: there is no readable file {$f}");
            }
        }
        $sha = (string) hash_file('sha256', $file);
        $want = $one('sha256');
        if ($apply && $want === null) {
            throw new InvalidArgumentException('--apply needs --sha256=<the hash of the archived stock file>');
        }
        if ($want !== null && !hash_equals(strtolower($want), $sha)) {
            $cli->error(basename($file) . ": sha256 {$sha} is not the expected {$want}; nothing booked");
            return Cli::PROBLEM;
        }

        // One opening lock for every opening tool's real run, from before the plan to the last booking (PI17); a dry run reads only.
        if ($apply && !OpeningLock::take($cli->db, $cli->schema)) {
            $cli->error(OpeningLock::BUSY);
            return Cli::PROBLEM;
        }
        try {
            $rules = ErpOpeningRules::fromSettings(new Settings($cli->db));
            if ($apply && $rules->requirePendingSales && $pendingFile === null) {
                $cli->error('--apply needs --pending-sales=<the units sold but not yet in ERPNext> (erp_opening.require_pending_sales is on; '
                    . 'an empty file with its header says there are none); nothing booked');
                return Cli::PROBLEM;
            }
            $stock = ErpOpeningFiles::stock($file);
            $map = $itemList === null ? null : ErpOpeningFiles::itemMap($itemList);
            $pending = $pendingFile === null ? null : ErpOpeningFiles::pendingSales($pendingFile);
            $op = new ErpOpening($cli->db, new Movements($cli->db, new Stock($cli->db)), $rules);
            $plan = $op->plan($stock, $map, $pending, $docRef, $asOf, $replaces);
        } catch (CwException $e) {
            $cli->error("refused: {$e->errorCode}: {$e->getMessage()}");
            foreach (array_slice((array) ($e->detail['items'] ?? []), 0, 20) as $i) {
                $cli->error('  ' . json_encode($i, JSON_UNESCAPED_SLASHES));
            }
            return Cli::PROBLEM;
        }
        $r = $plan['report'];
        $r['source'] = $source;
        $r['approved_by'] = $one('approved-by');
        foreach (summary($r, $apply) as $line) {
            $cli->log($line);
        }
        if ($reportPath !== null) {
            writeLines($reportPath, $plan['lines']);
            $cli->log('report: ' . count($plan['lines']) . " stock lines written to {$reportPath}");
        }
        if (!$apply) {
            $cli->log('dry run: nothing booked, nothing recorded (--apply books it, after the owner said yes)');
            return Cli::OK;
        }

        $approved = $r['approved_by'] === null ? '' : '; approved by ' . $r['approved_by'];
        $note = mb_strcut(sprintf('opening stock from ERPNext as of %s: %s; file sha256 %s; below zero %s%s%s',
            $r['as_of'], $source, substr($sha, 0, 16), $rules->belowZero,
            $pending === null ? '; sales not yet in ERPNext NOT taken off' : '; sales not yet in ERPNext taken off', $approved), 0, 240, 'UTF-8');
        $actor = ErpOpening::actor();
        $runId = $op->startRun($docRef, $asOf, ['name' => basename($file), 'sha256' => $sha, 'item_list' => $map['sha256'] ?? null,
            'pending_sales' => $pending['sha256'] ?? null], $actor);
        try {
            $done = $plan['items'] === [] ? ['booked_items' => 0, 'booked_units' => 0, 'replayed_items' => 0]
                : $op->apply(Caller::system(ErpOpening::ACTOR_JOB), $plan, $docRef, $note,
                    static fn (int $n, int $of) => $cli->log("  ... {$n} of {$of} items"));
        } catch (Throwable $e) {
            $op->finishRun($runId, 'failed', ['report' => $r, 'error' => $e instanceof CwException ? $e->errorCode : get_class($e),
                'message' => mb_substr($e->getMessage(), 0, 500)]);
            $cli->error('stopped: ' . ($e instanceof CwException ? "{$e->errorCode}: " : '') . $e->getMessage()
                . ' (whole items only; run the same command again to finish: what is booked is left alone)');
            return Cli::PROBLEM;
        }
        $op->finishRun($runId, 'done', ['report' => $r, 'booked' => $done]);
        $cli->log(sprintf('booked %d items, net %+d units%s (run %d)', $done['booked_items'], $done['booked_units'],
            $done['replayed_items'] > 0 ? ", {$done['replayed_items']} replayed" : '', $runId));
        return Cli::OK;
    }, true, true));

/**
 * The summary, in the order the owner reads it.
 *
 * @param array<string, mixed> $r ErpOpening::plan()'s report
 * @return list<string>
 */
function summary(array $r, bool $apply): array
{
    $n = static fn (int|string $v): string => is_int($v) ? number_format($v) : $v;
    $gbp = static fn (?string $v): string => $v === null ? 'n/a' : '£' . number_format((float) $v, 2);
    $f = $r['file'];
    $out = [];
    $out[] = sprintf('%s of %s (sha256 %s): %s stock lines under the header on row %d; Total line: %s; filters in the file: %s',
        $apply ? 'APPLY' : 'DRY RUN', $f['name'], substr($f['sha256'], 0, 16), $n($f['lines']), $f['header_row'], $f['total_line'],
        $f['filters'] === [] ? 'none' : json_encode($f['filters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $out[] = sprintf('  opening %s as of %s%s; source: %s%s', $r['doc_ref'], $r['as_of'], $r['replaces'] === null ? '' : ", replacing {$r['replaces']}",
        $r['source'], $r['approved_by'] === null ? '' : "; approved by {$r['approved_by']}");
    $out[] = '  rules: ' . json_encode($r['rules'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $out[] = sprintf('  read: %s lines (%s units; %s lines below zero, %s units)%s', $n($r['included']['rows']), $n($r['included']['units']),
        $n($r['included']['below_zero_rows']), $n($r['included']['below_zero_units']), $f['company_column'] ? '' : '; the file has no Company column: the warehouse list alone decided');
    foreach ($r['excluded'] as $why => $e) {
        if ($e['rows'] > 0) {
            $out[] = sprintf('  left out (%s): %s lines, %s units: %s', $why, $n($e['rows']), $n($e['units']), json_encode($e['names'], JSON_UNESCAPED_UNICODE));
        }
    }
    $out[] = sprintf('  resolved: %s lines (%s units): %s by CW\'s ERPNext link, %s through the store\'s match; %s at zero',
        $n($r['resolved']['rows']), $n($r['resolved']['units']), $n($r['resolved']['by_erp_link']), $n($r['resolved']['by_listing']), $n($r['zero_lines']));
    $out[] = sprintf('  UNRESOLVED: %s lines (%s units) %s', $n($r['unresolved']['rows']), $n($r['unresolved']['units']),
        $r['unresolved']['by_reason'] === [] ? '' : json_encode($r['unresolved']['by_reason']));
    foreach (array_slice($r['unresolved']['details'], 0, 50) as $d) {
        $out[] = '    ' . json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if ($r['unresolved']['rows'] > 50) {
        $out[] = sprintf('    ... %s more (all of them: --report)', $n($r['unresolved']['rows'] - 50));
    }
    $p = $r['pending_sales'];
    $out[] = $p['given'] === false
        ? '  sales not yet in ERPNext: NOT GIVEN: that correction is still OPEN (ERPNext\'s figures still hold units the stores already sold)'
        : sprintf('  sales not yet in ERPNext (%s): %s rows, %s units; taken off %s units (%s central) from %s items; unresolved %s rows (%s units) %s',
            $p['name'], $n($p['rows']), $n($p['units']), $n($p['subtracted']['units']), $n($p['subtracted']['central_units']), $n($p['subtracted']['items']),
            $n($p['unresolved']['rows']), $n($p['unresolved']['units']), $p['unresolved']['by_reason'] === [] ? '' : json_encode($p['unresolved']['by_reason']));
    $b = $r['below_zero'];
    $out[] = sprintf('  BELOW ZERO: %s items (%s units) after the correction; policy "%s": %s', $n($b['items']['items']), $n($b['items']['units']), $b['policy'],
        $b['policy'] === 'zero' ? "opened at zero ({$n($b['wiped_units'])} units not carried)" : "the minus carried ({$n($b['carried_units'])} units)");
    $c = $r['costs'];
    $out[] = sprintf('  COSTS: %s lines with a blank rate, %s with a placeholder rate, %s with a rate that is not a number; %s items to RE-COST '
        . '(%s units, opened without a cost); %s items costed from their other lines only', $n($c['lines_blank']), $n($c['lines_placeholder']),
        $n($c['lines_bad']), $n($c['recost']['items']), $n($c['recost']['units']), $n($c['mixed_items']));
    $out[] = sprintf('  items: %s (%s fed by more than one ERPNext item); unit kinds in ERPNext: %s', $n($r['items']['items']),
        $n($r['items']['fed_by_several_erp_items']), json_encode($r['uom'], JSON_UNESCAPED_UNICODE));
    $s = $r['skipped'];
    $out[] = sprintf('  SKIPPED items: %s (%s units) %s', $n($s['items']), $n($s['units']), $s['why'] === [] ? '' : json_encode($s['why']));
    foreach (array_slice($s['details'], 0, 30) as $d) {
        $out[] = '    ' . json_encode($d, JSON_UNESCAPED_SLASHES);
    }
    $a = $r['already_booked'];
    $out[] = sprintf('  already booked by this opening: %s items, %s units, %s; no change: %s items', $n($a['items']), $n($a['units']), $gbp($a['value']),
        $n($r['no_change']));
    $t = $r['to_book'];
    $out[] = sprintf('  TO BOOK: %s items, +%s / -%s units (net %+d), value %s (%s items without a cost)', $n($t['items']), $n($t['up']), $n($t['down']),
        $t['net'], $gbp($t['value']), $n($t['items_without_cost']));
    $o = $r['opening'];
    $out[] = sprintf('  THE OPENING when done: %s items, %s units, value %s at the ERPNext costs; ERPNext\'s own value of the resolved lines: %s',
        $n($o['items']), $n($o['units']), $gbp($o['value']), $gbp($r['erp_value']));
    return $out;
}

/** The --report file: one row per stock line and what became of it. @param list<array<string, mixed>> $lines */
function writeLines(string $path, array $lines): void
{
    $csv = new CsvWriter([['row', 'number'], ['item', 'text'], ['warehouse', 'text'], ['company', 'text'], ['balance_qty', 'text'], ['valuation_rate', 'text'],
        ['status', 'text'], ['reason', 'text'], ['cw_item', 'number'], ['units_per_item', 'number'], ['outcome', 'text']]);
    foreach ($lines as $l) {
        $csv->add([$l['row'], $l['item_code'], $l['warehouse'], $l['company'] ?? '', $l['qty'], $l['rate'], $l['status'], $l['reason'],
            $l['sku_id'] ?? null, $l['u'] ?? null, $l['outcome'] ?? '']);
    }
    if (@file_put_contents($path, $csv->output()) === false) {
        throw new RuntimeException('cannot write the report to ' . $path);
    }
}
