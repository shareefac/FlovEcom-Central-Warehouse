<?php

declare(strict_types=1);

namespace CW\Profit;

/**
 * The text of a Parcel2Go invoice PDF, line by line as laid out (docs/decisions.md PGL7; D5: behind this one interface, so the tool that
 * reads the PDF is chosen after the probe without touching the reader). The real one is PdftotextText; the tests give the text themselves.
 * A PDF it cannot read throws CwException p2g_pdf_text (CW's own words: never the PDF's content).
 */
interface P2gPdfText
{
    public function text(string $pdf): string;
}
