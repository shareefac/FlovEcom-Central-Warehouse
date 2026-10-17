<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Clock;
use CW\Db;
use CW\Settings;

/**
 * The nightly clean-up of the tables that grow with every call (go-live plan part 6 "Housekeeping on CW"; docs/decisions.md D14,
 * D18, PF11). Each retention is a setting:
 *
 *   idempotency    the stored answers of the stores' calls, older than housekeeping.idempotency_keep_days (30 at the least: a store
 *                  keeps its messages 14 days, plus a replay after a restore, D14). A key pruned and sent again books again only
 *                  where nothing else stops it: a relayed document line is held by relay_line (PF1), a queue row by its status.
 *                  Movement request keys stay: bookings made before relay_line have no durable line claim.
 *   channel_health the stores' heartbeats older than housekeeping.channel_health_keep_days, except each store's newest one (the Stores
 *                  page and the alerts read it: a store silent for longer must still read "last contact <date>", not "never").
 *   audit_log      older than housekeeping.audit_keep_years (7 at the least: records are kept "year end + 6 years"). The app login has
 *                  no DELETE on audit_log (append-only, Grants), so with it this part is refused by the database and reported as
 *                  kept; only the admin login (bin/housekeeping.php --admin, a person's decision) removes audit rows.
 *
 * In chunks of housekeeping.chunk_rows, each its own autocommit DELETE … ORDER BY … LIMIT on the table's time index, oldest first,
 * until nothing is left or housekeeping.max_seconds have passed (the rest goes the next night). The time is checked before EVERY chunk,
 * the first of each table and store included, so no chunk starts once it has passed (review finding 9 of 14 Oct 2026). Never inside a transaction. A dry run
 * counts what would go and removes nothing.
 */
final class Housekeeping
{
    private const DENIED = 1142;

    /** @var \Closure(): int the monotonic clock in nanoseconds (hrtime; tests move it) */
    private readonly \Closure $tick;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock the wall clock of the cut-offs
     * @param (\Closure(): int)|null $tick the monotonic clock of the time limit, nanoseconds
     */
    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null, ?\Closure $tick = null)
    {
        $this->tick = $tick ?? static fn (): int => (int) hrtime(true);
    }

    /**
     * @return array<string, array{cutoff: string, removed: int, kept_reason?: string, done: bool}> table => what happened
     */
    public function run(Settings $s, bool $dryRun = false): array
    {
        if ($this->db->inTransaction()) {
            throw new \LogicException('housekeeping deletes in autocommit chunks: not inside a transaction');
        }
        $now = ($this->clock ?? static fn (): \DateTimeImmutable => Clock::now())();
        $chunk = max(1, Alerts::int($s, 'housekeeping.chunk_rows', 1000));
        $deadline = ($this->tick)() + max(1, Alerts::int($s, 'housekeeping.max_seconds', 300)) * 1_000_000_000;
        $days = static fn (string $key, int $default, int $min): int => max($min, Alerts::int($s, $key, $default));
        $out = [];

        $cut = Clock::db($now->modify('-' . $days('housekeeping.idempotency_keep_days', 30, 30) . ' days'));
        // Pre-relay_line bookings have only their request key. Keep movement keys: a restore must never book them twice.
        $eligible = "created_at < ? AND path <> '/v1/movements'";
        $out['idempotency'] = $this->prune('idempotency', 'DELETE FROM idempotency WHERE ' . $eligible . ' ORDER BY created_at LIMIT ' . $chunk,
            'SELECT COUNT(*) FROM idempotency WHERE ' . $eligible, [$cut], $cut, $chunk, $deadline, $dryRun);

        $cut = Clock::db($now->modify('-' . $days('housekeeping.channel_health_keep_days', 30, 7) . ' days'));
        $removed = 0;
        $done = true;
        foreach ($this->db->all('SELECT channel_id, MAX(id) AS newest FROM channel_health GROUP BY channel_id ORDER BY channel_id') as $c) {
            $r = $this->prune('channel_health', 'DELETE FROM channel_health WHERE channel_id = ? AND id < ? AND received_at < ? ORDER BY id LIMIT ' . $chunk,
                'SELECT COUNT(*) FROM channel_health WHERE channel_id = ? AND id < ? AND received_at < ?', [(int) $c['channel_id'], (int) $c['newest'], $cut],
                $cut, $chunk, $deadline, $dryRun);
            $removed += $r['removed'];
            $done = $done && $r['done'];
        }
        $out['channel_health'] = ['cutoff' => $cut, 'removed' => $removed, 'done' => $done];

        $cut = Clock::db($now->modify('-' . $days('housekeeping.audit_keep_years', 7, 7) . ' years'));
        try {
            $out['audit_log'] = $this->prune('audit_log', 'DELETE FROM audit_log WHERE created_at < ? ORDER BY created_at LIMIT ' . $chunk,
                'SELECT COUNT(*) FROM audit_log WHERE created_at < ?', [$cut], $cut, $chunk, $deadline, $dryRun);
        } catch (\PDOException $e) {
            if (Db::driverCode($e) !== self::DENIED) {
                throw $e;
            }
            $out['audit_log'] = ['cutoff' => $cut, 'removed' => 0, 'kept_reason' => 'this login may not delete audit rows (append-only); they are kept', 'done' => true];
        }
        return $out;
    }

    /**
     * @param list<mixed> $params
     * @return array{cutoff: string, removed: int, done: bool}
     */
    private function prune(string $table, string $delete, string $count, array $params, string $cut, int $chunk, int $deadline, bool $dryRun): array
    {
        if ($dryRun) {
            if ($table === 'audit_log') {
                // A dry run of the audit part asks the database whether this login may delete there at all (nothing matches 1 = 0).
                $this->db->exec('DELETE FROM audit_log WHERE 1 = 0');
            }
            return ['cutoff' => $cut, 'removed' => (int) $this->db->value($count, $params), 'done' => true];
        }
        $removed = 0;
        while (true) {
            if (($this->tick)() >= $deadline) {
                return ['cutoff' => $cut, 'removed' => $removed, 'done' => false];
            }
            $n = $this->db->exec($delete, $params);
            $removed += $n;
            if ($n < $chunk) {
                return ['cutoff' => $cut, 'removed' => $removed, 'done' => true];
            }
        }
    }
}
