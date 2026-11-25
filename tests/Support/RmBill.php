<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * SYNTHETIC Royal Mail OBA bills in the page's real shape (cw_work/delivery_cost_20261123/RM_BILL_PAGE_SHAPE.md): the saved page (HTML, with
 * the session's hidden inputs, a script, the form button of a row's sales order) or its copied text (cells split by tabs). Made-up numbers
 * only: no real tracking number, order number or person.
 */
final class RmBill
{
    /**
     * A bill line.
     *
     * @param string $ref the reference text as printed ("CLICK AND DROP M2281", "ADJ5155465848RCN Surcharge", "943", "")
     * @param ?string $sales the form button's sales order (10 digits) or none
     * @return array<string, mixed>
     */
    public static function line(string $day, string $product, int $qty, int $net, string $ref = '', ?string $sales = null, string $vat = 'T', ?int $gross = null): array
    {
        return ['day' => $day, 'product' => $product, 'qty' => $qty, 'net' => $net, 'gross' => $gross ?? self::gross($net), 'ref' => $ref, 'sales' => $sales, 'vat' => $vat];
    }

    /** The gross of a T line: net + 20% rounded half up (whole pence). */
    public static function gross(int $net): int
    {
        return $net + intdiv(abs($net) * 20 + 50, 100) * ($net < 0 ? -1 : 1);
    }

    /** "GBP 18,859.74" */
    public static function gbp(int $p): string
    {
        return ($p < 0 ? 'GBP -' : 'GBP ') . number_format(intdiv(abs($p), 100)) . '.' . str_pad((string) (abs($p) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The totals of the lines as the page prints them: Royal Mail rounds each row's VAT on its own, so the VAT is the rows' gross less their net
     * (per code too).
     *
     * @param list<array<string, mixed>> $lines
     * @return array{net: int, vat: int, gross: int, by_code: array<string, array{net: int, vat: int}>}
     */
    public static function totals(array $lines): array
    {
        $by = [];
        foreach ($lines as $l) {
            $by[$l['vat']]['net'] = ($by[$l['vat']]['net'] ?? 0) + $l['net'];
            $by[$l['vat']]['vat'] = ($by[$l['vat']]['vat'] ?? 0) + $l['gross'] - $l['net'];
        }
        $vat = array_sum(array_column($by, 'vat'));
        $net = array_sum(array_column($lines, 'net'));
        return ['net' => $net, 'vat' => $vat, 'gross' => $net + $vat, 'by_code' => $by];
    }

    /**
     * The saved page.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $over account, invoice, date ("Nov 16, 2026"), net/vat/gross (pence, else the lines'), no_summary
     */
    public static function page(array $lines, array $over = []): string
    {
        $t = self::totals($lines);
        $net = $over['net'] ?? $t['net'];
        $vat = $over['vat'] ?? $t['vat'];
        $gross = $over['gross'] ?? $net + $vat;
        $acct = $over['account'] ?? '0706703000';
        $inv = $over['invoice'] ?? '9070000001';
        $date = $over['date'] ?? 'Nov 16, 2026';
        $rows = '';
        foreach ($lines as $l) {
            [$y, $m, $d] = explode('-', $l['day']);
            $form = $l['sales'] === null ? '' : '<form method="post" action="getOrderDetails.sap"><input type="submit" value="' . $l['sales'] . '"></form>'
                . '<input type="hidden" name="sapSession" value="SESSION-' . substr(sha1(json_encode($l, JSON_THROW_ON_ERROR) . ($over['session'] ?? '')), 0, 12)
                . ' Customer Test Person, 1 Made Up Street">';
            $rows .= "<tr><td>{$d}:{$m}:{$y}</td><td>{$l['product']}</td><td>" . number_format($l['qty'], 3, '.', '') . '</td><td>A DESCRIPTION OF THE PRODUCT</td>'
                . $form . '<td>' . ($l['sales'] === null ? '' : '/ ') . htmlspecialchars((string) $l['ref']) . '</td><td>' . self::gbp($l['net']) . '</td><td>'
                . self::gbp($l['gross']) . '</td><td>' . $l['vat'] . "</td></tr>\n";
        }
        $summary = '';
        if (!($over['no_summary'] ?? false)) {
            foreach ($t['by_code'] as $code => $f) {
                $summary .= '<tr><td></td><td>' . $code . '</td><td>' . ($code === 'T' ? 'Std Rated 20%' : 'Zero Rated 0%') . '</td><td>' . self::gbp($f['net']) . '</td><td>'
                    . self::gbp($f['vat']) . '</td><td>' . self::gbp($f['net'] + $f['vat']) . "</td></tr>\n";
            }
            $summary .= '<tr><td></td><td>Total</td><td></td><td></td><td></td><td>' . self::gbp($gross) . "</td></tr>\n";
        }
        $heads = '<tr><th>Service Rendered Date</th><th>Product Number</th><th>Quantity</th><th>Product Description</th><th>Sales Order/Your Reference</th>'
            . '<th>Net Price</th><th>Gross Price</th><th>VAT Code</th></tr>';
        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>SAP Biller Direct</title><script>var t = 'Total Price Net: GBP 1.00';</script>"
            . "<style>td { color: #000 }</style></head><body>\n"
            . '<input type="hidden" name="sessionToken" value="Invoice: 1111111111 Total Price Net: GBP 9.99">'
            . "<table><tr><td>Customer Account Number:</td><td>{$acct}</td></tr><tr><td>Legal Entity Number:</td><td>LE1</td></tr>"
            . "<tr><td>Date:</td><td>{$date}</td></tr><tr><td>Due Date:</td><td>Nov 30, 2026</td></tr>"
            . '<tr><td>Total Price Net:</td><td>' . self::gbp($net) . '</td></tr><tr><td>VAT:</td><td>' . self::gbp($vat) . '</td></tr>'
            . '<tr><td>Total Gross Price:</td><td>' . self::gbp($gross) . "</td></tr><tr><td>Invoice:</td><td>{$inv}</td></tr></table>\n"
            . "<table class=\"lines\">{$heads}\n{$rows}{$summary}</table>\n</body></html>\n";
    }

    /**
     * The page's text as select-all and copy gives it (cells split by tabs).
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $over as page()
     */
    public static function text(array $lines, array $over = []): string
    {
        $t = self::totals($lines);
        $net = $over['net'] ?? $t['net'];
        $vat = $over['vat'] ?? $t['vat'];
        $gross = $over['gross'] ?? $net + $vat;
        $out = "Invoice\nCustomer Account Number:\t" . ($over['account'] ?? '0706703000') . "\nLegal Entity Number:\tLE1\nDate:\t" . ($over['date'] ?? 'Nov 16, 2026')
            . "\nDue Date:\tNov 30, 2026\nTotal Price Net:\t" . self::gbp($net) . "\nVAT:\t" . self::gbp($vat) . "\nTotal Gross Price:\t" . self::gbp($gross)
            . "\nInvoice:\t" . ($over['invoice'] ?? '9070000001') . "\n\n" . implode("\t", \CW\Profit\RoyalMailBillPage::LINE_HEADINGS) . "\n";
        foreach ($lines as $l) {
            [$y, $m, $d] = explode('-', $l['day']);
            $out .= implode("\t", ["{$d}:{$m}:{$y}", $l['product'], number_format($l['qty'], 3, '.', ''), 'A DESCRIPTION OF THE PRODUCT',
                ($l['sales'] === null ? '' : $l['sales'] . "\t/ ") . $l['ref'], self::gbp($l['net']), self::gbp($l['gross']), $l['vat']]) . "\n";
        }
        if (!($over['no_summary'] ?? false)) {
            foreach ($t['by_code'] as $code => $f) {
                $out .= "\t{$code}\t" . ($code === 'T' ? 'Std Rated 20%' : 'Zero Rated 0%') . "\t" . self::gbp($f['net']) . "\t" . self::gbp($f['vat']) . "\t"
                    . self::gbp($f['net'] + $f['vat']) . "\n";
            }
            $out .= "\tTotal\t\t\t\t" . self::gbp($gross) . "\n";
        }
        return $out;
    }

    /**
     * The page's text as a browser's page reader gives it (the shape of the owner's three bills of Nov 2026, made-up numbers): no tabs, the
     * top block's labels and values on lines of their own, the heading's words joined by spaces, each bill line on two lines (the second
     * starting "/ " and ending with the amounts and the VAT code), the surcharge note, the VAT summary and the total.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $over as page()
     */
    public static function spaced(array $lines, array $over = []): string
    {
        $t = self::totals($lines);
        $net = $over['net'] ?? $t['net'];
        $vat = $over['vat'] ?? $t['vat'];
        $gross = $over['gross'] ?? $net + $vat;
        $out = "Invoice\n\nRoyal Mail Group Ltd.\n1 Made Up Walk,\nNOWHERETON\nZZ1 1ZZ\nCustomer Account Number: " . ($over['account'] ?? '0706703000')
            . "\nLegal Entity Number: 1000000000\nCustomer VAT Reg No:\n\nDate:\n" . ($over['date'] ?? 'Nov 16, 2026') . "\nTotal Price Net:\n" . self::gbp($net) . "\nVAT:\n"
            . self::gbp($vat) . "\nTotal Gross Price:\n" . self::gbp($gross) . "\n\nVAT Reg Number GB 000 0000 00\n\nTEST SHOP LIMITED\n1 Imaginary Road\n\nInvoice: "
            . ($over['invoice'] ?? '9070000001') . "\n" . implode(' ', \CW\Profit\RoyalMailBillPage::LINE_HEADINGS) . "\n";
        foreach ($lines as $l) {
            [$y, $m, $d] = explode('-', $l['day']);
            $out .= "{$d}:{$m}:{$y} {$l['product']} " . number_format($l['qty'], 3, '.', '') . " A DESCRIPTION OF THE PRODUCT\n/ " . ($l['ref'] === '' ? '' : $l['ref'] . ' ')
                . self::gbp($l['net']) . ' ' . self::gbp($l['gross']) . ' ' . $l['vat'] . "\n";
        }
        $out .= "\nSurcharge included on Invoice GBP 1,234.56\n\nVAT Summary\nVAT Code VAT Description Net Total VAT Total Gross Total\n";
        foreach ($t['by_code'] as $code => $f) {
            $out .= "{$code} " . ($code === 'T' ? 'Std Rated 20%' : 'Zero Rated 0%') . ' ' . self::gbp($f['net']) . ' ' . self::gbp($f['vat']) . ' ' . self::gbp($f['net'] + $f['vat']) . "\n";
        }
        return $out . 'Total ' . self::gbp($gross) . "\n\nPlease Pay By: Nov 23, 2026 Terms of payment: 7 Days\n";
    }

    /**
     * The page's text as Chrome on a Mac copies it (CW-1181, the bill of 23 Nov 2026): cells split by tabs, but each bill line broken where its
     * reference cell starts, so it comes on TWO lines: "date, product, quantity, description" (a trailing tab or none), then the reference cell
     * (with its sales order when it has one), the net, the gross and the VAT code (a leading tab or none); a description of $longDescription
     * runs onto a line of its own ("(HV)").
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $over as page()
     */
    public static function macText(array $lines, array $over = [], int $longDescription = -1): string
    {
        $t = self::totals($lines);
        $net = $over['net'] ?? $t['net'];
        $vat = $over['vat'] ?? $t['vat'];
        $gross = $over['gross'] ?? $net + $vat;
        $out = "Invoice\nCustomer Account Number:\t" . ($over['account'] ?? '0706703000') . "\nLegal Entity Number:\tLE1\nDate:\t" . ($over['date'] ?? 'Nov 16, 2026')
            . "\nDue Date:\tNov 30, 2026\nTotal Price Net:\t" . self::gbp($net) . "\nVAT:\t" . self::gbp($vat) . "\nTotal Gross Price:\t" . self::gbp($gross)
            . "\nInvoice:\t" . ($over['invoice'] ?? '9070000001') . "\n\n" . implode("\t", \CW\Profit\RoyalMailBillPage::LINE_HEADINGS) . "\n";
        foreach ($lines as $i => $l) {
            [$y, $m, $d] = explode('-', $l['day']);
            $long = $i === $longDescription;
            $ref = array_values(array_filter([$l['sales'] ?? '', ($l['sales'] === null ? '' : '/ ') . $l['ref']], static fn (string $c): bool => $c !== ''));
            $money = [self::gbp($l['net']), self::gbp($l['gross']), $l['vat']];
            if (in_array($i, $over['break_after_ref'] ?? [], true)) {
                // M1 (Codex final of 86aebbd): the copy breaks AFTER the reference cell: "date, product, quantity, description, reference" then
                // "net, gross, VAT code"
                $prefix = isset($over['amount_prefix'][$i]) ? [(string) $over['amount_prefix'][$i]] : [];
                $out .= implode("\t", ["{$d}:{$m}:{$y}", $l['product'], number_format($l['qty'], 3, '.', ''), 'A DESCRIPTION OF THE PRODUCT', ...$ref]) . "\n"
                    . ($long ? "(HV)\n" : '') . implode("\t", [...$prefix, ...$money]) . "\n";
                continue;
            }
            $out .= implode("\t", ["{$d}:{$m}:{$y}", $l['product'], number_format($l['qty'], 3, '.', ''), $long ? 'ROYAL MAIL TRACKED 48' : 'A DESCRIPTION OF THE PRODUCT'])
                . ($i % 2 === 0 ? "\t" : '') . "\n" . ($long ? "(HV)\n" : '')
                . ($i % 3 === 0 ? "\t" : '') . implode("\t", [...$ref, ...$money]) . "\n";
        }
        if (!($over['no_summary'] ?? false)) {
            foreach ($t['by_code'] as $code => $f) {
                $out .= "\t{$code}\t" . ($code === 'T' ? 'Std Rated 20%' : 'Zero Rated 0%') . "\t" . self::gbp($f['net']) . "\t" . self::gbp($f['vat']) . "\t"
                    . self::gbp($f['net'] + $f['vat']) . "\n";
            }
            $out .= "\tTotal\t\t\t\t" . self::gbp($gross) . "\n";
        }
        return $out;
    }

    /** Royal Mail's product codes (carrier_product's seed). */
    public const PRODUCTS = ['TPL', 'TPM', 'TRN', 'SD1', 'RX1', 'RXA', 'RW4', 'TSS'];
}
