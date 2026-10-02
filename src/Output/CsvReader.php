<?php

declare(strict_types=1);

namespace CW\Output;

use CW\CwException;

/**
 * Reads a CSV file people made (an export from the ERPNext backup copy, a spreadsheet saved as CSV) into rows keyed by
 * header name (docs/decisions.md I45):
 *
 *  - gzip input (magic \x1f\x8b) is read through zlib, at most $maxBytes after decompression (a zip bomb is refused);
 *  - a UTF-8 BOM is dropped; text that is not valid UTF-8 is re-read as Windows-1252 (what Excel UK saves: "£" is 0xA3);
 *  - the delimiter is detected from the header line: comma, semicolon or TAB (the most frequent outside quotes; comma
 *    on a tie);
 *  - RFC 4180 quoting (fgetcsv with no escape character: a quote inside a field is doubled; a field may span lines);
 *  - header names are trimmed, lower-cased, and their spaces become `_` ("Supplier Code" -> supplier_code); an empty or
 *    duplicate header is 400 bad_file;
 *  - a row wider than the header is 400 bad_file (its row number); a shorter one is padded with ''; blank lines and
 *    rows of empty cells are skipped;
 *  - at most $maxRows data rows (413 too_many_rows; null = no cap, the CLI tools) and $maxBytes bytes (413 too_large).
 *
 * Rows are keyed by their 1-based data-row number (row 1 is the first line after the header; skipped blank lines keep
 * their numbers, so a number points at the same line of the spreadsheet: spreadsheet row = number + 1). Cell text is
 * returned raw, `=SUM(A1)` included: formula safety is an OUTPUT concern (CsvWriter).
 */
final class CsvReader
{
    public const DEFAULT_MAX_BYTES = 2_097_152;
    public const DEFAULT_MAX_ROWS = 2000;
    private const DELIMITERS = [',', ';', "\t"];

    /**
     * @return \Generator<int, array<string, string>> data-row number => header => cell
     */
    public static function open(string $path, int $maxBytes = self::DEFAULT_MAX_BYTES, ?int $maxRows = self::DEFAULT_MAX_ROWS): \Generator
    {
        return self::table($path, $maxBytes, $maxRows)['rows'];
    }

    /**
     * The header (checked at once, before any row is read) and the rows.
     *
     * @return array{header: list<string>, delimiter: string, rows: \Generator<int, array<string, string>>}
     */
    public static function table(string $path, int $maxBytes = self::DEFAULT_MAX_BYTES, ?int $maxRows = self::DEFAULT_MAX_ROWS): array
    {
        $text = self::text(self::bytes($path, $maxBytes));
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('cannot open a temporary stream');
        }
        fwrite($stream, $text);
        rewind($stream);
        $delimiter = self::delimiter($text);
        $head = self::record($stream, $delimiter);
        while ($head !== false && self::blank($head)) {
            $head = self::record($stream, $delimiter);
        }
        if ($head === false) {
            fclose($stream);
            throw new CwException('bad_file', 'the file is empty: it needs a header line', 400);
        }
        $header = [];
        foreach ($head as $i => $h) {
            $name = str_replace(' ', '_', strtolower(trim((string) $h)));
            $name = (string) preg_replace('/_+/', '_', $name);
            if ($name === '') {
                fclose($stream);
                throw new CwException('bad_file', 'column ' . ($i + 1) . ' of the header has no name', 400, ['column' => $i + 1]);
            }
            if (in_array($name, $header, true)) {
                fclose($stream);
                throw new CwException('bad_file', "the header names the column {$name} twice", 400, ['column' => $name]);
            }
            $header[] = $name;
        }
        return ['header' => $header, 'delimiter' => $delimiter, 'rows' => self::rows($stream, $delimiter, $header, $maxRows)];
    }

    /**
     * @param resource $stream
     * @param list<string> $header
     * @return \Generator<int, array<string, string>>
     */
    private static function rows($stream, string $delimiter, array $header, ?int $maxRows): \Generator
    {
        try {
            $n = 0;
            $data = 0;
            $width = count($header);
            while (($rec = self::record($stream, $delimiter)) !== false) {
                $n++;
                if (self::blank($rec)) {
                    continue;
                }
                if (count($rec) > $width) {
                    $extra = array_slice($rec, $width);
                    if (!self::blank($extra)) {
                        throw new CwException('bad_file', "row {$n} has " . count($rec) . " cells but the header names {$width} columns", 400, ['row' => $n]);
                    }
                    $rec = array_slice($rec, 0, $width);
                }
                if (++$data > ($maxRows ?? PHP_INT_MAX)) {
                    throw new CwException('too_many_rows', "the file has more than {$maxRows} rows", 413, ['max_rows' => $maxRows]);
                }
                $row = [];
                foreach ($header as $i => $h) {
                    $row[$h] = (string) ($rec[$i] ?? '');
                }
                yield $n => $row;
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @return list<?string>|false */
    private static function record($stream, string $delimiter): array|false
    {
        $r = fgetcsv($stream, null, $delimiter, '"', '');
        return $r === false ? false : array_values($r);
    }

    /** @param list<?string> $rec */
    private static function blank(array $rec): bool
    {
        foreach ($rec as $c) {
            if ($c !== null && trim($c) !== '') {
                return false;
            }
        }
        return true;
    }

    /** The bytes of the file, gunzipped when it is gzip, at most $maxBytes (413 too_large). */
    private static function bytes(string $path, int $maxBytes): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CwException('bad_file', 'there is no readable file', 400);
        }
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new CwException('bad_file', 'there is no readable file', 400);
        }
        $magic = (string) fread($h, 2);
        fclose($h);
        $gz = $magic === "\x1f\x8b";
        $in = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($in === false) {
            throw new CwException('bad_file', 'the file cannot be read', 400);
        }
        $out = '';
        try {
            while (strlen($out) <= $maxBytes) {
                $chunk = $gz ? gzread($in, 65536) : fread($in, 65536);
                if ($chunk === false) {
                    throw new CwException('bad_file', $gz ? 'the gzip file is damaged' : 'the file cannot be read', 400);
                }
                if ($chunk === '') {
                    break;
                }
                $out .= $chunk;
            }
        } finally {
            $gz ? gzclose($in) : fclose($in);
        }
        if (strlen($out) > $maxBytes) {
            throw new CwException('too_large', 'the file is larger than ' . self::size($maxBytes), 413, ['max_bytes' => $maxBytes]);
        }
        return $out;
    }

    /** UTF-8 without a BOM; invalid UTF-8 is read as Windows-1252. */
    private static function text(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }
        return $bytes;
    }

    /** The delimiter of the header line: the most frequent of , ; TAB outside quotes (comma on a tie or none). */
    private static function delimiter(string $text): string
    {
        $counts = array_fill_keys(self::DELIMITERS, 0);
        $quoted = false;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($c === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && ($c === "\n" || $c === "\r")) {
                if (array_sum($counts) > 0) {
                    break;
                }
            } elseif (!$quoted && isset($counts[$c])) {
                $counts[$c]++;
            }
        }
        $best = ',';
        foreach ($counts as $d => $n) {
            if ($n > $counts[$best]) {
                $best = $d;
            }
        }
        return (string) $best;
    }

    private static function size(int $bytes): string
    {
        return $bytes % 1_048_576 === 0 ? ($bytes / 1_048_576) . ' MiB' : number_format($bytes) . ' bytes';
    }
}
