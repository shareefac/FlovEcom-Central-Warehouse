<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Output\PdfWriter;
use PHPUnit\Framework\TestCase;

/**
 * I24: the PDF writer on FPDF (core fonts, Windows-1252): a well-formed file, the title on the page, tables that run
 * over pages with their header repeated, and the UTF-8 to Windows-1252 conversion that never lets raw UTF-8 bytes or
 * control characters into the page.
 */
final class PdfWriterTest extends TestCase
{
    public function testAWellFormedPdfWithItsTitle(): void
    {
        $pdf = (new PdfWriter('Stock adjustment ADJ-000001', 'Status: posted', false))
            ->heading('Header')
            ->keyValues(['Warehouse' => 'MAIN', 'Note' => 'Café £12.50', 'Lines' => 2, 'Missing' => null])
            ->paragraph("A paragraph\nover two lines.")
            ->output();
        self::assertStringStartsWith('%PDF-1.', $pdf);
        self::assertSame('%%EOF', substr(rtrim($pdf), -5));
        self::assertStringContainsString('(Stock adjustment ADJ-000001) Tj', $pdf, 'the title is drawn on the page (uncompressed)');
        self::assertStringContainsString("(Caf\xE9 \xA312.50) Tj", $pdf, 'Windows-1252 text in the content stream');
        self::assertStringContainsString('/Creator', $pdf);
        self::assertStringContainsString('Central Warehouse', $pdf);
        self::assertSame(1, self::pages($pdf));
        self::assertStringContainsString('page 1/1', $pdf, 'the footer with the {nb} alias resolved');
        self::assertStringNotContainsString('{nb}', $pdf);

        $compressed = (new PdfWriter('Stock adjustment ADJ-000001'))->output();
        self::assertStringStartsWith('%PDF-1.', $compressed);
        self::assertStringContainsString('/FlateDecode', $compressed);
        self::assertStringNotContainsString('(Stock adjustment ADJ-000001) Tj', $compressed);
    }

    public function testALongTableRunsOverPagesWithItsHeaderRepeated(): void
    {
        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = [$i, "CW-{$i}", str_repeat('Long item name ', 8) . $i, $i % 2 === 0 ? -$i : $i, null, true];
        }
        $pdf = (new PdfWriter('Long list', '', false))->table([
            ['title' => 'Line', 'width_mm' => 20, 'align' => 'R'],
            ['title' => 'Item', 'width_mm' => 25],
            ['title' => 'Name', 'width_mm' => 60],
            ['title' => 'Qty', 'width_mm' => 20, 'align' => 'R'],
            ['title' => 'Cost', 'width_mm' => 20, 'align' => 'R'],
            ['title' => 'Checked', 'width_mm' => 20, 'align' => 'C'],
        ], $rows)->output();
        $pages = self::pages($pdf);
        self::assertGreaterThanOrEqual(2, $pages);
        self::assertSame($pages, substr_count($pdf, '(Line) Tj'), 'the header row on every page');
        self::assertStringContainsString('(CW-120) Tj', $pdf);
        self::assertStringContainsString("\x85) Tj", $pdf, 'a cell too long for its column is cut with an ellipsis');
        self::assertStringNotContainsString(str_repeat('Long item name ', 8), $pdf);
        self::assertStringContainsString('(yes) Tj', $pdf);
        self::assertStringContainsString("page 2/{$pages}", $pdf);
    }

    public function testTextIsWindows1252WithoutControlCharactersOrRawUtf8(): void
    {
        self::assertSame("\xA312.50 Caf\xE9 \x99", PdfWriter::text('£12.50 Café ™'));
        self::assertSame("\x80 \x93quoted\x94 \xB7", PdfWriter::text("€ \u{201C}quoted\u{201D} ·"));
        self::assertSame('abcd', PdfWriter::text("a\x01b\x7Fc\u{0085}d"), 'C0, DEL and C1 control characters removed');
        self::assertSame("one\ntwo three", PdfWriter::text("one\r\ntwo\tthree"), 'line feeds stay, a tab is a space, CR goes');
        $odd = PdfWriter::text('中文 Łódź ☃');
        self::assertMatchesRegularExpression('/^[\x0A\x20-\x7E\x80-\xFF]*$/', $odd);
        self::assertStringNotContainsString("\xE4\xB8\xAD", $odd, 'never raw UTF-8');
        self::assertStringStartsWith('?? ', $odd, 'a character Windows-1252 lacks becomes ? (or its ASCII look-alike)');
        self::assertStringContainsString("\xF3d", $odd, 'ó is in Windows-1252');
        self::assertSame('ok?', PdfWriter::text("ok\xC3"), 'invalid UTF-8 is repaired, not passed through');
    }

    public function testATableColumnMustBeDescribed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PdfWriter('x'))->table([['title' => 'A', 'width_mm' => 0]], []);
    }

    private static function pages(string $pdf): int
    {
        return preg_match_all('#/Type /Page\b(?!s)#', $pdf);
    }
}
