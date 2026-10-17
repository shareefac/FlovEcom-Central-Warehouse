<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Handovers\Counterparties;
use CW\Handovers\CounterpartyAccounts;
use CW\Handovers\HandoverPdf;
use CW\Handovers\Handovers;
use CW\OpResult;
use CW\Output\CsvWriter;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Stock › Hand-overs (pack H, IM11; docs/decisions.md PH1-PH13): three segments.
 *
 *   Hand-overs   the list (filter, export) and the "start a hand-over" form (FormOnce: one draft however often it is sent); one page per
 *                hand-over: its facts, its lines (an item by barcode, CW number or name; units; the price of a unit, defaulting to what
 *                the customer last paid), make it final, stop it, cancel it once final (a new record that puts the stock back), copy
 *                it, the reviewer's decide box when a rule asks, and its invoice PDF (refused until the company details are
 *                confirmed: PH8).
 *   Customers    who we hand stock to (sister shops, our shop units, trade customers): the list, add, change, switch off; the prices
 *                each paid last; loading the past ERPNext trade-invoice prices from a file (skipped rows listed, never guessed).
 *   Balances     what each customer owes us (hand-overs less payments received), with every entry; record and reverse a payment.
 *
 * Who: documents.view looks; doc.TRD.post keeps hand-overs; handovers.customers keeps the customer list and its prices; handovers.pay
 * records payments received. Every service checks again inside its transaction (never admin). Refusals come back on the page in
 * words (HANDOVER_ERROR, CUSTOMER_ERROR by code), saying whether anything was saved.
 */
final class HandoversController
{
    public const CUSTOMERS = Handovers::PATH . '/customers';
    public const BALANCES = Handovers::PATH . '/balances';
    /** The list's groups, in order. */
    public const GROUPS = ['draft', 'awaiting_approval', 'posted', 'reversed', 'reversal', 'cancelled'];

    /** Adds every route of the three segments (Kernel::router). */
    public static function routes(Router $r): void
    {
        $c = new self();
        $base = Handovers::PATH;
        $r->add('GET', $base, 'documents.view', $c->index(...));
        $r->add('GET', $base . '.csv', 'documents.view', $c->csv(...));
        $r->add('POST', $base, 'doc.TRD.post', $c->create(...));
        $r->add('GET', self::CUSTOMERS, 'documents.view', $c->customers(...));
        $r->add('POST', self::CUSTOMERS, Counterparties::PERMISSION, $c->addCustomer(...));
        $r->add('POST', self::CUSTOMERS . '/prices', Counterparties::PERMISSION, $c->importPrices(...));
        $r->add('GET', self::CUSTOMERS . '/{id}', 'documents.view', $c->customer(...));
        $r->add('POST', self::CUSTOMERS . '/{id}', Counterparties::PERMISSION, $c->saveCustomer(...));
        $r->add('POST', self::CUSTOMERS . '/{id}/active', Counterparties::PERMISSION, $c->activeCustomer(...));
        $r->add('GET', self::BALANCES, 'documents.view', $c->balances(...));
        $r->add('POST', self::BALANCES . '/{id}/payments', CounterpartyAccounts::PERMISSION, $c->pay(...));
        $r->add('POST', self::BALANCES . '/payments/{id}/reverse', CounterpartyAccounts::PERMISSION, $c->reversePayment(...));
        $r->add('GET', $base . '/{id}', 'documents.view', $c->show(...));
        foreach (['details', 'lines', 'add', 'post', 'cancel', 'reverse', 'copy'] as $action) {
            $r->add('POST', $base . '/{id}/' . $action, 'doc.TRD.post', static fn (Context $ctx): HtmlResponse => $c->{$action}($ctx));
        }
        $r->add('GET', $base . '/{id}/pdf', 'documents.view', $c->pdf(...));
    }

    private static function service(Context $ctx): Handovers
    {
        return new Handovers($ctx->db, $ctx->documents());
    }

    // ------------------------------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------------------------------

    /** @param array<string, mixed>|null $typed what a refused create form sent */
    public function index(Context $ctx, int $status = 200, ?CwException $error = null, ?array $typed = null): HtmlResponse
    {
        $me = $ctx->me();
        $canPost = Documents::mayPost($me->roles, Handovers::TYPE);
        $f = self::filters($ctx->req);
        $svc = self::service($ctx);
        $rows = array_map(static fn (array $r): array => self::listRow($r), $svc->list($f));
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['group']][] = $r;
        }
        $choices = $svc->formChoices();
        return $ctx->page('handovers', [
            'groups' => array_filter(array_map(static fn (string $g): array => $groups[$g] ?? [], array_combine(self::GROUPS, self::GROUPS)),
                static fn (array $g): bool => $g !== []),
            'rowsN' => count($rows),
            'limit' => Handovers::LIST_LIMIT,
            'f' => $f,
            'filtered' => $f['q'] !== '' || $f['state'] !== null || $f['counterparty'] !== null || $f['cancelled'],
            'states' => array_intersect_key(Words::DOC_STATUS, array_flip(Handovers::STATES)),
            'customers' => $choices['customers'],
            'form' => self::form($choices, $typed),
            'canPost' => $canPost,
            'formKey' => $canPost ? FormOnce::newKey() : null,
            'lookOnly' => $canPost ? null : Words::whoCan('doc.TRD.post'),
            'csv' => Html::url(Handovers::PATH . '.csv', ['q' => $f['q'] === '' ? null : $f['q'], 'state' => $f['state'], 'counterparty' => $f['counterparty'],
                'cancelled' => $f['cancelled'] ? '1' : null]),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::SEGMENT['handovers']]);
    }

    /** The list as CSV (the same filters; formula-safe, CsvWriter). */
    public function csv(Context $ctx): HtmlResponse
    {
        $cols = [];
        foreach (['number', 'status', 'date', 'customer', 'customer_kind', 'warehouse', 'their_reference', 'products', 'units', 'net_gbp', 'gross_gbp', 'vat_code',
            'made_by', 'final_at_utc', 'cancels', 'note'] as $c) {
            $cols[] = [$c, in_array($c, ['products', 'units', 'net_gbp', 'gross_gbp'], true) ? 'number' : 'text'];
        }
        $out = new CsvWriter($cols);
        foreach (self::service($ctx)->list(self::filters($ctx->req)) as $r) {
            $out->add([$r['number'] ?? '', $r['status'], $r['doc_date'] ?? '', $r['customer_name'], $r['customer_kind'], $r['warehouse'] ?? '', $r['external_ref'] ?? '',
                (int) $r['products'], (int) $r['units'], $r['net_amount'] === null ? ($r['priced'] === null ? null : (string) $r['priced']) : (string) $r['net_amount'],
                $r['gross_amount'] === null ? null : (string) $r['gross_amount'], $r['vat_code'] ?? '', $r['created_by_name'] ?? '', $r['posted_at'] ?? '',
                $r['reverses_number'] ?? '', $r['note'] ?? '']);
        }
        return FilesController::download($out->output(), 'text/csv; charset=utf-8', 'hand-overs.csv');
    }

    /** POST /ui/stock/handovers: a new draft (FormOnce), then its page. */
    public function create(Context $ctx): HtmlResponse
    {
        $typed = self::typedHeader($ctx->req);
        try {
            $r = FormOnce::run($ctx, 'ui.handover.create', $typed, function (Db $db) use ($ctx, $typed): OpResult {
                $d = self::service($ctx)->createDraft($ctx->caller(), $typed);
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id, 'redirect' => Html::url(Handovers::PATH . '/' . $d->id, ['notice' => 'created'])]);
            });
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e, $typed);
        }
        return FormOnce::redirect($r);
    }

    // ------------------------------------------------------------------------------------------
    // One hand-over
    // ------------------------------------------------------------------------------------------

    public function show(Context $ctx): HtmlResponse
    {
        $n = $ctx->req->param('notice');
        $notice = null;
        if ($n !== null) {
            $notice = match (true) {
                $n === 'added' || $n === 'incremented' => Words::say('HANDOVER_NOTICE', $n, (int) ($ctx->req->param('units') ?? 0), (int) ($ctx->req->param('line') ?? 0)),
                in_array($n, ['posted', 'reversed'], true) => Words::say('HANDOVER_NOTICE', $n, (string) ($ctx->req->param('number') ?? '')),
                isset(Words::HANDOVER_NOTICE[$n]) => Words::HANDOVER_NOTICE[$n],
                isset(Words::RECORD_NOTICE[$n]) => Words::RECORD_NOTICE[$n],
                default => null,
            };
        }
        return $this->page($ctx, $ctx->id(), 200, null, $notice);
    }

    /** POST {id}/details: the draft's customer, warehouse, VAT code, their reference, date and note. */
    public function details(Context $ctx): HtmlResponse
    {
        $typed = self::typedHeader($ctx->req);
        return $this->act($ctx, static function (Handovers $svc, int $id, int $version) use ($ctx, $typed): array {
            $svc->saveHeader($ctx->caller(), $id, $version, $typed);
            return ['notice' => 'header'];
        }, ['header' => $typed]);
    }

    /** POST {id}/lines: every line as the table sent it (units, price; a ticked "remove" takes a line off). */
    public function lines(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, static function (Handovers $svc, int $id, int $version) use ($ctx): array {
            $svc->saveLines($ctx->caller(), $id, $version, self::editedLines($ctx, $id));
            return ['notice' => 'lines'];
        });
    }

    /** POST {id}/add: an item by barcode, CW number or words (`choices` shown on the page), or one chosen from the choices. */
    public function add(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $qtyRaw = trim((string) ($req->field('qty') ?? '1'));
        $typed = ['q' => (string) ($req->field('q') ?? ''), 'qty' => $qtyRaw, 'price' => (string) ($req->field('price') ?? '')];
        $choice = UiRequest::id($req->field('sku_id'));
        $extra = [];
        return $this->act($ctx, static function (Handovers $svc, int $id, int $version) use ($ctx, $typed, $qtyRaw, $choice, &$extra): array {
            if (preg_match('/^[0-9]{1,8}$/D', $qtyRaw) !== 1) {
                throw new CwException('qty_positive', 'the units are a whole number above zero', 422, ['field' => 'qty']);
            }
            $price = trim($typed['price']) === '' ? null : str_replace(['£', ','], '', trim($typed['price']));
            $r = $choice !== null ? $svc->addItem($ctx->caller(), $id, $version, $choice, (int) $qtyRaw, $price, $ctx->settings())
                : $svc->addLine($ctx->caller(), $id, $version, $typed['q'], (int) $qtyRaw, $price, $ctx->settings());
            if ($r['status'] === 'choices') {
                $extra = ['choices' => $r['choices'], 'typedAdd' => $typed];
                return ['page' => true];
            }
            if ($r['status'] === 'not_found') {
                throw new CwException('not_found', 'nothing matches', 422, ['q' => mb_substr($typed['q'], 0, 60)]);
            }
            return ['notice' => $r['status'], 'query' => ['line' => $r['line_no'], 'units' => $r['units']]];
        }, ['add' => $typed], $extra);
    }

    public function post(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, static function (Handovers $svc, int $id, int $version) use ($ctx): array {
            $d = $svc->post($ctx->caller(), $id, $version);
            return $d->status === 'awaiting_approval' ? ['notice' => 'submitted'] : ['notice' => 'posted', 'query' => ['number' => $d->number]];
        });
    }

    public function cancel(Context $ctx): HtmlResponse
    {
        $why = (string) ($ctx->req->field('reason') ?? '');
        return $this->act($ctx, static function (Handovers $svc, int $id, int $version) use ($ctx, $why): array {
            $svc->cancel($ctx->caller(), $id, $version, $why);
            return ['notice' => 'cancelled'];
        }, ['stop' => $why]);
    }

    /** POST {id}/reverse: cancels a final hand-over by a new record; the person lands on the new record. */
    public function reverse(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $code = (string) ($ctx->req->field('reason_code') ?? '');
        $note = $ctx->req->field('note');
        if (!self::service($ctx)->isHandover($id)) {
            return self::notFound($ctx);
        }
        try {
            $rev = self::service($ctx)->reverse($ctx->caller(), $id, $code, $note === null || trim($note) === '' ? null : $note);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['typedCancel' => ['code' => $code, 'note' => (string) $note]]);
        }
        return HtmlResponse::redirect(Html::url(Handovers::PATH . '/' . $rev->id, $rev->status === 'awaiting_approval' ? ['notice' => 'reversal_submitted']
            : ['notice' => 'reversed', 'number' => $rev->number]));
    }

    /** POST {id}/copy: a new draft with the same customer, lines and prices; the person lands on it. */
    public function copy(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        if (!self::service($ctx)->isHandover($id)) {
            return self::notFound($ctx);
        }
        try {
            $d = self::service($ctx)->copy($ctx->caller(), $id);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(Handovers::PATH . '/' . $d->id, ['notice' => 'copied']));
    }

    /** GET {id}/pdf: the invoice; refused (409, on the page in words) while the company details printed on it are not confirmed (PH8). */
    public function pdf(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        if (!self::service($ctx)->isHandover($id)) {
            return self::notFound($ctx);
        }
        try {
            $inv = self::service($ctx)->invoice($id, $ctx->settings());
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return FilesController::download(HandoverPdf::build($inv), 'application/pdf', ($inv['record']['number'] ?? ('draft-' . $id)) . '.pdf');
    }

    /**
     * A hand-over's page, also after a refused form (the error in words, what was typed kept: $extra header, add / typedAdd,
     * typedCancel, stop, choices).
     *
     * @param array<string, mixed> $extra
     */
    public function page(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice = null, array $extra = []): HtmlResponse
    {
        $svc = self::service($ctx);
        $rec = $svc->record($id);
        if ($rec === null) {
            return self::notFound($ctx);
        }
        $me = $ctx->me();
        $doc = Document::fromRow($rec);
        $type = $ctx->documents()->typeInfo($doc->docType);
        $canPost = Documents::mayPost($me->roles, $doc->docType);
        $editable = $doc->status === 'draft' && $canPost && $doc->createdBy === $me->id;
        $lines = $svc->lines($id);
        $tasks = DocumentsController::tasks($ctx->db, $id);
        $reversedBy = $rec['reversed_by_id'] === null ? null : ['id' => (int) $rec['reversed_by_id'], 'number' => $rec['reversed_by_number'],
            'status' => (string) $rec['reversed_by_status']];
        $choices = $svc->formChoices();
        $posted = $rec['net_amount'] !== null;
        $net = '0.00';
        foreach ($lines as $l) {
            if ($l['amount'] !== null) {
                $net = bcadd($net, $l['amount'], 2);
            }
        }
        $missing = array_values(array_filter($lines, static fn (array $l): bool => $l['unit_cost'] === null));
        // Whether the invoice PDF prints now (the same rule as the download, PH8): said beside the link, so nobody meets a refusal.
        $pdfBlocked = null;
        if (!$doc->isReversal()) {
            try {
                $svc->invoice($id, $ctx->settings());
            } catch (CwException $e) {
                $pdfBlocked = self::plain($e);
            }
        }
        return $ctx->page('handover', [
            'doc' => $doc,
            'rec' => $rec,
            'title' => Words::docTitle($doc->docType, $doc->number, null, $doc->isReversal() ? (string) ($rec['reverses_number'] ?? '#' . $doc->reversesId) : null),
            'facts' => self::facts($rec, $doc),
            'lines' => $lines,
            'units' => array_sum(array_map(static fn (array $l): int => abs((int) $l['qty']), $lines)),
            'posted' => $posted,
            'sums' => $posted ? ['net' => (string) $rec['net_amount'], 'vat' => (string) $rec['vat_amount'], 'gross' => (string) $rec['gross_amount'],
                'vat_code' => $rec['vat_code'], 'vat_rate' => $rec['vat_rate']] : ['net' => $net],
            'missingPrices' => count($missing),
            'editable' => $editable,
            'notCreator' => $doc->status === 'draft' && $canPost && $doc->createdBy !== $me->id,
            'form' => self::form($choices, $extra['header'] ?? null, $rec),
            'version' => $doc->version,
            'decide' => DocumentsController::decideBox($ctx, $doc, $tasks, $type, null),
            'history' => DocumentsController::history($tasks),
            'reverse' => $doc->status === 'posted' && !$doc->isReversal() && $reversedBy === null && $canPost
                ? ['reasons' => $ctx->db->all("SELECT code, label, needs_note FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 AND system_only = 0 "
                    . "AND is_active = 1 AND code NOT IN ('po_amended', 'supplier_cannot_supply', 'not_needed') ORDER BY sort_order, code")] : null,
            'reversedBy' => $reversedBy,
            'canCopy' => $canPost && !$doc->isReversal(),
            'canPost' => $canPost,
            'ruleWaits' => $doc->status === 'draft' && ($type['approval_rule'] !== 'none' || (int) ($type['size_approval'] ?? 0) === 1),
            'pdf' => $doc->isReversal() ? null : Handovers::PATH . '/' . $id . '/pdf',
            'pdfBlocked' => $pdfBlocked,
            'companyHref' => '/ui/reference/company',
            'customerHref' => self::CUSTOMERS . '/' . (int) $rec['counterparty_id'],
            'balanceHref' => self::BALANCES . '#customer-' . (int) $rec['counterparty_id'],
            'choices' => $extra['choices'] ?? null,
            'typedAdd' => $extra['typedAdd'] ?? $extra['add'] ?? ['q' => '', 'qty' => '1', 'price' => ''],
            'typedCancel' => $extra['typedCancel'] ?? ['code' => '', 'note' => ''],
            'typedStop' => (string) ($extra['stop'] ?? ''),
            'lookOnly' => $canPost ? null : Words::whoCan('doc.TRD.post'),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::docTitle($doc->docType, $doc->number), 'notice' => $notice]);
    }

    // ------------------------------------------------------------------------------------------
    // Customers
    // ------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $typed what a refused add form sent
     * @param array<string, mixed>|null $import the result of a file of past prices
     */
    public function customers(Context $ctx, int $status = 200, ?CwException $error = null, ?array $typed = null, ?array $import = null): HtmlResponse
    {
        $me = $ctx->me();
        $svc = new Counterparties($ctx->db);
        $kind = $ctx->req->param('kind');
        $f = ['q' => mb_substr(trim((string) $ctx->req->param('q')), 0, 100), 'kind' => in_array($kind, Counterparties::KINDS, true) ? $kind : null,
            'off' => $ctx->req->param('off') === '1'];
        $canManage = Counterparties::mayManage($me->roles);
        $n = $ctx->req->param('notice');
        return $ctx->page('handover_customers', [
            'rows' => $svc->list($f),
            'f' => $f,
            'filtered' => $f['q'] !== '' || $f['kind'] !== null || $f['off'],
            'kinds' => Counterparties::KINDS,
            'vatCodes' => $svc->vatCodes(),
            'canManage' => $canManage,
            'formKey' => $canManage ? FormOnce::newKey() : null,
            'v' => self::customerValues($typed),
            'errors' => self::fieldErrors($error),
            'error' => $error === null ? null : self::plainCustomer($error),
            'errorCode' => $error?->errorCode,
            'import' => $import,
            'maxMb' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
            'lookOnly' => $canManage ? null : Words::whoCan(Counterparties::PERMISSION),
        ], $status, ['title' => Words::SEGMENT['handover_customers'], 'notice' => $n !== null && isset(Words::CUSTOMER_NOTICE[$n]) ? Words::CUSTOMER_NOTICE[$n] : null]);
    }

    /** POST /customers: adds a customer (FormOnce), then its page. */
    public function addCustomer(Context $ctx): HtmlResponse
    {
        $typed = self::typedCustomer($ctx->req);
        try {
            $r = FormOnce::run($ctx, 'ui.counterparty.add', $typed, static function (Db $db) use ($ctx, $typed): OpResult {
                $id = (new Counterparties($ctx->db))->add($ctx->caller(), $typed);
                return OpResult::of(303, ['result' => 'added', 'id' => $id, 'redirect' => Html::url(self::CUSTOMERS . '/' . $id, ['notice' => 'added'])]);
            });
        } catch (CwException $e) {
            return $this->customers($ctx, $e->httpStatus, $e, $typed);
        }
        return FormOnce::redirect($r);
    }

    /** POST /customers/prices: loads a file of past trade-invoice prices; the result (and every skipped row) is shown on the page. */
    public function importPrices(Context $ctx): HtmlResponse
    {
        try {
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
            $result = (new Counterparties($ctx->db))->importPrices($ctx->caller(), $file['path'], $ctx->settings());
        } catch (CwException $e) {
            return $this->customers($ctx, $e->httpStatus, $e);
        }
        return $this->customers($ctx, 200, null, null, $result);
    }

    /** @param array<string, mixed>|null $typed what a refused change form sent */
    public function customer(Context $ctx, int $status = 200, ?CwException $error = null, ?array $typed = null): HtmlResponse
    {
        $svc = new Counterparties($ctx->db);
        $id = $ctx->id();
        $c = $svc->get($id);
        if ($c === null) {
            return $ctx->error(404, 'not_found', 'there is no such customer', [self::CUSTOMERS, Words::SEGMENT['handover_customers']]);
        }
        $me = $ctx->me();
        $canManage = Counterparties::mayManage($me->roles);
        $n = $ctx->req->param('notice');
        $accounts = new CounterpartyAccounts($ctx->db);
        return $ctx->page('handover_customer', [
            'c' => $c,
            'v' => self::customerValues($typed ?? $c),
            'version' => $error?->errorCode === 'customer_changed' ? (int) ($error->detail['version'] ?? $c['version']) : (int) $c['version'],
            'vatCodes' => $svc->vatCodes(),
            'kinds' => Counterparties::KINDS,
            'prices' => $svc->prices($id),
            'balance' => $accounts->balance($id),
            'handovers' => self::service($ctx)->list(['counterparty' => $id, 'cancelled' => false]),
            'canManage' => $canManage,
            'errors' => self::fieldErrors($error),
            'error' => $error === null ? null : self::plainCustomer($error),
            'errorCode' => $error?->errorCode,
            'lookOnly' => $canManage ? null : Words::whoCan(Counterparties::PERMISSION),
            'listHref' => Html::url(Handovers::PATH, ['counterparty' => $id]),
            'balanceHref' => self::BALANCES . '#customer-' . $id,
        ], $status, ['title' => (string) $c['name'], 'notice' => $n !== null && isset(Words::CUSTOMER_NOTICE[$n]) ? Words::CUSTOMER_NOTICE[$n] : null]);
    }

    /** POST /customers/{id}: saves the details (the version the form was drawn with). */
    public function saveCustomer(Context $ctx): HtmlResponse
    {
        $typed = self::typedCustomer($ctx->req);
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $r = (new Counterparties($ctx->db))->save($ctx->caller(), $ctx->id(), $version, $typed);
        } catch (CwException $e) {
            return $this->customer($ctx, $e->httpStatus, $e, $typed);
        }
        return HtmlResponse::redirect(Html::url(self::CUSTOMERS . '/' . $ctx->id(), ['notice' => $r['result'] === 'saved' ? 'saved' : 'unchanged']));
    }

    /** POST /customers/{id}/active: switches a customer off or on again (`on` = 1 / 0). */
    public function activeCustomer(Context $ctx): HtmlResponse
    {
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        $on = $ctx->req->field('on') === '1';
        try {
            $r = (new Counterparties($ctx->db))->setActive($ctx->caller(), $ctx->id(), $version, $on);
        } catch (CwException $e) {
            return $this->customer($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::CUSTOMERS . '/' . $ctx->id(), ['notice' => $r['result'] === 'unchanged' ? 'unchanged' : ($on ? 'switched_on' : 'switched_off')]));
    }

    // ------------------------------------------------------------------------------------------
    // Balances
    // ------------------------------------------------------------------------------------------

    /** @param array<string, string>|null $typed what a refused payment form sent (with `for`: the customer's id) */
    public function balances(Context $ctx, int $status = 200, ?CwException $error = null, ?array $typed = null): HtmlResponse
    {
        $me = $ctx->me();
        $accounts = new CounterpartyAccounts($ctx->db);
        $rows = [];
        foreach ($accounts->balances() as $b) {
            $b['entries'] = $accounts->entries($b['id']);
            $b['form_key'] = $typed !== null && $typed['for'] === (string) $b['id']
                ? ($typed['form_key'] ?? FormOnce::newKey()) : FormOnce::newKey();
            $rows[] = $b;
        }
        $n = $ctx->req->param('notice');
        return $ctx->page('handover_balances', [
            'rows' => $rows,
            'canPay' => CounterpartyAccounts::mayPay($me->roles),
            'today' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d'),
            'typed' => $typed,
            'error' => $error === null ? null : self::plainBalance($error),
            'errorCode' => $error?->errorCode,
            'lookOnly' => CounterpartyAccounts::mayPay($me->roles) ? null : Words::whoCan(CounterpartyAccounts::PERMISSION),
        ], $status, ['title' => Words::SEGMENT['handover_balances'], 'notice' => $n !== null && isset(Words::BALANCE_NOTICE[$n])
            ? Words::say('BALANCE_NOTICE', $n, (string) ($ctx->req->param('balance') ?? '')) : null]);
    }

    public function pay(Context $ctx): HtmlResponse
    {
        $typed = ['for' => (string) $ctx->id(), 'amount' => (string) ($ctx->req->field('amount') ?? ''), 'paid_on' => (string) ($ctx->req->field('paid_on') ?? ''),
            'reference' => (string) ($ctx->req->field('reference') ?? ''), 'note' => (string) ($ctx->req->field('note') ?? '')];
        try {
            $r = FormOnce::run($ctx, 'ui.counterparty.payment', $typed, static function (Db $db) use ($ctx, $typed): OpResult {
                $paid = (new CounterpartyAccounts($db))->recordPayment($ctx->caller(), $ctx->id(), $typed['amount'], $typed['paid_on'], $typed['reference'],
                    $typed['note'] === '' ? null : $typed['note'], $ctx->settings());
                return OpResult::of(303, ['redirect' => Html::url(self::BALANCES, ['notice' => 'paid', 'balance' => Html::money($paid['balance'])])
                    . '#customer-' . $ctx->id()]);
            });
        } catch (CwException $e) {
            $typed['form_key'] = (string) ($ctx->req->field(FormOnce::FIELD) ?? '');
            return $this->balances($ctx, $e->httpStatus, $e, $typed);
        }
        return FormOnce::redirect($r);
    }

    public function reversePayment(Context $ctx): HtmlResponse
    {
        try {
            $r = (new CounterpartyAccounts($ctx->db))->reversePayment($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('reason') ?? ''));
        } catch (CwException $e) {
            return $this->balances($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::BALANCES, ['notice' => 'payment_reversed', 'balance' => Html::money($r['balance'])]) . '#customer-' . $r['counterparty_id']);
    }

    // ------------------------------------------------------------------------------------------
    // Words for refusals
    // ------------------------------------------------------------------------------------------

    /** A hand-over refusal in words, by its code (HANDOVER_ERROR; then the stock records' and Documents' words). */
    public static function plain(CwException $e): string
    {
        $d = $e->detail;
        return match ($e->errorCode) {
            'price_required', 'bad_line', 'line_warehouse' => Words::say('HANDOVER_ERROR', $e->errorCode, (int) ($d['line'] ?? 0)),
            'below_zero' => Words::say('HANDOVER_ERROR', 'below_zero', (int) ($d['line'] ?? 0), (string) ($d['sku_code'] ?? ''), (int) ($d['have'] ?? 0), (int) ($d['wanted'] ?? 0)),
            'not_found' => Words::say('STOCK_ERROR', 'not_found', (string) ($d['q'] ?? '')),
            'too_many_lines' => Words::say('HANDOVER_ERROR', 'too_many_lines', Handovers::MAX_LINES),
            'company_not_confirmed' => Words::HANDOVER_ERROR[($d['posted'] ?? false) ? 'company_not_confirmed_posted' : 'company_not_confirmed'],
            'note_required' => Words::noteRequired($d),
            default => Words::HANDOVER_ERROR[$e->errorCode] ?? Words::STOCK_ERROR[$e->errorCode] ?? Words::RECEIPT_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage()),
        };
    }

    private static function plainCustomer(CwException $e): string
    {
        return Words::CUSTOMER_ERROR[$e->errorCode] ?? Words::RECEIPT_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage());
    }

    private static function plainBalance(CwException $e): string
    {
        $d = $e->detail;
        return match ($e->errorCode) {
            'more_than_owed' => Words::say('BALANCE_ERROR', 'more_than_owed', Html::money((string) ($d['balance'] ?? '0'))),
            default => Words::BALANCE_ERROR[$e->errorCode] ?? Words::ACCOUNT_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage()),
        };
    }

    /** @return array<string, string> field => its problem in words (CUSTOMER_FIELD_ERROR by the service's code) */
    private static function fieldErrors(?CwException $e): array
    {
        $out = [];
        if ($e !== null && is_array($e->detail['errors'] ?? null)) {
            foreach ($e->detail['errors'] as $field => $code) {
                $out[(string) $field] = Words::CUSTOMER_FIELD_ERROR[(string) $code] ?? Words::CUSTOMER_FIELD_ERROR['other'];
            }
        }
        if ($e !== null && $e->errorCode === 'customer_exists') {
            $out['name'] = Words::CUSTOMER_ERROR['customer_exists'];
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * Runs one change of a draft (the form's version first), then lands back on the hand-over with a notice; a refusal shows the page with
     * the error and what was typed.
     *
     * @param \Closure(Handovers, int, int): array{notice?: string, query?: array<string, mixed>, page?: bool} $fn
     * @param array<string, mixed> $typed
     * @param array<string, mixed> $extra
     */
    private function act(Context $ctx, \Closure $fn, array $typed = [], array &$extra = []): HtmlResponse
    {
        $id = $ctx->id();
        $svc = self::service($ctx);
        if (!$svc->isHandover($id)) {
            return self::notFound($ctx);
        }
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $r = $fn($svc, $id, $version);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, $typed);
        }
        if (($r['page'] ?? false) === true) {
            return $this->page($ctx, $id, 200, null, null, $extra);
        }
        return HtmlResponse::redirect(Html::url(Handovers::PATH . '/' . $id, ['notice' => $r['notice'] ?? null] + ($r['query'] ?? [])));
    }

    private static function notFound(Context $ctx): HtmlResponse
    {
        return $ctx->error(404, 'not_found', 'there is no such hand-over', [Handovers::PATH, Words::SEGMENT['handovers']]);
    }

    /** @return array{q: string, state: ?string, counterparty: ?int, cancelled: bool} */
    private static function filters(UiRequest $req): array
    {
        $state = $req->param('state');
        return [
            'q' => mb_substr(trim((string) $req->param('q')), 0, 100),
            'state' => $state !== null && in_array($state, Handovers::STATES, true) ? $state : null,
            'counterparty' => UiRequest::id($req->param('counterparty')),
            'cancelled' => $req->param('cancelled') === '1',
        ];
    }

    /** @return array<string, string> the header fields a form sent (as typed) */
    private static function typedHeader(UiRequest $req): array
    {
        $out = [];
        foreach (['counterparty', 'warehouse', 'vat_code', 'external_ref', 'doc_date', 'note'] as $f) {
            $v = $req->field($f);
            if ($v !== null) {
                $out[$f] = $v;
            }
        }
        return $out;
    }

    /** @return array<string, string> a customer form's fields (as typed) */
    private static function typedCustomer(UiRequest $req): array
    {
        $out = [];
        foreach (Counterparties::FIELDS as $f) {
            $out[$f] = (string) ($req->field($f) ?? '');
        }
        return $out;
    }

    /** @param array<string, mixed>|null $c a customer row or what a form sent @return array<string, string> */
    private static function customerValues(?array $c): array
    {
        $out = [];
        foreach (Counterparties::FIELDS as $f) {
            $out[$f] = $c === null ? ($f === 'kind' ? 'trade' : '') : (string) ($c[$f] ?? '');
        }
        return $out;
    }

    /**
     * A list row as the board shows it.
     *
     * @param array<string, mixed> $r Handovers::list() row
     * @return array<string, mixed>
     */
    private static function listRow(array $r): array
    {
        $status = (string) $r['status'];
        $group = $r['reverses_id'] !== null && $status !== 'cancelled' ? 'reversal' : $status;
        $next = match (true) {
            $group === 'reversal' => Words::say('HANDOVER', 'next_reversal', (string) ($r['reverses_number'] ?? '')),
            $status === 'draft' => Words::HANDOVER['next_draft'],
            $status === 'awaiting_approval' => Words::HANDOVER['next_waiting'],
            $status === 'posted' && $r['open_task'] !== null => Words::HANDOVER['next_check'],
            $status === 'posted', $status === 'reversed' => Words::HANDOVER['next_done'],
            default => Words::HANDOVER['next_stopped'],
        };
        return [
            'id' => (int) $r['id'], 'group' => $group, 'href' => Handovers::PATH . '/' . (int) $r['id'],
            'number_line' => $r['number'] === null ? Words::HANDOVER['no_number'] : (string) $r['number'],
            'date_line' => $r['number'] === null ? Words::say('HANDOVER', 'started', Html::day((string) $r['created_at'])) : Words::say('HANDOVER', 'dated', Html::day((string) $r['doc_date'])),
            'customer' => (string) $r['customer_name'], 'kind' => Words::of('CUSTOMER_KIND', (string) $r['customer_kind']),
            'tone' => $group === 'reversal' ? 'off' : Words::tone('DOC_STATUS', $status), 'state_word' => Words::of('DOC_STATUS', $status),
            'products' => (int) $r['products'], 'units' => (int) $r['units'],
            'value' => $r['gross_amount'] !== null ? (string) $r['gross_amount'] : ($r['priced'] === null ? null : (string) $r['priced']),
            'final' => $r['gross_amount'] !== null,
            'made_by' => (string) ($r['created_by_name'] ?? ''), 'next' => $next,
        ];
    }

    /**
     * The header facts of a hand-over's page (label => value, only those it has).
     *
     * @param array<string, mixed> $rec Handovers::record()
     * @return list<array{label: string, value: string}>
     */
    private static function facts(array $rec, Document $doc): array
    {
        $by = static fn (?string $at, ?string $who): ?string => $at === null ? null : Words::say('HANDOVER', 'by', Html::when($at), (string) ($who ?? Words::RECORD['by_cw']));
        $vat = match (true) {
            $rec['net_amount'] !== null && $rec['vat_code'] === null => Words::HANDOVER['no_vat'],
            $rec['vat_code'] !== null => (string) $rec['vat_code'],
            default => Words::HANDOVER['vat_default'],
        };
        $rows = [
            [Words::HANDOVER['customer'], (string) $rec['customer_name'] . ' (' . Words::of('CUSTOMER_KIND', (string) $rec['customer_kind']) . ')'],
            [Words::HANDOVER['warehouse'], $rec['warehouse_name'] === null ? null : (string) $rec['warehouse_name']],
            [Words::HANDOVER['vat_code'], $vat],
            [Words::HANDOVER['their_ref'], $doc->externalRef],
            [Words::HANDOVER['date'], $doc->docDate === null ? null : Html::day($doc->docDate)],
            [Words::HANDOVER['note'], $doc->note],
            [Words::HANDOVER['made'], $by($doc->createdAt, $rec['created_by_name'] === null ? null : (string) $rec['created_by_name'])],
            [Words::HANDOVER['asked'], $by($doc->submittedAt, $rec['submitted_by_name'] === null ? null : (string) $rec['submitted_by_name'])],
            [Words::HANDOVER['final'], $by($doc->postedAt, $rec['posted_by_name'] === null ? null : (string) $rec['posted_by_name'])],
            [Words::HANDOVER['stopped_at'], $doc->cancelledAt === null ? null : $by($doc->cancelledAt, $rec['cancelled_by_name'] === null ? null : (string) $rec['cancelled_by_name'])
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
     * The create / details form: the choices and the values (what a refused form sent, else the hand-over's, else the defaults: no
     * customer chosen, MAIN, the VAT code that applies, today).
     *
     * @param array<string, mixed> $choices Handovers::formChoices()
     * @param array<string, mixed>|null $typed
     * @param array<string, mixed>|null $rec
     * @return array<string, mixed>
     */
    private static function form(array $choices, ?array $typed, ?array $rec = null): array
    {
        $v = static function (string $field, mixed $fromRec) use ($typed): string {
            if ($typed !== null && array_key_exists($field, $typed)) {
                return (string) $typed[$field];
            }
            return $fromRec === null ? '' : (string) $fromRec;
        };
        $main = null;
        foreach ($choices['warehouses'] as $w) {
            if ($w['code'] === 'MAIN') {
                $main = $w['id'];
            }
        }
        return [
            'customers' => $choices['customers'],
            'warehouses' => $choices['warehouses'],
            'vatCodes' => $choices['vat_codes'],
            'companyVat' => $choices['company_vat_code'],
            'notRegistered' => $choices['vat_registered'] === false,
            'noCustomers' => $choices['customers'] === [],
            'counterparty' => $v('counterparty', $rec['counterparty_id'] ?? null),
            'warehouse' => $v('warehouse', $rec['warehouse_id'] ?? $main ?? ($choices['warehouses'][0]['id'] ?? null)),
            'vat_code' => $v('vat_code', $rec !== null && $rec['net_amount'] === null ? $rec['vat_code'] : null),
            'external_ref' => $v('external_ref', $rec['external_ref'] ?? null),
            'doc_date' => $v('doc_date', $rec['doc_date'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d')),
            'note' => $v('note', $rec['note'] ?? null),
        ];
    }

    /**
     * The lines of a draft with the table's edits applied: per stored line `u_<n>` (units), `p_<n>` (price) and `x_<n>` (remove). The
     * table must hold the stored lines (`line_count`): a draft changed meanwhile is refused (409).
     *
     * @return list<array<string, mixed>>
     */
    private static function editedLines(Context $ctx, int $id): array
    {
        $req = $ctx->req;
        $current = $ctx->db->all('SELECT line_no, sku_id, qty, unit_cost FROM document_line WHERE document_id = ? AND sku_id IS NOT NULL ORDER BY line_no', [$id]);
        if ((string) count($current) !== (string) ($req->field('line_count') ?? '')) {
            throw new CwException('version_conflict', 'the lines changed since this page was drawn', 409);
        }
        $out = [];
        foreach ($current as $l) {
            $n = (int) $l['line_no'];
            if ($req->field('x_' . $n) === '1') {
                continue;
            }
            $u = trim((string) ($req->field('u_' . $n) ?? (string) $l['qty']));
            if (preg_match('/^[0-9]{1,8}$/D', $u) !== 1) {
                throw new CwException('qty_positive', 'the units are a whole number above zero', 422, ['line' => $n]);
            }
            $price = $req->field('p_' . $n);
            $price = $price === null ? ($l['unit_cost'] === null ? null : (string) $l['unit_cost']) : (trim($price) === '' ? null : str_replace(['£', ','], '', trim($price)));
            $out[] = ['sku_id' => (int) $l['sku_id'], 'qty' => (int) $u, 'unit_cost' => $price];
        }
        return $out;
    }
}
