<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Expenses;

use CW\Caller;
use CW\CwException;
use CW\Expenses\ExpenseViews;
use CW\Expenses\Expenses;
use CW\Expenses\RegularCostMath;
use CW\Expenses\RegularCosts;
use CW\Expenses\RegularCostSeed;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\TestDb;
use CW\Ui\Controller\ExpensesController;
use CW\Ui\Controller\FilesController;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Kernel;
use CW\Ui\Words;

/**
 * The two final reviews of 70ab3d2 (Codex astra: cw_work/runs/expenses_final_astra_report.txt; the Fable line-by-line review: M1, L1-L4;
 * docs/decisions.md EX39-), each shown failing first on 70ab3d2. Every amount and file is made up; no customer data.
 */
final class ExpensesFinalReviewTest extends KernelUiTestCase
{
    private const PDF = "%PDF-1.4\n% made-up test invoice final review\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private string $dir = '';
    private string|false $prevStore = false;
    private int $vpg = 0;
    private int $ef = 0;
    private int $vb = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_exp_final_' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/store', 0700, true);
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        $this->ef = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
        $this->vb = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapebig', 'Vape Big', 'off')");
    }

    protected function tearDown(): void
    {
        self::realFrom('vapeandgo', '2026-11-01');
        self::realFrom('electrofag', '2026-12-01');
        self::realFrom('vapebig', '2026-12-01');
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"20\"' AS JSON) WHERE setting_key = 'profit.vat_rate_pct'");
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private static function type(string $kind, string $name): int
    {
        return (int) self::$db->value('SELECT id FROM expense_type WHERE kind = ? AND name = ?', [$kind, $name]);
    }

    private function service(): Expenses
    {
        $store = $this->dir . '/store';
        return new Expenses(self::$db, static fn (): FileStore => new FileStore(self::$db, new LocalFileStorage($store)));
    }

    /** @param array<string, mixed> $over */
    private function form(array $over = []): array
    {
        return $over + ['shop' => $this->vpg, 'supplier' => 'Viking Direct', 'new_supplier' => true, 'type' => self::type('expense', 'Stationery and packaging'),
            'invoice_date' => '2026-11-12', 'invoice_no' => 'INV-42', 'net' => '64.20', 'vat' => '12.84', 'note' => ''];
    }

    private function pdf(string $name = 'inv.pdf', string $salt = ''): array
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, str_replace('final review', 'final review ' . $salt, self::PDF));
        return ['path' => $path, 'name' => $name];
    }

    private static function setVat(string $pct): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = \'profit.vat_rate_pct\'', [json_encode($pct)]);
    }

    private static function realFrom(string $code, string $day): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [json_encode($day), 'profit.real_from_' . $code]);
    }

    // ---- HIGH 1: a shop's real profit counts from its own day (the owner, 24 Nov 2026: EX39) -------------------------------------------

    /**
     * Vape and Go counts from 1 Nov 2026, Electrofag and Vape Big from 1 Dec (settings): November shows Vape and Go's figures and "not
     * started (from 1 Dec 2026)" for the other two, never a misleading -£100.00; 15 Nov - 10 Dec counts Electrofag from 1 Dec only (its
     * £50.00 of 20 Nov left out, its £30.00 of 5 Dec in). Nothing stored changes.
     */
    public function testRealProfitCountsEachShopFromItsOwnDay(): void
    {
        self::assertSame(['profit.real_from_electrofag' => '2026-12-01', 'profit.real_from_vapeandgo' => '2026-11-01', 'profit.real_from_vapebig' => '2026-12-01'],
            array_map(static fn (string $v): string => (string) json_decode($v), array_column(self::$db->all("SELECT setting_key, CAST(value_json AS CHAR) AS v FROM app_setting "
            . "WHERE setting_key LIKE 'profit.real\\_from\\_%' ORDER BY setting_key"), 'v', 'setting_key')));
        $job = $this->staffUser('expenses');
        $s = $this->service();
        $s->add($job, $this->form(['shop' => $this->vb, 'net' => '100.00', 'vat' => '0', 'invoice_date' => '2026-11-10']));
        $s->add($job, $this->form(['shop' => $this->vpg, 'net' => '64.20', 'vat' => '12.84', 'invoice_date' => '2026-11-12']));
        $s->add($job, $this->form(['shop' => $this->ef, 'invoice_no' => 'EF-1', 'net' => '50.00', 'vat' => '0', 'invoice_date' => '2026-11-20']));
        $s->add($job, $this->form(['shop' => $this->ef, 'invoice_no' => 'EF-2', 'net' => '30.00', 'vat' => '0', 'invoice_date' => '2026-12-05']));
        $by = [];
        foreach ((new ExpenseViews(self::$db))->realProfit('2026-11-01', '2026-11-30', null) as $x) {
            $by[$x['shop_id']] = $x;
        }
        self::assertTrue($by[$this->vpg]['started']);
        self::assertSame(-6420, $by[$this->vpg]['real']);
        self::assertFalse($by[$this->vb]['started'], 'Vape Big counts from 1 Dec');
        self::assertNull($by[$this->vb]['real'], 'no figure before its day');
        self::assertSame('2026-12-01', $by[$this->vb]['from']);
        self::assertFalse($by[$this->ef]['started']);
        $ef = (new ExpenseViews(self::$db))->realProfit('2026-11-15', '2026-12-10', $this->ef)[0];
        self::assertTrue($ef['started']);
        self::assertSame('2026-12-01', $ef['counted_from'], 'counted from its day only');
        self::assertSame(3000, $ef['expenses'], 'the £30.00 of 5 Dec, never the £50.00 of 20 Nov');
        self::assertSame(-3000, $ef['real']);
        // The page says it, the shop's column "not started (from 1 Dec 2026)"; Profit by shop the same in its three cells.
        $fin = $this->signIn($this->uiUser('finance'));
        $page = $fin->get('/ui/profit/real', ['period' => 'choose', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::say('PROFIT', 'real_not_started', Html::day('2026-12-01')), $page->text());
        self::assertStringNotContainsString('-£100.00', $page->text());
        self::$db->exec('INSERT INTO profit_day (channel_id, day, orders, units, sale_ex, cost) VALUES (?, ?, 1, 1, 1000, 500)', [$this->vb, '2026-11-20']);
        $shop = $fin->get('/ui/profit/by', ['by' => 'shop', 'period' => 'choose', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        self::assertSame(200, $shop->status, $shop->describe());
        self::assertStringContainsString(Words::say('PROFIT', 'real_not_started', Html::day('2026-12-01')), $shop->text());
        self::assertStringNotContainsString('-£100.00', $shop->text());
        // The day is a setting: Vape Big from 1 Nov counts its November.
        self::realFrom('vapebig', '2026-11-01');
        self::assertSame(-10000 + 500, (new ExpenseViews(self::$db))->realProfit('2026-11-01', '2026-11-30', $this->vb)[0]['real']);
    }

    // ---- HIGH 3: the seed's lock ----------------------------------------------------------------------------------------------------------

    /** A dry run (or anything) holding the seed's lock: --apply writes nothing and says so with exit 4 (Cli::BUSY), never 0. */
    public function testTheSeedsApplyIsBusyNotDoneWhenItsLockIsHeld(): void
    {
        $name = 'cw_job:seed_regular_costs:' . TestDb::name();
        $name = strlen($name) > 64 ? 'cw_job:' . sha1('seed_regular_costs' . "\0" . TestDb::name()) : $name;
        self::assertSame(1, (int) self::$db->value('SELECT GET_LOCK(?, 0)', [$name]));
        try {
            $cmd = [PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . '/bin/seed_regular_costs.php', '--db=' . TestDb::name(), '--admin', '--apply'];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
            self::assertIsResource($p);
            $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(4, proc_close($p), $out);
            self::assertStringContainsString('busy', $out);
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'), 'nothing written (Vape and Go £34,899.77 for November never missing unseen)');
        } finally {
            self::$db->value('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    // ---- HIGH 4: the same invoice with its number left out -------------------------------------------------------------------------------

    /** £64.20 + £12.84 as INV-42, then the same with no number (and the other way round): asked, never £128.40 unseen; credit notes too. */
    public function testAnInvoiceWithItsNumberLeftOutIsTheSameInvoice(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        $s->add($job, $this->form());
        self::refused(409, 'same_invoice', fn () => $s->add($job, $this->form(['invoice_no' => ''])));
        // The other way round: a bill typed in with no number, then again with its number.
        $s->add($job, $this->form(['invoice_no' => '', 'invoice_date' => '2026-11-13']));
        self::refused(409, 'same_invoice', fn () => $s->add($job, $this->form(['invoice_no' => 'INV-43', 'invoice_date' => '2026-11-13'])));
        // A credit note, both ways.
        $s->add($job, $this->form(['invoice_no' => 'CN-7', 'credit' => true, 'net' => '10.42', 'vat' => '2.08', 'invoice_date' => '2026-11-20']));
        $e = self::refused(409, 'same_invoice', fn () => $s->add($job, $this->form(['invoice_no' => '', 'credit' => true, 'net' => '10.42', 'vat' => '2.08', 'invoice_date' => '2026-11-20'])));
        self::assertTrue($e->detail['credit']);
        self::assertSame(6420 + 6420 - 1042, (new ExpenseViews(self::$db))->expensesBy('2026-11-01', '2026-11-30', null)[$this->vpg]['net']);
    }

    // ---- MEDIUM: money, forms and the seed ------------------------------------------------------------------------------------------------

    /** £100 a week (no VAT) from 1 Nov is £28.57 on 1-2 Nov; "£100 from 2 Nov" again changes nothing: still £28.57, one line. */
    public function testTheSameAmountAgainDoesNotSplitTheLine(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $id = $rc->add($job, ['name' => 'Unit 9', 'shop' => $this->vpg, 'type' => self::type('regular', 'Rent'), 'vat' => 'none', 'every' => 'week', 'amount' => '100.00',
            'from' => '2026-11-01']);
        self::assertSame($id, $rc->newAmount($job, $id, 1, '100.00', '2026-11-02'), 'nothing to change: the same line');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
        self::assertSame(2857, (new ExpenseViews(self::$db))->regularBy('2026-11-01', '2026-11-02', null)[$this->vpg]);
        // Including VAT at the same rate: the same.
        $inc = $rc->add($job, ['name' => 'Unit 10', 'shop' => $this->vpg, 'type' => self::type('regular', 'Rent'), 'vat' => 'inc', 'every' => 'week', 'amount' => '120.00',
            'from' => '2026-11-01']);
        self::assertSame($inc, $rc->newAmount($job, $inc, 1, '120.00', '2026-11-09'));
    }

    /** Entered as including VAT while the shops' rate was 0%: the choice is kept; at 20% again, a new amount of £120 includes £20 VAT (£100 counts). */
    public function testTheVatChoiceIsKeptApartFromItsRate(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        self::setVat('0');
        $id = $rc->add($job, ['name' => 'Unit 11', 'shop' => $this->vpg, 'type' => self::type('regular', 'Rent'), 'vat' => 'inc', 'every' => 'week', 'amount' => '100.00',
            'from' => '2026-11-01']);
        self::assertSame(['1', '0'], array_map('strval', array_values(self::$db->one('SELECT vat_included, vat_rate_bp FROM regular_cost WHERE id = ?', [$id]))));
        self::setVat('20');
        $new = $rc->newAmount($job, $id, 1, '120.00', '2026-11-08');
        self::assertSame(['1', '2000'], array_map('strval', array_values(self::$db->one('SELECT vat_included, vat_rate_bp FROM regular_cost WHERE id = ?', [$new]))));
        self::assertSame(10000, (new ExpenseViews(self::$db))->regularBy('2026-11-08', '2026-11-14', null)[$this->vpg], '£100.00 a week counts, never £120.00');
        self::assertSame([], \CW\Expenses\ExpenseInvariants::check(self::$db));
    }

    /** The seed's plan checked again whole under the locks: a second Novos line, a renamed line, or another VAT rate meanwhile refuses all. */
    public function testTheSeedChecksItsWholePlanAgainUnderTheLocks(): void
    {
        $seed = new RegularCostSeed(self::$db);
        $plan = $seed->plan('2026-11-01');
        self::assertSame([], $plan['refusals']);
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $other = $rc->add($job, ['name' => 'Novos', 'shop' => $this->vpg, 'type' => self::type('regular', 'Other'), 'vat' => 'inc', 'every' => 'week', 'amount' => '2,769.00',
            'from' => '2026-11-01']);
        $e = self::refused(409, 'seed_changed', fn () => $seed->apply($plan));
        self::assertStringContainsString('Novos', $e->getMessage());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'), 'nothing written (never £10,450.78 a week)');
        $rc->delete($job, $other, 1, 'a test line');
        // All 16 there; one renamed meanwhile: refused.
        $seed->apply($seed->plan('2026-11-01'));
        $plan = $seed->plan('2026-11-01');
        $novos = (int) self::$db->value("SELECT id FROM regular_cost WHERE name = 'Novos' AND deleted_at IS NULL");
        $rc->change($job, $novos, 1, ['name' => 'Novos Ltd', 'type' => self::type('regular', 'Marketing, website and people'), 'until' => '', 'note' => '']);
        self::refused(409, 'seed_changed', fn () => $seed->apply($plan));
        // Another VAT rate between the plan and the write: refused, nothing written.
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 0');
        self::$db->exec("DELETE FROM expense_history WHERE subject = 'regular_cost'");
        self::$db->exec('DELETE FROM regular_cost');
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $plan = $seed->plan('2026-11-01');
        self::setVat('17.5');
        self::refused(409, 'seed_changed', fn () => $seed->apply($plan));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
    }

    /** LOW: Rent switched off: the dry run says the seed would be refused (as --apply is), never "16 to add". */
    public function testTheSeedsPlanRefusesWhatItsApplyWould(): void
    {
        $rent = self::type('regular', 'Rent');
        self::$db->exec('UPDATE expense_type SET is_active = 0 WHERE id = ?', [$rent]);
        try {
            $plan = (new RegularCostSeed(self::$db))->plan('2026-11-01');
            self::assertNotSame([], $plan['refusals']);
            self::assertStringContainsString('Rent', implode(' ', $plan['refusals']));
        } finally {
            self::$db->exec('UPDATE expense_type SET is_active = 1 WHERE id = ?', [$rent]);
        }
    }

    /** A £480 invoice of Acme opened; Acme renamed "Acme Old" and a new "Acme" added; the open form saved (a note only): still Acme Old's. */
    public function testAnOpenFormKeepsItsSupplierWhenItsNameIsGivenToAnother(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        $x = $s->add($job, $this->form(['supplier' => 'Acme', 'invoice_no' => 'A-480', 'net' => '480.00', 'vat' => '96.00']));
        $s->changeSupplier($job, $x['supplier_id'], 1, 'Acme Old', null);
        $b = $s->addSupplier($job, 'Acme');
        $s->change($job, $x['id'], 1, $this->form(['supplier' => 'Acme', 'supplier_id' => (string) $x['supplier_id'], 'supplier_was' => 'Acme', 'invoice_no' => 'A-480',
            'net' => '480.00', 'vat' => '96.00', 'note' => 'paid']));
        self::assertSame($x['supplier_id'], (int) self::$db->value('SELECT supplier_id FROM expense WHERE id = ?', [$x['id']]), 'never moved to the other Acme');
        // Typed as a replacement (the name shown was another): the new one.
        $s->change($job, $x['id'], 2, $this->form(['supplier' => 'Acme', 'supplier_id' => (string) $x['supplier_id'], 'supplier_was' => 'Acme Old', 'invoice_no' => 'A-480',
            'net' => '480.00', 'vat' => '96.00', 'note' => 'paid']));
        self::assertSame($b, (int) self::$db->value('SELECT supplier_id FROM expense WHERE id = ?', [$x['id']]));
    }

    /** "Yes, another one" for INV-101 never saves INV-102 (already in) unasked; the same for a regular cost of another name. */
    public function testAConfirmationIsForTheRecordItWasAskedAbout(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        $a = $s->add($job, $this->form(['invoice_no' => 'INV-101', 'net' => '480.00', 'vat' => '96.00']));
        $b = $s->add($job, $this->form(['invoice_no' => 'INV-102', 'net' => '480.00', 'vat' => '96.00']));
        $e = self::refused(409, 'same_invoice', fn () => $s->add($job, $this->form(['invoice_no' => 'INV-101', 'net' => '480.00', 'vat' => '96.00'])));
        self::assertSame((string) $a['id'], $e->detail['confirm']);
        self::refused(409, 'same_invoice', fn () => $s->add($job, $this->form(['invoice_no' => 'INV-102', 'net' => '480.00', 'vat' => '96.00']), null, (string) $a['id']));
        $s->add($job, $this->form(['invoice_no' => 'INV-102', 'net' => '480.00', 'vat' => '96.00']), null, (string) $b['id']);
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
        $rc = new RegularCosts(self::$db);
        $rent = self::type('regular', 'Rent');
        $l5 = $rc->add($job, ['name' => 'Unit 5', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '140.00', 'from' => '2026-11-01']);
        $l6 = $rc->add($job, ['name' => 'Unit 6', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '140.00', 'from' => '2026-11-01']);
        $again = self::refused(409, 'same_cost', fn () => $rc->add($job, ['name' => 'Unit 5', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week',
            'amount' => '140.00', 'from' => '2026-11-01']));
        self::assertSame((string) $l5, $again->detail['confirm']);
        self::refused(409, 'same_cost', fn () => $rc->add($job, ['name' => 'Unit 6', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week',
            'amount' => '140.00', 'from' => '2026-11-01'], (string) $l5));
        $rc->add($job, ['name' => 'Unit 6', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '140.00', 'from' => '2026-11-01'], (string) $l6);
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
    }

    /** Through the screen: the INV-101 warning ticked, the number then changed to INV-102 (in already): asked again, nothing saved. */
    public function testTheScreensConfirmationIsBoundToTheMatchedInvoice(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $fields = ['shop' => (string) $this->vpg, 'supplier' => 'Tabsyst', 'new_supplier' => '1', 'type' => (string) self::type('expense', 'Developer'),
            'invoice_date' => '2026-11-12', 'net' => '480.00', 'vat' => '96.00', 'note' => '', 'then' => 'done'];
        foreach (['INV-101', 'INV-102'] as $no) {
            self::assertSame(303, $job->postMultipart('/ui/profit/expenses/new', ['invoice_no' => $no] + $fields + $job->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new'), [])->status);
        }
        $ask = $job->postMultipart('/ui/profit/expenses/new', ['invoice_no' => 'INV-101'] + $fields + $job->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new'), []);
        self::assertSame(409, $ask->status);
        $r = $job->postMultipart('/ui/profit/expenses/new', ['same_ok' => '1', 'invoice_no' => 'INV-102'] + $ask->form('/ui/profit/expenses/new'), []);
        self::assertSame(409, $r->status, 'asked again for INV-102');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM expense'), '£960.00, never £1,440.00');
    }

    /** Only the three shops: a fourth channel (the proto's vpgproto) is never offered nor taken for an expense or a regular cost. */
    public function testOnlyTheThreeShopsTakeExpenses(): void
    {
        $proto = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vpgproto', 'VPG proto', 'off')");
        self::assertSame(['vapeandgo', 'electrofag', 'vapebig'], array_column((new ExpenseViews(self::$db))->shops(), 'code'));
        $job = $this->staffUser('expenses');
        self::refused(422, 'choose_shop', fn () => $this->service()->add($job, $this->form(['shop' => $proto])));
        self::refused(422, 'choose_shop', fn () => (new RegularCosts(self::$db))->add($job, ['name' => 'Unit 7', 'shop' => $proto, 'type' => self::type('regular', 'Rent'),
            'vat' => 'none', 'every' => 'week', 'amount' => '140.00', 'from' => '2026-11-01']));
        self::assertSame([$this->vpg, $this->ef, $this->vb], array_column((new ExpenseViews(self::$db))->realProfit('2026-11-01', '2026-11-30', null), 'shop_id'));
    }

    /** One unpriced unit of 1,000 (below the margin pages' 0.5%): Profit by shop's Real profit cell says it is not complete. */
    public function testProfitByShopMarksARealProfitWithAnUnpricedUnit(): void
    {
        $day = \CW\Profit\ProfitViews::today();
        self::$db->exec('INSERT INTO profit_day (channel_id, day, orders, units, sale_ex, cost, units_no_cost) VALUES (?, ?, 100, 1000, 500000, 200000, 1)', [$this->vpg, $day]);
        $fin = $this->signIn($this->uiUser('finance'));
        $by = $fin->get('/ui/profit/by', ['by' => 'shop', 'period' => 'today']);
        self::assertSame(200, $by->status, $by->describe());
        self::assertStringContainsString(Words::PROFIT['real_cell_incomplete'], $by->text());
    }

    // ---- MEDIUM: permissions, files and operations ----------------------------------------------------------------------------------------

    /** The reads of expense figures are for expenses.view only, re-read inside their snapshot; a refused write answers with no figure. */
    public function testExpenseReadsAreForExpensesViewOnly(): void
    {
        $job = $this->staffUser('expenses');
        $x = $this->service()->add($job, $this->form(['invoice_no' => 'INV-480', 'net' => '480.00', 'vat' => '96.00']));
        $warehouse = $this->staffUser('warehouse');
        self::refused(403, 'role_not_allowed', fn () => (new ExpenseViews(self::$db, $warehouse))->expense($x['id']));
        self::refused(403, 'role_not_allowed', fn () => (new ExpenseViews(self::$db, $warehouse))->month('2026-11', null, '', false));
        self::assertNotNull((new ExpenseViews(self::$db, $this->staffUser('finance')))->expense($x['id']));
        // A write the service refused for its jobs (lost between the route and the service) answers with a plain refusal, no expense.
        self::assertTrue(ExpensesController::deniedWrite(new CwException('role_not_allowed', 'no', 403)));
        self::assertTrue(ExpensesController::deniedWrite(new CwException('admin_cannot_post', 'no', 403)));
        self::assertFalse(ExpensesController::deniedWrite(new CwException('same_invoice', 'asked', 409)));
    }

    /** A £480 PDF kept as an expense, then the same bytes made a supplier's evidence: the expense download needs suppliers.view too. */
    public function testSupplierEvidenceIsAskedWhateverTheUploadOrder(): void
    {
        $job = $this->staffUser('expenses');
        $x = $this->service()->add($job, $this->form(['invoice_no' => 'INV-480', 'net' => '480.00', 'vat' => '96.00']), $this->pdf());
        $sup = self::$db->insert("INSERT INTO supplier (code, name, created_actor, updated_actor) VALUES ('SUP-EV', 'Evidence Ltd', 'system:test', 'system:test')");
        self::$db->exec('UPDATE supplier SET dd_evidence_file_id = ? WHERE id = ?', [$x['file_id'], $sup]);
        $web = $this->signIn($this->uiUser('expenses'));
        $r = $web->get("/ui/profit/expenses/{$x['id']}/file");
        self::assertSame(403, $r->status, 'the supplier\'s evidence needs suppliers.view as well');
    }

    /** The download's sandbox policy is in what PHP sends, beside the pages' own policy (send() never replaces it). */
    public function testTheDownloadsSandboxIsEmitted(): void
    {
        $r = Kernel::secure(FilesController::download(self::PDF, 'application/pdf', 'inv.pdf'), true);
        $sent = $r->emitted();
        self::assertContains('Content-Security-Policy: sandbox', $sent);
        self::assertContains('Content-Security-Policy: ' . Kernel::CSP, $sent);
    }

    /** A long supplier name and invoice number in multibyte letters: the download name keeps ".pdf" within 255 bytes, in both parameters. */
    public function testALongDownloadNameKeepsItsExtension(): void
    {
        $name = ExpensesController::downloadName(str_repeat('é', 100), str_repeat('ü', 64), 'application/pdf');
        self::assertStringEndsWith('.pdf', $name);
        self::assertLessThanOrEqual(255, strlen($name));
        self::assertSame($name, FileStore::downloadName($name, 'application/pdf'));
        $d = FilesController::disposition($name);
        self::assertMatchesRegularExpression('/filename="[^"]*\.pdf";/', $d);
        self::assertStringEndsWith('.pdf', rawurldecode((string) substr($d, (int) strpos($d, "UTF-8''") + 7)));
    }

    /** Each file an expense names is kept 7 years from THAT attachment (a file stored years before too), the replaced one included. */
    public function testEachAttachmentIsKeptSevenYearsFromThen(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        $x = $s->add($job, $this->form(), $this->pdf('a.pdf', 'a'));
        $s->change($job, $x['id'], 1, $this->form(['note' => 'new PDF']), $this->pdf('b.pdf', 'b'));
        $until = (string) self::$db->value('SELECT DATE_ADD(CURRENT_DATE(), INTERVAL 7 YEAR)');
        $rows = self::$db->all('SELECT file_id, version, retain_until FROM expense_file WHERE expense_id = ? ORDER BY version', [$x['id']]);
        self::assertCount(2, $rows);
        self::assertSame([(string) $x['file_id'], '1', $until], array_map('strval', array_values($rows[0])));
        self::assertSame(['2', $until], array_map('strval', [$rows[1]['version'], $rows[1]['retain_until']]));
    }

    /** A file a version of an expense named is closed by its association row (indexed), never by reading the history's JSON. */
    public function testTheFileRulesAskTheAssociationNotTheHistory(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        // The bytes kept first as another kind (one stored row, its first kind), then an expense's PDF, then replaced on it.
        $a = $this->pdf('a.pdf', 'a');
        $first = (new FileStore(self::$db, new LocalFileStorage($this->dir . '/store')))->store(self::system(), $a['path'], 'a.pdf', 'other', null);
        $x = $s->add($job, $this->form(), $a);
        self::assertSame($first['id'], $x['file_id']);
        $s->change($job, $x['id'], 1, $this->form(['note' => 'new PDF']), $this->pdf('b.pdf', 'b'));
        // The history put aside while the rule is asked: the association row alone closes it.
        $hist = self::$db->all("SELECT id, subject, subject_id, version, action, CAST(state AS CHAR) AS state, CAST(before_state AS CHAR) AS before_state, reason, actor, "
            . "staff_user_id, created_at FROM expense_history WHERE subject = 'expense' AND subject_id = ?", [$x['id']]);
        self::$db->exec("DELETE FROM expense_history WHERE subject = 'expense' AND subject_id = ?", [$x['id']]);
        try {
            $buyer = $this->signIn($this->uiUser('buyer'));
            self::assertSame(403, $buyer->get('/ui/files/' . $x['file_id'])->status, 'the replaced PDF stays the expenses\'');
        } finally {
            foreach ($hist as $h) {
                self::$db->exec('INSERT INTO expense_history (id, subject, subject_id, version, action, state, before_state, reason, actor, staff_user_id, created_at) '
                    . 'VALUES (?, ?, ?, ?, ?, CAST(? AS JSON), CAST(? AS JSON), ?, ?, ?, ?)', array_values($h));
            }
        }
    }

    // ---- LOW ------------------------------------------------------------------------------------------------------------------------------

    /** £70 a week from 1 Nov; £98 from 15 Nov; then £84 from 8 Nov on the first line: the £84 line's next amount is the £98 line. */
    public function testABackdatedNewAmountLinksTheNextAmountByDate(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $l1 = $rc->add($job, ['name' => 'Unit 12', 'shop' => $this->vpg, 'type' => self::type('regular', 'Rent'), 'vat' => 'none', 'every' => 'week', 'amount' => '70.00',
            'from' => '2026-11-01']);
        $l98 = $rc->newAmount($job, $l1, 1, '98.00', '2026-11-15');
        $l84 = $rc->newAmount($job, $l1, 2, '84.00', '2026-11-08');
        self::assertSame($l98, (int) (new ExpenseViews(self::$db))->regular($l84)['next']['id']);
    }

    /** A one-day share ten (and two hundred) years after a monthly line's first day is worked out directly, the same as day by day. */
    public function testAShareYearsAfterTheFirstDayIsWorkedOutDirectly(): void
    {
        $ref = static function (string $every, int $amount, string $from, ?string $until, string $day): array {
            // The reference: day by day, each day amount / its month's (or year's) days, summed exactly.
            $end = $until !== null && $until < $day ? $until : $day;
            [$w, $n, $d] = [0, 0, 1];
            for ($c = $from; $c <= $end; $c = RegularCostMath::addDays($c, 1)) {
                $len = $every === 'month' ? (int) gmdate('t', (int) strtotime($c)) : (RegularCostMath::days(substr($c, 0, 4) . '-01-01', substr($c, 0, 4) . '-12-31'));
                $num = $n * $len + $amount * $d;
                $den = $d * $len;
                $w += intdiv($num, $den);
                $num %= $den;
                $g = $num === 0 ? $den : (static function (int $a, int $b): int {
                    while ($b !== 0) {
                        [$a, $b] = [$b, $a % $b];
                    }
                    return $a;
                })($num, $den);
                [$n, $d] = $num === 0 ? [0, 1] : [intdiv($num, $g), intdiv($den, $g)];
            }
            return [$w, $n, $d];
        };
        foreach ([['month', 3100, '2025-01-31', null, '2026-03-02'], ['month', 99999, '2024-02-29', '2026-02-27', '2026-12-01'], ['year', 36600, '2023-07-04', null, '2026-11-24'],
            ['year', 12345, '2024-12-31', null, '2025-01-01'], ['month', 7, '2026-11-24', null, '2026-11-24']] as [$every, $amount, $from, $until, $day]) {
            self::assertSame($ref($every, $amount, $from, $until, $day), RegularCostMath::accrued($every, $amount, $from, $until, $day), "{$every} {$from} {$day}");
        }
        $t = microtime(true);
        for ($i = 0; $i < 300; $i++) {
            RegularCostMath::share('month', 3100, '1826-11-24', null, '2026-11-24', '2026-11-24');
        }
        self::assertLessThan(0.5, microtime(true) - $t, '300 shares of a line 200 years old: never 2,400 months walked each');
    }

    // ---- The Fable review: M1, L1-L4 ------------------------------------------------------------------------------------------------------

    /** M1: deleting a "new amount" line says (and links) that the line before still ends the day before, on the form and after. */
    public function testDeletingANewAmountLineSaysTheLineBeforeStillEnds(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $l1 = $rc->add($job, ['name' => 'Unit 13', 'shop' => $this->vpg, 'type' => self::type('regular', 'Rent'), 'vat' => 'none', 'every' => 'week', 'amount' => '140.00',
            'from' => '2026-11-01']);
        $l2 = $rc->newAmount($job, $l1, 1, '175.00', '2026-11-15');
        $web = $this->signIn($this->uiUser('expenses'));
        $page = $web->get("/ui/profit/regular/{$l2}");
        $text = Words::say('REGULAR', 'delete_continues', Html::day('2026-11-14'));
        self::assertStringContainsString($text, $page->text());
        self::assertContains("/ui/profit/regular/{$l1}", $page->hrefs());
        $done = $web->post("/ui/profit/regular/{$l2}/delete", ['reason' => 'typed in by mistake', 'sure' => '1'] + $page->form("/ui/profit/regular/{$l2}/delete"));
        self::assertSame(303, $done->status, $done->describe());
        $after = $web->follow($done);
        self::assertStringContainsString(Words::say('REGULAR', 'deleted_continues', Html::day('2026-11-14')), $after->text());
        self::assertContains("/ui/profit/regular/{$l1}", $after->hrefs());
    }

    /** L1: the change form's VAT choice says it changes every day of the line (a VAT change from a date: end it and add a new one). */
    public function testTheVatChoiceOfALineSaysItChangesEveryDay(): void
    {
        $job = $this->staffUser('expenses');
        $id = (new RegularCosts(self::$db))->add($job, ['name' => 'Unit 14', 'shop' => $this->vpg, 'type' => self::type('regular', 'Rent'), 'vat' => 'inc', 'every' => 'week',
            'amount' => '140.00', 'from' => '2026-11-01']);
        $page = $this->signIn($this->uiUser('expenses'))->get("/ui/profit/regular/{$id}");
        self::assertStringContainsString(Words::REGULAR['vat_change_hint'], $page->text());
    }

    /** L2: Profit > Expenses and Regular costs on chosen days take at most reports.sales_max_days, as Real profit, and say so. */
    public function testExactDaysAreCappedAsRealProfit(): void
    {
        $max = \CW\Settings::number(self::$db, 'reports.sales_max_days', 366);
        $web = $this->signIn($this->uiUser('finance'));
        $first = RegularCostMath::addDays('2026-11-20', -($max - 1));
        foreach (['/ui/profit/expenses', '/ui/profit/regular'] as $path) {
            $r = $web->get($path, ['from' => '2020-01-01', 'to' => '2026-11-20']);
            self::assertSame(200, $r->status, $r->describe());
            self::assertStringContainsString(Words::say('EXPENSES', 'range_cut', $max, Html::day($first)), $r->text(), $path);
        }
    }

    /** L3: the help texts name the shops' VAT rate of the setting (17.5% here), never a fixed 20%. */
    public function testTheHelpTextsNameTheSettingsVatRate(): void
    {
        self::setVat('17.5');
        $r = $this->signIn($this->uiUser('finance'))->get('/ui/profit/regular');
        self::assertSame(200, $r->status, $r->describe());
        self::assertStringContainsString('17.5% VAT', $r->text());
        self::assertStringNotContainsString('20% VAT', $r->text());
    }

    /** L4: a month with an invoice and a credit note: the tile counts them apart ("1 invoice and 1 credit note"), never "2 invoices". */
    public function testTheTilesCountCreditNotesApart(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        $s->add($job, $this->form());
        $s->add($job, $this->form(['invoice_no' => 'CN-7', 'credit' => true, 'net' => '10.42', 'vat' => '2.08', 'invoice_date' => '2026-11-20']));
        $r = $this->signIn($this->uiUser('finance'))->get('/ui/profit/expenses', ['month' => '2026-11']);
        self::assertSame(200, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('EXPENSES', 'tile_parts', Words::EXPENSES['tile_inv_one'], Words::EXPENSES['tile_cn_one'], '£64.54'), $r->text());
    }

    /** The seed's caller used here is a system tool (the Expenses job's writes are tested above). */
    private static function system(): Caller
    {
        return Caller::system('test');
    }
}
