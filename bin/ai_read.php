<?php

declare(strict_types=1);

/**
 * "Claude reads the upload" (CW-1182; docs/decisions.md AI3, AI11, AI16, AI17; CW\Ai\DocumentReader): the documents staff dropped on the screens read by
 * Claude, one at a time, never in a web worker (a five-page bill takes minutes; php-fpm stops a request after 150 s). Run by cron every minute;
 * one run at a time (the job's lock): it first cleans up (a job left `reading` by a run that stopped, a file or an answer older than an hour,
 * job rows older than a day: whatever the switch), then reads what waits, looking again every 2 seconds, until --seconds have gone (it never
 * starts a document after that; one already started is finished: the next minute's run finds the lock held and stops at once).
 *
 *   php bin/ai_read.php [--db=cw_prod] [--seconds=55] [--once]
 *
 * Cron runs it as www-data (H1, the Codex final review of 86aebbd: the spool's owner, never root; the app login, as the web). It starts no
 * reading while the installer has the writers paused (M4: its markers), and a reading ends within Claude::DEADLINE_SEC (300 s), inside the
 * installer's 6-minute drain.
 *
 * --once: clean up, read at most one document, stop (the tests, a person). A line only when something was read or cleaned up, or the last
 * run did not end, or the writers are paused. Never a document, a field of it or the key in the log. Each run is recorded on the reader's one row (AI17: began, ended
 * well, or failed with the error's class; a run that never ended is recorded as failed by the next): bin/health_alert.php reads it. Exit
 * codes: 0 ok (failed readings are said on their pages), 2 usage, 3 cannot run.
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
        if ($reader->runBegins()) {
            $cli->log('the last run did not end (recorded as failed)');
        }
        try {
            $swept = $reader->sweep(true);
            if (array_sum($swept) > 0) {
                $cli->log(sprintf('cleaned up: stopped=%d too_old=%d expired=%d crashed=%d files=%d rows=%d', $swept['stopped'], $swept['too_old'], $swept['expired'],
                    $swept['crashed'], $swept['files'], $swept['rows']));
            }
            $until = hrtime(true) / 1e9 + $seconds;
            do {
                if (DocumentReader::paused()) {
                    // M4: the installer has paused the writers: no reading starts (its drain never waits on one); the jobs wait for after
                    $cli->log('the writers are paused (an install): no reading starts');
                    break;
                }
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
        } catch (\Throwable $e) {
            try {
                $reader->runEnds(get_class($e));
            } catch (\Throwable) {
                // the database itself failed: the next run records this one as not ended
            }
            throw $e;
        }
        $reader->runEnds(null);
        return Cli::OK;
    },
    true,
    true,
));
