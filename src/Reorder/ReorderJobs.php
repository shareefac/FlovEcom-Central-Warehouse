<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Settings;

/**
 * The reorder engine's schedule (pack G; docs/decisions.md PG4): bin/reorder_refresh.php runs scheduled() every 5 minutes.
 *
 *   1. fold    CW's own sales record (SalesFold::run): the UK days due (nothing once yesterday is folded; the last days once more a day).
 *   2. demand  the sales worked out again (DemandBuilder::rebuild) once a UK day, from reorder.demand_hour, when no run has finished
 *              well that day; a failed run is tried again after reorder.refresh_minutes. Never while the fold is behind (days left over
 *              after reorder.fold_days_per_run, or another run folding: review finding 3): "waiting for the fold", and the run that
 *              finishes the fold works it out.
 *   3. lines   the list worked out again (ReorderRefresh::all) when the demand was rebuilt in this run, or when the last run started
 *              at least reorder.refresh_minutes ago.
 * Each step under its own named lock ("already running" is not an error: the other run does it). record() keeps each step's last
 * run in reorder_job: started, finished, finished well, how long, what it did, the error (the screens' "Figures from ...").
 */
final class ReorderJobs
{
    public const JOBS = ['fold', 'demand', 'lines'];

    private readonly Settings $settings;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly PurchaseOrders $pos, ?Settings $settings = null, ?\Closure $clock = null)
    {
        $this->settings = $settings ?? new Settings($db);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * Runs $fn as the job $job: its row says when it started, then when it finished, and either its result (finished well) or its error.
     * The error is rethrown.
     *
     * @template T
     * @param \Closure(): \DateTimeImmutable $clock
     * @param \Closure(): T $fn
     * @return T
     */
    public static function record(Db $db, string $job, \Closure $clock, \Closure $fn): mixed
    {
        if (!in_array($job, self::JOBS, true)) {
            throw new \InvalidArgumentException("unknown reorder job {$job}");
        }
        $t0 = hrtime(true);
        $db->exec('INSERT INTO reorder_job (job, started_at, runs) VALUES (?, ?, 1) AS new ON DUPLICATE KEY UPDATE started_at = new.started_at, '
            . 'runs = reorder_job.runs + 1', [$job, Clock::db($clock())]);
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            if ($e instanceof CwException && str_ends_with($e->errorCode, '_running')) {
                throw $e; // another run holds the step's lock: that run records itself
            }
            $db->exec('UPDATE reorder_job SET finished_at = ?, ms = ?, error = ? WHERE job = ?',
                [Clock::db($clock()), intdiv(hrtime(true) - $t0, 1_000_000), mb_substr(($e instanceof CwException ? $e->errorCode . ': ' : '') . $e->getMessage(), 0, 500), $job]);
            throw $e;
        }
        $now = Clock::db($clock());
        $db->exec('UPDATE reorder_job SET finished_at = ?, ok_at = ?, ms = ?, result = CAST(? AS JSON), error = NULL WHERE job = ?',
            [$now, $now, intdiv(hrtime(true) - $t0, 1_000_000), json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $job]);
        return $result;
    }

    /** @return array<string, array<string, mixed>> job => its reorder_job row (a job never run has none) */
    public function state(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT job, started_at, finished_at, ok_at, ms, runs, CAST(result AS CHAR) AS result, error FROM reorder_job') as $r) {
            $out[(string) $r['job']] = $r;
        }
        return $out;
    }

    /**
     * The scheduled run (class docblock). $force: every step now (the fold's days due, the demand and the lines).
     *
     * @return array{fold: array<string, mixed>|string|null, demand: array<string, mixed>|string|null, lines: array<string, mixed>|string|null}
     */
    public function scheduled(bool $force = false): array
    {
        $e = ReorderSettings::engine($this->settings);
        $out = ['fold' => null, 'demand' => null, 'lines' => null];
        $fold = new SalesFold($this->db, $this->settings, $this->clock);
        $foldBehind = false;
        if ($force || $fold->due($e['refold_days']) !== []) {
            $out['fold'] = $this->step('fold', fn (): array => $fold->run());
            // Review finding 3 (Codex, 14 Oct 2026): days still to fold (more than reorder.fold_days_per_run), or another run folding.
            $foldBehind = $out['fold'] === 'already running' || $fold->due($e['refold_days']) !== [];
        }
        $state = $this->state();
        if ($foldBehind && ($force || $this->demandDue($state['demand'] ?? null, $e))) {
            $out['demand'] = 'waiting for the fold'; // worked out by the run that finishes the fold, the same day
        } elseif ($force || $this->demandDue($state['demand'] ?? null, $e)) {
            $out['demand'] = $this->step('demand', function (): array {
                $b = (new DemandBuilder($this->db, $this->settings, $this->clock))->rebuild();
                $this->db->all('ANALYZE TABLE reorder_demand'); // rewritten whole: fresh statistics before the list reads it (PG12)
                return $b;
            });
        }
        $rebuilt = is_array($out['demand']);
        if ($force || $rebuilt || $this->older($state['lines']['started_at'] ?? null, $e['refresh_minutes'])) {
            try {
                $out['lines'] = (new ReorderRefresh($this->db, $this->pos, $this->settings, $this->clock))->all();
            } catch (CwException $x) {
                if ($x->errorCode !== 'refresh_running') {
                    throw $x;
                }
                $out['lines'] = 'already running';
            }
        }
        return $out;
    }

    /** Whether the nightly demand is due (class docblock, step 2). @param array<string, mixed>|null $row @param array<string, mixed> $e */
    public function demandDue(?array $row, array $e): bool
    {
        $now = ($this->clock)()->setTimezone(new \DateTimeZone(SalesFold::TZ));
        if ((int) $now->format('G') < $e['demand_hour']) {
            return false;
        }
        $today = $now->format('Y-m-d');
        $okToday = $row !== null && $row['ok_at'] !== null && Clock::fromDb((string) $row['ok_at'])->setTimezone(new \DateTimeZone(SalesFold::TZ))->format('Y-m-d') === $today;
        if ($okToday) {
            return false;
        }
        return $row === null || $this->older($row['started_at'] ?? null, $e['refresh_minutes']);
    }

    private function older(mixed $at, int $minutes): bool
    {
        if ($at === null) {
            return true;
        }
        return ($this->clock)()->getTimestamp() - Clock::fromDb((string) $at)->getTimestamp() >= $minutes * 60;
    }

    /**
     * One step: its result, or 'already running' when another run holds its lock.
     *
     * @param \Closure(): array<string, mixed> $fn
     * @return array<string, mixed>|string
     */
    private function step(string $job, \Closure $fn): array|string
    {
        try {
            return self::record($this->db, $job, $this->clock, $fn);
        } catch (CwException $x) {
            if (in_array($x->errorCode, ['fold_running', 'build_running'], true)) {
                return 'already running';
            }
            throw $x;
        }
    }
}
