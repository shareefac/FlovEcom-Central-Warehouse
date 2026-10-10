<?php

declare(strict_types=1);

namespace CW\StockOps;

use CW\Db;

/**
 * What a product is worth when a stock record has no price of its own (pack A1; docs/decisions.md SO6). IM8 (valuation) is not built
 * yet, so these are estimates the screens label as such, and never booked:
 *
 *  - average(): the average cost so far: the weighted average of every unit that came in WITH a cost (a delivery, a stock in or a
 *    release at a price, a count or an adjustment that carried one), found through the item's value sequence (stock_value_seq, one
 *    row per on-hand change, keyed by the item), never by scanning the ledger;
 *  - supplierPrice(): the last supplier price of one unit: the preferred supplier item's last pack price divided by its pack, else the
 *    most recently priced active supplier item of the product;
 *  - suggested(): what a release from another account's warehouse is priced at by default (the owner's rule: "the last supplier price
 *    or average cost, editable"): the supplier price, else the average.
 *
 * Prices are GBP per central unit as 6-decimal strings (Movements::normaliseCost's form).
 */
final class CostHints
{
    public const CHUNK = 500;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param list<int> $skuIds
     * @return array<int, string> sku id => average cost so far (absent: no unit came in with a cost)
     */
    public function average(array $skuIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($skuIds)), self::CHUNK) as $chunk) {
            foreach ($this->db->all(
                'SELECT s.sku_id, SUM(l.qty_delta * l.unit_cost) AS v, SUM(l.qty_delta) AS q FROM stock_value_seq s '
                . 'JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE s.sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') '
                . 'AND l.unit_cost IS NOT NULL AND l.qty_delta > 0 GROUP BY s.sku_id',
                $chunk,
            ) as $r) {
                if ((int) $r['q'] > 0) {
                    $out[(int) $r['sku_id']] = self::six(bcdiv((string) $r['v'], (string) $r['q'], 7));
                }
            }
        }
        return $out;
    }

    /**
     * @param list<int> $skuIds
     * @return array<int, string> sku id => the last supplier price of one unit (absent: no priced supplier item)
     */
    public function supplierPrice(array $skuIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($skuIds)), self::CHUNK) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->all("SELECT preferred_sku_id AS sku_id, last_pack_price, units_per_pack FROM supplier_item WHERE preferred_sku_id IN ({$in}) "
                . 'AND last_pack_price IS NOT NULL', $chunk) as $r) {
                $out[(int) $r['sku_id']] = self::six(bcdiv((string) $r['last_pack_price'], (string) max(1, (int) $r['units_per_pack']), 7));
            }
            $rest = array_values(array_diff($chunk, array_keys($out)));
            if ($rest === []) {
                continue;
            }
            $in = implode(', ', array_fill(0, count($rest), '?'));
            foreach ($this->db->all("SELECT sku_id, last_pack_price, units_per_pack FROM supplier_item WHERE sku_id IN ({$in}) AND is_active = 1 "
                . 'AND last_pack_price IS NOT NULL ORDER BY last_price_on DESC, id DESC', $rest) as $r) {
                $out[(int) $r['sku_id']] ??= self::six(bcdiv((string) $r['last_pack_price'], (string) max(1, (int) $r['units_per_pack']), 7));
            }
        }
        return $out;
    }

    /**
     * The default price of a release line: the supplier price, else the average cost so far.
     *
     * @param list<int> $skuIds
     * @return array<int, array{price: string, source: string}> sku id => price and where it came from (`supplier` | `average`)
     */
    public function suggested(array $skuIds): array
    {
        $out = [];
        foreach ($this->supplierPrice($skuIds) as $sku => $p) {
            $out[$sku] = ['price' => $p, 'source' => 'supplier'];
        }
        $rest = array_values(array_diff($skuIds, array_keys($out)));
        foreach ($rest === [] ? [] : $this->average($rest) as $sku => $p) {
            $out[$sku] = ['price' => $p, 'source' => 'average'];
        }
        return $out;
    }

    /** A value rounded half up to 6 decimals (a non-negative decimal string). */
    public static function six(string $v): string
    {
        return bcadd($v, '0.0000005', 6);
    }

    /** Pounds and pence of qty x unit price, rounded half up (a decimal string with 2 decimals; qty may be negative). */
    public static function amount(int $qty, string $unit): string
    {
        $raw = bcmul((string) abs($qty), $unit, 7);
        $pennies = bcadd($raw, '0.005', 2);
        return $qty < 0 && bccomp($pennies, '0', 2) !== 0 ? '-' . $pennies : $pennies;
    }

    /** Whole pounds, rounded up, of a non-negative decimal amount (the size of a record, Documents\SizeApproval). */
    public static function wholePounds(string $amount): int
    {
        $whole = bcadd($amount, '0', 0);
        return (int) $whole + (bccomp($amount, $whole, 6) > 0 ? 1 : 0);
    }
}
