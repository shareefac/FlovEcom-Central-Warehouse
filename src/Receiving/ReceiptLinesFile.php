<?php

declare(strict_types=1);

namespace CW\Receiving;

use CW\CwException;
use CW\Output\XlsxReader;
use CW\PurchaseOrders\PoLinesFile;
use CW\PurchaseOrders\PoMath;

/**
 * The supplier's invoice or packing-list spreadsheet as receipt lines (IM6 "import the supplier's invoice or packing-list
 * spreadsheet"; docs/decisions.md I140): CSV or XLSX (its first sheet), at most 2 MiB and 2,000 rows.
 *
 * A supplier's sheet is not CW's: its header may sit below a letterhead and its columns have the supplier's names. So:
 *  - the HEADER is the first of the first 30 rows that names a code column (a supplier code, a barcode or a CW code) and a
 *    quantity column; header names are compared as lower-case letters and digits only ("Unit Price (£)" = unitprice) against
 *    ALIASES; other columns (VAT, line totals, ...) are ignored; a field named twice is an error.
 *  - a row that names NO item (no code of any kind) is skipped and listed: totals, carriage, notes. Every row that names an item
 *    must be right, or nothing is imported (all or nothing, as the PO lines file): the receipt never holds half a sheet.
 *  - the item: a CW code, else this supplier's code (an active supplier item), else a usable barcode (an outer case counts its
 *    units per scan); the first that resolves wins, and two that name different items are an error. A supplier code CW does not
 *    know is no error when the barcode (or CW code) of the row names the item: it is kept on the line.
 *  - quantity = packs (whole number); units per pack from the supplier item (a sheet's pack size that differs is an error:
 *    "pack differs"), else the sheet's pack size, else the outer case's units per scan, else 1; a units column, when there is
 *    one, must equal packs x units per pack (the boxes-for-units guard); a price column is the price of one pack (GBP excl.
 *    VAT, "£" and thousands commas ignored).
 * Errors read "row N, column: message" with N the sheet's own row number; at most 50 are listed.
 */
final class ReceiptLinesFile
{
    public const MAX_BYTES = 2_097_152;
    public const MAX_ROWS = 2000;
    public const MAX_ERRORS = 50;
    public const MAX_SKIPPED = 50;
    public const HEADER_SEARCH_ROWS = 30;
    public const IDENTIFIERS = ['cw_code', 'supplier_code', 'barcode'];
    /** field => header names (letters and digits only, lower case). */
    public const ALIASES = [
        'cw_code' => ['cwcode', 'cw', 'cwitem'],
        'supplier_code' => ['suppliercode', 'code', 'itemcode', 'productcode', 'sku', 'stockcode', 'partno', 'partnumber', 'itemno', 'itemnumber', 'article',
            'articleno', 'articlenumber', 'ref', 'reference', 'productref', 'itemref', 'supplierref', 'productid', 'itemid'],
        'barcode' => ['barcode', 'ean', 'ean13', 'eancode', 'gtin', 'upc', 'barcodeean', 'eanbarcode'],
        'description' => ['description', 'itemname', 'product', 'productname', 'name', 'item', 'details', 'itemdescription', 'productdescription', 'note'],
        'packs' => ['packs', 'qty', 'quantity', 'qtypacks', 'qtyshipped', 'shipped', 'qtydelivered', 'delivered', 'quantityshipped', 'qtyinvoiced', 'invoicedqty',
            'quantityinvoiced', 'cases', 'boxes', 'outers', 'qtycases'],
        'units_per_pack' => ['unitsperpack', 'packsize', 'packqty', 'casesize', 'caseqty', 'unitspercase', 'inner', 'perpack', 'innerqty', 'unitsperbox', 'boxsize',
            'unitsperouter'],
        'units' => ['units', 'totalunits', 'qtyunits', 'unitsdelivered', 'unitsshipped'],
        'pack_price' => ['packprice', 'price', 'unitprice', 'netprice', 'cost', 'unitcost', 'priceeach', 'rate', 'netunitprice', 'priceperpack', 'caseprice',
            'pricepercase'],
    ];

    /**
     * Reads the file: its format and its rows (sheet row number => cells). 400 empty_file / bad_file, 413 too_large / too_many_rows.
     *
     * @return array{format: 'csv'|'xlsx', rows: array<int, list<string>>}
     */
    public static function read(string $path): array
    {
        clearstatcache(true, $path);
        if (!is_file($path) || !is_readable($path)) {
            throw new CwException('no_file', 'there is no readable file', 400);
        }
        $size = (int) filesize($path);
        if ($size === 0) {
            throw new CwException('empty_file', 'the file is empty', 400);
        }
        if ($size > self::MAX_BYTES) {
            throw new CwException('too_large', 'the file is larger than ' . intdiv(self::MAX_BYTES, 1_048_576) . ' MiB', 413);
        }
        if (XlsxReader::looksLikeXlsx($path)) {
            return ['format' => 'xlsx', 'rows' => XlsxReader::read($path, self::MAX_ROWS + self::HEADER_SEARCH_ROWS)];
        }
        $text = (string) file_get_contents($path);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        if (str_contains($text, "\0")) {
            throw new CwException('bad_file', 'this is not a text (CSV) file or an XLSX workbook', 400);
        }
        $first = '';
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                $first = $line;
                break;
            }
        }
        $delimiter = ',';
        $best = substr_count($first, ',');
        foreach ([';', "\t"] as $d) {
            if (substr_count($first, $d) > $best) {
                $best = substr_count($first, $d);
                $delimiter = $d;
            }
        }
        $h = fopen('php://temp', 'w+b');
        if ($h === false) {
            throw new \RuntimeException('cannot open a temporary stream');
        }
        fwrite($h, $text);
        rewind($h);
        $rows = [];
        $n = 0;
        while (($rec = fgetcsv($h, null, $delimiter, '"', '')) !== false) {
            $n++;
            $cells = array_map(static fn (mixed $c): string => (string) $c, $rec);
            while ($cells !== [] && trim((string) end($cells)) === '') {
                array_pop($cells);
            }
            if ($cells === []) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS + self::HEADER_SEARCH_ROWS) {
                fclose($h);
                throw new CwException('too_many_rows', 'the file has more than ' . self::MAX_ROWS . ' rows', 413);
            }
            if (count($cells) > 100) {
                fclose($h);
                throw new CwException('bad_file', "row {$n} has more than 100 columns", 400);
            }
            $rows[$n] = $cells;
        }
        fclose($h);
        return ['format' => 'csv', 'rows' => $rows];
    }

    /** "Unit Price (£)" -> unitprice: the comparable form of a header cell. */
    public static function headerKey(string $h): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim(str_replace("\u{FEFF}", '', $h))));
    }

    /**
     * Checks every row and builds the lines (GoodsReceipts::saveDraft's shape, with `row`), or the errors; rows that name no
     * item are skipped and listed. $resolve(type, value) answers an identifier with {sku_id, sku_code, units_per_scan,
     * supplier_item: ?{id, units_per_pack, purchase_unit, supplier_code}} or an error message.
     *
     * @param array{format: string, rows: array<int, list<string>>} $file
     * @param \Closure(string, string): (array<string, mixed>|string) $resolve
     * @return array{lines: list<array<string, mixed>>, errors: list<string>, skipped: list<string>, header_row: ?int}
     */
    public static function parse(array $file, \Closure $resolve): array
    {
        $rows = $file['rows'];
        $headRow = null;
        $map = [];
        $errors = [];
        $searched = 0;
        foreach ($rows as $rowNo => $cells) {
            if (++$searched > self::HEADER_SEARCH_ROWS) {
                break;
            }
            $m = [];
            $dupes = [];
            foreach ($cells as $i => $c) {
                $key = self::headerKey($c);
                if ($key === '') {
                    continue;
                }
                foreach (self::ALIASES as $field => $names) {
                    if (in_array($key, $names, true)) {
                        if (isset($m[$field])) {
                            $dupes[$field] = [$m[$field] + 1, $i + 1];
                        } else {
                            $m[$field] = $i;
                        }
                    }
                }
            }
            if (isset($m['packs']) && array_intersect(self::IDENTIFIERS, array_keys($m)) !== []) {
                $headRow = (int) $rowNo;
                $map = $m;
                foreach ($dupes as $field => [$a, $b]) {
                    $errors[] = "row {$rowNo}, header: two columns ({$a} and {$b}) are both read as " . str_replace('_', ' ', $field) . ': rename one';
                }
                break;
            }
        }
        if ($headRow === null) {
            return ['lines' => [], 'errors' => ['no header row: the sheet needs a column of codes (code, item code, SKU, barcode, EAN or cw_code) and a quantity '
                . 'column (qty, quantity, packs) in one of its first ' . self::HEADER_SEARCH_ROWS . ' rows'], 'skipped' => [], 'header_row' => null];
        }
        if ($errors !== []) {
            return ['lines' => [], 'errors' => $errors, 'skipped' => [], 'header_row' => $headRow];
        }
        $lines = [];
        $skipped = [];
        $skippedCount = 0;
        $count = 0;
        foreach ($rows as $rowNo => $cells) {
            if ($rowNo <= $headRow) {
                continue;
            }
            if (++$count > self::MAX_ROWS) {
                $errors[] = 'the file has more than ' . self::MAX_ROWS . ' rows';
                break;
            }
            $get = static fn (string $f): string => isset($map[$f]) ? trim((string) ($cells[$map[$f]] ?? '')) : '';
            $ids = [];
            foreach (self::IDENTIFIERS as $f) {
                if ($get($f) !== '') {
                    $ids[$f] = $get($f);
                }
            }
            if ($ids === []) {
                if (trim(implode('', $cells)) !== '') {
                    $skippedCount++;
                    if (count($skipped) < self::MAX_SKIPPED) {
                        $what = $get('description') !== '' ? $get('description') : trim(implode(' ', array_filter(array_map('trim', $cells), static fn (string $c): bool => $c !== '')));
                        $skipped[] = "row {$rowNo}: " . mb_substr($what, 0, 80) . ' (no item code)';
                    }
                }
                continue;
            }
            $rowErrors = [];
            $line = self::row((int) $rowNo, $ids, $get, $resolve, $rowErrors);
            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);
            } elseif ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($skippedCount > count($skipped)) {
            $skipped[] = '... and ' . ($skippedCount - count($skipped)) . ' more';
        }
        if (count($errors) > self::MAX_ERRORS) {
            $more = count($errors) - self::MAX_ERRORS;
            $errors = array_slice($errors, 0, self::MAX_ERRORS);
            $errors[] = "... and {$more} more";
        }
        if ($errors === [] && $lines === []) {
            $errors[] = 'the file has no lines that name an item';
        }
        return ['lines' => $errors === [] ? $lines : [], 'errors' => $errors, 'skipped' => $skipped, 'header_row' => $headRow];
    }

    /**
     * One row that names an item: its line, or null with $errors filled.
     *
     * @param array<string, string> $ids
     * @param \Closure(string): string $get
     * @param \Closure(string, string): (array<string, mixed>|string) $resolve
     * @param list<string> $errors
     * @return array<string, mixed>|null
     */
    private static function row(int $n, array $ids, \Closure $get, \Closure $resolve, array &$errors): ?array
    {
        $err = static function (string $col, string $msg) use ($n, &$errors): void {
            $errors[] = "row {$n}, {$col}: {$msg}";
        };
        $resolved = [];
        $failed = [];
        foreach ($ids as $f => $v) {
            $x = $resolve($f, $v);
            if (is_string($x)) {
                $failed[$f] = $x;
            } else {
                $resolved[$f] = $x;
            }
        }
        if ($resolved === []) {
            foreach ($failed as $f => $why) {
                $err(str_replace('_', ' ', $f), $why);
            }
            return null;
        }
        $first = null;
        foreach ($resolved as $f => $x) {
            if ($first === null) {
                $first = [$f, $x];
                continue;
            }
            if ($x['sku_id'] !== $first[1]['sku_id']) {
                $err(str_replace('_', ' ', $f), "names another item ({$x['sku_code']}) than the " . str_replace('_', ' ', $first[0]) . " ({$first[1]['sku_code']})");
            }
        }
        foreach ($failed as $f => $why) {
            if ($f !== 'supplier_code') {
                $err(str_replace('_', ' ', $f), $why); // a supplier code CW does not know yet is kept on the line; a bad barcode or CW code is an error
            }
        }
        [, $item] = $first;
        $si = $item['supplier_item'];
        if (isset($resolved['supplier_code'])) {
            $si = $resolved['supplier_code']['supplier_item'];
            // A case barcode counts its own units: a supplier code of another pack on the same row is a contradiction, not a
            // choice (the boxes-for-units guard, I173).
            $case = (int) ($resolved['barcode']['units_per_scan'] ?? 1);
            if ($si !== null && $case > 1 && $case !== (int) $si['units_per_pack'] && (int) ($resolved['barcode']['sku_id'] ?? 0) === (int) $resolved['supplier_code']['sku_id']) {
                $err('barcode', "pack differs: the barcode is a case of {$case}, but the supplier code " . ($si['supplier_code'] ?? '') . " is a pack of {$si['units_per_pack']}");
            }
        }
        $packs = null;
        if ($get('packs') === '') {
            $err('quantity', 'required: a whole number of packs from 1 to 1,000,000');
        } else {
            $packs = PoLinesFile::wholeNumber($get('packs'));
            if ($packs === null || $packs < 1 || $packs > PoMath::MAX_PACKS) {
                $err('quantity', 'a whole number of packs from 1 to 1,000,000, not "' . mb_substr($get('packs'), 0, 20) . '"');
                $packs = null;
            }
        }
        $upp = $si !== null ? (int) $si['units_per_pack'] : max(1, (int) ($item['units_per_scan'] ?? 1));
        if ($get('units_per_pack') !== '') {
            $given = PoLinesFile::wholeNumber($get('units_per_pack'));
            if ($given === null || $given < 1 || $given > PoMath::MAX_UNITS_PER_PACK) {
                $err('pack size', 'a whole number from 1 to ' . number_format(PoMath::MAX_UNITS_PER_PACK));
            } elseif ($si !== null && $given !== $upp) {
                $err('pack size', "pack differs: CW has this item from this supplier in packs of {$upp}" . ($si['supplier_code'] !== null ? " (code {$si['supplier_code']})" : ''));
            } else {
                $upp = $given;
            }
        }
        if ($get('units') !== '' && $packs !== null) {
            $units = PoLinesFile::wholeNumber($get('units'));
            if ($units === null || $units !== $packs * $upp) {
                $err('units', $get('units') . " is not packs x units per pack ({$packs} x {$upp} = " . ($packs * $upp) . ')');
            }
        }
        $price = null;
        if ($get('pack_price') !== '') {
            $price = PoLinesFile::price($get('pack_price'));
            if ($price === null) {
                $err('price', 'an amount in GBP of at least 0 with at most 4 decimals');
            }
        }
        $desc = $get('description');
        if (mb_strlen($desc) > 255) {
            $desc = mb_substr($desc, 0, 255);
        }
        if ($errors !== []) {
            return null;
        }
        $code = $si !== null ? null : ($ids['supplier_code'] ?? null);
        if ($code !== null && preg_match('/^cw-?0*[0-9]{1,10}$/iD', $code) === 1) {
            $code = null; // a CW code in the code column is not the supplier's code
        }
        return ['sku_id' => (int) $item['sku_id'], 'supplier_item_id' => $si === null ? null : (int) $si['id'],
            'supplier_code' => $code === null ? null : mb_substr($code, 0, 64), 'units_per_pack' => $upp, 'packs' => $packs, 'pack_price' => $price,
            'description' => $desc === '' ? null : $desc, 'row' => $n];
    }
}
