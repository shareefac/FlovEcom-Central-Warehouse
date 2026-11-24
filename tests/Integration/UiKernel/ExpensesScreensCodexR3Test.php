<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * The Codex check r3 of ca25bfc (cw_work/runs/expenses_codex_r3.txt; docs/decisions.md EX32-), through the real /ui kernel, each shown failing
 * first on ca25bfc: one effect per add form, a confirmed second one included (H1); Real profit says when any unit sold has no buying price yet
 * (H5); a change refused because someone else changed the expense still asks for the file chosen (M8). Every file is made up.
 */
final class ExpensesScreensCodexR3Test extends KernelUiTestCase
{
    private const PDF = "%PDF-1.4\n% made-up test invoice ui r3\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private string $dir = '';
    private string|false $prevStore = false;
    private int $vpg = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_exp_ui_r3_' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/store', 0700, true);
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
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

    /** H1: a second £480.00 invoice confirmed as another one, its form sent again: still two invoices, never three; the same for a regular cost. */
    public function testAConfirmedSecondOneSentAgainIsNotAThird(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $fields = ['shop' => (string) $this->vpg, 'supplier' => 'Tabsyst', 'new_supplier' => '1', 'type' => self::type('Developer'), 'invoice_date' => '2026-11-12',
            'invoice_no' => 'INV-480', 'net' => '480.00', 'vat' => '96.00', 'note' => '', 'then' => 'done'];
        self::assertSame(303, $job->postMultipart('/ui/profit/expenses/new', $fields + $job->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new'), [])->status);
        $same = $job->postMultipart('/ui/profit/expenses/new', $fields + $job->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new'), []);
        self::assertSame(409, $same->status);
        $confirmed = ['same_ok' => '1'] + $same->form('/ui/profit/expenses/new');
        $first = $job->postMultipart('/ui/profit/expenses/new', $confirmed, []);
        self::assertSame(303, $first->status, $first->describe());
        $replay = $job->postMultipart('/ui/profit/expenses/new', $confirmed, []);
        self::assertSame(303, $replay->status);
        self::assertSame($first->location(), $replay->location(), 'the same page as the first time');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM expense'), '£960.00, never £1,440.00');
        // A regular cost confirmed as another one, sent again.
        $cost = ['name' => 'Van lease', 'shop' => (string) $this->vpg, 'type' => self::type('Rent', 'regular'), 'vat' => 'none', 'every' => 'week', 'amount' => '152.00',
            'from' => '2026-11-01', 'until' => '', 'note' => ''];
        self::assertSame(303, $job->post('/ui/profit/regular/new', $cost + $job->get('/ui/profit/regular/new')->form('/ui/profit/regular/new'))->status);
        $ask = $job->post('/ui/profit/regular/new', $cost + $job->get('/ui/profit/regular/new')->form('/ui/profit/regular/new'));
        self::assertSame(409, $ask->status);
        $ok = ['same_ok' => '1'] + $ask->form('/ui/profit/regular/new');
        self::assertSame(303, $job->post('/ui/profit/regular/new', $ok)->status);
        self::assertSame(303, $job->post('/ui/profit/regular/new', $ok)->status);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'), '£304.00 a week, never £456.00');
    }

    /** H5: £5,000.00 of sales on 250 units, one unit with no buying price: Real profit says it is not complete (below the 0.5% of the margin pages too). */
    public function testRealProfitSaysWhenAnyUnitHasNoBuyingPrice(): void
    {
        $day = \CW\Profit\ProfitViews::today();
        self::$db->exec('INSERT INTO profit_day (channel_id, day, orders, units, sale_ex, cost, units_no_cost) VALUES (?, ?, 100, 250, 500000, 200000, 1)', [$this->vpg, $day]);
        $fin = $this->signIn($this->uiUser('finance'));
        $page = $fin->get('/ui/profit/real');
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::PROFIT['real_incomplete'], $page->text());
    }

    /** M8: Alice chose the PDF, Bob saved a note first: the page Alice gets back shows Bob's figures AND asks for her file again. */
    public function testAChangeRefusedForAnotherPersonsChangeStillAsksForTheFile(): void
    {
        $a = $this->signIn($this->uiUser('expenses'));
        $b = $this->signIn($this->uiUser('expenses'));
        $fields = ['shop' => (string) $this->vpg, 'supplier' => 'Tabsyst', 'new_supplier' => '1', 'type' => self::type('Developer'), 'invoice_date' => '2026-11-12',
            'invoice_no' => 'INV-481', 'net' => '480.00', 'vat' => '96.00', 'note' => '', 'then' => 'done'];
        self::assertSame(303, $a->postMultipart('/ui/profit/expenses/new', $fields + $a->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new'), [])->status);
        $id = (int) self::$db->value('SELECT MAX(id) FROM expense');
        $formA = $a->get("/ui/profit/expenses/{$id}")->form("/ui/profit/expenses/{$id}/change");
        $formB = $b->get("/ui/profit/expenses/{$id}")->form("/ui/profit/expenses/{$id}/change");
        self::assertSame(303, $b->post("/ui/profit/expenses/{$id}/change", ['note' => 'Bob'] + $formB)->status);
        $path = $this->dir . '/inv.pdf';
        file_put_contents($path, self::PDF);
        $r = $a->postMultipart("/ui/profit/expenses/{$id}/change", $formA, ['file' => ['path' => $path, 'name' => 'inv.pdf']]);
        self::assertSame(409, $r->status);
        self::assertSame('1', $r->form("/ui/profit/expenses/{$id}/change")['file_again'] ?? null);
        self::assertStringContainsString(Words::EXPENSES['file_again'], $r->text());
    }
}
