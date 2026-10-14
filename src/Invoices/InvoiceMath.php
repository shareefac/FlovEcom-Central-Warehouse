<?php

declare(strict_types=1);

namespace CW\Invoices;

use CW\PurchaseOrders\PoMath;

/**
 * The arithmetic of a supplier invoice and its 3-way match (pack C, plan IM7; docs/decisions.md PC2-PC5): pure, integers only (pence
 * e2, unit prices e6, percentages e6), no database.
 *
 *  - rules(): the match's tolerances from the settings as typed (decimal strings): the price may differ by `price_pct` per cent OR by
 *    `price_gbp` pounds on the line (either is enough; 0 switches that test off; both 0: exact), the quantity may be `qty_pct` per cent
 *    more than what is there to invoice (0: exact), and the lines may add up to the stated total within `total_per_line` pounds a line.
 *  - line(): one goods line against what was ordered and received:
 *        units, amount      what the line bills, less what the supplier's credit notes took off it;
 *        available          what the delivery line still has to be invoiced: accepted into stock - returned to the supplier - invoiced
 *                           on the supplier's other invoices (null: no delivery linked: nothing can be matched);
 *        ref_unit_e6        the reference unit price: the order line's, else the delivery's provisional cost (null: none);
 *    gives `excess` (units billed beyond what is there), `price_e2` (the line's amount less the same units at the reference price), the
 *    line's state (matched, within, off), why, and `diff_e2` (what it charges above the units there at the reference price: the money a
 *    person looks at).
 *  - state(): the invoice's state from its lines and the total's rounding: needs_review when a line is off, within_tolerance when a
 *    line is within or the total differs by rounding, else matched.
 *  - spread(): an amount shared over weights to the penny (largest remainder: the shares add up exactly).
 */
final class InvoiceMath
{
    public const STATES = ['matched', 'within_tolerance', 'needs_review', 'accepted'];
    public const LINE_STATES = ['matched', 'within', 'off'];
    /** Why a line is not matched (Ui\Words::MATCH_WHY). */
    public const WHY = ['no_delivery', 'delivery_reversed', 'qty_over', 'price_over', 'price_under'];

    /**
     * @param array{price_pct: string, price_gbp: string, qty_pct: string, total_per_line: string} $typed the settings' decimal strings
     * @return array{price_pct_e6: int, price_gbp_e2: int, qty_pct_e6: int, total_per_line_e2: int}
     */
    public static function rules(array $typed): array
    {
        return [
            'price_pct_e6' => PoMath::e6(self::plain($typed['price_pct'])),
            'price_gbp_e2' => self::e2Round(self::plain($typed['price_gbp'])),
            'qty_pct_e6' => PoMath::e6(self::plain($typed['qty_pct'])),
            'total_per_line_e2' => self::e2Round(self::plain($typed['total_per_line'])),
        ];
    }

    /** Whether the lines' sum is within the stated total's tolerance (|sum - stated| <= per line x lines). */
    public static function totalOk(int $sumE2, int $statedE2, int $lines, int $perLineE2): bool
    {
        return abs($sumE2 - $statedE2) <= $perLineE2 * max(1, $lines);
    }

    /**
     * @param array{units: int, amount_e2: int, inv_unit_e6: int, available: ?int, ref_unit_e6: ?int, reversed?: bool} $l
     * @param array{price_pct_e6: int, price_gbp_e2: int, qty_pct_e6: int, total_per_line_e2: int} $rules
     * @return array{state: string, excess: int, allowed: int, price_e2: int, expected_e2: int, diff_e2: int, why: list<string>}
     */
    public static function line(array $l, array $rules): array
    {
        $units = $l['units'];
        $why = [];
        $off = false;
        $within = false;
        // Quantity: what is billed beyond what the delivery line still has to be invoiced.
        if ($l['available'] === null) {
            $excess = $units;
            $allowed = 0;
            $why[] = ($l['reversed'] ?? false) ? 'delivery_reversed' : 'no_delivery';
            $off = $units > 0;
        } else {
            $excess = $units - $l['available'];
            $allowed = $l['available'] > 0 ? intdiv($l['available'] * $rules['qty_pct_e6'], 100_000_000) : 0;
            if ($excess > 0) {
                $why[] = ($l['reversed'] ?? false) ? 'delivery_reversed' : 'qty_over';
                if ($excess > $allowed) {
                    $off = true;
                } else {
                    $within = true;
                }
            }
        }
        // Price: the line's amount against the same units at the reference price.
        $ref = $l['ref_unit_e6'];
        $expected = $ref === null ? $l['amount_e2'] : self::unitsAt($units, $ref);
        $price = $l['amount_e2'] - $expected;
        if ($price !== 0) {
            $why[] = $price > 0 ? 'price_over' : 'price_under';
            if (self::priceOk($price, $expected, $rules)) {
                $within = true;
            } else {
                $off = true;
            }
        }
        // The money to look at: the amount less the units that are there at the reference price (or the line's own).
        $there = max(0, min($units, $l['available'] ?? 0));
        $diff = $l['amount_e2'] - self::unitsAt($there, $ref ?? ($units > 0 ? intdiv($l['amount_e2'] * 10_000 + intdiv($units, 2), $units) : 0));
        if ($there === $units) {
            $diff = $price; // every unit is there: the difference is the price alone (no rounding of a derived unit price)
        }
        return ['state' => $off ? 'off' : ($within ? 'within' : 'matched'), 'excess' => $excess, 'allowed' => $allowed, 'price_e2' => $price,
            'expected_e2' => $expected, 'diff_e2' => $diff, 'why' => $why];
    }

    /**
     * The invoice's state: needs_review when a line is off; within_tolerance when a line is within or the lines differ from the stated
     * total by rounding; else matched.
     *
     * @param list<string> $lineStates
     */
    public static function state(array $lineStates, int $totalDiffE2): string
    {
        if (in_array('off', $lineStates, true)) {
            return 'needs_review';
        }
        return in_array('within', $lineStates, true) || $totalDiffE2 !== 0 ? 'within_tolerance' : 'matched';
    }

    /** Whether a price difference is within the tolerance: at most price_gbp on the line, or at most price_pct of the expected amount. */
    public static function priceOk(int $priceE2, int $expectedE2, array $rules): bool
    {
        $abs = abs($priceE2);
        if ($abs === 0) {
            return true;
        }
        if ($rules['price_gbp_e2'] > 0 && $abs <= $rules['price_gbp_e2']) {
            return true;
        }
        // |price| <= expected x pct / 100: |price| x 100 x 10^6 <= expected x pct_e6
        return $rules['price_pct_e6'] > 0 && $expectedE2 > 0 && $abs * 100_000_000 <= $expectedE2 * $rules['price_pct_e6'];
    }

    /** half-up(units x unit price e6, 2) as e2 (a negative product rounds away from zero). */
    public static function unitsAt(int $units, int $unitE6): int
    {
        $p = $units * $unitE6;
        return $p >= 0 ? intdiv($p + 5_000, 10_000) : -intdiv(-$p + 5_000, 10_000);
    }

    /**
     * $totalE2 shared over $weights (any non-negative integers) to the penny: each share is the rounded-down part, and the pennies left
     * go to the largest remainders (then the earliest key). All weights 0: shared equally by count. The shares add up to $totalE2
     * exactly; a negative total is shared as its absolute value and negated.
     *
     * @param array<int|string, int> $weights
     * @return array<int|string, int>
     */
    public static function spread(int $totalE2, array $weights): array
    {
        if ($weights === []) {
            return [];
        }
        if ($totalE2 < 0) {
            return array_map(static fn (int $v): int => -$v, self::spread(-$totalE2, $weights));
        }
        $sum = array_sum($weights);
        if ($sum <= 0) {
            $weights = array_map(static fn (): int => 1, $weights);
            $sum = count($weights);
        }
        $out = [];
        $rem = [];
        $given = 0;
        foreach ($weights as $k => $w) {
            $out[$k] = intdiv($totalE2 * $w, $sum);
            $rem[$k] = ($totalE2 * $w) % $sum;
            $given += $out[$k];
        }
        $left = $totalE2 - $given;
        $keys = array_keys($weights);
        usort($keys, static fn (int|string $a, int|string $b): int => [$rem[$b], array_search($a, array_keys($weights), true)]
            <=> [$rem[$a], array_search($b, array_keys($weights), true)]);
        foreach ($keys as $k) {
            if ($left <= 0) {
                break;
            }
            $out[$k]++;
            $left--;
        }
        return $out;
    }

    /** An amount in GBP as e2 (any sign, at most 2 decimals; more decimals must be zeros). */
    public static function e2(string $v): int
    {
        $v = trim($v);
        $neg = str_starts_with($v, '-');
        $e2 = PoMath::e2($neg ? substr($v, 1) : $v);
        return $neg ? -$e2 : $e2;
    }

    /** An e2 amount as a 2-decimal string ("-12.50"). */
    public static function fromE2(int $e2): string
    {
        return ($e2 < 0 ? '-' : '') . PoMath::fromE2(abs($e2));
    }

    /** A unit price as e6 (a 6-decimal string from the database). */
    public static function e6(string $v): int
    {
        return PoMath::e6($v);
    }

    /** A setting's decimal string as e2, rounded half-up to the penny (0.005 -> 0.01). */
    private static function e2Round(string $v): int
    {
        return intdiv(PoMath::e6($v) + 5_000, 10_000);
    }

    private static function plain(string $v): string
    {
        $v = trim($v);
        return $v === '' ? '0' : $v;
    }
}
