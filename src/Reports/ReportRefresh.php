<?php

declare(strict_types=1);

namespace CW\Reports;

use CW\Clock;
use CW\Db;
use CW\Settings;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The report job (bin/refresh_reports.php, every 15 minutes; docs/decisions.md PE10-PE12): keeps the reports' own tables up to date from
 * what CW records, so no report page reads the ledger. It reads only, except those tables (report_item, report_sales_day,
 * report_refresh), which can be emptied and rebuilt at any time: every part is exact whatever was read before.
 *
 *  A. Per item, from its value sequence (stock_value_seq: one row per on-hand change, gap-free per item in commit order, I3), from the
 *     seq the item was read up to (report_item.seq_done) to the item's clock (stock_value_clock.last_seq, committed with its rows):
 *     the units that came in with a cost and their cost (an on-hand rise with a unit cost: the average cost of
 *     StockOps\CostHints::average, the same rows), the first day its stock rose, and the last day it was sold through ERPNext or to a
 *     trade customer. At most reports.refresh_max_rows rows a run; the rest wait for the next run.
 *  C. The imported sales history (sales_history_day, Reorder's daily website sales), batch by batch in id order up to the first batch
 *     still loading or failed (a failed export is retried under the same id: PE18): the last day each matched item sold. A link made
 *     after a batch was read does not bring its older days back.
 *  B. Per UK day and store (report_sales_day), each day worked out whole again: orders paid that day (reservation.committed_at, the
 *     index of 0034) and the warehouse units on them (reservation_unit.units_per_item, whatever happened to them since), and the units
 *     sent, cancelled and returned that day (the ledger's ship/unship, cancel/uncancel of the allocated figure, and return, by the
 *     row's created_at, read as one range of ids). The days from the one where the last run stopped, less LAG_SECONDS (a transaction
 *     that began before the run commits after it), to today; a first run starts at the first paid order and catches up at most
 *     reports.refresh_max_days days a run. The items on those orders get their last sale day.
 *
 * Switched off by the setting reports.refresh_on (then a run does nothing; force: true for a hand run).
 */
final class ReportRefresh
{
    public const JOB = 'refresh_reports';
    /** Seconds a commit may land after its rows' times (the longest transaction, with room to spare): the days re-counted look back this far. */
    public const LAG_SECONDS = 3600;
    /** Items per transaction of part A. */
    public const ITEM_CHUNK = 200;
    /** Batches of imported sales history read per run. */
    public const HISTORY_BATCHES = 20;
    /** The states of a unit on a paid order (released and held units belong to a try that was never paid). */
    public const ORDER_STATES = ['allocated', 'shipped', 'returned', 'cancelled'];
    /** The states of a unit that was sold (its item's last sale day). */
    public const SOLD_STATES = ['allocated', 'shipped', 'returned'];
    /** Value-sequence movements that are a sale of the item. */
    public const SALE_MOVES = ['erp_sale', 'trade_sale'];

    /** @var \Closure(): DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => Clock::now();
    }

    /**
     * One run. $force runs it while the setting has it switched off (a hand run). $maxRows / $maxDays replace the settings'
     * bounds (a test, a hand run).
     *
     * @return array{off: bool, items: int, seq_rows: int, history_batches: int, days: int, sales_through: ?string}
     */
    public function run(bool $force = false, ?int $maxRows = null, ?int $maxDays = null): array
    {
        if (!$force && !Settings::flag($this->db, 'reports.refresh_on', true)) {
            return ['off' => true, 'items' => 0, 'seq_rows' => 0, 'history_batches' => 0, 'days' => 0, 'sales_through' => $this->state()['sales_through']];
        }
        $this->db->exec('INSERT INTO report_refresh (id) VALUES (1) AS n ON DUPLICATE KEY UPDATE id = report_refresh.id');
        [$items, $rows] = $this->items(max(1, $maxRows ?? Settings::number($this->db, 'reports.refresh_max_rows', 50_000)));
        [$batches, $waiting] = $this->history();
        [$days, $through] = $this->sales(max(1, $maxDays ?? Settings::number($this->db, 'reports.refresh_max_days', 31)));
        $note = "items {$items}, value rows {$rows}, history batches {$batches}, sales days {$days}"
            . ($waiting === null ? '' : ", history waits for batch {$waiting['id']} ({$waiting['status']})");
        $this->db->exec('UPDATE report_refresh SET ran_at = ?, note = ? WHERE id = 1', [Clock::db(($this->clock)()), $note]);
        return ['off' => false, 'items' => $items, 'seq_rows' => $rows, 'history_batches' => $batches, 'days' => $days, 'sales_through' => $through];
    }

    /** @return array{sales_through: ?string, history_batch_id: int, ran_at: ?string, note: ?string} */
    public function state(): array
    {
        $r = $this->db->one('SELECT sales_through, history_batch_id, ran_at, note FROM report_refresh WHERE id = 1');
        return ['sales_through' => $r['sales_through'] ?? null, 'history_batch_id' => (int) ($r['history_batch_id'] ?? 0),
            'ran_at' => $r['ran_at'] ?? null, 'note' => $r['note'] ?? null];
    }

    // ---- A. per item, from its value sequence ------------------------------------------------------------------------------------

    /** @return array{0: int, 1: int} items brought up to date (in part or whole), value-sequence rows read */
    private function items(int $maxRows): array
    {
        // The items whose clock is ahead of what was read: both tables are one row per item, read in key order.
        $todo = [];
        $budget = $maxRows;
        foreach ($this->db->all('SELECT c.sku_id, c.last_seq, COALESCE(r.seq_done, 0) AS done FROM stock_value_clock c '
            . 'LEFT JOIN report_item r ON r.sku_id = c.sku_id WHERE c.last_seq > COALESCE(r.seq_done, 0) ORDER BY c.sku_id') as $r) {
            if ($budget <= 0) {
                break;
            }
            $done = (int) $r['done'];
            $to = min((int) $r['last_seq'], $done + $budget);
            $budget -= $to - $done;
            $todo[(int) $r['sku_id']] = $to;
        }
        $rows = 0;
        foreach (array_chunk($todo, self::ITEM_CHUNK, true) as $chunk) {
            $rows += $this->db->transaction(fn (Db $db): int => $this->itemChunk($db, $chunk));
        }
        return [count($todo), $rows];
    }

    /** @param array<int, int> $chunk sku id => read up to this seq */
    private function itemChunk(Db $db, array $chunk): int
    {
        $ids = array_keys($chunk);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $have = [];
        foreach ($db->all("SELECT sku_id, seq_done, in_units, in_value, first_in_on, last_sale_on FROM report_item WHERE sku_id IN ({$in}) FOR UPDATE", $ids) as $r) {
            $have[(int) $r['sku_id']] = $r;
        }
        $cond = [];
        $params = [];
        $state = [];
        foreach ($chunk as $sku => $to) {
            $r = $have[$sku] ?? null;
            $from = $r === null ? 0 : (int) $r['seq_done'];
            $state[$sku] = ['done' => max($from, $to), 'units' => (string) ($r['in_units'] ?? '0'), 'value' => (string) ($r['in_value'] ?? '0'),
                'first' => $r['first_in_on'] ?? null, 'sale' => $r['last_sale_on'] ?? null];
            if ($to > $from) {
                $cond[] = '(s.sku_id = ? AND s.seq > ? AND s.seq <= ?)';
                array_push($params, $sku, $from, $to);
            }
        }
        $rows = 0;
        if ($cond !== []) {
            foreach ($db->all('SELECT s.sku_id, l.qty_delta, l.unit_cost, l.movement_type, COALESCE(l.effective_at, l.created_at) AS at '
                . 'FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE ' . implode(' OR ', $cond) . ' ORDER BY s.sku_id, s.seq', $params) as $l) {
                $rows++;
                $st = &$state[(int) $l['sku_id']];
                $qty = (int) $l['qty_delta'];
                $day = self::ukDay((string) $l['at']);
                if ($qty > 0) {
                    $st['first'] = $st['first'] === null || $day < $st['first'] ? $day : $st['first'];
                    if ($l['unit_cost'] !== null) {
                        $st['units'] = bcadd($st['units'], (string) $qty, 0);
                        $st['value'] = bcadd($st['value'], bcmul((string) $qty, (string) $l['unit_cost'], 6), 6);
                    }
                } elseif ($qty < 0 && in_array((string) $l['movement_type'], self::SALE_MOVES, true)) {
                    $st['sale'] = $st['sale'] === null || $day > $st['sale'] ? $day : $st['sale'];
                }
                unset($st);
            }
        }
        foreach ($state as $sku => $st) {
            $db->exec('INSERT INTO report_item (sku_id, seq_done, in_units, in_value, first_in_on, last_sale_on) VALUES (?, ?, ?, ?, ?, ?) AS n '
                . 'ON DUPLICATE KEY UPDATE seq_done = n.seq_done, in_units = n.in_units, in_value = n.in_value, first_in_on = n.first_in_on, '
                . 'last_sale_on = n.last_sale_on', [$sku, $st['done'], $st['units'], $st['value'], $st['first'], $st['sale']]);
        }
        return $rows;
    }

    // ---- C. the imported sales history -------------------------------------------------------------------------------------------

    /** @return array{0: int, 1: ?array{id: int, status: string}} batches read, and the batch the history waits for (still loading or failed) */
    private function history(): array
    {
        $done = 0;
        $waiting = null;
        $after = $this->state()['history_batch_id'];
        foreach ($this->db->all('SELECT id, status FROM sales_import_batch WHERE id > ? ORDER BY id LIMIT ' . self::HISTORY_BATCHES, [$after]) as $b) {
            if ($b['status'] !== 'loaded') {
                $waiting = ['id' => (int) $b['id'], 'status' => (string) $b['status']];
                // Read in id order: a batch still loading holds the later ones back, and so does a failed one (review finding 3, PE18:
                // the import retries a failed export under the same id, so passing it over would skip its days for good).
                break;
            }
            $id = (int) $b['id'];
            $last = [];
            foreach ($this->db->all('SELECT cl.sku_id, MAX(h.sale_date) AS d FROM sales_history_day h JOIN channel_listing cl '
                . 'ON cl.channel_id = h.channel_id AND cl.external_variant_id = h.external_variant_id WHERE h.batch_id = ? AND cl.sku_id IS NOT NULL '
                . 'GROUP BY cl.sku_id', [$id]) as $r) {
                $last[(int) $r['sku_id']] = (string) $r['d'];
            }
            $this->db->transaction(function (Db $db) use ($id, $last): void {
                $this->lastSale($db, $last);
                $db->exec('UPDATE report_refresh SET history_batch_id = ? WHERE id = 1 AND history_batch_id < ?', [$id, $id]);
            });
            $done++;
        }
        return [$done, $waiting];
    }

    /** @param array<int, string> $days sku id => a UK day it sold: the item's last sale day moves forward only */
    private function lastSale(Db $db, array $days): void
    {
        foreach (array_chunk($days, 500, true) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $sku => $day) {
                $values[] = '(?, ?)';
                array_push($params, $sku, $day);
            }
            $db->exec('INSERT INTO report_item (sku_id, last_sale_on) VALUES ' . implode(', ', $values) . ' AS n '
                . 'ON DUPLICATE KEY UPDATE last_sale_on = GREATEST(COALESCE(report_item.last_sale_on, n.last_sale_on), n.last_sale_on)', $params);
        }
    }

    // ---- B. per UK day and store ---------------------------------------------------------------------------------------------------

    /** @return array{0: int, 1: ?string} days worked out, and how far the store sales are now worked out (UTC) */
    private function sales(int $maxDays): array
    {
        $now = ($this->clock)();
        $through = $this->state()['sales_through'];
        if ($through === null) {
            $first = $this->db->value("SELECT MIN(committed_at) FROM reservation WHERE status = 'committed'");
            if ($first === null) {
                return [0, null];
            }
            $day = self::ukDay((string) $first);
        } else {
            $day = self::ukDay(Clock::db(Clock::fromDb((string) $through)->modify('-' . self::LAG_SECONDS . ' seconds')));
        }
        $today = self::ukDay(Clock::db($now));
        $n = 0;
        $last = null;
        while ($day <= $today && $n < $maxDays) {
            $this->recount($day);
            $last = $day;
            $day = self::nextDay($day);
            $n++;
        }
        if ($last === null) {
            return [0, $through === null ? null : (string) $through];
        }
        // Up to now when today was counted; else to the end of the last day counted plus LAG, so the next run starts with the next day.
        [, $end] = self::bounds($last);
        $next = $last === $today ? $now : min($now, $end->modify('+' . self::LAG_SECONDS . ' seconds'));
        $this->db->exec('UPDATE report_refresh SET sales_through = ? WHERE id = 1', [Clock::db($next)]);
        return [$n, Clock::db($next)];
    }

    /** Works one UK day out whole again (every store), and the last sale day of the items on its paid orders. */
    public function recount(string $day): void
    {
        [$from, $to] = self::bounds($day);
        $f = Clock::db($from);
        $t = Clock::db($to);
        $figures = [];
        $add = static function (int $channel, string $field, int $n) use (&$figures): void {
            $figures[$channel] ??= ['orders' => 0, 'units_sold' => 0, 'units_sent' => 0, 'units_cancelled' => 0, 'units_returned' => 0];
            $figures[$channel][$field] += $n;
        };
        foreach ($this->db->all("SELECT channel_id, COUNT(*) AS n FROM reservation WHERE status = 'committed' AND committed_at >= ? AND committed_at < ? "
            . 'GROUP BY channel_id', [$f, $t]) as $r) {
            $add((int) $r['channel_id'], 'orders', (int) $r['n']);
        }
        $states = implode(', ', array_map(static fn (string $s): string => "'{$s}'", self::ORDER_STATES));
        $items = [];
        foreach ($this->db->all('SELECT r.channel_id, u.sku_id, u.state, SUM(u.units_per_item) AS n FROM reservation r JOIN reservation_unit u ON u.reservation_id = r.id '
            . "WHERE r.status = 'committed' AND r.committed_at >= ? AND r.committed_at < ? AND u.state IN ({$states}) GROUP BY r.channel_id, u.sku_id, u.state",
            [$f, $t]) as $r) {
            $add((int) $r['channel_id'], 'units_sold', (int) $r['n']);
            if ($r['sku_id'] !== null && in_array((string) $r['state'], self::SOLD_STATES, true)) {
                $items[(int) $r['sku_id']] = $day;
            }
        }
        // The ledger's rows of the day, as one range of ids (a row's id and time are set together; LAG on both sides).
        $lo = $this->db->value('SELECT id FROM stock_ledger WHERE created_at >= ? ORDER BY created_at, id LIMIT 1',
            [Clock::db($from->modify('-' . self::LAG_SECONDS . ' seconds'))]);
        $hi = $this->db->value('SELECT id FROM stock_ledger WHERE created_at < ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [Clock::db($to->modify('+' . self::LAG_SECONDS . ' seconds'))]);
        if ($lo !== null && $hi !== null && (int) $lo <= (int) $hi) {
            foreach ($this->db->all('SELECT channel_id, movement_type, bucket, SUM(qty_delta) AS q FROM stock_ledger WHERE id BETWEEN ? AND ? '
                . "AND created_at >= ? AND created_at < ? AND channel_id IS NOT NULL AND movement_type IN ('ship', 'unship', 'cancel', 'uncancel', 'return') "
                . 'GROUP BY channel_id, movement_type, bucket', [(int) $lo, (int) $hi, $f, $t]) as $r) {
                $q = (int) $r['q'];
                match (true) {
                    in_array($r['movement_type'], ['ship', 'unship'], true) && $r['bucket'] === 'allocated' => $add((int) $r['channel_id'], 'units_sent', -$q),
                    in_array($r['movement_type'], ['cancel', 'uncancel'], true) && $r['bucket'] === 'allocated' => $add((int) $r['channel_id'], 'units_cancelled', -$q),
                    $r['movement_type'] === 'return' && $r['bucket'] === 'on_hand' => $add((int) $r['channel_id'], 'units_returned', $q),
                    default => null,
                };
            }
        }
        $this->db->transaction(function (Db $db) use ($day, $figures, $items): void {
            $db->exec('DELETE FROM report_sales_day WHERE sale_date = ?', [$day]);
            foreach ($figures as $channel => $x) {
                if (max(array_map('abs', $x)) === 0) {
                    continue;
                }
                $db->exec('INSERT INTO report_sales_day (sale_date, channel_id, orders, units_sold, units_sent, units_cancelled, units_returned, refreshed_at) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, NOW(6))', [$day, $channel, $x['orders'], $x['units_sold'], $x['units_sent'], $x['units_cancelled'], $x['units_returned']]);
            }
            $this->lastSale($db, $items);
        });
    }

    // ---- UK days -------------------------------------------------------------------------------------------------------------------

    private static function london(): DateTimeZone
    {
        static $tz = null;
        return $tz ??= new DateTimeZone('Europe/London');
    }

    /** The UK calendar day ('Y-m-d') of a UTC time as stored ('Y-m-d H:i:s[.u]'). */
    public static function ukDay(string $utc): string
    {
        return Clock::fromDb($utc)->setTimezone(self::london())->format('Y-m-d');
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} the UTC start of a UK day and of the next one (23, 24 or 25 hours apart) */
    public static function bounds(string $day): array
    {
        $start = new DateTimeImmutable($day . ' 00:00:00', self::london());
        return [$start->setTimezone(Clock::utc()), $start->modify('+1 day')->setTimezone(Clock::utc())];
    }

    public static function nextDay(string $day): string
    {
        return (new DateTimeImmutable($day . ' 12:00:00', self::london()))->modify('+1 day')->format('Y-m-d');
    }
}
