<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\ProfitMath;
use CW\Profit\SnapshotBody;
use PHPUnit\Framework\TestCase;

/**
 * The money rules of profit step 1 (docs/decisions.md PR4-PR11, PR13-PR16): VAT, discount, cancelled, free, refund, back in stock; the
 * moving average with a delivery, an invoice's correction, charges, a credit, a delivery taken back; and the snapshot's checks.
 */
final class ProfitMathTest extends TestCase
{
    private static function l(array $over = []): array
    {
        return $over + ['qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'refund_inc' => 0, 'cancelled' => false, 'free' => false];
    }

    public function testVatComesOffAtTheOrdersRateRoundedHalfAway(): void
    {
        self::assertSame(2000, ProfitMath::vatBp('20'));
        self::assertSame(1750, ProfitMath::vatBp('17.5'));
        self::assertSame(0, ProfitMath::vatBp('0'));
        self::assertNull(ProfitMath::vatBp('101'));
        self::assertNull(ProfitMath::vatBp('20.125'));
        self::assertNull(ProfitMath::vatBp('-5'));
        self::assertSame(1000, ProfitMath::exVat(1200, 2000));
        self::assertSame(83, ProfitMath::exVat(100, 2000), '83.33p');
        self::assertSame(1, ProfitMath::exVat(1, 2000), '0.83p rounds to 1p');
        self::assertSame(-83, ProfitMath::exVat(-100, 2000), 'a negative amount rounds the same way');
        self::assertSame(1200, ProfitMath::exVat(1200, 0));
        self::assertSame(3, ProfitMath::divRound(5, 2));
        self::assertSame(-3, ProfitMath::divRound(-5, 2));
    }

    public function testALineCountsItsSaleLessDiscountAndRefund(): void
    {
        self::assertSame(['counts' => true, 'units' => 1, 'sale_inc' => 1200, 'sale_ex' => 1000], ProfitMath::line(self::l(), 1, false, 2000));
        self::assertSame(['counts' => true, 'units' => 1, 'sale_inc' => 1000, 'sale_ex' => 833], ProfitMath::line(self::l(['discount_inc' => 200]), 1, false, 2000),
            'coupons and points are a price cut');
        self::assertSame(['counts' => true, 'units' => 1, 'sale_inc' => 1201, 'sale_ex' => 1001], ProfitMath::line(self::l(['discount_inc' => -1]), 1, false, 2000),
            'a coupon\'s rounding penny (a negative discount) is sale');
        self::assertSame(['counts' => true, 'units' => 1, 'sale_inc' => 600, 'sale_ex' => 500], ProfitMath::line(self::l(['refund_inc' => 600]), 1, false, 2000),
            'a part refund comes off the sale');
        self::assertSame(['counts' => false, 'units' => 0, 'sale_inc' => 0, 'sale_ex' => 0], ProfitMath::line(self::l(['cancelled' => true]), 1, false, 2000),
            'a cancelled line counts nothing');
        self::assertSame(['counts' => true, 'units' => 1, 'sale_inc' => 0, 'sale_ex' => 0], ProfitMath::line(self::l(['free' => true, 'price_inc' => 0]), 1, false, 2000),
            'a free line counts its unit with sale 0');
        self::assertSame(['counts' => true, 'units' => 3, 'sale_inc' => 1200, 'sale_ex' => 1000], ProfitMath::line(self::l(['qty' => 1]), 3, false, 2000),
            'a pack of 3 CW units');
        self::assertSame(['counts' => true, 'units' => 1, 'sale_inc' => 0, 'sale_ex' => 0], ProfitMath::line(self::l(['refund_inc' => 1200]), 1, true, 2000),
            'back in stock and refunded: no sale');
        self::assertSame(['counts' => true, 'units' => 1, 'sale_inc' => 1200, 'sale_ex' => 1000], ProfitMath::line(self::l(), 1, true, 2000),
            'back in stock, not refunded: the sale stays');
        // the cost
        self::assertSame(105, ProfitMath::lineCost(true, 1, 10500, false), '£1.05');
        self::assertSame(0, ProfitMath::lineCost(true, 1, 10500, true), 'a unit back in stock has no cost');
        self::assertSame(0, ProfitMath::lineCost(false, 0, 10500, false), 'a cancelled line has none');
        self::assertSame(0, ProfitMath::lineCost(true, 2, null, false), 'no price: 0 (listed as no buying price)');
        self::assertSame(1, ProfitMath::cost(1, 50), 'half a penny rounds up');
        self::assertSame(2500, ProfitMath::marginBp(250, 1000));
        self::assertSame(-1000, ProfitMath::marginBp(-100, 1000));
        self::assertNull(ProfitMath::marginBp(-50, 0), 'no sale: no margin');
    }

    public function testTheMovingAverage(): void
    {
        // A delivery moves the average with its units.
        self::assertSame(15000, ProfitMath::receive(10000, 10, 10, 20000));
        self::assertSame(20000, ProfitMath::receive(10000, 0, 10, 20000), 'nothing on hand: the delivery\'s price');
        self::assertSame(20000, ProfitMath::receive(10000, -4, 10, 20000), 'below zero: the delivery\'s price');
        self::assertSame(20000, ProfitMath::receive(null, 10, 10, 20000), 'no price yet: the delivery\'s');
        // An invoice corrects the units it bills that are still on hand.
        self::assertSame(15000, ProfitMath::correct(10000, 10, 10, 10000, 15000), 'all 10 billed units still here: +£0.50 each');
        self::assertSame(12500, ProfitMath::correct(10000, 20, 10, 10000, 15000), '10 of 20 on hand billed higher');
        self::assertSame(17500, ProfitMath::correct(10000, 4, 10, 10000, 17500), 'only the 4 still here move');
        self::assertSame(10000, ProfitMath::correct(10000, 0, 10, 10000, 15000), 'all sold: unchanged');
        self::assertSame(10000, ProfitMath::correct(10000, 10, 10, 10000, 10000), 'the same price: unchanged');
        self::assertSame(0, ProfitMath::correct(10000, 10, 10, 30000, 0), 'never below 0');
        // A price credit lowers the units still on hand (from = the credit per unit, to = 0).
        self::assertSame(9000, ProfitMath::correct(10000, 10, 10, 1000, 0));
        // A delivery taken back takes its units out at the price they came in at.
        self::assertSame(10000, ProfitMath::remove(15000, 20, 10, 20000));
        self::assertSame(15000, ProfitMath::remove(15000, 10, 10, 20000), 'nothing would be left: unchanged');
        self::assertSame(15000, ProfitMath::remove(15000, 12, 10, 90000), 'the value left would be below 0: unchanged');
        // Charges spread by value, the pennies to the largest remainders.
        self::assertSame([1 => 194, 2 => 106], ProfitMath::spread(300, [1 => 2000, 2 => 1100]));
        self::assertSame([1 => 0, 2 => 0], ProfitMath::spread(300, [1 => 0, 2 => 0]));
        self::assertSame([1 => -2, 2 => -1], ProfitMath::spread(-3, [1 => 2, 2 => 1]));
        self::assertSame(10500, ProfitMath::e4('1.05'));
        self::assertSame(2200, ProfitMath::e4('0.220000000'));
        self::assertSame(1, ProfitMath::e4('0.00005'), 'half a hundredth of a penny rounds up');
        self::assertNull(ProfitMath::e4('1,05'));
    }

    /**
     * The Codex re-check of 6b8a7ee (R3, F4): the fewest units on hand is looked at only between whole operations, in the item's commit order
     * (stock_value_seq), and an operation is its document, or its Idempotency-Key within the caller's scope (the store, or the staff / job).
     */
    public function testTheFewestOnHandIsSeenBetweenWholeOperationsInCommitOrder(): void
    {
        // 15 on hand. A transfer between two of our warehouses (out: ledger id 1, in: id 3) and a sale of 5 from a third warehouse booked
        // between them (id 2): the sale committed first (seq 1), the transfer after it (seq 2, 3).
        $transfer = ProfitMath::operation(7, 'staff:transfer-1', null, 'staff:3');
        $sale = ProfitMath::operation(null, 'sale-9', 2, 'channel:vapeandgo');
        $rows = [['id' => 1, 'seq' => 2, 'qty' => -10, 'op' => $transfer], ['id' => 2, 'seq' => 1, 'qty' => -5, 'op' => $sale], ['id' => 3, 'seq' => 3, 'qty' => 10, 'op' => $transfer]];
        self::assertSame(10, ProfitMath::lowestLevel(15, $rows), 'never 0: the transfer is whole, and the sale is before it in commit order');
        $rows[0]['seq'] = 1;
        $rows[1]['seq'] = 3;
        $rows[2]['seq'] = 2;
        self::assertSame(10, ProfitMath::lowestLevel(15, $rows), 'the transfer committed first: 15, then 10');
        // The same key of two stores is two operations: all ten sold, then ten more came.
        $a = ProfitMath::operation(null, 'batch:1', 1, 'channel:vapeandgo');
        $b = ProfitMath::operation(null, 'batch:1', 2, 'channel:vapebig');
        self::assertNotSame($a, $b);
        self::assertSame(0, ProfitMath::lowestLevel(10, [['id' => 5, 'seq' => 1, 'qty' => -10, 'op' => $a], ['id' => 6, 'seq' => 2, 'qty' => 10, 'op' => $b]]));
        self::assertSame(10, ProfitMath::lowestLevel(10, [['id' => 5, 'seq' => 1, 'qty' => -10, 'op' => $a], ['id' => 6, 'seq' => 2, 'qty' => 10, 'op' => $a]]),
            'one operation (one store, one key) is whole');
        // A row of no operation is one by itself; the level is never below 0.
        self::assertNull(ProfitMath::operation(null, null, null, 'system:x'));
        self::assertSame(0, ProfitMath::lowestLevel(3, [['id' => 1, 'seq' => 1, 'qty' => -5, 'op' => null], ['id' => 2, 'seq' => 2, 'qty' => 5, 'op' => null]]));
        self::assertSame(4, ProfitMath::lowestLevel(4, []));
    }

    private static function body(array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1763586245123, 'order_no' => 'VPG1567469', 'status' => 'Completed', 'placed_at' => '2026-11-19T21:04:05Z',
            'paid_at' => '2026-11-19T21:05:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => '8812345', 'variant_id' => 40123, 'qty' => 1, 'price_inc' => 1299, 'discount_inc' => 200, 'cancelled' => false, 'refund_inc' => 0,
                'free' => false]],
            'delivery' => ['method_id' => '12', 'method' => 'Royal Mail Tracked 48', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0,
            'grand_inc' => 1498, 'parcels' => [['parcel_ref' => 'odl:991122', 'tracking_no' => '15501234567890', 'carrier' => 'dpd', 'service_code' => 'x',
                'service' => 'DPD Next Day', 'status' => 'generated', 'label_at' => '2026-11-19T22:00:00+00:00', 'shipped_at' => null, 'manual' => false, 'partial' => false]]];
    }

    private static function field(array $body): ?string
    {
        try {
            SnapshotBody::parse($body, new \DateTimeImmutable('2026-11-20 00:00:00', new \DateTimeZone('UTC')));
        } catch (CwException $e) {
            self::assertSame(['bad_snapshot', 400], [$e->errorCode, $e->httpStatus]);
            return $e->detail['field'] ?? null;
        }
        return null;
    }

    public function testTheSnapshotIsCheckedFieldByFieldAndKeptNormalised(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 00:00:00', new \DateTimeZone('UTC'));
        $b = SnapshotBody::parse(self::body(['secret_note' => 'never kept', 'sub_of' => '1']), $now);
        self::assertArrayNotHasKey('secret_note', $b, 'an unknown field is never stored');
        self::assertSame('40123', $b['lines'][0]['variant_id'], 'an id is a string');
        self::assertSame('completed', $b['status']);
        self::assertSame('2026-11-19 21:05:00.000000', $b['paid_at']);
        self::assertSame(['v', 'version', 'order_no', 'status', 'placed_at', 'paid_at', 'gateway', 'currency', 'parent_ref', 'sub_of', 'lines', 'delivery',
            'order_refund_inc', 'grand_inc', 'parcels'], array_keys($b));
        self::assertSame('1', $b['sub_of'], 'an office sub order\'s first order is kept (CONTRACT.md change 2)');
        self::assertSame(SnapshotBody::hash($b), SnapshotBody::hash(['version' => 99] + $b), 'the version is not content');
        self::assertNotSame(SnapshotBody::hash($b), SnapshotBody::hash(['grand_inc' => 1] + $b));
        self::assertSame(-3, SnapshotBody::parse(self::body(['lines' => [['discount_inc' => -3] + self::body()['lines'][0]]]), $now)['lines'][0]['discount_inc'],
            'a negative discount (a coupon\'s rounding penny) is taken');

        self::assertSame('lines[0].discount_inc', self::field(self::body(['lines' => [['discount_inc' => -1300] + self::body()['lines'][0]]])),
            'never below minus the line\'s price');
        self::assertSame('lines[0].discount_inc', self::field(self::body(['lines' => [['price_inc' => 20000, 'discount_inc' => -10001] + self::body()['lines'][0]]])),
            'never below -£100');
        self::assertNull(self::field(self::body(['lines' => [['price_inc' => 20000, 'discount_inc' => -10000] + self::body()['lines'][0]]])));
        self::assertNull(self::field(self::body(['gateway' => 'pphf', 'parcels' => [['parcel_ref' => 'orl:55', 'tracking_no' => null, 'carrier' => 'royal_mail',
            'service_code' => 'TSS', 'service' => 'Return label', 'status' => 'created', 'label_at' => '2026-11-19T22:00:00Z', 'shipped_at' => null, 'manual' => false,
            'partial' => false]]])), 'a return label with no number');
        self::assertNull(self::field(self::body()));
        self::assertSame('v', self::field(self::body(['v' => 2])));
        self::assertSame('version', self::field(self::body(['version' => '5'])));
        self::assertSame('version', self::field(self::body(['version' => 0])));
        self::assertSame('currency', self::field(self::body(['currency' => 'EUR'])));
        self::assertSame('paid_at', self::field(self::body(['paid_at' => '2026-11-21T00:00:00Z'])), 'not in the future');
        self::assertSame('paid_at', self::field(self::body(['paid_at' => '19/11/2026'])));
        self::assertSame('placed_at', self::field(self::body(['placed_at' => '1999-12-31T23:59:59Z'])));
        self::assertSame('lines', self::field(self::body(['lines' => ['a' => 1]])));
        self::assertSame('lines[0].price_inc', self::field(self::body(['lines' => [['price_inc' => -1] + self::body()['lines'][0]]])));
        self::assertSame('lines[0].price_inc', self::field(self::body(['lines' => [['price_inc' => 12.99] + self::body()['lines'][0]]])), 'pence, not pounds');
        self::assertSame('lines[0].refund_inc', self::field(self::body(['lines' => [['refund_inc' => -5] + self::body()['lines'][0]]])));
        self::assertSame('lines[0].qty', self::field(self::body(['lines' => [['qty' => 0] + self::body()['lines'][0]]])));
        self::assertSame('lines[0].cancelled', self::field(self::body(['lines' => [['cancelled' => 1] + self::body()['lines'][0]]])));
        self::assertSame('lines[0].line_id', self::field(self::body(['lines' => [['line_id' => "a\nb"] + self::body()['lines'][0]]])));
        self::assertSame('lines[1].line_id', self::field(self::body(['lines' => [self::body()['lines'][0], self::body()['lines'][0]]])), 'a line twice');
        self::assertSame('lines', self::field(self::body(['lines' => array_fill(0, 1001, self::body()['lines'][0])])));
        self::assertSame('delivery.charged_inc', self::field(self::body(['delivery' => ['charged_inc' => 'x']])));
        self::assertSame('parcels[0].parcel_ref', self::field(self::body(['parcels' => [['parcel_ref' => ''] + self::body()['parcels'][0]]])));
        self::assertSame('parcels[0].tracking_no', self::field(self::body(['parcels' => [['tracking_no' => str_repeat('9', 65)] + self::body()['parcels'][0]]])));
        self::assertSame('parcels', self::field(self::body(['parcels' => array_map(static fn (int $i): array => ['parcel_ref' => 'p' . $i] + self::body()['parcels'][0],
            range(1, 51))])));
        self::assertSame('parent_ref', self::field(self::body(['parent_ref' => ['x']])));
        self::assertSame('order_refund_inc', self::field(self::body(['order_refund_inc' => ProfitMath::MAX_PENCE + 1])));
    }
}
