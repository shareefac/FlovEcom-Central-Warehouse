<?php

declare(strict_types=1);

namespace CW\Profit;

/**
 * The money rules of profit step 1 (docs/decisions.md PR4-PR11, PR13-PR16). Pure: no database, integers only (pence; a unit price is
 * GBP x 10,000 = hundredths of a penny, "e4"). Every rounding is half away from zero.
 *
 *  - ex VAT = inc VAT / (1 + rate); the rate is kept on each order in basis points (2000 = 20%).
 *  - a line's sale = price - discount - refund (coupons and points are in the discount: a price cut). A cancelled line counts nothing
 *    (no units, no sale, no cost). A free line counts its units and its cost with sale 0. A unit that came back to stock in CW (a
 *    return, restocked) has no cost; refunded, its sale is what is left after its refund, never below 0 (PR145).
 *  - a line's cost = its CW units x the unit price fixed at the sale.
 *  - margin = profit / sale ex VAT.
 *  - the buying price of an item is a moving average over the units on hand: a delivery moves it with its unit price; an invoice
 *    corrects the units it bills that are still on hand from the price they came in at to the invoice's landed price; a price credit
 *    lowers the units still on hand; a delivery taken back takes its units out at the price they came in at.
 */
final class ProfitMath
{
    /** The largest amount of pence a snapshot may carry in one field (GBP 100 million). */
    public const MAX_PENCE = 10_000_000_000;

    /** a / b rounded half away from zero (b > 0). */
    public static function divRound(int $a, int $b): int
    {
        if ($b <= 0) {
            throw new \InvalidArgumentException('divide by a positive number');
        }
        $q = intdiv(abs($a) * 2 + $b, 2 * $b);
        return $a < 0 ? -$q : $q;
    }

    /**
     * a x b / c rounded half away from zero (c > 0), exact whatever the size of a x b (bcmath: two amounts of pence can pass PHP's integers;
     * the Codex check of c48683e).
     */
    public static function mulDivRound(int $a, int $b, int $c): int
    {
        if ($c <= 0) {
            throw new \InvalidArgumentException('divide by a positive number');
        }
        $p = bcmul((string) $a, (string) $b, 0);
        $neg = str_starts_with($p, '-');
        $abs = ltrim($p, '-');
        // (2|p| + c) div 2c, as divRound
        $q = bcdiv(bcadd(bcmul($abs, '2', 0), (string) $c, 0), bcmul((string) $c, '2', 0), 0);
        return (int) ($neg && $q !== '0' ? '-' . $q : $q);
    }

    /** "20", "17.5" or "5.0000" (percent) => basis points (2000, 1750, 500); null when it is not a percent from 0 to 100 with at most two decimals. */
    public static function vatBp(string $percent): ?int
    {
        // Trailing zeros after two decimals are the same percent ("5.0000" as the settings screen may keep it: the final review of 6d59eda,
        // c-M5, PR109).
        if (preg_match('/^(0|[1-9][0-9]?|100)(?:\.([0-9]{1,2})0*)?$/D', trim($percent), $m) !== 1) {
            return null;
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        return $bp <= 10000 ? $bp : null;
    }

    /** Pence inc VAT => pence ex VAT at $vatBp. */
    public static function exVat(int $inc, int $vatBp): int
    {
        return self::divRound($inc * 10000, 10000 + $vatBp);
    }

    /** Pence of $units CW units at $unitE4 (GBP x 10,000 per unit). */
    public static function cost(int $units, int $unitE4): int
    {
        return self::divRound($units * $unitE4, 100);
    }

    /**
     * What one shop line counts for.
     *
     * @param array{qty: int, price_inc: int, discount_inc: int, refund_inc: int, cancelled: bool, free: bool} $l
     * @return array{counts: bool, units: int, sale_inc: int, sale_ex: int}
     */
    public static function line(array $l, int $unitsPerItem, bool $restocked, int $vatBp): array
    {
        if ($l['cancelled']) {
            return ['counts' => false, 'units' => 0, 'sale_inc' => 0, 'sale_ex' => 0];
        }
        $units = $l['qty'] * max(1, $unitsPerItem);
        if ($l['free']) {
            $inc = 0;
        } else {
            $inc = $l['price_inc'] - $l['discount_inc'] - $l['refund_inc'];
            // A unit back in stock and refunded keeps what is left after its own refund (a £12 sale refunded £6 on the line keeps £6), never
            // below 0: an order-level refund then comes off once (the second final review of 3c31d94, c-M1, PR145; it zeroed the line and the
            // order refund came off again: -£5 on a £12 sale refunded in full).
            if ($restocked && $l['refund_inc'] > 0) {
                $inc = max(0, $inc);
            }
        }
        return ['counts' => true, 'units' => $units, 'sale_inc' => $inc, 'sale_ex' => self::exVat($inc, $vatBp)];
    }

    /** The cost of a line that counts: 0 for a unit back in stock or with no price; else units x price. */
    public static function lineCost(bool $counts, int $units, ?int $unitE4, bool $restocked): int
    {
        return !$counts || $restocked || $unitE4 === null ? 0 : self::cost($units, $unitE4);
    }

    /** profit / sale in basis points (2500 = 25.00%), or null when there is no sale to measure against. */
    public static function marginBp(int $profit, int $sale): ?int
    {
        return $sale <= 0 ? null : self::divRound($profit * 10000, $sale);
    }

    // ---- the buying price (moving average per item) -------------------------------------------------------------------------------------

    /**
     * $units come in at $priceE4 while $onHand units are on hand at $costE4. Nothing on hand (or below zero), or no price yet: the
     * delivery's price.
     */
    public static function receive(?int $costE4, int $onHand, int $units, int $priceE4): int
    {
        if ($costE4 === null || $onHand <= 0 || $units <= 0) {
            return $units <= 0 && $costE4 !== null ? $costE4 : $priceE4;
        }
        return self::divRound($costE4 * $onHand + $priceE4 * $units, $onHand + $units);
    }

    /**
     * $units billed (or credited) move from $fromE4 to $toE4: the ones still on hand ($onHand, at most $units of them) carry the
     * difference into the average. Nothing on hand: unchanged (the units were sold at the cost they had). Never below 0.
     */
    public static function correct(?int $costE4, int $onHand, int $units, int $fromE4, int $toE4): ?int
    {
        if ($onHand <= 0 || $units <= 0 || $fromE4 === $toE4) {
            return $costE4;
        }
        if ($costE4 === null) {
            return max(0, $toE4);
        }
        $n = min($units, $onHand);
        return max(0, self::divRound($costE4 * $onHand + ($toE4 - $fromE4) * $n, $onHand));
    }

    /**
     * $units leave at the price they came in at ($priceE4: a delivery taken back) while $onHand units (them included) are on hand.
     * When none would be left, or the value left would be below zero: unchanged.
     */
    public static function remove(?int $costE4, int $onHand, int $units, int $priceE4): ?int
    {
        if ($costE4 === null || $units <= 0) {
            return $costE4;
        }
        $left = $onHand - $units;
        $value = $costE4 * $onHand - $priceE4 * $units;
        if ($left <= 0 || $value < 0) {
            return $costE4;
        }
        return self::divRound($value, $left);
    }

    /**
     * A relative move: $units of the $onHand units on hand change by $deltaE4 each (an invoice's landed price less the price they came in
     * at, a credit, the undoing of either). Nothing on hand, no units or no change: unchanged. An unknown price stays unknown (a credit on a
     * price nobody knows is still not a price: the Codex review of 92efa82, a4). Never below 0.
     */
    public static function shift(?int $costE4, int $onHand, int $units, int $deltaE4): ?int
    {
        if ($costE4 === null || $onHand <= 0 || $units <= 0 || $deltaE4 === 0) {
            return $costE4;
        }
        return max(0, self::divRound($costE4 * $onHand + $deltaE4 * min($units, $onHand), $onHand));
    }

    /**
     * One event of an item's price history applied to the price before it: THE rule the cost job applies and a replay folds with (the same
     * function both ways, so a history read in order gives the price the job wrote).
     *
     * @param array{kind: string, units: int, on_hand: ?int, price_e4: ?int, booked_e4: ?int, new_cost_e4?: ?int, deferred?: int|bool} $e
     */
    public static function step(?int $costE4, array $e): ?int
    {
        $units = (int) $e['units'];
        $onHand = (int) ($e['on_hand'] ?? 0);
        $price = $e['price_e4'] === null ? null : (int) $e['price_e4'];
        $booked = $e['booked_e4'] === null ? null : (int) $e['booked_e4'];
        return match ((string) $e['kind']) {
            'start' => array_key_exists('new_cost_e4', $e) && $e['new_cost_e4'] !== null ? (int) $e['new_cost_e4'] : null,
            'receipt' => $price === null ? $costE4 : self::receive($costE4, $onHand, $units, $price),
            'receipt_reversal' => $price === null ? $costE4 : self::remove($costE4, $onHand, $units, $price),
            // An invoice sets an absolute price: on a price nobody knew, its landed price; else the move of its units from booked to landed.
            'invoice' => $booked === null || $price === null || $units <= 0 || $onHand <= 0 ? $costE4
                : ($costE4 === null ? $price : self::shift($costE4, $onHand, $units, $price - $booked)),
            'credit_note' => ((int) ($e['deferred'] ?? 0)) === 1 || $booked === null ? $costE4 : self::shift($costE4, $onHand, $units, (int) $price - $booked),
            'invoice_reversal', 'credit_note_reversal' => $booked === null || $price === null ? $costE4 : self::shift($costE4, $onHand, $units, $price - $booked),
            default => $costE4, // replay: not a source
        };
    }

    /**
     * An item's history folded in its sources' order (start first): the price it should have.
     *
     * @param list<array<string, mixed>> $events ordered by source_at, id
     */
    public static function fold(array $events): ?int
    {
        // The start is the item's opening price: it comes first whatever its time (an item made after the start of the buying prices starts
        // at a run's time, and a delivery of before that time whose booking committed after the run comes later; it is folded on top of the
        // start, never under it: the Codex re-check of e5d2e5a, F1).
        usort($events, static fn (array $a, array $b): int => (int) ((string) $b['kind'] === 'start') <=> (int) ((string) $a['kind'] === 'start'));
        $cost = null;
        $moved = [];
        foreach ($events as $e) {
            // A cancellation undoes what its event moved in THIS fold (so a late event folded in since changes the undo too: the Codex
            // re-check of 37a2cf0, N2): the event's value change over the units it reached, a unit at a time.
            $undoes = isset($e['undoes_event_id']) ? (int) $e['undoes_event_id'] : 0;
            if ($undoes > 0 && isset($moved[$undoes]) && in_array((string) $e['kind'], ['invoice_reversal', 'credit_note_reversal'], true)) {
                [$delta, $onHand, $units] = $moved[$undoes];
                $e = ['price_e4' => $units > 0 ? -self::divRound($delta * $onHand, $units) : 0, 'booked_e4' => 0] + $e;
            }
            $before = $cost;
            $cost = self::step($cost, $e);
            if (isset($e['id'])) {
                $moved[(int) $e['id']] = [$before === null || $cost === null ? 0 : $cost - $before, (int) ($e['on_hand'] ?? 0), (int) $e['units']];
            }
        }
        return $cost;
    }

    /**
     * The stock operation a ledger row belongs to (one transaction): its document, else its Idempotency-Key within the caller's scope (the
     * store, or the staff member / job: a key is unique only there, so two stores' "batch:1" are two operations; the Codex re-check of
     * 6b8a7ee, F4); null when it has neither (a row by itself).
     */
    public static function operation(?int $documentId, ?string $idemKey, ?int $channelId, string $actor): ?string
    {
        if ($documentId !== null) {
            return 'd' . $documentId;
        }
        return $idemKey === null || $idemKey === '' ? null : 'k' . ($channelId ?? 0) . '|' . $actor . '|' . $idemKey;
    }

    /**
     * The fewest units on hand from $level on, through $rows (one item's on-hand rows in our warehouses) taken in the item's COMMIT order
     * (`seq`, stock_value_seq: ledger ids are not in commit order across warehouses) and looked at only between whole operations (the rows
     * of one operation are one transaction: a transfer between two of our warehouses is never a sell-out; the Codex re-checks of 37a2cf0 R3
     * and 6b8a7ee R3). Never below 0.
     *
     * @param list<array{id: int, seq: ?int, qty: int, op: ?string}> $rows
     */
    public static function lowestLevel(int $level, array $rows): int
    {
        usort($rows, static fn (array $a, array $b): int => [$a['seq'] ?? PHP_INT_MAX, $a['id']] <=> [$b['seq'] ?? PHP_INT_MAX, $b['id']]);
        $low = $level;
        foreach ($rows as $i => $r) {
            $level += (int) $r['qty'];
            $next = $rows[$i + 1] ?? null;
            if ($r['op'] === null || $next === null || $next['op'] !== $r['op']) {
                $low = min($low, $level); // the end of an operation
            }
        }
        return max(0, $low);
    }

    /**
     * The price of the units a receipt line brought of a final invoice (pack J), GBP x 10,000: the landed value of the units each invoice line
     * gave it (`take` of its `units` at its landed price) less the part of each credit given before they came that is on those units (a
     * credit's value x units taken / the line's units, each rounded), plus the units beyond the invoice at the receipt's own price, over every
     * unit that came (the Codex re-checks of 6b8a7ee F2/F3 and e5d2e5a: over-delivery). Never below 0; null when nothing came.
     *
     * @param array<int, array{take: int, units: int, landed: int, credits: array<int|string, int>}> $lines invoice line => what it gave; credits: each
     *        credit's value (what it takes off a credited unit x the units it credits)
     */
    public static function receiptPrice(array $lines, int $came, int $own): ?int
    {
        $value = 0;
        $taken = 0;
        foreach ($lines as $l) {
            $value += $l['landed'] * $l['take'];
            $taken += $l['take'];
            foreach ($l['credits'] as $c) {
                $value -= self::divRound($c * $l['take'], max(1, $l['units']));
            }
        }
        $beyond = max(0, $came - $taken);
        $all = $taken + $beyond;
        return $all <= 0 ? null : max(0, self::divRound($value + $own * $beyond, $all));
    }

    /** How many units a correction reaches: the units billed that are still on hand. */
    public static function reach(int $units, int $onHand): int
    {
        return max(0, min($units, $onHand));
    }

    /** GBP as a decimal string ("1.05", "0.2200", "12") => GBP x 10,000, rounded half up; null when it is not an amount. */
    public static function e4(string $gbp): ?int
    {
        $s = trim($gbp);
        if (preg_match('/^(-?)([0-9]{1,12})(?:\.([0-9]{1,12}))?$/D', $s, $m) !== 1) {
            return null;
        }
        $frac = str_pad($m[3] ?? '', 5, '0');
        $v = (int) $m[2] * 10000 + (int) substr($frac, 0, 4) + ((int) $frac[4] >= 5 ? 1 : 0);
        return $m[1] === '-' ? -$v : $v;
    }

    /**
     * An invoice's charges (carriage, duty, other) over its goods lines: by value; when every goods line is worth nothing (free samples), by
     * units, so the charge always reaches the goods and the shares add up to it (the final review of 6d59eda, c-M4, PR108).
     *
     * @param array<int|string, int> $values
     * @param array<int|string, int> $units
     * @return array<int|string, int>
     */
    public static function chargeShares(int $charges, array $values, array $units): array
    {
        return array_sum($values) > 0 ? self::spread($charges, $values) : self::spread($charges, $units);
    }

    /** Spreads $total over $weights (non-negative) in proportion; the pennies left over go to the largest remainders. */
    public static function spread(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $out = array_fill_keys(array_keys($weights), 0);
        if ($sum <= 0 || $total === 0) {
            return $out;
        }
        $sign = $total < 0 ? -1 : 1;
        $abs = abs($total);
        $rem = [];
        $given = 0;
        foreach ($weights as $k => $w) {
            // Exact quotient and remainder (bcmath): £40 million of goods and £40 million of charges pass PHP's integers as pence x pence (the
            // second final review of 3c31d94, c-L3, PR146; intdiv() was given a float and the cost job stopped on that invoice every run).
            $p = bcmul((string) $abs, (string) $w, 0);
            $share = (int) bcdiv($p, (string) $sum, 0);
            $out[$k] = $share;
            $rem[$k] = (int) bcmod($p, (string) $sum, 0);
            $given += $share;
        }
        arsort($rem);
        foreach (array_keys($rem) as $k) {
            if ($given >= $abs) {
                break;
            }
            $out[$k]++;
            $given++;
        }
        return array_map(static fn (int $v): int => $sign * $v, $out);
    }
}
