<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;

/**
 * The document review queue (documents.review; I19): "Waiting for approval (blocking)" (the requests that hold a
 * document back until a second person approves) and "Posted, waiting for review" (post first, a second person
 * reviews), oldest first, with the due date and an overdue flag; and the approve / reject forms of the document page.
 * A task the viewer may not decide (their own document) is listed with the reason, never with a form; the menu badge
 * counts only the tasks they may decide. Every decision goes through CW\Documents\Documents, which checks it again.
 */
final class ReviewsController
{
    public function queue(Context $ctx): HtmlResponse
    {
        $me = $ctx->me();
        $now = gmdate('Y-m-d H:i:s');
        $lists = ['approval' => [], 'review' => []];
        foreach ($ctx->db->all(
            'SELECT t.id AS task_id, t.kind, t.reason AS task_reason, t.units, t.opened_at, t.due_at, o.display_name AS opened_by_name, d.* '
            . 'FROM review_task t JOIN document d ON d.id = t.subject_id LEFT JOIN staff_user o ON o.id = t.opened_by '
            . "WHERE t.subject_type = 'document' AND t.state = 'open' ORDER BY t.opened_at, t.id",
        ) as $r) {
            $doc = Document::fromRow($r);
            $no = Documents::refusal($me->id, $me->roles, $doc, (string) $r['kind']);
            $lists[(string) $r['kind']][] = [
                'task_id' => (int) $r['task_id'], 'document_id' => $doc->id, 'label' => $doc->label(), 'type' => $doc->docType,
                'reason' => (string) $r['task_reason'], 'units' => $r['units'], 'opened_by' => $r['opened_by_name'], 'opened_at' => $r['opened_at'],
                'due_at' => $r['due_at'], 'overdue' => (string) $r['due_at'] < $now, 'refusal' => $no['message'] ?? null,
            ];
        }
        return $ctx->page('reviews', ['approvals' => $lists['approval'], 'reviews' => $lists['review']], 200,
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
        $task = $ctx->db->one("SELECT t.kind, t.subject_id, d.reverses_id FROM review_task t LEFT JOIN document d ON d.id = t.subject_id "
            . "WHERE t.id = ? AND t.subject_type = 'document'", [$taskId]);
        if ($task === null) {
            return $ctx->error(404, 'unknown_task', 'there is no such review task');
        }
        $docId = (int) $task['subject_id'];
        $note = $ctx->req->field('note');
        try {
            if ($approve) {
                $ctx->documents()->approve($ctx->caller(), $taskId, $note);
                $notice = $task['kind'] === 'approval' ? 'approved_posted' : 'approved';
            } else {
                $ctx->documents()->reject($ctx->caller(), $taskId, $note ?? '');
                $notice = $task['kind'] === 'approval' ? 'rejected_approval' : ($task['reverses_id'] === null ? 'rejected_review' : 'rejected_reversal');
            }
        } catch (CwException $e) {
            return (new DocumentsController())->page($ctx, $docId, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/documents/' . $docId, ['notice' => $notice]));
    }
}
