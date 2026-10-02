<?php

declare(strict_types=1);

/**
 * The owner's spot-check sample of Key proposals (docs/decisions.md M28): `--size` (20, the minimum) proposals from every
 * open Key proposal a bulk confirm could link now, stratified by the judge's confidence (>= 90 and 85-89, proportional).
 * The dry run (default) shows the population, its strata and the allocation, never members: nothing is drawn. --apply
 * writes the proved bases of older proposals (M27), then stores the sample and its population (key_sample,
 * key_sample_member) with a seed the SERVER draws at that moment (nobody chooses it). The sample belongs to --by: only
 * their confirmations count, and only they run its bulk confirm. The owner then confirms or rejects each member on the
 * review screen (/ui/review/samples lists them with their state).
 *
 *   php bin/sample_proposals.php --name=<sample> --by=<mapping lead e-mail> [--size=20] [--apply] [--after-failed=<sample>[,...]]
 *   php bin/sample_proposals.php --name=<sample> --verify          # re-draws a stored sample from its seed (read-only)
 *       [--db=<schema>] [--admin]
 *
 * --after-failed: the listings of a FAILED sample are never drawn again, unless named here; allowed only once a new matching
 * run was imported or the band version changed since it failed, and then only for proposals made after it (audited).
 * Exit codes: 0 ok · 1 refused (name taken, too few proposals, override not allowed, --verify differs) · 2 usage (or --by is
 * not an active mapping lead) · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Mapping\KeyBulk;
use CW\Mapping\KeySample;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

exit(Cli::main('sample_proposals', ['name:', 'by:', 'size:', 'apply', 'after-failed:', 'verify', 'seed:'],
    'usage: php bin/sample_proposals.php --name=<sample> --by=<mapping lead e-mail> [--size=20] [--apply] [--after-failed=<sample>[,...]]'
    . "\n       php bin/sample_proposals.php --name=<sample> --verify",
    static function (Cli $cli, array $opts): int {
        $name = $opts['name'] ?? null;
        if (!is_string($name)) {
            throw new InvalidArgumentException('--name=<sample> is required');
        }
        $db = $cli->db;
        if (array_key_exists('verify', $opts)) {
            try {
                $v = (new KeySample($db))->verify($name);
            } catch (CwException $e) {
                throw new InvalidArgumentException("{$e->errorCode}: {$e->getMessage()}");
            }
            $cli->log(sprintf('verify name=%s seed=%d members=%d %s', $v['name'], $v['seed'], count($v['stored']),
                $v['matches'] ? 'matches: the stored members are what the seed draws from the stored population'
                    : 'DIFFERS: stored ' . json_encode($v['stored']) . ' drawn ' . json_encode($v['drawn'])));
            return $v['matches'] ? Cli::OK : Cli::PROBLEM;
        }
        $email = $opts['by'] ?? null;
        if (!is_string($email)) {
            throw new InvalidArgumentException('--by=<mapping lead e-mail> is required');
        }
        if (isset($opts['seed'])) {
            throw new InvalidArgumentException('--seed is not taken: the server draws the seed when the sample is stored');
        }
        $size = Cli::intOpt($opts, 'size', KeySample::DEFAULT_SIZE, KeySample::MIN_SIZE, KeySample::MAX_SIZE);
        $after = is_string($opts['after-failed'] ?? null) ? array_values(array_filter(array_map('trim', explode(',', $opts['after-failed'])), 'strlen')) : [];
        $apply = array_key_exists('apply', $opts);
        $staff = $db->value('SELECT id FROM staff_user WHERE email = ?', [strtolower($email)]);
        if ($staff === null) {
            throw new InvalidArgumentException("--by: no staff account {$email}");
        }
        try {
            $s = (new KeySample($db))->create(Caller::staff((int) $staff), $name, $size, $apply, $after);
        } catch (CwException $e) {
            if ($e->httpStatus === 409) {
                $cli->error("{$e->errorCode}: {$e->getMessage()}");
                return Cli::PROBLEM;
            }
            throw new InvalidArgumentException("{$e->errorCode}: {$e->getMessage()}");
        }
        foreach ($s['strata'] as $x) {
            fwrite(STDOUT, sprintf("stratum %s (confidence %d-%d): %d of %d\n", $x['name'], $x['min_confidence'], $x['max_confidence'], $x['sample'], $x['population']));
        }
        foreach ($s['overrides'] as $o) {
            fwrite(STDOUT, sprintf("override: failed sample %s (%s): its listings may be drawn again for proposals made after %s\n", $o['name'], $o['why'], $o['created_at']));
        }
        foreach ($s['members'] as $m) {
            fwrite(STDOUT, sprintf("#%d proposal %d listing %d confidence %s stratum %s\n", $m['position'], $m['proposal_id'], $m['listing_id'],
                $m['confidence'] ?? '-', $m['stratum']));
        }
        $cli->log(sprintf('%sname=%s by=%s%s size=%d population=%d strata=%s excluded=%s units_30d=%d units_365d=%d bases: recorded=%d backfilled=%d unproved=%s%s',
            $apply ? '' : 'DRY RUN (nothing drawn, nothing written) ', $s['name'], $email, $s['seed'] !== null ? " seed={$s['seed']}" : '', $s['size'],
            $s['population'], json_encode(array_map(static fn (array $x): string => "{$x['name']}: {$x['sample']} of {$x['population']}", $s['strata'])),
            KeyBulk::counts($s['excluded']), $s['units_30d'], $s['units_365d'], $s['backfill']['recorded'], $s['backfill']['backfilled'],
            KeyBulk::counts($s['backfill']['unproved']), $s['sample_id'] !== null ? " sample_id={$s['sample_id']}" : ''));
        return Cli::OK;
    }));
