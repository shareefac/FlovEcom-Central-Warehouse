<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\CwException;
use CW\OpResult;
use CW\Tests\Support\StockTestCase;

/**
 * R5 / R10: caller-reported times must be plausible (CW's clock here is StockTestCase::NOW,
 * 26 Sep 18:00). Found by the review of 26 Sep (slots review2, review3): a count dated a year
 * ahead was booked, after which every real ship was "pre-count" (on_hand never fell) and every
 * correct recount was "stale": the book could not repair itself.
 */
final class EventTimeTest extends StockTestCase
{
    /** @return array{0: string, 1: array<string, mixed>} error code and detail of a refused call */
    private static function refused(callable $call): array
    {
        try {
            $r = $call();
            self::fail('expected a refusal, got ' . ($r instanceof OpResult ? $r->status . ' ' . json_encode($r->body) : 'nothing'));
        } catch (CwException $e) {
            self::assertSame(400, $e->httpStatus, json_encode($e->body()));
            return [$e->errorCode, $e->detail];
        }
    }

    private function countAt(int $sku, int $qty, string $countedAt, array $extra = []): OpResult
    {
        return $this->moves->record(self::staff(), ['type' => 'count', 'counted_at' => $countedAt, 'lines' => [['sku_id' => $sku, 'qty' => $qty]]] + $extra,
            $this->key('count'));
    }

    public function testACountedAtInTheFutureIsRefusedAndRealShipsStillLowerOnHand(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);

        [$code, $detail] = self::refused(fn () => $this->countAt($sku, 10, '2027-09-26T18:00:00Z')); // a year typo
        self::assertSame(['bad_time', 'in_future'], [$code, $detail['reason']]);
        self::assertNull(self::$db->value('SELECT counted_at FROM stock_balance WHERE sku_id = ?', [$sku]));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE path = '/v1/movements' AND response_status <> 200"),
            'nothing stored: the counter fixes the time and sends it again');
        // within the clock-skew allowance (5 min) is fine
        $this->ok($this->countAt($sku, 10, '2026-09-26T18:04:00Z'));

        $s = $this->ship($site, '1', ['a', 'b'], '2026-09-26T18:04:30Z');
        self::assertSame(['shipped', 'shipped'], array_column($s->body['units'], 'result'));
        $fix = $this->ok($this->countAt($sku, 8, '2026-09-26T18:05:00Z'));
        self::assertSame('counted', $fix->body['lines'][0]['result']);
        $this->assertBal(8, 0, 0, $sku);
    }

    public function testACountOlderThanADayNeedsTheStaffBackdatedOption(): void
    {
        $sku = $this->item('strict', 10);
        [$code, $detail] = self::refused(fn () => $this->countAt($sku, 9, '2026-09-25T17:59:00Z'));
        self::assertSame(['bad_time', 'too_old'], [$code, $detail['reason']]);
        $r = $this->ok($this->countAt($sku, 9, '2026-09-25T17:59:00Z', ['backdated' => true])); // typed in from paper
        self::assertSame('counted', $r->body['lines'][0]['result']);
        self::refused(fn () => $this->countAt($sku, 9, '2026-06-01T00:00:00Z', ['backdated' => true])); // beyond 90 days
        [$code] = self::refused(fn () => $this->countAt($sku, 9, '2026-09-26T12:00:00Z', ['backdated' => 'yes']));
        self::assertSame('bad_field', $code);
        [$code] = self::refused(fn () => $this->moves->record(self::staff(), ['type' => 'adjustment', 'backdated' => true,
            'lines' => [['sku_id' => $sku, 'qty' => 1]]], $this->key()));
        self::assertSame('bad_field', $code, 'backdated is an option of a count only');
        $this->assertBal(9, 0, 0, $sku);
    }

    public function testShipAndResetTimesMustBePlausible(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);

        [, $d] = self::refused(fn () => $this->ship($site, '1', ['a'], '2026-09-26T18:06:00Z'));
        self::assertSame('in_future', $d['reason']);
        [, $d] = self::refused(fn () => $this->ship($site, '1', ['a'], '2026-06-01T00:00:00Z'));
        self::assertSame('too_old', $d['reason']);
        $this->assertBal(10, 2, 0, $sku);
        $this->ship($site, '1', ['a', 'b'], '2026-07-01T09:00:00Z'); // the 30-day backstop reports late ones
        $this->assertBal(8, 0, 0, $sku);

        // a reset cannot precede (or equal) the dispatch it reverses: a connector that sends the
        // ship's own dispatched_at would book a reset after a count as a pre-count one (R10)
        [, $d] = self::refused(fn () => $this->res->unship($site, '1', ['a'], '2026-07-01T09:00:00Z', $this->key('unship')));
        self::assertSame(['not_after_dispatch', 'a'], [$d['reason'], $d['unit_id']]);
        [, $d] = self::refused(fn () => $this->res->unship($site, '1', ['a'], '2026-09-26T19:00:00Z', $this->key('unship')));
        self::assertSame('in_future', $d['reason']);
        $this->assertBal(8, 0, 0, $sku);
        self::assertSame('unshipped', $this->res->unship($site, '1', ['a'], '2026-07-01T09:30:00Z', $this->key('unship'))->body['units'][0]['result']);
        $this->assertBal(9, 1, 0, $sku);
    }

    /** The review's case: a reset after the count, reported with the ship's dispatch time. */
    public function testAResetReportedWithTheDispatchTimeIsRefusedNotBookedAsPreCount(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a')]);
        $this->ship($site, '1', ['a'], '2026-09-26T11:00:00Z');           // left at 11:00
        $this->book('count', $sku, 9, 'MAIN', '2026-09-26T12:00:00Z');    // 9 on the shelf
        [, $d] = self::refused(fn () => $this->res->unship($site, '1', ['a'], '2026-09-26T11:00:00Z', $this->key('unship')));
        self::assertSame('not_after_dispatch', $d['reason']);
        // sent with the real reset time (12:30), the unit is back on the shelf: on_hand rises
        $r = $this->res->unship($site, '1', ['a'], '2026-09-26T12:30:00Z', $this->key('unship'));
        self::assertSame('unshipped', $r->body['units'][0]['result']);
        $this->assertBal(10, 1, 0, $sku);
    }

    /** Calendar-invalid times, impossible offsets and years beyond 9999 in UTC are refused (R14, R16). */
    public function testTimesMustBeRealCalendarTimes(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a')]);
        foreach (['2026-02-31T00:00:00Z', '2026-09-26T24:00:00Z', '2026-09-26T12:60:00Z', '2026-09-26T12:00:61Z',
            '2026-09-26T12:00:00+25:00', '9999-12-31T23:59:59-01:00', '0000-01-01T00:00:00Z', '2026-9-26T12:00:00Z'] as $t) {
            [$code] = self::refused(fn () => $this->countAt($sku, 1, $t));
            self::assertSame('bad_time', $code, $t);
            [$code] = self::refused(fn () => $this->ship($site, '1', ['a'], $t));
            self::assertSame('bad_time', $code, $t);
        }
        $this->assertBal(10, 1, 0, $sku);
        self::assertNull(self::$db->value('SELECT counted_at FROM stock_balance WHERE sku_id = ?', [$sku]));
        // a real one with an offset is converted to UTC
        $this->ok($this->countAt($sku, 10, '2026-09-26T13:00:00+01:00'));
        self::assertSame('2026-09-26 12:00:00.000000', self::$db->value('SELECT counted_at FROM stock_balance WHERE sku_id = ?', [$sku]));
    }

    /** A stored answer is replayed, even when its time would no longer pass the window. */
    public function testAStoredShipIsReplayedWhateverTheClockSaysNow(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a')]);
        $first = $this->ok($this->ship($site, '1', ['a'], '2026-09-26T17:59:00Z', 'ship-a'));
        $this->now = $this->now->modify('-1 hour'); // e.g. CW's clock corrected backwards
        $again = $this->ship($site, '1', ['a'], '2026-09-26T17:59:00Z', 'ship-a');
        self::assertTrue($again->replayed);
        self::assertEquals($first->body, $again->body);
    }
}
