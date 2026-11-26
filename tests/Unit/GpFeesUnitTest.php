<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Profit\GpFees;
use CW\Profit\ProfitMath;
use PHPUnit\Framework\TestCase;

/**
 * CW-1174 (docs/decisions.md GPF1-GPF5): the card fee rules that need no database. The rate as a person types it (four decimals at most,
 * 0-10%); the estimate per order, half up (the owner's examples: a £30 order about 30p, a £40,000 day about £404); the share-out of an
 * invoice to the penny with the remainders to the largest (an October-sized month too); which account takes an order; what a refund, a
 * moved day or a changed amount does to a row.
 */
final class GpFeesUnitTest extends TestCase
{
    public function testTheRateAsAPersonTypesIt(): void
    {
        self::assertSame([10100, 9823, 100000, 10100, 22800, 0], [ProfitMath::rateE4('1.01'), ProfitMath::rateE4('0.9823'), ProfitMath::rateE4('10'),
            ProfitMath::rateE4('1.01000'), ProfitMath::rateE4('2.28'), ProfitMath::rateE4('0')]);
        foreach (['10.0001', '1.00001', '11', '-1', 'abc', '', '1,01'] as $bad) {
            self::assertNull(ProfitMath::rateE4($bad), $bad);
        }
        self::assertSame(['1.0099', '1.01', '2', '0'], [ProfitMath::ratePercent(10099), ProfitMath::ratePercent(10100), ProfitMath::ratePercent(20000), ProfitMath::ratePercent(0)]);
    }

    public function testTheEstimateIsPerOrderHalfUp(): void
    {
        self::assertSame(30, GpFees::estimate(2999, 10100), '£29.99 at 1.01% is 30.29p');
        self::assertSame(30, GpFees::estimate(3000, 10100), 'a £30 order: about 30p');
        self::assertSame(40400, GpFees::estimate(4_000_000, 10100), 'a £40,000 day: about £404');
        self::assertSame(1, GpFees::estimate(50, 10100), '0.505p rounds up');
        self::assertSame(0, GpFees::estimate(49, 10100));
        self::assertSame(0, GpFees::estimate(0, 10100));
        self::assertSame(0, GpFees::estimate(-500, 10100), 'never below 0');
    }

    public function testAnInvoiceIsSharedToThePenny(): void
    {
        self::assertSame([60, 30, 10], ProfitMath::shareOut(100, [2999, 1500, 501]));
        self::assertSame([1, 0], ProfitMath::shareOut(1, [1, 1]), 'a tie: the earlier one');
        self::assertSame(['b' => 1, 'a' => 0], ProfitMath::shareOut(1, ['b' => 1, 'a' => 1]), 'the order of the keys decides, not their names');
        self::assertSame([0, 0], ProfitMath::shareOut(500, [0, 0]), 'nothing to share it over: the caller puts it elsewhere');
        self::assertSame([0, 7, 0], ProfitMath::shareOut(7, [0, 3, 0]), 'an order of 0 gets nothing');
        // October 2026: £11,834.72 net over 33,694 card payments
        $w = [];
        $x = 12345;
        for ($i = 0; $i < 33694; $i++) {
            $x = ($x * 1103515245 + 12345) % 2147483648;
            $w[] = 200 + $x % 20000;
        }
        $s = ProfitMath::shareOut(1_183_472, $w);
        self::assertSame(1_183_472, array_sum($s), 'every penny once');
        $sum = array_sum($w);
        foreach ($s as $i => $p) {
            $exact = 1_183_472 * $w[$i] / $sum;
            self::assertTrue($p >= floor($exact) && $p <= floor($exact) + 1, "share {$i}");
        }
        self::assertSame($s, ProfitMath::shareOut(1_183_472, $w), 'the same every time');
    }

    public function testWhichAccountTakesAnOrder(): void
    {
        $cfg = ['vpg_online' => ['on' => true, 'shops' => [1, 3], 'from' => '2026-11-01', 'until' => null, 'rate' => 10100],
            'ef_online' => ['on' => true, 'shops' => [2], 'from' => '2026-11-01', 'until' => '2026-12-24', 'rate' => 22800]];
        self::assertSame('vpg_online', GpFees::accountOf($cfg, 1, 'globalpay', '2026-11-19'));
        self::assertSame('vpg_online', GpFees::accountOf($cfg, 3, 'globalpay', '2026-11-19'), 'Vape Big through Vape and Go Online');
        self::assertSame('ef_online', GpFees::accountOf($cfg, 2, 'globalpay', '2026-12-24'));
        self::assertNull(GpFees::accountOf($cfg, 2, 'globalpay', '2026-12-25'), 'after the last day');
        self::assertNull(GpFees::accountOf($cfg, 1, 'globalpay', '2026-10-31'), 'before the first day');
        self::assertNull(GpFees::accountOf($cfg, 1, 'pphf', '2026-11-19'), 'PayPal card fields are PayPal\'s');
        self::assertNull(GpFees::accountOf($cfg, 1, 'globalpay', null), 'not paid');
        self::assertNull(GpFees::accountOf($cfg, 4, 'globalpay', '2026-11-19'), 'a shop no account takes');
        $cfg['vpg_online']['on'] = false;
        self::assertNull(GpFees::accountOf($cfg, 1, 'globalpay', '2026-11-19'), 'switched off');
    }

    public function testWhatARefundADayAndAnAmountDoToARow(): void
    {
        $cfg = ['vpg_online' => ['on' => true, 'shops' => [1], 'from' => '2026-11-01', 'until' => null, 'rate' => 10100]];
        $none = static fn (string $a, string $m): bool => false;
        $live = static fn (string $a, string $m): bool => $m === '2026-11';
        $order = ['gateway' => 'globalpay', 'day' => '2026-11-19', 'grand_inc' => 2999];
        [$row, $q] = GpFees::plan($cfg, 1, '9100001', $order, null, '2026-11-19 10:00:00', $none);
        self::assertSame(['estimate', 30, []], [$row['state'], $row['fee_pence'], $q]);
        // a refund changes nothing of the order's paid amount: the stored row stays as it is
        [$again] = GpFees::plan($cfg, 1, '9100001', $order, $row, '2026-11-20 10:00:00', $none);
        self::assertSame($row, $again);
        // an actual share stays while its base and month stay (a day moved inside the month moves it along, and the applied month is spread
        // again so its rounding penny follows the day order: the Codex review of 6043729, L15)
        $actual = ['state' => 'actual', 'fee_pence' => 29, 'rate_e4' => null, 'invoice_id' => 7, 'spread_version' => 1] + $row;
        [$kept, $q] = GpFees::plan($cfg, 1, '9100001', ['day' => '2026-11-21'] + $order, $actual, '2026-11-21 10:00:00', $live);
        self::assertSame(['actual', 29, '2026-11-21', [['vpg_online', '2026-11']]], [$kept['state'], $kept['fee_pence'], $kept['day'], $q]);
        [$same, $q] = GpFees::plan($cfg, 1, '9100001', $order, $actual, '2026-11-21 10:00:00', $live);
        self::assertSame([$actual, []], [$same, $q], 'nothing changed: nothing queued');
        // another amount in an applied month: an estimate, and the month queued to be spread again
        [$moved, $q] = GpFees::plan($cfg, 1, '9100001', ['grand_inc' => 4000] + $order, $actual, '2026-11-21 10:00:00', $live);
        self::assertSame(['estimate', 40, [['vpg_online', '2026-11']]], [$moved['state'], $moved['fee_pence'], $q]);
        // back to estimates (its invoice deleted): keepActual false
        [$back] = GpFees::plan($cfg, 1, '9100001', $order, $actual, '2026-11-21 10:00:00', $none, false);
        self::assertSame(['estimate', 30], [$back['state'], $back['fee_pence']]);
        // paid another way now: off, counting nothing, the applied month queued
        [$off, $q] = GpFees::plan($cfg, 1, '9100001', ['gateway' => 'pp'] + $order, $actual, '2026-11-21 10:00:00', $live);
        self::assertSame(['off', 0, [['vpg_online', '2026-11']]], [$off['state'], $off['fee_pence'], $q]);
        self::assertSame(['fee' => 0, 'est' => 0], GpFees::share($off));
        self::assertSame(['fee' => 30, 'est' => 30], GpFees::share($row));
        self::assertSame(['fee' => 29, 'est' => 0], GpFees::share($actual));
    }
}
