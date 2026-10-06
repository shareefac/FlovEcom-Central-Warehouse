<?php

declare(strict_types=1);

/**
 * Holds listings of a Key spot-check's population back from every bulk confirm, for one-at-a-time review (docs/decisions.md
 * M30), or with --release lets held ones go into a bulk confirm again. The file names each by its proposal of the population
 * (the screened --report's rows), but the hold is on the LISTING and durable (table key_bulk_hold): every later run of
 * bin/bulk_confirm_key.php, of this sample or any later one, leaves every proposal of the listing out (`held_for_review`),
 * with or without this file, a newer matching run's or a re-band's proposal included, and no later sample draws it. The
 * listing stays in the normal Key queue, and its review screen says "Held back from the bulk confirm: <reason>".
 *
 *   php bin/key_bulk_hold.php --sample=<name> --file=<file.csv> --by=<mapping lead e-mail> [--apply]
 *   php bin/key_bulk_hold.php --sample=<name> --file=<file.csv> --by=<mapping lead e-mail> --release [--apply]
 *       [--db=<schema>] [--admin]
 *
 * The file: a header row naming proposal_id, listing_id and reason (any order; other columns, e.g. those of a bulk --report
 * file, are ignored), then one proposal per row; the reason is why it is held (or, with --release, why it may go back).
 * Every row is checked and listed: a row is refused when it cannot be read, repeats a proposal, names a proposal outside the
 * sample's population or one of the sample's own members, names another listing than the proposal's, or (hold) names a
 * listing that is no longer waiting (linked, ignored, quarantined), or (release) a proposal that was never held. The other
 * rows go ahead (a refused row is NOT held: fix it and run again). A listing already held (a proposal already released) is
 * left as it is, so a re-run of the same file writes nothing. A release names the proposal the hold was written for, even
 * when a newer proposal has replaced it since (the sample page and the listing page name it).
 * Dry run (default) writes nothing. --by must be an active mapping lead, for a hold and for a release; each run that writes
 * is audited (mapping.key_hold, mapping.key_hold_release).
 *
 * Exit codes: 0 ok · 1 some rows refused (the others were held or released with --apply), or another run of this tool is
 * busy · 2 usage (no such file or sample, no header, --by not an active mapping lead) · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Mapping\KeyBulk;
use CW\Mapping\KeyHold;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '512M');

exit(Cli::main('key_bulk_hold', ['sample:', 'file:', 'by:', 'release', 'apply'],
    'usage: php bin/key_bulk_hold.php --sample=<name> --file=<csv: proposal_id,listing_id,reason> --by=<mapping lead e-mail> [--release] [--apply]',
    static function (Cli $cli, array $opts): int {
        $sample = $opts['sample'] ?? null;
        $path = $opts['file'] ?? null;
        $email = $opts['by'] ?? null;
        if (!is_string($sample) || !is_string($path) || !is_string($email)) {
            throw new InvalidArgumentException('--sample=<name>, --file=<file.csv> and --by=<mapping lead e-mail> are required');
        }
        $release = array_key_exists('release', $opts);
        $apply = array_key_exists('apply', $opts);
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("--file: {$path} is not a readable file");
        }
        if ((int) filesize($path) > KeyHold::MAX_BYTES) {
            throw new InvalidArgumentException("--file: {$path} is larger than " . KeyHold::MAX_BYTES . ' bytes');
        }
        $db = $cli->db;
        $staff = $db->value('SELECT id FROM staff_user WHERE email = ?', [strtolower($email)]);
        if ($staff === null) {
            throw new InvalidArgumentException("--by: no staff account {$email}");
        }
        // One run at a time, and a busy one is an error here, never a silent "skipped" (the caller relies on the holds).
        if (!$cli->tryLock()) {
            $cli->error('another run of key_bulk_hold is busy on this schema: nothing was read or written; run this again when it ends');
            return Cli::PROBLEM;
        }
        $text = file_get_contents($path);
        if ($text === false) {
            throw new InvalidArgumentException("--file: cannot read {$path}");
        }
        $sha = hash('sha256', $text);
        try {
            $rows = KeyHold::parse($text);
            if ($rows === []) {
                throw new InvalidArgumentException("--file: {$path} has a header but no rows");
            }
            $r = (new KeyHold($db))->run(Caller::staff((int) $staff), $sample, $rows, $release, $apply, ['name' => basename($path), 'sha256' => $sha]);
        } catch (CwException $e) {
            throw new InvalidArgumentException("{$e->errorCode}: {$e->getMessage()}");
        }
        $s = $r['sample'];
        fwrite(STDOUT, sprintf("sample %s (drawn by %s, verdict %s): %d rows in %s (sha256 %s), %d listings held and waiting before this run\n",
            $s['name'], $s['owner_email'] ?? $s['owner'] ?? '?', $s['verdict'], $r['rows'], basename($path), $sha, $r['held_before']));
        $verb = $release ? ['release' => $apply ? 'released' : 'to release'] : ['hold' => $apply ? 'held' : 'to hold'];
        foreach ($r['lines'] as $l) {
            $what = match ($l['outcome']) {
                'refused' => 'REFUSED ' . $l['code'] . ($l['note'] !== null ? " (held for: {$l['note']})" : ''),
                'already_held' => 'already held: ' . $l['note'],
                'already_released' => 'already released',
                default => $verb[$l['outcome']] . ': ' . $l['reason'] . ($l['outcome'] === 'release' ? " (held for: {$l['note']})" : ''),
            };
            fwrite(STDOUT, sprintf("  row %d proposal %s listing %s: %s\n", $l['row'], $l['proposal_id'] ?? '?', $l['listing_id'] ?? '?', $what));
        }
        $cli->log(sprintf('%smode=%s sample=%s by=%s rows=%d %s=%d already=%d refused=%s held_before=%d held_after=%d',
            $apply ? '' : 'DRY RUN (nothing written) ', $r['mode'], $s['name'], $email, $r['rows'], $release ? 'release' : 'hold', $r['todo'], $r['already'],
            KeyBulk::counts($r['refused']), $r['held_before'], $r['held_after']));
        if ($r['refused'] !== []) {
            $cli->error(sprintf('%d rows refused (listed above, REFUSED): %s %s; fix them and run again',
                array_sum($r['refused']), $release ? 'they are NOT released' : 'they are NOT held', $apply ? '(the other rows were written)' : '(dry run: nothing written)'));
            return Cli::PROBLEM;
        }
        return Cli::OK;
    }, false));
