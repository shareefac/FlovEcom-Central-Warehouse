<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Tests\Support\UiResponse;
use PHPUnit\Framework\TestCase;

/**
 * The test client reads a form's fields as a browser does (Codex's review of By Item's quick match): a control belongs to the form
 * it names with form="<id>", wherever it is, else to the NEAREST form around it, so a form inside a form (By Item's quick match
 * puts its "Match to …" forms into a cell of the list's bulk form) keeps its fields to itself.
 */
final class UiResponseFormTest extends TestCase
{
    public function testAControlBelongsToTheFormItNamesElseToTheNearestFormAroundIt(): void
    {
        $r = new UiResponse(200, [], '<!doctype html><html><body>'
            . '<form action="/outer" method="post"><input name="o" value="1"><div><form action="/inner" method="post"><input name="i" value="2">'
            . '<select name="s"><option value="a">a</option></select></form></div><input name="o2" value="3"><input name="far" form="f1" value="9"></form>'
            . '<input name="x" form="f1" value="8"><form id="f1" action="/named" method="get"><input type="hidden" name="h" value="7"></form></body></html>');
        self::assertSame(['o' => '1', 'o2' => '3'], $r->form('/outer'), 'not the inner form\'s fields, not the one that names another form');
        self::assertSame(['i' => '2', 's' => 'a'], $r->form('/inner'));
        self::assertSame(['far' => '9', 'x' => '8', 'h' => '7'], $r->form('/named'), 'every control that names it, in the order of the page');
    }
}
