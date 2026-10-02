<?php

declare(strict_types=1);

/**
 * Books a site's opening on_hand ESTIMATE (plan §8.1, D40, D40a) from a CSV of that site's own
 * stock figures as of one moment: CW\Ops\OpeningEstimate (linked listings only, negatives as 0,
 * x units_per_item, counted items and items with an earlier opening or with on_hand movements
 * before --as-of left alone, one `adjustment` per item, resumable and refusing a different file
 * under the same --doc-ref).
 *
 *   php bin/import_opening_estimate.php --csv=<file> --as-of=<ISO time> --doc-ref=<id> --source=<text>
 *       --sha256=<hex> [--approved-by=<who>] [--channel=vapeandgo] [--warehouse=MAIN] [--dry-run]
 *       [--db=<schema>] [--admin]
 *
 * --rebase: the REBASE at the site's T0 (D40a "Rule at a site's T0", D40b; CW\Ops\OpeningRebase), after
 * the channel's final opening_orders batch was accepted. The CSV is the site stock read in the SAME
 * snapshot as the T0 watermarks (the connector's state_dir/t0_site_stock_<YYYYMMDDTHHMMSSZ>.csv):
 *
 *   php bin/import_opening_estimate.php --rebase --csv=<t0 file> --sha256=<hex> --source=<text>
 *       --estimate-doc-ref=<the estimate's doc_ref>|none [--doc-ref=<new id>] --as-of=<T0> (optional only with --dry-run)
 *       [--approved-by=<who>] [--channel=vapeandgo] [--warehouse=<code>] [--dry-run] [--db=<schema>] [--admin]
 *
 *   Per item: target = max(site stock at T0, 0) x u over its mapped listings + the units the opening committed
 *   (the channel's origin=opening reservation units with a commit row) x u; it books target - (the item's rows
 *   under --estimate-doc-ref) as one signed `adjustment` under --doc-ref (default
 *   opening-rebase:<channel>:<T0 YYYYMMDDTHHMMSSZ>). Counted items, items with any other on_hand history and
 *   items no longer linked on the channel are skipped and listed with the delta they would get.
 *   T0 is the one CW recorded with the opening batches: --as-of and a file named t0_site_stock_<ts>.csv must
 *   match it; a real run needs --as-of (a dry run without it says so). --warehouse defaults to the channel's
 *   sellable warehouse.
 *
 * The CSV: a header whose first column names the variant ("...variant...") and whose second names
 * the figure ("...qty..." or "...stock..."), then one row per variant: digits, integer (may be
 * negative). Further columns (the connector's T0 file has prodt_stock_mode, prodt_allow_backorders)
 * are ignored. --as-of is the moment the figures describe (with an offset, e.g. 2026-10-01T00:00+01:00).
 * --sha256 is required for a real run and must match the file. --doc-ref is compared byte for byte;
 * any other spelling of an existing opening (even in other letter case) counts as an earlier opening.
 * The as-of time (T0 for a rebase), the file hash, --source and --approved-by go into every ledger row's
 * note. The actor is system:opening_estimate.
 * Exit codes: 0 ok · 1 refused or failed · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Movements;
use CW\Ops\Cli;
use CW\Ops\OpeningEstimate;
use CW\Ops\OpeningRebase;
use CW\Stock;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '512M');

const USAGE = "usage: php bin/import_opening_estimate.php --csv=<file> --as-of=<ISO time> --doc-ref=<id> --source=<text> --sha256=<hex> "
    . "[--approved-by=<who>] [--channel=vapeandgo] [--warehouse=MAIN] [--dry-run]\n"
    . "       php bin/import_opening_estimate.php --rebase --csv=<T0 file> --sha256=<hex> --source=<text> --estimate-doc-ref=<id>|none "
    . '--as-of=<T0> [--doc-ref=<id>] [--approved-by=<who>] [--channel=vapeandgo] [--warehouse=<code>] [--dry-run]';

exit(Cli::main('import_opening_estimate',
    ['csv:', 'as-of:', 'doc-ref:', 'source:', 'sha256:', 'approved-by:', 'channel:', 'warehouse:', 'dry-run', 'rebase', 'estimate-doc-ref:'],
    USAGE,
    static function (Cli $cli, array $opts): int {
        foreach (['csv', 'as-of', 'doc-ref', 'source', 'sha256', 'approved-by', 'channel', 'warehouse', 'estimate-doc-ref'] as $k) {
            if (is_array($opts[$k] ?? null)) {
                throw new InvalidArgumentException("--{$k} given more than once");
            }
        }
        $rebase = array_key_exists('rebase', $opts);
        $file = $opts['csv'] ?? null;
        $docRef = $opts['doc-ref'] ?? null;
        $source = $opts['source'] ?? null;
        $asOfRaw = $opts['as-of'] ?? null;
        $estimateRef = $opts['estimate-doc-ref'] ?? null;
        $dry = array_key_exists('dry-run', $opts);
        $checkRef = static function (string $ref, string $opt): void {
            if (strlen($ref) > 160 || preg_match('/^[A-Za-z0-9][A-Za-z0-9:._+-]*\z/', $ref) !== 1) {
                throw new InvalidArgumentException("--{$opt}: up to 160 of A-Z a-z 0-9 : . _ + -");
            }
        };
        if ($rebase) {
            if (!is_string($file) || !is_string($source) || trim($source) === '' || !is_string($estimateRef)) {
                throw new InvalidArgumentException('--rebase needs --csv, --source and --estimate-doc-ref (the estimate\'s doc_ref, or none)');
            }
            $estimateRef = strtolower($estimateRef) === 'none' ? null : $estimateRef;
            if ($estimateRef !== null) {
                $checkRef($estimateRef, 'estimate-doc-ref');
            }
            if (is_string($docRef)) {
                $checkRef($docRef, 'doc-ref');
            }
        } else {
            if ($estimateRef !== null) {
                throw new InvalidArgumentException('--estimate-doc-ref belongs to --rebase');
            }
            if (!is_string($file) || !is_string($docRef) || !is_string($source) || !is_string($asOfRaw) || trim($source) === '') {
                throw new InvalidArgumentException('--csv, --as-of, --doc-ref and --source are required');
            }
            $checkRef($docRef, 'doc-ref');
        }
        $asOf = is_string($asOfRaw) ? Clock::parse($asOfRaw, 'as-of') : null;
        if ($asOf !== null && $asOf > Clock::now()) {
            throw new InvalidArgumentException('--as-of is in the future');
        }
        if (!is_readable($file)) {
            throw new InvalidArgumentException("cannot read {$file}");
        }
        $have = hash_file('sha256', $file);
        $want = $opts['sha256'] ?? null;
        if (!is_string($want) && !$dry) {
            throw new InvalidArgumentException('--sha256 is required for a real run (the hash of the archived file)');
        }
        if (is_string($want) && !hash_equals(strtolower($want), $have)) {
            $cli->error("{$file}: sha256 {$have} is not the expected {$want}; nothing booked");
            return 1;
        }
        $channel = is_string($opts['channel'] ?? null) ? $opts['channel'] : 'vapeandgo';
        $warehouse = is_string($opts['warehouse'] ?? null) ? $opts['warehouse'] : null;
        $approved = is_string($opts['approved-by'] ?? null) ? '; approved by ' . $opts['approved-by'] : '';

        $rows = (static function () use ($file): Generator {
            $fh = fopen($file, 'r');
            $header = fgetcsv($fh, null, ',', '"', '');
            if (is_array($header) && isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            }
            if (!is_array($header) || count($header) < 2 || stripos((string) $header[0], 'variant') === false
                || preg_match('/qty|stock/i', (string) $header[1]) !== 1) {
                throw new InvalidArgumentException('the CSV header must start with the variant id ("...variant...") then the figure ("...qty..." or "...stock...")');
            }
            $width = count($header);
            $line = 1;
            while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
                $line++;
                if ($r === [null] || $r === ['']) {
                    continue;
                }
                $v = trim((string) ($r[0] ?? ''));
                $q = trim((string) ($r[1] ?? ''));
                if (count($r) !== $width || preg_match('/^\d{1,18}$/', $v) !== 1 || preg_match('/^-?\d{1,9}$/', $q) !== 1) {
                    throw new InvalidArgumentException("line {$line}: needs {$width} columns, a numeric variant id and an integer figure first");
                }
                yield [$v, (int) $q];
            }
            fclose($fh);
        })();

        $moves = new Movements($cli->db, new Stock($cli->db));
        $op = new OpeningEstimate($cli->db, $moves);
        if ($rebase) {
            return rebase($cli, $op, $rows, $file, $have, $channel, $warehouse, $docRef, $estimateRef, $asOf, (string) $source, $approved, $dry);
        }

        $note = mb_strcut(sprintf('opening estimate (not a count) as of %s: %s; file sha256 %s; negatives as 0; excludes open paid units%s',
            $asOf->format('Y-m-d H:i:s') . ' UTC', $source, substr($have, 0, 16), $approved), 0, 255, 'UTF-8');
        $plan = $op->plan($channel, $rows, $docRef, $asOf);
        $r = $plan['report'];
        $h = $r['skipped']['has_stock_history'];
        $cli->log(sprintf('%s: %d rows (sha256 %s) as of %s; %d with stock > 0 (%d units)', basename($file), $r['rows'], substr($have, 0, 16),
            $asOf->format('Y-m-d\TH:i:s\Z'), $r['positive_rows'], $r['positive_units']));
        $cli->log(sprintf('  skipped: zero/negative %d rows (%d units) · no listing %d rows (%d units) · item has stock history %d items (%d units)%s',
            $r['skipped']['zero_or_negative']['rows'], $r['skipped']['zero_or_negative']['units'],
            $r['skipped']['no_listing']['rows'], $r['skipped']['no_listing']['units'], $h['items'], $h['units'],
            $h['why'] ? ' ' . json_encode($h['why']) : ''));
        foreach ($r['not_linked_by_status'] as $status => $s) {
            $cli->log(sprintf('  not linked (%s): %d rows (%d units)', $status, $s['rows'], $s['units']));
        }
        if ($r['already_booked']['items'] > 0) {
            $cli->log(sprintf('  already booked by this opening: %d items (%d units), same figures', $r['already_booked']['items'], $r['already_booked']['units']));
        }
        $warehouse ??= 'MAIN';
        $cli->log(sprintf('  to book: %d items, %d units (%d items fed by several listings) as %s %s at %s',
            $r['items'], $r['units'], $r['items_from_several_listings'], OpeningEstimate::MOVEMENT_TYPE, $docRef, $warehouse));
        if ($dry) {
            $cli->log('dry run: nothing booked');
            return 0;
        }
        if ($plan['lines'] === []) {
            $cli->log('nothing to book');
            return 0;
        }
        $done = $op->apply(Caller::system(OpeningEstimate::ACTOR_JOB), $plan['lines'], $docRef, $note, $warehouse,
            static fn (int $n, int $of) => $cli->log("  ... {$n} of {$of} items"));
        $cli->log(sprintf('booked %d items, %d units%s', $done['booked_items'], $done['booked_units'],
            $done['replayed_items'] ? " ({$done['replayed_items']} replayed)" : ''));
        return 0;
    }));

/**
 * The --rebase run (D40b): checks the file is the T0 snapshot CW recorded, plans, prints, books.
 *
 * @param iterable<array{0: string, 1: int}> $rows
 */
function rebase(Cli $cli, OpeningEstimate $op, iterable $rows, string $file, string $sha, string $channel, ?string $warehouse, ?string $docRef,
    ?string $estimateRef, ?DateTimeImmutable $asOf, string $source, string $approved, bool $dry): int
{
    $rb = new OpeningRebase($cli->db);
    try {
        $opening = $rb->opening($channel);
    } catch (CwException $e) {
        $cli->error($e->getMessage());
        return 1;
    }
    $t0 = Clock::fromDb($opening['t0_at']);
    $t0Iso = $t0->format('Y-m-d\TH:i:s\Z');
    if ($asOf !== null && $asOf->format('Y-m-d\TH:i:s') !== $t0->format('Y-m-d\TH:i:s')) {
        $cli->error('--as-of ' . $asOf->setTimezone(Clock::utc())->format('Y-m-d\TH:i:s\Z') . " is not the T0 CW recorded for {$channel} ({$t0Iso}); nothing booked");
        return 1;
    }
    $named = preg_match('/t0_site_stock_(\d{8}T\d{6}Z)\.csv$/', basename($file), $m) === 1 ? $m[1] : null;
    if ($named !== null && $named !== $t0->format('Ymd\THis\Z')) {
        $cli->error(basename($file) . " is the stock of another T0 than the one CW recorded for {$channel} ({$t0Iso}); nothing booked");
        return 1;
    }
    // --sha256 proves the bytes, not which snapshot they are: a real run must name its T0 (--as-of), whatever the file is called.
    if ($asOf === null && !$dry) {
        $cli->error("a real --rebase run needs --as-of=<the T0 of the snapshot> (CW recorded {$t0Iso} for {$channel}); nothing booked");
        return 1;
    }
    $docRef ??= OpeningRebase::defaultDocRef($channel, $opening['t0_at']);
    try {
        $plan = $rb->plan($channel, $rows, $docRef, $estimateRef, $warehouse);
    } catch (CwException $e) {
        $cli->error("{$e->errorCode}: {$e->getMessage()}");
        foreach (array_slice((array) ($e->detail['items'] ?? []), 0, 20) as $i) {
            $cli->error('  ' . json_encode($i, JSON_UNESCAPED_SLASHES));
        }
        return 1;
    }
    $r = $plan['report'];
    $wh = $plan['opening']['warehouse_code'];
    $cli->log(sprintf('rebase of %s at T0 %s (final opening batch %s) at %s; doc_ref %s; estimate %s',
        $channel, $t0Iso, $r['opening_orders_at'], $wh, $docRef, $estimateRef ?? '(none)'));
    $cli->log(sprintf('  T0 check: --as-of %s; the file name %s', $asOf === null ? 'not given (a real run needs it)' : 'matches',
        $named === null ? 'names no T0' : 'names T0 ' . $named . ' (matches)'));
    $cli->log(sprintf('%s: %d rows (sha256 %s); %d with stock > 0 (%d units), %d at or below zero',
        basename($file), $r['rows'], substr($sha, 0, 16), $r['positive_rows'], $r['positive_units'], $r['zero_or_negative_rows']));
    $cli->log(sprintf('  listings: %d mapped (%d not in the file), %d quarantined; no listing %d rows (%d units)',
        $r['listings']['mapped'], $r['listings']['mapped_missing_from_file'], $r['listings']['quarantined'], $r['no_listing']['rows'], $r['no_listing']['units']));
    foreach ($r['not_linked_by_status'] as $status => $s) {
        $cli->log(sprintf('  not linked (%s): %d rows (%d units)', $status, $s['rows'], $s['units']));
    }
    $cli->log(sprintf('  opening units: %d linked (%d central units), %d unlinked, %d released and %d never committed (not counted)',
        $r['opening_units']['units'], $r['opening_units']['central_units'], $r['opening_units']['unlinked'], $r['opening_units']['released'],
        $r['opening_units']['never_committed']));
    $cli->log(sprintf('  items: %d; rebased: site stock %d + opening units %d - earlier estimate %d', $r['items'], $r['site_term'], $r['units_term'], $r['earlier']));
    $cli->log(sprintf('  skipped: %d items %s', $r['skipped']['items'], $r['skipped']['why'] === [] ? '' : json_encode($r['skipped']['why'])));
    foreach (array_slice($r['skipped']['details'], 0, 50) as $d) {
        $cli->log('    ' . json_encode($d, JSON_UNESCAPED_SLASHES));
    }
    if ($r['skipped']['items'] > 50) {
        $cli->log(sprintf('    ... %d more (the report keeps the first %d)', $r['skipped']['items'] - 50, OpeningRebase::DETAIL_ITEMS));
    }
    if ($r['already_rebased']['items'] > 0) {
        $cli->log(sprintf('  already rebased under this doc_ref: %d items (net %d units), same figures', $r['already_rebased']['items'], $r['already_rebased']['units']));
    }
    $cli->log(sprintf('  no change: %d items', $r['no_change']));
    $cli->log(sprintf('  to book: %d items, +%d / -%d units (net %+d) as %s %s at %s', $r['to_book']['items'], $r['to_book']['up'],
        $r['to_book']['down'], $r['to_book']['net'], OpeningEstimate::MOVEMENT_TYPE, $docRef, $wh));
    if ($dry) {
        $cli->log('dry run: nothing booked');
        return 0;
    }
    if ($plan['lines'] === []) {
        $cli->log('nothing to book');
        return 0;
    }
    $note = mb_strcut(sprintf('opening rebase (not a count) to T0 %s (D40b): %s; file sha256 %s; max(site stock,0) x u + opening units x u - estimate%s',
        $t0Iso, $source, substr($sha, 0, 16), $approved), 0, 255, 'UTF-8');
    $done = $op->apply(Caller::system(OpeningEstimate::ACTOR_JOB), $plan['lines'], $docRef, $note, $wh,
        static fn (int $n, int $of) => $cli->log("  ... {$n} of {$of} items"), OpeningRebase::DOC_TYPE);
    $cli->log(sprintf('booked %d items, net %+d units%s', $done['booked_items'], $done['booked_units'],
        $done['replayed_items'] ? " ({$done['replayed_items']} replayed)" : ''));
    return 0;
}
