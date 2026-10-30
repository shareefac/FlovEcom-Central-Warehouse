<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The supplier screens through the real /ui kernel as cw_app (I38-I47), the owner's acceptance step "a supplier approved
 * by a second person": the buyer creates a supplier by form (sending the same form twice makes one supplier), edits it and
 * asks for activation, sees "waiting for a second person" and no decide form (a POST is 403); the reviewer finds it in
 * the review queue (and the badge), approves it on the card; desk and auditor look but cannot change; supplier items by
 * search, the preferred toggle, a manual price; the CSV's formula guard; the settings page; evidence uploads (stored with
 * CW_FILE_STORE_DIR = a temporary directory, 413 above 2 MiB, 503 when the store is not set up).
 */
final class SupplierScreensTest extends KernelUiTestCase
{
    private string|false $prevStore = false;
    private ?string $storeDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
    }

    protected function tearDown(): void
    {
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        if ($this->storeDir !== null && str_starts_with(basename($this->storeDir), 'cw_sst_')) {
            exec('rm -rf ' . escapeshellarg($this->storeDir) . ' ' . escapeshellarg($this->storeDir . '.src'));
        }
        parent::tearDown();
    }

    private static function supplierId(UiResponse $r): int
    {
        self::assertSame(1, preg_match('#^/ui/purchasing/suppliers/(\d+)#', (string) $r->location(), $m), $r->describe());
        return (int) $m[1];
    }

    /** @param array<string, mixed> $over @return array<string, mixed> a complete draft made through the service as $buyerId */
    private function draft(int $buyerId, array $over = []): array
    {
        return (new Suppliers(self::$db))->create(Caller::staff($buyerId), $over + ['name' => 'Service Supplier', 'address_line1' => '2 Mill Lane',
            'postcode' => 'M1 1AA', 'email' => 'sales@service.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyerId, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
    }

    public function testABuyerCreatesAndAsksAndASecondPersonApproves(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $buyer = $this->signIn($buyerUser);
        $list = $buyer->get('/ui/purchasing/suppliers');
        self::assertSame(200, $list->status, $list->describe());
        self::assertStringContainsString(Words::SUPPLIERS['none'], $list->text());
        self::assertContains('/ui/purchasing/suppliers/new', $list->hrefs());
        self::assertSame(['Purchasing', 'Suppliers'], [self::currentSection($list), self::currentTab($list)]);
        self::assertSame(['/ui/purchasing/suppliers/new'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array((new \DOMXPath($list->dom()))->query('//header[contains(@class, "page-head")]//a[contains(concat(" ", @class, " "), " primary ")] | //header[contains(@class, "page-head")]//details[@class="more"]//a'))),
            '"+ New supplier" in the header, alone (DC4)');

        // Create by form; the same form sent twice makes one supplier.
        $new = $buyer->get('/ui/purchasing/suppliers/new');
        self::assertSame(200, $new->status, $new->describe());
        $form = $new->form('/ui/purchasing/suppliers');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $form['form_key'] ?? '');
        $form = ['name' => 'Hollow Vapes Wholesale', 'code' => '', 'email' => 'orders@hollow.example'] + $form;
        $r = $buyer->post('/ui/purchasing/suppliers', $form);
        self::assertSame(303, $r->status, $r->describe());
        $id = self::supplierId($r);
        $again = $buyer->post('/ui/purchasing/suppliers', $form);
        self::assertSame([303, $r->location()], [$again->status, $again->location()], 'the replay lands on the same page');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM supplier'));
        $other = $buyer->post('/ui/purchasing/suppliers', ['name' => 'Something Else'] + $form);
        self::assertSame(422, $other->status);
        self::assertStringContainsString(Words::ERROR['idempotency_key_reused'], $other->text());
        $bad = $buyer->post('/ui/purchasing/suppliers', ['form_key' => 'nope', 'name' => 'X'] + $form);
        self::assertSame(400, $bad->status);
        $refused = $buyer->post('/ui/purchasing/suppliers', ['form_key' => str_repeat('a', 32), 'name' => 'Y', 'email' => 'not an address'] + $form);
        self::assertSame(422, $refused->status);
        self::assertStringContainsString(Words::SUPPLIER_FORM['invalid'], $refused->text());
        self::assertStringContainsString('E-mail: is not an e-mail address.', $refused->text(), 'next to the field, which is marked (F381)');
        self::assertSame(1, (new \DOMXPath($refused->dom()))->query('//input[@name="email" and @aria-invalid="true"]')->length);
        self::assertSame('Y', $refused->form('/ui/purchasing/suppliers')['name'] ?? null, 'the form keeps what was typed');

        $card = $buyer->follow($r);
        self::assertSame(200, $card->status, $card->describe());
        self::assertStringContainsString(Words::SUPPLIER_NOTICE['created'], $card->text());
        self::assertStringContainsString('HOLLOWVAPESW', $card->text());
        self::assertStringContainsString(Words::say('SUPPLIER', 'missing', \CW\Ui\Controller\SuppliersController::fields(['address_line1', 'postcode', 'payment_terms',
            'dd_checked_on', 'dd_checked_by', 'dd_next_review_on'])), $card->text());
        self::assertFalse($card->hasForm('/request-activation'));

        // Edit: complete it; a stale version is refused, and the form is drawn again with what was typed and the current version
        // (behaviour item 9, F382).
        $edit = $buyer->get("/ui/purchasing/suppliers/{$id}/edit");
        self::assertSame(200, $edit->status, $edit->describe());
        $ef = $edit->form("/ui/purchasing/suppliers/{$id}");
        self::assertSame('1', $ef['version']);
        $ef = ['address_line1' => '12 Canal Street', 'postcode' => 'M1 3HP', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 86400),
            'dd_checked_by' => (string) $buyerUser['id'], 'dd_next_review_on' => gmdate('Y-m-d', time() + 200 * 86400), 'dd_evidence' => 'Companies House: active'] + $ef;
        $saved = $buyer->post("/ui/purchasing/suppliers/{$id}", $ef);
        self::assertSame([303, "/ui/purchasing/suppliers/{$id}?notice=saved"], [$saved->status, $saved->location()], $saved->describe());
        $stale = $buyer->post("/ui/purchasing/suppliers/{$id}", ['phone' => '0161 000 0000'] + $ef);
        self::assertSame(409, $stale->status);
        $at = \CW\Ui\Html::when((string) self::$db->value('SELECT updated_at FROM supplier WHERE id = ?', [$id]));
        self::assertStringContainsString(Words::say('SUPPLIER_FORM', 'changed_meanwhile', (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?',
            [$buyerUser['id']]), $at), $stale->text());
        self::assertSame('2', $stale->form("/ui/purchasing/suppliers/{$id}")['version']);
        self::assertSame('12 Canal Street', $stale->form("/ui/purchasing/suppliers/{$id}")['address_line1']);
        self::assertSame('0161 000 0000', $stale->form("/ui/purchasing/suppliers/{$id}")['phone'], 'what was typed is kept');
        self::assertSame(0, (new \DOMXPath($stale->dom()))->query('//p[@class="field-theirs"]')->length, 'typed the same as saved now: nothing to check');

        // Ask for activation: then "waiting for a second person", no decide form for the buyer, and a POST approve is 403.
        $card = $buyer->get("/ui/purchasing/suppliers/{$id}");
        $req = $buyer->post("/ui/purchasing/suppliers/{$id}/request-activation", $card->form('/request-activation'));
        self::assertSame("/ui/purchasing/suppliers/{$id}?notice=requested", $req->location(), $req->describe());
        $card = $buyer->follow($req);
        self::assertStringContainsString(Words::SUPPLIER['waiting'], $card->text());
        self::assertFalse($card->hasForm('/approve'));
        self::assertFalse($card->hasForm('/reject'));
        self::assertStringContainsString(Words::SUPPLIER['look_note'], $card->text(), 'a buyer is told a reviewer decides (F364)');
        self::assertTrue($card->hasForm('/withdraw'), 'the requester may withdraw');
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [$id]);
        $no = $buyer->post("/ui/purchasing/suppliers/tasks/{$task}/approve", ['csrf' => $this->token($buyer)]);
        self::assertSame(403, $no->status);
        self::assertSame('role_not_allowed', $no->errorCode());
        self::assertStringContainsString('Only Reviewers can do this. Nothing was changed. You work as: Buyer.', $no->text());
        self::assertFalse($buyer->get("/ui/purchasing/suppliers/{$id}/edit")->hasForm("/ui/purchasing/suppliers/{$id}"), 'no editing while it waits');

        // The reviewer: the queue lists it (blocking), the badge counts it, the card has the forms; approving activates it.
        $reviewer = $this->signIn($this->uiUser('reviewer'));
        $queue = $reviewer->get('/ui/documents/reviews');
        self::assertSame(200, $queue->status, $queue->describe());
        $xp = new \DOMXPath($queue->dom());
        self::assertSame([\CW\Ui\Words::say('CHECKS', 'supplier', 'Hollow Vapes Wholesale')], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query('//section[@aria-labelledby="approvals-h"]//tbody/tr/th'))));
        self::assertSame("/ui/purchasing/suppliers/{$id}", $xp->query('//section[@aria-labelledby="approvals-h"]//tbody/tr/th/a')->item(0)?->getAttribute('href'));
        self::assertStringContainsString(\CW\Ui\Words::CHECK_REASON['new_supplier'], $queue->text());
        self::assertSame(\CW\Ui\Words::CHECKS['open'], trim((string) $xp->evaluate('string(//section[@aria-labelledby="approvals-h"]//tbody/tr/td[contains(@class, "c-next")]/a)')));
        self::assertSame(1, self::nav($queue)['Approvals']['count']);
        $rcard = $reviewer->get("/ui/purchasing/suppliers/{$id}");
        self::assertTrue($rcard->hasForm("/ui/purchasing/suppliers/tasks/{$task}/approve"));
        self::assertFalse($rcard->hasForm('/withdraw'));
        $ok = $reviewer->post("/ui/purchasing/suppliers/tasks/{$task}/approve", ['note' => 'Companies House checked'] + $rcard->form("/tasks/{$task}/approve"));
        self::assertSame("/ui/purchasing/suppliers/{$id}?notice=approved", $ok->location(), $ok->describe());
        $rcard = $reviewer->follow($ok);
        self::assertStringContainsString('Yes, since ', $rcard->text());
        self::assertSame('active', self::$db->value('SELECT status FROM supplier WHERE id = ?', [$id]));
        self::assertSame(0, array_sum(array_column(self::nav($reviewer->get('/ui/')), 'count')), 'nothing left to decide');
        // The requester's own approval is refused with the reason (a buyer who is also a reviewer).
        $both = $this->uiUser(['buyer', 'reviewer']);
        $s2 = $this->draft($both['id'], ['name' => 'Own Supplier']);
        (new Suppliers(self::$db))->requestActivation(Caller::staff($both['id']), (int) $s2['id'], (int) $s2['version']);
        $own = $this->signIn($both)->get('/ui/purchasing/suppliers/' . $s2['id']);
        self::assertStringContainsString(Words::REFUSAL['own_supplier'], $own->text());
        self::assertFalse($own->hasForm('/approve'));

        // Desk and auditor look, never change.
        foreach (['purchasing_desk', 'auditor'] as $role) {
            $web = $this->signIn($this->uiUser($role));
            self::assertSame(200, $web->get("/ui/purchasing/suppliers/{$id}")->status, $role);
            self::assertSame(200, $web->get('/ui/purchasing/suppliers')->status, $role);
            self::assertFalse($web->get("/ui/purchasing/suppliers/{$id}")->hasForm('/deactivate'), $role);
            $t = $this->token($web);
            foreach (["/ui/purchasing/suppliers/{$id}", "/ui/purchasing/suppliers/{$id}/deactivate", '/ui/purchasing/suppliers', "/ui/purchasing/suppliers/{$id}/items"] as $path) {
                self::assertSame(403, $web->post($path, ['csrf' => $t, 'version' => '1', 'reason' => 'nope'])->status, "{$role} {$path}");
            }
            self::assertSame(403, $web->get('/ui/purchasing/suppliers/new')->status, $role);
        }
        self::assertSame(403, $this->signIn($this->uiUser('viewer'))->get('/ui/purchasing/suppliers')->status, 'no suppliers.view');
        self::assertSame(403, $this->signIn($this->uiUser('admin'))->get('/ui/purchasing/suppliers')->status, 'admin sees people and roles only');

        // Deactivate through the card.
        $card = $buyer->get("/ui/purchasing/suppliers/{$id}");
        $d = $buyer->post("/ui/purchasing/suppliers/{$id}/deactivate", ['reason' => 'Stopped stamping its stock'] + $card->form('/deactivate'));
        self::assertSame("/ui/purchasing/suppliers/{$id}?notice=deactivated", $d->location(), $d->describe());
        self::assertStringContainsString(Words::SUPPLIER['ask_again'], $buyer->follow($d)->text());
    }

    /**
     * Review finding M2 (Y49): a supplier made usable by one person (the second person switched off) carries an "approved alone" chip
     * on the list, which a filter finds, until a reviewer gives the OK afterwards on its page.
     */
    public function testASupplierApprovedAloneIsMarkedOnTheListUntilAReviewerChecksIt(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $was = self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'approvals.supplier_activation'");
        (new \CW\Settings(self::$db))->set(Caller::system('settings'), 'approvals.supplier_activation', 'false', 'one person for this test');
        try {
            $sup = new Suppliers(self::$db);
            $d = $this->draft($buyerUser['id'], ['name' => 'Alone Supplies']);
            $s = $sup->requestActivation(Caller::staff($buyerUser['id']), (int) $d['id'], (int) $d['version']);
            self::assertSame(1, (int) $s['approved_alone']);
            $this->draft($buyerUser['id'], ['name' => 'Draft Supplies']);
            $buyer = $this->signIn($buyerUser);
            $list = $buyer->get('/ui/purchasing/suppliers');
            self::assertStringContainsString(Words::SUPPLIERS['alone_chip'], $list->text());
            $only = $buyer->get('/ui/purchasing/suppliers', ['alone' => '1']);
            self::assertStringContainsString('Alone Supplies', $only->text());
            self::assertStringNotContainsString('Draft Supplies', $only->text(), 'the filter shows the suppliers approved alone only');
            $page = $buyer->get('/ui/purchasing/suppliers/' . $s['id']);
            self::assertStringContainsString(Words::SUPPLIER['alone_title'], $page->text());
            self::assertFalse($page->hasForm('/ui/purchasing/suppliers/' . $s['id'] . '/check-alone'), 'a buyer looks');
            $rev = $this->signIn($this->uiUser('reviewer'));
            $card = $rev->get('/ui/purchasing/suppliers/' . $s['id']);
            $form = $card->form('/ui/purchasing/suppliers/' . $s['id'] . '/check-alone');
            self::assertNotSame([], $form);
            $r = $rev->post('/ui/purchasing/suppliers/' . $s['id'] . '/check-alone', $form);
            self::assertSame(303, $r->status, $r->describe());
            self::assertSame(Words::SUPPLIER_NOTICE['alone_checked'], trim((string) (new \DOMXPath($rev->follow($r)->dom()))->evaluate('string(//p[contains(@class, "notice")])')));
            self::assertStringNotContainsString('Alone Supplies', $buyer->get('/ui/purchasing/suppliers', ['alone' => '1'])->text(), 'checked: the mark goes');
            self::assertSame(403, $buyer->post('/ui/purchasing/suppliers/' . $s['id'] . '/check-alone', ['csrf' => $this->token($buyer), 'version' => '1'])->status);
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON), updated_actor = 'system:migrate' WHERE setting_key = 'approvals.supplier_activation'",
                [(string) $was]);
        }
    }

    /**
     * The 6.3 browser test (19 Oct 2026): with the second person switched off, the draft, its button and the notice after it say what
     * happens (usable at once, marked "approved alone"); its maker, a reviewer too, is told another reviewer gives the OK afterwards
     * and orders are not stopped; a request that waits from before the switch went off says how to use the supplier now.
     */
    public function testWithTheSecondPersonOffThePagesSayTheSupplierIsUsableAtOnce(): void
    {
        $ownerUser = $this->uiUser(['purchasing_manager', 'reviewer']);
        $was = self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'approvals.supplier_activation'");
        try {
            // Asked while the rule was on; then the rule goes off: the waiting page says how to use the supplier now.
            $sup = new Suppliers(self::$db);
            $d = $this->draft($ownerUser['id'], ['name' => 'Waited Supplies']);
            $sup->requestActivation(Caller::staff($ownerUser['id']), (int) $d['id'], (int) $d['version']);
            $owner = $this->signIn($ownerUser);
            self::assertStringNotContainsString(Words::SUPPLIER['waiting_rule_off'], $owner->get('/ui/purchasing/suppliers/' . $d['id'])->text(), 'the rule is on');
            (new \CW\Settings(self::$db))->set(Caller::system('settings'), 'approvals.supplier_activation', 'false', 'one person for this test');
            self::assertStringContainsString(Words::SUPPLIER['waiting_rule_off'], $owner->get('/ui/purchasing/suppliers/' . $d['id'])->text());

            // Made on the form: the notice says what to press.
            $new = $owner->get('/ui/purchasing/suppliers/new');
            $r = $owner->post('/ui/purchasing/suppliers', ['name' => 'Alone Now Supplies', 'code' => '', 'email' => 'orders@alone.example'] + $new->form('/ui/purchasing/suppliers'));
            self::assertSame(303, $r->status, $r->describe());
            $id = self::supplierId($r);
            $card = $owner->follow($r);
            $xp = new \DOMXPath($card->dom());
            self::assertSame(Words::SUPPLIER_NOTICE['created_alone'], trim((string) $xp->evaluate('string(//p[contains(@class, "notice")])')));
            // Complete it, then its button says "Make it usable".
            self::$db->exec("UPDATE supplier SET address_line1 = '2 Mill Lane', postcode = 'M1 1AA', payment_terms = '30 days', dd_checked_on = CURRENT_DATE, "
                . 'dd_checked_by = ?, dd_next_review_on = CURRENT_DATE + INTERVAL 1 YEAR WHERE id = ?', [$ownerUser['id'], $id]);
            $card = $owner->get('/ui/purchasing/suppliers/' . $id);
            self::assertStringContainsString(Words::SUPPLIER['draft_alone'], $card->text());
            self::assertStringNotContainsString(Words::SUPPLIER['draft'], $card->text(), 'nothing says a reviewer must approve it');
            $xp = new \DOMXPath($card->dom());
            self::assertSame(Words::SUPPLIER['ask_alone'], trim((string) $xp->evaluate('string(//form[contains(@action, "/request-activation")]//span[@class="btn-title"])')),
                $card->describe());
            self::assertStringContainsString(Words::SUPPLIER['ask_alone_does'], $card->text());
            self::assertStringContainsString(Words::SUPPLIER['draft_todo_alone'], $card->text());
            self::assertStringNotContainsString(Words::SUPPLIER['ask'], $card->text(), 'nothing says a reviewer must approve it first');

            // Pressed: usable at once, and the notice says so (not "a reviewer must approve").
            $r = $owner->post('/ui/purchasing/suppliers/' . $id . '/request-activation', $card->form('/ui/purchasing/suppliers/' . $id . '/request-activation'));
            self::assertSame(303, $r->status, $r->describe());
            $page = $owner->follow($r);
            self::assertSame(Words::SUPPLIER_NOTICE['made_usable'], trim((string) (new \DOMXPath($page->dom()))->evaluate('string(//p[contains(@class, "notice")])')));
            self::assertSame('active', self::$db->value('SELECT status FROM supplier WHERE id = ?', [$id]));
            // Its maker, a reviewer too: another reviewer gives the OK afterwards; never "another reviewer must decide" as if it waited.
            self::assertStringContainsString(Words::SUPPLIER['alone_yours'], $page->text());
            self::assertStringNotContainsString(Words::REFUSAL['own_supplier'], $page->text());
            self::assertStringContainsString(Words::SUPPLIER['stop_does_alone'], $page->text());
            // Stopped: using it again needs no reviewer either.
            $sup->deactivate(Caller::staff($ownerUser['id']), $id, (int) self::$db->value('SELECT version FROM supplier WHERE id = ?', [$id]), 'test stop');
            $stopped = $owner->get('/ui/purchasing/suppliers/' . $id);
            self::assertStringContainsString(Words::SUPPLIER['stopped_again_alone'], $stopped->text());
            self::assertStringNotContainsString(Words::SUPPLIER['stopped_again'], $stopped->text());
            // The rule back on: the create notice follows the rule now, also for a replay of the form (FormOnce keeps the address only).
            (new \CW\Settings(self::$db))->set(Caller::system('settings'), 'approvals.supplier_activation', 'true', 'back on for this test');
            $made = ['name' => 'Rule Back Supplies', 'code' => '', 'email' => 'orders@back.example'] + $owner->get('/ui/purchasing/suppliers/new')->form('/ui/purchasing/suppliers');
            self::assertSame(Words::SUPPLIER_NOTICE['created'], trim((string) (new \DOMXPath($owner->follow($owner->post('/ui/purchasing/suppliers', $made))->dom()))
                ->evaluate('string(//p[contains(@class, "notice")])')));
            (new \CW\Settings(self::$db))->set(Caller::system('settings'), 'approvals.supplier_activation', 'false', 'off again for this test');
            self::assertSame(Words::SUPPLIER_NOTICE['created_alone'], trim((string) (new \DOMXPath($owner->follow($owner->post('/ui/purchasing/suppliers', $made))->dom()))
                ->evaluate('string(//p[contains(@class, "notice")])')), 'the replay lands on the same page, in the words of the rule now');
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON), updated_actor = 'system:migrate' WHERE setting_key = 'approvals.supplier_activation'",
                [(string) $was]);
        }
    }

    public function testAChangeReviewAppearsInTheQueueAndIsDecidedOnTheCard(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $s = $this->draft($buyerUser['id']);
        $svc = new Suppliers(self::$db);
        $s = $svc->requestActivation(Caller::staff($buyerUser['id']), (int) $s['id'], (int) $s['version']);
        $first = $this->uiUser('reviewer');
        $svc->approve(Caller::staff($first['id']), (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND state = 'open'"), null);
        $buyer = $this->signIn($buyerUser);
        $edit = $buyer->get("/ui/purchasing/suppliers/{$s['id']}/edit");
        self::assertStringContainsString(Words::SUPPLIER_FORM['active_1'], $edit->text());
        $r = $buyer->post("/ui/purchasing/suppliers/{$s['id']}", ['email' => 'new@service.example'] + $edit->form("/ui/purchasing/suppliers/{$s['id']}"));
        self::assertSame("/ui/purchasing/suppliers/{$s['id']}?notice=saved_review", $r->location(), $r->describe());
        $reviewer = $this->signIn($first);
        $queue = $reviewer->get('/ui/documents/reviews');
        $xp = new \DOMXPath($queue->dom());
        self::assertSame(1, $xp->query('//section[@aria-labelledby="reviews-h"]//tbody/tr')->length);
        self::assertStringContainsString(\CW\Ui\Words::CHECK_REASON['supplier_changed'], $queue->text());
        $card = $reviewer->get("/ui/purchasing/suppliers/{$s['id']}");
        self::assertStringContainsString(Words::SUPPLIER['task_review'], $card->text());
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND state = 'open'");
        $no = $reviewer->post("/ui/purchasing/suppliers/tasks/{$task}/reject", ['note' => 'x'] + $card->form("/tasks/{$task}/reject"));
        self::assertSame(400, $no->status, 'a rejection needs a reason');
        self::assertStringContainsString(Words::ERROR['note_required'], $no->text());
        $rej = $reviewer->post("/ui/purchasing/suppliers/tasks/{$task}/reject", ['note' => 'unverified e-mail domain'] + $card->form("/tasks/{$task}/reject"));
        self::assertSame("/ui/purchasing/suppliers/{$s['id']}?notice=rejected", $rej->location());
        self::assertSame(['inactive', 'rejected at review: unverified e-mail domain'], array_values((array) self::$db->one(
            'SELECT status, deactivate_reason FROM supplier WHERE id = ?', [(int) $s['id']])));
    }

    public function testSupplierItemsBySearchThePreferredToggleAndAPrice(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $s = $this->draft($buyerUser['id'], ['name' => 'Item Supplier']);
        $sku = self::makeSku('Elux Legend Blue Razz 20mg');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $buyer = $this->signIn($buyerUser);
        $new = $buyer->get("/ui/purchasing/suppliers/{$s['id']}/items/new", ['q' => 'blue razz']);
        self::assertSame(200, $new->status, $new->describe());
        self::assertSame([(string) $sku], $new->radios('sku_id'));
        // The page's first form is the item search (GET .../items/new); the POST form follows it.
        $key = (new \DOMXPath($new->dom()))->query('//form[@method="post"]//input[@name="form_key"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $key);
        self::assertStringContainsString('checked', $new->body);
        $form = ['purchase_unit' => 'box', 'units_per_pack' => '10', 'supplier_code' => 'ELX-BR', 'pack_price' => '£9.50', 'is_preferred' => '1',
            'csrf' => $this->token($buyer), 'form_key' => $key->getAttribute('value'), 'sku_id' => (string) $sku, 'q' => 'blue razz'];
        $r = $buyer->post("/ui/purchasing/suppliers/{$s['id']}/items", $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(1, preg_match('#^/ui/purchasing/supplier-items/(\d+)\?notice=created$#', (string) $r->location(), $m));
        $itemId = (int) $m[1];
        self::assertSame($r->location(), $buyer->post("/ui/purchasing/suppliers/{$s['id']}/items", $form)->location(), 'replayed');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM supplier_item'));
        $dup = $buyer->post("/ui/purchasing/suppliers/{$s['id']}/items", ['form_key' => str_repeat('b', 32)] + $form);
        self::assertSame(409, $dup->status);
        self::assertStringContainsString(Words::BUY_ERROR['duplicate_pack'], $dup->text());
        self::assertSame(422, $buyer->post("/ui/purchasing/suppliers/{$s['id']}/items", ['form_key' => str_repeat('c', 32), 'sku_id' => ''] + $form)->status);

        $page = $buyer->follow($r);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::SUPPLIER_ITEMS['main_yes'], $page->text());
        self::assertStringContainsString('£9.50 a pack (£0.95 an item), typed in on ', $page->text());
        self::assertStringContainsString(Words::say('SUPPLIER_ITEMS', 'supplier_not_active', Words::SUPPLIER_STATUS['draft']), $page->text());
        $off = $buyer->post("/ui/purchasing/supplier-items/{$itemId}", $page->form("/ui/purchasing/supplier-items/{$itemId}"));
        self::assertSame("/ui/purchasing/supplier-items/{$itemId}?notice=not_preferred", $off->location(), $off->describe());
        self::assertNull(self::$db->value('SELECT preferred_sku_id FROM supplier_item WHERE id = ?', [$itemId]));
        $page = $buyer->follow($off);
        $stale = $buyer->post("/ui/purchasing/supplier-items/{$itemId}", ['version' => '1', 'preferred' => '1', 'csrf' => $this->token($buyer)]);
        self::assertSame(409, $stale->status);
        self::assertStringContainsString(Words::BUY_ERROR['version_conflict'], $stale->text());
        $price = $buyer->post("/ui/purchasing/supplier-items/{$itemId}/price", ['pack_price' => '9.00', 'note' => 'October list'] + $page->form('/price'));
        self::assertSame("/ui/purchasing/supplier-items/{$itemId}?notice=price", $price->location(), $price->describe());
        $page = $buyer->follow($price);
        $xp = new \DOMXPath($page->dom());
        self::assertSame(2, $xp->query('//table[contains(@class, "history")]/tbody/tr')->length);
        self::assertStringContainsString('October list', $page->text());
        $bad = $buyer->post("/ui/purchasing/supplier-items/{$itemId}/price", ['pack_price' => '-1'] + $page->form('/price'));
        self::assertSame(422, $bad->status);
        self::assertSame('-1', $bad->form('/price')['pack_price'] ?? null);
        $version = (string) self::$db->value('SELECT version FROM supplier_item WHERE id = ?', [$itemId]);
        $edit = $buyer->post("/ui/purchasing/supplier-items/{$itemId}", ['csrf' => $this->token($buyer), 'version' => $version, 'supplier_code' => 'ELX-BR',
            'purchase_unit' => 'box', 'units_per_pack' => '10', 'moq_packs' => '3', 'order_multiple_packs' => '1', 'lead_days' => '', 'is_active' => '1']);
        self::assertSame("/ui/purchasing/supplier-items/{$itemId}?notice=saved", $edit->location(), $edit->describe());
        self::assertSame(3, (int) self::$db->value('SELECT moq_packs FROM supplier_item WHERE id = ?', [$itemId]));

        // The supplier's items list and CSV; the item page's Suppliers panel (suppliers.view only).
        $list = $buyer->get("/ui/purchasing/suppliers/{$s['id']}/items");
        self::assertSame(200, $list->status);
        self::assertStringContainsString('Elux Legend Blue Razz 20mg', $list->text());
        self::assertStringContainsString($code, $list->text());
        self::assertStringContainsString(Words::say('SUPPLIER_ITEMS', 'pack_of', 'box', 10), $list->text());
        $csv = $buyer->get("/ui/purchasing/suppliers/{$s['id']}/items.csv");
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('"ELX-BR"', $csv->body);
        self::assertStringContainsString(',9.0000,', $csv->body);
        $item = $buyer->get("/ui/items/{$sku}");
        self::assertStringContainsString('Suppliers', $item->text());
        self::assertContains("/ui/purchasing/supplier-items/{$itemId}", $item->hrefs());
        $viewer = $this->signIn($this->uiUser('viewer'))->get("/ui/items/{$sku}");
        self::assertSame(200, $viewer->status);
        self::assertNotContains("/ui/purchasing/supplier-items/{$itemId}", $viewer->hrefs(), 'no suppliers.view, no panel');
    }

    public function testTheSuppliersCsvIsFormulaSafe(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $this->draft($buyerUser['id'], ['name' => '=HYPERLINK("http://evil.example","click")', 'code' => 'EVIL']);
        $csv = $this->signIn($buyerUser)->get('/ui/purchasing/suppliers.csv');
        self::assertSame(200, $csv->status, $csv->describe());
        self::assertSame('text/csv; charset=utf-8', $csv->header('content-type'));
        self::assertStringStartsWith("\xEF\xBB\xBF\"code\",\"name\"", $csv->body);
        self::assertStringContainsString('"EVIL","\'=HYPERLINK(""http://evil.example"",""click"")"', $csv->body);
        self::assertStringContainsString('attachment; filename="suppliers.csv"', (string) $csv->header('content-disposition'));
    }

    public function testTheSettingsPage(): void
    {
        $page = $this->signIn($this->uiUser('viewer'))->get('/ui/reference/settings');
        self::assertSame(200, $page->status, $page->describe());
        $xp = new \DOMXPath($page->dom());
        // Plan F418-F424: plain names by topic, "Not agreed yet", no decision numbers, no server command, one card per setting on a phone.
        // The approval rules have their own page since 0019 (Y9): one place for one thing.
        $rules = [...array_keys(\CW\Admin\ApprovalRules::SWITCHES), ...array_keys(\CW\Admin\ApprovalRules::NUMBERS)];
        $in = implode(', ', array_fill(0, count($rules), '?'));
        // The settings shell (docs/decisions.md W13.9): a card per topic, a row per setting (it was a table per topic).
        $rowsXp = '//section[contains(@class, "set-card")]//*[contains(@class, "set-row") and @data-key]';
        self::assertSame((int) self::$db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key NOT IN ({$in})", $rules), $xp->query($rowsXp)->length);
        self::assertSame((int) self::$db->value("SELECT COUNT(*) FROM app_setting WHERE provisional = 1 AND setting_key NOT IN ({$in})", $rules),
            $xp->query($rowsXp . '//span[contains(@class, "tag") and text()="Not agreed yet"]')->length);
        self::assertSame(0, $xp->query('//main//code[. = "suppliers.approval_due_days"]')->length, 'no technical key on the screen');
        self::assertSame(0, $xp->query('//main//form[@method="post"]')->length, 'a viewer looks: no row\'s Save');
        self::assertStringNotContainsString('company.legal_name', $page->text(), 'the company details have their own screen since 0013 (I91)');
        self::assertStringContainsString(Words::MENU['company'] . ' Our name, numbers and addresses, printed on every purchase order. Not confirmed', $page->text());
        self::assertContains('/ui/reference/company', $page->hrefs());
        $row = static fn (string $key): string => trim((string) preg_replace('/\s+/', ' ', (string) $xp->evaluate(
            'string(//*[contains(@class, "set-row") and @data-key="' . $key . '"])')));
        self::assertStringStartsWith('Copy CW costs to the websites Not agreed yet Write the warehouse average cost', $row('costs.site_writeback'));
        self::assertStringEndsWith(' No', $row('costs.site_writeback'), 'its value at the right');
        self::assertStringNotContainsString('When CW was set up', $row('costs.site_writeback'), 'a value as it was set up says nothing; its page\'s History has it');
        self::assertStringStartsWith(Words::settingName('reorder.default_safety_days') . ' Not agreed yet ', $row('reorder.default_safety_days'));
        self::assertStringEndsWith(' 5 ' . Words::SETTINGS_PAGE['unit_days'], $row('reorder.default_safety_days'));
        foreach (['bin/settings.php', 'provisional', 'decision', 'Phase', 'UTC'] as $gone) {
            self::assertStringNotContainsString($gone, $page->text());
        }
        // Who checks what is said rule by rule on the Approval rules page (one place for one thing, W13.9): the purchase orders' rule there.
        $po = trim((string) preg_replace('/\s+/', ' ', (string) (new \DOMXPath($this->signIn($this->uiUser('viewer'))->get('/ui/reference/approvals')->dom()))
            ->evaluate('string(//article[@id="rule-PO"]/div[@class="set-k"])')));
        // 0010 (the pos task, provisional decision 11): every PO reviewed within 7 days, approval above £10,000 net, a rejection recorded.
        self::assertStringContainsString('every one within 7 days', $po);
        self::assertStringContainsString('If the reviewer says not OK, the order stays and the buyer cancels or corrects it.', $po);
        self::assertSame(6, $xp->query('//section[contains(@class, "vat-codes")]//div[contains(@class, "set-row")]')->length);
        self::assertStringContainsString('Reverse charge', $page->text());
        foreach (['/ui/reference/reasons', '/ui/reference/series', '/ui/reference/approvals', '/ui/reference/warehouses', '/ui/reference/access'] as $href) {
            self::assertContains($href, $page->hrefs(), "{$href}: the section's pages are the sidebar's (DC28; F424)");
        }
        self::assertSame(0, $xp->query('//p[@class="see-also"]')->length);
    }

    public function testEvidenceUploads(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/cw_sst_' . bin2hex(random_bytes(5));
        mkdir($this->storeDir, 0700);
        mkdir($this->storeDir . '.src', 0700);
        putenv('CW_FILE_STORE_DIR=' . $this->storeDir);
        $buyerUser = $this->uiUser('buyer');
        $s = $this->draft($buyerUser['id'], ['name' => 'Evidence Supplier']);
        $buyer = $this->signIn($buyerUser);
        $card = $buyer->get("/ui/purchasing/suppliers/{$s['id']}");
        $form = $card->form('/evidence');
        self::assertSame(['csrf', 'version', 'kind'], array_keys($form));
        $file = $this->storeDir . '.src/companies-house.txt';
        file_put_contents($file, "Companies House extract\nStatus: active\n");
        $r = $buyer->postMultipart("/ui/purchasing/suppliers/{$s['id']}/evidence", $form, ['file' => ['path' => $file, 'name' => 'companies-house.txt']]);
        self::assertSame("/ui/purchasing/suppliers/{$s['id']}?notice=evidence", $r->location(), $r->describe());
        $row = self::$db->one('SELECT s.dd_evidence_file_id, f.kind, f.original_name, f.mime FROM supplier s JOIN stored_file f ON f.id = s.dd_evidence_file_id WHERE s.id = ?',
            [(int) $s['id']]);
        self::assertSame(['supplier_check', 'companies-house.txt', 'text/plain'], [$row['kind'], $row['original_name'], $row['mime']]);
        $card = $buyer->follow($r);
        self::assertContains('/ui/files/' . $row['dd_evidence_file_id'], $card->hrefs());
        self::assertSame(2, (int) self::$db->value('SELECT version FROM supplier WHERE id = ?', [(int) $s['id']]));

        // Over 2 MiB: PHP refuses the file (UPLOAD_ERR_INI_SIZE) or drops the whole body; either way 413, nothing kept.
        $big = $buyer->postMultipart("/ui/purchasing/suppliers/{$s['id']}/evidence", $card->form('/evidence'),
            ['file' => ['path' => '', 'name' => 'scan.pdf', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE]]);
        self::assertSame(413, $big->status, $big->describe());
        self::assertStringContainsString(Words::say('BUY_ERROR', 'too_large', 2), $big->text());
        $dropped = $buyer->send('POST', "/ui/purchasing/suppliers/{$s['id']}/evidence", [], [], ['content-type' => 'multipart/form-data; boundary=x',
            'content-length' => '3145728']);
        self::assertSame(413, $dropped->status, $dropped->describe());
        self::assertStringContainsString(Words::error('too_large', '', 2), $dropped->text());
        $none = $buyer->postMultipart("/ui/purchasing/suppliers/{$s['id']}/evidence", $card->form('/evidence'), []);
        self::assertSame(400, $none->status);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM stored_file'));

        // The store not set up on this server (as on cw_staging today): 503 with the reason.
        putenv('CW_FILE_STORE_DIR=' . $this->storeDir . '-missing');
        $u = $buyer->postMultipart("/ui/purchasing/suppliers/{$s['id']}/evidence", ['kind' => 'import_route'] + $card->form('/evidence'),
            ['file' => ['path' => $file, 'name' => 'route.txt']]);
        self::assertSame(503, $u->status, $u->describe());
        self::assertStringContainsString(Words::BUY_ERROR['file_store_unconfigured'], $u->text());
        self::assertNull(self::$db->value('SELECT import_route_file_id FROM supplier WHERE id = ?', [(int) $s['id']]));
    }
}
