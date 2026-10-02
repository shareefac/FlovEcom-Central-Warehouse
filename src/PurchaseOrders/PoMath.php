<?php

declare(strict_types=1);

namespace CW\PurchaseOrders;

/**
 * The money of a purchase order, with integers only (spec §0.6; docs/decisions.md I48-I59): no float ever touches a price.
 *
 *  - a pack price is GBP excl. VAT per purchase unit with at most 4 decimals (DECIMAL(14,4)): held as e4, an integer of
 *    1/10,000 GBP;
 *  - a line's net amount = half-up(packs × pack price, 2): pence (e2);
 *  - a unit cost = half-up(pack price / units per pack, 6): micro-GBP (e6), the value core's unit (I1);
 *  - a line's VAT = half-up(amount × rate / 100, 2), the rate a DECIMAL(5,2) percentage (e2);
 *  - net = Σ amounts, VAT = Σ line VAT (per line, not on the total: what the PDF lists per code adds up exactly),
 *    gross = net + VAT;
 *  - the approval units of the over_value rule = ceil(net) in whole GBP (decision 11: approval above £10,000).
 *
 * Every amount here is non-negative (a PO's lines are); the bounds (MAX_*) keep each product far inside a 64-bit int.
 */
final class PoMath
{
    /** The largest pack price a line takes (GBP): 10 million per pack. */
    public const MAX_PACK_PRICE_E4 = 99_999_999_999;
    /** The largest net amount of one line (GBP 99,999,999.99). */
    public const MAX_LINE_E2 = 9_999_999_999;
    public const MAX_PACKS = 1_000_000;
    public const MAX_UNITS_PER_PACK = 100_000;

    /** A non-negative decimal string with at most 4 decimals ('12.5', '12.3456') as e4. */
    public static function e4(string $v): int
    {
        return self::scaled($v, 4);
    }

    /** A non-negative decimal string with at most 2 decimals as e2 (pence); more decimals must be zeros ('12.340000'). */
    public static function e2(string $v): int
    {
        return self::scaled($v, 2);
    }

    /** A non-negative decimal string with at most 6 decimals as e6. */
    public static function e6(string $v): int
    {
        return self::scaled($v, 6);
    }

    public static function fromE2(int $e2): string
    {
        return self::format($e2, 2);
    }

    public static function fromE4(int $e4): string
    {
        return self::format($e4, 4);
    }

    public static function fromE6(int $e6): string
    {
        return self::format($e6, 6);
    }

    /** The net amount of a line: half-up(packs × pack price, 2), as e2. */
    public static function lineAmountE2(int $packs, int $packPriceE4): int
    {
        self::checkPacks($packs);
        self::checkPrice($packPriceE4);
        $e2 = intdiv($packs * $packPriceE4 + 50, 100);
        if ($e2 > self::MAX_LINE_E2) {
            throw new \RangeException('a line is at most GBP ' . self::fromE2(self::MAX_LINE_E2));
        }
        return $e2;
    }

    /** half-up(packs × pack price, 2) as a 2-decimal string. */
    public static function lineAmount(int $packs, string $packPrice): string
    {
        return self::fromE2(self::lineAmountE2($packs, self::e4($packPrice)));
    }

    /** The unit cost of a pack price: half-up(pack price / units per pack, 6), as e6. */
    public static function unitCostE6(int $packPriceE4, int $unitsPerPack): int
    {
        self::checkPrice($packPriceE4);
        if ($unitsPerPack < 1 || $unitsPerPack > self::MAX_UNITS_PER_PACK) {
            throw new \RangeException('units per pack is 1 to ' . self::MAX_UNITS_PER_PACK);
        }
        return intdiv($packPriceE4 * 200 + $unitsPerPack, 2 * $unitsPerPack); // round(e4 × 100 / upp), half-up
    }

    /** half-up(pack price / units per pack, 6) as a 6-decimal string. */
    public static function unitCost(string $packPrice, int $unitsPerPack): string
    {
        return self::fromE6(self::unitCostE6(self::e4($packPrice), $unitsPerPack));
    }

    /** The VAT of a line: half-up(amount × rate / 100, 2), amount and rate as e2; the result e2. */
    public static function lineVatE2(int $amountE2, int $rateE2): int
    {
        if ($amountE2 < 0 || $rateE2 < 0 || $rateE2 > 10_000) {
            throw new \RangeException('a VAT line needs a non-negative amount and a rate of 0 to 100 %');
        }
        return intdiv($amountE2 * $rateE2 + 5_000, 10_000);
    }

    /**
     * The totals of a PO: net = Σ amounts, VAT = Σ line VAT, gross = net + VAT, and the VAT by code (the PDF lists them).
     *
     * @param list<array{amount_e2: int, vat_code: string, rate_e2: int}> $lines
     * @return array{net_e2: int, vat_e2: int, gross_e2: int, by_code: array<string, array{rate_e2: int, net_e2: int, vat_e2: int}>}
     */
    public static function totals(array $lines): array
    {
        $net = 0;
        $vat = 0;
        $by = [];
        foreach ($lines as $l) {
            $v = self::lineVatE2($l['amount_e2'], $l['rate_e2']);
            $net += $l['amount_e2'];
            $vat += $v;
            $key = $l['vat_code'] . '|' . $l['rate_e2'];
            $by[$key] ??= ['code' => $l['vat_code'], 'rate_e2' => $l['rate_e2'], 'net_e2' => 0, 'vat_e2' => 0];
            $by[$key]['net_e2'] += $l['amount_e2'];
            $by[$key]['vat_e2'] += $v;
        }
        $byCode = [];
        foreach ($by as $b) {
            $code = isset($byCode[$b['code']]) ? $b['code'] . ' ' . self::format($b['rate_e2'], 2) . '%' : $b['code'];
            $byCode[$code] = ['rate_e2' => $b['rate_e2'], 'net_e2' => $b['net_e2'], 'vat_e2' => $b['vat_e2']];
        }
        return ['net_e2' => $net, 'vat_e2' => $vat, 'gross_e2' => $net + $vat, 'by_code' => $byCode];
    }

    /** The over_value approval units: the net total rounded UP to whole GBP (£10,000.01 -> 10001). */
    public static function approvalUnits(int $netE2): int
    {
        if ($netE2 < 0) {
            throw new \RangeException('a PO net total is never negative');
        }
        return intdiv($netE2 + 99, 100);
    }

    /** "£1,234.50" of an e2 amount (screens and the PDF; GBP only, ck_purchase_order_currency). */
    public static function money(int $e2): string
    {
        $neg = $e2 < 0;
        $e2 = abs($e2);
        return ($neg ? '-' : '') . '£' . number_format(intdiv($e2, 100)) . '.' . str_pad((string) ($e2 % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A pack price for people: 2 decimals, or up to 4 when it has them ("1.65", "0.1234"). */
    public static function price(string $packPrice): string
    {
        $s = self::fromE4(self::e4($packPrice));
        [$i, $f] = explode('.', $s);
        $f = rtrim($f, '0');
        return $i . '.' . str_pad($f, 2, '0');
    }

    /** A decimal string scaled to an integer of $scale decimals; extra decimals must be zeros. */
    private static function scaled(string $v, int $scale): int
    {
        if (preg_match('/^(\d{1,15})(?:\.(\d+))?$/D', trim($v), $m) !== 1) {
            throw new \InvalidArgumentException("not a non-negative decimal: {$v}");
        }
        $frac = $m[2] ?? '';
        if (strlen($frac) > $scale) {
            if (trim(substr($frac, $scale), '0') !== '') {
                throw new \InvalidArgumentException("more than {$scale} decimals: {$v}");
            }
            $frac = substr($frac, 0, $scale);
        }
        $n = (int) ($m[1] . str_pad($frac, $scale, '0'));
        if ($n < 0) {
            throw new \RangeException("too large: {$v}");
        }
        return $n;
    }

    private static function format(int $n, int $scale): string
    {
        $neg = $n < 0;
        $n = abs($n);
        $p = 10 ** $scale;
        return ($neg ? '-' : '') . intdiv($n, $p) . '.' . str_pad((string) ($n % $p), $scale, '0', STR_PAD_LEFT);
    }

    private static function checkPacks(int $packs): void
    {
        if ($packs < 1 || $packs > self::MAX_PACKS) {
            throw new \RangeException('packs is 1 to ' . self::MAX_PACKS);
        }
    }

    private static function checkPrice(int $e4): void
    {
        if ($e4 < 0 || $e4 > self::MAX_PACK_PRICE_E4) {
            throw new \RangeException('a pack price is 0 to GBP ' . self::fromE4(self::MAX_PACK_PRICE_E4));
        }
    }
}
