<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\DpdInvoiceFile;
use CW\Tests\Support\DpdCsv;
use PHPUnit\Framework\TestCase;

/**
 * DPD's weekly invoice CSV read (docs/decisions.md DL3-DL6) from SYNTHETIC files in DPD's real layout (tests/Support/DpdCsv: made-up
 * names and addresses; the real files are never read by a test): the top rows, the columns by name, the money columns Revenue to Cover
 * without Surcharge, a consignment of two parcels, credits, the # and * marks, VAT S and Z, the whole file refused when it does not tie
 * or a column or a number is wrong, and no personal text in what comes back.
 */
final class DpdInvoiceFileTest extends TestCase
{
    /** @return list<array<string, mixed>> a week of seven lines with every shape the real bills have */
    private static function week(): array
    {
        return [
            DpdCsv::line('05/11/2026', '6599195040/0', 420),
            DpdCsv::line('05/11/2026', '6599195041/0#', 897, ['Service Description' => 'SAT', 'Service Code' => '7']),
            // a consignment of two parcels: the second row has no money, its Header is the first parcel
            DpdCsv::line('06/11/2026', '6599195042/0', 1395, ['Items' => '2']),
            DpdCsv::line('06/11/2026', '6599195042/0', 0, ['money' => [], 'blank_money' => true, 'Parcel No' => '15976599195049', 'Weight' => '', 'Items' => '',
                'Surcharge' => '']),
            // a credit
            DpdCsv::line('07/11/2026', '6599195043/0*', -230, ['money' => ['Revenue' => -210, 'Fuel and Energy Charge' => -20]]),
            // a collection from the customer (a return): Third Party Collection, the order number in Senders Ref
            DpdCsv::line('08/11/2026', '6599195044/0#*', 1550, ['money' => ['Third Party Collection' => 1300, 'Fuel and Energy Charge' => 137, 'Carriage Charge' => 13,
                'Global Energy Charge' => 100], 'Senders Ref' => '1356070', 'Service Description' => 'TWO DAY']),
            // zero rated, a Senders Ref that is text (never kept)
            DpdCsv::line('09/11/2026', '6599195045', 1169, ['VAT Code' => 'Z', 'Senders Ref' => 'man return Test Person']),
        ];
    }

    public function testAWeekIsReadByNameWithEveryShapeOfLine(): void
    {
        $bytes = DpdCsv::file(self::week(), ['invoice' => '62368793']);
        $bill = DpdInvoiceFile::parse($bytes, 2000);
        self::assertSame(['dpd', '3012002', '62368793', null], [$bill['carrier'], $bill['account'], $bill['invoice_no'], $bill['invoice_date']]);
        $net = 420 + 897 + 1395 + 0 - 230 + 1550 + 1169;
        self::assertSame($net, $bill['net']);
        self::assertSame(intdiv(($net - 1169) * 2 + 5, 10), $bill['vat'], '20% of the S lines only');
        self::assertSame($bill['net'] + $bill['vat'], $bill['gross']);
        self::assertSame(['2026-11-05', '2026-11-09'], [$bill['period_from'], $bill['period_to']]);
        self::assertSame(hash('sha256', $bytes), $bill['sha256']);
        self::assertCount(7, $bill['lines']);
        $l = $bill['lines'];
        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_column($l, 'line_no'));
        self::assertSame(['6599195040', '6599195041', '6599195042', '6599195042', '6599195043', '6599195044', '6599195045'], array_column($l, 'consignment'));
        self::assertSame(['', '#', '', '', '*', '#*', ''], array_column($l, 'marks'), 'the marks kept as flags, taken off the number');
        self::assertSame([420, 897, 1395, 0, -230, 1550, 1169], array_column($l, 'cost'));
        self::assertSame(['Revenue' => 380, 'Fuel and Energy Charge' => 18, 'Carriage Charge' => 13, 'Global Energy Charge' => 9], $l[0]['money'],
            'only the columns that are not 0, in pence; Surcharge (a letter code) is never money');
        self::assertSame([], $l[3]['money'], 'the second parcel of a consignment: kept, cost 0');
        self::assertSame('15976599195049', $l[3]['parcel_no']);
        self::assertSame('15976599195042', $l[3]['header_parcel']);
        self::assertSame([false, false, false, false, false, true, false], array_column($l, 'collection'));
        self::assertSame(['1356063', '1356063', '1356063', '1356063', '1356063', '1356070', null], array_column($l, 'senders_ref'), 'digits only, else not kept');
        self::assertSame(['S', 'S', 'S', 'S', 'S', 'S', 'Z'], array_column($l, 'vat_code'));
        self::assertSame(['EXPRESSPAK 1', 'NEXT DAY', '4', '12', '1.0', 1], [$l[0]['product'], $l[0]['service'], $l[0]['product_code'], $l[0]['service_code'],
            $l[0]['weight'], $l[0]['items']]);
        self::assertSame([null, null], [$l[3]['weight'], $l[3]['items']]);
        self::assertSame('2026-11-07', $l[4]['line_date']);
        // Nothing personal comes back, whatever DPD put in the address, name and free-text columns.
        $all = json_encode($bill, JSON_THROW_ON_ERROR);
        foreach ([...array_values(DpdCsv::PERSONAL), 'Test Person', 'man return', 'UNITED KINGDOM', 'GB', 'ASU'] as $text) {
            self::assertStringNotContainsString($text, $all, "{$text} is never kept");
        }
        $keys = array_keys($l[0]);
        sort($keys);
        self::assertSame(['collection', 'consignment', 'cost', 'header_parcel', 'items', 'line_date', 'line_no', 'marks', 'money', 'parcel_no', 'product', 'product_code',
            'senders_ref', 'service', 'service_code', 'vat_code', 'weight'], $keys, 'only the fields the matching needs');
    }

    public function testAByteOrderMarkLineEndsAndTheColumnsOrderChangeNothing(): void
    {
        $base = DpdInvoiceFile::parse(DpdCsv::file(self::week()), 2000);
        $lf = DpdInvoiceFile::parse(DpdCsv::file(self::week(), ['bom' => true, 'eol' => "\n"]), 2000);
        $cols = DpdCsv::COLUMNS;
        shuffle($cols);
        // Revenue .. Cover stay one block (DPD's money columns); everything else in another order.
        $other = array_values(array_diff($cols, DpdCsv::MONEY));
        $moved = DpdInvoiceFile::parse(DpdCsv::file(self::week(), ['columns' => [...array_slice($other, 0, 10), ...DpdCsv::MONEY, ...array_slice($other, 10)]]), 2000);
        foreach ([$lf, $moved] as $b) {
            unset($b['sha256']);
            $want = $base;
            unset($want['sha256']);
            self::assertSame($want, $b);
        }
        self::assertNotSame($base['sha256'], $lf['sha256'], 'the fingerprint is of the bytes as uploaded');
    }

    public function testTheInvoiceDateIsReadWhenTheTopSaysIt(): void
    {
        $lines = [DpdCsv::line('05/11/2026', '6599195040/0', 420)];
        $top = [['Account No', '3012002', 'Invoice No', '62368793', 'Invoice Date', '16/11/2026'], ['Nett Invoice Value', '', '4.20', 'GBP'], ['VAT', '', '0.84', 'GBP'],
            ['Gross Invoice Value', '', '5.04', 'GBP']];
        self::assertSame('2026-11-16', DpdInvoiceFile::parse(DpdCsv::file($lines, ['top' => $top]), 2000)['invoice_date']);
    }

    /** @return array<string, array{0: \Closure(): string, 1: string}> */
    public static function refusals(): array
    {
        $week = self::week(...);
        $without = static fn (string $col): \Closure => static fn (): string => DpdCsv::file($week(), ['columns' => array_values(array_diff(DpdCsv::COLUMNS, [$col]))]);
        return [
            'lines short of the net' => [static fn (): string => DpdCsv::file($week(), ['net' => 5200, 'vat' => 1040, 'gross' => 6240]), 'the lines add up to £52.01'],
            'the VAT' => [static fn (): string => DpdCsv::file($week(), ['vat' => 800]), 'the VAT does not tie'],
            'the gross' => [static fn (): string => DpdCsv::file($week(), ['gross' => 1]), 'is not net'],
            'no Date' => [$without('Date'), '"Date" is missing'],
            'no Consignment' => [$without('Consignment'), 'no row names its columns'],
            'no Senders Ref' => [$without('Senders Ref'), '"Senders Ref" is missing'],
            'no VAT Code' => [$without('VAT Code'), '"VAT Code" is missing'],
            'no Cover' => [$without('Cover'), '"Cover" is missing'],
            // The Codex check r1 (MEDIUM 1, 2; HIGH 1): every column the matching needs; a name twice said by its place; a printed date that is
            // no day; an amount past what any line can be.
            'no Third Party Collection' => [$without('Third Party Collection'), '"Third Party Collection" is missing'],
            'no Fuel and Energy Charge' => [$without('Fuel and Energy Charge'), '"Fuel and Energy Charge" is missing'],
            'a name twice' => [static fn (): string => DpdCsv::file($week(), ['columns' => array_map(static fn (string $c): string => $c === 'Second Ref' ? 'Test Person' : $c,
                [...DpdCsv::COLUMNS, 'Test Person'])]), 'columns 39 and 43 have the same name'],
            'an invoice date that is no day' => [static fn (): string => DpdCsv::file([DpdCsv::line('05/11/2026', '6599195040/0', 420)], ['top' => [
                ['Account No', '3012002', 'Invoice No', '62368793', 'Invoice Date', '31/02/2026'], ['Nett Invoice Value', '', '4.20', 'GBP'], ['VAT', '', '0.84', 'GBP'],
                ['Gross Invoice Value', '', '5.04', 'GBP']]]), 'the invoice date at the top of the file is not a day'],
            'an amount past any line' => [static fn (): string => DpdCsv::file([DpdCsv::line('09/11/2026', '6599195046/0', 0, ['money' => []])], ['extra' => [implode(',',
                array_map(static fn (string $c): string => match ($c) {
                    'Date' => '09/11/2026', 'Consignment' => '6599195047/0', 'Revenue' => '"9,999,999,999,999,999,999.00"', 'VAT Code' => 'S', default => '',
                }, DpdCsv::COLUMNS))]]), '"Revenue" is not an amount'],
            'no Revenue' => [$without('Revenue'), 'no row names its columns'],
            'a money cell not a number' => [static fn (): string => DpdCsv::file([...$week(), DpdCsv::line('09/11/2026', '6599195046/0', 0,
                ['money' => ['Revenue' => 0], 'blank_money' => false])], ['extra' => [implode(',', array_map(static fn (string $c): string => match ($c) {
                    'Date' => '09/11/2026', 'Consignment' => '6599195047/0', 'Revenue' => '1 Test Street', 'VAT Code' => 'S', default => '',
                }, DpdCsv::COLUMNS))]]), '"Revenue" is not an amount in pounds and pence (it reads "9 aaaa aaaaaa")'],
            'three decimals' => [static fn (): string => DpdCsv::file([DpdCsv::line('09/11/2026', '6599195046/0', 0, ['money' => []])], ['extra' => [implode(',',
                array_map(static fn (string $c): string => match ($c) {
                    'Date' => '09/11/2026', 'Consignment' => '6599195047/0', 'Revenue' => '3.495', 'VAT Code' => 'S', default => '',
                }, DpdCsv::COLUMNS))]]), '"Revenue" is not an amount'],
            'a date that is no day' => [static fn (): string => DpdCsv::file([DpdCsv::line('31/02/2026', '6599195046/0', 420)]), 'the Date is not a day'],
            'a VAT code not known' => [static fn (): string => DpdCsv::file([DpdCsv::line('09/11/2026', '6599195046/0', 420, ['VAT Code' => 'E'])]), 'is not S or Z'],
            'more cells than names' => [static fn (): string => DpdCsv::file($week(), ['extra' => [implode(',', array_fill(0, count(DpdCsv::COLUMNS) + 1, '1'))]]),
                'has more cells than the file has column names'],
            'no net at the top' => [static fn (): string => DpdCsv::file($week(), ['top' => [['Account No', '3012002', 'Invoice No', '62368793'], ['VAT', '', '1.00', 'GBP'],
                ['Gross Invoice Value', '', '1.00', 'GBP']]]), 'does not say its net total'],
            'an account that is no number' => [static fn (): string => DpdCsv::file($week(), ['account' => 'Test Person']), 'the account at the top of the file is not a number'],
            'no lines' => [static fn (): string => DpdCsv::file([]), 'the file has no lines'],
            'empty' => [static fn (): string => '', 'the file is empty'],
            'UTF-16' => [static fn (): string => "\xFF\xFE" . mb_convert_encoding(DpdCsv::file($week()), 'UTF-16LE', 'UTF-8'), 'UTF-16'],
            'not a DPD file' => [static fn (): string => "a,b,c\r\n1,2,3\r\n", 'not a DPD invoice file'],
        ];
    }

    /** @dataProvider refusals */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testTheWholeFileIsRefusedWhenItDoesNotTieOrAColumnOrANumberIsWrong(\Closure $file, string $says): void
    {
        try {
            DpdInvoiceFile::parse($file(), 2000);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('bad_file', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            self::assertStringContainsString($says, $e->getMessage());
            self::assertStringEndsWith('Nothing was saved.', $e->getMessage());
            self::assertStringNotContainsString('Test Street', $e->getMessage(), 'a refusal never shows what a cell holds');
            self::assertStringNotContainsString('Test Person', $e->getMessage());
        }
    }

    /** The Codex check r1, HIGH 1: a name or an address in DPD's product or service columns is never kept; only DPD's own names are. */
    public function testOnlyDpdsOwnProductAndServiceNamesAreKept(): void
    {
        $bill = DpdInvoiceFile::parse(DpdCsv::file([
            DpdCsv::line('05/11/2026', '6599195040/0', 420, ['Product Description' => 'Test Person', 'Service Description' => '1 Test Street', 'Product Code' => 'AB12',
                'Service Code' => 'TT1']),
            DpdCsv::line('05/11/2026', '6599195041/0', 420, ['Product Description' => 'Parcel', 'Service Description' => ' ship2shop ']),
        ]), 2000);
        self::assertSame(['', '', '', ''], [$bill['lines'][0]['product'], $bill['lines'][0]['service'], $bill['lines'][0]['product_code'], $bill['lines'][0]['service_code']]);
        self::assertSame(['PARCEL', 'SHIP2SHOP'], [$bill['lines'][1]['product'], $bill['lines'][1]['service']]);
        self::assertStringNotContainsString('Test', json_encode($bill, JSON_THROW_ON_ERROR));
    }

    /**
     * The Codex check r1, MEDIUM 1: the money columns by their names wherever they are (a charge moved after the addresses still counts);
     * a column CW does not know is never read (neither money nor kept); the March sample's Peak Charge is money.
     */
    public function testTheMoneyColumnsAreReadByTheirNamesWhereverTheyAre(): void
    {
        $lines = [DpdCsv::line('05/11/2026', '6599195040/0', 420), DpdCsv::line('05/11/2026', '6599195041/0', 0, ['money' => ['Peak Charge' => 99, 'Revenue' => 250],
            'Delivery Notes' => 'Leave with Test Person, 9.99'])];
        $cols = [...array_values(array_diff(DpdCsv::COLUMNS, ['Fuel and Energy Charge', 'Global Energy Charge'])), 'Fuel and Energy Charge', 'Delivery Notes', 'Peak Charge'];
        $bill = DpdInvoiceFile::parse(DpdCsv::file($lines, ['columns' => $cols, 'net' => 420 - 9 + 349, 'vat' => intdiv((420 - 9 + 349) * 2 + 5, 10)]), 2000);
        self::assertSame([420 - 9, 349], array_column($bill['lines'], 'cost'), 'Global Energy (9p) is not on this file; the rest counted wherever it is');
        self::assertSame(['Revenue' => 380, 'Carriage Charge' => 13, 'Fuel and Energy Charge' => 18], $bill['lines'][0]['money']);
        self::assertSame(['Revenue' => 250, 'Peak Charge' => 99], $bill['lines'][1]['money']);
        self::assertStringNotContainsString('Test Person', json_encode($bill, JSON_THROW_ON_ERROR));
    }

    public function testSurchargeIsNeverMoneyEvenWhenItHoldsANumber(): void
    {
        $l = DpdCsv::line('05/11/2026', '6599195040/0', 420, ['Surcharge' => '5.00']);
        self::assertSame(420, DpdInvoiceFile::parse(DpdCsv::file([$l]), 2000)['lines'][0]['cost']);
    }

    public function testTheSmallReaders(): void
    {
        self::assertSame([349, -210, 0, 0, 123456, 50, 1200, 99999999999], array_map(DpdInvoiceFile::pence(...), ['3.49', '-2.10', '0', '', '1,234.56', '0.5', '12',
            '999,999,999.99']));
        foreach (['3.495', '£3.49', '3,49', '1.2.3', 'abc', '- 2.10', '1,23', '1,000,000,000.00', '1000000000', '9,999,999,999,999,999,999'] as $bad) {
            self::assertNull(DpdInvoiceFile::pence($bad), $bad);
        }
        self::assertSame([['6951225115', ''], ['6951225115', '#'], ['6951225115', '#*'], ['6951225115', '*'], ['6951225115', ''], [null, ''], [null, '#']],
            array_map(DpdInvoiceFile::consignment(...), ['6951225115/0', '6951225115/0#', '6951225115/0#*', '6951225115/0*', '6951225115', 'RTC Test Person', '#']));
        self::assertSame(['2026-11-05', '2026-02-28', '2026-11-05', null, null, null], array_map(DpdInvoiceFile::day(...),
            ['05/11/2026', '28/02/2026', '2026-11-05', '29/02/2026', '5/11/26', '']));
        self::assertSame(['£8,790.28', '-£0.32', '£0.00'], [DpdInvoiceFile::gbp(879028), DpdInvoiceFile::gbp(-32), DpdInvoiceFile::gbp(0)]);
    }

    public function testAFileFarTooLargeOrWithAZeroByteIsRefused(): void
    {
        try {
            DpdInvoiceFile::parse("a\0b", 2000);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertStringContainsString('not a CSV file', $e->getMessage());
        }
        try {
            DpdInvoiceFile::parse(str_repeat('x', DpdInvoiceFile::MAX_BYTES + 1), 2000);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame([413, 'too_large'], [$e->httpStatus, $e->errorCode]);
        }
    }
}
