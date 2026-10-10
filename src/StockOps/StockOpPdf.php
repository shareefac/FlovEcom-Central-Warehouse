<?php

declare(strict_types=1);

namespace CW\StockOps;

use CW\Output\PdfWriter;
use CW\Ui\Html;
use CW\Ui\Words;

/**
 * The printed copies of two stock records (pack A1; docs/decisions.md SO11), on PdfWriter like the purchase order's PDF: the TRANSFER
 * NOTE (from where to where, the products and units, lines to sign for picking and receiving) and the RELEASE INVOICE (sold by the
 * other account, bought by us: our company details, their invoice number, each product's units, agreed price and amount, the total).
 * A draft says so in its title; a cancelled record says which record cancelled it. Pounds only (Q13). Pure: the caller reads the record.
 */
final class StockOpPdf
{
    /**
     * @param array<string, mixed> $rec StockOps::record()
     * @param list<array<string, mixed>> $lines StockOps::lines()
     * @param array<string, mixed> $company CompanyDetails::company()
     */
    public static function build(array $rec, array $lines, array $company): string
    {
        $release = $rec['kind'] === 'release';
        $number = $rec['number'] === null ? Words::STOCK_PDF['draft'] : (string) $rec['number'];
        $title = sprintf(Words::STOCK_PDF[$release ? 'release_title' : 'transfer_title'], $number);
        $place = static fn (?string $w, ?string $l): string => $w === null ? '' : ($l === null ? $w : $w . ', ' . $l);
        $from = $place($rec['warehouse_name'] === null ? null : (string) $rec['warehouse_name'], $rec['location_name'] === null ? null : (string) $rec['location_name']);
        $to = $place($rec['to_warehouse_name'] === null ? null : (string) $rec['to_warehouse_name'], $rec['to_location_name'] === null ? null : (string) $rec['to_location_name']);
        $status = (string) $rec['status'];
        $sub = Words::of('DOC_STATUS', $status) . ($rec['reversed_by_number'] !== null ? ' · ' . sprintf(Words::STOCK_PDF['cancelled'], (string) $rec['reversed_by_number']) : '');
        $pdf = new PdfWriter($title, $sub, true);
        $account = (string) ($rec['account_name'] ?? $rec['warehouse_entity'] ?? '');
        $pairs = [];
        if ($release) {
            $us = trim(implode(', ', array_filter([(string) ($company['legal_name'] ?? ''), (string) ($company['address'] ?? '')], static fn (string $s): bool => trim($s) !== '')));
            $pairs = [Words::STOCK_PDF['seller'] => $account, Words::STOCK_PDF['buyer'] => $us === '' ? null : str_replace("\n", ', ', $us),
                Words::STOCK_PDF['their_ref'] => $rec['external_ref']];
        }
        $pairs += [
            Words::STOCK_PDF['from'] => $from,
            Words::STOCK_PDF['to'] => $to,
            Words::STOCK_PDF['date'] => $rec['doc_date'] === null ? null : Html::day((string) $rec['doc_date']),
            Words::STOCK_PDF['made_by'] => $rec['created_by_name'],
            Words::STOCK_PDF['final_by'] => $rec['posted_by_name'],
            Words::STOCK_PDF['note'] => $rec['note'],
        ];
        $pdf->keyValues(array_filter($pairs, static fn (mixed $v): bool => $v !== null && $v !== ''));
        if ($release) {
            $total = '0.00';
            $rows = [];
            foreach ($lines as $l) {
                $total = bcadd($total, (string) ($l['amount'] ?? '0'), 2);
                $rows[] = [$l['line_no'], $l['code'], $l['name'], number_format(abs((int) $l['qty'])), $l['unit_cost'] === null ? '' : '£' . Html::dec($l['unit_cost']),
                    $l['amount'] === null ? '' : Html::money($l['amount'])];
            }
            $rows[] = ['', '', Words::STOCK_PDF['total'], number_format(array_sum(array_map(static fn (array $l): int => abs((int) $l['qty']), $lines))), '', Html::money($total)];
            $pdf->table([
                ['title' => Words::STOCK_PDF['line'], 'width_mm' => 9, 'align' => 'R'],
                ['title' => Words::STOCK_PDF['code'], 'width_mm' => 24],
                ['title' => Words::STOCK_PDF['product'], 'width_mm' => 75],
                ['title' => Words::STOCK_PDF['units'], 'width_mm' => 18, 'align' => 'R'],
                ['title' => Words::STOCK_PDF['price'], 'width_mm' => 26, 'align' => 'R'],
                ['title' => Words::STOCK_PDF['amount'], 'width_mm' => 28, 'align' => 'R'],
            ], $rows);
            $pdf->paragraph(sprintf(Words::STOCK_PDF['pounds'], $from, $to, $account));
            return $pdf->output();
        }
        $pdf->table([
            ['title' => Words::STOCK_PDF['line'], 'width_mm' => 9, 'align' => 'R'],
            ['title' => Words::STOCK_PDF['code'], 'width_mm' => 26],
            ['title' => Words::STOCK_PDF['product'], 'width_mm' => 120],
            ['title' => Words::STOCK_PDF['units'], 'width_mm' => 25, 'align' => 'R'],
        ], array_map(static fn (array $l): array => [$l['line_no'], $l['code'], $l['name'], number_format(abs((int) $l['qty']))], $lines));
        if ($rec['to_warehouse_id'] !== null && (int) $rec['to_warehouse_id'] === (int) $rec['warehouse_id']) {
            $pdf->paragraph(Words::STOCK_PDF['moves_none']);
        }
        $pdf->paragraph(Words::STOCK_PDF['picked']);
        $pdf->paragraph(Words::STOCK_PDF['received']);
        return $pdf->output();
    }
}
