<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * Reads Royal Mail's weekly OBA bill (delivery cost, phase 1; docs/decisions.md DL52-DL55). Pure: no database, no file kept.
 *
 * Two ways in, one reader: the bill's page saved from the browser (Ctrl+S, "Webpage, complete": HTML) or the page's text copied with
 * select-all and pasted (cells split by tabs). The page (SAP Biller Direct, cw_work/delivery_cost_20261123/RM_BILL_PAGE_SHAPE.md): a top block
 * ("Customer Account Number: 0706703000", "Date: Nov 16, 2026", "Total Price Net: GBP 18,859.74", "VAT: GBP 3,771.95", "Total Gross Price: GBP
 * 22,631.69", "Invoice: 9077300608"), then the line table whose header names the 8 columns (LINE_HEADINGS), one row per line: service date
 * (dd:mm:yyyy), product code, quantity ("5.000"), description, the Sales Order / Your Reference cell (a form button with Royal Mail's sales
 * order, a hidden input of the session, and the reference text), net, gross ("GBP 53.08"), VAT code; then a VAT summary row per code ("T",
 * "Std Rated 20%", net, VAT, gross) and a total row.
 *
 * Only VISIBLE cells are read: script, style and every input that is not a button are dropped before anything is read (a saved page keeps the
 * session's hidden inputs: never read, never kept). A data row is read from both ends (the first four cells and the last three), so a
 * reference cell split in two (the button and the text) or missing reads the same.
 *
 * Kept per row: the service date, product code, quantity (whole), net, gross, VAT code, and the reference ONLY in a known form (KNOWN refs:
 * a pre-advice id of digits, "CLICK AND DROP M<n>", "ADJ<sales order>RCN", "<Rectification|IPROL> Week <n>"; a 10-digit sales order printed
 * with a manifest row); the description and any other reference text are never kept (free text could hold a name).
 *
 * The WHOLE bill is refused (nothing stored) when: the top block is incomplete or not in pounds (GBP); a row's date, quantity, amount or VAT
 * code is not one; a product code is not one of the carrier's (unknown product: refused, named by its code when it is a code's shape); a row
 * holds an amount but is no line, summary or total; there are no lines; the rows do not add up to the printed net and gross EXACTLY; the
 * printed VAT is not gross - net; the VAT does not follow from the rows by their codes (vat(): each code's rows' VAT at its rate within the
 * rows' own rounding, a summary row exactly its code's rows; a code with no summary row takes CW's rate for it: T 20%, Z 0%; no fixed 20% per
 * row). A refusal names the row and the column, never what a cell holds.
 */
final class RoyalMailBillPage
{
    public const CARRIER = 'royal_mail';
    /** The largest file read (a week's page is about 0.5 MB). */
    public const MAX_BYTES = 16 * 1_048_576;
    /** The most rows one bill may have (a week is about 160). */
    public const MAX_ROWS = 5_000;
    /** The line table's 8 headings, in order. */
    public const LINE_HEADINGS = ['Service Rendered Date', 'Product Number', 'Quantity', 'Product Description', 'Sales Order/Your Reference', 'Net Price',
        'Gross Price', 'VAT Code'];
    /** A VAT code with no summary row on the bill: CW's rate for it, in percent. */
    public const VAT_RATES = ['T' => '20', 'Z' => '0'];

    /**
     * @param list<string> $products the carrier's product codes (carrier_product)
     * @return array{carrier: string, account: string, invoice_no: string, invoice_date: string, period_from: string, period_to: string, net: int,
     *   vat: int, gross: int, sha256: string, rows: list<array{row_no: int, service_date: string, product_code: string, qty: int, net: int, gross: int,
     *   vat_code: string, ref_kind: string, ref_no: ?string, sales_order: ?string}>}
     */
    public static function parse(string $bytes, array $products): array
    {
        if ($bytes === '') {
            throw self::refuse('the file or the pasted text is empty');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw self::refuse('it is larger than ' . intdiv(self::MAX_BYTES, 1_048_576) . ' MB');
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }
        $bytes = str_replace("\u{00A0}", ' ', $bytes);
        $html = preg_match('/<\s*(table|html|body|tr)\b/i', $bytes) === 1;
        [$top, $table] = $html ? self::fromHtml($bytes) : self::fromText($bytes);
        $head = self::top($top);
        $out = self::rows($table, $products);
        $rows = $out['rows'];
        $net = array_sum(array_column($rows, 'net'));
        $gross = array_sum(array_column($rows, 'gross'));
        if ($net !== $head['net']) {
            throw self::refuse(sprintf('its lines add up to %s, not to the bill\'s net total %s: is the whole bill there?', DpdInvoiceFile::gbp($net),
                DpdInvoiceFile::gbp($head['net'])));
        }
        if ($gross !== $head['gross']) {
            throw self::refuse(sprintf('its lines\' gross add up to %s, not to the bill\'s total with VAT %s', DpdInvoiceFile::gbp($gross), DpdInvoiceFile::gbp($head['gross'])));
        }
        if ($head['gross'] - $head['net'] !== $head['vat']) {
            throw self::refuse('the bill\'s VAT is not its total with VAT less its net');
        }
        self::vat($rows, $out['summary'], $head['vat']);
        $days = array_column($rows, 'service_date');
        return ['carrier' => self::CARRIER, 'account' => $head['account'], 'invoice_no' => $head['invoice_no'], 'invoice_date' => $head['date'],
            'period_from' => min($days), 'period_to' => max($days), 'net' => $head['net'], 'vat' => $head['vat'], 'gross' => $head['gross'],
            'sha256' => hash('sha256', $bytes), 'rows' => $rows];
    }

    /**
     * The page's visible text (the top block) and its line table's rows as lists of visible cells.
     *
     * @return array{0: string, 1: list<list<string>>|null}
     */
    private static function fromHtml(string $bytes): array
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadHTML('<?xml encoding="UTF-8">' . $bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($ok === false) {
            throw self::refuse('it is not a page CW can read: save the bill\'s page again (Ctrl+S, "Webpage, complete")');
        }
        $x = new \DOMXPath($doc);
        // Never read: scripts, styles, and every input that is not a button (a saved page keeps the session's hidden inputs).
        foreach (iterator_to_array($x->query('//script|//style|//noscript|//template|//input[not(@type="submit" or @type="button")]') ?: []) as $n) {
            $n->parentNode?->removeChild($n);
        }
        $table = null;
        foreach ($x->query('//tr') ?: [] as $tr) {
            if (!$tr instanceof \DOMElement) {
                continue;
            }
            if ($table === null) {
                if (self::isHeading(self::cells($tr))) {
                    $table = [];
                    $owner = $tr->parentNode;
                    while ($owner !== null && !($owner instanceof \DOMElement && strtolower($owner->nodeName) === 'table')) {
                        $owner = $owner->parentNode;
                    }
                    $within = $owner;
                }
                continue;
            }
            // the rows after the heading, in the heading's table only
            $t = $tr->parentNode;
            while ($t !== null && !($t instanceof \DOMElement && strtolower($t->nodeName) === 'table')) {
                $t = $t->parentNode;
            }
            if ($t !== ($within ?? null)) {
                continue;
            }
            $table[] = self::cells($tr);
        }
        $body = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        return [self::text($body), $table];
    }

    /**
     * The pasted text: the top block is everything; the line table is the lines after the heading line, cells split by tabs.
     *
     * @return array{0: string, 1: list<list<string>>|null}
     */
    private static function fromText(string $bytes): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $bytes) ?: [];
        $table = null;
        foreach ($lines as $line) {
            $cells = array_map(static fn (string $c): string => self::squash($c), explode("\t", $line));
            if ($table === null) {
                if (self::isHeading($cells)) {
                    $table = [];
                }
                continue;
            }
            $table[] = $cells;
        }
        return [self::squash(str_replace("\t", ' ', $bytes)), $table];
    }

    /** @param list<string> $cells */
    private static function isHeading(array $cells): bool
    {
        $cells = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));
        $want = array_map('strtolower', self::LINE_HEADINGS);
        return array_map('strtolower', $cells) === $want;
    }

    /**
     * A row's visible cells, in order: its td / th (their text and their buttons' labels) and anything else it holds directly (a form: its
     * buttons' labels).
     *
     * @return list<string>
     */
    private static function cells(\DOMElement $tr): array
    {
        $out = [];
        foreach ($tr->childNodes as $c) {
            if (!$c instanceof \DOMElement) {
                if ($c instanceof \DOMText && trim($c->textContent) !== '') {
                    $out[] = self::squash($c->textContent);
                }
                continue;
            }
            $name = strtolower($c->nodeName);
            if ($name === 'td' || $name === 'th') {
                $out[] = self::text($c);
            } elseif ($name === 'form' || $name === 'button' || $name === 'input' || $name === 'span' || $name === 'div' || $name === 'a') {
                $t = self::text($c);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }
        return $out;
    }

    /** An element's visible text: its text nodes and its buttons' labels, spaces squashed. */
    private static function text(\DOMNode $n): string
    {
        $parts = [];
        $walk = static function (\DOMNode $n) use (&$walk, &$parts): void {
            foreach ($n->childNodes as $c) {
                if ($c instanceof \DOMText) {
                    $parts[] = $c->textContent;
                } elseif ($c instanceof \DOMElement) {
                    $name = strtolower($c->nodeName);
                    if ($name === 'input') {
                        $parts[] = ' ' . $c->getAttribute('value') . ' '; // a button's label (every other input was dropped)
                        continue;
                    }
                    $block = in_array($name, ['td', 'th', 'tr', 'div', 'p', 'br', 'table', 'li', 'form', 'h1', 'h2', 'h3', 'h4'], true);
                    if ($block) {
                        $parts[] = ' ';
                    }
                    $walk($c);
                    if ($block) {
                        $parts[] = ' ';
                    }
                }
            }
        };
        if ($n instanceof \DOMElement && strtolower($n->nodeName) === 'input') {
            return self::squash($n->getAttribute('value'));
        }
        $walk($n);
        return self::squash(implode('', $parts));
    }

    private static function squash(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * The top block's account, number, date and totals.
     *
     * @return array{account: string, invoice_no: string, date: string, net: int, vat: int, gross: int}
     */
    private static function top(string $text): array
    {
        $find = static function (string $re, string $what) use ($text): string {
            if (preg_match($re, $text, $m) !== 1) {
                throw self::refuse("the bill does not show its {$what}: save or copy the whole page of the bill");
            }
            return $m[1];
        };
        $account = $find('/Customer Account Number:?\s*([A-Za-z0-9-]{1,32})\b/i', 'customer account number');
        $invoice = $find('/\bInvoice:?\s*([0-9]{6,16})\b/i', 'invoice number');
        $date = $find('/(?<!Due )(?<!Due)\bDate:?\s*([A-Za-z]{3,9}\.?\s+[0-9]{1,2},?\s+[0-9]{4})/i', 'date');
        $day = \DateTimeImmutable::createFromFormat('!M j Y', (string) preg_replace('/[.,]/', '', $date), new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('!F j Y', (string) preg_replace('/[.,]/', '', $date), new \DateTimeZone('UTC'));
        if ($day === false) {
            throw self::refuse('the bill\'s date is not a day');
        }
        $money = static function (string $label, string $what) use ($text): int {
            if (preg_match('/' . $label . ':?\s*(-?\s*[A-Z]{3}\s*-?\s*[0-9][0-9,]*\.[0-9]{2})/i', $text, $m) !== 1) {
                throw self::refuse("the bill does not show its {$what}: save or copy the whole page of the bill");
            }
            $p = self::amount($m[1]);
            if ($p === null) {
                throw self::refuse("the bill's {$what} is not an amount in pounds (GBP)");
            }
            return $p;
        };
        return ['account' => $account, 'invoice_no' => $invoice, 'date' => $day->format('Y-m-d'), 'net' => $money('Total Price Net', 'net total'),
            'vat' => $money('(?<![A-Za-z])VAT(?!\s*Code)', 'VAT'), 'gross' => $money('Total Gross Price', 'total with VAT')];
    }

    /** "GBP 18,859.74", "GBP -2.54", "-GBP 2.54" as pence; null: not an amount in pounds. */
    public static function amount(string $s): ?int
    {
        $s = self::squash($s);
        if (preg_match('/^(-)?\s*GBP\s*(-)?\s*(\d{1,3}(?:,\d{3})+|\d{1,12})\.(\d{2})$/D', $s, $m) !== 1) {
            return null;
        }
        $p = (int) str_replace(',', '', $m[3]) * 100 + (int) $m[4];
        return ($m[1] === '-' || $m[2] === '-') ? -$p : $p;
    }

    /**
     * The line table's rows read: the lines, and the VAT summary per code.
     *
     * @param list<list<string>>|null $table
     * @param list<string> $products
     * @return array{rows: list<array<string, mixed>>, summary: array<string, array{rate: string, net: int, vat: int, gross: int}>}
     */
    private static function rows(?array $table, array $products): array
    {
        if ($table === null) {
            throw self::refuse('CW cannot find the table of the bill\'s lines (its columns ' . implode(', ', self::LINE_HEADINGS) . '): save or copy the whole page of the bill');
        }
        $rows = [];
        $summary = [];
        $n = 0;
        foreach ($table as $i => $cells) {
            $at = $i + 1;
            $cells = array_values($cells);
            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }
            if ($cells === [] || implode('', $cells) === '') {
                continue;
            }
            if (preg_match('/^\d{2}[:.\/]\d{2}[:.\/]\d{4}$/D', $cells[0]) === 1) {
                if (++$n > self::MAX_ROWS) {
                    throw self::refuse('it has more than ' . self::MAX_ROWS . ' lines');
                }
                $rows[] = self::line($cells, $n, $at, $products);
                continue;
            }
            $amounts = array_values(array_filter($cells, static fn (string $c): bool => self::amount($c) !== null));
            if ($amounts === []) {
                continue; // a row with no amount (a heading, a page note): nothing to read
            }
            $first = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));
            if (strcasecmp($first[0] ?? '', 'Total') === 0) {
                continue; // the total row: the gross again (checked against the top)
            }
            // a VAT summary row: a one-letter code, then its rate ("Std Rated 20%"), then net, VAT and gross
            $code = null;
            foreach ($first as $k => $c) {
                if (preg_match('/^[A-Z]$/D', $c) === 1 && isset($first[$k + 1]) && preg_match('/([0-9]{1,2}(?:\.[0-9]{1,2})?)\s*%/', $first[$k + 1], $r) === 1
                    && count($first) >= $k + 5) {
                    $code = $c;
                    $vals = array_map(static fn (string $v): ?int => self::amount($v), array_slice($first, $k + 2, 3));
                    if (in_array(null, $vals, true)) {
                        throw self::refuse("row {$at} of the lines' table (the VAT summary) does not hold three amounts in pounds");
                    }
                    if (isset($summary[$code])) {
                        throw self::refuse("the VAT summary names code {$code} twice");
                    }
                    $summary[$code] = ['rate' => $r[1], 'net' => (int) $vals[0], 'vat' => (int) $vals[1], 'gross' => (int) $vals[2]];
                    break;
                }
            }
            if ($code === null) {
                throw self::refuse("row {$at} of the lines' table holds an amount but is not a line CW can read");
            }
        }
        if ($rows === []) {
            throw self::refuse('the bill has no lines');
        }
        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * One line read from both ends: date, product, quantity, description (not kept) ... reference ... net, gross, VAT code.
     *
     * @param list<string> $cells
     * @param list<string> $products
     * @return array<string, mixed>
     */
    private static function line(array $cells, int $n, int $at, array $products): array
    {
        if (count($cells) < 7) {
            throw self::refuse("row {$at} of the lines' table has " . count($cells) . ' cells: a line has at least 7 (date, product, quantity, description, net, gross, VAT code)');
        }
        $c = count($cells);
        [$d, $mo, $y] = preg_split('/[:.\/]/', $cells[0]) ?: [];
        if (!checkdate((int) $mo, (int) $d, (int) $y)) {
            throw self::refuse("row {$at}: its Service Rendered Date is not a day");
        }
        $product = strtoupper($cells[1]);
        if (preg_match('/^[A-Z0-9]{2,8}$/D', $product) !== 1) {
            throw self::refuse("row {$at}: its Product Number is not a product code");
        }
        if (!in_array($product, $products, true)) {
            throw self::refuse(preg_match('/^[A-Z][A-Z0-9]{1,3}$/D', $product) === 1
                ? "row {$at}: product {$product} is one CW does not know: tell the CW team (it must be added before this bill can be read)"
                : "row {$at}: its product is one CW does not know: tell the CW team");
        }
        if (preg_match('/^(-?)(\d{1,3}(?:,\d{3})+|\d{1,9})(?:\.0{1,3})?$/D', $cells[2], $q) !== 1) {
            throw self::refuse("row {$at}: its Quantity is not a whole number");
        }
        $qty = (int) str_replace(',', '', $q[2]) * ($q[1] === '-' ? -1 : 1);
        $net = self::amount($cells[$c - 3]);
        $gross = self::amount($cells[$c - 2]);
        if ($net === null || $gross === null) {
            throw self::refuse("row {$at}: its " . ($net === null ? 'Net Price' : 'Gross Price') . ' is not an amount in pounds (GBP)');
        }
        $vat = $cells[$c - 1];
        if (preg_match('/^[A-Z]$/D', $vat) !== 1) {
            throw self::refuse("row {$at}: its VAT Code is not a code");
        }
        return ['row_no' => $n, 'service_date' => sprintf('%04d-%02d-%02d', (int) $y, (int) $mo, (int) $d), 'product_code' => $product, 'qty' => $qty, 'net' => $net,
            'gross' => $gross, 'vat_code' => $vat] + self::reference(implode(' ', array_slice($cells, 4, max(0, $c - 7))));
    }

    /**
     * The reference in a known form only (the rest is never kept).
     *
     * @return array{ref_kind: string, ref_no: ?string, sales_order: ?string}
     */
    public static function reference(string $text): array
    {
        $t = self::squash($text);
        $sales = null;
        if (preg_match('/^(\d{10})\b\s*\/?\s*(.*)$/D', $t, $m) === 1) {
            $sales = $m[1];
            $t = $m[2];
        }
        $t = trim((string) preg_replace('/^\/\s*/', '', $t));
        if (preg_match('/^CLICK AND DROP M(\d{1,9})$/Di', $t, $m) === 1) {
            return ['ref_kind' => 'manifest', 'ref_no' => ltrim($m[1], '0') ?: '0', 'sales_order' => $sales];
        }
        if (preg_match('/^ADJ(\d{10})RCN(?:\s+Surcharge)?$/Di', $t, $m) === 1) {
            return ['ref_kind' => 'sales_order', 'ref_no' => $m[1], 'sales_order' => null];
        }
        if (preg_match('/^(?:Rectification|IPROL) Week (\d{1,2})$/Di', $t, $m) === 1) {
            return ['ref_kind' => 'week', 'ref_no' => $m[1], 'sales_order' => null];
        }
        if (preg_match('/^(\d{1,9})$/D', $t, $m) === 1) {
            return ['ref_kind' => 'preadvice', 'ref_no' => $m[1], 'sales_order' => $sales];
        }
        return ['ref_kind' => 'none', 'ref_no' => null, 'sales_order' => $t === '' ? $sales : null];
    }

    /**
     * The VAT by the rows' codes (DL53). Royal Mail rounds each row's VAT on its own: the bill's VAT is its rows' gross less their net (its
     * total gross less its net, checked before), which is not always the bill's net at 20% rounded once (the real bills of 9 Nov and 26 Oct
     * 2026 are a penny apart that way). So per code: its rows' VAT (gross - net) is its rate on its rows' net within the rows' own rounding (half
     * a penny a row, and a penny), the rate its summary row's ("Std Rated 20%") or CW's for the code (VAT_RATES); a summary row's net, VAT and
     * gross are exactly its code's rows'. A code with no summary row and no rate known to CW is refused; a zero-rated row is fine.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, array{rate: string, net: int, vat: int, gross: int}> $summary
     */
    private static function vat(array $rows, array $summary, int $printed): void
    {
        $net = [];
        $gross = [];
        $count = [];
        foreach ($rows as $r) {
            $net[$r['vat_code']] = ($net[$r['vat_code']] ?? 0) + (int) $r['net'];
            $gross[$r['vat_code']] = ($gross[$r['vat_code']] ?? 0) + (int) $r['gross'];
            $count[$r['vat_code']] = ($count[$r['vat_code']] ?? 0) + 1;
        }
        foreach (array_keys($summary) as $code) {
            if (!isset($net[$code])) {
                throw self::refuse("the VAT summary names code {$code}, which no line has");
            }
        }
        $total = 0;
        ksort($net);
        foreach ($net as $code => $p) {
            $rate = $summary[$code]['rate'] ?? self::VAT_RATES[$code] ?? null;
            if ($rate === null) {
                throw self::refuse("VAT code {$code} has no summary row on the bill and CW does not know its rate");
            }
            $vat = $gross[$code] - $p;
            // |vat - net x rate| <= rows / 2 + 1 pence, in hundredths of a penny (bcmath: no float)
            $off = bcsub(bcmul((string) $vat, '100', 0), bcmul((string) $p, $rate, 2), 2);
            $slack = bcadd(bcmul((string) $count[$code], '50', 0), '100', 0);
            if (bccomp(ltrim($off, '-'), $slack, 2) > 0) {
                throw self::refuse("the VAT of the lines of code {$code} does not follow from the lines at its rate ({$rate}%)");
            }
            if (isset($summary[$code]) && ($summary[$code]['net'] !== $p || $summary[$code]['vat'] !== $vat || $summary[$code]['gross'] !== $gross[$code])) {
                throw self::refuse("the VAT summary of code {$code} does not follow from the lines of that code");
            }
            $total += $vat;
        }
        if ($total !== $printed) {
            throw self::refuse(sprintf('the VAT by the lines\' codes is %s, not the bill\'s VAT %s', DpdInvoiceFile::gbp($total), DpdInvoiceFile::gbp($printed)));
        }
    }

    /** @param array<string, mixed> $detail */
    private static function refuse(string $why, array $detail = []): CwException
    {
        return new CwException('bad_file', 'Royal Mail bill not read: ' . $why . (str_ends_with($why, '?') ? '' : '.') . ' Nothing was saved.', 422, $detail);
    }
}
