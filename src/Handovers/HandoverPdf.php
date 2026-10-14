<?php

declare(strict_types=1);

namespace CW\Handovers;

use CW\Company\CompanyDetails;
use CW\Output\PdfWriter;
use CW\Ui\Html;
use CW\Ui\Words;

/**
 * The invoice PDF of a hand-over (pack H; docs/decisions.md PH8, PH9), on PdfWriter like the purchase order's and the release invoice's:
 * sold by our company (its details as the hand-over printed them: confirmed, or the PDF is refused before it gets here unless the owner
 * switched that rule off, when it says "do not send"), bought by the customer, its number and date, the customer's reference, each line's
 * item, units, price, VAT code and amount, then net, VAT and the total, in pounds; the words of handovers.invoice_terms under it (typed
 * by the owner or the accountant: CW invents no legal wording). A draft says it is not an invoice; a cancelled hand-over says which record
 * cancelled it. Pure: Handovers::invoice() reads everything and decides the refusals.
 */
final class HandoverPdf
{
    /** @param array<string, mixed> $inv Handovers::invoice() */
    public static function build(array $inv): string
    {
        $rec = $inv['record'];
        $company = $inv['company'];
        $buyer = $inv['buyer'];
        $w = Words::HANDOVER_PDF;
        $number = $rec['number'] === null ? $w['draft_number'] : (string) $rec['number'];
        $sub = Words::of('DOC_STATUS', (string) $rec['status']) . ($rec['reversed_by_number'] !== null ? ' · ' . sprintf($w['cancelled'], (string) $rec['reversed_by_number']) : '');
        $pdf = new PdfWriter(sprintf($w['title'], $number), $sub, true);
        $banners = [];
        if ($rec['status'] === 'draft' || $rec['status'] === 'awaiting_approval' || $rec['status'] === 'cancelled') {
            $banners[] = $w['not_an_invoice'];
        }
        if ($rec['reversed_by_number'] !== null) {
            $banners[] = sprintf($w['cancelled_banner'], (string) $rec['reversed_by_number']);
        }
        if ($inv['banner']) {
            $banners[] = $w['do_not_send'];
        }
        foreach ($banners as $b) {
            $pdf->heading($b);
        }
        $vat = static function (array $p): ?string {
            if (($p['vat_registered'] ?? null) === false) {
                return null;
            }
            $n = trim((string) ($p['vat_number'] ?? ''));
            return $n === '' ? null : CompanyDetails::formatVat($n);
        };
        $lines = static fn (string $s): string => implode(', ', array_filter(array_map('trim', explode("\n", $s)), static fn (string $x): bool => $x !== ''));
        $pdf->heading($w['seller']);
        $pdf->keyValues(array_filter([
            $w['name'] => trim((string) ($company['legal_name'] ?? '')) === '' ? $w['tbc'] : (string) $company['legal_name'],
            $w['trading_as'] => trim((string) ($company['trading_name'] ?? '')) === '' ? null : (string) $company['trading_name'],
            $w['address'] => trim((string) ($company['address'] ?? '')) === '' ? $w['tbc'] : $lines((string) $company['address']),
            $w['company_number'] => trim((string) ($company['company_number'] ?? '')) === '' ? $w['tbc'] : (string) $company['company_number'],
            $w['vat_number'] => ($company['vat_registered'] ?? null) === false ? null : ($vat($company) ?? $w['tbc']),
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));
        $pdf->heading($w['buyer']);
        $pdf->keyValues(array_filter([
            $w['name'] => (string) ($buyer['name'] ?? ''),
            $w['address'] => trim((string) ($buyer['address'] ?? '')) === '' ? null : $lines((string) $buyer['address']),
            $w['company_number'] => trim((string) ($buyer['company_number'] ?? '')) === '' ? null : (string) $buyer['company_number'],
            $w['vat_number'] => $vat(['vat_number' => $buyer['vat_number'] ?? '']),
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));
        $pdf->heading($w['details']);
        $pdf->keyValues(array_filter([
            $w['number'] => $number,
            $w['date'] => $rec['doc_date'] === null ? null : Html::day((string) $rec['doc_date']),
            $w['their_ref'] => $rec['external_ref'] === null ? null : (string) $rec['external_ref'],
            $w['currency'] => (string) ($rec['currency'] ?? 'GBP') === 'GBP' ? $w['pounds'] : (string) $rec['currency'],
            $w['note'] => $rec['note'] === null ? null : (string) $rec['note'],
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));
        $code = $inv['vat_code'] === null ? '' : (string) $inv['vat_code'];
        $rows = [];
        $units = 0;
        foreach ($inv['lines'] as $l) {
            $units += abs((int) $l['qty']);
            $rows[] = [$l['line_no'], $l['code'], $l['name'], number_format(abs((int) $l['qty'])), $l['unit_cost'] === null ? '' : '£' . Html::dec($l['unit_cost']), $code,
                $l['amount'] === null ? '' : Html::money($l['amount'])];
        }
        $pdf->table([
            ['title' => $w['line'], 'width_mm' => 9, 'align' => 'R'],
            ['title' => $w['code'], 'width_mm' => 22],
            ['title' => $w['item'], 'width_mm' => 70],
            ['title' => $w['units'], 'width_mm' => 16, 'align' => 'R'],
            ['title' => $w['price'], 'width_mm' => 24, 'align' => 'R'],
            ['title' => $w['vat_code'], 'width_mm' => 13],
            ['title' => $w['amount'], 'width_mm' => 26, 'align' => 'R'],
        ], $rows);
        $t = $inv['totals'];
        $vatLine = $inv['vat_code'] === null ? $w['no_vat'] : sprintf($w['vat_at'], $code, Html::dec((string) $inv['vat_rate']));
        $pdf->keyValues([
            $w['total_units'] => number_format($units),
            $w['net'] => Html::money($t['net']),
            $vatLine => Html::money($t['vat']),
            $w['gross'] => Html::money($t['gross']),
        ]);
        $terms = trim((string) $inv['terms']);
        if ($terms !== '') {
            $pdf->paragraph($terms);
        }
        return $pdf->output();
    }
}
