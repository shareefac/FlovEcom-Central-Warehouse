<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Invoices\InvoiceMath;
use CW\Invoices\SupplierReturns;
use CW\OpResult;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Purchasing › Returns (pack C, plan IM7; docs/decisions.md PC17): the returns to suppliers, each our debit note. The list (with what
 * each claims and what the supplier's credit notes credited), a new return (by hand, or "Return what a delivery put aside": its VERIFY
 * and UNSTAMPED units), and one page per return: its details and lines while it is a draft (an item from a warehouse at a claimed
 * price with the duty paid on it, or a claim without goods), what stops it from being posted, post (the stock goes out), stop, cancel
 * (the stock comes back), take back, its credit notes and "Book the supplier's credit note", its files and history.
 *
 * Who: returns.view looks; doc.DN.post keeps them (checked again by SupplierReturns and the document base, never admin).
 */
final class SupplierReturnsController
{
    public const FROM_RECEIPT = '/ui/purchasing/returns/from-receipt';
    public const ACTIONS = ['details', 'lines', 'add-item', 'add-claim', 'post', 'cancel', 'reverse', 'withdraw', 'files'];

    public static function routes(Router $r): void
    {
        $c = new self();
        $base = SupplierReturns::PATH;
        $r->add('GET', $base, 'returns.view', $c->index(...));
        $r->add('POST', $base, 'doc.DN.post', $c->create(...));
        $r->add('POST', self::FROM_RECEIPT, 'doc.DN.post', $c->fromReceipt(...));
        $r->add('GET', $base . '/{id}', 'returns.view', $c->show(...));
        foreach (self::ACTIONS as $action) {
            $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action))));
            $r->add('POST', $base . '/{id}/' . $action, 'doc.DN.post', $c->{$method}(...));
        }
    }

    /** @param array<string, mixed>|null $typed */
    public function index(Context $ctx, int $status = 200, ?CwException $error = null, ?array $typed = null): HtmlResponse
    {
        $canPost = Documents::mayPost($ctx->me()->roles, 'DN');
        $state = $ctx->req->param('state');
        $f = ['q' => mb_substr(trim((string) $ctx->req->param('q')), 0, 100), 'state' => $state !== null && in_array($state, SupplierReturns::STATES, true) ? $state : null,
            'supplier' => UiRequest::id($ctx->req->param('supplier')), 'cancelled' => $ctx->req->param('cancelled') === '1'];
        $rows = [];
        foreach ($ctx->supplierReturns()->list($f) as $r) {
            $reversal = $r['reverses_id'] !== null;
            $claim = $r['claim_total'] === null ? null : InvoiceMath::e2((string) $r['claim_total']);
            $credit = $reversal ? null : SupplierReturns::creditState($claim, InvoiceMath::e2((string) $r['credited']));
            $rows[] = ['href' => SupplierReturns::PATH . '/' . (int) $r['id'], 'number' => $r['number'] ?? Words::STOCK_OPS['no_number'],
                'sub' => $reversal ? Words::say('STOCK_OPS', 'cancels', (string) ($r['reverses_number'] ?? '')) : (string) ($r['external_ref'] ?? ''),
                'supplier' => (string) ($r['supplier_name'] ?? ''), 'delivery' => $r['grn_number'], 'reason' => $r['reason_label'], 'units' => (int) $r['units'],
                'claim' => $reversal ? null : $r['claim_total'], 'credit' => $credit, 'credit_word' => $credit === null ? null : Words::of('CREDIT_STATE', $credit),
                'credit_tone' => $credit === null ? 'info' : Words::tone('CREDIT_STATE', $credit), 'tone' => $reversal ? 'off' : Words::tone('DOC_STATUS', (string) $r['status']),
                'state_word' => $reversal ? Words::INVOICES['reversal'] : Words::of('DOC_STATUS', (string) $r['status']), 'made_by' => (string) ($r['created_by_name'] ?? '')];
        }
        $choices = $ctx->supplierReturns()->formChoices();
        return $ctx->page('supplier_returns', [
            'rows' => $rows,
            'limit' => SupplierReturns::LIST_LIMIT,
            'f' => $f,
            'filtered' => $f['q'] !== '' || $f['state'] !== null || $f['supplier'] !== null || $f['cancelled'],
            'states' => array_intersect_key(Words::DOC_STATUS, array_flip(SupplierReturns::STATES)),
            'suppliers' => InvoicesController::suppliers($ctx),
            'warehouses' => $choices['warehouses'],
            'reasons' => $choices['reasons'],
            'deliveries' => $canPost ? self::deliveriesWithExceptions($ctx) : [],
            'canPost' => $canPost,
            'formKey' => $canPost ? FormOnce::newKey() : null,
            'quickKey' => $canPost ? FormOnce::newKey() : null,
            'typed' => $typed ?? [],
            'lookOnly' => $canPost ? null : Words::whoCan('doc.DN.post'),
            'error' => $error === null ? null : InvoicesController::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::MENU['returns']]);
    }

    public function create(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $typed = ['supplier_id' => (string) ($req->field('supplier_id') ?? ''), 'warehouse_id' => (string) ($req->field('warehouse_id') ?? ''),
            'reason_code' => (string) ($req->field('reason_code') ?? ''), 'return_ref' => (string) ($req->field('return_ref') ?? '')];
        try {
            $supplier = UiRequest::id($typed['supplier_id']) ?? throw new CwException('supplier_required', Words::INVOICE_ERROR['supplier_required'], 422);
            $r = FormOnce::run($ctx, 'ui.return.create', $typed, function (Db $db) use ($ctx, $supplier, $typed): OpResult {
                $d = $ctx->supplierReturns()->createDraft($ctx->caller(), $supplier, ['warehouse_id' => $typed['warehouse_id'], 'reason_code' => $typed['reason_code'],
                    'return_ref' => $typed['return_ref']]);
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id, 'redirect' => Html::url(SupplierReturns::PATH . '/' . $d->id, ['notice' => 'created'])]);
            });
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e, $typed);
        }
        return FormOnce::redirect($r);
    }

    /** POST from-receipt: a return of what a delivery put aside (VERIFY, UNSTAMPED), FormOnce. */
    public function fromReceipt(Context $ctx): HtmlResponse
    {
        $grn = UiRequest::id($ctx->req->field('grn_id'));
        try {
            if ($grn === null) {
                throw new CwException('delivery_not_posted', Words::INVOICE_ERROR['delivery_not_posted'], 422);
            }
            $r = FormOnce::run($ctx, 'ui.return.from_receipt', ['grn_id' => $grn], function (Db $db) use ($ctx, $grn): OpResult {
                $d = $ctx->supplierReturns()->createFromReceipt($ctx->caller(), $grn);
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id, 'redirect' => Html::url(SupplierReturns::PATH . '/' . $d->id,
                    ['notice' => 'from_receipt'])]);
            });
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    public function show(Context $ctx): HtmlResponse
    {
        $n = $ctx->req->param('notice');
        $notice = null;
        if ($n !== null) {
            $notice = match (true) {
                $n === 'posted' || $n === 'reversed' => Words::say('RETURN_NOTICE', $n, (string) ($ctx->req->param('number') ?? '')),
                isset(Words::RETURN_NOTICE[$n]) => Words::RETURN_NOTICE[$n],
                isset(Words::INVOICE_NOTICE[$n]) => Words::INVOICE_NOTICE[$n],
                isset(Words::RECORD_NOTICE[$n]) => Words::RECORD_NOTICE[$n],
                default => null,
            };
        }
        return $this->page($ctx, $ctx->id(), 200, null, $notice);
    }

    public function details(Context $ctx): HtmlResponse
    {
        $typed = [];
        foreach (['warehouse_id', 'reason_code', 'return_ref', 'return_date', 'note', 'grn_id', 'invoice_id'] as $f) {
            $v = $ctx->req->field($f);
            if ($v !== null) {
                $typed[$f] = $v;
            }
        }
        return $this->act($ctx, static function (SupplierReturns $sr, int $id, int $version) use ($ctx, $typed): array {
            $sr->saveHeader($ctx->caller(), $id, $version, $typed);
            return ['notice' => 'header'];
        }, ['header' => $typed]);
    }

    public function lines(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, static function (SupplierReturns $sr, int $id, int $version) use ($ctx): array {
            $sr->saveLines($ctx->caller(), $id, $version, self::editedLines($ctx, $id));
            return ['notice' => 'lines'];
        });
    }

    public function addItem(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $typed = ['q' => (string) ($req->field('q') ?? ''), 'qty' => trim((string) ($req->field('qty') ?? '1')), 'price' => (string) ($req->field('price') ?? ''),
            'warehouse' => (string) ($req->field('warehouse_id') ?? '')];
        $choice = UiRequest::id($req->field('sku_id'));
        $extra = [];
        return $this->act($ctx, static function (SupplierReturns $sr, int $id, int $version) use ($ctx, $typed, $choice, &$extra): array {
            if (preg_match('/^[0-9]{1,8}$/D', $typed['qty']) !== 1 || (int) $typed['qty'] < 1) {
                throw new CwException('qty_positive', Words::RETURN_ERROR['qty_positive'], 422, ['field' => 'qty']);
            }
            $r = $sr->addItem($ctx->caller(), $id, $version, $typed['q'], (int) $typed['qty'], UiRequest::id($typed['warehouse']),
                trim($typed['price']) === '' ? null : $typed['price'], $choice);
            if ($r['status'] === 'choices') {
                $extra = ['choices' => $r['choices'], 'typedAdd' => $typed];
                return ['page' => true];
            }
            if ($r['status'] === 'not_found') {
                throw new CwException('not_found', 'nothing matches', 422, ['q' => mb_substr($typed['q'], 0, 60)]);
            }
            return ['notice' => 'item_added'];
        }, ['add' => $typed], $extra);
    }

    public function addClaim(Context $ctx): HtmlResponse
    {
        $typed = ['amount' => (string) ($ctx->req->field('amount') ?? ''), 'description' => (string) ($ctx->req->field('description') ?? ''),
            'reason' => (string) ($ctx->req->field('reason_code') ?? '')];
        return $this->act($ctx, static function (SupplierReturns $sr, int $id, int $version) use ($ctx, $typed): array {
            $sr->addClaim($ctx->caller(), $id, $version, $typed['amount'], $typed['description'], $typed['reason']);
            return ['notice' => 'claim_added'];
        }, ['claim' => $typed]);
    }

    public function post(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, static function (SupplierReturns $sr, int $id, int $version) use ($ctx): array {
            $d = $sr->post($ctx->caller(), $id, $version);
            return $d->status === 'awaiting_approval' ? ['notice' => 'submitted'] : ['notice' => 'posted', 'query' => ['number' => $d->number]];
        });
    }

    public function cancel(Context $ctx): HtmlResponse
    {
        $why = (string) ($ctx->req->field('reason') ?? '');
        return $this->act($ctx, static function (SupplierReturns $sr, int $id, int $version) use ($ctx, $why): array {
            $sr->cancel($ctx->caller(), $id, $version, $why);
            return ['notice' => 'cancelled'];
        }, ['stop' => $why]);
    }

    public function reverse(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $code = (string) ($ctx->req->field('reason_code') ?? '');
        $note = $ctx->req->field('note');
        if (!$ctx->supplierReturns()->isReturn($id)) {
            return $this->notFound($ctx);
        }
        try {
            $rev = $ctx->supplierReturns()->reverse($ctx->caller(), $id, $code, $note === null || trim($note) === '' ? null : $note);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['typedCancel' => ['code' => $code, 'note' => (string) $note]]);
        }
        return HtmlResponse::redirect(Html::url(SupplierReturns::PATH . '/' . $rev->id, $rev->status === 'awaiting_approval' ? ['notice' => 'reversal_submitted']
            : ['notice' => 'reversed', 'number' => $rev->number]));
    }

    public function withdraw(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        if (!$ctx->supplierReturns()->isReturn($id)) {
            return $this->notFound($ctx);
        }
        try {
            $ctx->supplierReturns()->withdraw($ctx->caller(), $id);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(SupplierReturns::PATH . '/' . $id, ['notice' => 'withdrawn']));
    }

    public function files(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        if (!$ctx->supplierReturns()->isReturn($id)) {
            return $this->notFound($ctx);
        }
        try {
            $file = InvoicesController::upload($ctx);
            $r = $ctx->supplierReturns()->attach($ctx->caller(), $id, $file['path'], $file['name'], (string) ($ctx->req->field('role') ?? ''));
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(SupplierReturns::PATH . '/' . $id, ['notice' => $r['attached'] ? 'file' : 'file_again']) . '#files');
    }

    /** @param array<string, mixed> $extra */
    public function page(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice = null, array $extra = []): HtmlResponse
    {
        $sr = $ctx->supplierReturns();
        $rec = $sr->record($id);
        if ($rec === null) {
            return $this->notFound($ctx);
        }
        $me = $ctx->me();
        $doc = Document::fromRow($rec);
        $type = $ctx->documents()->typeInfo('DN');
        $canPost = Documents::mayPost($me->roles, 'DN');
        $editable = $doc->status === 'draft' && $canPost && $doc->createdBy === $me->id;
        $lines = $sr->lines($id);
        $tasks = DocumentsController::tasks($ctx->db, $id);
        $open = null;
        foreach ($tasks as $t) {
            if ($t['state'] === 'open') {
                $open = $t;
            }
        }
        $choices = $sr->formChoices();
        $supplier = (int) $rec['supplier_id'];
        $reversedBy = $rec['reversed_by_id'] === null ? null : ['id' => (int) $rec['reversed_by_id'], 'number' => $rec['reversed_by_number'],
            'status' => (string) $rec['reversed_by_status']];
        $total = 0;
        foreach ($lines as $l) {
            $total += $l['amount'] === null ? 0 : InvoiceMath::e2((string) $l['amount']);
        }
        $h = $extra['header'] ?? null;
        $v = static fn (string $f, mixed $fromRec): string => $h !== null && array_key_exists($f, $h) ? (string) $h[$f] : ($fromRec === null ? '' : (string) $fromRec);
        return $ctx->page('supplier_return', [
            'base' => SupplierReturns::PATH,
            'doc' => $doc,
            'rec' => $rec,
            'title' => Words::docTitle('DN', $doc->number, (string) ($rec['supplier_name'] ?? ''), $doc->isReversal() ? (string) ($rec['reverses_number'] ?? '#' . $doc->reversesId) : null),
            'facts' => self::facts($rec, $doc),
            'lines' => $lines,
            'total' => $total,
            'problems' => $editable ? array_map(static fn (array $p): string => InvoicesController::problemText($p), $sr->problems($id)) : [],
            'editable' => $editable,
            'notCreator' => $doc->status === 'draft' && $canPost && $doc->createdBy !== $me->id,
            'version' => $doc->version,
            'warehouses' => $choices['warehouses'],
            'reasons' => $choices['reasons'],
            'form' => ['warehouse_id' => $v('warehouse_id', $rec['warehouse_id']), 'reason_code' => $v('reason_code', $rec['reason_code']),
                'return_ref' => $v('return_ref', $rec['external_ref']), 'return_date' => $v('return_date', $rec['doc_date']), 'note' => $v('note', $rec['note']),
                'grn_id' => $v('grn_id', $rec['grn_document_id']), 'invoice_id' => $v('invoice_id', $rec['invoice_document_id'])],
            'deliveryChoices' => $editable ? $ctx->db->all("SELECT d.id, d.number FROM goods_receipt g JOIN document d ON d.id = g.document_id AND d.status = 'posted' "
                . 'WHERE g.supplier_id = ? ORDER BY d.id DESC LIMIT 50', [$supplier]) : [],
            'invoiceChoices' => $editable ? $ctx->supplierInvoices()->invoiceChoices($supplier) : [],
            'decide' => DocumentsController::decideBox($ctx, $doc, $tasks, $type, null),
            'waiting' => $open !== null,
            'history' => DocumentsController::history($tasks),
            'canWithdraw' => $doc->status === 'awaiting_approval' && $doc->submittedBy === $me->id && !$doc->isReversal(),
            'reverse' => $doc->status === 'posted' && !$doc->isReversal() && $reversedBy === null && $canPost
                ? ['reasons' => $ctx->db->all("SELECT code, label, needs_note FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 AND system_only = 0 "
                    . "AND is_active = 1 AND code NOT IN ('po_amended', 'supplier_cannot_supply', 'not_needed') ORDER BY sort_order, code")] : null,
            'reversedBy' => $reversedBy,
            'ruleWaits' => $doc->status === 'draft' && ($type['approval_rule'] !== 'none' || (int) ($type['size_approval'] ?? 0) === 1),
            'creditNotes' => $rec['credit_notes'],
            'canCredit' => $doc->status === 'posted' && !$doc->isReversal() && Documents::mayPost($me->roles, 'SCN'),
            'creditKey' => FormOnce::newKey(),
            'files' => array_map(static fn (array $f): array => $f + ['what' => Words::INVOICE_FILE[(string) $f['role']] ?? Words::of('RECEIPT_FILE', (string) $f['role'])],
                ReceivingController::fileRows($ctx->db->all('SELECT f.id, f.original_name, f.mime, f.size_bytes, df.role, df.attached_at FROM document_file df '
                . 'JOIN stored_file f ON f.id = df.file_id WHERE df.document_id = ? ORDER BY df.attached_at, f.id', [$id]))),
            'fileRoles' => array_intersect_key(Words::INVOICE_FILE, SupplierReturns::FILE_ROLES),
            'canFile' => $canPost && $doc->status !== 'cancelled',
            'maxMb' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
            'choices' => $extra['choices'] ?? null,
            'typedAdd' => $extra['typedAdd'] ?? $extra['add'] ?? ['q' => '', 'qty' => '1', 'price' => '', 'warehouse' => ''],
            'typedClaim' => $extra['claim'] ?? ['amount' => '', 'description' => '', 'reason' => ''],
            'typedCancel' => $extra['typedCancel'] ?? ['code' => '', 'note' => ''],
            'typedStop' => (string) ($extra['stop'] ?? ''),
            'lookOnly' => $canPost ? null : Words::whoCan('doc.DN.post'),
            'error' => $error === null ? null : InvoicesController::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::docTitle('DN', $doc->number), 'notice' => $notice]);
    }

    // ------------------------------------------------------------------------------------------

    /**
     * @param \Closure(SupplierReturns, int, int): array{notice?: string, query?: array<string, mixed>, page?: bool} $fn
     * @param array<string, mixed> $typed
     * @param array<string, mixed> $extra
     */
    private function act(Context $ctx, \Closure $fn, array $typed = [], array &$extra = []): HtmlResponse
    {
        $id = $ctx->id();
        if (!$ctx->supplierReturns()->isReturn($id)) {
            return $this->notFound($ctx);
        }
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $r = $fn($ctx->supplierReturns(), $id, $version);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, $typed);
        }
        if (($r['page'] ?? false) === true) {
            return $this->page($ctx, $id, 200, null, null, $extra);
        }
        return HtmlResponse::redirect(Html::url(SupplierReturns::PATH . '/' . $id, ['notice' => $r['notice'] ?? null] + ($r['query'] ?? [])));
    }

    private function notFound(Context $ctx): HtmlResponse
    {
        return $ctx->error(404, 'not_found', 'there is no such return', [SupplierReturns::PATH, Words::MENU['returns']]);
    }

    /** @return list<array{id: int, label: string}> recent posted deliveries with VERIFY or UNSTAMPED units (the quick return's choices) */
    private static function deliveriesWithExceptions(Context $ctx): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => Words::say('RETURNS', 'delivery_choice', (string) $r['number'],
            (string) $r['supplier_name'], (int) $r['units'])], $ctx->db->all(
            "SELECT d.id, d.number, s.name AS supplier_name, SUM(g.verify_units + g.quarantine_units) AS units FROM document d JOIN goods_receipt gr ON gr.document_id = d.id "
            . "JOIN supplier s ON s.id = gr.supplier_id JOIN grn_line g ON g.document_id = d.id WHERE d.doc_type = 'GRN' AND d.status = 'posted' "
            . 'GROUP BY d.id, d.number, s.name HAVING units > 0 ORDER BY d.id DESC LIMIT 50'));
    }

    /**
     * @param array<string, mixed> $rec
     * @return list<array{label: string, value: string}>
     */
    private static function facts(array $rec, Document $doc): array
    {
        $by = static fn (?string $at, ?string $who): ?string => $at === null ? null : Words::say('STOCK_OPS', 'by', Html::when($at), (string) ($who ?? Words::RECORD['by_cw']));
        $credited = (int) $rec['credited_e2'];
        $rows = [
            [Words::INVOICES['supplier'], (string) ($rec['supplier_name'] ?? '')],
            [Words::RETURNS['ref'], $doc->externalRef],
            [Words::RETURNS['date'], $doc->docDate === null ? null : Html::day($doc->docDate)],
            [Words::RETURNS['from'], $rec['warehouse_name'] === null ? null : (string) $rec['warehouse_name']],
            [Words::RETURNS['reason'], $rec['reason_label'] === null ? null : (string) $rec['reason_label']],
            [Words::RETURNS['delivery'], $rec['grn_number'] === null ? null : (string) $rec['grn_number']],
            [Words::RETURNS['invoice'], $rec['invoice_number'] === null ? null : (string) $rec['invoice_number']],
            [Words::RETURNS['claim'], $rec['claim_total'] === null ? null : Html::money((string) $rec['claim_total'])],
            [Words::RETURNS['credited'], $rec['credit_state'] === null ? null : Words::of('CREDIT_STATE', (string) $rec['credit_state'])
                . ($credited > 0 ? ' (' . Html::money(InvoiceMath::fromE2($credited)) . ')' : '')],
            [Words::STOCK_OPS['note'], $doc->note],
            [Words::STOCK_OPS['made'], $by($doc->createdAt, $rec['created_by_name'] === null ? null : (string) $rec['created_by_name'])],
            [Words::STOCK_OPS['asked'], $by($doc->submittedAt, $rec['submitted_by_name'] === null ? null : (string) $rec['submitted_by_name'])],
            [Words::INVOICES['posted'], $by($doc->postedAt, $rec['posted_by_name'] === null ? null : (string) $rec['posted_by_name'])],
            [Words::STOCK_OPS['stopped_at'], $doc->cancelledAt === null ? null : $by($doc->cancelledAt, $rec['cancelled_by_name'] === null ? null : (string) $rec['cancelled_by_name'])
                . ': ' . (string) $doc->cancelReason],
        ];
        $out = [];
        foreach ($rows as [$label, $value]) {
            if ($value !== null && $value !== '') {
                $out[] = ['label' => $label, 'value' => $value];
            }
        }
        return $out;
    }

    /**
     * The lines with the table's edits: per stored line `u_<n>` (units), `w_<n>` (warehouse), `c_<n>` (price), `t_<n>` (duty), `r_<n>`
     * (reason), `a_<n>` and `d_<n>` (a claim's amount and words), `x_<n>` (remove). 409 when the draft changed meanwhile.
     *
     * @return list<array<string, mixed>>
     */
    private static function editedLines(Context $ctx, int $id): array
    {
        $req = $ctx->req;
        $current = $ctx->db->all('SELECT dl.line_no, dl.sku_id, dl.qty, dl.warehouse_id, dl.unit_cost, dl.amount, dl.reason_code, dl.description, n.grn_document_id, n.grn_line_no, '
            . 'n.duty_amount FROM document_line dl JOIN dn_line n ON n.document_id = dl.document_id AND n.line_no = dl.line_no WHERE dl.document_id = ? ORDER BY dl.line_no', [$id]);
        if ((string) count($current) !== (string) ($req->field('line_count') ?? '')) {
            throw new CwException('version_conflict', 'the lines changed since this page was drawn', 409);
        }
        $out = [];
        foreach ($current as $l) {
            $n = (int) $l['line_no'];
            if ($req->field('x_' . $n) === '1') {
                continue;
            }
            $reason = $req->field('r_' . $n);
            $reason = $reason === null ? $l['reason_code'] : ($reason === '' ? null : $reason);
            if ($l['sku_id'] === null) {
                $out[] = ['amount' => trim((string) ($req->field('a_' . $n) ?? InvoiceMath::fromE2(InvoiceMath::e2((string) $l['amount'])))),
                    'description' => $req->field('d_' . $n) ?? $l['description'], 'reason_code' => $reason];
                continue;
            }
            $u = trim((string) ($req->field('u_' . $n) ?? (string) $l['qty']));
            if (preg_match('/^[0-9]{1,8}$/D', $u) !== 1) {
                throw new CwException('qty_positive', Words::RETURN_ERROR['qty_positive'], 422, ['line' => $n]);
            }
            $out[] = ['sku_id' => (int) $l['sku_id'], 'qty' => (int) $u, 'warehouse_id' => UiRequest::id($req->field('w_' . $n)) ?? ($l['warehouse_id'] === null ? null : (int) $l['warehouse_id']),
                'unit_cost' => trim((string) ($req->field('c_' . $n) ?? (string) $l['unit_cost'])), 'duty_amount' => trim((string) ($req->field('t_' . $n) ?? (string) $l['duty_amount'])),
                'reason_code' => $reason, 'description' => $l['description'], 'grn_document_id' => $l['grn_document_id'] === null ? null : (int) $l['grn_document_id'],
                'grn_line_no' => $l['grn_line_no'] === null ? null : (int) $l['grn_line_no']];
        }
        return $out;
    }
}
