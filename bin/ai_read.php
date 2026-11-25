<?php

declare(strict_types=1);

/**
 * "Claude reads the upload" (CW-1182; docs/decisions.md AI3, AI11; CW\Ai\DocumentReader): the documents staff dropped on the screens read by
 * Claude, one at a time, never in a web worker (a five-page bill takes minutes; php-fpm stops a request after 150 s). Run by cron every minute;
 * one run at a time (the job's lock): it first cleans up (a job left `reading` by a run that stopped, a file or an answer older than an hour,
 * job rows older than a day: whatever the switch), then reads what waits, looking again every 2 seconds, until --seconds have gone (it never
 * starts a document after that; one already started is finished: the next minute's run finds the lock held and stops at once).
 *
 *   php bin/ai_read.php [--db=cw_prod] [--seconds=55] [--once]
 *
 * --once: clean up, read at most one document, stop (the tests, a person). A line only when something was read or cleaned up. Never a
 * document, a field of it or the key in the log. Exit codes: 0 ok (failed readings are said on their pages), 2 usage, 3 cannot run.
 */

use CW\Ai\DocumentReader;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(
    'ai_read',
    ['seconds:', 'once'],
    'usage: php bin/ai_read.php [--seconds=55] [--once]',
    static function (Cli $cli, array $opts): int {
        $seconds = Cli::intOpt($opts, 'seconds', 55, 0, 3600);
        $once = array_key_exists('once', $opts);
        $reader = new DocumentReader($cli->db);
        $swept = $reader->sweep(true);
        if (array_sum($swept) > 0) {
            $cli->log(sprintf('cleaned up: stopped=%d too_old=%d expired=%d files=%d rows=%d', $swept['stopped'], $swept['too_old'], $swept['expired'], $swept['files'],
                $swept['rows']));
        }
        $until = hrtime(true) / 1e9 + $seconds;
        do {
            $r = $reader->runNext();
            if ($r !== null) {
                $cli->log(sprintf('read %d: %s (%s)', $r['id'], $r['state'], $r['reason']));
                continue;
            }
            if ($once || hrtime(true) / 1e9 + 2 > $until) {
                break;
            }
            usleep(2_000_000);
        } while (!$once && hrtime(true) / 1e9 < $until);
        return Cli::OK;
    },
    true,
    true,
));
