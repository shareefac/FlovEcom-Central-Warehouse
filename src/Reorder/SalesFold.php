<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Settings;

/**
 * CW's own record of each store's sales (pack G; docs/decisions.md PG1, PG2): folds the stock ledger into reorder_sales_day, one UK
 * day at a time, so the demand build and the screens never read the ledger.
 *
 * A day's sales of a store's variant = Σ over the day's ledger rows on the `allocated` bucket of a sale (commit +u), a cancellation
 * (cancel -u) and an uncancellation (uncancel +u), in the variant's own units (÷ the units per sale the unit was sold with), joined
 * through the sold unit (reservation_unit, its primary key) to its order (an opening order, placed before the store connected, is
 * left out: the imported history has it) and to the variant (channel_listing, its primary key). The day is the UK date of the ledger
 * row (its created_at in Europe/London): a cancellation lands on the day it happened. A sale of a variant that was not linked moved no
 * stock, so it has no ledger row and is not in the record (the import counts it as unlinked too).
 *
 *  run()       the days due, oldest first, at most reorder.fold_days_per_run: every UK day from the first (the UK date of the earliest
 *              store connect moment, channel_opening.t0_at, at most DemandBuilder::READ_DAYS back) to yesterday that was never folded,
 *              and each of the last reorder.fold_refold_days days once more on a later UK day (a late write lands). One build at a
 *              time: GET_LOCK('cw_reorder_fold:<schema>', 0), busy -> 409 fold_running.
 *  foldDay()   one UK day, read in ledger-id ranges (the day's first id from ix_stock_ledger_created, then the primary key in steps of
 *              ID_CHUNK), written in ONE short transaction: the day's rows set to 0, then the new figures upserted (never deleted).
 *  starts()    the stores whose sales the demand takes from this record (reorder.own_sales_stores): channel id => the first whole UK
 *              day after the store's connect moment.
 *
 * Reads the ledger only by its primary key and its created_at index; takes no stock lock.
 */
final class SalesFold
{
    public const LOCK_PREFIX = 'cw_reorder_fold:';
    /** Ledger ids read per query. */
    public const ID_CHUNK = 20_000;
    /** Ledger ids read before a day's first id and after its last (rows inserted in the same microsecond in another order). */
    public const ID_SLACK = 1_000;
    public const SALE_TYPES = ['commit', 'cancel', 'uncancel'];
    public const INSERT_CHUNK = 500;
    public const TZ = 'Europe/London';

    private readonly Settings $settings;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?Settings $settings = null, ?\Closure $clock = null)
    {
        $this->settings = $settings ?? new Settings($db);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /** Today in the UK as a day number. */
    public function today(): int
    {
        return DemandMath::day(($this->clock)()->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d'));
    }

    /**
     * Folds the days due (class docblock).
     *
     * @return array{days: list<array{date: string, rows: int, variants: int, units: int}>, first: ?string, last: ?string, more: bool, ms: int}
     */
    public function run(): array
    {
        $t0 = hrtime(true);
        $lock = self::LOCK_PREFIX . (string) $this->db->value('SELECT DATABASE()');
        if ((int) $this->db->value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            throw new CwException('fold_running', 'CW\'s own sales record is being folded right now: try again in a minute', 409);
        }
        try {
            $e = ReorderSettings::engine($this->settings);
            $due = $this->due($e['refold_days']);
            $todo = array_slice($due, 0, $e['fold_days_per_run']);
            $days = [];
            foreach ($todo as $day) {
                $days[] = $this->foldDay(DemandMath::date($day));
            }
            $first = $this->firstDay();
            $last = $this->lastDay();
            return ['days' => $days, 'first' => $first === null ? null : DemandMath::date($first), 'last' => $last === null ? null : DemandMath::date($last),
                'more' => count($due) > count($todo), 'ms' => intdiv(hrtime(true) - $t0, 1_000_000)];
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * The UK days due, oldest first: never folded, or among the last $refold days and last folded on an earlier UK day.
     *
     * @return list<int>
     */
    public function due(int $refold): array
    {
        $first = $this->firstDay();
        $yesterday = $this->today() - 1;
        if ($first === null || $first > $yesterday) {
            return [];
        }
        $done = [];
        foreach ($this->db->all('SELECT fold_date, folded_at FROM reorder_fold_day WHERE fold_date >= ?', [DemandMath::date($first)]) as $r) {
            $done[DemandMath::day((string) $r['fold_date'])] = DemandMath::day(Clock::fromDb((string) $r['folded_at'])->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d'));
        }
        $today = $this->today();
        $out = [];
        for ($d = $first; $d <= $yesterday; $d++) {
            if (!isset($done[$d]) || ($d > $yesterday - $refold && $done[$d] < $today)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** The first UK day of the record: the UK date of the earliest store connect moment, at most DemandBuilder::READ_DAYS back. */
    public function firstDay(): ?int
    {
        $t0 = $this->db->value('SELECT MIN(t0_at) FROM channel_opening WHERE t0_at IS NOT NULL');
        if ($t0 === null) {
            return null;
        }
        $day = DemandMath::day(Clock::fromDb((string) $t0)->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d'));
        return max($day, $this->today() - DemandBuilder::READ_DAYS);
    }

    /** The newest UK day folded (null: none). */
    public function lastDay(): ?int
    {
        $d = $this->db->value('SELECT MAX(fold_date) FROM reorder_fold_day');
        return $d === null ? null : DemandMath::day((string) $d);
    }

    /**
     * The stores whose sales the demand takes from this record: channel id => {code, from (the first whole UK day after the store's
     * connect moment)}. Only the stores named in reorder.own_sales_stores that have a connect moment.
     *
     * @return array<int, array{code: string, from: int}>
     */
    public function starts(): array
    {
        $codes = ReorderSettings::engine($this->settings)['own_stores'];
        if ($codes === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT c.id, c.code, o.t0_at FROM channel c JOIN channel_opening o ON o.channel_id = c.id WHERE o.t0_at IS NOT NULL AND c.code IN ('
            . implode(', ', array_fill(0, count($codes), '?')) . ') ORDER BY c.id', $codes) as $r) {
            $t0 = DemandMath::day(Clock::fromDb((string) $r['t0_at'])->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d'));
            $out[(int) $r['id']] = ['code' => (string) $r['code'], 'from' => $t0 + 1];
        }
        return $out;
    }

    /**
     * The UTC bounds [from, to) of a UK day.
     *
     * @return array{0: string, 1: string}
     */
    public static function bounds(int $day): array
    {
        $tz = new \DateTimeZone(self::TZ);
        $from = new \DateTimeImmutable(DemandMath::date($day) . ' 00:00:00', $tz);
        $to = new \DateTimeImmutable(DemandMath::date($day + 1) . ' 00:00:00', $tz);
        return [Clock::db($from), Clock::db($to)];
    }

    /**
     * Folds one UK day (class docblock).
     *
     * @return array{date: string, rows: int, variants: int, units: int}
     */
    public function foldDay(string $ymd): array
    {
        $t0 = hrtime(true);
        $day = DemandMath::day($ymd);
        [$from, $to] = self::bounds($day);
        $lo = $this->db->value('SELECT id FROM stock_ledger WHERE created_at >= ? ORDER BY created_at, id LIMIT 1', [$from]);
        $units = [];
        $rows = 0;
        $range = [null, null];
        if ($lo !== null) {
            $hi = $this->db->value('SELECT id FROM stock_ledger WHERE created_at >= ? ORDER BY created_at, id LIMIT 1', [$to])
                ?? $this->db->value('SELECT MAX(id) FROM stock_ledger');
            $lo = max(1, (int) $lo - self::ID_SLACK);
            $hi = (int) $hi + self::ID_SLACK;
            $range = [$lo, $hi];
            $in = implode(', ', array_fill(0, count(self::SALE_TYPES), '?'));
            for ($a = $lo; $a <= $hi; $a += self::ID_CHUNK) {
                foreach ($this->db->all(
                    'SELECT l.channel_id, cl.external_variant_id, ru.units_per_item AS u, SUM(l.qty_delta) AS central, COUNT(*) AS n FROM stock_ledger l '
                    . 'JOIN reservation_unit ru ON ru.channel_id = l.channel_id AND ru.unit_id = l.unit_id JOIN reservation r ON r.id = ru.reservation_id '
                    . 'JOIN channel_listing cl ON cl.id = ru.listing_id '
                    . "WHERE l.id BETWEEN ? AND ? AND l.created_at >= ? AND l.created_at < ? AND l.bucket = 'allocated' AND l.movement_type IN ({$in}) "
                    . "AND r.origin <> 'opening' GROUP BY l.channel_id, cl.external_variant_id, ru.units_per_item",
                    [$a, min($hi, $a + self::ID_CHUNK - 1), $from, $to, ...self::SALE_TYPES],
                ) as $r) {
                    $k = (int) $r['channel_id'] . "\0" . (string) $r['external_variant_id'];
                    $units[$k] = ($units[$k] ?? 0) + intdiv((int) $r['central'], max(1, (int) $r['u']));
                    $rows += (int) $r['n'];
                }
            }
        }
        ksort($units, SORT_STRING); // written in primary-key order (store, variant)
        $total = array_sum($units);
        $now = Clock::db(($this->clock)());
        $ms = intdiv(hrtime(true) - $t0, 1_000_000);
        $this->db->transaction(function (Db $db) use ($ymd, $units, $now, $rows, $range, $total, $ms): void {
            // The day's rows by their date index (EXPLAIN on a filled schema chose the whole primary key without the hint, PG12).
            $db->exec('UPDATE reorder_sales_day FORCE INDEX (ix_reorder_sales_day_date) SET units = 0, folded_at = ? WHERE sale_date = ? AND units <> 0', [$now, $ymd]);
            foreach (array_chunk($units, self::INSERT_CHUNK, true) as $chunk) {
                $params = [];
                foreach ($chunk as $k => $u) {
                    [$channel, $variant] = explode("\0", (string) $k, 2);
                    array_push($params, (int) $channel, $variant, $ymd, $u, $now);
                }
                $db->exec('INSERT INTO reorder_sales_day (channel_id, external_variant_id, sale_date, units, folded_at) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')) . ' AS new ON DUPLICATE KEY UPDATE units = new.units, folded_at = new.folded_at', $params);
            }
            $db->exec('INSERT INTO reorder_fold_day (fold_date, folded_at, times, ledger_from, ledger_to, rows_read, variants, units, ms) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?) '
                . 'AS new ON DUPLICATE KEY UPDATE folded_at = new.folded_at, times = reorder_fold_day.times + 1, ledger_from = new.ledger_from, ledger_to = new.ledger_to, '
                . 'rows_read = new.rows_read, variants = new.variants, units = new.units, ms = new.ms',
                [$ymd, $now, $range[0], $range[1], $rows, count($units), $total, $ms]);
        });
        return ['date' => $ymd, 'rows' => $rows, 'variants' => count($units), 'units' => $total];
    }

    /**
     * The record of some variants of one store over [$from, $to] (day numbers): variant => day => units (> 0; a day below 0 reads as
     * nothing sold).
     *
     * @param list<string> $variants
     * @return array<string, array<int, int>>
     */
    public function history(int $channelId, array $variants, int $from, int $to): array
    {
        $out = [];
        if ($variants === [] || $from > $to) {
            return $out;
        }
        foreach (array_chunk($variants, DemandBuilder::CHUNK) as $chunk) {
            foreach ($this->db->all('SELECT external_variant_id, sale_date, units FROM reorder_sales_day FORCE INDEX (PRIMARY) WHERE channel_id = ? AND external_variant_id IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ') AND sale_date BETWEEN ? AND ? AND units > 0',
                [$channelId, ...$chunk, DemandMath::date($from), DemandMath::date($to)]) as $r) {
                $out[(string) $r['external_variant_id']][DemandMath::day((string) $r['sale_date'])] = (int) $r['units'];
            }
        }
        return $out;
    }
}
