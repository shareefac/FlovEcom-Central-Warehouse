<?php

declare(strict_types=1);

/**
 * Re-bands the OPEN proposals with the CURRENT band rules (CW\Matching\Band::VERSION; docs/decisions.md M26), from their
 * stored evidence and judge answer. Decided and superseded proposals are never touched; Manual (relabel) proposals are
 * left alone. A proposal whose evidence does not replay to its stored band under its own run's band version is reported
 * (evidence_mismatch) and left alone. Moves between Key and Check are applied as a new proposal of the run
 * `reband-<version>` that supersedes the old one (Proposals::add, audited), only while the listing is unlinked, has no
 * decision waiting or taken since, and the proposal's basis (M27) still holds. Every other move is reported only.
 *
 *   php bin/reband_proposals.php [--apply] [--by=<mapping lead e-mail>] [--channel=<code>] [--run-id=<id>] [--db=<schema>] [--admin]
 *
 * --by: the mapping lead the new proposals and the run's audit rows are attributed to (the owner); without it, the system.
 * Dry run unless --apply. Re-runs move nothing twice. Exit codes: 0 ok · 1 a move failed · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Mapping\DecisionService;
use CW\Mapping\KeySample;
use CW\Mapping\Proposals;
use CW\Mapping\Reband;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

exit(Cli::main('reband_proposals', ['apply', 'by:', 'channel:', 'run-id:'],
    'usage: php bin/reband_proposals.php [--apply] [--by=<mapping lead e-mail>] [--channel=<code>] [--run-id=<id>]',
    static function (Cli $cli, array $opts): int {
        $apply = array_key_exists('apply', $opts);
        $db = $cli->db;
        $channelId = null;
        if (is_string($opts['channel'] ?? null)) {
            $channelId = $db->value('SELECT id FROM channel WHERE code = ?', [$opts['channel']]);
            if ($channelId === null) {
                throw new InvalidArgumentException("no channel {$opts['channel']}");
            }
            $channelId = (int) $channelId;
        }
        $runId = is_string($opts['run-id'] ?? null) ? $opts['run-id'] : null;
        if ($runId !== null && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $runId) !== 1) {
            throw new InvalidArgumentException('--run-id must be 1-64 of [A-Za-z0-9._:-]');
        }
        $caller = Caller::system('reband_proposals');
        if (is_string($opts['by'] ?? null)) {
            $staff = $db->value('SELECT id FROM staff_user WHERE email = ?', [strtolower($opts['by'])]);
            if ($staff === null) {
                throw new InvalidArgumentException("--by: no staff account {$opts['by']}");
            }
            $caller = Caller::staff((int) $staff);
            try {
                KeySample::lead($db, $caller);
            } catch (CwException $e) {
                throw new InvalidArgumentException("--by: {$e->errorCode}: {$e->getMessage()}");
            }
        }
        $t0 = hrtime(true);
        $r = (new Reband($db, new Proposals($db, new DecisionService($db))))->run($caller, $apply, $channelId, $runId);
        foreach (Reband::lines($r) as $line) {
            fwrite(STDOUT, $line . "\n");
        }
        $cli->log(sprintf('%sby=%s run=%s band=%s key_min=%d open=%d unchanged=%d skipped=%s moves=%s blocked=%s applied=%s failed=%s backfilled=%d ms=%d',
            $apply ? '' : 'DRY RUN (nothing written) ', is_string($opts['by'] ?? null) ? $opts['by'] : 'system', $r['run_id'], $r['band_version'], $r['key_min_confidence'], $r['open'], $r['unchanged'],
            Reband::counts($r['skipped']), Reband::counts($r['moves']), Reband::counts($r['blocked']), Reband::counts($r['applied']),
            Reband::counts($r['failed']), $r['backfilled'], intdiv(hrtime(true) - $t0, 1_000_000)));
        return $r['failed'] === [] ? Cli::OK : Cli::PROBLEM;
    }));
