<?php

declare(strict_types=1);

namespace CW\Output;

use CW\CwException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads the first sheet of an .xlsx file people made (a PO lines file, spec §6.6; openspout/openspout ^4.32) into rows of
 * strings, with the limits a file from a stranger needs (docs/decisions.md I48-I59):
 *
 *  - zip-bomb guard BEFORE openspout parses anything: the archive has at most MAX_ENTRIES entries, the first worksheet
 *    (xl/worksheets/sheet1.xml, or the first worksheet entry) and the shared strings each unpack to at most $maxSheetBytes
 *    (ZipArchive::statName: the uncompressed size the archive declares; a declared size that lies makes the inflate stop
 *    early, it never grows past it), and all entries together to at most twice that (413 too_large); then a streaming
 *    pre-scan refuses a row of more than MAX_CELLS_PER_ROW cells (400 bad_file: openspout builds a whole row in memory);
 *  - at most $maxRows non-empty rows (413 too_many_rows) and row numbers up to MAX_ROW_INDEX (a cell far down the sheet is
 *    refused before millions of empty rows are walked: the reader keeps empty rows so the numbers are the spreadsheet's);
 *  - at most $maxColumns columns (trailing empty cells are not counted; 400 bad_file);
 *  - a file that is not a readable XLSX: 400 bad_file.
 *
 * Values become strings: text as is, a number through its integral form when it has one ("24", never "24.0"; a large
 * barcode stored as a number stays all digits) or its decimal digits otherwise, a boolean "1" / "0", a date "Y-m-d", a
 * formula its computed value (a text that merely starts with "=" stays that text), an error or empty cell "". Rows are keyed
 * by their spreadsheet row number (1 = the first row). Nothing read is ever evaluated: the values are only parsed as the
 * PO lines rules say (PoLinesFile).
 */
final class XlsxReader
{
    /**
     * 16 MiB (was 50 MiB, review finding I83): 2,001 rows × 50 columns of PO lines are a few MiB of XML; the bigger the
     * allowance, the more cells a small, highly compressible file can carry.
     */
    public const MAX_SHEET_BYTES = 16_777_216;
    /**
     * Cells (<c> elements, empty styled ones included) one row of a worksheet may have, checked by a streaming pre-scan
     * BEFORE openspout builds a row in memory: an 88 KB file with 3,000,000 cells in row 1 exhausted 256 MB (review finding,
     * I83). Generous for styled empty cells; the 50-column limit still applies to what is read.
     */
    public const MAX_CELLS_PER_ROW = 1000;
    public const MAX_ROWS = 2001;
    public const MAX_COLUMNS = 50;
    public const MAX_ENTRIES = 1000;
    public const MAX_ROW_INDEX = 10_000;
    public const MAGIC = "PK\x03\x04";

    /** Whether the file starts like a zip archive (an XLSX). */
    public static function looksLikeXlsx(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return false;
        }
        $head = (string) fread($h, 4);
        fclose($h);
        return $head === self::MAGIC;
    }

    /**
     * @return array<int, list<string>> spreadsheet row number => cell values (trailing empty cells removed; empty rows left out)
     */
    public static function read(string $path, int $maxRows = self::MAX_ROWS, int $maxColumns = self::MAX_COLUMNS, int $maxSheetBytes = self::MAX_SHEET_BYTES): array
    {
        self::guard($path, $maxSheetBytes);
        $options = new Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->SHOULD_FORMAT_DATES = false;
        $reader = new Reader($options);
        $out = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    if ($index > self::MAX_ROW_INDEX) {
                        throw new CwException('too_many_rows', 'the sheet has rows beyond row ' . number_format(self::MAX_ROW_INDEX), 413);
                    }
                    $cells = array_map(self::value(...), $row->getCells());
                    while ($cells !== [] && trim((string) end($cells)) === '') {
                        array_pop($cells);
                    }
                    if ($cells === []) {
                        continue;
                    }
                    if (count($cells) > $maxColumns) {
                        throw new CwException('bad_file', "row {$index} has more than {$maxColumns} columns", 400);
                    }
                    if (count($out) >= $maxRows) {
                        throw new CwException('too_many_rows', "the sheet has more than {$maxRows} rows", 413);
                    }
                    $out[(int) $index] = array_values($cells);
                }
                break; // the first sheet only
            }
        } catch (CwException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CwException('bad_file', 'this is not a readable XLSX file (' . mb_substr($e->getMessage(), 0, 120) . ')', 400);
        } finally {
            try {
                $reader->close();
            } catch (\Throwable) {
                // never opened
            }
        }
        return $out;
    }

    /** The zip-bomb guard (class docblock). */
    private static function guard(string $path, int $maxSheetBytes): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ext-zip is required to read XLSX files');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new CwException('bad_file', 'this is not a readable XLSX file (not a zip archive)', 400);
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new CwException('bad_file', 'the XLSX archive has more than ' . self::MAX_ENTRIES . ' entries', 400);
            }
            $sheet = $zip->statName('xl/worksheets/sheet1.xml');
            $total = 0;
            $sheets = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if ($st === false) {
                    throw new CwException('bad_file', 'the XLSX archive is damaged', 400);
                }
                $size = (int) $st['size'];
                $total += $size;
                $name = (string) $st['name'];
                if ($sheet === false && str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')) {
                    $sheet = $st;
                }
                if (str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml') && !str_contains($name, '#')) {
                    $sheets[] = $name;
                }
                if (($name === 'xl/sharedStrings.xml' || str_starts_with($name, 'xl/worksheets/')) && $size > $maxSheetBytes) {
                    throw new CwException('too_large', "{$name} unpacks to " . number_format($size) . ' bytes: more than ' . number_format($maxSheetBytes) . ' (refused unread)', 413);
                }
            }
            if ($sheet === false) {
                throw new CwException('bad_file', 'the XLSX file has no worksheet', 400);
            }
            if ((int) $sheet['size'] > $maxSheetBytes) {
                throw new CwException('too_large', 'the worksheet unpacks to more than ' . number_format($maxSheetBytes) . ' bytes (refused unread)', 413);
            }
            if ($total > 2 * $maxSheetBytes) {
                throw new CwException('too_large', 'the XLSX file unpacks to more than ' . number_format(2 * $maxSheetBytes) . ' bytes (refused unread)', 413);
            }
        } finally {
            $zip->close();
        }
        foreach ($sheets as $name) {
            self::scanCells($path, $name);
        }
    }

    /**
     * Streams a worksheet's XML (XMLReader: constant memory, no entity expansion, no network) and refuses a row with more
     * than MAX_CELLS_PER_ROW cells (400 bad_file) before openspout would build it.
     */
    private static function scanCells(string $path, string $entry): void
    {
        $x = new \XMLReader();
        if (!@$x->open('zip://' . $path . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new CwException('bad_file', 'this is not a readable XLSX file (the worksheet cannot be read)', 400);
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $row = 0;
            $cells = 0;
            while (@$x->read()) {
                if ($x->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if ($x->localName === 'row') {
                    $row++;
                    $cells = 0;
                } elseif ($x->localName === 'c' && ++$cells > self::MAX_CELLS_PER_ROW) {
                    throw new CwException('bad_file', "row {$row} of the sheet has more than " . number_format(self::MAX_CELLS_PER_ROW) . ' cells (refused unread)', 400);
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $x->close();
        }
    }

    private static function value(Cell $cell): string
    {
        // openspout reads a text that starts with "=" as a FormulaCell without a computed value: that is the text itself. A real
        // formula (an <f> element) carries the value Excel computed: that value, never the formula.
        $v = match (true) {
            $cell instanceof Cell\FormulaCell => $cell->getComputedValue() ?? $cell->getValue(),
            $cell instanceof Cell\ErrorCell => null,
            default => $cell->getValue(),
        };
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? '1' : '0',
            is_int($v) => (string) $v,
            is_float($v) => self::float($v),
            $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
            $v instanceof \DateInterval => $v->format('%h:%I:%S'),
            default => (string) $v,
        };
    }

    /** A float as people typed it: its integral form when it has one, else up to 10 decimals without trailing zeros. */
    private static function float(float $v): string
    {
        if (!is_finite($v)) {
            return '';
        }
        if (floor($v) === $v && abs($v) < 1e18) {
            return sprintf('%.0f', $v);
        }
        $s = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }
}
