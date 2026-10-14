<?php

declare(strict_types=1);

/**
 * The alerts to a person (plan §6.3 "CW also alerts centrally"; go-live plan part 6; docs/decisions.md H8, PF10). Every run:
 *  1. checks (CW\Ops\Alerts): each store in Watching only or Live: a late heartbeat, a deep or old outbox, messages that failed for
 *     good, feed lag, another mode than CW's; CW itself: its own answer time, the nightly safety check (failed, or none lately).
 *     Each limit is a setting (alerts.*, Settings › System › Settings).
 *  2. prints one line per problem ("STALE vpg (live): last heartbeat ... (412 s ago; threshold 180 s)");
 *  3. sends them (CW\Ops\AlertDispatch) to alerts.webhook_url and/or alerts.email_to: once per alerts.repeat_minutes while a problem
 *     lasts, again when it comes back, and a "back to normal" line when it clears. Both settings are EMPTY by default: nothing is sent.
 *     --no-send checks and prints only (nothing is recorded or sent).
 *
 *   php bin/health_alert.php [--db=cw_staging] [--stale-after=180] [--no-send] [--admin]
 *
 * Scheduled every 2 minutes (deploy/staging/cw-staging.cron). One run at a time (it records what it sent).
 * Exit codes: 0 nothing to report, 1 problems found (sent or not), 2 usage, 3 cannot run (see CW\Ops\Cli). A destination that failed is
 * reported on stderr (never its address) and the problems are tried again on the next run.
 */

use CW\Ops\AlertDispatch;
use CW\Ops\Alerts;
use CW\Ops\AlertSender;
use CW\Ops\Cli;
use CW\Settings;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(
    'health_alert',
    ['stale-after:', 'no-send'],
    'usage: php bin/health_alert.php [--stale-after=<seconds>] [--no-send]',
    static function (Cli $cli, array $opts): int {
        $settings = new Settings($cli->db);
        $default = Alerts::int($settings, 'alerts.heartbeat_late_seconds', \CW\Ops\ChannelHealth::STALE_AFTER_SEC);
        $staleAfter = Cli::intOpt($opts, 'stale-after', $default, 1, 86_400);
        $problems = (new Alerts($cli->db))->problems($settings, $staleAfter);
        foreach ($problems as $p) {
            $cli->log(sprintf('%s %s (%s): %s', strtoupper($p['kind']), $p['channel'] ?? 'cw', $p['mode'] ?? 'system', $p['detail']));
        }
        if (!array_key_exists('no-send', $opts)) {
            $r = (new AlertDispatch($cli->db, new AlertSender()))->run($problems, $settings, $cli->schema);
            if ($r['destinations'] === 0) {
                if ($problems !== []) {
                    $cli->log('not sent: no alert address is set (alerts.webhook_url and alerts.email_to are empty)');
                }
            } else {
                if ($r['sent'] !== [] || $r['cleared'] !== []) {
                    $cli->log(sprintf('sent: %d alert(s), %d cleared, %d still inside their window', count($r['sent']), count($r['cleared']), count($r['held'])));
                }
                foreach ($r['failed'] as $f) {
                    $cli->error("could not send to the {$f}");
                }
            }
        }
        if ($problems === []) {
            $cli->log('ok: every shadow/live channel reported within ' . $staleAfter . ' s');
            return Cli::OK;
        }
        return Cli::PROBLEM;
    },
));
