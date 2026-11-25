<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Assets;

/**
 * The look of Add an expense, an expense's Change, Add a regular cost and a regular cost's Change (the owner, 25 Nov 2026: "It's so
 * misaligned, everything"; the approved drawing AddExpense of 24 Nov 2026; docs/decisions.md EX64): what the layout needs is in the markup.
 * ONE card (the shop as buttons in a row, then the fields two by two, then the total, the dollar hint and the note), the invoice file in its
 * own panel beside it on a wide screen and inside the card on a narrow one, Save at the bottom; every field a cell of its row (div.field:
 * its label, its control, its hint UNDER the control, tied to it with aria-describedby), so the boxes of one row line up whatever hints they
 * carry; the reading and keyboard order shop, supplier / type, date / number, amounts and total, note, file, Save; no numbered sections;
 * every new style scoped to these forms (other forms keep theirs). Nothing saved, no field name, check, FormOnce or CSRF changes here.
 */
final class ExpenseLayoutScreensTest extends KernelUiTestCase
{
    private const PDF = "%PDF-1.4\n% made-up test invoice\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private string $dir = '';
    private string|false $prevStore = false;
    private int $vpg = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_exp_layout_' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/store', 0700, true);
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
        self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapebig', 'Vape Big', 'off')");
    }

    protected function tearDown(): void
    {
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private static function type(string $name, string $kind = 'expense'): string
    {
        return (string) self::$db->value('SELECT id FROM expense_type WHERE kind = ? AND name = ?', [$kind, $name]);
    }

    private function pdf(): string
    {
        $path = $this->dir . '/viking.pdf';
        file_put_contents($path, self::PDF);
        return $path;
    }

    private function fields(array $over = []): array
    {
        return $over + ['shop' => (string) $this->vpg, 'supplier' => 'Viking Direct', 'new_supplier' => '1', 'type' => self::type('Stationery and packaging'),
            'invoice_date' => '2026-11-12', 'invoice_no' => '58812290', 'net' => '64.20', 'vat' => '12.84', 'note' => 'labels and tape', 'then' => 'done'];
    }

    private static function form(UiResponse $r, string $action): \DOMElement
    {
        $xp = new \DOMXPath($r->dom());
        $f = ($xp->query('//form[@action="' . $action . '"]') ?: null)?->item(0);
        self::assertInstanceOf(\DOMElement::class, $f, "no form {$action}: " . $r->describe());
        return $f;
    }

    /** @return list<\DOMElement> the element children, hidden inputs left out (they draw nothing) */
    private static function kids(\DOMElement $el): array
    {
        $out = [];
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement && !($c->nodeName === 'input' && $c->getAttribute('type') === 'hidden')) {
                $out[] = $c;
            }
        }
        return $out;
    }

    private static function has(\DOMElement $el, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [], true);
    }

    /** "tag.class1.class2" of an element (its classes in their order) */
    private static function sig(\DOMElement $el): string
    {
        $c = trim($el->getAttribute('class'));
        return $el->nodeName . ($c === '' ? '' : '.' . implode('.', preg_split('/\s+/', $c) ?: []));
    }

    /** @return list<string> the names of a form's controls in document order (the keyboard's order), hidden inputs left out */
    private static function order(\DOMElement $form): array
    {
        $out = [];
        foreach ((new \DOMXPath($form->ownerDocument))->query('.//input[not(@type="hidden")]|.//select|.//textarea|.//button', $form) ?: [] as $c) {
            \assert($c instanceof \DOMElement);
            $out[] = $c->getAttribute('name');
        }
        return $out;
    }

    /**
     * One cell: its label (for = the control's id, holding only words), the control, then nothing, a hint or the slot under it (div.ex-under:
     * the hint and a tick); every hint's id named by the control's aria-describedby. Returns the control's name.
     */
    private static function cell(\DOMElement $field, bool $hint): string
    {
        self::assertTrue(self::has($field, 'field'), self::sig($field));
        $k = self::kids($field);
        self::assertGreaterThanOrEqual(2, count($k), self::sig($field));
        [$label, $control] = $k;
        self::assertSame('label', $label->nodeName);
        self::assertSame(0, (new \DOMXPath($field->ownerDocument))->query('.//*', $label)?->length, 'the label holds its words only (its hint is under the box)');
        self::assertContains($control->nodeName, ['input', 'select', 'textarea']);
        self::assertNotSame('', $control->getAttribute('id'));
        self::assertSame($control->getAttribute('id'), $label->getAttribute('for'), 'the label names its control');
        $rest = array_slice(array_values(array_filter($k, static fn (\DOMElement $e): bool => $e->nodeName !== 'datalist')), 2);
        if (!$hint) {
            self::assertSame([], array_map(self::sig(...), $rest), $control->getAttribute('name') . ': nothing under the box');
            self::assertSame('', $control->getAttribute('aria-describedby'));
            return $control->getAttribute('name');
        }
        self::assertCount(1, $rest, $control->getAttribute('name') . ': one thing under the box');
        $under = $rest[0];
        $p = $under->nodeName === 'p' ? $under : self::kids($under)[0];
        self::assertSame('p.hint', self::sig($p), $control->getAttribute('name'));
        self::assertNotSame('', $p->getAttribute('id'));
        self::assertContains($p->getAttribute('id'), explode(' ', $control->getAttribute('aria-describedby')), $control->getAttribute('name') . ': the hint is read with the box');
        return $control->getAttribute('name');
    }

    /** @param list<bool> $hints which of the row's two cells carry a hint @return list<string> the row's two controls */
    private static function row(\DOMElement $row, array $hints): array
    {
        self::assertSame('div.ex-row', self::sig($row));
        $cells = self::kids($row);
        self::assertCount(2, $cells, 'two cells a row');
        return [self::cell($cells[0], $hints[0]), self::cell($cells[1], $hints[1])];
    }

    /** No two ids alike; every label's for, aria-describedby and list names an element of the page. */
    private static function idsHold(UiResponse $r): void
    {
        $xp = new \DOMXPath($r->dom());
        $ids = [];
        foreach ($xp->query('//*[@id]') ?: [] as $el) {
            \assert($el instanceof \DOMElement);
            $ids[] = $el->getAttribute('id');
        }
        self::assertSame([], array_keys(array_filter(array_count_values($ids), static fn (int $n): bool => $n > 1)), 'ids are unique');
        foreach ($xp->query('//label[@for]|//*[@aria-describedby]|//input[@list]') ?: [] as $el) {
            \assert($el instanceof \DOMElement);
            foreach (array_filter(explode(' ', $el->getAttribute('for') . ' ' . $el->getAttribute('aria-describedby') . ' ' . $el->getAttribute('list'))) as $id) {
                self::assertContains($id, $ids, "{$id} is on the page");
            }
        }
    }

    /**
     * The expense form's card: the shop, then the fields in the drawing's order; the file panel; the "same one" tick when asked (just above
     * Save, at every width); Save.
     */
    private static function expenseCard(\DOMElement $form, bool $new, bool $ask = false): void
    {
        self::assertTrue(self::has($form, 'record') && self::has($form, 'expense-form'), self::sig($form));
        self::assertSame(['div.ex-main', 'div.ex-side', ...($ask ? ['label.choice.same-ok'] : []), 'p.actions'], array_map(self::sig(...), self::kids($form)),
            'the card, the file panel, (the question,) then Save');
        [$main, $side] = self::kids($form);
        $parts = self::kids($main);
        self::assertSame(['fieldset.shop-pick', 'div.ex-fields'], array_map(self::sig(...), $parts));
        self::assertSame('legend', self::kids($parts[0])[0]->nodeName);
        self::assertSame(['label.choice.shop-choice', 'label.choice.shop-choice', 'label.choice.shop-choice'], array_map(self::sig(...), array_slice(self::kids($parts[0]), 1)));
        $f = self::kids($parts[1]);
        self::assertSame(['div.ex-row', 'div.ex-row', 'div.ex-credit', 'div.ex-row', 'p.expense-total', 'p.alert.expense-dollar', 'div.field.ex-wide'],
            array_map(self::sig(...), $f), 'supplier / type, date / number, the credit tick, the amounts, the total, the dollar hint, the note');
        self::assertSame(['supplier', 'type'], self::row($f[0], [true, true]));
        // The supplier's slot under the box: its hint, then "Add it as a new supplier" (no line of its own across the form).
        $under = self::kids(self::kids($f[0])[0])[2];
        self::assertSame('div.ex-under', self::sig($under));
        self::assertSame(['p.hint', 'label.choice.ex-tick'], array_map(self::sig(...), self::kids($under)));
        self::assertSame(['invoice_date', 'invoice_no'], self::row($f[1], [false, true]));
        $credit = self::kids($f[2]);
        self::assertSame(['label.choice.ex-tick', 'p.hint'], array_map(self::sig(...), $credit));
        self::assertSame($credit[1]->getAttribute('id'), ($credit[0]->getElementsByTagName('input')->item(0))?->getAttribute('aria-describedby'), 'the credit hint is read with its tick');
        self::assertSame(['net', 'vat'], self::row($f[3], [false, true]));
        self::assertSame('note', self::cell($f[6], false));
        // The file panel: its title, the label, the box (its hint under it, tied to it), the words that say it is kept.
        $file = self::kids($side);
        self::assertSame(['fieldset.expense-file'], array_map(self::sig(...), $file));
        $in = self::kids($file[0]);
        self::assertSame('legend', $in[0]->nodeName);
        $field = array_values(array_filter($in, static fn (\DOMElement $e): bool => self::has($e, 'field')));
        self::assertCount(1, $field);
        self::assertSame('file', self::cell($field[0], true));
        if ($new) {
            self::assertSame('p.muted', self::sig(end($in)), 'kept with the expense');
        }
    }

    public function testTheAddExpenseFormIsOneCardInTheDrawingsOrder(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/expenses/new');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $form = self::form($page, '/ui/profit/expenses/new');
        self::expenseCard($form, true);
        self::assertSame(['shop', 'shop', 'shop', 'supplier', 'new_supplier', 'type', 'invoice_date', 'invoice_no', 'credit', 'net', 'vat', 'note', 'file', 'then', 'then'],
            self::order($form), 'the reading order is the keyboard\'s: the file before Save');
        self::assertSame(['done', 'another'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'), iterator_to_array($form->getElementsByTagName('button'))),
            'Save expense stays the first button (Enter in a box saves)');
        self::idsHold($page);
        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//form[contains(@class, "expense-form")]/fieldset')?->length, 'no numbered sections (form.record numbers its direct fieldsets)');
        self::assertStringNotContainsString('style=', $page->body);
    }

    public function testARefusalAndTheSameOneQuestionKeepTheirPlaces(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $form = $job->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new');
        // The VAT refused: the error above the form, the VAT box marked in its row (its hint still under it).
        $r = $job->postMultipart('/ui/profit/expenses/new', $this->fields(['vat' => '12.8x']) + $form, []);
        self::assertSame(422, $r->status, $r->describe());
        $f = self::form($r, '/ui/profit/expenses/new');
        self::expenseCard($f, true);
        $xp = new \DOMXPath($r->dom());
        self::assertSame(1, $xp->query('//p[contains(@class, "error")][@role="alert"]/following-sibling::form[@action="/ui/profit/expenses/new"]')?->length, 'the error above the form');
        self::assertSame(['vat'], array_map(static fn (\DOMElement $e): string => $e->getAttribute('name'), iterator_to_array($xp->query('//*[@aria-invalid="true"]') ?: [])));
        self::assertSame('12.8x', $r->form('/ui/profit/expenses/new')['vat'] ?? null, 'what was typed comes back');
        // A supplier not on the list, a credit note and a file chosen: the supplier marked; "choose it again" and "Save without a file" in the file panel.
        $r = $job->postMultipart('/ui/profit/expenses/new', $this->fields(['supplier' => 'Viking Direkt', 'new_supplier' => '', 'credit' => '1']) + $form,
            ['file' => ['path' => $this->pdf(), 'name' => 'viking.pdf']]);
        self::assertSame(422, $r->status, $r->describe());
        $f = self::form($r, '/ui/profit/expenses/new');
        self::expenseCard($f, true);
        $xp = new \DOMXPath($r->dom());
        self::assertSame(['supplier'], array_map(static fn (\DOMElement $e): string => $e->getAttribute('name'), iterator_to_array($xp->query('//*[@aria-invalid="true"]') ?: [])));
        $panel = array_map(self::sig(...), self::kids(self::kids(self::kids($f)[1])[0]));
        self::assertSame(['legend', 'p.note', 'div.field', 'label.choice', 'p.muted'], $panel, 'the file asked again, then the box, then Save without a file');
        self::assertSame('-£77.04', trim((string) $xp->evaluate('string(//output[@data-expense-total])')), 'a credit note\'s total as minus');
        self::assertSame(['shop', 'shop', 'shop', 'supplier', 'new_supplier', 'type', 'invoice_date', 'invoice_no', 'credit', 'net', 'vat', 'note', 'file', 'no_file_ok', 'then', 'then'],
            self::order($f));
        self::idsHold($r);
        // The same invoice again: the list above the form, the tick at the end of the card, just above Save.
        $r = $job->postMultipart('/ui/profit/expenses/new', $this->fields() + $form, ['file' => ['path' => $this->pdf(), 'name' => 'viking.pdf']]);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $same = $job->postMultipart('/ui/profit/expenses/new', $this->fields(['new_supplier' => '']) + $job->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new'), []);
        self::assertSame(409, $same->status, $same->describe());
        $f = self::form($same, '/ui/profit/expenses/new');
        self::expenseCard($f, true, true);
        self::assertSame(1, (new \DOMXPath($same->dom()))->query('//div[contains(@class, "same-list")]/following-sibling::form[@action="/ui/profit/expenses/new"]')?->length);
        self::assertSame(['shop', 'shop', 'shop', 'supplier', 'new_supplier', 'type', 'invoice_date', 'invoice_no', 'credit', 'net', 'vat', 'note', 'file', 'same_ok', 'then', 'then'],
            self::order($f), 'the question just above Save');
        self::idsHold($same);
    }

    public function testTheExpensesChangeFormIsTheSameCard(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $r = $job->postMultipart('/ui/profit/expenses/new', $this->fields() + $job->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new'), []);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $id = (int) self::$db->value('SELECT id FROM expense');
        $one = $job->get("/ui/profit/expenses/{$id}");
        self::assertSame(200, $one->status, $one->describe());
        $f = self::form($one, "/ui/profit/expenses/{$id}/change");
        self::expenseCard($f, false);
        self::assertSame(['shop', 'shop', 'shop', 'supplier', 'new_supplier', 'type', 'invoice_date', 'invoice_no', 'credit', 'net', 'vat', 'note', 'file', ''], self::order($f));
        self::idsHold($one);
        // A refused change opens the form, the box marked in its row.
        $r = $job->postMultipart("/ui/profit/expenses/{$id}/change", ['vat' => '13.0x'] + $one->form("/ui/profit/expenses/{$id}/change"), []);
        self::assertSame(422, $r->status, $r->describe());
        self::expenseCard(self::form($r, "/ui/profit/expenses/{$id}/change"), false);
        self::assertSame(1, (new \DOMXPath($r->dom()))->query('//details[@id="change"][@open]//input[@name="vat"][@aria-invalid="true"]')?->length);
        // Delete keeps its own plain form.
        self::assertSame('form.record', self::sig(self::form($one, "/ui/profit/expenses/{$id}/delete")));
    }

    public function testTheRegularCostFormsAreOneCardToo(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/regular/new');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $form = self::form($page, '/ui/profit/regular/new');
        self::assertSame('form.record.regular-form', self::sig($form));
        self::assertSame(['div.ex-main', 'p.actions'], array_map(self::sig(...), self::kids($form)));
        $parts = self::kids(self::kids($form)[0]);
        self::assertSame(['fieldset.shop-pick', 'div.ex-fields'], array_map(self::sig(...), $parts));
        $f = self::kids($parts[1]);
        self::assertSame(['div.ex-row', 'div.ex-row', 'fieldset.vat-pick', 'div.ex-row', 'div.field.ex-wide'], array_map(self::sig(...), $f));
        self::assertSame(['name', 'type'], self::row($f[0], [false, true]));
        self::assertSame(['every', 'amount'], self::row($f[1], [false, true]));
        self::assertSame(['legend', 'label.choice', 'label.choice'], array_map(self::sig(...), self::kids($f[2])));
        self::assertSame(['from', 'until'], self::row($f[3], [false, true]));
        self::assertSame('note', self::cell($f[4], false));
        self::assertSame(['shop', 'shop', 'shop', 'name', 'type', 'every', 'amount', 'vat', 'vat', 'from', 'until', 'note', ''], self::order($form));
        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//form[contains(@class, "regular-form")]/fieldset')?->length, 'no numbered sections');
        self::idsHold($page);
        self::assertStringNotContainsString('style=', $page->body);
        // Saved, then its page: the Change form is the same card (name / group, until / note, the VAT).
        $rf = ['name' => 'Unit 1 rent', 'shop' => (string) $this->vpg, 'type' => self::type('Rent', 'regular'), 'vat' => 'inc', 'every' => 'week',
            'amount' => '207.69', 'from' => '2026-11-01', 'until' => '', 'note' => ''];
        $r = $job->post('/ui/profit/regular/new', $rf + $page->form('/ui/profit/regular/new'));
        self::assertSame(303, $r->status, $r->describe());
        $rid = (int) self::$db->value("SELECT id FROM regular_cost WHERE name = 'Unit 1 rent'");
        $one = $job->get("/ui/profit/regular/{$rid}");
        self::assertSame(200, $one->status, $one->describe());
        $c = self::form($one, "/ui/profit/regular/{$rid}/change");
        self::assertSame('form.record.regular-form', self::sig($c));
        self::assertSame(['div.ex-main', 'p.actions'], array_map(self::sig(...), self::kids($c)));
        $f = self::kids(self::kids(self::kids($c)[0])[0]);
        self::assertSame(['div.ex-row', 'div.ex-row', 'fieldset.vat-pick'], array_map(self::sig(...), $f));
        self::assertSame(['name', 'type'], self::row($f[0], [false, false]));
        self::assertSame(['until', 'note'], self::row($f[1], [true, false]));
        self::assertSame(['name', 'type', 'until', 'note', 'vat', 'vat', ''], self::order($c));
        self::idsHold($one);
        // The same one again: the tick just above Save.
        $same = $job->post('/ui/profit/regular/new', $rf + $job->get('/ui/profit/regular/new')->form('/ui/profit/regular/new'));
        self::assertSame(409, $same->status, $same->describe());
        $sf = self::form($same, '/ui/profit/regular/new');
        self::assertSame(['div.ex-main', 'label.choice.same-ok', 'p.actions'], array_map(self::sig(...), self::kids($sf)));
        self::assertSame(['shop', 'shop', 'shop', 'name', 'type', 'every', 'amount', 'vat', 'vat', 'from', 'until', 'note', 'same_ok', ''], self::order($sf));
        self::idsHold($same);
    }

    /** Every rule of the expenses' block of app.css is scoped to these forms (or the Expenses list's figures): other forms keep their look. */
    public function testTheNewStylesAreScopedToTheseForms(): void
    {
        $css = (string) file_get_contents(Assets::dir() . '/app.css');
        $start = strpos($css, '/* ==== Expenses and regular costs');
        $end = strpos($css, '/* ==== end expenses');
        self::assertIsInt($start);
        self::assertIsInt($end);
        $block = (string) preg_replace('#/\*.*?\*/#s', '', substr($css, $start, $end - $start));
        preg_match_all('/([^{}]+)\{[^{}]*\}/', (string) preg_replace('/@(container|media|supports)[^{]*\{/', '', $block), $m);
        self::assertGreaterThan(20, count($m[1]));
        foreach ($m[1] as $selectors) {
            // The list's selectors, split at the commas outside brackets (":is(.expense-form, .regular-form)" is one).
            $list = [];
            $depth = 0;
            $cur = '';
            foreach (str_split($selectors) as $ch) {
                $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
                if ($ch === ',' && $depth === 0) {
                    $list[] = $cur;
                    $cur = '';
                    continue;
                }
                $cur .= $ch;
            }
            $list[] = $cur;
            foreach ($list as $s) {
                $s = trim($s);
                if ($s === '') {
                    continue;
                }
                self::assertMatchesRegularExpression('/\.expense-form|\.regular-form|section\.expense-figs/', $s, "app.css: \"{$s}\" is not scoped to the expense or regular-cost forms");
            }
        }
        // What lines the boxes up: a row of two cells (one below 400 px), each cell's label / box / hint on the row's three lines (subgrid).
        self::assertStringContainsString('grid-template-rows: subgrid;', $block);
        self::assertStringContainsString('grid-template-areas: "main side" "ask side" "acts side";', $block, 'the file panel beside the card on a wide screen');
        self::assertStringContainsString('grid-column: auto;', $block, 'the shop buttons in a row, not one line each');
    }
}
