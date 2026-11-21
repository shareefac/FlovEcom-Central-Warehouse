<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\ProfitMath;
use CW\Profit\SnapshotBody;
use PHPUnit\Framework\TestCase;

/**
 * The third final review of bc7e248 (build/A/codex_final3_astra_{a,b,c}.txt; final2/FIX_BRIEF_A4.md H4, H5; docs/decisions.md PR154), shown
 * failing first on bc7e248: a parcel's service code is kept only when it is 1-3 digits (DPD's and the in-store codes) or exactly one of Royal
 * Mail's codes the real parcels hold, else "" (never a refusal): a name, a postcode or a word mapped into it is never stored (all three reviews'
 * HIGH); the ids are canonical integers, as the connector's (int) cast writes them: no leading zero ("0" only for a variant), at most 19
 * digits, so a phone number is never an id (b-HIGH2).
 */
final class ProfitFinalReview3UnitTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 00:00:00', new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private static function line(array $over = []): array
    {
        return $over + ['line_id' => '8812345', 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1299, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    /** @return array<string, mixed> */
    private static function parcel(array $over = []): array
    {
        return $over + ['parcel_ref' => 'odl:991122', 'tracking_no' => null, 'carrier' => 'royal_mail', 'service_code' => 'TPL48HV', 'service' => 'Royal Mail Tracked 48',
            'status' => 'generated', 'label_at' => '2026-11-19T22:00:00Z', 'shipped_at' => null, 'manual' => false, 'partial' => false];
    }

    /** @return array<string, mixed> */
    private static function body(array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1763586245123, 'order_no' => '1567469', 'status' => 'completed', 'placed_at' => '2026-11-19T21:04:05Z',
            'paid_at' => '2026-11-19T21:05:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'sub_of' => null, 'lines' => [self::line()],
            'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 1299, 'parcels' => []];
    }

    private static function code(string $code, string $carrier = 'royal_mail'): string
    {
        $b = SnapshotBody::parse(self::body(['parcels' => [self::parcel(['service_code' => $code, 'carrier' => $carrier])]]), self::now());
        return (string) $b['parcels'][0]['service_code'];
    }

    /** The field a body is refused for (400 bad_snapshot), or null when it is taken. */
    private static function refused(array $body): ?string
    {
        try {
            SnapshotBody::parse($body, self::now());
        } catch (CwException $e) {
            self::assertSame(['bad_snapshot', 400], [$e->errorCode, $e->httpStatus], $e->getMessage());
            return $e->detail['field'] ?? null;
        }
        return null;
    }

    private static function refRefused(string $ref): ?string
    {
        try {
            SnapshotBody::orderRef($ref, 'ref');
        } catch (CwException $e) {
            return $e->detail['field'] ?? null;
        }
        return null;
    }

    /** H4: "Jane.Smith", "SW1A1AA", "jane" (and any other code) are "", never a refusal; Royal Mail's codes and DPD's digits are kept. */
    public function testAServiceCodeIsOneOfTheCarriersCodesOrNothing(): void
    {
        foreach (['Jane.Smith', 'SW1A1AA', 'jane', 'PR12AB', 'tpl48hv', 'TPL48', 'TPN', '1234', 'x', 'ROYAL-MAIL', 'TSS1', 'SD1.'] as $bad) {
            self::assertSame('', self::code($bad), "{$bad}: not a carrier's code, never stored");
        }
        foreach (['TPL48HV', 'TPM24HV', 'SD1', 'TSS', 'TRN24', 'OTC'] as $rm) {
            self::assertSame($rm, self::code($rm), "Royal Mail's {$rm}");
        }
        foreach (['68', '72', '76', '11', '80', '0'] as $digits) {
            self::assertSame($digits, self::code($digits, 'dpd'), "DPD / in store {$digits}");
        }
        self::assertSame(['TPL48HV', 'TPM24HV', 'SD1', 'TSS', 'TRN24', 'OTC'], SnapshotBody::ROYAL_MAIL_CODES);
    }

    /** H5: a phone number with its leading zero ("07700900123") is no id anywhere; "40123" and a variant "0" are; 19 digits at most. */
    public function testTheIdsAreCanonicalIntegers(): void
    {
        $phone = '07700900123';
        self::assertSame('ref', self::refRefused($phone), 'order ref');
        self::assertSame('lines[0].line_id', self::refused(self::body(['lines' => [self::line(['line_id' => $phone])]])), 'line_id');
        self::assertSame('lines[0].variant_id', self::refused(self::body(['lines' => [self::line(['variant_id' => $phone])]])), 'variant_id');
        self::assertSame('parent_ref', self::refused(self::body(['parent_ref' => $phone])), 'parent_ref');
        self::assertSame('sub_of', self::refused(self::body(['sub_of' => $phone])), 'sub_of');
        self::assertSame('ref', self::refRefused('proto1-0123'), 'with a proto prefix too');
        foreach (['0', '00', '0123'] as $ref) {
            self::assertSame('ref', self::refRefused($ref), "order ref {$ref}");
            self::assertSame('lines[0].line_id', self::refused(self::body(['lines' => [self::line(['line_id' => $ref])]])), "line_id {$ref}");
        }
        self::assertSame('lines[0].variant_id', self::refused(self::body(['lines' => [self::line(['variant_id' => '00'])]])), 'variant 00');
        self::assertSame('ref', self::refRefused(str_repeat('9', 20)), '20 digits: more than a 64-bit id');
        // Taken: the connector's own shapes.
        self::assertNull(self::refRefused('1567469'));
        self::assertNull(self::refRefused('proto1-1567469'));
        self::assertNull(self::refRefused(str_repeat('9', 19)));
        self::assertNull(self::refused(self::body(['lines' => [self::line(['variant_id' => '40123'])]])));
        self::assertNull(self::refused(self::body(['lines' => [self::line(['variant_id' => '0'])]])), 'a variant 0: a row with none');
        self::assertNull(self::refused(self::body(['lines' => [self::line(['variant_id' => 0, 'line_id' => 8812345])]])), 'whole numbers as numbers');
        self::assertNull(self::refused(self::body(['parent_ref' => '1567000', 'sub_of' => 'proto1-1567001'])));
    }

    /** H1's rule (ProfitMath::lowestLayer): a later delivery's rows are left out of the walk; every other row counts; the fewest at an operation's end. */
    public function testALayerIsTheFewestOnHandLessWhatLaterDeliveriesBrought(): void
    {
        // 50 of the first delivery here; the second's 50 come (left out); 50 sold: none of the first delivery is left.
        self::assertSame(0, ProfitMath::lowestLayer(50, [['qty' => 50, 'op' => 'd2', 'later' => true], ['qty' => -50, 'op' => 'k1', 'later' => false]]));
        // ... the second delivery's own walk: 100 after it, 50 sold: 50.
        self::assertSame(50, ProfitMath::lowestLayer(100, [['qty' => -50, 'op' => 'k1', 'later' => false]]));
        // A count back up after a sale-out never brings the delivery's units back (the fewest is kept).
        self::assertSame(0, ProfitMath::lowestLayer(10, [['qty' => -10, 'op' => 'k1'], ['qty' => 10, 'op' => 'k2']]));
        // A walk starting inside its operation counts only the operation's end.
        self::assertSame(10, ProfitMath::lowestLayer(10, [['qty' => 5, 'op' => 'd1', 'later' => true]], false));
    }

    // ---- the Codex check of this fix round (r13, build/A/codex_r13_{a,b,c}.txt), shown failing first on 9c15538 ----------------------------

    /** r13 b (H4 PARTLY): a service code longer than 64 characters or with a control character is "" too, never a 400. */
    public function testAnyServiceCodeOutsideTheListIsNothingNeverARefusal(): void
    {
        foreach ([str_repeat('A', 65), "TSS\x01", str_repeat('Jane Smith 1 High Street ', 10)] as $code) {
            $b = SnapshotBody::parse(self::body(['parcels' => [self::parcel(['service_code' => $code])]]), self::now());
            self::assertSame('', $b['parcels'][0]['service_code'], 'never stored, never refused: ' . substr($code, 0, 20));
        }
        $b = SnapshotBody::parse(self::body(['parcels' => [self::parcel(['service_code' => 68, 'carrier' => 'dpd'])]]), self::now());
        self::assertSame('68', $b['parcels'][0]['service_code'], 'a whole number as a number');
    }

    /**
     * r13 b (H4 PARTLY, HIGH): a delivery method or a parcel's service with a number that is no service's number (next to none of tracked, day,
     * days, working, hour, hours, hr, hrs, class, service, nor part of such a range) is never kept: "Collection: 12 The Point, Preston" (a
     * house number and a street) is null; every real name stays.
     */
    public function testANumberInAServiceNameIsAServicesNumberOrTheNameIsNotKept(): void
    {
        foreach (['Collection: 12 The Point, Preston', 'Collection 12 The Point', 'Collect 4 Home Office', 'Delivery 12 Preston', 'Tracked Point 12',
            'Royal Mail 12 Shop', 'In Store Collection 7 Preston'] as $address) {
            self::assertNull(SnapshotBody::serviceName($address), $address);
            $b = SnapshotBody::parse(self::body(['delivery' => ['method_id' => '12', 'method' => $address, 'charged_inc' => 0, 'refund_inc' => 0],
                'parcels' => [self::parcel(['service' => $address])]]), self::now());
            self::assertSame([null, ''], [$b['delivery']['method'], $b['parcels'][0]['service']], $address);
        }
        foreach (['DPD', 'DPD 2 Day Service', 'DPD Pickup (2 day Service)', 'In Store Collection Preston', 'Royal Mail International Tracked',
            'Royal Mail Special Next Day Delivery', 'Royal Mail Tracked 24(1-2 Working Days)', 'Royal Mail Tracked 48 (2-4 Working Days)', 'EVRI International',
            'Return label', 'Royal Mail Tracked 48', 'Tracked 48', 'Click and Collect Preston', 'Royal Mail 1st Class', 'Royal Mail 2nd Class Signed For',
            '48 Hour Tracked', 'DPD Next Day (1-2 working days)'] as $real) {
            self::assertSame($real, SnapshotBody::serviceName($real), $real);
        }
    }
}
