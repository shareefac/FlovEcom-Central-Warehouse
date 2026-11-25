<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * Reads Royal Mail's weekly OBA bill (delivery cost, phase 1; docs/decisions.md DL52-DL55). Pure: no database, no file kept.
 *
 * Two ways in, one reader: the bill's page saved from the browser (Ctrl+S, "Webpage, complete": HTML) or the page's text copied with
 * select-all and pasted (cells split by tabs; a copy from Chrome on a Mac breaks each line of the bill in two: joined again, CW-1181, AI13; a
 * PDF or a photo of the bill read by Claude is written out as the page reader's text and read here: CW-1182, AI4). The page (SAP Biller Direct, cw_work/delivery_cost_20261123/RM_BILL_PAGE_SHAPE.md): a top block
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
    /** A bill line's first line as a page reader gives it: a service date (any separator), a product code, a quantity. */
    private const SPACED_LINE = '/^\d{2}[:.\/-]\d{2}[:.\/-]\d{4}\s+\S+\s+-?[0-9][0-9,]*(\.[0-9]+)?(\s|$)/D';
    /** A note the bill prints under its lines with an amount (not a line, not money of its own). */
    public const SURCHARGE_NOTE = 'Surcharge included on Invoice';
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
        $bill = ['carrier' => self::CARRIER, 'account' => $head['account'], 'invoice_no' => $head['invoice_no'], 'invoice_date' => $head['date'],
            'period_from' => min($days), 'period_to' => max($days), 'net' => $head['net'], 'vat' => $head['vat'], 'gross' => $head['gross'], 'rows' => $rows];
        // The fingerprint of what was READ, not of the bytes (DL54): a page saved twice holds a new session token each time, and the pasted text of
        // the same bill is other bytes; the same bill read again is "the same file".
        return $bill + ['sha256' => hash('sha256', json_encode($bill, JSON_THROW_ON_ERROR))];
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
        // ... nor anything the page hides (the hidden attribute, aria-hidden, display: none, visibility: hidden): the Codex check r1, MEDIUM 13
        foreach (iterator_to_array($x->query('//*[@hidden or @aria-hidden="true" or contains(translate(translate(@style, " ", ""), "DISPLAYNOEVSBTH", "displaynoevsbth"), '
            . '"display:none") or contains(translate(translate(@style, " ", ""), "VISIBLTYHDN", "visibltyhdn"), "visibility:hidden")]') ?: []) as $n) {
            $n->parentNode?->removeChild($n);
        }
        $table = null;
        $early = null;
        foreach ($x->query('//tr') ?: [] as $tr) {
            if (!$tr instanceof \DOMElement) {
                continue;
            }
            // one table of lines only: a second heading, or a row shaped as a bill line outside the heading's table (before it or in another
            // table), refuses the whole page: never read as the first table's lines alone (the astra re-check of b581e70, F2)
            if ($table !== null && self::isHeading(self::cells($tr))) {
                throw self::refuse('it has more than one table of lines: save the bill\'s own page again (Ctrl+S, "Webpage, complete")');
            }
            if ($table === null) {
                if (self::isLineStart(self::cells($tr))) {
                    $early ??= count($x->query('preceding::tr', $tr) ?: []) + 1; // refused once a heading follows (else: no table)
                }
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
                if (self::isLineStart(self::cells($tr))) {
                    throw self::refuse('it has lines of the bill outside its table of lines: save the bill\'s own page again (Ctrl+S, "Webpage, complete")');
                }
                continue;
            }
            $table[] = self::cells($tr);
        }
        if ($table !== null && $early !== null) {
            throw self::refuse('it has a line of the bill before the table of lines (row ' . $early . ')');
        }
        $body = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        // the top block from the page's text only: a button's label is read only in a row of the lines (the Codex check r1, MEDIUM 13)
        return [self::text($body, false), $table];
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
        $spacedHeading = strtolower(implode(' ', self::LINE_HEADINGS));
        $tabbed = array_filter($lines, static fn (string $l): bool => str_contains($l, "\t")
            && self::isHeading(array_map(static fn (string $c): string => self::squash($c), explode("\t", $l)))) !== [];
        foreach ($lines as $n => $line) {
            if (!$tabbed) {
                break; // no tabbed heading: the page reader's text (fromSpaced, its own checks)
            }
            $cells = array_map(static fn (string $c): string => self::squash($c), explode("\t", $line));
            if (!str_contains($line, "\t") && strtolower(self::squash($line)) === $spacedHeading) {
                // a section of lines as a page reader gives them beside a tabbed one: one section of lines only (the astra re-check of b581e70, F2)
                throw self::refuse('it has more than one section of lines (line ' . ($n + 1) . '): copy the bill\'s page once, whole');
            }
            if ($table === null) {
                if (self::isHeading($cells)) {
                    $table = [];
                } elseif (self::isLineStart($cells) || preg_match(self::SPACED_LINE, self::squash($line)) === 1) {
                    throw self::refuse('it has a line of the bill before its heading of lines (line ' . ($n + 1) . ')');
                }
                continue;
            }
            if (self::isHeading($cells)) {
                throw self::refuse('it has more than one heading of lines (line ' . ($n + 1) . '): copy the bill\'s page once, whole');
            }
            $table[] = $cells;
        }
        return [self::squash(str_replace("\t", ' ', $bytes)), $table === null ? self::fromSpaced($lines) : self::joinTabbed($table)];
    }

    /**
     * A tabbed copy whose lines of the bill come on TWO lines (CW-1181; Chrome on a Mac breaks a row where the reference cell starts with the
     * sales order's form): "date, product, quantity, description" then "reference, net, gross, VAT code" (the reference may be empty, a
     * description may run on over lines of its own), joined into the one row of cells the page gives, as fromSpaced() joins the page
     * reader's two lines. Only a row that starts as a bill line, holds fewer than 7 cells and no amount waits for the rest: the next row that
     * ends with the amounts and a VAT code (and is no bill line itself) completes it; a row of words between them is its description's.
     * A copy that breaks AFTER the reference (the first half holds it) keeps every part of it (M1, the Codex final review of 86aebbd: a £12
     * line's manifest M42 was lost); words between such a half and its amounts could be either's, so that is refused, never guessed.
     * Anything else (a bill line next, money in the row between, the end of the copy) leaves the half row as it was: refused whole (a line
     * has at least 7 cells), never read as something else.
     *
     * @param list<list<string>> $rows the rows after the heading, cells split by tabs
     * @return list<list<string>>
     */
    private static function joinTabbed(array $rows): array
    {
        $out = [];
        $half = null;
        $withRef = false;
        $money = static fn (array $cells): bool => preg_grep('/GBP\s*-?\s*[0-9][0-9,]*\.[0-9]{2}/i', $cells) !== [];
        $nonEmpty = static fn (array $cells): array => array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));
        foreach ($rows as $cells) {
            $filled = $nonEmpty($cells);
            if ($half !== null) {
                $c = count($filled);
                if ($c >= 3 && !self::isLineStart($filled) && preg_match('/^[A-Z]$/D', $filled[$c - 1]) === 1 && self::amount($filled[$c - 2]) !== null
                    && self::amount($filled[$c - 3]) !== null) {
                    $out[] = [...$half, ...$filled]; // every reference part the halves hold (M1), net, gross, VAT code
                    $half = null;
                    continue;
                }
                if (!$withRef && $filled !== [] && !self::isLineStart($filled) && !$money($filled)) {
                    $half[3] = trim($half[3] . ' ' . implode(' ', $filled)); // the description over more lines
                    continue;
                }
                // a bill line next, money between, or (the first half already held its reference: M1, Codex final of 86aebbd) words before the
                // amounts that could be the description's or the reference's: never guessed, the half line left as it was (refused whole)
                $out[] = $half;
                $half = null;
            }
            if (self::isLineStart($filled) && count($filled) < 7 && !$money($filled)) {
                $lead = $cells;
                while ($lead !== [] && $lead[0] === '') {
                    array_shift($lead);
                }
                // date, product, quantity, description in their places, and every reference part after them kept (a break after the reference)
                $rest = $nonEmpty(array_slice($lead, 4));
                $half = [...array_pad(array_slice($lead, 0, 4), 4, ''), ...$rest];
                $withRef = $rest !== [];
                continue;
            }
            $out[] = $cells;
        }
        if ($half !== null) {
            $out[] = $half;
        }
        return $out;
    }

    /**
     * The page's text as a browser's page reader gives it (the owner's bills of 2, 9 and 16 Nov 2026; DL73): no tabs, the heading on one line
     * with its words joined by spaces, each line of the bill on two lines ("05:11:2026 SD1 5.000 SD GUARANTEED BY 1PM" then "/ CLICK AND DROP
     * M2281 GBP 53.08 GBP 63.70 T"; a reference may be empty: "/ GBP 7.63 GBP 9.16 T"), then the VAT summary ("T Std Rated 20% GBP ... GBP ...
     * GBP ...") and the total. Each line made into the cells the table gives (date, product, quantity, description, reference, net, gross, VAT
     * code); the description is what follows the quantity on the first line, the reference what precedes the amounts on the next.
     *
     * @param list<string> $lines
     * @return list<list<string>>|null null: no heading
     */
    private static function fromSpaced(array $lines): ?array
    {
        $heading = strtolower(implode(' ', self::LINE_HEADINGS));
        $table = null;
        $row = null;
        $amount = '-?\s*GBP\s*-?\s*[0-9][0-9,]*\.[0-9]{2}'; // the same signed grammar as amount() ("-GBP 2.54", "GBP -2.54": the Codex check r2, LOW 7)
        $tail = '/^(.*?)\s*(' . $amount . ')\s+(' . $amount . ')\s+([A-Z])$/D';
        $early = null;
        foreach ($lines as $n => $line) {
            $line = self::squash($line);
            if ($table === null) {
                if (strtolower($line) === $heading) {
                    if ($early !== null) {
                        // a line of the bill before the heading: refused, never left out (the astra re-check of b581e70, F2)
                        throw self::refuse('it has a line of the bill before its heading of lines (line ' . $early . ')');
                    }
                    $table = [];
                } elseif (preg_match(self::SPACED_LINE, $line) === 1) {
                    $early ??= $n + 1;
                }
                continue;
            }
            if (strtolower($line) === $heading) {
                throw self::refuse('it has more than one heading of lines (line ' . ($n + 1) . '): copy the bill\'s page once, whole');
            }
            if (preg_match('/^(\d{2}[:.\/]\d{2}[:.\/]\d{4})\s+(\S+)\s+(\S+)\s*(.*)$/D', $line, $m) === 1) {
                if ($row !== null) {
                    $table[] = [$row[0], $row[1], $row[2], $row[3], '']; // a line with no amounts: refused as a line (fewer than 7 cells)
                }
                $row = [$m[1], $m[2], $m[3], $m[4]];
                if (preg_match($tail, $m[4], $t) === 1) {
                    // the whole line on one: its description and reference parted at " / "
                    [$desc, $ref] = array_pad(explode(' / ', $t[1], 2), 2, '');
                    $table[] = [$m[1], $m[2], $m[3], $desc, $ref === '' ? '' : '/ ' . $ref, $t[2], $t[3], $t[4]];
                    $row = null;
                }
                continue;
            }
            if ($row !== null) {
                if (preg_match($tail, $line, $t) === 1) {
                    $table[] = [$row[0], $row[1], $row[2], $row[3], $t[1], $t[2], $t[3], $t[4]];
                    $row = null;
                    continue;
                }
                $row[3] .= ' ' . $line; // a description over more lines
                continue;
            }
            // after the lines: the VAT summary ("T Std Rated 20% GBP a GBP b GBP c"), the total, and words
            if (preg_match('/^([A-Z])\s+(.*?[0-9]{1,2}(?:\.[0-9]{1,2})?\s*%)\s+(' . $amount . ')\s+(' . $amount . ')\s+(' . $amount . ')$/D', $line, $t) === 1) {
                $table[] = [$t[1], $t[2], $t[3], $t[4], $t[5]];
                continue;
            }
            if (preg_match('/^(.*?)\s*(' . $amount . ')$/D', $line, $t) === 1) {
                $table[] = [$t[1], $t[2]];
                continue;
            }
            $table[] = [$line];
        }
        if ($row !== null && $table !== null) {
            $table[] = [$row[0], $row[1], $row[2], $row[3], ''];
        }
        return $table;
    }

    /**
     * Whether a row of cells starts as a bill line does: its first cell a service date (dd:mm:yyyy, or with . / - between). A row outside the
     * table of lines that starts so is refused, never left out (the astra re-check of b581e70, F2).
     *
     * @param list<string> $cells
     */
    private static function isLineStart(array $cells): bool
    {
        $cells = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));
        return $cells !== [] && preg_match('/^\d{2}[:.\/-]\d{2}[:.\/-]\d{4}(\s|$)/D', $cells[0]) === 1;
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

    /** An element's visible text: its text nodes and (unless $buttons is false) its buttons' labels, spaces squashed. */
    private static function text(\DOMNode $n, bool $buttons = true): string
    {
        $parts = [];
        $walk = static function (\DOMNode $n) use (&$walk, &$parts, $buttons): void {
            foreach ($n->childNodes as $c) {
                if ($c instanceof \DOMText) {
                    $parts[] = $c->textContent;
                } elseif ($c instanceof \DOMElement) {
                    $name = strtolower($c->nodeName);
                    if ($name === 'input') {
                        if ($buttons) {
                            $parts[] = ' ' . $c->getAttribute('value') . ' '; // a button's label (every other input was dropped)
                        }
                        continue;
                    }
                    if ($name === 'button' && !$buttons) {
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
            return $buttons ? self::squash($n->getAttribute('value')) : '';
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
        // Each field must be shown once (or the same each time): a second, different value (a part of the page hidden by its stylesheet, say) is
        // refused, never chosen (the Codex check r2, r1 MEDIUM 13 PARTLY).
        $find = static function (string $re, string $what) use ($text): string {
            if (preg_match_all($re, $text, $m) < 1) {
                throw self::refuse("the bill does not show its {$what}: save or copy the whole page of the bill");
            }
            $values = array_values(array_unique(array_map(static fn (string $v): string => (string) preg_replace('/\s+/', ' ', $v), $m[1])));
            if (count($values) > 1) {
                throw self::refuse("the bill shows two different {$what}s: save or copy the bill's page again");
            }
            return $values[0];
        };
        $account = $find('/Customer Account Number:?\s*([A-Za-z0-9-]{1,32})\b/i', 'customer account number');
        $invoice = $find('/\bInvoice:?\s*([0-9]{6,16})\b/i', 'invoice number');
        $date = $find('/(?<!Due )(?<!Due)\bDate:?\s*([A-Za-z]{3,9}\.?\s+[0-9]{1,2},?\s+[0-9]{4})/i', 'date');
        // a calendar day only ("Feb 31" is refused, never read as 3 March: the Codex check r1, LOW 17)
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $parts = preg_split('/\s+/', trim((string) preg_replace('/[.,]/', ' ', $date))) ?: [];
        $mon = $months[strtolower(substr($parts[0] ?? '', 0, 3))] ?? 0;
        if (count($parts) !== 3 || $mon === 0 || !checkdate($mon, (int) $parts[1], (int) $parts[2])) {
            throw self::refuse('the bill\'s date is not a day');
        }
        $day = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $parts[2], $mon, (int) $parts[1]), new \DateTimeZone('UTC'));
        $money = static function (string $label, string $what) use ($text): int {
            if (preg_match_all('/' . $label . ':?\s*(-?\s*[A-Z]{3}\s*-?\s*[0-9][0-9,]*\.[0-9]{2})/i', $text, $m) < 1) {
                throw self::refuse("the bill does not show its {$what}: save or copy the whole page of the bill");
            }
            if (count(array_unique(array_map(static fn (string $v): string => (string) preg_replace('/\s+/', '', $v), $m[1]))) > 1) {
                throw self::refuse("the bill shows two different {$what}s: save or copy the bill's page again");
            }
            $p = self::amount($m[1][0]);
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
                // a row with no amount (a heading, a page note): nothing to read; but money anywhere in a row CW does not read as a line (a line
                // whose date is written another way, its second half on its own) refuses the WHOLE bill: a row is never dropped (the final review,
                // F2: two offsetting Special Delivery lines dropped and the totals still tied)
                foreach ($cells as $k => $c) {
                    if (preg_match('/GBP\s*-?\s*[0-9][0-9,]*\.[0-9]{2}/i', $c) === 1) {
                        throw self::refuse("row {$at} of the lines' table (column " . ($k + 1) . ') holds an amount but is not a line CW can read');
                    }
                }
                continue;
            }
            $first = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));
            if (strcasecmp($first[0] ?? '', 'Total') === 0) {
                continue; // the total row: the gross again (checked against the top)
            }
            if (count($first) === 2 && strcasecmp($first[0], self::SURCHARGE_NOTE) === 0) {
                continue; // "Surcharge included on Invoice GBP 2,382.79": a note of the bill's, not a line (its meaning is Royal Mail's; DL73)
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
            // the whole bill refused (DL55; the coordinator, 24 Nov 2026, D1): the line by its place and day, the code named only in a code's shape
            // (three letters and digits with a digit: a word may be a name, the Codex checks r1 LOW 16, r2 LOW 16), and the bill sent to the team
            $where = "line {$n} of the bill (" . (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $y, (int) $mo, (int) $d)))->format('j M Y') . ')';
            throw self::refuse(preg_match('/^(?=.*[0-9])[A-Z0-9]{3}$/D', $product) === 1
                ? "{$where} has product {$product}, which CW does not know. Send this bill to the CW team: the product must be added to CW before this bill can be read"
                : "{$where} has a product CW does not know. Send this bill to the CW team: the product must be added to CW before this bill can be read");
        }
        if (preg_match('/^(-?)(\d{1,3}(?:,\d{3})+|\d{1,9})(?:\.0{1,3})?$/D', $cells[2], $q) !== 1) {
            throw self::refuse("row {$at}: its Quantity is not a whole number");
        }
        $digits = str_replace(',', '', $q[2]);
        if (strlen($digits) > 10 || (int) $digits > 2_147_483_647) {
            // what CW stores (a signed INT): a check never offers a Store that would fail (the Codex check r4, D3 LOW 4)
            throw self::refuse("row {$at}: its Quantity is larger than CW stores");
        }
        $qty = (int) $digits * ($q[1] === '-' ? -1 : 1);
        $net = self::amount($cells[$c - 3]);
        $gross = self::amount($cells[$c - 2]);
        if ($net === null || $gross === null) {
            throw self::refuse("row {$at}: its " . ($net === null ? 'Net Price' : 'Gross Price') . ' is not an amount in pounds (GBP)');
        }
        $vat = $cells[$c - 1];
        if (preg_match('/^[A-Z]$/D', $vat) !== 1) {
            throw self::refuse("row {$at}: its VAT Code is not a code");
        }
        if (preg_match('/GBP\s*-?\s*[0-9][0-9,]*\.[0-9]{2}/i', $cells[3]) === 1) {
            // an amount where the description is (the page's text puts a line's first half there: the Codex check r2, MEDIUM 5)
            throw self::refuse("row {$at}: an amount where the description is");
        }
        $middle = array_slice($cells, 4, max(0, $c - 7));
        foreach ($middle as $cell) {
            // an amount where the reference is: refused (two such cells could move cost between rows unseen: the Codex check r1, MEDIUM 12)
            if (preg_match('/GBP\s*-?\s*[0-9][0-9,]*\.[0-9]{2}/i', $cell) === 1) {
                throw self::refuse("row {$at}: an amount where the reference is (column " . (5 + (int) array_search($cell, $middle, true)) . ')');
            }
        }
        return ['row_no' => $n, 'service_date' => sprintf('%04d-%02d-%02d', (int) $y, (int) $mo, (int) $d), 'product_code' => $product, 'qty' => $qty, 'net' => $net,
            'gross' => $gross, 'vat_code' => $vat] + self::reference(implode(' ', $middle));
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
            $rate = $summary[$r['vat_code']]['rate'] ?? self::VAT_RATES[$r['vat_code']] ?? null;
            if ($rate !== null && bccomp($rate, '0', 2) === 0 && (int) $r['gross'] !== (int) $r['net']) {
                // a zero-rated line carries no VAT, each on its own (two that cancel are refused too: the Codex check r2, LOW 18)
                throw self::refuse("row {$r['row_no']}: a zero-rated line with VAT");
            }
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
            // half a penny a row and a penny; a zero rate: no VAT at all (the Codex check r1, LOW 18)
            $slack = bccomp($rate, '0', 2) === 0 ? '0' : bcadd(bcmul((string) $count[$code], '50', 0), '100', 0);
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
