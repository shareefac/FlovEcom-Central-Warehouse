<?php

declare(strict_types=1);

namespace CW\Expenses;

/**
 * Money as the expense and regular cost forms take it and the pages show it (docs/decisions.md EX4): typed in pounds and pence ("64.20",
 * "£1,234.56", "12.5", "200"), kept in whole pence (BIGINT), never a float. At most two decimals, never below 0, at most £9,999,999.99 in a
 * box; an expense's total with VAT at most two boxes' worth (EX65). ONE rule, the same as the page's script (app.js, Codex's final review of
 * 1f18763, L1): spaces, tabs and the no-break spaces (U+00A0, U+202F, U+2007) around and after the £ are nothing; any other character
 * (a line break, a control character, a zero-width space) makes it not an amount.
 */
final class ExpenseMoney
{
    /** The largest amount a form takes (pence): £9,999,999.99. */
    public const MAX_PENCE = 999_999_999;
    /** The largest total with VAT (EX65): two boxes at their largest (a £9,000,000 + £1,800,000 expense's total is £10,800,000). */
    public const MAX_TOTAL_PENCE = 2 * self::MAX_PENCE;
    /** What counts as nothing around an amount, the script's [ \t\u00A0\u202F\u2007]. */
    private const SPACES = [" ", "\t", "\u{00A0}", "\u{202F}", "\u{2007}"];

    /** Pounds and pence typed => pence; null when it is not an amount (empty, negative, three decimals, a word, larger than $max). */
    public static function pence(string $typed, int $max = self::MAX_PENCE): ?int
    {
        $s = trim(str_replace(self::SPACES, ' ', $typed), ' ');
        if (str_starts_with($s, '£')) {
            $s = ltrim(substr($s, strlen('£')), ' ');
        }
        if (preg_match('/^(\d{1,3}(?:,\d{3}){1,2}|\d{1,8})(?:\.(\d{1,2}))?$/D', $s, $m) !== 1) {
            return null;
        }
        $p = (int) str_replace(',', '', $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        return $p > $max ? null : $p;
    }

    /** Whether a box holds nothing (only the spaces the rule above ignores): a total left empty is not asked (EX65). */
    public static function blank(string $typed): bool
    {
        return trim(str_replace(self::SPACES, ' ', $typed), ' ') === '';
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
