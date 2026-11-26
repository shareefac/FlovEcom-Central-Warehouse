<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Profit\P2gPdfText;

/** The text of the made-up PDFs (P2gFixtures::pdf). */
final class FakePdfText implements P2gPdfText
{
    /** @param array<string, string> $texts pdf bytes => its text */
    public function __construct(public array $texts = [])
    {
    }

    public function text(string $pdf): string
    {
        return $this->texts[$pdf] ?? throw new \CW\CwException('p2g_pdf_text', 'the invoice\'s text could not be read: unknown test PDF', 422);
    }
}
