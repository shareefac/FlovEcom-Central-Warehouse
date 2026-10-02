<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Output\PdfWriter;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;

/**
 * The documents screens (IM1, documents.view): the list with filters, one document (header, status, review state,
 * reversal links both ways, lines, files, review history, and the forms its viewer may use), its PDF, and the
 * voluntary reversal. Every change goes through CW\Documents\Documents, which checks the person again (doc.<TYPE>.post
 * for a reversal, the review rules for a decision); a refusal is shown on the same page under its status.
 *
 * A reversal's review can be rejected but never reverses it (I31: the page says so); a reversal that puts stock back on
 * hand above the approval limit is a request until a reviewer approves it (I32).
 *
 * A type without a handler says which phase brings its screens (I27); the list names the live types (PO since the I-2
 * pos task, whose own screens are in Purchasing: a PO's page here links "Open in Purchasing", I53). Nothing here creates
 * documents (the type screens of I-2 to I-6 do).
 */
final class DocumentsController
{
    public const PER_PAGE = 50;
    /** Reversal reasons that only make sense for a purchase order (0010). */
    public const PO_REASONS = ['po_amended', 'supplier_cannot_supply', 'not_needed'];
    /** notice key => text. Only these can be shown: a notice never comes from the URL as text. */
    public const NOTICES = [
        'approved' => 'Review approved.',
        'approved_posted' => 'Approved: the document is posted now, as the requester\'s posting.',
        'rejected_review' => 'Rejected: the document was reversed (its reversal is linked below).',
        'rejected_reversal' => 'Rejected: the rejection is recorded and nothing was booked, because a reversal is never reversed. '
            . 'The original stays reversed: if it was right, its poster posts it again as a new document.',
        'rejected_approval' => 'Rejected: the request was cancelled; nothing was booked.',
        'rejected_recorded' => 'Rejected: the rejection is recorded and the document stands (its type records a rejection instead of reversing: '
            . 'a purchase order may already be with the supplier). Its poster cancels or amends it.',
        'reversed' => 'Reversal posted: the stock of the original document is booked back.',
        'reversal_submitted' => 'Reversal requested: it puts stock back on hand without a supplier document above the limit, so a reviewer '
            . 'approves it before it is posted (nothing is numbered or booked until then).',
    ];

    public function index(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $docs = $ctx->documents();
        $types = $ctx->db->all('SELECT code, name, phase FROM document_type');
        usort($types, static fn (array $a, array $b): int => array_search($a['code'], ReferenceController::TYPE_ORDER, true)
            <=> array_search($b['code'], ReferenceController::TYPE_ORDER, true));
        $f = [
            'type' => in_array($req->param('type'), array_column($types, 'code'), true) ? $req->param('type') : null,
            'status' => in_array($req->param('status'), Document::STATUSES, true) ? $req->param('status') : null,
            'review' => in_array($req->param('review'), Document::REVIEW_STATES, true) ? $req->param('review') : null,
            'q' => mb_substr(trim($req->param('q') ?? ''), 0, 100),
        ];
        $page = max(1, min(10_000, UiRequest::id($req->param('page')) ?? 1));
        $where = [];
        $params = [];
        foreach (['type' => 'd.doc_type', 'status' => 'd.status', 'review' => 'd.review_state'] as $k => $col) {
            if ($f[$k] !== null) {
                $where[] = "{$col} = ?";
                $params[] = $f[$k];
            }
        }
        if (!$ctx->me()->can('purchasing.view')) {
            // Purchase orders (prices, suppliers) are for people with Purchasing (review finding, I85): warehouse and
            // stock-control staff see every other document.
            $where[] = "d.doc_type <> 'PO'";
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(d.number LIKE ? OR d.external_ref LIKE ?)';
            array_push($params, $like, $like);
        }
        $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $ctx->db->value('SELECT COUNT(*) FROM document d' . $sqlWhere, $params);
        $rows = $ctx->db->all(
            'SELECT d.id, d.doc_type, d.number, d.status, d.review_state, d.doc_date, d.external_ref, d.created_at, d.posted_at, w.code AS warehouse, '
            . 'cu.display_name AS created_by_name, pu.display_name AS posted_by_name FROM document d '
            . 'LEFT JOIN warehouse w ON w.id = d.warehouse_id LEFT JOIN staff_user cu ON cu.id = d.created_by LEFT JOIN staff_user pu ON pu.id = d.posted_by'
            . $sqlWhere . ' ORDER BY d.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params,
        );
        $live = array_values(array_filter(array_column($types, 'code'), static fn (string $c): bool => $docs->handler($c) !== null));
        foreach ($rows as &$r) {
            $r['label'] = $r['number'] ?? str_replace('_', ' ', (string) $r['status']) . ' #' . $r['id'];
        }
        unset($r);
        return $ctx->page('documents', [
            'rows' => $rows,
            'total' => $total,
            'filters' => $f,
            'types' => $types,
            'statuses' => Document::STATUSES,
            'reviewStates' => Document::REVIEW_STATES,
            'live' => $live,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ], 200, ['title' => 'Documents', 'active' => 'documents']);
    }

    public function show(Context $ctx): HtmlResponse
    {
        return $this->page($ctx, $ctx->id(), 200, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function reverse(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $reason = trim($ctx->req->field('reason_code') ?? '');
        $note = $ctx->req->field('note');
        if ($reason === '') {
            return $this->page($ctx, $id, 400, new CwException('reason_required', 'choose why the document is reversed', 400));
        }
        try {
            $rev = $ctx->documents()->reverse($ctx->caller(), $id, $reason, $note);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/documents/' . $rev->id, ['notice' => $rev->status === 'awaiting_approval' ? 'reversal_submitted' : 'reversed']));
    }

    /** The document as a PDF (any status; a draft says so in its title). */
    public function pdf(Context $ctx): HtmlResponse
    {
        $data = $this->load($ctx, $ctx->id());
        if ($data === null) {
            return $ctx->error(404, 'unknown_document', 'there is no such document');
        }
        if (($no = self::poRefusal($ctx, $data['doc'])) !== null) {
            return $no;
        }
        /** @var Document $doc */
        $doc = $data['doc'];
        $pdf = new PdfWriter("{$data['type']['name']} {$doc->label()}", 'Status: ' . self::statusText($doc)
            . ($doc->postedAt !== null ? ' · posted ' . substr($doc->postedAt, 0, 16) . ' UTC' : ''), true);
        $pdf->keyValues(array_filter([
            'Document' => $doc->label(),
            'Type' => "{$data['type']['name']} ({$doc->docType})",
            'Date' => $doc->docDate,
            'Warehouse' => $data['warehouse'],
            'External reference' => $doc->externalRef,
            'Reason' => $doc->reasonCode,
            'Note' => $doc->note,
            'Created by' => $data['people']['created'],
            'Posted by' => $data['people']['posted'],
            'Reverses' => $data['reverses']['number'] ?? null,
            'Reversed by' => $data['reversedBy'] === null ? null : ($data['reversedBy']['number'] ?? 'a reversal request waiting for approval'),
            'Review' => $doc->reviewState === null ? null : str_replace('_', ' ', $doc->reviewState),
            'Fingerprint (sha256)' => $doc->postedHash,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));
        $pdf->heading('Lines');
        $pdf->table([
            ['title' => '#', 'width_mm' => 9, 'align' => 'R'],
            ['title' => 'Item', 'width_mm' => 24],
            ['title' => 'Name', 'width_mm' => 55],
            ['title' => 'Where', 'width_mm' => 17],
            ['title' => 'Qty', 'width_mm' => 15, 'align' => 'R'],
            ['title' => 'Unit cost', 'width_mm' => 18, 'align' => 'R'],
            ['title' => 'Amount', 'width_mm' => 18, 'align' => 'R'],
            ['title' => 'Reason', 'width_mm' => 24],
        ], array_map(static fn (array $l): array => [$l['line_no'], $l['sku_code'], $l['sku_name'] ?? $l['description'], $l['warehouse'], $l['qty'],
            Html::dec($l['unit_cost']), Html::dec($l['amount']), $l['reason_code']], $data['lines']));
        if ($data['files'] !== []) {
            $pdf->heading('Files');
            $pdf->table([['title' => 'Role', 'width_mm' => 30], ['title' => 'Name', 'width_mm' => 70], ['title' => 'sha256', 'width_mm' => 80]],
                array_map(static fn (array $f): array => [$f['role'], $f['original_name'], $f['sha256']], $data['files']));
        }
        return FilesController::download($pdf->output(), 'application/pdf', $doc->label() . '.pdf');
    }

    /**
     * The document page, also after a refused form (ReviewsController, reverse()): the error is shown and the page
     * answered under its status.
     */
    public function page(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice = null): HtmlResponse
    {
        $data = $this->load($ctx, $id);
        if ($data === null) {
            return $ctx->error(404, 'unknown_document', 'there is no such document');
        }
        /** @var Document $doc */
        $doc = $data['doc'];
        if (($no = self::poRefusal($ctx, $doc)) !== null) {
            return $no;
        }
        $me = $ctx->me();
        $open = null;
        foreach ($data['tasks'] as $t) {
            if ($t['state'] === 'open') {
                $open = $t;
            }
        }
        $decide = null;
        if ($open !== null && ($me->can('documents.review') || $me->can('documents.approve'))) {
            $no = Documents::refusal($me->id, $me->roles, $doc, (string) $open['kind']);
            $decide = ['task' => $open, 'refusal' => $no['message'] ?? null];
        }
        $reverse = null;
        // A PO is cancelled or amended on its own page in Purchasing (I53), not with the generic reversal form.
        if ($doc->status === 'posted' && !$doc->isReversal() && $data['reversedBy'] === null && $data['handler'] && $doc->docType !== 'PO'
            && Documents::mayPost($me->roles, $doc->docType)) {
            // The PO reversal reasons (0010: an amended order, a supplier who cannot supply, not needed) are offered on POs only.
            $reverse = ['reasons' => array_values(array_filter($ctx->db->all(
                "SELECT code, label, needs_note FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 AND system_only = 0 AND is_active = 1 "
                . 'ORDER BY sort_order, code',
            ), static fn (array $r): bool => $doc->docType === 'PO' || !in_array($r['code'], self::PO_REASONS, true)))];
        }
        return $ctx->page('document', $data + [
            // A type whose rejected review is only recorded (PO, I49); a PO's own page is in Purchasing (I53).
            'rejectRecords' => ($data['type']['reject_action'] ?? 'reverse') === 'record',
            'poHref' => $doc->docType === 'PO' && $data['handler'] && $me->can('purchasing.view') ? '/ui/purchasing/orders/' . ($doc->reversesId ?? $doc->id) : null,
            'decide' => $decide,
            'reverse' => $reverse,
            'statusText' => self::statusText($doc),
            'error' => $error?->getMessage(),
            'errorCode' => $error?->errorCode,
            'waiting' => $open !== null,
        ], $status, ['title' => $doc->label(), 'active' => 'documents', 'notice' => $notice]);
    }

    /** 403 for a purchase order shown to someone without purchasing.view (I85), else null. */
    private static function poRefusal(Context $ctx, Document $doc): ?HtmlResponse
    {
        if ($doc->docType === 'PO' && !$ctx->me()->can('purchasing.view')) {
            return $ctx->error(403, 'role_not_allowed', 'purchase orders (their prices and suppliers) are shown to people with access to Purchasing');
        }
        return null;
    }

    /**
     * Everything a document page or PDF shows, or null for an unknown id.
     *
     * @return array<string, mixed>|null
     */
    private function load(Context $ctx, int $id): ?array
    {
        $docs = $ctx->documents();
        $doc = $docs->find($id);
        if ($doc === null) {
            return null;
        }
        $db = $ctx->db;
        $type = $docs->typeInfo($doc->docType);
        $names = [];
        foreach ($db->all('SELECT id, display_name FROM staff_user WHERE id IN (?, ?, ?, ?)',
            [$doc->createdBy ?? 0, $doc->submittedBy ?? 0, $doc->postedBy ?? 0, $doc->cancelledBy ?? 0]) as $u) {
            $names[(int) $u['id']] = (string) $u['display_name'];
        }
        $name = static fn (?int $uid): ?string => $uid === null ? null : ($names[$uid] ?? '#' . $uid);
        $lines = $db->all(
            'SELECT l.line_no, l.sku_id, s.code AS sku_code, s.name AS sku_name, w.code AS warehouse, l.qty, l.unit_cost, l.amount, l.reason_code, l.description '
            . 'FROM document_line l LEFT JOIN sku s ON s.id = l.sku_id LEFT JOIN warehouse w ON w.id = l.warehouse_id WHERE l.document_id = ? ORDER BY l.line_no',
            [$id],
        );
        $tasks = $db->all(
            'SELECT t.id, t.kind, t.reason, t.units, t.state, t.opened_at, t.due_at, t.decided_at, t.decision_note, t.opened_by, t.decided_by, '
            . 'o.display_name AS opened_by_name, x.display_name AS decided_by_name FROM review_task t '
            . 'LEFT JOIN staff_user o ON o.id = t.opened_by LEFT JOIN staff_user x ON x.id = t.decided_by '
            . "WHERE t.subject_type = 'document' AND t.subject_id = ? ORDER BY t.id",
            [$id],
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($tasks as &$t) {
            $t['overdue'] = $t['state'] === 'open' && (string) $t['due_at'] < $now;
        }
        unset($t);
        return [
            'doc' => $doc,
            'type' => $type,
            'handler' => $docs->handler($doc->docType) !== null,
            'warehouse' => $doc->warehouseId === null ? null : $db->value('SELECT code FROM warehouse WHERE id = ?', [$doc->warehouseId]),
            'people' => ['created' => $name($doc->createdBy) ?? $doc->createdActor, 'submitted' => $name($doc->submittedBy),
                'posted' => $doc->postedBy === null ? $doc->postedActor : $name($doc->postedBy), 'cancelled' => $name($doc->cancelledBy)],
            'reverses' => $doc->reversesId === null ? null : $db->one('SELECT id, number FROM document WHERE id = ?', [$doc->reversesId]),
            // the live reversal (posted, or a request waiting for approval: I32); cancelled requests are in the list
            'reversedBy' => $db->one("SELECT id, number, status FROM document WHERE reverses_id = ? AND status <> 'cancelled'", [$id]),
            'lines' => $lines,
            'files' => $db->all(
                'SELECT f.id, f.original_name, f.mime, f.size_bytes, f.sha256, df.role, df.attached_at FROM document_file df JOIN stored_file f ON f.id = df.file_id '
                . 'WHERE df.document_id = ? ORDER BY df.attached_at, f.id',
                [$id],
            ),
            'tasks' => $tasks,
        ];
    }

    private static function statusText(Document $doc): string
    {
        return match ($doc->status) {
            'awaiting_approval' => 'awaiting approval',
            default => $doc->status,
        };
    }
}
