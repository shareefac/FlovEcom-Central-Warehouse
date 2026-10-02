<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Suppliers\Suppliers;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;

/**
 * The document review queue (documents.review; I19): "Waiting for approval (blocking)" (the requests that hold a
 * document back until a second person approves) and "Posted, waiting for review" (post first, a second person
 * reviews), oldest first, with the due date and an overdue flag; and the approve / reject forms of the document page.
 * Since I-2 the queue also lists the open supplier tasks (type "Supplier", I40): activations and import-route approvals
 * with the blocking approvals, change reviews with the reviews; they link to the supplier's card, where they are decided.
 * A task the viewer may not decide (their own document) is listed with the reason, never with a form; the menu badge
 * counts only the tasks they may decide. Every decision goes through CW\Documents\Documents, which checks it again.
 * Since the pos task (I-2, I53): `?type=PO` (a document type, or Supplier) narrows the queue (the reviewer's weekly PO
 * routine); the units of an over_value approval are whole GBP and shown as £; a PO and its cancellation are decided on
 * the order's page in Purchasing, where a decision comes back to. Since 0013 (I94) the checks of a person's confirmation of
 * their own change of the company details are listed too (type "Company details", `?type=Company`, labelled with the
 * watched fields changed), decided on the Company details page.
 */
final class ReviewsController
{
    public function queue(Context $ctx): HtmlResponse
    {
        $me = $ctx->me();
        $types = array_map('strval', $ctx->db->column('SELECT code FROM document_type'));
        $type = $ctx->req->param('type');
        $type = $type !== null && (in_array($type, $types, true) || $type === 'Supplier' || $type === 'Company') ? $type : null;
        $now = gmdate('Y-m-d H:i:s');
        $lists = ['approval' => [], 'review' => []];
        foreach ($ctx->db->all(
            'SELECT t.id AS task_id, t.kind, t.reason AS task_reason, t.units, t.opened_at, t.due_at, o.display_name AS opened_by_name, d.* '
            . 'FROM review_task t JOIN document d ON d.id = t.subject_id LEFT JOIN staff_user o ON o.id = t.opened_by '
            . "WHERE t.subject_type = 'document' AND t.state = 'open' ORDER BY t.opened_at, t.id",
        ) as $r) {
            $doc = Document::fromRow($r);
            if ($type !== null && $doc->docType !== $type) {
                continue;
            }
            $no = Documents::refusal($me->id, $me->roles, $doc, (string) $r['kind']);
            $lists[(string) $r['kind']][] = [
                'task_id' => (int) $r['task_id'], 'document_id' => $doc->id, 'label' => $doc->label() . ($doc->isReversal() ? ' (cancellation)' : ''),
                'type' => $doc->docType, 'href' => $doc->docType === 'PO' ? '/ui/purchasing/orders/' . ($doc->reversesId ?? $doc->id) : '/ui/documents/' . $doc->id,
                'reason' => (string) $r['task_reason'], 'units' => $r['units'], 'money' => $r['task_reason'] === 'over_value', 'opened_by' => $r['opened_by_name'],
                'opened_at' => $r['opened_at'], 'due_at' => $r['due_at'], 'overdue' => (string) $r['due_at'] < $now, 'refusal' => $no['message'] ?? null,
            ];
        }
        // Supplier tasks (I-2, I40): activations and import routes are blocking approvals, a changed active supplier is a review.
        // They are decided on the supplier's card (CW\Suppliers\Suppliers::refusal says who may).
        foreach ($ctx->db->all(
            'SELECT t.id AS task_id, t.kind, t.reason AS task_reason, t.opened_by, t.opened_at, t.due_at, o.display_name AS opened_by_name, '
            . 's.id AS supplier_id, s.code, s.name, s.created_by, s.details_changed_by FROM review_task t JOIN supplier s ON s.id = t.subject_id '
            . "LEFT JOIN staff_user o ON o.id = t.opened_by WHERE t.subject_type = 'supplier' AND t.state = 'open' ORDER BY t.opened_at, t.id",
        ) as $r) {
            if ($type !== null && $type !== 'Supplier') {
                continue;
            }
            $no = Suppliers::refusal($me->id, $me->roles, $r, $r);
            $lists[(string) $r['kind']][] = [
                'task_id' => (int) $r['task_id'], 'document_id' => null, 'label' => $r['code'] . ' ' . $r['name'], 'type' => 'Supplier',
                'href' => '/ui/purchasing/suppliers/' . (int) $r['supplier_id'],
                'reason' => (string) $r['task_reason'], 'units' => null, 'money' => false, 'opened_by' => $r['opened_by_name'], 'opened_at' => $r['opened_at'],
                'due_at' => $r['due_at'], 'overdue' => (string) $r['due_at'] < $now, 'refusal' => $no['message'] ?? null,
            ];
        }
        // Checks of a person's confirmation of their own change of the company details (I94): non-blocking, decided on the
        // Company details page; the label says what changed.
        $what = [];
        foreach ($ctx->company()->reviews() as $t) {
            $what[(int) $t['id']] = implode(', ', array_map(static fn (array $c): string => $c['label'], array_filter($t['changes'], static fn (array $c): bool => $c['watched'])));
        }
        foreach ($ctx->db->all(
            'SELECT t.id AS task_id, t.kind, t.reason AS task_reason, t.subject_id, t.opened_by, t.opened_at, t.due_at, o.display_name AS opened_by_name '
            . "FROM review_task t LEFT JOIN staff_user o ON o.id = t.opened_by WHERE t.subject_type = 'company' AND t.state = 'open' ORDER BY t.opened_at, t.id",
        ) as $r) {
            if ($type !== null && $type !== 'Company') {
                continue;
            }
            $no = $ctx->company()->refusal($me->id, $me->roles, $r);
            $changed = $what[(int) $r['task_id']] ?? '';
            $lists[(string) $r['kind']][] = [
                'task_id' => (int) $r['task_id'], 'document_id' => null, 'label' => 'Company details, version ' . (int) $r['subject_id'] . ($changed !== '' ? " ({$changed})" : ''),
                'type' => 'Company details',
                'href' => '/ui/reference/company', 'reason' => (string) $r['task_reason'], 'units' => null, 'money' => false, 'opened_by' => $r['opened_by_name'],
                'opened_at' => $r['opened_at'], 'due_at' => $r['due_at'], 'overdue' => (string) $r['due_at'] < $now, 'refusal' => $no['message'] ?? null,
            ];
        }
        foreach ($lists as &$list) {
            usort($list, static fn (array $a, array $b): int => [(string) $a['opened_at'], $a['task_id']] <=> [(string) $b['opened_at'], $b['task_id']]);
        }
        unset($list);
        $names = array_column($ctx->db->all('SELECT code, name FROM document_type'), 'name', 'code');
        $filter = [];
        foreach (ReferenceController::TYPE_ORDER as $code) {
            if (isset($names[$code])) {
                $filter[$code] = $names[$code];
            }
        }
        $filter['Supplier'] = 'Suppliers';
        $filter['Company'] = 'Company details';
        return $ctx->page('reviews', ['approvals' => $lists['approval'], 'reviews' => $lists['review'], 'type' => $type, 'types' => $filter], 200,
            ['title' => 'Document reviews', 'active' => 'reviews']);
    }

    public function approve(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, true);
    }

    public function reject(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, false);
    }

    private function decide(Context $ctx, bool $approve): HtmlResponse
    {
        $taskId = $ctx->id();
        $task = $ctx->db->one("SELECT t.kind, t.subject_id, d.reverses_id, d.doc_type, dt.reject_action FROM review_task t LEFT JOIN document d ON d.id = t.subject_id "
            . "LEFT JOIN document_type dt ON dt.code = d.doc_type WHERE t.id = ? AND t.subject_type = 'document'", [$taskId]);
        if ($task === null) {
            return $ctx->error(404, 'unknown_task', 'there is no such review task');
        }
        $docId = (int) $task['subject_id'];
        // A PO (and its cancellation) is decided on the order's page in Purchasing, and the decision comes back there (I53).
        $po = $task['doc_type'] === 'PO';
        $note = $ctx->req->field('note');
        try {
            if ($approve) {
                $ctx->documents()->approve($ctx->caller(), $taskId, $note);
                $notice = $task['kind'] === 'approval' ? 'approved_posted' : ($po ? 'approved_review' : 'approved');
            } else {
                $ctx->documents()->reject($ctx->caller(), $taskId, $note ?? '');
                $notice = match (true) {
                    $task['kind'] === 'approval' => 'rejected_approval',
                    $task['reverses_id'] !== null => 'rejected_reversal',
                    ($task['reject_action'] ?? 'reverse') === 'record' => 'rejected_recorded',
                    default => 'rejected_review',
                };
            }
        } catch (CwException $e) {
            return $po ? (new PurchaseOrdersController())->page($ctx, $docId, $e->httpStatus, $e) : (new DocumentsController())->page($ctx, $docId, $e->httpStatus, $e);
        }
        if ($po) {
            return HtmlResponse::redirect(Html::url('/ui/purchasing/orders/' . ($task['reverses_id'] ?? $docId), ['notice' => $notice]));
        }
        return HtmlResponse::redirect(Html::url('/ui/documents/' . $docId, ['notice' => $notice]));
    }
}
