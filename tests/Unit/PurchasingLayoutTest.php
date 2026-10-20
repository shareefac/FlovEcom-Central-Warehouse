<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Two layout faults the owner's browser test found on staging (6.3, 19 Oct 2026; seen again after Design v5): the delivery editor ran
 * past the screen (its one grid column grew to the lines table), and the box saying what a claim or charge line is for was 30 px
 * wide. A browser measures these; this holds the rules that fixed them, and that the boxes carry them.
 */
final class PurchasingLayoutTest extends TestCase
{
    private const CSS = __DIR__ . '/../../public/ui/assets/app.css';
    private const VIEWS = __DIR__ . '/../../src/Ui/views';

    public function testTheEditorsColumnNeverGrowsPastThePage(): void
    {
        $css = (string) file_get_contents(self::CSS);
        self::assertMatchesRegularExpression('/^form\.po-editor \{[^}]*grid-template-columns: minmax\(0, 1fr\);[^}]*\}/m', $css);
        foreach (['purchase_order_edit.php', 'receipt_edit.php'] as $view) {
            self::assertStringContainsString('class="po-editor', (string) file_get_contents(self::VIEWS . '/' . $view), $view);
        }
    }

    public function testWhatALineWithoutAnItemIsForIsAWideBox(): void
    {
        self::assertMatchesRegularExpression('/^table\.lines input\.line-what \{[^}]*min-width: min\(14rem, 100%\);[^}]*\}/m', (string) file_get_contents(self::CSS));
        foreach (['supplier_invoice.php', 'supplier_return.php'] as $view) {
            $src = (string) file_get_contents(self::VIEWS . '/' . $view);
            self::assertSame(preg_match_all('/name="d_<\?=/', $src), preg_match_all('/class="line-what" name="d_<\?=/', $src), $view);
            self::assertGreaterThan(0, preg_match_all('/class="line-what"/', $src), $view);
        }
    }
}
