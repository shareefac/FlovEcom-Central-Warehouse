<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Receiving;

use CW\Output\XlsxWriter;

/**
 * The supplier's invoice or packing-list spreadsheet (IM6; I140): the header found below a letterhead, the supplier's own column
 * names, codes / barcodes / CW codes, rows without a code skipped and listed, all or nothing for the rest (the boxes-for-units
 * guard, a pack that differs, an unknown item), CSV and XLSX, append and replace.
 */
final class SupplierSheetImportTest extends ReceivingTestCase
{
    public function testACsvInvoiceWithALetterheadAndTheSuppliersColumnNames(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Sheet Supplies');
        $liquid = $this->itemWithBarcode('Sheet liquid', '5012345678900');
        $pods = $this->itemWithBarcode('Sheet pods', '4006381333931', '4006381333948', 5);
        $coil = self::makeSku('Sheet coil');
        $siLiquid = $this->supplierItem($buyer, (int) $s['id'], $liquid, 10, '20.0000', ['supplier_code' => 'LQ-10']);
        $d = $this->receipt($desk, (int) $s['id'], []);
        $csv = "Sheet Supplies Ltd,,,,,,,\r\nInvoice INV-7781,,,,,,,\r\n,,,,,,,\r\n"
            . "Item Code,Description,EAN,Qty,Pack Size,Units,Unit Price (£),Line Total\r\n"
            . "LQ-10,Sheet liquid 10 x 10ml,,3,10,30,20.00,60.00\r\n"
            // a code CW does not know yet: the barcode of the box (5 pods per scan) names the item; the code is kept on the line
            . "PD-NEW,Sheet pods box of 5,4006381333948,2,,10,\"£1,234.50\",2469.00\r\n"
            . 'CW-' . sprintf('%06d', $coil) . ",Sheet coil,,4,,,1.25,5.00\r\n"
            . ",Carriage,,1,,,7.50,7.50\r\n,,,,,,Total,2541.50\r\n";
        $r = $this->grns->importLines($desk, $d->id, $d->version, $this->file($csv, 'inv.csv'), 'inv.csv', 'append');
        self::assertSame([], $r['errors']);
        self::assertSame(3, $r['lines']);
        self::assertSame(['row 8: Carriage (no item code)', 'row 9: Total 2541.50 (no item code)'], $r['skipped']);
        $lines = $this->grns->lines($d->id);
        self::assertSame([
            [$liquid, (int) $siLiquid['id'], 'LQ-10', 10, 3, '20.0000', 'file'],
            [$pods, null, 'PD-NEW', 5, 2, '1234.5000', 'file'],
            [$coil, null, null, 1, 4, '1.2500', 'file'],
        ], array_map(static fn (array $l): array => [$l['sku_id'], $l['supplier_item_id'], $l['supplier_code'], $l['units_per_pack'], $l['packs'], $l['pack_price'],
            $l['entry']], $lines));
        self::assertSame([30, 10, 4], array_map('intval', self::$db->column('SELECT qty FROM document_line WHERE document_id = ? ORDER BY line_no', [$d->id])));
        // The same sheet again, appended: the packs add up on the same lines.
        $v = $this->docs->get($d->id)->version;
        $this->grns->importLines($desk, $d->id, $v, $this->file($csv, 'inv.csv'), 'inv.csv', 'append');
        self::assertSame([6, 4, 8], array_column($this->grns->lines($d->id), 'packs'));
        self::assertContains('grn.import_lines', $this->audits($d->id));
    }

    public function testAnyProblemImportsNothing(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Strict Sheet Ltd');
        $liquid = self::makeSku('Strict liquid');
        $this->supplierItem($buyer, (int) $s['id'], $liquid, 10, '20.0000', ['supplier_code' => 'SL-10']);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $liquid, 'packs' => 1, 'units_per_pack' => 10]]);
        $csv = "code;qty;pack size;units;price\r\n"
            . "SL-10;3;;3;20\r\n"           // boxes typed as units: 3 is not 3 x 10
            . "SL-10;2;12;;\r\n"            // a pack size the supplier item does not have
            . "NOPE-1;1;;;\r\n"             // nothing names this item
            . "SL-10;two;;;\r\n"            // not a quantity
            . "SL-10;1;;;12.5.0\r\n";       // not a price
        $r = $this->grns->importLines($desk, $d->id, $this->docs->get($d->id)->version, $this->file($csv, 'bad.csv'), 'bad.csv', 'replace');
        self::assertSame([
            'row 2, units: 3 is not packs x units per pack (3 x 10 = 30)',
            'row 3, pack size: pack differs: CW has this item from this supplier in packs of 10 (code SL-10)',
            'row 4, supplier code: this supplier has no active item with the code NOPE-1',
            'row 5, quantity: a whole number of packs from 1 to 1,000,000, not "two"',
            'row 6, price: an amount in GBP of at least 0 with at most 4 decimals',
        ], $r['errors']);
        self::assertSame([1], array_column($this->grns->lines($d->id), 'packs'), 'nothing imported, nothing replaced');
        $none = $this->grns->importLines($desk, $d->id, $this->docs->get($d->id)->version, $this->file("Description,Total\r\nA,1\r\n", 'none.csv'), 'none.csv', 'append');
        self::assertStringStartsWith('no header row: the sheet needs a column of codes', $none['errors'][0]);
        $dup = $this->grns->importLines($desk, $d->id, $this->docs->get($d->id)->version, $this->file("code,qty,quantity\r\nSL-10,1,1\r\n", 'dup.csv'), 'dup.csv', 'append');
        self::assertSame(['row 1, header: two columns (2 and 3) are both read as packs: rename one'], $dup['errors']);
    }

    public function testAnXlsxPackingListReplacesTheLines(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Xlsx Sheet Ltd');
        $liquid = self::makeSku('Xlsx liquid');
        $si = $this->supplierItem($buyer, (int) $s['id'], $liquid, 6, '9.0000', ['supplier_code' => 'XL-6']);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => self::makeSku('Old line'), 'packs' => 1]]);
        $w = new XlsxWriter([['Packing list', 'text'], ['', 'text'], ['', 'text']], 'Sheet1');
        $w->add(['Product Code', 'Quantity', 'Price']);
        $w->add(['XL-6', '5', '9']);
        $w->add(['', '', '=SUM(C3)']);
        $path = $this->file($w->output(), 'packing.xlsx');
        $r = $this->grns->importLines($desk, $d->id, $this->docs->get($d->id)->version, $path, 'packing.xlsx', 'replace');
        self::assertSame([], $r['errors']);
        self::assertSame(['row 4: =SUM(C3) (no item code)'], $r['skipped'], 'a formula-looking cell is text, never run; a row without a code is skipped');
        self::assertSame([[$liquid, (int) $si['id'], 5, '9.0000']], array_map(static fn (array $l): array => [$l['sku_id'], $l['supplier_item_id'], $l['packs'],
            $l['pack_price']], $this->grns->lines($d->id)));
    }
}
