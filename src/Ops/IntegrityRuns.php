<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Db;
use CW\Idempotency;

/**
 * The results of the nightly safety check (bin/invariants.php; integrity_run, 0019; G36, docs/decisions.md Y33): every run writes
 * one row (when it started and finished, whether everything held, how many problems, the first KEEP of them as text, its figures),
 * which the Safety checks page lists and Home turns into a card for the people who look after the system (system.view) when the
 * latest run found a problem or no run has finished for STALE_HOURS. Append-only for the app login.
 */
final class IntegrityRuns
{
    /** Problems kept as text per run (the run's log has them all). */
    public const KEEP = 200;
    /** A check older than this is "not run lately" (it runs every night). */
    public const STALE_HOURS = 36;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Records one run (outside any transaction: the check runs on a read-only snapshot first). $startedAt is the database's clock
     * (SELECT NOW(6) before the run), as finished_at (NOW(6) here): one clock for both.
     *
     * @param list<string> $problems
     * @param array<string, int|string> $stats
     */
    public function record(string $startedAt, array $problems, array $stats, string $runBy): int
    {
        $kept = array_values(array_map(static fn (string $p): string => mb_substr($p, 0, 1000), array_slice($problems, 0, self::KEEP)));
        return $this->db->insert(
            'INSERT INTO integrity_run (started_at, finished_at, ok, problems, details, stats, run_by) VALUES (?, NOW(6), ?, ?, CAST(? AS JSON), CAST(? AS JSON), ?)',
            [$startedAt, $problems === [] ? 1 : 0, count($problems), json_encode($kept, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                Idempotency::json($stats), mb_substr($runBy, 0, 64)],
        );
    }

    /**
     * The newest runs, newest first: id, started/finished, ok, problems, details (list), stats, run_by.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 30): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, started_at, finished_at, ok, problems, CAST(details AS CHAR) AS details, CAST(stats AS CHAR) AS stats, run_by '
            . 'FROM integrity_run ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))) as $r) {
            $details = json_decode((string) $r['details'], true);
            $stats = $r['stats'] === null ? null : json_decode((string) $r['stats'], true);
            $out[] = ['id' => (int) $r['id'], 'started_at' => (string) $r['started_at'], 'finished_at' => (string) $r['finished_at'], 'ok' => (int) $r['ok'] === 1,
                'problems' => (int) $r['problems'], 'details' => is_array($details) ? array_values(array_filter($details, 'is_string')) : [],
                'stats' => is_array($stats) ? $stats : [], 'run_by' => (string) $r['run_by']];
        }
        return $out;
    }

    /**
     * What Home needs: the latest run (ok, problems, when it finished) and how many hours ago it finished; null when no run has
     * been recorded yet (a new system: the page says so, Home stays quiet).
     *
     * @return array{ok: bool, problems: int, finished_at: string, hours: int}|null
     */
    public function latest(): ?array
    {
        $r = $this->db->one('SELECT ok, problems, finished_at, TIMESTAMPDIFF(HOUR, finished_at, NOW(6)) AS hours FROM integrity_run ORDER BY id DESC LIMIT 1');
        return $r === null ? null : ['ok' => (int) $r['ok'] === 1, 'problems' => (int) $r['problems'], 'finished_at' => (string) $r['finished_at'],
            'hours' => max(0, (int) $r['hours'])];
    }
}
