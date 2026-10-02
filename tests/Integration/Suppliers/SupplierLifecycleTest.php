<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Suppliers;

use CW\Caller;
use CW\Clock;

/**
 * The supplier state machine (spec §5.2-§5.3, I40-I42): draft -> pending_approval -> active -> inactive -> (reactivation)
 * active; the completeness check, edits refused while an activation waits, the requester-only withdrawal, the decider
 * rules (never the requester, the creator, the last editor or admin; only suppliers.approve), rejection, deactivation and
 * reactivation, the overseas import route and its blocking re-approval, the non-blocking change review, and the audit rows.
 * Every test ends with Invariants::check (S1-S5 included).
 */
final class SupplierLifecycleTest extends SupplierTestCase
{
    private const CHECK_VIOLATED = 3819;

    public function testCreateCompleteAndRequestActivation(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->sup->create($buyer, ['name' => 'Vapour Wholesale (UK) Ltd.']);
        self::assertSame(['VAPOURWHOLES', 'draft', 1, $buyer->staffUserId, $buyer->actor, 'GB', 'S', 'GBP', 0],
            [$s['code'], $s['status'], (int) $s['version'], (int) $s['created_by'], $s['created_actor'], $s['country'], $s['default_vat_code'], $s['currency'],
                (int) $s['is_overseas']]);
        self::assertSame('VAPOURWHOLES-2', $this->sup->create($buyer, ['name' => 'Vapour Wholesale Ltd'])['code'], 'a clash gets -2');
        self::assertSame('SUP', $this->sup->create($buyer, ['name' => '株式会社'])['code'], 'no letters or digits: SUP');

        $e = self::refused(422, 'supplier_incomplete', fn () => $this->sup->requestActivation($buyer, (int) $s['id'], 1));
        self::assertSame(['address_line1', 'postcode', 'email_or_phone', 'payment_terms', 'dd_checked_on', 'dd_checked_by', 'dd_next_review_on'], $e->detail['missing']);
        self::assertSame('complete these before asking for activation: address line 1, postcode, e-mail or phone, payment terms, due diligence checked on, '
            . 'due diligence checked by, next due diligence review', $e->getMessage());
        self::refused(409, 'version_conflict', fn () => $this->sup->update($buyer, (int) $s['id'], 7, ['phone' => '1']));

        $s = $this->sup->update($buyer, (int) $s['id'], 1, $this->complete($buyer, ['name' => 'Vapour Wholesale', 'is_overseas' => '1']));
        self::assertSame([2, $buyer->staffUserId], [(int) $s['version'], (int) $s['details_changed_by']]);
        $e = self::refused(422, 'supplier_incomplete', fn () => $this->sup->requestActivation($buyer, (int) $s['id'], 2));
        self::assertSame(['import_route'], $e->detail['missing'], 'an overseas supplier needs its import route');
        $s = $this->sup->update($buyer, (int) $s['id'], 2, ['is_overseas' => '0']);
        self::assertSame($s, $this->sup->update($buyer, (int) $s['id'], 3, ['is_overseas' => '0', 'name' => ' Vapour Wholesale ']), 'nothing changed: nothing written');

        $before = Clock::now();
        $p = $this->sup->requestActivation($buyer, (int) $s['id'], 3);
        self::assertSame(['pending_approval', 4], [$p['status'], (int) $p['version']]);
        $t = $this->taskRow($this->openTask((int) $s['id']));
        self::assertSame(['supplier', 'approval', 'new_supplier', null, 'open', $buyer->staffUserId, $buyer->actor],
            [$t['subject_type'], $t['kind'], $t['reason'], $t['units'], $t['state'], (int) $t['opened_by'], $t['opened_actor']]);
        $due = Clock::fromDb((string) $t['due_at'])->getTimestamp() - Clock::fromDb((string) $t['opened_at'])->getTimestamp();
        self::assertSame(3 * 86400, $due, 'due suppliers.approval_due_days (3) after it was asked for');
        self::assertGreaterThanOrEqual($before->getTimestamp() - 1, Clock::fromDb((string) $t['opened_at'])->getTimestamp());

        // While it waits nothing changes it: withdraw the request first.
        $e = self::refused(409, 'supplier_pending', fn () => $this->sup->update($buyer, (int) $s['id'], 4, ['phone' => '999']));
        self::assertStringContainsString('withdraw the activation request first', $e->getMessage());
        self::refused(409, 'supplier_pending', fn () => $this->sup->requestActivation($buyer, (int) $s['id'], 4));
        self::assertSame(['supplier.create', 'supplier.update', 'supplier.update', 'supplier.request_activation'], $this->supplierAudits((int) $s['id']));
    }

    public function testOnlyTheRequesterWithdrawsAndTheSupplierIsADraftAgain(): void
    {
        $a = $this->staffUser('buyer');
        $b = $this->staffUser('buyer');
        $s = $this->draft($a);
        $s = $this->sup->requestActivation($b, (int) $s['id'], (int) $s['version']);
        $task = $this->openTask((int) $s['id']);
        self::assertSame('only the person who asked for this activation can withdraw it',
            self::refused(403, 'not_requester', fn () => $this->sup->withdraw($a, $task))->getMessage(), 'not even its creator');
        self::refused(403, 'role_not_allowed', fn () => $this->sup->withdraw($this->staffUser('reviewer'), $task));
        self::refused(403, 'staff_required', fn () => $this->sup->withdraw(Caller::system('test'), $task));
        $s = $this->sup->withdraw($b, $task);
        self::assertSame('draft', $s['status']);
        $t = $this->taskRow($task);
        self::assertSame(['withdrawn', null, 'withdrawn by the requester'], [$t['state'], $t['decided_by'], $t['decision_note']]);
        self::refused(409, 'task_closed', fn () => $this->sup->withdraw($b, $task));
        // Asked for again: a new task.
        $s = $this->sup->requestActivation($a, (int) $s['id'], (int) $s['version']);
        self::assertNotSame($task, $this->openTask((int) $s['id']));
        self::assertSame(['supplier.create', 'supplier.request_activation', 'supplier.withdraw', 'supplier.request_activation'], $this->supplierAudits((int) $s['id']));
    }

    /** §5.3: never the requester, the creator, the last editor or admin; only suppliers.approve; then a second reviewer. */
    public function testWhoMayDecide(): void
    {
        $creator = $this->staffUser(['buyer', 'reviewer']);
        $editor = $this->staffUser(['buyer', 'reviewer']);
        $requester = $this->staffUser(['buyer', 'reviewer']);
        $s = $this->draft($creator);
        $s = $this->sup->update($editor, (int) $s['id'], (int) $s['version'], ['email' => 'buying@acme.example']);
        self::assertSame($editor->staffUserId, (int) $s['details_changed_by']);
        $s = $this->sup->update($requester, (int) $s['id'], (int) $s['version'], ['notes' => 'not an approval-relevant field']);
        self::assertSame($editor->staffUserId, (int) $s['details_changed_by'], 'notes are not approval-relevant');
        $s = $this->sup->requestActivation($requester, (int) $s['id'], (int) $s['version']);
        $task = $this->openTask((int) $s['id']);

        self::assertSame('You created this supplier: another reviewer must decide.',
            self::refused(403, 'own_supplier', fn () => $this->sup->approve($creator, $task, null))->getMessage());
        self::assertSame('You last changed this supplier: another reviewer must decide.',
            self::refused(403, 'own_supplier', fn () => $this->sup->approve($editor, $task, null))->getMessage());
        self::assertSame('You asked for this approval: another reviewer must decide.',
            self::refused(403, 'own_supplier', fn () => $this->sup->reject($requester, $task, 'looks fine to me'))->getMessage());
        self::refused(403, 'admin_cannot_review', fn () => $this->sup->approve($this->staffUser('admin'), $task, null));
        self::refused(403, 'admin_cannot_review', fn () => $this->sup->approve($this->staffUser(['admin', 'reviewer']), $task, null));
        self::assertSame('Your role (buyer) cannot approve or review suppliers.',
            self::refused(403, 'role_not_allowed', fn () => $this->sup->approve($this->staffUser('buyer'), $task, null))->getMessage());
        self::refused(403, 'role_not_allowed', fn () => $this->sup->approve($this->staffUser(['auditor', 'accountant']), $task, null));
        self::refused(403, 'staff_not_allowed', fn () => $this->sup->approve($this->staffUser('reviewer', false), $task, null));
        self::refused(404, 'unknown_task', fn () => $this->sup->approve($this->staffUser('reviewer'), 999_999, null));
        // ck_review_task_not_own holds the opener in SQL as well.
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "UPDATE review_task SET state = 'approved', decided_by = opened_by, decided_at = NOW(6) WHERE id = ?", [$task])));
        self::assertSame('open', $this->taskRow($task)['state']);

        $second = $this->staffUser('reviewer');
        $a = $this->sup->approve($second, $task, 'checked Companies House');
        self::assertSame(['active', $second->staffUserId, 'checked Companies House', null, null], [$a['status'], (int) $a['approved_by'], $a['last_decision_note'],
            $a['import_route_approved_at'], $a['deactivated_at']]);
        self::assertNotNull($a['approved_at']);
        $t = $this->taskRow($task);
        self::assertSame(['approved', $second->staffUserId, 'checked Companies House'], [$t['state'], (int) $t['decided_by'], $t['decision_note']]);
        self::refused(409, 'task_closed', fn () => $this->sup->approve($this->staffUser('reviewer'), $task, null));
        self::assertSame(['supplier.create', 'supplier.update', 'supplier.update', 'supplier.request_activation', 'supplier.approve'],
            $this->supplierAudits((int) $s['id']));
    }

    public function testRejectingANewSupplierSendsItBackToDraftWithTheNote(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->draft($buyer);
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $task = $this->openTask((int) $s['id']);
        $rev = $this->staffUser('reviewer');
        self::refused(400, 'note_required', fn () => $this->sup->reject($rev, $task, ' x '));
        $s = $this->sup->reject($rev, $task, 'The VAT number belongs to another company');
        self::assertSame(['draft', 'The VAT number belongs to another company', null], [$s['status'], $s['last_decision_note'], $s['approved_at']]);
        self::assertSame(['rejected', $rev->staffUserId], [$this->taskRow($task)['state'], (int) $this->taskRow($task)['decided_by']]);
        // Corrected and asked for again: still a new supplier (never approved).
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['vat_number' => 'GB987654321']);
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        self::assertSame('new_supplier', $this->taskRow($this->openTask((int) $s['id']))['reason']);
    }

    public function testDeactivationAndReactivation(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer);
        $firstApprover = (int) $s['approved_by'];
        self::refused(400, 'reason_required', fn () => $this->sup->deactivate($buyer, (int) $s['id'], (int) $s['version'], 'no'));
        self::refused(409, 'version_conflict', fn () => $this->sup->deactivate($buyer, (int) $s['id'], 99, 'stopped trading'));
        self::refused(403, 'role_not_allowed', fn () => $this->sup->deactivate($this->staffUser('reviewer'), (int) $s['id'], (int) $s['version'], 'stopped trading'));
        $s = $this->sup->deactivate($buyer, (int) $s['id'], (int) $s['version'], 'Stopped trading with us');
        self::assertSame(['inactive', $buyer->staffUserId, 'Stopped trading with us', $firstApprover], [$s['status'], (int) $s['deactivated_by'],
            $s['deactivate_reason'], (int) $s['approved_by']]);
        self::assertNotNull($s['deactivated_at']);
        self::refused(409, 'not_active', fn () => $this->sup->deactivate($buyer, (int) $s['id'], (int) $s['version'], 'again'));
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['email' => 'accounts@acme.example']);
        self::assertSame([0, $buyer->staffUserId], [$this->openTask((int) $s['id'], 'review'), (int) $s['details_changed_by']],
            'an inactive supplier\'s change is reviewed by its reactivation');

        // Reactivation: rejected -> inactive again (from now, by the reviewer).
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        self::assertSame(['pending_approval', null, 'Stopped trading with us'], [$s['status'], $s['deactivated_at'], $s['deactivate_reason']]);
        $task = $this->openTask((int) $s['id']);
        self::assertSame('reactivation', $this->taskRow($task)['reason']);
        $rev = $this->staffUser('reviewer');
        $s = $this->sup->reject($rev, $task, 'still no stamped stock');
        self::assertSame(['inactive', $rev->staffUserId, 'reactivation rejected: still no stamped stock', 'still no stamped stock'],
            [$s['status'], (int) $s['deactivated_by'], $s['deactivate_reason'], $s['last_decision_note']]);
        // Withdrawn -> inactive again (by the requester).
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $s = $this->sup->withdraw($buyer, $this->openTask((int) $s['id']));
        self::assertSame(['inactive', $buyer->staffUserId], [$s['status'], (int) $s['deactivated_by']]);
        self::assertStringStartsWith('reactivation withdrawn by the requester', (string) $s['deactivate_reason']);
        // Approved -> active, deactivation cleared, the new approver recorded.
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $second = $this->staffUser('reviewer');
        $s = $this->sup->approve($second, $this->openTask((int) $s['id']), null);
        self::assertSame(['active', $second->staffUserId, null, null, null], [$s['status'], (int) $s['approved_by'], $s['deactivated_by'], $s['deactivated_at'],
            $s['deactivate_reason']]);
        self::assertSame(['new_supplier' => 'approved', 'reactivation' => 'approved'], array_column(self::$db->all(
            "SELECT reason, state FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'approved' ORDER BY id", [(int) $s['id']]), 'state', 'reason'));
    }

    /** An overseas supplier's import route is approved with the activation; changing it needs a new (blocking) approval. */
    public function testTheImportRouteOfAnOverseasSupplier(): void
    {
        $buyer = $this->staffUser(['buyer', 'reviewer']);
        $s = $this->draft($buyer, ['country' => 'CN', 'is_overseas' => '1', 'import_route' => 'Stamped by Example Fulfilment Ltd, Felixstowe, before release']);
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $rev = $this->staffUser('reviewer');
        $s = $this->sup->approve($rev, $this->openTask((int) $s['id']), null);
        self::assertSame([$rev->staffUserId, $s['approved_at']], [(int) $s['import_route_approved_by'], $s['import_route_approved_at']],
            'the activation approved the route at the same moment');

        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['import_route' => 'Stamped in Shenzhen by the manufacturer (HMRC-approved stamps)']);
        self::assertSame(['active', null, null], [$s['status'], $s['import_route_approved_by'], $s['import_route_approved_at']]);
        $route = $this->openTask((int) $s['id']);
        self::assertSame(['approval', 'import_route'], [$this->taskRow($route)['kind'], $this->taskRow($route)['reason']], 'a blocking approval');
        self::assertSame(0, $this->openTask((int) $s['id'], 'review'), 'a route change is the route approval, not a change review');
        self::refused(403, 'own_supplier', fn () => $this->sup->approve($buyer, $route, null));
        $s = $this->sup->reject($rev, $route, 'no evidence of the stamping');
        self::assertSame(['active', null, 'no evidence of the stamping'], [$s['status'], $s['import_route_approved_at'], $s['last_decision_note']],
            'rejected: the route stays unapproved (POs refused), the supplier stays active');
        self::assertSame(0, $this->openTask((int) $s['id']));

        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['import_route' => 'Stamped in Shenzhen; certificate attached']);
        $route = $this->openTask((int) $s['id']);
        self::assertSame('import_route', $this->taskRow($route)['reason']);
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['import_route' => 'Stamped in Shenzhen; certificate no. 42 attached']);
        self::assertSame($route, $this->openTask((int) $s['id']), 'the open approval covers a further change');
        $s = $this->sup->approve($rev, $route, 'certificate seen');
        self::assertSame($rev->staffUserId, (int) $s['import_route_approved_by']);

        // No longer overseas (I72): the approval goes (CHECK) and an open route approval STAYS open: a second person confirms
        // the change; an active supplier made overseas needs a route.
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['import_route' => 'changed again']);
        $open = $this->openTask((int) $s['id']);
        self::assertSame('is_overseas', self::refused(422, 'bad_field', fn () => $this->sup->update($buyer, (int) $s['id'], (int) $s['version'],
            ['is_overseas' => '0']))->detail['field'], 'a CN supplier is overseas');
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['is_overseas' => '0', 'country' => 'GB']);
        self::assertSame([0, null], [(int) $s['is_overseas'], $s['import_route_approved_at']]);
        self::assertSame(['open', 'import_route'], [$this->taskRow($open)['state'], $this->taskRow($open)['reason']], 'still blocking');
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['import_route' => '']);
        $e = self::refused(422, 'supplier_incomplete', fn () => $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['is_overseas' => '1']));
        self::assertSame(['import_route'], $e->detail['missing']);
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['is_overseas' => '1', 'import_route' => 'Stamped at our bonded warehouse']);
        self::assertSame($open, $this->openTask((int) $s['id']), 'the same open approval covers every further route change');
        self::assertSame([], \CW\Suppliers\SupplierInvariants::check(self::$db));
    }

    /**
     * Review finding (blocker): one buyer unticking "overseas" switched the blocking route approval off. Now the flip opens
     * a blocking import_route approval; approving it confirms the supplier is UK, rejecting it deactivates the supplier.
     */
    public function testAnOverseasSupplierMadeUkWaitsForASecondPerson(): void
    {
        $buyer = $this->staffUser(['buyer', 'reviewer']);
        $rev = $this->staffUser('reviewer');
        $s = $this->activeSupplier($buyer, ['country' => 'CN', 'is_overseas' => '1', 'import_route' => 'Stamped in Kent']);
        self::assertNotNull($s['import_route_approved_at']);
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['is_overseas' => '0', 'country' => 'GB']);
        $t = $this->openTask((int) $s['id']);
        self::assertSame(['approval', 'import_route', $buyer->staffUserId], [$this->taskRow($t)['kind'], $this->taskRow($t)['reason'], (int) $this->taskRow($t)['opened_by']]);
        self::assertNotSame(0, $this->openTask((int) $s['id'], 'review'), 'the country changed too: also the (non-blocking) change review');
        self::assertSame([], \CW\Suppliers\SupplierInvariants::check(self::$db));
        self::refused(403, 'own_supplier', fn () => $this->sup->approve($buyer, $t, null));
        $s = $this->sup->approve($rev, $t, 'moved to a UK distributor');
        self::assertSame(['active', 0, null, 'approved'], [$s['status'], (int) $s['is_overseas'], $s['import_route_approved_at'], $this->taskRow($t)['state']]);

        // Rejected: the supplier is deactivated (POs refused) until a reactivation is approved afresh.
        $o = $this->activeSupplier($buyer, ['name' => 'Second Overseas', 'country' => 'NL', 'is_overseas' => '1', 'import_route' => 'Stamped in Kent']);
        $o = $this->sup->update($buyer, (int) $o['id'], (int) $o['version'], ['is_overseas' => '0', 'country' => 'GB']);
        $t2 = $this->openTask((int) $o['id']);
        $o = $this->sup->reject($rev, $t2, 'the goods still come from Rotterdam');
        self::assertSame(['inactive', 'rejected at review: the goods still come from Rotterdam'], [$o['status'], $o['deactivate_reason']]);
        self::assertSame([], \CW\Suppliers\SupplierInvariants::check(self::$db));
    }

    /** Review finding (minor): editing an inactive overseas supplier's route crashed on ck_supplier_route (500). */
    public function testTheRouteOfAnInactiveOverseasSupplierCanBeChanged(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['country' => 'CN', 'is_overseas' => '1', 'import_route' => 'Stamped in Kent']);
        $s = $this->sup->deactivate($buyer, (int) $s['id'], (int) $s['version'], 'paused');
        self::assertNotNull($s['import_route_approved_at'], 'deactivation keeps the approval of the route it had');
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['import_route' => '']);
        self::assertSame(['inactive', null, null], [$s['status'], $s['import_route'], $s['import_route_approved_at']]);
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['is_overseas' => '0', 'country' => 'GB']);
        self::assertSame([0, 0], [(int) $s['is_overseas'], $this->openTask((int) $s['id'])], 'no task on an inactive supplier: the reactivation decides');
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $s = $this->sup->approve($this->staffUser('reviewer'), $this->openTask((int) $s['id']), null);
        self::assertSame(['active', null], [$s['status'], $s['import_route_approved_at']]);
        self::assertSame([], \CW\Suppliers\SupplierInvariants::check(self::$db));
    }

    /** Identity changes of an active supplier open a non-blocking review; rejecting it deactivates the supplier. */
    public function testAChangeOfAnActiveSupplierIsReviewed(): void
    {
        $buyer = $this->staffUser('buyer');
        $editor = $this->staffUser(['buyer', 'reviewer']);
        $s = $this->activeSupplier($buyer);
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['payment_terms' => '60 days', 'default_lead_days' => '3']);
        self::assertSame(0, $this->openTask((int) $s['id'], 'review'), 'terms are not approval-relevant');

        $s = $this->sup->update($editor, (int) $s['id'], (int) $s['version'], ['email' => 'new-orders@acme.example']);
        self::assertSame(['active', $editor->staffUserId], [$s['status'], (int) $s['details_changed_by']], 'it does not block');
        $review = $this->openTask((int) $s['id'], 'review');
        $t = $this->taskRow($review);
        self::assertSame(['review', 'supplier_changed', $editor->staffUserId], [$t['kind'], $t['reason'], (int) $t['opened_by']]);
        self::assertSame(7 * 86400, Clock::fromDb((string) $t['due_at'])->getTimestamp() - Clock::fromDb((string) $t['opened_at'])->getTimestamp());
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['postcode' => 'LS2 2BB']);
        self::assertSame($review, $this->openTask((int) $s['id'], 'review'), 'one open review covers further changes');
        self::assertSame($buyer->staffUserId, (int) $s['details_changed_by']);
        self::refused(403, 'own_supplier', fn () => $this->sup->approve($editor, $review, null));
        $rev = $this->staffUser('reviewer');
        $s = $this->sup->approve($rev, $review, 'new address confirmed');
        self::assertSame(['active', 'new address confirmed'], [$s['status'], $s['last_decision_note']]);

        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['vat_number' => 'GB000000000']);
        $review = $this->openTask((int) $s['id'], 'review');
        $s = $this->sup->reject($rev, $review, 'VAT number is not valid');
        self::assertSame(['inactive', $rev->staffUserId, 'rejected at review: VAT number is not valid'], [$s['status'], (int) $s['deactivated_by'], $s['deactivate_reason']]);

        // suppliers.change_review false: no review (a provisional setting, decision 11).
        $t = $this->activeSupplier($buyer, ['name' => 'Other Supplier']);
        self::$db->exec("UPDATE app_setting SET value_json = CAST('false' AS JSON) WHERE setting_key = 'suppliers.change_review'");
        try {
            $t = (new \CW\Suppliers\Suppliers(self::$db))->update($buyer, (int) $t['id'], (int) $t['version'], ['email' => 'x@other.example']);
            self::assertSame(0, $this->openTask((int) $t['id'], 'review'));
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST('true' AS JSON) WHERE setting_key = 'suppliers.change_review'");
        }
    }

    public function testDeactivationWithdrawsTheOpenTasks(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['is_overseas' => '1', 'import_route' => 'Stamped in Kent']);
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['import_route' => 'Stamped in Essex', 'email' => 'x@acme.example']);
        $route = $this->openTask((int) $s['id']);
        $review = $this->openTask((int) $s['id'], 'review');
        self::assertNotSame(0, $route);
        self::assertNotSame(0, $review);
        $s = $this->sup->deactivate($buyer, (int) $s['id'], (int) $s['version'], 'Supplier closed');
        foreach ([$route, $review] as $t) {
            self::assertSame(['withdrawn', 'the supplier was deactivated'], [$this->taskRow($t)['state'], $this->taskRow($t)['decision_note']]);
        }
        // Reactivated: the approval covers the route again.
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $s = $this->sup->approve($this->staffUser('reviewer'), $this->openTask((int) $s['id']), null);
        self::assertNotNull($s['import_route_approved_at']);
        self::assertSame($s['approved_at'], $s['import_route_approved_at']);
    }

    public function testFieldChecks(): void
    {
        $buyer = $this->staffUser('buyer');
        $gone = $this->staffUser('buyer', false);
        foreach ([
            [['name' => ''], 422, 'name'], [['name' => 'X', 'email' => 'not-an-address'], 422, 'email'], [['name' => 'X', 'country' => 'GBR'], 422, 'country'],
            [['name' => 'X', 'code' => 'a b'], 422, 'code'], [['name' => 'X', 'dd_checked_on' => self::day('+1 day')], 422, 'dd_checked_on'],
            [['name' => 'X', 'dd_checked_on' => self::day('-1 day'), 'dd_next_review_on' => self::day('-2 days')], 422, 'dd_next_review_on'],
            [['name' => 'X', 'default_vat_code' => 'XX'], 422, 'default_vat_code'], [['name' => 'X', 'dd_checked_by' => (string) $gone->staffUserId], 422, 'dd_checked_by'],
            [['name' => 'X', 'payment_terms_days' => '366'], 422, 'payment_terms_days'], [['name' => 'X', 'min_order_value' => '1.234'], 422, 'min_order_value'],
            [['name' => 'X', 'is_overseas' => 'maybe'], 422, 'is_overseas'], [['name' => 'X', 'dd_checked_on' => '2026-02-30'], 422, 'dd_checked_on'],
            [['name' => 'X', 'bank_account' => '12345678'], 400, 'bank_account'], [['name' => 'X', 'erp_name' => 'Acme'], 400, 'erp_name'],
            [['name' => 'X', 'dd_evidence_file_id' => '1'], 400, 'dd_evidence_file_id'], [['name' => str_repeat('n', 129)], 422, 'name'],
        ] as [$fields, $status, $field]) {
            $e = self::refused($status, 'bad_field', fn () => $this->sup->create($buyer, $fields));
            self::assertSame($field, $e->detail['field'], json_encode($fields));
        }
        $s = $this->sup->create($buyer, ['name' => 'Fine', 'country' => 'uk', 'min_order_value' => '£1,250.5', 'dd_checked_on' => '01/09/2026',
            'code' => 'fine-1', 'default_vat_code' => 'z', 'contacts_note' => "Sales: Ann\r\nAccounts: Bob"]);
        self::assertSame(['GB', '1250.50', '2026-09-01', 'FINE-1', 'Z', "Sales: Ann\nAccounts: Bob"],
            [$s['country'], $s['min_order_value'], $s['dd_checked_on'], $s['code'], $s['default_vat_code'], $s['contacts_note']]);
        self::refused(409, 'duplicate_code', fn () => $this->sup->create($buyer, ['name' => 'Other', 'code' => 'FINE-1']));
        self::refused(403, 'role_not_allowed', fn () => $this->sup->create($this->staffUser('reviewer'), ['name' => 'X']));
        self::refused(403, 'role_not_allowed', fn () => $this->sup->create($this->staffUser(['admin', 'buyer']), ['name' => 'X'])); // admin + buyer counts admin only
        self::refused(403, 'staff_required', fn () => $this->sup->create(Caller::system('test'), ['name' => 'X']));
    }
}
