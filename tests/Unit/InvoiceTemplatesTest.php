<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ui\View;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The templates of Purchasing › Invoices and Returns (pack C): cards on a phone (every table a `stack`, every data cell says what it is),
 * every word taken from Words (none typed in the template), every POST form with its CSRF token, and every word group they name exists.
 * Pure: no database.
 */
final class InvoiceTemplatesTest extends TestCase
{
    private const TEMPLATES = ['invoices', 'invoices_to_match', 'invoices_not_invoiced', 'supplier_invoice', 'supplier_returns', 'supplier_return', 'invoice_edit'];

    public function testTheyTurnIntoCardsAndTakeEveryWordFromWords(): void
    {
        foreach (self::TEMPLATES as $name) {
            $src = (string) file_get_contents(View::defaultDir() . '/' . $name . '.php');
            preg_match_all('/<table\b([^>]*)>/i', $src, $tables, PREG_SET_ORDER);
            self::assertNotSame([], $tables, $name);
            foreach ($tables as $t) {
                self::assertMatchesRegularExpression('/class="[^"]*\bstack\b/', $t[1], "{$name}: {$t[0]} is cards on a phone");
            }
            preg_match_all('/<td\b[^>]*>/i', $src, $cells);
            foreach ($cells[0] as $td) {
                self::assertMatchesRegularExpression('/data-label=|class="[^"]*\bc-(status|next|head)\b/', $td, "{$name}: {$td} says what it is on a phone");
            }
            foreach (['(UTC)', '$dt(', '(GBP)', '<svg', '<img', 'data:'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old}");
            }
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $m) > 0 ? $m[0] : [], "{$name}: a word typed in the template instead of taken from Words");
            preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', $src, $forms, PREG_SET_ORDER);
            foreach ($forms as $f) {
                if (preg_match('/method="post"/i', $f[1]) === 1) {
                    self::assertMatchesRegularExpression('#<input type="hidden" name="csrf" value="<\?= \$e\(\$csrf\) \?>">#', $f[2], "{$name}: a POST form without the csrf field");
                }
            }
            preg_match_all("/\\\$(?:word|say)\\('([A-Z_]+)', '([a-z_]+)'/", $src, $calls, PREG_SET_ORDER);
            foreach ($calls as [, $group, $code]) {
                self::assertTrue(Words::has($group, $code), "{$name}: no word {$group} {$code}");
            }
            preg_match_all("/Words::([A-Z_]+)\\['([a-z_]+)'\\]/", $src, $consts, PREG_SET_ORDER);
            foreach ($consts as [, $group, $code]) {
                self::assertTrue(Words::has($group, $code), "{$name}: no word {$group} {$code}");
            }
        }
    }

    /** The words of both kinds of paper have the same keys, and the "how it works" steps are full sentences. */
    public function testTheTwoKindsOfPaperHaveTheSameWords(): void
    {
        self::assertSame(array_keys(Words::INVOICE_KIND['invoice']), array_keys(Words::INVOICE_KIND['credit_note']));
        foreach ([...Words::INVOICE_KIND['invoice']['how'], ...Words::INVOICE_KIND['credit_note']['how'], ...Words::RETURNS['how']] as $step) {
            self::assertMatchesRegularExpression('/^[A-Z"].*\.$/s', $step);
        }
        foreach ([Words::INVOICE_NOTICE, Words::RETURN_NOTICE, Words::INVOICE_ERROR, Words::RETURN_ERROR] as $texts) {
            foreach ($texts as $code => $text) {
                if ($code === 'on_line') {
                    continue;
                }
                self::assertMatchesRegularExpression('/^[A-Z"].*[.:]$/s', $text, "{$code}: a full sentence");
            }
        }
        foreach (\CW\Invoices\InvoiceMath::WHY as $why) {
            self::assertTrue(Words::has('MATCH_WHY', $why), $why);
        }
        foreach ([...\CW\Invoices\InvoiceMath::STATES] as $s) {
            self::assertTrue(Words::has('MATCH_STATE', $s), $s);
        }
        foreach (\CW\Invoices\SupplierInvoiceHandler::LINE_KINDS as $k) {
            self::assertTrue(Words::has('INVOICE_LINE', $k), $k);
        }
    }
}
