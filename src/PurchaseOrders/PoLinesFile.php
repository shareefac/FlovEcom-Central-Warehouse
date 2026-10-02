<?php

declare(strict_types=1);

namespace CW\PurchaseOrders;

use CW\CwException;
use CW\Output\CsvReader;
use CW\Output\CsvWriter;
use CW\Output\XlsxReader;
use CW\Output\XlsxWriter;

/**
 * A PO's lines as a file (spec §6.6; docs/decisions.md I48-I59): exported as CSV or XLSX, imported from either, ALL OR
 * NOTHING (PurchaseOrders::importLines).
 *
 * Columns, in export order (an import matches header names without regard to case or spaces, in any order, and ignores
 * columns it does not know): line (ignored), cw_code, supplier_code, barcode (one of the three is required: the first
 * non-empty in that order names the item; two that name different items are an error), item_name (ignored),
 * purchase_unit (must be the supplier item's when there is one), units_per_pack (must be the supplier item's: "pack
 * differs: supplier item says 24"; without one, default 1), packs (REQUIRED: a whole number 1..1,000,000; "24.0" from a
 * spreadsheet is 24), units (when given, must be packs × units_per_pack: catches boxes typed as units), pack_price (≥ 0, at
 * most 4 decimals, "£" and thousands commas ignored; default the last price), vat_code (must exist and be in use; default
 * the supplier's), line_total (ignored), note (the line's description, at most 255 characters).
 *
 * A charge (delivery, ...) is a row with purchase_unit "charge", no item code, the amount in pack_price (whole pence,
 * more than 0) and what it is in note (or item_name): what the export writes for a charge line, so an exported file
 * imports again.
 *
 * Limits: at most 2,000 data rows and 2 MiB (the upload cap). The format is decided by the content: "PK\x03\x04" is an XLSX
 * (its first sheet, XlsxReader), anything else a CSV (CsvReader: BOM, Windows-1252, ; and TAB). Errors read "row N,
 * column: message" (N = the data row: spreadsheet row N + 1), at most 50 of them.
 */
final class PoLinesFile
{
    public const COLUMNS = ['line', 'cw_code', 'supplier_code', 'barcode', 'item_name', 'purchase_unit', 'units_per_pack', 'packs', 'units', 'pack_price', 'vat_code',
        'line_total', 'note'];
    public const TYPES = ['line' => 'number', 'cw_code' => 'text', 'supplier_code' => 'text', 'barcode' => 'text', 'item_name' => 'text', 'purchase_unit' => 'text',
        'units_per_pack' => 'number', 'packs' => 'number', 'units' => 'number', 'pack_price' => 'number', 'vat_code' => 'text', 'line_total' => 'number', 'note' => 'text'];
    public const IDENTIFIERS = ['cw_code', 'supplier_code', 'barcode'];
    public const MAX_ROWS = 2000;
    public const MAX_BYTES = 2_097_152;
    public const MAX_ERRORS = 50;

    /** @param list<array<string, mixed>> $rows PurchaseOrders::exportRows() */
    public static function csv(array $rows): string
    {
        $w = new CsvWriter(array_map(static fn (string $c): array => [$c, self::TYPES[$c]], self::COLUMNS));
        foreach ($rows as $r) {
            $w->add(array_map(static fn (string $c): mixed => $r[$c] ?? null, self::COLUMNS));
        }
        return $w->output();
    }

    /** @param list<array<string, mixed>> $rows PurchaseOrders::exportRows() */
    public static function xlsx(array $rows, string $sheet = 'Lines'): string
    {
        $w = new XlsxWriter(array_map(static fn (string $c): array => [$c, self::TYPES[$c]], self::COLUMNS), $sheet);
        foreach ($rows as $r) {
            $w->add(array_map(static fn (string $c): mixed => $r[$c] ?? null, self::COLUMNS));
        }
        return $w->output();
    }

    /**
     * Reads a lines file: its format, its header (normalised names) and its rows keyed by data-row number.
     * 413 too_large over 2 MiB; 413 too_many_rows over 2,000 rows; 400 bad_file for an unreadable file or header.
     *
     * @return array{format: 'csv'|'xlsx', header: list<string>, rows: array<int, array<string, string>>}
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
            $sheet = XlsxReader::read($path, self::MAX_ROWS + 1);
            if ($sheet === []) {
                throw new CwException('bad_file', 'the sheet is empty', 400);
            }
            $headRow = (int) array_key_first($sheet);
            $header = [];
            foreach ($sheet[$headRow] as $i => $h) {
                $header[$i] = self::headerName($h);
            }
            unset($sheet[$headRow]); // never array_shift(): it renumbers the spreadsheet's row numbers
            self::checkHeader($header);
            $rows = [];
            foreach ($sheet as $rowNo => $cells) {
                $r = [];
                foreach ($header as $i => $name) {
                    $r[$name] = (string) ($cells[$i] ?? '');
                }
                if (count($cells) > count($header) && trim(implode('', array_slice($cells, count($header)))) !== '') {
                    throw new CwException('bad_file', 'row ' . ($rowNo - $headRow) . ' has more cells than the header', 400);
                }
                $rows[$rowNo - $headRow] = $r;
            }
            return ['format' => 'xlsx', 'header' => array_values($header), 'rows' => $rows];
        }
        $t = CsvReader::table($path, self::MAX_BYTES, self::MAX_ROWS);
        return ['format' => 'csv', 'header' => $t['header'], 'rows' => iterator_to_array($t['rows'], true)];
    }

    /**
     * Checks every row and builds the lines (saveDraft's shape), or the errors. $resolve(type, value) answers an identifier
     * (cw_code, supplier_code, barcode) with {sku_id, sku_code, supplier_item: ?{id, units_per_pack, purchase_unit,
     * supplier_code, last_pack_price}} or an error message. $vatCodes: code => in use.
     *
     * @param list<string> $header
     * @param iterable<int, array<string, string>> $rows
     * @param \Closure(string, string): (array<string, mixed>|string) $resolve
     * @param array<string, bool> $vatCodes
     * @return array{lines: list<array<string, mixed>>, errors: list<string>}
     */
    public static function parse(array $header, iterable $rows, \Closure $resolve, array $vatCodes): array
    {
        $errors = [];
        $lines = [];
        if (!in_array('packs', $header, true)) {
            $errors[] = 'header, packs: the column is missing (a lines file has packs and one of cw_code, supplier_code, barcode)';
        }
        if (array_intersect(self::IDENTIFIERS, $header) === []) {
            $errors[] = 'header, cw_code: the file needs one of the columns cw_code, supplier_code, barcode';
        }
        if ($errors !== []) {
            return ['lines' => [], 'errors' => $errors];
        }
        $count = 0;
        foreach ($rows as $n => $r) {
            if (++$count > self::MAX_ROWS) {
                $errors[] = 'the file has more than ' . self::MAX_ROWS . ' rows';
                break;
            }
            $rowErrors = [];
            $line = self::row((int) $n, $r, $resolve, $vatCodes, $rowErrors);
            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);
            } elseif ($line !== null) {
                $lines[] = $line;
            }
        }
        if (count($errors) > self::MAX_ERRORS) {
            $more = count($errors) - self::MAX_ERRORS;
            $errors = array_slice($errors, 0, self::MAX_ERRORS);
            $errors[] = "... and {$more} more";
        }
        if ($errors === [] && $lines === []) {
            $errors[] = 'the file has no lines';
        }
        return ['lines' => $errors === [] ? $lines : [], 'errors' => $errors];
    }

    /** "Supplier Code " -> supplier_code (CsvReader's rule). */
    public static function headerName(string $h): string
    {
        $h = strtolower(trim(str_replace("\u{FEFF}", '', $h)));
        return (string) preg_replace('/\s+/', '_', $h);
    }

    /**
     * One data row (class docblock): the line, or null with $errors filled.
     *
     * @param array<string, string> $r
     * @param \Closure(string, string): (array<string, mixed>|string) $resolve
     * @param array<string, bool> $vatCodes
     * @param list<string> $errors
     * @return array<string, mixed>|null
     */
    private static function row(int $n, array $r, \Closure $resolve, array $vatCodes, array &$errors): ?array
    {
        $err = static function (string $col, string $msg) use ($n, &$errors): void {
            $errors[] = "row {$n}, {$col}: {$msg}";
        };
        $get = static fn (string $c): string => trim((string) ($r[$c] ?? ''));
        $note = $get('note');
        if (mb_strlen($note) > 255) {
            $err('note', 'at most 255 characters');
        }
        $vat = null;
        if ($get('vat_code') !== '') {
            $vat = strtoupper($get('vat_code'));
            if (!array_key_exists($vat, $vatCodes)) {
                $err('vat_code', "there is no VAT code {$vat}");
            } elseif (!$vatCodes[$vat]) {
                $err('vat_code', "the VAT code {$vat} is no longer in use");
            }
        }
        $price = null;
        if ($get('pack_price') !== '') {
            $price = self::price($get('pack_price'));
            if ($price === null) {
                $err('pack_price', 'an amount in GBP of at least 0 with at most 4 decimals');
            }
        }
        $ids = [];
        foreach (self::IDENTIFIERS as $c) {
            if ($get($c) !== '') {
                $ids[$c] = $get($c);
            }
        }
        if (strtolower($get('purchase_unit')) === 'charge' && $ids === []) {
            $desc = $note !== '' ? $note : $get('item_name');
            if ($desc === '') {
                $err('note', 'a charge says what it is (delivery, ...)');
            }
            if ($price === null && $get('pack_price') === '') {
                $err('pack_price', 'a charge needs its amount');
            } elseif ($price !== null && (PoMath::e4($price) <= 0 || PoMath::e4($price) % 100 !== 0)) {
                $err('pack_price', 'a charge is an amount of more than £0 in whole pence');
            }
            if ($get('packs') !== '' && self::wholeNumber($get('packs')) !== 1) {
                $err('packs', 'a charge is one pack (leave it empty)');
            }
            return $errors !== [] ? null : ['kind' => 'charge', 'pack_price' => $price, 'vat_code' => $vat, 'description' => mb_substr($desc, 0, 255), 'row' => $n];
        }
        if ($ids === []) {
            $err('cw_code', 'give cw_code, supplier_code or barcode');
            return null;
        }
        $resolved = [];
        foreach ($ids as $c => $v) {
            $x = $resolve($c, $v);
            if (is_string($x)) {
                $err($c, $x);
                continue;
            }
            $resolved[$c] = $x;
        }
        $first = null;
        foreach ($resolved as $c => $x) {
            if ($first === null) {
                $first = [$c, $x];
                continue;
            }
            $a = $first[1];
            $same = ($a['supplier_item']['id'] ?? null) !== null && ($x['supplier_item']['id'] ?? null) !== null
                ? $a['supplier_item']['id'] === $x['supplier_item']['id']
                : $a['sku_id'] === $x['sku_id'];
            if (!$same) {
                $err($c, "names a different item than {$first[0]} ({$a['sku_code']}" . (($a['supplier_item']['supplier_code'] ?? null) !== null
                    ? ', supplier code ' . $a['supplier_item']['supplier_code'] : '') . ')');
            }
        }
        if (count($resolved) !== count($ids) || $first === null) {
            return null;
        }
        [, $item] = $first;
        $si = $item['supplier_item'];
        $packs = null;
        if ($get('packs') === '') {
            $err('packs', 'required: a whole number of packs from 1 to 1,000,000');
        } else {
            $packs = self::wholeNumber($get('packs'));
            if ($packs === null || $packs < 1 || $packs > PoMath::MAX_PACKS) {
                $err('packs', 'a whole number of packs from 1 to 1,000,000');
                $packs = null;
            }
        }
        $unit = $get('purchase_unit');
        if ($si !== null && $unit !== '' && mb_strtolower($unit) !== mb_strtolower((string) $si['purchase_unit'])) {
            $err('purchase_unit', "differs: supplier item says {$si['purchase_unit']}");
        } elseif ($si === null && mb_strlen($unit) > 32) {
            $err('purchase_unit', 'at most 32 characters');
        }
        $upp = $si === null ? 1 : (int) $si['units_per_pack'];
        if ($get('units_per_pack') !== '') {
            $given = self::wholeNumber($get('units_per_pack'));
            if ($given === null || $given < 1 || $given > PoMath::MAX_UNITS_PER_PACK) {
                $err('units_per_pack', 'a whole number from 1 to ' . number_format(PoMath::MAX_UNITS_PER_PACK));
            } elseif ($si !== null && $given !== $upp) {
                $err('units_per_pack', "pack differs: supplier item says {$upp}");
            } else {
                $upp = $given;
            }
        }
        if ($get('units') !== '' && $packs !== null) {
            $units = self::wholeNumber($get('units'));
            if ($units === null || $units !== $packs * $upp) {
                $err('units', $get('units') . ' is not packs × units per pack (' . $packs . ' × ' . $upp . ' = ' . ($packs * $upp) . ')');
            }
        }
        if ($errors !== []) {
            return null;
        }
        return ['kind' => 'item', 'sku_id' => $item['sku_id'], 'supplier_item_id' => $si['id'] ?? null,
            'supplier_code' => $si === null ? ($ids['supplier_code'] ?? null) : null, 'purchase_unit' => $si === null ? ($unit === '' ? null : $unit) : null,
            'units_per_pack' => $upp, 'packs' => $packs, 'pack_price' => $price ?? ($si['last_pack_price'] ?? null), 'vat_code' => $vat,
            'description' => $note === '' ? null : $note, 'row' => $n];
    }

    /** "24", "24.0", " 24 " -> 24; anything else null. */
    public static function wholeNumber(string $s): ?int
    {
        $s = trim(str_replace(',', '', $s));
        if (preg_match('/^(\d{1,12})(?:\.0+)?$/D', $s, $m) !== 1) {
            return null;
        }
        return (int) $m[1];
    }

    /** A price as typed ("£1,234.5") with 4 decimals, or null when it is not one. */
    public static function price(string $s): ?string
    {
        $s = str_replace([',', '£', ' ', "\u{00A0}"], '', trim($s));
        if (preg_match('/^(\d{1,10})(?:\.(\d{1,4}))?$/D', $s, $m) !== 1 && preg_match('/^()\.(\d{1,4})$/D', $s, $m) !== 1) {
            return null;
        }
        $int = ltrim($m[1], '0');
        $p = ($int === '' ? '0' : $int) . '.' . str_pad($m[2] ?? '', 4, '0');
        return PoMath::e4($p) > PoMath::MAX_PACK_PRICE_E4 ? null : $p;
    }

    /** @param array<int, string> $header */
    private static function checkHeader(array $header): void
    {
        $seen = [];
        foreach ($header as $i => $h) {
            if ($h === '') {
                throw new CwException('bad_file', 'column ' . ($i + 1) . ' of the header is empty', 400);
            }
            if (isset($seen[$h])) {
                throw new CwException('bad_file', "the header names {$h} twice", 400);
            }
            $seen[$h] = true;
        }
    }
}
