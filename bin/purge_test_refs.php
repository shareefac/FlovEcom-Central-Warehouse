<?php

declare(strict_types=1);

/**
 * STAGING ONLY. Removes the reservations tests left on a channel, by order_ref prefix (decisions D47;
 * CW\Ops\TestRefPurge). A DRY RUN unless --apply:
 *
 *   php bin/purge_test_refs.php --channel=<code> --prefix=<order_ref prefix> [--heartbeats-until=<ISO time>]
 *       [--actor=<who>] [--apply] [--db=<schema>] [--admin]
 *
 *  1. open reservations are neutralised through the normal paths, so the ledger records it: held -> released,
 *     the open units of a committed one -> cancelled (restockable);
 *  2. a reservation and its units are deleted only when no stock_ledger row and no oversell_event refers to them
 *     (unlinked units never have one); otherwise they stay and are listed;
 *  3. the idempotency rows of the refs left without a reservation are deleted (the keys audit_log names for them);
 *     the keys of a kept reservation stay, so a late retry under one of them still replays its answer;
 *  4. --heartbeats-until: ALL of the channel's channel_health rows received at or before that time, and ALL its
 *     heartbeat idempotency rows created at or before it (heartbeats name no order: the prefix does not apply);
 *  5. every deleted reservation is audited `reservation.purged`, the run `purge.test_refs` (actor
 *     system:purge_test_refs, with --actor as `by`). Ledger and audit rows are never deleted.
 *
 * Refused (exit 1) unless app.env says `environment=staging` (the file itself: no CW_* override) and the schema is
 * cw_staging or cw_test_*, and on a channel in mode `live`. The prefix: 4-32 of A-Z a-z 0-9 . : - with at least one
 * letter.
 * Exit codes: 0 ok (dry run included) · 1 refused, or a reservation could not be neutralised (the rest are done;
 * the failures are listed last) · 2 usage · 3 cannot run.
 */

use CW\Clock;
use CW\Config;
use CW\CwException;
use CW\Ops\Cli;
use CW\Ops\TestRefPurge;
use CW\Reservations;
use CW\Stock;

require dirname(__DIR__) . '/vendor/autoload.php';

const USAGE = 'usage: php bin/purge_test_refs.php --channel=<code> --prefix=<order_ref prefix> [--heartbeats-until=<ISO time>] [--actor=<who>] [--apply]';

exit(Cli::main('purge_test_refs', ['channel:', 'prefix:', 'heartbeats-until:', 'actor:', 'apply'], USAGE, static function (Cli $cli, array $opts): int {
    // getopt() silently drops "--prefix=" and would take the next option as the value of "--prefix": refuse both.
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
        if (in_array($arg, ['--channel=', '--prefix=', '--heartbeats-until=', '--actor='], true)) {
            throw new InvalidArgumentException("{$arg} has no value");
        }
    }
    foreach (['channel', 'prefix', 'heartbeats-until', 'actor'] as $k) {
        $v = $opts[$k] ?? null;
        if (is_array($v)) {
            throw new InvalidArgumentException("--{$k} given more than once");
        }
        if (is_string($v) && str_starts_with($v, '--')) {
            throw new InvalidArgumentException("--{$k} needs a value (write --{$k}=<value>)");
        }
    }
    $channel = $opts['channel'] ?? null;
    $prefix = $opts['prefix'] ?? null;
    if (!is_string($channel) || !is_string($prefix)) {
        throw new InvalidArgumentException(USAGE);
    }
    $problem = TestRefPurge::prefixProblem($prefix);
    if ($problem !== null) {
        throw new InvalidArgumentException("--prefix: {$problem}");
    }
    $until = null;
    if (is_string($opts['heartbeats-until'] ?? null)) {
        try {
            $until = Clock::parse($opts['heartbeats-until'], 'heartbeats-until');
        } catch (CwException $e) {
            throw new InvalidArgumentException('--' . $e->getMessage());
        }
        if ($until > Clock::now()) {
            throw new InvalidArgumentException('--heartbeats-until is in the future');
        }
    }
    $refusal = TestRefPurge::stagingRefusal(Config::load(), $cli->schema);
    if ($refusal !== null) {
        $cli->error("REFUSED: {$refusal}");
        return Cli::PROBLEM;
    }
    $actor = $opts['actor'] ?? null;
    if (!is_string($actor)) {
        $actor = getenv('SUDO_USER') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '') ?: get_current_user();
    }
    $actor = mb_strcut(trim((string) $actor), 0, 64, 'UTF-8');
    $apply = array_key_exists('apply', $opts);

    $purge = new TestRefPurge($cli->db, new Reservations($cli->db, new Stock($cli->db)));
    try {
        $r = $apply ? $purge->apply($channel, $prefix, $until, $actor) : $purge->plan($channel, $prefix, $until);
    } catch (CwException $e) {
        $cli->error(($e->errorCode === 'channel_live' ? 'REFUSED: ' : '') . "{$e->errorCode}: {$e->getMessage()}");
        return Cli::PROBLEM;
    }

    $t = $r['totals'];
    $out = ["channel {$r['channel']} (id {$r['channel_id']}, mode {$r['mode']}) [{$cli->schema}]; prefix {$r['prefix']}"];
    $out[] = sprintf('reservations: %d (release %d, cancel %d units); delete %d with %d units; keep %d',
        $t['reservations'], $t['to_release'], $t['to_cancel_units'], $t['to_delete_reservations'], $t['to_delete_units'], $t['kept_reservations']);
    foreach (array_slice($r['reservations'], 0, 200) as $x) {
        $out[] = sprintf('  %s %s%s origin=%s units=%d %s linked=%d ledger=%d oversell=%d%s -> %s',
            $x['order_ref'], $x['status'], $x['tombstone'] ? ' (tombstone)' : '', $x['origin'], $x['units'],
            json_encode((object) $x['by_state']), $x['linked_units'], $x['ledger_rows'], $x['oversell_events'],
            $x['neutralise'] === null ? '' : ' then ' . ($x['neutralise'] === 'release' ? 'release' : 'cancel ' . count($x['open_units']) . ' units'),
            $x['delete'] ? 'delete' : 'keep (' . implode(', ', $x['keep_why']) . ')');
    }
    if (count($r['reservations']) > 200) {
        $out[] = sprintf('  ... %d more', count($r['reservations']) - 200);
    }
    $out[] = "idempotency rows of these refs: {$t['idempotency_rows']}" . ($apply ? '' : ' (plus the rows of the release/cancel calls above)')
        . ($t['idempotency_rows_kept'] > 0 ? "; {$t['idempotency_rows_kept']} kept (their reservation stays)" : '');
    if ($r['heartbeats'] !== null) {
        $h = $r['heartbeats'];
        $out[] = sprintf('heartbeats until %s: %d channel_health rows (first %s, last %s; from %s; connector %s) and %d heartbeat idempotency rows',
            $h['until'], $h['rows'], $h['first'] ?? '-', $h['last'] ?? '-', implode(',', $h['remote_ips']) ?: '-',
            implode(',', $h['connector_versions']) ?: '-', $h['idempotency_rows']);
    }
    if (!$apply) {
        $out[] = 'dry run: nothing written; run again with --apply';
        fwrite(STDOUT, implode("\n", $out) . "\n");
        return Cli::OK;
    }
    $d = $r['done'];
    $out[] = sprintf('done: released %d, cancelled %d units; deleted %d reservations (%d units), %d idempotency rows, %d channel_health rows, '
        . '%d heartbeat idempotency rows; audited purge.test_refs by %s',
        $d['released'], $d['cancelled_units'], $d['deleted_reservations'], $d['deleted_units'], $d['idempotency_rows'], $d['channel_health_rows'],
        $d['heartbeat_idempotency_rows'], $actor);
    if ($d['idempotency_rows_kept'] > 0) {
        $out[] = "kept {$d['idempotency_rows_kept']} idempotency rows of the kept reservations (a late retry still replays)";
    }
    foreach ($d['kept'] as $k) {
        $out[] = "kept: {$k['order_ref']} (" . implode(', ', $k['why']) . ')';
    }
    foreach ($d['failed'] as $f) {
        $out[] = "FAILED: {$f['order_ref']} ({$f['step']}): {$f['error']}: {$f['message']}";
    }
    fwrite(STDOUT, implode("\n", $out) . "\n");
    return $d['failed'] === [] ? Cli::OK : Cli::PROBLEM;
}));
