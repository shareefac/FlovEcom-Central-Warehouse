<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * MADE-UP answers of Claude in the fixed shapes of CW's document kinds (CW-1182): a Royal Mail bill's reading built from RmBill's synthetic
 * lines (the numbers of tests/Support/RmBill, never a real bill), an expense invoice's reading. Each is what structured outputs would give:
 * every field present.
 */
final class AiAnswers
{
    /** "GBP 1,096.12" as Claude is told to write it: "1096.12". */
    public static function plain(int $p): string
    {
        return ($p < 0 ? '-' : '') . intdiv(abs($p), 100) . '.' . str_pad((string) (abs($p) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * A Royal Mail bill's reading of the bill RmBill::page($lines, $over) shows.
     *
     * @param list<array<string, mixed>> $lines RmBill::line()
     * @param array<string, mixed> $over account, invoice, date (YYYY-MM-DD), net/vat/gross (pence), surcharge (pence|null), sales_form ("slash" |
     *   "spaced" | "bare": how a sales order is printed before the reference), and any answer field to replace
     * @return array<string, mixed>
     */
    public static function royalMail(array $lines, array $over = []): array
    {
        $t = RmBill::totals($lines);
        $rows = [];
        foreach ($lines as $l) {
            [$y, $m, $d] = explode('-', $l['day']);
            $ref = (string) $l['ref'];
            if ($l['sales'] !== null) {
                $ref = match ($over['sales_form'] ?? 'slash') {
                    'slash' => $l['sales'] . '/' . ($ref === '' ? '' : ' ' . $ref),
                    'bare' => $l['sales'] . ($ref === '' ? '' : ' ' . $ref), // M1: "7181703660" as printed with no slash
                    default => $l['sales'] . ' / ' . $ref,
                };
            }
            $rows[] = ['service_date' => "{$d}:{$m}:{$y}", 'product_code' => $l['product'], 'quantity' => number_format($l['qty'], 3, '.', ''),
                'description' => 'A DESCRIPTION OF THE PRODUCT', 'reference' => trim($ref), 'net' => self::plain($l['net']), 'gross' => self::plain($l['gross']),
                'vat_code' => $l['vat']];
        }
        $summary = [];
        foreach ($t['by_code'] as $code => $f) {
            $summary[] = ['vat_code' => $code, 'rate_percent' => $code === 'T' ? '20' : '0', 'net' => self::plain($f['net']), 'vat' => self::plain($f['vat']),
                'gross' => self::plain($f['net'] + $f['vat'])];
        }
        $net = $over['net'] ?? $t['net'];
        $vat = $over['vat'] ?? $t['vat'];
        $a = ['readable' => true, 'not_readable_reason' => null, 'account' => $over['account'] ?? '0706703000', 'invoice_number' => $over['invoice'] ?? '9070000001',
            'invoice_date' => $over['date'] ?? '2026-11-16', 'currency' => 'GBP', 'net' => self::plain($net), 'vat' => self::plain($vat),
            'gross' => self::plain($over['gross'] ?? $net + $vat), 'lines' => $rows, 'line_count' => count($rows), 'vat_summary' => $summary,
            'surcharge_included' => array_key_exists('surcharge', $over) ? ($over['surcharge'] === null ? null : self::plain($over['surcharge'])) : '1234.56'];
        foreach (array_diff_key($over, array_flip(['account', 'invoice', 'date', 'net', 'vat', 'gross', 'surcharge', 'sales_form'])) as $k => $v) {
            $a[$k] = $v;
        }
        return $a;
    }

    /**
     * An expense invoice's reading.
     *
     * @param array<string, mixed> $over any field
     * @return array<string, mixed>
     */
    public static function invoice(array $over = []): array
    {
        return $over + ['readable' => true, 'not_readable_reason' => null, 'document_kind' => 'invoice', 'supplier_name' => 'Made Up Paper Ltd',
            'invoice_number' => 'INV-20417', 'invoice_date' => '2026-11-20', 'currency' => 'GBP', 'net' => '70.60', 'vat' => '14.12', 'total' => '84.72',
            'billed_to' => 'Test Trading Ltd'];
    }
}
