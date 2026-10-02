<?php

declare(strict_types=1);

namespace CW\Output;

/**
 * CSV files for people to open in Excel (I25): UTF-8 with a BOM (Excel then reads accents right), RFC 4180 (CRLF,
 * fields in double quotes, quotes doubled), and safe against spreadsheet formula injection.
 *
 * Every column is declared `text` or `number`:
 *  - a text cell is always quoted, NUL bytes are removed, invalid UTF-8 is repaired, and a cell that a spreadsheet
 *    would run as a formula is prefixed with an apostrophe: its first non-blank character (blank = any Unicode white
 *    space or U+3000) is = + - @ or their full-width forms ＝ ＋ － ＠ (U+FF1D, U+FF0B, U+FF0D, U+FF20), or its very
 *    first character is a TAB or a CR. "-5" in a text column therefore reads '-5: numbers belong in number columns.
 *  - a number cell is an int, a finite float, or a string of digits with an optional sign and decimals, written bare
 *    (so Excel sums it); null is an empty cell. Anything else is a programming error (\InvalidArgumentException),
 *    never silently turned into text.
 * Header cells follow the text rule. Downloads are served as text/csv; charset=utf-8, attachment (Ui FilesController).
 */
final class CsvWriter
{
    public const BOM = "\xEF\xBB\xBF";
    private const FORMULA = '/^[\s\x{3000}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u';
    private const NUMBER = '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/D';

    /** @var list<string> */
    private array $lines = [];

    /** @param list<array{0: string, 1: 'text'|'number'}> $columns header title and type of each column */
    public function __construct(private readonly array $columns)
    {
        if ($columns === [] || !array_is_list($columns)) {
            throw new \InvalidArgumentException('a CSV has at least one column');
        }
        $head = [];
        foreach ($columns as $c) {
            if (!is_array($c) || !is_string($c[0] ?? null) || !in_array($c[1] ?? null, ['text', 'number'], true)) {
                throw new \InvalidArgumentException('a CSV column is [title, text|number]');
            }
            $head[] = self::quote(self::safeText($c[0]));
        }
        $this->lines[] = implode(',', $head);
    }

    /** @param list<mixed> $row one value per column */
    public function add(array $row): self
    {
        if (!array_is_list($row) || count($row) !== count($this->columns)) {
            throw new \InvalidArgumentException('a CSV row has exactly one value per column (' . count($this->columns) . ')');
        }
        $cells = [];
        foreach ($row as $i => $v) {
            $cells[] = $this->columns[$i][1] === 'number' ? self::number($v, $this->columns[$i][0]) : self::quote(self::safeText(self::textOf($v)));
        }
        $this->lines[] = implode(',', $cells);
        return $this;
    }

    /** The file: BOM, header, rows, each line ended by CRLF. */
    public function output(): string
    {
        return self::BOM . implode("\r\n", $this->lines) . "\r\n";
    }

    /** The content of a text cell (before quoting): NUL out, invalid UTF-8 repaired, a would-be formula prefixed with '. */
    public static function safeText(?string $v): string
    {
        if ($v === null) {
            return '';
        }
        $v = str_replace("\0", '', mb_scrub($v, 'UTF-8'));
        if ($v !== '' && ($v[0] === "\t" || $v[0] === "\r" || preg_match(self::FORMULA, $v) === 1)) {
            return "'" . $v;
        }
        return $v;
    }

    private static function quote(string $v): string
    {
        return '"' . str_replace('"', '""', $v) . '"';
    }

    private static function textOf(mixed $v): ?string
    {
        return match (true) {
            $v === null => null,
            is_string($v) => $v,
            is_bool($v) => $v ? 'yes' : 'no',
            is_int($v), is_float($v) => (string) $v,
            default => throw new \InvalidArgumentException('a CSV text cell is a scalar or null, not ' . get_debug_type($v)),
        };
    }

    private static function number(mixed $v, string $column): string
    {
        if ($v === null) {
            return '';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v) && is_finite($v)) {
            $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
            return $s === '-0' ? '0' : $s;
        }
        if (is_string($v) && preg_match(self::NUMBER, $v) === 1) {
            return $v;
        }
        throw new \InvalidArgumentException("column {$column} is a number column: " . (is_string($v) ? 'a string that is not a plain number' : get_debug_type($v)) . ' given');
    }
}
