<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Clock;
use CW\CwException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Caller-reported times (R5, R14, R16): real calendar times, stored in UTC, within a plausible window. */
final class ClockTest extends TestCase
{
    public function testParseAcceptsRealTimesAndConvertsToUtc(): void
    {
        foreach ([
            '2026-09-26T13:00:00+01:00' => '2026-09-26 12:00:00.000000',
            '2026-09-26 12:00:00' => '2026-09-26 12:00:00.000000',
            '2026-09-26T12:00Z' => '2026-09-26 12:00:00.000000',
            '2028-02-29T23:59:59.5-0130' => '2028-03-01 01:29:59.500000',
            '9999-12-31T23:59:59Z' => '9999-12-31 23:59:59.000000',
        ] as $in => $utc) {
            self::assertSame($utc, Clock::db(Clock::parse($in, 't')), $in);
        }
    }

    public function testParseRefusesImpossibleTimes(): void
    {
        foreach (['2026-02-31T00:00:00Z', '2027-02-29T00:00:00Z', '2026-13-01T00:00:00Z', '2026-09-26T24:00:00Z',
            '2026-09-26T12:60:00Z', '2026-09-26T12:00:60Z', '2026-09-26T12:00:00+15:00', '2026-09-26T12:00:00+01:60',
            '9999-12-31T23:59:59-01:00', '0000-01-01T00:00:00Z', 'yesterday', '', '2026-09-26'] as $bad) {
            try {
                Clock::parse($bad, 't');
                self::fail("{$bad} should be refused");
            } catch (CwException $e) {
                self::assertSame(['bad_time', 400], [$e->errorCode, $e->httpStatus], $bad);
            }
        }
    }

    public function testCheckWindow(): void
    {
        $now = new DateTimeImmutable('2026-09-26 18:00:00', Clock::utc());
        Clock::checkWindow($now->modify('+300 seconds'), 't', $now, 3600);
        Clock::checkWindow($now->modify('-3600 seconds'), 't', $now, 3600);
        foreach (['+301 seconds' => 'in_future', '-3601 seconds' => 'too_old'] as $shift => $reason) {
            try {
                Clock::checkWindow($now->modify($shift), 't', $now, 3600);
                self::fail("{$shift} should be refused");
            } catch (CwException $e) {
                self::assertSame(['bad_time', $reason, 't'], [$e->errorCode, $e->detail['reason'], $e->detail['field']]);
            }
        }
    }
}
