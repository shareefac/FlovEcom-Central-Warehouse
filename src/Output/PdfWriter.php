<?php

declare(strict_types=1);

namespace CW\Output;

/**
 * Simple, printable PDFs (documents, lists) with FPDF (I24): A4 portrait, 15 mm margins, Helvetica (a PDF core font:
 * nothing embedded, nothing fetched), a title block, headings, key/value blocks, paragraphs and tables whose header
 * repeats after a page break. No HTML, no images, no remote resources: what is drawn is only what the code says.
 *
 * FPDF's core fonts are Windows-1252, so every string goes through text(): UTF-8 in, Windows-1252 out (£, é, ™, €,
 * curly quotes and · survive; other characters become their closest ASCII or "?"), control characters out. A text
 * the PDF must show exactly in another script (Chinese, Greek) is outside what this writer does.
 */
final class PdfWriter
{
    private const MARGIN = 15.0;
    private const ROW_H = 6.0;
    private const ALIGNS = ['L', 'C', 'R'];

    private readonly Fpdf $pdf;

    public function __construct(string $title, string $subtitle = '', bool $compress = true)
    {
        $pdf = new Fpdf();
        $pdf->SetCompression($compress);
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AliasNbPages();
        $pdf->SetCreator('Central Warehouse', true);
        $pdf->SetTitle(self::clean($title), true);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->MultiCell(0, 7, self::text($title), 0, 'L');
        if ($subtitle !== '') {
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->MultiCell(0, 5, self::text($subtitle), 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', '', 9);
        $this->pdf = $pdf;
    }

    public function heading(string $text): self
    {
        $this->pdf->Ln(2);
        $this->pdf->SetFont('Helvetica', 'B', 11);
        $this->pdf->MultiCell(0, 6, self::text($text), 0, 'L');
        $this->pdf->SetFont('Helvetica', '', 9);
        return $this;
    }

    /** Label: value lines (the value wraps). @param array<string, scalar|null> $pairs */
    public function keyValues(array $pairs): self
    {
        foreach ($pairs as $label => $value) {
            $this->pdf->SetFont('Helvetica', 'B', 9);
            $this->pdf->Cell(45, 5, $this->fit(self::text((string) $label), 45));
            $this->pdf->SetFont('Helvetica', '', 9);
            $this->pdf->MultiCell(0, 5, self::text(self::scalar($value)), 0, 'L');
        }
        $this->pdf->Ln(1);
        return $this;
    }

    public function paragraph(string $text): self
    {
        $this->pdf->SetFont('Helvetica', '', 9);
        $this->pdf->MultiCell(0, 5, self::text($text), 0, 'L');
        $this->pdf->Ln(1);
        return $this;
    }

    /**
     * A table: one line per row, each cell cut to its column with "…" when it does not fit; the header is drawn again
     * at the top of every page the table runs onto.
     *
     * @param list<array{title: string, width_mm: int|float, align?: string}> $cols
     * @param list<list<scalar|null>> $rows
     */
    public function table(array $cols, array $rows): self
    {
        foreach ($cols as $c) {
            if (!is_string($c['title'] ?? null) || !is_numeric($c['width_mm'] ?? null) || (float) $c['width_mm'] <= 0
                || !in_array($c['align'] ?? 'L', self::ALIGNS, true)) {
                throw new \InvalidArgumentException('a table column is {title, width_mm > 0, align L|C|R}');
            }
        }
        $pdf = $this->pdf;
        $header = function () use ($pdf, $cols): void {
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->SetFillColor(232, 232, 232);
            foreach ($cols as $c) {
                $pdf->Cell((float) $c['width_mm'], self::ROW_H, $this->fit(self::text($c['title']), (float) $c['width_mm']), 1, 0, 'L', true);
            }
            $pdf->Ln(self::ROW_H);
            $pdf->SetFont('Helvetica', '', 8);
        };
        $header();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('a table row is a list of cells');
            }
            if ($pdf->GetY() + self::ROW_H > $pdf->breakAt()) {
                $pdf->AddPage();
                $header();
            }
            foreach ($cols as $i => $c) {
                $w = (float) $c['width_mm'];
                $pdf->Cell($w, self::ROW_H, $this->fit(self::text(self::scalar($row[$i] ?? null)), $w), 1, 0, $c['align'] ?? 'L');
            }
            $pdf->Ln(self::ROW_H);
        }
        $pdf->Ln(2);
        return $this;
    }

    /** The PDF file's bytes. */
    public function output(): string
    {
        return $this->pdf->Output('S');
    }

    /**
     * UTF-8 to the Windows-1252 bytes FPDF's core fonts print: control characters removed (a tab becomes a space,
     * line feeds stay for paragraphs), invalid UTF-8 repaired, and a character Windows-1252 lacks turned into its
     * closest ASCII or "?" (iconv //TRANSLIT, falling back to mbstring's "?"); never raw UTF-8 bytes.
     */
    public static function text(string $utf8): string
    {
        $s = self::clean($utf8);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        if (!is_string($out)) {
            $out = (string) mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        }
        return $out;
    }

    /** Valid UTF-8 without control characters (C0 except line feed, DEL, C1); a tab becomes a space. */
    private static function clean(string $utf8): string
    {
        $s = str_replace("\t", ' ', mb_scrub($utf8, 'UTF-8'));
        return (string) preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u', '', $s);
    }

    private static function scalar(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'yes' : 'no',
            is_scalar($v) => (string) $v,
            default => throw new \InvalidArgumentException('a PDF cell is a scalar or null, not ' . get_debug_type($v)),
        };
    }

    /** $s (Windows-1252) on one line, cut with "…" (0x85) to fit a cell of $w mm. */
    private function fit(string $s, float $w): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        $room = $w - 2 * $this->pdf->cellPadding();
        if ($this->pdf->GetStringWidth($s) <= $room) {
            return $s;
        }
        $ellipsis = "\x85";
        while ($s !== '' && $this->pdf->GetStringWidth($s . $ellipsis) > $room) {
            $s = substr($s, 0, -1);
        }
        return $s . $ellipsis;
    }
}
