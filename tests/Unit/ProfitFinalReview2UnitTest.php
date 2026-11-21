<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitMath;
use CW\Profit\SnapshotBody;
use PHPUnit\Framework\TestCase;

/**
 * The second final review of 3c31d94 (build/A/codex_final2_astra_{a,b,c}.txt; final2/FIX_BRIEF.md), the pure parts, each shown failing
 * first with the reviewers' numbers: a parcel ref and a delivery name hold no customer's words (F12, b-HIGH1); parent_ref must be sent
 * (F13, b-M2); a line and an order refund on a restocked unit take the money off once (F16, c-M1); a charge of £40 million spreads without
 * overflow (F17, c-L3).
 */
final class ProfitFinalReview2UnitTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 00:00:00', new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private static function parcel(array $over = []): array
    {
        return $over + ['parcel_ref' => 'odl:991122', 'tracking_no' => null, 'carrier' => 'royal_mail', 'service_code' => 'TPL48HV',
            'service' => 'Royal Mail Tracked 48 (2-4 Working Days)', 'status' => 'generated', 'label_at' => '2026-11-19T22:00:00Z', 'shipped_at' => null,
            'manual' => false, 'partial' => false];
    }

    /** @return array<string, mixed> */
    private static function body(array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1763586245123, 'order_no' => '1567469', 'status' => 'completed', 'placed_at' => '2026-11-19T21:04:05Z',
            'paid_at' => '2026-11-19T21:05:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => '8812345', 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1299, 'discount_inc' => 200, 'cancelled' => false, 'refund_inc' => 0,
                'free' => false]],
            'delivery' => ['method_id' => '12', 'method' => 'Royal Mail Tracked 24(1-2 Working Days)', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0,
            'grand_inc' => 1498, 'parcels' => [self::parcel()]];
    }

    private static function field(array $body): ?string
    {
        try {
            SnapshotBody::parse($body, self::now());
        } catch (CwException $e) {
            self::assertSame(['bad_snapshot', 400], [$e->errorCode, $e->httpStatus]);
            return $e->detail['field'] ?? null;
        }
        return null;
    }

    /** F12 (b-HIGH1): a parcel ref is the shop's odl:<id> or orl:<id> and nothing else; a person's name there is refused, never stored. */
    public function testAParcelRefIsOnlyAShopParcelOrReturnLabel(): void
    {
        foreach (['odl:991122', 'orl:55', 'ODL:7'] as $ok) {
            self::assertNull(self::field(self::body(['parcels' => [self::parcel(['parcel_ref' => $ok])]])), $ok);
        }
        foreach (['Jane.Smith', 'odl:Jane', 'odl:12a', 'orl:', 'P1', 'odl:1:2'] as $bad) {
            self::assertSame('parcels[0].parcel_ref', self::field(self::body(['parcels' => [self::parcel(['parcel_ref' => $bad])]])), $bad);
        }
    }

    /**
     * F12 (b-HIGH1): a delivery method or service name is kept only when its words are a carrier's service words AND it names a carrier or a
     * service: "12 Preston Road" (an address) and "12 Preston" are never stored; every name the real day files hold is kept as it is.
     */
    public function testADeliveryNameThatCouldBeAnAddressIsNeverKept(): void
    {
        foreach (['12 Preston Road', '12 Preston', 'Preston', '5 Highlands', 'Northern Ireland', 'Road 12'] as $address) {
            self::assertNull(SnapshotBody::serviceName($address), $address);
            $b = SnapshotBody::parse(self::body(['delivery' => ['method_id' => '12', 'method' => $address, 'charged_inc' => 0, 'refund_inc' => 0],
                'parcels' => [self::parcel(['service' => $address])]]), self::now());
            self::assertSame([null, ''], [$b['delivery']['method'], $b['parcels'][0]['service']], $address);
        }
        // The names of the real Vape and Go day files (17-18 May 2026) and the return label (CONTRACT change 4).
        foreach (['DPD', 'DPD 2 Day Service', 'DPD Pickup (2 day Service)', 'In Store Collection Preston', 'Royal Mail International Tracked',
            'Royal Mail Special Next Day Delivery', 'Royal Mail Tracked 24(1-2 Working Days)', 'Royal Mail Tracked 48 (2-4 Working Days)', 'EVRI International',
            'Return label'] as $real) {
            self::assertSame($real, SnapshotBody::serviceName($real), $real);
        }
        self::assertNotContains('road', SnapshotBody::SERVICE_WORDS, 'a street word is no service word');
    }

    /** F13 (b-M2): parent_ref must be sent (null when the order is no re-send), as paid_at: a body without it is refused, never read as "no parent". */
    public function testParentRefMustBeSent(): void
    {
        $b = self::body();
        unset($b['parent_ref']);
        self::assertSame('parent_ref', self::field($b));
        self::assertNull(self::field(self::body(['parent_ref' => null])), 'an explicit null: no parent');
        self::assertNull(self::field(self::body(['parent_ref' => '1567000'])));
    }

    /**
     * F16 (c-M1): a £12 sale returned and restocked, refunded £6 on the line and £6 on the order: the line keeps its balance after its own
     * refund (£6 with VAT, £5 without), its cost is 0, and the order refund comes off once: the order sold £0 (it showed -£5).
     */
    public function testALineRefundAndAnOrderRefundOnARestockedUnitComeOffOnce(): void
    {
        $l = ['qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'refund_inc' => 600, 'cancelled' => false, 'free' => false];
        $m = ProfitMath::line($l, 1, true, 2000);
        self::assertSame(['counts' => true, 'units' => 1, 'sale_inc' => 600, 'sale_ex' => 500], $m, 'the line\'s balance after its own refund');
        self::assertSame(0, ProfitMath::lineCost(true, 1, 30000, true), 'a unit back in stock costs nothing');
        $row = ['removed_at' => null, 'price_inc' => 1200, 'discount_inc' => 0, 'refund_inc' => 600, 'units' => 1, 'sale_inc' => $m['sale_inc'], 'sale_ex' => $m['sale_ex'],
            'cost' => 0, 'cost_source' => 'cw_cost', 'restocked' => 1];
        $t = OrderSnapshots::totals(['parent_ref' => null, 'order_refund_inc' => 600, 'delivery' => ['method_id' => null, 'method' => null, 'charged_inc' => 0,
            'refund_inc' => 0], 'grand_inc' => 1200], ['l1' => $row], 2000);
        self::assertSame([0, 0, 500], [$t['sale_inc'], $t['sale_ex'], $t['order_refund_ex']], 'the order refund taken off once: £0, never -£5');
        // Refunded more than its price on the line: never below 0.
        self::assertSame(0, ProfitMath::line(['refund_inc' => 1500] + $l, 1, true, 2000)['sale_inc']);
        // Refunded in full on the line: nothing, as before.
        self::assertSame(0, ProfitMath::line(['refund_inc' => 1200] + $l, 1, true, 2000)['sale_ex']);
    }

    /** F17 (c-L3): a £40 million goods line and a £40 million charge: the shares are exact (the pence product passes PHP's integers). */
    public function testAChargeOfFortyMillionSpreadsWithoutOverflow(): void
    {
        self::assertSame([7 => 4_000_000_000], ProfitMath::chargeShares(4_000_000_000, [7 => 4_000_000_000], [7 => 1]));
        self::assertSame([1 => 3_000_000_000, 2 => 1_000_000_000], ProfitMath::spread(4_000_000_000, [1 => 3_000_000_000, 2 => 1_000_000_000]));
        // The penny rule kept: the pennies left over go to the largest remainders.
        self::assertSame([1 => 34, 2 => 33, 3 => 33], ProfitMath::spread(100, [1 => 1, 2 => 1, 3 => 1]));
        self::assertSame([1 => -3_000_000_001, 2 => -1_000_000_000], ProfitMath::spread(-4_000_000_001, [1 => 3_000_000_000, 2 => 1_000_000_000]));
    }
}
