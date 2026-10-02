<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\OpResult;
use CW\Output\CsvWriter;
use CW\Suppliers\Suppliers;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;

/**
 * The supplier screens (IM4, Phase I-2; docs/decisions.md I38-I47): the list (filters, CSV), the card (details, due
 * diligence, import route, the activation box, open tasks and their history, the items summary), the new / edit form,
 * the activation request, its withdrawal, the second person's approve / reject, deactivation and the evidence upload.
 *
 * Everyone with suppliers.view looks; buyers (suppliers.manage) change; a reviewer (suppliers.approve) decides a supplier
 * task only when CW\Suppliers\Suppliers::refusal() says nothing (never admin, never the person who created, asked for or
 * last changed the supplier): otherwise the card shows why, never a form. Every change goes through CW\Suppliers\Suppliers,
 * which checks the person again; a refusal is shown on the same page under its status. Creating carries a FormOnce key
 * (one supplier however often the form is sent); every other form carries the version it was drawn with (409: the page is
 * redrawn with the current data).
 */
final class SuppliersController
{
    public const NOTICES = [
        'created' => 'Supplier created as a draft. Add the due-diligence check, then ask for activation: a second person approves it.',
        'saved' => 'Saved.',
        'saved_review' => 'Saved. The supplier is active, so the change waits for a second person\'s review (it does not block orders).',
        'saved_route' => 'Saved. The import route changed: purchase orders for this supplier are refused until a second person approves the route.',
        'unchanged' => 'Nothing changed.',
        'requested' => 'Activation requested: a second person (a reviewer) approves it before the supplier can be used.',
        'withdrawn' => 'Activation request withdrawn.',
        'approved' => 'Approved.',
        'rejected' => 'Rejected: the note is recorded on the supplier.',
        'deactivated' => 'Supplier deactivated: no new purchase order can be approved for it.',
        'evidence' => 'Evidence file stored and recorded on the supplier.',
    ];
    public const STATUS_LABELS = ['draft' => 'draft', 'pending_approval' => 'waiting for approval', 'active' => 'active', 'inactive' => 'inactive'];
    /** "Checks due": the next due-diligence review within this many days. */
    public const DUE_WINDOW_DAYS = 30;
    public const LIST_LIMIT = 500;

    public function index(Context $ctx): HtmlResponse
    {
        $f = self::filters($ctx->req);
        $rows = $this->rows($ctx, $f, self::LIST_LIMIT);
        $today = $ctx->suppliers()->today();
        foreach ($rows as &$r) {
            $r['status_label'] = self::STATUS_LABELS[$r['status']] ?? $r['status'];
            $r['dd_overdue'] = $r['dd_next_review_on'] !== null && (string) $r['dd_next_review_on'] < $today;
        }
        unset($r);
        return $ctx->page('suppliers', [
            'rows' => $rows,
            'filters' => $f,
            'statuses' => self::STATUS_LABELS,
            'canManage' => $ctx->me()->can('suppliers.manage'),
            'limit' => self::LIST_LIMIT,
        ], 200, ['title' => 'Suppliers', 'active' => 'suppliers', 'notice' => null]);
    }

    public function csv(Context $ctx): HtmlResponse
    {
        $csv = new CsvWriter([['code', 'text'], ['name', 'text'], ['legal_name', 'text'], ['status', 'text'], ['company_number', 'text'], ['vat_number', 'text'],
            ['address_line1', 'text'], ['address_line2', 'text'], ['city', 'text'], ['postcode', 'text'], ['country', 'text'], ['contact_name', 'text'],
            ['email', 'text'], ['phone', 'text'], ['payment_terms', 'text'], ['payment_terms_days', 'number'], ['default_lead_days', 'number'],
            ['review_days', 'number'], ['min_order_value', 'number'], ['default_vat_code', 'text'], ['overseas', 'text'], ['import_route_approved', 'text'],
            ['dd_checked_on', 'text'], ['dd_next_review_on', 'text'], ['approved_at', 'text'], ['erp_name', 'text'], ['items', 'number']]);
        foreach ($this->rows($ctx, self::filters($ctx->req), null) as $r) {
            $csv->add([$r['code'], $r['name'], $r['legal_name'], $r['status'], $r['company_number'], $r['vat_number'], $r['address_line1'], $r['address_line2'],
                $r['city'], $r['postcode'], $r['country'], $r['contact_name'], $r['email'], $r['phone'], $r['payment_terms'], $r['payment_terms_days'],
                $r['default_lead_days'], $r['review_days'], $r['min_order_value'], $r['default_vat_code'], (int) $r['is_overseas'] === 1,
                $r['import_route_approved_at'] !== null, $r['dd_checked_on'], $r['dd_next_review_on'], $r['approved_at'], $r['erp_name'], (int) $r['items']]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'suppliers.csv');
    }

    public function newForm(Context $ctx): HtmlResponse
    {
        return $this->form($ctx, null, ['country' => 'GB', 'default_vat_code' => 'S', 'is_overseas' => 0], FormOnce::newKey(), 200, null);
    }

    public function create(Context $ctx): HtmlResponse
    {
        $fields = self::posted($ctx->req);
        try {
            $r = FormOnce::run($ctx, 'ui.supplier.create', $fields, static function (Db $db) use ($ctx, $fields): OpResult {
                $s = $ctx->suppliers()->create($ctx->caller(), $fields);
                return OpResult::of(303, ['result' => 'created', 'supplier_id' => (int) $s['id'],
                    'redirect' => Html::url('/ui/purchasing/suppliers/' . (int) $s['id'], ['notice' => 'created'])]);
            });
        } catch (CwException $e) {
            return $this->form($ctx, null, $fields, $ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey(), $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    public function show(Context $ctx): HtmlResponse
    {
        return $this->card($ctx, $ctx->id(), 200, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function editForm(Context $ctx): HtmlResponse
    {
        $s = $ctx->suppliers()->find($ctx->id());
        if ($s === null) {
            return $ctx->error(404, 'unknown_supplier', 'there is no such supplier');
        }
        if ($s['status'] === 'pending_approval') {
            return $this->card($ctx, (int) $s['id'], 409, new CwException('supplier_pending',
                "{$s['code']} is waiting for approval: withdraw the activation request first to change it", 409));
        }
        return $this->form($ctx, $s, $s, null, 200, null);
    }

    public function update(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $fields = self::posted($ctx->req);
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        $svc = $ctx->suppliers();
        try {
            $before = $svc->get($id);
            $after = $svc->update($ctx->caller(), $id, $version, $fields);
        } catch (CwException $e) {
            $s = $svc->find($id);
            if ($s === null) {
                return $ctx->error(404, 'unknown_supplier', 'there is no such supplier');
            }
            if ($e->errorCode === 'version_conflict') {
                // Redrawn with the current data: what changed meanwhile must be seen before it is overwritten.
                return $this->form($ctx, $s, $s, null, 409, new CwException('version_conflict',
                    'This supplier was changed since you opened the form: here is the current data. Make your change again.', 409));
            }
            if ($e->errorCode === 'supplier_pending') {
                return $this->card($ctx, $id, 409, $e);
            }
            return $this->form($ctx, $s, $fields + $s, null, $e->httpStatus, $e, $version);
        }
        $notice = 'unchanged';
        if ((int) $after['version'] !== (int) $before['version']) {
            $notice = 'saved';
            $opened = $ctx->db->column("SELECT reason FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open' AND opened_at >= ?",
                [$id, $after['updated_at']]);
            if (in_array('import_route', $opened, true)) {
                $notice = 'saved_route';
            } elseif (in_array('supplier_changed', $opened, true)) {
                $notice = 'saved_review';
            }
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/suppliers/' . $id, ['notice' => $notice]));
    }

    public function requestActivation(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $version = UiRequest::id($ctx->req->field('version'));
        try {
            $ctx->suppliers()->requestActivation($ctx->caller(), $id, $version ?? 0);
        } catch (CwException $e) {
            return $this->card($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/suppliers/' . $id, ['notice' => 'requested']));
    }

    public function deactivate(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $version = UiRequest::id($ctx->req->field('version'));
        try {
            $ctx->suppliers()->deactivate($ctx->caller(), $id, $version ?? 0, $ctx->req->field('reason') ?? '');
        } catch (CwException $e) {
            return $this->card($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/suppliers/' . $id, ['notice' => 'deactivated']));
    }

    /**
     * Stores an evidence file (multipart `file`, `kind` dd | import_route) in the file store (kind supplier_check) and
     * records it on the supplier. At most 2 MiB (413); 503 when the file store is not set up on this server.
     */
    public function evidence(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $s = $ctx->suppliers()->find($id);
        if ($s === null) {
            return $ctx->error(404, 'unknown_supplier', 'there is no such supplier');
        }
        $version = UiRequest::id($ctx->req->field('version'));
        $kind = $ctx->req->field('kind') ?? '';
        try {
            if (!in_array($kind, ['dd', 'import_route'], true)) {
                throw new CwException('bad_field', 'choose what the file is evidence of (due diligence or the import route)', 400, ['field' => 'kind']);
            }
            $file = $ctx->req->file('file');
            if ($file === null) {
                throw new CwException('no_file', 'choose a file to upload', 400, ['field' => 'file']);
            }
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > UiRequest::MAX_UPLOAD_BYTES) {
                throw new CwException('too_large', 'the file is larger than ' . intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576) . ' MiB: nothing was saved', 413);
            }
            if ($file['error'] !== UPLOAD_ERR_OK || $file['path'] === '') {
                throw new CwException('upload_failed', 'the file did not arrive completely: try again', 400);
            }
            if ($s['status'] === 'pending_approval') {
                throw new CwException('supplier_pending', "{$s['code']} is waiting for approval: withdraw the activation request first to change it", 409);
            }
            $stored = $ctx->files()->store($ctx->caller(), $file['path'], $file['name'], 'supplier_check',
                "supplier {$s['code']}: " . ($kind === 'dd' ? 'due diligence' : 'import route') . ' evidence');
            $ctx->suppliers()->setEvidence($ctx->caller(), $id, $version ?? 0, $kind, $stored['id']);
        } catch (CwException $e) {
            return $this->card($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/suppliers/' . $id, ['notice' => 'evidence']));
    }

    public function approve(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, 'approve');
    }

    public function reject(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, 'reject');
    }

    public function withdraw(Context $ctx): HtmlResponse
    {
        return $this->decide($ctx, 'withdraw');
    }

    private function decide(Context $ctx, string $what): HtmlResponse
    {
        $taskId = $ctx->id();
        $sid = $ctx->db->value("SELECT subject_id FROM review_task WHERE id = ? AND subject_type = 'supplier'", [$taskId]);
        if ($sid === null) {
            return $ctx->error(404, 'unknown_task', 'there is no such supplier task');
        }
        $sid = (int) $sid;
        $note = $ctx->req->field('note');
        try {
            match ($what) {
                'approve' => $ctx->suppliers()->approve($ctx->caller(), $taskId, $note),
                'reject' => $ctx->suppliers()->reject($ctx->caller(), $taskId, $note ?? ''),
                default => $ctx->suppliers()->withdraw($ctx->caller(), $taskId),
            };
        } catch (CwException $e) {
            return $this->card($ctx, $sid, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/suppliers/' . $sid,
            ['notice' => ['approve' => 'approved', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][$what]]));
    }

    // ------------------------------------------------------------------------------------------

    /** The card, also after a refused form (the error shown, answered under its status). */
    public function card(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice = null): HtmlResponse
    {
        $svc = $ctx->suppliers();
        $s = $svc->find($id);
        if ($s === null) {
            return $ctx->error(404, 'unknown_supplier', 'there is no such supplier');
        }
        $me = $ctx->me();
        $db = $ctx->db;
        $today = $svc->today();
        $ids = array_filter([$s['created_by'], $s['updated_by'], $s['details_changed_by'], $s['approved_by'], $s['dd_checked_by'],
            $s['import_route_approved_by'], $s['deactivated_by']], static fn (mixed $v): bool => $v !== null);
        $names = [];
        if ($ids !== []) {
            foreach ($db->all('SELECT id, display_name FROM staff_user WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
                array_values(array_map('intval', $ids))) as $u) {
                $names[(int) $u['id']] = (string) $u['display_name'];
            }
        }
        $name = static fn (mixed $uid): ?string => $uid === null ? null : ($names[(int) $uid] ?? '#' . $uid);
        $tasks = $svc->tasks($id);
        $now = gmdate('Y-m-d H:i:s');
        $open = ['activation' => null, 'route' => null, 'review' => null];
        foreach ($tasks as &$t) {
            $t['overdue'] = $t['state'] === 'open' && (string) $t['due_at'] < $now;
            if ($t['state'] !== 'open') {
                continue;
            }
            $slot = match (true) {
                $t['kind'] === 'review' => 'review',
                $t['reason'] === 'import_route' => 'route',
                default => 'activation',
            };
            $no = Suppliers::refusal($me->id, $me->roles, $s, $t);
            $open[$slot] = $t + [
                'refusal' => $no['message'] ?? null,
                'may_decide' => $no === null,
                'may_withdraw' => $slot === 'activation' && (int) $t['opened_by'] === $me->id && $me->can('suppliers.manage'),
            ];
        }
        unset($t);
        $files = [];
        foreach (['dd' => $s['dd_evidence_file_id'], 'import_route' => $s['import_route_file_id']] as $k => $fid) {
            $files[$k] = $fid === null ? null : $db->one('SELECT id, original_name, mime, size_bytes, sha256, created_at FROM stored_file WHERE id = ?', [(int) $fid]);
        }
        $items = $db->one('SELECT COUNT(*) AS n, COALESCE(SUM(is_active), 0) AS active, COALESCE(SUM(is_preferred = 1 AND is_active = 1), 0) AS preferred, '
            . 'COALESCE(SUM(last_pack_price IS NULL AND is_active = 1), 0) AS no_price FROM supplier_item WHERE supplier_id = ?', [$id]) ?? [];
        $canManage = $me->can('suppliers.manage');
        $missing = Suppliers::missing($s);
        // The supplier's last 10 purchase orders (the pos task, I53): cancellations are shown on their order.
        $recentPos = $me->can('purchasing.view') ? $db->all(
            'SELECT d.id, d.number, d.status, d.doc_date, d.review_state, p.state, p.net_total, p.expected_date FROM purchase_order p JOIN document d ON d.id = p.document_id '
            . 'WHERE p.supplier_id = ? ORDER BY d.id DESC LIMIT 10',
            [$id],
        ) : null;
        return $ctx->page('supplier', [
            's' => $s,
            'statusLabel' => self::STATUS_LABELS[$s['status']] ?? $s['status'],
            'people' => [
                'created' => $name($s['created_by']) ?? $s['created_actor'], 'updated' => $name($s['updated_by']) ?? $s['updated_actor'],
                'changed' => $name($s['details_changed_by']), 'approved' => $name($s['approved_by']), 'dd' => $name($s['dd_checked_by']),
                'route' => $name($s['import_route_approved_by']), 'deactivated' => $name($s['deactivated_by']),
            ],
            'ddOverdue' => $s['dd_next_review_on'] !== null && (string) $s['dd_next_review_on'] < $today,
            'routeUnapproved' => (int) $s['is_overseas'] === 1 && $s['import_route_approved_at'] === null,
            'missing' => $missing,
            'missingText' => Suppliers::labels($missing),
            'open' => $open,
            'tasks' => $tasks,
            'files' => $files,
            'items' => ['n' => (int) ($items['n'] ?? 0), 'active' => (int) ($items['active'] ?? 0), 'preferred' => (int) ($items['preferred'] ?? 0),
                'no_price' => (int) ($items['no_price'] ?? 0)],
            'canManage' => $canManage,
            'recentPos' => $recentPos,
            'canOrder' => \CW\Documents\Documents::mayPost($me->roles, 'PO') && $s['status'] !== 'inactive',
            'canRequest' => $canManage && in_array($s['status'], ['draft', 'inactive'], true),
            'canEdit' => $canManage && $s['status'] !== 'pending_approval',
            'canDeactivate' => $canManage && $s['status'] === 'active',
            'error' => $error?->getMessage(),
            'errorCode' => $error?->errorCode,
            'errorMissing' => $error !== null && is_array($error->detail['missing'] ?? null) ? Suppliers::labels($error->detail['missing']) : null,
        ], $status, ['title' => (string) $s['code'], 'active' => 'suppliers', 'notice' => $notice]);
    }

    /**
     * The new / edit form. $s: the supplier being edited (null: new); $values: what the fields show; $formKey for a new
     * supplier (FormOnce); $version: the version the form carries (default the row's).
     *
     * @param array<string, mixed>|null $s
     * @param array<string, mixed> $values
     */
    private function form(Context $ctx, ?array $s, array $values, ?string $formKey, int $status, ?CwException $error, ?int $version = null): HtmlResponse
    {
        $v = [];
        foreach (array_keys(Suppliers::FIELDS) as $k) {
            $v[$k] = isset($values[$k]) ? (string) $values[$k] : '';
        }
        return $ctx->page('supplier_form', [
            's' => $s,
            'action' => $s === null ? '/ui/purchasing/suppliers' : '/ui/purchasing/suppliers/' . (int) $s['id'],
            'v' => $v,
            'formKey' => $formKey,
            'version' => $version ?? ($s === null ? null : (int) $s['version']),
            'vatCodes' => $ctx->db->all('SELECT code, label FROM vat_code WHERE is_active = 1 ORDER BY sort_order, code'),
            'staff' => $ctx->db->all('SELECT id, display_name FROM staff_user WHERE is_active = 1 ORDER BY display_name, id'),
            'meId' => $ctx->me()->id,
            'error' => $error?->getMessage(),
            'errorField' => is_string($error?->detail['field'] ?? null) ? $error->detail['field'] : null,
            'today' => $ctx->suppliers()->today(),
        ], $status, ['title' => $s === null ? 'New supplier' : 'Edit ' . $s['code'], 'active' => 'suppliers']);
    }

    /**
     * The supplier fields of a posted form: every field of Suppliers::FIELDS the form carries (the overseas checkbox is
     * absent when unticked: 0).
     *
     * @return array<string, string>
     */
    private static function posted(UiRequest $req): array
    {
        $out = [];
        foreach (array_keys(Suppliers::FIELDS) as $k) {
            if ($k === 'is_overseas') {
                $out[$k] = $req->field($k) === '1' ? '1' : '0';
                continue;
            }
            $v = $req->field($k);
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @return array{status: ?string, q: string, due: bool} */
    private static function filters(UiRequest $req): array
    {
        return [
            'status' => in_array($req->param('status'), Suppliers::STATUSES, true) ? $req->param('status') : null,
            'q' => mb_substr(trim($req->param('q') ?? ''), 0, 100),
            'due' => $req->param('due') === '1',
        ];
    }

    /**
     * @param array{status: ?string, q: string, due: bool} $f
     * @return list<array<string, mixed>>
     */
    private function rows(Context $ctx, array $f, ?int $limit): array
    {
        $where = [];
        $params = [];
        if ($f['status'] !== null) {
            $where[] = 's.status = ?';
            $params[] = $f['status'];
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(s.code LIKE ? OR s.name LIKE ? OR s.legal_name LIKE ? OR s.vat_number LIKE ? OR s.erp_name LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($f['due']) {
            $where[] = 's.dd_next_review_on <= ?';
            $params[] = (new \DateTimeImmutable($ctx->suppliers()->today()))->modify('+' . self::DUE_WINDOW_DAYS . ' days')->format('Y-m-d');
        }
        return $ctx->db->all(
            'SELECT s.*, a.display_name AS approved_by_name, (SELECT COUNT(*) FROM supplier_item i WHERE i.supplier_id = s.id AND i.is_active = 1) AS items '
            . 'FROM supplier s LEFT JOIN staff_user a ON a.id = s.approved_by' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY s.name, s.id' . ($limit === null ? '' : ' LIMIT ' . $limit),
            $params,
        );
    }
}
