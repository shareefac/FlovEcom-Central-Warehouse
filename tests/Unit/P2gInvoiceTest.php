<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\P2gInvoice;
use CW\Profit\PdftotextText;
use CW\Tests\Support\P2gFixtures;
use PHPUnit\Framework\TestCase;

/**
 * CW-1160 (docs/decisions.md PGL7): Parcel2Go's basket invoice read STRICTLY from its text, in the layout of the owner's real sample of 26 Nov
 * 2026 (made-up names, addresses, numbers and prices: the real file is never read by a test). One invoice per checkout (TX-<transaction>), a
 * row per label, an Extra Payment row a later charge of an earlier label; the whole invoice refused when anything with money is not understood.
 */
final class P2gInvoiceTest extends TestCase
{
    private const ROWS = [[150271419, 'Extra Payment', 1, 293, 0], [150278180, 'Evri Europe Standard Parcelshop', 1, 1132, 0],
        [150278181, 'Evri Europe Standard Parcelshop', 1, 886, 0]];

    private static function refusal(string $text, ?int $must = null, ?int $tx = null): string
    {
        try {
            P2gInvoice::read($text, $must, $tx);
        } catch (CwException $e) {
            self::assertSame('p2g_invoice_refused', $e->errorCode);
            self::assertStringNotContainsString(P2gFixtures::PERSON, $e->getMessage(), 'a refusal never quotes the invoice');
            return (string) $e->detail['refuse'];
        }
        return 'read';
    }

    public function testTheRealLayoutIsReadToThePenny(): void
    {
        $inv = P2gInvoice::read(P2gFixtures::invoiceText(95792472, '26/11/2026', self::ROWS), 150278180, 95792472);
        self::assertSame(['TX-95792472', 95792472, '2026-11-26', 2311, 0, 2311], [$inv['invoice_no'], $inv['transaction_id'], $inv['invoice_date'], $inv['net'], $inv['vat'],
            $inv['gross']]);
        self::assertSame([[150271419, 'surcharge', 'SURCH', null, 293], [150278180, 'price', 'SVC', 'evri-europe-standard-parcelshop', 1132],
            [150278181, 'price', 'SVC', 'evri-europe-standard-parcelshop', 886]],
            array_map(static fn (array $r): array => [$r['order_line_id'], $r['kind'], $r['code'], $r['service'], $r['net']], $inv['rows']));
        self::assertStringNotContainsString(P2gFixtures::PERSON, json_encode($inv), 'no name or address is kept');
        self::assertStringNotContainsString('Testtown', json_encode($inv));
        self::assertSame($inv, P2gInvoice::read(P2gFixtures::invoiceText(95792472, '26/11/2026', self::ROWS)), 'the same text, the same reading (its fingerprint too)');
    }

    public function testUkRowsAtTwentyPercentAndAPennyOfRounding(): void
    {
        $inv = P2gInvoice::read(P2gFixtures::invoiceText(5000001, '24/11/2026', [[150000001, 'Royal Mail Tracked 48 Small Parcel', 1, 319, 64],
            [150000002, 'Royal Mail Tracked 48 Small Parcel', 1, 324, 65], [150000003, 'Royal Mail Tracked 48 Small Parcel', 1, 249, 50]]));
        self::assertSame([2000, 2000, 2000], array_column($inv['rows'], 'rate_bp'));
        self::assertSame([892, 179, 1071], [$inv['net'], $inv['vat'], $inv['gross']]);
        self::assertSame('vat_rate', self::refusal(P2gFixtures::invoiceText(5000001, '24/11/2026', [[150000001, 'Royal Mail Tracked 48 Small Parcel', 1, 319, 70]])),
            'a VAT that is neither 0 nor 20% of the net');
    }

    public function testEveryLineWithMoneyMustBeUnderstoodAndAddUp(): void
    {
        $good = P2gFixtures::invoiceText(95792472, '26/11/2026', self::ROWS);
        self::assertSame('not_adding_up', self::refusal(P2gFixtures::invoiceText(95792472, '26/11/2026', self::ROWS, 2310)), 'Net one penny off');
        self::assertSame('not_adding_up', self::refusal(P2gFixtures::invoiceText(95792472, '26/11/2026', self::ROWS, null, null, 2312)), 'the total one penny off');
        self::assertSame('unknown_line', self::refusal(str_replace(' Testtown', ' Testtown      Admin fee £1.00', $good)), 'money on a line CW does not know');
        self::assertSame('unknown_row', self::refusal(P2gFixtures::invoiceText(1, '26/11/2026', [[150000009, 'Mystery Charge', 1, 100, 0]])), 'a row naming no delivery');
        self::assertSame('row_shape', self::refusal(str_replace('    £0.00', '', P2gFixtures::invoiceText(1, '26/11/2026', [[150000009, 'Evri Europe Standard Parcelshop', 1, 100, 0]]))));
        self::assertSame('row_twice', self::refusal(P2gFixtures::invoiceText(1, '26/11/2026', [[150000009, 'Evri Europe Standard Parcelshop', 1, 100, 0],
            [150000009, 'Evri Europe Standard Parcelshop', 1, 100, 0]])), 'a label priced twice');
        self::assertSame('below_zero', self::refusal(P2gFixtures::invoiceText(1, '26/11/2026', [[150000009, 'Evri Europe Standard Parcelshop', 1, -100, 0]])));
        self::assertSame('no_totals', self::refusal(preg_replace('/^.*Invoice Total.*$/m', '', $good)));
        self::assertSame('no_number', self::refusal(str_replace('TX-95792472', 'INV-95792472', $good)));
        self::assertSame('no_date', self::refusal(str_replace('26/11/2026', '2026-11-26', $good)));
        self::assertSame('no_rows', self::refusal(P2gFixtures::invoiceText(1, '26/11/2026', [])));
        self::assertSame('pdf_text', self::refusal(''));
    }

    public function testItMustBeTheBasketOfTheLabelAskedFor(): void
    {
        $text = P2gFixtures::invoiceText(95792472, '26/11/2026', self::ROWS);
        self::assertSame('other_line', self::refusal($text, 150000000), 'the label asked for is not priced on it');
        self::assertSame('other_line', self::refusal($text, 150271419), 'an Extra Payment row does not price its label');
        self::assertSame('other_line', self::refusal($text, 150278180, 95792473), 'another basket');
        self::assertSame('read', self::refusal($text, 150278181, 95792472));
    }

    public function testAPrePayTopUpIsNeverADeliveryCost(): void
    {
        self::assertSame('topup', self::refusal(P2gFixtures::invoiceText(7000001, '26/11/2026', [[150000010, 'PrePay Top Up', 1, 50000, 0]])));
        self::assertSame('topup', self::refusal("Prepayment Invoice\n" . P2gFixtures::invoiceText(7000001, '26/11/2026', [[150000010, 'Evri Europe Standard Parcelshop', 1, 100, 0]])));
    }

    public function testPenceAndVatRounding(): void
    {
        self::assertSame([123456, -150, 293], [P2gInvoice::pence('£1,234.56'), P2gInvoice::pence('-£1.50'), P2gInvoice::pence('£2.93')]);
        self::assertSame([64, 65, 50, -64], [P2gInvoice::vatOf(319, 2000), P2gInvoice::vatOf(324, 2000), P2gInvoice::vatOf(249, 2000), P2gInvoice::vatOf(-319, 2000)]);
        self::assertSame(['2026-11-26', null, null], [P2gInvoice::day('26/11/2026'), P2gInvoice::day('31/02/2026'), P2gInvoice::day('26-11-2026')]);
    }
    /**
     * A real PDF in the sample's layout (made up here with FPDF: made-up names and prices) read through pdftotext as the job reads it
     * (PdftotextText: -layout, as nobody when root). Skipped where poppler-utils is not installed (production needs it: the kit's prepare step).
     */
    public function testARealPdfIsReadThroughPdftotext(): void
    {
        if (!PdftotextText::available()) {
            self::markTestSkipped('no pdftotext here (poppler-utils)');
        }
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 9);
        $cell = static function (float $x, float $y, string $t) use ($pdf): void {
            $pdf->Text($x, $y, (string) iconv('UTF-8', 'windows-1252', $t));
        };
        $cell(10, 15, 'Parcel2Go.com Ltd');
        $cell(170, 15, 'Page 1 of 1');
        $cell(120, 40, 'Invoice No.');
        $cell(165, 40, 'TX-95792472');
        $cell(10, 48, 'VAT No. GB000000000');
        $cell(120, 48, 'Date');
        $cell(165, 48, '26/11/2026');
        $cell(120, 56, 'Payment Method');
        $cell(165, 56, 'PrePay');
        $cell(10, 80, 'Details');
        $cell(140, 80, 'Parcels');
        $cell(160, 80, 'Net');
        $cell(182, 80, 'VAT');
        $y = 90;
        foreach (self::ROWS as [$olid, $desc, $parcels, $net, $vat]) {
            $cell(10, $y, "P2G{$olid} - {$desc}");
            $cell(143, $y, (string) $parcels);
            $cell(158, $y, '£' . number_format($net / 100, 2));
            $cell(180, $y, '£' . number_format($vat / 100, 2));
            $cell(10, $y + 5, P2gFixtures::PERSON . ' ' . P2gFixtures::ADDRESS);
            $y += 14;
        }
        foreach ([['Net', '£23.11'], ['VAT', '£0.00'], ['Invoice Total', '£23.11']] as $i => [$label, $amount]) {
            $cell(120, 150 + 8 * $i, $label);
            $cell(178, 150 + 8 * $i, $amount);
        }
        $inv = P2gInvoice::read((new PdftotextText())->text($pdf->Output('S')), 150278180, 95792472);
        self::assertSame(['TX-95792472', 2311, [293, 1132, 886], ['surcharge', 'price', 'price']], [$inv['invoice_no'], $inv['net'], array_column($inv['rows'], 'net'),
            array_column($inv['rows'], 'kind')]);
    }

    public function testOnlyExactlyExtraPaymentIsASurchargeAndTheHeadingsMustBeThere(): void
    {
        // H3 / F2 (the lead's decision 4): any other "Extra ..." text is an unknown row (the whole basket refused); the table's headings are the expected ones
        foreach (['Extra Payment - Evri', 'Extra Payment Parcel Overweight', 'extra payment', 'Extra Charge'] as $desc) {
            self::assertSame('unknown_row', self::refusal(P2gFixtures::invoiceText(1, '26/11/2026', [[150000009, $desc, 1, 100, 0]])), $desc);
        }
        self::assertSame('surcharge', P2gInvoice::read(P2gFixtures::invoiceText(1, '26/11/2026', [[150000009, 'Extra Payment', 1, 100, 0]]))['rows'][0]['kind']);
        $text = P2gFixtures::invoiceText(1, '26/11/2026', [[150000009, 'Evri Europe Standard Parcelshop', 1, 100, 0]]);
        self::assertSame('no_heading', self::refusal(str_replace('Parcels    Net', 'Items    Net', $text)), 'not the table CW knows');
        self::assertSame('no_heading', self::refusal((string) preg_replace('/^ Details .*$/m', '', $text)));
    }

    public function testTheApiCopyWithItsHeadingsOnOneLineIsReadTheSame(): void
    {
        // B0 on production (27 Nov 2026, both accounts): GET /invoices serves the same invoice, but its table prints "Net Amount" and "VAT Amount"
        // on ONE line (the website's copy wraps "Amount" to a second line). Read the same, to the penny; any other heading stays refused.
        $web = P2gFixtures::invoiceText(95792472, '26/11/2026', self::ROWS);
        $one = ' Details                                                                         Parcels    Net Amount    VAT Amount';
        $api = (string) preg_replace('/^ Details .*\n\s+Amount\s+Amount\n/m', $one . "\n", $web, 1, $done);
        self::assertSame(1, $done, 'the fixture has the two heading lines');
        self::assertSame(P2gInvoice::read($web, 150278180, 95792472), P2gInvoice::read($api, 150278180, 95792472), 'the API copy reads as the website copy');
        self::assertSame('no_heading', self::refusal(str_replace('VAT Amount', 'VAT', $api)), 'Net Amount beside a bare VAT: not the table CW knows');
        self::assertSame('no_heading', self::refusal(str_replace('Net Amount', 'Price Amount', $api)));
        // a long basket: the API heading again on page 2
        $text = (string) preg_replace('/^ Details .*\n\s+Amount\s+Amount\n/m', $one . "\n", P2gFixtures::invoiceText(1, '26/11/2026',
            [[150000011, 'Evri Europe Standard Parcelshop', 1, 100, 0], [150000012, 'Evri Europe Standard Parcelshop', 1, 200, 0]]), 1);
        $pages = (string) preg_replace('/^( P2G150000012 .*)$/m', "\fParcel2Go.com Ltd                Page 2 of 2\n Invoice No.   TX-1\n{$one}\n$1", $text, 1);
        self::assertSame([300, [100, 200]], [P2gInvoice::read($pages)['net'], array_column(P2gInvoice::read($pages)['rows'], 'net')]);
    }

    public function testEachPagesRowsNeedThatPagesOwnHeading(): void
    {
        // the final review (Codex, 27 Nov): a known heading on page 1 must not let page 2's rows through under another heading, or none
        $one = ' Details                                                                         Parcels    Net Amount    VAT Amount';
        $text = (string) preg_replace('/^ Details .*\n\s+Amount\s+Amount\n/m', $one . "\n", P2gFixtures::invoiceText(1, '26/11/2026',
            [[150000011, 'Evri Europe Standard Parcelshop', 1, 100, 20], [150000012, 'Evri Europe Standard Parcelshop', 1, 200, 40]]), 1);
        $page2 = static fn (string $head): string => (string) preg_replace('/^( P2G150000012 .*)$/m',
            "\fParcel2Go.com Ltd                Page 2 of 2\n Invoice No.   TX-1\n" . $head . "$1", $text, 1);
        self::assertSame(360, P2gInvoice::read($page2($one . "\n"))['gross'], 'page 2 with its own known heading: read');
        self::assertSame('no_heading', self::refusal($page2(str_replace('Net Amount', 'Price Amount', $one) . "\n")), 'page 2 under another heading');
        self::assertSame('no_heading', self::refusal($page2('')), 'page 2 rows with no heading of their own');
        self::assertSame(360, P2gInvoice::read($text)['gross'], 'one page: as before');
    }

    public function testALongBasketOverTwoPagesReadsWithItsHeadingsOnEachPage(): void
    {
        // the fix round's own check (a basket of many labels spills onto a second page, whose table repeats its headings): read whole
        $text = P2gFixtures::invoiceText(1, '26/11/2026', [[150000011, 'Evri Europe Standard Parcelshop', 1, 100, 0], [150000012, 'Evri Europe Standard Parcelshop', 1, 200, 0]]);
        preg_match('/^ Details .*$/m', $text, $h);
        $pages = (string) preg_replace('/^( P2G150000012 .*)$/m', "\fParcel2Go.com Ltd                Page 2 of 2\n Invoice No.   TX-1\n{$h[0]}\n$1", $text, 1);
        self::assertNotSame($text, $pages);
        $inv = P2gInvoice::read($pages);
        self::assertSame([300, [100, 200]], [$inv['net'], array_column($inv['rows'], 'net')]);
    }
}
