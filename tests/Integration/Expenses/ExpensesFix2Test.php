<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Expenses;

use CW\Auth\Csrf;
use CW\Auth\StaffIdentity;
use CW\CwException;
use CW\Expenses\Expenses;
use CW\Expenses\RegularCostMath;
use CW\Expenses\RegularCosts;
use CW\Expenses\RegularCostSeed;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Settings;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\TestDb;
use CW\Ui\Context;
use CW\Ui\Controller\FilesController;
use CW\Ui\Html;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Fix round 2 (the Codex re-check of 8dbee81: cw_work/runs/expenses_recheck_astra_report.txt, C1-C7; the Fable re-check F-A, F-B), each shown
 * failing first on 8dbee81. Every amount and file is made up; no customer data.
 */
final class ExpensesFix2Test extends KernelUiTestCase
{
    private const PDF = "%PDF-1.4\n% made-up test invoice fix two\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private string $dir = '';
    private string|false $prevStore = false;
    private int $vpg = 0;
    private int $ef = 0;
    private int $vb = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_exp_fix2_' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/store', 0700, true);
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        $this->ef = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
        $this->vb = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapebig', 'Vape Big', 'off')");
    }

    protected function tearDown(): void
    {
        foreach (['vapeandgo' => '2026-11-01', 'electrofag' => '2026-12-01', 'vapebig' => '2026-12-01'] as $code => $day) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [json_encode($day), 'profit.real_from_' . $code]);
        }
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"20\"' AS JSON) WHERE setting_key = 'profit.vat_rate_pct'");
        self::$db->exec("UPDATE expense_type SET name = 'Rent' WHERE kind = 'regular' AND name = 'Rent and rates'");
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

    private function store(): FileStore
    {
        return new FileStore(self::$db, new LocalFileStorage($this->dir . '/store'));
    }

    /** @param array<string, mixed> $over */
    private function form(array $over = []): array
    {
        return $over + ['shop' => $this->vpg, 'supplier' => 'Tabsyst', 'new_supplier' => true, 'type' => self::type('expense', 'Developer'),
            'invoice_date' => '2026-11-12', 'invoice_no' => '', 'net' => '480.00', 'vat' => '96.00', 'note' => ''];
    }

    private function pdf(string $name, string $salt): array
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, str_replace('fix two', 'fix two ' . $salt, self::PDF));
        return ['path' => $path, 'name' => $name];
    }

    // ---- C1 + F-B: every match shown and confirmed, whole; the no-number words -----------------------------------------------------------

    /**
     * Two £576.00 Tabsyst bills of 12 Nov (INV-1, INV-2), then the same with no number: asked about BOTH, in the no-number words, each linked;
     * the confirmation covers exactly that set: a third such bill arriving meanwhile is asked again. 25 matches: all 25 named, no cap of 20.
     */
    public function testEveryMatchIsShownAndTheConfirmationCoversThemAll(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        $a = $s->add($job, $this->form(['invoice_no' => 'INV-1']));
        $b = $s->add($job, $this->form(['invoice_no' => 'INV-2', 'new_supplier' => false]));
        $e = self::refused(409, 'same_invoice', fn () => $s->add($job, $this->form(['new_supplier' => false])));
        self::assertSame([$a['id'], $b['id']], array_column($e->detail['matches'], 'id'), 'both, never the first alone');
        self::assertSame('day_total', $e->detail['why']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $e->detail['confirm'], 'a digest of the whole set, no 20-id field');
        $c = $s->add($job, $this->form(['invoice_no' => 'INV-3', 'new_supplier' => false]));
        $again = self::refused(409, 'same_invoice', fn () => $s->add($job, $this->form(['new_supplier' => false]), null, $e->detail['confirm']));
        self::assertSame([$a['id'], $b['id'], $c['id']], array_column($again->detail['matches'], 'id'), 'INV-3 came meanwhile: asked again');
        $s->add($job, $this->form(['new_supplier' => false]), null, $again->detail['confirm']);
        self::assertSame(4, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
        // 25 bills of one day and total: all named (never 20 and a 21st that slips through).
        for ($i = 4; $i <= 25; $i++) {
            $s->add($job, $this->form(['invoice_no' => 'INV-' . $i, 'new_supplier' => false]), null,
                self::refusedOrNull(fn () => $s->add($job, $this->form(['invoice_no' => 'INV-' . $i, 'new_supplier' => false]))));
        }
        $many = self::refused(409, 'same_invoice', fn () => $s->add($job, $this->form(['new_supplier' => false])));
        self::assertCount(26, $many->detail['matches']);
        // The regular costs: every line of the name that meets the days, no cap.
        $rc = new RegularCosts(self::$db);
        $rent = self::type('regular', 'Rent');
        $l1 = $rc->add($job, ['name' => 'Unit 5', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '140.00', 'from' => '2026-11-01',
            'until' => '2026-11-30']);
        $l2 = $rc->add($job, ['name' => 'Unit 5', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '140.00', 'from' => '2026-12-01']);
        $r = self::refused(409, 'same_cost', fn () => $rc->add($job, ['name' => 'Unit 5', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week',
            'amount' => '140.00', 'from' => '2026-11-15']));
        self::assertSame([$l1, $l2], array_column($r->detail['matches'], 'id'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $r->detail['confirm']);
    }

    /** The add form and the change form list every match with its link, in the no-number words when that is why (F-B). */
    public function testTheFormsLinkEveryMatchInItsOwnWords(): void
    {
        $web = $this->signIn($this->uiUser('expenses'));
        $fields = ['shop' => (string) $this->vpg, 'supplier' => 'Tabsyst', 'new_supplier' => '1', 'type' => (string) self::type('expense', 'Developer'),
            'invoice_date' => '2026-11-12', 'net' => '480.00', 'vat' => '96.00', 'note' => '', 'then' => 'done'];
        foreach (['INV-1', 'INV-2'] as $no) {
            self::assertSame(303, $web->postMultipart('/ui/profit/expenses/new', ['invoice_no' => $no] + $fields + $web->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new'), [])->status);
        }
        $ids = array_map('intval', self::$db->column('SELECT id FROM expense ORDER BY id'));
        $ask = $web->postMultipart('/ui/profit/expenses/new', ['invoice_no' => ''] + $fields + $web->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new'), []);
        self::assertSame(409, $ask->status, $ask->describe());
        foreach ($ids as $id) {
            self::assertContains("/ui/profit/expenses/{$id}", $ask->hrefs(), "match {$id} linked");
        }
        self::assertStringContainsString('the same supplier, shop, date and total', $ask->text());
        $ok = $web->postMultipart('/ui/profit/expenses/new', ['same_ok' => '1'] + $ask->form('/ui/profit/expenses/new'), []);
        self::assertSame(303, $ok->status, $ok->describe());
        // The change form: INV-2 made a bill of no number links INV-1 and the one of no number.
        $third = (int) self::$db->value('SELECT MAX(id) FROM expense');
        $page = $web->get("/ui/profit/expenses/{$ids[1]}");
        $r = $web->postMultipart("/ui/profit/expenses/{$ids[1]}/change", ['invoice_no' => ''] + $page->form("/ui/profit/expenses/{$ids[1]}/change"), []);
        self::assertSame(409, $r->status, $r->describe());
        self::assertContains("/ui/profit/expenses/{$ids[0]}", $r->hrefs());
        self::assertContains("/ui/profit/expenses/{$third}", $r->hrefs());
        self::assertStringContainsString('the same supplier, shop, date and total', $r->text());
    }

    // ---- C2: supplier evidence recorded append-only, whatever the order ---------------------------------------------------------------

    /**
     * A £480 PDF kept as an expense, named as a supplier's evidence, then REPLACED there: still closed to an Expenses-only person (its
     * association kept, append-only). The other order too: bytes kept first as another kind, named as evidence, replaced, then an expense's.
     */
    public function testReplacedSupplierEvidenceStaysClosedWhateverTheOrder(): void
    {
        $job = $this->staffUser('expenses');
        $buyer = $this->staffUser('buyer');
        $x = $this->service()->add($job, $this->form(['invoice_no' => 'INV-480']), $this->pdf('a.pdf', 'a'));
        $sup = self::$db->insert("INSERT INTO supplier (code, name, created_actor, updated_actor) VALUES ('SUP-EV', 'Evidence Ltd', 'system:test', 'system:test')");
        $suppliers = new Suppliers(self::$db);
        $suppliers->setEvidence($buyer, $sup, 1, 'dd', (int) $x['file_id']);
        $other = $this->store()->store(\CW\Caller::system('fix2_test'), $this->pdf('b.pdf', 'b')['path'], 'b.pdf', 'supplier_check', null);
        $suppliers->setEvidence($buyer, $sup, 2, 'dd', (int) $other['id']);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM supplier_file WHERE supplier_id = ?', [$sup]), 'each evidence kept, the replaced one too');
        $web = $this->signIn($this->uiUser('expenses'));
        self::assertSame(403, $web->get("/ui/profit/expenses/{$x['id']}/file")->status, 'the replaced evidence still needs suppliers.view');
        // The other order: kept first as another kind, evidence, replaced, then an expense's PDF.
        $c = $this->pdf('c.pdf', 'c');
        $first = $this->store()->store(\CW\Caller::system('fix2_test'), $c['path'], 'c.pdf', 'other', null);
        $sup2 = self::$db->insert("INSERT INTO supplier (code, name, created_actor, updated_actor) VALUES ('SUP-EV2', 'Evidence Two Ltd', 'system:test', 'system:test')");
        $suppliers->setEvidence($buyer, $sup2, 1, 'import_route', (int) $first['id']);
        $suppliers->setEvidence($buyer, $sup2, 2, 'import_route', (int) $other['id']);
        $y = $this->service()->add($job, $this->form(['invoice_no' => 'INV-481', 'new_supplier' => false]), $c);
        self::assertSame((int) $first['id'], (int) $y['file_id']);
        self::assertSame(403, $web->get("/ui/profit/expenses/{$y['id']}/file")->status);
    }

    // ---- C3: the download's rules with the jobs of the same moment -----------------------------------------------------------------------

    /** A Finance person loses the job after the request began: the file rules read the jobs again with the file's rows, so £480's PDF is refused. */
    public function testTheFileRulesReadTheJobsOfTheirOwnMoment(): void
    {
        $job = $this->staffUser('expenses');
        $x = $this->service()->add($job, $this->form(['invoice_no' => 'INV-480']), $this->pdf('a.pdf', 'a'));
        $fin = $this->uiUser('finance');
        $req = new UiRequest('GET', '/ui/files/' . $x['file_id'], [], [], [], [], '198.51.100.20', true);
        $me = new StaffIdentity((int) $fin['id'], (string) $fin['email'], 'Finance', ['finance'], false, str_repeat('a', 64));
        $ctx = new Context($req, self::$db, $me, Csrf::fromSecretKey(base64_encode(random_bytes(32))), ['id' => (string) $x['file_id']], 'rid-fix2');
        self::assertNull(FilesController::refusal($ctx, (int) $x['file_id']), 'Finance may');
        self::$db->exec('UPDATE staff_role SET revoked_at = CURRENT_TIMESTAMP(6) WHERE staff_user_id = ?', [$fin['id']]);
        $r = FilesController::refusal($ctx, (int) $x['file_id']);
        self::assertNotNull($r, 'the job taken away: refused, whatever the request began with');
        self::assertSame(403, $r->status);
    }

    // ---- C4: strict days -------------------------------------------------------------------------------------------------------------

    /** 0027-11-01 is no day CW takes; a £70 a week line's share is never minus (£300.00 for 1-30 Nov, whatever the first day asked). */
    public function testAStrangeYearIsNoDayAndAShareIsNeverMinus(): void
    {
        self::assertFalse(RegularCostMath::isDay('0027-11-01'));
        self::assertFalse(RegularCostMath::isDay('1999-12-31'));
        self::assertFalse(RegularCostMath::isDay('2100-01-01'));
        self::assertTrue(RegularCostMath::isDay('2026-11-01'));
        self::assertSame(30000, RegularCostMath::share('week', 7000, '2026-11-01', null, '0027-11-01', '2026-11-30'));
        self::assertGreaterThan(366, RegularCostMath::days('0027-11-01', '2026-11-30'), 'the year as written: never read as 2027');
        self::assertSame('0028-01-01', RegularCostMath::addDays('0027-12-31', 1));
        $job = $this->staffUser('expenses');
        (new RegularCosts(self::$db))->add($job, ['name' => 'Unit 12', 'shop' => $this->vpg, 'type' => self::type('regular', 'Rent'), 'vat' => 'none', 'every' => 'week',
            'amount' => '70.00', 'from' => '2026-11-01']);
        $web = $this->signIn($this->uiUser('finance'));
        foreach (['/ui/profit/regular', '/ui/profit/expenses'] as $path) {
            $r = $web->get($path, ['from' => '0027-11-01', 'to' => '2026-11-30', 'shop' => (string) $this->vpg]);
            self::assertSame(200, $r->status, $r->describe());
            self::assertStringNotContainsString('-£', $r->text(), $path);
            self::assertStringNotContainsString('−£', $r->text(), $path);
        }
    }

    // ---- C5: the line before by date across the chain -------------------------------------------------------------------------------

    /** £70 from 1 Nov, £98 from 15 Nov, then £84 from 8 Nov on the first line: deleting the £98 line names the £84 line (ends 14 Nov). */
    public function testDeletingANewAmountNamesTheLineJustBeforeByDate(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $l70 = $rc->add($job, ['name' => 'Unit 13', 'shop' => $this->vpg, 'type' => self::type('regular', 'Rent'), 'vat' => 'none', 'every' => 'week', 'amount' => '70.00',
            'from' => '2026-11-01']);
        $l98 = $rc->newAmount($job, $l70, 1, '98.00', '2026-11-15');
        $l84 = $rc->newAmount($job, $l70, 2, '84.00', '2026-11-08');
        $web = $this->signIn($this->uiUser('expenses'));
        $page = $web->get("/ui/profit/regular/{$l98}");
        self::assertStringContainsString(Words::say('REGULAR', 'delete_continues', Html::day('2026-11-14')), $page->text());
        $link = static fn ($r, string $class): string => (string) (new \DOMXPath($r->dom()))->evaluate('string(//p[contains(@class, "' . $class . '")]/a/@href)');
        self::assertSame("/ui/profit/regular/{$l84}", $link($page, 'delete-continues'), 'the £84 line, never the £70 line ending on 7 Nov');
        self::assertNotSame("/ui/profit/regular/{$l70}", $link($page, 'delete-continues'));
        $done = $web->post("/ui/profit/regular/{$l98}/delete", ['reason' => 'typed in by mistake', 'sure' => '1'] + $page->form("/ui/profit/regular/{$l98}/delete"));
        self::assertSame(303, $done->status, $done->describe());
        $after = $web->follow($done);
        self::assertStringContainsString(Words::say('REGULAR', 'deleted_continues', Html::day('2026-11-14')), $after->text());
        self::assertSame("/ui/profit/regular/{$l84}", $link($after, 'deleted-continues'));
    }

    // ---- C6: the seed locks and checks its groups by id and name, and the VAT rate's row ---------------------------------------------

    /** Rent renamed after the plan: refused (never £207.69 a week reported under Rent and written under another name); the VAT row locked. */
    public function testTheSeedChecksItsGroupsByNameAndLocksTheVatRate(): void
    {
        $seed = new RegularCostSeed(self::$db);
        $plan = $seed->plan('2026-11-01');
        self::assertSame([], $plan['refusals']);
        self::$db->exec("UPDATE expense_type SET name = 'Rent and rates' WHERE kind = 'regular' AND name = 'Rent'");
        $e = self::refused(409, 'seed_changed', fn () => $seed->apply($plan));
        self::assertStringContainsString('Rent', $e->getMessage());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
        self::$db->exec("UPDATE expense_type SET name = 'Rent' WHERE kind = 'regular' AND name = 'Rent and rates'");
        // Someone changing the VAT rate holds its row: the seed waits for it (its read is a locking one), never writes at the old rate meanwhile.
        $other = TestDb::connect();
        $other->exec('START TRANSACTION');
        $other->value("SELECT value_json FROM app_setting WHERE setting_key = 'profit.vat_rate_pct' FOR UPDATE");
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $plan = $seed->plan('2026-11-01');
            $waited = false;
            try {
                $seed->apply($plan);
            } catch (\PDOException $x) {
                $waited = \CW\Db::driverCode($x) === 1205;
            }
            self::assertTrue($waited, 'the seed read the VAT rate with a lock');
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
        } finally {
            $other->exec('ROLLBACK');
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
    }

    // ---- C7: Real profit offers the three shops only ---------------------------------------------------------------------------------

    public function testRealProfitOffersTheThreeShopsOnly(): void
    {
        $proto = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vpgproto', 'VPG proto', 'off')");
        $web = $this->signIn($this->uiUser('finance'));
        $page = $web->get('/ui/profit/real');
        self::assertSame(200, $page->status, $page->describe());
        $xp = new \DOMXPath($page->dom());
        $values = [];
        foreach ($xp->query('//select[@name="store"]/option') as $o) {
            /** @var \DOMElement $o */
            $values[] = $o->getAttribute('value');
        }
        self::assertNotContains((string) $proto, $values);
        self::assertContains((string) $this->vb, $values);
        $asked = $web->get('/ui/profit/real', ['store' => (string) $proto]);
        self::assertSame(200, $asked->status, $asked->describe());
        $heads = [];
        foreach ((new \DOMXPath($asked->dom()))->query('//table[contains(@class, "real-profit")]/thead//th') as $th) {
            $heads[] = trim($th->textContent);
        }
        self::assertContains('Vape Big', $heads, 'a fourth channel asked for: the three shops shown, never an empty table');
    }

    // ---- F-A: a shop's first real profit day is never before CW holds its sales ---------------------------------------------------------

    public function testARealProfitDayBeforeTheShopsSalesIsRefused(): void
    {
        self::$db->exec('INSERT INTO profit_day (channel_id, day, orders) VALUES (?, ?, 3), (?, ?, 2)', [$this->vpg, '2026-10-29', $this->ef, '2026-11-17']);
        $s = new Settings(self::$db);
        foreach ([['profit.real_from_vapeandgo', '2026-10-28'], ['profit.real_from_electrofag', '2026-11-16'], ['profit.real_from_vapebig', '2026-12-01']] as [$key, $day]) {
            try {
                $s->checkRule($key, $day);
                self::fail("{$key} took {$day}");
            } catch (CwException $e) {
                self::assertSame('bad_value', $e->errorCode, $key);
            }
        }
        $s->checkRule('profit.real_from_vapeandgo', '2026-10-29');
        $s->checkRule('profit.real_from_electrofag', '2026-11-17');
        $s->checkRule('profit.real_from_electrofag', '2026-12-01');
    }

    /** The confirmation of the 409 the call answers, or '' when it is not asked. */
    private static function refusedOrNull(callable $fn): string
    {
        try {
            $fn();
        } catch (CwException $e) {
            return (string) ($e->detail['confirm'] ?? '');
        }
        self::fail('expected a question');
    }
}
