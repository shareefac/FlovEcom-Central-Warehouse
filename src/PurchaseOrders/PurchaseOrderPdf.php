<?php

declare(strict_types=1);

namespace CW\PurchaseOrders;

use CW\Company\CompanyDetails;
use CW\Output\Fpdf;
use CW\Output\PdfWriter;

/**
 * The purchase order a supplier receives (spec §6.7; docs/decisions.md I48-I59), replacing ERPNext's three PO print formats:
 * A4 portrait, 15 mm margins, Helvetica (a PDF core font: nothing embedded or fetched), every string through
 * PdfWriter::text() (Windows-1252: £, ×, — survive).
 *
 *   banners (red)      DRAFT — NOT AN ORDER (not approved; SAMPLE — NOT AN ORDER for the Company details screen's sample);
 *                      CANCELLED — see PO-x (reversed); COMPANY DETAILS NOT CONFIRMED — DO NOT SEND (company `confirmed`
 *                      false: the Company details in use for a draft, the snapshot when posted); COMPANY DETAILS REJECTED AT
 *                      REVIEW — DO NOT SEND (`company_rejected`: an approved order whose snapshot carries a change a
 *                      reviewer rejected, I98)
 *   top left           the buying company (decision 9; CW\Company\CompanyDetails since 0013, I96): legal name, trading name,
 *                      address, phone, e-mail, company and VAT numbers ("GB 123 4567 89"; no VAT line for a company that
 *                      said it is not VAT registered). Only what is given is printed (the owner, 18 Nov 2026; CQ2): an
 *                      empty legal name or registered address (the two details a confirmation needs) prints [to be confirmed],
 *                      any other empty detail prints no line at all, never a label with nothing after it
 *   top right          PURCHASE ORDER, the number (or "draft #id", or SAMPLE), order date, expected delivery, the supplier's
 *                      quote reference, "Amends PO-x"
 *   two boxes          Supplier (name, code, address, VAT no., contact, e-mail) and Deliver to (the delivery address, or the
 *                      registered address when none is given, CQ3; long lines wrapped, never cut), both on one page (a new page when the letterhead leaves too little room)
 *   lines (180 mm)     # · Supplier code · CW code · Description · Pack ("box ×24") · Packs · Units · Pack price · VAT · Net;
 *                      the header repeats on every page; a charge line leaves the codes and pack columns blank
 *   totals             lines / units; Net; VAT per code ("VAT S 20%  £x"); Total
 *   notes              the PO's note ("Notes to supplier"), then po.terms
 *   footer             "<legal name> · Company no. · VAT no. · <number> · page n/{nb}" (the numbers only when given)
 *
 * A posted PO prints its snapshots (what was approved), a draft the current company details and supplier
 * (PurchaseOrders::pdfData); a cancellation document prints "CANCELLATION OF PO-x" with the original's lines and the reason;
 * sampleData() is the made-up order of "See how a purchase order will look". Pure: it draws what it is given, no database
 * (PurchaseOrderPdfTest).
 *
 * Items only ($itemsOnly; the owner, 18 Nov 2026: "the table I only need number, supplier code if there is any else leave it empty,
 * CW code, item name, qty, no amount details, no VAT details, nothing"; docs/decisions.md PL1-PL5): the same banners, letterhead,
 * order details, boxes, notes, terms and footer, from the same data; the lines table is No. · Supplier code · CW code · Item name
 * (wrapped, never cut) · Qty ("5 boxes of 10 = 50", "24": quantity()), item lines only (a charge is money), its header repeated on
 * every page; no totals.
 */
final class PurchaseOrderPdf
{
    public const TBC = '[to be confirmed]';
    private const ROW_H = 5.5;
    /** @var list<array{title: string, w: float, align: string}> */
    private const COLS = [
        ['title' => '#', 'w' => 7, 'align' => 'R'],
        ['title' => 'Supplier code', 'w' => 22, 'align' => 'L'],
        ['title' => 'CW code', 'w' => 19, 'align' => 'L'],
        ['title' => 'Description', 'w' => 50, 'align' => 'L'],
        ['title' => 'Pack', 'w' => 17, 'align' => 'L'],
        ['title' => 'Packs', 'w' => 11, 'align' => 'R'],
        ['title' => 'Units', 'w' => 13, 'align' => 'R'],
        ['title' => 'Pack price', 'w' => 16, 'align' => 'R'],
        ['title' => 'VAT', 'w' => 9, 'align' => 'L'],
        ['title' => 'Net', 'w' => 16, 'align' => 'R'],
    ];
    /** @var list<array{title: string, w: float, align: string}> the items-only table (PL2): 180 mm, as COLS */
    private const ITEM_COLS = [
        ['title' => 'No.', 'w' => 10, 'align' => 'R'],
        ['title' => 'Supplier code', 'w' => 32, 'align' => 'L'],
        ['title' => 'CW code', 'w' => 22, 'align' => 'L'],
        ['title' => 'Item name', 'w' => 78, 'align' => 'L'],
        ['title' => 'Qty', 'w' => 38, 'align' => 'R'],
    ];
    /** An item name's lines on the items-only PDF: their height (mm) and how many at most (the last one cut with "…" past that). */
    private const NAME_LH = 4.0;
    private const NAME_LINES = 4;

    /** @param bool $itemsOnly the order without prices (PL1): No., Supplier code, CW code, Item name, Qty; no money anywhere */
    public function __construct(private readonly bool $compress = true, private readonly bool $itemsOnly = false)
    {
    }

    /**
     * The PDF's bytes.
     *
     * @param array<string, mixed> $d PurchaseOrders::pdfData()
     */
    public function render(array $d): string
    {
        $company = $d['company'];
        $label = (string) $d['label'];
        $cancel = $d['cancellation'] ?? null;
        $sample = ($d['sample'] ?? false) === true;
        // Only the details given are printed (CQ2): no VAT number given, or "Not VAT registered" (said explicitly, I92), prints no
        // VAT line at all; no company number, phone or e-mail prints no line either. Only the two details a confirmation needs (the
        // legal name, the registered address) say [to be confirmed] while missing: only unconfirmed details lack them (DO NOT SEND).
        $vat = ($company['vat_registered'] ?? null) === false ? null : self::given(CompanyDetails::formatVat(trim((string) ($company['vat_number'] ?? ''))));
        $companyNo = self::given($company['company_number'] ?? '');
        $footer = self::val($company['legal_name'] ?? '') . ($companyNo !== null ? ' · Company no. ' . $companyNo : '')
            . ($vat !== null ? ' · VAT no. ' . $vat : '') . ' · ' . ($cancel !== null ? (string) $cancel['number'] : $label);
        $pdf = new Fpdf($footer);
        $pdf->SetCompression($this->compress);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AliasNbPages();
        $pdf->SetCreator('Central Warehouse', true);
        $pdf->SetTitle(($cancel !== null ? 'Cancellation of ' : ($sample ? 'Sample purchase order ' : 'Purchase order ')) . $label . ($this->itemsOnly ? ' (items only)' : ''), true);
        $pdf->AddPage();

        // Banners
        $banners = [];
        if ($sample) {
            $banners[] = 'SAMPLE — NOT AN ORDER';
        } elseif (!($d['posted'] ?? false)) {
            $banners[] = 'DRAFT — NOT AN ORDER';
        }
        if ($cancel === null && ($d['cancelled_by'] ?? null) !== null) {
            $banners[] = 'CANCELLED — see ' . $d['cancelled_by'];
        }
        if (!($company['confirmed'] ?? false)) {
            $banners[] = 'COMPANY DETAILS NOT CONFIRMED — DO NOT SEND';
        }
        if (($d['company_rejected'] ?? false) === true) {
            $banners[] = 'COMPANY DETAILS REJECTED AT REVIEW — DO NOT SEND';
        }
        foreach ($banners as $b) {
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->SetTextColor(190, 0, 0);
            $pdf->Cell(0, 6, PdfWriter::text($b), 0, 1, 'C');
        }
        $pdf->SetTextColor(0, 0, 0);
        if ($banners !== []) {
            $pdf->Ln(2);
        }

        // Top left: the buying company; top right: the order
        $top = $pdf->GetY();
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetXY(15, $top);
        $pdf->MultiCell(100, 6, PdfWriter::text(self::val($company['legal_name'] ?? '')), 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $left = [];
        if (trim((string) ($company['trading_name'] ?? '')) !== '') {
            $left[] = (string) $company['trading_name'];
        }
        $addr = self::lines((string) ($company['address'] ?? ''));
        array_push($left, ...($addr === [] ? [self::TBC] : $addr));
        foreach (['Phone: ' => self::given($company['phone'] ?? ''), 'E-mail: ' => self::given($company['email'] ?? ''), 'Company no. ' => $companyNo,
            'VAT no. ' => $vat] as $what => $value) {
            if ($value !== null) {
                $left[] = $what . $value;
            }
        }
        foreach ($left as $l) {
            $pdf->SetX(15);
            $pdf->MultiCell(100, 4.5, PdfWriter::text($l), 0, 'L');
        }
        $leftEnd = $pdf->GetY();

        $pdf->SetXY(120, $top);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(75, 8, PdfWriter::text($cancel !== null ? 'CANCELLATION' : 'PURCHASE ORDER'), 0, 1, 'R');
        $pdf->SetFont('Helvetica', '', 9);
        $right = [];
        if ($cancel !== null) {
            $right[] = ['Cancellation', (string) $cancel['number']];
            $right[] = ['Of order', $label];
            $right[] = ['Cancelled on', (string) ($cancel['date'] ?? '')];
        } else {
            $right[] = ['Order no.', $sample ? 'SAMPLE' : ($d['number'] === null ? 'draft #' . $d['id'] : $label)];
        }
        $right[] = ['Order date', (string) ($d['order_date'] ?? '')];
        if (($d['expected_date'] ?? null) !== null) {
            $right[] = ['Expected delivery', (string) $d['expected_date']];
        }
        if (($d['external_ref'] ?? null) !== null) {
            $right[] = ['Your ref', (string) $d['external_ref']];
        }
        if (($d['amends'] ?? null) !== null) {
            $right[] = ['Amends', (string) $d['amends']];
        }
        foreach ($right as [$k, $v]) {
            $pdf->SetX(120);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(32, 5, PdfWriter::text($k), 0, 0, 'L');
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->Cell(43, 5, $this->fit($pdf, PdfWriter::text($v), 43), 0, 1, 'R');
        }
        $pdf->SetY(max($leftEnd, $pdf->GetY()) + 4);

        // Supplier and Deliver to
        $s = $d['supplier'];
        $supplierLines = array_values(array_filter([
            (string) ($s['legal_name'] ?? '') !== '' && $s['legal_name'] !== $s['name'] ? $s['name'] . ' (' . $s['legal_name'] . ')' : (string) ($s['name'] ?? ''),
            'Supplier code ' . ($s['code'] ?? ''),
            implode(', ', array_filter([$s['address_line1'] ?? null, $s['address_line2'] ?? null, $s['city'] ?? null], static fn (?string $v): bool => $v !== null && $v !== '')),
            trim(($s['postcode'] ?? '') . ' ' . ($s['country'] ?? '')),
            ($s['vat_number'] ?? null) !== null ? 'VAT no. ' . $s['vat_number'] : null,
            ($s['contact_name'] ?? null) !== null ? 'Contact: ' . $s['contact_name'] : null,
            ($s['email'] ?? null) !== null ? 'E-mail: ' . $s['email'] : null,
        ], static fn (?string $v): bool => $v !== null && trim($v) !== ''));
        // The delivery address is wrapped to the box, never cut: a driver must be able to read all of it (I96). None given: the
        // registered address (CQ3).
        $pdf->SetFont('Helvetica', '', 9);
        $deliver = [];
        foreach (self::lines(CompanyDetails::deliveryAddress($company)) as $line) {
            array_push($deliver, ...$this->wrap($pdf, PdfWriter::text($line), 84));
        }
        $h = 6 + 4.5 * max(count($supplierLines), max(1, count($deliver))) + 2;
        // The two boxes stay whole on one page (a long letterhead may leave too little room under it): at most 8 address
        // lines of 100 characters wrap to about 116 mm, which fits a fresh page.
        if ($pdf->GetY() + $h > $pdf->breakAt()) {
            $pdf->AddPage();
        }
        $boxTop = $pdf->GetY();
        $pdf->Rect(15, $boxTop, 88, $h);
        $pdf->Rect(107, $boxTop, 88, $h);
        $pdf->SetXY(17, $boxTop + 1.5);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(84, 5, PdfWriter::text('Supplier'), 0, 2, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        foreach ($supplierLines as $l) {
            $pdf->SetX(17);
            $pdf->Cell(84, 4.5, $this->fit($pdf, PdfWriter::text($l), 84), 0, 2, 'L');
        }
        $pdf->SetXY(109, $boxTop + 1.5);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(84, 5, PdfWriter::text('Deliver to'), 0, 2, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        foreach ($deliver === [] ? [PdfWriter::text(self::TBC)] : $deliver as $l) {
            $pdf->SetX(109);
            $pdf->Cell(84, 4.5, $l, 0, 2, 'L');
        }
        $pdf->SetY($boxTop + $h + 5);

        if ($cancel !== null) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->MultiCell(0, 5, PdfWriter::text("Order {$label} is cancelled. Please do not deliver it."), 0, 'L');
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->MultiCell(0, 5, PdfWriter::text('Reason: ' . $cancel['reason'] . (($cancel['note'] ?? null) !== null ? ' — ' . $cancel['note'] : '')), 0, 'L');
            $pdf->Ln(2);
        }

        if ($this->itemsOnly) {
            $this->itemsTable($pdf, $d['lines']);
        } else {
            $this->pricedTable($pdf, $d);
        }

        // Notes and terms
        if (($d['note'] ?? null) !== null && $d['note'] !== '') {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(0, 5, PdfWriter::text('Notes to supplier'), 0, 1, 'L');
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->MultiCell(0, 4.5, PdfWriter::text((string) $d['note']), 0, 'L');
            $pdf->Ln(2);
        }
        if (trim((string) ($d['terms'] ?? '')) !== '') {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(0, 5, PdfWriter::text('Terms'), 0, 1, 'L');
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->MultiCell(0, 4, PdfWriter::text((string) $d['terms']), 0, 'L');
        }
        return $pdf->Output('S');
    }

    /**
     * The made-up order of "See how a purchase order will look" (the Company details screen, I96): no number, marked SAMPLE,
     * a fictional supplier and three lines, with the company details given ($company: CompanyDetails::company()).
     *
     * @param array<string, mixed> $company
     * @return array<string, mixed>
     */
    public static function sampleData(array $company, string $orderDate, string $terms): array
    {
        $lines = [
            ['line_no' => 1, 'kind' => 'item', 'supplier_code' => 'EX-1001', 'sku_code' => 'CW-000001', 'description' => 'Example disposable vape, 20mg, strawberry',
                'purchase_unit' => 'box', 'units_per_pack' => 10, 'packs' => 5, 'units' => 50, 'pack_price' => '25.0000', 'vat_code' => 'S', 'amount_e2' => 12500],
            ['line_no' => 2, 'kind' => 'item', 'supplier_code' => 'EX-2002', 'sku_code' => 'CW-000002', 'description' => 'Example e-liquid 10ml, menthol',
                'purchase_unit' => 'each', 'units_per_pack' => 1, 'packs' => 24, 'units' => 24, 'pack_price' => '1.2000', 'vat_code' => 'S', 'amount_e2' => 2880],
            ['line_no' => 3, 'kind' => 'charge', 'supplier_code' => null, 'sku_code' => null, 'description' => 'Delivery', 'purchase_unit' => 'each',
                'units_per_pack' => 1, 'packs' => 1, 'units' => null, 'pack_price' => '6.5000', 'vat_code' => 'S', 'amount_e2' => 650],
        ];
        return [
            'sample' => true, 'number' => null, 'label' => 'SAMPLE', 'id' => 0, 'status' => 'draft', 'state' => null, 'posted' => false,
            'order_date' => $orderDate, 'expected_date' => null, 'external_ref' => null, 'note' => 'This is a sample: it shows how a purchase order prints the company details.',
            'amends' => null, 'cancellation' => null, 'cancelled_by' => null,
            'company' => $company,
            'supplier' => ['code' => 'EXAMPLE', 'name' => 'Example Supplier', 'legal_name' => 'Example Supplier Ltd', 'address_line1' => '1 Example Street',
                'address_line2' => null, 'city' => 'Exampletown', 'postcode' => 'EX1 1AA', 'country' => 'GB', 'vat_number' => null, 'contact_name' => null,
                'email' => 'orders@supplier.example', 'phone' => null],
            'lines' => $lines,
            'totals' => PoMath::totals(array_map(static fn (array $l): array => ['amount_e2' => $l['amount_e2'], 'vat_code' => 'S', 'rate_e2' => 2000], $lines)),
            'terms' => $terms,
        ];
    }

    /**
     * The lines with their prices and the totals (the PDF to send, I48-I59).
     *
     * @param array<string, mixed> $d
     */
    private function pricedTable(Fpdf $pdf, array $d): void
    {
        // Lines
        $this->tableHeader($pdf);
        $units = 0;
        foreach ($d['lines'] as $l) {
            if ($pdf->GetY() + self::ROW_H > $pdf->breakAt()) {
                $pdf->AddPage();
                $this->tableHeader($pdf);
            }
            $charge = $l['kind'] === 'charge';
            $units += (int) ($l['units'] ?? 0);
            $cells = [
                (string) $l['line_no'],
                $charge ? '' : (string) ($l['supplier_code'] ?? ''),
                $charge ? '' : (string) ($l['sku_code'] ?? ''),
                (string) $l['description'],
                $charge ? '' : ($l['units_per_pack'] === 1 && $l['purchase_unit'] === 'each' ? 'each' : $l['purchase_unit'] . ' ×' . $l['units_per_pack']),
                $charge ? '' : number_format((int) $l['packs']),
                $charge ? '' : number_format((int) $l['units']),
                $charge ? '' : '£' . PoMath::price((string) $l['pack_price']),
                (string) $l['vat_code'],
                PoMath::money((int) $l['amount_e2']),
            ];
            $pdf->SetFont('Helvetica', '', 8);
            foreach (self::COLS as $i => $c) {
                $pdf->Cell($c['w'], self::ROW_H, $this->fit($pdf, PdfWriter::text($cells[$i]), $c['w']), 1, 0, $c['align']);
            }
            $pdf->Ln(self::ROW_H);
        }

        // Totals
        $t = $d['totals'];
        $rows = [['Lines / units', number_format(count($d['lines'])) . ' / ' . number_format($units)], ['Net', PoMath::money((int) $t['net_e2'])]];
        foreach ($t['by_code'] as $code => $v) {
            $rows[] = ['VAT ' . $code . ' ' . self::rate((int) $v['rate_e2']) . '%', PoMath::money((int) $v['vat_e2'])];
        }
        $rows[] = ['Total', PoMath::money((int) $t['gross_e2'])];
        if ($pdf->GetY() + 5 * count($rows) + 4 > $pdf->breakAt()) {
            $pdf->AddPage();
        }
        $pdf->Ln(2);
        foreach ($rows as $i => [$k, $v]) {
            $last = $i === count($rows) - 1;
            $pdf->SetX(115);
            $pdf->SetFont('Helvetica', $last ? 'B' : '', $last ? 10 : 9);
            $pdf->Cell(45, 5.5, PdfWriter::text($k), $last ? 'T' : 0, 0, 'L');
            $pdf->Cell(35, 5.5, PdfWriter::text($v), $last ? 'T' : 0, 1, 'R');
        }
        $pdf->Ln(4);
    }

    /**
     * The items-only table (PL2): one row per item line (a charge is only money: left out), its line number as on the order, the
     * supplier's code (empty when there is none), the CW code, the item name wrapped over up to NAME_LINES lines, the quantity.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function itemsTable(Fpdf $pdf, array $lines): void
    {
        $this->tableHeader($pdf, self::ITEM_COLS);
        foreach ($lines as $l) {
            if ($l['kind'] === 'charge') {
                continue;
            }
            $pdf->SetFont('Helvetica', '', 8);
            $nameW = self::ITEM_COLS[3]['w'];
            $name = $this->wrap($pdf, PdfWriter::text((string) $l['description']), $nameW);
            if (count($name) > self::NAME_LINES) {
                $name = [...array_slice($name, 0, self::NAME_LINES - 1), $this->fit($pdf, implode(' ', array_slice($name, self::NAME_LINES - 1)), $nameW)];
            }
            $h = max(self::ROW_H, count($name) * self::NAME_LH + 1.5);
            if ($pdf->GetY() + $h > $pdf->breakAt()) {
                $pdf->AddPage();
                $this->tableHeader($pdf, self::ITEM_COLS);
            }
            $units = $l['units'] === null ? (int) $l['packs'] * (int) $l['units_per_pack'] : (int) $l['units'];
            $cells = [(string) $l['line_no'], (string) ($l['supplier_code'] ?? ''), (string) ($l['sku_code'] ?? ''), '',
                self::quantity((string) $l['purchase_unit'], (int) $l['units_per_pack'], (int) $l['packs'], $units)];
            $y = $pdf->GetY();
            $x = 15.0;
            foreach (self::ITEM_COLS as $i => $c) {
                $pdf->Rect($x, $y, $c['w'], $h);
                if ($i === 3) {
                    foreach ($name as $k => $line) {
                        $pdf->SetXY($x, $y + 0.75 + $k * self::NAME_LH);
                        $pdf->Cell($c['w'], self::NAME_LH, $line, 0, 0, 'L');
                    }
                } else {
                    // A code or a quantity is never cut while it can be read: smaller type first; a quantity too long even so prints its units.
                    $pdf->SetXY($x, $y);
                    $this->whole($pdf, PdfWriter::text($cells[$i]), $c['w'], $c['align'], $i === 4 ? 5.0 : 6.0, $i === 4 ? number_format($units) : null);
                }
                $x += $c['w'];
            }
            $pdf->SetXY(15, $y + $h);
        }
        $pdf->Ln(4);
    }

    /**
     * A line's quantity in words, as the PDF with prices prints it in Pack, Packs and Units: "5 boxes of 10 = 50"; "24" for single
     * items (each, unit); "3 cases" for packs of one; "2 packs of 6 = 12" for single items bought 6 to a pack.
     */
    public static function quantity(string $unit, int $unitsPerPack, int $packs, int $units): string
    {
        $unit = trim($unit);
        $single = in_array(strtolower($unit), ['each', 'unit', ''], true);
        if ($unitsPerPack === 1 && $single) {
            return number_format($units);
        }
        if ($single) {
            $unit = 'pack'; // "2 eaches of 6" reads as nonsense
        }
        $word = $packs === 1 ? $unit : (preg_match('/(s|x|z|ch|sh)$/i', $unit) === 1 ? $unit . 'es'
            : (preg_match('/[^aeiou]y$/i', $unit) === 1 ? substr($unit, 0, -1) . 'ies' : $unit . 's'));
        return number_format($packs) . ' ' . $word . ($unitsPerPack === 1 ? '' : ' of ' . number_format($unitsPerPack) . ' = ' . number_format($units));
    }

    /**
     * $s (Windows-1252) on one line of a cell of $w mm without cutting it: the type made smaller, down to $min pt, until it fits; then
     * $short in its place (the same way); only then cut with "…" (fit()). The type is 8 pt again after it.
     */
    private function whole(Fpdf $pdf, string $s, float $w, string $align, float $min, ?string $short = null): void
    {
        $room = $w - 2 * $pdf->cellPadding();
        $size = 8.0;
        $pdf->SetFontSize($size);
        while ($pdf->GetStringWidth($s) > $room && $size > $min) {
            $size -= 0.5;
            $pdf->SetFontSize($size);
        }
        if ($pdf->GetStringWidth($s) > $room && $short !== null) {
            $this->whole($pdf, $short, $w, $align, $min);
            return;
        }
        $pdf->Cell($w, self::ROW_H, $this->fit($pdf, $s, $w), 0, 0, $align);
        $pdf->SetFontSize(8);
    }

    /** @param list<array{title: string, w: float, align: string}> $cols */
    private function tableHeader(Fpdf $pdf, array $cols = self::COLS): void
    {
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->SetFillColor(232, 232, 232);
        foreach ($cols as $c) {
            $pdf->Cell($c['w'], self::ROW_H, $this->fit($pdf, PdfWriter::text($c['title']), $c['w']), 1, 0, $c['align'] === 'R' ? 'R' : 'L', true);
        }
        $pdf->Ln(self::ROW_H);
        $pdf->SetFont('Helvetica', '', 8);
    }

    /**
     * $s (Windows-1252) as the lines that fit a cell of $w mm at the current font: broken at spaces, a single word longer than
     * the cell broken where it must.
     *
     * @return list<string>
     */
    private function wrap(Fpdf $pdf, string $s, float $w): array
    {
        $room = $w - 2 * $pdf->cellPadding();
        $out = [];
        $line = '';
        foreach (explode(' ', str_replace(["\r", "\n"], ' ', $s)) as $word) {
            if ($word === '') {
                continue;
            }
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($pdf->GetStringWidth($try) <= $room) {
                $line = $try;
                continue;
            }
            if ($line !== '') {
                $out[] = $line;
            }
            while ($pdf->GetStringWidth($word) > $room) {
                $cut = strlen($word) - 1;
                while ($cut > 1 && $pdf->GetStringWidth(substr($word, 0, $cut)) > $room) {
                    $cut--;
                }
                $out[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            $line = $word;
        }
        if ($line !== '') {
            $out[] = $line;
        }
        return $out;
    }

    /** $s (Windows-1252) on one line, cut with "…" (0x85) to fit a cell of $w mm. */
    private function fit(Fpdf $pdf, string $s, float $w): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        $room = $w - 2 * $pdf->cellPadding();
        if ($pdf->GetStringWidth($s) <= $room) {
            return $s;
        }
        while ($s !== '' && $pdf->GetStringWidth($s . "\x85") > $room) {
            $s = substr($s, 0, -1);
        }
        return $s . "\x85";
    }

    /** A needed detail (the legal name): [to be confirmed] while it is empty. */
    private static function val(mixed $v): string
    {
        $v = trim((string) $v);
        return $v === '' ? self::TBC : $v;
    }

    /** An optional detail: null (nothing printed) while it is empty (CQ2). */
    private static function given(mixed $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    /** @return list<string> the non-empty lines of a multi-line setting */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), static fn (string $l): bool => $l !== ''));
    }

    /** "20" / "5" / "17.5" of a rate in e2. */
    private static function rate(int $e2): string
    {
        $s = intdiv($e2, 100) . '.' . str_pad((string) ($e2 % 100), 2, '0', STR_PAD_LEFT);
        return rtrim(rtrim($s, '0'), '.');
    }
}
