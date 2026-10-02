<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Output\XlsxReader;
use CW\Output\XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * XLSX through openspout (I48-I59): what XlsxWriter writes, XlsxReader reads back; a text that starts with "=" is written as
 * a string, never a formula (no <f> in the sheet XML); numbers are numeric cells; the reader's limits: rows, columns and
 * the zip-bomb guard (a worksheet whose declared uncompressed size is too large is refused before it is read).
 */
final class XlsxRoundTripTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw_xlsx_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function file(string $bytes, string $name = 'f.xlsx'): string
    {
        $p = "{$this->dir}/{$name}";
        file_put_contents($p, $bytes);
        return $p;
    }

    private static function sheetXml(string $path): string
    {
        $z = new \ZipArchive();
        self::assertTrue($z->open($path));
        $xml = (string) $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        return $xml;
    }

    public function testWriteThenRead(): void
    {
        $w = new XlsxWriter([['code', 'text'], ['packs', 'number'], ['price', 'number'], ['note', 'text']], 'PO-000001 lines');
        $w->add(['CW-000001', 24, '1.6500', '=1+1'])
            ->add(['5060000000017', '3', null, "Café £12 \x01ctl"])
            ->add([null, -2, '0.1234', '+44 113 000'])
            ->add(['@SUM(A1)', 0, 12, '-1+2']);
        $path = $this->file($w->output());
        self::assertSame(XlsxReader::MAGIC, substr((string) file_get_contents($path), 0, 4));
        $xml = self::sheetXml($path);
        self::assertStringNotContainsString('<f>', $xml, 'no formula anywhere');
        self::assertStringNotContainsString('<f ', $xml);
        self::assertStringContainsString('=1+1', $xml, 'the text is there, as a string');
        self::assertMatchesRegularExpression('#<c r="B2"[^>]*><v>24</v></c>#', $xml, 'a number cell');
        self::assertMatchesRegularExpression('#<c r="A2"[^>]*t="inlineStr"#', $xml, 'a string cell');

        $rows = XlsxReader::read($path);
        self::assertSame([
            1 => ['code', 'packs', 'price', 'note'],
            2 => ['CW-000001', '24', '1.65', '=1+1'],
            3 => ['5060000000017', '3', '', 'Café £12 ctl'],
            4 => ['', '-2', '0.1234', '+44 113 000'],
            5 => ['@SUM(A1)', '0', '12', '-1+2'],
        ], $rows);
    }

    public function testTheReaderLimits(): void
    {
        $w = new XlsxWriter([['n', 'number']]);
        for ($i = 1; $i <= 2000; $i++) {
            $w->add([$i]);
        }
        $path = $this->file($w->output(), 'rows.xlsx');
        self::assertCount(2001, XlsxReader::read($path, 2001), 'a header and 2,000 rows');
        self::assertSame([413, 'too_many_rows'], self::refused(static fn () => XlsxReader::read($path, 2000)));

        $cols = array_map(static fn (int $i): array => ["c{$i}", 'text'], range(1, 51));
        $path = $this->file((new XlsxWriter($cols))->output(), 'cols.xlsx');
        self::assertSame([400, 'bad_file'], self::refused(static fn () => XlsxReader::read($path)), 'more than 50 columns');

        self::assertSame([400, 'bad_file'], self::refused(fn () => XlsxReader::read($this->file("PK\x03\x04not really a zip", 'bad.xlsx'))));
        $z = new \ZipArchive();
        $p = "{$this->dir}/nosheet.xlsx";
        self::assertTrue($z->open($p, \ZipArchive::CREATE));
        $z->addFromString('hello.txt', 'hi');
        $z->close();
        self::assertSame([400, 'bad_file'], self::refused(static fn () => XlsxReader::read($p)), 'no worksheet');
    }

    public function testTheZipBombGuard(): void
    {
        // A real file whose worksheet claims (in the central directory) to unpack to 60 MiB: refused before it is read.
        $path = $this->file((new XlsxWriter([['a', 'text']]))->add(['x'])->output(), 'bomb.xlsx');
        $bytes = (string) file_get_contents($path);
        $name = 'xl/worksheets/sheet1.xml';
        $at = 0;
        $patched = 0;
        while (($at = strpos($bytes, "PK\x01\x02", $at)) !== false) {
            $nameLen = unpack('v', substr($bytes, $at + 28, 2))[1];
            if (substr($bytes, $at + 46, $nameLen) === $name) {
                $bytes = substr_replace($bytes, pack('V', 62_914_560), $at + 24, 4);
                $patched++;
            }
            $at += 4;
        }
        self::assertSame(1, $patched);
        $bomb = $this->file($bytes, 'bomb.xlsx');
        self::assertSame([413, 'too_large'], self::refused(static fn () => XlsxReader::read($bomb)));

        // A really large worksheet (2 MiB of padding, a few KB compressed) against a 1 MiB limit.
        $z = new \ZipArchive();
        $p = "{$this->dir}/big.xlsx";
        self::assertTrue($z->open($p, \ZipArchive::CREATE));
        $z->addFromString('xl/worksheets/sheet1.xml', '<worksheet>' . str_repeat(' ', 2_097_152) . '</worksheet>');
        $z->close();
        self::assertLessThan(100_000, filesize($p));
        self::assertSame([413, 'too_large'], self::refused(static fn () => XlsxReader::read($p, 2001, 50, 1_048_576)));
    }

    /**
     * Review finding (I83): an 88 KB file whose row 1 held 3,000,000 cells exhausted 256 MB inside openspout (a fatal error,
     * a crash page). The streaming pre-scan refuses a row of more than MAX_CELLS_PER_ROW cells before openspout reads it.
     */
    public function testAVeryWideRowIsRefusedBeforeItIsRead(): void
    {
        $path = $this->file((new XlsxWriter([['a', 'text']]))->add(['x'])->output(), 'wide.xlsx');
        $z = new \ZipArchive();
        self::assertTrue($z->open($path));
        $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData><row r="1">' . str_repeat('<c/>', 3_000_000) . '</row></sheetData></worksheet>');
        $z->close();
        self::assertLessThan(200_000, filesize($path), 'small on disk');
        $before = memory_get_usage();
        try {
            XlsxReader::read($path);
            self::fail('a 3,000,000-cell row was read');
        } catch (CwException $e) {
            self::assertSame([400, 'bad_file'], [$e->httpStatus, $e->errorCode]);
            self::assertStringContainsString('row 1 of the sheet has more than 1,000 cells', $e->getMessage());
        }
        self::assertLessThan(8_388_608, memory_get_usage() - $before, 'nothing large was built');
        // 50 cells with a value pass the scan and are read.
        $ok = $this->file((new XlsxWriter(array_map(static fn (int $i): array => ["c{$i}", 'text'], range(1, 50))))->add(array_map('strval', range(1, 50)))->output(), 'ok.xlsx');
        self::assertCount(2, XlsxReader::read($ok));
    }

    /** @return array{0: int, 1: string} */
    private static function refused(callable $fn): array
    {
        try {
            $fn();
        } catch (CwException $e) {
            return [$e->httpStatus, $e->errorCode];
        }
        self::fail('nothing was refused');
    }
}
