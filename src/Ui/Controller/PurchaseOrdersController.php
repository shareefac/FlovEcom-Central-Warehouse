<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Auth\Permissions;
use CW\Company\CompanyDetails;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\OpResult;
use CW\Output\CsvWriter;
use CW\PurchaseOrders\PoLinesFile;
use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrderPdf;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\PoWarnings;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * The purchase order screens (IM5, Phase I-2; docs/decisions.md I48-I59, spec §8.1, §8.3): the list (filters, CSV, the
 * "new order" form), the ONE-FORM editor of a draft (header, a scan / search box whose Enter adds a line and saves every
 * edit made in the table, the lines, a charge), the posted order's page (its state, review and history, the decide box,
 * send / cancel / amend / copy / close), the lines file (CSV, XLSX: export and an all-or-nothing import) and the PDF.
 *
 * Everyone with purchasing.view looks; buyers (doc.PO.post, checked again by CW\PurchaseOrders\PurchaseOrders) act; the
 * creator alone edits a draft (others see it read-only); reviewers decide on the order's page (the forms post to
 * /ui/documents/reviews/{task}/..., which come back here). Creating forms (new, copy, amend) carry a FormOnce key; every
 * other form carries the version it was drawn with (409: the page is redrawn with the current data). The editor carries
 * `version`, `line_count` and `lines_editable` FIRST and refuses a form with fewer line rows than it says (400
 * form_truncated: PHP drops fields past max_input_vars = 1000 without a word; Kernel also refuses any POST that arrives
 * with max_input_vars fields). The editor is offered only while its fields stay below max_input_vars (editorFits(): about
 * 240 lines with supplier items, I73); a larger order is shown read-only and edited by the file import. Approve is a
 * button of the editor form: it saves what is typed and approves exactly that, in one transaction.
 */
final class PurchaseOrdersController
{
    /** The notices named in a redirect (their words: Words::PO_NOTICE; `approved`, `submitted`, `cancelled_posted` name a number). */
    public const NOTICES = Words::PO_NOTICE;
    /** The status filter (the URL values stay; their words: Words::PO_FILTER). */
    public const STATE_FILTERS = Words::PO_FILTER;
    /** The states the CSV writes (unchanged: a file for Excel and the accounts). */
    private const CSV_STATE = ['draft' => 'draft', 'awaiting_approval' => 'awaiting approval', 'approved' => 'approved', 'sent' => 'sent',
        'part_received' => 'part-received', 'received' => 'received', 'closed' => 'closed', 'cancelled' => 'cancelled'];
    public const LIST_LIMIT = 500;
    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    /**
     * The editor's fields outside its line rows, with room to spare: csrf, version, line_count, lines_editable, supplier_id,
     * 4 header fields, q, packs, the pressed button, a choice button, 3 charge fields (15-17 in fact).
     */
    public const EDITOR_FIXED_FIELDS = 24;

    /**
     * The most fields the editor form of these lines sends: an item line with a supplier item 4 (packs, price, VAT, note),
     * without one 6 (+ units per pack, "save"), a charge 3 (packs, price, note); plus EDITOR_FIXED_FIELDS.
     *
     * @param list<array<string, mixed>> $lines po_line rows (kind, supplier_item_id)
     */
    public static function editorFields(array $lines): int
    {
        $n = self::EDITOR_FIXED_FIELDS;
        foreach ($lines as $l) {
            $n += ($l['kind'] ?? 'item') === 'charge' ? 3 : (($l['supplier_item_id'] ?? null) === null ? 6 : 4);
        }
        return $n;
    }

    /** @param list<array<string, mixed>> $lines Whether the one-form editor can carry these lines (I73). */
    public static function editorFits(array $lines): bool
    {
        return count($lines) <= PurchaseOrders::MAX_EDITOR_LINES && self::editorFields($lines) < UiRequest::maxInputVars();
    }

    /** About how many lines with supplier items the editor carries on this server (the read-only notice). */
    public static function editorMaxLines(): int
    {
        return min(PurchaseOrders::MAX_EDITOR_LINES, intdiv(UiRequest::maxInputVars() - 1 - self::EDITOR_FIXED_FIELDS, 4));
    }

    // ------------------------------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------------------------------

    public function index(Context $ctx): HtmlResponse
    {
        return $this->listPage($ctx, 200, null, null);
    }

    /** The list, also after a refused "Start a new order" ($error shown, $formKey kept). */
    private function listPage(Context $ctx, int $status, ?string $error, ?string $formKey): HtmlResponse
    {
        $me = $ctx->me();
        $draftsOff = self::draftsOffByDefault($me->roles);
        $f = self::filters($ctx->req, $draftsOff);
        $canPost = Documents::mayPost($me->roles, 'PO');
        $rows = array_map(static fn (array $r): array => self::screenRow($r, $canPost), $this->rows($ctx, $f, self::LIST_LIMIT));
        return $ctx->page('purchase_orders', [
            'rows' => $rows,
            'filters' => $f,
            'filtered' => $f['state'] !== null || $f['supplier'] !== null || $f['q'] !== '' || $f['rejected'] || $f['all'],
            // Behaviour item 8 (F305, provisional): goods-in and the purchasing desk do not see drafts unless they ask for them.
            'draftsChoice' => $draftsOff,
            'draftsHidden' => !$f['drafts'] && $f['state'] === null,
            'states' => self::STATE_FILTERS,
            'suppliers' => $ctx->db->all("SELECT id, code, name, status FROM supplier ORDER BY name, id"),
            'newSuppliers' => $canPost ? $ctx->db->all("SELECT id, code, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id") : [],
            'formKey' => $canPost ? ($formKey ?? FormOnce::newKey()) : null,
            'canPost' => $canPost,
            'lookOnly' => $canPost ? null : Words::whoCan('doc.PO.post'),
            'limit' => self::LIST_LIMIT,
            // null while the owner has the OK first of orders switched off (Approval rules page, 0019, Y10).
            'approvalLimit' => $ctx->documents()->typeInfo('PO')['approval_rule'] === 'none' ? null
                : Html::money((int) $ctx->documents()->typeInfo('PO')['approval_limit_units']),
            'error' => $error,
        ], $status, ['title' => Words::MENU['orders'], 'active' => 'orders']);
    }

    /**
     * A row of the list as people read it (plan F296-F305, design B's list cards): the supplier's name first, the number (or "no
     * number yet") under it, the status chip and the next step, the dates, the size, the total in £, the reviewer check.
     *
     * @param array<string, mixed> $r a rows() row
     * @return array<string, mixed>
     */
    public static function screenRow(array $r, bool $canPost): array
    {
        $cancellation = $r['reverses_id'] !== null;
        $code = self::rowState($r);
        $next = match (true) {
            $cancellation => Words::say('ORDERS', 'next_cancellation', (string) ($r['reverses_number'] ?? '')),
            $r['review_state'] === 'rejected' && in_array($code, ['approved', 'sent'], true) => Words::ORDERS['next_rejected'],
            $code === 'draft' => Words::ORDERS[$canPost ? 'next_draft' : 'next_draft_look'],
            $code === 'awaiting_approval' => Words::ORDERS['next_awaiting'],
            $code === 'approved' => Words::ORDERS[$canPost ? 'next_approved' : 'next_approved_look'],
            $code === 'sent' => Words::ORDERS['next_sent'],
            $code === 'part_received' => Words::ORDERS['next_part'],
            $code === 'received' => Words::ORDERS['next_received'],
            $code === 'closed' => Words::ORDERS['next_closed'],
            $r['status'] === 'reversed' && $r['cancelled_by'] !== null => Words::say('ORDERS', 'next_cancelled', Html::day((string) $r['cancelled_on']),
                (string) $r['cancelled_by']),
            default => Words::ORDERS['next_cancelled_draft'],
        };
        $lines = (int) $r['lines'];
        return $r + [
            'name' => $r['supplier_name'] ?? Words::ORDER['some_supplier'],
            'number_line' => $cancellation ? Words::say('ORDERS', 'cancels', (string) ($r['reverses_number'] ?? '')) . ' · ' . ($r['number'] ?? Words::ORDERS['no_number_yet'])
                : ($r['number'] ?? Words::ORDERS[$r['status'] === 'draft' ? 'no_number' : 'no_number_yet']),
            'state_code' => $code,
            'state_word' => $cancellation ? Words::ORDERS['cancellation'] : Words::of('PO_STATE', $code),
            'state_tone' => $cancellation ? 'off' : Words::tone('PO_STATE', $code),
            'next' => $next,
            'date_line' => $r['doc_date'] === null ? null : Words::say('ORDERS', $r['number'] === null ? 'started' : 'dated', Html::day((string) $r['doc_date'])),
            'expected_line' => $r['expected_date'] === null ? null : Words::say('ORDERS', 'expected', Html::day((string) $r['expected_date'])),
            'size_line' => $lines === 1 ? Words::ORDERS['products_one'] : Words::say('ORDERS', 'products_many', $lines),
            'items_line' => Words::say('ORDERS', 'items', (int) $r['units']),
            'total' => $cancellation ? null : Html::money($r['net_total']),
            'check' => $r['review_state'] === null ? null : Words::of('CHECK_STATE', (string) $r['review_state']),
            'check_tone' => Words::tone('REVIEW_STATE', $r['review_state'] === null ? null : (string) $r['review_state']),
            'sent_line' => $r['sent_at'] === null ? null : Words::say('ORDERS', 'sent_on', Html::when((string) $r['sent_at']), lcfirst(Words::of('SEND_VIA', (string) $r['sent_via']))),
        ];
    }

    /** The PO_STATE code of a list row: the document's status until it is posted, then the order's own state. @param array<string, mixed> $r */
    private static function rowState(array $r): string
    {
        return match ((string) $r['status']) {
            'draft', 'awaiting_approval' => (string) $r['status'],
            'cancelled', 'reversed' => 'cancelled',
            default => isset(Words::PO_STATE[(string) ($r['state'] ?? '')]) ? (string) $r['state'] : 'approved',
        };
    }

    public function csv(Context $ctx): HtmlResponse
    {
        $csv = new CsvWriter([['number', 'text'], ['document_id', 'number'], ['supplier_code', 'text'], ['supplier', 'text'], ['order_date', 'text'],
            ['expected_date', 'text'], ['state', 'text'], ['lines', 'number'], ['units', 'number'], ['net', 'number'], ['vat', 'number'], ['gross', 'number'],
            ['review', 'text'], ['sent_at', 'text'], ['sent_via', 'text'], ['external_ref', 'text'], ['source', 'text'], ['created_by', 'text']]);
        foreach ($this->rows($ctx, self::filters($ctx->req), null) as $r) {
            $csv->add([$r['label'], (int) $r['id'], $r['supplier_code'], $r['supplier_name'], $r['doc_date'], $r['expected_date'], $r['state_label'], (int) $r['lines'],
                (int) $r['units'], $r['net_total'], $r['vat_total'], $r['gross_total'], $r['review_state'], $r['sent_at'], $r['sent_via'], $r['external_ref'],
                $r['source'], $r['created_by_name']]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'orders.csv');
    }

    /** POST /ui/purchasing/orders: a new draft for a supplier (FormOnce: one draft however often the form is sent). */
    public function create(Context $ctx): HtmlResponse
    {
        $sid = UiRequest::id($ctx->req->field('supplier_id'));
        try {
            if ($sid === null) {
                throw new CwException('choose_supplier', 'choose the supplier', 400, ['field' => 'supplier_id']);
            }
            $r = FormOnce::run($ctx, 'ui.po.create', ['supplier_id' => $sid], function (Db $db) use ($ctx, $sid): OpResult {
                $d = $this->service($ctx)->createDraft($ctx->caller(), $sid, []);
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id,
                    'redirect' => Html::url('/ui/purchasing/orders/' . $d->id, ['notice' => 'created'])]);
            });
        } catch (CwException $e) {
            return $this->listPage($ctx, $e->httpStatus, self::plain($e), $ctx->req->field(FormOnce::FIELD));
        }
        return FormOnce::redirect($r);
    }

    // ------------------------------------------------------------------------------------------
    // One order
    // ------------------------------------------------------------------------------------------

    public function show(Context $ctx): HtmlResponse
    {
        $doc = $ctx->documents()->find($ctx->id());
        if ($doc === null || $doc->docType !== 'PO') {
            return self::notFound($ctx);
        }
        if ($doc->reversesId !== null) {
            return HtmlResponse::redirect(Html::url('/ui/purchasing/orders/' . $doc->reversesId, ['notice' => self::noticeKey($ctx)]));
        }
        return $this->page($ctx, $doc->id, 200, null, $this->notice($ctx, $doc));
    }

    /** The notice named in the URL, with the number it names (F275, F279, F283). */
    private function notice(Context $ctx, Document $doc): ?string
    {
        $key = self::noticeKey($ctx);
        return match ($key) {
            null => null,
            'approved' => Words::say('PO_NOTICE', 'approved', (string) ($doc->number ?? '')),
            'submitted' => Words::say('PO_NOTICE', 'submitted', Html::money((int) $ctx->documents()->typeInfo('PO')['approval_limit_units'])),
            'cancelled_posted' => Words::say('PO_NOTICE', 'cancelled_posted',
                (string) ($ctx->db->value("SELECT number FROM document WHERE reverses_id = ? AND number IS NOT NULL ORDER BY id DESC LIMIT 1", [$doc->id]) ?? '')),
            default => Words::PO_NOTICE[$key],
        };
    }

    private static function notFound(Context $ctx): HtmlResponse
    {
        return $ctx->error(404, 'unknown_purchase_order', Words::BUY_ERROR['unknown_purchase_order'], ['/ui/purchasing/orders', Words::MENU['orders']]);
    }

    /**
     * A refusal of the purchase-order services in the page's words, by its error code (plan rule 18: the service's message stays
     * the API's). A code without words: the service's message, then "Nothing was saved."
     */
    public static function plain(CwException $e): string
    {
        $code = $e->errorCode;
        return match (true) {
            // The page's own refusals are made in words already.
            in_array($code, ['scan_pending', 'not_found', 'import_refused', 'no_file', 'upload_failed'], true) => $e->getMessage(),
            // The upload limit is this page's own refusal; any other too_large is the spreadsheet reader's (a sheet too big inside).
            $code === 'too_large' && $e->getMessage() === Words::say('BUY_ERROR', 'too_large', intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576)) => $e->getMessage(),
            $code === 'too_large' => Words::BUY_ERROR['too_large_inside'],
            $code === 'note_required' => Words::noteRequired($e->detail),
            $code === 'bad_line' && is_int($e->detail['line'] ?? null) && isset(Words::LINE_FIELD[$e->detail['field'] ?? ''])
                => Words::say('BUY_ERROR', 'line_field', $e->detail['line'], sprintf(Words::LINE_FIELD[(string) $e->detail['field']],
                    Html::money(intdiv(PoMath::MAX_PACK_PRICE_E4, 10_000)))),
            isset(Words::ERROR[$code]) && !str_contains(Words::ERROR[$code], '%') => Words::ERROR[$code],
            $code === 'too_many_lines' => Words::say('BUY_ERROR', 'too_many_lines', PurchaseOrders::MAX_LINES),
            $code === 'bad_line', $code === 'bad_field', $code === 'bad_price' => Words::say('BUY_ERROR', 'other', self::sentence($e->getMessage())),
            isset(Words::BUY_ERROR[$code]) && !in_array($code, ['other', 'nothing_ordered', 'supplier_incomplete', 'scan_pending', 'not_found'], true) => Words::BUY_ERROR[$code],
            default => Words::say('BUY_ERROR', 'other', self::sentence($e->getMessage())),
        };
    }

    /** "line 2, packs: a whole number" -> "Line 2, packs: a whole number." */
    public static function sentence(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
        return preg_match('/[.!?]$/u', $s) === 1 ? $s : $s . '.';
    }

    /** The editor's one form (spec §8.3): saves every edit, then adds what was scanned / searched / chosen. */
    public function lines(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $version = UiRequest::id($req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', Words::ERROR['bad_version']);
        }
        $count = $req->field('line_count');
        $editable = $req->field('lines_editable') === '1';
        if ($count === null || preg_match('/^(0|[1-9][0-9]{0,4})$/D', $count) !== 1) {
            return $ctx->error(400, 'form_truncated', Words::ERROR['bad_form']);
        }
        $count = (int) $count;
        $rows = $req->fieldsMatching('/^line_[1-9][0-9]{0,4}_packs$/');
        if ($editable && ($count > PurchaseOrders::MAX_EDITOR_LINES || count($rows) < $count)) {
            return $ctx->error(400, 'form_truncated', Words::say('ORDER', 'too_many', self::editorMaxLines()));
        }
        $svc = $this->service($ctx);
        $typed = $req->post;
        $choices = null;
        $q = trim($req->field('q') ?? '');
        try {
            $current = $svc->lines($id);
            $lines = $editable ? self::editedLines($req, $current, $count) : $current;
            $charge = trim($req->field('charge_amount') ?? '');
            if ($charge !== '') {
                $lines[] = ['kind' => 'charge', 'description' => $req->field('charge_description'), 'pack_price' => $charge,
                    'vat_code' => ($req->field('charge_vat') ?? '') === '' ? null : $req->field('charge_vat')];
            }
            $header = [];
            foreach (['expected_date', 'external_ref', 'note', 'doc_date', 'supplier_id'] as $k) {
                $v = $req->field($k);
                if ($v !== null && ($k !== 'supplier_id' || $v !== '')) {
                    $header[$k] = $v;
                }
            }
            $packs = PoLinesFile::wholeNumber($req->field('packs') ?? '1') ?? 0;
            $addSku = UiRequest::id($req->field('add_sku'));
            $addSi = UiRequest::id($req->field('add_si'));
            $approving = $req->field('action') === 'approve' && $addSku === null && $addSi === null;
            if ($approving && $q !== '') {
                return $this->page($ctx, $id, 422, new CwException('scan_pending', Words::say('BUY_ERROR', 'scan_pending', $q), 422), null, ['typed' => $typed, 'q' => $q]);
            }
            $adding = $addSku !== null || $addSi !== null || ($req->field('action') === 'add' && $q !== '');
            $result = $ctx->db->transaction(function () use ($ctx, $svc, $id, $version, $header, $lines, $adding, $approving, $addSku, $addSi, $q, $packs): array {
                $doc = $svc->saveDraft($ctx->caller(), $id, $version, $header, $lines);
                if ($approving) {
                    // Review finding: "Approve" sits in the editor form, so what is approved is what the buyer sees, typed
                    // edits included; save and approval are one transaction (a refused approval saves nothing, and the
                    // page comes back with what was typed).
                    $d = $svc->approve($ctx->caller(), $id, $doc->version);
                    return ['status' => $d->status === 'awaiting_approval' ? 'submitted' : 'approved', 'document' => $d];
                }
                if (!$adding) {
                    return ['status' => 'saved', 'document' => $doc];
                }
                if ($addSi !== null) {
                    return $svc->addSupplierItem($ctx->caller(), $id, $doc->version, $addSi, max(1, $packs));
                }
                $code = $addSku !== null ? (string) ($ctx->db->value('SELECT code FROM sku WHERE id = ?', [$addSku]) ?? '') : $q;
                return $svc->addLine($ctx->caller(), $id, $doc->version, $code, max(1, $packs));
            });
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->page($ctx, $id, 409, new CwException('version_conflict', Words::BUY_ERROR['version_conflict'], 409));
            }
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['typed' => $typed]);
        }
        if ($result['status'] === 'choices') {
            $choices = $result['choices'];
            return $this->page($ctx, $id, 200, null, null, ['choices' => $choices, 'q' => $q, 'packs' => max(1, $packs)]);
        }
        if ($result['status'] === 'not_found') {
            return $this->page($ctx, $id, 422, new CwException('not_found', Words::say('BUY_ERROR', 'not_found', $q), 422), null, ['q' => $q]);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/orders/' . $id, ['notice' => $result['status']])
            . (in_array($result['status'], ['approved', 'submitted'], true) ? '' : '#scan'));
    }

    /** The lines file import (multipart `file`, `mode` append | replace, `version`): all or nothing. */
    public function import(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $version = UiRequest::id($ctx->req->field('version'));
        try {
            $file = $ctx->req->file('file');
            if ($file === null) {
                throw new CwException('no_file', Words::BUY_ERROR['no_file'], 400, ['field' => 'file']);
            }
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > UiRequest::MAX_UPLOAD_BYTES) {
                throw new CwException('too_large', Words::say('BUY_ERROR', 'too_large', intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576)), 413);
            }
            if ($file['error'] !== UPLOAD_ERR_OK || $file['path'] === '') {
                throw new CwException('upload_failed', Words::BUY_ERROR['upload_failed'], 400);
            }
            $r = $this->service($ctx)->importLines($ctx->caller(), $id, $version ?? 0, $file['path'], $file['name'], $ctx->req->field('mode') ?? '');
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        if ($r['errors'] !== []) {
            $n = count($r['errors']);
            return $this->page($ctx, $id, 422, new CwException('import_refused', $n === 1 ? Words::BUY_ERROR['import_refused_one'] : Words::say('BUY_ERROR', 'import_refused', $n),
                422), null, ['importErrors' => array_map(PoWarnings::fileRow(...), $r['errors'])]);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/orders/' . $id, ['notice' => 'imported']));
    }

    public function linesCsv(Context $ctx): HtmlResponse
    {
        $doc = $this->poDoc($ctx);
        if ($doc === null) {
            return self::notFound($ctx);
        }
        return FilesController::download(PoLinesFile::csv($this->service($ctx)->exportRows($doc->id)), 'text/csv; charset=utf-8', self::fileName($doc) . '-lines.csv');
    }

    public function linesXlsx(Context $ctx): HtmlResponse
    {
        $doc = $this->poDoc($ctx);
        if ($doc === null) {
            return self::notFound($ctx);
        }
        return FilesController::download(PoLinesFile::xlsx($this->service($ctx)->exportRows($doc->id), self::fileName($doc)), self::XLSX, self::fileName($doc) . '-lines.xlsx');
    }

    public function pdf(Context $ctx): HtmlResponse
    {
        $doc = $ctx->documents()->find($ctx->id());
        if ($doc === null || $doc->docType !== 'PO') {
            return self::notFound($ctx);
        }
        $bytes = (new PurchaseOrderPdf())->render($this->service($ctx)->pdfData($doc->id));
        return FilesController::download($bytes, 'application/pdf', self::fileName($doc) . '.pdf');
    }

    public function approve(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, function (PurchaseOrders $svc, int $id, int $version) use ($ctx): string {
            $d = $svc->approve($ctx->caller(), $id, $version);
            return $d->status === 'awaiting_approval' ? 'submitted' : 'approved';
        });
    }

    public function send(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, function (PurchaseOrders $svc, int $id, int $version) use ($ctx): string {
            $svc->markSent($ctx->caller(), $id, $version, $ctx->req->field('via') ?? '', $ctx->req->field('to'), $ctx->req->field('send_anyway') === '1');
            // With a file store markSent archives the PDF or fails: there is no third outcome.
            return $this->files($ctx) === null ? 'sent_unarchived' : 'sent_archived';
        });
    }

    public function cancel(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, function (PurchaseOrders $svc, int $id, int $version) use ($ctx): string {
            $d = $svc->cancel($ctx->caller(), $id, $version, $ctx->req->field('reason_code') ?? '', $ctx->req->field('note'));
            return $d->status === 'reversed' ? 'cancelled_posted' : 'cancelled';
        });
    }

    public function close(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, function (PurchaseOrders $svc, int $id, int $version) use ($ctx): string {
            $svc->close($ctx->caller(), $id, $version, $ctx->req->field('reason') ?? '');
            return 'closed';
        });
    }

    public function withdraw(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, function (PurchaseOrders $svc, int $id, int $version) use ($ctx): string {
            $svc->withdraw($ctx->caller(), $id, $version);
            return 'withdrawn';
        });
    }

    /** POST amend (FormOnce): the order's cancellation and a new draft copied from it; lands on the new draft. */
    public function amend(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $reason = $ctx->req->field('reason_code') ?? '';
        $note = $ctx->req->field('note');
        try {
            $r = FormOnce::run($ctx, 'ui.po.amend', ['id' => $id, 'reason_code' => $reason, 'note' => $note], function (Db $db) use ($ctx, $id, $reason, $note): OpResult {
                $new = $this->service($ctx)->amend($ctx->caller(), $id, $reason, $note);
                return OpResult::of(303, ['result' => 'amended', 'document_id' => $new->id, 'redirect' => Html::url('/ui/purchasing/orders/' . $new->id, ['notice' => 'amended'])]);
            });
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    /** POST copy (FormOnce): a new draft from this order. */
    public function copy(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            $r = FormOnce::run($ctx, 'ui.po.copy', ['id' => $id], function (Db $db) use ($ctx, $id): OpResult {
                $new = $this->service($ctx)->copy($ctx->caller(), $id);
                return OpResult::of(303, ['result' => 'copied', 'document_id' => $new->id, 'redirect' => Html::url('/ui/purchasing/orders/' . $new->id, ['notice' => 'copied'])]);
            });
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    // ------------------------------------------------------------------------------------------

    /**
     * The order's page: the editor for its creator while it is a draft, else the read-only view with the actions the
     * person may use; also after a refused form (the error shown, answered under its status). $extra: typed (the posted
     * values to show again), choices + q + packs (an ambiguous search), importErrors.
     *
     * @param array<string, mixed> $extra
     */
    public function page(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice = null, array $extra = []): HtmlResponse
    {
        $docs = $ctx->documents();
        $doc = $docs->find($id);
        if ($doc === null || $doc->docType !== 'PO') {
            return self::notFound($ctx);
        }
        if ($doc->reversesId !== null) {
            $doc = $docs->get($doc->reversesId);
        }
        $svc = $this->service($ctx);
        $db = $ctx->db;
        $me = $ctx->me();
        $po = $svc->headerRow($doc->id) ?? [];
        $supplier = $db->one('SELECT * FROM supplier WHERE id = ?', [(int) ($po['supplier_id'] ?? 0)]) ?? [];
        $supplierName = (string) ($supplier['name'] ?? Words::ORDER['some_supplier']);
        $canPost = Documents::mayPost($me->roles, 'PO');
        $editor = $doc->status === 'draft' && $canPost && $doc->createdBy === $me->id;
        $lines = $db->all(
            'SELECT pl.*, dl.sku_id, dl.qty, dl.unit_cost, dl.amount, dl.description, s.code AS sku_code, s.name AS sku_name, s.merged_into_sku_id, '
            . 'si.supplier_code AS si_code, si.last_pack_price, si.last_po_pack_price, si.is_active AS si_active FROM po_line pl '
            . 'JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no LEFT JOIN sku s ON s.id = dl.sku_id '
            . 'LEFT JOIN supplier_item si ON si.id = pl.supplier_item_id WHERE pl.document_id = ? ORDER BY pl.line_no',
            [$doc->id],
        );
        $skus = array_values(array_unique(array_filter(array_map(static fn (array $l): int => (int) ($l['sku_id'] ?? 0), $lines))));
        $stock = [];
        if ($editor && $skus !== []) {
            foreach ($db->all('SELECT b.sku_id, SUM(b.on_hand - b.allocated - b.held) AS a FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id AND w.is_sellable = 1 '
                . 'WHERE b.sku_id IN (' . implode(', ', array_fill(0, count($skus), '?')) . ') GROUP BY b.sku_id', $skus) as $r) {
                $stock[(int) $r['sku_id']] = (int) $r['a'];
            }
        }
        $onOrder = $editor && $skus !== [] ? $svc->onOrder($skus) : [];
        $units = 0;
        $products = [];
        foreach ($lines as &$l) {
            $l['pack_label'] = $l['kind'] === 'charge' ? '' : self::pack((string) $l['purchase_unit'], (int) $l['units_per_pack']);
            $l['net'] = PoMath::money(PoMath::e2((string) $l['amount']));
            $l['price'] = PoMath::price((string) $l['pack_price']);
            $l['stock'] = $l['sku_id'] === null ? null : ($stock[(int) $l['sku_id']] ?? 0);
            $l['on_order'] = $l['sku_id'] === null ? null : ($onOrder[(int) $l['sku_id']] ?? 0);
            $l['code'] = $l['supplier_code'] ?? $l['si_code'];
            $units += (int) ($l['qty'] ?? 0);
            if ($l['sku_code'] !== null) {
                $products[(string) $l['sku_code']] = (string) $l['sku_name'];
            }
        }
        unset($l);
        $netE2 = PoMath::e2((string) ($po['net_total'] ?? '0'));
        $totals = PoMath::totals(array_map(static fn (array $l): array => ['amount_e2' => PoMath::e2((string) $l['amount']), 'vat_code' => (string) $l['vat_code'],
            'rate_e2' => PoMath::e2((string) $l['vat_rate'])], $lines));
        $vatLabels = [];
        foreach ($db->all('SELECT code, label FROM vat_code') as $v) {
            $vatLabels[(string) $v['code']] = (string) $v['label'];
        }
        $type = $docs->typeInfo('PO');
        $limit = (int) $type['approval_limit_units'];
        $okFirst = $type['approval_rule'] !== 'none'; // the owner may switch the OK first off (Approval rules page, Y10)
        $state = self::stateCode($doc, $po);
        $data = [
            'doc' => $doc,
            'po' => $po,
            'supplier' => $supplier,
            'supplierName' => $supplierName,
            'title' => self::title($doc, $supplierName),
            'lines' => $lines,
            'units' => $units,
            'totals' => ['net' => PoMath::money($netE2), 'vat' => PoMath::money(PoMath::e2((string) ($po['vat_total'] ?? '0'))),
                'gross' => PoMath::money(PoMath::e2((string) ($po['gross_total'] ?? '0'))),
                'by_code' => array_map(static fn (array $v): array => ['rate' => rtrim(rtrim(PoMath::fromE2($v['rate_e2']), '0'), '.'), 'vat' => PoMath::money($v['vat_e2'])],
                    $totals['by_code'])],
            'vatLabels' => $vatLabels,
            'state' => $state,
            'warnings' => in_array($doc->status, ['draft', 'awaiting_approval'], true) ? PoWarnings::plain($svc->warnings($doc->id), $supplierName, $products) : [],
            'companyNote' => self::companyNote($ctx, $po),
            'limit' => $okFirst ? Html::money($limit) : null,
            'overLimit' => $okFirst && $netE2 > 0 && PoMath::approvalUnits($netE2) > $limit,
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
            // A cancel or correct sent without a reason (behaviour item 6): that form opens again at its list.
            'reasonError' => $error?->errorCode === 'bad_reason' ? (str_ends_with($ctx->req->path, '/amend') ? 'amend' : 'cancel') : null,
            'importErrors' => $extra['importErrors'] ?? [],
            'fileName' => self::fileName($doc),
        ];
        $layout = ['title' => $data['title'], 'active' => 'orders', 'notice' => $notice];
        if ($editor) {
            $count = count($lines);
            $data += [
                'editable' => self::editorFits($lines),
                'maxLines' => self::editorMaxLines(),
                'typed' => $extra['typed'] ?? [],
                'choices' => $extra['choices'] ?? null,
                'q' => $extra['q'] ?? '',
                'packs' => (int) ($extra['packs'] ?? 1),
                'vatCodes' => $db->all('SELECT code, label FROM vat_code WHERE is_active = 1 ORDER BY sort_order, code'),
                'suppliers' => $count === 0 ? $db->all("SELECT id, code, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id") : [],
                'today' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d'),
                'fileColumns' => array_values(array_diff(PoLinesFile::COLUMNS, ['line', 'item_name'])),
                'maxMb' => intdiv(PoLinesFile::MAX_BYTES, 1_048_576),
                'maxRows' => PoLinesFile::MAX_ROWS,
                'cancelReasons' => self::reasonChoices($db, 'draft_cancel'),
            ];
            return $ctx->page('purchase_order_edit', $data, $status, $layout);
        }
        return $ctx->page('purchase_order', $data + $this->viewData($ctx, $doc, $po, $canPost, $supplierName), $status, $layout);
    }

    /**
     * The reasons an order form offers (review finding I6, Y51): from the Reasons page (PurchaseOrders::reasons: where it is used,
     * switched on), each with its own name; Words::REASON only when a reason has no name to show.
     *
     * @return list<array{code: string, label: string}>
     */
    public static function reasonChoices(\CW\Db $db, string $kind): array
    {
        return array_map(static fn (array $r): array => ['code' => $r['code'], 'label' => self::reasonLabel($r['code'], $r['label'])],
            PurchaseOrders::reasons($db, PurchaseOrders::REASON_USES[$kind]));
    }

    /** A reason's name as people read it: its label from the Reasons page, the Words fallback only when it has none. */
    public static function reasonLabel(string $code, ?string $label): string
    {
        $label = trim((string) $label);
        return $label !== '' ? $label : Words::of('REASON', $code);
    }

    /** "PO-000123 – Elux Wholesale", "New order for Elux Wholesale (draft, no PO number yet)", "Order for … (no number yet)" (F259, F359). */
    public static function title(Document $doc, string $supplier): string
    {
        return match (true) {
            $doc->number !== null => Words::say('ORDER', 'title', $doc->number, $supplier),
            $doc->status === 'draft' => Words::say('ORDER', 'title_draft', $supplier),
            default => Words::say('ORDER', 'title_awaiting', $supplier),
        };
    }

    /** A pack as people read it: "each", "box of 24". */
    public static function pack(string $unit, int $upp): string
    {
        return $upp === 1 && $unit === 'each' ? Words::SUPPLIER_ITEMS['each'] : Words::say('SUPPLIER_ITEMS', 'pack_of', $unit, $upp);
    }

    /**
     * What the read-only view adds: people, the cancellation and amendment links, the review tasks (the order's and its
     * cancellation's) as sentences, the decide boxes, the actions this person may use, the files.
     *
     * @param array<string, mixed> $po
     * @return array<string, mixed>
     */
    private function viewData(Context $ctx, Document $doc, array $po, bool $canPost, string $supplierName): array
    {
        $db = $ctx->db;
        $me = $ctx->me();
        $rev = $db->one("SELECT * FROM document WHERE reverses_id = ? AND status <> 'cancelled'", [$doc->id]);
        $revDoc = $rev === null ? null : Document::fromRow($rev);
        $ids = array_values(array_filter([$doc->id, $revDoc?->id]));
        $tasks = $db->all(
            'SELECT t.*, o.display_name AS opened_by_name, x.display_name AS decided_by_name FROM review_task t '
            . 'LEFT JOIN staff_user o ON o.id = t.opened_by LEFT JOIN staff_user x ON x.id = t.decided_by '
            . "WHERE t.subject_type = 'document' AND t.subject_id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY t.id',
            $ids,
        );
        $now = gmdate('Y-m-d H:i:s');
        $limit = (int) $ctx->documents()->typeInfo('PO')['approval_limit_units'];
        $decide = [];
        $rejected = null;
        foreach ($tasks as &$t) {
            $t['overdue'] = $t['state'] === 'open' && (string) $t['due_at'] < $now;
            $ofCancel = (int) $t['subject_id'] !== $doc->id;
            $t['of_cancel'] = $ofCancel ? ($revDoc?->number ?? Words::ORDER['cancel_waiting']) : null;
            if ($t['state'] === 'open' && ($me->can('documents.review') || $me->can('documents.approve'))) {
                $subject = $ofCancel ? $revDoc : $doc;
                $no = $subject === null ? null : Documents::refusal($me->id, $me->roles, $subject, (string) $t['kind']);
                $approval = $t['kind'] === 'approval';
                $reversal = $subject !== null && $subject->isReversal();
                $by = Html::day((string) $t['due_at']);
                $decide[] = [
                    'task' => $t,
                    'refusal' => Words::refusal($no),
                    'title' => Words::ORDER[$approval ? 'approval_title' : 'review_title'],
                    'text' => match (true) {
                        $approval => Words::say('ORDER', 'approval_text', Html::money($po['net_total'] ?? null), Html::money($limit), $by),
                        $reversal => Words::say('ORDER', 'review_cancel_text', (string) ($subject->number ?? ''), $by),
                        default => Words::say('ORDER', 'review_text', $by),
                    },
                    'ok' => Words::ORDER[$approval ? 'ok_approval' : 'ok_review'],
                    'okDoes' => Words::ORDER[$approval ? 'does_ok_approval' : 'does_ok_review'],
                    'notOkDoes' => Words::ORDER[$approval ? 'does_not_ok_approval' : ($reversal ? 'does_not_ok_cancel' : 'does_not_ok_review')],
                    // Saying no to a request books nothing: the safer answer when in doubt (design B).
                    'safer' => $approval,
                ];
            }
            if ($t['state'] === 'rejected' && $t['kind'] === 'review' && !$ofCancel) {
                $rejected = $t;
            }
        }
        unset($t);
        $names = [];
        foreach ($db->all('SELECT id, display_name FROM staff_user WHERE id IN (?, ?, ?, ?, ?, ?)', [$doc->createdBy ?? 0, $doc->submittedBy ?? 0, $doc->postedBy ?? 0,
            $doc->cancelledBy ?? 0, (int) ($po['sent_by'] ?? 0), (int) ($po['closed_by'] ?? 0)]) as $u) {
            $names[(int) $u['id']] = (string) $u['display_name'];
        }
        $name = static fn (mixed $uid): ?string => $uid === null ? null : ($names[(int) $uid] ?? Words::ANOMALIES['set_up']);
        $state = $po['state'] ?? null;
        $received = (int) $db->value('SELECT COALESCE(SUM(received_units), 0) FROM po_line WHERE document_id = ?', [$doc->id]);
        $posted = $doc->status === 'posted';

        $cancellable = $canPost && (($posted && $received === 0 && !in_array($state, ['received', 'closed'], true)) || $doc->status === 'draft'
            || ($doc->status === 'awaiting_approval' && $doc->submittedBy === $me->id));
        $send = $canPost && $posted && in_array($state, ['approved', 'sent'], true) ? PoWarnings::send($this->service($ctx)->sendWarnings($doc->id)) : ['texts' => [], 'company' => false];
        $amendedBy = $db->all('SELECT d.id, d.number, d.status FROM purchase_order p JOIN document d ON d.id = p.document_id WHERE p.amends_document_id = ? ORDER BY d.id',
            [$doc->id]);
        return [
            'people' => ['created' => $name($doc->createdBy) ?? Words::ANOMALIES['set_up'], 'submitted' => $name($doc->submittedBy), 'posted' => $name($doc->postedBy),
                'cancelled' => $name($doc->cancelledBy), 'sent' => $name($po['sent_by'] ?? null), 'closed' => $name($po['closed_by'] ?? null)],
            'reversal' => $revDoc,
            'reversalReason' => $revDoc === null || $revDoc->reasonCode === null ? null
                : self::reasonLabel($revDoc->reasonCode, (string) ($db->value('SELECT label FROM reason_code WHERE code = ?', [$revDoc->reasonCode]) ?? '')),
            'cancelReason' => $doc->cancelReason,
            'amends' => ($po['amends_document_id'] ?? null) === null ? null : $db->one('SELECT id, number FROM document WHERE id = ?', [(int) $po['amends_document_id']]),
            'amendedBy' => array_map(static fn (array $a): array => $a + ['label' => $a['number'] ?? Words::ORDER['a_draft']], $amendedBy),
            'history' => self::history($tasks, $revDoc?->number),
            'decide' => $decide,
            'rejected' => $rejected,
            'received' => $received,
            'files' => array_map(static fn (array $f): array => $f + ['what' => $f['role'] === 'generated_pdf' ? Words::ORDER['file_sent'] : Words::ORDER['file_other'],
                'size' => Html::size((int) $f['size_bytes'])],
                $db->all('SELECT f.id, f.original_name, f.mime, f.size_bytes, df.role, df.attached_at FROM document_file df JOIN stored_file f ON f.id = df.file_id '
                . 'WHERE df.document_id = ? ORDER BY df.attached_at, f.id', [$doc->id])),
            'can' => [
                'send' => $canPost && $posted && in_array($state, ['approved', 'sent'], true),
                'cancel' => $cancellable,
                'amend' => $canPost && $posted && $received === 0 && !in_array($state, ['received', 'closed'], true),
                'copy' => $canPost,
                'close' => $canPost && $posted && $state === 'part_received',
                'approve' => $canPost && $doc->status === 'draft',
                'withdraw' => $canPost && $doc->status === 'awaiting_approval' && $doc->submittedBy === $me->id,
            ],
            'canPost' => $canPost,
            'sendVia' => array_map(static fn (string $code): string => Words::of('SEND_VIA', $code), array_combine(array_keys(PurchaseOrders::SEND_VIA), array_keys(PurchaseOrders::SEND_VIA))),
            'sendWarnings' => $send['texts'],
            'sendCompany' => $send['company'],
            // A draft, or an order waiting for its OK, is cancelled for a draft's reasons; a confirmed one for a confirmed order's.
            'cancelReasons' => self::reasonChoices($db, $posted ? 'cancel' : 'draft_cancel'),
            'amendReasons' => self::reasonChoices($db, 'amend'),
            'formKey' => $canPost ? FormOnce::newKey() : null,
        ];
    }

    /**
     * The order's checks and OKs as sentences (plan F291): "7 Oct 2026: Reviewer check asked for by Ben. Why: Every order is checked.",
     * then how it ended.
     *
     * @param list<array<string, mixed>> $tasks review_task rows with the people's names
     * @return list<array{asked: string, why: string, about: ?string, outcome: string, note: ?string}>
     */
    private static function history(array $tasks, ?string $cancellation): array
    {
        $out = [];
        foreach ($tasks as $t) {
            $who = (string) ($t['opened_by_name'] ?? Words::ANOMALIES['set_up']);
            $outcome = match ((string) $t['state']) {
                'open' => Words::say('RECORD', 'open_until', Html::day((string) $t['due_at'])),
                'withdrawn' => $cancellation !== null && $t['kind'] === 'review' ? Words::say('RECORD', 'closed_cancelled', $cancellation)
                    : Words::RECORD['closed_withdrawn'],
                default => Words::say('RECORD', 'decided', Words::of('TASK_STATE', (string) $t['state']), (string) ($t['decided_by_name'] ?? Words::ANOMALIES['set_up']),
                    Html::when((string) $t['decided_at'])),
            };
            $out[] = [
                'asked' => Words::say('RECORD', 'asked_line', Html::day((string) $t['opened_at']), Words::RECORD[$t['kind'] === 'approval' ? 'kind_approval' : 'kind_review'], $who),
                'why' => Words::say('RECORD', 'why_line', Words::of('CHECK_REASON', (string) $t['reason'])),
                'about' => $t['of_cancel'] === null ? null : Words::say('ORDER', 'about_cancel', (string) $t['of_cancel']),
                'outcome' => $outcome,
                'note' => $t['decision_note'] === null || trim((string) $t['decision_note']) === '' ? null : (string) $t['decision_note'],
            ];
        }
        return $out;
    }

    /**
     * Why this order's PDF says DO NOT SEND because of the company details, with a link to the Company details screen and its
     * words (I96, I98, plan F268), or null: a draft prints the details in use, a confirmed order the snapshot it was confirmed
     * with (an order confirmed before the details were confirmed keeps saying it: correcting it prints the confirmed ones; an
     * order whose snapshot carries a change a reviewer said is wrong says so: cancel or correct it). Only while the order may
     * still go to the supplier or be delivered (draft, waiting for OK, confirmed, sent, partly delivered).
     *
     * @param array<string, mixed> $po
     * @return array{text: string, link: string}|null
     */
    private static function companyNote(Context $ctx, array $po): ?array
    {
        $state = $po['state'] ?? null;
        if ($state !== null && !in_array($state, CompanyDetails::OPEN_ORDER_STATES, true)) {
            return null;
        }
        $current = $ctx->company()->current();
        $fix = Words::ORDER[$ctx->me()->can('company.edit') && !$current['confirmed'] ? 'company_fix' : 'company_see'];
        if ($state !== null) {
            $snapshot = json_decode((string) ($po['company_snapshot'] ?? 'null'), true);
            if (is_array($snapshot) && $ctx->company()->rejectedIn($snapshot) !== null) {
                return ['link' => Words::ORDER['company_see'], 'text' => Words::ORDER['company_rejected']];
            }
            if (is_array($snapshot) && ($snapshot['confirmed'] ?? false) === true) {
                return null;
            }
            return $current['confirmed']
                ? ['link' => Words::ORDER['company_see'], 'text' => Words::ORDER['company_before']]
                : ['link' => $fix, 'text' => Words::ORDER['company_not_confirmed']];
        }
        if ($current['confirmed']) {
            return null;
        }
        return ['link' => $fix, 'text' => Words::ORDER['company_not_confirmed']];
    }

    /**
     * A POST that carries the version the page was drawn with; $fn returns the notice key.
     *
     * @param \Closure(PurchaseOrders, int, int): string $fn
     */
    private function act(Context $ctx, \Closure $fn): HtmlResponse
    {
        $id = $ctx->id();
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', Words::ERROR['bad_version']);
        }
        try {
            $notice = $fn($this->service($ctx), $id, $version);
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->page($ctx, $id, 409, new CwException('version_conflict', Words::BUY_ERROR['version_conflict'], 409));
            }
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/orders/' . $id, ['notice' => $notice]));
    }

    /**
     * The editor's lines with the table's edits applied (rows 1..$count): packs (0 removes the line), pack price, VAT code,
     * note; for a line without a supplier item also units per pack and "save as supplier item".
     *
     * @param list<array<string, mixed>> $current
     * @return list<array<string, mixed>>
     */
    private static function editedLines(UiRequest $req, array $current, int $count): array
    {
        if ($count !== count($current)) {
            throw new CwException('version_conflict', 'the lines changed since this page was drawn', 409);
        }
        $out = [];
        foreach ($current as $i => $l) {
            $n = $i + 1;
            $packs = trim($req->field("line_{$n}_packs") ?? '');
            $p = PoLinesFile::wholeNumber($packs);
            if ($p === null) {
                throw new CwException('bad_line', "line {$n}, packs: a whole number (0 removes the line)", 422, ['line' => $n]);
            }
            if ($p === 0) {
                continue;
            }
            $l['packs'] = $p;
            $note = $req->field("line_{$n}_note");
            if ($note !== null) {
                $l['description'] = $note;
            }
            if ($l['kind'] === 'charge') {
                $price = $req->field("line_{$n}_price");
                if ($price !== null) {
                    $l['pack_price'] = $price;
                }
            } else {
                $price = $req->field("line_{$n}_price");
                if ($price !== null) {
                    $l['pack_price'] = trim($price) === '' ? null : $price;
                }
                $vat = $req->field("line_{$n}_vat");
                if ($vat !== null && $vat !== '') {
                    $l['vat_code'] = $vat;
                }
                if ($l['supplier_item_id'] === null) {
                    $upp = $req->field("line_{$n}_upp");
                    if ($upp !== null && trim($upp) !== '') {
                        $u = PoLinesFile::wholeNumber($upp);
                        if ($u === null || $u < 1) {
                            throw new CwException('bad_line', "line {$n}, units per pack: a whole number of at least 1", 422, ['line' => $n]);
                        }
                        $l['units_per_pack'] = $u;
                    }
                    if ($req->field("line_{$n}_save") === '1') {
                        $l['save_item'] = true;
                    }
                }
            }
            $out[] = $l;
        }
        return $out;
    }

    private function service(Context $ctx): PurchaseOrders
    {
        return new PurchaseOrders($ctx->db, $ctx->documents(), $ctx->settings(), fn (): ?\CW\Files\FileStore => $this->files($ctx));
    }

    /** The file store when this server has one (markSent archives the PDF), else null. */
    private function files(Context $ctx): ?\CW\Files\FileStore
    {
        try {
            return $ctx->files();
        } catch (CwException $e) {
            if ($e->errorCode === 'file_store_unconfigured') {
                return null;
            }
            throw $e;
        }
    }

    private function poDoc(Context $ctx): ?Document
    {
        $doc = $ctx->documents()->find($ctx->id());
        if ($doc === null || $doc->docType !== 'PO') {
            return null;
        }
        return $doc->reversesId !== null ? $ctx->documents()->get($doc->reversesId) : $doc;
    }

    /** "PO-000123" / "PO-draft-12" (the download names). */
    public static function fileName(Document $doc): string
    {
        return $doc->number ?? 'PO-' . str_replace('_', '-', $doc->status) . '-' . $doc->id;
    }

    /** A PO's state as a PO_STATE code: the document's status until it is posted, then the PO's own state. @param array<string, mixed> $po */
    public static function stateCode(Document $doc, array $po): string
    {
        return match ($doc->status) {
            'draft', 'awaiting_approval' => $doc->status,
            'cancelled', 'reversed' => 'cancelled',
            default => isset(Words::PO_STATE[(string) ($po['state'] ?? '')]) ? (string) $po['state'] : 'approved',
        };
    }

    private static function noticeKey(Context $ctx): ?string
    {
        $n = $ctx->req->param('notice');
        return $n !== null && isset(self::NOTICES[$n]) ? $n : null;
    }

    /**
     * The list's filters. `drafts`: whether drafts are listed (`?drafts=1` / `?drafts=0`); without either, every person sees them
     * except those $draftsOff names (draftsOffByDefault), and the CSV lists them unless `drafts=0` (the list's own link says so).
     *
     * @return array{state: ?string, supplier: ?int, q: string, rejected: bool, all: bool, drafts: bool}
     */
    private static function filters(UiRequest $req, bool $draftsOff = false): array
    {
        $drafts = $req->param('drafts');
        return [
            'state' => isset(self::STATE_FILTERS[$req->param('state') ?? '']) ? $req->param('state') : null,
            'supplier' => UiRequest::id($req->param('supplier')),
            'q' => mb_substr(trim($req->param('q') ?? ''), 0, 100),
            'rejected' => $req->param('rejected') === '1',
            'all' => $req->param('show') === 'all',
            'drafts' => $drafts === '1' || ($drafts !== '0' && !$draftsOff),
        ];
    }

    /**
     * Behaviour item 8 of plan §8.6 (F305, provisional): goods-in and the purchasing desk who cannot make orders themselves see the
     * list without drafts unless they tick "Show drafts too": a draft is not ordered, so no delivery is coming for it.
     *
     * @param list<string> $roles
     */
    public static function draftsOffByDefault(array $roles): bool
    {
        $working = array_diff($roles, Permissions::switchedOff($roles));
        return array_intersect($working, ['goods_in', 'purchasing_desk']) !== [] && !Documents::mayPost($roles, 'PO');
    }

    /**
     * @param array{state: ?string, supplier: ?int, q: string, rejected: bool, all: bool, drafts: bool} $f
     * @return list<array<string, mixed>>
     */
    private function rows(Context $ctx, array $f, ?int $limit): array
    {
        $where = ["d.doc_type = 'PO'"];
        $params = [];
        if (!$f['all']) {
            $where[] = 'd.reverses_id IS NULL';
        }
        if (!$f['drafts'] && $f['state'] === null) {
            $where[] = "d.status <> 'draft'";
        }
        if ($f['state'] !== null) {
            $where[] = match ($f['state']) {
                'draft', 'awaiting_approval' => 'd.status = ?',
                'cancelled' => "(d.status = 'cancelled' OR po.state = 'cancelled' OR d.reverses_id IS NOT NULL) AND ? <> ''",
                default => "d.status = 'posted' AND po.state = ?",
            };
            $params[] = $f['state'];
        }
        if ($f['supplier'] !== null) {
            $where[] = 'po.supplier_id = ?';
            $params[] = $f['supplier'];
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(d.number LIKE ? OR d.external_ref LIKE ?)';
            array_push($params, $like, $like);
        }
        if ($f['rejected']) {
            $where[] = "d.review_state = 'rejected'";
        }
        $rows = $ctx->db->all(
            'SELECT d.id, d.number, d.status, d.review_state, d.doc_date, d.external_ref, d.reverses_id, po.state, po.expected_date, po.net_total, po.vat_total, '
            . 'po.gross_total, po.sent_at, po.sent_via, po.source, s.id AS supplier_id, s.code AS supplier_code, s.name AS supplier_name, u.display_name AS created_by_name, '
            . 'o.number AS reverses_number, (SELECT COUNT(*) FROM document_line l WHERE l.document_id = d.id) AS `lines`, '
            . '(SELECT COALESCE(SUM(l.qty), 0) FROM document_line l WHERE l.document_id = d.id) AS units, '
            . "(SELECT r.number FROM document r WHERE r.reverses_id = d.id AND r.status = 'posted' LIMIT 1) AS cancelled_by, "
            . "(SELECT r.posted_at FROM document r WHERE r.reverses_id = d.id AND r.status = 'posted' LIMIT 1) AS cancelled_on "
            . 'FROM document d LEFT JOIN purchase_order po ON po.document_id = COALESCE(d.reverses_id, d.id) LEFT JOIN supplier s ON s.id = po.supplier_id '
            . 'LEFT JOIN staff_user u ON u.id = d.created_by LEFT JOIN document o ON o.id = d.reverses_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC' . ($limit === null ? '' : ' LIMIT ' . $limit),
            $params,
        );
        foreach ($rows as &$r) {
            $r['label'] = $r['number'] ?? str_replace('_', ' ', (string) $r['status']) . ' #' . $r['id'];
            $r['state_label'] = match (true) {
                $r['reverses_id'] !== null => 'cancellation of ' . $r['reverses_number'],
                $r['status'] === 'draft' => 'draft',
                $r['status'] === 'awaiting_approval' => 'awaiting approval',
                $r['status'] === 'cancelled' => 'cancelled',
                $r['status'] === 'reversed' => 'cancelled by ' . ($r['cancelled_by'] ?? '?'),
                default => self::CSV_STATE[$r['state'] ?? ''] ?? (string) $r['state'],
            };
            $r['href'] = '/ui/purchasing/orders/' . ($r['reverses_id'] ?? $r['id']);
            if ($r['reverses_id'] !== null) {
                $r['net_total'] = null;
                $r['vat_total'] = null;
                $r['gross_total'] = null;
            }
        }
        unset($r);
        return $rows;
    }
}
