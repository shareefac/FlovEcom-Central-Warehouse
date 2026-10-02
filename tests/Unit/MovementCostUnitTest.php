<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Movements;
use PHPUnit\Framework\TestCase;

/** I1, I6: a unit cost has ONE canonical form (6 decimals), whatever JSON type carried it, so the request hash is stable. */
final class MovementCostUnitTest extends TestCase
{
    public function testIntStringAndFloatFormsGiveTheSameCanonicalString(): void
    {
        foreach (['1.25', '1.250000', '1.2500', 1.25] as $in) {
            self::assertSame('1.250000', Movements::normaliseCost($in, 'c'), var_export($in, true));
        }
        foreach ([[3, '3.000000'], ['3', '3.000000'], [3.0, '3.000000'], ['0.5', '0.500000'], [0.1, '0.100000'],
            ['12.345678', '12.345678'], [12.345678, '12.345678'], ['99999999.999999', Movements::MAX_COST], [99_999_999, '99999999.000000']] as [$in, $out]) {
            self::assertSame($out, Movements::normaliseCost($in, 'c'), var_export($in, true));
        }
    }

    public function testZeroIsAllowed(): void
    {
        foreach ([0, '0', '0.0', '0.000000', 0.0, -0.0] as $in) {
            self::assertSame('0.000000', Movements::normaliseCost($in, 'c'), var_export($in, true));
        }
    }

    public function testAlwaysExactlySixDecimals(): void
    {
        foreach ([7, '7.1', 7.12, '7.123', 7.1234, '7.12345', 7.123456] as $in) {
            self::assertMatchesRegularExpression('/^\d+\.\d{6}$/D', Movements::normaliseCost($in, 'c'), var_export($in, true));
        }
    }

    public function testRefusesEverythingElse(): void
    {
        foreach ([-1, -0.5, '-1', '1.2345678', 1.2345678, '1e3', '01.5', '00', 100_000_000, 100_000_000.0, '100000000', NAN, INF, -INF,
            '1,5', ' 1', '1 ', '', '.5', '5.', '+1', '0x1A', true, false, null, [], ['1.5']] as $bad) {
            try {
                Movements::normaliseCost($bad, 'lines[2].unit_cost');
                self::fail(var_export($bad, true) . ' should be refused');
            } catch (CwException $e) {
                self::assertSame(['bad_cost', 400, ['field' => 'lines[2].unit_cost']], [$e->errorCode, $e->httpStatus, $e->detail], var_export($bad, true));
            }
        }
    }
}
