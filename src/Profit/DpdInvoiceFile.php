<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * Reads DPD's weekly invoice CSV (delivery cost in profit, step 2 part 1; docs/decisions.md DL3-DL6). Pure: no database, no file kept.
 *
 * The file as DPD sends it (sample 61922637 and the four bills of 15 Oct - 14 Nov 2026, account 3012002): the top rows say the bill
 * ("Account No", <account>, "Invoice No", <number>; "Nett Invoice Value", "", <net>, "GBP"; "VAT", ...; "Gross Invoice Value", ...), the
 * next row names the columns (42 of them: Date, Consignment, Header, Parcel No, the product and service, Depot No, Collection, Delivery,
 * Senders Ref, Weight, Items, VAT Code, the money columns from Revenue to Cover, Country, Second Ref, Third Ref, Delivery Address,
 * Collection Post Code), then one row per parcel and an empty row. A UTF-8 byte order mark or none, CRLF or LF line ends.
 *
 * Every column is read by its NAME, never its place. A line's cost is the sum of DPD's named money columns (MONEY: Revenue, Fuel and Energy
 * Charge, ... Cover, wherever they are), never "Surcharge" (a letter code naming the extras) nor a column CW does not know (never read: a
 * new charge column would leave the lines short of the net, and the file refused); a consignment of two parcels is two rows, the second
 * with no money (kept, cost 0); a credit (negative money) is kept as it is; the # and * DPD prints after a consignment are taken off it for
 * the matching and kept as flags.
 *
 * The WHOLE file is refused (nothing is read from it) when: a column the matching needs is missing, a money cell is not an amount in pounds
 * and pence, a date is not a day, the lines do not add up to the printed net exactly, the VAT is not the printed VAT (VAT code S at the
 * shops' rate on the S lines' total, Z at 0%, any other code refused), or the gross is not net + VAT. A refusal names the row and the
 * column, never what the cell holds (a cell in the wrong place may be part of an address).
 *
 * Only the fields the matching and the screens need are returned: the date, the consignment (digits), the parcel numbers (digits), DPD's
 * product and service names (their own short words only), weight, items, the Senders Ref when it is digits (the order number the label
 * carried; anything else is dropped), the VAT code, the money columns that are not 0, the cost. Delivery, Delivery Address, Collection,
 * Collection Post Code, Second Ref, Third Ref, Country and any other column are never read into the result. DPD's product and service are
 * kept only when they are one of DPD's own names (PRODUCTS, SERVICES) and their codes only as 1-3 digits: a name or an address that lands in
 * one of those columns is never kept (the Codex check r1, HIGH 1). A refusal names a column by its name only when it is one of DPD's
 * known columns, else by its number.
 */
final class DpdInvoiceFile
{
    public const CARRIER = 'dpd';
    /** The largest file read (the screen's upload limit is 10 MiB; a DPD week is about 1 MB). */
    public const MAX_BYTES = 16 * 1_048_576;
    /** The most lines one bill may have (a DPD week is about 2,500). */
    public const MAX_LINES = 50_000;
    /** The top rows are looked for in at most this many rows before the row of column names. */
    public const MAX_TOP_ROWS = 20;
    /** The columns a DPD bill must name (the matching reads them; DPD prints every one of them on every bill). */
    public const REQUIRED = ['Date', 'Consignment', 'Senders Ref', 'VAT Code', 'Revenue', 'Fuel and Energy Charge', 'Third Party Collection', 'Cover'];
    /** The row that names the columns is the first one naming these two. */
    public const FIRST_MONEY = 'Revenue';
    /**
     * DPD's money columns (the four bills of 15 Oct - 14 Nov 2026 name Global Energy Charge, the March sample Peak Charge instead; bill
     * 62382344 of the week after adds Peak Demand Charge, 10p or 20p a parcel: DL42): a line's cost is their sum, wherever they are.
     */
    public const MONEY = ['Revenue', 'Fuel and Energy Charge', 'Third Party Collection', 'Fourth Party Collection', 'Congestion Charge', 'Clearance Charge',
        'Return to Consignor Charge', 'Failed Collection Charge', 'Scottish Delivery Zone', 'Duties & Taxes Prepaid Admin Charge', 'Oversized/Overweight Charge',
        'Contractual Liability', 'Oversized Exports Charge', 'Unsuccessful Export Charge', 'EU Export Return Charge', 'Carriage Charge', 'Non Coms Handling Charge',
        'Global Energy Charge', 'Peak Charge', 'Peak Demand Charge', 'Relabel Charge', 'Cover'];
    /** Not money: a letter code naming a line's extras (AS, AES, APS ...). */
    public const NOT_MONEY = ['Surcharge'];
    /** DPD's other columns (read or not): the ones a refusal may name. */
    public const OTHER_COLUMNS = ['Date', 'Consignment', 'Header', 'Parcel No', 'Product Code', 'Product Description', 'Service Code', 'Service Description',
        'Depot No', 'Collection', 'Delivery', 'Senders Ref', 'Weight', 'Items', 'VAT Code', 'Surcharge', 'Country Code', 'Country', 'Second Ref', 'Third Ref',
        'Delivery Address', 'Collection Post Code'];
    /** DPD's product names CW keeps (any other is kept as ""). */
    public const PRODUCTS = ['EXPRESSPAK 1', 'EXPRESSPAK 5', 'EXPRESSPAK 10', 'EXPRESSPAK 15', 'EXPRESSPAK', 'PARCEL', 'PALLET', 'FREIGHT'];
    /** DPD's service names CW keeps (any other is kept as ""). */
    public const SERVICES = ['NEXT DAY', 'SHIP2SHOP', 'SAT', 'SUN', 'TWO DAY', 'BY 12', 'BY 10:30', 'BY 10.30', 'SAT BY 12', 'SUN BY 12', 'SAT BY 10:30', 'SATURDAY',
        'SUNDAY', 'RETURNS', 'COLLECTION', 'COLLECT', 'HOMECALL', 'AIR CLASSIC', 'AIR EXPRESS', 'DPD CLASSIC'];
    /** A line with money in this column is a collection DPD made from the customer (a return), DL9. */
    public const COLLECTION = 'Third Party Collection';
    /** VAT codes: S at the shops' rate (profit.vat_rate_pct), Z at 0%. Any other refuses the file. */
    public const VAT_CODES = ['S', 'Z'];
    /** The top rows' labels (lower case, spaces as one) => what they say. */
    private const LABELS = ['account no' => 'account', 'account number' => 'account', 'account' => 'account', 'invoice no' => 'invoice_no',
        'invoice number' => 'invoice_no', 'nett invoice value' => 'net', 'net invoice value' => 'net', 'vat' => 'vat', 'vat value' => 'vat',
        'gross invoice value' => 'gross', 'invoice date' => 'invoice_date', 'date of invoice' => 'invoice_date', 'tax point' => 'invoice_date'];
    /** DPD's account and bill numbers: digits only (3012002, 62368793), so no name can ride in on them (the Codex check r2, HIGH 2). */
    public const NUMBER = '/^[0-9]{1,16}$/D';
    /** DPD's product and service codes as kept: 1-3 digits (1, 4, 12, 24 ...). */
    private const CODE = '/^[0-9]{1,3}$/D';

    /**
     * @param int $vatBp the shops' VAT rate in basis points (2000 = 20%): the rate of VAT code S
     * @return array{carrier: string, account: string, invoice_no: string, invoice_date: ?string, net: int, vat: int, gross: int,
     *   period_from: string, period_to: string, sha256: string, lines: list<array<string, mixed>>}
     */
    public static function parse(string $bytes, int $vatBp): array
    {
        if ($bytes === '') {
            throw self::refuse('the file is empty');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new CwException('too_large', 'the file is larger than ' . intdiv(self::MAX_BYTES, 1_048_576) . ' MB: it is not a DPD week', 413);
        }
        $sha = hash('sha256', $bytes);
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            throw self::refuse('the file is saved as UTF-16: save it as CSV (comma separated) and upload it again');
        }
        if (str_contains($bytes, "\0")) {
            throw self::refuse('this is not a CSV file');
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        // Old Mac line ends (CR only) read as CRLF and LF are; a non-breaking space (UTF-8 U+00A0) is a space (the lead's LOW 3).
        if (!str_contains($bytes, "\n") && str_contains($bytes, "\r")) {
            $bytes = str_replace("\r", "\n", $bytes);
        }
        $bytes = str_replace("\xC2\xA0", ' ', $bytes);
        $h = fopen('php://temp', 'w+b');
        if ($h === false) {
            throw new \RuntimeException('cannot open a temporary stream');
        }
        try {
            fwrite($h, $bytes);
            rewind($h);
            return self::read($h, $sha, $vatBp);
        } finally {
            fclose($h);
        }
    }

    /**
     * @param resource $h
     * @return array<string, mixed>
     */
    private static function read($h, string $sha, int $vatBp): array
    {
        // The top rows, then the row that names the columns.
        $top = [];
        $names = null;
        $row = 0;
        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $row++;
            $cells = array_map(static fn (?string $c): string => trim((string) $c), $r);
            $lower = array_map(static fn (string $c): string => strtolower($c), $cells);
            if (in_array('consignment', $lower, true) && in_array(strtolower(self::FIRST_MONEY), $lower, true)) {
                $names = $cells;
                break;
            }
            if ($row > self::MAX_TOP_ROWS) {
                break;
            }
            $top[] = $cells;
        }
        if ($names === null) {
            throw self::refuse('this is not a DPD invoice file: no row names its columns (Date, Consignment, ..., Revenue, ..., Cover)');
        }
        $head = self::top($top);
        $ix = [];
        foreach ($names as $i => $n) {
            if ($n === '') {
                continue;
            }
            $key = strtolower((string) preg_replace('/\s+/', ' ', $n));
            if (isset($ix[$key])) {
                throw self::refuse(sprintf('columns %d and %d have the same name: the file is not read', $ix[$key] + 1, $i + 1), ['row' => $row]);
            }
            $ix[$key] = $i;
        }
        foreach (self::REQUIRED as $col) {
            if (!isset($ix[strtolower($col)])) {
                throw self::refuse("the column \"{$col}\" is missing: the file is not read", ['column' => $col]);
            }
        }
        // The money columns by their names, wherever they are (Codex r1 MEDIUM 1); kept under DPD's own spelling.
        $money = [];
        foreach (self::MONEY as $name) {
            if (isset($ix[strtolower($name)])) {
                $money[$ix[strtolower($name)]] = $name;
            }
        }
        ksort($money);
        $col = static fn (string $name): ?int => $ix[strtolower($name)] ?? null;
        $at = [
            'date' => $col('Date'), 'consignment' => $col('Consignment'), 'header' => $col('Header'), 'parcel' => $col('Parcel No'),
            'product_code' => $col('Product Code'), 'product' => $col('Product Description'), 'service_code' => $col('Service Code'),
            'service' => $col('Service Description'), 'senders_ref' => $col('Senders Ref'), 'weight' => $col('Weight'), 'items' => $col('Items'),
            'vat_code' => $col('VAT Code'), 'collection' => $col(self::COLLECTION),
        ];
        $width = count($names);
        // The columns CW does not know (neither money nor DPD's others, a column with no heading too): never read into a line; one that holds
        // amounts (any digit: DL40) refuses the whole file, even when the lines tie (+£5 on one line and -£5 on another would move cost between
        // orders unseen), named by its NUMBER only: a heading CW does not know may be anything, a name too (the final review, astra items 3 and 4;
        // DL33).
        $known = array_map('strtolower', [...self::MONEY, ...self::OTHER_COLUMNS]);
        $unknown = [];
        foreach ($names as $i => $n) {
            if (!in_array(strtolower((string) preg_replace('/\s+/', ' ', $n)), $known, true)) {
                $unknown[$i] = 'column ' . ($i + 1);
            }
        }
        $unknownMoney = [];

        $lines = [];
        $sumNet = 0;
        $sumS = 0;
        $from = null;
        $to = null;
        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $row++;
            $cells = array_map(static fn (?string $c): string => trim((string) $c), $r);
            if (implode('', $cells) === '') {
                continue; // an empty row (DPD ends the file with one)
            }
            if (count($cells) > $width) {
                // More cells than names: a comma out of its quotes; reading on would put a cell under the wrong name.
                throw self::refuse('row ' . $row . ' has more cells than the file has column names: the file is not read', ['row' => $row]);
            }
            $cells = array_pad($cells, $width, '');
            // Any cell there with a digit in it counts as an amount, however it is written ("£5.00", "(5.00)", "5.00-", "0.00"): the astra
            // re-check of a33d184, item 4 (DL40).
            foreach ($unknown as $i => $label) {
                if (preg_match('/[0-9]/', $cells[$i]) === 1) {
                    $unknownMoney[$i] = $label;
                }
            }
            $n = count($lines) + 1;
            if ($n > self::MAX_LINES) {
                throw new CwException('too_many_lines', 'the file has more than ' . number_format(self::MAX_LINES) . ' lines: it is not a DPD week', 413);
            }
            $date = self::day($cells[$at['date']]);
            if ($date === null) {
                throw self::refuse("row {$row}: the Date is not a day (DD/MM/YYYY)", ['row' => $row, 'column' => 'Date']);
            }
            $m = [];
            $cost = 0;
            foreach ($money as $i => $name) {
                $p = self::pence($cells[$i]);
                if ($p === null) {
                    throw self::refuse("row {$row}: \"{$name}\" is not an amount in pounds and pence (it reads " . self::shape($cells[$i]) . ')',
                        ['row' => $row, 'column' => $name]);
                }
                if ($p !== 0) {
                    $m[$name] = $p;
                    $cost += $p;
                }
            }
            $vat = strtoupper($cells[$at['vat_code']]);
            if ($vat === '' && $cost === 0) {
                $vat = 'S'; // a row with no money (a consignment's second parcel) needs no rate
            }
            if (!in_array($vat, self::VAT_CODES, true)) {
                throw self::refuse("row {$row}: the VAT Code " . ($vat === '' ? 'is empty' : 'is not S or Z (it reads ' . self::shape($vat) . ')')
                    . ': the VAT cannot be checked', ['row' => $row, 'column' => 'VAT Code']);
            }
            [$consignment, $marks] = self::consignment($cells[$at['consignment']]);
            $collection = isset($m[self::COLLECTION]);
            $lines[] = [
                'line_no' => $n,
                'line_date' => $date,
                'consignment' => $consignment,
                'marks' => $marks,
                'parcel_no' => self::digits($at['parcel'] === null ? '' : $cells[$at['parcel']], 20),
                'header_parcel' => self::digits($at['header'] === null ? '' : $cells[$at['header']], 20),
                'product_code' => self::code($at['product_code'] === null ? '' : $cells[$at['product_code']]),
                'product' => self::known($at['product'] === null ? '' : $cells[$at['product']], self::PRODUCTS),
                'service_code' => self::code($at['service_code'] === null ? '' : $cells[$at['service_code']]),
                'service' => self::known($at['service'] === null ? '' : $cells[$at['service']], self::SERVICES),
                'weight' => self::weight($at['weight'] === null ? '' : $cells[$at['weight']]),
                'items' => self::items($at['items'] === null ? '' : $cells[$at['items']]),
                // At most 9 digits (an order number; a phone number typed as a reference is never kept: the final review, Fable L4; DL39).
                'senders_ref' => self::digits($cells[$at['senders_ref']], 9),
                'vat_code' => $vat,
                'money' => $m,
                'cost' => $cost,
                'collection' => $collection,
            ];
            $sumNet += $cost;
            if ($vat === 'S') {
                $sumS += $cost;
            }
            $from = $from === null || $date < $from ? $date : $from;
            $to = $to === null || $date > $to ? $date : $to;
        }
        if ($lines === []) {
            throw self::refuse('the file has no lines');
        }
        if ($unknownMoney !== []) {
            ksort($unknownMoney);
            throw self::refuse(sprintf('the file has %s we do not know, %s, with amounts in it: tell the CW team', count($unknownMoney) === 1 ? 'a column' : 'columns',
                implode(', ', $unknownMoney)));
        }
        if ($sumNet !== $head['net']) {
            throw self::refuse(sprintf('the lines add up to %s, but the file says its net total is %s: the file is not read (is it the whole file?)',
                self::gbp($sumNet), self::gbp($head['net'])));
        }
        $vatDue = ProfitMath::mulDivRound($sumS, $vatBp, 10000);
        if ($vatDue !== $head['vat']) {
            throw self::refuse(sprintf('the VAT does not tie: %s%% of the standard-rated lines (%s) is %s, the file says %s: the file is not read',
                rtrim(rtrim(number_format($vatBp / 100, 2, '.', ''), '0'), '.'), self::gbp($sumS), self::gbp($vatDue), self::gbp($head['vat'])));
        }
        if ($head['gross'] !== $head['net'] + $head['vat']) {
            throw self::refuse(sprintf('the gross total %s is not net %s + VAT %s: the file is not read', self::gbp($head['gross']), self::gbp($head['net']),
                self::gbp($head['vat'])));
        }
        return ['carrier' => self::CARRIER, 'account' => $head['account'], 'invoice_no' => $head['invoice_no'], 'invoice_date' => $head['invoice_date'],
            'net' => $head['net'], 'vat' => $head['vat'], 'gross' => $head['gross'], 'period_from' => (string) $from, 'period_to' => (string) $to,
            'sha256' => $sha, 'lines' => $lines];
    }

    /** A top-row cell as a label is looked up (lower case, spaces as one, no ":" or "." after it). */
    private static function label(string $cell): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', rtrim($cell, ':. '))));
    }

    /**
     * What the top rows say: each label ("Account No", "Nett Invoice Value", ...) and the next cell after it that is not empty.
     *
     * @param list<list<string>> $rows
     * @return array{account: string, invoice_no: string, invoice_date: ?string, net: int, vat: int, gross: int}
     */
    private static function top(array $rows): array
    {
        $found = [];
        $currency = [];
        foreach ($rows as $cells) {
            $count = count($cells);
            for ($i = 0; $i < $count; $i++) {
                $what = self::LABELS[self::label($cells[$i])] ?? null;
                if ($what === null || isset($found[$what])) {
                    continue;
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($cells[$j] !== '') {
                        $found[$what] = $cells[$j];
                        // The currency DPD prints after a total ("GBP"), when there is one: looked for up to the next label only (net, VAT and
                        // gross side by side on one row, no currency: none; the astra re-check of a33d184, LOW; DL40).
                        for ($k = $j + 1; $k < $count; $k++) {
                            if (trim($cells[$k]) !== '') {
                                if (!isset(self::LABELS[self::label($cells[$k])])) {
                                    $currency[$what] = strtoupper(trim($cells[$k]));
                                }
                                break;
                            }
                        }
                        break;
                    }
                }
            }
        }
        // What each of them is called in a sentence ("the top of the file does not say its ...", "the ... at the top of the file is not ..."; the
        // lead's LOW 1).
        $words = ['account' => 'account (Account No)', 'invoice_no' => 'bill number (Invoice No)', 'net' => 'net total (Nett Invoice Value)', 'vat' => 'VAT',
            'gross' => 'gross total (Gross Invoice Value)'];
        foreach ($words as $k => $w) {
            if (!isset($found[$k])) {
                throw self::refuse("the top of the file does not say its {$w}: is it DPD's invoice CSV?");
            }
        }
        foreach (['account' => 'account', 'invoice_no' => 'invoice number'] as $k => $w) {
            if (preg_match(self::NUMBER, $found[$k]) !== 1) {
                throw self::refuse("the {$w} at the top of the file is not a number");
            }
        }
        // The totals in pounds, all three (the final review, astra item 5; DL34): another currency printed beside one refuses the file.
        foreach (['net', 'vat', 'gross'] as $k) {
            if (isset($currency[$k]) && $currency[$k] !== 'GBP') {
                throw self::refuse('the totals at the top of the file are not all in pounds (GBP)');
            }
        }
        $amounts = [];
        foreach (['net', 'vat', 'gross'] as $k) {
            $p = self::pence($found[$k]);
            if ($p === null) {
                throw self::refuse("the {$words[$k]} at the top of the file is not an amount in pounds and pence");
            }
            $amounts[$k] = $p;
        }
        $date = null;
        if (isset($found['invoice_date'])) {
            $date = self::day($found['invoice_date']);
            if ($date === null) {
                throw self::refuse('the invoice date at the top of the file is not a day (DD/MM/YYYY)'); // Codex r1 MEDIUM 2
            }
        }
        return ['account' => $found['account'], 'invoice_no' => $found['invoice_no'], 'invoice_date' => $date] + $amounts;
    }

    /**
     * An amount in pounds as DPD prints it ("3.49", "-2.10", "0", "1,234.56", "") => pence; '' is 0; null when it is not one, or when it is
     * £1,000,000,000 or more (no carrier line is; the sums stay far inside PHP's integers: Codex r1 MEDIUM 2).
     */
    public static function pence(string $s): ?int
    {
        $s = trim($s);
        if ($s === '') {
            return 0;
        }
        if (preg_match('/^(-)?(\d{1,3}(?:,\d{3}){1,2}|\d{1,9})(?:\.(\d{1,2}))?$/D', $s, $m) !== 1) {
            return null;
        }
        $p = (int) str_replace(',', '', $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        return ($m[1] ?? '') === '-' ? -$p : $p;
    }

    /** "15/10/2026" (DD/MM/YYYY) or "2026-10-15" => "2026-10-15"; null when it is not a real day. */
    public static function day(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#D', $s, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $s, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return $y >= 2000 && $y <= 2099 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * A consignment as DPD prints it ("6951225115/0", "6951225115/0#", "6951225115/0#*", "6951225115") => [the digits before the "/", the
     * marks # and * it carried]; [null, marks] when what is left is not digits.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function consignment(string $s): array
    {
        $marks = (str_contains($s, '#') ? '#' : '') . (str_contains($s, '*') ? '*' : '');
        $s = trim(str_replace(['#', '*'], '', $s));
        $base = trim(explode('/', $s, 2)[0]);
        return [preg_match('/^[0-9]{1,20}$/D', $base) === 1 ? $base : null, $marks];
    }

    private static function digits(string $s, int $max): ?string
    {
        $s = trim($s);
        return preg_match('/^[0-9]{1,' . $max . '}$/D', $s) === 1 ? $s : null;
    }

    private static function code(string $s): string
    {
        return preg_match(self::CODE, trim($s)) === 1 ? trim($s) : '';
    }

    /** @param list<string> $names DPD's own names: the cell when it is one of them (any case, spaces as one), in DPD's spelling; else "" */
    private static function known(string $s, array $names): string
    {
        $s = strtoupper(trim((string) preg_replace('/\s+/', ' ', $s)));
        return in_array($s, $names, true) ? $s : '';
    }

    private static function weight(string $s): ?string
    {
        $s = trim($s);
        return preg_match('/^\d{1,6}(?:\.\d{1,3})?$/D', $s) === 1 ? $s : null;
    }

    private static function items(string $s): ?int
    {
        $s = trim($s);
        return preg_match('/^\d{1,4}$/D', $s) === 1 ? (int) $s : null;
    }

    /** What a cell looks like without what it says: digits as 9, letters as a ("99.99", "aaaa 99"), at most 20 characters. */
    private static function shape(string $s): string
    {
        $s = (string) preg_replace(['/[0-9]/', '/[A-Za-z]/', '/[^9a .,\/()-]/'], ['9', 'a', '?'], $s);
        return '"' . (strlen($s) > 20 ? substr($s, 0, 20) . '...' : $s) . '"';
    }

    /** Pence as "£1,234.56". */
    public static function gbp(int $pence): string
    {
        return ($pence < 0 ? '-' : '') . '£' . number_format(intdiv(abs($pence), 100)) . '.' . str_pad((string) (abs($pence) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $detail */
    private static function refuse(string $why, array $detail = []): CwException
    {
        return new CwException('bad_file', 'DPD bill not read: ' . $why . (str_ends_with($why, '?') ? '' : '.') . ' Nothing was saved.', 422, $detail);
    }
}
