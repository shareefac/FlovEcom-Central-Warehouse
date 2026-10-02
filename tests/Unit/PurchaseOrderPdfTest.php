<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrderPdf;
use PHPUnit\Framework\TestCase;

/**
 * The PO PDF (spec §6.7; I48-I59), uncompressed so the page text can be read: the letterhead from the company settings or
 * the posting snapshot, [to be confirmed] for empty values, the three banners, "Amends PO-x", a long order over several
 * pages with the table header repeated, the totals and VAT per code, £ in Windows-1252, the footer, the cancellation.
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
}
