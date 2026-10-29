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
 * The receiving screens (IM6 Receive (+ invoice), Phase I-3; docs/decisions.md I141; Goods in on ONE page since 29 Oct 2026, GV1-GV10):
 * the Goods in board (Arriving orders with "Receive", Being checked, Ready to book in, Booked in today; Show; one search) with the "new
 * delivery" drawer; a delivery not booked in yet on ONE page for everyone who receives goods (onePage(): its details, the scan box, the
 * lines with their check and duty stamp, the problem panel on the row, the prices fold for the buying desk, photos and papers, "Book in";
 * one form, lines()); "receive all as ordered" from the PO, the supplier-sheet import, the files; the read-only page of a delivery booked
 * in (what was booked where, the incidents, the review and reversal) and of a draft for someone who only looks; the bench list.
 *
 * receiving.view looks; doc.GRN.post (goods_in, purchasing_desk, purchasing_manager) acts, checked again by CW\Receiving\GoodsReceipts:
 * a draft's header and lines are its creator's (saveDraft); the check is anyone's who receives goods (bench()), so is a draft's supplier
 * invoice (setInvoice) and booking it in (post()); reviewers decide on the delivery's page (the forms post to /ui/documents/reviews/{task}/...,
 * which come back here). The "new delivery" form and each Arriving "Receive" carry a FormOnce key; every other form the version it was drawn
 * with, and the one page the stamps of what it shows (a save drawn before someone else's is saved while what it changes was not changed
 * meanwhile, else drawn again with what was typed: GV4). The one page sends `version`, `page`, `line_count` and `lines_editable` first and
 * shows as many lines as max_input_vars carries (pageSize()), a page at a time beyond that (GV7).
 *
 * Plain words (U85-U90): every word of these pages comes from Ui\Words (GI, GI_STATE, GI_CHECK, GI_DUTY, RECEIVING, RECEIPT, BENCH,
 * RECEIPT_NOTICE, RECEIPT_ERROR, ...); the services' refusals are said again by error code (plain()), and the goods-in plan's problems and
 * warnings by Ui\ReceiptWords. The services' own messages are unchanged.
 */
final class ReceivingController
{
    /** The notices named in a redirect (their words: Words::RECEIPT_NOTICE). */
    public const NOTICES = Words::RECEIPT_NOTICE;
    /** The old links' state filter (Home's cards): a delivery's state, or "checked at the bench, not booked in yet", or "refused at the bench". */
    public const STATE_FILTERS = ['draft', 'checked', 'refused', 'posted', 'reversed', 'cancelled'];
    /** The board's Show menu (docs/decisions.md GV1): today and open (''), every delivery, the cancelled ones. */
    public const SHOWS = ['', 'all', 'cancelled'];
    /** Where a delivery is on the board (Words::GI_STATE), and the board's groups in their order with their colours. */
    public const BOARD_STATES = ['todo', 'checking', 'refused', 'ready', 'posted', 'reversed', 'cancelled'];
    public const BOARD = ['arriving' => 'info', 'checking' => 'review', 'ready' => 'needs', 'today' => 'done', 'booked' => 'done', 'cancelled' => 'off'];
    public const LIST_LIMIT = 300;
    /** A duty line's stamp answer (Words::GI_DUTY): the kind of stamp on the pack, on the pack without its kind (older checks), or none. */
    public const DUTY_ANSWERS = ['digital', 'transitional', 'yes', 'none'];
    /**
     * The one page's fields outside its line rows (docs/decisions.md GV7): csrf, version, page, line_count, lines_editable, from, page_count,
     * stamp, bench_was, inv_was, header_present, the 9 header fields, paperwork_ok, bench_note, arrived_now, q, packs, price, action, fine, next,
     * add_si / add_sku, file_role, photo_line, with room to spare.
     */
    public const ONE_FIXED_FIELDS = 40;
    /** A line's fields of its keyer: packs, items a pack, price, order line, selling mode, note. */
    public const ONE_DESK_FIELDS = 6;
    /** A line's check fields: present, was, checked, fine, duty, code, short, over, over right, damaged, wrong, no stamp, what to do, proof. */
    public const ONE_BENCH_FIELDS = 14;

    /** Lines on one page of the delivery (all of them while they fit max_input_vars, I73): with its keyer's fields ($desk) or the check only. */
    public static function pageSize(bool $desk): int
    {
        return max(1, intdiv(UiRequest::maxInputVars() - 1 - self::ONE_FIXED_FIELDS, self::ONE_BENCH_FIELDS + ($desk ? self::ONE_DESK_FIELDS : 0)));
    }

    /** Whether the one page carries $n lines with their keyer's fields at once (else it shows them a page at a time). */
    public static function editorFits(int $n): bool
    {
        return $n <= self::pageSize(true);
    }

    /** The most lines one page shows with their keyer's fields (the truncation refusal says it). */
    public static function editorMaxLines(): int
    {
        return self::pageSize(true);
    }

    // ------------------------------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------------------------------

    /**
     * The Goods in board (the owner's approved design of 29 Oct 2026, GI_List; docs/decisions.md GV1): one search (a scanned delivery note,
     * the supplier, the order, the number, the invoice), one Show menu (today and open, all, cancelled) and "+ New delivery" (the drawer
     * #new) in the toolbar; then the groups, each a coloured heading with its count over a white card with its colour on the left edge:
     * Arriving (the open orders that still expect goods and have no delivery being checked, each with "Receive"), Being checked (not started,
     * part checked, paperwork not right), Ready to book in (checked at the bench), Booked in today (or Booked in, under Show "all"), and
     * Cancelled only under Show. The old links' state and supplier filters (Home's cards) still narrow it.
     */
    public function index(Context $ctx, int $status = 200, ?string $error = null): HtmlResponse
    {
        $f = self::filters($ctx->req);
        $me = $ctx->me();
        $canPost = Documents::mayPost($me->roles, 'GRN');
        $rows = array_map(self::screenRow(...), $this->rows($ctx, $f));
        $arriving = $f['show'] === 'cancelled' || $f['state'] !== null ? [] : $this->arriving($ctx, $f, $canPost);
        $typedPo = UiRequest::id($ctx->req->param('po'));
        return $ctx->page('receipts', [
            'rows' => $rows,
            'groups' => self::boardGroups($rows, $arriving, $f['show']),
            'arrivingCount' => count($arriving),
            'filters' => $f,
            'filtered' => $f['state'] !== null || $f['supplier'] !== null || $f['q'] !== '' || $f['show'] !== '',
            'newSuppliers' => $canPost ? $ctx->db->all("SELECT id, code, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id") : [],
            'orders' => $canPost ? $ctx->goodsReceipts()->receivableOrders() : [],
            'formKey' => $canPost ? ($ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey()) : null,
            // The order's page's "Receive" (docs/decisions.md PV) opens this form with its order chosen (?po=).
            'typed' => $error === null ? ($typedPo === null ? [] : ['po_id' => (string) $typedPo]) : $ctx->req->post,
            'openNew' => $error !== null || $typedPo !== null,
            'limit' => self::LIST_LIMIT,
            'error' => $error,
            'canPost' => $canPost,
            'canSeeOrders' => $me->can('purchasing.view'),
            'lookOnly' => $canPost ? null : Words::whoCan('doc.GRN.post'),
        ], $status, ['title' => Words::MENU['receiving'], 'active' => 'receiving', 'notice' => self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null]);
    }

    /**
     * One delivery of the board as the screen shows it: its name (the number once booked in, else "Delivery 12"), the order (or "no order")
     * and the invoice under it, where it is on the board (BOARD_STATES) and that state's words and colour, the bench's progress, its size.
     *
     * @param array<string, mixed> $r a rows() row
     * @return array<string, mixed>
     */
    public static function screenRow(array $r): array
    {
        $sub = [$r['po_number'] !== null ? (string) $r['po_number'] : Words::GI['no_order']];
        if ($r['external_ref'] !== null && $r['external_ref'] !== '') {
            $sub[] = Words::say('GI', 'invoice_no', (string) $r['external_ref']);
        }
        $lines = (int) $r['lines'];
        $bench = $r['status'] === 'draft' ? self::benchState($r) : null;
        $state = match (true) {
            $r['status'] === 'draft' => match ($bench) {
                'done' => 'ready',
                'refused' => 'refused',
                'todo' => 'todo',
                default => 'checking',
            },
            $r['status'] === 'reversed' => 'reversed',
            $r['status'] === 'cancelled' => 'cancelled',
            default => 'posted',
        };
        $problems = (int) ($r['problem_lines'] ?? 0);
        return $r + [
            'name' => $r['number'] ?? Words::say('GI', 'draft_name', (int) $r['id']),
            'sub_line' => implode(' · ', $sub),
            'board_state' => $state,
            'state_tone' => Words::tone('GI_STATE', $state),
            'state_word' => Words::of('GI_STATE', $state),
            'problems_line' => $state !== 'ready' || $problems === 0 ? null : ($problems === 1 ? Words::GI['problems_one'] : Words::say('GI', 'problems_many', $problems)),
            'bench_state' => $bench,
            'size_line' => $lines === 1 ? Words::say('RECEIVING', 'size_one', (int) $r['units']) : Words::say('RECEIVING', 'size_many', $lines, (int) $r['units']),
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

    /**
     * The board's groups (BOARD's order, only those with rows): Arriving (the orders), Being checked, Ready to book in, Booked in today (or
     * Booked in under Show "all"), Cancelled.
     *
     * @param list<array<string, mixed>> $rows screenRow() rows
     * @param list<array<string, mixed>> $arriving arriving() rows
     * @return list<array{key: string, tone: string, name: string, rows: list<array<string, mixed>>}>
     */
    public static function boardGroups(array $rows, array $arriving, string $show): array
    {
        $by = $arriving === [] ? [] : ['arriving' => $arriving];
        foreach ($rows as $r) {
            $key = match ($r['board_state']) {
                'ready' => 'ready',
                'todo', 'checking', 'refused' => 'checking',
                'cancelled' => 'cancelled',
                default => $show === '' ? 'today' : 'booked',
            };
            $by[$key][] = $r;
        }
        $out = [];
        foreach (self::BOARD as $key => $tone) {
            if (isset($by[$key])) {
                $out[] = ['key' => $key, 'tone' => $tone, 'name' => Words::GI['group_' . $key], 'rows' => $by[$key]];
            }
        }
        return $out;
    }

    /**
     * The open orders that still expect goods (approved, sent, part received; GoodsReceipts::receivableOrders) and have no delivery being
     * checked, as the board's Arriving rows: the order, its state, the supplier, expected (late when passed), the items still to come, and
     * for someone who receives goods a "Receive" form (a delivery with the order's lines still to come, FormOnce).
     *
     * @param array{state: ?string, supplier: ?int, q: string, show: string} $f
     * @return list<array<string, mixed>>
     */
    private function arriving(Context $ctx, array $f, bool $canPost): array
    {
        $busy = array_flip(array_map('intval', array_column($ctx->db->all("SELECT DISTINCT g.po_document_id FROM goods_receipt g JOIN document d ON d.id = g.document_id "
            . "WHERE d.status = 'draft' AND g.po_document_id IS NOT NULL"), 'po_document_id')));
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');
        $q = mb_strtolower($f['q']);
        $out = [];
        foreach ($ctx->goodsReceipts()->receivableOrders($f['supplier']) as $o) {
            if ((int) $o['outstanding'] <= 0 || isset($busy[(int) $o['id']])) {
                continue;
            }
            if ($q !== '' && !str_contains(mb_strtolower((string) $o['number']), $q) && !str_contains(mb_strtolower((string) $o['supplier_name']), $q)) {
                continue;
            }
            $out[] = $o + [
                'state_tone' => Words::tone('PO_STATE', (string) $o['state']),
                'state_word' => Words::of('PO_STATE', (string) $o['state']),
                'late' => $o['expected_date'] !== null && (string) $o['expected_date'] <= $today,
                'is_today' => $o['expected_date'] !== null && (string) $o['expected_date'] === $today,
                'formKey' => $canPost ? FormOnce::newKey() : null,
            ];
        }
        return $out;
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
        // Goods in never sees a price (docs/decisions.md GV6): the notice says the packs only to someone who does not read money.
        $text = $kind === 'added'
            ? Words::say('RECEIPT_ADDED', 'added', $lineNo, $item, !$ctx->me()->can('invoices.view') ? $packs : (PoMath::e4((string) $l['pack_price']) > 0
                ? Words::say('RECEIPT_ADDED', 'at_price', $packs, '£' . PoMath::price((string) $l['pack_price'])) : Words::say('RECEIPT_ADDED', 'no_price', $packs)))
            : Words::say('RECEIPT_ADDED', 'incremented', $lineNo, $item, $unitsAdded, $packs);
        if ($case) {
            $text .= ' ' . Words::say('RECEIPT_ADDED', 'case', (int) $l['units_per_pack']);
        }
        return $text . ' ' . Words::RECEIPT_ADDED['all_saved'];
    }

    /**
     * POST /ui/receiving/{id}/lines: the delivery's one form (docs/decisions.md GV3, GV4). In ONE transaction: its keyer's save (the
     * header and every line of the page as edited: GoodsReceipts::saveDraft, the creator's only), the supplier invoice of someone who did
     * not key it (setInvoice, I172), the check of the lines this person touched (bench(): an answer changed, "All fine", the tick; the
     * lines at their numbers after the save), the file, then what was scanned or chosen. "Book in" (action=post) books in after that
     * commit, with the version the save left, in the posting's own transaction (GoodsReceipts::post: no outer transaction around it); a
     * refused booking leaves everything saved and says why. A form drawn before someone else's save is saved on the current version while
     * what it changes was not changed meanwhile (the keyer's stamp, I175; each touched line's stamp and the checklist's); otherwise it is
     * drawn again with what was typed (409), except on lines whose product or quantity changed.
     */
    public function lines(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $version = UiRequest::id($req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        $count = $req->field('line_count');
        if ($count === null || preg_match('/^(0|[1-9][0-9]{0,4})$/D', $count) !== 1) {
            return $ctx->error(400, 'form_truncated', Words::RECEIPT_ERROR['form_truncated']);
        }
        $count = (int) $count;
        $editable = $req->field('lines_editable') === '1';
        $from = max(1, UiRequest::id($req->field('from')) ?? 1);
        $pageCount = $req->field('page_count') === null ? $count : (UiRequest::id($req->field('page_count')) ?? 0);
        $to = min($count, $from + $pageCount - 1);
        if ($editable) {
            $arrived = 0;
            for ($n = $from; $n <= $to; $n++) {
                $arrived += $req->field("line_{$n}_packs") === null ? 0 : 1;
            }
            if ($arrived < $to - $from + 1) {
                return $ctx->error(400, 'form_truncated', Words::say('RECEIPT_ERROR', 'form_truncated_lines', $to - $from + 1, $arrived, self::editorMaxLines()));
            }
        }
        $svc = $ctx->goodsReceipts();
        $typed = $req->post;
        $q = trim($req->field('q') ?? '');
        $packs = PoLinesFile::wholeNumber($req->field('packs') ?? '1') ?? 0;
        $price = trim($req->field('price') ?? '');
        $price = $price === '' ? null : $price;
        $addSku = UiRequest::id($req->field('add_sku'));
        $addSi = UiRequest::id($req->field('add_si'));
        $posting = $req->field('action') === 'post' && $addSku === null && $addSi === null;
        $back = ['typed' => $typed, 'q' => $q, 'from' => $from];
        if ($posting && $q !== '') {
            return $this->page($ctx, $id, 422, new CwException('scan_pending', Words::say('RECEIPT_ERROR', 'scan_pending', $q), 422), null, $back);
        }
        $adding = $addSku !== null || $addSi !== null || ($req->field('action') === 'add' && $q !== '');
        // The keyer's part: the header and the lines (a person who did not key it sends neither; sent anyway, the service refuses it).
        $deskSave = $editable || $req->field('header_present') === '1';
        $header = [];
        if ($deskSave) {
            foreach (['invoice_number', 'invoice_date', 'delivery_note', 'received_at', 'backdate_reason', 'note', 'supplier_id', 'po_id'] as $k) {
                $v = $req->field($k);
                if ($v !== null) {
                    $header[$k] = $v;
                }
            }
            if ($req->field('header_present') === '1') {
                $header['paper_sheet'] = $req->field('paper_sheet') === '1';
            }
        }
        try {
            $file = $req->file('file') === null ? null : self::upload($ctx) + ['role' => self::fileRole($req->field('file_role')), 'note' => self::photoNote($req)];
            $answers = self::answers($req);
            $checklist = self::checklist($req);
            $invoice = $deskSave ? null : self::invoiceSent($req);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, $back + ['open' => (int) ($e->detail['line'] ?? 0)]);
        }
        $tries = 0;
        while (true) {
            $now = $ctx->documents()->find($id);
            if ($now === null || $now->docType !== 'GRN' || $now->reversesId !== null) {
                return $ctx->error(404, 'unknown_receipt', Words::RECEIPT_ERROR['unknown_receipt']);
            }
            $use = $version;
            if ($now->version !== $version && $now->status === 'draft') {
                $stale = $this->stale($ctx, $id, $now, $deskSave, $answers, $checklist, $invoice, $count, $back);
                if ($stale !== null) {
                    return $stale;
                }
                $use = $now->version;
            }
            try {
                $result = $ctx->db->transaction(fn (): array => $this->saveAll($ctx, $id, $use, $deskSave, $header, $editable, $count, $from, $to, $answers, $checklist,
                    $invoice, $file, $adding, $addSi, $addSku, $q, $packs, $price));
                break;
            } catch (CwException $e) {
                if ($e->errorCode === 'version_conflict' && ++$tries < 3) {
                    continue; // changed between the look and the save: look again
                }
                if ($e->errorCode === 'version_conflict') {
                    return $this->page($ctx, $id, 409, new CwException('version_conflict', Words::RECEIPT_ERROR['version_conflict'], 409), null, $back);
                }
                return $this->page($ctx, $id, $e->httpStatus, $e, null, $back + ['open' => (int) ($e->detail['line'] ?? 0)]);
            }
        }
        if ($result['status'] === 'choices') {
            // The note names the product by its name (U86): the choices carry it, also when it is not on the delivery yet.
            $named = array_column($result['choices'], 'name', 'sku_code');
            return $this->page($ctx, $id, 200, null, isset($result['choice_note']) ? ReceiptWords::one((string) $result['choice_note'], $named) : null, ['choices' => $result['choices'],
                'q' => $q, 'packs' => max(1, $packs), 'price' => $price ?? '', 'from' => $from]);
        }
        if ($result['status'] === 'not_found') {
            return $this->page($ctx, $id, 422, new CwException('not_found', Words::say('RECEIPT_ERROR', 'not_found', $q), 422), null, ['q' => $q, 'from' => $from]);
        }
        if ($posting && $use !== $version) {
            // Saved on a version the page was not drawn with (someone else's save meanwhile, which this form did not change): what would be
            // booked in is not what this person saw, so the booking waits for them to look again (the save stays).
            return $this->page($ctx, $id, 409, new CwException('not_booked', Words::RECEIPT_ERROR['saved_not_booked_changed'], 409), null, ['from' => $from, 'ready' => true]);
        }
        if ($posting) {
            return $this->bookIn($ctx, $id, (int) $result['version'], $from);
        }
        $query = ['notice' => $result['status']];
        if (isset($result['line_no'])) {
            $query += ['line' => $result['line_no'], 'u' => $result['units_added'] ?? null, 'x' => ($result['note'] ?? null) !== null ? 'case' : null];
        }
        if ($result['cleared'] !== []) {
            $query['rc'] = implode(',', array_slice($result['cleared'], 0, 200));
        }
        $next = $req->field('next') === '1' ? $from + max(1, $pageCount) : $from;
        if ($next > 1) {
            $query['from'] = $next;
        }
        $anchor = match (true) {
            isset($result['line_no']) => '#line-' . $result['line_no'],
            $req->field('next') === '1' => '#lines',
            $result['checked'] !== [] => '#line-' . max($result['checked']),
            default => '#scan',
        };
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id, $query) . $anchor);
    }

    /**
     * The one form's save, inside its transaction (lines()): the keyer's save, the invoice of someone else, the check of the touched lines
     * at their numbers after the save, the file, then the scan or the choice. Each service checks the version it is given and moves it.
     *
     * @param array<string, mixed> $header
     * @param array<int, array<string, mixed>> $answers answers()
     * @param array{stamp: string, header: array<string, mixed>} $checklist
     * @param array{fields: array<string, string>, touched: bool}|null $invoice
     * @param array{path: string, name: string, role: string, note: ?string}|null $file
     * @return array<string, mixed> status (saved, added, incremented, choices, not_found), version, checked (the lines whose check was written),
     *   cleared (the lines whose check this save took back), and an add's line_no, units_added, note, choices
     */
    private function saveAll(Context $ctx, int $id, int $version, bool $deskSave, array $header, bool $editable, int $count, int $from, int $to, array $answers,
        array $checklist, ?array $invoice, ?array $file, bool $adding, ?int $addSi, ?int $addSku, string $q, int $packs, ?string $price): array
    {
        $svc = $ctx->goodsReceipts();
        $caller = $ctx->caller();
        $current = $svc->lines($id);
        $before = [];
        $map = [];
        foreach ($current as $l) {
            $map[$l['line_no']] = $l['line_no'];
            if ($l['checked_at'] !== null) {
                $before[$l['line_no']] = true;
            }
        }
        $v = $version;
        if ($deskSave) {
            $lines = $editable ? self::editedLines($ctx->req, $current, $count, $from, $to) : $current;
            $v = $svc->saveDraft($caller, $id, $v, $header, $lines)->version;
            $map = [];
            foreach ($lines as $i => $l) {
                $map[(int) $l['line_no']] = $i + 1;
            }
        }
        if ($invoice !== null && $invoice['touched'] && $invoice['fields'] !== []) {
            $v = $svc->setInvoice($caller, $id, $v, $invoice['fields'])->version;
        }
        // The check: only the lines this person touched (an untouched line is never written back as their page showed it), at their
        // numbers after the save; a line counted when ticked or an answer changed, uncounted when its tick was taken off (I168).
        $stored = [];
        foreach ($svc->lines($id) as $l) {
            $stored[$l['line_no']] = $l;
        }
        $bench = [];
        foreach ($answers as $old => $a) {
            $no = $map[$old] ?? null;
            if ($no === null || !isset($stored[$no])) {
                continue; // removed by this save
            }
            $f = self::benchOf($a);
            if ($a['sent']['ok'] === '1' || self::benchChanged($f, $stored[$no])) {
                $bench[$no] = $f + ['over_confirmed' => $a['overok']];
            } elseif ($stored[$no]['checked_at'] !== null) {
                $bench[$no] = $f + ['checked' => false];
            }
        }
        ksort($bench);
        if ($bench !== [] || $checklist['header'] !== []) {
            $v = $svc->bench($caller, $id, $v, $checklist['header'], $bench)->version;
        }
        if ($file !== null) {
            $svc->attach($caller, $id, $file['path'], $file['name'], $file['role'], $file['note']);
        }
        $result = ['status' => 'saved'];
        if ($adding) {
            $result = $addSi !== null || $addSku !== null ? $svc->addChoice($caller, $id, $v, $addSi, $addSi === null ? $addSku : null, max(1, $packs), $price)
                : $svc->addLine($caller, $id, $v, $q, max(1, $packs), $price);
            $v = $result['document']->version;
        }
        // The lines whose check this save took back (their quantity or product changed, I168): said in the notice.
        $cleared = [];
        $back = array_flip($map);
        foreach ($svc->lines($id) as $l) {
            $was = $back[$l['line_no']] ?? null;
            if ($l['checked_at'] === null && $was !== null && isset($before[$was])) {
                $cleared[] = $l['line_no'];
            }
        }
        return $result + ['version' => $v, 'checked' => array_keys(array_filter($bench, static fn (array $b): bool => ($b['checked'] ?? true) !== false)),
            'cleared' => $cleared];
    }

    /**
     * "Book in" after its save committed (docs/decisions.md GV3): GoodsReceipts::post with the version the save left. Refused: the delivery
     * stays a draft with everything saved and the page says why ("Saved. Not booked in yet: …", the problems under "Before it can be booked
     * in"); the same form sent twice lands on the delivery the other one booked in.
     */
    private function bookIn(Context $ctx, int $id, int $version, int $from): HtmlResponse
    {
        try {
            $ctx->goodsReceipts()->post($ctx->caller(), $id, $version);
        } catch (CwException $e) {
            $now = $ctx->documents()->find($id);
            if ($now !== null && $now->status !== 'draft') {
                return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id, ['notice' => $now->status === 'posted' ? 'posted' : null]));
            }
            $problems = is_array($e->detail['problems'] ?? null) ? count($e->detail['problems']) : 0;
            $message = match (true) {
                $problems === 1 => Words::RECEIPT_ERROR['saved_not_booked_one'],
                $problems > 1 => Words::say('RECEIPT_ERROR', 'saved_not_booked', $problems),
                $e->errorCode === 'version_conflict' => Words::RECEIPT_ERROR['saved_not_booked_changed'],
                default => Words::RECEIPT_ERROR['saved_not_booked_lead'] . ' ' . self::plain($e),
            };
            return $this->page($ctx, $id, $e->httpStatus, new CwException('not_booked', $message, $e->httpStatus, $e->detail), null, ['from' => $from, 'ready' => true]);
        }
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id, ['notice' => 'posted']));
    }

    /**
     * A form drawn before someone else's save: null when what it changes was not changed meanwhile (it is saved on the current version),
     * else the page again (409) with what was typed: the keyer's part (the desk stamp, I175: drawn again without the line rows when the
     * lines were added or removed), the supplier invoice of someone else, or the check (the checklist's stamp and each touched line's;
     * what was typed stays, except on lines whose product or quantity changed).
     *
     * @param array<int, array<string, mixed>> $answers
     * @param array{stamp: string, header: array<string, mixed>} $checklist
     * @param array{fields: array<string, string>, touched: bool}|null $invoice
     * @param array<string, mixed> $back typed, q, from
     */
    private function stale(Context $ctx, int $id, Document $now, bool $deskSave, array $answers, array $checklist, ?array $invoice, int $count, array $back): ?HtmlResponse
    {
        $svc = $ctx->goodsReceipts();
        $req = $ctx->req;
        $typed = $back['typed'];
        if ($deskSave && !hash_equals($svc->deskStamp($id), (string) ($req->field('stamp') ?? ''))) {
            return $this->page($ctx, $id, 409, new CwException('version_conflict', Words::RECEIPT_ERROR['version_conflict'], 409), null, ['typed' => count($svc->lines($id)) === $count
                ? $typed : array_filter($typed, static fn ($k): bool => !str_starts_with((string) $k, 'line_') && !str_starts_with((string) $k, 'b_'), ARRAY_FILTER_USE_KEY)] + $back);
        }
        if ($invoice !== null && $invoice['touched']) {
            $g = $svc->header($id) ?? [];
            $drawn = (string) ($req->field('inv_was') ?? '');
            if (!hash_equals(self::answersFp(self::invoiceAnswers(['invoice_number' => $now->externalRef, 'invoice_date' => $g['invoice_date'] ?? null,
                'delivery_note' => $g['delivery_note'] ?? null])), $drawn)) {
                return $this->page($ctx, $id, 409, new CwException('version_conflict', Words::RECEIPT_ERROR['version_conflict'], 409), null, $back);
            }
        }
        $stamps = $svc->benchStamps($id);
        $same = $checklist['header'] === [] || hash_equals($stamps['checklist'], $checklist['stamp']);
        $moved = [];
        foreach ($answers as $no => $a) {
            if (($stamps['lines'][$no] ?? '') !== $a['was']) {
                $same = false;
                if (($stamps['identity'][$no] ?? '') !== $a['identity']) {
                    $moved[] = $no;
                }
            }
        }
        if ($same) {
            return null;
        }
        // What was typed stays, except on lines whose item or quantity changed (their answers are for other figures).
        $typed = array_filter($typed, static function ($k) use ($moved): bool {
            return preg_match('/^b_([0-9]+)_/', (string) $k, $m) !== 1 || !in_array((int) $m[1], $moved, true);
        }, ARRAY_FILTER_USE_KEY);
        return $this->page($ctx, $id, 409, new CwException('version_conflict', Words::say('RECEIPT_ERROR', 'version_conflict_bench',
            $moved === [] ? Words::RECEIPT_ERROR['bench_the_delivery'] : ReceiptPlan::lineList($moved),
            $moved === [] ? '' : Words::say('RECEIPT_ERROR', 'bench_except', ReceiptPlan::lineList($moved))), 409), null, ['typed' => $typed] + $back);
    }

    /**
     * The check of each line drawn on the page that this person touched (docs/decisions.md GV4): its answers as sent (sentAnswers), the
     * stamp of the line as drawn (`b_<n>_was`: the bench stamp, then the fingerprint of the answers drawn) and its identity. A line whose
     * answers came back as drawn is left out (never written back as the page showed it). "All fine" (`fine` = the line's number, or the
     * script's `b_<n>_fine`) is the counted tick with no problem: a duty line needs its stamp answer first, and "No stamp" is not all fine.
     *
     * @return array<int, array{identity: string, was: string, sent: array<string, string>, stampReq: bool, fine: bool, overok: ?string}>
     */
    private static function answers(UiRequest $req): array
    {
        $fine = UiRequest::id($req->field('fine'));
        $out = [];
        foreach ($req->fieldsMatching('/^b_[1-9][0-9]{0,4}_present$/') as $name => $identity) {
            $no = (int) substr($name, 2, -8);
            [$was, $fp] = array_pad(explode('.', (string) ($req->field("b_{$no}_was") ?? ''), 2), 2, '');
            $stampReq = $req->field("b_{$no}_duty") !== null;
            $sent = self::sentAnswers($req, $no, $stampReq);
            $isFine = $fine === $no || $req->field("b_{$no}_fine") === '1';
            if ($isFine) {
                if ($stampReq && $sent['duty'] === '') {
                    throw new CwException('fine_needs_stamp', Words::say('RECEIPT_ERROR', 'fine_needs_stamp', $no), 422, ['line' => $no, 'field' => 'stamp_on_pack']);
                }
                if ($stampReq && $sent['duty'] === 'none') {
                    throw new CwException('fine_no_stamp', Words::say('RECEIPT_ERROR', 'fine_no_stamp', $no), 422, ['line' => $no, 'field' => 'unstamped_units']);
                }
                $sent = array_replace($sent, ['ok' => '1', 'short' => '0', 'over' => '0', 'damaged' => '0', 'wrong' => '0']
                    + ($stampReq ? ['unstamped' => '0', 'action' => '', 'evidence' => ''] : []));
            } elseif (hash_equals(self::answersFp($sent), $fp)) {
                continue; // not touched
            }
            $out[$no] = ['identity' => (string) $identity, 'was' => $was, 'sent' => $sent, 'stampReq' => $stampReq, 'fine' => $isFine,
                'overok' => $req->field("b_{$no}_overok")];
        }
        ksort($out);
        return $out;
    }

    /**
     * A line's answers as its row draws them (drawnAnswers) or as they came back (sentAnswers), in the same keys and the same plain strings:
     * checked, the problems (empty = 0), and for a duty line its stamp answer, code, items without a stamp, what to do and the proof.
     *
     * @param array<string, mixed> $l a lines() row
     * @return array<string, string>
     */
    public static function drawnAnswers(array $l, bool $stampReq): array
    {
        $a = ['ok' => $l['checked_at'] !== null ? '1' : '', 'short' => (string) (int) $l['short_units'], 'over' => (string) (int) $l['over_units'],
            'damaged' => (string) (int) $l['damaged_units'], 'wrong' => (string) (int) $l['wrong_item_units']];
        if ($stampReq) {
            $a += ['duty' => self::dutyAnswer($l) === 'unchecked' ? '' : self::dutyAnswer($l), 'code' => trim((string) ($l['stamp_code'] ?? '')), 'unstamped' => (string) (int) $l['unstamped_units'],
                'action' => (string) ($l['unstamped_action'] ?? ''), 'evidence' => trim((string) ($l['pre_october_evidence'] ?? ''))];
        }
        return $a;
    }

    /** @return array<string, string> */
    private static function sentAnswers(UiRequest $req, int $no, bool $stampReq): array
    {
        $num = static function (?string $v): string {
            $t = trim((string) ($v ?? ''));
            return $t === '' ? '0' : (string) (PoLinesFile::wholeNumber($t) ?? $t);
        };
        $a = ['ok' => $req->field("b_{$no}_ok") === '1' ? '1' : '', 'short' => $num($req->field("b_{$no}_short")), 'over' => $num($req->field("b_{$no}_over")),
            'damaged' => $num($req->field("b_{$no}_damaged")), 'wrong' => $num($req->field("b_{$no}_wrong"))];
        if ($stampReq) {
            $a += ['duty' => (string) $req->field("b_{$no}_duty"), 'code' => trim((string) ($req->field("b_{$no}_code") ?? '')),
                'unstamped' => $num($req->field("b_{$no}_unstamped")), 'action' => trim((string) ($req->field("b_{$no}_action") ?? '')),
                'evidence' => trim((string) ($req->field("b_{$no}_evidence") ?? ''))];
        }
        return $a;
    }

    /** A duty line's stamp answer from what is stored (DUTY_ANSWERS, or 'unchecked'). @param array<string, mixed> $l */
    public static function dutyAnswer(array $l): string
    {
        if (($l['stamp_on_pack'] ?? null) === null) {
            return 'unchecked';
        }
        if ((int) $l['stamp_on_pack'] === 0) {
            return 'none';
        }
        return in_array($l['stamp_type'] ?? null, ['digital', 'transitional'], true) ? (string) $l['stamp_type'] : 'yes';
    }

    /** Answers in one short fingerprint (the drawn and the sent compare by it). @param array<int|string, mixed> $answers */
    public static function answersFp(array $answers): string
    {
        return substr(sha1(json_encode(array_values($answers), JSON_THROW_ON_ERROR)), 0, 12);
    }

    /** The bench fields (GoodsReceipts::bench) of a touched line's answers: the stamp answer as stamp_on_pack and stamp_type (GV5). @param array<string, mixed> $a @return array<string, mixed> */
    private static function benchOf(array $a): array
    {
        $s = $a['sent'];
        $f = ['stamp_on_pack' => null, 'stamp_type' => null, 'stamp_code' => null, 'short_units' => $s['short'], 'over_units' => $s['over'], 'damaged_units' => $s['damaged'],
            'wrong_item_units' => $s['wrong'], 'unstamped_units' => null, 'unstamped_action' => null, 'pre_october_evidence' => null];
        if ($a['stampReq']) {
            [$on, $type] = match ($s['duty']) {
                'digital', 'transitional' => ['1', $s['duty']],
                'yes' => ['1', ''],
                'none' => ['0', ''],
                'unchecked', '' => ['', ''],
                default => [$s['duty'], ''], // not an answer: the service refuses it in words
            };
            $f = ['stamp_on_pack' => $on, 'stamp_type' => $type, 'stamp_code' => $s['code'], 'unstamped_units' => $s['unstamped'], 'unstamped_action' => $s['action'],
                'pre_october_evidence' => $s['evidence']] + $f;
        }
        return $f;
    }

    /**
     * The delivery's checklist as this person changed it (`bench_was`: its stamp as drawn, the paperwork answer drawn, the note's
     * fingerprint): only the answers changed are written (the paperwork answer names its checker, I133).
     *
     * @return array{stamp: string, header: array<string, mixed>}
     */
    private static function checklist(UiRequest $req): array
    {
        [$stamp, $paper, $noteFp] = array_pad(explode('.', (string) ($req->field('bench_was') ?? ''), 3), 3, '');
        $h = [];
        $p = $req->field('paperwork_ok');
        if ($p !== null && $p !== $paper) {
            $h['paperwork_ok'] = $p;
        }
        $n = $req->field('bench_note');
        if ($n !== null && !hash_equals(self::answersFp([trim($n)]), $noteFp)) {
            $h['bench_note'] = $n;
        }
        if ($req->field('arrived_now') === '1') {
            $h['arrived_now'] = true;
        }
        return ['stamp' => $stamp, 'header' => $h];
    }

    /**
     * The supplier invoice fields of someone who did not key the delivery (`inv_was`: the fingerprint of what was drawn): set through
     * GoodsReceipts::setInvoice only when changed (setting it keeps that person out of the delivery's review, I172).
     *
     * @return array{fields: array<string, string>, touched: bool}|null
     */
    private static function invoiceSent(UiRequest $req): ?array
    {
        $drawn = $req->field('inv_was');
        if ($drawn === null) {
            return null;
        }
        $fields = [];
        foreach (['invoice_number', 'invoice_date', 'delivery_note'] as $k) {
            if ($req->field($k) !== null) {
                $fields[$k] = (string) $req->field($k);
            }
        }
        return ['fields' => $fields, 'touched' => !hash_equals(self::answersFp(self::invoiceAnswers($fields)), $drawn)];
    }

    /** @param array<string, mixed> $v invoice_number, invoice_date, delivery_note @return list<string> */
    public static function invoiceAnswers(array $v): array
    {
        return [trim((string) ($v['invoice_number'] ?? '')), trim((string) ($v['invoice_date'] ?? '')), trim((string) ($v['delivery_note'] ?? ''))];
    }

    /** What a file of the one form is (GoodsReceipts::FILE_ROLES; a photo when the form says nothing). */
    private static function fileRole(?string $role): string
    {
        return isset(GoodsReceipts::FILE_ROLES[$role ?? '']) ? (string) $role : 'photo';
    }

    /** A photo taken from a line's panel names its line ("Line 3"), with the line's note when typed. */
    private static function photoNote(UiRequest $req): ?string
    {
        $no = UiRequest::id($req->field('photo_line'));
        if ($no === null) {
            return null;
        }
        $note = trim((string) ($req->field("line_{$no}_note") ?? ''));
        return mb_substr($note === '' ? Words::say('GI', 'photo_of', $no) : Words::say('GI', 'found', $no, $note), 0, 255);
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
        try {
            $file = self::upload($ctx);
            $ctx->goodsReceipts()->attach($ctx->caller(), $id, $file['path'], $file['name'], $ctx->req->field('role') ?? '', $ctx->req->field('note'));
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id, ['notice' => 'attached']));
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

    /** GET /ui/receiving/{id}/bench (an old link): the check is part of the delivery's one page (docs/decisions.md GV2). */
    public function benchForm(Context $ctx): HtmlResponse
    {
        $doc = $ctx->documents()->find($ctx->id());
        if ($doc === null || $doc->docType !== 'GRN' || $doc->reversesId !== null) {
            return $ctx->error(404, 'unknown_receipt', Words::RECEIPT_ERROR['unknown_receipt']);
        }
        $from = UiRequest::id($ctx->req->param('from'));
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $doc->id, ['from' => $from !== null && $from > 1 ? $from : null]) . '#lines');
    }

    /**
     * POST /ui/receiving/{id}/bench: the older bench page's form (a page left open across the change to the one page, GV9), as before;
     * it lands on the delivery's one page. The bench check of one page of lines (b_<line>_<field>) and the delivery's checklist. A line is counted when its
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
                    return $this->page($ctx, $id, $e->httpStatus, $e, null, ['typed' => $req->post, 'from' => $from]);
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
                return $this->page($ctx, $id, 409, new CwException('version_conflict', Words::say('RECEIPT_ERROR', 'version_conflict_bench',
                    $moved === [] ? Words::RECEIPT_ERROR['bench_the_delivery'] : ReceiptPlan::lineList($moved),
                    $moved === [] ? '' : Words::say('RECEIPT_ERROR', 'bench_except', ReceiptPlan::lineList($moved))), 409), null, ['typed' => $typed, 'from' => $from]);
            }
        }
        $anchor = $lines === [] ? '' : '#line-' . array_key_last($lines);
        return HtmlResponse::redirect(Html::url('/ui/receiving/' . $id, ($from > 1 ? ['from' => $from] : []) + ['notice' => 'checked']) . $anchor);
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
            if ($ctx->req->field('invoice_split') === '1') {
                $fields['invoice_split'] = true; // pack PO (PO10): a later delivery on the same supplier invoice
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
     * The delivery's page: the one page (onePage()) for someone who receives goods while it is a draft, else the read-only view; also after a
     * refused form (the error shown, answered under its status). $extra: typed, q, choices, packs, price, importErrors, skipped, from, open,
     * ready.
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
        // Goods in on one page (docs/decisions.md GV2): a delivery not booked in yet is the one page for everyone who receives goods (its
        // keyer changes everything; the others check it, set its supplier invoice and book it in); the others look at its read-only page.
        $onePage = $doc->status === 'draft' && $canPost;
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
            // --- Pack PO (PO10, review finding 6): the number is on another delivery of the same order: "a later delivery on this invoice"
            // is offered as a second step (the /invoice form with invoice_split); and which delivery of its invoice this one is.
            'splitStep' => $doc->status === 'draft' && $canPost && $error?->errorCode === 'duplicate_invoice' && is_string($error->detail['split_po'] ?? null)
                ? ['po' => (string) $error->detail['split_po'], 'number' => (string) ($error->detail['number'] ?? '')] : null,
            'invoicePart' => (int) ($gr['invoice_part'] ?? 1),
            // --- end of pack PO
        ];
        if ($onePage) {
            return $this->onePage($ctx, $doc, $data, $status, $error, $notice, $extra);
        }
        $data['canSetInvoice'] = $canPost && $doc->status === 'draft' && $doc->createdBy !== $me->id;
        // --- Pack C: the invoices that bill this delivery, and "Book its invoice" (the quick way) while units are left to invoice.
        $data['invoiceBook'] = self::invoiceBook($ctx, $doc);
        // --- Pack J (docs/decisions.md PJ14): a receipt made from an invoice names it, says what it did not bring, and claims it.
        $data['arrivalBox'] = ArrivalsController::receiptBox($ctx, $doc->id, $doc->status);
        return $ctx->page('receipt', $data + $this->viewData($ctx, $doc), $status, ['title' => $data['title'], 'active' => 'receiving', 'notice' => $notice]);
    }

    /**
     * The delivery on ONE page (the owner's approved design of 29 Oct 2026, Main and GI_Phone; docs/decisions.md GV2-GV7): its board state
     * in the head with "More" (copy the order, import the supplier's sheet, cancel: the keyer's), the progress strip (lines, checked, problems,
     * items in), the details (the keyer's fields, or the supplier invoice and the paperwork for someone else), the scan box, the lines as a
     * board (packs in, items, the check and the duty stamp as solid cells, "All fine" / "Problem" / "Open", the problem panel on the row), the
     * prices fold for the buying desk (invoices.view), photos and papers, the note, "Before it can be booked in" and the sticky bar. Lines are
     * shown a page at a time when they do not fit max_input_vars (GV7).
     *
     * @param array<string, mixed> $data page()'s
     * @param array<string, mixed> $extra
     */
    private function onePage(Context $ctx, Document $doc, array $data, int $status, ?CwException $error, ?string $notice, array $extra): HtmlResponse
    {
        $svc = $ctx->goodsReceipts();
        $db = $ctx->db;
        $me = $ctx->me();
        $gr = $data['gr'];
        $plan = $data['plan'];
        $all = $data['lines'];
        $keyer = $doc->createdBy === $me->id;
        $prices = $me->can('invoices.view');
        if (!$prices && $plan !== null) {
            // Goods in never sees a price (GV6): the warnings about one (a line at £0) are the buying desk's.
            $plan['warnings'] = array_values(array_filter($plan['warnings'], static fn (string $w): bool => !str_contains($w, '£')));
            $data['plan'] = $plan;
        }
        $count = count($all);
        $size = self::pageSize($keyer);
        $from = $count <= $size ? 1 : max(1, min($count, (int) ($extra['from'] ?? UiRequest::id($ctx->req->param('from') ?? '') ?? 1)));
        $page = array_slice($all, $from - 1, $size, true);
        $stamps = $svc->benchStamps($doc->id);
        $typed = $extra['typed'] ?? [];
        $open = (int) ($extra['open'] ?? 0);
        // Each line's codes (the scan box finds its line by them), its check, its duty answer, what was ordered, its stamps.
        $codes = [];
        $skus = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['sku_id'], $page)));
        if ($skus !== []) {
            foreach ($db->all('SELECT sku_id, barcode FROM sku_barcode WHERE is_usable = 1 AND sku_id IN (' . implode(', ', array_fill(0, count($skus), '?')) . ') ORDER BY sku_id, barcode',
                $skus) as $b) {
                $codes[(int) $b['sku_id']][] = (string) $b['barcode'];
            }
        }
        $rows = [];
        foreach ($page as $no => $l) {
            $stampReq = (bool) $l['stamp_req'];
            $rows[$no] = $l + [
                'check' => self::lineCheck($l, $stampReq),
                'duty' => $stampReq ? self::dutyAnswer($l) : 'not_needed',
                'ordered' => self::ordered($l, $plan['lines'][$no]['po_line'] ?? null),
                'accepted' => (int) ($l['split']['accepted'] ?? 0),
                'identity' => $stamps['identity'][$no] ?? '',
                'was' => ($stamps['lines'][$no] ?? '') . '.' . self::answersFp(self::drawnAnswers($l, $stampReq)),
                'codes' => implode(' ', array_filter([(string) $l['sku_code'], (string) ($l['supplier_code'] ?? ''), ...($codes[(int) $l['sku_id']] ?? [])])),
                // The panel opens on the line a refusal names, a line with something to settle, and a line typed in before a refusal.
                'open' => $open === $no || $l['problems'] !== [] || self::typedProblem($typed, $no),
            ];
        }
        $checked = count(array_filter($all, static fn (array $l): bool => $l['checked_at'] !== null));
        $problemLines = count(array_filter($all, static fn (array $l): bool => $l['checked_at'] !== null && self::lineCheck($l, (bool) $l['stamp_req']) === 'problem'));
        $linked = false;
        foreach ($all as $l) {
            $linked = $linked || $l['po_line_no'] !== null;
        }
        $orders = [];
        if ($keyer && !$linked) {
            $orders = $svc->receivableOrders((int) ($gr['supplier_id'] ?? 0));
            if ($data['po'] !== null && !in_array((int) $data['po']['id'], array_map(static fn (array $o): int => (int) $o['id'], $orders), true)) {
                // The order it names, even when it expects nothing any more (closed, received): the select must not drop it unseen.
                array_unshift($orders, ['id' => (int) $data['po']['id'], 'number' => $data['po']['number'], 'outstanding' => 0]);
            }
        }
        $poLines = [];
        if ($keyer && $prices && $data['po'] !== null) {
            foreach ($db->all("SELECT pl.line_no, dl.qty, pl.received_units, s.code, s.name FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id "
                . "AND dl.line_no = pl.line_no LEFT JOIN sku s ON s.id = dl.sku_id WHERE pl.document_id = ? AND pl.kind = 'item' ORDER BY pl.line_no", [(int) $data['po']['id']]) as $p) {
                $poLines[(int) $p['line_no']] = Words::say('RECEIPT', 'po_line_option', (int) $p['line_no'], (string) ($p['name'] ?? $p['code']),
                    max(0, (int) $p['qty'] - (int) $p['received_units']));
            }
        }
        $todayUk = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');
        $hasInvoice = (bool) ($plan['header']['has_invoice_file'] ?? false);
        $benchState = (string) $data['benchState'];
        $state = match ($benchState) {
            'done' => 'ready',
            'refused' => 'refused',
            'todo' => 'todo',
            default => 'checking',
        };
        $arrival = ArrivalsController::receiptBox($ctx, $doc->id, $doc->status);
        return $ctx->page('receipt_edit', $data + [
            'keyer' => $keyer,
            'prices' => $prices,
            'rows' => $rows,
            'count' => $count,
            'from' => $from,
            'pageSize' => $size,
            'paged' => $count > $size,
            'state' => $state,
            'progress' => ['lines' => $count, 'checked' => $checked, 'problems' => $problemLines, 'in' => (int) ($plan['totals']['accepted'] ?? 0),
                'of' => (int) ($plan['totals']['units'] ?? 0)],
            'left' => $count - $checked,
            'typed' => $typed,
            'choices' => $extra['choices'] ?? null,
            'q' => $extra['q'] ?? '',
            'packs' => (int) ($extra['packs'] ?? 1),
            'price' => (string) ($extra['price'] ?? ''),
            'stamp' => $svc->deskStamp($doc->id),
            'benchWas' => $stamps['checklist'] . '.' . (($gr['paperwork_ok'] ?? null) === null ? '' : (string) (int) $gr['paperwork_ok']) . '.'
                . self::answersFp([trim((string) ($gr['bench_note'] ?? ''))]),
            'invWas' => $keyer ? null : self::answersFp(self::invoiceAnswers(['invoice_number' => $doc->externalRef, 'invoice_date' => $gr['invoice_date'] ?? null,
                'delivery_note' => $gr['delivery_note'] ?? null])),
            'refusedPost' => ($extra['ready'] ?? false) || ($error !== null && is_array($error->detail['problems'] ?? null)),
            'reaches' => $prices ? self::reaches($this->receiptSites($ctx)) : '',
            'poLines' => $poLines,
            'suppliers' => $keyer && $all === [] ? $db->all("SELECT id, code, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id") : [],
            'orders' => $orders,
            'linked' => $linked,
            'modeChoices' => GoodsReceipts::MODE_CHOICES,
            'refusal' => (bool) ($plan['header']['refusal_regime'] ?? true),
            'receivedToday' => ($plan['header']['received_uk'] ?? $todayUk) === $todayUk,
            'receivedDay' => (string) ($plan['header']['received_day'] ?? ''),
            'backdated' => (bool) ($plan['header']['backdated'] ?? false),
            'actions' => Words::UNSTAMPED_ACTION,
            'duties' => self::DUTY_ANSWERS,
            'fileRole' => $hasInvoice ? 'photo' : 'supplier_invoice',
            'maxBytes' => UiRequest::MAX_UPLOAD_BYTES,
            'arrival' => $arrival,
            'canCopy' => $keyer && $data['po'] !== null,
        ], $status, ['title' => $data['title'], 'active' => 'receiving', 'notice' => $notice]);
    }

    /** A line's check on the page: not checked, checked, or checked with a problem (something short, over, damaged, wrong or without a stamp). @param array<string, mixed> $l */
    public static function lineCheck(array $l, bool $stampReq): string
    {
        if ($l['checked_at'] === null) {
            return 'todo';
        }
        $problem = (int) $l['short_units'] + (int) $l['over_units'] + (int) $l['damaged_units'] + (int) $l['wrong_item_units'] + (int) $l['unstamped_units'] > 0
            || ($stampReq && ($l['stamp_on_pack'] ?? null) !== null && (int) $l['stamp_on_pack'] === 0);
        return $problem ? 'problem' : 'done';
    }

    /**
     * What the order's line asked for, in this line's packs when it is the same pack (else in items): ['packs' => n] or ['items' => n], or
     * null for a line not against the order.
     *
     * @param array<string, mixed> $l
     * @param array<string, mixed>|null $po the plan's po_line
     * @return array{packs?: int, items?: int}|null
     */
    private static function ordered(array $l, ?array $po): ?array
    {
        if ($po === null || ($po['kind'] ?? 'item') !== 'item') {
            return null;
        }
        $upp = (int) $po['units_per_pack'];
        $qty = (int) $po['qty'];
        return $upp === (int) $l['units_per_pack'] && $upp > 0 && $qty % $upp === 0 ? ['packs' => intdiv($qty, $upp)] : ['items' => $qty];
    }

    /** Whether a refused form typed a problem on line $no (its panel opens again). @param array<string, mixed> $typed */
    private static function typedProblem(array $typed, int $no): bool
    {
        foreach (['short', 'over', 'damaged', 'wrong', 'unstamped'] as $k) {
            $v = trim((string) ($typed["b_{$no}_{$k}"] ?? ''));
            if ($v !== '' && $v !== '0') {
                return true;
            }
        }
        return ($typed["b_{$no}_duty"] ?? null) === 'none';
    }

    /**
     * Pack C (docs/decisions.md PC17): a posted delivery's supplier invoices (number, match) and, for someone who books invoices while
     * the delivery has units left to invoice, the form of "Book its invoice"; null for a delivery not posted or a reader of no invoices.
     *
     * @return array{invoices: list<array<string, mixed>>, left: int, formKey: ?string}|null
     */
    private static function invoiceBook(Context $ctx, \CW\Documents\Document $doc): ?array
    {
        $me = $ctx->me();
        if ($doc->status !== 'posted' || $doc->isReversal() || !$me->can('invoices.view')) {
            return null;
        }
        $invoices = $ctx->db->all("SELECT DISTINCT d.id, d.number, si.match_state FROM sinv_line sl JOIN supplier_invoice si ON si.document_id = sl.document_id "
            . "AND si.kind = 'invoice' JOIN document d ON d.id = sl.document_id AND d.status = 'posted' WHERE sl.grn_document_id = ? ORDER BY d.id", [$doc->id]);
        $left = 0;
        foreach ((new \CW\Invoices\InvoiceMatch($ctx->db))->receiptFacts([$doc->id]) as $f) {
            $left += max(0, $f['accepted'] - $f['returned'] - $f['invoiced_elsewhere']);
        }
        $canBook = $left > 0 && \CW\Documents\Documents::mayPost($me->roles, 'SINV');
        return ['invoices' => $invoices, 'left' => $left, 'formKey' => $canBook ? FormOnce::newKey() : null];
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
     * The websites a receipt's selling mode reaches (site_writer.receipt_mode_sites, I152), with whether their stock link is on
     * (the site stock writer on and the website connection live): the editor says so beside the mode column.
     *
     * @return list<array{code: string, name: string, writer: bool}>
     */
    private function receiptSites(Context $ctx): array
    {
        $ids = array_keys((new \CW\SiteWriter\SiteModes($ctx->db))->receiptChannels($ctx->settings()));
        if ($ids === []) {
            return [];
        }
        // The stock link is on when both switches are: the site writer and the website connection live (as on the product page).
        return array_map(static fn (array $r): array => ['code' => (string) $r['code'], 'name' => (string) $r['name'],
            'writer' => (int) $r['site_writer'] === 1 && (string) $r['mode'] === 'live'],
            $ctx->db->all('SELECT code, name, mode, site_writer FROM channel WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id', $ids));
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
            in_array($code, ['scan_pending', 'not_found', 'import_refused', 'choose_supplier', 'reason_required', 'fine_needs_stamp', 'fine_no_stamp', 'not_booked'], true)
                => $e->getMessage(),
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
        // A rejected review reverses the receipt; its order closed meanwhile stays closed while something of it is still delivered
        // (pack PO, PO8: it was refused, I176 (1)): the box says so before the reviewer presses reject.
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
     * The delivery's lines with the edits of the page's rows applied (rows $from..$to; the other pages' lines as they are, GV7): packs (0
     * removes the line), pack price, units per pack (a line without a supplier item), PO line, selling mode, note. A field the page did
     * not draw (the prices, for someone who does not see them) keeps the line's value.
     *
     * @param list<array<string, mixed>> $current
     * @return list<array<string, mixed>>
     */
    private static function editedLines(UiRequest $req, array $current, int $count, int $from = 1, ?int $to = null): array
    {
        if ($count !== count($current)) {
            throw new CwException('version_conflict', 'the lines changed since this page was drawn', 409);
        }
        $to ??= $count;
        $out = [];
        foreach ($current as $i => $l) {
            $n = $i + 1;
            if ($n < $from || $n > $to) {
                $out[] = $l;
                continue;
            }
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

    /** @return array{state: ?string, supplier: ?int, q: string, show: string} */
    private static function filters(UiRequest $req): array
    {
        $state = in_array($req->param('state'), self::STATE_FILTERS, true) ? $req->param('state') : null;
        $show = in_array($req->param('show'), self::SHOWS, true) ? (string) $req->param('show') : '';
        return [
            'state' => $state,
            'supplier' => UiRequest::id($req->param('supplier')),
            'q' => mb_substr(trim($req->param('q') ?? ''), 0, 100),
            // An old link's state (Home's cards: checked, refused, posted ...) looks at every delivery of that state, not only today's.
            'show' => $show === '' && $state !== null ? 'all' : $show,
        ];
    }

    /**
     * The deliveries of the board, newest first (LIST_LIMIT): today and open = every draft and what was booked in today (UK day); all;
     * the cancelled ones. The search finds the number, the supplier invoice, the order, the supplier and a scanned delivery note.
     *
     * @param array{state: ?string, supplier: ?int, q: string, show: string} $f
     * @return list<array<string, mixed>>
     */
    private function rows(Context $ctx, array $f): array
    {
        $where = ["d.doc_type = 'GRN'", 'd.reverses_id IS NULL'];
        $params = [];
        if ($f['show'] === '') {
            $midnight = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->setTime(0, 0);
            $where[] = "(d.status = 'draft' OR (d.status IN ('posted', 'awaiting_approval') AND d.posted_at >= ?))";
            $params[] = \CW\Clock::db($midnight);
        } elseif ($f['show'] === 'cancelled') {
            $where[] = "d.status = 'cancelled'";
        }
        if ($f['state'] === 'checked') {
            // Checked at the bench, not booked in yet: the paperwork looks right and every line is checked (Home's "to book in").
            $where[] = "d.status = 'draft' AND g.paperwork_ok = 1 AND EXISTS (SELECT 1 FROM grn_line l WHERE l.document_id = d.id) "
                . 'AND NOT EXISTS (SELECT 1 FROM grn_line l WHERE l.document_id = d.id AND l.checked_at IS NULL)';
        } elseif ($f['state'] === 'refused') {
            // Refused at the bench (the paperwork is not right), not cancelled yet (Home's "refused at the goods-in bench").
            $where[] = "d.status = 'draft' AND g.paperwork_ok = 0";
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
            $where[] = '(d.number LIKE ? OR d.external_ref LIKE ? OR p.number LIKE ? OR s.name LIKE ? OR g.delivery_note LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return $ctx->db->all(
            'SELECT d.id, d.number, d.status, d.review_state, d.external_ref, d.posted_at, g.received_at, g.paper_sheet, g.checked_at, g.paperwork_ok, g.delivery_note, '
            . 's.code AS supplier_code, s.name AS supplier_name, (SELECT COUNT(*) FROM grn_line l WHERE l.document_id = d.id AND l.checked_at IS NOT NULL) AS checked_lines, '
            . '(SELECT COUNT(*) FROM grn_line l WHERE l.document_id = d.id AND l.checked_at IS NOT NULL AND (l.short_units + l.over_units + l.damaged_units '
            . '+ l.wrong_item_units + l.unstamped_units > 0 OR l.stamp_on_pack = 0)) AS problem_lines, '
            . 'p.number AS po_number, u.display_name AS created_by_name, pb.display_name AS posted_by_name, '
            . '(SELECT COUNT(*) FROM document_line l WHERE l.document_id = d.id) AS `lines`, (SELECT COALESCE(SUM(l.qty), 0) FROM document_line l WHERE l.document_id = d.id) AS units, '
            . "(SELECT COUNT(*) FROM incident i WHERE i.document_id = d.id AND i.status = 'open') AS open_incidents "
            . 'FROM document d JOIN goods_receipt g ON g.document_id = d.id JOIN supplier s ON s.id = g.supplier_id LEFT JOIN document p ON p.id = g.po_document_id '
            . 'LEFT JOIN staff_user u ON u.id = d.created_by LEFT JOIN staff_user pb ON pb.id = d.posted_by WHERE ' . implode(' AND ', $where)
            . ' ORDER BY d.id DESC LIMIT ' . self::LIST_LIMIT,
            $params,
        );
    }
}
