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
use CW\Ui\Words;

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
    /** The notices named in a redirect (their words: Words::SUPPLIER_NOTICE). */
    public const NOTICES = Words::SUPPLIER_NOTICE;
    /** The status filter (the URL values stay; their words: Words::SUPPLIER_STATUS). */
    public const STATUS_LABELS = Words::SUPPLIER_STATUS;
    /** "Checks due": the next due-diligence review within this many days. */
    public const DUE_WINDOW_DAYS = 30;
    public const LIST_LIMIT = 500;

    public function index(Context $ctx): HtmlResponse
    {
        $f = self::filters($ctx->req);
        $rows = $this->rows($ctx, $f, self::LIST_LIMIT);
        $today = $ctx->suppliers()->today();
        foreach ($rows as &$r) {
            $r['dd_overdue'] = $r['dd_next_review_on'] !== null && (string) $r['dd_next_review_on'] < $today;
        }
        unset($r);
        $canManage = $ctx->me()->can('suppliers.manage');
        return $ctx->page('suppliers', [
            'rows' => $rows,
            'filters' => $f,
            'filtered' => $f['status'] !== null || $f['q'] !== '' || $f['due'],
            'statuses' => self::STATUS_LABELS,
            'canManage' => $canManage,
            'lookOnly' => $canManage ? null : Words::whoCan('suppliers.manage'),
            'dueDays' => self::DUE_WINDOW_DAYS,
            'limit' => self::LIST_LIMIT,
        ], 200, ['title' => Words::MENU['suppliers'], 'active' => 'suppliers', 'notice' => null]);
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
            return self::notFound($ctx);
        }
        if ($s['status'] === 'pending_approval') {
            return $this->card($ctx, (int) $s['id'], 409, new CwException('supplier_pending', Words::BUY_ERROR['supplier_pending'], 409));
        }
        return $this->form($ctx, $s, $s, null, 200, null);
    }

    public function update(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $fields = self::posted($ctx->req);
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', Words::ERROR['bad_version']);
        }
        $svc = $ctx->suppliers();
        try {
            $before = $svc->get($id);
            $after = $svc->update($ctx->caller(), $id, $version, $fields);
        } catch (CwException $e) {
            $s = $svc->find($id);
            if ($s === null) {
                return self::notFound($ctx);
            }
            if ($e->errorCode === 'version_conflict') {
                // Behaviour item 9 of plan §8.6 (F382, provisional): what the person typed stays, the form now carries the current
                // version (so Save works again), and what someone else changed meanwhile is listed and marked at its field: it must be
                // seen before it is overwritten. Nothing was saved.
                // A field only they changed shows their value; a field the person changed too keeps what was typed (both are marked).
                $theirs = self::changedSince($ctx, $id, $version, $s);
                $values = $fields + $s;
                foreach ($theirs as $field => $t) {
                    if (array_key_exists($field, $fields) && self::same($fields[$field], $s[$field] ?? null)) {
                        unset($theirs[$field]); // typed the same as is saved now: nothing to check
                        continue;
                    }
                    $theirs[$field]['kept'] = array_key_exists($field, $fields) && !self::same($fields[$field], $t['was']);
                    if (!$theirs[$field]['kept']) {
                        $values[$field] = $s[$field];
                    }
                }
                $who = $s['updated_by'] === null ? null : $ctx->db->value('SELECT display_name FROM staff_user WHERE id = ?', [(int) $s['updated_by']]);
                $text = Words::say('SUPPLIER_FORM', 'changed_meanwhile', is_string($who) && $who !== '' ? $who : Words::SUPPLIER_FORM['someone'],
                    Html::when((string) $s['updated_at']));
                return $this->form($ctx, $s, $values, null, 409, new CwException('version_conflict', $text, 409), (int) $s['version'], $theirs);
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
            return self::notFound($ctx);
        }
        $version = UiRequest::id($ctx->req->field('version'));
        $kind = $ctx->req->field('kind') ?? '';
        try {
            if (!in_array($kind, ['dd', 'import_route'], true)) {
                throw new CwException('bad_kind', Words::BUY_ERROR['choose_kind'], 400, ['field' => 'kind']);
            }
            $file = $ctx->req->file('file');
            if ($file === null) {
                throw new CwException('no_file', Words::BUY_ERROR['no_file_upload'], 400, ['field' => 'file']);
            }
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > UiRequest::MAX_UPLOAD_BYTES) {
                throw new CwException('too_large', Words::say('BUY_ERROR', 'too_large', intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576)), 413);
            }
            if ($file['error'] !== UPLOAD_ERR_OK || $file['path'] === '') {
                throw new CwException('upload_failed', Words::BUY_ERROR['upload_failed'], 400);
            }
            if ($s['status'] === 'pending_approval') {
                throw new CwException('supplier_pending', Words::BUY_ERROR['supplier_pending'], 409);
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
            return $ctx->error(404, 'unknown_task', Words::ERROR['unknown_task']);
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
            return self::notFound($ctx);
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
        $name = static fn (mixed $uid): ?string => $uid === null ? null : ($names[(int) $uid] ?? Words::ANOMALIES['set_up']);
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
            $who = (string) ($t['opened_by_name'] ?? Words::ANOMALIES['set_up']);
            $on = Html::when((string) $t['opened_at']);
            $notAbroad = $slot === 'route' && (int) $s['is_overseas'] !== 1;
            $open[$slot] = $t + [
                'title' => match (true) {
                    $slot === 'review' => Words::SUPPLIER['task_review'],
                    $slot === 'route' => Words::SUPPLIER['task_route'],
                    $t['reason'] === 'reactivation' => Words::SUPPLIER['task_reactivation'],
                    default => Words::SUPPLIER['task_activation'],
                },
                'text' => match (true) {
                    $notAbroad => Words::say('SUPPLIER', 'route_not_abroad', $who, $on),
                    $slot === 'route' => Words::say('SUPPLIER', 'route_changed', $who, $on),
                    $slot === 'review' => Words::say('SUPPLIER', 'review_changed', $who, $on),
                    default => Words::say('SUPPLIER', 'asked_on', $who, $on),
                },
                'check_by' => Words::say('SUPPLIER', 'check_by', Html::day((string) $t['due_at'])),
                'ok' => Words::SUPPLIER[match ($slot) { 'review' => 'ok_review', 'route' => 'ok_route', default => 'ok_activation' }],
                'ok_does' => Words::SUPPLIER[match ($slot) { 'review' => 'does_ok_review', 'route' => 'does_ok_route', default => 'does_ok_activation' }],
                'not_ok_does' => Words::SUPPLIER[match (true) {
                    $slot === 'review' || $notAbroad => 'does_not_ok',
                    $slot === 'route' => 'does_not_ok_route',
                    $t['reason'] === 'reactivation' => 'does_not_ok_reactivation',
                    default => 'does_not_ok_activation',
                }],
                // A buyer is told the reviewer decides (F364); a reviewer, why it is not theirs (F367).
                'refusal' => $no === null ? null : (($no['code'] ?? '') === 'role_not_allowed' ? Words::SUPPLIER['look_note'] : Words::refusal($no)),
                'may_decide' => $no === null,
                'may_withdraw' => $slot === 'activation' && (int) $t['opened_by'] === $me->id && $me->can('suppliers.manage'),
            ];
        }
        unset($t);
        $files = [];
        foreach (['dd' => $s['dd_evidence_file_id'], 'import_route' => $s['import_route_file_id']] as $k => $fid) {
            $f = $fid === null ? null : $db->one('SELECT id, original_name, mime, size_bytes, sha256, created_at FROM stored_file WHERE id = ?', [(int) $fid]);
            $files[$k] = $f === null ? null : $f + ['size' => Html::size((int) $f['size_bytes'])];
        }
        $items = $db->one('SELECT COUNT(*) AS n, COALESCE(SUM(is_active), 0) AS active, COALESCE(SUM(is_preferred = 1 AND is_active = 1), 0) AS preferred, '
            . 'COALESCE(SUM(last_pack_price IS NULL AND is_active = 1), 0) AS no_price FROM supplier_item WHERE supplier_id = ?', [$id]) ?? [];
        $canManage = $me->can('suppliers.manage');
        $missing = Suppliers::missing($s);
        $canPost = \CW\Documents\Documents::mayPost($me->roles, 'PO');
        // The supplier's last 10 purchase orders (the pos task, I53): cancellations are shown on their order.
        $recentPos = $me->can('purchasing.view') ? array_map(static fn (array $p): array => PurchaseOrdersController::screenRow($p + ['reverses_id' => null,
            'supplier_name' => $s['name'], 'lines' => 0, 'units' => 0, 'cancelled_by' => null, 'cancelled_on' => null, 'sent_at' => null, 'sent_via' => null], $canPost),
            $db->all(
                'SELECT d.id, d.number, d.status, d.doc_date, d.review_state, p.state, p.net_total, p.expected_date, '
                . "(SELECT r.number FROM document r WHERE r.reverses_id = d.id AND r.status = 'posted' LIMIT 1) AS cancelled_by, "
                . "(SELECT r.posted_at FROM document r WHERE r.reverses_id = d.id AND r.status = 'posted' LIMIT 1) AS cancelled_on FROM purchase_order p "
                . 'JOIN document d ON d.id = p.document_id WHERE p.supplier_id = ? ORDER BY d.id DESC LIMIT 10',
                [$id],
            )) : null;
        // The details that are empty are named once (F373).
        $empty = [];
        foreach (['legal_name', 'company_number', 'vat_number', 'contact_name', 'phone', 'payment_terms', 'default_lead_days', 'review_days', 'min_order_value'] as $k) {
            if ($s[$k] === null || trim((string) $s[$k]) === '') {
                $empty[] = Words::of('SUPPLIER_FIELD', $k);
            }
        }
        return $ctx->page('supplier', [
            's' => $s,
            'title' => Words::say('SUPPLIER', 'title', (string) $s['name'], (string) $s['code']),
            'people' => [
                'created' => $name($s['created_by']) ?? Words::ANOMALIES['set_up'], 'updated' => $name($s['updated_by']) ?? Words::ANOMALIES['set_up'],
                'changed' => $name($s['details_changed_by']), 'approved' => $name($s['approved_by']), 'dd' => $name($s['dd_checked_by']),
                'route' => $name($s['import_route_approved_by']), 'deactivated' => $name($s['deactivated_by']),
            ],
            'ddOverdue' => $s['dd_next_review_on'] !== null && (string) $s['dd_next_review_on'] < $today,
            'routeUnapproved' => (int) $s['is_overseas'] === 1 && $s['import_route_approved_at'] === null,
            'missing' => $missing,
            'missingText' => self::fields($missing),
            'emptyText' => Words::andList($empty),
            'open' => $open,
            'history' => self::history($tasks),
            'files' => $files,
            'items' => ['n' => (int) ($items['n'] ?? 0), 'active' => (int) ($items['active'] ?? 0), 'preferred' => (int) ($items['preferred'] ?? 0),
                'no_price' => (int) ($items['no_price'] ?? 0)],
            'canManage' => $canManage,
            'recentPos' => $recentPos,
            'canOrder' => $canPost && $s['status'] !== 'inactive',
            'canRequest' => $canManage && in_array($s['status'], ['draft', 'inactive'], true),
            'canEdit' => $canManage && $s['status'] !== 'pending_approval',
            'canDeactivate' => $canManage && $s['status'] === 'active',
            'lookOnly' => $canManage ? null : Words::whoCan('suppliers.manage'),
            'maxMb' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::say('SUPPLIER', 'title', (string) $s['name'], (string) $s['code']), 'active' => 'suppliers', 'notice' => $notice]);
    }

    /**
     * The supplier's approvals and checks as sentences (plan F376): "7 Oct 2026, 10:26 – Ben asked for a reviewer's OK (New supplier).",
     * then how it ended.
     *
     * @param list<array<string, mixed>> $tasks
     * @return list<array{asked: string, outcome: string}>
     */
    private static function history(array $tasks): array
    {
        $out = [];
        foreach ($tasks as $t) {
            $who = (string) ($t['opened_by_name'] ?? Words::ANOMALIES['set_up']);
            $asked = Words::say('SUPPLIER', $t['kind'] === 'review' ? 'h_line_review' : 'h_line', Html::when((string) $t['opened_at']), $who,
                Words::of('CHECK_REASON', (string) $t['reason']));
            $note = $t['decision_note'] === null || trim((string) $t['decision_note']) === '' ? null : (string) $t['decision_note'];
            $outcome = match ((string) $t['state']) {
                'open' => Words::say('SUPPLIER', 'h_open', Html::day((string) $t['due_at'])),
                'withdrawn' => Words::SUPPLIER['h_withdrawn'],
                default => $note === null
                    ? Words::say('SUPPLIER', 'h_decided', Words::of('TASK_STATE', (string) $t['state']), (string) ($t['decided_by_name'] ?? Words::ANOMALIES['set_up']),
                        Html::when((string) $t['decided_at']))
                    : Words::say('SUPPLIER', 'h_decided_note', Words::of('TASK_STATE', (string) $t['state']), (string) ($t['decided_by_name'] ?? Words::ANOMALIES['set_up']),
                        Html::when((string) $t['decided_at']), $note),
            };
            $out[] = ['asked' => $asked, 'outcome' => $outcome];
        }
        return $out;
    }

    /** "address, postcode, e-mail or phone": the supplier's details by name. @param list<string> $fields */
    public static function fields(array $fields): string
    {
        return implode(', ', array_map(static fn (string $f): string => Words::of('SUPPLIER_FIELD', $f), $fields));
    }

    private static function notFound(Context $ctx): HtmlResponse
    {
        return $ctx->error(404, 'unknown_supplier', Words::BUY_ERROR['unknown_supplier'], ['/ui/purchasing/suppliers', Words::MENU['suppliers']]);
    }

    /**
     * A refusal of the supplier services in the page's words, by its error code (plan rule 18: the service's message stays the
     * API's). A code without words: the service's message, then "Nothing was saved."
     */
    public static function plain(CwException $e): string
    {
        $code = $e->errorCode;
        return match (true) {
            in_array($code, ['version_conflict', 'no_file', 'too_large', 'upload_failed', 'bad_kind', 'supplier_pending'], true) => $e->getMessage(),
            $code === 'supplier_incomplete' && is_array($e->detail['missing'] ?? null) => Words::say('BUY_ERROR', 'supplier_incomplete', self::fields($e->detail['missing'])),
            $code === 'supplier_incomplete' => Words::say('BUY_ERROR', 'supplier_incomplete', Words::SUPPLIER_FIELD['import_route']),
            $code === 'bad_field' => self::fieldError($e)['text'],
            isset(Words::ERROR[$code]) && !str_contains(Words::ERROR[$code], '%') => Words::ERROR[$code],
            isset(Words::BUY_ERROR[$code]) && $code !== 'other' => Words::BUY_ERROR[$code],
            default => Words::say('BUY_ERROR', 'other', PurchaseOrdersController::sentence($e->getMessage())),
        };
    }

    /**
     * A refused field of the supplier form (plan F380, F381): which field, and what is wrong in a sentence. The service's message
     * starts with the field's own label ("e-mail: is not an e-mail address"): that part is left out, the field is marked instead.
     *
     * @return array{field: ?string, text: string}
     */
    public static function fieldError(CwException $e): array
    {
        $field = is_string($e->detail['field'] ?? null) ? $e->detail['field'] : null;
        $label = $field === null ? null : (Suppliers::LABELS[$field] ?? $field);
        $msg = $e->getMessage();
        if ($label !== null && str_starts_with($msg, $label . ': ')) {
            $msg = substr($msg, strlen($label) + 2);
        }
        $text = match ($field) {
            'country' => Words::SUPPLIER_FORM['e_country'],
            'is_overseas' => Words::SUPPLIER_FORM['e_abroad'],
            default => Words::say('SUPPLIER_FORM', 'e_field', ucfirst(Words::of('SUPPLIER_FIELD', (string) $field)), rtrim(str_replace(['in GBP', 'GBP'], ['in £', '£'], $msg), '.')),
        };
        return ['field' => $field, 'text' => $text];
    }

    /**
     * The form's fields someone else changed after version $version of supplier $id (behaviour item 9): field => who changed it
     * last, when, its saved value now as a person reads it, and its value when the form was drawn (`was`, raw: from the first change
     * since). Read from the supplier.update audit rows written since; each carries the version it made and the columns it changed
     * as [before, after].
     *
     * @param array<string, mixed> $s the supplier now
     * @return array<string, array{label: string, by: string, at: string, now: string, was: mixed}>
     */
    private static function changedSince(Context $ctx, int $id, int $version, array $s): array
    {
        $out = [];
        foreach ($ctx->db->all("SELECT a.detail, a.created_at, u.display_name FROM audit_log a LEFT JOIN staff_user u ON u.id = a.staff_user_id "
            . "WHERE a.entity_type = 'supplier' AND a.entity_id = ? AND a.action = 'supplier.update' ORDER BY a.id", [(string) $id]) as $a) {
            $d = Html::json($a['detail']);
            if (!is_int($d['version'] ?? null) || $d['version'] <= $version || !is_array($d['changed'] ?? null)) {
                continue;
            }
            foreach ($d['changed'] as $field => $change) {
                if (is_string($field) && isset(Suppliers::FIELDS[$field])) {
                    $out[$field] = ['label' => ucfirst(Words::of('SUPPLIER_FIELD', $field)), 'by' => (string) ($a['display_name'] ?? Words::SUPPLIER_FORM['someone']),
                        'at' => Html::when((string) $a['created_at']), 'now' => self::shownValue($ctx, $field, $s[$field] ?? null),
                        'was' => array_key_exists($field, $out) ? $out[$field]['was'] : (is_array($change) ? ($change[0] ?? null) : null)];
                }
            }
        }
        $order = array_flip(array_keys(Suppliers::FIELDS));
        uksort($out, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
        return $out;
    }

    /** Whether a typed value is the same as a stored one (as the form shows it: trimmed, line breaks as \n). */
    private static function same(mixed $typed, mixed $stored): bool
    {
        $norm = static fn (mixed $v): string => trim(str_replace("\r\n", "\n", $v === null || is_array($v) ? '' : (string) $v));
        return $norm($typed) === $norm($stored);
    }

    /** A supplier field's saved value as the marks of a stale form show it. */
    private static function shownValue(Context $ctx, string $field, mixed $v): string
    {
        $v = $v === null ? '' : trim((string) $v);
        return match (true) {
            $field === 'is_overseas' => Words::SUPPLIER_FORM[$v === '1' ? 'ticked' : 'not_ticked'],
            $v === '' => Words::SUPPLIER_FORM['empty'],
            $field === 'dd_checked_by' => (string) ($ctx->db->value('SELECT display_name FROM staff_user WHERE id = ?', [(int) $v]) ?? $v),
            default => $v,
        };
    }

    /**
     * The new / edit form. $s: the supplier being edited (null: new); $values: what the fields show; $formKey for a new
     * supplier (FormOnce); $version: the version the form carries (default the row's); $theirs: after a stale save, the fields
     * someone else changed meanwhile (changedSince), listed at the top and marked at their field.
     *
     * @param array<string, mixed>|null $s
     * @param array<string, mixed> $values
     * @param array<string, array{label: string, by: string, at: string, now: string, was: mixed, kept?: bool}> $theirs
     */
    private function form(Context $ctx, ?array $s, array $values, ?string $formKey, int $status, ?CwException $error, ?int $version = null, array $theirs = []): HtmlResponse
    {
        $v = [];
        foreach (array_keys(Suppliers::FIELDS) as $k) {
            $v[$k] = isset($values[$k]) ? (string) $values[$k] : '';
        }
        $fieldError = $error === null || !in_array($error->errorCode, ['bad_field', 'duplicate_code'], true) ? null : self::fieldError($error);
        if ($fieldError !== null && $error?->errorCode === 'duplicate_code') {
            $fieldError['text'] = Words::BUY_ERROR['duplicate_code'];
        }
        $title = $s === null ? Words::SUPPLIER_FORM['title_new'] : Words::say('SUPPLIER_FORM', 'title_edit', (string) $s['name']);
        return $ctx->page('supplier_form', [
            's' => $s,
            'title' => $title,
            'action' => $s === null ? '/ui/purchasing/suppliers' : '/ui/purchasing/suppliers/' . (int) $s['id'],
            'v' => $v,
            'formKey' => $formKey,
            'version' => $version ?? ($s === null ? null : (int) $s['version']),
            'vatCodes' => $ctx->db->all('SELECT code, label FROM vat_code WHERE is_active = 1 ORDER BY sort_order, code'),
            'staff' => $ctx->db->all('SELECT id, display_name FROM staff_user WHERE is_active = 1 ORDER BY display_name, id'),
            'meId' => $ctx->me()->id,
            // A refused field is marked and its message sits under it (F381); anything else is said at the top.
            'error' => $error === null ? null : ($fieldError !== null && $fieldError['field'] !== null ? Words::SUPPLIER_FORM['invalid'] : self::plain($error)),
            'errorField' => $fieldError['field'] ?? null,
            'fieldError' => $fieldError['text'] ?? null,
            'theirs' => $theirs,
            'today' => $ctx->suppliers()->today(),
        ], $status, ['title' => $title, 'active' => 'suppliers']);
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
