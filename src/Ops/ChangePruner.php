<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Clock;
use CW\Db;
use DateTimeImmutable;

/**
 * Prunes the change feed (stock_change) to the last N days (bin/prune_changes.php, nightly).
 *
 * A listing's version is MAX(seq) over the rows of its scopes (its listing rows, its item's rows,
 * its channel's rows and global rows; D38). Deleting every old row would move the version of a
 * listing that has not changed for N days backwards (to 0 when nothing newer exists), so a site
 * starting from an empty state would never accept the value. The pruner therefore keeps the
 * NEWEST row of every scope (channel_id, sku_id, listing_id) however old it is and deletes only
 * rows that a newer row of the same scope supersedes: every version stays exactly as it was
 * (H2). Rows newer than the cutoff are never touched, so the feed's overlap window is safe.
 *
 * Works through the old part of the table in windows of WINDOW rows by seq (a PK range): reads
 * the window, looks up the newest seq of each scope in it once (scope index), and deletes the
 * window's old rows below it in short autocommit statements: no long transaction, no lock on
 * the feed clock, nothing a concurrent writer or reader waits for.
 */
final class ChangePruner
{
    public const KEEP_DAYS = 14;
    public const WINDOW = 5000;
    private const DELETE_CHUNK = 1000;

    public function __construct(private readonly Db $db, private readonly int $window = self::WINDOW)
    {
        if ($window < 1) {
            throw new \InvalidArgumentException('window must be >= 1');
        }
    }

    /**
     * @param float|null $deadline microtime(true) after which no new window is started
     * @return array{deleted: int, kept_newest: int, examined: int, windows: int, up_to_seq: int, complete: bool}
     */
    public function prune(DateTimeImmutable $cutoff, bool $dryRun = false, ?float $deadline = null): array
    {
        $cut = Clock::db($cutoff);
        $upTo = (int) ($this->db->value('SELECT MAX(seq) FROM stock_change WHERE created_at < ?', [$cut]) ?? 0);
        $out = ['deleted' => 0, 'kept_newest' => 0, 'examined' => 0, 'windows' => 0, 'up_to_seq' => $upTo, 'complete' => true];
        $lo = 0;
        while ($lo < $upTo) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $out['complete'] = false;
                break;
            }
            // Window = the next $window rows by seq (jumps over gaps left by earlier prunes).
            $hi = (int) ($this->db->value(
                'SELECT MAX(seq) FROM (SELECT seq FROM stock_change WHERE seq > ? AND seq <= ? ORDER BY seq LIMIT ' . $this->window . ') w',
                [$lo, $upTo],
            ) ?? 0);
            if ($hi === 0) {
                break;
            }
            // The window's rows, and the newest seq of each scope they belong to: one backward
            // index lookup per distinct scope on (channel_id, sku_id, listing_id, seq). (A per-row
            // "EXISTS a newer row" probe walks a scope's older rows each time: 27 s instead of
            // 47 ms for a 5,000-row window of one busy item.)
            $rows = $this->db->all(
                'SELECT seq, channel_id, sku_id, listing_id, created_at < ? AS old FROM stock_change WHERE seq > ? AND seq <= ?',
                [$cut, $lo, $hi],
            );
            $newest = [];
            foreach ($this->db->all(
                'SELECT s.channel_id, s.sku_id, s.listing_id, (SELECT n.seq FROM stock_change n FORCE INDEX (ix_stock_change_scope) '
                . '  WHERE n.channel_id <=> s.channel_id AND n.sku_id <=> s.sku_id AND n.listing_id <=> s.listing_id '
                . '  ORDER BY n.seq DESC LIMIT 1) AS newest '
                . 'FROM (SELECT DISTINCT channel_id, sku_id, listing_id FROM stock_change WHERE seq > ? AND seq <= ?) s',
                [$lo, $hi],
            ) as $r) {
                $newest[self::scope($r)] = (int) $r['newest'];
            }
            $superseded = [];
            $examined = 0;
            foreach ($rows as $r) {
                if ((int) $r['old'] !== 1) {
                    continue;
                }
                $examined++;
                if ((int) $r['seq'] < ($newest[self::scope($r)] ?? 0)) {
                    $superseded[] = (int) $r['seq'];
                }
            }
            if (!$dryRun) {
                foreach (array_chunk($superseded, self::DELETE_CHUNK) as $chunk) {
                    $this->db->exec('DELETE FROM stock_change WHERE seq IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
                }
            }
            $out['deleted'] += count($superseded);
            $out['examined'] += $examined;
            $out['kept_newest'] += $examined - count($superseded);
            $out['windows']++;
            $lo = $hi;
        }
        return $out;
    }

    /** @param array<string, mixed> $r */
    private static function scope(array $r): string
    {
        return ($r['channel_id'] ?? '-') . ':' . ($r['sku_id'] ?? '-') . ':' . ($r['listing_id'] ?? '-');
    }

    public static function cutoff(int $keepDays, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? Clock::now())->modify("-{$keepDays} days");
    }
}
