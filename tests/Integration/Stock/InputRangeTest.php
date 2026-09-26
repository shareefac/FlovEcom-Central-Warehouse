<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\CwException;
use CW\ListingProfiles;
use CW\Tests\Support\StockTestCase;

/**
 * R16: inputs that pass the shape checks but do not fit their column are refused with a 4xx
 * (not stored, nothing booked) instead of failing the statement in strict mode with a 500 that
 * the relay or outbox retries for ever. Found by the review of 26 Sep (slot review3).
 */
final class InputRangeTest extends StockTestCase
{
    private static function refusedWith(int $status, string $code, callable $call): void
    {
        try {
            $call();
            self::fail("expected {$status} {$code}");
        } catch (CwException $e) {
            self::assertSame([$status, $code], [$e->httpStatus, $e->errorCode], $e->getMessage());
        }
    }

    public function testQuantitiesBeyondTheIntColumnsAreRefused(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        $sku = $this->item('strict', 10);
        $this->listing($site, 'K', $sku, 1000);
        // 10,000,000 listing units x u = 1000
        self::refusedWith(422, 'out_of_range', fn () => $this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'P-1',
            'lines' => [['variant_id' => 'K', 'qty' => 10_000_000]]], $this->key()));
        // the summed count of one item (300 lines x 10M)
        $lines = array_map(static fn (int $i): array => ['sku_id' => $sku, 'qty' => 10_000_000, 'line_index' => $i], range(0, 299));
        self::refusedWith(422, 'out_of_range', fn () => $this->moves->record(self::staff(), ['type' => 'count',
            'counted_at' => '2026-09-26T17:00:00Z', 'lines' => $lines], $this->key()));
        // on_hand pushed past the top by a later goods-in (each line is at most 10M)
        $many = array_map(static fn (int $i): array => ['sku_id' => $sku, 'qty' => 10_000_000, 'line_index' => $i], range(0, 213));
        $this->ok($this->moves->record(self::staff(), ['type' => 'goods_in', 'doc_ref' => 'P-BIG', 'lines' => $many], 'big'));
        self::refusedWith(422, 'out_of_range', fn () => $this->book('goods_in', $sku, 10_000_000));
        $this->assertBal(2_140_000_010, 0, 0, $sku);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE response_status >= 400"), 'no refusal is stored');
    }

    public function testLineIndexAndAttemptBeyondIntUnsignedAreRefused(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        self::refusedWith(400, 'bad_lines', fn () => $this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'P-2',
            'lines' => [['variant_id' => 'NOPE', 'qty' => 1, 'line_index' => 5_000_000_000]]], $this->key()));
        self::refusedWith(400, 'bad_attempt', fn () => $this->release($site, 'unknown', 5_000_000_000));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM goods_in_suspense'));
        self::assertNull($this->reservation($site, 'unknown'));
    }

    public function testAPriceThatRoundsBeyondTheColumnIsRefusedAndOneThatFitsIsKept(): void
    {
        $site = $this->site();
        $p = new ListingProfiles(self::$db);
        self::refusedWith(400, 'bad_listings', fn () => $p->push($site, [['variant_id' => 'A', 'price' => 99_999_999.999]]));
        $r = $p->push($site, [['variant_id' => 'A', 'price' => 99_999_999.994], ['variant_id' => 'B', 'price' => '0.005']]);
        self::assertSame(2, $r['created']);
        self::assertSame(['99999999.99', '0.01'], array_map('strval', self::$db->column('SELECT price FROM listing_profile ORDER BY listing_id')));
    }

    public function testATimePastYear9999InUtcIsRefused(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a')]);
        $this->ship($site, '1', ['a']);
        self::refusedWith(400, 'bad_time', fn () => $this->res->unship($site, '1', ['a'], '9999-12-31T23:59:59-01:00', $this->key()));
        $this->assertBal(4, 0, 0, $sku);
    }
}
