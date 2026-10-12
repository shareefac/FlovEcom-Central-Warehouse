<?php

declare(strict_types=1);

/**
 * The check on arrival (CW\Mapping\ArrivalCheck, docs/decisions.md M64, M65; docs/ops.md): a rules-only computer check that gives a
 * variant nobody has looked at a suggestion when its barcode, or a web address copied from the main store, points at an item. It
 * only suggests; a person confirms every match.
 *
 *   php bin/check_arrivals.php [--apply] [--channel=<code>] [--backfill] [--limit=<variants>] [--max-seconds=<seconds>]
 *                              [--after-id=<listing id>] [--list] [--db=<schema>] [--admin]
 *   php bin/check_arrivals.php --listing=<listing id>
 *   php bin/check_arrivals.php --score --channel=<code> [--run=<run id>]
 *
 * DRY RUN by default: it reads, counts and prints, and writes NOTHING (no suggestion, no mark on a profile, no run row, no audit
 * row). --apply writes: one transaction per variant, and one audit row (mapping.arrival_check) for the run.
 *
 *   (no --channel)   the timer's form: the variants marked "to check" of every store named in the setting mapping.arrival_stores.
 *                    With no store in the setting it prints "switched off" and exits 0. A store with more waiting than the
 *                    setting mapping.arrival_queue_alarm is left for a hand run.
 *   --channel=<code> one store, by hand: its queue whatever its size. --apply refuses a store that is not in the setting; a dry
 *                    run may look at any store.
 *   --backfill       (with --channel) the unmatched variants of that store that were there BEFORE the check (no mark), instead of
 *                    the queue. The timer never takes those.
 *   --limit, --max-seconds   instead of the settings mapping.arrival_max_per_run and mapping.arrival_max_seconds, for a hand run.
 *   --after-id       only listings above this id: go on where a run stopped (it prints the id).
 *   --list           one line per variant looked at.
 *   --listing=<id>   explains one variant: what the check reads and what it would say. Always dry.
 *   --score          read only: how the rules compare with a judged run's own clue (its lane and lane target) and with the matches
 *                    people confirmed, for the variants of the store that have a suggestion of that run (the newest judged run of
 *                    the store unless --run names one). Prints the counts and every difference.
 *
 * The command line is read whole or not at all (Ops\Cli::unreadable): an option this tool does not have (a misspelt --channel
 * would otherwise leave the timer's form, every store), a value on a flag (`--apply=false` would otherwise be `--apply`), an
 * option with no value or given twice, or a stray word ends it with exit 2 before anything is read. Values are written with "=".
 *
 * Exit codes: 0 done (also: switched off, a store left for a hand run) · 1 the database refused a variant (it stays to check) ·
 * 2 usage · 3 cannot run.
 */

use CW\CwException;
use CW\Mapping\ArrivalCheck;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(ArrivalCheck::JOB, ['apply', 'channel:', 'backfill', 'limit:', 'max-seconds:', 'after-id:', 'list', 'listing:', 'score', 'run:'],
    "usage: php bin/check_arrivals.php [--apply] [--channel=<code>] [--backfill] [--limit=<variants>] [--max-seconds=<seconds>] [--after-id=<listing id>] [--list]\n"
    . "       php bin/check_arrivals.php --listing=<listing id>\n"
    . '       php bin/check_arrivals.php --score --channel=<code> [--run=<run id>]',
    static function (Cli $cli, array $opts): int {
        $has = static fn (string $o): bool => array_key_exists($o, $opts);
        $apply = $has('apply');
        $code = null;
        if ($has('channel')) {
            $code = $opts['channel'];
            if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $code) !== 1) {
                throw new InvalidArgumentException('--channel must be a channel code (e.g. electrofag)');
            }
            if ($cli->db->value('SELECT 1 FROM channel WHERE code = ?', [$code]) === null) {
                throw new InvalidArgumentException("there is no channel {$code}");
            }
        }
        $check = new ArrivalCheck($cli->db);
        $json = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($has('listing')) {
            if ($apply || $has('backfill') || $has('score')) {
                throw new InvalidArgumentException('--listing explains one variant and writes nothing: give it alone');
            }
            $look = $check->look(Cli::intOpt($opts, 'listing', 0, 1, 4_294_967_295));
            $cli->log('DRY RUN (nothing written) ' . ArrivalCheck::line($look, ['result' => $look['action'], 'outcome' => $look['outcome'], 'proposal_id' => null]));
            fwrite(STDOUT, json_encode(array_diff_key($look, ['snapshot' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            return Cli::OK;
        }

        if ($has('score')) {
            if ($apply || $has('backfill') || $code === null) {
                throw new InvalidArgumentException('--score reads only and names its store: --score --channel=<code> [--run=<run id>]');
            }
            $run = $opts['run'] ?? null;
            if ($run !== null && (!is_string($run) || preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $run) !== 1)) {
                throw new InvalidArgumentException('--run must be a run id');
            }
            try {
                $s = $check->score($code, $run);
            } catch (CwException $e) {
                throw new InvalidArgumentException($e->getMessage());
            }
            $cli->log("SCORE (read only) store={$s['store']} run={$s['run_id']} rules=" . ArrivalCheck::engine() . " variants={$s['variants']} ms={$s['ms']}");
            $cli->log('what the rules say now, by outcome: ' . $json($s['outcomes']));
            $same = $s['t1']['same_clue_same_item'] + $s['t1']['both_no_clue'];
            $cli->log(sprintf('T1 the clue and its item, against the run\'s own (lane, lane target): agree %d of %d (%s%%) %s', $same, $s['variants'],
                $s['variants'] === 0 ? '-' : number_format(100 * $same / $s['variants'], 1), $json($s['t1'])));
            foreach ($s['t1_misses'] as $m) {
                $cli->log('  T1 ' . $m);
            }
            $cli->log('T2 where the rules say "match", against the item the run proposed: ' . $json($s['t2_run']));
            foreach ($s['t2_run_misses'] as $m) {
                $cli->log('  T2 ' . $m);
            }
            $cli->log('T2 the variants a person has matched, against the item the clue names: ' . $json($s['t2_person']));
            foreach ($s['t2_person_misses'] as $m) {
                $cli->log('  T2 ' . $m);
            }
            return Cli::OK;
        }

        if ($has('run')) {
            throw new InvalidArgumentException('--run goes with --score');
        }
        if ($has('backfill') && $code === null) {
            throw new InvalidArgumentException('--backfill names its store: --backfill --channel=<code>');
        }
        $stores = ArrivalCheck::stores($cli->db);
        if ($apply && $code !== null && !in_array($code, $stores, true)) {
            throw new InvalidArgumentException("the check on arrival is not switched on for {$code}: name it in the setting mapping.arrival_stores first (a run without --apply may look at it)");
        }
        $run = ['apply' => $apply, 'channel' => $code, 'backfill' => $has('backfill'), 'after_id' => Cli::intOpt($opts, 'after-id', 0, 0, 4_294_967_295)];
        if ($has('limit')) {
            $run['limit'] = Cli::intOpt($opts, 'limit', 0, 1, 1_000_000);
        }
        if ($has('max-seconds')) {
            $run['max_seconds'] = Cli::intOpt($opts, 'max-seconds', 0, 1, 86_400);
        }
        if ($has('list')) {
            $run['each'] = static function (string $line) use ($cli): void {
                $cli->log('  ' . $line);
            };
        }
        $r = $check->run($run);
        $dry = $apply ? '' : 'DRY RUN (nothing written) ';
        if ($r['per_store'] === []) {
            $cli->log("{$dry}switched off: no store is named in the setting mapping.arrival_stores (nothing looked at)");
            return Cli::OK;
        }
        $failed = 0;
        foreach ($r['per_store'] as $store => $s) {
            if (($s['skipped'] ?? null) === 'above_alarm') {
                $cli->log("{$dry}store={$store} waiting={$s['waiting']}: more than the timer takes by itself (the setting mapping.arrival_queue_alarm): "
                    . "left for a hand run, --channel={$store}");
                continue;
            }
            $failed += $s['failed'];
            $cli->log(sprintf('%sstore=%s waiting=%d looked=%d %s=%d %s=%d %s=%d same_as_before=%d changed_meanwhile=%d failed=%d no_suggestion=%s%s', $dry, $store, $s['waiting'],
                $s['looked'], $apply ? 'match' : 'would_match', $s['match'], $apply ? 'conflict' : 'would_conflict', $s['conflict'], $apply ? 'not_sure' : 'would_not_sure',
                $s['not_sure'], $s['kept'], $s['moved'], $s['failed'], $json($s['left'] === [] ? new stdClass() : $s['left']),
                $r['stopped'] !== null && $s['last_id'] !== null ? " (to go on: --after-id={$s['last_id']})" : ''));
        }
        $cli->log(sprintf('%srules=%s run=%s looked=%d%s%s ms=%d', $dry, ArrivalCheck::engine(), $r['run_id'] ?? '-', $r['looked'],
            $r['stopped'] === null ? '' : " stopped={$r['stopped']}", $stores === [] ? ' (the setting names no store: the timer does nothing)' : '', $r['ms']));
        return $failed > 0 ? Cli::PROBLEM : Cli::OK;
    }, strict: true));
