<?php

declare(strict_types=1);

/**
 * Imports a first-match catalogue export (tools/first_match/export.php: gzipped JSONL, one line
 * per variant) into listing_profile, through the same ListingIngestService as PUT /v1/listings:
 * a variant CW has never seen gets its `unmapped` listing row; nothing is linked. Idempotent: a
 * second run finds every profile `unchanged`.
 *
 *   php bin/import_listings.php --channel=vpg --file=/path/vapeandgo_listings_<ts>.jsonl.gz [--batch=500]
 *       [--db=<schema>] [--admin]
 *
 * Lines that fail the PUT /v1/listings checks are skipped and listed on stderr (at most 20).
 * A barcode the checks refuse (a source's barcode field can hold junk such as "Black Grey" or
 * "85104 - 1": a space, a control character, more than 64 characters) is dropped from its listing
 * instead of skipping the whole listing; every drop is counted in the summary and the first 20 are
 * listed on stderr (docs/decisions.md M17).
 * Exit codes: 0 ok · 1 some lines skipped · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Mapping\ListingIngestService;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('import_listings', ['channel:', 'file:', 'batch:'],
    'usage: php bin/import_listings.php --channel=<code> --file=<export .jsonl.gz> [--batch=500]',
    static function (Cli $cli, array $opts): int {
        $code = $opts['channel'] ?? null;
        $file = $opts['file'] ?? null;
        if (!is_string($code) || !is_string($file)) {
            throw new InvalidArgumentException('--channel=<code> and --file=<export .jsonl.gz> are required');
        }
        $batch = Cli::intOpt($opts, 'batch', 500, 1, ListingIngestService::MAX_LISTINGS);
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
            'barcodes_dropped' => 0, 'listings_with_dropped' => 0];
        $sites = [];
        $seen = [];
        $buf = [];
        $flush = static function () use (&$buf, &$tot, $svc, $caller, $channelId): void {
            if ($buf === []) {
                return;
            }
            $r = $svc->ingest($caller, (int) $channelId, $buf);
            foreach (['received', 'created', 'updated', 'unchanged'] as $k) {
                $tot[$k] += $r[$k];
            }
            $tot['identity_changed'] += count(array_filter($r['listings'], static fn (array $l): bool => $l['result'] !== 'created' && $l['identity_changed']));
            $tot['batches']++;
            $buf = [];
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
            $buf[] = $l;
            if (count($buf) >= $batch) {
                $flush();
            }
        }
        gzclose($fh);
        $flush();
        $cli->log(sprintf('channel=%s file=%s sites=%s lines=%d received=%d created=%d updated=%d unchanged=%d identity_changed=%d skipped=%d batches=%d barcodes_dropped=%d listings_with_dropped=%d ms=%d',
            $code, basename($file), implode(',', array_keys($sites)), $tot['lines'], $tot['received'], $tot['created'], $tot['updated'],
            $tot['unchanged'], $tot['identity_changed'], $tot['skipped'], $tot['batches'], $tot['barcodes_dropped'], $tot['listings_with_dropped'],
            intdiv(hrtime(true) - $t0, 1_000_000)));
        return $tot['skipped'] > 0 ? Cli::PROBLEM : Cli::OK;
    }));
