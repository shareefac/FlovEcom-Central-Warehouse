<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Expenses\ExpenseMoney;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Assets;
use CW\Ui\Words;

/**
 * The total with VAT on an expense (the owner, 25 Nov 2026: "When I type in price including VAT, why the non-VAT is not automatically
 * calculated and shown? If it is right, then I don't have to type it again."; docs/decisions.md EX65, amending EX3): Add an expense and an
 * expense's Change have "Total with VAT (£)" and a VAT choice ("Includes 20% VAT", picked for you; "No VAT"; "Different VAT (type it)");
 * the page's script fills "Amount without VAT" and "VAT" from the total (at 20%: without VAT = total / 1.2 to the penny, VAT = the rest), a
 * box typed by hand is never overwritten, and the old way (without VAT + VAT) still works. What is shown is what is saved: the server keeps
 * the two boxes as typed and refuses a total that is not their sum (nothing saved); it never works VAT out itself and needs no script.
 * Every figure here is made up, but for the owner's Amazon invoice (£8.47 = £7.06 + £1.41).
 */
final class ExpenseTotalVatScreensTest extends KernelUiTestCase
{
    private int $vpg = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
        self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapebig', 'Vape Big', 'off')");
    }

    private static function type(string $name): string
    {
        return (string) self::$db->value("SELECT id FROM expense_type WHERE kind = 'expense' AND name = ?", [$name]);
    }

    private function fields(array $over = []): array
    {
        return $over + ['shop' => (string) $this->vpg, 'supplier' => 'Amazon', 'new_supplier' => '1', 'type' => self::type('Stationery and packaging'),
            'invoice_date' => '2026-11-20', 'invoice_no' => '204-1', 'vat_way' => 'inc', 'total' => '8.47', 'net' => '7.06', 'vat' => '1.41', 'note' => '', 'then' => 'done'];
    }

    private function add(KernelBrowser $web, array $over = []): UiResponse
    {
        return $web->postMultipart('/ui/profit/expenses/new', $this->fields($over) + $web->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new'), []);
    }

    /** @return array{0: list<string>, 1: string} the VAT choice's values and the one picked */
    private static function ways(UiResponse $r, string $action): array
    {
        $xp = new \DOMXPath($r->dom());
        $vals = [];
        $picked = '';
        foreach ($xp->query('//form[contains(@action, "' . $action . '")]//input[@name="vat_way"]') ?: [] as $in) {
            \assert($in instanceof \DOMElement);
            $vals[] = $in->getAttribute('value');
            if ($in->hasAttribute('checked')) {
                $picked .= $in->getAttribute('value');
            }
        }
        return [$vals, $picked];
    }

    public function testTheFormOffersTheTotalAndTheVatChoice(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/expenses/new');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = new \DOMXPath($page->dom());
        self::assertSame([['inc', 'none', 'other'], 'inc'], self::ways($page, '/ui/profit/expenses/new'), '"Includes 20% VAT" picked for you');
        $labels = array_map(static fn (\DOMElement $l): string => trim((string) preg_replace('/\s+/u', ' ', $l->textContent)),
            iterator_to_array($xp->query('//fieldset[contains(@class, "ex-vat-way")]/label') ?: []));
        self::assertSame([Words::say('EXPENSES', 'way_inc', '20%'), Words::EXPENSES['way_none'], Words::EXPENSES['way_other']], $labels);
        self::assertStringStartsWith(Words::EXPENSES['vat_way'], trim((string) $xp->evaluate('string(//fieldset[contains(@class, "ex-vat-way")]/legend)')));
        $total = ($xp->query('//input[@name="total"]') ?: null)?->item(0);
        \assert($total instanceof \DOMElement);
        self::assertSame(['ex-total', '', 'decimal'], [$total->getAttribute('id'), $total->getAttribute('value'), $total->getAttribute('inputmode')]);
        self::assertTrue($total->hasAttribute('data-expense-gross'));
        self::assertFalse($total->hasAttribute('required'), 'the total may be left empty (the old way, or no script)');
        self::assertSame(Words::EXPENSES['total_with_vat'], trim((string) $xp->evaluate('string(//label[@for="ex-total"])')));
        self::assertSame('2000', (string) $xp->evaluate('string(//form[@action="/ui/profit/expenses/new"]/@data-vat-bp)'), 'the shops\' rate for the script, in basis points');
        $words = (string) $xp->evaluate('string(//p[@data-expense-adds-up]/@data-words)');
        self::assertSame(Words::EXPENSES['adds_up'], $words);
        self::assertSame(2, substr_count($words, '%s'));
        self::assertSame('', trim((string) $xp->evaluate('string(//p[@data-expense-adds-up])')), 'nothing to say until the boxes and the total disagree');
    }

    public function testATotalThatAddsUpIsSavedAsTheBoxesSay(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $r = $this->add($job);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['706', '141', '847'], array_map('strval', array_values(self::$db->one('SELECT net_pence, vat_pence, total_pence FROM expense'))));
        // A penny's rounding on the invoice (its VAT printed £1.42, typed by hand; the script then makes the rest £7.05): kept as typed.
        $r = $this->add($job, ['invoice_no' => '204-2', 'vat_way' => 'other', 'net' => '7.05', 'vat' => '1.42']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['705', '142'], array_map('strval', array_values(self::$db->one("SELECT net_pence, vat_pence FROM expense WHERE invoice_no = '204-2'"))));
        // No VAT: the total is the amount without VAT.
        $r = $this->add($job, ['invoice_no' => '204-3', 'vat_way' => 'none', 'total' => '12.00', 'net' => '12.00', 'vat' => '0']);
        self::assertSame(303, $r->status, $r->describe());
        // A credit note: everything as printed, without a minus; kept as minus (EX37).
        $r = $this->add($job, ['invoice_no' => 'CN-1', 'credit' => '1', 'total' => '12.50', 'net' => '10.42', 'vat' => '2.08']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['-1042', '-208', '-1250'], array_map('strval', array_values(self::$db->one("SELECT net_pence, vat_pence, total_pence FROM expense WHERE invoice_no = 'CN-1'"))));
    }

    public function testATotalThatIsNotTheSumIsRefusedAndNothingSaved(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $r = $this->add($job, ['vat' => '1.42']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(Words::say('EXPENSES_ERROR', 'total_mismatch', '£8.48', '£8.47'), trim((string) (new \DOMXPath($r->dom()))->evaluate('string(//p[contains(@class, "error")])')));
        self::assertSame(['total'], array_map(static fn (\DOMElement $e): string => $e->getAttribute('name'),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//*[@aria-invalid="true"]') ?: [])));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
        $back = $r->form('/ui/profit/expenses/new');
        self::assertSame(['8.47', '7.06', '1.42'], [$back['total'] ?? null, $back['net'] ?? null, $back['vat'] ?? null], 'what was typed comes back');
        // Not an amount at all.
        $r = $this->add($job, ['total' => '8,4']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::EXPENSES_ERROR['bad_total'], $r->text());
        // The amounts' own refusals come first (a bad VAT is said as before, whatever the total).
        $r = $this->add($job, ['vat' => '1.4x']);
        self::assertSame('bad_vat', $r->errorCode());
        // A credit note's total is typed as printed too: one that is not the sum is refused.
        $r = $this->add($job, ['credit' => '1', 'total' => '12.51', 'net' => '10.42', 'vat' => '2.08']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
    }

    public function testTheOldWayAndAFormWithoutTheScriptStillWork(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        // The total left empty: without VAT + VAT as today.
        $r = $this->add($job, ['total' => '', 'net' => '64.20', 'vat' => '12.84']);
        self::assertSame(303, $r->status, $r->describe());
        // A form sent with no total and no VAT choice at all (an old page, a script that never ran).
        $f = $this->fields(['invoice_no' => '204-9', 'net' => '1.00', 'vat' => '0.20']);
        unset($f['total'], $f['vat_way']);
        $page = $job->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new');
        unset($page['total'], $page['vat_way']);
        $r = $job->postMultipart('/ui/profit/expenses/new', $f + $page, []);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
        // A VAT choice the form never offers: refused nowhere (it only steers the script), shown as the one picked for you.
        $r = $this->add($job, ['invoice_no' => '204-10', 'vat_way' => 'bogus', 'net' => '', 'total' => '']);
        self::assertSame('bad_net', $r->errorCode());
        self::assertSame([['inc', 'none', 'other'], 'inc'], self::ways($r, '/ui/profit/expenses/new'));
    }

    public function testTheChangeFormShowsTheTotalAndTheChoiceItsFiguresMatch(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        self::assertSame(303, $this->add($job, ['invoice_no' => 'A', 'total' => '77.04', 'net' => '64.20', 'vat' => '12.84'])->status);
        self::assertSame(303, $this->add($job, ['invoice_no' => 'B', 'vat_way' => 'none', 'total' => '12.00', 'net' => '12.00', 'vat' => '0'])->status);
        self::assertSame(303, $this->add($job, ['invoice_no' => 'C', 'vat_way' => 'other', 'net' => '7.05', 'vat' => '1.42'])->status);
        self::assertSame(303, $this->add($job, ['invoice_no' => 'D', 'credit' => '1', 'total' => '12.50', 'net' => '10.42', 'vat' => '2.08'])->status);
        foreach (['A' => ['77.04', 'inc'], 'B' => ['12.00', 'none'], 'C' => ['8.47', 'other'], 'D' => ['12.50', 'inc']] as $no => [$total, $way]) {
            $id = (int) self::$db->value('SELECT id FROM expense WHERE invoice_no = ?', [$no]);
            $one = $job->get("/ui/profit/expenses/{$id}");
            self::assertSame(200, $one->status, $one->describe());
            self::assertSame($total, $one->form("/ui/profit/expenses/{$id}/change")['total'] ?? null, "{$no}: its total as printed");
            self::assertSame($way, self::ways($one, "/ui/profit/expenses/{$id}/change")[1], "{$no}: the VAT choice its figures match");
        }
        // A change whose total is not the sum: refused, nothing changed.
        $id = (int) self::$db->value("SELECT id FROM expense WHERE invoice_no = 'A'");
        $f = $job->get("/ui/profit/expenses/{$id}")->form("/ui/profit/expenses/{$id}/change");
        $r = $job->postMultipart("/ui/profit/expenses/{$id}/change", ['total' => '77.05'] + $f, []);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame('7704', (string) self::$db->value('SELECT total_pence FROM expense WHERE id = ?', [$id]));
        $r = $job->postMultipart("/ui/profit/expenses/{$id}/change", ['total' => '78.04', 'net' => '65.03', 'vat' => '13.01', 'vat_way' => 'inc'] + $f, []);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['6503', '1301'], array_map('strval', array_values(self::$db->one('SELECT net_pence, vat_pence FROM expense WHERE id = ?', [$id]))));
    }

    /**
     * The page's script (app.js; no JavaScript engine on the build or test machines, so its text is pinned and its sums are checked against
     * the same rule in PHP): at the rate in basis points, without VAT = the total x 10000 / (10000 + rate) to the penny, half up, in whole pence;
     * VAT = the total - that, so the two always add up to the total. A box typed by hand is never overwritten by the total; typing the VAT
     * by hand picks "Different VAT"; picking "Includes" or "No VAT" again works both boxes out afresh.
     */
    public function testTheScriptFillsTheBoxesByTheRule(): void
    {
        foreach ([[847, 2000, 706], [7704, 2000, 6420], [1, 2000, 1], [3, 2000, 3], [9, 2000, 8], [1250, 2000, 1042], [999999999, 2000, 833333333], [1050, 500, 1000], [100, 0, 100]] as [$t, $bp, $net]) {
            self::assertSame($net, ExpenseMoney::netOfTotal($t, $bp), "{$t} at {$bp}");
            self::assertSame($net, intdiv(2 * $t * 10000 + (10000 + $bp), 2 * (10000 + $bp)), 'the script\'s own sum');
        }
        $js = (string) file_get_contents(Assets::dir() . '/app.js');
        $start = strpos($js, '// An expense\'s total as it is typed');
        self::assertIsInt($start);
        $part = substr($js, $start);
        self::assertStringContainsString('Math.floor((2 * t * 10000 + (10000 + bp)) / (2 * (10000 + bp)))', $part, 'the same sum, in whole pence');
        foreach (["querySelector('input[data-expense-gross]')", "querySelectorAll('input[name=\"vat_way\"]')", "getAttribute('data-vat-bp')",
            'hand.net', 'hand.vat', "setWay('other')", '[data-expense-adds-up]'] as $piece) {
            self::assertStringContainsString($piece, $part, $piece);
        }
        // Programmatic values raise no input event: only the person's own typing marks a box as typed by hand.
        self::assertStringNotContainsString("dispatchEvent(new Event('input'", $part);
    }
}
