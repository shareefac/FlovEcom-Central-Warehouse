<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Clock;
use CW\Db;
use CW\Settings;

/**
 * Everything bin/health_alert.php checks (go-live plan part 6 "Alerts to a person"; docs/decisions.md PF10), each limit a setting:
 *
 *   per store in Watching only or Live (ChannelHealth, from its newest heartbeat):
 *     stale          no heartbeat for alerts.heartbeat_late_seconds (or never)
 *     outbox_deep    more than alerts.outbox_max_depth messages waiting to reach CW
 *     outbox_old     the oldest of them older than alerts.outbox_max_age_seconds
 *     dead_letters   more than alerts.dead_letters_max messages that failed for good
 *     feed_lag       alerts.feed_lag_max_seconds or more behind CW's stock changes
 *     mode_mismatch  the store runs in another mode than CW has it in
 *   CW itself:
 *     answer_slow    CW's own answer time: alerts.answer_probes times a NEW connection and the reads of a health call (what every API
 *                    call pays first), measured on CW's server; the middle value above alerts.answer_max_ms (answer_failed: a probe
 *                    could not connect). The answer time a store sees at Place order is measured on the store (its connector log).
 *     invariants_failed / invariants_stale
 *                    the newest nightly safety check (integrity_run) found problems, or none finished for
 *                    alerts.invariants_max_age_hours. A system where no check has ever run is quiet (as Home is).
 *
 * Read only. The probe is injectable (tests).
 */
final class Alerts
{
    /** @var \Closure(): float one answer-time probe, in milliseconds (throws when CW cannot be reached) */
    private readonly \Closure $probe;

    /** @param (\Closure(): float)|null $probe */
    public function __construct(private readonly Db $db, ?\Closure $probe = null)
    {
        $this->probe = $probe ?? function (): float {
            $t = hrtime(true);
            $c = Db::connect($this->db->settings);
            $c->value("SELECT mode FROM channel WHERE mode <> 'off' LIMIT 1");
            $c->value('SELECT MAX(seq) FROM stock_change');
            return (hrtime(true) - $t) / 1_000_000;
        };
    }

    /**
     * Every problem now: key (alert_state's), kind, channel code/name/id (null for CW's own), mode, detail (the log line's words), data
     * (the numbers the alert's words take).
     *
     * @return list<array{key: string, kind: string, channel: ?string, channel_id: ?int, name: ?string, mode: ?string, detail: string, data: array<string, int|string|null>}>
     */
    public function problems(Settings $s, ?int $staleAfterSec = null): array
    {
        $limits = [
            'outbox_depth' => self::int($s, 'alerts.outbox_max_depth', 500),
            'outbox_age' => self::int($s, 'alerts.outbox_max_age_seconds', 300),
            'dead_letters' => self::int($s, 'alerts.dead_letters_max', 0),
            'feed_lag' => self::int($s, 'alerts.feed_lag_max_seconds', 60),
        ];
        $stale = $staleAfterSec ?? self::int($s, 'alerts.heartbeat_late_seconds', ChannelHealth::STALE_AFTER_SEC);
        $out = [];
        foreach ((new ChannelHealth($this->db))->problems(null, $stale, $limits) as $p) {
            $out[] = ['key' => $p['problem'] . ':' . $p['channel'], 'kind' => $p['problem'], 'channel' => $p['channel'], 'channel_id' => $p['channel_id'],
                'name' => $p['name'], 'mode' => $p['mode'], 'detail' => $p['detail'], 'data' => $p['data']];
        }
        foreach ([$this->answerTime($s), $this->nightlyCheck($s)] as $p) {
            if ($p !== null) {
                $out[] = $p + ['channel' => null, 'channel_id' => null, 'name' => null, 'mode' => null];
            }
        }
        return $out;
    }

    /** @return array{key: string, kind: string, detail: string, data: array<string, int|string|null>}|null */
    private function answerTime(Settings $s): ?array
    {
        $n = max(1, self::int($s, 'alerts.answer_probes', 3));
        $max = self::int($s, 'alerts.answer_max_ms', 1500);
        $ms = [];
        try {
            for ($i = 0; $i < $n; $i++) {
                $ms[] = (float) ($this->probe)();
            }
        } catch (\Throwable $e) {
            return ['key' => 'answer_failed', 'kind' => 'answer_failed', 'detail' => 'a probe could not reach the database: ' . Cli::describe($e), 'data' => []];
        }
        sort($ms);
        $middle = (int) round($ms[intdiv(count($ms), 2)]);
        if ($middle <= $max) {
            return null;
        }
        return ['key' => 'answer_slow', 'kind' => 'answer_slow', 'detail' => sprintf('CW answered in %d ms (the middle of %d probes; limit %d ms)', $middle, $n, $max),
            'data' => ['ms' => $middle, 'probes' => $n, 'limit' => $max]];
    }

    /** @return array{key: string, kind: string, detail: string, data: array<string, int|string|null>}|null */
    private function nightlyCheck(Settings $s): ?array
    {
        $last = (new IntegrityRuns($this->db))->latest();
        if ($last === null) {
            return null;
        }
        if (!$last['ok']) {
            return ['key' => 'invariants_failed', 'kind' => 'invariants_failed', 'detail' => sprintf('the nightly check of %s found %d problems',
                Clock::iso($last['finished_at']), $last['problems']), 'data' => ['problems' => $last['problems']]];
        }
        $max = self::int($s, 'alerts.invariants_max_age_hours', IntegrityRuns::STALE_HOURS);
        if ($last['hours'] >= $max) {
            return ['key' => 'invariants_stale', 'kind' => 'invariants_stale', 'detail' => sprintf('no nightly check finished for %d hours (limit %d)', $last['hours'], $max),
                'data' => ['hours' => $last['hours'], 'limit' => $max]];
        }
        return null;
    }

    /** An int setting, or $default on a schema without it (or unset). */
    public static function int(Settings $s, string $key, int $default): int
    {
        if (!$s->has($key)) {
            return $default;
        }
        $v = $s->get($key);
        return is_int($v) ? $v : $default;
    }
}
