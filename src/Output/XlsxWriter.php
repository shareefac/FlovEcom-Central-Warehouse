<?php

declare(strict_types=1);

namespace CW\Output;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * XLSX files for people to open in Excel (docs/decisions.md I48-I59; openspout/openspout ^4.32, MIT): one sheet, a header
 * row and the rows, the twin of CsvWriter.
 *
 * Every column is declared `text` or `number`. A text cell is ALWAYS written as an explicit string cell
 * (OpenSpout\Common\Entity\Cell\StringCell, an inline string), never through Cell::fromValue(), which turns a string
 * starting with "=" into a FORMULA: "=HYPERLINK(...)" from a supplier's description stays text and never runs
 * (XlsxRoundTripTest checks that the sheet XML has no <f> element). Characters XML 1.0 cannot hold (C0 controls but TAB,
 * LF, CR) are removed and invalid UTF-8 repaired. A number cell is an int or a plain decimal string (written as a number
 * so a spreadsheet sums it); null is an empty cell; anything else is a programming error (\InvalidArgumentException).
 */
final class XlsxWriter
{
    private const NUMBER = '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/D';

    /** @var list<Row> */
    private array $rows = [];

    /** @param list<array{0: string, 1: 'text'|'number'}> $columns header title and type of each column */
    public function __construct(private readonly array $columns, private readonly string $sheetName = 'Sheet1')
    {
        if ($columns === [] || !array_is_list($columns)) {
            throw new \InvalidArgumentException('an XLSX sheet has at least one column');
        }
        $head = [];
        foreach ($columns as $c) {
            if (!is_array($c) || !is_string($c[0] ?? null) || !in_array($c[1] ?? null, ['text', 'number'], true)) {
                throw new \InvalidArgumentException('an XLSX column is [title, text|number]');
            }
            $head[] = new Cell\StringCell(self::clean($c[0]), null);
        }
        $this->rows[] = new Row($head);
    }

    /** @param list<mixed> $row one value per column */
    public function add(array $row): self
    {
        if (!array_is_list($row) || count($row) !== count($this->columns)) {
            throw new \InvalidArgumentException('an XLSX row has exactly one value per column (' . count($this->columns) . ')');
        }
        $cells = [];
        foreach ($row as $i => $v) {
            $cells[] = $this->columns[$i][1] === 'number' ? self::number($v, $this->columns[$i][0]) : self::text($v);
        }
        $this->rows[] = new Row($cells);
        return $this;
    }

    /** The .xlsx file's bytes. */
    public function output(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cw-xlsx-');
        if ($tmp === false) {
            throw new \RuntimeException('cannot create a temporary file');
        }
        try {
            $w = new Writer();
            $w->openToFile($tmp);
            $w->getCurrentSheet()->setName(self::sheetName($this->sheetName));
            $w->addRows($this->rows);
            $w->close();
            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new \RuntimeException('cannot read the written XLSX file');
            }
            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }

    private static function text(mixed $v): Cell
    {
        $s = match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'yes' : 'no',
            is_string($v) => $v,
            is_int($v), is_float($v) => (string) $v,
            $v instanceof \Stringable => (string) $v,
            default => throw new \InvalidArgumentException('a text cell is a scalar or null, not ' . get_debug_type($v)),
        };
        $s = self::clean($s);
        return $s === '' ? new Cell\EmptyCell(null, null) : new Cell\StringCell($s, null);
    }

    private static function number(mixed $v, string $column): Cell
    {
        if ($v === null) {
            return new Cell\EmptyCell(null, null);
        }
        if (is_int($v)) {
            return new Cell\NumericCell($v, null);
        }
        if (is_float($v) && is_finite($v)) {
            return new Cell\NumericCell($v, null);
        }
        if (is_string($v) && preg_match(self::NUMBER, $v) === 1) {
            if (!str_contains($v, '.') && strlen(ltrim($v, '-')) <= 15) {
                return new Cell\NumericCell((int) $v, null);
            }
            return new Cell\NumericCell((float) $v, null);
        }
        throw new \InvalidArgumentException("column {$column}: a number cell is an int, a finite float or a decimal string, not " . get_debug_type($v));
    }

    /** Valid UTF-8 without the characters XML 1.0 cannot hold (C0 controls but TAB, LF, CR; DEL is allowed but dropped). */
    private static function clean(string $s): string
    {
        return (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{FFFE}\x{FFFF}]/u', '', mb_scrub($s, 'UTF-8'));
    }

    /** A sheet name Excel accepts: at most 31 characters, none of \ / ? * [ ] : */
    private static function sheetName(string $name): string
    {
        $n = trim((string) preg_replace('#[\\\\/?*\[\]:]+#', ' ', self::clean($name)), " '");
        return $n === '' ? 'Sheet1' : mb_substr($n, 0, 31);
    }
}
