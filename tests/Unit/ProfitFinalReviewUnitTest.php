<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Api\Request;
use CW\Auth\Permissions;
use CW\CwException;
use CW\Profit\ProfitMath;
use CW\Profit\SnapshotBody;
use PHPUnit\Framework\TestCase;

/**
 * The final review of 6d59eda (build/A/codex_final_astra_{a,b,c}.txt), the pure parts, each shown failing first with the reviewers' numbers:
 * no free text kept from a snapshot (b-HIGH2); a shop line is one unit (c-M1); a line id in another case is the same line (b-M); paid_at
 * must be sent (b-M); `lines: {}` is not a list (b-M); a time without its zone is refused (b-LOW); the snapshot's 256 KB cap before the body
 * is read (b-LOW1); a VAT of "5.0000" is 5% (c-M5); freight on goods worth nothing reaches them (c-M4).
 */
final class ProfitFinalReviewUnitTest extends TestCase
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

    public function testNoFreeTextIsEverKept(): void
    {
        // The shapes the connector really sends are kept as they are.
        $b = SnapshotBody::parse(self::body(['order_no' => 'VPG1567469', 'parcels' => [self::parcel(), self::parcel(['parcel_ref' => 'odl:2', 'carrier' => 'dpd',
            'service_code' => '12', 'service' => 'DPD Pickup (2 day Service)']), self::parcel(['parcel_ref' => 'orl:55', 'service_code' => 'TSS', 'service' => 'Return label',
            'status' => 'created']), self::parcel(['parcel_ref' => 'odl:3', 'carrier' => 'in_store', 'service_code' => '', 'service' => 'In Store Collection Preston']),
            self::parcel(['parcel_ref' => 'odl:4', 'carrier' => 'evri', 'service' => 'EVRI International'])]]), self::now());
        self::assertSame('VPG1567469', $b['order_no']);
        self::assertSame('Royal Mail Tracked 24(1-2 Working Days)', $b['delivery']['method']);
        self::assertSame('12', $b['delivery']['method_id']);
        self::assertSame(['Royal Mail Tracked 48 (2-4 Working Days)', 'DPD Pickup (2 day Service)', 'Return label', 'In Store Collection Preston', 'EVRI International'],
            array_column($b['parcels'], 'service'));
        self::assertSame(['royal_mail', 'dpd', 'royal_mail', 'in_store', 'evri'], array_column($b['parcels'], 'carrier'));
        self::assertSame(['TPL48HV', '12', 'TSS', '', 'TPL48HV'], array_column($b['parcels'], 'service_code'));
        self::assertSame('1567469', SnapshotBody::parse(self::body(), self::now())['order_no']);

        // Anything a connector could map a customer's details into is never stored: nulled, emptied or "other".
        $b = SnapshotBody::parse(self::body(['order_no' => 'jane@example.com', 'status' => 'Call Jane Smith', 'gateway' => 'jane smith',
            'delivery' => ['method_id' => '07700 900123', 'method' => 'Jane Smith, 1 High Street, PR1 2AB', 'charged_inc' => 399, 'refund_inc' => 0],
            'parcels' => [self::parcel(['carrier' => 'jane_smith_courier', 'service_code' => '07700900123', 'service' => 'Leave with Jane next door 07700 900123',
                'status' => 'left with neighbour'])]]), self::now());
        self::assertSame('', $b['order_no'], 'an order number is a number');
        self::assertSame('other', $b['status']);
        self::assertSame('other', $b['gateway']);
        self::assertNull($b['delivery']['method_id']);
        self::assertNull($b['delivery']['method'], 'a name and an address: not a delivery method');
        self::assertSame(['other', '', '', 'other'], [$b['parcels'][0]['carrier'], $b['parcels'][0]['service_code'], $b['parcels'][0]['service'],
            $b['parcels'][0]['status']]);
        $json = json_encode($b, JSON_THROW_ON_ERROR);
        foreach (['Jane', 'jane', '07700', '900123', 'High', 'PR1', '@'] as $bad) {
            self::assertStringNotContainsString($bad, $json, "nothing of the customer's details is kept ({$bad})");
        }
        // Ids are codes: a text with spaces or an @ is refused (they are keys, never kept as something else).
        self::assertSame('lines[0].line_id', self::field(self::body(['lines' => [['line_id' => 'Jane Smith'] + self::body()['lines'][0]]])));
        self::assertSame('lines[0].variant_id', self::field(self::body(['lines' => [['variant_id' => 'jane@example.com'] + self::body()['lines'][0]]])));
        self::assertSame('parcels[0].parcel_ref', self::field(self::body(['parcels' => [self::parcel(['parcel_ref' => 'odl: 7 Jane'])]])));
        self::assertSame('parent_ref', self::field(self::body(['parent_ref' => 'Jane Smith'])));
    }

    public function testAShopLineIsOneUnit(): void
    {
        self::assertSame('lines[0].qty', self::field(self::body(['lines' => [['qty' => 2] + self::body()['lines'][0]]])),
            'the shops send one orders_items row per unit: a qty of 2 is refused (a restock of one of two units would erase the whole line)');
        self::assertNull(self::field(self::body()));
    }

    public function testALineIdInAnotherCaseIsTheSameLine(): void
    {
        $l = self::body()['lines'][0];
        self::assertSame('lines[1].line_id', self::field(self::body(['lines' => [['line_id' => 'L1'] + $l, ['line_id' => 'l1'] + $l]])),
            'L1 and l1 are one row to the database: refused, never counted twice in the order and once in its lines');
        self::assertSame('parcels[1].parcel_ref', self::field(self::body(['parcels' => [self::parcel(['parcel_ref' => 'ODL:1']), self::parcel(['parcel_ref' => 'odl:1'])]])));
    }

    public function testPaidAtMustBeSent(): void
    {
        $b = self::body();
        unset($b['paid_at']);
        self::assertSame('paid_at', self::field($b), 'a body without paid_at is refused, never read as "not paid" (it would take a paid £100 sale off its day)');
        self::assertNull(self::field(self::body(['paid_at' => null])), 'null: not paid');
    }

    public function testListsAreLists(): void
    {
        $refused = static function (string $json): ?string {
            try {
                SnapshotBody::checkJsonShapes($json);
            } catch (CwException $e) {
                return $e->detail['field'] ?? null;
            }
            return null;
        };
        self::assertSame('lines', $refused('{"v":1,"lines":{}}'), '{} is not an empty list: it would take every line off the order');
        self::assertSame('parcels', $refused('{"v":1,"lines":[],"parcels":{}}'));
        self::assertSame('delivery', $refused('{"v":1,"lines":[],"delivery":[]}'));
        self::assertNull($refused('{"v":1,"lines":[],"parcels":[],"delivery":null}'));
        self::assertNull($refused((string) json_encode(self::body())));
    }

    public function testATimeWithoutItsZoneIsRefused(): void
    {
        self::assertSame('paid_at', self::field(self::body(['paid_at' => '2026-07-15 23:30:00'])),
            'a London time without its zone would be read as UTC and move the sale to 16 July');
        self::assertSame('placed_at', self::field(self::body(['placed_at' => '2026-11-19T21:04:05'])));
        self::assertNull(self::field(self::body(['paid_at' => '2026-11-19T21:05:00+01:00'])));
    }

    public function testTheSnapshotCapIsKeptBeforeTheBodyIsRead(): void
    {
        $asked = [];
        $reader = static function (int $max) use (&$asked): string {
            $asked[] = $max;
            return str_repeat('x', min($max, 7 * 1024 * 1024));
        };
        $declared = Request::streamed('PUT', '/v1/orders/1/snapshot', ['Content-Length' => (string) (7 * 1024 * 1024)], $reader);
        try {
            $declared->body(SnapshotBody::MAX_BYTES);
            self::fail('a declared 7 MB body is refused');
        } catch (CwException $e) {
            self::assertSame(['body_too_large', 413], [$e->errorCode, $e->httpStatus]);
        }
        self::assertSame([], $asked, 'refused before a byte of it is read');
        $unknown = Request::streamed('PUT', '/v1/orders/1/snapshot', [], $reader);
        try {
            $unknown->body(SnapshotBody::MAX_BYTES);
            self::fail('a 7 MB body without a length is refused');
        } catch (CwException $e) {
            self::assertSame(413, $e->httpStatus);
        }
        self::assertSame([SnapshotBody::MAX_BYTES + 1], $asked, 'never more than 256 KB + 1 byte read');
        $ok = Request::streamed('PUT', '/v1/orders/1/snapshot', [], static fn (int $max): string => '{"v":1}');
        self::assertSame('{"v":1}', $ok->body(SnapshotBody::MAX_BYTES));
        self::assertSame('{"v":1}', $ok->body(), 'read once, the same body after');
    }

    public function testAVatOfFivePointZeroZeroIsFivePercent(): void
    {
        self::assertSame(500, ProfitMath::vatBp('5.0000'), 'the settings screen keeps a decimal as typed: £126 is £120 ex VAT, never £105');
        self::assertSame(1750, ProfitMath::vatBp('17.50'));
        self::assertSame(2000, ProfitMath::vatBp('20'));
        self::assertNull(ProfitMath::vatBp('5.125'), 'three decimals that matter: not a percent CW keeps');
        self::assertNull(ProfitMath::vatBp('100.01'));
    }

    public function testFreightOnGoodsWorthNothingReachesThem(): void
    {
        self::assertSame([1 => 2000], ProfitMath::chargeShares(2000, [1 => 0], [1 => 10]),
            '10 free samples and £20 freight: the £20 is on the samples (£2 a unit), never lost');
        self::assertSame([1 => 500, 2 => 1500], ProfitMath::chargeShares(2000, [1 => 0, 2 => 0], [1 => 5, 2 => 15]), 'by units when every line is worth nothing');
        self::assertSame([1 => 1000, 2 => 1000], ProfitMath::chargeShares(2000, [1 => 300, 2 => 300], [1 => 1, 2 => 99]), 'by value otherwise, as before');
    }

    public function testFinanceOnlyLooks(): void
    {
        self::assertContains('acting_job', Permissions::problems('doc.TRF.post', ['finance', 'stock_controller']),
            'the Roles and permissions page refuses to give Finance a task that changes something (a-M7)');
        self::assertNotContains('finance', Permissions::sanitise('doc.TRF.post', ['finance', 'stock_controller']),
            'a stored grant of it is dropped when it is read');
        self::assertSame([], Permissions::problems('profit.view', ['finance']));
    }
}
