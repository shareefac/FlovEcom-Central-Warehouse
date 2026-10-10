<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Clock;
use CW\Db;
use DateTimeImmutable;

/**
 * The read side of Stock › Reservations (docs/decisions.md RS1-RS12): the rows of `reservation` and `reservation_unit` as the engine
 * (CW\Reservations, the only writer) keeps them, for every store at once or one store. Reads only.
 *
 * The list is newest first by the reservation's id and pages by that id (`before`), never with a COUNT or an OFFSET over the table.
 * Every read keeps to an index (0023 says why one was added):
 *   - no store and no state: the primary key, backwards;
 *   - a store and/or a state: one range of ix_reservation_channel_status (channel_id, status, id) per (store, state) pair asked for,
 *     each newest first and cut at the page size, merged (at most page size x pairs ids);
 *   - a search: the reservations it finds first (a bounded set of ids), then those by the primary key. An order reference is looked
 *     up whole in uq_reservation_order; when no order has it, the text is read as a product (words, or a CW number, as Stock ›
 *     Movements finds products) and followed through ix_reservation_unit_sku_state (sku_id, warehouse_id, state), ONLY among the
 *     units reserved now (in a checkout, or sold and waiting to ship): a product's whole sales history has no index by time and
 *     would read every unit row it ever sold. Finding products by words reads the product list itself, as Movements does;
 *   - the units of the rows shown: ix_reservation_unit_reservation; a reservation's history: ix_stock_ledger_order.
 * The figures over the list are read from the same indexes and are bounded by what is reserved now; the units sold and waiting to
 * ship are stock_balance's `allocated` (every store together: per store they would need every unit row of the store, so that figure
 * is left out when a store is chosen). EXPLAIN of every statement, on 60,000 orders: docs/decisions.md RS11.
 */
final class ReservationViews
{
    public const PAGE = 50;
    /** reservation.status: the engine's four states. */
    public const STATES = ['held', 'committed', 'released', 'expired'];
    /** reservation_unit.state: what became of each sold item. */
    public const UNIT_STATES = ['held', 'allocated', 'shipped', 'cancelled', 'released', 'returned'];
    /**
     * The board's groups, in order. A committed (paid) reservation is `to_ship` while one of its items is still allocated and
     * `closed` once none is (sent, cancelled or returned); the other three are the engine's states.
     */
    public const GROUPS = ['held', 'to_ship', 'closed', 'released', 'expired'];
    /** The unit states that hold stock now: a product search looks at these only. */
    public const OPEN_UNITS = ['held', 'allocated'];
    /** Reservations a product search follows at most (the list says so when there are more). */
    public const FOUND_LIMIT = 1000;
    /** Rows of the CSV at most, read CSV_BATCH at a time. */
    public const CSV_LIMIT = 5000;
    public const CSV_BATCH = 500;
    /** Steps of one reservation's history shown at most. */
    public const HISTORY_LIMIT = 200;
    /** "Run out within the hour". */
    public const SOON_SEC = 3600;

    /** Ignored while the index does not exist yet (the code may run a moment before 0023). */
    private const HINT = '/*+ INDEX(r ix_reservation_channel_status) */ ';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id: int, code: string, name: string}> every store (the channel table's rows; none is named in the code), by name */
    public function stores(): array
    {
        return array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'code' => (string) $c['code'], 'name' => (string) $c['name']],
            $this->db->all('SELECT id, code, name FROM channel ORDER BY name, id'));
    }

    /** @return array<int, int> store id => its reservations held now (in a checkout, not paid) */
    public function heldByStore(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT c.id, (SELECT ' . self::HINT . "COUNT(*) FROM reservation r WHERE r.channel_id = c.id AND r.status = 'held') AS n FROM channel c") as $r) {
            $out[(int) $r['id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * The figures over the list, for one store or (null) every store: the reservations held now that run out within SOON_SEC (one
     * already past its time and not freed yet counts), the warehouse units they and the other holds keep, and the units sold and
     * waiting to ship (null for one store: see the class comment).
     *
     * @return array{soon: int, held_units: int, to_ship_units: ?int}
     */
    public function totals(?int $channelId, ?DateTimeImmutable $now = null): array
    {
        $until = Clock::db(($now ?? Clock::now())->modify('+' . self::SOON_SEC . ' seconds'));
        $store = $channelId === null ? '' : ' AND r.channel_id = ?';
        $p = $channelId === null ? [] : [$channelId];
        $soon = (int) $this->db->value("SELECT COUNT(*) FROM reservation r WHERE r.status = 'held' AND r.expires_at <= ?" . $store, [$until, ...$p]);
        $held = (int) $this->db->value(
            'SELECT ' . ($channelId === null ? '' : self::HINT) . 'COALESCE(SUM(ru.units_per_item), 0) FROM reservation r JOIN reservation_unit ru ON ru.reservation_id = r.id '
            . "WHERE r.status = 'held'" . $store . " AND ru.state = 'held' AND ru.sku_id IS NOT NULL",
            $p,
        );
        $toShip = $channelId !== null ? null : (int) $this->db->value('SELECT COALESCE(SUM(allocated), 0) FROM stock_balance');
        return ['soon' => $soon, 'held_units' => $held, 'to_ship_units' => $toShip];
    }

    /**
     * One page of the list, newest first: at most $size reservations older than `before`, and the id to ask for the next (older)
     * page. `by`: how a search text was read (`reference`: it is an order's reference; `product`: it was read as a product; null: no
     * search). `capped`: a product search found more than FOUND_LIMIT reservations, so some are not shown.
     *
     * @param array{channel: ?int, state: ?string, q: string, before: ?int} $f
     * @param list<array{id: int, code: string, name: string}> $stores
     * @return array{rows: list<array<string, mixed>>, next: ?int, capped: bool, by: ?string}
     */
    public function page(array $f, array $stores, int $size = self::PAGE): array
    {
        $storeIds = array_column($stores, 'id');
        $found = $f['q'] === '' ? null : $this->find($f['q'], $f['channel'] !== null ? [$f['channel']] : $storeIds);
        $ids = $this->ids($f, $storeIds, $size + 1, $found['ids'] ?? null);
        $next = null;
        if (count($ids) > $size) {
            $ids = array_slice($ids, 0, $size);
            $next = $ids[$size - 1];
        }
        return ['rows' => $this->rows($ids), 'next' => $next, 'capped' => $found['capped'] ?? false, 'by' => $found['by'] ?? null];
    }

    /**
     * The whole filtered list for a file, newest first: at most $limit rows (a multiple of $batch), read $batch at a time by id (the
     * list's own index ranges, never an OFFSET). `more`: the list goes on after them.
     *
     * @param array{channel: ?int, state: ?string, q: string, before: ?int} $f
     * @param list<array{id: int, code: string, name: string}> $stores
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    public function export(array $f, array $stores, int $batch = self::CSV_BATCH, int $limit = self::CSV_LIMIT): array
    {
        $storeIds = array_column($stores, 'id');
        $found = $f['q'] === '' ? null : $this->find($f['q'], $f['channel'] !== null ? [$f['channel']] : $storeIds);
        $rows = [];
        $before = null;
        $more = false;
        while (true) {
            $ids = $this->ids(['before' => $before] + $f, $storeIds, $batch + 1, $found['ids'] ?? null);
            $part = array_slice($ids, 0, $batch);
            array_push($rows, ...$this->rows($part));
            if (count($ids) <= $batch) {
                break;
            }
            if (count($rows) >= $limit) {
                $more = true;
                break;
            }
            $before = $part[$batch - 1];
        }
        return ['rows' => $rows, 'more' => $more || ($found['capped'] ?? false)];
    }

    /** One reservation as the list shows it, or null. @return array<string, mixed>|null */
    public function one(int $id): ?array
    {
        return $this->rows([$id])[0] ?? null;
    }

    /**
     * The items of one reservation, one line per store product, warehouse and state: how many sold items, the warehouse units they
     * stand for, the warehouse product (null when the store product was not linked at the sale: such a line holds no stock) and, for
     * those, the store's own name of the product.
     *
     * @return list<array{listing_id: int, sku_id: ?int, code: ?string, name: ?string, per_item: int, warehouse: string, state: string, items: int, units: int, sent_at: ?string, variant: ?string, title: ?string}>
     */
    public function lines(int $id): array
    {
        $rows = $this->db->all(
            'SELECT ru.listing_id, ru.sku_id, ru.units_per_item, ru.warehouse_id, ru.state, COUNT(*) AS items, SUM(ru.units_per_item) AS units, MAX(ru.dispatched_at) AS sent_at '
            . 'FROM reservation_unit ru WHERE ru.reservation_id = ? GROUP BY ru.listing_id, ru.sku_id, ru.units_per_item, ru.warehouse_id, ru.state '
            . 'ORDER BY ru.listing_id, ru.warehouse_id, ru.state',
            [$id],
        );
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
        // The store's own name of a product is read only for the lines without a warehouse product (a listing profile is a wide row).
        $loose = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['listing_id'], array_filter($rows, static fn (array $r): bool => $r['sku_id'] === null))));
        $listings = [];
        if ($loose !== []) {
            foreach ($this->db->all('SELECT cl.id, cl.external_variant_id, lp.product_title, lp.variant_title FROM channel_listing cl '
                . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id IN (' . self::marks($loose) . ')', $loose) as $l) {
                $listings[(int) $l['id']] = $l;
            }
        }
        $warehouses = array_column($this->db->all('SELECT id, name FROM warehouse'), 'name', 'id');
        $out = [];
        foreach ($rows as $r) {
            $sku = $r['sku_id'] === null ? null : ($skus[(int) $r['sku_id']] ?? null);
            $l = $listings[(int) $r['listing_id']] ?? null;
            $title = $l === null ? null : trim(implode(' ', array_filter([(string) ($l['product_title'] ?? ''), (string) ($l['variant_title'] ?? '')], static fn (string $t): bool => $t !== '')));
            $out[] = [
                'listing_id' => (int) $r['listing_id'], 'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'],
                'code' => $sku === null ? null : (string) ($sku['code'] ?? ''), 'name' => $sku === null ? null : (string) $sku['name'],
                'per_item' => (int) $r['units_per_item'], 'warehouse' => (string) ($warehouses[(int) $r['warehouse_id']] ?? ''), 'state' => (string) $r['state'],
                'items' => (int) $r['items'], 'units' => $r['sku_id'] === null ? 0 : (int) $r['units'], 'sent_at' => $r['sent_at'] === null ? null : (string) $r['sent_at'],
                'variant' => $l === null ? null : (string) $l['external_variant_id'], 'title' => $title === null || $title === '' ? null : mb_substr($title, 0, 200),
            ];
        }
        return $out;
    }

    /**
     * What the engine did to the stock for one order, oldest first: the stock ledger's rows of the order (the only history the engine
     * keeps of a reservation), one step per call of the engine (its Idempotency-Key; the minute for the expiry job, which has none):
     * the kind of change, who, when, how many sold items, and the change of each stock figure. An order whose products are not
     * linked moved no stock and has no steps.
     *
     * @return list<array{type: string, actor: string, at: string, items: int, held: int, allocated: int, on_hand: int}>
     */
    public function history(int $channelId, string $orderRef): array
    {
        $sum = static fn (string $bucket): string => "COALESCE(SUM(CASE WHEN l.bucket = '{$bucket}' THEN l.qty_delta ELSE 0 END), 0) AS {$bucket}";
        return array_map(static fn (array $r): array => ['type' => (string) $r['movement_type'], 'actor' => (string) $r['actor'], 'at' => (string) $r['at'],
            'items' => (int) $r['items'], 'held' => (int) $r['held'], 'allocated' => (int) $r['allocated'], 'on_hand' => (int) $r['on_hand']],
            $this->db->all(
                'SELECT l.movement_type, l.actor, MIN(l.created_at) AS at, MIN(l.id) AS first_id, COUNT(DISTINCT l.unit_id) AS items, '
                . $sum('held') . ', ' . $sum('allocated') . ', ' . $sum('on_hand') . ' '
                . 'FROM stock_ledger l WHERE l.channel_id = ? AND l.order_ref = ? '
                . "GROUP BY l.movement_type, l.actor, l.idem_key, IF(l.idem_key IS NULL, DATE_FORMAT(l.created_at, '%Y-%m-%d %H:%i'), '') "
                . 'ORDER BY first_id LIMIT ' . self::HISTORY_LIMIT,
                [$channelId, $orderRef],
            ));
    }

    /**
     * The ids of the reservations a page shows, newest first, at most $limit. $found: the reservations a search found (null: no
     * search), read by the primary key; else the primary key itself (no store, no state) or one index range per (store, state) pair.
     *
     * @param array{channel: ?int, state: ?string, q?: string, before: ?int} $f
     * @param list<int> $storeIds
     * @param list<int>|null $found
     * @return list<int>
     */
    private function ids(array $f, array $storeIds, int $limit, ?array $found): array
    {
        $older = $f['before'] === null ? [] : [$f['before']];
        if ($found !== null) {
            if ($found === []) {
                return [];
            }
            $where = ['r.id IN (' . self::marks($found) . ')'];
            $params = $found;
            foreach (['channel' => 'r.channel_id = ?', 'state' => 'r.status = ?', 'before' => 'r.id < ?'] as $k => $cond) {
                if ($f[$k] !== null) {
                    $where[] = $cond;
                    $params[] = $f[$k];
                }
            }
            $sql = 'SELECT r.id FROM reservation r WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT ' . $limit;
        } elseif ($f['channel'] === null && $f['state'] === null) {
            $sql = 'SELECT r.id FROM reservation r' . ($older === [] ? '' : ' WHERE r.id < ?') . ' ORDER BY r.id DESC LIMIT ' . $limit;
            $params = $older;
        } else {
            $branches = [];
            $params = [];
            foreach ($f['channel'] !== null ? [$f['channel']] : $storeIds as $store) {
                foreach ($f['state'] !== null ? [$f['state']] : self::STATES as $state) {
                    $branches[] = 'SELECT ' . self::HINT . 'r.id FROM reservation r WHERE r.channel_id = ? AND r.status = ?' . ($older === [] ? '' : ' AND r.id < ?')
                        . ' ORDER BY r.id DESC LIMIT ' . $limit;
                    array_push($params, $store, $state, ...$older);
                }
            }
            if ($branches === []) {
                return [];
            }
            $sql = count($branches) === 1 ? $branches[0]
                : 'SELECT x.id FROM ((' . implode(') UNION ALL (', $branches) . ')) x ORDER BY x.id DESC LIMIT ' . $limit;
        }
        return array_map('intval', $this->db->column($sql, $params));
    }

    /**
     * The reservations a search text finds. First the ones whose order reference it is (whole, in the stores looked at): when there
     * is one, that is the answer (`by` reference), and the product list is not read at all. Else the text is read as a product (`by`
     * product): the reservations that hold stock now of a product it finds (a CW number, or every word in the name, brand or code:
     * StockViews::skuMatch; at most StockViews::SKU_LIMIT products, at most FOUND_LIMIT reservations: `capped` when there are more).
     *
     * @param list<int> $storeIds
     * @return array{ids: list<int>, capped: bool, by: string}
     */
    private function find(string $q, array $storeIds): array
    {
        $ids = [];
        // An order reference is 1 to 64 printable characters without a space (Reservations::ref).
        if ($storeIds !== [] && preg_match('/^[\x21-\x7e]{1,64}$/D', $q) === 1) {
            foreach ($this->db->column('SELECT r.id FROM reservation r WHERE r.channel_id IN (' . self::marks($storeIds) . ') AND r.order_ref = ?', [...$storeIds, $q]) as $id) {
                $ids[(int) $id] = true;
            }
            if ($ids !== []) {
                return ['ids' => array_keys($ids), 'capped' => false, 'by' => 'reference'];
            }
        }
        [$where, $params] = StockViews::skuMatch($q, 'sku');
        $skus = $where === [] ? []
            : array_map('intval', $this->db->column('SELECT id FROM sku WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT ' . StockViews::SKU_LIMIT, $params));
        $capped = false;
        // Every warehouse is named so that the index (sku_id, warehouse_id, state) is read at exactly the units reserved now, never
        // along a product's whole sales history; and the index is named, because with many products MySQL 8.4 estimates the ranges
        // from the index statistics and then prefers to read the whole table (seen with EXPLAIN on 200 products, 10 Oct 2026).
        $warehouses = $skus === [] ? [] : array_map('intval', $this->db->column('SELECT id FROM warehouse'));
        if ($skus !== [] && $warehouses !== []) {
            $open = $this->db->column(
                'SELECT /*+ INDEX(ru ix_reservation_unit_sku_state) */ DISTINCT ru.reservation_id FROM reservation_unit ru '
                . 'WHERE ru.sku_id IN (' . self::marks($skus) . ') AND ru.warehouse_id IN (' . self::marks($warehouses) . ') '
                . "AND ru.state IN ('" . implode("', '", self::OPEN_UNITS) . "') LIMIT " . (self::FOUND_LIMIT + 1),
                [...$skus, ...$warehouses],
            );
            $capped = count($open) > self::FOUND_LIMIT;
            foreach (array_slice($open, 0, self::FOUND_LIMIT) as $id) {
                $ids[(int) $id] = true;
            }
        }
        return ['ids' => array_keys($ids), 'capped' => $capped, 'by' => 'product'];
    }

    /**
     * The reservations with these ids, newest first, each with its items added up: the store products on it, the sold items, the
     * warehouse units they stand for (an item not linked to a warehouse product stands for none), how many items are not linked, how
     * many items are in each state, and the board's group.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function rows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = self::marks($ids);
        $states = '';
        foreach (self::UNIT_STATES as $s) {
            $states .= ", COALESCE(SUM(ru.state = '{$s}'), 0) AS n_{$s}";
        }
        $units = [];
        foreach ($this->db->all(
            'SELECT ru.reservation_id, COUNT(DISTINCT ru.listing_id) AS products, COUNT(*) AS items, '
            . 'COALESCE(SUM(CASE WHEN ru.sku_id IS NULL THEN 0 ELSE ru.units_per_item END), 0) AS units, COALESCE(SUM(ru.sku_id IS NULL), 0) AS unlinked' . $states
            . ' FROM reservation_unit ru WHERE ru.reservation_id IN (' . $marks . ') GROUP BY ru.reservation_id',
            $ids,
        ) as $u) {
            $units[(int) $u['reservation_id']] = $u;
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT r.id, r.channel_id, r.order_ref, r.status, r.attempt, r.origin, r.is_tombstone, r.expires_at, r.committed_at, r.released_at, r.created_at '
            . 'FROM reservation r WHERE r.id IN (' . $marks . ') ORDER BY r.id DESC',
            $ids,
        ) as $r) {
            $u = $units[(int) $r['id']] ?? [];
            $by = [];
            foreach (self::UNIT_STATES as $s) {
                $by[$s] = (int) ($u['n_' . $s] ?? 0);
            }
            $status = (string) $r['status'];
            $out[] = [
                'id' => (int) $r['id'], 'channel_id' => (int) $r['channel_id'], 'ref' => (string) $r['order_ref'], 'status' => $status,
                'group' => $status === 'committed' ? ($by['allocated'] > 0 ? 'to_ship' : 'closed') : $status,
                'attempt' => (int) $r['attempt'], 'origin' => (string) $r['origin'], 'tombstone' => (int) $r['is_tombstone'] === 1,
                'created_at' => (string) $r['created_at'], 'expires_at' => $r['expires_at'] === null ? null : (string) $r['expires_at'],
                'committed_at' => $r['committed_at'] === null ? null : (string) $r['committed_at'],
                'released_at' => $r['released_at'] === null ? null : (string) $r['released_at'],
                'products' => (int) ($u['products'] ?? 0), 'items' => (int) ($u['items'] ?? 0), 'units' => (int) ($u['units'] ?? 0),
                'unlinked' => (int) ($u['unlinked'] ?? 0), 'by_state' => $by,
            ];
        }
        return $out;
    }

    /** @param list<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
