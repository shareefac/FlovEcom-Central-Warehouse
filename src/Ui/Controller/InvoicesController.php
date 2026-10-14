<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Invoices\InvoiceMath;
use CW\Invoices\SupplierInvoices;
use CW\OpResult;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Purchasing › Invoices (pack C, plan IM7; docs/decisions.md PC17): the supplier invoices and the supplier's credit notes, the "To
 * match" list (posted invoices whose 3-way match needs a person) and the deliveries not invoiced yet (where "Book its invoice" starts
 * the quick way). One page per invoice or credit note: its details and lines while it is a draft (its creator changes them: a
 * delivery, an order, an item, a charge, a credit of an invoice line), what stops it from being posted, its match line by line with
 * the acceptance of its differences, its credit notes, what it records for the value engine, its files, post / stop / cancel / take
 * back, the reviewer's decide box and its history.
 *
 * Who: invoices.view looks; doc.SINV.post / doc.SCN.post keep them (checked again by SupplierInvoices and the document base, never
 * admin); invoices.accept accepts differences. Every refusal comes back on the page in words (INVOICE_ERROR by code).
 */
final class InvoicesController
{
    public const TO_MATCH = '/ui/purchasing/invoices/to-match';
    public const NOT_INVOICED = '/ui/purchasing/invoices/not-invoiced';
    public const FROM_RECEIPT = '/ui/purchasing/invoices/from-receipt';
    public const FROM_RETURN = '/ui/purchasing/credit-notes/from-return';
    /** POST actions of one record (both kinds). */
    public const ACTIONS = ['details', 'lines', 'add-delivery', 'add-order', 'add-item', 'add-charge', 'add-credit', 'post', 'cancel', 'reverse', 'withdraw', 'files'];

    /** Adds every route (Kernel::router). */
    public static function routes(Router $r): void
    {
        $c = new self();
        $r->add('GET', self::TO_MATCH, 'invoices.view', $c->toMatch(...));
        $r->add('POST', self::TO_MATCH . '/recheck', 'doc.SINV.post', $c->recheck(...));
        $r->add('GET', self::NOT_INVOICED, 'invoices.view', $c->notInvoiced(...));
        $r->add('POST', self::FROM_RECEIPT, 'doc.SINV.post', $c->fromReceipt(...));
        $r->add('POST', self::FROM_RETURN, 'doc.SCN.post', $c->fromReturn(...));
        foreach (SupplierInvoices::PATHS as $kind => $base) {
            $post = 'doc.' . SupplierInvoices::KINDS[$kind] . '.post';
            $r->add('GET', $base, 'invoices.view', static fn (Context $ctx): HtmlResponse => $c->index($ctx, $kind));
            $r->add('POST', $base, $post, static fn (Context $ctx): HtmlResponse => $c->create($ctx, $kind));
            $r->add('GET', $base . '/{id}', 'invoices.view', static fn (Context $ctx): HtmlResponse => $c->show($ctx, $kind));
            foreach (self::ACTIONS as $action) {
                $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action))));
                $r->add('POST', $base . '/{id}/' . $action, $post, static fn (Context $ctx): HtmlResponse => $c->{$method}($ctx, $kind));
            }
        }
        $r->add('POST', SupplierInvoices::PATHS['invoice'] . '/{id}/accept', 'invoices.accept', $c->accept(...));
    }

    // ------------------------------------------------------------------------------------------
    // Lists
    // ------------------------------------------------------------------------------------------

    /** @param array<string, mixed>|null $typed what a refused create form sent */
    public function index(Context $ctx, string $kind, int $status = 200, ?CwException $error = null, ?array $typed = null): HtmlResponse
    {
        $type = SupplierInvoices::KINDS[$kind];
        $me = $ctx->me();
        $canPost = Documents::mayPost($me->roles, $type);
        $f = self::filters($ctx->req);
        $rows = array_map(fn (array $r): array => $this->listRow($kind, $r), $ctx->supplierInvoices()->list($kind, $f));
        $k = Words::INVOICE_KIND[$kind];
        return $ctx->page('invoices', [
            'kind' => $kind,
            'k' => $k,
            'base' => SupplierInvoices::PATHS[$kind],
            'rows' => $rows,
            'limit' => SupplierInvoices::LIST_LIMIT,
            'f' => $f,
            'filtered' => $f['q'] !== '' || $f['state'] !== null || $f['supplier'] !== null || $f['match'] !== null || $f['cancelled'],
            'states' => array_intersect_key(Words::DOC_STATUS, array_flip(SupplierInvoices::STATES)),
            'matchStates' => Words::MATCH_STATE,
            'suppliers' => self::suppliers($ctx),
            'canPost' => $canPost,
            'formKey' => $canPost ? FormOnce::newKey() : null,
            'typed' => $typed ?? [],
            'lookOnly' => $canPost ? null : Words::whoCan('doc.' . $type . '.post'),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => $k['tab']]);
    }

    /** GET to-match: posted invoices whose match needs a person (or, show=accepted, whose differences were accepted). */
    public function toMatch(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $show = $ctx->req->param('show') === 'accepted' ? 'accepted' : 'needs_review';
        $rows = $ctx->supplierInvoices()->toMatch($show);
        $notice = $ctx->req->param('notice');
        return $ctx->page('invoices_to_match', [
            'show' => $show,
            'rows' => array_map(static fn (array $r): array => $r + ['href' => SupplierInvoices::PATHS['invoice'] . '/' . (int) $r['id']], $rows),
            'limit' => SupplierInvoices::LIST_LIMIT,
            'canRecheck' => Documents::mayPost($ctx->me()->roles, 'SINV') && $rows !== [],
            'rules' => self::rulesText($ctx),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::SEGMENT['to_match'], 'notice' => $notice === 'rechecked'
            ? Words::say('INVOICE_NOTICE', 'rechecked', (int) ($ctx->req->param('changed') ?? 0)) : null]);
    }

    /** POST to-match/recheck: works the listed invoices' matches out again with the tolerances now. */
    public function recheck(Context $ctx): HtmlResponse
    {
        $si = $ctx->supplierInvoices();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], [...$si->toMatch('needs_review'), ...$si->toMatch('accepted')]);
        try {
            $changed = $si->recheck($ctx->caller(), $ids);
        } catch (CwException $e) {
            return $this->toMatch($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::TO_MATCH, ['notice' => 'rechecked', 'changed' => $changed]));
    }

    /** GET not-invoiced: every posted delivery with units still to invoice, and "Book its invoice". */
    public function notInvoiced(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $q = mb_substr(trim((string) $ctx->req->param('q')), 0, 100);
        $supplier = UiRequest::id($ctx->req->param('supplier'));
        $rows = $ctx->supplierInvoices()->notInvoiced($supplier, $q);
        $canPost = Documents::mayPost($ctx->me()->roles, 'SINV');
        return $ctx->page('invoices_not_invoiced', [
            // One key a row: each "Book its invoice" is its own form (FormOnce: a double click books one invoice).
            'rows' => array_map(static fn (array $r): array => $r + ['form_key' => $canPost ? FormOnce::newKey() : null], $rows),
            'q' => $q,
            'supplier' => $supplier,
            'suppliers' => self::suppliers($ctx),
            'total' => array_sum(array_map(static fn (array $r): int => InvoiceMath::e2((string) $r['value_left']), $rows)),
            'limit' => SupplierInvoices::LIST_LIMIT,
            'canPost' => $canPost,
            'lookOnly' => $canPost ? null : Words::whoCan('doc.SINV.post'),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::SEGMENT['not_invoiced']]);
    }

    // ------------------------------------------------------------------------------------------
    // Creating
    // ------------------------------------------------------------------------------------------

    /** POST {base}: a new draft (FormOnce): the supplier, their number and date; a credit note may name the invoice it reduces. */
    public function create(Context $ctx, string $kind): HtmlResponse
    {
        $req = $ctx->req;
        $typed = ['supplier_id' => (string) ($req->field('supplier_id') ?? ''), 'invoice_number' => (string) ($req->field('invoice_number') ?? ''),
            'invoice_date' => (string) ($req->field('invoice_date') ?? ''), 'credits_id' => (string) ($req->field('credits_id') ?? '')];
        try {
            $supplier = UiRequest::id($typed['supplier_id']) ?? throw new CwException('supplier_required', Words::INVOICE_ERROR['supplier_required'], 422);
            $r = FormOnce::run($ctx, 'ui.invoice.create', ['kind' => $kind] + $typed, function (Db $db) use ($ctx, $kind, $supplier, $typed): OpResult {
                $header = ['invoice_number' => $typed['invoice_number'], 'invoice_date' => $typed['invoice_date']];
                if ($kind === 'credit_note') {
                    $header['credits_id'] = $typed['credits_id'];
                }
                $d = $ctx->supplierInvoices()->createDraft($ctx->caller(), $kind, $supplier, $header);
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id,
                    'redirect' => Html::url(SupplierInvoices::PATHS[$kind] . '/' . $d->id, ['notice' => 'created'])]);
            });
        } catch (CwException $e) {
            return $this->index($ctx, $kind, $e->httpStatus, $e, $typed);
        }
        return FormOnce::redirect($r);
    }

    /** POST from-receipt: the quick way, the invoice of a posted delivery (FormOnce). */
    public function fromReceipt(Context $ctx): HtmlResponse
    {
        $grn = UiRequest::id($ctx->req->field('grn_id'));
        try {
            if ($grn === null) {
                throw new CwException('delivery_not_posted', Words::INVOICE_ERROR['delivery_not_posted'], 422);
            }
            $r = FormOnce::run($ctx, 'ui.invoice.from_receipt', ['grn_id' => $grn], function (Db $db) use ($ctx, $grn): OpResult {
                $x = $ctx->supplierInvoices()->createFromReceipt($ctx->caller(), $grn);
                $d = $x['document'];
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id, 'redirect' => Html::url(SupplierInvoices::PATHS['invoice'] . '/' . $d->id,
                    ['notice' => $x['number_taken'] === null ? 'from_receipt' : 'number_taken', 'other' => $x['number_taken']])]);
            });
        } catch (CwException $e) {
            return $this->notInvoiced($ctx, $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    /** POST credit-notes/from-return: the credit note of one of our returns (FormOnce). */
    public function fromReturn(Context $ctx): HtmlResponse
    {
        $dn = UiRequest::id($ctx->req->field('dn_id'));
        try {
            if ($dn === null) {
                throw new CwException('return_not_posted', Words::INVOICE_ERROR['return_not_posted'], 422);
            }
            $r = FormOnce::run($ctx, 'ui.invoice.from_return', ['dn_id' => $dn], function (Db $db) use ($ctx, $dn): OpResult {
                $d = $ctx->supplierInvoices()->createFromReturn($ctx->caller(), $dn);
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id,
                    'redirect' => Html::url(SupplierInvoices::PATHS['credit_note'] . '/' . $d->id, ['notice' => 'from_return'])]);
            });
        } catch (CwException $e) {
            return (new SupplierReturnsController())->page($ctx, $dn ?? 0, $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    // ------------------------------------------------------------------------------------------
    // One record
    // ------------------------------------------------------------------------------------------

    public function show(Context $ctx, string $kind): HtmlResponse
    {
        $n = $ctx->req->param('notice');
        $notice = null;
        if ($n !== null) {
            $notice = match (true) {
                $n === 'posted' || $n === 'reversed' => Words::say('INVOICE_NOTICE', $n, (string) ($ctx->req->param('number') ?? '')),
                $n === 'number_taken' => Words::say('INVOICE_NOTICE', 'number_taken', (string) ($ctx->req->param('other') ?? '')),
                isset(Words::INVOICE_NOTICE[$n]) => Words::INVOICE_NOTICE[$n],
                isset(Words::RECORD_NOTICE[$n]) => Words::RECORD_NOTICE[$n],
                default => null,
            };
        }
        return $this->page($ctx, $kind, $ctx->id(), 200, null, $notice);
    }

    /** POST {id}/details: the draft's number, date, totals, note (a credit note: the invoice it reduces, the return it answers). */
    public function details(Context $ctx, string $kind): HtmlResponse
    {
        $typed = [];
        foreach (['invoice_number', 'invoice_date', 'net_total', 'vat_total', 'gross_total', 'note', 'credits_id', 'debit_note_id'] as $f) {
            $v = $ctx->req->field($f);
            if ($v !== null && ($kind === 'credit_note' || !in_array($f, ['credits_id', 'debit_note_id'], true))) {
                $typed[$f] = $v;
            }
        }
        return $this->act($ctx, $kind, static function (SupplierInvoices $si, int $id, int $version) use ($ctx, $typed): array {
            $si->saveHeader($ctx->caller(), $id, $version, $typed);
            return ['notice' => 'header'];
        }, ['header' => $typed]);
    }

    /** POST {id}/lines: every line as the table sent it (packs and pack price of a goods line, amount and words of a charge; "remove"). */
    public function lines(Context $ctx, string $kind): HtmlResponse
    {
        return $this->act($ctx, $kind, static function (SupplierInvoices $si, int $id, int $version) use ($ctx): array {
            $si->saveLines($ctx->caller(), $id, $version, self::editedLines($ctx, $id));
            return ['notice' => 'lines'];
        });
    }

    public function addDelivery(Context $ctx, string $kind): HtmlResponse
    {
        $grn = UiRequest::id($ctx->req->field('grn_id'));
        return $this->act($ctx, $kind, static function (SupplierInvoices $si, int $id, int $version) use ($ctx, $grn): array {
            $si->addReceipt($ctx->caller(), $id, $version, $grn ?? throw new CwException('delivery_not_posted', Words::INVOICE_ERROR['delivery_not_posted'], 422));
            return ['notice' => 'delivery_added'];
        });
    }

    public function addOrder(Context $ctx, string $kind): HtmlResponse
    {
        $po = UiRequest::id($ctx->req->field('po_id'));
        return $this->act($ctx, $kind, static function (SupplierInvoices $si, int $id, int $version) use ($ctx, $po): array {
            $si->addOrder($ctx->caller(), $id, $version, $po ?? throw new CwException('order_not_posted', Words::INVOICE_ERROR['order_not_posted'], 422));
            return ['notice' => 'order_added'];
        });
    }

    /** POST {id}/add-item: an item by barcode, CW number or words (`choices` drawn on the page), or one chosen from the choices. */
    public function addItem(Context $ctx, string $kind): HtmlResponse
    {
        $req = $ctx->req;
        $typed = ['q' => (string) ($req->field('q') ?? ''), 'packs' => trim((string) ($req->field('packs') ?? '1')), 'price' => (string) ($req->field('price') ?? '')];
        $choice = UiRequest::id($req->field('sku_id'));
        $extra = [];
        return $this->act($ctx, $kind, static function (SupplierInvoices $si, int $id, int $version) use ($ctx, $typed, $choice, &$extra): array {
            if (preg_match('/^[0-9]{1,7}$/D', $typed['packs']) !== 1 || (int) $typed['packs'] < 1) {
                throw new CwException('bad_line', Words::INVOICE_ERROR['bad_packs'], 422, ['field' => 'packs']);
            }
            $r = $si->addItem($ctx->caller(), $id, $version, $typed['q'], (int) $typed['packs'], trim($typed['price']) === '' ? null : $typed['price'], $choice);
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

    public function addCharge(Context $ctx, string $kind): HtmlResponse
    {
        $typed = ['kind' => (string) ($ctx->req->field('line_kind') ?? ''), 'amount' => (string) ($ctx->req->field('amount') ?? ''),
            'description' => (string) ($ctx->req->field('description') ?? '')];
        return $this->act($ctx, $kind, static function (SupplierInvoices $si, int $id, int $version) use ($ctx, $typed): array {
            $si->addCharge($ctx->caller(), $id, $version, $typed['kind'], $typed['amount'], $typed['description']);
            return ['notice' => 'charge_added'];
        }, ['charge' => $typed]);
    }

    /** POST {id}/add-credit: a credit note credits units of one line of the invoice it reduces. */
    public function addCredit(Context $ctx, string $kind): HtmlResponse
    {
        $typed = ['line' => (string) ($ctx->req->field('line') ?? ''), 'units' => trim((string) ($ctx->req->field('units') ?? '')),
            'price' => (string) ($ctx->req->field('price') ?? '')];
        return $this->act($ctx, $kind, static function (SupplierInvoices $si, int $id, int $version) use ($ctx, $typed): array {
            $line = UiRequest::id($typed['line']) ?? throw new CwException('credit_line_mismatch', Words::INVOICE_ERROR['credit_line_mismatch'], 422);
            if (preg_match('/^[0-9]{1,8}$/D', $typed['units']) !== 1) {
                throw new CwException('credit_more_than_invoiced', Words::INVOICE_ERROR['bad_units'], 422, ['field' => 'units']);
            }
            $si->addCredit($ctx->caller(), $id, $version, $line, (int) $typed['units'], trim($typed['price']) === '' ? null : $typed['price']);
            return ['notice' => 'credit_added'];
        }, ['credit' => $typed]);
    }

    public function post(Context $ctx, string $kind): HtmlResponse
    {
        return $this->act($ctx, $kind, static function (SupplierInvoices $si, int $id, int $version) use ($ctx): array {
            $d = $si->post($ctx->caller(), $id, $version);
            return $d->status === 'awaiting_approval' ? ['notice' => 'submitted'] : ['notice' => 'posted', 'query' => ['number' => $d->number]];
        });
    }

    public function cancel(Context $ctx, string $kind): HtmlResponse
    {
        $why = (string) ($ctx->req->field('reason') ?? '');
        return $this->act($ctx, $kind, static function (SupplierInvoices $si, int $id, int $version) use ($ctx, $why): array {
            $si->cancel($ctx->caller(), $id, $version, $why);
            return ['notice' => 'cancelled'];
        }, ['stop' => $why]);
    }

    /** POST {id}/reverse: cancels a posted invoice or credit note by its reversal; the person lands on the reversal. */
    public function reverse(Context $ctx, string $kind): HtmlResponse
    {
        $id = $ctx->id();
        $code = (string) ($ctx->req->field('reason_code') ?? '');
        $note = $ctx->req->field('note');
        if (!$this->belongs($ctx, $kind, $id)) {
            return $this->notFound($ctx, $kind);
        }
        try {
            $rev = $ctx->supplierInvoices()->reverse($ctx->caller(), $id, $code, $note === null || trim($note) === '' ? null : $note);
        } catch (CwException $e) {
            return $this->page($ctx, $kind, $id, $e->httpStatus, $e, null, ['typedCancel' => ['code' => $code, 'note' => (string) $note]]);
        }
        return HtmlResponse::redirect(Html::url(SupplierInvoices::PATHS[$kind] . '/' . $rev->id, ['notice' => 'reversed', 'number' => $rev->number]));
    }

    public function withdraw(Context $ctx, string $kind): HtmlResponse
    {
        $id = $ctx->id();
        if (!$this->belongs($ctx, $kind, $id)) {
            return $this->notFound($ctx, $kind);
        }
        try {
            $ctx->supplierInvoices()->withdraw($ctx->caller(), $id);
        } catch (CwException $e) {
            return $this->page($ctx, $kind, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(SupplierInvoices::PATHS[$kind] . '/' . $id, ['notice' => 'withdrawn']));
    }

    /** POST {id}/files: the supplier's paper or other evidence (multipart; FileStore). */
    public function files(Context $ctx, string $kind): HtmlResponse
    {
        $id = $ctx->id();
        if (!$this->belongs($ctx, $kind, $id)) {
            return $this->notFound($ctx, $kind);
        }
        try {
            $file = self::upload($ctx);
            $r = $ctx->supplierInvoices()->attach($ctx->caller(), $id, $file['path'], $file['name'], (string) ($ctx->req->field('role') ?? ''));
        } catch (CwException $e) {
            return $this->page($ctx, $kind, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(SupplierInvoices::PATHS[$kind] . '/' . $id, ['notice' => $r['attached'] ? 'file' : 'file_again']) . '#files');
    }

    /** POST invoices/{id}/accept: a person accepts the differences of an invoice that needs review (invoices.accept). */
    public function accept(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $note = (string) ($ctx->req->field('note') ?? '');
        if (!$this->belongs($ctx, 'invoice', $id)) {
            return $this->notFound($ctx, 'invoice');
        }
        try {
            $ctx->supplierInvoices()->accept($ctx->caller(), $id, $note);
        } catch (CwException $e) {
            return $this->page($ctx, 'invoice', $id, $e->httpStatus, $e, null, ['typedAccept' => $note]);
        }
        return HtmlResponse::redirect(Html::url(SupplierInvoices::PATHS['invoice'] . '/' . $id, ['notice' => 'accepted']) . '#match');
    }

    /**
     * A record's page, also after a refused form (the error in words, what was typed kept: $extra header, add, charge, credit, stop,
     * typedCancel, typedAccept, choices).
     *
     * @param array<string, mixed> $extra
     */
    public function page(Context $ctx, string $kind, int $id, int $status, ?CwException $error, ?string $notice = null, array $extra = []): HtmlResponse
    {
        $si = $ctx->supplierInvoices();
        $rec = $si->record($id);
        if ($rec === null || $rec['kind'] !== $kind) {
            return $this->notFound($ctx, $kind);
        }
        $me = $ctx->me();
        $doc = Document::fromRow($rec);
        $type = $ctx->documents()->typeInfo($doc->docType);
        $canPost = Documents::mayPost($me->roles, $doc->docType);
        $editable = $doc->status === 'draft' && $canPost && $doc->createdBy === $me->id;
        $lines = $si->lines($id);
        $tasks = DocumentsController::tasks($ctx->db, $id);
        $open = null;
        foreach ($tasks as $t) {
            if ($t['state'] === 'open') {
                $open = $t;
            }
        }
        $k = Words::INVOICE_KIND[$kind];
        $supplier = (int) $rec['supplier_id'];
        $isInvoice = $kind === 'invoice';
        $original = !$doc->isReversal();
        $match = $isInvoice && $original && in_array($doc->status, ['draft', 'awaiting_approval', 'posted'], true) && $lines !== [] ? $si->matchOf($id) : null;
        $sum = 0;
        foreach ($lines as $l) {
            $sum += $l['amount'] === null ? 0 : InvoiceMath::e2((string) $l['amount']);
        }
        $reversedBy = $rec['reversed_by_id'] === null ? null : ['id' => (int) $rec['reversed_by_id'], 'number' => $rec['reversed_by_number'],
            'status' => (string) $rec['reversed_by_status']];
        $ruleWaits = $doc->status === 'draft' && ($type['approval_rule'] !== 'none' || (int) ($type['size_approval'] ?? 0) === 1);
        $creditLines = [];
        if (!$isInvoice && $editable && $rec['credits_document_id'] !== null) {
            foreach ($si->lines((int) $rec['credits_document_id']) as $l) {
                if ($l['kind'] === 'goods') {
                    $left = (int) $l['qty'] - $si->creditedUnits((int) $rec['credits_document_id'], (int) $l['line_no'], $id);
                    $creditLines[] = ['line_no' => $l['line_no'], 'label' => Words::say('INVOICES', 'credit_line_choice', (int) $l['line_no'], (string) ($l['name'] ?? ''),
                        max(0, $left)), 'left' => $left];
                }
            }
        }
        return $ctx->page('supplier_invoice', [
            'kind' => $kind,
            'k' => $k,
            'base' => SupplierInvoices::PATHS[$kind],
            'doc' => $doc,
            'rec' => $rec,
            'title' => Words::docTitle($doc->docType, $doc->number, (string) ($rec['supplier_name'] ?? ''), $original ? null : (string) ($rec['reverses_number'] ?? '#' . $doc->reversesId)),
            'facts' => self::facts($rec, $doc, $kind),
            'lines' => array_map(static fn (array $l): array => $l + ['kind_word' => Words::of('INVOICE_LINE', (string) $l['kind']),
                'packs_text' => $l['kind'] === 'goods' ? ReceivingController::packsText((int) $l['packs'], (string) ($l['purchase_unit'] ?? 'each'), (int) $l['units_per_pack']) : null,
                'match' => $match['lines'][(int) $l['line_no']] ?? null], $lines),
            'sum' => $sum,
            'match' => $match === null ? null : self::matchView($match, $doc->status === 'posted'),
            'storedMatch' => $isInvoice && $doc->status === 'posted' && $original ? (string) $rec['match_state'] : null,
            'problems' => $editable ? $si->problems($id) : [],
            'editable' => $editable,
            'notCreator' => $doc->status === 'draft' && $canPost && $doc->createdBy !== $me->id,
            'version' => $doc->version,
            'form' => self::form($rec, $extra['header'] ?? null),
            'deliveries' => $editable && $isInvoice ? array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => Words::say('INVOICES', 'delivery_choice',
                (string) $r['number'], Html::day((string) $r['doc_date']), (int) $r['units_left'])], $si->notInvoiced($supplier)) : [],
            'orders' => $editable && $isInvoice ? $si->orderChoices($supplier) : [],
            'invoiceChoices' => $editable && !$isInvoice ? $si->invoiceChoices($supplier) : [],
            'returnChoices' => $editable && !$isInvoice ? $ctx->db->all("SELECT d.id, d.number FROM supplier_return r JOIN document d ON d.id = r.document_id AND d.status = 'posted' "
                . 'WHERE r.supplier_id = ? ORDER BY d.id DESC LIMIT 50', [$supplier]) : [],
            'creditLines' => $creditLines,
            'chargeKinds' => array_intersect_key(Words::INVOICE_LINE, array_flip(SupplierInvoices::CHARGE_KINDS)),
            'decide' => DocumentsController::decideBox($ctx, $doc, $tasks, $type, $rec['gross_total'] === null ? null : (string) $rec['gross_total']),
            'waiting' => $open !== null,
            'history' => DocumentsController::history($tasks),
            'canWithdraw' => $doc->status === 'awaiting_approval' && $doc->submittedBy === $me->id && $original,
            'reverse' => $doc->status === 'posted' && $original && $reversedBy === null && $canPost
                ? ['reasons' => $ctx->db->all("SELECT code, label, needs_note FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 AND system_only = 0 "
                    . "AND is_active = 1 AND code NOT IN ('po_amended', 'supplier_cannot_supply', 'not_needed') ORDER BY sort_order, code")] : null,
            'reversedBy' => $reversedBy,
            'ruleWaits' => $ruleWaits,
            'canAccept' => $isInvoice && $doc->status === 'posted' && $original && $rec['match_state'] === 'needs_review' && $me->can('invoices.accept'),
            'canCredit' => $isInvoice && $doc->status === 'posted' && $original && Documents::mayPost($me->roles, 'SCN'),
            'creditKey' => FormOnce::newKey(),
            'events' => $doc->isPosted() || $doc->isReversal() ? $si->valueEvents($id) : [],
            'files' => array_map(static fn (array $f): array => $f + ['what' => Words::INVOICE_FILE[(string) $f['role']] ?? Words::of('RECEIPT_FILE', (string) $f['role'])],
                ReceivingController::fileRows($ctx->db->all('SELECT f.id, f.original_name, f.mime, f.size_bytes, df.role, df.attached_at FROM document_file df '
                . 'JOIN stored_file f ON f.id = df.file_id WHERE df.document_id = ? ORDER BY df.attached_at, f.id', [$id]))),
            'fileRoles' => array_intersect_key(Words::INVOICE_FILE, SupplierInvoices::FILE_ROLES),
            'canFile' => $canPost && $doc->status !== 'cancelled',
            'maxMb' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
            'choices' => $extra['choices'] ?? null,
            'typedAdd' => $extra['typedAdd'] ?? $extra['add'] ?? ['q' => '', 'packs' => '1', 'price' => ''],
            'typedCharge' => $extra['charge'] ?? ['kind' => 'freight', 'amount' => '', 'description' => ''],
            'typedCredit' => $extra['credit'] ?? ['line' => '', 'units' => '', 'price' => ''],
            'typedCancel' => $extra['typedCancel'] ?? ['code' => '', 'note' => ''],
            'typedStop' => (string) ($extra['stop'] ?? ''),
            'typedAccept' => (string) ($extra['typedAccept'] ?? ''),
            'lookOnly' => $canPost ? null : Words::whoCan('doc.' . $doc->docType . '.post'),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
            'problemsOfError' => $error !== null && is_array($error->detail['problems'] ?? null) ? array_map(static fn (array $p): string => self::problemText($p),
                $error->detail['problems']) : [],
        ], $status, ['title' => Words::docTitle($doc->docType, $doc->number), 'notice' => $notice]);
    }

    /** A refusal in words, by its code (INVOICE_ERROR; the stock records' and the document base's), filled in from its detail. */
    public static function plain(CwException $e): string
    {
        $d = $e->detail;
        return match ($e->errorCode) {
            'duplicate_invoice' => Words::say('INVOICE_ERROR', 'duplicate_invoice', (string) ($d['key'] ?? ''), (string) ($d['other_label'] ?? '')),
            'not_found' => Words::say('STOCK_ERROR', 'not_found', (string) ($d['q'] ?? '')),
            'below_zero' => Words::say('STOCK_ERROR', 'below_zero', (int) ($d['line'] ?? 0), (string) ($d['sku_code'] ?? ''), (int) ($d['have'] ?? 0), (int) ($d['wanted'] ?? 0)),
            'credit_more_than_invoiced' => isset($d['left']) ? Words::say('INVOICE_ERROR', 'credit_left', (int) $d['left']) : self::problemText(['code' => $e->errorCode,
                'message' => $e->getMessage()] + (isset($d['line']) ? ['line' => (int) $d['line']] : [])),
            'note_required' => Words::INVOICE_ERROR['accept_note'],
            default => isset(Words::INVOICE_ERROR[$e->errorCode]) && is_int($d['line'] ?? null)
                ? Words::say('INVOICE_ERROR', 'on_line', (int) $d['line'], Words::INVOICE_ERROR[$e->errorCode])
                : (Words::INVOICE_ERROR[$e->errorCode] ?? Words::STOCK_ERROR[$e->errorCode] ?? Words::RECEIPT_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage())),
        };
    }

    /** One problem of a posting in words (its code's, naming its line), else the service's own sentence. @param array<string, mixed> $p */
    public static function problemText(array $p): string
    {
        $w = Words::INVOICE_ERROR[(string) ($p['code'] ?? '')] ?? null;
        if ($w === null) {
            return (string) ($p['message'] ?? '');
        }
        return is_int($p['line'] ?? null) ? Words::say('INVOICE_ERROR', 'on_line', (int) $p['line'], $w) : $w;
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * Runs one change of a draft (the form's version first), then lands back on the record with a notice; a refusal shows the page
     * with the error and what was typed.
     *
     * @param \Closure(SupplierInvoices, int, int): array{notice?: string, query?: array<string, mixed>, page?: bool} $fn
     * @param array<string, mixed> $typed
     * @param array<string, mixed> $extra
     */
    private function act(Context $ctx, string $kind, \Closure $fn, array $typed = [], array &$extra = []): HtmlResponse
    {
        $id = $ctx->id();
        if (!$this->belongs($ctx, $kind, $id)) {
            return $this->notFound($ctx, $kind);
        }
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $r = $fn($ctx->supplierInvoices(), $id, $version);
        } catch (CwException $e) {
            return $this->page($ctx, $kind, $id, $e->httpStatus, $e, null, $typed);
        }
        if (($r['page'] ?? false) === true) {
            return $this->page($ctx, $kind, $id, 200, null, null, $extra);
        }
        return HtmlResponse::redirect(Html::url(SupplierInvoices::PATHS[$kind] . '/' . $id, ['notice' => $r['notice'] ?? null] + ($r['query'] ?? [])));
    }

    private function belongs(Context $ctx, string $kind, int $id): bool
    {
        return $ctx->supplierInvoices()->kindOfDocument($id) === $kind;
    }

    private function notFound(Context $ctx, string $kind): HtmlResponse
    {
        return $ctx->error(404, 'not_found', 'there is no such record', [SupplierInvoices::PATHS[$kind], Words::INVOICE_KIND[$kind]['tab']]);
    }

    /** @return array{name: string, path: string} the uploaded file (400/413 in words otherwise) */
    public static function upload(Context $ctx): array
    {
        $file = $ctx->req->file('file');
        if ($file === null) {
            throw new CwException('no_file', Words::RECEIPT_ERROR['no_file'], 400, ['field' => 'file']);
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > UiRequest::MAX_UPLOAD_BYTES) {
            throw new CwException('too_large', Words::say('RECEIPT_ERROR', 'too_large', intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576)), 413);
        }
        if ($file['error'] !== UPLOAD_ERR_OK || $file['path'] === '') {
            throw new CwException('upload_failed', Words::RECEIPT_ERROR['upload_failed'], 400);
        }
        return ['name' => (string) $file['name'], 'path' => (string) $file['path']];
    }

    /** @return list<array{id: int, label: string}> the suppliers an invoice or a return may name (active, or switched off since) */
    public static function suppliers(Context $ctx): array
    {
        return array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'label' => (string) $s['name'] . ' (' . (string) $s['code'] . ')'],
            $ctx->db->all("SELECT id, code, name FROM supplier WHERE status IN ('active', 'inactive') ORDER BY name, id"));
    }

    /** @return array{q: string, state: ?string, supplier: ?int, match: ?string, cancelled: bool} */
    private static function filters(UiRequest $req): array
    {
        $state = $req->param('state');
        $match = $req->param('match');
        return [
            'q' => mb_substr(trim((string) $req->param('q')), 0, 100),
            'state' => $state !== null && in_array($state, SupplierInvoices::STATES, true) ? $state : null,
            'supplier' => UiRequest::id($req->param('supplier')),
            'match' => $match !== null && in_array($match, InvoiceMath::STATES, true) ? $match : null,
            'cancelled' => $req->param('cancelled') === '1',
        ];
    }

    /**
     * @param array<string, mixed> $r SupplierInvoices::list() row
     * @return array<string, mixed>
     */
    private function listRow(string $kind, array $r): array
    {
        $status = (string) $r['status'];
        $reversal = $r['reverses_id'] !== null;
        return [
            'href' => SupplierInvoices::PATHS[$kind] . '/' . (int) $r['id'],
            'number' => $r['number'] === null ? Words::STOCK_OPS['no_number'] : (string) $r['number'],
            'sub' => $reversal ? Words::say('STOCK_OPS', 'cancels', (string) ($r['reverses_number'] ?? '')) : (string) ($r['external_ref'] ?? ''),
            'supplier' => (string) ($r['supplier_name'] ?? ''),
            'date' => $r['doc_date'] === null ? '' : Html::day((string) $r['doc_date']),
            'gross' => $reversal ? null : $r['gross_total'],
            'credits' => $r['credits_number'],
            'status' => $status,
            'tone' => $reversal ? 'off' : Words::tone('DOC_STATUS', $status),
            'state_word' => $reversal ? Words::INVOICES['reversal'] : Words::of('DOC_STATUS', $status),
            'match' => $r['match_state'],
            'made_by' => (string) ($r['created_by_name'] ?? ''),
        ];
    }

    /**
     * The header facts of a record's page.
     *
     * @param array<string, mixed> $rec SupplierInvoices::record()
     * @return list<array{label: string, value: string, href?: string}>
     */
    private static function facts(array $rec, Document $doc, string $kind): array
    {
        $by = static fn (?string $at, ?string $who): ?string => $at === null ? null : Words::say('STOCK_OPS', 'by', Html::when($at), (string) ($who ?? Words::RECORD['by_cw']));
        $money = static fn (mixed $v): ?string => $v === null ? null : Html::money((string) $v);
        $rows = [
            [Words::INVOICES['supplier'], (string) ($rec['supplier_name'] ?? '')],
            [Words::INVOICE_KIND[$kind]['number'], $doc->externalRef],
            [Words::INVOICE_KIND[$kind]['date'], $doc->docDate === null ? null : Html::day($doc->docDate)],
            [Words::INVOICES['net'], $money($rec['net_total'])],
            [Words::INVOICES['vat'], $money($rec['vat_total'])],
            [Words::INVOICE_KIND[$kind]['gross'], $money($rec['gross_total'])],
            [Words::INVOICES['credits'], $kind === 'credit_note' ? ($rec['credits_number'] === null ? Words::INVOICES['on_account'] : (string) $rec['credits_number']) : null],
            [Words::INVOICES['debit_note'], $kind === 'credit_note' && $rec['debit_note_number'] !== null ? (string) $rec['debit_note_number'] : null],
            [Words::INVOICES['credited'], $kind === 'invoice' && $rec['credited_e2'] > 0 ? Html::money(InvoiceMath::fromE2((int) $rec['credited_e2'])) : null],
            [Words::INVOICES['owed'], $kind === 'invoice' && $rec['owed_e2'] !== null && $doc->status === 'posted' ? Html::money(InvoiceMath::fromE2((int) $rec['owed_e2'])) : null],
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
     * The details form's values: what a refused form sent, else the record's.
     *
     * @param array<string, mixed> $rec
     * @param array<string, mixed>|null $typed
     * @return array<string, string>
     */
    private static function form(array $rec, ?array $typed): array
    {
        $v = static function (string $field, mixed $fromRec) use ($typed): string {
            if ($typed !== null && array_key_exists($field, $typed)) {
                return (string) $typed[$field];
            }
            return $fromRec === null ? '' : (string) $fromRec;
        };
        return ['invoice_number' => $v('invoice_number', $rec['external_ref']), 'invoice_date' => $v('invoice_date', $rec['doc_date']),
            // The total to pay is typed only to check it (net + VAT): never filled in, so a changed VAT is not refused by an old total.
            'net_total' => $v('net_total', $rec['net_total']), 'vat_total' => $v('vat_total', $rec['vat_total']), 'gross_total' => $v('gross_total', null),
            'note' => $v('note', $rec['note']), 'credits_id' => $v('credits_id', $rec['credits_document_id']), 'debit_note_id' => $v('debit_note_id', $rec['debit_note_document_id'])];
    }

    /**
     * The match as the page shows it: the state's words and every goods line's figures in words.
     *
     * @param array<string, mixed> $m InvoiceMatch::compute()
     * @return array<string, mixed>
     */
    private static function matchView(array $m, bool $posted): array
    {
        $lines = [];
        foreach ($m['lines'] as $no => $l) {
            if ($l['kind'] !== 'goods') {
                continue;
            }
            $lines[$no] = [
                'state' => (string) $l['state'],
                'state_word' => Words::of('MATCH_LINE', (string) $l['state']),
                'tone' => Words::tone('MATCH_LINE', (string) $l['state']),
                'billed' => Words::say('INVOICES', 'billed', (int) $l['units'], Html::money(InvoiceMath::fromE2((int) $l['amount_e2']))),
                'there' => $l['available'] === null ? Words::INVOICES['nothing_there'] : Words::say('INVOICES', 'there', max(0, (int) $l['available'])),
                'ref' => $l['ref_unit_e6'] === null ? Words::INVOICES['no_ref'] : Words::say('INVOICES', $l['ref_from'] === 'order' ? 'ref_order' : 'ref_delivery',
                    Html::money(rtrim(rtrim(\CW\PurchaseOrders\PoMath::fromE6((int) $l['ref_unit_e6']), '0'), '.'))),
                'why' => implode(' ', array_map(static fn (string $w): string => Words::of('MATCH_WHY', $w), $l['why'])),
                'diff' => (int) $l['diff_e2'] === 0 ? '' : Html::money(InvoiceMath::fromE2((int) $l['diff_e2'])),
                'credited' => (int) ($l['credited_units'] ?? 0) > 0 ? Words::say('INVOICES', 'credited_units', (int) $l['credited_units']) : null,
            ];
        }
        return ['state' => $m['state'], 'word' => Words::of('MATCH_STATE', (string) $m['state']), 'tone' => Words::tone('MATCH_STATE', (string) $m['state']),
            'lines' => $lines, 'off' => (int) $m['lines_off'], 'difference' => Html::money(InvoiceMath::fromE2((int) $m['difference_e2'])),
            'total_diff' => (int) $m['total_diff_e2'] === 0 ? null : Html::money(InvoiceMath::fromE2((int) $m['total_diff_e2'])), 'posted' => $posted];
    }

    /** The tolerances in force, in one sentence (the "To match" page). */
    private static function rulesText(Context $ctx): string
    {
        $r = (new \CW\Invoices\InvoiceMatch($ctx->db))->typedRules();
        return Words::say('INVOICES', 'rules_now', Html::dec($r['price_pct']), Html::money($r['price_gbp']), Html::dec($r['qty_pct']), Html::money($r['total_per_line']));
    }

    /**
     * The lines of a draft with the table's edits applied: per stored line `p_<n>` (packs), `pp_<n>` (pack price), `a_<n>` (amount),
     * `d_<n>` (words) and `x_<n>` (remove). The table must hold the stored lines (`line_count`): a draft changed meanwhile is refused.
     *
     * @return list<array<string, mixed>>
     */
    private static function editedLines(Context $ctx, int $id): array
    {
        $req = $ctx->req;
        $current = $ctx->db->all('SELECT dl.line_no, dl.sku_id, dl.amount, dl.description, sl.* FROM document_line dl JOIN sinv_line sl ON sl.document_id = dl.document_id '
            . 'AND sl.line_no = dl.line_no WHERE dl.document_id = ? ORDER BY dl.line_no', [$id]);
        if ((string) count($current) !== (string) ($req->field('line_count') ?? '')) {
            throw new CwException('version_conflict', 'the lines changed since this page was drawn', 409);
        }
        $out = [];
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        foreach ($current as $l) {
            $n = (int) $l['line_no'];
            if ($req->field('x_' . $n) === '1') {
                continue;
            }
            if ($l['kind'] === 'goods') {
                $packs = trim((string) ($req->field('p_' . $n) ?? (string) $l['packs']));
                if (preg_match('/^[0-9]{1,7}$/D', $packs) !== 1 || (int) $packs < 1) {
                    throw new CwException('bad_line', Words::INVOICE_ERROR['bad_packs'], 422, ['line' => $n]);
                }
                $out[] = ['kind' => 'goods', 'sku_id' => (int) $l['sku_id'], 'packs' => (int) $packs, 'units_per_pack' => (int) $l['units_per_pack'],
                    'pack_price' => trim((string) ($req->field('pp_' . $n) ?? (string) $l['pack_price'])), 'description' => $l['description'],
                    'grn_document_id' => $int($l['grn_document_id']), 'grn_line_no' => $int($l['grn_line_no']), 'po_document_id' => $int($l['po_document_id']),
                    'po_line_no' => $int($l['po_line_no']), 'credit_of_line_no' => $int($l['credit_of_line_no']), 'supplier_code' => $l['supplier_code'],
                    'purchase_unit' => $l['purchase_unit'], 'entry' => $l['entry']];
            } else {
                $out[] = ['kind' => (string) $l['kind'], 'amount' => trim((string) ($req->field('a_' . $n) ?? InvoiceMath::fromE2(InvoiceMath::e2((string) $l['amount'])))),
                    'description' => $req->field('d_' . $n) ?? $l['description'], 'entry' => $l['entry']];
            }
        }
        return $out;
    }
}
