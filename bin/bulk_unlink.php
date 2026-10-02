<?php

declare(strict_types=1);

/**
 * The undo of a Key bulk confirm (docs/decisions.md M28): unlinks, through DecisionService and as --lead (a mapping lead),
 * every listing still linked by a decision of the batch (bulk_batch_id `undo:<batch>`), and re-opens each one's proposal
 * as a new proposal (run `undo:<batch>`) so the listing is back in the Key queue. Only `key_bulk:` batches; a listing
 * relinked by hand since is left alone; an unlink that needs a second person waits for one (re-run after the approval
 * to re-open its proposal).
 *
 *   php bin/bulk_unlink.php --batch=key_bulk:<sample> --lead=<mapping lead e-mail> [--apply] [--db=<schema>] [--admin]
 *
 * Dry run unless --apply. Exit codes: 0 ok · 1 some unlinks failed · 2 usage (or --lead is not an active mapping lead) · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Mapping\DecisionService;
use CW\Mapping\KeyBulk;
use CW\Mapping\Proposals;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

exit(Cli::main('bulk_unlink', ['batch:', 'lead:', 'apply'],
    'usage: php bin/bulk_unlink.php --batch=key_bulk:<sample> --lead=<mapping lead e-mail> [--apply]',
    static function (Cli $cli, array $opts): int {
        $batch = $opts['batch'] ?? null;
        $email = $opts['lead'] ?? null;
        if (!is_string($batch) || !is_string($email)) {
            throw new InvalidArgumentException('--batch=key_bulk:<sample> and --lead=<mapping lead e-mail> are required');
        }
        $apply = array_key_exists('apply', $opts);
        $db = $cli->db;
        $staff = $db->value('SELECT id FROM staff_user WHERE email = ?', [strtolower($email)]);
        if ($staff === null) {
            throw new InvalidArgumentException("--lead: no staff account {$email}");
        }
        try {
            $ds = new DecisionService($db);
            $r = (new KeyBulk($db, $ds, new Proposals($db, $ds)))->undo(Caller::staff((int) $staff), $batch, $apply);
        } catch (CwException $e) {
            throw new InvalidArgumentException("{$e->errorCode}: {$e->getMessage()}");
        }
        $cli->log(sprintf('%sbatch=%s undo_batch=%s lead=%s decisions=%d linked=%d waiting_second=%d reopen=%d undone=%d changed_since=%d applied=%d pending=%d reopened=%d failed=%s',
            $apply ? '' : 'DRY RUN (nothing written) ', $r['batch'], $r['undo_batch'], $email, $r['decisions'], $r['linked'], $r['waiting_second'],
            $r['reopen'], $r['undone'], $r['changed_since'], $r['applied'], $r['pending'], $r['reopened'], KeyBulk::counts($r['failed'])));
        return $r['failed'] === [] ? Cli::OK : Cli::PROBLEM;
    }));
