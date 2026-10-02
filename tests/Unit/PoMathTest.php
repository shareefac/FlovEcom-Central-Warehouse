<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\PurchaseOrders\PoMath;
use PHPUnit\Framework\TestCase;

/** PO money with integers only (I48-I59): half-up line amounts, 6-decimal unit costs, per-line VAT, totals, ceil approval units. */
final class PoMathTest extends TestCase
{
    public function testLineAmountIsHalfUpToPence(): void
    {
        self::assertSame('39.60', PoMath::lineAmount(24, '1.65'));
        self::assertSame('0.01', PoMath::lineAmount(1, '0.0050'), '0.005 rounds up');
        self::assertSame('0.00', PoMath::lineAmount(1, '0.0049'));
        self::assertSame('1.24', PoMath::lineAmount(3, '0.4125'), '1.2375 -> 1.24');
        self::assertSame('10000.01', PoMath::lineAmount(1, '10000.0100'));
        self::assertSame('2469134.56', PoMath::lineAmount(2, '1234567.2800'));
        self::assertSame(12_345_680, PoMath::lineAmountE2(1_000, 1_234_568), '1,000 x 123.4568 = 123,456.80');
        $this->expectException(\RangeException::class);
        PoMath::lineAmountE2(1_000_000, 99_999_999_999);
    }

    public function testUnitCostIsHalfUpToSixDecimals(): void
    {
        self::assertSame('0.068750', PoMath::unitCost('1.65', 24));
        self::assertSame('0.333333', PoMath::unitCost('1', 3));
        self::assertSame('0.666667', PoMath::unitCost('2', 3));
        self::assertSame('0.000001', PoMath::unitCost('0.0001', 100), '0.000001 exactly');
        self::assertSame('0.000001', PoMath::unitCost('0.0001', 199), '0.0000005025 rounds up');
        self::assertSame('0.000000', PoMath::unitCost('0.0001', 201), '0.000000497 rounds down');
        self::assertSame('0.499990', PoMath::unitCost('4.9999', 10), 'exact');
        self::assertSame('0.041663', PoMath::unitCost('0.9999', 24), '0.0416625 exactly: half-up');
        self::assertSame('0.000001', PoMath::unitCost('0.0499', 99_800), 'exactly 0.0000005: half-up');
        // The double-rounding trap of an 8-decimal intermediate (MySQL's decimal division): 0.0499 / 99,999 = 0.000000499005 rounds DOWN,
        // although rounding to 8 decimals first (0.00000050) would round it up.
        self::assertSame('0.000000', PoMath::unitCost('0.0499', 99_999));
        self::assertSame('12.000000', PoMath::unitCost('12', 1));
    }

    public function testVatPerLineAndTotals(): void
    {
        self::assertSame(792, PoMath::lineVatE2(3960, 2000), '39.60 at 20 % = 7.92');
        self::assertSame(1, PoMath::lineVatE2(3, 2000), '0.03 at 20 % = 0.006 -> 0.01');
        self::assertSame(0, PoMath::lineVatE2(2, 2000), '0.02 at 20 % = 0.004 -> 0.00');
        self::assertSame(13, PoMath::lineVatE2(250, 500), '2.50 at 5 % = 0.125 -> 0.13');
        $t = PoMath::totals([
            ['amount_e2' => 3, 'vat_code' => 'S', 'rate_e2' => 2000],
            ['amount_e2' => 3, 'vat_code' => 'S', 'rate_e2' => 2000],
            ['amount_e2' => 250, 'vat_code' => 'R', 'rate_e2' => 500],
            ['amount_e2' => 1000, 'vat_code' => 'Z', 'rate_e2' => 0],
        ]);
        self::assertSame(1256, $t['net_e2']);
        self::assertSame(1 + 1 + 13, $t['vat_e2'], 'VAT per line, then summed (not 0.012 on the total)');
        self::assertSame(1271, $t['gross_e2']);
        self::assertSame(['S' => ['rate_e2' => 2000, 'net_e2' => 6, 'vat_e2' => 2], 'R' => ['rate_e2' => 500, 'net_e2' => 250, 'vat_e2' => 13],
            'Z' => ['rate_e2' => 0, 'net_e2' => 1000, 'vat_e2' => 0]], $t['by_code']);
    }

    public function testApprovalUnitsAreTheNetRoundedUpToWholePounds(): void
    {
        self::assertSame(10000, PoMath::approvalUnits(1_000_000));
        self::assertSame(10001, PoMath::approvalUnits(1_000_001), '£10,000.01 -> 10001');
        self::assertSame(0, PoMath::approvalUnits(0));
        self::assertSame(1, PoMath::approvalUnits(1));
    }

    public function testParsingAndFormatting(): void
    {
        self::assertSame(123_400, PoMath::e2('1234.000000'), 'a DECIMAL(18,6) of whole pence');
        self::assertSame(16_500, PoMath::e4('1.65'));
        self::assertSame('1.6500', PoMath::fromE4(16_500));
        self::assertSame('£1,234.50', PoMath::money(123_450));
        self::assertSame('£0.07', PoMath::money(7));
        self::assertSame('1.65', PoMath::price('1.6500'));
        self::assertSame('0.1234', PoMath::price('0.1234'));
        self::assertSame('12.00', PoMath::price('12'));
        foreach (['-1', '1.234567', 'abc', '1e3', ''] as $bad) {
            try {
                PoMath::e4($bad);
                self::fail("accepted {$bad}");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
