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

    /**
     * The forms a barcode is looked up in on another record (the bulk "barcode elsewhere" rule and the variant page's list): as it
     * is, its key, and the key padded with zeros to every length up to 14 digits, so a code is found whichever way it was typed.
     */
    public function testTheFormsABarcodeIsLookedUpIn(): void
    {
        self::assertSame(['5056168800073', '05056168800073'], Gtin::forms(['5056168800073']), 'a 13-digit code is also its 14-digit form');
        self::assertSame(['05056168800073', '5056168800073'], Gtin::forms(['05056168800073']), 'and the other way round');
        self::assertSame(['012345678905', '12345678905', '0012345678905', '00012345678905'], Gtin::forms(['012345678905']), 'every padding of the key up to 14 digits');
        self::assertContains('0012345678905', Gtin::forms(['012345678905']));
        self::assertContains('012345678905', Gtin::forms(['0012345678905']), 'two paddings of one code find each other');
        self::assertSame(['96385074', '096385074', '0096385074', '00096385074', '000096385074', '0000096385074', '00000096385074'], Gtin::forms(['96385074']));
        self::assertSame(['ABC-1', '1', '01', '001', '0001', '00001', '000001', '0000001', '00000001', '000000001', '0000000001', '00000000001', '000000000001', '0000000000001',
            '00000000000001'], Gtin::forms(['ABC-1']), 'a code that is not a number keeps its own text, and its digits as a key');
        self::assertSame(['no digits'], Gtin::forms(['no digits']));
        self::assertSame([], Gtin::forms([]));
        self::assertSame([], Gtin::forms([null, ['x'], 1.5, true]), 'only texts and whole numbers are barcodes');
        self::assertSame(['5012345678900', '05012345678900'], Gtin::forms([5012345678900, '5012345678900']), 'each form once');
        self::assertSame(['123456789012345678'], Gtin::forms(['123456789012345678']), 'a code longer than 14 digits is not padded');
        self::assertCount(100, Gtin::forms(array_map(static fn (int $i): string => (string) (10000000000000 + $i), range(1, 100))), 'every barcode of a variant, not the first twenty');
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
