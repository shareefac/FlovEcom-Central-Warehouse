<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Catalogue\ItemBarcodes;
use CW\CwException;
use CW\Matching\Gtin;
use PHPUnit\Framework\TestCase;

/**
 * GTIN check digits (IM3; plan §7: a barcode is used only with a valid check digit): EAN-8, UPC-A (GTIN-12), EAN-13, GTIN-14,
 * leading zeros, what a person is told when only the check digit is wrong, and what the item page accepts (ItemBarcodes::parse).
 */
final class GtinTest extends TestCase
{
    /** Published examples: EAN-8, UPC-A, EAN-13, GTIN-14, and an EAN-13 with a check digit of 0. */
    private const VALID = ['96385074', '036000291452', '4006381333931', '10012345678902', '5012345678900', '5060999888770'];

    public function testValidCodesOfEveryLength(): void
    {
        foreach (self::VALID as $code) {
            $c = Gtin::classify($code);
            self::assertTrue($c['usable'], $code);
            self::assertSame('ok', $c['reason'], $code);
            self::assertTrue(Gtin::checkDigitOk(ltrim($code, '0')), $code);
            self::assertSame((int) substr($code, -1), Gtin::checkDigit(substr($code, 0, -1)), "{$code}: the computed check digit");
        }
        self::assertSame('36000291452', Gtin::classify('036000291452')['key'], 'the key drops leading zeros');
        self::assertSame('00036000291452', Gtin::classify('036000291452')['gtin14']);
        self::assertSame(Gtin::key('0036000291452'), Gtin::key('36000291452'), 'a scanner adding zeros finds the same key');
    }

    public function testEveryWrongCheckDigitIsCaught(): void
    {
        foreach (self::VALID as $code) {
            $good = (int) substr($code, -1);
            for ($d = 0; $d <= 9; $d++) {
                if ($d !== $good) {
                    $c = Gtin::classify(substr($code, 0, -1) . $d);
                    self::assertFalse($c['usable'], "{$code} with {$d}");
                    self::assertSame('bad_check_digit', $c['reason']);
                }
            }
        }
        self::assertSame('bad_check_digit', Gtin::classify('5060999888777')['reason']);
    }

    public function testShapesThatAreNotGtins(): void
    {
        self::assertSame('too_short', Gtin::classify('1746')['reason'], 'a shop code');
        self::assertSame('too_short', Gtin::classify('0000001746')['reason'], 'leading zeros do not make it long');
        self::assertSame('too_long', Gtin::classify('123456789012345')['reason']);
        self::assertSame('empty', Gtin::classify('Black Grey')['reason']);
        self::assertSame('empty', Gtin::classify(null)['reason']);
        self::assertSame('empty', Gtin::classify('')['reason']);
        foreach (['', '12345678901234', 'abc', '1x'] as $bad) {
            try {
                Gtin::checkDigit($bad);
                self::fail("checkDigit({$bad}) should throw");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testWhatTheItemPageAccepts(): void
    {
        self::assertSame('4006381333931', ItemBarcodes::parse(' 4006 3813 3393 1 '));
        self::assertSame('36000291452', ItemBarcodes::parse('0-36000-29145-2'));
        self::assertSame('96385074', ItemBarcodes::parse('96385074'));
        $e = self::refused(static fn () => ItemBarcodes::parse('4006381333932'));
        self::assertStringContainsString('the last one would be 1', $e->getMessage());
        foreach (['1746', 'ABC123', '', '12345678901234567', 'CW-000123'] as $bad) {
            self::assertSame('bad_barcode', self::refused(static fn () => ItemBarcodes::parse($bad))->errorCode, $bad);
        }
    }

    private static function refused(callable $fn): CwException
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame(422, $e->httpStatus);
            return $e;
        }
        self::fail('expected a CwException');
    }
}
