<?php

declare(strict_types=1);

namespace CW\Ai;

use CW\CwException;
use CW\Profit\RoyalMailBillPage;

/**
 * Royal Mail's weekly OBA bill read by Claude from a PDF (the bill's page printed from the browser) or a photo of it (CW-1182 phase 1;
 * docs/decisions.md AI4). Claude copies the top block (account, invoice number, date, net, VAT, gross, currency), EVERY line of the table (the
 * service date, product code, quantity, description and the Sales Order / Your Reference cell exactly as printed, net, gross, VAT code), its
 * count of the lines, the VAT summary rows and the "Surcharge included on Invoice" amount. CW checks every field's form (shape()), then writes
 * the bill out as the page reader's text (render(): the shape RoyalMailBillPage::fromSpaced reads, one line of the bill on two lines) and runs
 * the EXISTING RoyalMailBillPage::parse on it (through RoyalMailBills::check), so every check of a pasted bill applies unchanged: the lines
 * tie to the printed net and gross to the penny, the VAT follows from the codes, an unknown product refuses the bill, the account must be the
 * one chosen, the same bill twice, ... CW never corrects Claude's reading: a bill whose lines do not add up is refused and says so.
 */
final class RoyalMailBillKind implements Kind
{
    public const NAME = 'royal_mail_bill';
    /** The most lines CW takes (RoyalMailBillPage::MAX_ROWS). */
    public const MAX_LINES = RoyalMailBillPage::MAX_ROWS;
    /** What Claude may say when it cannot read the document (shown in words: never Claude's own text). */
    public const NOT_READABLE = ['not_a_royal_mail_bill', 'pages_missing', 'unreadable', 'other'];
    /** A copied text field's most characters (a description, a reference). */
    private const TEXT_MAX = 200;
    private const AMOUNT = '/^(-)?(\d{1,3}(?:,\d{3})+|\d{1,12})\.(\d{2})$/D';

    public function name(): string
    {
        return self::NAME;
    }

    public function maxTokens(): int
    {
        return 64_000; // a week of about 160 lines is ~11,000 tokens of answer; the thinking counts in it too
    }

    public function instructions(): string
    {
        return <<<'TXT'
This document is a Royal Mail OBA bill: the invoice page of Royal Mail's "SAP Biller Direct" site, printed to PDF from a browser, or a photo of it. Copy what it prints into the JSON form, exactly as printed. Do not work anything out, do not correct anything, do not add, merge, split or leave out any line.

The top of the bill:
- account: the "Customer Account Number".
- invoice_number: the number after "Invoice".
- invoice_date: the "Date" (not the due date), as YYYY-MM-DD.
- net: "Total Price Net"; vat: "VAT"; gross: "Total Gross Price".
- currency: the three letters printed before those amounts (for example GBP).

lines: every line of the table whose columns are "Service Rendered Date", "Product Number", "Quantity", "Product Description", "Sales Order/Your Reference", "Net Price", "Gross Price" and "VAT Code", on every page, in the order printed, one entry per line. Include lines with a minus amount and lines whose reference is empty. A line, its description or its reference may run onto the next printed line: join the parts with single spaces. Leave out what the print adds at the top and bottom of each page (the date and time, "SAP Biller Direct", the web address, "Print", "Close", page numbers).
- service_date: as printed (dd:mm:yyyy).
- product_code: the Product Number as printed.
- quantity: as printed (for example 5.000).
- description: the Product Description as printed.
- reference: the Sales Order/Your Reference cell as printed, including a sales order number and the slash when they are printed; an empty string when the cell is empty.
- net and gross: the line's Net Price and Gross Price.
- vat_code: the one letter of the VAT Code column.

line_count: how many lines you copied into lines.
vat_summary: each row of the VAT summary printed under the lines: its VAT code, its rate as a number (20 for "Std Rated 20%"), its net, VAT and gross.
surcharge_included: the amount printed after "Surcharge included on Invoice", or null when the bill does not print it.

Every amount: digits with a decimal point and exactly two decimals, a minus sign first when the amount is negative, no currency, no thousands separators (for example 18859.74 or -2.54).

If the document is not a Royal Mail OBA bill, if a page of it is missing, or if any line, figure or code cannot be read with certainty, set readable to false and not_readable_reason to the reason, and leave lines and vat_summary empty. Otherwise set readable to true and not_readable_reason to null.
TXT;
    }

    public function schema(): array
    {
        $s = ['type' => 'string'];
        $nullable = static fn (array $t): array => ['anyOf' => [$t, ['type' => 'null']]];
        $line = ['type' => 'object', 'additionalProperties' => false,
            'required' => ['service_date', 'product_code', 'quantity', 'description', 'reference', 'net', 'gross', 'vat_code'],
            'properties' => ['service_date' => $s, 'product_code' => $s, 'quantity' => $s, 'description' => $s, 'reference' => $s, 'net' => $s, 'gross' => $s,
                'vat_code' => $s]];
        $summary = ['type' => 'object', 'additionalProperties' => false, 'required' => ['vat_code', 'rate_percent', 'net', 'vat', 'gross'],
            'properties' => ['vat_code' => $s, 'rate_percent' => $s, 'net' => $s, 'vat' => $s, 'gross' => $s]];
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['readable', 'not_readable_reason', 'account', 'invoice_number', 'invoice_date', 'currency', 'net', 'vat', 'gross', 'lines', 'line_count',
                'vat_summary', 'surcharge_included'],
            'properties' => [
                'readable' => ['type' => 'boolean'],
                'not_readable_reason' => $nullable(['type' => 'string', 'enum' => self::NOT_READABLE]),
                'account' => $s, 'invoice_number' => $s, 'invoice_date' => $s, 'currency' => $s, 'net' => $s, 'vat' => $s, 'gross' => $s,
                'lines' => ['type' => 'array', 'items' => $line],
                'line_count' => ['type' => 'integer'],
                'vat_summary' => ['type' => 'array', 'items' => $summary],
                'surcharge_included' => $nullable($s),
            ]];
    }

    /**
     * Every field in its form (the words name the field and the line, never what it holds); amounts and quantities kept as printed (CW's own
     * reader reads them), the product code in capitals, text squashed to one line.
     *
     * @return array{account: string, invoice_number: string, invoice_date: string, net: string, vat: string, gross: string, surcharge: ?string,
     *   lines: list<array{date: string, product: string, qty: string, description: string, reference: string, net: string, gross: string, vat_code: string}>,
     *   summary: list<array{vat_code: string, rate: string, net: string, vat: string, gross: string}>}
     */
    public function shape(array $answer): array
    {
        if (($answer['readable'] ?? null) !== true) {
            $why = $answer['not_readable_reason'] ?? null;
            throw new CwException('ai_not_readable', is_string($why) && in_array($why, self::NOT_READABLE, true) ? $why : 'other', 422);
        }
        $bad = static fn (string $what): CwException => new CwException('ai_bad_answer', $what, 422);
        $str = static function (array $a, string $k, string $what) use ($bad): string {
            $v = $a[$k] ?? null;
            if (!is_string($v)) {
                throw $bad($what);
            }
            return trim((string) preg_replace('/\s+/u', ' ', $v));
        };
        if (strtoupper($str($answer, 'currency', 'the currency')) !== 'GBP') {
            throw new CwException('ai_not_gbp', 'the bill is not in pounds (GBP)', 422);
        }
        $out = ['account' => $str($answer, 'account', 'the customer account number'), 'invoice_number' => $str($answer, 'invoice_number', 'the invoice number')];
        if (preg_match('/^[A-Za-z0-9-]{1,32}$/D', $out['account']) !== 1) {
            throw $bad('the customer account number');
        }
        if (preg_match('/^[0-9]{6,16}$/D', $out['invoice_number']) !== 1) {
            throw $bad('the invoice number');
        }
        $date = $str($answer, 'invoice_date', 'the bill\'s date');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw $bad('the bill\'s date');
        }
        $out['invoice_date'] = $date;
        foreach (['net' => 'the net total', 'vat' => 'the VAT', 'gross' => 'the total with VAT'] as $k => $what) {
            $out[$k] = self::amount($str($answer, $k, $what)) ?? throw $bad($what);
        }
        $sur = $answer['surcharge_included'] ?? null;
        $out['surcharge'] = $sur === null ? null : (is_string($sur) ? self::amount(trim($sur)) : null) ?? throw $bad('the surcharge included');
        $lines = $answer['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || $lines === []) {
            throw $bad('the lines (none)');
        }
        if (count($lines) > self::MAX_LINES) {
            throw $bad('the lines (more than ' . self::MAX_LINES . ')');
        }
        if (!is_int($answer['line_count'] ?? null) || $answer['line_count'] !== count($lines)) {
            // Claude's own count of the lines against the lines it gave: a reading that lost or doubled lines on the way is refused
            throw new CwException('ai_count', (string) count($lines), 422);
        }
        $out['lines'] = [];
        foreach ($lines as $i => $l) {
            $n = $i + 1;
            if (!is_array($l)) {
                throw $bad("line {$n}");
            }
            $date = $str($l, 'service_date', "line {$n}: its date");
            if (preg_match('/^(\d{2})[:.\/-](\d{2})[:.\/-](\d{4})$/D', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                throw $bad("line {$n}: its date");
            }
            $product = strtoupper($str($l, 'product_code', "line {$n}: its product code"));
            if (preg_match('/^[A-Z0-9]{1,12}$/D', $product) !== 1) {
                throw $bad("line {$n}: its product code");
            }
            $qty = $str($l, 'quantity', "line {$n}: its quantity");
            if (preg_match('/^-?[0-9][0-9,]{0,12}(\.[0-9]{1,3})?$/D', $qty) !== 1) {
                throw $bad("line {$n}: its quantity");
            }
            $text = [];
            foreach (['description' => 'its description', 'reference' => 'its reference'] as $k => $what) {
                $v = $str($l, $k, "line {$n}: {$what}");
                if (mb_strlen($v) > self::TEXT_MAX || preg_match('/[\x00-\x1F\x7F]/', $v) === 1 || preg_match('/GBP\s*-?\s*[0-9]/i', $v) === 1) {
                    throw $bad("line {$n}: {$what}");
                }
                $text[$k] = $v;
            }
            $net = self::amount($str($l, 'net', "line {$n}: its net")) ?? throw $bad("line {$n}: its net");
            $gross = self::amount($str($l, 'gross', "line {$n}: its gross")) ?? throw $bad("line {$n}: its gross");
            $vat = $str($l, 'vat_code', "line {$n}: its VAT code");
            if (preg_match('/^[A-Z]$/D', $vat) !== 1) {
                throw $bad("line {$n}: its VAT code");
            }
            $out['lines'][] = ['date' => "{$m[1]}:{$m[2]}:{$m[3]}", 'product' => $product, 'qty' => $qty, 'description' => $text['description'],
                'reference' => $text['reference'], 'net' => $net, 'gross' => $gross, 'vat_code' => $vat];
        }
        $summary = $answer['vat_summary'] ?? null;
        if (!is_array($summary) || !array_is_list($summary) || count($summary) > 10) {
            throw $bad('the VAT summary');
        }
        $out['summary'] = [];
        foreach ($summary as $i => $r) {
            $n = $i + 1;
            if (!is_array($r)) {
                throw $bad("the VAT summary's row {$n}");
            }
            $code = $str($r, 'vat_code', "the VAT summary's row {$n}");
            $rate = $str($r, 'rate_percent', "the VAT summary's row {$n}");
            if (preg_match('/^[A-Z]$/D', $code) !== 1 || preg_match('/^[0-9]{1,2}(\.[0-9]{1,2})?$/D', rtrim($rate, '%')) !== 1) {
                throw $bad("the VAT summary's row {$n}");
            }
            $row = ['vat_code' => $code, 'rate' => rtrim($rate, '%')];
            foreach (['net', 'vat', 'gross'] as $k) {
                $row[$k] = self::amount($str($r, $k, "the VAT summary's row {$n}")) ?? throw $bad("the VAT summary's row {$n}");
            }
            $out['summary'][] = $row;
        }
        return $out;
    }

    /**
     * The bill as the page reader's text (RoyalMailBillPage::fromSpaced; the shape of the owner's bills of 2, 9 and 16 Nov 2026): the top block,
     * the heading on one line, each line of the bill on two lines (date, product, quantity, description; then the reference and the amounts),
     * the surcharge note and the VAT summary. No tab anywhere (shape() squashed every field to one line), so CW's reader reads it as that text.
     *
     * @param array<string, mixed> $b shape()
     */
    public static function render(array $b): string
    {
        $gbp = static fn (string $a): string => 'GBP ' . $a;
        $date = (new \DateTimeImmutable((string) $b['invoice_date'], new \DateTimeZone('UTC')))->format('M j, Y');
        $out = "Customer Account Number: {$b['account']}\nDate: {$date}\nTotal Price Net: " . $gbp((string) $b['net']) . "\nVAT: " . $gbp((string) $b['vat'])
            . "\nTotal Gross Price: " . $gbp((string) $b['gross']) . "\nInvoice: {$b['invoice_number']}\n" . implode(' ', RoyalMailBillPage::LINE_HEADINGS) . "\n";
        foreach ($b['lines'] as $l) {
            $ref = (string) $l['reference'];
            // the reference cell as printed: "/ CLICK AND DROP M2281"; one that prints its sales order ("7181703660/", "5155465848/ ADJ...") as it is
            $cell = $ref === '' ? '/' : (str_contains($ref, '/') ? $ref : '/ ' . $ref);
            $out .= trim("{$l['date']} {$l['product']} {$l['qty']} {$l['description']}") . "\n{$cell} " . $gbp((string) $l['net']) . ' ' . $gbp((string) $l['gross'])
                . " {$l['vat_code']}\n";
        }
        if ($b['surcharge'] !== null) {
            $out .= RoyalMailBillPage::SURCHARGE_NOTE . ' ' . $gbp((string) $b['surcharge']) . "\n";
        }
        foreach ($b['summary'] as $r) {
            $out .= "{$r['vat_code']} Rated {$r['rate']}% " . $gbp((string) $r['net']) . ' ' . $gbp((string) $r['vat']) . ' ' . $gbp((string) $r['gross']) . "\n";
        }
        return $out;
    }

    /** "18,859.74" / "-2.54" as "18859.74" / "-2.54"; null: not an amount with two decimals. */
    private static function amount(string $s): ?string
    {
        if (preg_match(self::AMOUNT, $s, $m) !== 1) {
            return null;
        }
        return ($m[1] ?? '') . str_replace(',', '', $m[2]) . '.' . $m[3];
    }
}
