<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Db;
use CW\PurchaseOrders\PoMath;

/**
 * The stored reorder list (pack G; docs/decisions.md PG3): what the list, its download and Low-stock warnings read, so a page view
 * reads one page of lines and never works the list out (ReorderRefresh writes it).
 *
 *  page()   one page in list order (urgent, then days left, no sales last, then the value, then the item), and the total. Two reads:
 *           the ids of the page from the compact rows (reorder_line, an index in list order), then their whole lines
 *           (reorder_line_detail, by primary key).
 *  ids()    every id in list order (the download, read in chunks with lines()).
 *  lines()  the whole lines of some ids, in the order given.
 * Filters (ReorderList::filters plus `low`): brand (the column ignores case), main supplier, words of the name, brand or code, or a CW
 * code, urgent only, below the safety stock only (whatever there is to buy), show need (something to buy) or all, and low (Low-stock
 * warnings: any, set (CW keeps a figure), own (each website keeps its own)).
 *
 * "Buy for N days" (filter `buy_for`; docs/decisions.md BF1-BF3, BF9): every listed line bought for N days in place of its order cycle.
 * The compact rows hold the inputs of a line's need (INPUTS, written by ReorderRefresh with the line), so a read of the compact rows works
 * out, in SQL, each line's packs and cost for N exactly as ReorderMath::line() does (buyForSql()): "Products to buy", the count and the
 * order follow N without reading a JSON line, the ledger or an order line. The lines of the page (or of a chunk of the download) are then
 * read as always and worked out for N in PHP (ReorderList::forDays()), so what is shown is ReorderMath's own figures; the SQL only chooses,
 * counts and orders them (tests hold SQL = PHP). A page bought for N is two reads of the compact rows (the counts, then the page's ids),
 * each one pass over the listed rows (about 20,000), then the 200 lines by primary key.
 */
final class ReorderLines
{
    public const LOW = ['any', 'set', 'own'];
    private const ORDER = 'ORDER BY rl.urgent DESC, rl.no_demand, rl.cover_now_e1, rl.value_e4 DESC, rl.sku_id';
    /**
     * A line's packs bought for N days from its packs before the smallest order and steps (`packs0` of buyForSql()'s table `k`): 0 when
     * never suggested or nothing to buy, else ⌈max(packs0, moq) / mult⌉·mult.
     */
    private const PACKS_N = 'IF(k.never_suggested = 1 OR k.packs0 = 0, 0, ((GREATEST(k.packs0, k.moq_packs) + k.multiple_packs - 1) DIV k.multiple_packs) * k.multiple_packs)';
    /**
     * The reads of buyForSql()'s table (BF9): `k` is worked out once into a table of its own (NO_MERGE), and no condition is pushed into it
     * (NO_DERIVED_CONDITION_PUSHDOWN), so each row's need is worked out once, not again for "to buy", the count and the order.
     */
    private const HINT_N = '/*+ NO_MERGE(k) NO_DERIVED_CONDITION_PUSHDOWN(k) */';
    /**
     * The compact inputs of a line's need (0061; BF3), in the order inputs() gives them: sales a day × the factor (e6), delivery days, spare
     * days, available + on order, the smallest and largest stock, the pack, its smallest order and steps (packs), nearest rounding, the pack
     * price (e4). NULL d_e6: not stored yet.
     */
    public const INPUTS = ['d_e6', 'lead_days', 'safety_days', 'stock_position', 'min_stock', 'max_stock', 'units_per_pack', 'moq_packs', 'multiple_packs',
        'round_nearest', 'pack_price_e4'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * One page and the total. Bought for N days (`buy_for`) also `waiting`: how many lines of the filters have no inputs for N yet (BF4:
     * written by the refresh since 0061; until then such a line is never "to buy" for N, and the page says so); 0 for the usual list.
     *
     * @param array<string, mixed> $f ReorderList::filters() (+ `low`)
     * @return array{lines: list<array<string, mixed>>, total: int, waiting: int}
     */
    public function page(array $f, int $page, int $size): array
    {
        $days = self::days($f);
        $limit = ' LIMIT ' . max(1, $size) . ' OFFSET ' . max(0, ($page - 1) * $size);
        if ($days !== null) {
            [$sql, $params, $need] = self::buyForSql($f, $days);
            $c = (array) $this->db->one('SELECT ' . self::HINT_N . ' COUNT(*) AS n, COALESCE(SUM(' . self::PACKS_N . ' > 0), 0) AS buy, '
                . 'COALESCE(SUM(k.ready = 0), 0) AS waiting FROM ' . $sql, $params);
            $ids = array_map('intval', $this->db->column(self::idsN($sql, $need) . $limit, $params));
            return ['lines' => $this->lines($ids, $days), 'total' => (int) ($need ? $c['buy'] : $c['n']), 'waiting' => (int) $c['waiting']];
        }
        [$where, $params, $join] = self::where($f);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM reorder_line rl{$join} WHERE {$where}", $params);
        $ids = array_map('intval', $this->db->column("SELECT rl.sku_id FROM reorder_line rl{$join} WHERE {$where} " . self::ORDER . $limit, $params));
        return ['lines' => $this->lines($ids), 'total' => $total, 'waiting' => 0];
    }

    /**
     * @param array<string, mixed> $f
     * @return list<int>
     */
    public function ids(array $f): array
    {
        $days = self::days($f);
        if ($days !== null) {
            [$sql, $params, $need] = self::buyForSql($f, $days);
            return array_map('intval', $this->db->column(self::idsN($sql, $need), $params));
        }
        [$where, $params, $join] = self::where($f);
        return array_map('intval', $this->db->column("SELECT rl.sku_id FROM reorder_line rl{$join} WHERE {$where} " . self::ORDER, $params));
    }

    /**
     * The whole lines of some ids, in the order given; with $days, each bought for those days (ReorderList::forDays(), BF2).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function lines(array $ids, ?int $days = null): array
    {
        if ($ids === []) {
            return [];
        }
        $by = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            // By primary key whatever the statistics say (a freshly filled table read "one row" and was scanned whole: PG12).
            foreach ($this->db->all('SELECT sku_id, CAST(line AS CHAR) AS line FROM reorder_line_detail FORCE INDEX (PRIMARY) WHERE sku_id IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                $chunk) as $r) {
                $by[(int) $r['sku_id']] = (array) json_decode((string) $r['line'], true, 64, JSON_THROW_ON_ERROR);
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($by[$id])) {
                $out[] = $days === null ? $by[$id] : ReorderList::forDays($by[$id], $days);
            }
        }
        return $out;
    }

    /**
     * The compact inputs of a line (INPUTS order; BF3): what ReorderRefresh stores with the line, and compares with what is stored.
     *
     * @param array<string, mixed> $l a ReorderList::lines() line (usual)
     * @return list<?int>
     */
    public static function inputs(array $l): array
    {
        return [(int) $l['d_e6'], (int) $l['lead'], (int) $l['safety'], (int) $l['position'], $l['min_stock'] === null ? null : (int) $l['min_stock'],
            $l['max_stock'] === null ? null : (int) $l['max_stock'], (int) $l['upp'], (int) $l['moq'], (int) $l['mult'], $l['rounding'] === 'nearest' ? 1 : 0,
            $l['pack_price'] === null ? null : PoMath::e4((string) $l['pack_price'])];
    }

    /** The days of "Buy for N days" in the filters, or null (the usual order cycles). @param array<string, mixed> $f */
    private static function days(array $f): ?int
    {
        $d = $f['buy_for'] ?? null;
        return is_int($d) && $d > 0 ? $d : null;
    }

    /** The ids of buyForSql()'s table in list order: those with packs for N ($need, "Products to buy") or all. */
    private static function idsN(string $sql, bool $need): string
    {
        return 'SELECT ' . self::HINT_N . ' k.sku_id FROM ' . $sql . ($need ? ' WHERE ' . self::PACKS_N . ' > 0' : '')
            . ' ORDER BY k.urgent DESC, k.no_demand, k.cover_now_e1, COALESCE(' . self::PACKS_N . ' * k.pack_price_e4, 0) DESC, k.sku_id';
    }

    /**
     * The listed lines matching the filters, each with its packs before the smallest order and steps bought for $days days, worked out in
     * SQL from the compact inputs exactly as ReorderMath::line() does with $days in place of R (BF3; every quantity is a whole number and
     * never below 0, so DIV is PHP's intdiv and (a + b - 1) DIV b its ceilDiv):
     *   raw = ⌈d·(L + days + S) / 10^6⌉;  target = min(max(raw, min_stock ?? 0), max_stock ?? ∞);  need = max(0, target - P)
     *   packs0 = ⌈need / upp⌉, or ⌊(2·need + upp) / (2·upp)⌋ rounded to the nearest: both are (need·(1+r) + upp - 1 + r) DIV (upp·(1+r))
     *            with r = 1 for nearest, else 0 (packs0 = 0 whenever need = 0)
     * then PACKS_N (the smallest order, the steps, never suggested) and value = packs × price (0 without one) in the reads (idsN(), page()).
     * A row without inputs (d_e6 NULL, BF4) has no packs (`ready` 0): never "to buy", value 0.
     * Returns the FROM part (a derived table `k`: sku_id, urgent, no_demand, cover_now_e1, never_suggested, moq_packs, multiple_packs,
     * pack_price_e4, ready, packs0), its parameters, and whether only lines with packs are kept ("Products to buy"; every product and below
     * the safety stock: whatever there is to buy, as the usual list).
     * BF9 (EXPLAIN on 20,000 lines): the inner read names no index of the list's order (they start with `listed`, true of nearly every row:
     * read through one, every row was a second look-up by primary key, 100 ms of a 146 ms count); a brand or a supplier still takes its own.
     *
     * @param array<string, mixed> $f
     * @return array{0: string, 1: list<mixed>, 2: bool}
     */
    private static function buyForSql(array $f, int $days): array
    {
        [$where, $params, $join] = self::where($f, false);
        $sql = '(SELECT rl.sku_id, rl.urgent, rl.no_demand, rl.cover_now_e1, rl.never_suggested, rl.moq_packs, rl.multiple_packs, rl.pack_price_e4, '
            . 'rl.d_e6 IS NOT NULL AS ready, '
            . '(GREATEST(0, LEAST(GREATEST((rl.d_e6 * (rl.lead_days + ? + rl.safety_days) + 999999) DIV 1000000, COALESCE(rl.min_stock, 0)), '
            . 'COALESCE(rl.max_stock, 9223372036854775807)) - rl.stock_position) * (1 + rl.round_nearest) + rl.units_per_pack - 1 + rl.round_nearest) '
            . 'DIV (rl.units_per_pack * (1 + rl.round_nearest)) AS packs0 '
            . "FROM reorder_line rl IGNORE INDEX (ix_reorder_line_need, ix_reorder_line_all, ix_reorder_line_below){$join} WHERE {$where}) k";
        return [$sql, [$days, ...$params], !(bool) ($f['below'] ?? false) && ($f['show'] ?? 'need') === 'need'];
    }

    /** @return list<string> the brands of the listed items (the brand filter) */
    public function brands(): array
    {
        return array_map('strval', $this->db->column('SELECT DISTINCT brand FROM reorder_line WHERE listed = 1 AND brand IS NOT NULL ORDER BY brand'));
    }

    /** @return int how many items are listed */
    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM reorder_line WHERE listed = 1');
    }

    /**
     * @param array<string, mixed> $f
     * @return array{0: string, 1: list<mixed>, 2: string}
     */
    private static function where(array $f, bool $storedNeed = true): array
    {
        $where = ['rl.listed = 1'];
        $params = [];
        $join = '';
        $below = (bool) ($f['below'] ?? false);
        if ($below) {
            $where[] = 'rl.below_safety = 1';
        } elseif ($storedNeed && ($f['show'] ?? 'need') === 'need') {
            $where[] = 'rl.need = 1'; // the usual list's "to buy"; bought for N days, buyForSql() keeps those with packs for N
        }
        if ($f['urgent'] ?? false) {
            $where[] = 'rl.urgent = 1';
        }
        if (($f['brand'] ?? null) !== null) {
            $where[] = 'rl.brand = ?';
            $params[] = (string) $f['brand'];
        }
        if (($f['supplier'] ?? null) !== null) {
            $where[] = 'rl.supplier_id = ?';
            $params[] = (int) $f['supplier'];
        }
        $low = in_array($f['low'] ?? 'any', self::LOW, true) ? (string) ($f['low'] ?? 'any') : 'any';
        if ($low === 'set') {
            $where[] = 'rl.low_stock_level IS NOT NULL';
        } elseif ($low === 'own') {
            $where[] = 'rl.low_stock_level IS NULL';
        }
        $q = (string) ($f['q'] ?? '');
        if ($q !== '') {
            if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $q, $m) === 1) {
                $where[] = 'rl.sku_id = ?';
                $params[] = (int) $m[1];
            } else {
                $join = ' JOIN sku s ON s.id = rl.sku_id';
                foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $w) {
                    $where[] = "CONCAT_WS(' ', s.name, s.brand, s.code) LIKE ?";
                    $params[] = '%' . addcslashes($w, '\\%_') . '%';
                }
            }
        }
        return [implode(' AND ', $where), $params, $join];
    }
}
