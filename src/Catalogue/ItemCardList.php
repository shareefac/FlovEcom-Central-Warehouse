<?php

declare(strict_types=1);

namespace CW\Catalogue;

use CW\Db;
use CW\Matching\Gtin;

/**
 * The item cards list and its CSV (IM3; docs/decisions.md I109): every item that is not merged away, with its card, ordered by
 * the stock it holds (Σ max(on_hand, 0) over every warehouse), so the items holding stock (8,199 on staging) come first and the
 * catalogue work starts there. Filters: words (code, name, catalogue brand), state (no card, not confirmed, changed since it was
 * confirmed, confirmed), holds stock, warned or blocked (ItemRules::sqlFlagged: ItemRules::status in SQL), blocked
 * (ItemRules::sqlBlocked), product type (or not set), discontinued.
 *
 * Each row also says, per store of STORES, whether the item is linked there (`links`, docs/decisions.md IL2): Items' three small icon columns
 * and the CSV's yes/no columns (the owner, 17 Nov 2026: "add 3 small extra column with alt, vpg, vbig ... to know if it is connected or not").
 */
final class ItemCardList
{
    public const PAGE = 100;
    /**
     * The stores Items shows a column for, in the owner's order ("alt, vpg, vbig": Alt is Electrofag), by channel code. A store that is not
     * there (a new test system) shows every item as not linked; another store (a proto's channel) has no column.
     */
    public const STORES = ['electrofag', 'vapeandgo', 'vapebig'];
    /** The items one read of links() asks about: a page (PAGE) is one read; the CSV of the whole catalogue (about 15,300 items) sixteen. */
    public const LINKS_CHUNK = 1_000;
    public const STATES = [
        'all' => 'Any',
        'unconfirmed' => 'Not confirmed (no card, or not confirmed yet)',
        'none' => 'No card yet',
        'changed' => 'Changed since it was confirmed',
        'confirmed' => 'Confirmed',
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The filters from a query string (unknown values fall back to the defaults).
     *
     * @param array<string, mixed> $q
     * @return array{q: string, state: string, stock: bool, warnings: bool, blocked: bool, type: string, discontinued: bool, page: int}
     */
    public static function filters(array $q): array
    {
        $s = static fn (string $k): string => is_string($q[$k] ?? null) ? (string) $q[$k] : '';
        $type = $s('type');
        $page = $s('page');
        return [
            'q' => mb_substr(trim($s('q')), 0, 100),
            'state' => isset(self::STATES[$s('state')]) ? $s('state') : 'all',
            'stock' => $s('stock') === '1',
            'warnings' => $s('warnings') === '1',
            'blocked' => $s('blocked') === '1',
            'type' => $type === 'none' || isset(ItemRules::TYPES[$type]) ? $type : '',
            'discontinued' => $s('discontinued') === '1',
            'page' => preg_match('/^[1-9][0-9]{0,5}$/D', $page) === 1 ? (int) $page : 1,
        ];
    }

    /** @param array{q: string, state: string, stock: bool, warnings: bool, blocked: bool, type: string, discontinued: bool, page: int} $f @return array<string, string> the filters as URL query values */
    public static function query(array $f, bool $withPage = false): array
    {
        return array_filter([
            'q' => $f['q'], 'state' => $f['state'] === 'all' ? '' : $f['state'], 'stock' => $f['stock'] ? '1' : '', 'warnings' => $f['warnings'] ? '1' : '', 'blocked' => $f['blocked'] ? '1' : '',
            'type' => $f['type'], 'discontinued' => $f['discontinued'] ? '1' : '', 'page' => $withPage && $f['page'] > 1 ? (string) $f['page'] : '',
        ], static fn (string $v): bool => $v !== '');
    }

    /**
     * The rows of the filtered list, in list order: the sku columns (id, code, name, catalogue brand), `held` and every card
     * column (NULL without a card), confirmed_by_name, and `links` (links(): store code => its linked website products, for every
     * store of STORES; one more read per LINKS_CHUNK rows, never one per row).
     *
     * @param array{q: string, state: string, stock: bool, warnings: bool, blocked: bool, type: string, discontinued: bool, page: int} $f
     * @return list<array<string, mixed>>
     */
    public function rows(array $f, ?int $limit = self::PAGE, int $offset = 0): array
    {
        [$where, $params] = $this->where($f);
        $rows = $this->db->all('SELECT s.id, s.code, s.name, s.brand AS catalogue_brand, COALESCE(st.held, 0) AS held, c.sku_id AS card_sku_id, c.version, '
            . 'c.product_type, c.liquid_ml, c.nicotine_mg, c.duty_liable, c.single_use, c.ecid, c.manufacturer, c.brand, c.flavour, c.flavour_status, '
            . 'c.discontinued, c.confirmed_at, c.confirmed_breaches, c.first_confirmed_at, u.display_name AS confirmed_by_name '
            . $this->from() . " WHERE {$where} ORDER BY held DESC, s.id"
            . ($limit === null ? '' : ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)), $params);
        $links = $this->links(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        foreach ($rows as $i => $r) {
            $rows[$i]['links'] = $links[(int) $r['id']];
        }
        return $rows;
    }

    /**
     * Where each item is linked (docs/decisions.md IL2): per store of STORES, how many of that store's website products are linked to it
     * (`channel_listing.sku_id` = the item: the link of DecisionService, the only writer) and how many of those are on hold. 0001's CHECK
     * ties a set sku_id to the two linked statuses, `mapped` and `quarantined` (on hold: linked, held off sale; D32), so the count reads the
     * link alone; a suggestion, a website product not matched, ignored or a landing page has no sku_id and never counts. A merged item has
     * none: a merge moves every link of the item folded away to the kept item (M10), whose row then counts them; the list leaves merged
     * items out anyway. One read per LINKS_CHUNK items (a page: one), on the (sku_id, channel_id) index of 0024.
     *
     * @param list<int> $skuIds
     * @return array<int, array<string, array{linked: int, on_hold: int}>> item id => store code (every one of STORES, in that order) => counts
     */
    public function links(array $skuIds): array
    {
        $empty = array_fill_keys(self::STORES, ['linked' => 0, 'on_hold' => 0]);
        $out = [];
        foreach ($skuIds as $id) {
            $out[$id] = $empty;
        }
        foreach (array_chunk(array_keys($out), self::LINKS_CHUNK) as $chunk) {
            $rows = $this->db->all('SELECT cl.sku_id, ch.code, COUNT(*) AS linked, SUM(cl.status = \'quarantined\') AS on_hold '
                . 'FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id '
                . 'WHERE cl.sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') AND ch.code IN (' . implode(', ', array_fill(0, count(self::STORES), '?')) . ') '
                . 'GROUP BY cl.sku_id, ch.code', [...$chunk, ...self::STORES]);
            foreach ($rows as $r) {
                $out[(int) $r['sku_id']][(string) $r['code']] = ['linked' => (int) $r['linked'], 'on_hold' => (int) $r['on_hold']];
            }
        }
        return $out;
    }

    /** @param array{q: string, state: string, stock: bool, warnings: bool, blocked: bool, type: string, discontinued: bool, page: int} $f */
    public function count(array $f): int
    {
        [$where, $params] = $this->where($f);
        return (int) $this->db->value('SELECT COUNT(*) ' . $this->from() . " WHERE {$where}", $params);
    }

    /**
     * The numbers on top of the list: items (not merged), holding stock, cards, confirmed (and of the items holding stock),
     * breaking a rule (warned / blocked), discontinued.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $breach = ItemRules::sqlBreach('c');
        $blocked = ItemRules::sqlBlocked('c');
        $r = $this->db->one('SELECT COUNT(*) AS items, SUM(COALESCE(st.held, 0) > 0) AS with_stock, SUM(c.sku_id IS NOT NULL) AS cards, '
            . 'SUM(c.confirmed_at IS NOT NULL) AS confirmed, SUM(c.confirmed_at IS NOT NULL AND COALESCE(st.held, 0) > 0) AS confirmed_with_stock, '
            . "SUM(NOT {$blocked} AND {$breach} = 1) AS warned, SUM({$blocked}) AS blocked, "
            . 'SUM(c.discontinued = 1) AS discontinued ' . $this->from() . ' WHERE s.merged_into_sku_id IS NULL') ?? [];
        $out = [];
        foreach (['items', 'with_stock', 'cards', 'confirmed', 'confirmed_with_stock', 'warned', 'blocked', 'discontinued'] as $k) {
            $out[$k] = (int) ($r[$k] ?? 0);
        }
        return $out;
    }

    private function from(): string
    {
        return 'FROM sku s LEFT JOIN (SELECT sku_id, SUM(GREATEST(on_hand, 0)) AS held FROM stock_balance GROUP BY sku_id) st ON st.sku_id = s.id '
            . 'LEFT JOIN item_card c ON c.sku_id = s.id LEFT JOIN staff_user u ON u.id = c.confirmed_by';
    }

    /**
     * @param array{q: string, state: string, stock: bool, warnings: bool, blocked: bool, type: string, discontinued: bool, page: int} $f
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $f): array
    {
        $w = ['s.merged_into_sku_id IS NULL'];
        $p = [];
        if ($f['q'] !== '') {
            $barcode = preg_match('/^[0-9]{6,64}$/D', $f['q']) === 1 ? (Gtin::key($f['q']) ?? $f['q']) : null;
            if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $f['q'], $m) === 1) {
                // a bare number of 6 digits or more is a CW number or a barcode (the Items artboard's search, docs/decisions.md SS1);
                // sku_barcode holds the GTIN key (no leading zeros, as a scanner may add them), as Queries::searchSkus reads it
                $w[] = $barcode === null ? 's.id = ?' : '(s.id = ? OR s.id IN (SELECT b.sku_id FROM sku_barcode b WHERE b.barcode = ?))';
                array_push($p, (int) $m[1], ...($barcode === null ? [] : [$barcode]));
            } elseif ($barcode !== null) {
                $w[] = 's.id IN (SELECT b.sku_id FROM sku_barcode b WHERE b.barcode = ?)';
                $p[] = $barcode;
            } else {
                foreach (array_slice(preg_split('/\s+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $word) {
                    $w[] = "CONCAT_WS(' ', s.name, s.brand, s.code, c.brand, c.flavour) LIKE ?";
                    $p[] = '%' . addcslashes($word, '\\%_') . '%';
                }
            }
        }
        $w[] = match ($f['state']) {
            'none' => 'c.sku_id IS NULL',
            'unconfirmed' => 'c.confirmed_at IS NULL',
            'changed' => 'c.confirmed_at IS NULL AND c.first_confirmed_at IS NOT NULL',
            'confirmed' => 'c.confirmed_at IS NOT NULL',
            default => '1 = 1',
        };
        if ($f['stock']) {
            $w[] = 'COALESCE(st.held, 0) > 0';
        }
        if ($f['warnings']) {
            $w[] = ItemRules::sqlFlagged('c');
        }
        if ($f['blocked']) {
            // The blocked items, most stock first: what someone takes off sale on the website by hand until IM10 does it (I121).
            $w[] = ItemRules::sqlBlocked('c');
        }
        if ($f['type'] === 'none') {
            $w[] = 'c.product_type IS NULL';
        } elseif ($f['type'] !== '') {
            $w[] = 'c.product_type = ?';
            $p[] = $f['type'];
        }
        if ($f['discontinued']) {
            $w[] = 'c.discontinued = 1';
        }
        return [implode(' AND ', $w), $p];
    }
}
