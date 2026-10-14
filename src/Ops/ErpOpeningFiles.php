<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\CwException;
use CW\Output\CsvReader;
use CW\Output\XlsxReader;

/**
 * Reads the files of the ERPNext opening import (pack I, docs/decisions.md PI8-PI10). CW never connects to ERPNext and never calls
 * its stock feed: staff download these files from ERPNext's own screens (docs/ops.md "The opening stock from ERPNext").
 *
 *  1. The stock file (required): ERPNext's "Stock Balance" report exported as CSV or Excel, one row per item and warehouse. Read by
 *     its column names (as CsvReader normalises them): Item (or Item Code), Warehouse, Balance Qty, Valuation Rate; Company, Balance
 *     Value, Stock UOM and Item Name when present. A Report-view export of ERPNext's Bin list (Item Code, Warehouse, Actual Quantity,
 *     Valuation Rate, Stock Value) reads the same way. An export made "with filters" starts with its filter lines (name, value) and
 *     a blank line: they are kept (company, to_date) for the checks. The report's Total line is checked against the lines (a file that
 *     was edited or cut is refused) and left out. The same item and warehouse twice is refused.
 *  2. The item list (optional, needed for every item without a CW ERPNext link): ERPNext's Item list exported with its ID (or Item
 *     Code) and Product ID columns: Product ID is the custom field that holds the store's variant number.
 *  3. The sales still on their way (optional; required for --apply by erp_opening.require_pending_sales): units the store sold that
 *     ERPNext does not have yet (the website's sync queue, pending and failed), one row per variant (Variant ID / Product ID) or ERPNext
 *     item (Item Code), with Qty (no Qty column: each row is one unit).
 *
 * Quantities must be whole numbers ("12", "12.0", "-3"); a fraction is the line's own problem, listed, never rounded. A rate is
 * kept as a 6-decimal string (rounded half up); a blank rate, a negative one or one that is not a number is flagged, never guessed.
 */
final class ErpOpeningFiles
{
    public const MAX_BYTES = 67_108_864;
    public const MAX_ROWS = 300_000;
    public const MAX_COLUMNS = 60;
    /** The stock file's columns: what each is called in the ERPNext exports, as CsvReader normalises a header. */
    public const STOCK_COLUMNS = [
        'item_code' => ['item', 'item_code'],
        'warehouse' => ['warehouse'],
        'qty' => ['balance_qty', 'bal_qty', 'actual_qty', 'actual_quantity'],
        'rate' => ['valuation_rate', 'val_rate'],
        'company' => ['company'],
        'value' => ['balance_value', 'bal_val', 'stock_value'],
        'uom' => ['stock_uom', 'uom'],
        'item_name' => ['item_name'],
    ];
    public const STOCK_REQUIRED = ['item_code', 'warehouse', 'qty', 'rate'];
    public const MAP_COLUMNS = ['item_code' => ['item_code', 'id', 'item', 'name'], 'product_id' => ['product_id']];
    public const PENDING_COLUMNS = ['variant' => ['variant_id', 'product_id', 'prodt_id', 'variant'], 'item_code' => ['item_code', 'item'],
        'qty' => ['qty', 'quantity', 'units']];
    public const MAX_QTY = 10_000_000;

    /**
     * Every non-blank record of a CSV (gzip too) or XLSX file (the first sheet), keyed by its line / row number.
     *
     * @return iterable<int, list<string>>
     */
    public static function records(string $path): iterable
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CwException('bad_file', 'there is no readable file ' . basename($path), 400);
        }
        if (XlsxReader::looksLikeXlsx($path)) {
            return XlsxReader::read($path, self::MAX_ROWS, self::MAX_COLUMNS, self::MAX_BYTES, self::MAX_ROWS + 1000);
        }
        return CsvReader::records($path, self::MAX_BYTES);
    }

    /** A header cell as CsvReader names a column ("Balance Qty" -> balance_qty). */
    public static function name(string $cell): string
    {
        $cell = (string) preg_replace('/^\xEF\xBB\xBF/', '', $cell);
        return (string) preg_replace('/_+/', '_', str_replace(' ', '_', strtolower(trim($cell))));
    }

    /**
     * The stock file.
     *
     * @return array{sha256: string, file: string, header_row: int, columns: list<string>, filters: array<string, string>,
     *               total: array{row: int, qty: ?string, check: string}|null, preamble_rows: int,
     *               lines: list<array{row: int, item_code: string, warehouse: string, company: ?string, qty: ?int, qty_raw: string,
     *                     qty_error: ?string, rate: ?string, rate_raw: string, rate_flag: ?string, value: ?string, uom: ?string, item_name: ?string}>}
     */
    public static function stock(string $path): array
    {
        $records = self::records($path);
        [$headerRow, $cols, $filters, $preamble, $rest] = self::header($records, self::STOCK_COLUMNS, self::STOCK_REQUIRED,
            'the stock file needs the columns Item, Warehouse, Balance Qty and Valuation Rate (the Stock Balance report; a Bin export: '
            . 'Item Code, Warehouse, Actual Quantity, Valuation Rate)');
        $lines = [];
        $seen = [];
        $total = null;
        $sum = '0';
        $sumOk = true;
        foreach ($rest as $n => $rec) {
            $cell = static fn (string $k): ?string => isset($cols[$k]) ? trim((string) ($rec[$cols[$k]] ?? '')) : null;
            $item = (string) $cell('item_code');
            $wh = (string) $cell('warehouse');
            if ($wh === '' && preg_match("/^'?total'?$/i", $item) === 1) {
                if ($total !== null) {
                    throw new CwException('bad_file', "the stock file has two Total lines (rows {$total['row']} and {$n})", 400);
                }
                $total = ['row' => $n, 'qty' => self::decimal((string) $cell('qty'), true), 'check' => 'not_checked'];
                continue;
            }
            if ($total !== null) {
                throw new CwException('bad_file', "row {$n} comes after the Total line (row {$total['row']}): the file is not one export", 400);
            }
            $qtyRaw = (string) $cell('qty');
            [$qty, $qtyError] = self::wholeNumber($qtyRaw);
            $dec = self::decimal($qtyRaw, true);
            if ($dec === null) {
                $sumOk = false;
            } else {
                $sum = bcadd($sum, $dec, 9);
            }
            $rateRaw = (string) $cell('rate');
            [$rate, $flag] = self::rate($rateRaw);
            $value = $cell('value');
            $company = $cell('company');
            $key = ErpOpeningRules::itemKey($item) . "\0" . ErpOpeningRules::key($wh);
            if ($item !== '' && isset($seen[$key])) {
                throw new CwException('bad_file', "the item {$item} at {$wh} is in the stock file twice (rows {$seen[$key]} and {$n}): export it again", 400);
            }
            $seen[$key] = $n;
            $lines[] = ['row' => $n, 'item_code' => $item, 'warehouse' => $wh, 'company' => $company, 'qty' => $qty, 'qty_raw' => $qtyRaw,
                'qty_error' => $qtyError, 'rate' => $rate, 'rate_raw' => $rateRaw, 'rate_flag' => $flag,
                'value' => $value === null || $value === '' ? null : self::decimal($value, true), 'uom' => $cell('uom'), 'item_name' => $cell('item_name')];
        }
        if ($lines === []) {
            throw new CwException('bad_file', 'the stock file has no lines under its column names', 400);
        }
        if ($total !== null && $total['qty'] !== null) {
            if (!$sumOk) {
                $total['check'] = 'not_checked';
            } elseif (abs((float) bcsub($sum, $total['qty'], 9)) > 0.001) {
                throw new CwException('total_mismatch', sprintf('the Total line (row %d) says %s units but the lines add up to %s: the file was edited, '
                    . 'filtered in the report grid or cut short; export it again', $total['row'], self::trim0($total['qty']), self::trim0($sum)), 400);
            } else {
                $total['check'] = 'matches';
            }
        }
        return ['sha256' => (string) hash_file('sha256', $path), 'file' => basename($path), 'header_row' => $headerRow, 'columns' => array_keys($cols),
            'filters' => $filters, 'total' => $total, 'preamble_rows' => $preamble, 'lines' => $lines];
    }

    /**
     * The item list: ERPNext item code => the store's variant number (Product ID).
     *
     * @return array{sha256: string, file: string, rows: int, map: array<string, string>, without_product: int, ambiguous: array<string, list<string>>}
     *         map keys: ErpOpeningRules::itemKey(item code)
     */
    public static function itemMap(string $path): array
    {
        [, $cols, , , $rest] = self::header(self::records($path), self::MAP_COLUMNS, ['item_code', 'product_id'],
            'the item list needs the columns ID (or Item Code) and Product ID');
        $map = [];
        $ambiguous = [];
        $rows = 0;
        $without = 0;
        foreach ($rest as $rec) {
            $rows++;
            $code = trim((string) ($rec[$cols['item_code']] ?? ''));
            $pid = trim((string) ($rec[$cols['product_id']] ?? ''));
            if ($code === '') {
                continue;
            }
            if ($pid === '') {
                $without++;
                continue;
            }
            $k = ErpOpeningRules::itemKey($code);
            if (isset($map[$k]) && $map[$k] !== $pid) {
                $ambiguous[$k] = array_values(array_unique([...($ambiguous[$k] ?? [$map[$k]]), $pid]));
            }
            $map[$k] ??= $pid;
        }
        foreach (array_keys($ambiguous) as $k) {
            unset($map[$k]);
        }
        return ['sha256' => (string) hash_file('sha256', $path), 'file' => basename($path), 'rows' => $rows, 'map' => $map, 'without_product' => $without,
            'ambiguous' => $ambiguous];
    }

    /**
     * The sales still on their way to ERPNext.
     *
     * @return array{sha256: string, file: string, by: string, rows: list<array{row: int, ref: string, qty: ?int, error: ?string}>}
     */
    public static function pendingSales(string $path): array
    {
        $records = self::records($path);
        [, $cols, , , $rest] = self::header($records, self::PENDING_COLUMNS, [],
            'the file of sales not yet in ERPNext needs a Variant ID (or Product ID) or an Item Code column, and Qty', true);
        $by = isset($cols['variant']) ? 'variant' : 'item_code';
        $rows = [];
        foreach ($rest as $n => $rec) {
            $ref = trim((string) ($rec[$cols[$by]] ?? ''));
            if (isset($cols['qty'])) {
                [$qty, $err] = self::wholeNumber(trim((string) ($rec[$cols['qty']] ?? '')));
                if ($err === null && $qty !== null && $qty <= 0) {
                    [$qty, $err] = [null, 'not_above_zero'];
                }
            } else {
                [$qty, $err] = [1, null];
            }
            if ($ref === '') {
                [$qty, $err] = [null, 'no_reference'];
            }
            $rows[] = ['row' => $n, 'ref' => $ref, 'qty' => $err === null ? $qty : null, 'error' => $err];
        }
        return ['sha256' => (string) hash_file('sha256', $path), 'file' => basename($path), 'by' => $by, 'rows' => $rows];
    }

    /**
     * Finds the header: the first record naming every required column (or, $either, a variant or item column). The records before it
     * with two cells are the export's filter lines (name => value); any other is counted as preamble.
     *
     * @param iterable<int, list<string>> $records
     * @param array<string, list<string>> $known
     * @param list<string> $required
     * @return array{0: int, 1: array<string, int>, 2: array<string, string>, 3: int, 4: \Generator<int, list<string>>}
     */
    private static function header(iterable $records, array $known, array $required, string $needs, bool $either = false): array
    {
        $gen = (static function (iterable $r): \Generator {
            yield from $r;
        })($records);
        $filters = [];
        $preamble = 0;
        for (; $gen->valid(); $gen->next()) {
            $rec = $gen->current();
            $names = array_map(self::name(...), $rec);
            $cols = [];
            foreach ($known as $field => $aliases) {
                foreach ($aliases as $alias) {
                    $i = array_search($alias, $names, true);
                    if ($i !== false) {
                        $cols[$field] = (int) $i;
                        break;
                    }
                }
            }
            $ok = $either ? (isset($cols['variant']) || isset($cols['item_code'])) : array_diff($required, array_keys($cols)) === [];
            if ($ok) {
                $row = (int) $gen->key();
                $gen->next();
                $rest = (static function (\Generator $g): \Generator {
                    for (; $g->valid(); $g->next()) {
                        yield $g->key() => $g->current();
                    }
                })($gen);
                return [$row, $cols, $filters, $preamble, $rest];
            }
            $cells = array_values(array_filter($rec, static fn (string $c): bool => trim($c) !== ''));
            if (count($cells) === 2 && preg_match('/^[a-z_ ]{1,40}$/i', trim($cells[0])) === 1) {
                $filters[self::name($cells[0])] = trim($cells[1]);
            } else {
                $preamble++;
            }
            if ($preamble + count($filters) > 50) {
                break;
            }
        }
        throw new CwException('bad_file', $needs . ': no line of the file names them', 400);
    }

    /**
     * A whole number, or why not (bad_qty, fractional_qty). ERPNext's figures are floats: "12.0", "-3", and float noise such as
     * "11.999999999999998" read as the whole number they are (within 0.0001); "1.5" is a fraction, never rounded.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function wholeNumber(string $raw): array
    {
        $d = self::decimal($raw, true);
        if ($d === null) {
            return [null, 'bad_qty'];
        }
        $f = (float) $d;
        $int = (int) round($f);
        if (abs($f - $int) > 0.0001) {
            return [null, 'fractional_qty'];
        }
        return abs($int) > self::MAX_QTY ? [null, 'bad_qty'] : [$int, null];
    }

    /** @return array{0: ?string, 1: ?string} a rate as a 6-decimal string, or null and its flag (`blank`, `bad`) */
    public static function rate(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, 'blank'];
        }
        $d = self::decimal(ltrim($raw, '£'), false);
        if ($d === null) {
            return [null, 'bad'];
        }
        $six = bcadd($d, '0.0000005', 6);
        return bccomp($six, '99999999.999999', 6) > 0 ? [null, 'bad'] : [$six, null];
    }

    /**
     * A number as a plain bcmath decimal string, or null: "1234.5", "-3", "1,234.50" (whole thousands groups), and what Python writes
     * for a float ERPNext computed ("3.3333333333333335", "1e-05": read through a float, 12 decimals).
     */
    public static function decimal(string $raw, bool $signed): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^-?[0-9]{1,3}(,[0-9]{3})+(\.[0-9]+)?$/D', $raw) === 1) {
            $raw = str_replace(',', '', $raw);
        }
        if (preg_match('/^(-?)([0-9]{1,15})(?:\.([0-9]+))?(?:[eE]([-+]?[0-9]{1,3}))?$/D', $raw, $m) !== 1 || (!$signed && $m[1] === '-')) {
            return null;
        }
        if (isset($m[4]) || strlen($m[3] ?? '') > 12) {
            $f = (float) $raw;
            if (!is_finite($f) || abs($f) >= 1e15) {
                return null;
            }
            $raw = sprintf('%.12F', $f);
            return $raw === '-0.000000000000' ? '0.000000000000' : $raw;
        }
        return $raw === '-0' ? '0' : $raw;
    }

    private static function trim0(string $d): string
    {
        return str_contains($d, '.') ? rtrim(rtrim($d, '0'), '.') : $d;
    }
}
