<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * SYNTHETIC DPD invoice CSVs in DPD's real layout (docs/decisions.md DL3): the four top rows, the 42 column names in DPD's order, one row per
 * parcel and an empty row at the end. Every name, address and postcode is made up ("Test Person", "1 Test Street", "AB1 2CD"): the real
 * files are never opened by the tests (they hold customer addresses).
 */
final class DpdCsv
{
    /** DPD's money columns, Revenue to Cover, with the letter-code column "Surcharge" second (as in the real files). */
    public const MONEY = ['Revenue', 'Surcharge', 'Fuel and Energy Charge', 'Third Party Collection', 'Fourth Party Collection', 'Congestion Charge',
        'Clearance Charge', 'Return to Consignor Charge', 'Failed Collection Charge', 'Scottish Delivery Zone', 'Duties & Taxes Prepaid Admin Charge',
        'Oversized/Overweight Charge', 'Contractual Liability', 'Oversized Exports Charge', 'Unsuccessful Export Charge', 'EU Export Return Charge',
        'Carriage Charge', 'Non Coms Handling Charge', 'Global Energy Charge', 'Relabel Charge', 'Cover'];
    /** The 42 columns in DPD's order. */
    public const COLUMNS = ['Date', 'Consignment', 'Header', 'Parcel No', 'Product Code', 'Product Description', 'Service Code', 'Service Description',
        'Depot No', 'Collection', 'Delivery', 'Senders Ref', 'Weight', 'Items', 'VAT Code', ...self::MONEY, 'Country Code', 'Country', 'Second Ref', 'Third Ref',
        'Delivery Address', 'Collection Post Code'];
    /** The made-up personal text every row carries in the columns CW must never keep. */
    public const PERSONAL = ['Collection' => 'Test Depot Collection', 'Delivery' => 'Test Person', 'Delivery Address' => '1 Test Street, Testtown, TT1 1TT',
        'Collection Post Code' => 'AB1 2CD', 'Second Ref' => 'Ring Test Person on 07700 900123', 'Third Ref' => 'test@example.com'];

    /**
     * A parcel line: $pence of DPD's next day Expresspak split over Revenue, Fuel and Energy, Carriage and Global Energy (as the real lines),
     * unless $over['money'] says the columns itself.
     *
     * @param array<string, mixed> $over cells by column name ('money' => [column => pence])
     * @return array<string, mixed>
     */
    public static function line(string $date, string $consignment, int $pence, array $over = []): array
    {
        $money = $over['money'] ?? self::split($pence);
        unset($over['money']);
        $digits = preg_replace('/[^0-9]/', '', explode('/', $consignment)[0]);
        return $over + ['Date' => $date, 'Consignment' => $consignment, 'Header' => '1597' . $digits, 'Parcel No' => '1597' . $digits, 'Product Code' => '4',
            'Product Description' => 'EXPRESSPAK 1', 'Service Code' => '12', 'Service Description' => 'NEXT DAY', 'Depot No' => '1597', 'Senders Ref' => '1356063',
            'Weight' => '1.0', 'Items' => '1', 'VAT Code' => 'S', 'Surcharge' => 'ASU', 'Country Code' => 'GB', 'Country' => 'UNITED KINGDOM', 'money' => $money]
            + self::PERSONAL;
    }

    /** @return array<string, int> $pence over the four columns of an ordinary line (all of it in Revenue when it is small or a credit) */
    public static function split(int $pence): array
    {
        if ($pence < 40) {
            return ['Revenue' => $pence];
        }
        return ['Revenue' => $pence - 13 - 18 - 9, 'Fuel and Energy Charge' => 18, 'Carriage Charge' => 13, 'Global Energy Charge' => 9];
    }

    /**
     * The whole file.
     *
     * @param list<array<string, mixed>> $lines line()s
     * @param array{account?: string, invoice?: string, net?: int, vat?: int, gross?: int, bom?: bool, eol?: string, columns?: list<string>, top?: list<list<string>>,
     *   extra?: list<string>} $o net / vat / gross default to what the lines add up to (VAT: 20% of the S lines)
     */
    public static function file(array $lines, array $o = []): string
    {
        $net = 0;
        $s = 0;
        foreach ($lines as $l) {
            $sum = array_sum($l['money']);
            $net += $sum;
            if (($l['VAT Code'] ?? 'S') === 'S') {
                $s += $sum;
            }
        }
        $vat = $o['vat'] ?? intdiv($s * 2 + ($s >= 0 ? 5 : -5), 10);
        $net = $o['net'] ?? $net;
        $gross = $o['gross'] ?? $net + $vat;
        $eol = $o['eol'] ?? "\r\n";
        $cols = $o['columns'] ?? self::COLUMNS;
        $rows = $o['top'] ?? [
            ['Account No', $o['account'] ?? '3012002', 'Invoice No', $o['invoice'] ?? '62999001'],
            ['Nett Invoice Value', '', self::gbp($net), 'GBP'],
            ['VAT', '', self::gbp($vat), 'GBP'],
            ['Gross Invoice Value', '', self::gbp($gross), 'GBP'],
        ];
        $rows[] = $cols;
        foreach ($lines as $l) {
            $row = [];
            foreach ($cols as $c) {
                if ((in_array($c, self::MONEY, true) && $c !== 'Surcharge') || array_key_exists($c, $l['money'])) {
                    $v = $l['money'][$c] ?? null;
                    $row[] = $v === null ? ($l['blank_money'] ?? false ? '' : ($c === 'Cover' ? '0' : '0.00')) : self::gbp((int) $v);
                    continue;
                }
                $row[] = (string) ($l[$c] ?? '');
            }
            $rows[] = $row;
        }
        foreach ($o['extra'] ?? [] as $raw) {
            $rows[] = $raw;
        }
        $out = ($o['bom'] ?? false) ? "\xEF\xBB\xBF" : '';
        foreach ($rows as $r) {
            $out .= is_string($r) ? $r . $eol : implode(',', array_map(self::cell(...), $r)) . $eol;
        }
        return $out . $eol; // DPD ends with an empty row
    }

    /** Pence as DPD prints them ("3.49", "-2.10"). */
    public static function gbp(int $p): string
    {
        return ($p < 0 ? '-' : '') . intdiv(abs($p), 100) . '.' . str_pad((string) (abs($p) % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function cell(string $v): string
    {
        return preg_match('/[",\r\n]/', $v) === 1 || $v !== trim($v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
    }
}
