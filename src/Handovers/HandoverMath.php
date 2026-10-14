<?php

declare(strict_types=1);

namespace CW\Handovers;

use CW\StockOps\CostHints;

/**
 * The sums of a hand-over invoice (pack H; docs/decisions.md PH5), pure: no database. Pounds only (Q13).
 *
 *   each line   units x price per unit, to the penny, half up (CostHints::amount, the release's rule, SO8)
 *   net         the sum of the lines
 *   VAT         net x rate / 100, to the penny, half up, once on the invoice's net (not per line)
 *   gross       net + VAT: what the customer owes for it (counterparty_entry)
 *
 * A reversal's sums are the original's negated (never computed again from the negated lines: the same pennies come off).
 */
final class HandoverMath
{
    /**
     * The net of a hand-over's lines: those with an item, units and a price (a draft may still miss a price: it adds nothing).
     *
     * @param list<array<string, mixed>> $lines document_line rows (qty, unit_cost)
     */
    public static function net(array $lines): string
    {
        $sum = '0.00';
        foreach ($lines as $l) {
            if (($l['sku_id'] ?? null) !== null && ($l['qty'] ?? null) !== null && ($l['unit_cost'] ?? null) !== null) {
                $sum = bcadd($sum, CostHints::amount((int) $l['qty'], (string) $l['unit_cost']), 2);
            }
        }
        return $sum;
    }

    /** VAT on $net at $ratePercent ("20.00"; null = no VAT): to the penny, half up, away from zero for a negative net. */
    public static function vat(string $net, ?string $ratePercent): string
    {
        if ($ratePercent === null || bccomp($ratePercent, '0', 2) === 0) {
            return '0.00';
        }
        $raw = bcdiv(bcmul(ltrim($net, '-'), $ratePercent, 8), '100', 8);
        $pennies = bcadd($raw, '0.005', 2);
        return str_starts_with($net, '-') && bccomp($pennies, '0', 2) !== 0 ? '-' . $pennies : $pennies;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{net: string, vat: string, gross: string}
     */
    public static function totals(array $lines, ?string $ratePercent): array
    {
        $net = self::net($lines);
        $vat = self::vat($net, $ratePercent);
        return ['net' => $net, 'vat' => $vat, 'gross' => bcadd($net, $vat, 2)];
    }

    /** A decimal as pounds with 2 decimals ("-0.00" as "0.00"). */
    public static function money(string $v): string
    {
        $s = bcadd($v, '0', 2);
        return $s === '-0.00' ? '0.00' : $s;
    }

    /** -$v to 2 decimals ("0.00" stays "0.00"). */
    public static function negate(string $v): string
    {
        return self::money(bcsub('0', $v, 2));
    }
}
