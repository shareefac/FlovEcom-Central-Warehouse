<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Output\CsvWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * I25: CSV for Excel without formula injection. Text cells are quoted and a would-be formula is prefixed with an
 * apostrophe (ASCII and full-width trigger characters, after any blank, and a leading TAB or CR); number columns are
 * bare numbers or a programming error; UTF-8 BOM, RFC 4180 quoting, CRLF.
 */
final class CsvWriterTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function injections(): array
    {
        $out = [];
        foreach (['=1+1', '+1', '-1+2', '@SUM(A1)', "\t=1", "\r=1", ' =1', "\u{3000}=1", "\u{FF1D}1", "\u{FF0B}1", "\u{FF0D}1", "\u{FF20}1",
            "=cmd|' /C calc'!A0", '=HYPERLINK("http://x.invalid","click")', "  \t+1", "\n-1"] as $v) {
            $out[json_encode($v, JSON_UNESCAPED_UNICODE)] = [$v];
        }
        return $out;
    }

    #[DataProvider('injections')]
    public function testAWouldBeFormulaIsPrefixed(string $value): void
    {
        self::assertSame("'" . $value, CsvWriter::safeText($value));
        $csv = (new CsvWriter([['name', 'text']]))->add([$value])->output();
        $rows = self::parse($csv);
        self::assertSame("'" . $value, $rows[1][0], 'the cell a spreadsheet reads starts with the apostrophe');
    }

    public function testOrdinaryTextIsUntouched(): void
    {
        foreach (['plain text', 'a=b', 'x+1', 'mail@example', 'Café £12.50', '', '5', '1-2', "'already"] as $v) {
            self::assertSame($v, CsvWriter::safeText($v), $v);
        }
        self::assertSame('', CsvWriter::safeText(null));
        self::assertSame('ab', CsvWriter::safeText("a\0b"), 'NUL removed');
        self::assertSame("'=1", CsvWriter::safeText("\0=1"), 'NUL cannot hide a formula');
    }

    public function testNumberColumnsAreBareAndRefuseAnythingElse(): void
    {
        $csv = (new CsvWriter([['qty', 'number'], ['cost', 'number'], ['label', 'text']]))
            ->add([-5, '1.25', '-5'])->add([0, 2.5, null])->add(['12', null, 'x'])->output();
        $lines = explode("\r\n", substr($csv, 3));
        self::assertSame('"qty","cost","label"', $lines[0]);
        self::assertSame('-5,1.25,"\'-5"', $lines[1], 'a negative number is a number in a number column and text in a text column');
        self::assertSame('0,2.5,""', $lines[2]);
        self::assertSame('12,,"x"', $lines[3]);
        foreach (['=1', '1e5', '01', '1.', '+1', ' 1', '1,5', 'NaN', true] as $bad) {
            try {
                (new CsvWriter([['qty', 'number']]))->add([$bad]);
                self::fail('accepted ' . var_export($bad, true));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('number column', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        (new CsvWriter([['qty', 'number']]))->add([INF]);
    }

    public function testQuotesNewlinesAndCommasRoundTripWithOneBomAndCrlf(): void
    {
        $tricky = ['say "hello", then leave', "two\nlines", 'semi;colon', 'comma, inside', 'Łódź 中文 ™'];
        $csv = (new CsvWriter([['a', 'text'], ['b', 'text'], ['c', 'text'], ['d', 'text'], ['e', 'text']]))->add($tricky)->add(['x', 'y', 'z', 'w', 'v'])->output();
        self::assertStringStartsWith("\xEF\xBB\xBF\"a\",", $csv);
        self::assertSame(1, substr_count($csv, "\xEF\xBB\xBF"), 'one BOM');
        self::assertStringEndsWith("\r\n", $csv);
        self::assertSame(3, substr_count($csv, "\r\n"), 'every record ends with CRLF; the newline inside a cell is not a record end');
        $rows = self::parse($csv);
        self::assertSame(['a', 'b', 'c', 'd', 'e'], $rows[0]);
        self::assertSame($tricky, $rows[1]);
        self::assertSame(['x', 'y', 'z', 'w', 'v'], $rows[2]);
        self::assertSame(['say "hello", then leave'], str_getcsv('"say ""hello"", then leave"', ',', '"', ''), 'str_getcsv reads the quoting back');
        self::assertStringContainsString('"say ""hello"", then leave"', $csv);
    }

    public function testRowsMustMatchTheColumns(): void
    {
        $w = new CsvWriter([['a', 'text'], ['b', 'number']]);
        $this->expectException(\InvalidArgumentException::class);
        $w->add(['only one']);
    }

    public function testHeaderCellsFollowTheTextRule(): void
    {
        $csv = (new CsvWriter([['=evil()', 'text']]))->output();
        self::assertStringStartsWith("\xEF\xBB\xBF\"'=evil()\"\r\n", $csv);
    }

    /** @return list<list<string>> the records a spreadsheet reads (RFC 4180, no escape character) */
    private static function parse(string $csv): array
    {
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $h = fopen('php://memory', 'w+b');
        self::assertIsResource($h);
        fwrite($h, substr($csv, 3));
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $rows[] = array_map('strval', $r);
        }
        fclose($h);
        return $rows;
    }
}
