<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The owner's answers of 24 Nov 2026 on the pages (docs/decisions.md EX37, EX38): "Add an expense" has a "This is a credit note" tick (its
 * amounts typed as printed, kept and shown as minus, the list marks it); every regular cost line has its VAT choice ("Includes 20% VAT", the
 * one picked for you, or "No VAT"), the list shows the amount with VAT, its VAT and the cost without VAT, a line's page shares out the cost
 * without VAT, the choice is changed on the line with its history; the real profit takes the regular costs without VAT. Made-up figures.
 */
final class ExpensesScreensOwnerAnswersTest extends KernelUiTestCase
{
    private int $vpg = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
    }

    /** @return list<list<string>> the rows of a table (by class) as their cells' text */
    private static function rows(UiResponse $r, string $class): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query("//table[contains(@class, \"{$class}\")]/tbody/tr") ?: [] as $tr) {
            $row = [];
            foreach ($xp->query('th|td', $tr) ?: [] as $c) {
                $row[] = trim((string) preg_replace('/\s+/u', ' ', (string) $c->textContent));
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function type(string $name, string $kind = 'expense'): string
    {
        return (string) self::$db->value('SELECT id FROM expense_type WHERE kind = ? AND name = ?', [$kind, $name]);
    }

    public function testACreditNoteIsTickedAndShownAsMinus(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/expenses/new');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::EXPENSES['credit'], $page->text());
        self::assertArrayNotHasKey('credit', $page->form('/ui/profit/expenses/new'), 'not ticked for you');
        $fields = ['shop' => (string) $this->vpg, 'supplier' => 'Viking Direct', 'new_supplier' => '1', 'type' => self::type('Stationery and packaging'),
            'invoice_date' => '2026-11-20', 'invoice_no' => 'CN-0042', 'net' => '10.42', 'vat' => '2.08', 'note' => '', 'then' => 'done'];
        $r = $job->postMultipart('/ui/profit/expenses/new', ['credit' => '1'] + $fields + $page->form('/ui/profit/expenses/new'), []);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $id = (int) self::$db->value("SELECT id FROM expense WHERE invoice_no = 'CN-0042'");
        self::assertSame([-1042, -208, -1250], array_map('intval', array_values(self::$db->one('SELECT net_pence, vat_pence, total_pence FROM expense WHERE id = ?', [$id]))));
        $p2 = $job->get('/ui/profit/expenses/new');
        $job->postMultipart('/ui/profit/expenses/new', ['invoice_no' => '58812290', 'net' => '64.20', 'vat' => '12.84', 'invoice_date' => '2026-11-12',
            'new_supplier' => ''] + $fields + $p2->form('/ui/profit/expenses/new'), []);
        $list = $job->get('/ui/profit/expenses', ['month' => '2026-11']);
        $rows = self::rows($list, 'expenses-list');
        self::assertStringContainsString(Words::EXPENSES['credit_chip'], $rows[1][0], 'the newest first: the credit note, marked');
        self::assertSame(['-£10.42', '-£12.50'], [$rows[1][5], $rows[1][6]]);
        self::assertSame(['£64.20', '£77.04'], [$rows[2][5], $rows[2][6]]);
        $tile = trim((string) preg_replace('/\s+/u', ' ', (string) ((new \DOMXPath($list->dom()))->query('//*[contains(@class, "expense-tile")]') ?: null)?->item(0)?->textContent));
        self::assertStringContainsString('£53.78', $tile, 'Vape and Go\'s November: £64.20 - £10.42');
        // Its page says so; its change form has the tick on and the amounts as printed.
        $one = $job->get("/ui/profit/expenses/{$id}");
        self::assertStringContainsString(Words::say('EXPENSES', 'credit_banner', 'Vape and Go'), $one->text());
        $form = $one->form("/ui/profit/expenses/{$id}/change");
        self::assertSame(['1', '10.42', '2.08'], [$form['credit'] ?? null, $form['net'] ?? null, $form['vat'] ?? null]);
        // Sent again unchanged: nothing changes (still a credit note).
        $same = $job->post("/ui/profit/expenses/{$id}/change", $form);
        self::assertSame(303, $same->status, $same->describe());
        self::assertSame([-1042, 1], array_map('intval', array_values(self::$db->one('SELECT net_pence, version FROM expense WHERE id = ?', [$id]))));
        // The tick taken off: an invoice again, with its history.
        unset($form['credit']);
        $job->post("/ui/profit/expenses/{$id}/change", $form);
        self::assertSame(1042, (int) self::$db->value('SELECT net_pence FROM expense WHERE id = ?', [$id]));
        self::assertStringContainsString('-£10.42 → £10.42', self::rows($job->get("/ui/profit/expenses/{$id}"), 'expense-history')[0][2]);
    }

    public function testRegularCostsShowTheirVatAndCountTheCostWithoutIt(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/regular/new');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $form = $page->form('/ui/profit/regular/new');
        self::assertSame('inc', $form['vat'] ?? null, '"Includes 20% VAT" is picked for you');
        self::assertStringContainsString(Words::say('REGULAR', 'vat_inc', '20%'), $page->text());
        self::assertStringContainsString(Words::REGULAR['vat_none'], $page->text());
        $r = $job->post('/ui/profit/regular/new', ['name' => 'Unit 1 rent', 'shop' => (string) $this->vpg, 'type' => self::type('Rent', 'regular'), 'every' => 'week',
            'amount' => '207.69', 'from' => '2026-11-01', 'until' => '', 'note' => ''] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $list = $job->get('/ui/profit/regular');
        $rows = self::rows($list, 'regular-costs');
        $head = array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($list->dom()))->query('//table[contains(@class, "regular-costs")]/thead/tr/th') ?: []));
        self::assertSame([Words::REGULAR['amount'], Words::REGULAR['vat'], Words::REGULAR['cost']], array_slice($head, 3, 3));
        self::assertSame(['Unit 1 rent', '£207.69', '£34.62', '£173.07'], [$rows[1][0], $rows[1][3], $rows[1][4], $rows[1][5]]);
        self::assertSame([Words::EXPENSES['every_week_total'], '£207.69', '£34.62', '£173.07'], [end($rows)[0], end($rows)[3], end($rows)[4], end($rows)[5]]);
        // The line's page: its VAT and cost; a day's share is of the cost without VAT (£173.07 / 7 = about £24.72).
        $id = (int) self::$db->value("SELECT id FROM regular_cost WHERE name = 'Unit 1 rent'");
        $one = $job->get("/ui/profit/regular/{$id}");
        $facts = array_column(self::rows($one, 'regular-facts'), 1, 0);
        self::assertSame(['£207.69', Words::say('REGULAR', 'vat_amount', '£34.62', '20%'), '£173.07'],
            [$facts[Words::REGULAR['amount']] ?? null, $facts[Words::REGULAR['vat']] ?? null, $facts[Words::REGULAR['cost']] ?? null]);
        self::assertStringContainsString('£24.72', self::rows($one, 'regular-share')[0][1]);
        // The real profit of November takes £173.07 x 30 / 7 = £741.73.
        $fin = $this->signIn($this->uiUser('finance'));
        $real = self::rows($fin->get('/ui/profit/real', ['period' => 'choose', 'from' => '2026-11-01', 'to' => '2026-11-30']), 'real-profit');
        self::assertSame('£741.73', array_column($real, 1, 0)[Words::PROFIT['real_regular']] ?? null);
        // "No VAT" chosen on the line: all of it counts, kept in its history.
        $f = $one->form("/ui/profit/regular/{$id}/change");
        self::assertSame('inc', $f['vat'] ?? null);
        $r = $job->post("/ui/profit/regular/{$id}/change", ['vat' => 'none'] + $f);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(0, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$id]));
        $after = $job->get("/ui/profit/regular/{$id}");
        self::assertStringContainsString(Words::EXPENSES['f_vat_rate_bp'] . ': ' . Words::say('REGULAR', 'vat_inc', '20%') . ' → ' . Words::REGULAR['vat_none'],
            self::rows($after, 'expense-history')[0][2]);
        self::assertSame('£207.69', array_column(self::rows($after, 'regular-facts'), 1, 0)[Words::REGULAR['cost']] ?? null);
        self::assertSame('£890.10', array_column(self::rows($fin->get('/ui/profit/real', ['period' => 'choose', 'from' => '2026-11-01', 'to' => '2026-11-30']),
            'real-profit'), 1, 0)[Words::PROFIT['real_regular']] ?? null);
    }
}
