<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Profit > Expenses, Profit > Regular costs, Profit > Real profit and Settings > Expense types through the real /ui kernel as cw_app
 * (CW-1150; docs/decisions.md EX1-; the approved drawings Main, AddExpense, MonthlyCosts and ProfitMonth of 24 Nov 2026). Only the Expenses
 * job adds, changes and deletes (403 for every other job; CSRF on every POST); Finance looks (and sees the real profit); the shop is picked
 * every time (no default); the invoice file is downloaded only by those who may see the expenses, through the expense's own address, as an
 * attachment of its sniffed type, never from the general file address; the regular costs' list and a new amount; the real profit per shop.
 * Every file here is made up.
 */
final class ExpensesScreensTest extends KernelUiTestCase
{
    private const PDF = "%PDF-1.4\n% made-up test invoice\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private string $dir = '';
    private string|false $prevStore = false;
    /** @var array{vpg: int, ef: int, vb: int} */
    private array $shops = ['vpg' => 0, 'ef' => 0, 'vb' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_exp_ui_' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/store', 0700, true);
        // Files go to a temporary file store, never to the server's own (docs/dev.md).
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
        $this->shops['vpg'] = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        $this->shops['ef'] = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
        $this->shops['vb'] = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapebig', 'Vape Big', 'off')");
    }

    protected function tearDown(): void
    {
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
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

    /** Every data cell of every table with a head names its column (the phone's cards); no inline style. */
    private static function phoneReady(UiResponse $r): void
    {
        $xp = new \DOMXPath($r->dom());
        foreach ($xp->query('//table[contains(@class, "rp-table")]') ?: [] as $t) {
            self::assertStringContainsString('stack', (string) $t->attributes?->getNamedItem('class')?->nodeValue);
            foreach ($xp->query('.//thead/following-sibling::tbody/tr/td', $t) ?: [] as $td) {
                self::assertNotSame('', (string) $td->attributes?->getNamedItem('data-label')?->nodeValue, 'each cell names its column on a phone');
            }
        }
        self::assertStringNotContainsString('style=', $r->body);
    }

    /** @return list<array{value: string, label: string, checked: bool}> the shop choices of a form (radios inside their labels) */
    private static function shopChoices(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//input[@type="radio"][@name="shop"]') ?: [] as $in) {
            \assert($in instanceof \DOMElement);
            $label = ($xp->query('ancestor::label[1]', $in) ?: null)?->item(0);
            $out[] = ['value' => $in->getAttribute('value'), 'label' => trim((string) preg_replace('/\s+/u', ' ', (string) $label?->textContent)),
                'checked' => $in->hasAttribute('checked')];
        }
        return $out;
    }

    private function pdf(string $name = 'viking.pdf', string $bytes = self::PDF): string
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(3)) . '-' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    private static function type(string $name, string $kind = 'expense'): string
    {
        return (string) self::$db->value('SELECT id FROM expense_type WHERE kind = ? AND name = ?', [$kind, $name]);
    }

    /** The add form filled in as a person would, and sent. */
    private function addExpense(KernelBrowser $web, array $fields, ?string $file = null): UiResponse
    {
        $page = $web->get('/ui/profit/expenses/new');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $form = $fields + $page->form('/ui/profit/expenses/new');
        return $web->postMultipart('/ui/profit/expenses/new', $form, $file === null ? [] : ['file' => ['path' => $file, 'name' => basename($file)]]);
    }

    private function fields(array $over = []): array
    {
        return $over + ['shop' => (string) $this->shops['vpg'], 'supplier' => 'Viking Direct', 'new_supplier' => '1', 'type' => self::type('Stationery and packaging'),
            'invoice_date' => '2026-11-12', 'invoice_no' => '58812290', 'net' => '64.20', 'vat' => '12.84', 'note' => 'labels and tape', 'then' => 'done'];
    }

    public function testWhoSeesWhatAndOnlyTheJobWrites(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $fin = $this->signIn($this->uiUser('finance'));
        $list = $job->get('/ui/profit/expenses');
        self::assertSame(200, $list->status, $list->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame([Words::SECTION['profit'], Words::MENU['profit_expenses']], [self::currentSection($list), self::currentTab($list)]);
        self::assertSame([Words::MENU['profit_expenses'], Words::MENU['profit_regular']], array_column(self::sectionTabs($list), 'label'),
            'the job sees the expenses and the regular costs, never the profit');
        self::assertContains('/ui/profit/expenses/new', $list->hrefs());
        self::assertSame(403, $job->get('/ui/profit')->status);
        self::assertSame(403, $job->get('/ui/profit/real')->status);
        $finList = $fin->get('/ui/profit/expenses');
        self::assertSame(200, $finList->status);
        self::assertNotContains('/ui/profit/expenses/new', $finList->hrefs(), 'Finance looks only');
        self::assertSame(403, $fin->get('/ui/profit/expenses/new')->status);
        self::assertContains(Words::MENU['profit_real'], array_column(self::sectionTabs($finList), 'label'));
        self::assertSame(200, $fin->get('/ui/profit/regular')->status);
        self::assertSame(200, $fin->get('/ui/profit/real')->status);
        foreach (['reviewer', 'accountant', 'auditor', 'admin', 'buyer', 'delivery_bills', 'purchasing_desk'] as $other) {
            $web = $this->signIn($this->uiUser($other));
            self::assertSame(403, $web->get('/ui/profit/expenses')->status, $other);
            self::assertSame(403, $web->get('/ui/profit/regular')->status, $other);
        }
        // Finance's POST is refused; no CSRF token: refused; nothing stored.
        $r = $fin->postMultipart('/ui/profit/expenses/new', ['csrf' => $this->token($fin)] + $this->fields(), ['file' => ['path' => $this->pdf(), 'name' => 'v.pdf']]);
        self::assertSame(403, $r->status);
        $r = $job->postMultipart('/ui/profit/expenses/new', $this->fields(), []);
        self::assertSame(403, $r->status);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
    }

    public function testAnExpenseIsAddedWithItsShopPickedEachTimeAndItsPdf(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/expenses/new');
        self::phoneReady($page);
        self::assertSame(['Vape and Go', 'Electrofag', 'Vape Big'], array_column(self::shopChoices($page), 'label'));
        self::assertSame([false, false, false], array_column(self::shopChoices($page), 'checked'), 'no shop picked for you');
        self::assertStringContainsString(Words::EXPENSES['dollar_hint'], $page->text());
        // No shop: refused, what was typed kept.
        $r = $this->addExpense($job, ['shop' => ''] + $this->fields());
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::EXPENSES_ERROR['choose_shop'], $r->text());
        self::assertSame('58812290', $r->form('/ui/profit/expenses/new')['invoice_no'] ?? null, 'typed values kept');
        // Saved: lands on the expense with its PDF.
        $r = $this->addExpense($job, $this->fields(), $this->pdf());
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $id = (int) self::$db->value('SELECT id FROM expense');
        self::assertSame("/ui/profit/expenses/{$id}?notice=added", $r->location());
        $one = $job->follow($r);
        self::assertSame(200, $one->status, $one->describe());
        self::phoneReady($one);
        self::assertStringContainsString('£77.04', $one->text(), 'the total, net + VAT');
        self::assertContains("/ui/profit/expenses/{$id}/file", $one->hrefs());
        // "Save and add another": back on an empty form, the shop NOT kept (picked each time).
        $r = $this->addExpense($job, $this->fields(['invoice_no' => '58812291', 'new_supplier' => '', 'then' => 'another']));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/profit/expenses/new?notice=added', $r->location());
        $again = $job->follow($r);
        self::assertSame([false, false, false], array_column(self::shopChoices($again), 'checked'), 'the shop is picked again each time');
        self::assertStringContainsString(Words::EXPENSES['notice_added'], $again->text());
        // The same invoice again: a warning; sent again with the box ticked, kept.
        $same = $this->addExpense($job, $this->fields(['new_supplier' => '']));
        self::assertSame(409, $same->status);
        self::assertStringContainsString(Words::EXPENSES_ERROR['same_invoice_lead'], $same->text());
        $ok = $job->postMultipart('/ui/profit/expenses/new', ['same_ok' => '1'] + $same->form('/ui/profit/expenses/new'), []);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
        // A supplier not on the list and "add it" not ticked: asked, nothing stored.
        $r = $this->addExpense($job, $this->fields(['supplier' => 'Viking Direkt', 'new_supplier' => '', 'invoice_no' => 'Z9']));
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::EXPENSES_ERROR['unknown_supplier'], $r->text());
        // A text file named .pdf: refused in words.
        $r = $this->addExpense($job, $this->fields(['invoice_no' => 'Z10']), $this->pdf('fake.pdf', "not a pdf\n"));
        self::assertSame(415, $r->status);
        self::assertStringContainsString(Words::EXPENSES_ERROR['file_type'], $r->text());
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
    }

    public function testThePdfIsServedOnlyToThoseWhoSeeTheExpenses(): void
    {
        $jobUser = $this->uiUser('expenses');
        $job = $this->signIn($jobUser);
        $r = $this->addExpense($job, $this->fields(), $this->pdf());
        self::assertSame(303, $r->status, $r->describe());
        $id = (int) self::$db->value('SELECT id FROM expense');
        $fileId = (int) self::$db->value('SELECT file_id FROM expense');
        foreach ([$job, $this->signIn($this->uiUser('finance'))] as $web) {
            $f = $web->get("/ui/profit/expenses/{$id}/file");
            self::assertSame(200, $f->status, $f->describe());
            self::assertSame(self::PDF, $f->body);
            self::assertStringStartsWith('application/pdf', (string) $f->header('content-type'));
            self::assertStringStartsWith('attachment; filename="', (string) $f->header('content-disposition'));
            self::assertStringContainsString('.pdf', (string) $f->header('content-disposition'));
            self::assertContains('sandbox', $f->headerValues('content-security-policy'));
            self::assertSame('nosniff', $f->header('x-content-type-options'));
        }
        // Not through the general file address for someone who may not see the expenses (a buyer reads documents, not expenses).
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(403, $buyer->get("/ui/files/{$fileId}")->status);
        self::assertSame(403, $buyer->get("/ui/profit/expenses/{$id}/file")->status);
        // An expense with no file: 404; a deleted expense's file still opens for those who see it (it is kept).
        self::assertSame(404, $job->get('/ui/profit/expenses/999999/file')->status);
    }

    public function testChangeAndDeleteOnTheExpensesPageWithItsHistory(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $this->addExpense($job, $this->fields());
        $id = (int) self::$db->value('SELECT id FROM expense');
        $page = $job->get("/ui/profit/expenses/{$id}");
        $form = $page->form("/ui/profit/expenses/{$id}/change");
        self::assertSame(['64.20', '12.84', '1'], [$form['net'] ?? null, $form['vat'] ?? null, $form['seen'] ?? null]);
        $r = $job->post("/ui/profit/expenses/{$id}/change", ['net' => '65.00', 'vat' => '13.00', 'shop' => (string) $this->shops['ef']] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $after = $job->follow($r);
        self::assertStringContainsString(Words::EXPENSES['notice_changed'], $after->text());
        $hist = self::rows($after, 'expense-history');
        self::assertCount(2, $hist);
        self::assertStringContainsString('£64.20 → £65.00', $hist[0][2], 'old -> new');
        self::assertStringContainsString('Vape and Go → Electrofag', $hist[0][2]);
        // The delete needs its box and a reason.
        $del = $after->form("/ui/profit/expenses/{$id}/delete");
        self::assertSame(422, $job->post("/ui/profit/expenses/{$id}/delete", ['reason' => 'entered twice'] + $del)->status, 'the box not ticked');
        $r = $job->post("/ui/profit/expenses/{$id}/delete", ['reason' => 'entered twice', 'sure' => '1'] + $del);
        self::assertSame(303, $r->status, $r->describe());
        self::assertNotNull(self::$db->value('SELECT deleted_at FROM expense WHERE id = ?', [$id]));
        $gone = $job->follow($r);
        self::assertStringContainsString(Words::EXPENSES['deleted_banner'], $gone->text());
        self::assertFalse($gone->hasForm("/ui/profit/expenses/{$id}/change"), 'a deleted expense is not changed');
        self::assertSame([], self::rows($job->get('/ui/profit/expenses', ['month' => '2026-11']), 'expenses-list'), 'out of the list');
    }

    public function testTheListByMonthAndShopWithItsTotalsAndRegularCostsLine(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $this->addExpense($job, $this->fields(['supplier' => 'Tabsyst', 'type' => self::type('Developer'), 'invoice_no' => 'INV-2041', 'invoice_date' => '2026-11-20',
            'net' => '750.00', 'vat' => '150.00']), $this->pdf());
        $this->addExpense($job, $this->fields(['supplier' => 'Amazon', 'invoice_no' => '026-4471', 'invoice_date' => '2026-11-15', 'net' => '39.00', 'vat' => '7.80',
            'shop' => (string) $this->shops['vb']]));
        $this->addExpense($job, $this->fields(['supplier' => 'Amazon', 'invoice_no' => 'OCT-1', 'invoice_date' => '2026-10-15', 'net' => '5.00', 'vat' => '1.00']));
        (new \CW\Expenses\RegularCosts(self::$db))->add(\CW\Caller::system('test'), ['name' => 'Unit 1 rent', 'shop' => $this->shops['vpg'],
            'type' => (int) self::type('Rent', 'regular'), 'every' => 'week', 'amount' => '207.69', 'from' => '2026-11-01']);
        $list = $job->get('/ui/profit/expenses', ['month' => '2026-11']);
        self::assertSame(200, $list->status, $list->describe() . ' ' . implode(' | ', self::$log));
        self::phoneReady($list);
        $rows = self::rows($list, 'expenses-list');
        self::assertSame(['Vape and Go', '20 Nov 2026', 'Tabsyst', 'Developer', 'INV-2041', '£750.00', '£900.00', Words::EXPENSES['pdf']],
            [$rows[0][0], $rows[1][0], $rows[1][1], $rows[1][2], $rows[1][3], $rows[1][5], $rows[1][6], $rows[1][7]]);
        self::assertSame([Words::EXPENSES['regular_row'], '£890.10'], [$rows[2][1], $rows[2][5]], 'the month\'s regular costs on their own line');
        self::assertSame(['Vape Big', 'Amazon', '£39.00'], [$rows[3][0], $rows[4][1], $rows[4][5]]);
        $tiles = array_map(static fn (\DOMNode $n): string => trim((string) preg_replace('/\s+/u', ' ', (string) $n->textContent)),
            iterator_to_array((new \DOMXPath($list->dom()))->query('//*[contains(@class, "expense-tile")]') ?: []));
        self::assertCount(3, $tiles);
        self::assertStringContainsString('£750.00', $tiles[0]);
        self::assertStringContainsString('£0.00', $tiles[1]);
        self::assertStringContainsString('£39.00', $tiles[2]);
        // One shop; a search by supplier or number.
        self::assertSame(['Vape Big'], array_values(array_filter(array_column(self::rows($job->get('/ui/profit/expenses', ['month' => '2026-11',
            'shop' => $this->shops['vb']]), 'expenses-list'), 0), static fn (string $c): bool => $c === 'Vape Big' || $c === 'Vape and Go')));
        $found = self::rows($job->get('/ui/profit/expenses', ['month' => '2026-11', 'q' => 'tabs']), 'expenses-list');
        self::assertSame('INV-2041', $found[1][3]);
        self::assertSame('£5.00', self::rows($job->get('/ui/profit/expenses', ['month' => '2026-10']), 'expenses-list')[1][5]);
    }

    public function testRegularCostsListANewLineAndANewAmount(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/regular/new');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $form = $page->form('/ui/profit/regular/new');
        $r = $job->post('/ui/profit/regular/new', ['name' => 'Unit 1 rent', 'shop' => (string) $this->shops['vpg'], 'type' => self::type('Rent', 'regular'),
            'every' => 'week', 'amount' => '207.69', 'from' => '2026-11-01', 'until' => '', 'note' => ''] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $job->post('/ui/profit/regular/new', ['name' => 'Cloudflare', 'shop' => (string) $this->shops['vpg'], 'type' => self::type('Running the business', 'regular'),
            'every' => 'year', 'amount' => '240.00', 'from' => '2026-11-01'] + $form);
        $list = $job->get('/ui/profit/regular');
        self::phoneReady($list);
        $rows = self::rows($list, 'regular-costs');
        self::assertSame(['Rent'], $rows[0]);
        self::assertSame(['Unit 1 rent', 'Vape and Go', Words::EXPENSES['every_week'], '£207.69', '1 Nov 2026', Words::EXPENSES['no_end']], array_slice($rows[1], 0, 6));
        self::assertSame([Words::EXPENSES['every_week_total'], '£207.69'], [end($rows)[0], end($rows)[3]]);
        // A new amount from 11 Nov: the line's page offers it; the old line ends on 10 Nov.
        $id = (int) self::$db->value("SELECT id FROM regular_cost WHERE name = 'Unit 1 rent'");
        $one = $job->get("/ui/profit/regular/{$id}");
        self::phoneReady($one);
        $f = $one->form("/ui/profit/regular/{$id}/amount");
        $r = $job->post("/ui/profit/regular/{$id}/amount", ['amount' => '210.00', 'from' => '2026-11-11'] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('2026-11-10', self::$db->value('SELECT until_day FROM regular_cost WHERE id = ?', [$id]));
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM regular_cost WHERE name = 'Unit 1 rent'"));
        $rows = self::rows($job->get('/ui/profit/regular'), 'regular-costs');
        self::assertSame([Words::EXPENSES['every_week_total'], '£210.00'], [end($rows)[0], end($rows)[3]], 'in force today: the new amount');
        // Finance looks: no forms.
        $fin = $this->signIn($this->uiUser('finance'));
        self::assertFalse($fin->get("/ui/profit/regular/{$id}")->hasForm("/ui/profit/regular/{$id}/amount"));
        self::assertSame(403, $fin->post("/ui/profit/regular/{$id}/delete", ['csrf' => $this->token($fin), 'reason' => 'no', 'sure' => '1', 'seen' => '2'])->status);
    }

    public function testExpenseTypesAreASettingWithTheirHistory(): void
    {
        $reviewer = $this->signIn($this->uiUser('reviewer'));
        $page = $reviewer->get('/ui/reference/expense-types');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(Words::SECTION['settings'], self::currentSection($page));
        $r = $reviewer->post('/ui/reference/expense-types/add', ['kind' => 'expense', 'name' => 'Postage', 'reason' => 'stamps we buy'] + $page->form('/ui/reference/expense-types/add'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('1', (string) self::$db->value("SELECT is_active FROM expense_type WHERE name = 'Postage'"));
        $job = $this->signIn($this->uiUser('expenses'));
        $look = $job->get('/ui/reference/expense-types');
        self::assertSame(200, $look->status);
        self::assertFalse($look->hasForm('/ui/reference/expense-types/add'), 'the job looks; an admin or a reviewer changes the list');
        self::assertStringContainsString('Postage', $job->get('/ui/profit/expenses/new')->body, 'offered at once');
    }

    public function testTheRealProfitPerShop(): void
    {
        // The days' figures as the profit days hold them (goods, cost, delivery charged, delivery cost on orders, charges on no order).
        self::$db->exec('INSERT INTO profit_day (channel_id, day, orders, units, sale_ex, order_refund_ex, cost, delivery_ex, delivery_cost, delivery_other) VALUES '
            . "(?, '2026-11-05', 10, 12, 100000, 2000, 50000, 5000, 4000, 300), (?, '2026-11-06', 2, 2, 20000, 0, 9000, 0, 0, 0), (?, '2026-10-31', 9, 9, 99999, 0, 1, 0, 0, 0)",
            [$this->shops['vpg'], $this->shops['ef'], $this->shops['vpg']]);
        $job = $this->signIn($this->uiUser('expenses'));
        $this->addExpense($job, $this->fields(['invoice_date' => '2026-11-20', 'net' => '750.00', 'vat' => '150.00']));
        (new \CW\Expenses\RegularCosts(self::$db))->add(\CW\Caller::system('test'), ['name' => 'Unit 1 rent', 'shop' => $this->shops['vpg'],
            'type' => (int) self::type('Rent', 'regular'), 'every' => 'week', 'amount' => '207.69', 'from' => '2026-11-01']);
        $fin = $this->signIn($this->uiUser('finance'));
        $page = $fin->get('/ui/profit/real', ['period' => 'choose', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::phoneReady($page);
        $rows = self::rows($page, 'real-profit');
        $lines = array_column($rows, 0);
        self::assertSame([Words::PROFIT['real_sales'], Words::PROFIT['real_cost'], Words::PROFIT['real_delivery_charged'], Words::PROFIT['real_delivery'],
            Words::PROFIT['real_fees'], Words::PROFIT['real_expenses'], Words::PROFIT['real_regular'], Words::PROFIT['real_profit']], $lines);
        $col = static fn (int $i): array => array_column($rows, $i);
        // Vape and Go: 980.00 - 500.00 + 50.00 - 43.00 - 0 - 750.00 - 890.10 = -1,153.10; Electrofag: 200.00 - 90.00 = 110.00; Vape Big: 0.
        self::assertSame(['£980.00', '£500.00', '£50.00', '£43.00', '£0.00', '£750.00', '£890.10', '-£1,153.10'], $col(1));
        self::assertSame(['£200.00', '£90.00', '£0.00', '£0.00', '£0.00', '£0.00', '£0.00', '£110.00'], $col(2));
        self::assertSame(['£0.00', '£0.00', '£0.00', '£0.00', '£0.00', '£0.00', '£0.00', '£0.00'], $col(3));
        self::assertStringContainsString(Words::PROFIT['real_fees_not_built'], $page->text(), 'payment fees: not built yet');
        // One shop: its column only; the expenses line opens that month's expenses of the shop.
        $one = $fin->get('/ui/profit/real', ['period' => 'choose', 'from' => '2026-11-01', 'to' => '2026-11-30', 'store' => $this->shops['ef']]);
        self::assertCount(2, self::rows($one, 'real-profit')[0]);
        self::assertContains('/ui/profit/expenses?month=2026-11&shop=' . $this->shops['vpg'], $page->hrefs());
        // Profit by product, by shop: the same three after delivery (expenses, regular costs, real profit).
        $by = self::rows($fin->get('/ui/profit/by', ['by' => 'shop', 'period' => 'choose', 'from' => '2026-11-01', 'to' => '2026-11-30']), 'profit-breakdown');
        self::assertSame(['Vape and Go', '£750.00', '£890.10', '-£1,153.10'], [$by[0][0], ...array_slice($by[0], 11, 3)]);
        self::assertSame(['Electrofag', '£0.00', '£0.00', '£110.00'], [$by[1][0], ...array_slice($by[1], 11, 3)]);
    }
}
