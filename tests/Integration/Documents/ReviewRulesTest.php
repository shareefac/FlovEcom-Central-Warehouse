<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Documents;

use CW\Caller;

/**
 * I19: post first, a second person reviews. Nobody decides a task on a document they created, asked an approval for
 * or posted (403 own_document; the CHECK ck_review_task_not_own refuses the opener in SQL too), admin never decides,
 * a review needs documents.review and an approval documents.approve; rejecting a posted document posts its reversal
 * (reason review_rejected, by the reviewer, not reviewed again); a closed task cannot be decided; the badge counts
 * only what the person may decide.
 */
final class ReviewRulesTest extends DocumentTestCase
{
    private const CHECK_VIOLATED = 3819;

    public function testThePosterCannotDecideTheirOwnDocumentNotEvenInSql(): void
    {
        $both = $this->staffUser(['stock_controller', 'reviewer']);
        $p = $this->posted($both, [['sku_id' => $this->item('strict', 5), 'qty' => 2]]);
        $task = $this->openTask($p->id);
        $e = self::refused(403, 'own_document', fn () => $this->docs->approve($both, $task, null));
        self::assertSame('You posted this document: another reviewer must review it.', $e->getMessage());
        self::refused(403, 'own_document', fn () => $this->docs->reject($both, $task, 'looks wrong'));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "UPDATE review_task SET state = 'approved', decided_by = opened_by, decided_at = NOW(6) WHERE id = ?", [$task])));
        self::assertSame('open', $this->task($task)['state']);
        $this->docs->approve($this->staffUser('reviewer'), $task, null);
    }

    public function testTheCreatorOfTheDraftCannotDecideEither(): void
    {
        $creator = $this->staffUser(['stock_controller', 'reviewer']);
        $poster = $this->staffUser('stock_controller');
        $d = $this->draft($creator, [['sku_id' => $this->item('strict', 5), 'qty' => -1]]);
        $p = $this->docs->post($poster, $d->id, $d->version);
        $task = $this->openTask($p->id);
        $e = self::refused(403, 'own_document', fn () => $this->docs->approve($creator, $task, null));
        self::assertSame('You created this document: another reviewer must review it.', $e->getMessage());
        // An approval request: its requester cannot decide it.
        $asker = $this->staffUser(['stock_controller', 'reviewer']);
        $x = $this->posted($asker, [['sku_id' => $this->item('strict', 0), 'qty' => 30]], []);
        $e = self::refused(403, 'own_document', fn () => $this->docs->approve($asker, $this->openTask($x->id, 'approval'), null));
        self::assertSame('You asked for this approval: another reviewer must decide this request.', $e->getMessage());
        self::assertSame('approved', $this->docs->approve($this->staffUser('reviewer'), $task, 'fine')->reviewState);
    }

    public function testAdminAndRolesWithoutTheReviewPermissionsNeverDecide(): void
    {
        $sc = $this->staffUser('stock_controller');
        $a = $this->item('strict', 5);
        $review = $this->openTask($this->posted($sc, [['sku_id' => $a, 'qty' => 1]])->id);
        $approval = $this->openTask($this->posted($sc, [['sku_id' => $a, 'qty' => 50]], [])->id, 'approval');
        self::refused(403, 'admin_cannot_review', fn () => $this->docs->approve($this->staffUser('admin'), $review, null));
        self::refused(403, 'admin_cannot_review', fn () => $this->docs->reject($this->staffUser(['admin', 'reviewer']), $review, 'admin SQL made me'));
        $other = $this->staffUser('stock_controller');
        $e = self::refused(403, 'role_not_allowed', fn () => $this->docs->approve($other, $review, null));
        self::assertSame('Your role (stock_controller) cannot review documents.', $e->getMessage());
        $e = self::refused(403, 'role_not_allowed', fn () => $this->docs->approve($other, $approval, null));
        self::assertSame('Your role (stock_controller) cannot give approvals.', $e->getMessage(), 'an approval needs documents.approve');
        self::refused(403, 'role_not_allowed', fn () => $this->docs->reject($this->staffUser(['auditor', 'accountant']), $approval, 'not mine to say'));
        self::refused(403, 'staff_required', fn () => $this->docs->approve(Caller::system('test'), $review, null));
        self::refused(404, 'unknown_task', fn () => $this->docs->approve($this->staffUser('reviewer'), 999_999, null));
        $supplier = self::$db->insert("INSERT INTO review_task (subject_type, subject_id, kind, reason, opened_actor, due_at) "
            . "VALUES ('supplier', 1, 'approval', 'new_supplier', 'staff:1', NOW(6))");
        self::refused(409, 'subject_not_built', fn () => $this->docs->approve($this->staffUser('reviewer'), $supplier, null));
        self::$db->exec('DELETE FROM review_task WHERE id = ?', [$supplier]);
        self::assertSame('open', $this->task($review)['state']);
        self::assertSame('open', $this->task($approval)['state']);
        $rev = $this->staffUser('reviewer');
        $this->docs->approve($rev, $review, null);
        $this->docs->reject($rev, $approval, 'no supplier document');
    }

    public function testRejectingAPostedDocumentPostsItsReversal(): void
    {
        $sc = $this->staffUser('stock_controller');
        $rev = $this->staffUser('reviewer');
        [$a, $b] = [$this->item('strict', 10), $this->item('strict', 10)];
        $p = $this->posted($sc, [['sku_id' => $a, 'qty' => -4], ['sku_id' => $b, 'qty' => 2, 'unit_cost' => '3']]);
        $task = $this->openTask($p->id);
        $this->assertBal(6, 0, 0, $a);
        self::refused(400, 'note_required', fn () => $this->docs->reject($rev, $task, '  '));
        $o = $this->docs->reject($rev, $task, 'wrong item scanned');
        self::assertSame(['reversed', 'rejected'], [$o->status, $o->reviewState]);
        $t = $this->task($task);
        self::assertSame(['rejected', $rev->staffUserId, 'wrong item scanned'], [$t['state'], (int) $t['decided_by'], $t['decision_note']]);
        $r = $this->docs->get((int) self::$db->value('SELECT id FROM document WHERE reverses_id = ?', [$p->id]));
        self::assertSame(['ADJ-000002', 'posted', 'review_rejected', $rev->staffUserId, $rev->staffUserId, $rev->actor, 'not_required', 'Rejected at review: wrong item scanned'],
            [$r->number, $r->status, $r->reasonCode, $r->createdBy, $r->postedBy, $r->postedActor, $r->reviewState, $r->note]);
        self::assertSame(0, $this->openTask($r->id), 'a rejection\'s reversal is not reviewed again');
        $this->assertBal(10, 0, 0, $a);
        $this->assertBal(10, 0, 0, $b);
        self::assertSame([[1, 'ADJ-000002', 4, null, null], [2, 'ADJ-000002', -2, '3.000000', 'document']], $this->ledger($r->id));
        self::assertSame(['document.reject'], array_values(array_intersect($this->audits($p->id), ['document.reject'])));
        self::assertSame(['document.reverse'], $this->audits($r->id));
        self::assertSame($rev->actor, self::$db->value("SELECT actor FROM audit_log WHERE action = 'document.reverse'"));
        self::refused(409, 'task_closed', fn () => $this->docs->approve($this->staffUser('reviewer'), $task, null));
    }

    /**
     * I31 (review finding, blocker): rejecting the review of a voluntary reversal never reverses the reversal. The
     * rejection is recorded on it (review_state rejected, task rejected, audit booked=false), no stock moves, the
     * original stays reversed, and every invariant holds (the post-condition of this test case).
     */
    public function testRejectingTheReviewOfAReversalRecordsItAndBooksNothing(): void
    {
        $a = $this->staffUser('stock_controller');
        $b = $this->staffUser('stock_controller');
        $rev = $this->staffUser('reviewer');
        $sku = $this->item('strict', 10);
        $p = $this->posted($a, [['sku_id' => $sku, 'qty' => -5]]);
        $this->docs->approve($rev, $this->openTask($p->id), null);
        $r = $this->docs->reverse($b, $p->id, 'entered_in_error', null);
        self::assertSame(['posted', 'pending'], [$r->status, $r->reviewState], '5 units back: under the approval limit, posted and reviewed');
        $this->assertBal(10, 0, 0, $sku);
        $ledgerRows = $this->ledgerCount();
        $task = $this->openTask($r->id);

        $x = $this->docs->reject($rev, $task, 'the write-down was right');
        self::assertSame([$r->id, 'posted', 'rejected', 'ADJ-000002'], [$x->id, $x->status, $x->reviewState, $x->number]);
        self::assertSame(['rejected', $rev->staffUserId], [$this->task($task)['state'], (int) $this->task($task)['decided_by']]);
        self::assertSame('reversed', $this->docs->get($p->id)->status, 'the original stays reversed');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM document WHERE reverses_id = ?', [$r->id]), 'no reversal of the reversal');
        self::assertSame($ledgerRows, $this->ledgerCount(), 'no stock moved');
        $this->assertBal(10, 0, 0, $sku);
        $detail = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'document.reject' AND entity_id = ?", [(string) $r->id]), true);
        self::assertSame([false, $p->id], [$detail['booked'], $detail['reverses']]);
        self::refused(409, 'not_reversible', fn () => $this->docs->reverse($b, $r->id, 'duplicate', null), 'and still never reversed by hand');
    }

    public function testAReviewOnAReversedDocumentCannotBeDecided(): void
    {
        $sc = $this->staffUser('stock_controller');
        $rev = $this->staffUser('reviewer');
        $p = $this->posted($sc, [['sku_id' => $this->item('strict', 5), 'qty' => 1]]);
        $task = $this->openTask($p->id);
        $this->docs->reverse($sc, $p->id, 'duplicate', null);
        self::refused(409, 'task_closed', fn () => $this->docs->approve($rev, $task, null));
        self::refused(409, 'task_closed', fn () => $this->docs->reject($rev, $task, 'too late now'));
        // The same with a task that is still open by admin SQL (DocumentInvariants would report it): the document check refuses.
        self::$db->exec("UPDATE review_task SET state = 'open', decided_at = NULL, decision_note = NULL WHERE id = ?", [$task]);
        self::refused(409, 'not_reviewable', fn () => $this->docs->approve($rev, $task, null));
        self::$db->exec("UPDATE review_task SET state = 'withdrawn', decided_at = NOW(6) WHERE id = ?", [$task]);
    }

    public function testTheBadgeCountsOnlyWhatThePersonMayDecide(): void
    {
        $sc = $this->staffUser('stock_controller');
        $r1 = $this->staffUser(['reviewer', 'stock_controller']);
        $r2 = $this->staffUser('reviewer');
        $a = $this->item('strict', 50);
        $count = fn (Caller $c, array $roles): int => $this->docs->decidableCount((int) $c->staffUserId, $roles);
        self::assertSame(0, $count($r2, ['reviewer']));
        $this->posted($sc, [['sku_id' => $a, 'qty' => 1]]);
        $this->posted($sc, [['sku_id' => $a, 'qty' => -1]]);
        $own = $this->posted($r1, [['sku_id' => $a, 'qty' => 2]]);
        $held = $this->posted($sc, [['sku_id' => $a, 'qty' => 40]], []);
        self::assertSame('awaiting_approval', $held->status);
        self::assertSame(4, $count($r2, ['reviewer']), 'three reviews and one approval');
        self::assertSame(3, $count($r1, ['reviewer', 'stock_controller']), 'not their own document');
        self::assertSame(0, $count($sc, ['stock_controller']), 'no review permission');
        self::assertSame(0, $count($this->staffUser('admin'), ['admin']));
        $this->docs->approve($r2, $this->openTask($own->id), null);
        $this->docs->approve($r1, $this->openTask($held->id, 'approval'), null);
        self::assertSame(2, $count($r2, ['reviewer']));
        self::assertSame(2, $count($r1, ['reviewer', 'stock_controller']));
    }
}
