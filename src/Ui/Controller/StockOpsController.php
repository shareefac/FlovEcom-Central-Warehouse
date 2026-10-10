<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\OpResult;
use CW\Output\CsvWriter;
use CW\StockOps\StockOpPdf;
use CW\StockOps\StockOps;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * The stock records' screens (pack A1; docs/decisions.md SO10-SO11): Stock › Stock In, Stock Out, Adjustments, Transfers (and its
 * Releases segment). One controller for the five kinds; each kind has its list (a board grouped by status, v4: Search, Filter,
 * Export), its create form (FormOnce: one draft however often it is sent) and one page per record: the details and the products while
 * it is a draft (its creator changes it: a product by barcode, CW number or name, its units, a cost or the agreed price, a reason per
 * adjustment line), its files, "make it final" (Documents: a reviewer's OK first when a rule asks), stop it, cancel it once final (a
 * new record that puts everything back), take it back while it waits, the reviewer's decide box (the generic record page's), its
 * history, and the transfer note or release invoice as a PDF.
 *
 * Who: documents.view looks (the lists and pages); doc.<TYPE>.post keeps the records (the routes, checked again by StockOps and
 * Documents, never admin). Every refusal comes back on the page in words (STOCK_ERROR by code), saying whether anything was saved.
 */
final class StockOpsController
{
    /** kind => the key of its list page (Words::MENU / SEGMENT, PAGE_INTRO). */
    public const PAGES = ['in' => 'stock_in', 'out' => 'stock_out', 'adjust' => 'adjustments', 'transfer' => 'transfers', 'release' => 'releases'];
    /** The board's groups, in order: status, and the cancellation records apart. */
    public const GROUPS = ['draft', 'awaiting_approval', 'posted', 'reversed', 'reversal', 'cancelled'];
    /** The kinds that print a PDF (the transfer note, the release invoice). */
    public const PDF_KINDS = ['transfer', 'release'];

    /** Adds every route of the five kinds to the router (Kernel::router). */
    public static function routes(Router $r): void
    {
        $c = new self();
        foreach (StockOps::PATHS as $kind => $base) {
            $post = 'doc.' . StockOps::KINDS[$kind] . '.post';
            $r->add('GET', $base, 'documents.view', static fn (Context $ctx): HtmlResponse => $c->index($ctx, $kind));
            $r->add('GET', $base . '.csv', 'documents.view', static fn (Context $ctx): HtmlResponse => $c->csv($ctx, $kind));
            $r->add('POST', $base, $post, static fn (Context $ctx): HtmlResponse => $c->create($ctx, $kind));
            $r->add('GET', $base . '/{id}', 'documents.view', static fn (Context $ctx): HtmlResponse => $c->show($ctx, $kind));
            foreach (['details', 'lines', 'add', 'post', 'cancel', 'reverse', 'withdraw', 'files'] as $action) {
                $r->add('POST', $base . '/{id}/' . $action, $post, static fn (Context $ctx): HtmlResponse => $c->{$action}($ctx, $kind));
            }
            if (in_array($kind, self::PDF_KINDS, true)) {
                $r->add('GET', $base . '/{id}/pdf', 'documents.view', static fn (Context $ctx): HtmlResponse => $c->pdf($ctx, $kind));
            }
        }
    }

    // ------------------------------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $typed what a refused create form sent
     */
    public function index(Context $ctx, string $kind, int $status = 200, ?CwException $error = null, ?array $typed = null): HtmlResponse
    {
        $type = StockOps::KINDS[$kind];
        $me = $ctx->me();
        $canPost = Documents::mayPost($me->roles, $type);
        $f = self::filters($ctx->req);
        $rows = array_map(fn (array $r): array => $this->listRow($kind, $r), $ctx->stockOps()->list($kind, $f));
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['group']][] = $r;
        }
        $choices = $ctx->stockOps()->formChoices($kind);
        $k = Words::STOCK_KIND[$kind];
        $page = self::PAGES[$kind];
        return $ctx->page('stock_ops', [
            'kind' => $kind,
            'k' => $k,
            'pageKey' => $page,
            'base' => StockOps::PATHS[$kind],
            'groups' => array_filter(array_map(static fn (string $g): array => $groups[$g] ?? [], array_combine(self::GROUPS, self::GROUPS)),
                static fn (array $g): bool => $g !== []),
            'rows_n' => count($rows),
            'limit' => StockOps::LIST_LIMIT,
            'f' => $f,
            'filtered' => $f['q'] !== '' || $f['state'] !== null || $f['warehouse'] !== null || $f['cancelled'],
            'states' => array_intersect_key(Words::DOC_STATUS, array_flip(StockOps::STATES)),
            'warehouses' => $choices['warehouses'],
            'form' => self::form($kind, $choices, $typed),
            'canPost' => $canPost,
            'formKey' => $canPost ? FormOnce::newKey() : null,
            'lookOnly' => $canPost ? null : Words::whoCan('doc.' . $type . '.post'),
            'csv' => Html::url(StockOps::PATHS[$kind] . '.csv', ['q' => $f['q'] === '' ? null : $f['q'], 'state' => $f['state'], 'warehouse' => $f['warehouse'],
                'cancelled' => $f['cancelled'] ? '1' : null]),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => $kind === 'release' ? Words::SEGMENT['releases'] : Words::MENU[$page]]);
    }

    /** The list as CSV (the same filters; formula-safe, CsvWriter). */
    public function csv(Context $ctx, string $kind): HtmlResponse
    {
        $cols = [];
        foreach (['number', 'status', 'date', 'warehouse', 'place', 'to_warehouse', 'to_place', 'reason', 'given_to', 'account', 'reference', 'products', 'units',
            'amount_gbp', 'made_by', 'final_at_utc', 'cancels', 'note'] as $c) {
            $cols[] = [$c, in_array($c, ['products', 'units', 'amount_gbp'], true) ? 'number' : 'text'];
        }
        $out = new CsvWriter($cols);
        foreach ($ctx->stockOps()->list($kind, self::filters($ctx->req)) as $r) {
            $out->add([$r['number'] ?? '', $r['status'], $r['doc_date'] ?? '', $r['warehouse'] ?? '', $r['location'] ?? '', $r['to_warehouse'] ?? '', $r['to_location'] ?? '',
                $r['reason_label'] ?? '', $r['given_to'] ?? '', $r['account_name'] ?? '', $r['external_ref'] ?? '', (int) $r['products'], (int) $r['units'],
                $r['priced'] === null ? null : (string) $r['priced'], $r['created_by_name'] ?? '', $r['posted_at'] ?? '', $r['reverses_number'] ?? '', $r['note'] ?? '']);
        }
        return FilesController::download($out->output(), 'text/csv; charset=utf-8', self::PAGES[$kind] . '.csv');
    }

    /** POST {base}: a new draft (FormOnce), then its page. */
    public function create(Context $ctx, string $kind): HtmlResponse
    {
        $typed = self::typedHeader($ctx->req);
        try {
            $r = FormOnce::run($ctx, 'ui.stockop.create', ['kind' => $kind] + $typed, function (Db $db) use ($ctx, $kind, $typed): OpResult {
                $d = $ctx->stockOps()->createDraft($ctx->caller(), $kind, $typed);
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id,
                    'redirect' => Html::url(StockOps::PATHS[$kind] . '/' . $d->id, ['notice' => 'created'])]);
            });
        } catch (CwException $e) {
            return $this->index($ctx, $kind, $e->httpStatus, $e, $typed);
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
                $n === 'added' || $n === 'incremented' => Words::say('STOCK_NOTICE', $n, ...self::addedArgs($ctx)),
                in_array($n, ['posted', 'posted_none', 'reversed'], true) => Words::say('STOCK_NOTICE', $n, (string) ($ctx->req->param('number') ?? '')),
                isset(Words::STOCK_NOTICE[$n]) => Words::STOCK_NOTICE[$n],
                isset(Words::RECORD_NOTICE[$n]) => Words::RECORD_NOTICE[$n],
                default => null,
            };
        }
        return $this->page($ctx, $kind, $ctx->id(), 200, null, $notice);
    }

    /** POST {id}/details: the draft's details (warehouse, places, reason, given to, reference, date, note). */
    public function details(Context $ctx, string $kind): HtmlResponse
    {
        $typed = self::typedHeader($ctx->req);
        return $this->act($ctx, $kind, static function (StockOps $ops, int $id, int $version) use ($ctx, $typed): array {
            $ops->saveHeader($ctx->caller(), $id, $version, $typed);
            return ['notice' => 'header'];
        }, ['header' => $typed]);
    }

    /** POST {id}/lines: every line as the table sent it (units, cost or price, reason; a ticked "remove" takes a line off). */
    public function lines(Context $ctx, string $kind): HtmlResponse
    {
        return $this->act($ctx, $kind, static function (StockOps $ops, int $id, int $version) use ($ctx, $kind): array {
            $ops->saveLines($ctx->caller(), $id, $version, self::editedLines($ctx, $kind, $id));
            return ['notice' => 'lines'];
        });
    }

    /** POST {id}/add: a product by barcode, CW number or words (`choices` shown on the page), or one chosen from the choices. */
    public function add(Context $ctx, string $kind): HtmlResponse
    {
        $req = $ctx->req;
        $qtyRaw = trim((string) ($req->field('qty') ?? '1'));
        $typed = ['q' => (string) ($req->field('q') ?? ''), 'qty' => $qtyRaw, 'cost' => (string) ($req->field('cost') ?? ''), 'reason' => (string) ($req->field('reason') ?? '')];
        $choice = UiRequest::id($req->field('sku_id'));
        $extra = [];
        $res = $this->act($ctx, $kind, static function (StockOps $ops, int $id, int $version) use ($ctx, $typed, $qtyRaw, $choice, &$extra): array {
            if (preg_match('/^[+-]?[0-9]{1,8}$/D', $qtyRaw) !== 1) {
                throw new CwException('qty_positive', 'the units are a whole number', 422, ['field' => 'qty']);
            }
            $qty = (int) $qtyRaw;
            $cost = trim($typed['cost']) === '' ? null : str_replace(['£', ','], '', trim($typed['cost']));
            $reason = $typed['reason'] === '' ? null : $typed['reason'];
            $r = $choice !== null ? $ops->addItem($ctx->caller(), $id, $version, $choice, $qty, $cost, $reason)
                : $ops->addLine($ctx->caller(), $id, $version, $typed['q'], $qty, $cost, $reason);
            if ($r['status'] === 'choices') {
                $extra = ['choices' => $r['choices'], 'typedAdd' => $typed];
                return ['page' => true];
            }
            if ($r['status'] === 'not_found') {
                throw new CwException('not_found', 'nothing matches', 422, ['q' => mb_substr($typed['q'], 0, 60)]);
            }
            return ['notice' => $r['status'], 'query' => ['line' => $r['line_no'], 'units' => $r['units']]];
        }, ['add' => $typed], $extra);
        return $res;
    }

    public function post(Context $ctx, string $kind): HtmlResponse
    {
        return $this->act($ctx, $kind, static function (StockOps $ops, int $id, int $version) use ($ctx): array {
            $d = $ops->post($ctx->caller(), $id, $version);
            if ($d->status === 'awaiting_approval') {
                return ['notice' => 'submitted'];
            }
            $none = (int) $ctx->db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$d->id]) === 0;
            return ['notice' => $none ? 'posted_none' : 'posted', 'query' => ['number' => $d->number]];
        });
    }

    public function cancel(Context $ctx, string $kind): HtmlResponse
    {
        $why = (string) ($ctx->req->field('reason') ?? '');
        return $this->act($ctx, $kind, static function (StockOps $ops, int $id, int $version) use ($ctx, $why): array {
            $ops->cancel($ctx->caller(), $id, $version, $why);
            return ['notice' => 'cancelled'];
        }, ['stop' => $why]);
    }

    /** POST {id}/reverse: cancels a final record by a new record that puts everything back; the person lands on the new record. */
    public function reverse(Context $ctx, string $kind): HtmlResponse
    {
        $id = $ctx->id();
        $code = (string) ($ctx->req->field('reason_code') ?? '');
        $note = $ctx->req->field('note');
        if (!$this->belongs($ctx, $kind, $id)) {
            return $ctx->error(404, 'not_found', 'there is no such record', [StockOps::PATHS[$kind], Words::STOCK_KIND[$kind]['tab']]);
        }
        try {
            $rev = $ctx->stockOps()->reverse($ctx->caller(), $id, $code, $note === null || trim($note) === '' ? null : $note);
        } catch (CwException $e) {
            return $this->page($ctx, $kind, $id, $e->httpStatus, $e, null, ['typedCancel' => ['code' => $code, 'note' => (string) $note]]);
        }
        return HtmlResponse::redirect(Html::url(StockOps::PATHS[$kind] . '/' . $rev->id, $rev->status === 'awaiting_approval' ? ['notice' => 'reversal_submitted']
            : ['notice' => 'reversed', 'number' => $rev->number]));
    }

    /** POST {id}/withdraw: the requester takes back a record waiting for a reviewer's OK (a draft again). */
    public function withdraw(Context $ctx, string $kind): HtmlResponse
    {
        $id = $ctx->id();
        if (!$this->belongs($ctx, $kind, $id)) {
            return $ctx->error(404, 'not_found', 'there is no such record', [StockOps::PATHS[$kind], Words::STOCK_KIND[$kind]['tab']]);
        }
        try {
            $ctx->stockOps()->withdraw($ctx->caller(), $id);
        } catch (CwException $e) {
            return $this->page($ctx, $kind, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(StockOps::PATHS[$kind] . '/' . $id, ['notice' => 'withdrawn']));
    }

    /** POST {id}/files: a file kept with the record (multipart; FileStore). */
    public function files(Context $ctx, string $kind): HtmlResponse
    {
        $id = $ctx->id();
        if (!$this->belongs($ctx, $kind, $id)) {
            return $ctx->error(404, 'not_found', 'there is no such record', [StockOps::PATHS[$kind], Words::STOCK_KIND[$kind]['tab']]);
        }
        $role = (string) ($ctx->req->field('role') ?? '');
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
            $r = $ctx->stockOps()->attach($ctx->caller(), $id, $file['path'], $file['name'], $role);
        } catch (CwException $e) {
            return $this->page($ctx, $kind, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(StockOps::PATHS[$kind] . '/' . $id, ['notice' => $r['attached'] ? 'file' : 'file_again']) . '#files');
    }

    /** GET {id}/pdf: the transfer note or the release invoice (any status; a draft says so). */
    public function pdf(Context $ctx, string $kind): HtmlResponse
    {
        $id = $ctx->id();
        $rec = $ctx->stockOps()->record($id);
        if ($rec === null || $rec['kind'] !== $kind) {
            return $ctx->error(404, 'not_found', 'there is no such record', [StockOps::PATHS[$kind], Words::STOCK_KIND[$kind]['tab']]);
        }
        $pdf = StockOpPdf::build($rec, $ctx->stockOps()->lines($id), $ctx->company()->company());
        return FilesController::download($pdf, 'application/pdf', ($rec['number'] ?? ('draft-' . $id)) . '.pdf');
    }

    /**
     * A record's page, also after a refused form (the error in words, under its status, what was typed kept: $extra typedHeader,
     * typedAdd, typedCancel, choices).
     *
     * @param array<string, mixed> $extra
     */
    public function page(Context $ctx, string $kind, int $id, int $status, ?CwException $error, ?string $notice = null, array $extra = []): HtmlResponse
    {
        $ops = $ctx->stockOps();
        $rec = $ops->record($id);
        if ($rec === null || $rec['kind'] !== $kind) {
            return $ctx->error(404, 'not_found', 'there is no such record', [StockOps::PATHS[$kind], Words::STOCK_KIND[$kind]['tab']]);
        }
        $me = $ctx->me();
        $doc = Document::fromRow($rec);
        $type = $ctx->documents()->typeInfo($doc->docType);
        $canPost = Documents::mayPost($me->roles, $doc->docType);
        $editable = $doc->status === 'draft' && $canPost && $doc->createdBy === $me->id;
        $lines = $ops->lines($id);
        $tasks = DocumentsController::tasks($ctx->db, $id);
        $open = null;
        foreach ($tasks as $t) {
            if ($t['state'] === 'open') {
                $open = $t;
            }
        }
        $reversedBy = $rec['reversed_by_id'] === null ? null : ['id' => (int) $rec['reversed_by_id'], 'number' => $rec['reversed_by_number'],
            'status' => (string) $rec['reversed_by_status']];
        $choices = $ops->formChoices($kind);
        $priced = in_array($kind, ['in', 'release'], true);
        $total = '0.00';
        foreach ($lines as $l) {
            if ($l['amount'] !== null) {
                $total = bcadd($total, $l['amount'], 2);
            }
        }
        $ruleWaits = $doc->status === 'draft' && ($type['approval_rule'] !== 'none' || (int) ($type['size_approval'] ?? 0) === 1);
        return $ctx->page('stock_op', [
            'kind' => $kind,
            'k' => Words::STOCK_KIND[$kind],
            'base' => StockOps::PATHS[$kind],
            'listName' => $kind === 'release' ? Words::SEGMENT['releases'] : Words::STOCK_KIND[$kind]['tab'],
            'doc' => $doc,
            'rec' => $rec,
            'title' => Words::docTitle($doc->docType, $doc->number, null, $doc->isReversal() ? (string) ($rec['reverses_number'] ?? '#' . $doc->reversesId) : null),
            'facts' => self::facts($rec, $doc),
            'lines' => array_map(static fn (array $l): array => $l + ['reason_text' => $l['reason_label'] ?? null], $lines),
            'priced' => $priced,
            'total' => $total,
            'units' => array_sum(array_map(static fn (array $l): int => abs($l['qty']), $lines)),
            'editable' => $editable,
            'notCreator' => $doc->status === 'draft' && $canPost && $doc->createdBy !== $me->id,
            'form' => self::form($kind, $choices, $extra['header'] ?? null, $rec),
            'reasons' => $choices['reasons'],
            'version' => $doc->version,
            'decide' => DocumentsController::decideBox($ctx, $doc, $tasks, $type, null),
            'waiting' => $open !== null,
            'history' => DocumentsController::history($tasks),
            'canWithdraw' => $doc->status === 'awaiting_approval' && $doc->submittedBy === $me->id && !$doc->isReversal(),
            'reverse' => $doc->status === 'posted' && !$doc->isReversal() && $reversedBy === null && $canPost
                ? ['reasons' => $ctx->db->all("SELECT code, label, needs_note FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 AND system_only = 0 "
                    . "AND is_active = 1 AND code NOT IN ('po_amended', 'supplier_cannot_supply', 'not_needed') ORDER BY sort_order, code")] : null,
            'reversedBy' => $reversedBy,
            'ruleWaits' => $ruleWaits,
            'files' => array_map(static fn (array $f): array => $f + ['what' => Words::STOCK_FILE[(string) $f['role']] ?? Words::of('RECEIPT_FILE', (string) $f['role'])],
                ReceivingController::fileRows($ctx->db->all('SELECT f.id, f.original_name, f.mime, f.size_bytes, df.role, df.attached_at FROM document_file df '
                . 'JOIN stored_file f ON f.id = df.file_id WHERE df.document_id = ? ORDER BY df.attached_at, f.id', [$id]))),
            'fileRoles' => array_intersect_key(Words::STOCK_FILE, StockOps::FILE_ROLES),
            'canFile' => $canPost && $doc->status !== 'cancelled',
            'maxMb' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
            'pdf' => in_array($kind, self::PDF_KINDS, true) ? StockOps::PATHS[$kind] . '/' . $id . '/pdf' : null,
            'choices' => $extra['choices'] ?? null,
            'typedAdd' => $extra['typedAdd'] ?? $extra['add'] ?? ['q' => '', 'qty' => '1', 'cost' => '', 'reason' => ''],
            'typedCancel' => $extra['typedCancel'] ?? ['code' => '', 'note' => ''],
            'typedStop' => (string) ($extra['stop'] ?? ''),
            'lookOnly' => $canPost ? null : Words::whoCan('doc.' . $doc->docType . '.post'),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
            'balanceHref' => $kind === 'release' ? Html::url('/ui/stock/accounts') . '#account-' . (int) $doc->warehouseId : null,
        ], $status, ['title' => Words::docTitle($doc->docType, $doc->number), 'notice' => $notice]);
    }

    /** A refusal in words, by its code (STOCK_ERROR; Documents' and the file store's), filled in from its detail. */
    public static function plain(CwException $e): string
    {
        $d = $e->detail;
        return match ($e->errorCode) {
            'reason_direction', 'price_required' => Words::say('STOCK_ERROR', $e->errorCode, (int) ($d['line'] ?? 0)),
            'below_zero' => Words::say('STOCK_ERROR', 'below_zero', (int) ($d['line'] ?? 0), (string) ($d['sku_code'] ?? ''), (int) ($d['have'] ?? 0), (int) ($d['wanted'] ?? 0)),
            'not_enough_held' => Words::say('STOCK_ERROR', 'not_enough_held', (int) ($d['line'] ?? 0), (int) ($d['on_hand'] ?? 0), (string) ($d['sku_code'] ?? '')),
            'not_found' => Words::say('STOCK_ERROR', 'not_found', (string) ($d['q'] ?? '')),
            'too_many_lines' => Words::say('STOCK_ERROR', 'too_many_lines', StockOps::MAX_LINES),
            'note_required' => Words::noteRequired($d),
            default => Words::STOCK_ERROR[$e->errorCode] ?? Words::RECEIPT_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage()),
        };
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * Runs one change of a draft (the form's version first), then lands back on the record with a notice; a refusal shows the page
     * with the error and what was typed.
     *
     * @param \Closure(StockOps, int, int): array{notice?: string, query?: array<string, mixed>, page?: bool} $fn
     * @param array<string, mixed> $typed
     * @param array<string, mixed> $extra filled by $fn when it wants the page drawn (the choices of a search)
     */
    private function act(Context $ctx, string $kind, \Closure $fn, array $typed = [], array &$extra = []): HtmlResponse
    {
        $id = $ctx->id();
        if (!$this->belongs($ctx, $kind, $id)) {
            return $ctx->error(404, 'not_found', 'there is no such record', [StockOps::PATHS[$kind], Words::STOCK_KIND[$kind]['tab']]);
        }
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $r = $fn($ctx->stockOps(), $id, $version);
        } catch (CwException $e) {
            return $this->page($ctx, $kind, $id, $e->httpStatus, $e, null, $typed);
        }
        if (($r['page'] ?? false) === true) {
            return $this->page($ctx, $kind, $id, 200, null, null, $extra);
        }
        return HtmlResponse::redirect(Html::url(StockOps::PATHS[$kind] . '/' . $id, ['notice' => $r['notice'] ?? null] + ($r['query'] ?? [])));
    }

    private function belongs(Context $ctx, string $kind, int $id): bool
    {
        return $ctx->stockOps()->kindOfDocument($id) === $kind;
    }

    /** @return array{q: string, state: ?string, warehouse: ?int, cancelled: bool} */
    private static function filters(UiRequest $req): array
    {
        $state = $req->param('state');
        return [
            'q' => mb_substr(trim((string) $req->param('q')), 0, 100),
            'state' => $state !== null && in_array($state, StockOps::STATES, true) ? $state : null,
            'warehouse' => UiRequest::id($req->param('warehouse')),
            'cancelled' => $req->param('cancelled') === '1',
        ];
    }

    /** @return array<string, string> the header fields a form sent (as typed) */
    private static function typedHeader(UiRequest $req): array
    {
        $out = [];
        foreach (['warehouse', 'location', 'to_warehouse', 'to_location', 'reason_code', 'given_to', 'external_ref', 'doc_date', 'note'] as $f) {
            $v = $req->field($f);
            if ($v !== null) {
                $out[$f] = $v;
            }
        }
        return $out;
    }

    /**
     * A list row as the board shows it.
     *
     * @param array<string, mixed> $r StockOps::list() row
     * @return array<string, mixed>
     */
    private function listRow(string $kind, array $r): array
    {
        $status = (string) $r['status'];
        $group = $r['reverses_id'] !== null && $status !== 'cancelled' ? 'reversal' : $status;
        $from = $r['location'] === null ? (string) ($r['warehouse'] ?? '') : Words::say('STOCK_OPS', 'in_place', (string) $r['warehouse'], (string) $r['location']);
        $to = $r['to_warehouse'] === null ? null : ($r['to_location'] === null ? (string) $r['to_warehouse'] : Words::say('STOCK_OPS', 'in_place', (string) $r['to_warehouse'], (string) $r['to_location']));
        $why = match ($kind) {
            'in', 'out' => (string) ($r['reason_label'] ?? ''),
            'release' => (string) ($r['account_name'] ?? $r['warehouse_entity'] ?? ''),
            default => '',
        };
        $next = match (true) {
            $group === 'reversal' => Words::say('STOCK_OPS', 'next_reversal', (string) ($r['reverses_number'] ?? '')),
            $status === 'draft' => Words::STOCK_OPS['next_draft'],
            $status === 'awaiting_approval' => Words::STOCK_OPS['next_waiting'],
            $status === 'posted' && $r['open_task'] !== null => Words::STOCK_OPS['next_check'],
            $status === 'posted' => Words::STOCK_OPS['next_done'],
            $status === 'reversed' => Words::STOCK_OPS['next_done'],
            default => Words::STOCK_OPS['next_stopped'],
        };
        return [
            'id' => (int) $r['id'], 'group' => $group, 'href' => StockOps::PATHS[$kind] . '/' . (int) $r['id'],
            'number_line' => $r['number'] === null ? Words::STOCK_OPS['no_number'] : (string) $r['number'],
            'date_line' => $r['number'] === null ? Words::say('STOCK_OPS', 'started', Html::day((string) $r['created_at'])) : Words::say('STOCK_OPS', 'dated', Html::day((string) $r['doc_date'])),
            'where' => $to === null ? $from : Words::say('STOCK_OPS', 'to', $from, $to),
            'why' => $why, 'given' => $r['given_to'] === null ? null : Words::say('STOCK_OPS', 'given', (string) $r['given_to']),
            'tone' => $group === 'reversal' ? 'off' : Words::tone('DOC_STATUS', $status), 'state_word' => Words::of('DOC_STATUS', $status),
            'products' => (int) $r['products'], 'units' => (int) $r['units'], 'value' => $r['priced'] === null ? null : (string) $r['priced'],
            'made_by' => (string) ($r['created_by_name'] ?? ''), 'next' => $next,
        ];
    }

    /**
     * The header facts of a record's page (label => value, only those it has).
     *
     * @param array<string, mixed> $rec StockOps::record()
     * @return list<array{label: string, value: string}>
     */
    private static function facts(array $rec, Document $doc): array
    {
        $place = static fn (?string $w, ?string $l): ?string => $w === null ? null : ($l === null ? $w : Words::say('STOCK_OPS', 'in_place', $w, $l));
        $by = static fn (?string $at, ?string $who): ?string => $at === null ? null : Words::say('STOCK_OPS', 'by', Html::when($at), (string) ($who ?? Words::RECORD['by_cw']));
        $kind = (string) $rec['kind'];
        $rows = [
            [Words::STOCK_OPS['kind'], Words::STOCK_KIND[$kind]['one']],
            [in_array($kind, ['transfer', 'release'], true) ? Words::STOCK_OPS['from'] : Words::STOCK_OPS['warehouse'],
                $place($rec['warehouse_name'] === null ? null : (string) $rec['warehouse_name'], $rec['location_name'] === null ? null : (string) $rec['location_name'])],
            [Words::STOCK_OPS['warehouse_to'], $place($rec['to_warehouse_name'] === null ? null : (string) $rec['to_warehouse_name'],
                $rec['to_location_name'] === null ? null : (string) $rec['to_location_name'])],
            [Words::STOCK_OPS['account'], $kind === 'release' ? (string) ($rec['account_name'] ?? $rec['warehouse_entity'] ?? '') : null],
            [Words::STOCK_OPS['reason'], $rec['reason_label'] === null ? null : (string) $rec['reason_label']],
            [Words::STOCK_OPS['given_to'], $rec['given_to'] === null ? null : (string) $rec['given_to']],
            [$kind === 'release' ? Words::STOCK_OPS['ref_release'] : Words::STOCK_OPS['ref'], $doc->externalRef],
            [Words::STOCK_OPS['date'], $doc->docDate === null ? null : Html::day($doc->docDate)],
            [Words::STOCK_OPS['note'], $doc->note],
            [Words::STOCK_OPS['made'], $by($doc->createdAt, $rec['created_by_name'] === null ? null : (string) $rec['created_by_name'])],
            [Words::STOCK_OPS['asked'], $by($doc->submittedAt, $rec['submitted_by_name'] === null ? null : (string) $rec['submitted_by_name'])],
            [Words::STOCK_OPS['final'], $by($doc->postedAt, $rec['posted_by_name'] === null ? null : (string) $rec['posted_by_name'])],
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
     * The create / details form of a kind: which fields it has, the choices (warehouses and their places, reasons) and the values
     * (what a refused form sent, else the record's, else the defaults: the first warehouse of each list, today).
     *
     * @param array{warehouses: list<array<string, mixed>>, reasons: list<array<string, mixed>>} $choices
     * @param array<string, mixed>|null $typed
     * @param array<string, mixed>|null $rec
     * @return array<string, mixed>
     */
    private static function form(string $kind, array $choices, ?array $typed, ?array $rec = null): array
    {
        $own = array_values(array_filter($choices['warehouses'], static fn (array $w): bool => $w['own']));
        $other = array_values(array_filter($choices['warehouses'], static fn (array $w): bool => !$w['own']));
        $from = match ($kind) {
            'out' => $own,
            'release' => $other,
            default => $choices['warehouses'],
        };
        $to = match ($kind) {
            'transfer' => $choices['warehouses'],
            'release' => $own,
            default => [],
        };
        $places = [];
        foreach ($choices['warehouses'] as $w) {
            foreach ($w['places'] as $p) {
                $places[] = ['id' => $p['id'], 'warehouse_id' => $w['id'], 'label' => Words::say('STOCK_OPS', 'in_place', $w['name'], $p['name'])];
            }
        }
        $v = static function (string $field, mixed $fromRec) use ($typed): string {
            if ($typed !== null && array_key_exists($field, $typed)) {
                return (string) $typed[$field];
            }
            return $fromRec === null ? '' : (string) $fromRec;
        };
        $needs = array_values(array_map(static fn (array $r): string => $r['label'], array_filter($choices['reasons'], static fn (array $r): bool => $r['needs_given_to'])));
        return [
            'from' => array_map(static fn (array $w): array => $w + ['label' => $w['own'] ? $w['name'] : Words::say('STOCK_OPS', 'other_room', $w['name'], (string) $w['entity'])], $from),
            'to' => array_map(static fn (array $w): array => $w + ['label' => $w['own'] ? $w['name'] : Words::say('STOCK_OPS', 'other_room', $w['name'], (string) $w['entity'])], $to),
            'places' => $places,
            'reasons' => $choices['reasons'],
            'hasReason' => in_array($kind, ['in', 'out'], true),
            'hasGiven' => $kind === 'out' || ($kind === 'adjust' && $needs !== []),
            'givenHint' => $needs === [] ? null : Words::say('STOCK_OPS', 'given_hint', Words::andList($needs)),
            'hasTo' => in_array($kind, ['transfer', 'release'], true),
            'noOther' => $kind === 'release' && $other === [],
            'warehouse' => $v('warehouse', $rec['warehouse_id'] ?? ($from[0]['id'] ?? null)),
            'location' => $v('location', $rec['location_id'] ?? null),
            'to_warehouse' => $v('to_warehouse', $rec['to_warehouse_id'] ?? ($kind === 'release' ? ($to[0]['id'] ?? null) : null)),
            'to_location' => $v('to_location', $rec['to_location_id'] ?? null),
            'reason_code' => $v('reason_code', $rec['reason_code'] ?? null),
            'given_to' => $v('given_to', $rec['given_to'] ?? null),
            'external_ref' => $v('external_ref', $rec['external_ref'] ?? null),
            'doc_date' => $v('doc_date', $rec['doc_date'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d')),
            'note' => $v('note', $rec['note'] ?? null),
        ];
    }

    /**
     * The lines of a draft with the table's edits applied: per stored line `u_<n>` (units), `c_<n>` (cost or price), `r_<n>` (reason)
     * and `x_<n>` (remove). The table must hold the stored lines (`line_count`): a draft changed meanwhile is refused (409).
     *
     * @return list<array<string, mixed>>
     */
    private static function editedLines(Context $ctx, string $kind, int $id): array
    {
        $req = $ctx->req;
        $current = $ctx->db->all('SELECT line_no, sku_id, qty, unit_cost, reason_code, description FROM document_line WHERE document_id = ? AND sku_id IS NOT NULL ORDER BY line_no',
            [$id]);
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
            if (preg_match('/^[+-]?[0-9]{1,8}$/D', $u) !== 1) {
                throw new CwException($kind === 'adjust' ? 'qty_nonzero' : 'qty_positive', 'the units are a whole number', 422, ['line' => $n]);
            }
            $cost = $req->field('c_' . $n);
            $cost = $cost === null ? ($l['unit_cost'] === null ? null : (string) $l['unit_cost']) : (trim($cost) === '' ? null : str_replace(['£', ','], '', trim($cost)));
            $reason = $req->field('r_' . $n);
            $out[] = ['sku_id' => (int) $l['sku_id'], 'qty' => (int) $u, 'unit_cost' => $cost,
                'reason_code' => $reason === null ? ($l['reason_code'] === null ? null : (string) $l['reason_code']) : ($reason === '' ? null : $reason),
                'description' => $l['description'] === null ? null : (string) $l['description']];
        }
        return $out;
    }

    /** @return list<string|int> the words of the "added" notice: the product, the units, the line */
    private static function addedArgs(Context $ctx): array
    {
        $id = $ctx->id();
        $line = UiRequest::id($ctx->req->param('line')) ?? 0;
        $units = (int) ($ctx->req->param('units') ?? 0);
        $name = (string) ($ctx->db->value('SELECT s.name FROM document_line l JOIN sku s ON s.id = l.sku_id WHERE l.document_id = ? AND l.line_no = ?', [$id, $line]) ?? '');
        return $ctx->req->param('notice') === 'added' ? [$name, $units, $line] : [$units, $line, $name];
    }
}
