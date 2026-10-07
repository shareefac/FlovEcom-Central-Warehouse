<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Documents;

use CW\Caller;
use CW\Documents\Documents;

/**
 * I17-I21 through the generic base and the test-only ADJ type: draft -> lines -> post (number, stock with the document
 * link, posted_hash, the post-first review task, audit), the version and status guards, who may draft and post, the
 * blocking approval (approve, reject, withdraw), the voluntary reversal and the cancelled draft.
 */
final class DocumentLifecycleTest extends DocumentTestCase
{
    public function testDraftLinesAndPostBookStockWithTheNumberTheHashAndAReviewTask(): void
    {
        $sc = $this->staffUser('stock_controller');
        [$a, $b] = [$this->item('strict', 10), $this->item('strict', 10)];
        $d = $this->docs->createDraft($sc, 'ADJ', ['external_ref' => 'SUP-77', 'warehouse' => 'MAIN', 'reason_code' => 'supplier_error',
            'note' => 'over-delivery', 'doc_date' => '2026-10-01']);
        self::assertSame(['draft', null, 1, $sc->staffUserId, $sc->actor, null], [$d->status, $d->number, $d->version, $d->createdBy, $d->createdActor, $d->reviewState]);
        self::assertSame(['SUP-77', '2026-10-01', self::warehouseId('MAIN'), 'supplier_error', 'over-delivery'],
            [$d->externalRef, $d->docDate, $d->warehouseId, $d->reasonCode, $d->note]);

        $d = $this->docs->setLines($sc, $d->id, 1, [
            ['sku_id' => $a, 'qty' => 5, 'unit_cost' => '1.25'],
            ['sku_code' => sprintf('CW-%06d', $b), 'warehouse' => 'MAIN', 'qty' => -2, 'reason_code' => 'damaged', 'description' => 'crushed box'],
            ['amount' => '-3.5', 'description' => 'carriage refund'],
        ]);
        self::assertSame(2, $d->version);
        self::assertSame([
            ['line_no' => 1, 'sku_id' => $a, 'warehouse_id' => null, 'qty' => 5, 'unit_cost' => '1.250000', 'amount' => null, 'reason_code' => null, 'description' => null],
            ['line_no' => 2, 'sku_id' => $b, 'warehouse_id' => self::warehouseId('MAIN'), 'qty' => -2, 'unit_cost' => null, 'amount' => null, 'reason_code' => 'damaged',
                'description' => 'crushed box'],
            ['line_no' => 3, 'sku_id' => null, 'warehouse_id' => null, 'qty' => null, 'unit_cost' => null, 'amount' => '-3.500000', 'reason_code' => null,
                'description' => 'carriage refund'],
        ], $this->docs->lines($d->id));
        self::refused(409, 'version_conflict', fn () => $this->docs->setLines($sc, $d->id, 1, []));
        self::refused(422, 'bad_line', fn () => $this->docs->post($sc, $d->id, 2), 'the type refuses a line without an item');
        $d = $this->docs->setLines($sc, $d->id, 2, [['sku_id' => $a, 'qty' => 5, 'unit_cost' => '1.25'],
            ['sku_code' => sprintf('CW-%06d', $b), 'warehouse' => 'MAIN', 'qty' => -2, 'reason_code' => 'damaged', 'description' => 'crushed box']]);

        $p = $this->docs->post($sc, $d->id, 3);
        self::assertSame(['posted', 'ADJ-000001', 4, $sc->staffUserId, $sc->actor, 'pending'],
            [$p->status, $p->number, $p->version, $p->postedBy, $p->postedActor, $p->reviewState]);
        self::assertSame(Documents::fingerprint($p, $this->docs->lines($p->id)), $p->postedHash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $p->postedHash);
        // I33: the write-once posting record keeps the hash, who and when, and the content it covers.
        $rec = self::$db->one('SELECT number, posted_hash, posted_by, posted_actor, posted_at, content FROM document_posting WHERE document_id = ?', [$p->id]);
        self::assertSame(['ADJ-000001', $p->postedHash, $sc->staffUserId, $sc->actor, $p->postedAt, Documents::canonical($p, $this->docs->lines($p->id))],
            [$rec['number'], $rec['posted_hash'], (int) $rec['posted_by'], $rec['posted_actor'], (string) $rec['posted_at'], $rec['content']]);
        self::assertSame($p->postedHash, hash('sha256', (string) $rec['content']));
        self::assertSame([[1, 'ADJ-000001', 5, '1.250000', 'document'], [2, 'ADJ-000001', -2, null, null]], $this->ledger($p->id));
        self::assertSame(["doc:{$p->id}:post"], array_values(array_unique(array_map('strval',
            self::$db->column('SELECT idem_key FROM stock_ledger WHERE document_id = ?', [$p->id])))));
        self::assertSame($sc->actor, self::$db->value('SELECT DISTINCT actor FROM stock_ledger WHERE document_id = ?', [$p->id]));
        $this->assertBal(15, 0, 0, $a);
        $this->assertBal(8, 0, 0, $b);
        self::assertSame(1, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'ADJ'"));

        $t = $this->task($this->openTask($p->id));
        self::assertSame(['review', 'all_documents', 7, $sc->staffUserId, $sc->actor],
            [$t['kind'], $t['reason'], (int) $t['units'], (int) $t['opened_by'], $t['opened_actor']]);
        self::assertSame((new \DateTimeImmutable((string) $p->postedAt))->modify('+3 days')->format('Y-m-d H:i:s.u'), (string) $t['due_at']);
        self::assertSame($p->postedAt, (string) $t['opened_at']);
        self::assertSame(['document.create', 'document.lines', 'document.lines', 'document.post'], $this->audits($p->id), 'refused changes write nothing');
        self::assertSame("doc:{$p->id}:post", self::$db->value("SELECT idem_key FROM audit_log WHERE action = 'document.post'"));
        self::assertSame($p->postedHash, json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'document.post'"), true)['posted_hash']);

        self::refused(409, 'not_draft', fn () => $this->docs->post($sc, $p->id, 4), 'posting twice');
        self::refused(409, 'not_draft', fn () => $this->docs->updateDraft($sc, $p->id, 4, ['note' => 'x']));
        self::refused(409, 'not_draft', fn () => $this->docs->setLines($sc, $p->id, 4, []));
        self::refused(409, 'not_draft', fn () => $this->docs->cancelDraft($sc, $p->id, 4, 'too late'));
    }

    public function testWhoMayDraftAndPostAndWhatADraftTakes(): void
    {
        $sc = $this->staffUser('stock_controller');
        $other = $this->staffUser('stock_controller');
        $a = $this->item('strict', 10);
        // A type without a handler (PO is live since the I-2 pos task, GRN since IM6 in I-3; SINV arrives in I-4).
        $e = self::refused(409, 'type_not_built', fn () => $this->docs->createDraft($this->staffUser('purchasing_desk'), 'SINV', []));
        self::assertSame(['type' => 'SINV', 'phase' => 'I-4'], $e->detail);
        self::assertSame('Supplier invoice documents arrive in Phase I-4', $e->getMessage());
        self::refused(403, 'admin_cannot_post', fn () => $this->docs->createDraft($this->staffUser('admin'), 'ADJ', []));
        self::refused(403, 'admin_cannot_post', fn () => $this->docs->createDraft($this->staffUser(['admin', 'stock_controller']), 'ADJ', []),
            'admin with a posting role (admin SQL only) is still refused');
        $e = self::refused(403, 'role_not_allowed', fn () => $this->docs->createDraft($this->staffUser('buyer'), 'ADJ', []));
        self::assertSame('your role (buyer) cannot post ADJ documents', $e->getMessage());
        self::refused(403, 'role_not_allowed', fn () => $this->docs->createDraft($this->staffUser('reviewer'), 'ADJ', []));
        self::refused(403, 'staff_required', fn () => $this->docs->createDraft(Caller::system('test'), 'ADJ', []));
        self::refused(403, 'staff_required', fn () => $this->docs->createDraft($this->site(), 'ADJ', []));
        self::refused(403, 'staff_not_allowed', fn () => $this->docs->createDraft($this->staffUser('stock_controller', false), 'ADJ', []));
        self::refused(400, 'unknown_type', fn () => $this->docs->createDraft($sc, 'XX', []));

        // The header.
        self::refused(400, 'bad_field', fn () => $this->docs->createDraft($sc, 'ADJ', ['supplier' => 'x']));
        self::refused(400, 'bad_field', fn () => $this->docs->createDraft($sc, 'ADJ', ['doc_date' => '2026-02-30']));
        self::refused(400, 'bad_field', fn () => $this->docs->createDraft($sc, 'ADJ', ['note' => str_repeat('x', 1001)]));
        self::refused(422, 'unknown_warehouse', fn () => $this->docs->createDraft($sc, 'ADJ', ['warehouse' => 'NOPE']));
        self::refused(422, 'unknown_reason', fn () => $this->docs->createDraft($sc, 'ADJ', ['reason_code' => 'nope']));
        self::refused(422, 'reason_not_applicable', fn () => $this->docs->createDraft($sc, 'ADJ', ['reason_code' => 'count_difference']));
        self::refused(422, 'reason_system_only', fn () => $this->docs->createDraft($sc, 'ADJ', ['reason_code' => 'opening_rebase']));

        // The lines.
        $d = $this->docs->createDraft($sc, 'ADJ', ['external_ref' => 'SUP-2']);
        $merged = $this->item('strict', 0);
        $into = $this->item('strict', 0);
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$into, $merged]);
        foreach ([
            [422, 'unknown_sku', [['sku_id' => 999_999_999, 'qty' => 1]]],
            [422, 'unknown_sku', [['sku_code' => 'CW-999999', 'qty' => 1]]],
            [422, 'merged_item', [['sku_id' => $merged, 'qty' => 1]]],
            [400, 'bad_lines', [['sku_id' => $a, 'sku_code' => 'CW-000001', 'qty' => 1]]],
            [400, 'bad_lines', [['sku_id' => $a, 'qty' => 1.5]]],
            [400, 'bad_lines', [['sku_id' => $a]]],
            [400, 'bad_lines', [['sku_id' => $a, 'qty' => 1, 'colour' => 'red']]],
            [400, 'bad_lines', [[]]],
            [400, 'bad_cost', [['sku_id' => $a, 'qty' => 1, 'unit_cost' => '-1']]],
            [400, 'bad_amount', [['amount' => '1.2345678', 'description' => 'x']]],
            [422, 'unknown_warehouse', [['sku_id' => $a, 'qty' => 1, 'warehouse' => 'NOPE']]],
            [422, 'reason_not_applicable', [['sku_id' => $a, 'qty' => 1, 'reason_code' => 'recount']]],
        ] as [$status, $code, $lines]) {
            $ex = self::refused($status, $code, fn () => $this->docs->setLines($sc, $d->id, 1, $lines));
            self::assertSame(1, $ex->detail['line'] ?? 1);
        }
        self::assertSame(1, $this->docs->get($d->id)->version, 'a refused change changes nothing');

        // Only the creator changes a draft; anyone allowed to post the type may post or cancel it.
        self::refused(403, 'not_creator', fn () => $this->docs->setLines($other, $d->id, 1, [['sku_id' => $a, 'qty' => 1]]));
        self::refused(403, 'not_creator', fn () => $this->docs->updateDraft($other, $d->id, 1, ['note' => 'mine now']));
        $d = $this->docs->updateDraft($sc, $d->id, 1, ['note' => 'counted twice', 'reason_code' => 'data_correction']);
        self::assertSame(['SUP-2', 'counted twice', 'data_correction', 2], [$d->externalRef, $d->note, $d->reasonCode, $d->version]);
        self::refused(422, 'no_lines', fn () => $this->docs->post($other, $d->id, 2));
        $d = $this->docs->setLines($sc, $d->id, 2, [['sku_id' => $a, 'qty' => -1]]);
        $d = $this->docs->updateDraft($sc, $d->id, 3, ['note' => null]);
        self::refused(422, 'note_required', fn () => $this->docs->post($other, $d->id, 4), 'data_correction needs a note');
        $d = $this->docs->updateDraft($sc, $d->id, 4, ['note' => 'typed 10 instead of 1']);
        $p = $this->docs->post($other, $d->id, 5);
        self::assertSame(['posted', $sc->staffUserId, $other->staffUserId], [$p->status, $p->createdBy, $p->postedBy]);
        $this->assertBal(9, 0, 0, $a);
    }

    public function testABlockingApprovalIsApprovedRejectedOrWithdrawn(): void
    {
        $sc = $this->staffUser('stock_controller');
        $rev = $this->staffUser('reviewer');
        $a = $this->item('strict', 10);
        // 11 positive units without a supplier document: over the ADJ limit (10) -> held back.
        $d = $this->posted($sc, [['sku_id' => $a, 'qty' => 6], ['sku_id' => $a, 'qty' => 5, 'warehouse' => 'VERIFY'], ['sku_id' => $a, 'qty' => -3]], []);
        self::assertSame(['awaiting_approval', null, $sc->staffUserId, null], [$d->status, $d->number, $d->submittedBy, $d->reviewState]);
        self::assertNotNull($d->submittedAt);
        self::assertSame([], $this->ledger($d->id), 'nothing booked');
        self::assertSame(0, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'ADJ'"), 'nothing numbered');
        $t = $this->task($this->openTask($d->id, 'approval'));
        self::assertSame(['approval', 'positive_without_supplier_doc', 11, $sc->staffUserId], [$t['kind'], $t['reason'], (int) $t['units'], (int) $t['opened_by']]);
        self::assertSame(0, $this->openTask($d->id, 'review'));
        self::assertContains('document.submit', $this->audits($d->id));
        self::refused(409, 'not_draft', fn () => $this->docs->setLines($sc, $d->id, $d->version, []), 'held back, not editable');

        $p = $this->docs->approve($rev, (int) $t['id'], 'supplier confirmed by phone');
        self::assertSame(['posted', 'ADJ-000001', $sc->staffUserId, $sc->actor, 'approved'], [$p->status, $p->number, $p->postedBy, $p->postedActor, $p->reviewState],
            "the requester's posting");
        $t = $this->task((int) $t['id']);
        self::assertSame(['approved', $rev->staffUserId, 'supplier confirmed by phone'], [$t['state'], (int) $t['decided_by'], $t['decision_note']]);
        self::assertSame(0, $this->openTask($p->id, 'review'), 'the approval was the review');
        self::assertSame([$sc->actor], array_values(array_unique(array_map('strval', self::$db->column('SELECT actor FROM stock_ledger WHERE document_id = ?', [$p->id])))));
        $this->assertBal(13, 0, 0, $a);
        $this->assertBal(5, 0, 0, $a, 'VERIFY');
        self::assertSame(['document.approve', 'document.post'], array_slice($this->audits($p->id), -2));
        // I34: the posting is the requester's, but the audit row names the reviewer who performed it.
        $post = self::$db->one("SELECT actor, detail FROM audit_log WHERE action = 'document.post' AND entity_id = ?", [(string) $p->id]);
        self::assertSame($rev->actor, $post['actor']);
        $detail = (array) json_decode((string) $post['detail'], true);
        self::assertSame([$sc->staffUserId, $rev->staffUserId], [$detail['on_behalf_of'] ?? null, $detail['approved_by'] ?? null]);

        // Rejected: the request is cancelled, nothing booked.
        $x = $this->posted($sc, [['sku_id' => $a, 'qty' => 20]], []);
        self::refused(400, 'note_required', fn () => $this->docs->reject($rev, $this->openTask($x->id, 'approval'), 'no'));
        $c = $this->docs->reject($rev, $this->openTask($x->id, 'approval'), 'no supplier document');
        self::assertSame(['cancelled', null, $rev->staffUserId, 'no supplier document'], [$c->status, $c->number, $c->cancelledBy, $c->cancelReason]);
        self::assertSame([], $this->ledger($x->id));

        // Withdrawn by the requester: a draft again, editable, postable.
        $w = $this->posted($sc, [['sku_id' => $a, 'qty' => 12]], []);
        $task = $this->openTask($w->id, 'approval');
        self::refused(403, 'not_requester', fn () => $this->docs->withdraw($this->staffUser('stock_controller'), $task));
        $w = $this->docs->withdraw($sc, $task);
        self::assertSame(['draft', null, null], [$w->status, $w->submittedBy, $w->submittedAt]);
        self::assertSame(['withdrawn', null, 'withdrawn by the requester'], [$this->task($task)['state'], $this->task($task)['decided_by'], $this->task($task)['decision_note']]);
        self::refused(409, 'task_closed', fn () => $this->docs->approve($rev, $task, null));
        $w = $this->docs->setLines($sc, $w->id, $w->version, [['sku_id' => $a, 'qty' => 4]]);
        $w = $this->docs->post($sc, $w->id, $w->version);
        self::assertSame(['posted', 'ADJ-000002', 'pending'], [$w->status, $w->number, $w->reviewState]);

        // A requester who may no longer post: the reviewer has to reject instead.
        $y = $this->posted($sc, [['sku_id' => $a, 'qty' => 11]], []);
        self::$db->exec("UPDATE staff_role SET revoked_at = NOW(6) WHERE staff_user_id = ? AND role = 'stock_controller'", [$sc->staffUserId]);
        self::refused(409, 'requester_cannot_post', fn () => $this->docs->approve($rev, $this->openTask($y->id, 'approval'), null));
        self::assertSame('awaiting_approval', $this->docs->get($y->id)->status);
        self::assertSame('cancelled', $this->docs->reject($rev, $this->openTask($y->id, 'approval'), 'requester left the team')->status);
    }

    public function testAVoluntaryReversalNegatesTheDocumentInTheSameSeries(): void
    {
        $sc = $this->staffUser('stock_controller');
        [$a, $b] = [$this->item('strict', 10), $this->item('strict', 10)];
        $p = $this->posted($sc, [['sku_id' => $a, 'qty' => 4, 'unit_cost' => '2.5'], ['sku_id' => $b, 'qty' => -3, 'warehouse' => 'VERIFY']]);
        $task = $this->openTask($p->id);
        self::refused(403, 'role_not_allowed', fn () => $this->docs->reverse($this->staffUser('buyer'), $p->id, 'entered_in_error', null));
        self::refused(422, 'reason_system_only', fn () => $this->docs->reverse($sc, $p->id, 'review_rejected', null));
        self::refused(422, 'reason_not_applicable', fn () => $this->docs->reverse($sc, $p->id, 'damaged', null));
        self::refused(422, 'note_required', fn () => $this->docs->reverse($sc, $p->id, 'other', null));

        $r = $this->docs->reverse($sc, $p->id, 'entered_in_error', 'booked on the wrong day');
        self::assertSame(['ADJ', 'ADJ-000002', 'posted', $p->id, 'entered_in_error', 'booked on the wrong day', $sc->staffUserId, 'pending'],
            [$r->docType, $r->number, $r->status, $r->reversesId, $r->reasonCode, $r->note, $r->createdBy, $r->reviewState]);
        self::assertSame([[1, $a, 'SUP-1', -4, '2.500000'], [2, $b, 'SUP-1', 3, null]], array_map(
            static fn (array $l): array => [$l['line_no'], $l['sku_id'], $r->externalRef, $l['qty'], $l['unit_cost']], $this->docs->lines($r->id)));
        self::assertSame(Documents::fingerprint($r, $this->docs->lines($r->id)), $r->postedHash);
        $this->assertBal(10, 0, 0, $a);
        $this->assertBal(0, 0, 0, $b, 'VERIFY');
        self::assertSame([[1, 'ADJ-000002', -4, '2.500000', 'document'], [2, 'ADJ-000002', 3, null, null]], $this->ledger($r->id));
        self::assertSame('reversed', $this->docs->get($p->id)->status);
        self::assertSame(['withdrawn', null, 'reversed by ADJ-000002'], [$this->task($task)['state'], $this->task($task)['decided_by'], $this->task($task)['decision_note']]);
        $rt = $this->task($this->openTask($r->id));
        self::assertSame(['all_documents', 7, $sc->staffUserId], [$rt['reason'], (int) $rt['units'], (int) $rt['opened_by']], 'the reversal is reviewed like the type');
        self::assertContains('document.reverse', $this->audits($r->id));
        self::assertSame("doc:{$r->id}:reverse", self::$db->value("SELECT idem_key FROM audit_log WHERE action = 'document.reverse'"));

        self::refused(409, 'not_reversible', fn () => $this->docs->reverse($sc, $r->id, 'duplicate', null), 'a reversal is never reversed');
        self::refused(409, 'not_reversible', fn () => $this->docs->reverse($sc, $p->id, 'duplicate', null), 'reversed already');
        $draft = $this->draft($sc, [['sku_id' => $a, 'qty' => 1]]);
        self::refused(409, 'not_reversible', fn () => $this->docs->reverse($sc, $draft->id, 'duplicate', null));
        self::refused(404, 'unknown_document', fn () => $this->docs->reverse($sc, 999_999, 'duplicate', null));
    }

    /**
     * I32 (review finding): a voluntary reversal that puts stock back on hand without a supplier document above the
     * approval limit (the reversal of a write-down) is a request, like a positive adjustment: unnumbered and unbooked
     * until a reviewer approves it; approved it is posted as the requester's reversal; rejected or withdrawn it is
     * cancelled and the document can be reversed again; one live reversal per document.
     */
    public function testAReversalThatPutsStockBackWaitsForTheBlockingApproval(): void
    {
        $sc = $this->staffUser('stock_controller');
        $other = $this->staffUser('stock_controller');
        $rev = $this->staffUser('reviewer');
        [$a, $b] = [$this->item('strict', 60), $this->item('strict', 60)];
        // A correct write-down of 50 (with a supplier document, so posted at once), reviewed.
        $p = $this->posted($sc, [['sku_id' => $a, 'qty' => -30], ['sku_id' => $a, 'qty' => -20], ['sku_id' => $b, 'qty' => -4]]);
        $this->docs->approve($rev, $this->openTask($p->id), 'counted');
        $this->assertBal(10, 0, 0, $a);

        $r = $this->docs->reverse($other, $p->id, 'entered_in_error', null);
        self::assertSame(['awaiting_approval', null, $p->id, $other->staffUserId, $other->staffUserId, null], [$r->status, $r->number, $r->reversesId,
            $r->createdBy, $r->submittedBy, $r->reviewState]);
        self::assertSame([30, 20, 4], array_map(static fn (array $l): int => (int) $l['qty'], $this->docs->lines($r->id)), 'the lines are the reversal\'s');
        self::assertSame([], $this->ledger($r->id), 'nothing booked');
        self::assertSame(1, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'ADJ'"), 'nothing numbered');
        self::assertSame('posted', $this->docs->get($p->id)->status, 'the original stands until the approval');
        $this->assertBal(10, 0, 0, $a);
        $t = $this->task($this->openTask($r->id, 'approval'));
        self::assertSame(['positive_without_supplier_doc', 54, $other->staffUserId], [$t['reason'], (int) $t['units'], (int) $t['opened_by']]);
        self::assertContains('document.submit', $this->audits($r->id));
        self::refused(409, 'reversal_pending', fn () => $this->docs->reverse($sc, $p->id, 'duplicate', null), 'one live reversal');
        self::assertSame('own_document', Documents::refusal((int) $other->staffUserId, ['reviewer', 'stock_controller'], $r, 'approval')['code'] ?? null,
            'the requester never approves their own reversal');

        // Rejected: the request is cancelled, nothing booked; the document can be reversed again.
        $c = $this->docs->reject($rev, (int) $t['id'], 'the write-down was right');
        self::assertSame(['cancelled', null], [$c->status, $c->number]);
        self::assertSame('posted', $this->docs->get($p->id)->status);
        // Withdrawn by its requester: cancelled too (a reversal is never a draft).
        $w = $this->docs->reverse($other, $p->id, 'entered_in_error', null);
        self::refused(403, 'not_requester', fn () => $this->docs->withdraw($sc, $this->openTask($w->id, 'approval')));
        $w = $this->docs->withdraw($other, $this->openTask($w->id, 'approval'));
        self::assertSame(['cancelled', 'reversal request withdrawn by the requester'], [$w->status, $w->cancelReason]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id IN (?, ?)', [$c->id, $w->id]));

        // Approved: posted now as the requester's reversal, the original reversed, the stock back, no review of its own.
        $q = $this->docs->reverse($other, $p->id, 'entered_in_error', 'counted the wrong shelf');
        $task = $this->openTask($q->id, 'approval');
        $done = $this->docs->approve($rev, $task, 'checked the shelf');
        self::assertSame(['posted', 'ADJ-000002', $other->staffUserId, $other->actor, 'approved'],
            [$done->status, $done->number, $done->postedBy, $done->postedActor, $done->reviewState]);
        self::assertSame('reversed', $this->docs->get($p->id)->status);
        self::assertSame(0, $this->openTask($q->id), 'the approval was the review');
        $this->assertBal(60, 0, 0, $a);
        $this->assertBal(60, 0, 0, $b);
        self::assertSame([$other->actor], array_values(array_unique(array_map('strval', self::$db->column('SELECT actor FROM stock_ledger WHERE document_id = ?', [$q->id])))));
        $audit = self::$db->one("SELECT actor, detail FROM audit_log WHERE action = 'document.reverse'");
        self::assertSame($rev->actor, $audit['actor'], 'audited under the reviewer who performed it (I34)');
        self::assertSame($other->staffUserId, json_decode((string) $audit['detail'], true)['on_behalf_of']);
        self::refused(409, 'not_reversible', fn () => $this->docs->reverse($sc, $p->id, 'duplicate', null));

        // A reversal that puts back no more than the limit, or nets to nothing per item, is posted at once.
        $small = $this->posted($sc, [['sku_id' => $a, 'qty' => -10]]);
        self::assertSame('posted', $this->docs->reverse($other, $small->id, 'duplicate', null)->status);
        $move = $this->posted($sc, [['sku_id' => $b, 'qty' => -40], ['sku_id' => $b, 'qty' => 40, 'warehouse' => 'VERIFY']]);
        self::assertSame('posted', $this->docs->reverse($other, $move->id, 'duplicate', null)->status, 'a move between warehouses adds nothing');
    }

    /**
     * I32: rejecting the review of a document whose reversal request still waits for approval cancels that request
     * and posts the rejection's reversal (one live reversal per document).
     */
    public function testRejectingTheOriginalCancelsAWaitingReversalRequest(): void
    {
        $sc = $this->staffUser('stock_controller');
        $other = $this->staffUser('stock_controller');
        $rev = $this->staffUser('reviewer');
        $a = $this->item('strict', 40);
        $p = $this->posted($sc, [['sku_id' => $a, 'qty' => -25]]);
        $req = $this->docs->reverse($other, $p->id, 'entered_in_error', null);
        self::assertSame('awaiting_approval', $req->status);
        $o = $this->docs->reject($rev, $this->openTask($p->id), 'wrong item');
        self::assertSame(['reversed', 'rejected'], [$o->status, $o->reviewState]);
        $req = $this->docs->get($req->id);
        self::assertSame('cancelled', $req->status);
        self::assertStringContainsString('superseded', (string) $req->cancelReason);
        self::assertSame(0, $this->openTask($req->id, 'approval'));
        $r = $this->docs->get((int) self::$db->value("SELECT id FROM document WHERE reverses_id = ? AND status = 'posted'", [$p->id]));
        self::assertSame(['ADJ-000002', 'review_rejected'], [$r->number, $r->reasonCode]);
        $this->assertBal(40, 0, 0, $a);
    }

    /**
     * I29 (review finding): nothing locks a balance after the value clocks or the feed clock, whatever the Stock
     * instance. A handler whose reverse() books stock (the docblock says module rows only) makes the generic stock
     * reversal lock again: refused, and the whole reversal rolls back.
     */
    public function testAHandlerThatBooksStockInReverseIsRefused(): void
    {
        $sc = $this->staffUser('stock_controller');
        $a = $this->item('strict', 10);
        $inner = new \CW\Tests\Support\Documents\FixtureAdjustmentHandler(self::$db);
        $bad = new class ($inner) implements \CW\Documents\DocumentHandler {
            public function __construct(private \CW\Tests\Support\Documents\FixtureAdjustmentHandler $inner)
            {
            }

            public function type(): string
            {
                return 'ADJ';
            }

            public function validate(\CW\Db $db, \CW\Documents\Document $doc, array $lines): void
            {
                $this->inner->validate($db, $doc, $lines);
            }

            public function approvalUnits(\CW\Db $db, \CW\Documents\Document $doc, array $lines): int
            {
                return $this->inner->approvalUnits($db, $doc, $lines);
            }

            public function post(\CW\Db $db, \CW\Documents\Document $doc, array $lines, Caller $caller, string $opKey): int
            {
                return $this->inner->post($db, $doc, $lines, $caller, $opKey);
            }

            public function reverse(\CW\Db $db, \CW\Documents\Document $original, \CW\Documents\Document $reversal, array $lines, Caller $caller, string $opKey): void
            {
                // A module booking stock itself, with its own Stock (what the I7 per-instance guard could not see).
                $stock = new \CW\Stock($db);
                $wh = (int) $db->value("SELECT id FROM warehouse WHERE code = 'MAIN'");
                $stock->lock([[$wh, (int) $lines[0]['sku_id']]]);
                $stock->apply($wh, (int) $lines[0]['sku_id'], 'on_hand', 1, ['type' => 'adjustment', 'actor' => $caller->actor, 'note' => 'module stock']);
                $stock->flush();
            }
        };
        $docs = new Documents(self::$db, ['ADJ' => $bad]);
        $d = $docs->createDraft($sc, 'ADJ', ['external_ref' => 'SUP-9']);
        $d = $docs->setLines($sc, $d->id, $d->version, [['sku_id' => $a, 'qty' => 3]]);
        $p = $docs->post($sc, $d->id, $d->version);
        try {
            $docs->reverse($sc, $p->id, 'duplicate', null);
            self::fail('the stock reversal locked balances after the handler\'s clocks');
        } catch (\LogicException $e) {
            self::assertStringContainsString('no balance is locked after them', $e->getMessage());
        }
        self::assertSame('posted', $docs->get($p->id)->status, 'rolled back whole');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM document WHERE reverses_id = ?', [$p->id]));
        $this->assertBal(13, 0, 0, $a);
    }

    public function testACancelledDraftIsNeverNumbered(): void
    {
        $sc = $this->staffUser('stock_controller');
        $d = $this->draft($sc, [['sku_id' => $this->item('strict', 1), 'qty' => 1]]);
        self::refused(400, 'bad_cancel_reason', fn () => $this->docs->cancelDraft($sc, $d->id, $d->version, 'no'));
        self::refused(403, 'role_not_allowed', fn () => $this->docs->cancelDraft($this->staffUser('buyer'), $d->id, $d->version, 'not needed'));
        $c = $this->docs->cancelDraft($sc, $d->id, $d->version, 'not needed after all');
        self::assertSame(['cancelled', null, $sc->staffUserId, 'not needed after all', null], [$c->status, $c->number, $c->cancelledBy, $c->cancelReason, $c->reviewState]);
        self::assertNotNull($c->cancelledAt);
        self::assertSame('cancelled #' . $c->id, $c->label());
        self::assertSame(0, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'ADJ'"));
        self::refused(409, 'not_draft', fn () => $this->docs->post($sc, $c->id, $c->version));
        self::assertSame('ADJ-000001', $this->posted($sc, [['sku_id' => $this->item('strict', 1), 'qty' => 1]])->number, 'the next posting takes number 1');
    }
}
