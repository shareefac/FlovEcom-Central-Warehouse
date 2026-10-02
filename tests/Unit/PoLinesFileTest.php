<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Output\XlsxWriter;
use CW\PurchaseOrders\PoLinesFile;
use PHPUnit\Framework\TestCase;

/**
 * The PO lines file (spec §6.6; I48-I59): every import rule with its "row N, column: message", the export columns, and the
 * reading of CSV and XLSX by their content, within the 2 MiB / 2,000-row limits. The item lookups are a stub resolver here
 * (PurchaseOrders::importLines gives the real one; PurchaseOrderScreensTest runs it end to end).
 */
final class PoLinesFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw_polines_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private static function resolver(): \Closure
    {
        $siA = ['id' => 11, 'units_per_pack' => 24, 'purchase_unit' => 'box', 'supplier_code' => 'A-24', 'last_pack_price' => '30.0000'];
        $siB = ['id' => 13, 'units_per_pack' => 6, 'purchase_unit' => 'case', 'supplier_code' => 'B-6', 'last_pack_price' => null];
        $known = [
            'cw_code:CW-000001' => ['sku_id' => 1, 'sku_code' => 'CW-000001', 'supplier_item' => $siA],
            'supplier_code:A-24' => ['sku_id' => 1, 'sku_code' => 'CW-000001', 'supplier_item' => $siA],
            'barcode:5000000000001' => ['sku_id' => 1, 'sku_code' => 'CW-000001', 'supplier_item' => $siA],
            'cw_code:CW-000002' => ['sku_id' => 2, 'sku_code' => 'CW-000002', 'supplier_item' => null],
            'supplier_code:B-6' => ['sku_id' => 3, 'sku_code' => 'CW-000003', 'supplier_item' => $siB],
        ];
        return static fn (string $type, string $v): array|string => $known["{$type}:{$v}"] ?? "there is no item {$v}";
    }

    /** @param list<array<string, string>> $rows @return array{lines: list<array<string, mixed>>, errors: list<string>} */
    private static function parse(array $rows, ?array $header = null): array
    {
        $header ??= ['cw_code', 'supplier_code', 'barcode', 'purchase_unit', 'units_per_pack', 'packs', 'units', 'pack_price', 'vat_code', 'note', 'item_name'];
        $numbered = [];
        foreach ($rows as $i => $r) {
            $numbered[$i + 1] = $r;
        }
        return PoLinesFile::parse($header, $numbered, self::resolver(), ['S' => true, 'R' => true, 'Z' => true, 'X' => false]);
    }

    public function testGoodRowsBecomeLines(): void
    {
        $r = self::parse([
            ['cw_code' => 'CW-000001', 'packs' => '2'],
            ['supplier_code' => 'A-24', 'packs' => '24.0', 'units' => '576', 'pack_price' => '£1,234.5', 'vat_code' => 'r', 'note' => 'urgent'],
            ['barcode' => '5000000000001', 'cw_code' => 'CW-000001', 'packs' => ' 3 ', 'units_per_pack' => '24', 'purchase_unit' => 'BOX'],
            ['cw_code' => 'CW-000002', 'packs' => '5', 'units_per_pack' => '12', 'purchase_unit' => 'tray', 'supplier_code' => '', 'pack_price' => '0'],
            ['purchase_unit' => 'charge', 'note' => 'Delivery', 'pack_price' => '7.50', 'vat_code' => 'S'],
        ]);
        self::assertSame([], $r['errors']);
        self::assertSame([
            ['kind' => 'item', 'sku_id' => 1, 'supplier_item_id' => 11, 'supplier_code' => null, 'purchase_unit' => null, 'units_per_pack' => 24, 'packs' => 2,
                'pack_price' => '30.0000', 'vat_code' => null, 'description' => null, 'row' => 1],
            ['kind' => 'item', 'sku_id' => 1, 'supplier_item_id' => 11, 'supplier_code' => null, 'purchase_unit' => null, 'units_per_pack' => 24, 'packs' => 24,
                'pack_price' => '1234.5000', 'vat_code' => 'R', 'description' => 'urgent', 'row' => 2],
            ['kind' => 'item', 'sku_id' => 1, 'supplier_item_id' => 11, 'supplier_code' => null, 'purchase_unit' => null, 'units_per_pack' => 24, 'packs' => 3,
                'pack_price' => '30.0000', 'vat_code' => null, 'description' => null, 'row' => 3],
            ['kind' => 'item', 'sku_id' => 2, 'supplier_item_id' => null, 'supplier_code' => null, 'purchase_unit' => 'tray', 'units_per_pack' => 12, 'packs' => 5,
                'pack_price' => '0.0000', 'vat_code' => null, 'description' => null, 'row' => 4],
            ['kind' => 'charge', 'pack_price' => '7.5000', 'vat_code' => 'S', 'description' => 'Delivery', 'row' => 5],
        ], $r['lines']);
    }

    public function testEveryRuleNamesItsRowAndColumn(): void
    {
        $r = self::parse([
            ['cw_code' => 'CW-000001'],                                                         // 1 packs missing
            ['cw_code' => 'CW-000001', 'packs' => '0'],                                         // 2
            ['cw_code' => 'CW-000001', 'packs' => '1.5'],                                       // 3
            ['cw_code' => 'CW-000001', 'packs' => '1000001'],                                   // 4
            ['cw_code' => 'CW-000001', 'packs' => '2', 'units' => '2'],                         // 5 boxes typed as units
            ['cw_code' => 'CW-000001', 'packs' => '2', 'units_per_pack' => '12'],               // 6
            ['cw_code' => 'CW-000001', 'packs' => '2', 'purchase_unit' => 'case'],              // 7
            ['cw_code' => 'CW-000001', 'packs' => '2', 'pack_price' => '1.23456'],              // 8
            ['cw_code' => 'CW-000001', 'packs' => '2', 'vat_code' => 'Q'],                      // 9
            ['cw_code' => 'CW-000001', 'packs' => '2', 'vat_code' => 'x'],                      // 10
            ['cw_code' => 'CW-000001', 'packs' => '2', 'note' => str_repeat('n', 256)],         // 11
            ['packs' => '2'],                                                                   // 12 no identifier
            ['cw_code' => 'CW-999999', 'packs' => '2'],                                         // 13 unknown
            ['cw_code' => 'CW-000001', 'supplier_code' => 'B-6', 'packs' => '2'],               // 14 disagree
            ['purchase_unit' => 'charge', 'pack_price' => '7.505', 'note' => 'Delivery'],       // 15
            ['purchase_unit' => 'charge', 'pack_price' => '5'],                                 // 16 no description
            ['cw_code' => 'CW-000002', 'packs' => '2', 'units_per_pack' => '0'],                // 17
        ]);
        self::assertSame([
            'row 1, packs: required: a whole number of packs from 1 to 1,000,000',
            'row 2, packs: a whole number of packs from 1 to 1,000,000',
            'row 3, packs: a whole number of packs from 1 to 1,000,000',
            'row 4, packs: a whole number of packs from 1 to 1,000,000',
            'row 5, units: 2 is not packs × units per pack (2 × 24 = 48)',
            'row 6, units_per_pack: pack differs: supplier item says 24',
            'row 7, purchase_unit: differs: supplier item says box',
            'row 8, pack_price: an amount in GBP of at least 0 with at most 4 decimals',
            'row 9, vat_code: there is no VAT code Q',
            'row 10, vat_code: the VAT code X is no longer in use',
            'row 11, note: at most 255 characters',
            'row 12, cw_code: give cw_code, supplier_code or barcode',
            'row 13, cw_code: there is no item CW-999999',
            'row 14, supplier_code: names a different item than cw_code (CW-000001, supplier code A-24)',
            'row 15, pack_price: a charge is an amount of more than £0 in whole pence',
            'row 16, note: a charge says what it is (delivery, ...)',
            'row 17, units_per_pack: a whole number from 1 to 100,000',
        ], $r['errors']);
        self::assertSame([], $r['lines'], 'all or nothing');
    }

    public function testTheHeaderAndTheErrorCap(): void
    {
        self::assertSame(['header, packs: the column is missing (a lines file has packs and one of cw_code, supplier_code, barcode)'],
            self::parse([['cw_code' => 'CW-000001']], ['cw_code', 'qty'])['errors']);
        self::assertSame(['header, cw_code: the file needs one of the columns cw_code, supplier_code, barcode'], self::parse([['packs' => '1']], ['packs', 'name'])['errors']);
        $many = array_fill(0, 60, ['cw_code' => 'nope', 'packs' => '1']);
        $r = self::parse($many);
        self::assertCount(51, $r['errors']);
        self::assertSame('... and 10 more', $r['errors'][50]);
        self::assertSame(['the file has no lines'], self::parse([])['errors']);
        self::assertSame('supplier_code', PoLinesFile::headerName(" Supplier Code\u{FEFF}"));
    }

    public function testReadingCsvAndXlsxByTheirContent(): void
    {
        $csv = "{$this->dir}/lines.txt";
        file_put_contents($csv, "\xEF\xBB\xBFCW Code;Packs;Pack Price\r\nCW-000001;2;\"1,5\"\r\n\r\nCW-000002;3;2\r\n");
        $f = PoLinesFile::read($csv);
        self::assertSame(['csv', ['cw_code', 'packs', 'pack_price']], [$f['format'], $f['header']]);
        self::assertSame([1 => ['cw_code' => 'CW-000001', 'packs' => '2', 'pack_price' => '1,5'], 3 => ['cw_code' => 'CW-000002', 'packs' => '3', 'pack_price' => '2']],
            $f['rows'], 'a blank line keeps the numbering');

        $x = new XlsxWriter([['CW code', 'text'], ['Packs', 'number'], ['Note', 'text']]);
        $x->add(['CW-000001', 24, '=HYPERLINK("http://evil")'])->add(['CW-000002', 1, null]);
        $xlsx = "{$this->dir}/lines.bin";
        file_put_contents($xlsx, $x->output());
        $f = PoLinesFile::read($xlsx);
        self::assertSame(['xlsx', ['cw_code', 'packs', 'note']], [$f['format'], $f['header']]);
        self::assertSame([1 => ['cw_code' => 'CW-000001', 'packs' => '24', 'note' => '=HYPERLINK("http://evil")'], 2 => ['cw_code' => 'CW-000002', 'packs' => '1', 'note' => '']],
            $f['rows']);

        $export = [['line' => 1, 'cw_code' => 'CW-000001', 'supplier_code' => '=cmd', 'barcode' => '5000000000001', 'item_name' => 'Pod', 'purchase_unit' => 'box',
            'units_per_pack' => 24, 'packs' => 2, 'units' => 48, 'pack_price' => '30.0000', 'vat_code' => 'S', 'line_total' => '60.00', 'note' => null]];
        $out = PoLinesFile::csv($export);
        self::assertStringStartsWith("\xEF\xBB\xBF\"line\",\"cw_code\",\"supplier_code\",\"barcode\",\"item_name\",\"purchase_unit\",\"units_per_pack\",\"packs\",\"units\","
            . "\"pack_price\",\"vat_code\",\"line_total\",\"note\"\r\n", $out);
        self::assertStringContainsString('1,"CW-000001","\'=cmd","5000000000001","Pod","box",24,2,48,30.0000,"S",60.00,""', $out);
        file_put_contents("{$this->dir}/export.xlsx", PoLinesFile::xlsx($export, 'PO-000001'));
        $back = PoLinesFile::read("{$this->dir}/export.xlsx");
        self::assertSame(PoLinesFile::COLUMNS, $back['header']);
        self::assertSame(['=cmd', '5000000000001', '30', '60'], [$back['rows'][1]['supplier_code'], $back['rows'][1]['barcode'], $back['rows'][1]['pack_price'],
            $back['rows'][1]['line_total']], 'text stays text; numbers come back in their shortest form');

        // The limits.
        $big = "{$this->dir}/big.csv";
        file_put_contents($big, "cw_code,packs\n" . str_repeat("CW-000001,1\n", 200_000));
        self::assertSame([413, 'too_large'], self::refused(static fn () => PoLinesFile::read($big)));
        $rows = "{$this->dir}/rows.csv";
        file_put_contents($rows, "cw_code,packs\n" . str_repeat("CW-000001,1\n", 2001));
        self::assertSame([413, 'too_many_rows'], self::refused(static fn () => PoLinesFile::read($rows)));
        file_put_contents("{$this->dir}/empty.csv", '');
        self::assertSame([400, 'empty_file'], self::refused(fn () => PoLinesFile::read("{$this->dir}/empty.csv")));
    }

    /** @return array{0: int, 1: string} */
    private static function refused(callable $fn): array
    {
        try {
            $fn();
        } catch (CwException $e) {
            return [$e->httpStatus, $e->errorCode];
        }
        self::fail('nothing was refused');
    }
}
