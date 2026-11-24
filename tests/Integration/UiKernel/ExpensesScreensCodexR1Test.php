<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Expenses\RegularCosts;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Codex check r1 of bb5c0a5 (cw_work/runs/expenses_codex_r1.txt; docs/decisions.md EX21-), through the real /ui kernel, each shown failing
 * first on bb5c0a5: a form sent back after another person's change never undoes it (H2); a deleted regular cost shows no share (H5); Real
 * profit's first page is the same as "This month" chosen (H6); its lines open exactly its days and shop (M7); a job given profit.view on the
 * Roles page sees no expense figure without expenses.view (H10); a file an expense named once stays the expenses' after it is replaced (H11).
 * Every file is made up.
 */
final class ExpensesScreensCodexR1Test extends KernelUiTestCase
{
    private const PDF = "%PDF-1.4\n% made-up test invoice ui r1\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private string $dir = '';
    private string|false $prevStore = false;
    private int $vpg = 0;
    private int $ef = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_exp_ui_r1_' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/store', 0700, true);
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        $this->ef = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
    }

    protected function tearDown(): void
    {
        // The three shops' first real profit days as 0079 sets them (a test that moves one puts it back here; EX39).
        foreach (['vapeandgo' => '2026-11-01', 'electrofag' => '2026-12-01', 'vapebig' => '2026-12-01'] as $code => $day) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [json_encode($day), 'profit.real_from_' . $code]);
        }
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return list<list<string>> */
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

    private function add(KernelBrowser $web, array $over = [], ?string $bytes = null): int
    {
        $page = $web->get('/ui/profit/expenses/new');
        $form = $over + ['shop' => (string) $this->vpg, 'supplier' => 'Viking Direct', 'new_supplier' => '1', 'type' => self::type('Fees'),
            'invoice_date' => '2026-11-05', 'invoice_no' => 'A-' . bin2hex(random_bytes(3)), 'net' => '100.00', 'vat' => '20.00', 'note' => '', 'then' => 'done']
            + $page->form('/ui/profit/expenses/new');
        $files = [];
        if ($bytes !== null) {
            $path = $this->dir . '/' . bin2hex(random_bytes(3)) . '.pdf';
            file_put_contents($path, $bytes);
            $files = ['file' => ['path' => $path, 'name' => 'scan.pdf']];
        }
        $r = $web->postMultipart('/ui/profit/expenses/new', $form, $files);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        return (int) self::$db->value('SELECT MAX(id) FROM expense');
    }

    /** H2: A and B open the £100.00 + £20.00 invoice; B corrects it to £120.00 + £24.00; A's note change is refused and the form A gets back holds B's figures. */
    public function testAFormSentBackAfterAnotherPersonsChangeNeverUndoesIt(): void
    {
        $a = $this->signIn($this->uiUser('expenses'));
        $b = $this->signIn($this->uiUser('expenses'));
        $id = $this->add($a);
        $formA = $a->get("/ui/profit/expenses/{$id}")->form("/ui/profit/expenses/{$id}/change");
        $formB = $b->get("/ui/profit/expenses/{$id}")->form("/ui/profit/expenses/{$id}/change");
        self::assertSame(303, $b->post("/ui/profit/expenses/{$id}/change", ['net' => '120.00', 'vat' => '24.00'] + $formB)->status);
        $refused = $a->post("/ui/profit/expenses/{$id}/change", ['note' => 'paid by card'] + $formA);
        self::assertSame(409, $refused->status);
        $back = $refused->form("/ui/profit/expenses/{$id}/change");
        self::assertSame(['120.00', '24.00'], [$back['net'], $back['vat']], 'the form shows what is saved now');
        $again = $a->post("/ui/profit/expenses/{$id}/change", ['note' => 'paid by card'] + $back);
        self::assertSame(303, $again->status, $again->describe());
        self::assertSame([12000, 2400, 14400, 'paid by card'], array_values(array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v,
            self::$db->one('SELECT net_pence, vat_pence, total_pence, note FROM expense WHERE id = ?', [$id]))));
        // A form refused for its own mistake keeps the version it was drawn with: sent again after someone else's change, it is refused again.
        $formA = $a->get("/ui/profit/expenses/{$id}")->form("/ui/profit/expenses/{$id}/change");
        $bad = $a->post("/ui/profit/expenses/{$id}/change", ['vat' => 'x'] + $formA);
        self::assertSame(422, $bad->status);
        $formB = $b->get("/ui/profit/expenses/{$id}")->form("/ui/profit/expenses/{$id}/change");
        self::assertSame(303, $b->post("/ui/profit/expenses/{$id}/change", ['net' => '130.00'] + $formB)->status);
        self::assertSame(409, $a->post("/ui/profit/expenses/{$id}/change", ['vat' => '24.00'] + $bad->form("/ui/profit/expenses/{$id}/change"))->status);
        self::assertSame(13000, (int) self::$db->value('SELECT net_pence FROM expense WHERE id = ?', [$id]));
        // The same for a regular cost's details.
        $rc = (new RegularCosts(self::$db))->add(Caller::system('test'), ['name' => 'Unit 1 rent', 'shop' => $this->vpg, 'type' => (int) self::type('Rent', 'regular'),
            'vat' => 'none', 'every' => 'week', 'amount' => '207.69', 'from' => '2026-11-01']);
        $fa = $a->get("/ui/profit/regular/{$rc}")->form("/ui/profit/regular/{$rc}/change");
        $fb = $b->get("/ui/profit/regular/{$rc}")->form("/ui/profit/regular/{$rc}/change");
        self::assertSame(303, $b->post("/ui/profit/regular/{$rc}/change", ['until' => '2027-03-31'] + $fb)->status);
        $r = $a->post("/ui/profit/regular/{$rc}/change", ['note' => 'lease'] + $fa);
        self::assertSame(409, $r->status);
        self::assertSame('2027-03-31', $r->form("/ui/profit/regular/{$rc}/change")['until'] ?? null, 'the form shows the end B saved');
    }

    /** H5: a deleted regular cost shows no share of a day or of the month. */
    public function testADeletedRegularCostShowsNoShare(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $rc = (new RegularCosts(self::$db))->add(Caller::system('test'), ['name' => 'Van lease', 'shop' => $this->vpg, 'type' => (int) self::type('Rent', 'regular'),
            'vat' => 'none', 'every' => 'month', 'amount' => '310.00', 'from' => '2026-01-01']);
        $page = $job->get("/ui/profit/regular/{$rc}");
        self::assertNotSame([], self::rows($page, 'regular-share'));
        $del = $page->form("/ui/profit/regular/{$rc}/delete");
        self::assertSame(303, $job->post("/ui/profit/regular/{$rc}/delete", ['reason' => 'put in twice', 'sure' => '1'] + $del)->status);
        self::assertSame([], self::rows($job->get("/ui/profit/regular/{$rc}"), 'regular-share'), 'deleted: in the profit on no day');
    }

    /** H6: Real profit's first page is "This month" exactly as chosen, the longest period setting included. */
    public function testRealProfitsFirstPageIsThisMonthAsChosen(): void
    {
        $fin = $this->signIn($this->uiUser('finance'));
        $was = self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'reports.sales_max_days'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST('7' AS JSON) WHERE setting_key = 'reports.sales_max_days'");
        try {
            $first = $fin->get('/ui/profit/real');
            $chosen = $fin->get('/ui/profit/real', ['period' => 'this_month']);
            self::assertSame(200, $first->status, $first->describe());
            $days = static fn (UiResponse $r): string => (string) preg_replace('/\s+/u', ' ', (string) (new \DOMXPath($r->dom()))->evaluate('string(//p[contains(@class, "real-days")])'));
            self::assertNotSame('', $days($first));
            self::assertSame($days($chosen), $days($first));
        } finally {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [(string) $was, 'reports.sales_max_days']);
        }
    }

    /** M7: Electrofag 1-7 Nov with £40.00 on the 5th and £300.00 on the 20th: the expenses line opens those days of that shop (£40.00), the regular costs line too. */
    public function testRealProfitsLinesOpenExactlyItsDaysAndShop(): void
    {
        // These figures are of November for every shop: all three counted from 1 Nov here (0079 has Electrofag and Vape Big from 1 Dec: EX39).
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key IN (?, ?, ?)', ['"2026-11-01"', 'profit.real_from_vapeandgo',
            'profit.real_from_electrofag', 'profit.real_from_vapebig']);
        $job = $this->signIn($this->uiUser('expenses'));
        $this->add($job, ['shop' => (string) $this->ef, 'net' => '40.00', 'vat' => '0', 'invoice_date' => '2026-11-05']);
        $this->add($job, ['shop' => (string) $this->ef, 'net' => '300.00', 'vat' => '0', 'invoice_date' => '2026-11-20']);
        (new RegularCosts(self::$db))->add(Caller::system('test'), ['name' => 'Unit 1 rent', 'shop' => $this->ef, 'type' => (int) self::type('Rent', 'regular'),
            'vat' => 'none', 'every' => 'week', 'amount' => '207.69', 'from' => '2026-11-01']);
        $fin = $this->signIn($this->uiUser('finance'));
        $page = $fin->get('/ui/profit/real', ['period' => 'choose', 'from' => '2026-11-01', 'to' => '2026-11-07', 'store' => $this->ef]);
        $exp = '/ui/profit/expenses?from=2026-11-01&to=2026-11-07&shop=' . $this->ef;
        $reg = '/ui/profit/regular?from=2026-11-01&to=2026-11-07&shop=' . $this->ef;
        self::assertContains($exp, $page->hrefs());
        self::assertContains($reg, $page->hrefs());
        $list = $fin->get('/ui/profit/expenses', ['from' => '2026-11-01', 'to' => '2026-11-07', 'shop' => (string) $this->ef]);
        self::assertSame(200, $list->status, $list->describe());
        $rows = self::rows($list, 'expenses-list');
        self::assertSame(['Electrofag', '£40.00'], [$rows[0][0], $rows[1][5]]);
        self::assertCount(3, $rows, 'its group, the £40.00 invoice and the regular costs of those days; not the £300.00 of the 20th');
        self::assertSame('£207.69', $rows[2][5], 'Unit 1 rent for 7 days');
        $regular = $fin->get('/ui/profit/regular', ['from' => '2026-11-01', 'to' => '2026-11-07', 'shop' => (string) $this->ef]);
        self::assertStringContainsString('£207.69', (string) (new \DOMXPath($regular->dom()))->evaluate('string(//*[contains(@class, "regular-period-total")])'));
    }

    /** H10: a reviewer given profit.view on the Roles page sees no expense figure: Real profit refused, Profit by shop without the three columns. */
    public function testProfitViewAloneShowsNoExpenseFigure(): void
    {
        $was = self::$db->value("SELECT roles FROM role_permission WHERE permission = 'profit.view'");
        self::$db->exec("UPDATE role_permission SET roles = 'finance,reviewer' WHERE permission = 'profit.view'");
        self::$db->exec('INSERT INTO profit_day (channel_id, day, orders, units, sale_ex, cost) VALUES (?, ?, 1, 1, 1000, 500)', [$this->vpg, \CW\Profit\ProfitViews::today()]);
        try {
            $rev = $this->signIn($this->uiUser('reviewer'));
            self::assertSame(200, $rev->get('/ui/profit')->status, 'the reviewer sees the profit now');
            $real = $rev->get('/ui/profit/real');
            self::assertSame(200, $real->status, $real->describe());
            self::assertSame([Words::PROFIT['real_sales'], Words::PROFIT['real_cost'], Words::PROFIT['real_delivery_charged'], Words::PROFIT['real_delivery'],
                Words::PROFIT['real_fees']], array_column(self::rows($real, 'real-profit'), 0), 'no expenses, regular costs or real profit without expenses.view');
            self::assertStringContainsString(Words::PROFIT['real_no_expenses'], $real->text());
            $head = static fn (UiResponse $r): array => array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
                iterator_to_array((new \DOMXPath($r->dom()))->query('//table[contains(@class, "profit-breakdown")]/thead//th') ?: []));
            $by = $rev->get('/ui/profit/by', ['by' => 'shop']);
            self::assertSame(200, $by->status);
            self::assertNotContains(Words::PROFIT['col_expenses'], $head($by));
            self::assertNotContains(Words::PROFIT['col_real'], $head($by));
            $fin = $this->signIn($this->uiUser('finance'));
            self::assertContains(Words::PROFIT['real_profit'], array_column(self::rows($fin->get('/ui/profit/real'), 'real-profit'), 0));
        } finally {
            self::$db->exec("UPDATE role_permission SET roles = ? WHERE permission = 'profit.view'", [(string) $was]);
        }
    }

    /** H11: bytes kept first as another kind, then an expense's file, then replaced: still the expenses' only on the general file address. */
    public function testAFileAnExpenseNamedOnceStaysTheExpenses(): void
    {
        $path = $this->dir . '/other.pdf';
        file_put_contents($path, self::PDF);
        $first = (new FileStore(self::$db, new LocalFileStorage($this->dir . '/store')))->store(Caller::system('test'), $path, 'other.pdf', 'other', null);
        $job = $this->signIn($this->uiUser('expenses'));
        $id = $this->add($job, [], self::PDF);
        self::assertSame($first['id'], (int) self::$db->value('SELECT file_id FROM expense WHERE id = ?', [$id]), 'the same bytes: one stored row');
        $form = $job->get("/ui/profit/expenses/{$id}")->form("/ui/profit/expenses/{$id}/change");
        $new = $this->dir . '/new.pdf';
        file_put_contents($new, str_replace('ui r1', 'ui r1 corrected', self::PDF));
        self::assertSame(303, $job->postMultipart("/ui/profit/expenses/{$id}/change", $form, ['file' => ['path' => $new, 'name' => 'new.pdf']])->status);
        self::assertNotSame($first['id'], (int) self::$db->value('SELECT file_id FROM expense WHERE id = ?', [$id]));
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(403, $buyer->get('/ui/files/' . $first['id'])->status, 'named by an expense once: the expenses\' for good');
    }
}
