<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Codex check r2 of 9b25afe (cw_work/runs/expenses_codex_r2.txt; docs/decisions.md EX28-), through the real /ui kernel, each shown failing
 * first on 9b25afe: the Add regular cost form sent twice asks before a second line (H2); the exact days of the expenses list survive Show (M6);
 * the types' history (with its reasons) only for those who see the expenses or change settings (H7); an expense's file that is also a
 * supplier's invoice copy needs invoices.view too (H8); a form refused after a file was chosen asks for the file again, or to go on without
 * one (M10). Every file is made up.
 */
final class ExpensesScreensCodexR2Test extends KernelUiTestCase
{
    private const PDF = "%PDF-1.4\n% made-up test invoice ui r2\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private string $dir = '';
    private string|false $prevStore = false;
    private int $vpg = 0;
    private int $ef = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_exp_ui_r2_' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/store', 0700, true);
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        $this->ef = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
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

    private function fields(array $over = []): array
    {
        return $over + ['shop' => (string) $this->vpg, 'supplier' => 'Viking Direct', 'new_supplier' => '1', 'type' => self::type('Fees'),
            'invoice_date' => '2026-11-05', 'invoice_no' => 'R2-' . bin2hex(random_bytes(3)), 'net' => '480.00', 'vat' => '96.00', 'note' => '', 'then' => 'done'];
    }

    private function pdf(string $bytes = self::PDF): array
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($path, $bytes);
        return ['file' => ['path' => $path, 'name' => 'scan.pdf']];
    }

    /** H2: a £152.00 a week van lease sent twice: the second asks first; confirmed, it is a second line. */
    public function testTheAddRegularCostFormSentTwiceAsksFirst(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $form = ['name' => 'Van lease', 'shop' => (string) $this->vpg, 'type' => self::type('Rent', 'regular'), 'every' => 'week', 'amount' => '152.00',
            'from' => '2026-11-01', 'until' => '', 'note' => ''] + $job->get('/ui/profit/regular/new')->form('/ui/profit/regular/new');
        self::assertSame(303, $job->post('/ui/profit/regular/new', $form)->status);
        $again = $job->post('/ui/profit/regular/new', $form);
        self::assertSame(409, $again->status);
        self::assertStringContainsString(Words::EXPENSES_ERROR['same_cost_lead'], $again->text());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
        self::assertSame(303, $job->post('/ui/profit/regular/new', ['same_ok' => '1'] + $again->form('/ui/profit/regular/new'))->status);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
    }

    /** M6: the list opened on exact days keeps them when Show is pressed with another choice (deleted ones too); "By month" leaves them. */
    public function testTheExactDaysOfTheExpensesListSurviveShow(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $list = $job->get('/ui/profit/expenses', ['from' => '2026-11-01', 'to' => '2026-11-07', 'shop' => (string) $this->ef]);
        $form = $list->form('/ui/profit/expenses');
        self::assertSame(['2026-11-01', '2026-11-07'], [$form['from'] ?? null, $form['to'] ?? null]);
        self::assertArrayNotHasKey('month', $form, 'exact days: no month sent with them');
    }

    /** H7: Settings > Expense types: the types for everyone; their history (with its reasons) for the expenses' people and settings' people only. */
    public function testTheTypesHistoryOnlyForThoseWhoSeeTheExpensesOrChangeSettings(): void
    {
        $rev = $this->signIn($this->uiUser('reviewer'));
        $page = $rev->get('/ui/reference/expense-types');
        self::assertSame(303, $rev->post('/ui/reference/expense-types/add', ['kind' => 'regular', 'name' => 'Agency', 'reason' => 'Reboot costs £2,076.00 a week']
            + $page->form('/ui/reference/expense-types/add'))->status);
        self::assertStringContainsString('£2,076.00', $rev->get('/ui/reference/expense-types')->text());
        $wh = $this->signIn($this->uiUser('warehouse'));
        $look = $wh->get('/ui/reference/expense-types');
        self::assertSame(200, $look->status);
        self::assertStringContainsString('Agency', $look->text());
        self::assertStringNotContainsString('£2,076.00', $look->text());
        self::assertStringContainsString('£2,076.00', $this->signIn($this->uiUser('finance'))->get('/ui/reference/expense-types')->text());
    }

    /** H8: bytes kept first as a supplier's invoice copy, then an expense's file: the expense's download needs invoices.view too. */
    public function testAnExpenseFileThatIsASupplierInvoiceCopyNeedsInvoicesViewToo(): void
    {
        $path = $this->dir . '/sinv.pdf';
        file_put_contents($path, self::PDF);
        (new FileStore(self::$db, new LocalFileStorage($this->dir . '/store')))->store(Caller::system('test'), $path, 'sinv.pdf', 'supplier_invoice', null);
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/expenses/new');
        self::assertSame(303, $job->postMultipart('/ui/profit/expenses/new', $this->fields() + $page->form('/ui/profit/expenses/new'), $this->pdf())->status);
        $id = (int) self::$db->value('SELECT MAX(id) FROM expense');
        self::assertSame(403, $job->get("/ui/profit/expenses/{$id}/file")->status, 'a supplier invoice copy: invoices.view as well');
        self::assertSame(200, $this->signIn($this->uiUser(['expenses', 'purchasing_desk']))->get("/ui/profit/expenses/{$id}/file")->status);
    }

    /** M10: a £480.00 invoice with its PDF refused for its net: the form says the file was not kept; sent again without it, it asks; or saves without on purpose. */
    public function testARefusedFormAsksForItsFileAgain(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/expenses/new');
        $bad = $job->postMultipart('/ui/profit/expenses/new', $this->fields(['net' => '480.001']) + $page->form('/ui/profit/expenses/new'), $this->pdf());
        self::assertSame(422, $bad->status);
        self::assertStringContainsString(Words::EXPENSES['file_again'], $bad->text());
        $form = $bad->form('/ui/profit/expenses/new');
        self::assertSame('1', $form['file_again'] ?? null);
        $again = $job->postMultipart('/ui/profit/expenses/new', ['net' => '480.00'] + $form, []);
        self::assertSame(422, $again->status, 'the file chosen before is asked for');
        self::assertStringContainsString(Words::EXPENSES_ERROR['file_again'], $again->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
        self::assertSame(303, $job->postMultipart('/ui/profit/expenses/new', ['net' => '480.00'] + $again->form('/ui/profit/expenses/new'), $this->pdf())->status);
        self::assertNotNull(self::$db->value('SELECT file_id FROM expense'));
        // Or on purpose without it.
        $page = $job->get('/ui/profit/expenses/new');
        $bad = $job->postMultipart('/ui/profit/expenses/new', $this->fields(['vat' => 'x']) + $page->form('/ui/profit/expenses/new'), $this->pdf());
        self::assertSame(303, $job->postMultipart('/ui/profit/expenses/new', ['vat' => '96.00', 'no_file_ok' => '1'] + $bad->form('/ui/profit/expenses/new'), [])->status);
    }
}
