<?php

declare(strict_types=1);

/**
 * Follows the stores' choices at Add product (CW\Mapping\StoreChoices, docs/decisions.md SC1-SC12; tracker CW-217): a new item, a pack
 * of an item, or not stock, once the variant itself has arrived. On a timer every minute; a choice the warehouse finds something like
 * waits for a person on Approvals > New products.
 *
 *   php bin/apply_store_choices.php [--apply] [--limit=<choices>] [--list] [--db=<schema>] [--admin]
 *   php bin/apply_store_choices.php --choice=<id>
 *
 * DRY RUN by default: it reads and prints what it would do, and writes nothing. --apply follows them: one transaction per choice (the
 * decision and the choice's row together).
 *
 *   --limit=<n>     at most this many waiting choices (default StoreChoices::PER_RUN)
 *   --list          one line per choice looked at
 *   --choice=<id>   explains one waiting choice: what it would do now. Always dry.
 *
 * Exit codes: 0 done (choices waiting for a person are work for people, not errors) · 2 usage · 3 cannot run.
 */

use CW\Mapping\StoreChoices;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(StoreChoices::JOB, ['apply', 'limit:', 'list', 'choice:'],
    "usage: php bin/apply_store_choices.php [--apply] [--limit=<choices>] [--list]\n       php bin/apply_store_choices.php --choice=<id>",
    static function (Cli $cli, array $opts): int {
        $svc = new StoreChoices($cli->db);
        $json = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (array_key_exists('choice', $opts)) {
            if (array_key_exists('apply', $opts)) {
                throw new InvalidArgumentException('--choice explains one choice and writes nothing: give it alone');
            }
            $id = Cli::intOpt($opts, 'choice', 0, 1, PHP_INT_MAX);
            $plan = $svc->plan($id);
            $cli->log('DRY RUN (nothing written) choice ' . $id . ': ' . ($plan === null ? 'not waiting, or its variant has not arrived' : $json($plan)));
            return Cli::OK;
        }
        $apply = array_key_exists('apply', $opts);
        $limit = isset($opts['limit']) ? Cli::intOpt($opts, 'limit', 0, 1, 5000) : StoreChoices::PER_RUN;
        $r = $svc->run($apply, $limit);
        if (array_key_exists('list', $opts)) {
            foreach ($r['rows'] as $row) {
                $cli->log(sprintf('choice=%d listing=%d %s %s', $row['id'], $row['listing_id'], $row['state'], $row['outcome']));
            }
        }
        ksort($r['counts']);
        $counts = implode(' ', array_map(static fn (string $k, int $n): string => "{$k}={$n}", array_keys($r['counts']), $r['counts']));
        if ($r['looked'] > 0 || $r['counts'] !== [] || !$apply) {
            $cli->log(($apply ? '' : 'DRY RUN (nothing written) ') . 'looked=' . $r['looked'] . ($counts === '' ? '' : ' ' . $counts));
        }
        return Cli::OK;
    }));
