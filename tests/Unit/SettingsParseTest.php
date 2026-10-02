<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Settings;
use PHPUnit\Framework\TestCase;

/** Settings::parse (I38): what bin/settings.php accepts for each type, and every kind of bad value (400 bad_value). */
final class SettingsParseTest extends TestCase
{
    public function testEveryType(): void
    {
        self::assertSame(3, Settings::parse('int', '3'));
        self::assertSame(-12, Settings::parse('int', ' -12 '));
        self::assertSame(999_999_999, Settings::parse('int', '999999999'));
        self::assertNull(Settings::parse('int', ''), 'empty: not set');
        self::assertSame('0.50', Settings::parse('decimal', '0.50'), 'a decimal stays a string: never a float');
        self::assertSame('12345678.123456', Settings::parse('decimal', '12345678.123456'));
        self::assertSame('10', Settings::parse('decimal', '10'));
        self::assertNull(Settings::parse('decimal', ''));
        self::assertTrue(Settings::parse('bool', 'true'));
        self::assertFalse(Settings::parse('bool', ' FALSE '));
        self::assertSame('Vape and Go Ltd', Settings::parse('string', '  Vape and Go Ltd '));
        self::assertSame('', Settings::parse('string', ''));
        self::assertSame(str_repeat('é', 255), Settings::parse('string', str_repeat('é', 255)), 'characters, not bytes');
        self::assertSame("Unit 1\nSome Road\nLondon", Settings::parse('text', "Unit 1\r\nSome Road\nLondon\n"));
        self::assertSame(str_repeat('a', 4000), Settings::parse('text', str_repeat('a', 4000)));
        self::assertSame('2026-10-01', Settings::parse('date', '2026-10-01'));
        self::assertSame('2028-02-29', Settings::parse('date', '2028-02-29'));
        self::assertNull(Settings::parse('date', ''));
    }

    public function testEveryBadValue(): void
    {
        $bad = [
            ['int', '1.5'], ['int', '1e3'], ['int', '1234567890'], ['int', 'ten'], ['int', '+1'],
            ['decimal', '-0.5'], ['decimal', '.5'], ['decimal', '1.'], ['decimal', '123456789'], ['decimal', '0.1234567'], ['decimal', '1,5'], ['decimal', 'NaN'],
            ['bool', '1'], ['bool', 'yes'], ['bool', ''],
            ['string', "two\nlines"], ['string', "carriage\rreturn"], ['string', str_repeat('x', 256)],
            ['text', str_repeat('x', 4001)], ['text', "bare\rreturn"],
            ['date', '2026-02-30'], ['date', '01/10/2026'], ['date', '2026-1-1'], ['date', 'tomorrow'],
            ['string', "nul\x00byte"], ['text', "bell\x07"], ['string', "\xC3\x28 invalid UTF-8"],
        ];
        foreach ($bad as [$type, $raw]) {
            try {
                Settings::parse($type, $raw);
                self::fail("{$type} accepted " . json_encode($raw, JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (CwException $e) {
                self::assertSame([400, 'bad_value'], [$e->httpStatus, $e->errorCode], "{$type}: " . $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Settings::parse('float', '1');
    }

    public function testEncodeAndDecodeRoundTrip(): void
    {
        foreach ([['int', 3, '3'], ['int', null, '""'], ['decimal', '0.50', '"0.50"'], ['decimal', null, '""'], ['bool', true, 'true'], ['bool', false, 'false'],
            ['string', 'x"y', '"x\"y"'], ['string', '', '""'], ['text', "a\nb", '"a\nb"'], ['date', '2026-10-01', '"2026-10-01"'], ['date', null, '""']] as [$type, $v, $json]) {
            self::assertSame($json, Settings::encode($type, $v), $type);
            self::assertSame($v ?? ($type === 'string' || $type === 'text' ? '' : null), Settings::decode($type, $json), $type);
        }
        self::assertSame('0.5', Settings::decode('decimal', '0.5'), 'a seeded JSON number is read through its text');
        self::assertSame('', Settings::decode('string', '""'));
        self::assertSame(0, Settings::cmpDecimal('0.50', '0.5'));
        self::assertSame(-1, Settings::cmpDecimal('0.09', '0.1'));
        self::assertSame(1, Settings::cmpDecimal('10', '9.999'));
        self::assertSame(1, Settings::cmpDecimal('1.000001', '1'));
    }
}
