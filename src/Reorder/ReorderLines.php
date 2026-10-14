<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Db;

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
 */
final class ReorderLines
{
    public const LOW = ['any', 'set', 'own'];
    private const ORDER = 'ORDER BY rl.urgent DESC, rl.no_demand, rl.cover_now_e1, rl.value_e4 DESC, rl.sku_id';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string, mixed> $f ReorderList::filters() (+ `low`)
     * @return array{lines: list<array<string, mixed>>, total: int}
     */
    public function page(array $f, int $page, int $size): array
    {
        [$where, $params, $join] = self::where($f);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM reorder_line rl{$join} WHERE {$where}", $params);
        $ids = array_map('intval', $this->db->column("SELECT rl.sku_id FROM reorder_line rl{$join} WHERE {$where} " . self::ORDER . ' LIMIT ' . max(1, $size)
            . ' OFFSET ' . max(0, ($page - 1) * $size), $params));
        return ['lines' => $this->lines($ids), 'total' => $total];
    }

    /**
     * @param array<string, mixed> $f
     * @return list<int>
     */
    public function ids(array $f): array
    {
        [$where, $params, $join] = self::where($f);
        return array_map('intval', $this->db->column("SELECT rl.sku_id FROM reorder_line rl{$join} WHERE {$where} " . self::ORDER, $params));
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function lines(array $ids): array
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
                $out[] = $by[$id];
            }
        }
        return $out;
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
    private static function where(array $f): array
    {
        $where = ['rl.listed = 1'];
        $params = [];
        $join = '';
        $below = (bool) ($f['below'] ?? false);
        if ($below) {
            $where[] = 'rl.below_safety = 1';
        } elseif (($f['show'] ?? 'need') === 'need') {
            $where[] = 'rl.need = 1';
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
