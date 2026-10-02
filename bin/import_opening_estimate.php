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
 * The CSV: a header whose first column names the variant ("...variant...") and whose second names
 * the figure ("...qty..." or "...stock..."), then one row per variant: digits, integer (may be
 * negative). --as-of is the moment the figures describe (with an offset, e.g. 2026-10-01T00:00+01:00).
 * --sha256 is required for a real run and must match the file. --doc-ref is compared byte for byte;
 * any other spelling of an existing opening (even in other letter case) counts as an earlier opening.
 * The as-of time, the file hash, --source and --approved-by go into every ledger row's note.
 * The actor is system:opening_estimate.
 * Exit codes: 0 ok · 1 refused or failed · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Clock;
use CW\Movements;
use CW\Ops\Cli;
use CW\Ops\OpeningEstimate;
use CW\Stock;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '512M');

exit(Cli::main('import_opening_estimate',
    ['csv:', 'as-of:', 'doc-ref:', 'source:', 'sha256:', 'approved-by:', 'channel:', 'warehouse:', 'dry-run'],
    'usage: php bin/import_opening_estimate.php --csv=<file> --as-of=<ISO time> --doc-ref=<id> --source=<text> --sha256=<hex> [--approved-by=<who>] [--channel=vapeandgo] [--warehouse=MAIN] [--dry-run]',
    static function (Cli $cli, array $opts): int {
        $file = $opts['csv'] ?? null;
        $docRef = $opts['doc-ref'] ?? null;
        $source = $opts['source'] ?? null;
        $asOfRaw = $opts['as-of'] ?? null;
        $dry = array_key_exists('dry-run', $opts);
        if (!is_string($file) || !is_string($docRef) || !is_string($source) || !is_string($asOfRaw) || trim($source) === '') {
            throw new InvalidArgumentException('--csv, --as-of, --doc-ref and --source are required');
        }
        if (strlen($docRef) > 160 || preg_match('/^[A-Za-z0-9][A-Za-z0-9:._+-]*\z/', $docRef) !== 1) {
            throw new InvalidArgumentException('--doc-ref: up to 160 of A-Z a-z 0-9 : . _ + -');
        }
        $asOf = Clock::parse($asOfRaw, 'as-of');
        if ($asOf > Clock::now()) {
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
        $warehouse = is_string($opts['warehouse'] ?? null) ? $opts['warehouse'] : 'MAIN';
        $approved = is_string($opts['approved-by'] ?? null) ? '; approved by ' . $opts['approved-by'] : '';
        $note = mb_strcut(sprintf('opening estimate (not a count) as of %s: %s; file sha256 %s; negatives as 0; excludes open paid units%s',
            $asOf->format('Y-m-d H:i:s') . ' UTC', $source, substr($have, 0, 16), $approved), 0, 255, 'UTF-8');

        $rows = (static function () use ($file): Generator {
            $fh = fopen($file, 'r');
            $header = fgetcsv($fh, null, ',', '"', '');
            if (is_array($header) && isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            }
            if (!is_array($header) || count($header) !== 2 || stripos((string) $header[0], 'variant') === false
                || preg_match('/qty|stock/i', (string) $header[1]) !== 1) {
                throw new InvalidArgumentException('the CSV header must be two columns: the variant id ("...variant...") then the figure ("...qty..." or "...stock...")');
            }
            $line = 1;
            while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
                $line++;
                if ($r === [null] || $r === ['']) {
                    continue;
                }
                $v = trim((string) ($r[0] ?? ''));
                $q = trim((string) ($r[1] ?? ''));
                if (count($r) !== 2 || preg_match('/^\d{1,18}$/', $v) !== 1 || preg_match('/^-?\d{1,9}$/', $q) !== 1) {
                    throw new InvalidArgumentException("line {$line}: needs two columns, a numeric variant id and an integer figure");
                }
                yield [$v, (int) $q];
            }
            fclose($fh);
        })();

        $op = new OpeningEstimate($cli->db, new Movements($cli->db, new Stock($cli->db)));
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
