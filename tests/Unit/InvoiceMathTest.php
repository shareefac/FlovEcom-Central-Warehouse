<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Invoices\InvoiceMath;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic of the 3-way match (pack C; docs/decisions.md PC2-PC5): the plan's tolerances (price 2 % or £5 a line, the total
 * 5p a line, the quantity exact), a line within and outside them, the money to look at, the invoice's state, and spreading an amount
 * to the penny.
 */
final class InvoiceMathTest extends TestCase
{
    /** @return array{price_pct_e6: int, price_gbp_e2: int, qty_pct_e6: int, total_per_line_e2: int} */
    private static function plan(array $over = []): array
    {
        return InvoiceMath::rules($over + ['price_pct' => '2', 'price_gbp' => '5.00', 'qty_pct' => '0', 'total_per_line' => '0.05']);
    }

    public function testTheRulesAreTheSettingsInWholeUnits(): void
    {
        self::assertSame(['price_pct_e6' => 2_000_000, 'price_gbp_e2' => 500, 'qty_pct_e6' => 0, 'total_per_line_e2' => 5], self::plan());
        self::assertSame(1, InvoiceMath::rules(['price_pct' => '', 'price_gbp' => '0.005', 'qty_pct' => '0', 'total_per_line' => '0'])['price_gbp_e2'], 'a half penny rounds up');
    }

    public function testALineMatchedWithinAndOff(): void
    {
        $r = self::plan();
        $l = static fn (int $units, int $amount, ?int $avail, ?int $ref): array => InvoiceMath::line(['units' => $units, 'amount_e2' => $amount, 'inv_unit_e6' => 0,
            'available' => $avail, 'ref_unit_e6' => $ref], $r);
        // 10 at £10.00 against an order of £10.00 and 10 received.
        self::assertSame(['matched', 0, 0, 0, []], self::pick($l(10, 10000, 10, 10_000_000)));
        // £1.50 over on £100 (1.5 %, under £5): within; £10 over (10 %): off, and the money to look at is £10.
        self::assertSame(['within', 0, 150, 150, ['price_over']], self::pick($l(10, 10150, 10, 10_000_000)));
        self::assertSame(['off', 0, 1000, 1000, ['price_over']], self::pick($l(10, 11000, 10, 10_000_000)));
        // £6 over on £1,000 (0.6 %, more than £5): within by the percentage; £30 under on £1,000 (3 %, more than £5): off.
        self::assertSame('within', $l(100, 100600, 100, 10_000_000)['state']);
        self::assertSame(['off', ['price_under']], [$l(100, 97000, 100, 10_000_000)['state'], $l(100, 97000, 100, 10_000_000)['why']]);
        // 12 billed of 10 to invoice: off (exact), the 2 extra at their price are the money.
        self::assertSame(['off', 2, 0, 2000, ['qty_over']], self::pick($l(12, 12000, 10, 10_000_000)));
        // A partial invoice (6 of 10) is matched; nothing linked: off, the whole amount.
        self::assertSame('matched', $l(6, 6000, 10, 10_000_000)['state']);
        self::assertSame(['off', 3, 0, 3000, ['no_delivery']], self::pick($l(3, 3000, null, null)));
        // A quantity tolerance of 20 % lets 2 over 10 through, not 3.
        $q = self::plan(['qty_pct' => '20']);
        self::assertSame('within', InvoiceMath::line(['units' => 12, 'amount_e2' => 12000, 'inv_unit_e6' => 0, 'available' => 10, 'ref_unit_e6' => 10_000_000], $q)['state']);
        self::assertSame('off', InvoiceMath::line(['units' => 13, 'amount_e2' => 13000, 'inv_unit_e6' => 0, 'available' => 10, 'ref_unit_e6' => 10_000_000], $q)['state']);
        // Both price tests switched off: exact.
        $exact = self::plan(['price_pct' => '0', 'price_gbp' => '0']);
        self::assertSame('off', InvoiceMath::line(['units' => 1, 'amount_e2' => 1001, 'inv_unit_e6' => 0, 'available' => 1, 'ref_unit_e6' => 10_000_000], $exact)['state']);
    }

    public function testTheInvoiceStateAndTheTotal(): void
    {
        self::assertSame('matched', InvoiceMath::state(['matched', 'matched'], 0));
        self::assertSame('within_tolerance', InvoiceMath::state(['matched'], 3), 'the total differs by rounding');
        self::assertSame('within_tolerance', InvoiceMath::state(['within', 'matched'], 0));
        self::assertSame('needs_review', InvoiceMath::state(['within', 'off'], 0));
        self::assertTrue(InvoiceMath::totalOk(1450, 1460, 2, 5), '10p over two lines of 5p');
        self::assertFalse(InvoiceMath::totalOk(1450, 1461, 2, 5));
    }

    public function testSpreadingToThePenny(): void
    {
        self::assertSame([1 => 194, 2 => 106], InvoiceMath::spread(300, [1 => 2000, 2 => 1100]), 'the penny left goes to the larger remainder');
        self::assertSame([1 => 2200, 2 => 0], InvoiceMath::spread(2200, [1 => 1000, 2 => 0]));
        self::assertSame(['a' => 34, 'b' => 33, 'c' => 33], InvoiceMath::spread(100, ['a' => 0, 'b' => 0, 'c' => 0]), 'no weight: equal shares');
        self::assertSame([1 => -194, 2 => -106], InvoiceMath::spread(-300, [1 => 2000, 2 => 1100]));
        self::assertSame(1, array_sum(InvoiceMath::spread(1, [1 => 1, 2 => 1, 3 => 1])));
    }

    public function testMoney(): void
    {
        self::assertSame(-1250, InvoiceMath::e2('-12.500000'));
        self::assertSame('-12.50', InvoiceMath::fromE2(-1250));
        self::assertSame(1001, InvoiceMath::unitsAt(3, 3_336_666), '3 x £3.336666 = £10.009998: £10.01');
        self::assertSame(-1001, InvoiceMath::unitsAt(-3, 3_336_666));
    }

    /** @param array<string, mixed> $m @return list<mixed> */
    private static function pick(array $m): array
    {
        return [$m['state'], $m['excess'] > 0 ? $m['excess'] : 0, $m['price_e2'], $m['diff_e2'], $m['why']];
    }
}
