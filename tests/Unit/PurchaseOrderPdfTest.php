<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Company\CompanyDetails;
use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrderPdf;
use PHPUnit\Framework\TestCase;

/**
 * The PO PDF (spec §6.7; I48-I59), uncompressed so the page text can be read: the letterhead from the company details or
 * the posting snapshot, [to be confirmed] for empty values, the three banners, "Amends PO-x", a long order over several
 * pages with the table header repeated, the totals and VAT per code, £ in Windows-1252, the footer, the cancellation; since
 * the Company details screen (I96): the VAT number formatted, no VAT line for "not VAT registered", accented letters, a long
 * delivery address wrapped (never cut), a long legal name cut in the footer (the page number stays), the SAMPLE order.
 */
final class PurchaseOrderPdfTest extends TestCase
{
    /** @param array<string, mixed> $over @return array<string, mixed> */
    private static function data(array $over = [], int $lines = 2): array
    {
        $rows = [];
        $calc = [];
        for ($i = 1; $i <= $lines; $i++) {
            $charge = $i === $lines && $lines > 1;
            $amount = $charge ? 750 : 3960;
            $rows[] = ['line_no' => $i, 'kind' => $charge ? 'charge' : 'item', 'supplier_code' => $charge ? null : "SUP-{$i}", 'sku_code' => $charge ? null : sprintf('CW-%06d', $i),
                'description' => $charge ? 'Delivery' : "Elux Legend 3500 Blueberry Ice {$i}", 'purchase_unit' => $charge ? 'each' : 'box', 'units_per_pack' => $charge ? 1 : 24,
                'packs' => 1, 'units' => $charge ? null : 24, 'pack_price' => $charge ? '7.5000' : '39.6000', 'vat_code' => $charge ? 'R' : 'S', 'amount_e2' => $amount];
            $calc[] = ['amount_e2' => $amount, 'vat_code' => $charge ? 'R' : 'S', 'rate_e2' => $charge ? 500 : 2000];
        }
        return $over + [
            'number' => 'PO-000007', 'label' => 'PO-000007', 'id' => 42, 'status' => 'posted', 'state' => 'approved', 'posted' => true,
            'order_date' => '2026-10-02', 'expected_date' => '2026-10-09', 'external_ref' => 'Q-2210', 'note' => 'Deliver to bay 2 before noon', 'amends' => null,
            'cancellation' => null, 'cancelled_by' => null,
            'company' => ['legal_name' => 'Vape Wholesale Ltd', 'trading_name' => 'VPG Central', 'address' => "Unit 4, Example Park\nLeeds\nLS1 1AA", 'company_number' => '01234567',
                'vat_number' => 'GB 123 4567 89', 'phone' => '0113 000 0000', 'email' => 'buying@vpg.example', 'delivery_address' => "Goods In, Unit 4\nLeeds LS1 1AA",
                'confirmed' => true],
            'supplier' => ['code' => 'ACME', 'name' => 'Acme Vapes', 'legal_name' => 'Acme Vapes Ltd', 'address_line1' => '1 Trading Estate', 'address_line2' => null,
                'city' => 'Leeds', 'postcode' => 'LS2 2BB', 'country' => 'GB', 'vat_number' => 'GB999', 'contact_name' => 'Sam', 'email' => 'orders@acme.example', 'phone' => null],
            'lines' => $rows,
            'totals' => PoMath::totals($calc),
            'terms' => 'Please quote our order number. Every duty-liable vaping liquid must carry a UK duty stamp.',
        ];
    }

    private static function pages(string $pdf): int
    {
        return preg_match_all('#/Type /Page\b(?!s)#', $pdf);
    }

    public function testTheLetterheadLinesAndTotals(): void
    {
        $pdf = (new PurchaseOrderPdf(false))->render(self::data());
        self::assertStringStartsWith('%PDF-1.', $pdf);
        self::assertSame('%%EOF', substr(rtrim($pdf), -5));
        foreach (['(Vape Wholesale Ltd) Tj', '(VPG Central) Tj', '(Unit 4, Example Park) Tj', '(Company no. 01234567) Tj', '(VAT no. GB 123 4567 89) Tj',
            '(PURCHASE ORDER) Tj', '(PO-000007) Tj', '(2026-10-02) Tj', '(2026-10-09) Tj', '(Q-2210) Tj', '(Supplier) Tj', '(Deliver to) Tj', '(Goods In, Unit 4) Tj',
            '(Acme Vapes \(Acme Vapes Ltd\)) Tj', '(Supplier code ACME) Tj', '(VAT no. GB999) Tj', '(E-mail: orders@acme.example) Tj', '(SUP-1) Tj', '(CW-000001) Tj',
            "(box \xD724) Tj", '(Delivery) Tj', "(\xA339.60) Tj", "(\xA37.50) Tj", '(Notes to supplier) Tj', '(Deliver to bay 2 before noon) Tj', '(Terms) Tj'] as $text) {
            self::assertStringContainsString($text, $pdf, $text);
        }
        // Totals: net 47.10; VAT S 20% 7.92 + R 5% 0.38 (0.375 half-up); total 55.40.
        self::assertStringContainsString("(Net) Tj", $pdf);
        self::assertStringContainsString("(\xA347.10) Tj", $pdf);
        self::assertStringContainsString('(VAT S 20%) Tj', $pdf);
        self::assertStringContainsString("(\xA37.92) Tj", $pdf);
        self::assertStringContainsString('(VAT R 5%) Tj', $pdf);
        self::assertStringContainsString("(\xA30.38) Tj", $pdf);
        self::assertStringContainsString("(\xA355.40) Tj", $pdf);
        self::assertStringContainsString("(Vape Wholesale Ltd \xB7 Company no. 01234567 \xB7 VAT no. GB 123 4567 89 \xB7 PO-000007 \xB7 page 1/1) Tj", $pdf, 'the footer');
        foreach (['DRAFT', 'CANCELLED', 'NOT CONFIRMED', '[to be confirmed]'] as $absent) {
            self::assertStringNotContainsString($absent, $pdf);
        }
        self::assertSame(1, self::pages($pdf));
    }

    public function testTheBannersAndPlaceholders(): void
    {
        $empty = ['legal_name' => '', 'trading_name' => '', 'address' => '', 'company_number' => '', 'vat_number' => '', 'phone' => '', 'email' => '',
            'delivery_address' => '', 'confirmed' => false];
        $pdf = (new PurchaseOrderPdf(false))->render(self::data(['number' => null, 'label' => 'draft #42', 'status' => 'draft', 'state' => null, 'posted' => false,
            'company' => $empty, 'amends' => 'PO-000003']));
        self::assertStringContainsString("(DRAFT \x97 NOT AN ORDER) Tj", $pdf);
        self::assertStringContainsString("(COMPANY DETAILS NOT CONFIRMED \x97 DO NOT SEND) Tj", $pdf);
        self::assertStringContainsString('(draft #42) Tj', $pdf);
        self::assertStringContainsString('(Amends) Tj', $pdf);
        self::assertStringContainsString('(PO-000003) Tj', $pdf);
        self::assertGreaterThanOrEqual(5, substr_count($pdf, '[to be confirmed]'), 'name, address, numbers, delivery address');
        self::assertStringContainsString("([to be confirmed] \xB7 Company no. [to be confirmed]", $pdf);

        $cancelled = (new PurchaseOrderPdf(false))->render(self::data(['status' => 'reversed', 'state' => 'cancelled', 'cancelled_by' => 'PO-000008']));
        self::assertStringContainsString("(CANCELLED \x97 see PO-000008) Tj", $cancelled);
        self::assertStringNotContainsString('DRAFT', $cancelled);

        $cancellation = (new PurchaseOrderPdf(false))->render(self::data(['status' => 'reversed', 'cancelled_by' => 'PO-000008',
            'cancellation' => ['number' => 'PO-000008', 'reason' => 'Supplier cannot supply', 'note' => 'discontinued', 'date' => '2026-10-03']]));
        self::assertStringContainsString('(CANCELLATION) Tj', $cancellation);
        self::assertStringContainsString('(Order PO-000007 is cancelled. Please do not deliver it.) Tj', $cancellation);
        self::assertStringContainsString("(Reason: Supplier cannot supply \x97 discontinued) Tj", $cancellation);
        self::assertStringContainsString("\xB7 PO-000008 \xB7 page 1/1) Tj", $cancellation, 'the footer names the cancellation');
    }

    public function testALongOrderRunsOverPagesWithItsHeaderRepeated(): void
    {
        $started = hrtime(true);
        $pdf = (new PurchaseOrderPdf(false))->render(self::data([], 120));
        $ms = (hrtime(true) - $started) / 1e6;
        $pages = self::pages($pdf);
        self::assertGreaterThanOrEqual(3, $pages);
        $headers = substr_count($pdf, '(Supplier code) Tj');
        self::assertGreaterThanOrEqual(3, $headers, 'the lines header repeated on every page the table runs onto');
        self::assertContains($headers, [$pages, $pages - 1], 'every page with lines (the totals may start a page of their own)');
        self::assertStringContainsString('(CW-000119) Tj', $pdf);
        self::assertStringContainsString("PO-000007 \xB7 page {$pages}/{$pages}) Tj", $pdf);
        self::assertStringContainsString('(120 / 2,856) Tj', $pdf, '120 lines, 119 x 24 units');
        self::assertLessThan(5000, $ms, 'a 120-line PDF is quick');
    }

    /** @return list<string> the strings the page draws, in order (FPDF's escapes undone) */
    private static function texts(string $pdf): array
    {
        preg_match_all('/\(((?:[^()\\\\\n]|\\\\.)*)\) Tj/', $pdf, $m);
        return array_map(static fn (string $t): string => str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $t), $m[1]);
    }

    /** The details as the Company details screen keeps them (CompanyDetails::company()): normalised VAT number, vat_registered, version. */
    public function testTheCompanyDetailsScreensValues(): void
    {
        $company = CompanyDetails::check([
            'legal_name' => 'Café Zoë Vapes Ltd', 'trading_name' => 'Vape and Go – Straße', 'company_number' => 'sc123456', 'vat_registered' => 'yes',
            'vat_number' => '123456782', 'address' => "1 High Street\nLeeds", 'phone' => '0113 496 0000', 'email' => 'buying@example.co.uk',
            'delivery_address' => "Goods In, Unit 4, Example Industrial Park, Long Road Name, Some Very Long Town Name, West Yorkshire\nLS1 1AA",
        ])['values'] + ['confirmed' => true, 'version' => 3];
        $pdf = (new PurchaseOrderPdf(false))->render(self::data(['company' => $company]));
        foreach (["(Caf\xE9 Zo\xEB Vapes Ltd) Tj", "(Vape and Go \x96 Stra\xDFe) Tj", '(Company no. SC123456) Tj', '(VAT no. GB 123 4567 82) Tj',
            "(Caf\xE9 Zo\xEB Vapes Ltd \xB7 Company no. SC123456 \xB7 VAT no. GB 123 4567 82 \xB7 PO-000007 \xB7 page 1/1) Tj", '(LS1 1AA) Tj'] as $text) {
            self::assertStringContainsString($text, $pdf, $text);
        }
        // The long delivery line is wrapped over two lines of the box, every word kept (never cut with "...").
        $texts = self::texts($pdf);
        $at = array_search('Deliver to', $texts, true);
        self::assertIsInt($at);
        self::assertSame('Goods In, Unit 4, Example Industrial Park, Long Road Name, Some Very Long Town Name, West Yorkshire',
            $texts[$at + 1] . ' ' . $texts[$at + 2], 'two lines, joined at a space');
        self::assertSame('LS1 1AA', $texts[$at + 3]);
        self::assertStringNotContainsString("\x85", $pdf, 'nothing is cut');
        foreach (['NOT CONFIRMED', '[to be confirmed]'] as $absent) {
            self::assertStringNotContainsString($absent, $pdf);
        }

        // "Not VAT registered": no VAT line, no VAT in the footer (an empty VAT number not said yet prints [to be confirmed]).
        $none = (new PurchaseOrderPdf(false))->render(self::data(['company' => ['vat_registered' => false, 'vat_number' => ''] + $company]));
        self::assertStringNotContainsString('VAT no. ', substr($none, 0, (int) strpos($none, '(Supplier) Tj')), 'no VAT line in the letterhead');
        self::assertStringContainsString("(Caf\xE9 Zo\xEB Vapes Ltd \xB7 Company no. SC123456 \xB7 PO-000007 \xB7 page 1/1) Tj", $none);
        self::assertStringContainsString('(VAT no. GB999) Tj', $none, "the supplier's VAT number still prints");
        $unknown = (new PurchaseOrderPdf(false))->render(self::data(['company' => ['vat_registered' => null, 'vat_number' => ''] + $company]));
        self::assertStringContainsString('(VAT no. [to be confirmed]) Tj', $unknown);

        // A legal name too long for the footer is cut there with "..." (0x85); the page number always shows.
        $long = (new PurchaseOrderPdf(false))->render(self::data(['company' => ['legal_name' => str_repeat('Very Long Name ', 10) . 'Ltd'] + $company]));
        self::assertMatchesRegularExpression('/\x85 \xB7 page 1\/1\) Tj/', $long);
        self::assertStringContainsString('(Very Long Name Very Long Name', $long, 'the letterhead wraps it in full');
    }

    public function testTheSampleOrder(): void
    {
        $company = ['legal_name' => 'Example Vapes Ltd', 'trading_name' => '', 'address' => "1 High Street\nLeeds", 'company_number' => '01234567', 'vat_number' => 'GB123456782',
            'phone' => '', 'email' => 'buying@example.co.uk', 'delivery_address' => "Unit 4\nLeeds", 'confirmed' => true, 'vat_registered' => true, 'version' => 2];
        $pdf = (new PurchaseOrderPdf(false))->render(PurchaseOrderPdf::sampleData($company, '2026-10-02', 'Please quote our order number.'));
        foreach (["(SAMPLE \x97 NOT AN ORDER) Tj", '(Order no.) Tj', '(SAMPLE) Tj', '(Example Vapes Ltd) Tj', '(VAT no. GB 123 4567 82) Tj', '(Example Supplier \(Example Supplier Ltd\)) Tj',
            '(Unit 4) Tj', '(2026-10-02) Tj', '(Please quote our order number.) Tj', "\xB7 SAMPLE \xB7 page 1/1) Tj", '(Phone: [to be confirmed]) Tj'] as $text) {
            self::assertStringContainsString($text, $pdf, $text);
        }
        foreach (['DRAFT', 'NOT CONFIRMED', 'PO-0'] as $absent) {
            self::assertStringNotContainsString($absent, $pdf, $absent);
        }
        $unconfirmed = (new PurchaseOrderPdf(false))->render(PurchaseOrderPdf::sampleData(['confirmed' => false] + $company, '2026-10-02', ''));
        self::assertStringContainsString("(COMPANY DETAILS NOT CONFIRMED \x97 DO NOT SEND) Tj", $unconfirmed);
        self::assertStringContainsString("(SAMPLE \x97 NOT AN ORDER) Tj", $unconfirmed);
    }

    /** @return list<string> the content stream of each page, in page order (uncompressed) */
    private static function pageStreams(string $pdf): array
    {
        preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);
        return array_values(array_filter($m[1], static fn (string $c): bool => str_contains($c, ' Tj')));
    }

    /**
     * Review finding: at the largest values the form accepts (names of 160 characters, both addresses 8 lines of 100 wide
     * capitals, a 191-character e-mail), the Supplier and Deliver-to boxes are drawn whole on one page, with every delivery line
     * inside them, never past the page's edge.
     */
    public function testTheBoxesStayWholeAtTheLargestAcceptedValues(): void
    {
        $lines = static fn (string $tag): string => implode("\n", array_map(static fn (int $i): string => rtrim(substr(str_repeat("{$tag}{$i}WWW ", 20), 0, 100)), range(1, 8)));
        $company = CompanyDetails::check([
            'legal_name' => str_repeat('LEGALNAME ', 15) . 'LEGALNAMEX', 'trading_name' => str_repeat('TRADINGNA ', 15) . 'TRADINGNAX', 'company_number' => '01234567',
            'vat_registered' => 'yes',
            'vat_number' => 'GB123456782', 'address' => $lines('R'), 'phone' => '+44 (0)113 496 0000', 'email' => str_repeat('a', 64) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 62) . '.uk',
            'delivery_address' => $lines('K'),
        ])['values'] + ['confirmed' => true, 'version' => 9];
        self::assertSame([160, 160, 191], [mb_strlen($company['legal_name']), mb_strlen($company['trading_name']), mb_strlen($company['email'])], 'at their limits');
        $pdf = (new PurchaseOrderPdf(false))->render(self::data(['company' => $company]));
        $pages = self::pageStreams($pdf);
        $k = 72 / 25.4;
        $deliverPage = null;
        foreach ($pages as $i => $c) {
            if (str_contains($c, '(Deliver to) Tj')) {
                $deliverPage = $i;
            }
        }
        self::assertIsInt($deliverPage);
        self::assertSame(1, $deliverPage, 'the long letterhead leaves too little room on page 1: the boxes start page 2');
        foreach ($pages as $i => $c) {
            preg_match_all('/([\d.]+) ([\d.]+) ([\d.]+) (-[\d.]+) re S/', $c, $r, PREG_SET_ORDER);
            $boxes = array_values(array_filter($r, static fn (array $x): bool => abs((float) $x[3] - 88 * $k) < 0.1));
            if ($i !== $deliverPage) {
                self::assertSame([], $boxes, "page {$i} has no box");
                self::assertStringNotContainsString('K1W', $c, "page {$i} has no delivery line");
                continue;
            }
            self::assertCount(2, $boxes, 'Supplier and Deliver to');
            foreach ($boxes as $b) {
                self::assertGreaterThanOrEqual(0.0, (float) $b[2] + (float) $b[4], 'the box ends above the bottom edge of the page');
                self::assertLessThanOrEqual(297 * $k, (float) $b[2]);
                self::assertGreaterThan(18 * $k, (float) $b[2] + (float) $b[4], 'and above the footer');
            }
            // Every word of every delivery line is on this page (wrapped, never cut).
            $text = implode(' ', self::texts($c));
            for ($n = 1; $n <= 8; $n++) {
                self::assertSame(substr_count($lines('K'), "K{$n}W"), substr_count($text, "K{$n}W"), "delivery line {$n}");
            }
        }
    }

    /** I98: an approved order whose company snapshot carries a change a reviewer rejected says so in red. */
    public function testTheRejectedBanner(): void
    {
        $pdf = (new PurchaseOrderPdf(false))->render(self::data(['company_rejected' => true]));
        self::assertStringContainsString("(COMPANY DETAILS REJECTED AT REVIEW \x97 DO NOT SEND) Tj", $pdf);
        self::assertStringNotContainsString('REJECTED AT REVIEW', (new PurchaseOrderPdf(false))->render(self::data()));
    }
}
