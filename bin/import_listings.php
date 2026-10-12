<?php

declare(strict_types=1);

/**
 * Imports a first-match catalogue export (tools/first_match/export.php: gzipped JSONL, one line
 * per variant) into listing_profile, through the same ListingIngestService as PUT /v1/listings:
 * a variant CW has never seen gets its `unmapped` listing row; nothing is linked. Idempotent: a
 * second run finds every profile `unchanged`.
 *
 *   php bin/import_listings.php --channel=vpg --file=/path/vapeandgo_listings_<ts>.jsonl.gz [--batch=500]
 *       [--exported-at=<UTC time>] [--db=<schema>] [--admin]
 *   php bin/import_listings.php --channel=vpg --file=/path/vapeandgo_listings_<ts>.jsonl.gz --state-only [--apply]
 *       [--exported-at=<UTC time>] [--batch=500] [--db=<schema>] [--admin]
 *
 * Lines that fail the PUT /v1/listings checks are skipped and listed on stderr (at most 20).
 * A barcode the checks refuse (a source's barcode field can hold junk such as "Black Grey" or
 * "85104 - 1": a space, a control character, more than 64 characters) is dropped from its listing
 * instead of skipping the whole listing; every drop is counted in the summary and the first 20 are
 * listed on stderr (docs/decisions.md M17).
 *
 * THE WEBSITE STATE (docs/decisions.md M66; CW\Mapping\SiteState). An export line carries whether its variant is on sale on the
 * website and what the page-heading rule reads (variant_status, product_status, product_type, product_id, is_landing, is_default,
 * product_permalink). The import hands them to the intake as the listing's `website`, dated with the export's time:
 * --exported-at=<UTC time, like 2026-09-26T09:41:52Z>, else the time in the file's name (…_listings_20260926T094152Z.jsonl.gz, as
 * the export tool names its files). A file with neither is imported as before and its state is NOT written (`state_at=not_dated`
 * in the summary): a state with no date is no state.
 *
 * --state-only: the state and NOTHING ELSE, for the variants that are already there (ListingIngestService::stateOnly()). It
 * writes the eight state columns of a variant whose line IS the stored profile (the same profile_hash, after the same barcode
 * cleaning as above), and leaves the rest, counted and listed on stderr (at most 20 of each): `differs` (the line is not the
 * stored profile: a title, a barcode, the price, the address or the units sold changed since; load the file with the ordinary
 * import if the profiles are to follow it), `unknown` (the warehouse has no such variant: it is not created), `no_state` (the line
 * carries none), `older` (the stored state is newer than this file). It creates no listing, moves no identity, no map_version,
 * no features and no mark of the check on arrival, and queues nothing.
 * It is a DRY RUN unless --apply is given: it counts and prints, and writes NOTHING (no state, no audit row). --apply writes, one
 * transaction and one audit row (listing.website_state) a batch. It needs a date (above) and refuses a file that has none.
 *
 * The command line is read whole or not at all (Ops\Cli::unreadable): an option this tool does not have (a misspelt
 * --state-only would otherwise be the ordinary import, which changes profiles), a value on a flag (`--apply=false` would
 * otherwise be `--apply`), an option with no value or given twice, or a stray word ends it with exit 2 before anything is read.
 * Values are written with "=". --apply without --state-only is refused too: the ordinary import always writes.
 *
 * Exit codes: 0 ok · 1 some lines skipped, or (--state-only) left · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Mapping\ListingIngestService;
use CW\Mapping\SiteState;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('import_listings', ['channel:', 'file:', 'batch:', 'exported-at:', 'state-only', 'apply'],
    "usage: php bin/import_listings.php --channel=<code> --file=<export .jsonl.gz> [--batch=500] [--exported-at=<UTC time>]\n"
    . '       php bin/import_listings.php --channel=<code> --file=<export .jsonl.gz> --state-only [--apply] [--exported-at=<UTC time>] [--batch=500]',
    static function (Cli $cli, array $opts): int {
        $code = $opts['channel'] ?? null;
        $file = $opts['file'] ?? null;
        if (!is_string($code) || !is_string($file)) {
            throw new InvalidArgumentException('--channel=<code> and --file=<export .jsonl.gz> are required');
        }
        $stateOnly = array_key_exists('state-only', $opts);
        $apply = array_key_exists('apply', $opts);
        if ($apply && !$stateOnly) {
            throw new InvalidArgumentException('--apply goes with --state-only (the ordinary import always writes)');
        }
        $batch = Cli::intOpt($opts, 'batch', 500, 1, ListingIngestService::MAX_LISTINGS);
        // As of when the file's website state was true: --exported-at, else the time in the file's name; null: not known.
        $stateAt = null;
        if (array_key_exists('exported-at', $opts)) {
            try {
                $stateAt = Clock::parse($opts['exported-at'], '--exported-at');
            } catch (CwException $e) {
                throw new InvalidArgumentException('--exported-at must be a UTC time like 2026-09-26T09:41:52Z');
            }
        } elseif (preg_match('/_(\d{8}T\d{6}Z)\.jsonl\.gz$/D', basename($file), $m) === 1) {
            $stateAt = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $m[1], Clock::utc()) ?: null;
        }
        if ($stateAt !== null && Clock::diff(Clock::now(), $stateAt) > Clock::MAX_AHEAD_SEC) {
            throw new InvalidArgumentException('the time of the export (' . $stateAt->format('Y-m-d\TH:i:s\Z') . ') is in the future: give --exported-at=<UTC time>');
        }
        if ($stateOnly && $stateAt === null) {
            throw new InvalidArgumentException("--state-only needs the time of the export: the name of {$file} has none, so give --exported-at=<UTC time, like 2026-09-26T09:41:52Z>");
        }
        $channelId = $cli->db->value('SELECT id FROM channel WHERE code = ?', [$code]);
        if ($channelId === null) {
            throw new InvalidArgumentException("no channel {$code}");
        }
        $fh = @gzopen($file, 'rb');
        if ($fh === false) {
            throw new InvalidArgumentException("cannot read {$file}");
        }
        $t0 = hrtime(true);
        $svc = new ListingIngestService($cli->db);
        $caller = Caller::system('import_listings');
        $tot = ['lines' => 0, 'received' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'identity_changed' => 0, 'skipped' => 0, 'batches' => 0,
            'barcodes_dropped' => 0, 'listings_with_dropped' => 0, 'state_written' => 0, 'state_ignored' => 0, 'state_older' => 0,
            'first' => 0, 'changed' => 0, 'same' => 0, 'differs' => 0, 'unknown' => 0, 'no_state' => 0];
        $sites = [];
        $seen = [];
        $buf = [];
        $lineOf = [];
        /** Why a line of a state-only load was left, in words. */
        $left = ['differs' => 'differs from the stored profile', 'unknown' => 'is not known to the warehouse', 'no_state' => 'carries no website state',
            'older' => 'has a newer state stored than this file\'s'];
        $said = array_fill_keys(array_keys($left), 0);
        $flush = static function () use (&$buf, &$tot, &$lineOf, &$said, $left, $svc, $caller, $channelId, $stateAt, $stateOnly, $apply, $cli): void {
            if ($buf === []) {
                return;
            }
            if ($stateOnly) {
                /** @var DateTimeImmutable $stateAt */
                $r = $svc->stateOnly($caller, (int) $channelId, $buf, $stateAt, $apply);
                foreach (['received', 'first', 'changed', 'same', 'differs', 'unknown', 'no_state'] as $k) {
                    $tot[$k] += $r[$k];
                }
                $tot['state_written'] += $r['written'];
                $tot['state_ignored'] += $r['ignored'];
                $tot['state_older'] += $r['older'];
                foreach ($r['listings'] as $l) {
                    if (isset($left[$l['result']]) && ++$said[$l['result']] <= 20) {
                        $cli->error(sprintf('line %d variant %s %s: left', $lineOf[strtolower($l['variant_id'])] ?? 0, $l['variant_id'], $left[$l['result']]));
                    }
                }
            } else {
                $r = $svc->ingest($caller, (int) $channelId, $buf, $stateAt);
                foreach (['received', 'created', 'updated', 'unchanged'] as $k) {
                    $tot[$k] += $r[$k];
                }
                $tot['identity_changed'] += count(array_filter($r['listings'], static fn (array $l): bool => $l['result'] !== 'created' && $l['identity_changed']));
                $tot['state_written'] += $r['website']['written'];
                $tot['state_ignored'] += $r['website']['ignored'];
                $tot['state_older'] += $r['website']['older'];
            }
            $tot['batches']++;
            $buf = [];
            $lineOf = [];
        };
        $skip = static function (int $line, string $why) use (&$tot, $cli): void {
            if (++$tot['skipped'] <= 20) {
                $cli->error("line {$line} skipped: {$why}");
            }
        };
        while (($line = gzgets($fh)) !== false) {
            $tot['lines']++;
            if (trim($line) === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                $skip($tot['lines'], 'not a JSON object');
                continue;
            }
            $sites[(string) ($row['site'] ?? '?')] = true;
            $l = ListingIngestService::fromExport($row);
            if (is_array($l['barcodes']) && array_is_list($l['barcodes'])) {
                $keep = [];
                $dropped = 0;
                foreach ($l['barcodes'] as $c) {
                    $s = is_int($c) ? (string) $c : $c;
                    if (is_string($s) && strlen(trim($s)) <= 64 && preg_match('/^[\x21-\x7e]*$/', trim($s)) === 1) {
                        $keep[] = $c;
                        continue;
                    }
                    $dropped++;
                    if (++$tot['barcodes_dropped'] <= 20) {
                        $cli->error(sprintf('line %d variant %s: barcode %s dropped (not a printable code without spaces, max 64)',
                            $tot['lines'], is_scalar($l['variant_id'] ?? null) ? (string) $l['variant_id'] : '?',
                            json_encode(is_string($s) ? mb_substr($s, 0, 40) : gettype($c), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)));
                    }
                }
                if ($dropped > 0) {
                    $tot['listings_with_dropped']++;
                    $l['barcodes'] = $keep;
                }
            }
            // The website state the line carries, only when it can be dated (M66). Neither hash of the profile reads it.
            if ($stateAt !== null) {
                $l[SiteState::KEY] = SiteState::fromExport($row);
            }
            $bad = ListingIngestService::check($l);
            if ($bad !== null) {
                $skip($tot['lines'], $bad['message']);
                continue;
            }
            $v = strtolower((string) $l['variant_id']);
            if (isset($seen[$v])) {
                $skip($tot['lines'], "variant {$l['variant_id']} appears twice");
                continue;
            }
            $seen[$v] = true;
            $lineOf[$v] = $tot['lines'];
            $buf[] = $l;
            if (count($buf) >= $batch) {
                $flush();
            }
        }
        gzclose($fh);
        $flush();
        $at = $stateAt === null ? 'not_dated' : $stateAt->format('Y-m-d\TH:i:s\Z');
        $ms = intdiv(hrtime(true) - $t0, 1_000_000);
        if ($stateOnly) {
            $cli->log(sprintf('STATE ONLY%s channel=%s file=%s sites=%s state_at=%s lines=%d received=%d %s=%d (first=%d changed=%d same=%d) differs=%d unknown=%d no_state=%d '
                . 'older=%d values_ignored=%d skipped=%d batches=%d ms=%d', $apply ? '' : ', DRY RUN (nothing written)', $code, basename($file), implode(',', array_keys($sites)), $at,
                $tot['lines'], $tot['received'], $apply ? 'written' : 'would_write', $tot['state_written'], $tot['first'], $tot['changed'], $tot['same'], $tot['differs'],
                $tot['unknown'], $tot['no_state'], $tot['state_older'], $tot['state_ignored'], $tot['skipped'], $tot['batches'], $ms));
            return $tot['skipped'] + $tot['differs'] + $tot['unknown'] + $tot['no_state'] + $tot['state_older'] > 0 ? Cli::PROBLEM : Cli::OK;
        }
        $cli->log(sprintf('channel=%s file=%s sites=%s lines=%d received=%d created=%d updated=%d unchanged=%d identity_changed=%d skipped=%d batches=%d barcodes_dropped=%d '
            . 'listings_with_dropped=%d state_at=%s state_written=%d state_values_ignored=%d state_older=%d ms=%d',
            $code, basename($file), implode(',', array_keys($sites)), $tot['lines'], $tot['received'], $tot['created'], $tot['updated'],
            $tot['unchanged'], $tot['identity_changed'], $tot['skipped'], $tot['batches'], $tot['barcodes_dropped'], $tot['listings_with_dropped'],
            $at, $tot['state_written'], $tot['state_ignored'], $tot['state_older'], $ms));
        return $tot['skipped'] > 0 ? Cli::PROBLEM : Cli::OK;
    }, strict: true));
