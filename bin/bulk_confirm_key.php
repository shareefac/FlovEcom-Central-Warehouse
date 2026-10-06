<?php

declare(strict_types=1);

/**
 * The owner's bulk confirm of Key proposals after a spot-check (docs/decisions.md M28). Only the sample's owner (--lead
 * must be the mapping lead who drew it) runs it. Refuses unless every member of the sample is confirmed by that owner,
 * none was rejected or decided otherwise, and the sample is fit (20 or more members, every stratum represented, the
 * members what its seed draws); then links, one DecisionService decision per listing (each its own transaction,
 * bulk_batch_id `key_bulk:<sample>`, decided by --lead), every proposal of the sample's population that still qualifies
 * (CW\Mapping\KeyEligibility: Key now, unlinked, nothing for two people, no reject, nothing changed since the proposal was
 * made, the key's target listing still linked to the item with units 1, the titles the judge saw unchanged, the listing
 * not held back for one-at-a-time review by bin/key_bulk_hold.php). The dry run (default) reports how many qualify, why the
 * others do not, the held ones still waiting (`held`) with their reasons, and the units they cover; --report=<file.csv> writes every proposal of the population
 * with its outcome (what will be linked to what, and the first reason for the others) and its hold reason.
 * Re-runs link only what is left; --limit=N stops after N links (a canary). Undo: bin/bulk_unlink.php --batch=key_bulk:<sample>.
 *
 *   php bin/bulk_confirm_key.php --sample=<name> --lead=<owner e-mail> [--apply] [--limit=N] [--report=<file.csv>] [--db=<schema>] [--admin]
 *
 * Exit codes: 0 ok · 1 refused (sample not complete or not fit) or some links failed · 2 usage (or --lead is not the sample's
 * owner or not an active mapping lead) · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Mapping\DecisionService;
use CW\Mapping\KeyBulk;
use CW\Mapping\KeyEligibility;
use CW\Mapping\Proposals;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

/** A CSV cell a spreadsheet will not run as a formula. */
function csvCell(mixed $v): string
{
    $s = $v === null ? '' : (string) $v;
    return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
}

exit(Cli::main('bulk_confirm_key', ['sample:', 'lead:', 'apply', 'limit:', 'report:'],
    'usage: php bin/bulk_confirm_key.php --sample=<name> --lead=<owner e-mail> [--apply] [--limit=N] [--report=<file.csv>]',
    static function (Cli $cli, array $opts): int {
        $sample = $opts['sample'] ?? null;
        $email = $opts['lead'] ?? null;
        if (!is_string($sample) || !is_string($email)) {
            throw new InvalidArgumentException('--sample=<name> and --lead=<owner e-mail> are required');
        }
        $limit = isset($opts['limit']) ? Cli::intOpt($opts, 'limit', 0, 1, PHP_INT_MAX) : null;
        $apply = array_key_exists('apply', $opts);
        $reportFile = is_string($opts['report'] ?? null) ? $opts['report'] : null;
        if ($reportFile !== null && (file_exists($reportFile) || !is_dir(dirname($reportFile)) || !is_writable(dirname($reportFile)))) {
            throw new InvalidArgumentException("--report: {$reportFile} exists already (never overwritten) or its directory is not writable");
        }
        $db = $cli->db;
        $staff = $db->value('SELECT id FROM staff_user WHERE email = ?', [strtolower($email)]);
        if ($staff === null) {
            throw new InvalidArgumentException("--lead: no staff account {$email}");
        }
        $t0 = hrtime(true);
        try {
            $ds = new DecisionService($db);
            $r = (new KeyBulk($db, $ds, new Proposals($db, $ds)))->confirm(Caller::staff((int) $staff), $sample, $apply, $limit);
        } catch (CwException $e) {
            throw new InvalidArgumentException("{$e->errorCode}: {$e->getMessage()}");
        }
        $s = $r['sample'];
        fwrite(STDOUT, sprintf("sample %s: seed %d, %d of %d decided, %d confirmed by its owner, verdict %s (drawn %s by %s from %d proposals)\n",
            $s['name'], $s['seed'], $s['decided'], $s['size'], $s['confirmed'], $s['verdict'], $s['created_at'], $s['created_by'] ?? '?', $s['population']));
        $fh = $reportFile !== null ? @fopen($reportFile, 'xb') : null;
        if ($fh === false) {
            $cli->error("--report: cannot create {$reportFile}");
            $fh = null;
        }
        if ($fh !== null) {
            $channels = array_column($db->all('SELECT id, code FROM channel'), 'code', 'id');
            $stratum = array_column($db->all('SELECT proposal_id, stratum FROM key_sample_member WHERE sample_id = ?', [$s['id']]), 'stratum', 'proposal_id');
            fputcsv($fh, ['outcome', 'first_reason', 'all_reasons', 'proposal_id', 'listing_id', 'channel', 'variant', 'title', 'item_id', 'item_code',
                'item_name', 'confidence', 'stratum', 'units_30d', 'units_365d', 'lane_target_listing_id', 'hold_reason', 'held_by', 'held_at']);
            foreach ($r['checked'] as $pid => $c) {
                fputcsv($fh, array_map('csvCell', [$c['reasons'] === [] ? 'eligible' : 'excluded', $c['reasons'][0] ?? '',
                    implode(' ', $c['reasons']), $pid, $c['listing_id'], $channels[$c['channel_id']] ?? $c['channel_id'], $c['variant'], $c['title'],
                    $c['sku_id'], $c['item_code'], $c['item_name'], $c['confidence'], $stratum[$pid] ?? '', $c['units_30d'], $c['units_365d'],
                    $c['target_listing_id'], $c['hold']['reason'] ?? '', $c['hold'] === null ? '' : ($c['hold']['by_email'] ?? $c['hold']['by'] ?? ''),
                    $c['hold']['at'] ?? '']));
            }
            fclose($fh);
            fwrite(STDOUT, sprintf("report: %d proposals written to %s\n", count($r['checked']), $reportFile));
        }
        foreach ($r['checked'] as $pid => $c) {
            if ($c['hold'] !== null && in_array($c['listing_status'], KeyEligibility::OPEN_LISTING, true)) {
                fwrite(STDOUT, sprintf("  held: proposal %d listing %d (by %s, %s%s): %s\n", $pid, $c['listing_id'], $c['hold']['by_email'] ?? $c['hold']['by'] ?? '?',
                    $c['hold']['at'], $c['hold']['proposal_id'] !== $pid ? ", held under proposal {$c['hold']['proposal_id']} of {$c['hold']['sample']}" : '',
                    $c['hold']['reason']));
            }
        }
        if ($r['refused'] !== null && $r['checked'] === []) {
            foreach ($r['problems'] as $p) {
                fwrite(STDOUT, isset($p['position'])
                    ? sprintf("  #%d proposal %d listing %d: %s\n", $p['position'], $p['proposal_id'], $p['listing_id'], $p['state'])
                    : sprintf("  %s\n", $p['state']));
            }
            $cli->error("refused ({$r['refused']}): every sample member must be confirmed by the sample's owner, none rejected or decided otherwise, "
                . 'and the sample must be fit (20 or more members, every stratum represented, the members its seed draws)');
            return Cli::PROBLEM;
        }
        $cli->log(sprintf('%ssample=%s batch=%s lead=%s population=%d eligible=%d excluded=%s held=%d units_30d=%d units_365d=%d already_in_batch=%d applied=%d skipped=%s failed=%s%s%s ms=%d',
            $apply ? '' : 'DRY RUN (nothing written) ', $s['name'], $r['batch'], $email, $r['population'], $r['eligible'], KeyBulk::counts($r['excluded']), $r['held'],
            $r['units_30d'], $r['units_365d'], $r['already_in_batch'], $r['applied'], KeyBulk::counts($r['skipped']), KeyBulk::counts($r['failed']),
            $limit !== null ? " limit={$limit}" : '', $r['refused'] !== null ? " stopped={$r['refused']}" : '', intdiv(hrtime(true) - $t0, 1_000_000)));
        if ($r['refused'] !== null) {
            $cli->error("stopped ({$r['refused']}): the sample is no longer complete or fit; the links made before the stop stand (undo: bin/bulk_unlink.php)");
            return Cli::PROBLEM;
        }
        return $r['failed'] === [] ? Cli::OK : Cli::PROBLEM;
    }));
