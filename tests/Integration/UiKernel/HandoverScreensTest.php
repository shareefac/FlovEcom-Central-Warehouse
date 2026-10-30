<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Company\CompanyDetails;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Stock › Hand-overs and Purchasing › Goods In › Duty register (pack H; docs/decisions.md PH1-PH20) through the real /ui kernel as cw_app,
 * with the production document types: the tab and its segments; a customer added (once, however often the form is sent); a hand-over
 * started (once), filled in and made final, the same "make it final" form sent again refused with nothing booked twice; the invoice
 * PDF refused in words until the company details are confirmed, then printed; a copy made final; a payment received on Balances; the
 * company details' VAT code; the duty register with its download; who may open and press what, and the CSRF token on every POST.
 */
final class HandoverScreensTest extends KernelUiTestCase
{
    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    /** Posts a page's form (its fields as drawn, with $over). @param array<string, string> $over */
    private function submit(KernelBrowser $web, string $page, string $action, array $over = []): UiResponse
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe() . ' ' . implode(' | ', self::$log));
        $form = $p->form($action, true);
        self::assertNotSame([], $form, "no {$action} form: " . $p->describe());
        return $web->post($action, $over + $form);
    }

    private function confirmCompany(): void
    {
        $svc = new CompanyDetails(self::$db);
        $owner = $this->staffUser('reviewer');
        $r = $svc->save($owner, $svc->current()['version'], ['legal_name' => 'Example Vapes Ltd', 'company_number' => '01234567', 'address' => "1 High Street\nLeeds LS1 1AA",
            'vat_registered' => 'yes', 'vat_number' => 'GB123456782', 'vat_code' => 'S', 'email' => 'buying@example.co.uk', 'delivery_address' => "Unit 4\nLeeds LS2 2BB"]);
        $svc->confirm($owner, $r['version']);
    }

    public function testTheTabSegmentsAndWhoSeesThem(): void
    {
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $list = $desk->get('/ui/stock/handovers');
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(['Stock', 'Hand-overs'], [self::currentSection($list), self::currentTab($list)]);
        self::assertContains(['Hand-overs', null], array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($list)), 'the tab shown: no link (CV13)');
        self::assertSame('/ui/stock/handovers', array_column(self::sectionTabs($desk->get((string) array_column(self::segments($list), 'href', 'label')['Customers'])), 'href', 'label')['Hand-overs'],
            'a link from its other pages');
        self::assertSame(['Hand-overs', 'Customers', 'Balances'], array_column(self::segments($list), 'label'));
        self::assertStringContainsString(Words::HANDOVER['no_customers'], $list->text(), 'no customer yet: the form says to add one first');
        foreach (['/ui/stock/handovers/customers', '/ui/stock/handovers/balances', '/ui/receiving/duty'] as $path) {
            self::assertSame(200, $desk->get($path)->status, $path);
        }
        self::assertContains('Duty register', array_column(self::segments($desk->get('/ui/receiving/duty')), 'label'));
        // A buyer reads hand-overs but keeps none and does not see the duty register; a viewer and admin open none of it.
        $buyer = $this->signIn($this->uiUser('buyer'));
        $b = $buyer->get('/ui/stock/handovers');
        self::assertSame(200, $b->status);
        self::assertFalse($b->hasForm('/ui/stock/handovers/customers'));
        self::assertStringContainsString(sprintf(Words::UI['look_only'], Words::whoCan('doc.TRD.post')), $b->text(), 'who keeps them, under the heading\'s "?" (DC1)');
        self::assertSame(403, $buyer->get('/ui/receiving/duty')->status);
        foreach (['viewer', 'admin'] as $role) {
            $w = $this->signIn($this->uiUser($role));
            foreach (['/ui/stock/handovers', '/ui/stock/handovers/customers', '/ui/stock/handovers/balances', '/ui/receiving/duty'] as $path) {
                self::assertSame(403, $w->get($path)->status, "{$role} {$path}");
            }
        }
        // An accountant and an auditor read the duty register.
        self::assertSame(200, $this->signIn($this->uiUser('accountant'))->get('/ui/receiving/duty.csv')->status);
    }

    public function testAHandoverFromTheCustomerToTheInvoice(): void
    {
        $sku = $this->item('strict', 30, 'Zeta liquid 10ml');
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        // The customer, once however often the form is sent.
        $cpage = $web->get('/ui/stock/handovers/customers');
        $form = $cpage->form('/ui/stock/handovers/customers', true);
        self::assertArrayHasKey('form_key', $form);
        $add = ['kind' => 'sister', 'name' => 'Electrofag Ltd', 'address' => "2 Shop Row\nBradford BD1 1AA", 'vat_code' => 'S'] + $form;
        $r = $web->post('/ui/stock/handovers/customers', $add);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($r->location(), $web->post('/ui/stock/handovers/customers', $add)->location(), 'one customer however often the form is sent');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM counterparty'));
        $cp = (int) self::$db->value('SELECT id FROM counterparty');
        self::assertStringContainsString(Words::CUSTOMER_NOTICE['added'], $web->follow($r)->text());
        // A refused customer: the problem at its field, what was typed kept.
        $bad = $web->post('/ui/stock/handovers/customers', ['kind' => 'trade', 'name' => 'Q', 'vat_number' => 'GB1'] + $web->get('/ui/stock/handovers/customers')
            ->form('/ui/stock/handovers/customers', true));
        self::assertSame(422, $bad->status);
        self::assertStringContainsString(Words::CUSTOMER_FIELD_ERROR['vat_number'], $bad->text());

        // The hand-over, once however often the form is sent.
        $list = $web->get('/ui/stock/handovers');
        $form = $list->form('/ui/stock/handovers', true);
        $start = ['counterparty' => (string) $cp] + $form;
        $r = $web->post('/ui/stock/handovers', $start);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($r->location(), $web->post('/ui/stock/handovers', $start)->location(), 'one draft however often the form is sent');
        $page = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        $id = (int) basename($page);
        $r = $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $sku, 'qty' => '5', 'price' => '2.40']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('HANDOVER_NOTICE', 'added', 5, 1), $web->follow($r)->text());
        $draft = $web->get($page);
        self::assertStringContainsString(Words::HANDOVER_ERROR['company_not_confirmed'], $draft->text(), 'the page says the invoice waits, before anyone asks for it');
        // Make it final; the same form sent again books nothing twice.
        $final = ['action' => 'post'] + $draft->form($page . '/lines', true);
        $r = $web->post($page . '/lines', $final);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('HANDOVER_NOTICE', 'posted', 'TRD-000001'), $web->follow($r)->text());
        $again = $web->post($page . '/lines', $final);
        self::assertSame(409, $again->status, $again->describe());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$id]));
        self::assertSame(25, (int) self::$db->value("SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = (SELECT id FROM warehouse WHERE code = 'MAIN')", [$sku]));
        // The invoice: refused in words while the company details are not confirmed.
        $pdf = $web->get($page . '/pdf');
        self::assertSame(409, $pdf->status);
        self::assertStringContainsString(Words::HANDOVER_ERROR['company_not_confirmed_posted'], $pdf->text());
        // Confirmed: a copy made final prints; the first one keeps its unconfirmed details.
        $this->confirmCompany();
        $r = $this->submit($web, $page, $page . '/copy');
        self::assertSame(303, $r->status, $r->describe());
        $copy = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        self::assertStringContainsString(Words::HANDOVER_NOTICE['copied'], $web->follow($r)->text());
        $r = $this->submit($web, $copy, $copy . '/lines', ['action' => 'post']);
        self::assertSame(303, $r->status, $r->describe());
        $inv = $web->get($copy . '/pdf');
        self::assertSame(200, $inv->status, $inv->describe());
        self::assertSame('application/pdf', $inv->header('content-type'));
        self::assertStringStartsWith('%PDF-', $inv->body);
        self::assertSame(409, $web->get($page . '/pdf')->status);
        // Cancel the first: the stock comes back, the balance goes down.
        $r = $this->submit($web, $page, $page . '/reverse', ['reason_code' => 'entered_in_error']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('HANDOVER_NOTICE', 'reversed', 'TRD-000003'), $web->follow($r)->text());
        // 5 x 2.40 = 12.00 + 20% = 14.40 owed for the copy.
        $bal = $web->get('/ui/stock/handovers/balances');
        self::assertStringContainsString('£14.40', $bal->text());
        $paymentPath = '/ui/stock/handovers/balances/' . $cp . '/payments';
        $r = $web->post($paymentPath, ['amount' => '10.00', 'reference' => 'BACS 99'] + $bal->form($paymentPath));
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('BALANCE_NOTICE', 'paid', '£4.40'), $web->follow($r)->text());
        $over = $web->post($paymentPath, ['amount' => '10.00', 'reference' => 'BACS 100']
            + $web->get('/ui/stock/handovers/balances')->form($paymentPath));
        self::assertSame(409, $over->status);
        self::assertStringContainsString(Words::say('BALANCE_ERROR', 'more_than_owed', '£4.40'), $over->text());
        // The list and its download; the customer's page with its remembered price.
        self::assertStringContainsString('TRD-000002', $web->get('/ui/stock/handovers')->text());
        $csv = $web->get('/ui/stock/handovers.csv');
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('TRD-000002', $csv->body);
        $customer = $web->get('/ui/stock/handovers/customers/' . $cp);
        self::assertSame(200, $customer->status);
        self::assertStringContainsString('£2.40', $customer->text());
    }

    public function testPaymentRetriesRecordOneEntryAndRefusalsKeepTheFormKey(): void
    {
        $sku = $this->item('legacy', 2, 'Payment retry item');
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $cp = (int) self::$db->insert("INSERT INTO counterparty (kind, name, vat_code, created_actor) VALUES ('trade', 'Payment Retry Ltd', 'Z', 'test')");
        $r = $web->post('/ui/stock/handovers', ['counterparty' => (string) $cp]
            + $web->get('/ui/stock/handovers')->form('/ui/stock/handovers', true));
        self::assertSame(303, $r->status, $r->describe());
        $page = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $sku, 'qty' => '1', 'price' => '1000.00'])->status);
        self::assertSame(303, $this->submit($web, $page, $page . '/lines', ['action' => 'post'])->status);
        $balances = '/ui/stock/handovers/balances';
        $path = $balances . '/' . $cp . '/payments';
        $form = $web->get($balances)->form($path);
        self::assertArrayHasKey('form_key', $form);
        $pay = ['amount' => '100.00', 'reference' => 'BACS-RETRY'] + $form;
        $missing = $pay;
        unset($missing['form_key']);
        $badKey = $web->post($path, $missing);
        self::assertSame(400, $badKey->status);
        self::assertSame('bad_form_key', $badKey->errorCode());

        $first = $web->post($path, $pay);
        self::assertSame(303, $first->status, $first->describe());
        $again = $web->post($path, $pay);
        self::assertSame(303, $again->status, $again->describe());
        self::assertSame($first->location(), $again->location());
        self::assertSame('900.00', (new \CW\Handovers\CounterpartyAccounts(self::$db))->balance($cp));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM counterparty_entry WHERE counterparty_id = ? AND kind = 'payment'", [$cp]));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'counterparty.payment'"));
        $changed = $web->post($path, ['amount' => '200.00'] + $pay);
        self::assertSame(422, $changed->status);
        self::assertSame('idempotency_key_reused', $changed->errorCode());

        $fresh = $web->get($balances)->form($path);
        $over = $web->post($path, ['amount' => '1000.00', 'reference' => 'BACS-CORRECT'] + $fresh);
        self::assertSame(409, $over->status);
        self::assertSame($fresh['form_key'], $over->form($path)['form_key']);
        $corrected = $web->post($path, ['amount' => '50.00'] + $over->form($path));
        self::assertSame(303, $corrected->status, $corrected->describe());
        self::assertSame('850.00', (new \CW\Handovers\CounterpartyAccounts(self::$db))->balance($cp));
    }

    /** Review finding 3 (Codex, 14 Oct): the generic record PDF of a hand-over is held to the same rule as its invoice. */
    public function testTheRecordsPdfOfAHandoverWaitsForTheCompanyDetailsToo(): void
    {
        $sku = $this->item('legacy', 10, 'Theta liquid');
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $cp = (int) self::$db->insert("INSERT INTO counterparty (kind, name, vat_code, created_actor) VALUES ('sister', 'Gate Test Ltd', 'S', 'test')");
        $r = $web->post('/ui/stock/handovers', ['counterparty' => (string) $cp] + $web->get('/ui/stock/handovers')->form('/ui/stock/handovers', true));
        $page = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        $id = (int) basename($page);
        $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $sku, 'qty' => '2', 'price' => '3.00']);
        self::assertSame(303, $this->submit($web, $page, $page . '/lines', ['action' => 'post'])->status);
        $pdf = $web->get('/ui/documents/' . $id . '/pdf');
        self::assertSame(409, $pdf->status, $pdf->describe());
        self::assertStringContainsString(Words::HANDOVER_ERROR['company_not_confirmed_posted'], $pdf->text());
        self::assertStringNotContainsString('%PDF-', $pdf->body);
    }

    public function testTheCompanyDetailsTakeTheVatCodeOfOurInvoices(): void
    {
        $web = $this->signIn($this->uiUser('reviewer'));
        $edit = $web->get('/ui/reference/company/edit');
        self::assertSame(200, $edit->status, $edit->describe());
        self::assertStringContainsString(Words::COMPANY['currency_line'], $edit->text());
        $form = $edit->form('/ui/reference/company', true);
        self::assertArrayHasKey('vat_code', $form);
        $r = $web->post('/ui/reference/company', ['legal_name' => 'Example Vapes Ltd', 'vat_registered' => 'yes', 'vat_number' => 'GB123456782', 'vat_code' => 'S'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['S', 'GBP'], [(new CompanyDetails(self::$db))->current()['vat_code'], (new CompanyDetails(self::$db))->current()['currency']]);
        $show = $web->get('/ui/reference/company');
        self::assertStringContainsString(Words::COMPANY['vat_code'], $show->text());
        self::assertStringContainsString(Words::COMPANY['currency_gbp'], $show->text());
        $bad = $web->post('/ui/reference/company', ['vat_code' => 'QQ', 'version' => '1'] + $web->get('/ui/reference/company/edit')->form('/ui/reference/company', true));
        self::assertSame(422, $bad->status, $bad->describe());
    }

    public function testTheDutyRegisterPageAndItsDownload(): void
    {
        $web = $this->signIn($this->uiUser('goods_in'));
        $page = $web->get('/ui/receiving/duty');
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::DUTY['none'], $page->text());
        self::assertStringContainsString(Words::DUTY['held_title'], $page->text());
        self::assertSame(200, $web->get('/ui/receiving/duty', ['stamp' => 'unstamped', 'from' => '2026-10-01', 'q' => 'x'])->status);
        $csv = $web->get('/ui/receiving/duty.csv');
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('received_at_utc', $csv->body);
        // Something unstamped held: shown with where it is.
        $sku = $this->item('legacy', 0, 'Held back liquid');
        $this->book('adjustment', $sku, 3, 'UNSTAMPED');
        $held = $web->get('/ui/receiving/duty');
        self::assertStringContainsString('Held back liquid', $held->text());
        self::assertStringContainsString(Words::say('DUTY', 'must_empty', 3, '1 Apr 2027'), $held->text());
    }

    public function testEveryPostNeedsItsJobAndTheCsrfToken(): void
    {
        $deskUser = $this->uiUser('purchasing_desk');
        $desk = $this->signIn($deskUser);
        $cp = (int) self::$db->insert("INSERT INTO counterparty (kind, name, created_actor) VALUES ('trade', 'Token Test Ltd', 'test')");
        $r = $desk->post('/ui/stock/handovers', ['counterparty' => (string) $cp] + $desk->get('/ui/stock/handovers')->form('/ui/stock/handovers', true));
        self::assertSame(303, $r->status, $r->describe());
        $page = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        $buyer = $this->signIn($this->uiUser('buyer'));
        $admin = $this->signIn($this->uiUser('admin'));
        $posts = ['/ui/stock/handovers', '/ui/stock/handovers/customers', '/ui/stock/handovers/customers/prices', '/ui/stock/handovers/customers/' . $cp,
            '/ui/stock/handovers/customers/' . $cp . '/active', '/ui/stock/handovers/balances/' . $cp . '/payments', '/ui/stock/handovers/balances/payments/1/reverse'];
        foreach (['details', 'lines', 'add', 'post', 'cancel', 'reverse', 'copy'] as $action) {
            $posts[] = $page . '/' . $action;
        }
        foreach ($posts as $path) {
            self::assertSame(403, $buyer->post($path, ['csrf' => $this->token($buyer), 'version' => '1', 'form_key' => str_repeat('a', 32)])->status, "buyer {$path}");
            self::assertSame(403, $admin->post($path, ['csrf' => $this->token($admin), 'version' => '1', 'form_key' => str_repeat('b', 32)])->status, "admin {$path}");
            self::assertSame(403, $desk->post($path, ['version' => '1', 'form_key' => str_repeat('c', 32)])->status, "no token {$path}");
        }
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'TRD'"), 'nothing was made by a refused POST');
        // A stock controller reads hand-overs and the duty register, and keeps none.
        $sc = $this->signIn($this->uiUser('stock_controller'));
        self::assertSame(200, $sc->get($page)->status);
        self::assertSame(403, $sc->post($page . '/post', ['csrf' => $this->token($sc), 'version' => '1'])->status);
        self::assertSame(200, $sc->get('/ui/receiving/duty')->status);
    }
}
