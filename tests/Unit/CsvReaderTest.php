<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Output\CsvReader;
use PHPUnit\Framework\TestCase;

/**
 * CsvReader (I45): what people's CSV files look like (Excel UK, LibreOffice, an export from the ERPNext backup copy) is
 * read into rows keyed by a normalised header, and what cannot be read safely is refused with 400 bad_file / 413.
 * Fixtures are written to temporary files here: *.csv is git-ignored and never committed.
 */
final class CsvReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function file(string $bytes, string $suffix = '.csv'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cw-csvr-');
        self::assertIsString($path);
        @unlink($path);
        $path .= $suffix;
        file_put_contents($path, $bytes);
        $this->files[] = $path;
        return $path;
    }

    /** @return array<int, array<string, string>> */
    private static function read(string $path, int $maxBytes = CsvReader::DEFAULT_MAX_BYTES, ?int $maxRows = CsvReader::DEFAULT_MAX_ROWS): array
    {
        return iterator_to_array(CsvReader::open($path, $maxBytes, $maxRows), true);
    }

    private static function refused(int $status, string $code, callable $fn): CwException
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame([$status, $code], [$e->httpStatus, $e->errorCode], $e->getMessage());
            return $e;
        }
        self::fail("expected {$status} {$code}");
    }

    public function testBomCrlfHeadersAndRowNumbers(): void
    {
        $rows = self::read($this->file("\xEF\xBB\xBFSupplier Code, Pack Price ,NAME\r\nA1,1.50,One\r\n\r\nB2,2,Two\r\n"));
        self::assertSame([1 => ['supplier_code' => 'A1', 'pack_price' => '1.50', 'name' => 'One'], 3 => ['supplier_code' => 'B2', 'pack_price' => '2', 'name' => 'Two']],
            $rows, 'BOM dropped, headers trimmed + lower-cased + spaces to _, the blank line skipped but counted');
        $t = CsvReader::table($this->file("a,b\n1,2\n"));
        self::assertSame(['a', 'b'], $t['header']);
        self::assertSame(',', $t['delimiter']);
    }

    public function testQuotedNewlinesAndQuotes(): void
    {
        $rows = self::read($this->file("name,note\r\n\"Acme, Ltd\",\"line one\r\nline \"\"two\"\"\"\r\nPlain,x\r\n"));
        self::assertSame(['name' => 'Acme, Ltd', 'note' => "line one\r\nline \"two\""], $rows[1]);
        self::assertSame(['name' => 'Plain', 'note' => 'x'], $rows[2]);
        // No escape character: a backslash is an ordinary character (RFC 4180).
        self::assertSame(['a' => 'C:\\path\\', 'b' => 'x'], self::read($this->file("a,b\n\"C:\\path\\\",x\n"))[1]);
    }

    public function testSemicolonAndTabDelimitersAreDetectedFromTheHeader(): void
    {
        self::assertSame([1 => ['code' => 'A', 'price' => '1,50', 'n' => '3']], self::read($this->file("code;price;n\nA;1,50;3\n")), 'semicolon (European Excel)');
        self::assertSame([1 => ['code' => 'A', 'name' => 'x, y']], self::read($this->file("code\tname\nA\tx, y\n")), 'TAB');
        self::assertSame([1 => ['a;b' => 'x', 'c' => 'y']], self::read($this->file("\"a;b\",c\nx,y\n")), 'a delimiter inside quotes does not count');
    }

    public function testWindows1252IsReadWhenTheFileIsNotUtf8(): void
    {
        $rows = self::read($this->file("name,price\n\x93Caf\xE9\x94,\xA31.50\n"));
        self::assertSame(['name' => "\u{201C}Café\u{201D}", 'price' => '£1.50'], $rows[1], 'Excel UK: £ is 0xA3, curly quotes 0x93/0x94');
        $utf8 = self::read($this->file("name,price\nCafé,£2\n"));
        self::assertSame(['name' => 'Café', 'price' => '£2'], $utf8[1], 'valid UTF-8 is left alone');
    }

    public function testEmptyOrDuplicateHeadersAndWideRowsAreRefused(): void
    {
        $e = self::refused(400, 'bad_file', fn () => self::read($this->file("a,,c\n1,2,3\n")));
        self::assertSame('column 2 of the header has no name', $e->getMessage());
        $e = self::refused(400, 'bad_file', fn () => self::read($this->file("Code,code\n1,2\n")));
        self::assertSame('the header names the column code twice', $e->getMessage());
        self::refused(400, 'bad_file', fn () => self::read($this->file('')));
        self::refused(400, 'bad_file', fn () => self::read($this->file("\n\n")));
        $e = self::refused(400, 'bad_file', fn () => self::read($this->file("a,b\n1,2\n1,2,3\n")));
        self::assertSame('row 2 has 3 cells but the header names 2 columns', $e->getMessage());
        self::assertSame([1 => ['a' => '1', 'b' => '2']], self::read($this->file("a,b\n1,2,,\n")), 'trailing empty cells (a spreadsheet\'s) are not wider');
        self::assertSame([1 => ['a' => '1', 'b' => '']], self::read($this->file("a,b\n1\n")), 'a short row is padded');
        self::assertSame([], self::read($this->file("a,b\n,\n , \n")), 'rows of empty cells are skipped');
        self::refused(400, 'bad_file', fn () => CsvReader::open('/nonexistent/cw.csv'));
    }

    public function testTheRowAndByteCaps(): void
    {
        $body = "a\n" . implode("\n", range(1, 5)) . "\n";
        self::assertCount(5, self::read($this->file($body), CsvReader::DEFAULT_MAX_BYTES, 5));
        $e = self::refused(413, 'too_many_rows', fn () => self::read($this->file($body), CsvReader::DEFAULT_MAX_BYTES, 4));
        self::assertSame('the file has more than 4 rows', $e->getMessage());
        self::assertCount(5, self::read($this->file($body), CsvReader::DEFAULT_MAX_BYTES, null), 'null: no cap (the CLI)');
        self::refused(413, 'too_large', fn () => self::read($this->file(str_repeat('x', 101)), 100));
        self::assertCount(2000, self::read($this->file("a\n" . str_repeat("1\n", 2000))), 'the default cap is 2,000 rows');
        self::refused(413, 'too_many_rows', fn () => self::read($this->file("a\n" . str_repeat("1\n", 2001))));
    }

    public function testGzipInputAndItsCap(): void
    {
        $rows = self::read($this->file((string) gzencode("\xEF\xBB\xBFcode,qty\nA,1\nB,2\n"), '.csv.gz'));
        self::assertSame([1 => ['code' => 'A', 'qty' => '1'], 2 => ['code' => 'B', 'qty' => '2']], $rows);
        $bomb = $this->file((string) gzencode("a\n" . str_repeat("0\n", 1_200_000), 9), '.gz');
        self::assertLessThan(20_000, (int) filesize($bomb));
        self::refused(413, 'too_large', fn () => self::read($bomb)); // the cap applies after decompression
    }

    public function testFormulaCellsAreReturnedRaw(): void
    {
        $rows = self::read($this->file("name,price\n=SUM(A1),=1+1\n\"=HYPERLINK(\"\"http://x\"\")\",-2\n"));
        self::assertSame(['name' => '=SUM(A1)', 'price' => '=1+1'], $rows[1], 'formula safety is an output concern (CsvWriter)');
        self::assertSame(['name' => '=HYPERLINK("http://x")', 'price' => '-2'], $rows[2]);
    }
}
