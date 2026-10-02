<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Company\CompanyDetails;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Kernel;

/**
 * The Company details screen through the real /ui kernel as cw_app (I90-I97), the owner's request "add an option in the
 * setting where I can add or edit these details": everyone finds it (Reference > Company details, and from Settings) and
 * reads it; the owner (reviewer + mapping_lead) adds the details on one form (sent twice: one version), confirms them and the
 * "do not send" banner goes; a buyer and admin see no form and are refused 403 even when they POST; problems are shown at
 * their fields with what was typed kept; a stale form (someone saved meanwhile) writes nothing and says what changed; CSRF;
 * the confirm needs the required details; a change of confirmed details goes to another reviewer through the review queue;
 * the sample PDF; phone-width markup.
 */
final class CompanyScreensTest extends KernelUiTestCase
{
    /** @return array<string, string> the details as typed on the form */
    private static function typed(array $over = []): array
    {
        return $over + ['legal_name' => 'Example Vapes Ltd', 'trading_name' => 'Vape and Go', 'company_number' => '01234567', 'address' => "1 High Street\r\nLeeds\r\nLS1 1AA",
            'vat_registered' => 'yes', 'vat_number' => 'GB 123 4567 82', 'phone' => '0113 496 0000', 'email' => 'buying@example.co.uk',
            'delivery_address' => "Unit 4, Example Park\r\nLeeds LS2 2BB", 'reason' => ''];
    }

    /** The edit form's fields as a browser would send them, with $over typed in. @param array<string, string> $over @return array<string, string> */
    private static function editForm(KernelBrowser $web, array $over = []): array
    {
        $page = $web->get('/ui/reference/company/edit');
        self::assertSame(200, $page->status, $page->describe());
        return $over + $page->form('/ui/reference/company', true);
    }

    private static function versions(): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM company_profile');
    }

    public function testTheOwnerAddsAndConfirmsTheDetailsAndTheBannerGoes(): void
    {
        $owner = $this->signIn($this->uiUser(['reviewer', 'mapping_lead']));

        // Found from the menu and from Settings.
        $settings = $owner->get('/ui/reference/settings');
        self::assertSame(200, $settings->status, $settings->describe());
        $xp = new \DOMXPath($settings->dom());
        self::assertSame('Add or change the company details', trim((string) $xp->query('//section[contains(@class, "company-summary")]//a')->item(0)?->textContent));
        self::assertStringContainsString('Not confirmed Every purchase order PDF says "do not send" until they are confirmed. Still missing: legal name, company number',
            $settings->text());
        $page = $owner->get('/ui/reference/company');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['label' => 'Company details', 'href' => '/ui/reference/company'], self::nav($page)['Reference'][3]);
        self::assertSame('Company details', trim((string) (new \DOMXPath($page->dom()))->query('//nav//a[@aria-current="page"]')->item(0)?->textContent));
        self::assertStringContainsString('Not confirmed Every purchase order PDF says "COMPANY DETAILS NOT CONFIRMED — DO NOT SEND"', $page->text());
        self::assertStringContainsString('Still missing: legal name, company number, registered address, VAT number (or "not VAT registered"), purchasing e-mail, '
            . 'delivery address.', $page->text());
        self::assertFalse($page->hasForm('/ui/reference/company/confirm'), 'nothing to confirm yet');
        self::assertContains('/ui/reference/company/edit', $page->hrefs());
        self::assertContains('/ui/reference/company/sample.pdf', $page->hrefs());
        self::assertStringContainsString('No details were saved yet.', $page->text());

        // One form, every field; sent twice: one version.
        $f = self::editForm($owner, self::typed(['reason' => 'details from Companies House']));
        self::assertSame('0', $f['version']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $f['form_key']);
        $r = $owner->post('/ui/reference/company', $f);
        self::assertSame([303, '/ui/reference/company?notice=saved'], [$r->status, $r->location()], $r->describe());
        $again = $owner->post('/ui/reference/company', $f);
        self::assertSame([303, '/ui/reference/company?notice=saved'], [$again->status, $again->location()], 'the replay lands on the same page');
        self::assertSame(1, self::versions());
        $page = $owner->follow($r);
        self::assertStringContainsString('Saved. The details are not confirmed yet', $page->text());
        foreach (['Example Vapes Ltd', 'Vape and Go', '01234567', 'GB 123 4567 82', '0113 496 0000', 'buying@example.co.uk', 'Unit 4, Example Park'] as $shown) {
            self::assertStringContainsString($shown, $page->text(), $shown);
        }
        self::assertStringContainsString("1 High Street\nLeeds\nLS1 1AA", (string) (new \DOMXPath($page->dom()))->query('//dl[contains(@class, "company")]/dd[5]')->item(0)?->textContent);
        self::assertStringContainsString('Version 1 · changed by Reviewer-mapping_lead 1', $page->text());
        self::assertStringContainsString('Why: details from Companies House', $page->text());

        // Confirm: the form carries the version shown; the banner goes from what a PO prints.
        $confirm = $page->form('/ui/reference/company/confirm');
        self::assertSame(['csrf', 'form_key', 'version'], array_keys($confirm));
        self::assertSame('1', $confirm['version']);
        self::assertStringContainsString('These details are correct', $page->text());
        $c = $owner->post('/ui/reference/company/confirm', $confirm);
        self::assertSame('/ui/reference/company?notice=confirmed', $c->location(), $c->describe());
        self::assertSame('/ui/reference/company?notice=confirmed', $owner->post('/ui/reference/company/confirm', $confirm)->location(), 'sent twice: one confirmation');
        self::assertSame(2, self::versions());
        $page = $owner->follow($c);
        self::assertStringContainsString('Confirmed: purchase orders now print these details without the "do not send" banner.', $page->text());
        self::assertStringContainsString('Confirmed Confirmed by Reviewer-mapping_lead 1 on ', $page->text());
        self::assertFalse($page->hasForm('/ui/reference/company/confirm'));
        $company = (new CompanyDetails(self::$db))->company();
        self::assertSame([true, 2, 'GB123456782', "1 High Street\nLeeds\nLS1 1AA"], [$company['confirmed'], $company['version'], $company['vat_number'], $company['address']]);
        self::assertSame('company.confirm', (string) self::$db->value("SELECT action FROM audit_log WHERE action LIKE 'company.%' ORDER BY id DESC LIMIT 1"));

        // The sample PDF: no PO number, marked SAMPLE, the details in use, an attachment under the download CSP.
        $pdf = $owner->get('/ui/reference/company/sample.pdf');
        self::assertSame([200, 'application/pdf'], [$pdf->status, $pdf->header('content-type')]);
        self::assertSame('attachment; filename="purchase-order-sample.pdf"; filename*=UTF-8\'\'purchase-order-sample.pdf', $pdf->header('content-disposition'));
        self::assertSame(['sandbox', Kernel::CSP], $pdf->headerValues('content-security-policy'));
        self::assertStringStartsWith('%PDF-', $pdf->body);
    }

    public function testBuyersAndAdminLookButAreRefusedEvenWhenTheyPost(): void
    {
        foreach ([['buyer', 'Your role (buyer) can look at the company details but not change them: a reviewer does.'],
            [['admin', 'auditor'], 'Your roles (admin, auditor) can look at the company details but not change them: a reviewer does.']] as [$roles, $why]) {
            $web = $this->signIn($this->uiUser($roles));
            $page = $web->get('/ui/reference/company');
            self::assertSame(200, $page->status, $page->describe());
            self::assertStringContainsString($why, $page->text());
            self::assertNotContains('/ui/reference/company/edit', $page->hrefs());
            self::assertFalse($page->hasForm('/ui/reference/company/confirm'));
            self::assertSame('See the company details', trim((string) (new \DOMXPath($web->get('/ui/reference/settings')->dom()))
                ->query('//section[contains(@class, "company-summary")]//a')->item(0)?->textContent));
            self::assertSame(403, $web->get('/ui/reference/company/edit')->status);
            $token = $this->token($web);
            foreach (['/ui/reference/company' => self::typed(['version' => '0', 'form_key' => str_repeat('a', 32)]),
                '/ui/reference/company/confirm' => ['version' => '0', 'form_key' => str_repeat('b', 32)],
                '/ui/reference/company/reviews/1/approve' => [], '/ui/reference/company/reviews/1/reject' => ['note' => 'not ours']] as $path => $form) {
                $r = $web->post($path, ['csrf' => $token] + $form);
                self::assertSame(403, $r->status, "{$path}: " . $r->describe());
                self::assertStringContainsString('role_not_allowed', $r->text());
            }
            self::assertSame(200, $web->get('/ui/reference/company/sample.pdf')->status, 'everyone may see how a PO looks');
        }
        self::assertSame(0, self::versions());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE '%company%'"));
    }

    public function testProblemsStaleFormsAndCsrf(): void
    {
        $alice = $this->uiUser('reviewer');
        $bob = $this->uiUser('reviewer');
        $a = $this->signIn($alice);
        $b = $this->signIn($bob);

        // Problems are shown at their fields; what was typed comes back; nothing is saved.
        $f = self::editForm($a, self::typed(['company_number' => '1234567', 'vat_number' => 'GB 12', 'delivery_address' => "Unit 4\r\nŁódź"]));
        $bad = $a->post('/ui/reference/company', $f);
        self::assertSame(422, $bad->status, $bad->describe());
        self::assertStringContainsString('Nothing was saved: some details need correcting (marked below).', $bad->text());
        $xp = new \DOMXPath($bad->dom());
        self::assertStringContainsString('keep the leading zeros', (string) $xp->query('//p[@id="e-company_number"]')->item(0)?->textContent);
        self::assertSame('true', $xp->query('//input[@name="company_number"]')->item(0)?->getAttribute('aria-invalid'));
        self::assertSame('e-company_number', $xp->query('//input[@name="company_number"]')->item(0)?->getAttribute('aria-describedby'));
        self::assertStringContainsString('GB and 9 digits', (string) $xp->query('//p[@id="e-vat_number"]')->item(0)?->textContent);
        self::assertStringContainsString('"Ł", which a purchase order cannot print', (string) $xp->query('//p[@id="e-delivery_address"]')->item(0)?->textContent);
        $kept = $bad->form('/ui/reference/company', true);
        self::assertSame(['1234567', 'GB 12', "Unit 4\nŁódź", $f['form_key'], '0'], [$kept['company_number'], $kept['vat_number'],
            str_replace("\r", '', $kept['delivery_address']), $kept['form_key'], $kept['version']]);
        self::assertSame(0, self::versions());

        // Both open the form at version 0; Alice saves; Bob's save is stale: nothing written, he is told what changed and keeps what he typed.
        $fa = self::editForm($a);
        $fb = self::editForm($b, self::typed(['phone' => '0113 496 0999']));
        self::assertSame('/ui/reference/company?notice=saved', $a->post('/ui/reference/company', self::typed() + $fa)->location());
        $stale = $b->post('/ui/reference/company', $fb);
        self::assertSame(409, $stale->status, $stale->describe());
        self::assertStringContainsString('Someone changed the company details while you had them open (Reviewer 1 saved version 1 at ', $stale->text());
        self::assertStringContainsString('What changed meanwhile (your form still shows what you typed; saving it now replaces these):', $stale->text());
        self::assertStringContainsString('Legal name Was: (empty) Now: Example Vapes Ltd', $stale->text());
        $again = $stale->form('/ui/reference/company', true);
        self::assertSame(['1', '0113 496 0999'], [$again['version'], $again['phone']]);
        self::assertSame(1, self::versions());
        self::assertSame('/ui/reference/company?notice=saved', $b->post('/ui/reference/company', $again)->location(), 'saving again is a deliberate overwrite');
        self::assertSame(['0113 496 0999', 2], [(new CompanyDetails(self::$db))->current()['phone'], self::versions()]);

        // A confirmation of a version that is no longer current: 409, the page shows the details as they are now.
        $old = $a->get('/ui/reference/company')->form('/ui/reference/company/confirm');
        self::assertSame('2', $old['version']);
        self::assertSame('/ui/reference/company?notice=saved', $b->post('/ui/reference/company', self::editForm($b, ['email' => 'orders@example.co.uk']))->location());
        $late = $a->post('/ui/reference/company/confirm', $old);
        self::assertSame(409, $late->status);
        self::assertStringContainsString('Here are the details as they are now: check them, then confirm again.', $late->text());
        self::assertStringContainsString('orders@example.co.uk', $late->text());
        self::assertFalse((new CompanyDetails(self::$db))->current()['confirmed']);

        // CSRF: no token, another session's token, a made-up one: 403, nothing saved.
        $f = self::editForm($a, ['legal_name' => 'Forged Ltd']);
        foreach (['no token' => array_diff_key($f, ['csrf' => 1]), "another person's token" => ['csrf' => $this->token($b)] + $f, 'made up' => ['csrf' => 'x'] + $f] as $what => $form) {
            $r = $a->post('/ui/reference/company', $form);
            self::assertSame(403, $r->status, $what);
            self::assertStringContainsString('csrf', $r->text(), $what);
        }
        self::assertSame(3, self::versions());
        self::assertSame(400, $a->post('/ui/reference/company', ['version' => 'x'] + $f)->status, 'a form without a version');
        self::assertSame(400, $a->post('/ui/reference/company', ['form_key' => 'nope'] + $f)->status, 'a form without a valid form key');

        // Confirming before every required detail is there: no form on the page, a forced POST says what is missing.
        $b->post('/ui/reference/company', self::editForm($b, ['delivery_address' => '', 'vat_registered' => '', 'vat_number' => '']));
        $page = $a->get('/ui/reference/company');
        self::assertFalse($page->hasForm('/ui/reference/company/confirm'));
        self::assertStringContainsString('Still missing: VAT number (or "not VAT registered"), delivery address.', $page->text());
        $r = $a->post('/ui/reference/company/confirm', ['csrf' => $this->token($a), 'version' => '4', 'form_key' => str_repeat('c', 32)]);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString('Before the details can be confirmed, fill in: VAT number (or "not VAT registered"), delivery address.', $r->text());
    }

    public function testConfirmingYourOwnChangeGoesToAnotherReviewer(): void
    {
        $ownerUser = $this->uiUser(['reviewer', 'mapping_lead']);
        $svc = new CompanyDetails(self::$db);
        $svc->save(Caller::staff($ownerUser['id']), 0, self::typed());
        $svc->confirm(Caller::staff($ownerUser['id']), 1);
        $owner = $this->signIn($ownerUser);
        $edit = $owner->get('/ui/reference/company/edit');
        self::assertStringContainsString('These details are confirmed. Saving a change makes them "not confirmed" until someone confirms them again. If you confirm your '
            . 'own change of the legal name, company number, VAT, purchasing e-mail or delivery address, another reviewer is asked to check it.', $edit->text());
        $r = $owner->post('/ui/reference/company', ['delivery_address' => "Unit 9, Elsewhere\r\nBradford BD1 1AA", 'reason' => 'moved'] + $edit->form('/ui/reference/company', true));
        self::assertSame('/ui/reference/company?notice=saved', $r->location(), $r->describe());
        $page = $owner->follow($r);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM review_task WHERE subject_type = 'company'"), 'a save asks nobody: the details are unconfirmed');
        self::assertStringContainsString('Delivery address Was: Unit 4, Example Park Leeds LS2 2BB Now: Unit 9, Elsewhere Bradford BD1 1AA', $page->text());

        // One person may change and confirm (I94): the owner confirms the new address, which asks another reviewer to check it.
        $c = $owner->post('/ui/reference/company/confirm', $page->form('/ui/reference/company/confirm'));
        self::assertSame('/ui/reference/company?notice=confirmed_review', $c->location(), $c->describe());
        $page = $owner->follow($c);
        self::assertStringContainsString('You confirmed your own change of the legal name, company number, VAT, purchasing e-mail or delivery address, so another '
            . 'reviewer is asked to check it (it stops nothing).', $page->text());
        self::assertTrue($svc->company()['confirmed']);
        self::assertStringContainsString('Version 4, confirmed by Reviewer-mapping_lead 1 on ', $page->text());
        self::assertStringContainsString('What changed since the details were last confirmed (version 2): Delivery address Was: Unit 4, Example Park Leeds LS2 2BB Now: '
            . 'Unit 9, Elsewhere Bradford BD1 1AA', $page->text());
        self::assertStringContainsString('You made or confirmed this change: another reviewer must check it.', $page->text());
        // The owner is the only reviewer: the check waits, calmly (no due date, never "overdue").
        self::assertStringContainsString('Nobody else holds the reviewer role yet, so this check stays open. It stops nothing', $page->text());
        self::$db->exec("UPDATE review_task SET due_at = '2026-01-01 00:00:00' WHERE subject_type = 'company'");
        self::assertStringNotContainsString('overdue', $owner->get('/ui/reference/company')->text());
        self::assertFalse($page->hasForm('/approve'));
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'company' AND state = 'open'");
        self::assertSame(403, $owner->post("/ui/reference/company/reviews/{$task}/approve", ['csrf' => $this->token($owner)])->status, 'never their own change');

        // Another reviewer finds it in the review queue (and its badge), labelled with what changed, and decides it on the Company
        // details page; now that someone could, the owner's page shows it as overdue.
        $second = $this->signIn($this->uiUser('reviewer'));
        self::assertStringContainsString('overdue', $owner->get('/ui/reference/company')->text());
        $queue = $second->get('/ui/documents/reviews');
        self::assertSame(200, $queue->status, $queue->describe());
        self::assertStringContainsString('Company details, version 4 (delivery address) Company details company changed', $queue->text());
        self::assertContains('/ui/reference/company', $queue->hrefs());
        self::assertSame('Review queue 1', self::nav($queue)['Document reviews'][0]['label']);
        self::assertStringContainsString('Company details, version 4', $second->get('/ui/documents/reviews', ['type' => 'Company'])->text());
        self::assertStringNotContainsString('Company details, version 4', $second->get('/ui/documents/reviews', ['type' => 'PO'])->text());
        self::assertStringNotContainsString('class="badge"', $owner->get('/ui/')->body, 'the owner cannot decide it: no count');
        $page = $second->get('/ui/reference/company');
        $reject = $page->form("/ui/reference/company/reviews/{$task}/reject");
        self::assertSame(['csrf', 'note'], array_keys($reject));
        $r = $second->post("/ui/reference/company/reviews/{$task}/reject", ['note' => 'that is not our warehouse'] + $reject);
        self::assertSame('/ui/reference/company?notice=review_rejected', $r->location(), $r->describe());
        $page = $second->follow($r);
        self::assertStringContainsString('Recorded: you rejected the change. The details in use carried it, so they are not confirmed any more', $page->text());
        self::assertStringContainsString('Not confirmed', $page->text());
        self::assertStringContainsString('made unconfirmed by Reviewer 2', $page->text());
        self::assertStringContainsString('rejected by Reviewer 2 on ', $page->text());
        self::assertStringContainsString('that is not our warehouse', $page->text());
        self::assertStringContainsString('check: rejected', $page->text());
        self::assertFalse($svc->company()['confirmed']);
        self::assertSame(409, $second->post("/ui/reference/company/reviews/{$task}/approve", ['csrf' => $this->token($second)])->status, 'decided once');

        // The owner cannot confirm the rejected address again (review finding): the button is there, the answer is 403 with why.
        $again = $owner->get('/ui/reference/company');
        $no = $owner->post('/ui/reference/company/confirm', $again->form('/ui/reference/company/confirm'));
        self::assertSame(403, $no->status, $no->describe());
        self::assertStringContainsString('A reviewer rejected this change of the delivery address (Reviewer 2: "that is not our warehouse"). You made or confirmed that '
            . 'change, so another reviewer must confirm it; or change the details.', $no->text());
        self::assertFalse($svc->company()['confirmed']);
    }

    /** I98: an order approved with details a reviewer later rejected is listed on the Company details page and says so itself. */
    public function testOrdersCarryingARejectedChangeAreListed(): void
    {
        $ownerUser = $this->uiUser(['reviewer', 'mapping_lead']);
        $secondUser = $this->uiUser('reviewer');
        $buyerUser = $this->uiUser('buyer');
        $owner = Caller::staff($ownerUser['id']);
        $svc = new CompanyDetails(self::$db);
        $svc->save($owner, 0, self::typed());
        $svc->confirm($owner, 1);
        $svc->save($owner, 2, self::typed(['delivery_address' => "Unit 66, Somewhere Odd\nHull HU1 1AA"]));
        $task = (int) $svc->confirm($owner, 3)['review_task'];

        // A buyer approves an order with the confirmed (diverted) details before anyone checks them.
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyerUser['id']), ['name' => 'Odd Supplies', 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA', 'email' => 'sales@odd.example',
            'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400), 'dd_checked_by' => (string) $buyerUser['id'],
            'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400), 'min_order_value' => '100.00']);
        $s = $sup->requestActivation(Caller::staff($buyerUser['id']), (int) $s['id'], (int) $s['version']);
        $sup->approve(Caller::staff($secondUser['id']), (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'",
            [(int) $s['id']]), null);
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $pos->createDraft(Caller::staff($buyerUser['id']), (int) $s['id'], []);
        $d = $pos->saveDraft(Caller::staff($buyerUser['id']), $d->id, $d->version, [], [['kind' => 'item', 'sku_id' => self::makeSku('Odd item'), 'units_per_pack' => 1,
            'packs' => 10, 'pack_price' => '12.00']]);
        $d = $pos->approve(Caller::staff($buyerUser['id']), $d->id, $d->version);

        $second = $this->signIn($secondUser);
        $r = $second->post("/ui/reference/company/reviews/{$task}/reject", ['note' => 'not our warehouse']
            + $second->get('/ui/reference/company')->form("/ui/reference/company/reviews/{$task}/reject"));
        self::assertSame('/ui/reference/company?notice=review_rejected', $r->location(), $r->describe());
        $page = $second->follow($r);
        self::assertStringContainsString('A reviewer rejected a change of these details, and 1 approved purchase order still carries it (their PDF says "do not send"). '
            . 'Cancel or amend it:', $page->text());
        self::assertContains("/ui/purchasing/orders/{$d->id}", $page->hrefs());
        self::assertStringContainsString((string) $d->number . ' (approved)', $page->text());

        // The order's own page says so, with the words that fit (it is not "add or confirm").
        $buyer = $this->signIn($buyerUser);
        $view = $buyer->get("/ui/purchasing/orders/{$d->id}");
        self::assertStringContainsString('This order was approved with company details that include a change a reviewer rejected: its PDF says "company details rejected at '
            . 'review - do not send". Cancel or amend it. See the company details', $view->text());
        self::assertStringContainsString('include a change a reviewer rejected (see Company details): cancel or amend it rather than send it.', $view->text());
        $pdf = $buyer->get("/ui/purchasing/orders/{$d->id}/pdf");
        self::assertSame(200, $pdf->status, $pdf->describe());
    }

    /** A seed copied from the old settings that is valid but not tidy offers no confirm button (it would be refused) and says why. */
    public function testASeedThatIsNotTidyYetSaysToSaveItOnce(): void
    {
        self::$db->exec("INSERT INTO company_profile (version, kind, legal_name, company_number, vat_registered, vat_number, address, email, delivery_address, saved_actor) "
            . "VALUES (1, 'seed', 'Seeded  Ltd', '01234567', 1, 'GB123456782', '1 High Street', 'a@example.co.uk', 'Unit 4', 'system:migrate')");
        self::$db->exec("INSERT INTO audit_log (actor, action, entity_type, entity_id, detail) VALUES ('system:migrate', 'company.change', 'company_profile', '1', '{}')");
        $web = $this->signIn($this->uiUser('reviewer'));
        $page = $web->get('/ui/reference/company');
        self::assertFalse($page->hasForm('/ui/reference/company/confirm'));
        self::assertStringContainsString('These details were copied from the old settings. Press "Change the details" and Save once (spaces and line breaks are tidied), '
            . 'then confirm them here.', $page->text());
        self::assertStringContainsString('Version 1 · copied from the old settings', $page->text());
        self::assertStringContainsString('by the set-up (copied from the old settings)', $page->text());
        self::assertStringNotContainsString('system:', $page->text());
        // The form shows the tidy values; saving them once makes the details confirmable.
        $form = self::editForm($web);
        self::assertSame('Seeded Ltd', $form['legal_name']);
        self::assertSame('/ui/reference/company?notice=saved', $web->post('/ui/reference/company', $form)->location());
        self::assertTrue($web->get('/ui/reference/company')->hasForm('/ui/reference/company/confirm'));

        // A confirmed seed that breaks today's rules shows its problems too.
        self::$db->exec('DELETE FROM company_profile');
        self::$db->exec("DELETE FROM audit_log WHERE entity_type = 'company_profile'");
        self::$db->exec("INSERT INTO company_profile (version, kind, legal_name, company_number, vat_registered, vat_number, address, email, delivery_address, confirmed, "
            . "confirmed_actor, confirmed_at, saved_actor) VALUES (1, 'seed', 'Seeded Ltd', 'not a number', 1, 'GB 12', 'x', 'a@example.co.uk', 'y', 1, 'system:settings', "
            . "NOW(6), 'system:migrate')");
        self::$db->exec("INSERT INTO audit_log (actor, action, entity_type, entity_id, detail) VALUES ('system:migrate', 'company.change', 'company_profile', '1', '{}')");
        $page = $web->get('/ui/reference/company');
        self::assertStringContainsString('Confirmed by the old settings on ', $page->text());
        self::assertStringContainsString('These details need correcting (they were confirmed before today\'s checks):', $page->text());
        self::assertStringContainsString('keep the leading zeros', $page->text());
    }

    /** The same form sent again with other values after it was saved (the back button) comes back ready to save, not stale. */
    public function testAFormSentAgainWithOtherValuesComesBackReadyToSave(): void
    {
        $web = $this->signIn($this->uiUser('reviewer'));
        $f = self::editForm($web, self::typed());
        self::assertSame('/ui/reference/company?notice=saved', $web->post('/ui/reference/company', $f)->location());
        $back = $web->post('/ui/reference/company', ['phone' => '0113 496 0999'] + $f);
        self::assertSame(409, $back->status, $back->describe());
        self::assertStringContainsString('You already saved this form once. What you typed is kept below: check it and press Save again.', $back->text());
        $again = $back->form('/ui/reference/company', true);
        self::assertSame(['1', '0113 496 0999'], [$again['version'], $again['phone']]);
        self::assertNotSame($f['form_key'], $again['form_key']);
        self::assertSame('/ui/reference/company?notice=saved', $web->post('/ui/reference/company', $again)->location(), 'the next Save works');
        self::assertSame('0113 496 0999', (new CompanyDetails(self::$db))->current()['phone']);
    }

    public function testPhoneWidthMarkup(): void
    {
        $web = $this->signIn($this->uiUser('reviewer'));
        foreach (['/ui/reference/company', '/ui/reference/company/edit'] as $path) {
            $page = $web->get($path);
            self::assertSame(200, $page->status);
            $xp = new \DOMXPath($page->dom());
            self::assertSame('width=device-width, initial-scale=1', $xp->query('//meta[@name="viewport"]')->item(0)?->getAttribute('content'));
            self::assertSame(0, $xp->query('//main//table')->length, "{$path}: no table to scroll sideways on a phone");
            self::assertSame(0, $xp->query('//main//*[@style or @width or @size]')->length, "{$path}: no fixed widths");
        }
        $form = new \DOMXPath($web->get('/ui/reference/company/edit')->dom());
        self::assertSame(1, $form->query('//form[@class="record company"]')->length);
        $named = [];
        foreach ($form->query('//form[@class="record company"]//input[@type!="hidden" and @type!="radio"] | //form[@class="record company"]//textarea') as $field) {
            /** @var \DOMElement $field */
            $named[] = $field->getAttribute('name');
            self::assertSame(1, $form->query('//label[@for="' . $field->getAttribute('id') . '"]')->length, $field->getAttribute('name') . ' has its label');
        }
        self::assertSame(['legal_name', 'trading_name', 'company_number', 'address', 'vat_number', 'phone', 'email', 'delivery_address', 'reason'], $named);
        self::assertSame(['tel', 'email'], [$form->query('//input[@name="phone"]')->item(0)?->getAttribute('type'), $form->query('//input[@name="email"]')->item(0)?->getAttribute('type')]);
        self::assertSame(['yes', 'no', ''], array_map(static fn (\DOMElement $r): string => $r->getAttribute('value'),
            iterator_to_array($form->query('//input[@name="vat_registered"]'))));
        $history = new \DOMXPath($web->get('/ui/reference/company')->dom());
        self::assertSame(0, $history->query('//ol[contains(@class, "versions")]')->length, 'no versions yet');
        (new CompanyDetails(self::$db))->save(Caller::staff((int) self::$db->value("SELECT id FROM staff_user WHERE email LIKE 'k-reviewer-%' LIMIT 1")), 0, self::typed());
        self::assertSame(1, (new \DOMXPath($web->get('/ui/reference/company')->dom()))->query('//ol[contains(@class, "versions")]/li')->length, 'the history is a list');
        // The phone-width rule for the check forms is this page's own (review finding: it changed every inline form of the app).
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/ui/assets/app.css');
        self::assertSame(1, preg_match_all('/^\s*form\.inline\s*\{/m', $css), 'only the app-wide inline-flex rule');
        self::assertStringContainsString('  article.review form.inline { display: flex; }', $css);
    }

    /** Hostile text typed into the details is shown as text on the page, the form and the settings summary. */
    public function testWhatWasTypedIsShownAsText(): void
    {
        $user = $this->uiUser('reviewer');
        $evil = '<script>alert(1)</script>"\'&';
        (new CompanyDetails(self::$db))->save(Caller::staff($user['id']), 0, self::typed(['legal_name' => $evil, 'trading_name' => '"><b>x', 'address' => "<i>1</i>\nLeeds"]));
        $web = $this->signIn($user);
        foreach (['/ui/reference/company', '/ui/reference/company/edit', '/ui/reference/settings'] as $path) {
            $r = $web->get($path);
            self::assertSame(200, $r->status, $path);
            self::assertStringNotContainsString('<script>alert', $r->body, $path);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&quot;&apos;&amp;', $r->body, $path);
        }
        self::assertStringNotContainsString('"><b>x', $web->get('/ui/reference/company/edit')->body);
    }
}
