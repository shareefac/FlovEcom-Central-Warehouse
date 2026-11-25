<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Ai\AiSettings;
use CW\Profit\OrderSnapshots;
use CW\Tests\Support\AiAnswers;
use CW\Tests\Support\AiFixtures;
use CW\Tests\Support\FakeClaude;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\RmBill;
use CW\Tests\Support\RmFixtures;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * "Claude reads the upload" through the real /ui kernel as cw_app (CW-1182 phase 1; docs/decisions.md AI3-AI12), a FAKE Claude (made-up
 * answers at the HTTP level) and SYNTHETIC documents: the settings screen keeps the key hidden for good and says the month's reading; on
 * Profit > Delivery bills a Royal Mail bill's PDF waits on its own page, then shows the SAME check page a pasted bill shows (Store as today),
 * a reading that does not add up refused and said; DPD's PDF and an iPhone photo said apart; on Profit > Expenses the invoice fills the add
 * form (and an expense's Change), Save as today. Only the person who sent a document sees its reading; only the place's job sends one.
 */
final class AiReadScreensTest extends KernelUiTestCase
{
    private string $dir = '';
    private string $spool = '';
    private string|false $prevSpool = false;
    private string|false $prevStore = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = AiFixtures::dir('ui');
        $this->spool = AiFixtures::dir('uispool');
        mkdir($this->dir . '/store', 0700, true);
        $this->prevSpool = getenv('CW_AI_SPOOL_DIR');
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_AI_SPOOL_DIR=' . $this->spool);
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
    }

    protected function tearDown(): void
    {
        putenv($this->prevSpool === false ? 'CW_AI_SPOOL_DIR' : 'CW_AI_SPOOL_DIR=' . $this->prevSpool);
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        exec('rm -rf ' . escapeshellarg($this->dir) . ' ' . escapeshellarg($this->spool));
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

    /** The runner's turn (bin/ai_read.php does it every minute), as the app login, with the fake Claude. */
    private function runner(FakeClaude $f): ?array
    {
        return AiFixtures::reader(self::$appDb, $this->spool, $f)->runNext();
    }

    /** @return array{0: int, 1: int} Vape and Go with two orders of 9 Nov, each with a Royal Mail parcel, and the account */
    private function shop(): array
    {
        $ch = (int) $this->site('vapeandgo', 'live')->channelId;
        self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, committed_at, created_at) VALUES (?, '9000000', 'committed', "
            . "'2026-10-28 10:28:00', '2026-10-28 10:28:00')", [$ch]);
        $acct = RmFixtures::account(self::$db, $ch)['account'];
        foreach (['100' => 'JV000000101GB', '101' => 'JV000000102GB'] as $ref => $t) {
            (new OrderSnapshots(self::$db))->store($ch, (string) $ref, ['v' => 1, 'version' => 1, 'order_no' => '1360' . $ref, 'status' => 'completed',
                'placed_at' => '2026-11-09T08:00:00Z', 'paid_at' => '2026-11-09T09:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
                'lines' => [['line_id' => '8' . $ref, 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
                    'free' => false]], 'delivery' => ['method_id' => '5', 'method' => 'Royal Mail', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0,
                'grand_inc' => 1599, 'parcels' => [['parcel_ref' => 'odl:' . $ref, 'tracking_no' => $t, 'carrier' => 'royal_mail', 'service_code' => '', 'service' => '',
                    'status' => 'generated', 'label_at' => '2026-11-09T10:00:00Z', 'shipped_at' => '2026-11-09T10:00:00Z', 'manual' => false, 'partial' => false]]], 'api');
        }
        return [$ch, $acct];
    }

    /** @return list<array<string, mixed>> the bill of RoyalMailScreensTest: 2 lines, £6.13 */
    private static function bill(): array
    {
        return [RmBill::line('2026-11-09', 'TPL', 2, 508, '943'), RmBill::line('2026-11-08', 'RW4', 1, 105, 'Rectification Week 45')];
    }

    /**
     * H5 (Codex final of 86aebbd): the form's submit buttons in the order Enter meets them: the FIRST must be Save (no formaction), the Claude
     * button only after it (pressed on purpose).
     *
     * @return list<string> each button's formaction ('' for none)
     */
    private static function submits(UiResponse $r, string $action): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//form[@action="' . $action . '"]//*[(self::button and (not(@type) or @type="submit")) or (self::input and @type="submit")]') ?: [] as $b) {
            $out[] = $b instanceof \DOMElement ? $b->getAttribute('formaction') : '';
        }
        return $out;
    }

    private function upload(KernelBrowser $web, array $form, string $path, string $name = 'bill.pdf'): UiResponse
    {
        $page = $web->get('/ui/profit/delivery');
        self::assertSame(200, $page->status, $page->describe());
        return $web->postMultipart('/ui/profit/delivery/upload', $form + $page->form('/ui/profit/delivery/upload'), ['file' => ['path' => $path, 'name' => $name]]);
    }

    public function testTheKeyIsNeverShownBackAndTheMonthIsSaid(): void
    {
        $rev = $this->signIn($this->uiUser('reviewer'));
        $list = $rev->get('/ui/reference/settings');
        self::assertSame(200, $list->status, $list->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::SETTING_TOPIC['ai'], $list->text());
        self::assertStringContainsString(Words::say('AIREAD', 'month', '0', '0.00', '30.00'), $list->text());
        $page = $rev->get('/ui/reference/settings/setting', ['key' => AiSettings::KEY]);
        self::assertSame(200, $page->status);
        $xp = new \DOMXPath($page->dom());
        self::assertSame('password', $xp->query('//form[@action="/ui/reference/settings/setting"]//input[@name="value"]')?->item(0)?->getAttribute('type'));
        // a key of the wrong form: refused, and never drawn back
        $bad = $rev->post('/ui/reference/settings/setting', ['value' => 'sk-wrong key 123'] + ['reason' => 'the owner made a key']
            + $page->form('/ui/reference/settings/setting'));
        self::assertSame(400, $bad->status, $bad->describe());
        self::assertStringNotContainsString('sk-wrong', $bad->body);
        $ok = $rev->post('/ui/reference/settings/setting', ['value' => AiFixtures::KEY, 'reason' => 'the owner made a key'] + $page->form('/ui/reference/settings/setting'));
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame(json_encode(AiFixtures::KEY), (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?", [AiSettings::KEY]));
        foreach ([$rev->get('/ui/reference/settings/setting', ['key' => AiSettings::KEY]), $rev->get('/ui/reference/settings')] as $r) {
            self::assertSame(200, $r->status);
            self::assertStringNotContainsString(AiFixtures::KEY, $r->body, 'never shown back');
            self::assertStringNotContainsString('MADEUPKEY', $r->body);
        }
        $again = $rev->get('/ui/reference/settings/setting', ['key' => AiSettings::KEY]);
        self::assertStringContainsString(\CW\Settings::HIDDEN, $again->body, 'the box holds "(set: hidden)" while a key is set');
        foreach (['config_change' => 'CAST(state AS CHAR)', 'audit_log' => 'CAST(detail AS CHAR)'] as $t => $col) {
            self::assertStringNotContainsString('MADEUPKEY', implode(' ', self::$db->column("SELECT {$col} FROM {$t}")), "{$t} keeps a fingerprint only");
        }
        // sent back as drawn: the key stays
        $same = $rev->post('/ui/reference/settings/setting', ['reason' => 'only looked'] + $again->form('/ui/reference/settings/setting'));
        self::assertSame(303, $same->status);
        self::assertSame(json_encode(AiFixtures::KEY), (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?", [AiSettings::KEY]));
        // the month: the calls of this UK month that read a document, rounded up
        self::$db->exec("INSERT INTO ai_call (read_id, created_at, staff_user_id, place, kind, model, media_type, file_bytes, input_tokens, output_tokens, cost_millipence, "
            . "result, reason, seconds) VALUES (NULL, NOW(6), (SELECT MIN(id) FROM staff_user), 'expense_new', 'expense_invoice', 'claude-opus-5-5', 'image/png', 1, 1, 1, "
            . "25600, 'ok', 'ok', 1), (NULL, NOW(6), (SELECT MIN(id) FROM staff_user), 'expense_new', 'expense_invoice', 'claude-opus-5-5', 'image/png', 1, 1, 1, 1696, "
            . "'failed', 'max_tokens', 1)");
        self::assertStringContainsString(Words::say('AIREAD', 'month', '1', '0.28', '30.00'), $rev->get('/ui/reference/settings')->text());
    }

    public function testARoyalMailPdfIsReadThenCheckedAsAPastedBillAndStored(): void
    {
        [, $acct] = $this->shop();
        $user = $this->uiUser('delivery_bills');
        $job = $this->signIn($user);
        $pdf = AiFixtures::pdf($this->dir, 'a bill');
        // off (as installed): the PDF refused in words, nothing kept
        $off = $this->upload($job, ['carrier' => 'royal_mail', 'account' => (string) $acct], $pdf);
        self::assertSame(409, $off->status, $off->describe());
        self::assertStringContainsString(Words::AIREAD['off_switch'] . ' ' . Words::AIREAD['rm_needs_page'], $off->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM ai_read'));
        self::assertStringNotContainsString(Words::AIREAD['hint_bill'], $job->get('/ui/profit/delivery')->text());
        AiFixtures::on(self::$db);
        self::assertStringContainsString(Words::AIREAD['hint_bill'], $job->get('/ui/profit/delivery')->text());
        // on: the page that waits (it looks again by itself)
        $r = $this->upload($job, ['carrier' => 'royal_mail', 'account' => (string) $acct], $pdf);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $id = (int) self::$db->value('SELECT MAX(id) FROM ai_read');
        self::assertSame('/ui/profit/delivery/read/' . $id, $r->location());
        $wait = $job->follow($r);
        self::assertSame(200, $wait->status, $wait->describe());
        self::assertSame('3', $wait->header('Refresh'));
        self::assertStringContainsString(Words::say('AIREAD', 'wait_text', Words::AIREAD['what_royal_mail_bill']), $wait->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_bill_check'), 'nothing checked yet');
        // only its person sees it; Finance cannot open the page
        self::assertSame(404, $this->signIn($this->uiUser('delivery_bills'))->get('/ui/profit/delivery/read/' . $id)->status);
        self::assertSame(403, $this->signIn($this->uiUser('finance'))->get('/ui/profit/delivery/read/' . $id)->status);
        // read: the same check page a pasted bill shows, with a line that says Claude read it
        $f = (new FakeClaude())->push(FakeClaude::answer(AiAnswers::royalMail(self::bill()), 9_000, 2_400));
        self::assertSame('done', $this->runner($f)['state'] ?? null);
        $check = $job->get('/ui/profit/delivery/read/' . $id);
        self::assertSame(200, $check->status, $check->describe() . ' ' . implode(' | ', self::$log));
        self::assertNull($check->header('Refresh'));
        self::assertStringContainsString(Words::say('DELIVERY', 'rm_check_title', '9070000001'), $check->text());
        self::assertStringContainsString(Words::AIREAD['check_by_claude'], $check->text());
        self::assertSame([[Words::DELIVERY['account'], '0706703000'], [Words::DELIVERY['rm_bill_date'], '16 Nov 2026'], [Words::DELIVERY['days'], '8 Nov 2026 - 9 Nov 2026'],
            [Words::DELIVERY['rm_check_ties'], Words::DELIVERY['rm_yes']], [Words::DELIVERY['rm_rows_title'], '2'], [Words::DELIVERY['net'], '£6.13'],
            [Words::DELIVERY['vat'], '£1.23'], [Words::DELIVERY['gross'], '£7.36']], self::rows($check, 'delivery-rm-check-facts'));
        // the pasted page of the same bill would check the same rows
        $pasted = $this->upload($job, ['carrier' => 'royal_mail', 'account' => (string) $acct, 'text' => RmBill::spaced(self::bill())], $this->dir . '/none.html');
        self::assertSame(self::rows($pasted, 'delivery-rm-check-rows'), self::rows($check, 'delivery-rm-check-rows'));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'delivery_bill.check' ORDER BY id LIMIT 1"), true);
        self::assertSame(['claude', $id], [$audit['read_by'] ?? null, $audit['ai_read'] ?? null], 'the check says Claude read the bill');
        self::assertSame(['passed', null], array_values(self::$db->one('SELECT checked, check_code FROM ai_call WHERE read_id = ?', [$id]) ?? []));
        // Store as today
        $stored = $job->post('/ui/profit/delivery/store', $check->form('/ui/profit/delivery/store'));
        self::assertSame(303, $stored->status, $stored->describe());
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM carrier_invoice WHERE status = 'live' AND invoice_no = '9070000001'"));
        // shown once
        $again = $job->get('/ui/profit/delivery/read/' . $id);
        self::assertSame(409, $again->status);
        self::assertStringContainsString(Words::AIREAD['taken'], $again->text());
        self::assertSame([], array_values(array_diff(scandir($this->spool) ?: [], ['.', '..'])), 'no file left');
        // a corrected bill's PDF on the bill's own page (Replace): read, then the same check of a replacement, then Store replaces it
        $billId = (int) self::$db->value("SELECT id FROM carrier_invoice WHERE status = 'live' AND invoice_no = '9070000001'");
        $page = $job->get('/ui/profit/delivery/' . $billId);
        self::assertSame(200, $page->status);
        self::assertStringContainsString(Words::AIREAD['hint_bill'], $page->text());
        $f->push(FakeClaude::answer(AiAnswers::royalMail([RmBill::line('2026-11-09', 'TPL', 2, 508, '943'), RmBill::line('2026-11-08', 'RW4', 2, 210,
            'Rectification Week 45')]), 9_000, 2_400));
        $r = $job->postMultipart('/ui/profit/delivery/' . $billId . '/replace', $page->form('/ui/profit/delivery/' . $billId . '/replace'),
            ['file' => ['path' => AiFixtures::pdf($this->dir, 'corrected'), 'name' => 'corrected.pdf']]);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $rid = (int) self::$db->value('SELECT MAX(id) FROM ai_read');
        self::assertSame(['/ui/profit/delivery/read/' . $rid, 'delivery_replace'], [$r->location(), self::$db->value('SELECT place FROM ai_read WHERE id = ?', [$rid])]);
        self::assertSame('done', $this->runner($f)['state'] ?? null);
        $check = $job->get('/ui/profit/delivery/read/' . $rid);
        self::assertSame(200, $check->status, $check->describe() . ' ' . implode(' | ', self::$log));
        self::assertContains([Words::DELIVERY['net'], '£7.18'], self::rows($check, 'delivery-rm-check-facts'));
        $stored = $job->post('/ui/profit/delivery/store', $check->form('/ui/profit/delivery/store'));
        self::assertSame(303, $stored->status, $stored->describe());
        self::assertSame('superseded', self::$db->value('SELECT status FROM carrier_invoice WHERE id = ?', [$billId]), 'replaced by the corrected bill');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM carrier_invoice WHERE status = 'live' AND invoice_no = '9070000001' AND net_pence = 718"));
    }

    public function testAReadingThatDoesNotAddUpIsRefusedAndOtherFilesAreSaidApart(): void
    {
        [, $acct] = $this->shop();
        $job = $this->signIn($this->uiUser('delivery_bills'));
        AiFixtures::on(self::$db);
        $f = new FakeClaude();
        // a line read a penny out: CW's own reader refuses the whole bill and says so; nothing kept
        $a = AiAnswers::royalMail(self::bill());
        $a['lines'][0]['net'] = '5.09';
        $f->push(FakeClaude::answer($a, 9_000, 2_400));
        $id = (int) str_replace('/ui/profit/delivery/read/', '', (string) $this->upload($job, ['carrier' => 'royal_mail', 'account' => (string) $acct],
            AiFixtures::pdf($this->dir))->location());
        $this->runner($f);
        $no = $job->get('/ui/profit/delivery/read/' . $id);
        self::assertSame(422, $no->status, $no->describe());
        self::assertStringContainsString(Words::AIREAD['rm_not_tie'], $no->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_bill_check'));
        self::assertSame(['refused', 'bad_file'], array_values(self::$db->one('SELECT checked, check_code FROM ai_call WHERE read_id = ?', [$id]) ?? []));
        // Claude declined: said, with what to do instead
        $f->push(FakeClaude::answer(null, 9_000, 0, ['stop' => 'refusal']));
        $id = (int) str_replace('/ui/profit/delivery/read/', '', (string) $this->upload($job, ['carrier' => 'royal_mail', 'account' => (string) $acct],
            AiFixtures::pdf($this->dir, 'two'))->location());
        $this->runner($f);
        $no = $job->get('/ui/profit/delivery/read/' . $id);
        self::assertSame(422, $no->status);
        self::assertStringContainsString(Words::say('AIREAD', 'failed', Words::AIREAD['why_refusal']) . ' ' . Words::AIREAD['next_royal_mail_bill'], $no->text());
        // DPD's PDF: never sent, said; an iPhone photo: said; the saved page and the pasted text work as before (no reading)
        $dpd = $this->upload($job, ['carrier' => 'dpd'], AiFixtures::pdf($this->dir, 'dpd'));
        self::assertSame(415, $dpd->status);
        self::assertStringContainsString(Words::AIREAD['dpd_pdf'], $dpd->text());
        $heic = $this->upload($job, ['carrier' => 'royal_mail', 'account' => (string) $acct], AiFixtures::heic($this->dir), 'IMG_0001.HEIC');
        self::assertSame(415, $heic->status);
        self::assertStringContainsString(Words::AIREAD['heic'], $heic->text());
        $page = $this->dir . '/bill.html';
        file_put_contents($page, RmBill::page(self::bill(), ['invoice' => '9070000002']));
        $html = $this->upload($job, ['carrier' => 'royal_mail', 'account' => (string) $acct], $page, 'bill.html');
        self::assertSame(200, $html->status, $html->describe());
        self::assertStringNotContainsString(Words::AIREAD['check_by_claude'], $html->text());
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM ai_read'), 'only the two PDFs');
        self::assertCount(2, $f->sent);
    }

    public function testAnInvoiceFillsTheExpenseFormAndSaveIsAsToday(): void
    {
        $vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
        // a supplier on CW's list, added as the Suppliers page adds one (with its history: the nightly checks read it)
        $sup = (new \CW\Expenses\Expenses(self::$db))->addSupplier(\CW\Caller::system('test'), 'Made Up Paper Ltd');
        $type = (string) self::$db->value("SELECT id FROM expense_type WHERE kind = 'expense' AND name = 'Stationery and packaging'");
        $web = $this->signIn($this->uiUser('expenses'));
        self::assertFalse(str_contains($web->get('/ui/profit/expenses/new')->body, 'formaction="/ui/profit/expenses/read"'), 'no button while off');
        AiFixtures::on(self::$db);
        $new = $web->get('/ui/profit/expenses/new');
        self::assertStringContainsString('formaction="/ui/profit/expenses/read"', $new->body);
        $order = self::submits($new, '/ui/profit/expenses/new');
        self::assertSame('', $order[0] ?? null, 'H5: Enter presses Save, never "Fill from the file with Claude"');
        self::assertContains('/ui/profit/expenses/read', $order);
        $form = ['shop' => (string) $vpg, 'type' => $type, 'note' => 'typed before'] + $new->form('/ui/profit/expenses/new');
        $none = $web->postMultipart('/ui/profit/expenses/read', $form, []);
        self::assertSame(400, $none->status, $none->describe());
        self::assertStringContainsString(Words::AIREAD['read_no_file'], $none->text());
        $invoice = AiFixtures::pdf($this->dir, 'invoice', 'invoice.pdf');
        $invoiceSha = hash_file('sha256', $invoice);
        $r = $web->postMultipart('/ui/profit/expenses/read', $form, ['file' => ['path' => $invoice, 'name' => 'inv.pdf']]);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $wait = $web->follow($r);
        self::assertSame(['3', 200], [$wait->header('Refresh'), $wait->status]);
        self::assertStringContainsString(Words::say('AIREAD', 'wait_text', Words::AIREAD['what_expense_invoice']), $wait->text());
        $f = (new FakeClaude())->push(FakeClaude::answer(AiAnswers::invoice(['supplier_name' => 'MADE UP PAPER LTD']), 2_100, 640));
        self::assertSame('done', $this->runner($f)['state'] ?? null);
        $filled = $web->get((string) $r->location());
        self::assertSame(200, $filled->status, $filled->describe() . ' ' . implode(' | ', self::$log));
        $v = $filled->form('/ui/profit/expenses/new');
        $read = (string) self::$db->value('SELECT MAX(id) FROM ai_read');
        self::assertSame(['Made Up Paper Ltd', 'INV-20417', '2026-11-20', '84.72', '70.60', '14.12', 'inc', (string) $vpg, $type, 'typed before', $read],
            [$v['supplier'], $v['invoice_no'], $v['invoice_date'], $v['total'], $v['net'], $v['vat'], $v['vat_way'], $v['shop'], $v['type'], $v['note'],
                $v['ai_file'] ?? null]);
        self::assertStringContainsString(Words::say('AIREAD', 'filled', implode(', ', array_map(static fn (string $k): string => Words::AIREAD['field_' . $k],
            ['supplier', 'invoice_no', 'invoice_date', 'total', 'net', 'vat']))), $filled->text());
        // AI15: the invoice Claude read is kept for this expense: never asked for again
        self::assertSame('1', $v['file_again'] ?? null, 'H7: the marker the release before reads (choose the file again), alongside ai_file');
        self::assertStringContainsString(Words::AIREAD['file_kept'], $filled->text());
        self::assertCount(1, array_diff(scandir($this->spool) ?: [], ['.', '..']), 'kept in the spool for its Save');
        // another person's form naming that reading attaches nothing: refused in words, nothing saved
        $other = $this->signIn($this->uiUser('expenses'));
        $theirs = $other->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new');
        $steal = $other->postMultipart('/ui/profit/expenses/new', ['then' => 'done', 'csrf' => $theirs['csrf'], 'form_key' => $theirs['form_key']] + $v, []);
        self::assertSame(422, $steal->status, $steal->describe());
        self::assertStringContainsString(Words::AIREAD['file_gone'], $steal->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
        // a Save refused for a field (no shop): the page keeps the reading for the next Save
        $refused = $web->postMultipart('/ui/profit/expenses/new', ['then' => 'done', 'shop' => ''] + $v, []);
        self::assertSame(422, $refused->status, $refused->describe());
        $again = $refused->form('/ui/profit/expenses/new');
        self::assertSame([$read, '1'], [$again['ai_file'] ?? null, $again['file_again'] ?? null]);
        self::assertNull(self::$db->value('SELECT file_used_at FROM ai_read WHERE id = ?', [$read]), 'a refused Save uses nothing');
        // Save: stored with Claude's figures, the supplier CW's own, and the invoice it read attached (as if chosen), once
        $save = $web->postMultipart('/ui/profit/expenses/new', array_merge($again, ['then' => 'done', 'shop' => (string) $vpg]), []);
        self::assertSame(303, $save->status, $save->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame([(string) $sup, '7060', '1412', 'INV-20417', '2026-11-20'], array_values(array_map('strval', self::$db->one('SELECT supplier_id, net_pence, vat_pence, '
            . 'invoice_no, invoice_date FROM expense') ?? [])));
        $stored = self::$db->one('SELECT f.sha256, f.kind FROM expense e JOIN stored_file f ON f.id = e.file_id');
        self::assertSame([$invoiceSha, 'expense_invoice'], [$stored['sha256'] ?? null, $stored['kind'] ?? null], 'the file Claude read, kept as the expense\'s');
        self::assertSame([], array_values(array_diff(scandir($this->spool) ?: [], ['.', '..'])), 'gone from the spool once attached');
        self::assertNotNull(self::$db->value('SELECT file_used_at FROM ai_read WHERE id = ?', [$read]));
        // the same form sent again (a lost answer): where the first went, nothing added; a new form naming it: refused, it was attached once
        $replay = $web->postMultipart('/ui/profit/expenses/new', array_merge($again, ['then' => 'done', 'shop' => (string) $vpg]), []);
        self::assertSame([303, $save->location()], [$replay->status, $replay->location()]);
        $fresh = $web->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new');
        $twice = $web->postMultipart('/ui/profit/expenses/new', array_merge($again, ['then' => 'done', 'form_key' => $fresh['form_key'], 'invoice_no' => 'INV-OTHER',
            'shop' => (string) $vpg]), []);
        self::assertSame(422, $twice->status, $twice->describe());
        self::assertStringContainsString(Words::AIREAD['file_gone'], $twice->text());
        self::assertSame('1', ($twice->form('/ui/profit/expenses/new')['file_again'] ?? null), 'asked for a file, or Save without one');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM expense'));

        // an expense's Change: its fold filled from the invoice, its version kept; Save attaches the photo Claude read in place of the PDF
        $id = (int) self::$db->value('SELECT id FROM expense');
        $before = (int) self::$db->value('SELECT file_id FROM expense');
        $page = $web->get('/ui/profit/expenses/' . $id);
        $change = $page->form('/ui/profit/expenses/' . $id . '/change');
        $order = self::submits($page, '/ui/profit/expenses/' . $id . '/change');
        self::assertSame('', $order[0] ?? null, 'H5: the Change form\'s Enter presses Save too');
        self::assertContains('/ui/profit/expenses/' . $id . '/read', $order);
        $f->push(FakeClaude::answer(AiAnswers::invoice(['invoice_number' => 'INV-20418', 'net' => '50.00', 'vat' => '10.00', 'total' => '60.00']), 2_100, 640));
        $r = $web->postMultipart('/ui/profit/expenses/' . $id . '/read', $change, ['file' => ['path' => AiFixtures::png($this->dir), 'name' => 'photo.png']]);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $this->runner($f);
        $filled = $web->get((string) $r->location());
        self::assertSame(200, $filled->status, $filled->describe());
        $v = $filled->form('/ui/profit/expenses/' . $id . '/change');
        self::assertSame(['INV-20418', '60.00', '50.00', '10.00', $change['seen'], (string) self::$db->value('SELECT MAX(id) FROM ai_read'), true],
            [$v['invoice_no'], $v['total'], $v['net'], $v['vat'], $v['seen'], $v['ai_file'] ?? null, ($v['file_again'] ?? null) === '1']);
        $fold = (new \DOMXPath($filled->dom()))->query('//details[@id="change"]')?->item(0);
        self::assertTrue($fold instanceof \DOMElement && $fold->hasAttribute('open'), 'the Change fold open, filled');
        // the add form never takes a Change's invoice
        $wrong = $web->get('/ui/profit/expenses/new')->form('/ui/profit/expenses/new');
        $cross = $web->postMultipart('/ui/profit/expenses/new', ['then' => 'done', 'ai_file' => $v['ai_file'], 'shop' => (string) $vpg, 'type' => $type, 'supplier' => 'Made Up Paper Ltd',
            'invoice_no' => 'INV-X', 'invoice_date' => '2026-11-21', 'net' => '1.00', 'vat' => '0.00', 'vat_way' => 'none'] + $wrong, []);
        self::assertSame(422, $cross->status, $cross->describe());
        $saved = $web->postMultipart('/ui/profit/expenses/' . $id . '/change', $v, []);
        self::assertSame(303, $saved->status, $saved->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['5000', '1000', 'INV-20418'], array_values(array_map('strval', self::$db->one('SELECT net_pence, vat_pence, invoice_no FROM expense') ?? [])));
        $now = self::$db->one('SELECT f.sha256, f.mime FROM expense e JOIN stored_file f ON f.id = e.file_id') ?? [];
        self::assertSame([hash_file('sha256', $this->dir . '/photo.png'), 'image/png'], [$now['sha256'] ?? null, $now['mime'] ?? null]);
        self::assertNotSame($before, (int) self::$db->value('SELECT file_id FROM expense'));
        // only the Expenses job sends an invoice
        $fin = $this->signIn($this->uiUser('finance'));
        self::assertSame(403, $fin->postMultipart('/ui/profit/expenses/read', ['csrf' => $this->token($fin)], ['file' => ['path' => AiFixtures::pdf($this->dir), 'name' => 'x.pdf']])->status);
    }
}
