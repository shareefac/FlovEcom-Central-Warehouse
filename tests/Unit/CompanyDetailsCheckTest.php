<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Company\CompanyDetails;
use CW\CwException;
use PHPUnit\Framework\TestCase;

/**
 * What a person types on the Company details form (I92), without a database: company numbers (8 digits or 2 letters + 6
 * digits, spaces and case tidied), UK VAT numbers (GB/XI + 9 or 12 digits, spaces and a missing GB accepted, the explicit
 * "not VAT registered" choice), phone, e-mail, one-line names, multi-line addresses (line count, line length, blank lines,
 * CR LF), characters the PDF's Windows-1252 font cannot print, every problem reported at once; what a confirmation needs;
 * the VAT display format and its check digits.
 */
final class CompanyDetailsCheckTest extends TestCase
{
    /** @param array<string, mixed> $over @return array<string, mixed> */
    private static function good(array $over = []): array
    {
        return $over + ['legal_name' => 'Example Vapes Ltd', 'trading_name' => 'Vape and Go', 'company_number' => '01234567', 'address' => "1 High Street\nLeeds\nLS1 1AA",
            'vat_registered' => 'yes', 'vat_number' => 'GB123456782', 'phone' => '0113 496 0000', 'email' => 'buying@example.co.uk',
            'delivery_address' => "Unit 4, Example Park\nLeeds LS2 2BB"];
    }

    /** @param array<string, mixed> $in @return array<string, string> field => message */
    private static function errors(array $in, ?string $reason = null): array
    {
        try {
            CompanyDetails::check($in, $reason);
        } catch (CwException $e) {
            self::assertSame(['company_invalid', 422], [$e->errorCode, $e->httpStatus]);
            self::assertIsArray($e->detail['errors'] ?? null);
            return $e->detail['errors'];
        }
        return [];
    }

    public function testGoodDetailsAreTidied(): void
    {
        $r = CompanyDetails::check(self::good(['legal_name' => "  Example\tVapes   Ltd ", 'company_number' => ' sc 12 34-56 ', 'vat_number' => 'gb 123 4567 82',
            'address' => "\r\n 1  High Street \r\n\r\nLeeds\rLS1 1AA\n\n", 'phone' => ' +44 (0)113  496 0000 ', 'email' => ' buying@example.co.uk ']), '  owner\'s details,  3 Oct ');
        self::assertSame(['legal_name' => 'Example Vapes Ltd', 'trading_name' => 'Vape and Go', 'company_number' => 'SC123456', 'address' => "1 High Street\nLeeds\nLS1 1AA",
            'vat_registered' => true, 'vat_number' => 'GB123456782', 'phone' => '+44 (0)113 496 0000', 'email' => 'buying@example.co.uk',
            'delivery_address' => "Unit 4, Example Park\nLeeds LS2 2BB"], $r['values']);
        self::assertSame("owner's details, 3 Oct", $r['reason']);
        self::assertNull(CompanyDetails::check(self::good(), '   ')['reason'], 'an empty reason is no reason');

        // Nine or twelve digits without GB are a GB number; XI is Northern Ireland; a branch has 12 digits.
        foreach (['123456782' => 'GB123456782', '123 4567 82 001' => 'GB123456782001', 'xi 123.4567.82' => 'XI123456782', 'GB-123-4567-82' => 'GB123456782'] as $typed => $stored) {
            self::assertSame($stored, CompanyDetails::check(self::good(['vat_number' => (string) $typed]))['values']['vat_number'], (string) $typed);
        }
        // A number typed with "not known yet" says "registered"; "not VAT registered" with no number is an answer too.
        self::assertTrue(CompanyDetails::check(self::good(['vat_registered' => '']))['values']['vat_registered']);
        $no = CompanyDetails::check(self::good(['vat_registered' => 'no', 'vat_number' => '']))['values'];
        self::assertSame([false, ''], [$no['vat_registered'], $no['vat_number']]);
        $unknown = CompanyDetails::check(self::good(['vat_registered' => '', 'vat_number' => '  ']))['values'];
        self::assertSame([null, ''], [$unknown['vat_registered'], $unknown['vat_number']]);
        self::assertTrue(CompanyDetails::check(self::good(['vat_registered' => true]))['values']['vat_registered'], 'service callers may pass a bool');

        // Everything may be empty (saved bit by bit; a confirmation needs more: missing()).
        $empty = CompanyDetails::check([])['values'];
        self::assertSame(['legal_name' => '', 'trading_name' => '', 'company_number' => '', 'address' => '', 'vat_registered' => null, 'vat_number' => '',
            'phone' => '', 'email' => '', 'delivery_address' => ''], $empty);

        // Western European letters, £ and € print (Windows-1252), so they are kept; a decomposed é is composed when intl is there.
        $r = CompanyDetails::check(self::good(['legal_name' => 'Café Zoë Vapes £ € Ltd', 'delivery_address' => "Straße 1\nGöteborg – Øst"]))['values'];
        self::assertSame('Café Zoë Vapes £ € Ltd', $r['legal_name']);
        self::assertSame("Straße 1\nGöteborg – Øst", $r['delivery_address']);
        if (class_exists(\Normalizer::class)) {
            self::assertSame('Café Ltd', CompanyDetails::check(self::good(['legal_name' => "Cafe\u{0301} Ltd"]))['values']['legal_name']);
        }
    }

    public function testBadDetailsAreRefusedAllAtOnceWithFriendlyMessages(): void
    {
        $errors = self::errors(self::good(['company_number' => '1234567', 'vat_number' => 'GB12345678', 'phone' => '0113 call me', 'email' => 'not an address',
            'legal_name' => str_repeat('a', 161)]), str_repeat('r', 501));
        self::assertSame(['legal_name', 'company_number', 'vat_number', 'phone', 'email', 'reason'], array_keys($errors));
        self::assertSame('The legal name is at most 160 characters (this one has 161).', $errors['legal_name']);
        self::assertStringContainsString('keep the leading zeros, for example 01234567', $errors['company_number']);
        self::assertStringContainsString('GB and 9 digits (for example GB 123 4567 89)', $errors['vat_number']);
        self::assertStringContainsString('for example 0113 496 0000', $errors['phone']);
        self::assertSame('This is not an e-mail address (for example buying@example.co.uk).', $errors['email']);
        self::assertSame('The reason is at most 500 characters.', $errors['reason']);

        foreach (['SC12345', 'S1234567', '123456789', 'SC1234567', 'ABC12345', '1234 567X', 'R123456', 'R12345678', 'IP1234R', 'IP12345', 'XP12345R'] as $cn) {
            self::assertArrayHasKey('company_number', self::errors(self::good(['company_number' => $cn])), $cn);
        }
        // The rarer 8-character numbers are accepted too: an old Northern Ireland company (R + 7 digits), a registered society
        // (IP, SP, NP + 5 digits + R).
        foreach (['R0000123' => 'R0000123', 'ip12345r' => 'IP12345R', 'SP 01234 R' => 'SP01234R', 'NP00001R' => 'NP00001R', 'oc 300 001' => 'OC300001'] as $typed => $stored) {
            self::assertSame($stored, CompanyDetails::check(self::good(['company_number' => (string) $typed]))['values']['company_number'], (string) $typed);
        }
        foreach (['GB1234567890', 'FR123456789', 'GB12345678901', 'XX123456789', 'GBGD001', '12345678'] as $vat) {
            self::assertArrayHasKey('vat_number', self::errors(self::good(['vat_number' => $vat])), $vat);
        }
        foreach (['123', '++44 113 496 0000', '0113 496 0000 0000 000', '44+113', '0113/496/0000', str_repeat('1', 33)] as $phone) {
            self::assertArrayHasKey('phone', self::errors(self::good(['phone' => $phone])), $phone);
        }
        self::assertSame('0113.496.0000', CompanyDetails::check(self::good(['phone' => '0113.496.0000']))['values']['phone'], 'dots, as the help says');
        self::assertStringContainsString('+ ( ) - . only', CompanyDetails::PHONE_HELP);

        // The VAT choice and the number must agree.
        self::assertSame('You chose "VAT registered": type the VAT number, or choose "Not VAT registered" or "Not known yet".',
            self::errors(self::good(['vat_registered' => 'yes', 'vat_number' => '']))['vat_number']);
        self::assertSame('You chose "Not VAT registered": clear the VAT number, or choose "VAT registered".',
            self::errors(self::good(['vat_registered' => 'no']))['vat_number']);
        self::assertArrayHasKey('vat_registered', self::errors(self::good(['vat_registered' => 'maybe'])));

        // Names are one line; control characters, invalid UTF-8.
        self::assertSame('The legal name must be one line.', self::errors(self::good(['legal_name' => "Example\nVapes"]))['legal_name']);
        self::assertSame('The trading name contains a control character: type it again.', self::errors(self::good(['trading_name' => "Vape\x07Go"]))['trading_name']);
        self::assertSame('The legal name is not valid text: type it again.', self::errors(self::good(['legal_name' => "Caf\xE9"]))['legal_name']);
        self::assertSame('The delivery address contains a control character: type it again.',
            self::errors(self::good(['delivery_address' => "Unit 4\x0B\nLeeds"]))['delivery_address']);

        // Addresses: at most 8 lines of 100 characters (blank lines do not count).
        self::assertSame('The registered address has at most 8 lines (this one has 9).', self::errors(self::good(['address' => implode("\n", range(1, 9))]))['address']);
        self::assertSame([], self::errors(self::good(['address' => implode("\n\n", range(1, 8))])));
        self::assertSame('Line 2 of the delivery address is longer than 100 characters: split it over two lines.',
            self::errors(self::good(['delivery_address' => "Unit 4\n" . str_repeat('x', 101)]))['delivery_address']);
    }

    public function testCharactersThePdfCannotPrintAreRefused(): void
    {
        $e = self::errors(self::good(['legal_name' => 'Łódź Vapes Sp. z o.o.']));
        self::assertSame('The legal name contains "Ł", which a purchase order cannot print (the PDF has Western European letters only, such as é, ü, '
            . 'ß, £ and €): type a plain letter instead.', $e['legal_name']);
        self::assertStringContainsString('contains "汉", which', self::errors(self::good(['trading_name' => '汉字 Vapes']))['trading_name']);
        self::assertStringContainsString('contains an invisible character, which', self::errors(self::good(['legal_name' => "Vapes\u{202E}dtL"]))['legal_name'],
            'a right-to-left override');
        self::assertStringContainsString('contains "😀", which', self::errors(self::good(['delivery_address' => "Unit 4 😀\nLeeds"]))['delivery_address']);
        self::assertStringNotContainsString('U+', self::errors(self::good(['delivery_address' => "Unit 4 😀\nLeeds"]))['delivery_address'], 'no code points');
        self::assertSame('The legal name contains a control character: type it again.', self::errors(self::good(['legal_name' => "Vapes\u{0085}Ltd"]))['legal_name'],
            'a C1 control character (NEL)');
        self::assertNull(CompanyDetails::unprintable("Zoë’s “Vapes” – £5 €6 ™ •\nØst"));
        self::assertSame('ł', CompanyDetails::unprintable('Wrocław'));
    }

    public function testWhatAConfirmationNeeds(): void
    {
        $all = CompanyDetails::check(self::good())['values'];
        self::assertSame([], CompanyDetails::missing($all));
        self::assertSame([], CompanyDetails::missing(['trading_name' => '', 'phone' => ''] + $all), 'trading name and phone are optional');
        self::assertSame([], CompanyDetails::missing(['vat_registered' => false, 'vat_number' => ''] + $all), '"not VAT registered" is an answer');
        self::assertSame(['legal name', 'company number', 'registered address', 'VAT number (or "not VAT registered")', 'purchasing e-mail', 'delivery address'],
            CompanyDetails::missing(CompanyDetails::check([])['values']));
        self::assertSame(['VAT number (or "not VAT registered")'], CompanyDetails::missing(['vat_registered' => null, 'vat_number' => ''] + $all));
    }

    /** What a save would store (tidy), whether stored details still need one save before a confirmation, and the watched fields. */
    public function testTidyUnsavedAndWatched(): void
    {
        $tidy = CompanyDetails::check(self::good())['values'];
        self::assertSame($tidy, CompanyDetails::tidy($tidy));
        self::assertFalse(CompanyDetails::unsaved($tidy));
        $messy = ['legal_name' => 'Example  Vapes Ltd', 'address' => "1 High Street \r\nLeeds\n\nLS1 1AA", 'vat_number' => 'GB123456782'] + $tidy;
        self::assertSame($tidy, CompanyDetails::tidy($messy));
        self::assertTrue(CompanyDetails::unsaved($messy), 'valid, but not as a save stores it');
        self::assertFalse(CompanyDetails::unsaved(['company_number' => 'not a number'] + $tidy), 'a problem is a problem, not "unsaved"');
        self::assertSame('not a number', CompanyDetails::tidy(['company_number' => ' not a number '] + $tidy)['company_number'], 'tidied as far as it goes');

        self::assertSame(['legal_name', 'company_number', 'vat_registered', 'vat_number', 'email', 'delivery_address'], array_keys(CompanyDetails::watched($tidy)));
        self::assertSame(CompanyDetails::watched($tidy), CompanyDetails::watched($messy));
        // A PO snapshot from before 0013 has no vat_registered: its VAT number says registered.
        $old = $tidy;
        unset($old['vat_registered']);
        $old['vat_number'] = 'GB 123 4567 82';
        self::assertSame(CompanyDetails::watched($tidy), CompanyDetails::watched($old));
    }

    public function testVatFormatAndCheckDigits(): void
    {
        self::assertSame('GB 123 4567 82', CompanyDetails::formatVat('GB123456782'));
        self::assertSame('GB 123 4567 82 001', CompanyDetails::formatVat('GB123456782001'));
        self::assertSame('XI 999 9999 73', CompanyDetails::formatVat('XI999999973'));
        self::assertSame('GB 123 4567 89', CompanyDetails::formatVat('GB 123 4567 89'), 'an old snapshot prints as it was');
        self::assertSame('', CompanyDetails::formatVat(''));
        // HMRC's weighted modulus 97: 1x8 + 2x7 + ... + 7x2 = 112, + 82 = 194 = 2 x 97; the "9755" scheme adds 55.
        self::assertTrue(CompanyDetails::vatChecksumOk('GB123456782'));
        self::assertTrue(CompanyDetails::vatChecksumOk('GB999999973'));
        self::assertTrue(CompanyDetails::vatChecksumOk('GB123456782001'), 'a branch: the first 9 digits');
        self::assertTrue(CompanyDetails::vatChecksumOk('GB123456727'), '112 + 27 + 55 = 194');
        self::assertFalse(CompanyDetails::vatChecksumOk('GB123456789'));
        self::assertFalse(CompanyDetails::vatChecksumOk('GB12345678'));
    }
}
