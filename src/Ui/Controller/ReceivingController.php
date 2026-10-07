<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\OpResult;
use CW\Output\CsvWriter;
use CW\PurchaseOrders\PoLinesFile;
use CW\PurchaseOrders\PoMath;
use CW\Receiving\GoodsReceipts;
use CW\Receiving\Incidents;
use CW\Receiving\ReceiptMath;
use CW\Receiving\ReceiptPlan;
use CW\Receiving\SellingModes;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\ReceiptWords;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * The receiving screens (IM6 Receive (+ invoice), Phase I-3; docs/decisions.md I141): the receipts list with the "new delivery"
 * form, the ONE-FORM receive editor of a draft (header, a scan / supplier-code / search box whose Enter adds a line and saves every
 * edit in the table, the lines with their pack, price, PO line and selling mode, the checklist of what posting would do and what
 * stops it, "Save and post"), "receive all as ordered" from the PO, the supplier-sheet import, the files (the invoice copy, a
 * delivery note, photos, duty evidence), the receipt's read-only page (what was booked where, the incidents, the review and
 * reversal), and the goods-in bench: its list of deliveries to check and the bench check of one (tablet and phone width, a page of
 * lines at a time, a scanner can type the stamp code).
 *
 * receiving.view looks; doc.GRN.post (goods_in, purchasing_desk, purchasing_manager) acts, checked again by CW\Receiving\GoodsReceipts;
 * a draft's header and lines are its creator's (others see it read-only, with the bench check and "post"); reviewers decide on the
 * receipt's page (the forms post to /ui/documents/reviews/{task}/..., which come back here). The "new delivery" form carries a
 * FormOnce key; every other form the version it was drawn with (409: the page is redrawn with the current data). The editor sends
 * `version`, `line_count` and `lines_editable` FIRST and is offered while its fields stay below max_input_vars (editorFits(): 6 a
 * line); a longer receipt is read-only here and changed with the sheet import.
 *
 * Plain words (U85-U90): every word of these pages comes from Ui\Words (RECEIVING, RECEIPT, BENCH, RECEIPT_NOTICE, RECEIPT_ERROR,
 * ...); the services' refusals are said again by error code (plain()), and the goods-in plan's problems and warnings by
 * Ui\ReceiptWords. The services' own messages are unchanged.
 */
final class ReceivingController
{
    /** The notices named in a redirect (their words: Words::RECEIPT_NOTICE). */
    public const NOTICES = Words::RECEIPT_NOTICE;
    /** The list's "Show" filter: a delivery's state, or "checked at the bench, not booked in yet". */
    public const STATE_FILTERS = ['draft', 'checked', 'posted', 'reversed', 'cancelled'];
    public const LIST_LIMIT = 300;
    /** The editor's fields outside its line rows (csrf, version, stamp, line_count, lines_editable, 9 header fields, q, packs, price, the button, a choice). */
    public const EDITOR_FIXED_FIELDS = 26;
    /** Fields of one line in the editor: packs, price, units per pack, PO line, mode, note. */
    public const EDITOR_LINE_FIELDS = 6;
    /** Lines on one page of the bench check (14 fields each, well below max_input_vars). */
    public const BENCH_PAGE = 40;

    /** Whether the one-form editor can carry $n lines (max_input_vars, I73). */
    public static function editorFits(int $n): bool
    {
        return self::EDITOR_FIXED_FIELDS + $n * self::EDITOR_LINE_FIELDS < UiRequest::maxInputVars();
    }

    public static function editorMaxLines(): int
    {
        return intdiv(UiRequest::maxInputVars() - 1 - self::EDITOR_FIXED_FIELDS, self::EDITOR_LINE_FIELDS);
    }

    // ------------------------------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------------------------------

    public function index(Context $ctx, int $status = 200, ?string $error = null): HtmlResponse
    {
        $f = self::filters($ctx->req);
        $me = $ctx->me();
        $canPost = Documents::mayPost($me->roles, 'GRN');
        $states = [];
        foreach (self::STATE_FILTERS as $code) {
            $states[$code] = $code === 'checked' ? Words::RECEIVING['state_checked'] : Words::of('RECEIPT_STATE', $code);
        }
        return $ctx->page('receipts', [
            'rows' => array_map(self::screenRow(...), $this->rows($ctx, $f)),
            'filters' => $f,
            'filtered' => $f['state'] !== null || $f['supplier'] !== null || $f['q'] !== '',
            'states' => $states,
            'suppliers' => $ctx->db->all('SELECT id, code, name, status FROM supplier ORDER BY name, id'),
            'newSuppliers' => $canPost ? $ctx->db->all("SELECT id, code, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id") : [],
            'orders' => $canPost ? $ctx->goodsReceipts()->receivableOrders() : [],
            'formKey' => $canPost ? ($ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey()) : null,
            'typed' => $error === null ? [] : $ctx->req->post,
            'limit' => self::LIST_LIMIT,
            'error' => $error,
            'canPost' => $canPost,
            'lookOnly' => $canPost ? null : Words::whoCan('doc.GRN.post'),
        ], $status, ['title' => Words::MENU['receiving'], 'active' => 'receiving', 'notice' => self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null]);
    }

    /**
     * One delivery of the list as the screen shows it: the supplier's name first, a line with its number, invoice and order, its
     * state, the bench's progress and the reviewer's check as chips, its size in words.
     *
     * @param array<string, mixed> $r a rows() row
     * @return array<string, mixed>
     */
    public static function screenRow(array $r): array
    {
        $parts = [$r['number'] ?? Words::RECEIVING['no_number']];
        $parts[] = $r['external_ref'] !== null && $r['external_ref'] !== '' ? Words::say('RECEIVING', 'invoice_no', (string) $r['external_ref']) : Words::RECEIVING['no_invoice'];
        if ($r['po_number'] !== null) {
            $parts[] = Words::say('RECEIVING', 'order_no', (string) $r['po_number']);
        }
        $lines = (int) $r['lines'];
        return $r + [
            'number_line' => implode(' · ', $parts),
            'state_tone' => Words::tone('RECEIPT_STATE', (string) $r['status']),
            'state_word' => Words::of('RECEIPT_STATE', (string) $r['status']),
            'size_line' => $lines === 1 ? Words::say('RECEIVING', 'size_one', (int) $r['units']) : Words::say('RECEIVING', 'size_many', $lines, (int) $r['units']),
            'bench_state' => $r['status'] === 'draft' ? self::benchState($r) : null,
            'incidents_line' => (int) $r['open_incidents'] === 0 ? null : ((int) $r['open_incidents'] === 1 ? Words::RECEIVING['open_incidents_one']
                : Words::say('RECEIVING', 'open_incidents', (int) $r['open_incidents'])),
        ];
    }

    /**
     * How far the goods-in bench got with a delivery not booked in yet (BENCH_STATE): todo (nothing answered), part, done (the
     * paperwork looks right and every line is checked), refused (the paperwork is not right).
     *
     * @param array<string, mixed> $r checked_at, paperwork_ok, lines, checked_lines
     */
    public static function benchState(array $r): string
    {
        if ($r['checked_at'] !== null && $r['paperwork_ok'] !== null && (int) $r['paperwork_ok'] === 0) {
            return 'refused';
        }
        $checked = (int) ($r['checked_lines'] ?? 0);
        if ($r['checked_at'] !== null && (int) $r['lines'] > 0 && $checked === (int) $r['lines']) {
            return 'done';
        }
        return $r['checked_at'] === null && $checked === 0 ? 'todo' : 'part';
    }

    /** POST /ui/receiving: a new draft receipt (FormOnce: one draft however often the form is sent). */
    public function create(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $poId = UiRequest::id($req->field('po_id'));
        $sid = UiRequest::id($req->field('supplier_id'));
        $invoice = $req->field('invoice_number');
        $copy = $req->field('copy') === '1';
        try {
            if ($sid === null && $poId !== null) {
                $sid = (int) ($ctx->db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$poId]) ?? 0) ?: null;
            }
            if ($sid === null) {
                throw new CwException('choose_supplier', Words::RECEIPT_ERROR['choose_supplier'], 400, ['field' => 'supplier_id']);
            }
            $r = FormOnce::run($ctx, 'ui.grn.create', ['supplier_id' => $sid, 'po_id' => $poId, 'invoice_number' => $invoice, 'copy' => $copy],
                function (Db $db) use ($ctx, $sid, $poId, $invoice, $copy): OpResult {
                    $d = $ctx->goodsReceipts()->createDraft($ctx->caller(), $sid, ['invoice_number' => $invoice], $poId, $copy && $poId !== null);
                    return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id,
                        'redirect' => Html::url('/ui/receiving/' . $d->id, ['notice' => $copy && $poId !== null ? 'copied' : 'created'])]);
                });
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, self::plain($e));
        }
        return FormOnce::redirect($r);
    }

    /** A blank supplier sheet: the column names the import reads (any of their spellings work; ReceiptLinesFile::ALIASES). */
    public function template(Context $ctx): HtmlResponse
    {
        $csv = new CsvWriter([['code', 'text'], ['barcode', 'text'], ['description', 'text'], ['qty', 'number'], ['pack_size', 'number'], ['units', 'number'],
            ['price', 'number']]);
        $csv->add(['ABC-123', '5012345678900', 'Example liquid 10ml (delete this row)', 3, 10, 30, '12.50']);
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'receipt-sheet-template.csv');
    }

    // ------------------------------------------------------------------------------------------
    // One receipt
    // ------------------------------------------------------------------------------------------

    public function show(Context $ctx): HtmlResponse
    {
        $n = $ctx->req->param('notice');
        $id = $ctx->id();
        $notice = self::NOTICES[$n ?? ''] ?? null;
        if (in_array($n, ['added', 'incremented'], true) && ($line = UiRequest::id($ctx->req->param('line'))) !== null) {
            $notice = $this->addedNotice($ctx, $id, $n, $line, UiRequest::id($ctx->req->param('u')) ?? 0, $ctx->req->param('x') === 'case') ?? $notice;
        }
        if (($n === 'saved' || in_array($n, ['added', 'incremented'], true)) && preg_match('/^[1-9][0-9]{0,4}(,[1-9][0-9]{0,4}){0,199}$/D', $ctx->req->param('rc') ?? '') === 1) {
            $nos = array_map('intval', explode(',', (string) $ctx->req->param('rc')));
            $notice .= ' ' . Words::say('RECEIPT_ADDED', count($nos) === 1 ? 'cleared_one' : 'cleared_many', ReceiptPlan::lineList($nos));
        }
        return $this->page($ctx, $id, 200, null, $notice);
    }

    /** "Added line 7: Name, 2 cases of 10 = 20 items at £18.50 a pack." / "Line 1, Name: +5 items (now 4 packs of 5 = 20 items)." */
    private function addedNotice(Context $ctx, int $id, string $kind, int $lineNo, int $unitsAdded, bool $case): ?string
    {
        $l = $ctx->db->one('SELECT g.packs, g.units_per_pack, g.purchase_unit, g.pack_price, s.code, s.name FROM grn_line g JOIN document_line dl ON dl.document_id = g.document_id '
            . 'AND dl.line_no = g.line_no JOIN sku s ON s.id = dl.sku_id WHERE g.document_id = ? AND g.line_no = ?', [$id, $lineNo]);
        if ($l === null) {
            return null;
        }
        $units = (int) $l['packs'] * (int) $l['units_per_pack'];
        $packs = self::packsText((int) $l['packs'], (string) $l['purchase_unit'], (int) $l['units_per_pack']);
        if ((int) $l['units_per_pack'] > 1) {
            $packs = Words::say('RECEIPT_ADDED', 'packs_items', $packs, $units);
        }
        $item = "{$l['code']} " . mb_substr((string) $l['name'], 0, 60);
        $text = $kind === 'added'
            ? Words::say('RECEIPT_ADDED', 'added', $lineNo, $item, PoMath::e4((string) $l['pack_price']) > 0
                ? Words::say('RECEIPT_ADDED', 'at_price', $packs, '£' . PoMath::price((string) $l['pack_price'])) : Words::say('RECEIPT_ADDED', 'no_price', $packs))
            : Words::say('RECEIPT_ADDED', 'incremented', $lineNo, $item, $unitsAdded, $packs);
        if ($case) {
            $text .= ' ' . Words::say('RECEIPT_ADDED', 'case', (int) $l['units_per_pack']);
        }
        return $text . ' ' . Words::RECEIPT_ADDED['all_saved'];
    }

    /** The editor's one form: saves every edit, then adds what was scanned / chosen, or posts ("Save and post"). */
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
            return $ctx->error(400, 'form_truncated', Words::RECEIPT_ERROR['form_truncated']);
        }
        $count = (int) $count;
        $rows = $req->fieldsMatching('/^line_[1-9][0-9]{0,4}_packs$/');
        if ($editable && count($rows) < $count) {
            return $ctx->error(400, 'form_truncated', Words::say('RECEIPT_ERROR', 'form_truncated_lines', $count, count($rows), self::editorMaxLines()));
        }
        $svc = $ctx->goodsReceipts();
        $typed = $req->post;
        $q = trim($req->field('q') ?? '');
        $stamp = $req->field('stamp');
        $retried = false;
        retry:
        try {
            $current = $svc->lines($id);
            $lines = $editable ? self::editedLines($req, $current, $count) : $current;
            $header = [];
            foreach (['invoice_number', 'invoice_date', 'delivery_note', 'received_at', 'backdate_reason', 'note', 'supplier_id', 'po_id'] as $k) {
                $v = $req->field($k);
                if ($v !== null) {
                    $header[$k] = $v;
                }
            }
            if ($req->field('header_present') === '1') {
                $header['paper_sheet'] = $req->field('paper_sheet') === '1';
            }
            $packs = PoLinesFile::wholeNumber($req->field('packs') ?? '1') ?? 0;
            $price = trim($req->field('price') ?? '');
            $price = $price === '' ? null : $price;
            $addSku = UiRequest::id($req->field('add_sku'));
            $addSi = UiRequest::id($req->field('add_si'));
            $posting = $req->field('action') === 'post' && $addSku === null && $addSi === null;
            if ($posting && $q !== '') {
                return $this->page($ctx, $id, 422, new CwException('scan_pending', Words::say('RECEIPT_ERROR', 'scan_pending', $q), 422), null, ['typed' => $typed, 'q' => $q]);
            }
            $adding = $addSku !== null || $addSi !== null || ($req->field('action') === 'add' && $q !== '');
            $result = $ctx->db->transaction(function () use ($ctx, $svc, $id, $version, $header, $lines, $adding, $posting, $addSku, $addSi, $q, $packs, $price): array {
                $doc = $svc->saveDraft($ctx->caller(), $id, $version, $header, $lines);
                if ($posting) {
                    // "Save and post": what is posted is what the desk sees, typed edits included, in one transaction.
                    return ['status' => 'posted', 'document' => $svc->post($ctx->caller(), $id, $doc->version)];
                }
                if (!$adding) {
                    return ['status' => 'saved', 'document' => $doc];
                }
                if ($addSi !== null || $addSku !== null) {
                    return $svc->addChoice($ctx->caller(), $id, $doc->version, $addSi, $addSi === null ? $addSku : null, max(1, $packs), $price);
                }
                return $svc->addLine($ctx->caller(), $id, $doc->version, $q, max(1, $packs), $price);
            });
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                // Changed meanwhile by the bench only (its findings, which this form never sends): save again on the current version
                // (I175). Changed by the desk (another tab): redraw with what was typed, nothing saved.
                $now = $ctx->documents()->find($id);
                if (!$retried && $stamp !== null && $now !== null && $now->status === 'draft' && hash_equals($svc->deskStamp($id), $stamp)) {
                    $retried = true;
                    $version = $now->version;
                    goto retry;
                }
                return $this->page($ctx, $id, 409, new CwException('version_conflict', Words::RECEIPT_ERROR['version_conflict'], 409), null, ['typed' => count($svc->lines($id)) === $count ? $typed : array_filter($typed, static fn ($k): bool =>
                    !str_starts_with((string) $k, 'line_'), ARRAY_FILTER_USE_KEY), 'q' => $q]);
            }
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['typed' => $typed, 'q' => $q]);
        }
        if ($result['status'] === 'choices') {
            return $this->page($ctx, $id, 200, null, isset($result['choice_note']) ? ReceiptWords::one((string) $result['choice_note']) : null, ['choices' => $result['choices'], 'q' => $q, 'packs' => max(1, $packs),
                'price' => $price ?? '']);
        }
        if ($result['status'] === 'not_found') {
            return $this->page($ctx, $id, 422, new CwException('not_found', Words::say('RECEIPT_ERROR', 'not_found', $q), 422), null, ['q' => $q]);
        }
        if ($result['status'] === 'posted') {
            return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id, ['notice' => 'posted']));
        }
        // The lines whose bench check this save cleared (their quantity or item changed, I168): said in the notice.
        $before = [];
        foreach ($current as $l) {
            if ($l['checked_at'] !== null) {
                $before[$l['line_no']] = true;
            }
        }
        $cleared = [];
        foreach ($svc->lines($id) as $i => $l) {
            $from = $lines[$i]['line_no'] ?? null;
            if ($l['checked_at'] === null && $from !== null && isset($before[$from])) {
                $cleared[] = $l['line_no'];
            }
        }
        $query = ['notice' => $result['status']];
        if (isset($result['line_no'])) {
            $query += ['line' => $result['line_no'], 'u' => $result['units_added'] ?? null, 'x' => ($result['note'] ?? null) !== null ? 'case' : null];
        }
        if ($cleared !== []) {
            $query['rc'] = implode(',', array_slice($cleared, 0, 200));
        }
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id, $query) . (isset($result['line_no']) ? '#line-' . $result['line_no'] : '#scan'));
    }

    /** POST copy: "receive all as ordered" (the PO's outstanding lines not on the receipt yet). */
    public function copy(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, function (GoodsReceipts $svc, int $id, int $version) use ($ctx): string {
            $svc->copyFromPo($ctx->caller(), $id, $version);
            return 'copied';
        });
    }

    /** The supplier's sheet (multipart `file`, `mode` append | replace, `version`): all or nothing. */
    public function import(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $version = UiRequest::id($ctx->req->field('version'));
        try {
            $file = self::upload($ctx);
            $r = $ctx->goodsReceipts()->importLines($ctx->caller(), $id, $version ?? 0, $file['path'], $file['name'], $ctx->req->field('mode') ?? '');
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->errorCode === 'version_conflict' ? 409 : $e->httpStatus, $e);
        }
        if ($r['errors'] !== []) {
            return $this->page($ctx, $id, 422, new CwException('import_refused', count($r['errors']) === 1 ? Words::RECEIPT_ERROR['import_refused_one']
                : Words::say('RECEIPT_ERROR', 'import_refused', count($r['errors'])), 422), null, ['importErrors' => $r['errors'], 'skipped' => $r['skipped']]);
        }
        return $this->page($ctx, $id, 200, null, self::NOTICES['imported'] . ($r['skipped'] === [] ? '' : ' ' . (count($r['skipped']) === 1
            ? Words::RECEIPT_ADDED['skipped_one'] : Words::say('RECEIPT_ADDED', 'skipped_many', count($r['skipped'])))), ['skipped' => $r['skipped']]);
    }

    /** A file for the receipt (multipart `file`, `role`, `note`): the invoice copy, a delivery note, a photo, duty evidence. */
    public function files(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $back = $ctx->req->field('back') === 'bench' ? '/bench' : '';
        try {
            $file = self::upload($ctx);
            $ctx->goodsReceipts()->attach($ctx->caller(), $id, $file['path'], $file['name'], $ctx->req->field('role') ?? '', $ctx->req->field('note'));
        } catch (CwException $e) {
            return $back === '' ? $this->page($ctx, $id, $e->httpStatus, $e) : $this->benchPage($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id . $back, ['notice' => 'attached']));
    }

    public function post(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, function (GoodsReceipts $svc, int $id, int $version) use ($ctx): string {
            $svc->post($ctx->caller(), $id, $version);
            return 'posted';
        });
    }

    public function cancel(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, function (GoodsReceipts $svc, int $id, int $version) use ($ctx): string {
            $svc->cancel($ctx->caller(), $id, $version, $ctx->req->field('reason') ?? '');
            return 'cancelled';
        });
    }

    /** POST reverse: a posted receipt reversed (a reversal reason, a note when it needs one): Documents::reverse. */
    public function reverse(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $reason = trim($ctx->req->field('reason_code') ?? '');
        try {
            if ($reason === '') {
                throw new CwException('reason_required', Words::RECEIPT_ERROR['reason_required'], 400);
            }
            $ctx->documents()->reverse($ctx->caller(), $id, $reason, $ctx->req->field('note'));
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id, ['notice' => 'reversed']));
    }

    // ------------------------------------------------------------------------------------------
    // The goods-in bench
    // ------------------------------------------------------------------------------------------

    /** GET /ui/receiving/bench: the draft receipts, the earliest delivery first, with how far their check got. */
    public function benchList(Context $ctx): HtmlResponse
    {
        $rows = $ctx->db->all(
            'SELECT d.id, d.status, d.external_ref, d.version, g.received_at, g.checked_at, g.paperwork_ok, s.code AS supplier_code, s.name AS supplier_name, '
            . 'p.number AS po_number, u.display_name AS created_by_name, c.display_name AS checked_by_name, '
            . '(SELECT COUNT(*) FROM grn_line l WHERE l.document_id = d.id) AS `lines`, (SELECT COUNT(*) FROM grn_line l WHERE l.document_id = d.id AND l.checked_at IS NOT NULL) AS checked_lines, '
            . '(SELECT COALESCE(SUM(dl.qty), 0) FROM document_line dl WHERE dl.document_id = d.id) AS units '
            . "FROM document d JOIN goods_receipt g ON g.document_id = d.id JOIN supplier s ON s.id = g.supplier_id LEFT JOIN document p ON p.id = g.po_document_id "
            . "LEFT JOIN staff_user u ON u.id = d.created_by LEFT JOIN staff_user c ON c.id = g.checked_by WHERE d.doc_type = 'GRN' AND d.status = 'draft' "
            . 'ORDER BY g.received_at, d.id LIMIT 200',
        );
        foreach ($rows as &$r) {
            $r['bench_state'] = self::benchState($r);
            $r['size_line'] = (int) $r['lines'] === 1 ? Words::say('RECEIVING', 'size_one', (int) $r['units'])
                : Words::say('RECEIVING', 'size_many', (int) $r['lines'], (int) $r['units']);
        }
        unset($r);
        return $ctx->page('bench_list', ['rows' => $rows], 200, ['title' => Words::MENU['bench'], 'active' => 'bench']);
    }

    public function benchForm(Context $ctx): HtmlResponse
    {
        return $this->benchPage($ctx, $ctx->id(), 200, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    /**
     * POST the bench check of one page of lines (b_<line>_<field>) and the delivery's checklist. A line is counted when its
     * "counted" box is ticked or one of its findings changed; unticking a counted line takes its count back (I168). The page
     * carries a stamp of each line and of the checklist as drawn: a save refused only because the desk changed something the
     * bench does not see (a price, a note) is saved again on the current version; otherwise the page is redrawn with what was
     * typed (the lines whose item or quantity changed show their new figures), nothing saved (I175).
     */
    public function bench(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $version = UiRequest::id($req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        $from = max(1, UiRequest::id($req->field('from')) ?? 1);
        $svc = $ctx->goodsReceipts();
        $stored = [];
        foreach ($svc->lines($id) as $l) {
            $stored[$l['line_no']] = $l;
        }
        $drawn = [];
        $lines = [];
        foreach ($req->fieldsMatching('/^b_[1-9][0-9]{0,4}_present$/') as $name => $identity) {
            $no = (int) substr($name, 2, -8);
            $drawn[$no] = ['identity' => (string) $identity, 'was' => (string) ($req->field("b_{$no}_was") ?? '')];
            $f = [
                'stamp_on_pack' => $req->field("b_{$no}_stamp"),
                'stamp_type' => $req->field("b_{$no}_type"),
                'stamp_code' => $req->field("b_{$no}_code"),
                'short_units' => $req->field("b_{$no}_short"),
                'over_units' => $req->field("b_{$no}_over"),
                'damaged_units' => $req->field("b_{$no}_damaged"),
                'wrong_item_units' => $req->field("b_{$no}_wrong"),
                'unstamped_units' => $req->field("b_{$no}_unstamped"),
                'unstamped_action' => $req->field("b_{$no}_action"),
                'pre_october_evidence' => $req->field("b_{$no}_evidence"),
            ];
            $s = $stored[$no] ?? null;
            $ticked = $req->field("b_{$no}_ok") === '1';
            $changed = $s === null || self::benchChanged($f, $s);
            if ($ticked || $changed) {
                $lines[$no] = $f + ['over_confirmed' => $req->field("b_{$no}_overok")];
            } elseif ($s['checked_at'] !== null) {
                $lines[$no] = $f + ['checked' => false]; // unticked: the count is taken back
            }
        }
        ksort($lines);
        $header = [];
        if ($req->field('paperwork_ok') !== null) {
            $header['paperwork_ok'] = $req->field('paperwork_ok');
        }
        if ($req->field('bench_note') !== null) {
            $header['bench_note'] = $req->field('bench_note');
        }
        if ($req->field('arrived_now') === '1') {
            $header['arrived_now'] = true;
        }
        $retried = false;
        while (true) {
            try {
                $svc->bench($ctx->caller(), $id, $version, $header, $lines);
                break;
            } catch (CwException $e) {
                if ($e->errorCode !== 'version_conflict') {
                    return $this->benchPage($ctx, $id, $e->httpStatus, $e, null, ['typed' => $req->post]);
                }
                $now = $ctx->documents()->find($id);
                $stamps = $svc->benchStamps($id);
                $same = $now !== null && $now->status === 'draft' && hash_equals($stamps['checklist'], (string) ($req->field('bench_was') ?? ''));
                $moved = [];
                foreach ($drawn as $no => $d) {
                    if (($stamps['lines'][$no] ?? '') !== $d['was']) {
                        $same = false;
                        if (($stamps['identity'][$no] ?? '') !== $d['identity']) {
                            $moved[] = $no;
                        }
                    }
                }
                if ($same && !$retried) {
                    $retried = true;
                    $version = $now->version;
                    continue;
                }
                // What was typed stays, except on lines whose item or quantity changed (their findings are for other figures).
                $typed = array_filter($req->post, static function ($k) use ($moved): bool {
                    return preg_match('/^b_([0-9]+)_/', (string) $k, $m) !== 1 || !in_array((int) $m[1], $moved, true);
                }, ARRAY_FILTER_USE_KEY);
                return $this->benchPage($ctx, $id, 409, new CwException('version_conflict', Words::say('RECEIPT_ERROR', 'version_conflict_bench',
                    $moved === [] ? Words::RECEIPT_ERROR['bench_the_delivery'] : ReceiptPlan::lineList($moved),
                    $moved === [] ? '' : Words::say('RECEIPT_ERROR', 'bench_except', ReceiptPlan::lineList($moved))), 409), null, ['typed' => $typed]);
            }
        }
        $next = $req->field('next') === '1' ? ['from' => $from + self::BENCH_PAGE] : ($from > 1 ? ['from' => $from] : []);
        $anchor = $req->field('next') === '1' || $lines === [] ? '' : '#line-' . array_key_last($lines);
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id . '/bench', $next + ['notice' => 'checked']) . $anchor);
    }

    /** POST invoice: the supplier invoice of a draft set by someone who did not key it (GoodsReceipts::setInvoice, I172). */
    public function invoice(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, function (GoodsReceipts $svc, int $id, int $version) use ($ctx): string {
            $fields = [];
            foreach (['invoice_number', 'invoice_date', 'delivery_note'] as $k) {
                if ($ctx->req->field($k) !== null) {
                    $fields[$k] = $ctx->req->field($k);
                }
            }
            $svc->setInvoice($ctx->caller(), $id, $version, $fields);
            return 'invoice_set';
        });
    }

    /**
     * Whether the bench fields sent for a line differ from what is stored (a change counts the line, I168).
     *
     * @param array<string, mixed> $f
     * @param array<string, mixed> $s a lines() row
     */
    private static function benchChanged(array $f, array $s): bool
    {
        $text = static fn (mixed $v): string => trim((string) ($v ?? ''));
        $num = static fn (mixed $v): string => $text($v) === '' ? '0' : (string) (PoLinesFile::wholeNumber($text($v)) ?? $text($v));
        foreach (['stamp_on_pack', 'stamp_type', 'stamp_code', 'unstamped_action', 'pre_october_evidence'] as $k) {
            if ($f[$k] !== null && $text($f[$k]) !== $text($s[$k])) {
                return true;
            }
        }
        foreach (['short_units', 'over_units', 'damaged_units', 'wrong_item_units', 'unstamped_units'] as $k) {
            if ($f[$k] !== null && $num($f[$k]) !== (string) (int) $s[$k]) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------------------------------

    /**
     * The receipt's page: the editor for its creator while it is a draft, else the read-only view; also after a refused form (the
     * error shown, answered under its status). $extra: typed, q, choices, packs, importErrors, skipped.
     *
     * @param array<string, mixed> $extra
     */
    public function page(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice = null, array $extra = []): HtmlResponse
    {
        $docs = $ctx->documents();
        $doc = $docs->find($id);
        if ($doc === null || $doc->docType !== 'GRN') {
            return $ctx->error(404, 'unknown_receipt', Words::RECEIPT_ERROR['unknown_receipt']);
        }
        if ($doc->reversesId !== null) {
            $doc = $docs->get($doc->reversesId);
        }
        $svc = $ctx->goodsReceipts();
        $db = $ctx->db;
        $me = $ctx->me();
        $gr = $svc->header($doc->id) ?? [];
        $canPost = Documents::mayPost($me->roles, 'GRN');
        $editor = $doc->status === 'draft' && $canPost && $doc->createdBy === $me->id;
        $plan = $doc->status === 'draft' ? $svc->plan($doc->id) : null;
        $supplier = $db->one('SELECT id, code, name, status FROM supplier WHERE id = ?', [(int) ($gr['supplier_id'] ?? 0)]) ?? [];
        $supplierName = (string) ($supplier['name'] ?? '');
        $lines = $this->lineRows($ctx, $doc->id, $plan);
        $products = self::products($lines);
        if ($plan !== null) {
            // The plan's own sentences, said again in the screens' words (Ui\ReceiptWords; one it does not know stays as it is).
            $plan['problems'] = array_map(static fn (array $p): array => ['message' => ReceiptWords::one((string) $p['message'], $products, $supplierName)] + $p,
                $plan['problems']);
            $plan['warnings'] = ReceiptWords::plain(array_map('strval', $plan['warnings']), $products, $supplierName);
            foreach ($lines as &$l) {
                $l['problems'] = ReceiptWords::plain(array_map('strval', $l['problems']), $products, $supplierName);
            }
            unset($l);
        }
        $names = [];
        foreach ($db->all('SELECT id, display_name FROM staff_user WHERE id IN (?, ?, ?, ?)', [$doc->createdBy ?? 0, $doc->postedBy ?? 0, $doc->cancelledBy ?? 0,
            (int) ($gr['checked_by'] ?? 0)]) as $u) {
            $names[(int) $u['id']] = (string) $u['display_name'];
        }
        $checkedLines = count(array_filter($lines, static fn (array $l): bool => $l['checked_at'] !== null));
        $data = [
            'doc' => $doc,
            'gr' => $gr,
            'supplier' => $supplier,
            'title' => self::title($doc->number, $doc->status, $supplierName),
            'po' => ($gr['po_document_id'] ?? null) === null ? null : $db->one('SELECT d.id, d.number, p.state FROM document d JOIN purchase_order p ON p.document_id = d.id WHERE d.id = ?',
                [(int) $gr['po_document_id']]),
            'lines' => $lines,
            'plan' => $plan,
            'totals' => self::totals($lines, $plan),
            'receivedLocal' => ($gr['received_at'] ?? null) === null ? '' : self::local((string) $gr['received_at']),
            'files' => self::fileRows($db->all('SELECT f.id, f.original_name, f.mime, f.size_bytes, df.role, df.attached_at FROM document_file df JOIN stored_file f ON f.id = df.file_id '
                . 'WHERE df.document_id = ? ORDER BY df.attached_at, f.id', [$doc->id])),
            'incidents' => (new Incidents($db))->list(null, null, $doc->id),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
            'problems' => is_array($error?->detail['problems'] ?? null)
                ? ReceiptWords::plain(array_map('strval', array_column($error->detail['problems'], 'message')), $products, $supplierName) : [],
            'importErrors' => $extra['importErrors'] ?? [],
            'skipped' => $extra['skipped'] ?? [],
            'fileRoles' => Words::RECEIPT_FILE,
            'canPost' => $canPost,
            'canBench' => $canPost && $doc->status === 'draft',
            'people' => [
                'created' => $names[$doc->createdBy ?? 0] ?? $doc->createdActor,
                'posted' => $doc->postedBy === null ? $doc->postedActor : ($names[$doc->postedBy] ?? null),
                'cancelled' => $doc->cancelledBy === null ? null : ($names[$doc->cancelledBy] ?? null),
                'checked' => ($gr['checked_by'] ?? null) === null ? null : ($names[(int) $gr['checked_by']] ?? null),
            ],
            'benchState' => $doc->status !== 'draft' ? null : self::benchState(['checked_at' => $gr['checked_at'] ?? null, 'paperwork_ok' => $gr['paperwork_ok'] ?? null,
                'lines' => count($lines), 'checked_lines' => $checkedLines]),
            'maxMb' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
        ];
        if ($editor) {
            $poLines = [];
            if ($data['po'] !== null) {
                foreach ($db->all("SELECT pl.line_no, dl.qty, pl.received_units, s.code, s.name FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id "
                    . "AND dl.line_no = pl.line_no LEFT JOIN sku s ON s.id = dl.sku_id WHERE pl.document_id = ? AND pl.kind = 'item' ORDER BY pl.line_no", [(int) $data['po']['id']]) as $p) {
                    $poLines[(int) $p['line_no']] = Words::say('RECEIPT', 'po_line_option', (int) $p['line_no'], (string) ($p['name'] ?? $p['code']),
                        max(0, (int) $p['qty'] - (int) $p['received_units']));
                }
            }
            $noLines = $lines === [];
            $linked = false;
            foreach ($lines as $l) {
                $linked = $linked || $l['po_line_no'] !== null;
            }
            $orders = $linked ? [] : $svc->receivableOrders((int) ($gr['supplier_id'] ?? 0));
            if ($data['po'] !== null && !$linked && !in_array((int) $data['po']['id'], array_map(static fn (array $o): int => (int) $o['id'], $orders), true)) {
                // The order it names, even when it expects nothing any more (closed, received): the select must not drop it unseen.
                array_unshift($orders, ['id' => (int) $data['po']['id'], 'number' => $data['po']['number'], 'outstanding' => 0]);
            }
            $data += [
                'editable' => self::editorFits(count($lines)),
                'maxLines' => self::editorMaxLines(),
                'typed' => $extra['typed'] ?? [],
                'choices' => $extra['choices'] ?? null,
                'q' => $extra['q'] ?? '',
                'packs' => (int) ($extra['packs'] ?? 1),
                'price' => (string) ($extra['price'] ?? ''),
                'stamp' => $svc->deskStamp($doc->id),
                'refusedPost' => $error !== null && is_array($error->detail['problems'] ?? null),
                'reaches' => self::reaches($this->receiptSites($ctx)),
                'poLines' => $poLines,
                'suppliers' => $noLines ? $db->all("SELECT id, code, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id") : [],
                'orders' => $orders,
                'linked' => $linked,
                'modeChoices' => GoodsReceipts::MODE_CHOICES,
            ];
            return $ctx->page('receipt_edit', $data, $status, ['title' => $data['title'], 'active' => 'receiving', 'notice' => $notice]);
        }
        $data['canSetInvoice'] = $canPost && $doc->status === 'draft' && $doc->createdBy !== $me->id;
        return $ctx->page('receipt', $data + $this->viewData($ctx, $doc), $status, ['title' => $data['title'], 'active' => 'receiving', 'notice' => $notice]);
    }

    /** "GRN-000001 – Elux Wholesale"; "Delivery from Elux Wholesale (not booked in yet)" for a draft; "Delivery from …" for one cancelled before. */
    public static function title(?string $number, string $status, string $supplier): string
    {
        if ($number !== null) {
            return Words::say('RECEIPT', 'title', $number, $supplier);
        }
        return Words::say('RECEIPT', $status === 'draft' ? 'title_draft' : 'title_plain', $supplier);
    }

    /**
     * Which websites the editor's selling mode reaches, in a sentence (a website whose stock link is not on yet is named so).
     *
     * @param list<array{code: string, name: string, writer: bool}> $sites
     */
    public static function reaches(array $sites): string
    {
        if ($sites === []) {
            return Words::RECEIPT['reaches_none'];
        }
        return Words::say('RECEIPT', 'reaches', Words::andList(array_map(static fn (array $s): string => $s['writer'] ? $s['name'] : Words::say('RECEIPT', 'reaches_off', $s['name']),
            $sites)));
    }

    /** @param array<int, array<string, mixed>> $lines @return array<string, string> CW number => product name (Ui\ReceiptWords names the products) */
    private static function products(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if ($l['sku_code'] !== null) {
                $out[(string) $l['sku_code']] = (string) $l['sku_name'];
            }
        }
        return $out;
    }

    /**
     * The files as the page shows them: what each is in words, its kind ("PDF", "Photo (JPEG)") and size ("12 KB").
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function fileRows(array $rows): array
    {
        return array_map(static fn (array $f): array => $f + [
            'role_word' => Words::of('RECEIPT_FILE', (string) $f['role']),
            'kind' => match ((string) ($f['mime'] ?? '')) {
                'application/pdf' => Words::RECEIPT['kind_pdf'],
                'image/jpeg' => Words::RECEIPT['kind_jpeg'],
                'image/png' => Words::RECEIPT['kind_png'],
                default => Words::RECEIPT['kind_other'],
            },
            'size' => Html::size((int) ($f['size_bytes'] ?? 0)),
        ], $rows);
    }

    /**
     * The websites a receipt's selling mode reaches (site_writer.receipt_mode_sites, I152), with whether their site stock writer
     * is on: the editor says so beside the mode column.
     *
     * @return list<array{code: string, name: string, writer: bool}>
     */
    private function receiptSites(Context $ctx): array
    {
        $ids = array_keys((new \CW\SiteWriter\SiteModes($ctx->db))->receiptChannels($ctx->settings()));
        if ($ids === []) {
            return [];
        }
        return array_map(static fn (array $r): array => ['code' => (string) $r['code'], 'name' => (string) $r['name'], 'writer' => (int) $r['site_writer'] === 1],
            $ctx->db->all('SELECT code, name, site_writer FROM channel WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id', $ids));
    }

    /**
     * The bench check of one receipt: a page of BENCH_PAGE lines (`?from=`), each a card with the duty stamp check and the
     * exceptions, and the delivery's checklist; the photo upload. Drafts only.
     *
     * @param array<string, mixed> $extra typed
     */
    public function benchPage(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice = null, array $extra = []): HtmlResponse
    {
        $doc = $ctx->documents()->find($id);
        if ($doc === null || $doc->docType !== 'GRN' || $doc->reversesId !== null) {
            return $ctx->error(404, 'unknown_receipt', Words::RECEIPT_ERROR['unknown_receipt']);
        }
        if ($doc->status !== 'draft') {
            return HtmlResponse::redirect('/ui/receiving/' . $doc->id);
        }
        $svc = $ctx->goodsReceipts();
        $gr = $svc->header($doc->id) ?? [];
        $plan = $svc->plan($doc->id);
        $all = $this->lineRows($ctx, $doc->id, $plan);
        $supplier = $ctx->db->one('SELECT id, code, name, status FROM supplier WHERE id = ?', [(int) ($gr['supplier_id'] ?? 0)]) ?? [];
        $products = self::products($all);
        $from = max(1, UiRequest::id($ctx->req->param('from') ?? $ctx->req->field('from')) ?? 1);
        $page = array_slice($all, $from - 1, self::BENCH_PAGE, true);
        foreach ($page as &$l) {
            $l['problems'] = ReceiptWords::plain(array_map('strval', $l['problems']), $products, (string) ($supplier['name'] ?? ''));
        }
        unset($l);
        $barcodes = [];
        $skus = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['sku_id'], $page)));
        if ($skus !== []) {
            foreach ($ctx->db->all('SELECT sku_id, barcode, units_per_scan FROM sku_barcode WHERE is_usable = 1 AND sku_id IN (' . implode(', ', array_fill(0, count($skus), '?')) . ') '
                . 'ORDER BY sku_id, units_per_scan, barcode', $skus) as $b) {
                $barcodes[(int) $b['sku_id']]['labels'][] = (int) $b['units_per_scan'] > 1
                    ? Words::say('BENCH', 'barcode_case', (string) $b['barcode'], (int) $b['units_per_scan']) : (string) $b['barcode'];
                $barcodes[(int) $b['sku_id']]['codes'][] = (string) $b['barcode'];
            }
        }
        $stamps = $svc->benchStamps($doc->id);
        $todayUk = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');
        $cutoff = $plan['header']['cutoff'];
        return $ctx->page('receipt_bench', [
            'doc' => $doc,
            'gr' => $gr,
            'stamps' => $stamps,
            'title' => Words::say('BENCH', 'title', (string) ($supplier['name'] ?? '')),
            'arrivedWhen' => Html::when((string) $gr['received_at']),
            'receivedDay' => $plan['header']['received_day'],
            'receivedToday' => $plan['header']['received_uk'] === $todayUk,
            'cutoffDay' => $cutoff === null ? null : ReceiptPlan::day((string) $cutoff),
            'lastDay' => $cutoff === null ? null : ReceiptPlan::day((new \DateTimeImmutable((string) $cutoff))->modify('-1 day')->format('Y-m-d')),
            'supplier' => $supplier,
            'lines' => $page,
            'count' => count($all),
            'from' => $from,
            'pageSize' => self::BENCH_PAGE,
            'barcodes' => $barcodes,
            'plan' => $plan,
            'typed' => $extra['typed'] ?? [],
            'error' => $error === null ? null : self::plain($error),
            'refusal' => $plan['header']['refusal_regime'],
            'cutoff' => $cutoff,
            'actions' => Words::UNSTAMPED_ACTION,
            'stampTypes' => Words::STAMP_TYPE,
            'photoRoles' => ['photo' => Words::RECEIPT_FILE['photo'], 'evidence' => Words::RECEIPT_FILE['evidence'], 'delivery_note' => Words::RECEIPT_FILE['delivery_note']],
            'photos' => self::fileRows($ctx->db->all("SELECT f.id, f.original_name, f.mime, f.size_bytes, df.role, df.attached_at FROM document_file df JOIN stored_file f ON f.id = df.file_id "
                . "WHERE df.document_id = ? AND df.role IN ('photo', 'evidence', 'delivery_note') ORDER BY df.attached_at, f.id", [$doc->id])),
            'checkedBy' => ($gr['checked_by'] ?? null) === null ? null : $ctx->db->value('SELECT display_name FROM staff_user WHERE id = ?', [(int) $gr['checked_by']]),
            'maxMb' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
        ], $status, ['title' => Words::say('BENCH', 'title', (string) ($supplier['name'] ?? '')), 'active' => 'bench', 'notice' => $notice]);
    }

    /** What each file of a delivery is (the upload's choice; the words of GoodsReceipts::FILE_ROLES). */
    public const FILE_ROLE_LABELS = Words::RECEIPT_FILE;
    /** Why a line gives its product that selling mode (the words of SellingModes' sources). */
    public const MODE_SOURCES = Words::MODE_SOURCE;

    /**
     * The services' refusals of these pages in words, by error code (the model of ReviewController::plain): the kernel's own codes
     * take Words::ERROR, the deliveries' RECEIPT_ERROR; a refused posting says how many things to settle (they are listed under
     * "Before it can be booked in"); a code without words shows the service's message as a sentence, then "Nothing was saved.".
     * The services' messages themselves are unchanged (they reach the tests and the logs).
     */
    public static function plain(CwException $e): string
    {
        $code = $e->errorCode;
        $problems = is_array($e->detail['problems'] ?? null) ? count($e->detail['problems']) : 0;
        $x = [];
        return match (true) {
            // The page's own refusals are made in words already.
            in_array($code, ['scan_pending', 'not_found', 'import_refused', 'choose_supplier', 'reason_required'], true) => $e->getMessage(),
            $code === 'version_conflict' && (in_array($e->getMessage(), [Words::RECEIPT_ERROR['version_conflict'], Words::RECEIPT_ERROR['version_conflict_choice']], true)
                || str_starts_with($e->getMessage(), (string) strstr(Words::RECEIPT_ERROR['version_conflict_bench'], '(', true))) => $e->getMessage(),
            $code === 'version_conflict' => Words::RECEIPT_ERROR['version_conflict_choice'],
            $problems === 1 => Words::RECEIPT_ERROR['not_ready_one'],
            $problems > 1 => Words::say('RECEIPT_ERROR', 'not_ready', $problems),
            $code === 'too_large' => Words::say('RECEIPT_ERROR', 'too_large', intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576)),
            $code === 'too_many_rows' => Words::say('RECEIPT_ERROR', 'too_many_rows', \CW\Receiving\ReceiptLinesFile::MAX_ROWS),
            $code === 'note_required' => Words::noteRequired($e->detail),
            $code === 'duplicate_invoice' && preg_match('/^this supplier\'s invoice (\S+) is already on (.+?): a supplier invoice is received once/s', $e->getMessage(), $x) === 1
                => Words::say('RECEIPT_ERROR', 'duplicate_invoice', $x[1], ReceiptWords::receiptLabel($x[2])),
            $code === 'invoice_copy_elsewhere' && preg_match('/^this file is already the supplier invoice of (.+?): a supplier\'s invoice is received once/s', $e->getMessage(), $x) === 1
                => Words::say('RECEIPT_ERROR', 'invoice_copy_elsewhere', ReceiptWords::receiptLabel($x[1])),
            $code === 'bad_field' && ($e->detail['field'] ?? null) === 'status' => Words::RECEIPT_ERROR['bad_status'],
            $code === 'bad_field' && ($e->detail['field'] ?? null) === 'note' => Words::RECEIPT_ERROR['note_length'],
            isset(Words::RECEIPT_ERROR[$code]) && !str_contains(Words::RECEIPT_ERROR[$code], '%') => Words::RECEIPT_ERROR[$code],
            isset(Words::ERROR[$code]) && !str_contains(Words::ERROR[$code], '%') => Words::ERROR[$code],
            default => Words::say('BUY_ERROR', 'other', PurchaseOrdersController::sentence($e->getMessage())),
        };
    }

    /**
     * The lines as the pages draw them: the document and receipt line, the item, stock now, and the plan's view of it (draft) or what
     * the posting booked (posted).
     *
     * @param array<string, mixed>|null $plan
     * @return array<int, array<string, mixed>> line_no => row
     */
    private function lineRows(Context $ctx, int $id, ?array $plan): array
    {
        $rows = $ctx->db->all(
            'SELECT dl.line_no, dl.sku_id, dl.qty, dl.unit_cost, dl.amount, dl.description, g.*, s.code AS sku_code, s.name AS sku_name, s.merged_into_sku_id '
            . 'FROM document_line dl JOIN grn_line g ON g.document_id = dl.document_id AND g.line_no = dl.line_no LEFT JOIN sku s ON s.id = dl.sku_id '
            . 'WHERE dl.document_id = ? ORDER BY dl.line_no',
            [$id],
        );
        $stock = $ctx->goodsReceipts()->stockNow(array_map(static fn (array $r): int => (int) $r['sku_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $no = (int) $r['line_no'];
            $p = $plan['lines'][$no] ?? null;
            $r['units'] = (int) $r['packs'] * (int) $r['units_per_pack'];
            $r['pack_label'] = PurchaseOrdersController::pack((string) $r['purchase_unit'], (int) $r['units_per_pack']);
            $r['packs_text'] = self::packsText((int) $r['packs'], (string) $r['purchase_unit'], (int) $r['units_per_pack']);
            $r['price'] = PoMath::price((string) $r['pack_price']);
            $r['net'] = PoMath::money(PoMath::e2((string) $r['amount']));
            $r['stock'] = $stock[(int) $r['sku_id']] ?? ['on_hand' => 0, 'available' => 0];
            if ($p !== null) {
                $r['split'] = $p['split'];
                $r['stamp_req'] = $p['stamp_required'];
                $r['duty_unknown'] = $p['duty_unknown'];
                $r['mode_default'] = SellingModes::resolve($p['mode_current'], 'default', self::fallback($ctx));
                $r['mode_now'] = $p['mode_current']['mode'];
                $r['mode_resolved'] = $p['mode'];
                $r['duty_text'] = $p['expected_duty_pence'] === null ? null : ReceiptMath::money($p['expected_duty_pence']);
                $r['problems'] = $p['problems'];
                $r['warnings'] = $p['warnings'];
            } else {
                $r['split'] = ['accepted' => (int) $r['accepted_units'], 'verify' => (int) $r['verify_units'], 'quarantine' => (int) $r['quarantine_units'],
                    'refused' => (int) $r['refused_units'], 'short' => (int) $r['short_units'], 'po_units' => (int) $r['po_units']];
                $r['stamp_req'] = $r['stamp_required'] === null ? null : (int) $r['stamp_required'] === 1;
                $r['duty_unknown'] = false;
                $r['mode_default'] = null;
                $r['mode_now'] = null;
                $r['mode_resolved'] = $r['mode_source'] === null ? null : ['mode' => $r['selling_mode'] === null ? Words::RECEIPT['mode_kept'] : (string) $r['selling_mode'],
                    'source' => (string) $r['mode_source']];
                $r['duty_text'] = $r['expected_duty'] === null ? null : Html::money((string) $r['expected_duty']);
                $r['problems'] = [];
                $r['warnings'] = [];
            }
            $r['went'] = self::went($r['split']);
            $r['findings'] = self::findings($r);
            $out[$no] = $r;
        }
        return $out;
    }

    /** "13 into stock, 2 set aside to check" (where a line's items went, or will go). @param array<string, int> $split */
    public static function went(array $split): string
    {
        $parts = [];
        foreach (['accepted' => 'went_stock', 'verify' => 'went_aside', 'quarantine' => 'went_quarantine', 'refused' => 'went_refused', 'short' => 'went_short'] as $k => $word) {
            if ((int) ($split[$k] ?? 0) > 0) {
                $parts[] = Words::say('RECEIPT', $word, (int) $split[$k]);
            }
        }
        return $parts === [] ? Words::say('RECEIPT', 'went_stock', 0) : implode(', ', $parts);
    }

    /** What the bench found on a line: "short 2, damaged 1" ('' when nothing). @param array<string, mixed> $l */
    private static function findings(array $l): string
    {
        $parts = [];
        foreach (['short_units' => 'finding_short', 'over_units' => 'finding_over', 'damaged_units' => 'finding_damaged', 'wrong_item_units' => 'finding_wrong',
            'unstamped_units' => 'finding_unstamped'] as $k => $word) {
            if ((int) ($l[$k] ?? 0) > 0) {
                $parts[] = Words::say('RECEIPT', 'finding', Words::RECEIPT[$word], (int) $l[$k]);
            }
        }
        if ((int) ($l['unstamped_units'] ?? 0) > 0 && ($l['unstamped_action'] ?? null) !== null) {
            $parts[count($parts) - 1] .= ' (' . mb_strtolower(Words::of('UNSTAMPED_ACTION', (string) $l['unstamped_action'])) . ')';
        }
        return implode(', ', $parts);
    }

    /** "2 boxes of 10", "1 case of 5", "12 items" (each). */
    public static function packsText(int $packs, string $unit, int $upp): string
    {
        if ($upp === 1 && in_array($unit, ['each', 'unit'], true)) {
            return Words::say('RECEIPT_ADDED', $packs === 1 ? 'unit_one' : 'unit_many', $packs);
        }
        if (in_array($unit, ['each', 'unit', ''], true)) {
            $unit = 'pack'; // "each" of a pack of 5 reads as nonsense
        }
        $plural = $packs === 1 ? $unit : (preg_match('/(s|x|z|ch|sh)$/i', $unit) === 1 ? $unit . 'es'
            : (preg_match('/[^aeiou]y$/i', $unit) === 1 ? substr($unit, 0, -1) . 'ies' : $unit . 's'));
        return Words::say('RECEIPT_ADDED', 'pack_of', $packs, $plural, $upp);
    }

    private static function fallback(Context $ctx): string
    {
        $s = $ctx->settings();
        return $s->has('receiving.mode_after_out_of_stock') ? (string) $s->get('receiving.mode_after_out_of_stock') : 'From-Warehouse';
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed>|null $plan
     * @return array<string, mixed>
     */
    private static function totals(array $lines, ?array $plan): array
    {
        $t = ['lines' => count($lines), 'units' => 0, 'accepted' => 0, 'verify' => 0, 'quarantine' => 0, 'refused' => 0, 'short' => 0, 'net_e2' => 0];
        foreach ($lines as $l) {
            $t['units'] += $l['units'];
            foreach (['accepted', 'verify', 'quarantine', 'refused', 'short'] as $k) {
                $t[$k] += (int) ($l['split'][$k] ?? 0);
            }
            $t['net_e2'] += PoMath::e2((string) $l['amount']);
        }
        $t['net'] = PoMath::money($t['net_e2']);
        $t['duty'] = $plan === null ? null : ReceiptMath::money((int) $plan['totals']['expected_duty_pence']);
        $t['size'] = $t['lines'] === 1 ? Words::say('RECEIPT', 'size_one', $t['units']) : Words::say('RECEIPT', 'size_many', $t['lines'], $t['units']);
        return $t;
    }

    /**
     * What the read-only view adds: the reversal, the review tasks as sentences and the reviewer's box (its two answers and what
     * each does, or why this person may not decide), the reversal form.
     *
     * @return array<string, mixed>
     */
    private function viewData(Context $ctx, Document $doc): array
    {
        $db = $ctx->db;
        $me = $ctx->me();
        $rev = $db->one("SELECT * FROM document WHERE reverses_id = ? AND status <> 'cancelled'", [$doc->id]);
        $revDoc = $rev === null ? null : Document::fromRow($rev);
        $ids = array_values(array_filter([$doc->id, $revDoc?->id]));
        $tasks = $db->all('SELECT t.*, o.display_name AS opened_by_name, x.display_name AS decided_by_name FROM review_task t LEFT JOIN staff_user o ON o.id = t.opened_by '
            . "LEFT JOIN staff_user x ON x.id = t.decided_by WHERE t.subject_type = 'document' AND t.subject_id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY t.id',
            $ids);
        $now = gmdate('Y-m-d H:i:s');
        // A rejected review reverses the receipt, which is refused while its order is closed (I145): say so before the reviewer
        // presses reject (open owner question, I176).
        $poClosed = $doc->status === 'posted' && ($po = $db->one('SELECT d.number, p.state FROM goods_receipt g JOIN document d ON d.id = g.po_document_id '
            . 'JOIN purchase_order p ON p.document_id = d.id WHERE g.document_id = ?', [$doc->id])) !== null && $po['state'] === 'closed'
            && (int) $db->value('SELECT COALESCE(SUM(po_units), 0) FROM grn_line WHERE document_id = ?', [$doc->id]) > 0 ? (string) $po['number'] : null;
        $decide = [];
        foreach ($tasks as &$t) {
            $t['overdue'] = $t['state'] === 'open' && (string) $t['due_at'] < $now;
            $isReversal = (int) $t['subject_id'] !== $doc->id;
            $t['of'] = Words::RECEIPT[$isReversal ? 'of_reversal' : 'of_delivery'];
            if ($t['state'] === 'open' && ($me->can('documents.review') || $me->can('documents.approve'))) {
                $subject = $isReversal ? $revDoc : $doc;
                $no = $subject === null ? null : $ctx->documents()->refusalFor($me->id, $me->roles, $subject, (string) $t['kind']);
                $decide[] = [
                    'task' => $t,
                    'refusal' => $subject === null ? null : self::refusal($no, $subject, $me->id),
                    'reversal' => $isReversal,
                    'title' => Words::RECEIPT[$isReversal ? 'check_reversal_title' : 'check_title'],
                    'text' => Words::say('RECEIPT', $isReversal ? 'check_text_reversal' : 'check_text', Html::when((string) $t['opened_at']),
                        (string) ($t['opened_by_name'] ?? ''), Html::when((string) $t['due_at'])),
                    'poClosed' => !$isReversal ? $poClosed : null,
                ];
            }
        }
        unset($t);
        $canPost = Documents::mayPost($me->roles, 'GRN');
        return [
            'reversal' => $revDoc,
            'tasks' => $tasks,
            'decide' => $decide,
            'reverseReasons' => $canPost && $doc->status === 'posted' && $revDoc === null
                ? $db->all("SELECT code, label, needs_note FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 AND system_only = 0 AND is_active = 1 "
                    . "AND code NOT IN ('po_amended', 'supplier_cannot_supply', 'not_needed') ORDER BY sort_order, code") : [],
            'canAttach' => $canPost && $doc->status !== 'cancelled',
            'canPostDraft' => $canPost && $doc->status === 'draft',
            'canResolve' => $me->can('incidents.resolve'),
        ];
    }

    /**
     * Why this person may not decide a delivery's check, in words: their own booking in or keying (RECEIPT's words), their bench
     * check or the invoice they set (ReviewInvolvement, I133, I172), else the refusal by its code (Words::refusal).
     *
     * @param array{code: string, message: string}|null $no Documents::refusalFor
     */
    private static function refusal(?array $no, Document $subject, int $me): ?string
    {
        if ($no === null) {
            return null;
        }
        if ($no['code'] !== 'own_document') {
            return Words::refusal($no);
        }
        return match (true) {
            $subject->postedBy === $me => Words::RECEIPT['refusal_posted'],
            $subject->createdBy === $me || $subject->submittedBy === $me => Words::RECEIPT['refusal_created'],
            str_contains($no['message'], 'goods-in bench') => Words::RECEIPT['refusal_bench'],
            str_contains($no['message'], 'supplier invoice') => Words::RECEIPT['refusal_invoice'],
            default => Words::refusal($no),
        };
    }

    /**
     * A POST that carries the version the page was drawn with; $fn returns the notice key.
     *
     * @param \Closure(GoodsReceipts, int, int): string $fn
     */
    private function act(Context $ctx, \Closure $fn): HtmlResponse
    {
        $id = $ctx->id();
        $version = UiRequest::id($ctx->req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        try {
            $notice = $fn($ctx->goodsReceipts(), $id, $version);
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->page($ctx, $id, 409, new CwException('version_conflict', Words::RECEIPT_ERROR['version_conflict_choice'], 409));
            }
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id, ['notice' => $notice]));
    }

    /**
     * The editor's lines with the table's edits applied (rows 1..$count): packs (0 removes the line), pack price, units per pack (a
     * line without a supplier item), PO line, selling mode, note.
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
            $p = PoLinesFile::wholeNumber(trim($req->field("line_{$n}_packs") ?? ''));
            if ($p === null) {
                throw new CwException('bad_line', "line {$n}, packs: a whole number (0 removes the line)", 422, ['line' => $n]);
            }
            if ($p === 0) {
                continue;
            }
            $l['packs'] = $p;
            $price = $req->field("line_{$n}_price");
            if ($price !== null) {
                $l['pack_price'] = trim($price) === '' ? null : $price;
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
            }
            $po = $req->field("line_{$n}_po");
            if ($po !== null) {
                $l['po_line_no'] = $po === '' || $po === '0' ? 0 : $po;
            }
            $mode = $req->field("line_{$n}_mode");
            if ($mode !== null) {
                $l['mode_choice'] = $mode;
            }
            $note = $req->field("line_{$n}_note");
            if ($note !== null) {
                $l['description'] = $note;
            }
            $out[] = $l;
        }
        return $out;
    }

    /** @return array{path: string, name: string} the form's `file`, or a CwException (no file, too large, failed) */
    private static function upload(Context $ctx): array
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
        return ['path' => $file['path'], 'name' => $file['name']];
    }

    /** "2026-10-07T09:30" in UK time of a stored UTC time (a datetime-local value). */
    public static function local(string $dbTime): string
    {
        return \CW\Clock::fromDb($dbTime)->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d\TH:i');
    }

    /** @return array{state: ?string, supplier: ?int, q: string} */
    private static function filters(UiRequest $req): array
    {
        return [
            'state' => in_array($req->param('state'), self::STATE_FILTERS, true) ? $req->param('state') : null,
            'supplier' => UiRequest::id($req->param('supplier')),
            'q' => mb_substr(trim($req->param('q') ?? ''), 0, 100),
        ];
    }

    /**
     * @param array{state: ?string, supplier: ?int, q: string} $f
     * @return list<array<string, mixed>>
     */
    private function rows(Context $ctx, array $f): array
    {
        $where = ["d.doc_type = 'GRN'", 'd.reverses_id IS NULL'];
        $params = [];
        if ($f['state'] === 'checked') {
            // Checked at the bench, not booked in yet: the paperwork looks right and every line is checked (Home's "to book in").
            $where[] = "d.status = 'draft' AND g.paperwork_ok = 1 AND EXISTS (SELECT 1 FROM grn_line l WHERE l.document_id = d.id) "
                . 'AND NOT EXISTS (SELECT 1 FROM grn_line l WHERE l.document_id = d.id AND l.checked_at IS NULL)';
        } elseif ($f['state'] !== null) {
            $where[] = 'd.status = ?';
            $params[] = $f['state'];
        }
        if ($f['supplier'] !== null) {
            $where[] = 'g.supplier_id = ?';
            $params[] = $f['supplier'];
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(d.number LIKE ? OR d.external_ref LIKE ? OR p.number LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $rows = $ctx->db->all(
            'SELECT d.id, d.number, d.status, d.review_state, d.external_ref, g.received_at, g.paper_sheet, g.checked_at, g.paperwork_ok, s.code AS supplier_code, '
            . 's.name AS supplier_name, (SELECT COUNT(*) FROM grn_line l WHERE l.document_id = d.id AND l.checked_at IS NOT NULL) AS checked_lines, '
            . 'p.number AS po_number, u.display_name AS created_by_name, (SELECT COUNT(*) FROM document_line l WHERE l.document_id = d.id) AS `lines`, '
            . '(SELECT COALESCE(SUM(l.qty), 0) FROM document_line l WHERE l.document_id = d.id) AS units, '
            . "(SELECT COUNT(*) FROM incident i WHERE i.document_id = d.id AND i.status = 'open') AS open_incidents "
            . 'FROM document d JOIN goods_receipt g ON g.document_id = d.id JOIN supplier s ON s.id = g.supplier_id LEFT JOIN document p ON p.id = g.po_document_id '
            . 'LEFT JOIN staff_user u ON u.id = d.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . self::LIST_LIMIT,
            $params,
        );
        return $rows;
    }
}
