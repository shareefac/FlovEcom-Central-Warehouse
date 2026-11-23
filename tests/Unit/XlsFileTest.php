<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\XlsFile;
use CW\Tests\Support\XlsWriter;
use PHPUnit\Framework\TestCase;

/**
 * The old-Excel reader (BIFF8 in OLE2; docs/decisions.md DL48), from SYNTHETIC files (tests/Support/XlsWriter): every cell kind, the shared
 * strings split across CONTINUE records (compressed, wide, switching between them, rich and extended), the mini stream and the regular one,
 * the FAT named in a DIFAT sector, only the first worksheet, and a file that is not one, or cut short, or whose chain loops: refused whole.
 */
final class XlsFileTest extends TestCase
{
    /** @return array<int, array<int, string|float|int|bool|null>> row => column => value */
    private static function cells(string $bytes): array
    {
        $out = [];
        XlsFile::firstSheet($bytes, static function (int $r, int $c, string|float|int|bool|null $v) use (&$out): void {
            $out[$r][$c] = $v;
        });
        return $out;
    }

    /** @return list<list<mixed>> */
    private static function sheet(): array
    {
        return [
            ['Order number', 'Name with Łódź', 'Ünïcode café'],
            ['plain text', 1.5, 7],
            [['rk' => 12], ['rk' => 3.25], ['rk' => -0.123456789], ['rk' => -5]],
            [['mulrk' => [1, 2.5, -3]]],
            [['label' => 'a label'], ['formula' => 2.5], ['formula' => 'formula text'], ['formula' => true], true, ['error' => 7], null, 'after a gap'],
        ];
    }

    private static function expected(): array
    {
        return [
            0 => [0 => 'Order number', 1 => 'Name with Łódź', 2 => 'Ünïcode café'],
            1 => [0 => 'plain text', 1 => 1.5, 2 => 7.0],
            2 => [0 => 12, 1 => 3.25, 2 => -0.123456789, 3 => -5],
            3 => [0 => 1, 1 => 2.5, 2 => -3],
            4 => [0 => 'a label', 1 => 2.5, 2 => 'formula text', 3 => true, 4 => true, 5 => null, 7 => 'after a gap'],
        ];
    }

    public function testEveryCellKindIsReadBack(): void
    {
        $got = self::cells(XlsWriter::file(self::sheet()));
        $want = self::expected();
        self::assertSame(array_keys($want), array_keys($got));
        foreach ($want as $r => $cols) {
            self::assertSame(array_keys($cols), array_keys($got[$r]), "row {$r}");
            foreach ($cols as $c => $v) {
                if (is_float($v) && $r === 2) {
                    self::assertEqualsWithDelta($v, $got[$r][$c], 1e-6, "row {$r} col {$c} (an RK float keeps 30 bits)");
                } else {
                    self::assertEquals($v, $got[$r][$c], "row {$r} col {$c}");
                }
            }
        }
        self::assertIsInt($got[2][0], 'an RK whole number is an int');
        self::assertIsFloat($got[1][1], 'a NUMBER is a float');
    }

    public function testASmallFileIsInTheMiniStreamAndABigOneInTheRegularOne(): void
    {
        $small = XlsWriter::workbook(self::sheet());
        self::assertLessThan(4096, strlen($small));
        self::assertSame(self::expected()[0], self::cells(XlsWriter::file(self::sheet()))[0], 'from the mini stream');
        $rows = [['a', 'b']];
        for ($i = 1; $i <= 1500; $i++) {
            $rows[] = ['made-up text ' . $i, $i];
        }
        self::assertGreaterThan(4096, strlen(XlsWriter::workbook($rows)));
        $got = self::cells(XlsWriter::file($rows));
        self::assertCount(1501, $got);
        self::assertSame('made-up text 1500', $got[1500][0]);
        self::assertEquals(1500.0, $got[1500][1]);
        $got = self::cells(XlsWriter::file($rows, ['difat_sector' => true]));
        self::assertSame('made-up text 777', $got[777][0], 'the FAT named in a DIFAT sector');
    }

    public function testTheSharedStringsSplitAcrossContinueRecordsReadWhole(): void
    {
        $long = str_repeat('compressed text ', 30);
        $wide = 'wide Ł ' . str_repeat('ąęść ', 25);
        $rows = [[$long, $wide, 'short'], ['x' . $long, 'y' . $wide]];
        foreach ([['max_record' => 40], ['max_record' => 40, 'switch_wide' => true], ['max_record' => 33, 'rich' => true],
            ['max_record' => 8224, 'rich' => true]] as $opt) {
            $wb = XlsWriter::workbook($rows, $opt);
            if ($opt['max_record'] < 100) {
                self::assertGreaterThan(5, substr_count($wb, pack('v', 0x003C)), 'CONTINUE records were written: ' . json_encode($opt));
            }
            $got = self::cells(XlsWriter::file($rows, $opt));
            self::assertSame([$long, $wide, 'short'], array_values($got[0]), json_encode($opt));
            self::assertSame(['x' . $long, 'y' . $wide], array_values($got[1]), json_encode($opt));
        }
    }

    public function testOnlyTheFirstWorksheetIsRead(): void
    {
        $got = self::cells(XlsWriter::file([['first']], ['chart_first' => true, 'second_sheet' => [['second'], ['more']]]));
        self::assertSame([0 => [0 => 'first']], $got, 'a chart before it and a second sheet after it are not read');
    }

    public function testAFileThatIsNotOneOrIsCutShortIsRefusedWhole(): void
    {
        $good = XlsWriter::file([['a', 'b'], ['c', 1]]);
        $big = [['a']];
        for ($i = 1; $i <= 600; $i++) {
            $big[] = ['made-up ' . $i];
        }
        $bigFile = XlsWriter::file($big);
        $cases = [
            'empty' => '',
            'garbage' => random_bytes(3000),
            'a CSV' => "Order number,Tracking number\n1,JV000000001GB\n",
            'the signature only' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 600),
            'cut in the header' => substr($good, 0, 300),
            'cut in the sectors' => substr($bigFile, 0, intdiv(strlen($bigFile), 2)),
            'no workbook stream' => self::renamed($good),
            'a FAT that loops' => self::looped($bigFile),
            'not BIFF8' => XlsWriter::ole(pack('vv', 0x0809, 16) . pack('vvvvVV', 0x0500, 0x0005, 0, 0, 0, 0) . str_repeat(pack('vv', 0x000A, 0), 1)),
            'a worksheet with no end' => XlsWriter::ole(substr(XlsWriter::workbook([['a', 'b']]), 0, -4)),
            'a record longer than the stream' => XlsWriter::ole(substr(XlsWriter::workbook([['a']]), 0, -4) . pack('vv', 0x0203, 200) . 'xx'),
        ];
        foreach ($cases as $why => $bytes) {
            try {
                self::cells($bytes);
                self::fail("{$why}: refused");
            } catch (CwException $e) {
                self::assertSame('bad_file', $e->errorCode, $why);
                self::assertSame(422, $e->httpStatus, $why);
                self::assertSame('Click & Drop report not read: it is not an Excel file CW can read (.xls). Nothing was saved.', $e->getMessage(), $why);
            }
        }
    }

    public function testAFileLargerThanTheLimitIsRefusedUnread(): void
    {
        $this->expectException(CwException::class);
        XlsFile::firstSheet(str_repeat('x', XlsFile::MAX_BYTES + 1), static function (): void {
            self::fail('nothing is read');
        });
    }

    /** The file with its stream's directory name changed (no "Workbook" or "Book" in it). */
    private static function renamed(string $file): string
    {
        $needle = (string) mb_convert_encoding("Workbook\0", 'UTF-16LE', 'UTF-8');
        $at = strpos($file, $needle);
        self::assertIsInt($at);
        return substr_replace($file, (string) mb_convert_encoding("Notebook\0", 'UTF-16LE', 'UTF-8'), $at, strlen($needle));
    }

    /** The file with its stream's first FAT link pointing back at itself. */
    private static function looped(string $file): string
    {
        $fatSector = unpack('V', $file, 76)[1];
        $dirStart = unpack('V', $file, 48)[1];
        // the stream starts at sector 0 (the writer puts it first): its FAT entry made to point at itself
        $at = ($fatSector + 1) * 512;
        self::assertNotSame(0, $dirStart);
        return substr_replace($file, pack('V', 0), $at, 4);
    }
}
