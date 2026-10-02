<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

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
use CW\Ui\UiRequest;

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
    public const NOTICES = [
        'created' => 'Draft purchase order created: scan or search to add lines, then approve it.',
        'saved' => 'Saved.',
        'added' => 'Line added (and every change in the table saved).',
        'incremented' => 'One more pack on the line that was already there (and every change in the table saved).',
        'imported' => 'Lines imported from the file.',
        'approved' => 'Approved: the order is numbered and fixed. A second person reviews it within 7 days; send it to the supplier now.',
        'submitted' => 'The order is above the approval limit: a reviewer approves it before it is numbered (nothing is ordered until then).',
        'sent' => 'Marked as sent.',
        'sent_archived' => 'Marked as sent; the PDF as sent is kept in the document store.',
        'sent_unarchived' => 'Marked as sent. The PDF was not archived: the file store is not set up on this server.',
        'cancelled' => 'Cancelled.',
        'cancelled_posted' => 'Cancelled: a cancellation was posted in the PO series (it is reviewed like an order). Tell the supplier.',
        'amended' => 'The order was cancelled and this new draft copied from it: change it, approve it and send it (its PDF says which order it amends).',
        'copied' => 'New draft copied from the order.',
        'closed' => 'Closed: the rest is no longer expected.',
        'withdrawn' => 'Approval request withdrawn: the order is a draft again.',
        'approved_review' => 'Review approved.',
        'approved_posted' => 'Approved: the order is posted now, as the requester\'s posting.',
        'rejected_recorded' => 'Rejected at review: the rejection is recorded and the order stands. Its buyer cancels or amends it.',
        'rejected_approval' => 'Rejected: the approval request was cancelled; nothing was ordered.',
        'rejected_reversal' => 'Rejected: the rejection of the cancellation is recorded and nothing changed (a cancellation is never undone: '
            . 'if the order was right, its buyer copies it into a new order).',
    ];
    public const STATE_FILTERS = ['draft' => 'draft', 'awaiting_approval' => 'awaiting approval', 'approved' => 'approved', 'sent' => 'sent',
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
        $f = self::filters($ctx->req);
        $rows = $this->rows($ctx, $f, self::LIST_LIMIT);
        $me = $ctx->me();
        $canPost = Documents::mayPost($me->roles, 'PO');
        return $ctx->page('purchase_orders', [
            'rows' => $rows,
            'filters' => $f,
            'states' => self::STATE_FILTERS,
            'suppliers' => $ctx->db->all("SELECT id, code, name, status FROM supplier ORDER BY name, id"),
            'newSuppliers' => $canPost ? $ctx->db->all("SELECT id, code, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id") : [],
            'formKey' => $canPost ? FormOnce::newKey() : null,
            'limit' => self::LIST_LIMIT,
            'approvalLimit' => (int) $ctx->documents()->typeInfo('PO')['approval_limit_units'],
            'error' => null,
        ], 200, ['title' => 'Purchase orders', 'active' => 'orders']);
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
                throw new CwException('bad_field', 'choose the supplier', 400, ['field' => 'supplier_id']);
            }
            $r = FormOnce::run($ctx, 'ui.po.create', ['supplier_id' => $sid], function (Db $db) use ($ctx, $sid): OpResult {
                $d = $this->service($ctx)->createDraft($ctx->caller(), $sid, []);
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id,
                    'redirect' => Html::url('/ui/purchasing/orders/' . $d->id, ['notice' => 'created'])]);
            });
        } catch (CwException $e) {
            $f = self::filters($ctx->req);
            $me = $ctx->me();
            return $ctx->page('purchase_orders', [
                'rows' => $this->rows($ctx, $f, self::LIST_LIMIT), 'filters' => $f, 'states' => self::STATE_FILTERS,
                'suppliers' => $ctx->db->all('SELECT id, code, name, status FROM supplier ORDER BY name, id'),
                'newSuppliers' => Documents::mayPost($me->roles, 'PO') ? $ctx->db->all("SELECT id, code, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id") : [],
                'formKey' => $ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey(), 'limit' => self::LIST_LIMIT,
                'approvalLimit' => (int) $ctx->documents()->typeInfo('PO')['approval_limit_units'], 'error' => $e->getMessage(),
            ], $e->httpStatus, ['title' => 'Purchase orders', 'active' => 'orders']);
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
            return $ctx->error(404, 'unknown_purchase_order', 'there is no such purchase order');
        }
        if ($doc->reversesId !== null) {
            return HtmlResponse::redirect(Html::url('/ui/purchasing/orders/' . $doc->reversesId, ['notice' => self::noticeKey($ctx)]));
        }
        return $this->page($ctx, $doc->id, 200, null, self::NOTICES[self::noticeKey($ctx) ?? ''] ?? null);
    }

    /** The editor's one form (spec §8.3): saves every edit, then adds what was scanned / searched / chosen. */
    public function lines(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $version = UiRequest::id($req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        $count = $req->field('line_count');
        $editable = $req->field('lines_editable') === '1';
        if ($count === null || preg_match('/^(0|[1-9][0-9]{0,4})$/D', $count) !== 1) {
            return $ctx->error(400, 'form_truncated', 'the form arrived incomplete (no line count): nothing was saved. Reload the page and try again.');
        }
        $count = (int) $count;
        $rows = $req->fieldsMatching('/^line_[1-9][0-9]{0,4}_packs$/');
        if ($editable && ($count > PurchaseOrders::MAX_EDITOR_LINES || count($rows) < $count)) {
            return $ctx->error(400, 'form_truncated', "the form arrived incomplete ({$count} lines sent, " . count($rows) . ' arrived): nothing was saved. '
                . 'An order of more than about ' . self::editorMaxLines() . ' lines is changed with the file import.');
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
                return $this->page($ctx, $id, 422, new CwException('scan_pending', "The scan box still holds \"{$q}\": press Add, or clear it, then approve. "
                    . 'Nothing was saved.', 422), null, ['typed' => $typed, 'q' => $q]);
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
                return $this->page($ctx, $id, 409, new CwException('version_conflict',
                    'This order was changed since you opened it (here is the current data): make your change again.', 409));
            }
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['typed' => $typed]);
        }
        if ($result['status'] === 'choices') {
            $choices = $result['choices'];
            return $this->page($ctx, $id, 200, null, null, ['choices' => $choices, 'q' => $q, 'packs' => max(1, $packs)]);
        }
        if ($result['status'] === 'not_found') {
            return $this->page($ctx, $id, 422, new CwException('not_found', "Nothing matches \"{$q}\" (a barcode, this supplier's code, a CW code or words of the "
                . 'item name). Your other changes are saved.', 422), null, ['q' => $q]);
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
                throw new CwException('no_file', 'choose a CSV or XLSX file to import', 400, ['field' => 'file']);
            }
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > UiRequest::MAX_UPLOAD_BYTES) {
                throw new CwException('too_large', 'the file is larger than ' . intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576) . ' MiB: nothing was imported', 413);
            }
            if ($file['error'] !== UPLOAD_ERR_OK || $file['path'] === '') {
                throw new CwException('upload_failed', 'the file did not arrive completely: try again', 400);
            }
            $r = $this->service($ctx)->importLines($ctx->caller(), $id, $version ?? 0, $file['path'], $file['name'], $ctx->req->field('mode') ?? '');
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        if ($r['errors'] !== []) {
            return $this->page($ctx, $id, 422, new CwException('import_refused', 'Nothing was imported: the file has ' . count($r['errors']) . ' problem'
                . (count($r['errors']) === 1 ? '' : 's') . '. Correct them and import the whole file again.', 422), null, ['importErrors' => $r['errors']]);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/orders/' . $id, ['notice' => 'imported']));
    }

    public function linesCsv(Context $ctx): HtmlResponse
    {
        $doc = $this->poDoc($ctx);
        if ($doc === null) {
            return $ctx->error(404, 'unknown_purchase_order', 'there is no such purchase order');
        }
        return FilesController::download(PoLinesFile::csv($this->service($ctx)->exportRows($doc->id)), 'text/csv; charset=utf-8', self::fileName($doc) . '-lines.csv');
    }

    public function linesXlsx(Context $ctx): HtmlResponse
    {
        $doc = $this->poDoc($ctx);
        if ($doc === null) {
            return $ctx->error(404, 'unknown_purchase_order', 'there is no such purchase order');
        }
        return FilesController::download(PoLinesFile::xlsx($this->service($ctx)->exportRows($doc->id), self::fileName($doc)), self::XLSX, self::fileName($doc) . '-lines.xlsx');
    }

    public function pdf(Context $ctx): HtmlResponse
    {
        $doc = $ctx->documents()->find($ctx->id());
        if ($doc === null || $doc->docType !== 'PO') {
            return $ctx->error(404, 'unknown_purchase_order', 'there is no such purchase order');
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
            return $ctx->error(404, 'unknown_purchase_order', 'there is no such purchase order');
        }
        if ($doc->reversesId !== null) {
            $doc = $docs->get($doc->reversesId);
        }
        $svc = $this->service($ctx);
        $db = $ctx->db;
        $me = $ctx->me();
        $po = $svc->headerRow($doc->id) ?? [];
        $supplier = $db->one('SELECT * FROM supplier WHERE id = ?', [(int) ($po['supplier_id'] ?? 0)]) ?? [];
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
        foreach ($lines as &$l) {
            $l['pack_label'] = $l['kind'] === 'charge' ? '' : ((int) $l['units_per_pack'] === 1 && $l['purchase_unit'] === 'each' ? 'each'
                : $l['purchase_unit'] . ' ×' . $l['units_per_pack']);
            $l['net'] = PoMath::money(PoMath::e2((string) $l['amount']));
            $l['price'] = PoMath::price((string) $l['pack_price']);
            $l['stock'] = $l['sku_id'] === null ? null : ($stock[(int) $l['sku_id']] ?? 0);
            $l['on_order'] = $l['sku_id'] === null ? null : ($onOrder[(int) $l['sku_id']] ?? 0);
            $l['code'] = $l['supplier_code'] ?? $l['si_code'];
            $units += (int) ($l['qty'] ?? 0);
        }
        unset($l);
        $netE2 = PoMath::e2((string) ($po['net_total'] ?? '0'));
        $totals = PoMath::totals(array_map(static fn (array $l): array => ['amount_e2' => PoMath::e2((string) $l['amount']), 'vat_code' => (string) $l['vat_code'],
            'rate_e2' => PoMath::e2((string) $l['vat_rate'])], $lines));
        $type = $docs->typeInfo('PO');
        $data = [
            'doc' => $doc,
            'po' => $po,
            'supplier' => $supplier,
            'lines' => $lines,
            'units' => $units,
            'totals' => ['net' => PoMath::money($netE2), 'vat' => PoMath::money(PoMath::e2((string) ($po['vat_total'] ?? '0'))),
                'gross' => PoMath::money(PoMath::e2((string) ($po['gross_total'] ?? '0'))),
                'by_code' => array_map(static fn (array $v): array => ['rate' => rtrim(rtrim(PoMath::fromE2($v['rate_e2']), '0'), '.'), 'vat' => PoMath::money($v['vat_e2'])],
                    $totals['by_code'])],
            'stateText' => self::stateText($doc, $po, $db),
            'warnings' => in_array($doc->status, ['draft', 'awaiting_approval'], true) ? $svc->warnings($doc->id) : [],
            'companyNote' => self::companyNote($ctx, $po),
            'limit' => (int) $type['approval_limit_units'],
            'error' => $error?->getMessage(),
            'errorCode' => $error?->errorCode,
            'importErrors' => $extra['importErrors'] ?? [],
            'fileName' => self::fileName($doc),
        ];
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
            ];
            return $ctx->page('purchase_order_edit', $data, $status, ['title' => $doc->label(), 'active' => 'orders', 'notice' => $notice]);
        }
        return $ctx->page('purchase_order', $data + $this->viewData($ctx, $doc, $po, $canPost), $status,
            ['title' => $doc->label(), 'active' => 'orders', 'notice' => $notice]);
    }

    /**
     * What the read-only view adds: people, the cancellation and amendment links, the review tasks (the order's and its
     * cancellation's), the decide box, the actions this person may use, the files.
     *
     * @param array<string, mixed> $po
     * @return array<string, mixed>
     */
    private function viewData(Context $ctx, Document $doc, array $po, bool $canPost): array
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
        $decide = [];
        $rejected = null;
        foreach ($tasks as &$t) {
            $t['overdue'] = $t['state'] === 'open' && (string) $t['due_at'] < $now;
            $t['of'] = (int) $t['subject_id'] === $doc->id ? $doc->label() : ($revDoc?->number ?? 'the cancellation');
            if ($t['state'] === 'open' && ($me->can('documents.review') || $me->can('documents.approve'))) {
                $subject = (int) $t['subject_id'] === $doc->id ? $doc : $revDoc;
                $no = $subject === null ? null : Documents::refusal($me->id, $me->roles, $subject, (string) $t['kind']);
                $decide[] = ['task' => $t, 'refusal' => $no['message'] ?? null, 'of' => $t['of'], 'reversal' => $subject !== null && $subject->isReversal()];
            }
            if ($t['state'] === 'rejected' && $t['kind'] === 'review' && (int) $t['subject_id'] === $doc->id) {
                $rejected = $t;
            }
        }
        unset($t);
        $names = [];
        foreach ($db->all('SELECT id, display_name FROM staff_user WHERE id IN (?, ?, ?, ?, ?, ?)', [$doc->createdBy ?? 0, $doc->submittedBy ?? 0, $doc->postedBy ?? 0,
            $doc->cancelledBy ?? 0, (int) ($po['sent_by'] ?? 0), (int) ($po['closed_by'] ?? 0)]) as $u) {
            $names[(int) $u['id']] = (string) $u['display_name'];
        }
        $name = static fn (mixed $uid): ?string => $uid === null ? null : ($names[(int) $uid] ?? '#' . $uid);
        $state = $po['state'] ?? null;
        $received = (int) $db->value('SELECT COALESCE(SUM(received_units), 0) FROM po_line WHERE document_id = ?', [$doc->id]);
        $posted = $doc->status === 'posted';
        $reasons = $db->all("SELECT code, label FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 AND system_only = 0 AND is_active = 1 ORDER BY sort_order, code");
        $pick = static fn (array $codes): array => array_values(array_filter($reasons, static fn (array $r): bool => in_array($r['code'], $codes, true)));
        $cancellable = $canPost && (($posted && $received === 0 && !in_array($state, ['received', 'closed'], true)) || $doc->status === 'draft'
            || ($doc->status === 'awaiting_approval' && $doc->submittedBy === $me->id));
        return [
            'people' => ['created' => $name($doc->createdBy) ?? $doc->createdActor, 'submitted' => $name($doc->submittedBy), 'posted' => $name($doc->postedBy),
                'cancelled' => $name($doc->cancelledBy), 'sent' => $name($po['sent_by'] ?? null), 'closed' => $name($po['closed_by'] ?? null)],
            'reversal' => $revDoc,
            'amends' => ($po['amends_document_id'] ?? null) === null ? null : $db->one('SELECT id, number FROM document WHERE id = ?', [(int) $po['amends_document_id']]),
            'amendedBy' => $db->all('SELECT d.id, d.number, d.status FROM purchase_order p JOIN document d ON d.id = p.document_id WHERE p.amends_document_id = ? ORDER BY d.id',
                [$doc->id]),
            'tasks' => $tasks,
            'decide' => $decide,
            'rejected' => $rejected,
            'received' => $received,
            'files' => $db->all('SELECT f.id, f.original_name, f.mime, f.size_bytes, df.role, df.attached_at FROM document_file df JOIN stored_file f ON f.id = df.file_id '
                . 'WHERE df.document_id = ? ORDER BY df.attached_at, f.id', [$doc->id]),
            'can' => [
                'send' => $canPost && $posted && in_array($state, ['approved', 'sent'], true),
                'cancel' => $cancellable,
                'amend' => $canPost && $posted && $received === 0 && !in_array($state, ['received', 'closed'], true),
                'copy' => $canPost,
                'close' => $canPost && $posted && $state === 'part_received',
                'approve' => $canPost && $doc->status === 'draft',
                'withdraw' => $canPost && $doc->status === 'awaiting_approval' && $doc->submittedBy === $me->id,
            ],
            'sendVia' => PurchaseOrders::SEND_VIA,
            'sendWarnings' => $canPost && $posted && in_array($state, ['approved', 'sent'], true) ? $this->service($ctx)->sendWarnings($doc->id) : [],
            'cancelReasons' => $pick(PurchaseOrders::CANCEL_REASONS),
            'amendReasons' => $pick(PurchaseOrders::AMEND_REASONS),
            'formKey' => $canPost ? FormOnce::newKey() : null,
        ];
    }

    /**
     * Why this order's PDF says "do not send" because of the company details, with a link to the Company details screen and
     * its words (I96, I98), or null: a draft prints the details in use, an approved order the snapshot it was approved with (an
     * order approved before the details were confirmed keeps saying it: amending it prints the confirmed ones; an order whose
     * snapshot carries a change a reviewer rejected says so: cancel or amend it). Only while the order may still go to the
     * supplier or be delivered (draft, waiting for approval, approved, sent, part received).
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
        $fix = $ctx->me()->can('company.edit') && !$current['confirmed'] ? 'Add or confirm the company details' : 'See the company details';
        if ($state !== null) {
            $snapshot = json_decode((string) ($po['company_snapshot'] ?? 'null'), true);
            if (is_array($snapshot) && $ctx->company()->rejectedIn($snapshot) !== null) {
                return ['link' => 'See the company details', 'text' => 'This order was approved with company details that include a change a reviewer '
                    . 'rejected: its PDF says "company details rejected at review - do not send". Cancel or amend it.'];
            }
            if (is_array($snapshot) && ($snapshot['confirmed'] ?? false) === true) {
                return null;
            }
            return $current['confirmed']
                ? ['link' => 'See the company details', 'text' => 'This order was approved before the company details were confirmed: its PDF keeps the details '
                    . 'it was approved with and says "company details not confirmed - do not send". Amend it to print the confirmed details.']
                : ['link' => $fix, 'text' => 'The company details are not confirmed yet: this order\'s PDF says "company details not confirmed - do not send".'];
        }
        if ($current['confirmed']) {
            return null;
        }
        return ['link' => $fix, 'text' => 'The company details are not confirmed yet, so this order\'s PDF says "company details not confirmed - do not send".'];
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
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $notice = $fn($this->service($ctx), $id, $version);
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->page($ctx, $id, 409, new CwException('version_conflict',
                    'This order was changed since you opened it (here is the current data): make your choice again.', 409));
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

    /** What the screens call a PO's state: the document's status until it is posted, then the PO's own state. */
    public static function stateText(Document $doc, array $po, Db $db): string
    {
        return match ($doc->status) {
            'draft' => 'draft',
            'awaiting_approval' => 'awaiting approval',
            'cancelled' => 'cancelled',
            'reversed' => 'cancelled by ' . ($db->value("SELECT number FROM document WHERE reverses_id = ? AND status = 'posted'", [$doc->id]) ?? 'a cancellation'),
            default => self::STATE_FILTERS[$po['state'] ?? ''] ?? (string) ($po['state'] ?? ''),
        };
    }

    private static function noticeKey(Context $ctx): ?string
    {
        $n = $ctx->req->param('notice');
        return $n !== null && isset(self::NOTICES[$n]) ? $n : null;
    }

    /** @return array{state: ?string, supplier: ?int, q: string, rejected: bool, all: bool} */
    private static function filters(UiRequest $req): array
    {
        return [
            'state' => isset(self::STATE_FILTERS[$req->param('state') ?? '']) ? $req->param('state') : null,
            'supplier' => UiRequest::id($req->param('supplier')),
            'q' => mb_substr(trim($req->param('q') ?? ''), 0, 100),
            'rejected' => $req->param('rejected') === '1',
            'all' => $req->param('show') === 'all',
        ];
    }

    /**
     * @param array{state: ?string, supplier: ?int, q: string, rejected: bool, all: bool} $f
     * @return list<array<string, mixed>>
     */
    private function rows(Context $ctx, array $f, ?int $limit): array
    {
        $where = ["d.doc_type = 'PO'"];
        $params = [];
        if (!$f['all']) {
            $where[] = 'd.reverses_id IS NULL';
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
            . "(SELECT r.number FROM document r WHERE r.reverses_id = d.id AND r.status = 'posted' LIMIT 1) AS cancelled_by "
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
                default => self::STATE_FILTERS[$r['state'] ?? ''] ?? (string) $r['state'],
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
