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
use CW\Ui\UiRequest;

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
 */
final class ReceivingController
{
    public const NOTICES = [
        'created' => 'Receipt started: copy the order down, import the supplier\'s sheet or scan the goods, attach the invoice, then the bench checks it.',
        'saved' => 'Saved.',
        'added' => 'Line added (and every change in the table saved).',
        'incremented' => 'One more pack on the line that was already there (and every change in the table saved).',
        'copied' => 'The order\'s outstanding lines were copied down: change what arrived differently.',
        'imported' => 'Lines imported from the supplier\'s sheet.',
        'attached' => 'File attached.',
        'checked' => 'Bench check saved.',
        'invoice_set' => 'The supplier invoice is set on this receipt.',
        'posted' => 'Posted: the stock is booked and a second person reviews the receipt within 3 days.',
        'cancelled' => 'Receipt cancelled: nothing was booked, and its invoice number is free again.',
        'reversed' => 'Reversed: its stock, its PO receipts and its incidents were taken back.',
        'approved_review' => 'Review approved.',
        'approved_posted' => 'Approved.',
        'rejected_review' => 'Rejected at review: the receipt was reversed (its stock and PO receipts taken back). Key it again if the goods are here.',
        'rejected_reversal' => 'Rejected: the rejection of the reversal is recorded and nothing changed (a reversal is never undone: key the receipt again).',
        'rejected_recorded' => 'Rejected at review: the rejection is recorded.',
        'rejected_approval' => 'Rejected: the request was cancelled.',
    ];
    public const STATE_FILTERS = ['draft' => 'draft (being keyed or checked)', 'posted' => 'posted', 'reversed' => 'reversed', 'cancelled' => 'cancelled'];
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
        return $ctx->page('receipts', [
            'rows' => $this->rows($ctx, $f),
            'filters' => $f,
            'states' => self::STATE_FILTERS,
            'suppliers' => $ctx->db->all('SELECT id, code, name, status FROM supplier ORDER BY name, id'),
            'newSuppliers' => $canPost ? $ctx->db->all("SELECT id, code, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id") : [],
            'orders' => $canPost ? $ctx->goodsReceipts()->receivableOrders() : [],
            'formKey' => $canPost ? ($ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey()) : null,
            'typed' => $error === null ? [] : $ctx->req->post,
            'limit' => self::LIST_LIMIT,
            'error' => $error,
        ], $status, ['title' => 'Receive + invoice', 'active' => 'receiving', 'notice' => self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null]);
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
                throw new CwException('bad_field', 'choose the supplier (or the purchase order the delivery is against)', 400, ['field' => 'supplier_id']);
            }
            $r = FormOnce::run($ctx, 'ui.grn.create', ['supplier_id' => $sid, 'po_id' => $poId, 'invoice_number' => $invoice, 'copy' => $copy],
                function (Db $db) use ($ctx, $sid, $poId, $invoice, $copy): OpResult {
                    $d = $ctx->goodsReceipts()->createDraft($ctx->caller(), $sid, ['invoice_number' => $invoice], $poId, $copy && $poId !== null);
                    return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id,
                        'redirect' => Html::url('/ui/receiving/' . $d->id, ['notice' => $copy && $poId !== null ? 'copied' : 'created'])]);
                });
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e->getMessage());
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
            $notice .= ' The bench check of ' . ReceiptPlan::lineList($nos) . ' was cleared (' . (count($nos) === 1 ? 'its' : 'their') . ' quantity or item changed): '
                . 'the bench counts ' . (count($nos) === 1 ? 'it' : 'them') . ' again.';
        }
        return $this->page($ctx, $id, 200, null, $notice);
    }

    /** "Added line 7: CW-000123 Name, 2 x case x10 = 20 units at £18.50 (now 40 units on the line)." */
    private function addedNotice(Context $ctx, int $id, string $kind, int $lineNo, int $unitsAdded, bool $case): ?string
    {
        $l = $ctx->db->one('SELECT g.packs, g.units_per_pack, g.purchase_unit, g.pack_price, s.code, s.name FROM grn_line g JOIN document_line dl ON dl.document_id = g.document_id '
            . 'AND dl.line_no = g.line_no JOIN sku s ON s.id = dl.sku_id WHERE g.document_id = ? AND g.line_no = ?', [$id, $lineNo]);
        if ($l === null) {
            return null;
        }
        $units = (int) $l['packs'] * (int) $l['units_per_pack'];
        $packs = self::packsText((int) $l['packs'], (string) $l['purchase_unit'], (int) $l['units_per_pack']);
        $item = "{$l['code']} " . mb_substr((string) $l['name'], 0, 60);
        $price = PoMath::e4((string) $l['pack_price']) > 0 ? ' at £' . PoMath::price((string) $l['pack_price']) . ' a pack' : ' (no price yet)';
        $text = $kind === 'added'
            ? "Added line {$lineNo}: {$item}, {$packs}" . ((int) $l['units_per_pack'] > 1 ? ' = ' . Html::int($units) . ' units' : '') . "{$price}."
            : "Line {$lineNo}, {$item}: +" . Html::int($unitsAdded) . " units (now {$packs}" . ((int) $l['units_per_pack'] > 1 ? ' = ' . Html::int($units) . ' units' : '') . ').';
        if ($case) {
            $text .= ' A case barcode that is not this supplier\'s pack: the line is in packs of ' . $l['units_per_pack'] . '; check its price.';
        }
        return $text . ' Every change in the table was saved too.';
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
            return $ctx->error(400, 'form_truncated', 'the form arrived incomplete (no line count): nothing was saved. Reload the page and try again.');
        }
        $count = (int) $count;
        $rows = $req->fieldsMatching('/^line_[1-9][0-9]{0,4}_packs$/');
        if ($editable && count($rows) < $count) {
            return $ctx->error(400, 'form_truncated', "the form arrived incomplete ({$count} lines sent, " . count($rows) . ' arrived): nothing was saved. '
                . 'A receipt of more than about ' . self::editorMaxLines() . ' lines is changed with the sheet import.');
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
                return $this->page($ctx, $id, 422, new CwException('scan_pending', "The scan box still holds \"{$q}\": press Add, or clear it, then post. Nothing was saved.",
                    422), null, ['typed' => $typed, 'q' => $q]);
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
                return $this->page($ctx, $id, 409, new CwException('version_conflict',
                    'This receipt was changed since you opened it (by you in another tab: here is the current data, with what you typed). Nothing was saved: check '
                    . 'the lines and save again.', 409), null, ['typed' => count($svc->lines($id)) === $count ? $typed : array_filter($typed, static fn ($k): bool =>
                    !str_starts_with((string) $k, 'line_'), ARRAY_FILTER_USE_KEY), 'q' => $q]);
            }
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['typed' => $typed, 'q' => $q]);
        }
        if ($result['status'] === 'choices') {
            return $this->page($ctx, $id, 200, null, $result['choice_note'] ?? null, ['choices' => $result['choices'], 'q' => $q, 'packs' => max(1, $packs),
                'price' => $price ?? '']);
        }
        if ($result['status'] === 'not_found') {
            return $this->page($ctx, $id, 422, new CwException('not_found', "Nothing matches \"{$q}\" (a barcode, this supplier's code, a CW code or words of the item "
                . 'name). Your other changes are saved.', 422), null, ['q' => $q]);
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
            return $this->page($ctx, $id, 422, new CwException('import_refused', 'Nothing was imported: the sheet has ' . count($r['errors']) . ' problem'
                . (count($r['errors']) === 1 ? '' : 's') . '. Correct them and import the whole sheet again.', 422), null, ['importErrors' => $r['errors'],
                'skipped' => $r['skipped']]);
        }
        return $this->page($ctx, $id, 200, null, self::NOTICES['imported'] . ($r['skipped'] === [] ? '' : ' ' . count($r['skipped']) . ' row'
            . (count($r['skipped']) === 1 ? '' : 's') . ' without an item code skipped (listed below).'), ['skipped' => $r['skipped']]);
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
                throw new CwException('reason_required', 'choose why the receipt is reversed', 400);
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
            $r['received_uk'] = str_replace('T', ' ', self::local((string) $r['received_at']));
        }
        unset($r);
        return $ctx->page('bench_list', ['rows' => $rows], 200, ['title' => 'Goods-in bench', 'active' => 'bench']);
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
                return $this->benchPage($ctx, $id, 409, new CwException('version_conflict', 'Someone saved this receipt since you opened this page (another bench '
                    . 'check, or the desk changed ' . ($moved === [] ? 'the delivery' : ReceiptPlan::lineList($moved)) . '): nothing was saved. What you typed is '
                    . 'still here' . ($moved === [] ? '' : ', except on ' . ReceiptPlan::lineList($moved) . ', whose item or quantity changed') . ': check it and save again.',
                    409), null, ['typed' => $typed]);
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
            return $ctx->error(404, 'unknown_receipt', 'there is no such receipt');
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
        $lines = $this->lineRows($ctx, $doc->id, $plan);
        $data = [
            'doc' => $doc,
            'gr' => $gr,
            'supplier' => $db->one('SELECT id, code, name, status FROM supplier WHERE id = ?', [(int) ($gr['supplier_id'] ?? 0)]) ?? [],
            'po' => ($gr['po_document_id'] ?? null) === null ? null : $db->one('SELECT d.id, d.number, p.state FROM document d JOIN purchase_order p ON p.document_id = d.id WHERE d.id = ?',
                [(int) $gr['po_document_id']]),
            'lines' => $lines,
            'plan' => $plan,
            'totals' => self::totals($lines, $plan),
            'statusText' => self::statusText($doc),
            'receivedLocal' => ($gr['received_at'] ?? null) === null ? '' : self::local((string) $gr['received_at']),
            'files' => $db->all('SELECT f.id, f.original_name, f.mime, f.size_bytes, df.role, df.attached_at FROM document_file df JOIN stored_file f ON f.id = df.file_id '
                . 'WHERE df.document_id = ? ORDER BY df.attached_at, f.id', [$doc->id]),
            'incidents' => (new Incidents($db))->list(null, null, $doc->id),
            'error' => $error?->getMessage(),
            'errorCode' => $error?->errorCode,
            'problems' => is_array($error?->detail['problems'] ?? null) ? array_column($error->detail['problems'], 'message') : [],
            'importErrors' => $extra['importErrors'] ?? [],
            'skipped' => $extra['skipped'] ?? [],
            'fileRoles' => self::FILE_ROLE_LABELS,
            'modes' => SellingModes::MEANING,
            'canPost' => $canPost,
            'canBench' => $canPost && $doc->status === 'draft',
            'modeSources' => self::MODE_SOURCES,
        ];
        if ($editor) {
            $poLines = [];
            if ($data['po'] !== null) {
                foreach ($db->all("SELECT pl.line_no, dl.qty, pl.received_units, s.code FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id "
                    . "AND dl.line_no = pl.line_no LEFT JOIN sku s ON s.id = dl.sku_id WHERE pl.document_id = ? AND pl.kind = 'item' ORDER BY pl.line_no", [(int) $data['po']['id']]) as $p) {
                    $poLines[(int) $p['line_no']] = 'line ' . $p['line_no'] . ': ' . $p['code'] . ', ' . max(0, (int) $p['qty'] - (int) $p['received_units']) . ' to come';
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
                'receiptSites' => $this->receiptSites($ctx),
                'poLines' => $poLines,
                'suppliers' => $noLines ? $db->all("SELECT id, code, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id") : [],
                'orders' => $orders,
                'linked' => $linked,
                'modeChoices' => GoodsReceipts::MODE_CHOICES,
            ];
            return $ctx->page('receipt_edit', $data, $status, ['title' => $doc->label(), 'active' => 'receiving', 'notice' => $notice]);
        }
        $data['canSetInvoice'] = $canPost && $doc->status === 'draft' && $doc->createdBy !== $me->id;
        return $ctx->page('receipt', $data + $this->viewData($ctx, $doc), $status, ['title' => $doc->label(), 'active' => 'receiving', 'notice' => $notice]);
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
            return $ctx->error(404, 'unknown_receipt', 'there is no such receipt');
        }
        if ($doc->status !== 'draft') {
            return HtmlResponse::redirect('/ui/receiving/' . $doc->id);
        }
        $svc = $ctx->goodsReceipts();
        $gr = $svc->header($doc->id) ?? [];
        $plan = $svc->plan($doc->id);
        $all = $this->lineRows($ctx, $doc->id, $plan);
        $from = max(1, UiRequest::id($ctx->req->param('from') ?? $ctx->req->field('from')) ?? 1);
        $page = array_slice($all, $from - 1, self::BENCH_PAGE, true);
        $barcodes = [];
        $skus = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['sku_id'], $page)));
        if ($skus !== []) {
            foreach ($ctx->db->all('SELECT sku_id, barcode, units_per_scan FROM sku_barcode WHERE is_usable = 1 AND sku_id IN (' . implode(', ', array_fill(0, count($skus), '?')) . ') '
                . 'ORDER BY sku_id, units_per_scan, barcode', $skus) as $b) {
                $barcodes[(int) $b['sku_id']]['labels'][] = $b['barcode'] . ((int) $b['units_per_scan'] > 1 ? ' (a case of ' . $b['units_per_scan'] . ')' : '');
                $barcodes[(int) $b['sku_id']]['codes'][] = (string) $b['barcode'];
            }
        }
        $stamps = $svc->benchStamps($doc->id);
        $todayUk = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');
        return $ctx->page('receipt_bench', [
            'doc' => $doc,
            'gr' => $gr,
            'stamps' => $stamps,
            'receivedLabel' => $plan['header']['received_label'],
            'receivedDay' => $plan['header']['received_day'],
            'receivedToday' => $plan['header']['received_uk'] === $todayUk,
            'cutoffDay' => $plan['header']['cutoff'] === null ? null : ReceiptPlan::day((string) $plan['header']['cutoff']),
            'lastDay' => $plan['header']['cutoff'] === null ? null : ReceiptPlan::day((new \DateTimeImmutable((string) $plan['header']['cutoff']))->modify('-1 day')->format('Y-m-d')),
            'supplier' => $ctx->db->one('SELECT id, code, name, status FROM supplier WHERE id = ?', [(int) ($gr['supplier_id'] ?? 0)]) ?? [],
            'lines' => $page,
            'count' => count($all),
            'from' => $from,
            'pageSize' => self::BENCH_PAGE,
            'barcodes' => $barcodes,
            'plan' => $plan,
            'typed' => $extra['typed'] ?? [],
            'error' => $error?->getMessage(),
            'refusal' => $plan['header']['refusal_regime'],
            'cutoff' => $plan['header']['cutoff'],
            'actions' => ReceiptPlan::ACTIONS,
            'stampTypes' => ReceiptPlan::STAMP_TYPES,
            'photos' => $ctx->db->all("SELECT f.id, f.original_name, df.role FROM document_file df JOIN stored_file f ON f.id = df.file_id WHERE df.document_id = ? "
                . "AND df.role IN ('photo', 'evidence', 'delivery_note') ORDER BY df.attached_at, f.id", [$doc->id]),
            'checkedBy' => ($gr['checked_by'] ?? null) === null ? null : $ctx->db->value('SELECT display_name FROM staff_user WHERE id = ?', [(int) $gr['checked_by']]),
        ], $status, ['title' => 'Bench check ' . $doc->label(), 'active' => 'bench', 'notice' => $notice]);
    }

    public const FILE_ROLE_LABELS = ['supplier_invoice' => 'Supplier invoice (PDF or photo)', 'delivery_note' => 'Delivery note', 'photo' => 'Photo',
        'evidence' => 'Duty evidence (made before 1 Oct 2026)'];
    public const MODE_SOURCES = ['last' => 'its last mode', 'previous' => 'its mode before it went Out-Of-Stock', 'fallback' => 'no earlier mode known',
        'chosen' => 'chosen on the receipt', 'kept' => 'kept: nothing of it was accepted'];

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
            $r['pack_label'] = (int) $r['units_per_pack'] === 1 && $r['purchase_unit'] === 'each' ? 'each' : $r['purchase_unit'] . ' x' . $r['units_per_pack'];
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
                $r['mode_resolved'] = $r['mode_source'] === null ? null : ['mode' => $r['selling_mode'] === null ? 'unchanged' : (string) $r['selling_mode'],
                    'source' => (string) $r['mode_source']];
                $r['duty_text'] = $r['expected_duty'] === null ? null : '£' . number_format((float) $r['expected_duty'], 2);
                $r['problems'] = [];
                $r['warnings'] = [];
            }
            $out[$no] = $r;
        }
        return $out;
    }

    /** "2 boxes of 10", "1 case of 5", "12 units" (each). */
    public static function packsText(int $packs, string $unit, int $upp): string
    {
        if ($upp === 1 && in_array($unit, ['each', 'unit'], true)) {
            return Html::int($packs) . ($packs === 1 ? ' unit' : ' units');
        }
        if (in_array($unit, ['each', 'unit', ''], true)) {
            $unit = 'pack'; // "each" of a pack of 5 reads as nonsense
        }
        $plural = $packs === 1 ? $unit : (preg_match('/(s|x|z|ch|sh)$/i', $unit) === 1 ? $unit . 'es'
            : (preg_match('/[^aeiou]y$/i', $unit) === 1 ? substr($unit, 0, -1) . 'ies' : $unit . 's'));
        return Html::int($packs) . ' ' . $plural . ' of ' . Html::int($upp);
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
        return $t;
    }

    /**
     * What the read-only view adds: people, the reversal, the review tasks and the decide box, the reversal form.
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
        $decide = [];
        foreach ($tasks as &$t) {
            $t['overdue'] = $t['state'] === 'open' && (string) $t['due_at'] < $now;
            $t['of'] = (int) $t['subject_id'] === $doc->id ? $doc->label() : ($revDoc?->number ?? 'the reversal');
            if ($t['state'] === 'open' && ($me->can('documents.review') || $me->can('documents.approve'))) {
                $subject = (int) $t['subject_id'] === $doc->id ? $doc : $revDoc;
                $no = $subject === null ? null : $ctx->documents()->refusalFor($me->id, $me->roles, $subject, (string) $t['kind']);
                $decide[] = ['task' => $t, 'refusal' => $no['message'] ?? null, 'of' => $t['of'], 'reversal' => $subject !== null && $subject->isReversal()];
            }
        }
        unset($t);
        $names = [];
        foreach ($db->all('SELECT id, display_name FROM staff_user WHERE id IN (?, ?, ?)', [$doc->createdBy ?? 0, $doc->postedBy ?? 0, $doc->cancelledBy ?? 0]) as $u) {
            $names[(int) $u['id']] = (string) $u['display_name'];
        }
        $canPost = Documents::mayPost($me->roles, 'GRN');
        return [
            'people' => ['created' => $names[$doc->createdBy ?? 0] ?? $doc->createdActor, 'posted' => $doc->postedBy === null ? $doc->postedActor : ($names[$doc->postedBy] ?? null),
                'cancelled' => $doc->cancelledBy === null ? null : ($names[$doc->cancelledBy] ?? null)],
            'reversal' => $revDoc,
            'tasks' => $tasks,
            'decide' => $decide,
            'reverseReasons' => $canPost && $doc->status === 'posted' && $revDoc === null
                ? $db->all("SELECT code, label, needs_note FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 AND system_only = 0 AND is_active = 1 "
                    . "AND code NOT IN ('po_amended', 'supplier_cannot_supply', 'not_needed') ORDER BY sort_order, code") : [],
            'canAttach' => $canPost && $doc->status !== 'cancelled',
            'canPostDraft' => $canPost && $doc->status === 'draft',
            'canResolve' => $me->can('incidents.resolve'),
            // A rejected review reverses the receipt, which is refused while its order is closed (I145): say so before the reviewer
            // presses reject (open owner question, I176).
            'poClosed' => $doc->status === 'posted' && ($po = $db->one('SELECT d.number, p.state FROM goods_receipt g JOIN document d ON d.id = g.po_document_id '
                . 'JOIN purchase_order p ON p.document_id = d.id WHERE g.document_id = ?', [$doc->id])) !== null && $po['state'] === 'closed'
                && (int) $db->value('SELECT COALESCE(SUM(po_units), 0) FROM grn_line WHERE document_id = ?', [$doc->id]) > 0 ? (string) $po['number'] : null,
        ];
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
                return $this->page($ctx, $id, 409, new CwException('version_conflict',
                    'This receipt was changed since you opened it (here is the current data): make your choice again.', 409));
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
            throw new CwException('no_file', 'choose a file', 400, ['field' => 'file']);
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > UiRequest::MAX_UPLOAD_BYTES) {
            throw new CwException('too_large', 'the file is larger than ' . intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576) . ' MiB: nothing was saved', 413);
        }
        if ($file['error'] !== UPLOAD_ERR_OK || $file['path'] === '') {
            throw new CwException('upload_failed', 'the file did not arrive completely: try again', 400);
        }
        return ['path' => $file['path'], 'name' => $file['name']];
    }

    /** "2026-10-07T09:30" in UK time of a stored UTC time (a datetime-local value). */
    public static function local(string $dbTime): string
    {
        return \CW\Clock::fromDb($dbTime)->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d\TH:i');
    }

    public static function statusText(Document $doc): string
    {
        return match ($doc->status) {
            'draft' => 'draft',
            'awaiting_approval' => 'awaiting approval',
            'reversed' => 'reversed',
            'cancelled' => 'cancelled',
            default => 'posted',
        };
    }

    /** @return array{state: ?string, supplier: ?int, q: string} */
    private static function filters(UiRequest $req): array
    {
        return [
            'state' => isset(self::STATE_FILTERS[$req->param('state') ?? '']) ? $req->param('state') : null,
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
        if ($f['state'] !== null) {
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
            'SELECT d.id, d.number, d.status, d.review_state, d.external_ref, g.received_at, g.paper_sheet, g.checked_at, s.code AS supplier_code, s.name AS supplier_name, '
            . 'p.number AS po_number, u.display_name AS created_by_name, (SELECT COUNT(*) FROM document_line l WHERE l.document_id = d.id) AS `lines`, '
            . '(SELECT COALESCE(SUM(l.qty), 0) FROM document_line l WHERE l.document_id = d.id) AS units, '
            . "(SELECT COUNT(*) FROM incident i WHERE i.document_id = d.id AND i.status = 'open') AS open_incidents "
            . 'FROM document d JOIN goods_receipt g ON g.document_id = d.id JOIN supplier s ON s.id = g.supplier_id LEFT JOIN document p ON p.id = g.po_document_id '
            . 'LEFT JOIN staff_user u ON u.id = d.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . self::LIST_LIMIT,
            $params,
        );
        foreach ($rows as &$r) {
            $r['label'] = $r['number'] ?? str_replace('_', ' ', (string) $r['status']) . ' #' . $r['id'];
            $r['received_uk'] = str_replace('T', ' ', self::local((string) $r['received_at']));
        }
        unset($r);
        return $rows;
    }
}
