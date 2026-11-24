<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\RoyalMailBillPage;
use CW\Tests\Support\RmBill;
use PHPUnit\Framework\TestCase;

/**
 * Royal Mail's weekly bill read from its saved page or its copied text (docs/decisions.md DL52-DL55), SYNTHETIC bills in the page's real
 * shape (tests/Support/RmBill): every row kept with its known reference only, never a hidden input, a script, a description or free text;
 * refused whole when the rows do not add up to the printed net / gross, the VAT does not follow from the codes, a product is unknown, a
 * cell is not what its column says.
 */
final class RoyalMailBillPageTest extends TestCase
{
    /** @return list<array<string, mixed>> a week of every kind of line */
    private static function week(): array
    {
        return [
            RmBill::line('2026-11-05', 'SD1', 5, 5308, 'CLICK AND DROP M2281', '5155465848'),
            RmBill::line('2026-11-05', 'SD1', 2, 2007, 'CLICK AND DROP M2281', '5155465848'),
            RmBill::line('2026-11-09', 'TPL', 431, 109612, '943'),
            RmBill::line('2026-11-09', 'TPL', 269, 68412, '7312'),
            RmBill::line('2026-11-09', 'TPM', 311, 105081, '943'),
            RmBill::line('2026-11-02', 'TRN', 1, 250, '4239'),
            RmBill::line('2026-11-06', 'RX1', 3, 925, 'ADJ5155465848RCN Surcharge'),
            RmBill::line('2026-11-06', 'RXA', 3, 375, 'ADJ5155465848RCN Surcharge'),
            RmBill::line('2026-11-08', 'RW4', 31, 3255, 'Rectification Week 32'),
            RmBill::line('2026-11-08', 'RW4', 1, 105, 'IPROL Week 32'),
            RmBill::line('2026-11-04', 'TSS', 4, 1078, ''),
            RmBill::line('2026-09-17', 'TPM', 1, 316, 'Mrs Test Person 1 Made Up Street'),
            RmBill::line('2026-11-10', 'TPL', 1, -254, '5672'),
        ];
    }

    public function testTheSavedPageIsReadRowByRowKeepingOnlyKnownReferences(): void
    {
        $lines = self::week();
        $b = RoyalMailBillPage::parse(RmBill::page($lines, ['invoice' => '9077300608']), RmBill::PRODUCTS);
        $t = RmBill::totals($lines);
        self::assertSame(['royal_mail', '0706703000', '9077300608', '2026-11-16', '2026-09-17', '2026-11-10', $t['net'], $t['vat'], $t['gross']],
            [$b['carrier'], $b['account'], $b['invoice_no'], $b['invoice_date'], $b['period_from'], $b['period_to'], $b['net'], $b['vat'], $b['gross']]);
        self::assertCount(13, $b['rows']);
        self::assertSame(['row_no' => 1, 'service_date' => '2026-11-05', 'product_code' => 'SD1', 'qty' => 5, 'net' => 5308, 'gross' => 6370, 'vat_code' => 'T',
            'ref_kind' => 'manifest', 'ref_no' => '2281', 'sales_order' => '5155465848'], $b['rows'][0]);
        $refs = array_map(static fn (array $r): array => [$r['product_code'], $r['ref_kind'], $r['ref_no'], $r['sales_order']], $b['rows']);
        self::assertSame([['SD1', 'manifest', '2281', '5155465848'], ['SD1', 'manifest', '2281', '5155465848'], ['TPL', 'preadvice', '943', null],
            ['TPL', 'preadvice', '7312', null], ['TPM', 'preadvice', '943', null], ['TRN', 'preadvice', '4239', null], ['RX1', 'sales_order', '5155465848', null],
            ['RXA', 'sales_order', '5155465848', null], ['RW4', 'week', '32', null], ['RW4', 'week', '32', null], ['TSS', 'none', null, null],
            ['TPM', 'none', null, null], ['TPL', 'preadvice', '5672', null]], $refs);
        self::assertSame(-254, $b['rows'][12]['net'], 'a credit line is kept as it is');
        $kept = json_encode($b, JSON_THROW_ON_ERROR);
        foreach (['SESSION-', 'Made Up Street', 'Test Person', 'DESCRIPTION', '1111111111', '9.99', 'sapSession'] as $never) {
            self::assertStringNotContainsString($never, $kept, 'never a hidden input, a script, a description or free text');
        }
    }

    public function testThePastedTextReadsTheSame(): void
    {
        $lines = self::week();
        $page = RoyalMailBillPage::parse(RmBill::page($lines), RmBill::PRODUCTS);
        $text = RoyalMailBillPage::parse(RmBill::text($lines), RmBill::PRODUCTS);
        self::assertSame($page, $text, 'the fingerprint too: it is of what was read, not of the bytes');
        self::assertSame($page, RoyalMailBillPage::parse(RmBill::page($lines, ['session' => 'saved again']), RmBill::PRODUCTS), 'a page saved again (a new session token)');
        self::assertNotSame($page['sha256'], RoyalMailBillPage::parse(RmBill::page([...$lines, RmBill::line('2026-11-09', 'TPL', 1, 254, '1')]), RmBill::PRODUCTS)['sha256']);
    }

    /**
     * The page's text as a browser's page reader gives it (the coordinator's real bills of 2, 9 and 16 Nov 2026 are in this shape; DL73): each
     * line over two lines, cells joined by spaces, an empty reference ("/ GBP 7.63 ..."), the surcharge note, the summary: read the same.
     */
    public function testThePagesReaderTextReadsTheSame(): void
    {
        // (the page's text does not show the sales order button of a Special Delivery line: the lines here have none, so both read the same)
        $lines = array_map(static fn (array $l): array => ['sales' => null] + $l, [...self::week(), RmBill::line('2026-07-17', 'TPM', 1, 316, ''),
            RmBill::line('2026-10-31', 'TRN', 1, 250, '4239')]);
        $page = RoyalMailBillPage::parse(RmBill::page($lines), RmBill::PRODUCTS);
        self::assertSame($page['rows'], RoyalMailBillPage::parse(RmBill::spaced($lines), RmBill::PRODUCTS)['rows']);
        self::assertSame($page, RoyalMailBillPage::parse(RmBill::spaced($lines), RmBill::PRODUCTS));
        // a line cut short (its amounts lost) is refused, never read as something else
        $cut = str_replace("/ 943 GBP 1,096.12 GBP 1,315.34 T\n", '', RmBill::spaced($lines));
        try {
            RoyalMailBillPage::parse($cut, RmBill::PRODUCTS);
            self::fail('a line with no amounts');
        } catch (CwException $e) {
            self::assertSame('bad_file', $e->errorCode);
        }
    }

    public function testThousandsAndZeroRatedLinesAndNoSummary(): void
    {
        $lines = [RmBill::line('2026-11-09', 'TPL', 703, 178_524, '943'), RmBill::line('2026-11-09', 'TSS', 2, 539, '', null, 'Z', 539)];
        $b = RoyalMailBillPage::parse(RmBill::page($lines), RmBill::PRODUCTS);
        self::assertSame([178_524 + 539, 35_705], [$b['net'], $b['vat']], 'VAT 20% on the T lines only; Z at 0');
        // Royal Mail rounds each row's VAT: the bill's VAT is its rows' gross less net, a penny off 20% of the net rounded once (as the real bills
        // of 9 Nov and 26 Oct 2026): read.
        $rows = [RmBill::line('2026-11-09', 'TPL', 1, 1001, '1', null, 'T', 1201), RmBill::line('2026-11-09', 'TPL', 1, 1001, '2', null, 'T', 1201),
            RmBill::line('2026-11-09', 'TPL', 1, 1001, '3', null, 'T', 1201)];
        self::assertSame([3003, 600], array_values(array_intersect_key(RoyalMailBillPage::parse(RmBill::page($rows), RmBill::PRODUCTS), ['net' => 1, 'vat' => 1])),
            '3 x 200p of VAT, not 601p (20% of 3003p rounded once)');
        $b2 = RoyalMailBillPage::parse(RmBill::page($lines, ['no_summary' => true]), RmBill::PRODUCTS);
        self::assertSame($b['rows'], $b2['rows'], 'no summary row: CW\'s rate for T and Z');
    }

    /** @return iterable<string, array{0: \Closure(): string, 1: string}> */
    public static function refusals(): iterable
    {
        $w = self::week();
        $t = RmBill::totals($w);
        yield 'net a penny off' => [static fn (): string => RmBill::page($w, ['net' => $t['net'] + 1, 'vat' => $t['vat'], 'gross' => $t['gross'] + 1]),
            'add up to £'];
        yield 'gross a penny off' => [static fn (): string => RmBill::page([RmBill::line('2026-11-09', 'TPL', 1, 254, '1', null, 'T', 306)],
            ['net' => 254, 'vat' => 51, 'gross' => 305]), 'gross add up'];
        yield 'VAT not gross - net' => [static fn (): string => RmBill::page($w, ['vat' => $t['vat'] + 1, 'gross' => $t['gross']]), 'VAT is not its total'];
        // the coordinator, 24 Nov 2026 (D1): the code and its line named, nothing stored, and the bill sent to the CW team
        yield 'unknown product' => [static fn (): string => RmBill::page([RmBill::line('2026-11-09', 'TPL', 1, 254, '1'), RmBill::line('2026-11-09', 'XY9', 1, 100, '1')]),
            'Royal Mail bill not read: line 2 of the bill (9 Nov 2026) has product XY9, which CW does not know. Send this bill to the CW team: the product must be '
            . 'added to CW before this bill can be read. Nothing was saved.'];
        // the Codex check r2: a 3-letter word may be a name (LOW 16: named only with a digit); an amount on the description line of the page's
        // text (MEDIUM 5); a zero-rated row with VAT offset by another (LOW 18); the top block naming two invoice numbers (r1 MEDIUM 13 PARTLY)
        yield 'a 3-letter product' => [static fn (): string => RmBill::page([RmBill::line('2026-11-09', 'ADA', 1, 100, '1')]),
            'line 1 of the bill (9 Nov 2026) has a product CW does not know. Send this bill to the CW team'];
        yield 'an amount on the description line' => [static fn (): string => str_replace('A DESCRIPTION OF THE PRODUCT', 'A DESCRIPTION GBP 5.00',
            RmBill::spaced([RmBill::line('2026-11-09', 'TPL', 1, 254, '943')])), 'row 1: an amount where the description is'];
        yield 'zero-rated rows with VAT that cancels' => [static fn (): string => RmBill::page([RmBill::line('2026-11-09', 'TSS', 1, 1000, '', null, 'Z', 1001),
            RmBill::line('2026-11-09', 'TSS', 1, 1000, '', null, 'Z', 999)]), 'row 1: a zero-rated line with VAT'];
        yield 'two invoice numbers in the top block' => [static fn (): string => str_replace('<body>', '<body><style>.x { display: none }</style><p class="x">Invoice: 9070009999</p>',
            RmBill::page($w)), 'the bill shows two different invoice numbers'];
        yield 'unknown product, a name shape' => [static fn (): string => RmBill::page([RmBill::line('2026-11-09', 'SMITHJ', 1, 100, '1')]),
            'line 1 of the bill (9 Nov 2026) has a product CW does not know. Send this bill to the CW team: the product must be added to CW before this bill can be read.'];
        yield 'quantity not whole' => [static fn (): string => str_replace('>5.000<', '>5.500<', RmBill::page($w)), 'row 1: its Quantity is not a whole number'];
        yield 'not pounds' => [static fn (): string => str_replace('Total Price Net:</td><td>GBP', 'Total Price Net:</td><td>EUR', RmBill::page($w)), 'net total'];
        yield 'no line table' => [static fn (): string => str_replace('Product Number', 'Product', RmBill::page($w)), 'cannot find the table'];
        yield 'a row of money that is no line' => [static fn (): string => str_replace('<tr><td></td><td>Total</td>', '<tr><td>x</td><td>Somewhere</td>', RmBill::page($w)),
            'holds an amount but is not a line'];
        yield 'unknown VAT code with no summary' => [static fn (): string => RmBill::page([RmBill::line('2026-11-09', 'TPL', 1, 254, '1', null, 'X', 254)],
            ['no_summary' => true]), 'VAT code X has no summary row'];
        yield 'a rate the lines do not follow' => [static fn (): string => str_replace('>Std Rated 20%<', '>Std Rated 17.5%<', RmBill::page($w)),
            'does not follow from the lines at its rate (17.5%)'];
        yield 'a summary that is not its lines\'' => [static fn (): string => str_replace('<td>T</td><td>Std Rated 20%</td><td>GBP ', '<td>T</td><td>Std Rated 20%</td><td>GBP 1',
            RmBill::page($w)), 'summary of code T does not follow'];
        yield 'a zero-rated line printed T' => [static fn (): string => RmBill::page([RmBill::line('2026-11-09', 'TSS', 100, 25400, '', null, 'T', 25400)]),
            'does not follow from the lines at its rate (20%)'];
        yield 'no date' => [static fn (): string => str_replace('<td>Date:</td>', '<td>Dated</td>', RmBill::page($w)), 'does not show its date'];
        yield 'a bad day' => [static fn (): string => str_replace('<td>05:11:2026</td>', '<td>31:02:2026</td>', RmBill::page($w)), 'is not a day'];
        yield 'empty' => [static fn (): string => '', 'empty'];
        // the Codex check r1: MEDIUM 12 (an amount among the reference cells), LOW 17 (an impossible bill date), LOW 18 (VAT on a zero-rated row),
        // LOW 16 (a product cell that may be a name is never said)
        yield 'an amount among the reference cells' => [static fn (): string => str_replace('<td>/ CLICK AND DROP M2281</td>', '<td>GBP 5.00</td><td>/ CLICK AND DROP M2281</td>',
            RmBill::page([RmBill::line('2026-11-05', 'SD1', 1, 1004, 'CLICK AND DROP M2281', '5155465848')])), 'row 1: an amount where the reference is'];
        yield 'an impossible bill date' => [static fn (): string => RmBill::page($w, ['date' => 'Feb 31, 2026']), 'the bill\'s date is not a day'];
        yield 'VAT on a zero-rated row' => [static fn (): string => RmBill::page([RmBill::line('2026-11-09', 'TSS', 1, 1000, '', null, 'Z', 1001)]),
            'row 1: a zero-rated line with VAT'];
        yield 'a product that may be a name' => [static fn (): string => RmBill::page([RmBill::line('2026-11-09', 'JEAN', 1, 100, '1')]), 'its product is one CW does not know'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testTheWholeBillIsRefused(\Closure $bytes, string $why): void
    {
        try {
            RoyalMailBillPage::parse($bytes(), RmBill::PRODUCTS);
            self::fail('refused: ' . $why);
        } catch (CwException $e) {
            self::assertSame(['bad_file', 422], [$e->errorCode, $e->httpStatus]);
            self::assertStringContainsString($why, $e->getMessage());
            self::assertStringContainsString('Nothing was saved', $e->getMessage());
            self::assertStringNotContainsString('SMITHJ', $e->getMessage(), 'a cell that may be a name is never said');
            self::assertStringNotContainsString('JEAN', $e->getMessage(), 'a cell that may be a name is never said');
            self::assertStringNotContainsString('ADA', $e->getMessage(), 'a cell that may be a name is never said');
        }
    }

    /** The Codex check r2, LOW 7: a credit written "-GBP 2.54" reads in the page's text as in the other forms. */
    public function testACreditReadsTheSameInEveryForm(): void
    {
        $lines = [RmBill::line('2026-11-09', 'TPL', 2, 508, '943'), RmBill::line('2026-11-10', 'TPL', 1, -254, '943')];
        $spaced = str_replace('GBP -2.54 GBP -3.05', '-GBP 2.54 -GBP 3.05', RmBill::spaced($lines));
        self::assertStringContainsString('-GBP 2.54', $spaced);
        self::assertSame(RoyalMailBillPage::parse(RmBill::page($lines), RmBill::PRODUCTS)['rows'], RoyalMailBillPage::parse($spaced, RmBill::PRODUCTS)['rows']);
    }

    /** The Codex check r1, MEDIUM 13: a button the page hides (the hidden attribute, display none) is never read, in the top block or a row. */
    public function testAHiddenButtonIsNeverRead(): void
    {
        $lines = self::week();
        $page = str_replace('<body>', '<body><form><input type="submit" hidden value="Invoice: 1234567"><button style="display: none">Invoice: 7654321</button></form>',
            RmBill::page($lines, ['invoice' => '9077300608']));
        $b = RoyalMailBillPage::parse($page, RmBill::PRODUCTS);
        self::assertSame('9077300608', $b['invoice_no']);
    }

    public function testTheReferenceFormsAndNothingElse(): void
    {
        self::assertSame(['ref_kind' => 'manifest', 'ref_no' => '2281', 'sales_order' => '5155465848'], RoyalMailBillPage::reference('5155465848 / CLICK AND DROP M2281'));
        self::assertSame(['ref_kind' => 'manifest', 'ref_no' => '2281', 'sales_order' => null], RoyalMailBillPage::reference('/ CLICK AND DROP M2281'));
        self::assertSame(['ref_kind' => 'sales_order', 'ref_no' => '5155369482', 'sales_order' => null], RoyalMailBillPage::reference('ADJ5155369482RCN Surcharge'));
        self::assertSame(['ref_kind' => 'preadvice', 'ref_no' => '943', 'sales_order' => '5155514809'], RoyalMailBillPage::reference('5155514809 / 943'));
        self::assertSame(['ref_kind' => 'week', 'ref_no' => '32', 'sales_order' => null], RoyalMailBillPage::reference('Rectification Week 32'));
        self::assertSame(['ref_kind' => 'none', 'ref_no' => null, 'sales_order' => null], RoyalMailBillPage::reference('Mr Test 07700 900123'));
        self::assertSame(['ref_kind' => 'none', 'ref_no' => null, 'sales_order' => null], RoyalMailBillPage::reference('ADJ12RCN'));
        self::assertSame([-254, 1885974, null, null], [RoyalMailBillPage::amount('GBP -2.54'), RoyalMailBillPage::amount('GBP 18,859.74'),
            RoyalMailBillPage::amount('EUR 1.00'), RoyalMailBillPage::amount('GBP 1.5')]);
    }
}
