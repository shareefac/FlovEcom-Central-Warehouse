<?php

declare(strict_types=1);

namespace CW\Output;

/**
 * FPDF (setasign/fpdf, MIT; I24) with CW's page footer and the two measurements PdfWriter needs to repeat a table's header
 * after a page break. The footer is "Central Warehouse · generated <UTC> · page n/{nb}", or, given a footer text (a PO's
 * "<legal name> · Company no. · VAT no. · <number>", spec §6.7), that text followed by " · page n/{nb}". Use it through
 * PdfWriter (or PurchaseOrderPdf): text given to FPDF must already be Windows-1252 (PdfWriter::text()).
 */
final class Fpdf extends \FPDF
{
    private readonly string $stamp;

    /** @param string|null $footer UTF-8 footer text (null: CW's own footer) */
    public function __construct(private readonly ?string $footer = null)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->stamp = gmdate('Y-m-d H:i') . ' UTC';
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(110, 110, 110);
        $text = $this->footer === null ? 'Central Warehouse · generated ' . $this->stamp : $this->footer;
        $this->Cell(0, 5, PdfWriter::text($text . ' · page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    /** The y position at which FPDF breaks the page (a row taller than what is left goes to the next page). */
    public function breakAt(): float
    {
        return (float) $this->PageBreakTrigger;
    }

    /** The cell padding on each side, in mm (what a cell's text may not use of its width). */
    public function cellPadding(): float
    {
        return (float) $this->cMargin;
    }

    /** The width between the margins, in mm. */
    public function innerWidth(): float
    {
        return (float) ($this->w - $this->lMargin - $this->rMargin);
    }
}
