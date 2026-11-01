<?php

declare(strict_types=1);

namespace CW\Queues;

use CW\Clock;
use CW\Db;
use CW\Settings;

/**
 * The nightly Confirm all (the owner, 1 Nov 2026: "Make it automatic", then "Yes, build it" on NightlyConfirm.dc.html; docs/decisions.md
 * NC1-NC12): once a night, while the switch relay.nightly_confirm is on, CW does what a person's "Confirm all ready" does: the ready ERPNext
 * corrections (the same rules a-f, Corrections::readiness()), each confirmed through the same gate under its locks, within the press's
 * deadline, at most relay.nightly_confirm_max of them; the doubtful ones stay in Approvals for a person. bin/confirm_corrections_nightly.php
 * runs tick() every five minutes (cron); tick() does something only:
 *   - while the switch is on;
 *   - inside the night's window: [the UK time relay.nightly_confirm_at, one hour later), in UK time whatever the clock change (night());
 *     a window that crosses midnight belongs to the night it started;
 *   - when no nightly run has started for that night: its run row (Corrections::RUN_ACTION, entity `nightly:<date>`) is looked for and
 *     written under the night's named lock (lockName(): server-wide, freed on disconnect), so two ticks at once make one run, a second tick
 *     the same night does nothing, and a run that stopped half way is not started again that night.
 * Its press is Corrections::confirmNightly(): the job's own system caller, let through there and nowhere else. The note on each confirmation
 * says whose switch it is: "Nightly Confirm all (setting on since <UK date, time>, by <person>)".
 */
final class NightlyConfirm
{
    public const JOB = Corrections::NIGHTLY_JOB;
    public const SWITCH = Corrections::NIGHTLY_SWITCH;
    public const AT = 'relay.nightly_confirm_at';
    public const MAX = 'relay.nightly_confirm_max';
    public const DEFAULT_AT = '04:30';
    public const DEFAULT_MAX = 500;
    /** How long after the time it runs a tick may still start the night's run. */
    public const WINDOW_MINUTES = 60;
    /** The longest one night's press takes (no web page waits for it; under the five-minute cron). */
    public const SECONDS = Corrections::NIGHTLY_SECONDS;
    public const UK = 'Europe/London';
    /** The note on each confirmation of a nightly run (the coordinator's words of 1 Nov 2026). */
    public const NOTE = 'Nightly Confirm all (setting on since %s, by %s)';

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $now;

    /** @param (\Closure(): \DateTimeImmutable)|null $now the window's clock (tests move it; the bookings keep the database's) */
    public function __construct(private readonly Db $db, ?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * The night $now is inside the window of, as its UK date (the day the window opened), or null: outside it, or $at is not a time. The
     * window is [$at on that UK day, one hour later), worked out in Europe/London: across a clock change it is still an hour of real time
     * from that UK time (25 Oct: 04:30 UK is 03:30 UTC the day before, 04:30 UTC that day); in the hour that happens twice it opens once.
     */
    public static function night(\DateTimeImmutable $now, string $at): ?string
    {
        if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $at) !== 1) {
            return null;
        }
        $uk = new \DateTimeZone(self::UK);
        $today = $now->setTimezone($uk);
        foreach ([$today, $today->modify('-1 day')] as $day) {
            $start = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $at . ':00', $uk);
            $t = $now->getTimestamp();
            if ($t >= $start->getTimestamp() && $t < $start->getTimestamp() + 60 * self::WINDOW_MINUTES) {
                return $day->format('Y-m-d');
            }
        }
        return null;
    }

    /** The night's named lock (one start at a time per schema): the schema's name in it, so two test schemas never wait for each other. */
    public static function lockName(Db $db): string
    {
        return 'cw_nightly_confirm:' . sha1((string) ($db->settings->database ?? ''));
    }

    /** @return array{on: bool, at: string, max: int} the settings as the job reads them (their defaults while 0055 is not there) */
    public function settings(): array
    {
        $s = new Settings($this->db);
        $at = $s->has(self::AT) ? $s->get(self::AT) : null;
        $max = $s->has(self::MAX) ? $s->get(self::MAX) : null;
        return ['on' => Settings::flag($this->db, self::SWITCH, false), 'at' => is_string($at) && $at !== '' ? $at : self::DEFAULT_AT,
            'max' => is_int($max) && $max >= 1 ? min($max, 5000) : self::DEFAULT_MAX];
    }

    /**
     * One cron tick (class docblock). `result`: off, outside, busy (another start holds the night's lock), done (the night has its run
     * already), ran (this tick ran it: `run` its run row, with what it confirmed and left).
     *
     * @return array{result: string, night?: string, run?: int, confirmed?: int, booked?: int, left?: int}
     */
    public function tick(): array
    {
        $set = $this->settings();
        if (!$set['on']) {
            return ['result' => 'off'];
        }
        $night = self::night(($this->now)(), $set['at']);
        if ($night === null) {
            return ['result' => 'outside', 'at' => $set['at']];
        }
        $lock = self::lockName($this->db);
        if ((int) $this->db->value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return ['result' => 'busy', 'night' => $night];
        }
        try {
            $done = $this->runOf($night);
            if ($done !== null) {
                return ['result' => 'done', 'night' => $night, 'run' => $done];
            }
            $corrections = new Corrections($this->db);
            $ready = array_values(array_map(static fn (array $r): int => (int) $r['id'],
                array_filter($corrections->readiness(), static fn (array $r): bool => $r['why'] === null)));
            $press = $corrections->confirmNightly($night, $ready, self::note($this->db), $set['max']);
            $run = $corrections->run($press['run']);
            return ['result' => 'ran', 'night' => $night, 'run' => $press['run'], 'confirmed' => count($press['confirmed']), 'booked' => (int) ($run['booked'] ?? 0),
                'left' => count($press['left'])];
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** The run row of a night's run, if one has started (its entity, by audit_log's entity index). */
    public function runOf(string $night): ?int
    {
        $id = $this->db->value('SELECT id FROM audit_log WHERE entity_type = ? AND entity_id = ? AND action = ? ORDER BY id LIMIT 1',
            [Corrections::TABLE, 'nightly:' . $night, Corrections::RUN_ACTION]);
        return $id === null ? null : (int) $id;
    }

    /** The latest nightly run (Corrections::run(), with its night), or null when none ran yet. @return array<string, mixed>|null */
    public function lastRun(): ?array
    {
        $id = $this->db->value("SELECT id FROM audit_log WHERE entity_type = ? AND entity_id LIKE 'nightly:%' AND action = ? ORDER BY id DESC LIMIT 1",
            [Corrections::TABLE, Corrections::RUN_ACTION]);
        return $id === null ? null : (new Corrections($this->db))->run((int) $id);
    }

    /**
     * The note on each confirmation of a nightly run: since when the switch is on (its row's last change, UK time) and by whom (their name;
     * a server tool by its name).
     */
    public static function note(Db $db): string
    {
        $row = $db->one('SELECT updated_actor, updated_at FROM app_setting WHERE setting_key = ?', [self::SWITCH]);
        $when = $row === null ? '' : Clock::fromDb((string) $row['updated_at'])->setTimezone(new \DateTimeZone(self::UK))->format('j M Y, H:i');
        $actor = (string) ($row['updated_actor'] ?? '');
        $who = $actor;
        if (preg_match('/^staff:([1-9][0-9]*)$/D', $actor, $m) === 1) {
            $who = (string) ($db->value('SELECT display_name FROM staff_user WHERE id = ?', [(int) $m[1]]) ?? $actor);
        }
        return mb_substr(sprintf(self::NOTE, $when, $who), 0, QueueRules::NOTE_MAX);
    }

    /** One log line of a tick, as the other bin/ jobs write them. @param array<string, mixed> $r */
    public static function line(array $r): string
    {
        return match ($r['result']) {
            'off' => 'off: ' . self::SWITCH . ' is off',
            'outside' => 'outside the night\'s window (' . ($r['at'] ?? '') . ' UK, for ' . self::WINDOW_MINUTES . ' minutes): nothing to do',
            'busy' => "busy: another start holds the night's lock ({$r['night']}): nothing done",
            'done' => "done already tonight ({$r['night']}): run {$r['run']}",
            default => "ran {$r['night']}: run {$r['run']}, {$r['confirmed']} confirmed ({$r['booked']} booked), {$r['left']} left; "
                . "done page /ui/queues/corrections/confirm-all/{$r['run']}",
        };
    }
}
