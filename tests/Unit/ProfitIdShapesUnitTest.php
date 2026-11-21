<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\SnapshotBody;
use PHPUnit\Framework\TestCase;

/**
 * The Codex check of the fix round (r11, build/A/codex_r11_astra.txt, F12 PARTLY, HIGH, privacy; final2/FIX_BRIEF_A3.md G1), shown failing
 * first on f2b6230: the four ids of a snapshot take only the shapes the connector really sends (0.4.17: README of build/A, "The four ids' real
 * shapes"), so a person's name or an address can never be stored as a key: the order ref (the PUT path's {ref}, a bulk line's "ref") and a
 * line_id are the shop's number with a proto copy's store prefix at most, a variant_id is digits, parent_ref and sub_of are an order's number
 * from 1 (with the prefix at most) or null. The order number is digits. A value outside its shape is refused as a bad code was: 400
 * bad_snapshot naming the field.
 */
final class ProfitIdShapesUnitTest extends TestCase
{
    /** What a connector could map a customer's details into. */
    private const WORDS = ['Jane.Smith', 'jane.smith', '12 Preston Road'];

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 00:00:00', new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> one line of the connector's shape */
    private static function line(array $over = []): array
    {
        return $over + ['line_id' => '8812345', 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1299, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    /** @return array<string, mixed> the connector's body (README "The four ids' real shapes") */
    private static function body(array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1763586245123, 'order_no' => '1567469', 'status' => 'completed', 'placed_at' => '2026-11-19T21:04:05Z',
            'paid_at' => '2026-11-19T21:05:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'sub_of' => null, 'lines' => [self::line()],
            'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 1299, 'parcels' => []];
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

    private static function refRefused(mixed $ref): ?string
    {
        try {
            SnapshotBody::orderRef($ref, 'ref');
        } catch (CwException $e) {
            self::assertSame(['bad_snapshot', 400], [$e->errorCode, $e->httpStatus], $e->getMessage());
            return $e->detail['field'] ?? null;
        }
        return null;
    }

    /** "Jane.Smith", "jane.smith", "12 Preston Road" are refused as each of the four ids, naming the field. */
    public function testAPersonsNameOrAnAddressIsRefusedAsEachId(): void
    {
        foreach (self::WORDS as $w) {
            self::assertSame('ref', self::refRefused($w), "order ref {$w}");
            self::assertSame('lines[0].line_id', self::refused(self::body(['lines' => [self::line(['line_id' => $w])]])), "line_id {$w}");
            self::assertSame('lines[0].variant_id', self::refused(self::body(['lines' => [self::line(['variant_id' => $w])]])), "variant_id {$w}");
            self::assertSame('parent_ref', self::refused(self::body(['parent_ref' => $w])), "parent_ref {$w}");
            self::assertSame('sub_of', self::refused(self::body(['sub_of' => $w])), "sub_of {$w}");
        }
    }

    /** The connector's real shapes are taken: the shop's numbers, with a proto copy's prefix where the connector puts one. */
    public function testTheConnectorsRealShapesAreTaken(): void
    {
        foreach (['1567469', 'proto1-1567469', 1567469] as $ref) {
            self::assertNull(self::refRefused($ref), 'order ref ' . $ref);
            self::assertNull(self::refused(self::body(['lines' => [self::line(['line_id' => $ref])]])), 'line_id ' . $ref);
            self::assertNull(self::refused(self::body(['parent_ref' => $ref])), 'parent_ref ' . $ref);
            self::assertNull(self::refused(self::body(['sub_of' => $ref])), 'sub_of ' . $ref);
        }
        self::assertSame('proto1-1567469', SnapshotBody::orderRef('proto1-1567469'));
        foreach (['40123', 40123, '0'] as $v) {
            self::assertNull(self::refused(self::body(['lines' => [self::line(['variant_id' => $v])]])), 'variant_id ' . $v);
        }
        $b = SnapshotBody::parse(self::body(['parent_ref' => null, 'sub_of' => null]), self::now());
        self::assertSame([null, null, '8812345', '40123'], [$b['parent_ref'], $b['sub_of'], $b['lines'][0]['line_id'], $b['lines'][0]['variant_id']]);
        $b = SnapshotBody::parse(self::body(['parent_ref' => 'proto1-1567000', 'sub_of' => 1567001]), self::now());
        self::assertSame(['proto1-1567000', '1567001'], [$b['parent_ref'], $b['sub_of']]);
    }

    /** Close to a real shape is not the shape: a code of letters, a variant with a prefix, a parent 0, a prefix in capitals or too long. */
    public function testOnlyTheRealShapesAreTaken(): void
    {
        foreach (['ORD1', 'L1', 'VPG42', 'Proto1-1567469', 'proto1_1567469', 'abcdefghijklm-1', '1567469-', ' 1567469', 'a.b', '1:2'] as $ref) {
            self::assertSame('ref', self::refRefused($ref), "order ref {$ref}");
            self::assertSame('lines[0].line_id', self::refused(self::body(['lines' => [self::line(['line_id' => $ref])]])), "line_id {$ref}");
        }
        foreach (['V1', 'proto1-40123', '40123a', '-1', '4012.3'] as $v) {
            self::assertSame('lines[0].variant_id', self::refused(self::body(['lines' => [self::line(['variant_id' => $v])]])), "variant_id {$v}");
        }
        foreach (['0', '0123', 'proto1-0', 'P', 'C1'] as $p) {
            self::assertSame('parent_ref', self::refused(self::body(['parent_ref' => $p])), "parent_ref {$p}");
            self::assertSame('sub_of', self::refused(self::body(['sub_of' => $p])), "sub_of {$p}");
        }
    }

    /** The order number the customer sees is digits (all 65,363 real orders of 1 Oct - 19 Nov): anything else is not kept (""), as before. */
    public function testTheOrderNumberIsDigits(): void
    {
        self::assertSame('1567469', SnapshotBody::parse(self::body(), self::now())['order_no']);
        foreach (['Jane1', 'VPG1567469', 'jane.smith', '12 Preston Road', '0'] as $no) {
            self::assertSame('', SnapshotBody::parse(self::body(['order_no' => $no]), self::now())['order_no'], $no);
        }
    }
}
