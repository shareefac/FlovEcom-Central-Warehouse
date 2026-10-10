<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Output\PdfWriter;
use CW\StockOps\StockOps;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

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
    /** Service error code => its words on this page (Words::RECORD). */
    private const ERRORS = ['not_reversible' => 'e_not_reversible', 'reversal_pending' => 'e_reversal_pending', 'not_reviewable' => 'e_done',
        'not_awaiting_approval' => 'e_done'];
    /** notice key => text (Ui\Words). Only these can be shown: a notice never comes from the URL as text. */
    public const NOTICES = Words::RECORD_NOTICE;

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
            'SELECT d.id, d.doc_type, d.number, d.status, d.review_state, d.doc_date, d.external_ref, d.created_at, d.posted_at, d.reverses_id, '
            . 'cu.display_name AS created_by_name, od.number AS cancels_number, s.name AS supplier_name FROM document d '
            . 'LEFT JOIN staff_user cu ON cu.id = d.created_by LEFT JOIN document od ON od.id = d.reverses_id '
            . 'LEFT JOIN purchase_order po ON po.document_id = COALESCE(d.reverses_id, d.id) LEFT JOIN supplier s ON s.id = po.supplier_id'
            . $sqlWhere . ' ORDER BY d.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params,
        );
        $names = array_column($types, 'name', 'code');
        $live = array_values(array_filter(array_column($types, 'code'), static fn (string $c): bool => $docs->handler($c) !== null));
        foreach ($rows as &$r) {
            // "What": a cancellation record says what it cancels (plan F408); an order names its supplier.
            $r['what'] = match (true) {
                $r['reverses_id'] !== null => Words::say('CHECKS', 'cancellation', (string) ($r['cancels_number'] ?? '#' . $r['reverses_id'])),
                $r['doc_type'] === 'PO' && $r['supplier_name'] !== null => Words::say('RECORDS', 'order_for', (string) $r['supplier_name']),
                default => Words::docType((string) $r['doc_type'], false, $names[$r['doc_type']] ?? null),
            };
        }
        unset($r);
        // The filter lists the kinds in use (and the one asked for); the note says which kinds are in use and which come later (F406).
        $kinds = array_values(array_filter($types, static fn (array $t): bool => in_array($t['code'], $live, true) || $f['type'] === $t['code']));
        $later = array_values(array_filter($types, static fn (array $t): bool => !in_array($t['code'], $live, true)));
        return $ctx->page('documents', [
            'rows' => $rows,
            'total' => $total,
            'filters' => $f,
            'kinds' => array_map(static fn (array $t): array => ['code' => $t['code'], 'name' => Words::docType((string) $t['code'], true, (string) $t['name'])], $kinds),
            'today' => Words::andList(array_map(static fn (string $c): string => mb_strtolower(Words::docType($c, true, $names[$c] ?? $c)), $live)),
            'later' => ucfirst(mb_strtolower(Words::andList(array_map(static fn (array $t): string => Words::docType((string) $t['code'], true, (string) $t['name']), $later)))),
            'poHidden' => !$ctx->me()->can('purchasing.view'),
            'statuses' => Document::STATUSES,
            'reviewStates' => Document::REVIEW_STATES,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ], 200, ['title' => Words::MENU['documents'], 'active' => 'documents']);
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
            return $this->page($ctx, $id, 400, new CwException('reason_required', Words::RECORD['reason_required'], 400));
        }
        try {
            $rev = $ctx->documents()->reverse($ctx->caller(), $id, $reason, $note);
        } catch (CwException $e) {
            if ($e->errorCode === 'role_not_allowed' || $e->errorCode === 'admin_cannot_post') {
                // The service says which documents the person cannot post (the API's words); the page says what it means here.
                $e = new CwException($e->errorCode, Words::RECORD['cancel_not_allowed'], $e->httpStatus);
            }
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
     * answered under its status. The words are Ui\Words' (plan §6.32): the decision first, with what each answer does;
     * the fingerprint in a "Technical details" fold; the checks as sentences in UK time.
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
        $rejectRecords = ($data['type']['reject_action'] ?? 'reverse') === 'record';
        $decide = self::decideBox($ctx, $doc, $data['tasks'], $data['type'], $data['value']);
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
        // Lines: a column nobody filled in is left out (plan F415).
        $cols = [];
        foreach (['warehouse', 'unit_cost', 'amount', 'reason_code', 'description'] as $c) {
            $cols[$c] = array_filter($data['lines'], static fn (array $l): bool => $l[$c] !== null && $l[$c] !== '') !== [];
        }
        // People by name; a set-up actor ("system:…") is never shown as a code (plan rule 10).
        $data['people'] = array_map(static fn (?string $p): ?string => $p !== null && str_starts_with($p, 'system:') ? Words::RECORD['by_cw'] : $p, $data['people']);
        $message = null;
        if ($error !== null) {
            // The service's refusals in the page's words, by code (plan F055); a code without words keeps the service's message.
            $message = match (true) {
                isset(self::ERRORS[$error->errorCode]) => Words::RECORD[self::ERRORS[$error->errorCode]],
                $error->errorCode === 'note_required' => Words::noteRequired($error->detail),
                isset(Words::REFUSAL[$error->errorCode]) && $error->errorCode !== 'role_not_allowed' => Words::REFUSAL[$error->errorCode],
                default => Words::error($error->errorCode, $error->getMessage()),
            };
        }
        return $ctx->page('document', $data + [
            'title' => Words::docTitle($doc->docType, $doc->number, null, null, (string) $data['type']['name']),
            'kindName' => Words::docType($doc->docType, false, (string) $data['type']['name']),
            'kindMany' => mb_strtolower(Words::docType($doc->docType, true, (string) $data['type']['name'])),
            'rejectRecords' => $rejectRecords,
            'poHref' => $doc->docType === 'PO' && $data['handler'] && $me->can('purchasing.view') ? '/ui/purchasing/orders/' . ($doc->reversesId ?? $doc->id) : null,
            // A goods receipt's own page is in Receiving (IM6, I141): its lines, bench findings, incidents and files.
            'grnHref' => $doc->docType === 'GRN' && $data['handler'] && $me->can('receiving.view') ? '/ui/receiving/' . ($doc->reversesId ?? $doc->id) : null,
            // A stock record's own page is in Stock (pack A1): its places, its "given to", its prices, the balance owed.
            'stockHref' => ($k = StockOps::kindOf($doc->docType)) !== null && $data['handler'] ? StockOps::PATHS[$k] . '/' . $doc->id : null,
            'decide' => $decide,
            'reverse' => $reverse,
            'cols' => $cols,
            'history' => self::history($data['tasks']),
            'error' => $message,
            'errorCode' => $error?->errorCode,
            'waiting' => $open !== null,
        ], $status, ['title' => Words::docTitle($doc->docType, $doc->number, null, null, (string) $data['type']['name']), 'active' => 'documents', 'notice' => $notice]);
    }

    /**
     * The decide box of a record's open task for this person (the forms of a reviewer, or why they may not decide it), or null when
     * no task is open or the person decides none: the generic record page and the stock records' pages (pack A1) draw it with the
     * `decide_box` partial. $value: the record's value in pounds when it has one (an order), else its task's units are its size.
     *
     * @param list<array<string, mixed>> $tasks tasks() rows
     * @param array<string, mixed> $type the document_type row
     * @return array<string, mixed>|null
     */
    public static function decideBox(Context $ctx, Document $doc, array $tasks, array $type, ?string $value): ?array
    {
        $me = $ctx->me();
        $open = null;
        foreach ($tasks as $t) {
            if ($t['state'] === 'open') {
                $open = $t;
            }
        }
        if ($open === null || !($me->can('documents.review') || $me->can('documents.approve'))) {
            return null;
        }
        $rejectRecords = ($type['reject_action'] ?? 'reverse') === 'record';
        $kind = mb_strtolower(Words::docType($doc->docType, false, (string) $type['name']));
        $size = $value !== null ? Html::money($value) : Words::say('CHECKS', 'items', (int) ($open['units'] ?? 0));
        // refusalFor: also the people a type names as having written part of it (a receipt's bench check, I133).
        $no = $ctx->documents()->refusalFor($me->id, $me->roles, $doc, (string) $open['kind']);
        $approval = $open['kind'] === 'approval';
        return [
            'task' => $open,
            'refusal' => Words::refusal($no),
            'text' => Words::say('RECORD', $approval ? 'approval_text' : 'review_text', $kind, $size, Html::day((string) $open['due_at'])),
            'ok' => Words::RECORD[$approval ? 'ok_approval' : 'ok_review'],
            // What each answer does (design B), and which is the safer one: refusing a request books nothing.
            'okDoes' => Words::RECORD[match (true) {
                $approval && $doc->isReversal() => 'does_ok_cancel',
                $approval => 'does_ok_approval',
                default => 'does_ok_review',
            }],
            'notOkDoes' => Words::RECORD[match (true) {
                $approval => 'does_not_ok_approval',
                $doc->isReversal() => 'does_not_ok_cancel',
                $rejectRecords => 'does_not_ok_record',
                default => 'does_not_ok_review',
            }],
            'safer' => $approval,
        ];
    }

    /**
     * A record's review tasks, oldest first, with the names of who opened and who decided each, and `overdue`.
     *
     * @return list<array<string, mixed>>
     */
    public static function tasks(\CW\Db $db, int $documentId): array
    {
        $tasks = $db->all(
            'SELECT t.id, t.kind, t.reason, t.units, t.state, t.opened_at, t.due_at, t.decided_at, t.decision_note, t.opened_by, t.decided_by, '
            . 'o.display_name AS opened_by_name, x.display_name AS decided_by_name FROM review_task t '
            . 'LEFT JOIN staff_user o ON o.id = t.opened_by LEFT JOIN staff_user x ON x.id = t.decided_by '
            . "WHERE t.subject_type = 'document' AND t.subject_id = ? ORDER BY t.id",
            [$documentId],
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($tasks as &$t) {
            $t['overdue'] = $t['state'] === 'open' && (string) $t['due_at'] < $now;
        }
        unset($t);
        return $tasks;
    }

    /**
     * The checks of a record as sentences (plan F416): "7 Oct 2026: Reviewer check asked for by Buyer 1." then what came of it
     * ("OK by Sam on 8 Oct 2026: note", "Closed: the record was cancelled (PO-000004).").
     *
     * @param list<array<string, mixed>> $tasks
     * @return list<array{asked: string, reason: string, outcome: string, tone: string, note: ?string}>
     */
    public static function history(array $tasks): array
    {
        $out = [];
        foreach ($tasks as $t) {
            $state = (string) $t['state'];
            $note = $t['decision_note'] === null || $t['decision_note'] === '' ? null : (string) $t['decision_note'];
            $outcome = match (true) {
                $state === 'open' => Words::say('RECORD', 'open_until', Html::day((string) $t['due_at'])),
                $state === 'withdrawn' && $note !== null && preg_match('/^reversed by (\S+)$/', $note, $m) === 1 => Words::say('RECORD', 'closed_cancelled', $m[1]),
                $state === 'withdrawn' && $note !== null && str_contains($note, 'withdrawn by the requester') => Words::RECORD['closed_withdrawn'],
                $state === 'withdrawn' => Words::say('RECORD', 'closed', Html::day((string) $t['decided_at'])),
                default => Words::say('RECORD', 'decided', Words::of('TASK_STATE', $state), (string) ($t['decided_by_name'] ?? ''), Html::day((string) $t['decided_at'])),
            };
            $out[] = [
                'asked' => Words::say('RECORD', 'asked_line', Html::day((string) $t['opened_at']), Words::RECORD[$t['kind'] === 'approval' ? 'kind_approval' : 'kind_review'],
                    (string) ($t['opened_by_name'] ?? '')),
                'reason' => Words::say('RECORD', 'why_line', Words::of('CHECK_REASON', (string) $t['reason'])),
                'outcome' => $outcome,
                'tone' => Words::tone('TASK_STATE', $state),
                // A withdrawal's note is the system's own words: the outcome already says it.
                'note' => $state === 'withdrawn' || $state === 'open' ? null : $note,
            ];
        }
        return $out;
    }

    /** 403 for a purchase order shown to someone without purchasing.view (I85), else null. */
    private static function poRefusal(Context $ctx, Document $doc): ?HtmlResponse
    {
        if ($doc->docType === 'PO' && !$ctx->me()->can('purchasing.view')) {
            return $ctx->error(403, 'role_not_allowed', Words::RECORDS['po_hidden']);
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
            'SELECT l.line_no, l.sku_id, s.code AS sku_code, s.name AS sku_name, w.code AS warehouse, w.name AS warehouse_name, l.qty, l.unit_cost, l.amount, l.reason_code, l.description, '
            . 'rc.label AS reason_label FROM document_line l LEFT JOIN sku s ON s.id = l.sku_id LEFT JOIN warehouse w ON w.id = l.warehouse_id '
            . 'LEFT JOIN reason_code rc ON rc.code = l.reason_code WHERE l.document_id = ? ORDER BY l.line_no',
            [$id],
        );
        $tasks = self::tasks($db, $id);
        $po = $doc->docType !== 'PO' ? null : $db->one('SELECT po.net_total, s.name AS supplier_name FROM purchase_order po JOIN supplier s ON s.id = po.supplier_id '
            . 'WHERE po.document_id = ?', [$doc->reversesId ?? $doc->id]);
        return [
            'doc' => $doc,
            'type' => $type,
            'handler' => $docs->handler($doc->docType) !== null,
            'warehouse' => $doc->warehouseId === null ? null : $db->value('SELECT code FROM warehouse WHERE id = ?', [$doc->warehouseId]),
            'warehouseName' => $doc->warehouseId === null ? null : $db->value('SELECT name FROM warehouse WHERE id = ?', [$doc->warehouseId]),
            'people' => ['created' => $name($doc->createdBy) ?? $doc->createdActor, 'submitted' => $name($doc->submittedBy),
                'posted' => $doc->postedBy === null ? $doc->postedActor : $name($doc->postedBy), 'cancelled' => $name($doc->cancelledBy)],
            'reverses' => $doc->reversesId === null ? null : $db->one('SELECT id, number FROM document WHERE id = ?', [$doc->reversesId]),
            // An order (or its cancellation record, which has no purchase_order row) is shown with its supplier and net value.
            'value' => $po === null ? null : (string) $po['net_total'],
            'supplier' => $po === null ? null : (string) $po['supplier_name'],
            'reasonLabel' => $doc->reasonCode === null ? null : ($db->value('SELECT label FROM reason_code WHERE code = ?', [$doc->reasonCode]) ?? $doc->reasonCode),
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
