<?php

declare(strict_types=1);

namespace CW\Expenses;

/**
 * Money as the expense and regular cost forms take it and the pages show it (docs/decisions.md EX4): typed in pounds and pence ("64.20",
 * "£1,234.56", "12.5", "200"), kept in whole pence (BIGINT), never a float. At most two decimals, never below 0, at most £9,999,999.99.
 */
final class ExpenseMoney
{
    /** The largest amount a form takes (pence): £9,999,999.99. */
    public const MAX_PENCE = 999_999_999;

    /** Pounds and pence typed => pence; null when it is not an amount (empty, negative, three decimals, a word, too large). */
    public static function pence(string $typed): ?int
    {
        $s = str_replace("\u{00A0}", ' ', $typed);
        $s = trim($s, " \t");
        if (str_starts_with($s, '£')) {
            $s = ltrim(substr($s, strlen('£')), ' ');
        }
        if (preg_match('/^(\d{1,3}(?:,\d{3}){1,2}|\d{1,7})(?:\.(\d{1,2}))?$/D', $s, $m) !== 1) {
            return null;
        }
        $p = (int) str_replace(',', '', $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        return $p > self::MAX_PENCE ? null : $p;
    }

    /** Pence as the pages show it: "£1,234.56", "-£12.00". */
    public static function gbp(int $pence): string
    {
        return ($pence < 0 ? '-' : '') . '£' . number_format(intdiv(abs($pence), 100)) . '.' . str_pad((string) (abs($pence) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The amount without VAT of a total that includes VAT at $rateBp (basis points: 2000 = 20%), to the penny, half up, in whole pence:
     * total x 10000 / (10000 + rate) (Amazon £8.47 at 20%: £7.06; its VAT is the rest, £1.41, so the two always add up to the total).
     * The rule the form's script fills the boxes by (app.js, the same sum: docs/decisions.md EX65); on the server it only says which VAT
     * choice a saved expense's figures match. CW never saves it: what is saved is what the boxes say. $total >= 0, $rateBp >= 0.
     */
    public static function netOfTotal(int $total, int $rateBp): int
    {
        return intdiv(2 * $total * 10000 + (10000 + $rateBp), 2 * (10000 + $rateBp));
    }

    /** Pence as a form's box holds it again: "64.20". */
    public static function plain(int $pence): string
    {
        return ($pence < 0 ? '-' : '') . intdiv(abs($pence), 100) . '.' . str_pad((string) (abs($pence) % 100), 2, '0', STR_PAD_LEFT);
    }
}
