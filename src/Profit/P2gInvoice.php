<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * A Parcel2Go invoice read from its text (P2gPdfText: `pdftotext -layout`), STRICTLY (docs/decisions.md PGL7; the lessons checklist 6: the
 * WHOLE invoice is refused when any line holding money is not understood; never a row dropped). Its layout is a real invoice's (the owner's
 * sample of 26 Nov 2026, TX-95792472, read by the lead; no customer detail of it is in CW's code or tests): ONE invoice per checkout
 * TRANSACTION (basket), its number "TX-" + the transaction id, a row per P2G label of the basket:
 *
 *   Invoice No.   TX-95792472
 *   Date          26/11/2026
 *   Details                                         Parcels    Net        VAT
 *   P2G150278180 - Evri Europe Standard Parcelshop   1         £11.32     £0.00
 *   <the recipient's name and address>                                              (no money on it: never read into anything)
 *   P2G150271419 - Extra Payment                     1         £2.93      £0.00     (a LATER charge on an EARLIER label: its own P2G number)
 *   Net £23.11  /  VAT £0.00  /  Invoice Total £23.11
 *
 * A row is a "surcharge" when its description is EXACTLY "Extra Payment" (the named label's, wherever that label was paid); any other "Extra ..."
 * text is a row CW does not know (the whole basket refused); else a "price" when it names a delivery (a courier or a service word): Parcel2Go::store
 * then accepts it only as the label's own service in the label's own basket (the final review, H3). The headings Details, Parcels, Net, VAT are
 * printed before the rows (again at the top of each page of a long basket, each page's rows after its own heading line). Each row's VAT is 0 (international: zero-rated), its net at 20% (whole pence, within a penny:
 * P2G may work VAT out on the price with VAT) or, part of its price free of VAT (as cover is), less: never more; the rows add up to the printed Net and VAT and Net + VAT = Invoice Total, to the penny. A
 * PrePay top-up's invoice is never a delivery cost: refused. Kept: the number, the transaction id, the date, the rows (P2G number, kind,
 * service, parcels, net, VAT rate, VAT, total) and the totals; never the text (names and addresses are on it).
 * B0 (production, 27 Nov 2026, both accounts): GET /invoices by OrderId serves this same invoice, its headings "Net Amount" and "VAT Amount"
 * on one line (HEADINGS). The switch (production, 27 Nov 2026, Vape Big): a total may share its line with the last row ("1Net", "£0.62£40.69")
 * or with text with no money (an address or cover line), and a row may carry less than 20% VAT. STILL WAITS FOR THE FIRST WEEKS: any other row
 * kinds (cover, SMS, a discount, a refund) the first weeks show: until they are known, such an invoice is REFUSED (said), never priced wrongly.
 */
final class P2gInvoice
{
    /** The refusals, by code (p2g_line.refuse_code; the reader's and Parcel2Go::store's): CW's own words. */
    public const REFUSALS = [
        'pdf_text' => 'the invoice\'s text could not be read',
        'no_number' => 'no invoice number TX-<digits> (or more than one)',
        'no_date' => 'no invoice date (or more than one)',
        'other_line' => 'it is not the invoice of this label\'s basket',
        'topup' => 'it is a PrePay top-up, not a delivery',
        'unknown_line' => 'a line with money on it that CW does not know',
        'unknown_row' => 'a row whose description names no delivery and is no Extra Payment',
        'row_shape' => 'a row whose amounts are not parcels, net and VAT',
        'row_twice' => 'a label priced twice on it',
        'vat_rate' => 'a row\'s VAT is more than 20% of its net (until 27 Nov 2026 also: a VAT below 20% and not 0)',
        'below_zero' => 'a row below 0 (a credit CW does not read yet)',
        'no_heading' => 'its table\'s headings are not Details, Parcels, Net (Amount), VAT (Amount) before its rows',
        'service_differs' => 'a price row\'s service is not the label\'s own service on Parcel2Go\'s list or export',
        'other_basket' => 'a price row\'s label was paid in another basket',
        'parcels_differ' => 'it prints fewer parcels for a label than CW holds for it',
        'other_account' => 'one of its labels is another Parcel2Go account\'s',
        'priced_elsewhere' => 'one of its labels is priced on another invoice',
        'no_rows' => 'no rows',
        'no_totals' => 'its totals (Net, VAT, Invoice Total) are not printed once each',
        'not_adding_up' => 'its rows do not add up to its printed totals',
    ];
    /** The product codes of its rows (carrier_bill_row.product_code). */
    public const KINDS = ['price' => 'SVC', 'surcharge' => 'SURCH'];
    /** The VAT rate a row with VAT is read at (basis points). */
    public const VAT_BP = 2000;

    private const MONEY = '-?£-?(?:[0-9]{1,3}(?:,[0-9]{3})+|[0-9]+)\.[0-9]{2}';
    private const ROW = '/^P2G([0-9]{6,12})\s+-\s+(.{2,120})$/D';
    /** The ONE surcharge row the real invoice shows (exactly this text: any other "Extra ..." is a row CW does not know). */
    private const EXTRA = 'Extra Payment';
    /** The table's headings, as a line of `pdftotext -layout`: the website's copy wraps "Amount" under Net and VAT (a line with no money: skipped);
     *  the API's copy (GET /invoices, B0 on production 27 Nov 2026) prints "Net Amount" and "VAT Amount" on the one line. Nothing else. */
    private const HEADINGS = [['Details', 'Parcels', 'Net', 'VAT'], ['Details', 'Parcels', 'Net Amount', 'VAT Amount']];
    private const DELIVERY = '/evri|hermes|royal\s+mail|parcelforce|\bdpd\b|\bups\b|\bdhl\b|fedex|yodel|inpost|parcel|delivery|collection|drop[\s-]*off|shop'
        . '|tracked|international|europe|express|economy|standard|next\s+day|\b24\b|\b48\b|letter|packet/i';
    /** Words that make text beside a total more than an address or a cover line: such a line is one CW does not know (refused). */
    private const NOT_BESIDE = '\\b(?:refund|credit|discount|rebate|return|cancel|adjust|voucher|promo|deduct|minus|less|saving|deposit|balance|paid|due|owed)';
    private const TOPUP = '/pre-?pay(?:ment)?\s+top[\s-]*up|top[\s-]*up\s+(?:of\s+)?(?:your\s+)?pre-?pay|prepayment\s+invoice/i';

    /**
     * The invoice read from its text. $mustPrice: a P2G number that must be a price row of it (the label it was asked for).
     *
     * @return array{invoice_no: string, transaction_id: int, invoice_date: string, rows: list<array{order_line_id: int, kind: string, code: string,
     *   service: ?string, parcels: int, net: int, rate_bp: int, vat: int, gross: int}>, net: int, vat: int, gross: int, sha256: string}
     */
    public static function read(string $text, ?int $mustPrice = null, ?int $transactionId = null): array
    {
        $no = static fn (string $code, ?int $line = null): CwException => new CwException('p2g_invoice_refused',
            'Parcel2Go\'s invoice was not read: ' . self::REFUSALS[$code] . ($line === null ? '' : " (line {$line})") . '. Nothing was stored.', 422,
            ['refuse' => $code] + ($line === null ? [] : ['line' => $line]));
        if ($text === '' || !mb_check_encoding($text, 'UTF-8') || strlen($text) > PdftotextText::MAX_TEXT) {
            throw $no('pdf_text');
        }
        if (preg_match(self::TOPUP, $text) === 1) {
            throw $no('topup');
        }
        $numbers = [];
        $dates = [];
        $rows = [];
        $totals = ['net' => [], 'vat' => [], 'total' => []];
        $headings = 0;
        // the lines with their page (pdftotext ends each page with a form feed, which \R also splits on: the same line numbers as before)
        $lines = [];
        foreach (explode("\f", $text) as $p => $page) {
            foreach (preg_split('/\R/u', $page) ?: [] as $raw) {
                $lines[] = [$raw, $p];
            }
        }
        $pageHeading = -1; // the page whose own heading line was read: a page's rows need it (a long basket prints it again on each page; final review 27 Nov)
        foreach ($lines as $i => [$raw, $page]) {
            $n = $i + 1;
            // a tab or a no-break space is a column gap; cells are what the layout put two or more spaces apart
            $line = trim((string) preg_replace('/[\x{00A0}\t]+/u', '  ', $raw));
            if ($line === '') {
                continue;
            }
            $cells = array_values(array_filter(array_map('trim', preg_split('/\s{2,}/u', $line) ?: []), static fn (string $c): bool => $c !== ''));
            foreach ($cells as $k => $c) {
                $next = $cells[$k + 1] ?? null;
                if ($next !== null && preg_match('/^invoice\s+(?:no\.?|number)$/i', $c) === 1 && preg_match('/^TX-([0-9]{1,12})$/D', $next, $m) === 1) {
                    $numbers[$next] = (int) $m[1];
                }
                if ($next !== null && preg_match('/^(?:invoice\s+)?date$/i', $c) === 1) {
                    $d = self::day($next);
                    if ($d !== null) {
                        $dates[$d] = true;
                    }
                }
            }
            if (in_array($cells, self::HEADINGS, true)) {
                $headings++;
                $pageHeading = $page;
                continue;
            }
            if (!str_contains($line, '£')) {
                continue; // no money: the seller, the bill-to address, a recipient's name and address (never read into anything)
            }
            // The last row and a total on ONE line (the switch on production, 27 Nov 2026: Vape Big): the row's parcels run into the total's
            // label ("1Net") and its VAT into the total's amount ("£0.62£40.69"). Read as the row and the total; a split read the wrong way
            // round cannot add up (its VAT above 20%, or the totals off): refused, never priced.
            if (count($cells) === 4 && preg_match(self::ROW, $cells[0]) === 1 && preg_match('/^([1-9][0-9]?) ?([A-Za-z][A-Za-z ]{1,19})$/D', $cells[1], $pm) === 1
                && ($key = self::totalKey($pm[2])) !== null && preg_match('/^(' . self::MONEY . ') ?(' . self::MONEY . ')$/Du', $cells[3], $vm) === 1) {
                $totals[$key][] = self::pence($vm[2]);
                $cells = [$cells[0], $pm[1], $cells[2], $vm[1]];
            }
            // A total: its label and its amount the line's last two cells. Beside the NET total only (27 Nov 2026, production: a recipient's
            // address line or a cover line printed under the last row, on the Net total's line), text that is never read, with no money, no
            // label's number and no word of a refund, credit or discount (the final review, astra: "Refund  Total  £6.00" is never a total).
            $last = count($cells) - 1;
            $left = implode('  ', array_slice($cells, 0, max(0, $last - 1)));
            if ($last >= 1 && preg_match('/^' . self::MONEY . '$/Du', $cells[$last]) === 1 && ($key = self::totalKey($cells[$last - 1])) !== null
                && preg_match(self::ROW, $cells[0]) !== 1 && ($left === '' || ($key === 'net' && !str_contains($left, '£')
                && preg_match('/P2G\s*[0-9]|' . self::NOT_BESIDE . '/i', $left) !== 1))) {
                $totals[$key][] = self::pence($cells[$last]);
                continue;
            }
            if (preg_match(self::ROW, $cells[0] ?? '', $m) !== 1) {
                throw $no('unknown_line', $n);
            }
            if (count($cells) !== 4 || preg_match('/^[1-9][0-9]?$/D', $cells[1]) !== 1 || preg_match('/^' . self::MONEY . '$/u', $cells[2]) !== 1
                || preg_match('/^' . self::MONEY . '$/u', $cells[3]) !== 1) {
                throw $no('row_shape', $n);
            }
            $desc = trim($m[2]);
            if ($pageHeading !== $page) {
                throw $no('no_heading', $n);
            }
            $kind = match (true) {
                $desc === self::EXTRA => 'surcharge',
                preg_match('/^extra\b/i', $desc) === 1 => throw $no('unknown_row', $n),
                preg_match('/top[\s-]*up|pre-?pay/i', $desc) === 1 => throw $no('topup', $n),
                preg_match(self::DELIVERY, $desc) === 1 => 'price',
                default => throw $no('unknown_row', $n),
            };
            $net = self::pence($cells[2]);
            $vat = self::pence($cells[3]);
            if ($net <= 0 || $vat < 0) {
                throw $no('below_zero', $n);
            }
            // VAT at most 20% of the net (a penny of rounding). Below it: part of the label's price free of VAT (as cover is; 27 Nov 2026,
            // production: £3.20 with £0.55 VAT), read as printed (code S); the totals must still add up.
            if ($vat > self::vatOf($net, self::VAT_BP) + 1) {
                throw $no('vat_rate', $n);
            }
            $rows[] = ['order_line_id' => (int) $m[1], 'kind' => $kind, 'code' => self::KINDS[$kind], 'service' => $kind === 'price' ? P2gApi::slug($desc, 64) : null,
                'parcels' => (int) $cells[1], 'net' => $net, 'rate_bp' => $vat === 0 ? 0 : self::VAT_BP, 'vat' => $vat, 'gross' => $net + $vat];
        }
        if (count($numbers) !== 1) {
            throw $no('no_number');
        }
        if (count($dates) !== 1) {
            throw $no('no_date');
        }
        if ($headings === 0) {
            throw $no('no_heading');
        }
        if ($rows === []) {
            throw $no('no_rows');
        }
        if (count($totals['net']) !== 1 || count($totals['vat']) !== 1 || count($totals['total']) !== 1) {
            throw $no('no_totals');
        }
        $priced = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'price') {
                if (isset($priced[$r['order_line_id']])) {
                    throw $no('row_twice');
                }
                $priced[$r['order_line_id']] = true;
            }
        }
        $tx = (int) reset($numbers);
        if (($mustPrice !== null && !isset($priced[$mustPrice])) || ($transactionId !== null && $transactionId !== $tx)) {
            throw $no('other_line');
        }
        $net = array_sum(array_column($rows, 'net'));
        $vat = array_sum(array_column($rows, 'vat'));
        if ($net !== $totals['net'][0] || $vat !== $totals['vat'][0] || $net + $vat !== $totals['total'][0]) {
            throw $no('not_adding_up');
        }
        $out = ['invoice_no' => (string) array_key_first($numbers), 'transaction_id' => $tx, 'invoice_date' => (string) array_key_first($dates), 'rows' => $rows,
            'net' => $net, 'vat' => $vat, 'gross' => $net + $vat];
        return $out + ['sha256' => hash('sha256', json_encode($out, JSON_THROW_ON_ERROR))];
    }

    /** "£1,234.56" / "-£1.50" / "£-1.50" in pence. */
    public static function pence(string $s): int
    {
        $neg = str_contains($s, '-');
        $t = str_replace(['£', ',', '-'], '', $s);
        if (preg_match('/^[0-9]+\.[0-9]{2}$/D', $t) !== 1) {
            throw new \InvalidArgumentException('not an amount');
        }
        [$p, $d] = explode('.', $t);
        $v = (int) $p * 100 + (int) $d;
        return $neg ? -$v : $v;
    }

    /** 'net', 'vat' or 'total': the total a label names (any case, its spaces any width), or null. */
    private static function totalKey(string $label): ?string
    {
        return match (strtolower(trim((string) preg_replace('/\s+/', ' ', $label)))) {
            'net', 'net total', 'sub total', 'subtotal' => 'net',
            'vat', 'vat total', 'total vat' => 'vat',
            'invoice total', 'total' => 'total',
            default => null,
        };
    }

    /** The VAT of $net at $rateBp, whole pence, half away from zero. */
    public static function vatOf(int $net, int $rateBp): int
    {
        $x = abs($net) * $rateBp;
        $v = intdiv($x, 10000) + (($x % 10000) * 2 >= 10000 ? 1 : 0);
        return $net < 0 ? -$v : $v;
    }

    /** A printed date (26/11/2026, as the invoice prints it) as Y-m-d, or null. */
    public static function day(string $s): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!d/m/Y', trim($s));
        return $d !== false && \DateTimeImmutable::getLastErrors() === false && $d->format('d/m/Y') === trim($s) ? $d->format('Y-m-d') : null;
    }
}
