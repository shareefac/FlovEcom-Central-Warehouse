<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Company\CompanyDetails;
use CW\Handovers\HandoverPdf;
use PHPUnit\Framework\TestCase;

/**
 * Our company's details on a hand-over's invoice PDF (pack H, PH8) since only the legal name and the registered address are needed
 * (the owner, 18 Nov 2026; docs/decisions.md CQ2): an optional detail not given prints no "Company number" or "VAT number" row with
 * nothing (or "[to be confirmed]") after it; given, it prints as before. Pure: HandoverPdf::build on a made-up invoice.
 */
final class HandoverPdfCompanyTest extends TestCase
{
    /** @param array<string, mixed> $company @return array<string, mixed> Handovers::invoice()'s shape */
    private static function invoice(array $company): array
    {
        return [
            'record' => ['number' => 'HO-000001', 'status' => 'posted', 'reversed_by_number' => null, 'doc_date' => '2026-11-18 10:00:00', 'external_ref' => null,
                'currency' => 'GBP', 'note' => null],
            'lines' => [['line_no' => 1, 'code' => 'CW-000001', 'name' => 'Example e-liquid', 'qty' => -2, 'unit_cost' => '4.000000', 'amount' => '8.00']],
            'company' => $company,
            'buyer' => ['name' => 'Electrofag Ltd', 'address' => '', 'company_number' => '', 'vat_number' => '', 'email' => '', 'phone' => ''],
            'totals' => ['net' => '8.00', 'vat' => '1.60', 'gross' => '9.60'], 'vat_code' => 'S', 'vat_rate' => '20.00', 'banner' => false, 'terms' => '',
        ];
    }

    /** @return list<string> every string the PDF draws (its compressed page streams opened) */
    private static function texts(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
        $out = [];
        foreach ($m[1] as $stream) {
            $plain = @gzuncompress($stream);
            if (!is_string($plain)) {
                continue;
            }
            preg_match_all('/\(((?:[^()\\\\\n]|\\\\.)*)\) Tj/', $plain, $t);
            foreach ($t[1] as $s) {
                $out[] = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $s);
            }
        }
        return $out;
    }

    public function testOnlyTheCompanyDetailsGivenArePrinted(): void
    {
        $company = CompanyDetails::check(['legal_name' => 'Example Vapes Ltd', 'trading_name' => 'Vape and Go', 'address' => "1 High Street\nLeeds\nLS1 1AA",
            'email' => 'buying@example.co.uk', 'delivery_address' => "Unit 4, Example Park\nLeeds LS2 2BB"])['values'] + ['confirmed' => true, 'version' => 4];
        $texts = self::texts(HandoverPdf::build(self::invoice($company)));
        self::assertContains('Example Vapes Ltd', $texts);
        self::assertContains('Vape and Go', $texts);
        self::assertContains('1 High Street, Leeds, LS1 1AA', $texts);
        foreach (['Company number', 'VAT number', '[to be confirmed]'] as $absent) {
            self::assertNotContains($absent, $texts, $absent);
        }

        // Given, they print as before.
        $texts = self::texts(HandoverPdf::build(self::invoice(['company_number' => '01234567', 'vat_registered' => true, 'vat_number' => 'GB123456782'] + $company)));
        foreach (['Company number', '01234567', 'VAT number', 'GB 123 4567 82'] as $shown) {
            self::assertContains($shown, $texts, $shown);
        }
        // "Not VAT registered" prints no VAT row, as before.
        self::assertNotContains('VAT number', self::texts(HandoverPdf::build(self::invoice(['vat_registered' => false] + $company))));
        // The two details a confirmation needs still say so while they are missing (only unconfirmed details can lack them).
        $texts = self::texts(HandoverPdf::build(['banner' => true] + self::invoice(['legal_name' => '', 'address' => '', 'confirmed' => false] + $company)));
        self::assertSame(2, count(array_keys($texts, '[to be confirmed]', true)), 'the name and the address');
    }
}
