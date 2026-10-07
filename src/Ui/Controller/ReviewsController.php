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
use CW\Ui\Words;

/**
 * Things to check (To check > Waiting for me; documents.review; I19): "Needs your OK before anything happens" (the requests
 * that hold a document back until a second person approves) and "Already done: please check it" (post first, a second
 * person reviews), oldest first, with the check-by date and a "Late" chip; and the approve / reject forms of the document page.
 * The words are Ui\Words' (plan §6.8): a record is named by Words::docTitle (an order by its supplier), money is £, the
 * refusals are translated by code (Words::REFUSAL).
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
        // A purchase order (and its cancellation record, which has no purchase_order row of its own) is named by its supplier and
        // valued by the order's net total: review_task.units holds whole pounds for an order, never "units" (plan F095, F099).
        foreach ($ctx->db->all(
            'SELECT t.id AS task_id, t.kind, t.reason AS task_reason, t.units, t.opened_at, t.due_at, o.display_name AS opened_by_name, d.*, '
            . 'od.number AS cancels_number, s.name AS supplier_name, po.net_total AS po_net, dt.name AS type_name '
            . 'FROM review_task t JOIN document d ON d.id = t.subject_id LEFT JOIN staff_user o ON o.id = t.opened_by '
            . 'LEFT JOIN document od ON od.id = d.reverses_id LEFT JOIN document_type dt ON dt.code = d.doc_type '
            . 'LEFT JOIN purchase_order po ON po.document_id = COALESCE(d.reverses_id, d.id) LEFT JOIN supplier s ON s.id = po.supplier_id '
            . "WHERE t.subject_type = 'document' AND t.state = 'open' ORDER BY t.opened_at, t.id",
        ) as $r) {
            $doc = Document::fromRow($r);
            if ($type !== null && $doc->docType !== $type) {
                continue;
            }
            $no = Documents::refusal($me->id, $me->roles, $doc, (string) $r['kind']);
            $isPo = $doc->docType === 'PO';
            $lists[(string) $r['kind']][] = [
                'task_id' => (int) $r['task_id'], 'document_id' => $doc->id,
                'label' => Words::docTitle($doc->docType, $doc->number, $r['supplier_name'] === null ? null : (string) $r['supplier_name'],
                    $doc->isReversal() ? (string) ($r['cancels_number'] ?? '#' . $doc->reversesId) : null, $r['type_name'] === null ? null : (string) $r['type_name']),
                'type' => $doc->docType, 'href' => $isPo ? '/ui/purchasing/orders/' . ($doc->reversesId ?? $doc->id) : '/ui/documents/' . $doc->id,
                'reason' => (string) $r['task_reason'], 'money' => $isPo && $r['po_net'] !== null ? (string) $r['po_net'] : null,
                'items' => !$isPo && $r['units'] !== null ? (int) $r['units'] : null, 'opened_by' => $r['opened_by_name'],
                'opened_at' => $r['opened_at'], 'due_at' => $r['due_at'], 'overdue' => (string) $r['due_at'] < $now, 'refusal' => Words::refusal($no),
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
                'task_id' => (int) $r['task_id'], 'document_id' => null, 'label' => Words::say('CHECKS', 'supplier', (string) $r['name']), 'type' => 'Supplier',
                'href' => '/ui/purchasing/suppliers/' . (int) $r['supplier_id'],
                'reason' => (string) $r['task_reason'], 'money' => null, 'items' => null, 'opened_by' => $r['opened_by_name'], 'opened_at' => $r['opened_at'],
                'due_at' => $r['due_at'], 'overdue' => (string) $r['due_at'] < $now, 'refusal' => Words::refusal($no),
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
                'task_id' => (int) $r['task_id'], 'document_id' => null, 'label' => Words::CHECKS['company_changed'] . ($changed !== '' ? " ({$changed})" : ''),
                'type' => 'Company details',
                'href' => '/ui/reference/company', 'reason' => (string) $r['task_reason'], 'money' => null, 'items' => null, 'opened_by' => $r['opened_by_name'],
                'opened_at' => $r['opened_at'], 'due_at' => $r['due_at'], 'overdue' => (string) $r['due_at'] < $now, 'refusal' => Words::refusal($no),
            ];
        }
        foreach ($lists as &$list) {
            usort($list, static fn (array $a, array $b): int => [(string) $a['opened_at'], $a['task_id']] <=> [(string) $b['opened_at'], $b['task_id']]);
        }
        unset($list);
        // The filter offers only the kinds in use today (plan F102): the live document types, suppliers and the company details.
        $docs = $ctx->documents();
        $filter = [];
        foreach (ReferenceController::TYPE_ORDER as $code) {
            if (in_array($code, $types, true) && ($docs->handler($code) !== null || $type === $code)) {
                $filter[$code] = Words::docType($code, true);
            }
        }
        $filter['Supplier'] = Words::CHECKS['suppliers'];
        $filter['Company'] = Words::CHECKS['company'];
        return $ctx->page('reviews', ['approvals' => $lists['approval'], 'reviews' => $lists['review'], 'type' => $type, 'types' => $filter], 200,
            ['title' => Words::title('reviews'), 'active' => 'reviews']);
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
