<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Clock;
use CW\Db;
use CW\Reservations;
use CW\Settings;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The read side of Stock › Sales (pack D, docs/decisions.md PD1-PD14): each store's orders, one order with its sold units and their
 * stock changes, what waits to be sent (Dispatch), and what came back or was cancelled (Returns). Reads only: CW\Reservations stays the
 * only writer of `reservation` and `reservation_unit`, and the stock ledger is written by CW\Stock alone.
 *
 * Every list is read from an index in its own order and pages by a key (keyset), never with an OFFSET or a COUNT over a table that
 * grows for ever. The indexes are 0023's and 0033's; each statement names its index in an optimizer hint (ignored while the index is
 * not there, so the code may run a moment before 0033: slower, not wrong):
 *   - Orders, by order state and way in: one range of reservation (channel_id, status, origin, created_at, id) per (store, state, way
 *     in) asked for, each newest first and cut at a page, merged (orderIds);
 *   - Orders with a unit sent, cancelled or returned: reservation_unit (channel_id, state, reservation_id) per store, newest order
 *     first; the days and the way in are checked on the orders read, at most `sales.scan_max_rows` of them per page (the page says
 *     when it stopped). Order ids follow the time an order was placed (both are set by the one INSERT), so the scan stops at the
 *     first order placed before the period, and starts below the newest order placed before its end (newestBefore);
 *   - one order's units: reservation_unit (reservation_id) [+ the primary key], by the store's unit number; their stock changes:
 *     stock_ledger (channel_id, unit_id);
 *   - Dispatch: the waiting units of a store, reservation_unit (channel_id, state, reservation_id), oldest order first; the units
 *     sent in a period, reservation_unit (channel_id, state, dispatched_at, reservation_id), read from the index alone; sending undone,
 *     stock_ledger (channel_id, movement_type, id), the week turned into ledger ids through stock_ledger (created_at);
 *   - Returns: reservation_unit (channel_id, state, reservation_id) per (store, kind), newest order first; where each unit's stock is
 *     now from its own ledger rows and its recount (count_review's unique dedupe key).
 * EXPLAIN of every statement on a filled schema: docs/decisions.md PD12.
 */
final class SalesViews
{
    /** Store orders' state filter: the engine's order states (paid = every paid order), then the orders with a unit in a state. */
    public const STATES = ['held', 'paid', 'shipped', 'cancelled', 'returned', 'released', 'expired'];
    /** The filter's order states => reservation.status. */
    public const ORDER_STATUS = ['held' => 'held', 'paid' => 'committed', 'released' => 'released', 'expired' => 'expired'];
    /** The filter's unit states (reservation_unit.state): the orders with at least one sold unit in it. */
    public const UNIT_STATES = ['shipped', 'cancelled', 'returned'];
    /** reservation.origin. */
    public const ORIGINS = Reservations::ORIGINS;
    /** Dispatch's lists. */
    public const SHOWS = ['waiting', 'today', 'week', 'undone'];
    /** Returns' kinds (reservation_unit.state). */
    public const KINDS = ['returned', 'cancelled'];
    /** reservation.status, every value (0001). */
    public const STATUSES = ['held', 'committed', 'released', 'expired'];
    /** The settings (0033) => [key, the value on a schema before 0033: the migration's]. */
    public const SETTINGS = ['page' => ['sales.page_size', 50], 'days' => ['sales.default_days', 7], 'late_hours' => ['sales.late_hours', 24],
        'csv_max' => ['sales.csv_max_rows', 5000], 'scan_max' => ['sales.scan_max_rows', 5000]];
    /** The warehouse a doubtful cancel parks its units in (0001's seeded row; CW\Reservations::cancel). */
    public const VERIFY = 'VERIFY';
    /** The day a week starts on: Monday (the UK's, and ISO 8601's; docs/decisions.md PD6). */
    public const WEEK_START = 'monday this week';

    private const HINT_PLACED = '/*+ INDEX(r ix_reservation_store_placed) */ ';
    private const HINT_STATE = '/*+ INDEX(ru ix_reservation_unit_store_state) */ ';
    private const HINT_SENT = '/*+ INDEX(ru ix_reservation_unit_store_sent) */ ';
    private const HINT_TYPE = '/*+ INDEX(l ix_stock_ledger_store_type) */ ';
    private const SENT = "('shipped', 'returned')";

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The five settings, read in one statement on every request (a change on the Settings page applies to the next page). A row that is
     * missing (a schema before 0033) or holds a value the rule does not allow (only admin SQL could write one) reads as the migration's.
     *
     * @return array{page: int, days: int, late_hours: int, csv_max: int, scan_max: int}
     */
    public static function settings(Db $db): array
    {
        $keys = array_values(array_map(static fn (array $s): string => $s[0], self::SETTINGS));
        $stored = [];
        foreach ($db->all("SELECT setting_key, CAST(value_json AS CHAR) AS v FROM app_setting WHERE value_type = 'int' AND setting_key IN ("
            . implode(', ', array_fill(0, count($keys), '?')) . ')', $keys) as $r) {
            $stored[(string) $r['setting_key']] = trim((string) $r['v']);
        }
        $out = [];
        foreach (self::SETTINGS as $name => [$key, $default]) {
            $raw = $stored[$key] ?? null;
            $v = $raw !== null && preg_match('/^-?\d{1,9}$/D', $raw) === 1 ? (int) $raw : $default;
            $rule = Settings::RULES[$key];
            $ok = isset($rule['in']) ? in_array($v, $rule['in'], true) : $v >= (int) $rule['min'] && $v <= (int) $rule['max'];
            $out[$name] = $ok ? $v : $default;
        }
        /** @var array{page: int, days: int, late_hours: int, csv_max: int, scan_max: int} $out */
        return $out;
    }

    /**
     * The period Store orders reads, from the address: two UK days (from and until, both included), "any time", or (nothing asked, or
     * a day that is not a real day, or the first after the last) the last $days days, today included. As UTC database times: `from`
     * (included) and `to` (excluded), null for no bound.
     *
     * @return array{kind: string, from: ?string, to: ?string, from_day: ?string, to_day: ?string, bad: bool}
     */
    public static function period(?string $fromDay, ?string $toDay, bool $all, int $days, DateTimeImmutable $now): array
    {
        $london = new DateTimeZone('Europe/London');
        $day = static function (?string $v) use ($london): ?DateTimeImmutable {
            if ($v === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, $london);
            return $d !== false && $d->format('Y-m-d') === $v ? $d : null;
        };
        $utc = static fn (DateTimeImmutable $d): string => Clock::db($d->setTimezone(Clock::utc()));
        $typed = ($fromDay ?? '') !== '' || ($toDay ?? '') !== '';
        $f = $day($fromDay);
        $t = $day($toDay);
        $bad = $typed && ((($fromDay ?? '') !== '' && $f === null) || (($toDay ?? '') !== '' && $t === null) || ($f !== null && $t !== null && $f > $t));
        if ($typed && !$bad) {
            return ['kind' => 'range', 'from' => $f === null ? null : $utc($f), 'to' => $t === null ? null : $utc($t->modify('+1 day')),
                'from_day' => $f?->format('Y-m-d'), 'to_day' => $t?->format('Y-m-d'), 'bad' => false];
        }
        if ($all && !$bad) {
            return ['kind' => 'all', 'from' => null, 'to' => null, 'from_day' => null, 'to_day' => null, 'bad' => false];
        }
        $today = $now->setTimezone($london)->setTime(0, 0);
        $first = $today->modify('-' . max(0, $days - 1) . ' days');
        return ['kind' => 'default', 'from' => $utc($first), 'to' => null, 'from_day' => $first->format('Y-m-d'), 'to_day' => $today->format('Y-m-d'), 'bad' => $bad];
    }

    /** The start of today and of this week (Monday), UK time, as UTC database times. @return array{today: string, week: string} */
    public static function since(DateTimeImmutable $now): array
    {
        $uk = $now->setTimezone(new DateTimeZone('Europe/London'))->setTime(0, 0);
        return ['today' => Clock::db($uk->setTimezone(Clock::utc())), 'week' => Clock::db($uk->modify(self::WEEK_START)->setTime(0, 0)->setTimezone(Clock::utc()))];
    }

    // ------------------------------------------------------------------------------------------
    // Store orders
    // ------------------------------------------------------------------------------------------

    /**
     * One page of Store orders, newest first: the ids of at most $size orders older than `before` (an order id), the id to ask for
     * the next page, and, when a filter on the units read $scanMax orders without filling the page, the id to read on from
     * (`stopped`) and how many orders it read.
     *
     * @param array{channel: ?int, state: ?string, origin: ?string, from: ?string, to: ?string, before: ?int} $f
     * @param list<int> $storeIds
     * @return array{ids: list<int>, next: ?int, stopped: ?int, read: int}
     */
    public function orderIds(array $f, array $storeIds, int $size, int $scanMax): array
    {
        $stores = $f['channel'] !== null ? [$f['channel']] : $storeIds;
        if ($stores === []) {
            return ['ids' => [], 'next' => null, 'stopped' => null, 'read' => 0];
        }
        if ($f['state'] !== null && in_array($f['state'], self::UNIT_STATES, true)) {
            return $this->byUnit($f, $stores, $size, $scanMax);
        }
        $ids = $this->placed($f, $stores, $size + 1);
        $next = null;
        if (count($ids) > $size) {
            $ids = array_slice($ids, 0, $size);
            $next = $ids[$size - 1];
        }
        return ['ids' => $ids, 'next' => $next, 'stopped' => null, 'read' => count($ids)];
    }

    /**
     * The orders with these ids as the lists show them (ReservationViews::rows: the units added up, the board's group), in the order
     * of $ids.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function rows(array $ids): array
    {
        $byId = [];
        foreach ((new ReservationViews($this->db))->rows($ids) as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        return $out;
    }

    /**
     * The whole filtered list for a file, newest first: at most $max rows, read $batch at a time by key. `more`: the list goes on;
     * `stopped`: a filter on the units stopped reading (its scan limit) before the end.
     *
     * @param array{channel: ?int, state: ?string, origin: ?string, from: ?string, to: ?string, before: ?int} $f
     * @param list<int> $storeIds
     * @return array{rows: list<array<string, mixed>>, more: bool, stopped: ?int}
     */
    public function exportOrders(array $f, array $storeIds, int $max, int $scanMax, int $batch = 500): array
    {
        $rows = [];
        $f['before'] = null;
        $budget = $scanMax;
        while (true) {
            $page = $this->orderIds($f, $storeIds, min($batch, $max - count($rows)), $budget);
            array_push($rows, ...$this->rows($page['ids']));
            if ($page['stopped'] !== null) {
                return ['rows' => $rows, 'more' => true, 'stopped' => $page['read']];
            }
            if ($page['next'] === null) {
                return ['rows' => $rows, 'more' => false, 'stopped' => null];
            }
            if (count($rows) >= $max) {
                return ['rows' => $rows, 'more' => true, 'stopped' => null];
            }
            $budget = max(1, $budget - $page['read']);
            $f['before'] = $page['next'];
        }
    }

    /**
     * The ids of the orders of these stores in the order states and ways in asked for, newest placed first, at most $limit: one range of
     * ix_reservation_store_placed per (store, state, way in), each cut at $limit, merged. `before`: an order id; the page starts after
     * it in the list's order (placed time, then id), so the boundary is exact.
     *
     * @param array{channel: ?int, state: ?string, origin: ?string, from: ?string, to: ?string, before: ?int} $f
     * @param list<int> $stores
     * @return list<int>
     */
    private function placed(array $f, array $stores, int $limit): array
    {
        $statuses = $f['state'] === null ? self::STATUSES : [self::ORDER_STATUS[$f['state']]];
        $origins = $f['origin'] === null ? self::ORIGINS : [$f['origin']];
        $tail = '';
        $tailParams = [];
        if ($f['from'] !== null) {
            $tail .= ' AND r.created_at >= ?';
            $tailParams[] = $f['from'];
        }
        if ($f['to'] !== null) {
            $tail .= ' AND r.created_at < ?';
            $tailParams[] = $f['to'];
        }
        if ($f['before'] !== null) {
            $at = $this->db->value('SELECT created_at FROM reservation WHERE id = ?', [$f['before']]);
            if ($at === null) {
                return []; // an order that is not there has nothing after it
            }
            $tail .= ' AND r.created_at <= ? AND (r.created_at < ? OR r.id < ?)';
            array_push($tailParams, (string) $at, (string) $at, $f['before']);
        }
        $branches = [];
        $params = [];
        foreach ($stores as $store) {
            foreach ($statuses as $status) {
                foreach ($origins as $origin) {
                    $branches[] = 'SELECT ' . self::HINT_PLACED . 'r.id, r.created_at FROM reservation r WHERE r.channel_id = ? AND r.status = ? AND r.origin = ?' . $tail
                        . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . $limit;
                    array_push($params, $store, $status, $origin, ...$tailParams);
                }
            }
        }
        $sql = count($branches) === 1 ? $branches[0]
            : 'SELECT x.id, x.created_at FROM ((' . implode(') UNION ALL (', $branches) . ')) x ORDER BY x.created_at DESC, x.id DESC LIMIT ' . $limit;
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->all($sql, $params));
    }

    /**
     * Orders with at least one sold unit in a state (sent, cancelled, returned), newest first: the store's units in that state by
     * order (ix_reservation_unit_store_state), read in chunks below `before` (and below the newest order placed before the period's
     * end); each order read is checked for the period and the way in; reading stops at the first order placed before the period
     * (every later one is older), when the page is full, or after $scanMax orders (`stopped`: the last order read).
     *
     * @param array{channel: ?int, state: ?string, origin: ?string, from: ?string, to: ?string, before: ?int} $f
     * @param list<int> $stores
     * @return array{ids: list<int>, next: ?int, stopped: ?int, read: int}
     */
    private function byUnit(array $f, array $stores, int $size, int $scanMax): array
    {
        $none = ['ids' => [], 'next' => null, 'stopped' => null, 'read' => 0];
        $below = $f['before'];
        if ($f['to'] !== null) {
            $last = $this->newestBefore($stores, $f['to']);
            if ($last === null) {
                return $none;
            }
            $below = $below === null ? $last + 1 : min($below, $last + 1);
        }
        $found = [];
        $read = 0;
        $chunk = max($size + 1, min(500, $scanMax));
        while (true) {
            $ids = $this->unitOrders($stores, (string) $f['state'], $below, min($chunk, $scanMax - $read));
            if ($ids === []) {
                break;
            }
            $orders = [];
            foreach ($this->db->all('SELECT id, created_at, origin FROM reservation WHERE id IN (' . self::marks($ids) . ')', $ids) as $r) {
                $orders[(int) $r['id']] = $r;
            }
            $end = false;
            foreach ($ids as $id) {
                $read++;
                $below = $id;
                $o = $orders[$id] ?? null;
                if ($o === null) {
                    continue;
                }
                if ($f['from'] !== null && (string) $o['created_at'] < $f['from']) {
                    $end = true; // placed before the period: every order after it in this list is older still
                    break;
                }
                if (($f['to'] !== null && (string) $o['created_at'] >= $f['to']) || ($f['origin'] !== null && (string) $o['origin'] !== $f['origin'])) {
                    continue;
                }
                $found[] = $id;
                if (count($found) > $size) {
                    break;
                }
            }
            if ($end || count($found) > $size) {
                break;
            }
            if ($read >= $scanMax) {
                // Stopped by the limit, unless the index has nothing more anyway.
                $more = $this->unitOrders($stores, (string) $f['state'], $below, 1) !== [];
                return ['ids' => $found, 'next' => null, 'stopped' => $more ? $below : null, 'read' => $read];
            }
        }
        $next = null;
        if (count($found) > $size) {
            $found = array_slice($found, 0, $size);
            $next = $found[$size - 1];
        }
        return ['ids' => $found, 'next' => $next, 'stopped' => null, 'read' => $read];
    }

    /**
     * The ids of the orders of these stores with a unit in $state, newest first, below $below, at most $limit: one range of
     * ix_reservation_unit_store_state per store (read from the index alone), merged. An order belongs to one store.
     *
     * @param list<int> $stores
     * @return list<int>
     */
    private function unitOrders(array $stores, string $state, ?int $below, int $limit): array
    {
        $branches = [];
        $params = [];
        foreach ($stores as $store) {
            $branches[] = 'SELECT ' . self::HINT_STATE . 'ru.reservation_id AS id FROM reservation_unit ru WHERE ru.channel_id = ? AND ru.state = ?'
                . ($below === null ? '' : ' AND ru.reservation_id < ?') . ' GROUP BY ru.reservation_id ORDER BY ru.reservation_id DESC LIMIT ' . max(1, $limit);
            array_push($params, $store, $state, ...($below === null ? [] : [$below]));
        }
        $sql = count($branches) === 1 ? $branches[0] : 'SELECT x.id FROM ((' . implode(') UNION ALL (', $branches) . ')) x ORDER BY x.id DESC LIMIT ' . max(1, $limit);
        return array_map('intval', $this->db->column($sql, $params));
    }

    /**
     * The newest order of these stores placed before $to (a UTC time), or null: the end of each range of ix_reservation_store_placed,
     * one look-up per (store, state, way in). Order ids follow the time an order was placed.
     *
     * @param list<int> $stores
     */
    private function newestBefore(array $stores, string $to): ?int
    {
        $branches = [];
        $params = [];
        foreach ($stores as $store) {
            foreach (self::STATUSES as $status) {
                foreach (self::ORIGINS as $origin) {
                    $branches[] = 'SELECT ' . self::HINT_PLACED . 'r.id FROM reservation r WHERE r.channel_id = ? AND r.status = ? AND r.origin = ? AND r.created_at < ? '
                        . 'ORDER BY r.created_at DESC, r.id DESC LIMIT 1';
                    array_push($params, $store, $status, $origin, $to);
                }
            }
        }
        $v = $this->db->value('SELECT MAX(x.id) FROM ' . self::union($branches) . ' x', $params);
        return $v === null ? null : (int) $v;
    }

    // ------------------------------------------------------------------------------------------
    // One order
    // ------------------------------------------------------------------------------------------

    /**
     * One page of an order's sold units, by the store's unit number, after `after`: each with its listing (the store variant), its
     * warehouse item (null when the variant was not linked at the sale), warehouse, state and dispatch time; and the unit number to
     * ask for the next page.
     *
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function units(int $reservationId, int $channelId, ?string $after, int $size): array
    {
        $rows = $this->db->all(
            'SELECT ru.unit_id, ru.listing_id, ru.sku_id, ru.units_per_item, ru.warehouse_id, ru.state, ru.dispatched_at, ru.updated_at '
            . 'FROM reservation_unit ru WHERE ru.reservation_id = ? AND ru.channel_id = ?' . ($after === null ? '' : ' AND ru.unit_id > ?')
            . ' ORDER BY ru.unit_id LIMIT ' . ($size + 1),
            [$reservationId, $channelId, ...($after === null ? [] : [$after])],
        );
        $next = null;
        if (count($rows) > $size) {
            $rows = array_slice($rows, 0, $size);
            $next = (string) $rows[$size - 1]['unit_id'];
        }
        return ['rows' => $this->describeUnits($rows), 'next' => $next];
    }

    /**
     * Every stock change the ledger holds for these units of one store, oldest first, at most $max rows (`capped`: there are more),
     * grouped per unit into steps: one call of the engine (its key; the minute for the expiry job, which has none) at one warehouse,
     * with the change of each stock figure, the item, who, when (the physical time where the engine keeps one: a dispatch's), and the
     * last ledger row of the step (Movements opens at it).
     *
     * @param list<string> $unitIds
     * @return array{steps: array<string, list<array{type: string, warehouse_id: int, sku_id: int, actor: string, at: string, last_id: int, held: int, allocated: int, on_hand: int}>>, capped: bool}
     */
    public function steps(int $channelId, array $unitIds, int $max): array
    {
        if ($unitIds === []) {
            return ['steps' => [], 'capped' => false];
        }
        $rows = $this->db->all(
            'SELECT l.id, l.unit_id, l.movement_type, l.bucket, l.qty_delta, l.warehouse_id, l.sku_id, l.actor, l.idem_key, l.created_at, '
            . 'COALESCE(l.effective_at, l.created_at) AS at FROM stock_ledger l WHERE l.channel_id = ? AND l.unit_id IN (' . self::marks($unitIds) . ') '
            . 'ORDER BY l.id LIMIT ' . ($max + 1),
            [$channelId, ...$unitIds],
        );
        $capped = count($rows) > $max;
        $steps = [];
        $index = [];
        foreach (array_slice($rows, 0, $max) as $r) {
            $unit = (string) $r['unit_id'];
            $key = $unit . "\x1f" . $r['movement_type'] . "\x1f" . $r['warehouse_id'] . "\x1f"
                . ($r['idem_key'] ?? 'm' . substr((string) $r['created_at'], 0, 16));
            if (!isset($index[$key])) {
                $index[$key] = count($steps[$unit] ?? []);
                $steps[$unit][] = ['type' => (string) $r['movement_type'], 'warehouse_id' => (int) $r['warehouse_id'], 'sku_id' => (int) $r['sku_id'],
                    'actor' => (string) $r['actor'], 'at' => (string) $r['at'], 'last_id' => (int) $r['id'], 'held' => 0, 'allocated' => 0, 'on_hand' => 0];
            }
            $step = &$steps[$unit][$index[$key]];
            $step[(string) $r['bucket']] += (int) $r['qty_delta'];
            $step['last_id'] = max($step['last_id'], (int) $r['id']);
            unset($step);
        }
        return ['steps' => $steps, 'capped' => $capped];
    }

    /** @return array<int, array{name: string, code: string}> every warehouse by id */
    public function warehouses(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, name, code FROM warehouse ORDER BY id') as $w) {
            $out[(int) $w['id']] = ['name' => (string) $w['name'], 'code' => (string) $w['code']];
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------------------------------

    /**
     * Paid orders with a unit waiting to ship, oldest order first, after the order id `after`: at most $size, and the id to ask for the
     * next page. One range of ix_reservation_unit_store_state per store, read from the index alone, merged.
     *
     * @param list<int> $stores
     * @return array{ids: list<int>, next: ?int}
     */
    public function waiting(array $stores, ?int $after, int $size): array
    {
        if ($stores === []) {
            return ['ids' => [], 'next' => null];
        }
        $branches = [];
        $params = [];
        foreach ($stores as $store) {
            $branches[] = 'SELECT ' . self::HINT_STATE . "ru.reservation_id AS id FROM reservation_unit ru WHERE ru.channel_id = ? AND ru.state = 'allocated'"
                . ($after === null ? '' : ' AND ru.reservation_id > ?') . ' GROUP BY ru.reservation_id ORDER BY ru.reservation_id LIMIT ' . ($size + 1);
            array_push($params, $store, ...($after === null ? [] : [$after]));
        }
        $sql = count($branches) === 1 ? $branches[0] : 'SELECT x.id FROM ((' . implode(') UNION ALL (', $branches) . ')) x ORDER BY x.id LIMIT ' . ($size + 1);
        $ids = array_map('intval', $this->db->column($sql, $params));
        $next = null;
        if (count($ids) > $size) {
            $ids = array_slice($ids, 0, $size);
            $next = $ids[$size - 1];
        }
        return ['ids' => $ids, 'next' => $next];
    }

    /**
     * Each store's orders and sold units waiting to ship: the store's range of ix_reservation_unit_store_state, read from the index
     * alone (it holds what is paid and not sent, a small set by nature).
     *
     * @param list<int> $stores
     * @return array<int, array{orders: int, units: int}>
     */
    public function waitingCounts(array $stores): array
    {
        $out = [];
        foreach ($stores as $store) {
            $r = $this->db->one('SELECT ' . self::HINT_STATE . "COUNT(DISTINCT ru.reservation_id) AS orders, COUNT(*) AS units FROM reservation_unit ru WHERE ru.channel_id = ? AND ru.state = 'allocated'",
                [$store]);
            $out[$store] = ['orders' => (int) ($r['orders'] ?? 0), 'units' => (int) ($r['units'] ?? 0)];
        }
        return $out;
    }

    /**
     * How many of each store's oldest $scanMax waiting orders were paid before $cutoff (late), for the stores of $counts
     * (waitingCounts); `capped`: the store has more waiting orders than were looked at. The waiting orders come from the index, the
     * payment time from each order (its primary key).
     *
     * @param array<int, array{orders: int, units: int}> $counts
     * @return array<int, array{late: int, capped: bool}>
     */
    public function late(array $counts, string $cutoff, int $scanMax): array
    {
        $out = [];
        foreach ($counts as $store => $c) {
            $late = $c['orders'] === 0 ? 0 : (int) $this->db->value(
                'SELECT COUNT(*) FROM (SELECT ' . self::HINT_STATE . "ru.reservation_id FROM reservation_unit ru WHERE ru.channel_id = ? AND ru.state = 'allocated' "
                . 'GROUP BY ru.reservation_id ORDER BY ru.reservation_id LIMIT ' . $scanMax . ') w JOIN reservation r ON r.id = w.reservation_id WHERE r.committed_at < ?',
                [$store, $cutoff],
            );
            $out[$store] = ['late' => $late, 'capped' => $c['orders'] > $scanMax];
        }
        return $out;
    }

    /**
     * The orders that had a unit sent since $since (UTC) and still count as sent (shipped, or returned later: a unit keeps its dispatch
     * time), newest sending first: at most $size after the order `before`, with the time of the order's last unit sent in the period
     * and its units sent then. Read from ix_reservation_unit_store_sent alone; the period is a day or a week, so the read is bounded by it.
     *
     * @param list<int> $stores
     * @return array{rows: list<array{id: int, sent_at: string, units: int}>, next: ?int}
     */
    public function sent(array $stores, string $since, ?int $before, int $size): array
    {
        if ($stores === []) {
            return ['rows' => [], 'next' => null];
        }
        $branches = [];
        $params = [];
        foreach ($stores as $store) {
            $branches[] = 'SELECT ' . self::HINT_SENT . 'ru.reservation_id AS id, MAX(ru.dispatched_at) AS sent_at, COUNT(*) AS units FROM reservation_unit ru '
                . 'WHERE ru.channel_id = ? AND ru.state IN ' . self::SENT . ' AND ru.dispatched_at >= ? GROUP BY ru.reservation_id';
            array_push($params, $store, $since);
        }
        $where = '';
        if ($before !== null) {
            $at = $this->db->value('SELECT MAX(dispatched_at) FROM reservation_unit WHERE reservation_id = ? AND state IN ' . self::SENT . ' AND dispatched_at >= ?',
                [$before, $since]);
            if ($at === null) {
                return ['rows' => [], 'next' => null];
            }
            $where = ' WHERE x.sent_at <= ? AND (x.sent_at < ? OR x.id < ?)';
            array_push($params, (string) $at, (string) $at, $before);
        }
        $rows = $this->db->all('SELECT x.id, x.sent_at, x.units FROM ' . self::union($branches) . ' x' . $where
            . ' ORDER BY x.sent_at DESC, x.id DESC LIMIT ' . ($size + 1), $params);
        $out = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'sent_at' => (string) $r['sent_at'], 'units' => (int) $r['units']], $rows);
        $next = null;
        if (count($out) > $size) {
            $out = array_slice($out, 0, $size);
            $next = $out[$size - 1]['id'];
        }
        return ['rows' => $out, 'next' => $next];
    }

    /**
     * Each store's orders and units sent since $since (UTC), counted as sent() lists them, from the index alone.
     *
     * @param list<int> $stores
     * @return array<int, array{orders: int, units: int}>
     */
    public function sentFigures(array $stores, string $since): array
    {
        $out = [];
        foreach ($stores as $store) {
            $r = $this->db->one('SELECT ' . self::HINT_SENT . 'COUNT(DISTINCT ru.reservation_id) AS orders, COUNT(*) AS units FROM reservation_unit ru '
                . 'WHERE ru.channel_id = ? AND ru.state IN ' . self::SENT . ' AND ru.dispatched_at >= ?', [$store, $since]);
            $out[$store] = ['orders' => (int) ($r['orders'] ?? 0), 'units' => (int) ($r['units'] ?? 0)];
        }
        return $out;
    }

    /**
     * Sending undone (a dispatch reset: the engine's `unship`), newest first, one row per sold unit brought back (its `allocated` row:
     * the one every reset books): at most $size older than the ledger id `before`, and the id to ask for the next page. Only units
     * linked to a warehouse item are in the ledger. One range of ix_stock_ledger_store_type per store, merged.
     *
     * @param list<int> $stores
     * @return array{rows: list<array<string, mixed>>, next: ?int}
     */
    public function undone(array $stores, ?int $before, int $size): array
    {
        if ($stores === []) {
            return ['rows' => [], 'next' => null];
        }
        // A reset books one or two rows a unit (allocated, and on_hand unless the shelf count already had it): 2 x (size + 1) rows
        // always hold size + 1 units.
        $limit = 2 * ($size + 1);
        $branches = [];
        $params = [];
        foreach ($stores as $store) {
            $branches[] = 'SELECT ' . self::HINT_TYPE . "l.id FROM stock_ledger l WHERE l.channel_id = ? AND l.movement_type = 'unship'"
                . ($before === null ? '' : ' AND l.id < ?') . ' ORDER BY l.id DESC LIMIT ' . $limit;
            array_push($params, $store, ...($before === null ? [] : [$before]));
        }
        $sql = count($branches) === 1 ? $branches[0] : 'SELECT x.id FROM ((' . implode(') UNION ALL (', $branches) . ')) x ORDER BY x.id DESC LIMIT ' . $limit;
        $ids = array_map('intval', $this->db->column($sql, $params));
        if ($ids === []) {
            return ['rows' => [], 'next' => null];
        }
        $rows = $this->db->all('SELECT l.id, l.channel_id, l.order_ref, l.unit_id, l.sku_id, l.actor, l.created_at FROM stock_ledger l WHERE l.id IN (' . self::marks($ids)
            . ") AND l.bucket = 'allocated' ORDER BY l.id DESC", $ids);
        $next = null;
        if (count($rows) > $size) {
            $rows = array_slice($rows, 0, $size);
            $next = (int) $rows[$size - 1]['id'];
        }
        // The order and the unit's state now, by the unit's key (the primary key).
        $where = [];
        $p = [];
        foreach ($rows as $r) {
            $where[] = '(ru.channel_id = ? AND ru.unit_id = ?)';
            array_push($p, (int) $r['channel_id'], (string) $r['unit_id']);
        }
        $units = [];
        if ($where !== []) {
            foreach ($this->db->all('SELECT ru.channel_id, ru.unit_id, ru.reservation_id, ru.state FROM reservation_unit ru WHERE ' . implode(' OR ', $where), $p) as $u) {
                $units[$u['channel_id'] . "\x1f" . $u['unit_id']] = $u;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $ch = (int) $r['channel_id'];
            $unit = $units[$ch . "\x1f" . $r['unit_id']] ?? null;
            $out[] = ['ledger_id' => (int) $r['id'], 'channel_id' => $ch, 'ref' => (string) $r['order_ref'], 'unit_id' => (string) $r['unit_id'],
                'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'], 'actor' => (string) $r['actor'], 'at' => (string) $r['created_at'],
                'reservation_id' => $unit === null ? null : (int) $unit['reservation_id'], 'state' => $unit === null ? null : (string) $unit['state']];
        }
        return ['rows' => $out, 'next' => $next];
    }

    /**
     * Each store's units whose sending was undone since $since (UTC): the window is turned into ledger ids (the first row booked at or
     * after it, through ix_stock_ledger_created), then one range of ix_stock_ledger_store_type per store.
     *
     * @param list<int> $stores
     * @return array<int, int>
     */
    public function undoneSince(array $stores, string $since): array
    {
        $first = $this->db->value('SELECT id FROM stock_ledger WHERE created_at >= ? ORDER BY created_at, id LIMIT 1', [$since]);
        $out = [];
        foreach ($stores as $store) {
            $out[$store] = $first === null ? 0 : (int) $this->db->value('SELECT ' . self::HINT_TYPE . "COUNT(*) FROM stock_ledger l WHERE l.channel_id = ? AND l.movement_type = 'unship' "
                . "AND l.id >= ? AND l.bucket = 'allocated'", [$store, (int) $first]);
        }
        return $out;
    }

    /**
     * The orders among these (one store's references) whose sending was undone at least once: the ledger's rows of each order
     * (ix_stock_ledger_order), bounded by the page.
     *
     * @param list<string> $refs
     * @return array<string, true>
     */
    public function undoneOrders(int $channelId, array $refs): array
    {
        if ($refs === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->column('SELECT /*+ INDEX(l ix_stock_ledger_order) */ DISTINCT l.order_ref FROM stock_ledger l WHERE l.channel_id = ? AND l.order_ref IN ('
            . self::marks($refs) . ") AND l.movement_type = 'unship'", [$channelId, ...$refs]) as $ref) {
            $out[(string) $ref] = true;
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Returns
    // ------------------------------------------------------------------------------------------

    /**
     * Returned and cancelled sold units of these stores, newest order first (then by the store's unit number, backwards): at most $size
     * after the key `before` [order id, unit number], and the key of the next page. One range of ix_reservation_unit_store_state per
     * (store, kind), merged; then the units themselves by their key.
     *
     * @param list<int> $stores
     * @param list<string> $kinds
     * @param array{0: int, 1: string}|null $before
     * @return array{rows: list<array<string, mixed>>, next: ?array{0: int, 1: string}}
     */
    public function returns(array $stores, array $kinds, ?array $before, int $size): array
    {
        if ($stores === [] || $kinds === []) {
            return ['rows' => [], 'next' => null];
        }
        $branches = [];
        $params = [];
        foreach ($stores as $store) {
            foreach ($kinds as $kind) {
                $branches[] = 'SELECT ' . self::HINT_STATE . 'ru.channel_id, ru.unit_id, ru.reservation_id FROM reservation_unit ru WHERE ru.channel_id = ? AND ru.state = ?'
                    . ($before === null ? '' : ' AND ru.reservation_id <= ? AND (ru.reservation_id < ? OR ru.unit_id < ?)')
                    . ' ORDER BY ru.reservation_id DESC, ru.unit_id DESC LIMIT ' . ($size + 1);
                array_push($params, $store, $kind, ...($before === null ? [] : [$before[0], $before[0], $before[1]]));
            }
        }
        $sql = count($branches) === 1 ? $branches[0]
            : 'SELECT x.channel_id, x.unit_id, x.reservation_id FROM ((' . implode(') UNION ALL (', $branches) . ')) x ORDER BY x.reservation_id DESC, x.unit_id DESC LIMIT ' . ($size + 1);
        $keys = $this->db->all($sql, $params);
        $next = null;
        if (count($keys) > $size) {
            $keys = array_slice($keys, 0, $size);
            $next = [(int) $keys[$size - 1]['reservation_id'], (string) $keys[$size - 1]['unit_id']];
        }
        if ($keys === []) {
            return ['rows' => [], 'next' => null];
        }
        $where = [];
        $p = [];
        foreach ($keys as $k) {
            $where[] = '(ru.channel_id = ? AND ru.unit_id = ?)';
            array_push($p, (int) $k['channel_id'], (string) $k['unit_id']);
        }
        $found = [];
        foreach ($this->db->all('SELECT ru.channel_id, ru.reservation_id, r.order_ref, ru.unit_id, ru.listing_id, ru.sku_id, ru.units_per_item, ru.warehouse_id, ru.state, '
            . 'ru.dispatched_at, ru.updated_at FROM reservation_unit ru JOIN reservation r ON r.id = ru.reservation_id WHERE ' . implode(' OR ', $where), $p) as $r) {
            $found[$r['channel_id'] . "\x1f" . $r['unit_id']] = $r;
        }
        $rows = [];
        foreach ($keys as $k) {
            $r = $found[$k['channel_id'] . "\x1f" . $k['unit_id']] ?? null;
            if ($r !== null) {
                $rows[] = $r;
            }
        }
        $described = $this->describeUnits($rows);
        $restock = $this->restock($described);
        foreach ($described as $i => $u) {
            $described[$i] += ['channel_id' => (int) $rows[$i]['channel_id'], 'reservation_id' => (int) $rows[$i]['reservation_id'], 'ref' => (string) $rows[$i]['order_ref']]
                + $restock[$i];
        }
        return ['rows' => $described, 'next' => $next];
    }

    /**
     * Where the stock of each returned or cancelled unit is now (CW\Reservations' own rules, read back from the ledger): a return put it
     * back in stock at the unit's warehouse; a cancel of a paid unit gave it back to sale there (restockable) or moved it to VERIFY
     * for a recount (not restockable: a transfer into VERIFY noted cancel_not_restockable after the unit's last cancel), and the
     * recount's state; a cancel before payment freed the reservation; a unit not linked at the sale kept no stock.
     *
     * @param list<array<string, mixed>> $units describeUnits rows with channel_id
     * @return list<array{restock: string, outcome: string, where_id: ?int, recount: ?string}>
     */
    public function restock(array $units): array
    {
        $verify = (int) ($this->db->value('SELECT id FROM warehouse WHERE code = ?', [self::VERIFY]) ?? 0);
        $byStore = [];
        foreach ($units as $u) {
            if ($u['sku_id'] !== null && $u['state'] === 'cancelled') {
                $byStore[(int) $u['channel_id']][] = (string) $u['unit_id'];
            }
        }
        $lastCancel = [];
        $toVerify = [];
        foreach ($byStore as $store => $ids) {
            foreach ($this->db->all('SELECT l.id, l.unit_id, l.movement_type, l.bucket, l.warehouse_id, l.note FROM stock_ledger l WHERE l.channel_id = ? AND l.unit_id IN ('
                . self::marks($ids) . ") AND l.movement_type IN ('cancel', 'transfer_in') ORDER BY l.id", [$store, ...$ids]) as $l) {
                $key = $store . "\x1f" . $l['unit_id'];
                if ($l['movement_type'] === 'cancel') {
                    $lastCancel[$key] = ['id' => (int) $l['id'], 'bucket' => (string) $l['bucket']];
                    unset($toVerify[$key]);
                } elseif ((int) $l['warehouse_id'] === $verify && $l['note'] === 'cancel_not_restockable' && isset($lastCancel[$key]) && (int) $l['id'] > $lastCancel[$key]['id']) {
                    $toVerify[$key] = true;
                }
            }
        }
        $recounts = [];
        $dedupe = array_map(static fn (string $k): string => 'verify:' . str_replace("\x1f", ':', $k), array_keys($toVerify));
        if ($dedupe !== []) {
            foreach ($this->db->all('SELECT dedupe_key, status FROM count_review WHERE dedupe_key IN (' . self::marks($dedupe) . ')', $dedupe) as $c) {
                $recounts[(string) $c['dedupe_key']] = (string) $c['status'];
            }
        }
        $out = [];
        foreach ($units as $u) {
            $key = $u['channel_id'] . "\x1f" . $u['unit_id'];
            $wh = (int) $u['warehouse_id'];
            if ($u['sku_id'] === null) {
                $out[] = ['restock' => 'none', 'outcome' => 'unlinked', 'where_id' => null, 'recount' => null];
            } elseif ($u['state'] === 'returned') {
                $out[] = ['restock' => 'yes', 'outcome' => 'returned', 'where_id' => $wh, 'recount' => null];
            } elseif (isset($toVerify[$key])) {
                $status = $recounts['verify:' . $u['channel_id'] . ':' . $u['unit_id']] ?? 'open';
                $out[] = ['restock' => 'no', 'outcome' => 'verify_' . $status, 'where_id' => $verify, 'recount' => $status];
            } elseif (isset($lastCancel[$key])) {
                $held = $lastCancel[$key]['bucket'] === 'held';
                $out[] = ['restock' => 'yes', 'outcome' => $held ? 'cancelled_held' : 'cancelled', 'where_id' => $wh, 'recount' => null];
            } else {
                $out[] = ['restock' => 'none', 'outcome' => 'unknown', 'where_id' => null, 'recount' => null];
            }
        }
        return $out;
    }

    /**
     * Each store's cancelled units waiting in VERIFY for a recount: the open recounts the engine opened (count_review, source
     * verify_recount; its `ref` starts with the store's id). Read from ix_count_review_status: the open reviews, a small set by nature.
     *
     * @return array<int, int> store id => units
     */
    public function verifyOpen(): array
    {
        $out = [];
        foreach ($this->db->column("SELECT ref FROM count_review WHERE status = 'open' AND source = 'verify_recount'") as $ref) {
            if (preg_match('/^(\d{1,5}):/', (string) $ref, $m) === 1) {
                $out[(int) $m[1]] = ($out[(int) $m[1]] ?? 0) + 1;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Shared
    // ------------------------------------------------------------------------------------------

    /**
     * Sold-unit rows with their warehouse item (code, name; null when not linked at the sale) and their store variant (the store's
     * variant number and its title, from the listing's profile).
     *
     * @param list<array<string, mixed>> $rows reservation_unit rows
     * @return list<array<string, mixed>>
     */
    private function describeUnits(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $skuIds = array_values(array_unique(array_map('intval', array_filter(array_column($rows, 'sku_id'), static fn (mixed $v): bool => $v !== null))));
        $skus = [];
        if ($skuIds !== []) {
            foreach ($this->db->all('SELECT id, code, name FROM sku WHERE id IN (' . self::marks($skuIds) . ')', $skuIds) as $s) {
                $skus[(int) $s['id']] = $s;
            }
        }
        $listingIds = array_values(array_unique(array_map('intval', array_column($rows, 'listing_id'))));
        $listings = [];
        foreach ($this->db->all('SELECT cl.id, cl.external_variant_id, lp.product_title, lp.variant_title FROM channel_listing cl '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id IN (' . self::marks($listingIds) . ')', $listingIds) as $l) {
            $listings[(int) $l['id']] = $l;
        }
        $out = [];
        foreach ($rows as $r) {
            $sku = $r['sku_id'] === null ? null : ($skus[(int) $r['sku_id']] ?? null);
            $l = $listings[(int) $r['listing_id']] ?? null;
            $title = $l === null ? '' : trim(implode(' ', array_filter([(string) ($l['product_title'] ?? ''), (string) ($l['variant_title'] ?? '')], static fn (string $t): bool => $t !== '')));
            $out[] = [
                'unit_id' => (string) $r['unit_id'], 'listing_id' => (int) $r['listing_id'], 'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'],
                'code' => $sku === null ? null : (string) ($sku['code'] ?? ''), 'name' => $sku === null ? null : (string) $sku['name'],
                'per_item' => (int) $r['units_per_item'], 'warehouse_id' => (int) $r['warehouse_id'], 'state' => (string) $r['state'],
                'sent_at' => $r['dispatched_at'] === null ? null : (string) $r['dispatched_at'], 'changed_at' => (string) $r['updated_at'],
                'variant' => $l === null ? null : (string) $l['external_variant_id'], 'title' => $title === '' ? null : mb_substr($title, 0, 200),
            ] + (isset($r['channel_id']) ? ['channel_id' => (int) $r['channel_id']] : []);
        }
        return $out;
    }

    /** The branches as one derived table: `(b)` or `((b1) UNION ALL (b2) ...)`. @param list<string> $branches */
    private static function union(array $branches): string
    {
        return count($branches) === 1 ? '(' . $branches[0] . ')' : '((' . implode(') UNION ALL (', $branches) . '))';
    }

    /** @param list<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
