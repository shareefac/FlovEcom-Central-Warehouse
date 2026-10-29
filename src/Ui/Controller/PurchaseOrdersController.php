<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Auth\Permissions;
use CW\Company\CompanyDetails;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Items\HandMade;
use CW\OpResult;
use CW\Output\CsvWriter;
use CW\PurchaseOrders\PoLinesFile;
use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrderPdf;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\Suppliers;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Kernel;
use CW\Ui\PoWarnings;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * The purchase order screens (IM5, Phase I-2; docs/decisions.md I48-I59, spec §8.1, §8.3): the list (filters, CSV), the order on ONE
 * page (PO on one page, PX1-PX10: "New purchase order" opens it with no draft; the draft is made by its first POST with something to
 * keep and is the same page after: the supplier with "+ Add new supplier", the board of products whose last row is the scan box with
 * "+ Add new item", the delivery charge, the totals, Save and Confirm, all one form whose Enter adds a line and saves every edit), the
 * posted order's page (its state, review and history, the decide box, send / cancel / amend / copy / close), the lines file (CSV, XLSX:
 * export and an all-or-nothing import) and the PDF.
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
     * The editor's fields outside its line rows, with room to spare: csrf, version, line_count, lines_editable, form_key, the pressed button,
     * the supplier and the three of "+ Add new supplier", 3 order details, q and the choice's two hidden fields, packs, the 7 of "+ Add new
     * item", the delivery charge (25 in fact since PO on one page, PX4).
     */
    public const EDITOR_FIXED_FIELDS = 28;

    /**
     * The most fields the editor form of these lines sends: an item line with a supplier item 4 (packs, price, VAT, note),
     * without one 6 (+ units per pack, "save"), a charge 4 (packs, price, note, "remove"); plus EDITOR_FIXED_FIELDS.
     *
     * @param list<array<string, mixed>> $lines po_line rows (kind, supplier_item_id)
     */
    public static function editorFields(array $lines): int
    {
        $n = self::EDITOR_FIXED_FIELDS;
        foreach ($lines as $l) {
            $n += ($l['kind'] ?? 'item') === 'charge' ? 4 : (($l['supplier_item_id'] ?? null) === null ? 6 : 4);
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

    /** The list (its "New purchase order" button opens the order's one page: PO on one page, docs/decisions.md PX1). */
    public function index(Context $ctx): HtmlResponse
    {
        $me = $ctx->me();
        $draftsOff = self::draftsOffByDefault($me->roles);
        $f = self::filters($ctx->req, $draftsOff);
        $canPost = Documents::mayPost($me->roles, 'PO');
        $recent = $ctx->settings()->has('po.board_recent_days') ? (int) $ctx->settings()->get('po.board_recent_days') : 14;
        $rows = array_map(static fn (array $r): array => self::screenRow($r, $canPost), $this->rows($ctx, $f, self::LIST_LIMIT, $recent));
        return $ctx->page('purchase_orders', [
            'rows' => $rows,
            // The board (the owner's approved design, docs/decisions.md PV18): the groups in their order, each with its colour.
            'groups' => self::boardGroups($rows),
            'filters' => $f,
            'filtered' => $f['state'] !== null || $f['supplier'] !== null || $f['q'] !== '' || $f['rejected'] || $f['all'] || $f['view'] !== 'open',
            // Behaviour item 8 (F305, provisional): goods-in and the purchasing desk do not see drafts unless they ask for them.
            'draftsChoice' => $draftsOff,
            'draftsHidden' => !$f['drafts'] && $f['state'] === null,
            'states' => self::STATE_FILTERS,
            'suppliers' => $ctx->db->all("SELECT id, code, name, status FROM supplier ORDER BY name, id"),
            'canPost' => $canPost,
            'lookOnly' => $canPost ? null : Words::whoCan('doc.PO.post'),
            'limit' => self::LIST_LIMIT,
            // null while the owner has the OK first of orders switched off (Approval rules page, 0019, Y10).
            'approvalLimit' => $ctx->documents()->typeInfo('PO')['approval_rule'] === 'none' ? null
                : Html::money((int) $ctx->documents()->typeInfo('PO')['approval_limit_units']),
        ], 200, ['title' => Words::MENU['orders'], 'active' => 'orders']);
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
            'gross' => $cancellation || ($r['gross_total'] ?? null) === null ? null : Html::money($r['gross_total']),
            'received' => (int) ($r['received'] ?? 0),
            'check' => $r['review_state'] === null ? null : Words::of('CHECK_STATE', (string) $r['review_state']),
            'check_tone' => Words::tone('REVIEW_STATE', $r['review_state'] === null ? null : (string) $r['review_state']),
            'sent_line' => $r['sent_at'] === null ? null : Words::say('ORDERS', 'sent_on', Html::when((string) $r['sent_at']), lcfirst(Words::of('SEND_VIA', (string) $r['sent_via']))),
        ];
    }

    /** The board's groups (PV18), in their order, with their tone and words: waiting for approval, on the way, draft, received or closed, cancelled. */
    public const BOARD = ['waiting' => 'review', 'on_way' => 'info', 'draft' => 'waiting', 'done' => 'done', 'cancelled' => 'off'];

    /**
     * The board's groups of these rows (PV18): each row to its group by its state, the groups in BOARD's order, only those with rows.
     *
     * @param list<array<string, mixed>> $rows screenRow() rows
     * @return list<array{key: string, tone: string, name: string, rows: list<array<string, mixed>>}>
     */
    public static function boardGroups(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $g = $r['reverses_id'] !== null ? 'cancelled' : match ((string) $r['state_code']) {
                'awaiting_approval' => 'waiting',
                'approved', 'sent', 'part_received' => 'on_way',
                'draft' => 'draft',
                'received', 'closed' => 'done',
                default => 'cancelled',
            };
            $by[$g][] = $r;
        }
        $out = [];
        foreach (self::BOARD as $key => $tone) {
            if (isset($by[$key])) {
                $out[] = ['key' => $key, 'tone' => $tone, 'name' => Words::ORDERS['group_' . $key], 'rows' => $by[$key]];
            }
        }
        return $out;
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
        $f = self::filters($ctx->req);
        // The CSV keeps its default (every order but the cancellation records); "closed" and "all" of the board's Show menu narrow it the same way.
        foreach ($this->rows($ctx, $f, null, $f['view'] === 'open' ? null : 0) as $r) {
            $csv->add([$r['label'], (int) $r['id'], $r['supplier_code'], $r['supplier_name'], $r['doc_date'], $r['expected_date'], $r['state_label'], (int) $r['lines'],
                (int) $r['units'], $r['net_total'], $r['vat_total'], $r['gross_total'], $r['review_state'], $r['sent_at'], $r['sent_via'], $r['external_ref'],
                $r['source'], $r['created_by_name']]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'orders.csv');
    }

    // ------------------------------------------------------------------------------------------
    // The order on one page (the owner, 26 Oct 2026; docs/decisions.md PX1-PX9)
    // ------------------------------------------------------------------------------------------

    /**
     * GET /ui/purchasing/orders/new: the order's one page at once, with no draft (PX1, PX2): nothing is written by opening it, so a page left
     * empty leaves nothing behind. The address may name the supplier (the supplier's card, "+ Add new supplier" coming back) and carry what
     * was typed before a new supplier was added (carried()).
     */
    public function newPage(Context $ctx): HtmlResponse
    {
        $typed = [];
        foreach (self::CARRIED as $k) {
            $v = $ctx->req->param($k);
            if ($v !== null) {
                $typed[$k] = mb_substr($v, 0, $k === 'note' ? 1000 : 255);
            }
        }
        $typed['supplier_id'] = (string) (UiRequest::id($ctx->req->param('supplier')) ?? '');
        $typed['pick_sku'] = (string) (UiRequest::id($ctx->req->param('pick_sku')) ?? ''); // the product chosen before a new supplier (checked by picked())
        $notice = self::noticeKey($ctx);
        return $this->newOrderPage($ctx, 200, null, $notice === null ? null : Words::PO_NOTICE[$notice], ['typed' => $typed, 'q' => $typed['q'] ?? '',
            'packs' => $typed['packs'] ?? '1']);
    }

    /**
     * POST /ui/purchasing/orders: the one page's form before the order exists (PX2). Everything runs through FormOnce, so the same form sent
     * twice does one thing:
     *  - "+ Add new supplier": the supplier alone (PurchaseOrders::newSupplier), then the page again with it chosen and what was typed;
     *  - else, with a supplier chosen and something to keep (a product added, "+ Add new item", Save, Confirm): the draft is made and the form
     *    applied to it in ONE transaction (apply()); a refusal, a search with several products or none, rolls it all back: no draft.
     * Without a supplier, or Add with nothing to add, nothing is written (the page says so, with what was typed).
     */
    public function create(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $ask = self::asked($req);
        $denied = self::denied($ctx, $ask['action']);
        if ($denied !== null) {
            return $denied;
        }
        $sid = UiRequest::id($req->field('supplier_id'));
        try {
            if ($ask['action'] === 'new_supplier') {
                $fields = PoAddNew::supplierFields(PoAddNew::supplierTyped($req));
                $r = FormOnce::run($ctx, 'ui.po.new_supplier', self::request($req), function (Db $db) use ($ctx, $req, $fields): OpResult {
                    $s = $this->service($ctx)->newSupplier($ctx->caller(), new Suppliers($db, $ctx->settings()), $fields);
                    return OpResult::of(303, ['result' => 'supplier_added', 'supplier_id' => (int) $s['id'],
                        'redirect' => Html::url('/ui/purchasing/orders/new', ['supplier' => (int) $s['id']] + array_merge(self::carried($req), $this->carriedPick($ctx, $req))
                            + ['notice' => 'supplier_added'])]);
                });
                return FormOnce::redirect($r);
            }
            if ($sid === null) {
                throw new CwException('need_supplier', Words::PO_ONE['need_supplier'], 422, ['field' => 'supplier_id']);
            }
            if ($ask['action'] === 'approve' && $ask['q'] !== '') {
                throw new CwException('scan_pending', Words::say('BUY_ERROR', 'scan_pending', $ask['q']), 422);
            }
            if ($ask['action'] === 'add' && $ask['si'] === null && $ask['sku'] === null && $ask['q'] === '') {
                throw new CwException('need_product', Words::PO_ONE['need_product'], 422, ['field' => 'q']);
            }
            $header = self::header($req, false);
            $lines = self::withCharge($req, []);
            $r = FormOnce::run($ctx, 'ui.po.create', self::request($req), function (Db $db) use ($ctx, $sid, $header, $lines, $ask): OpResult {
                $svc = $this->service($ctx);
                $d = $svc->createDraft($ctx->caller(), $sid, []);
                $res = $this->apply($ctx, $svc, $d->id, $d->version, $header, $lines, $ask);
                if ($res['status'] === 'choices' || $res['status'] === 'not_found') {
                    // Nothing to keep yet: the draft goes with the rest of the transaction, and the page shows the choices or says so.
                    throw new CwException('one_page_' . $res['status'], '', $res['status'] === 'choices' ? 200 : 422, ['choices' => $res['choices'] ?? []]);
                }
                $status = $res['status'] === 'saved' ? 'created' : $res['status'];
                return OpResult::of(303, ['result' => $status, 'document_id' => $d->id, 'redirect' => self::after($d->id, $status)]);
            });
        } catch (CwException $e) {
            $extra = ['typed' => $req->post, 'q' => $ask['q'], 'packs' => $req->field('packs') ?? '1', 'formKey' => PoAddNew::keyAgain($ctx, $e)];
            return match (true) {
                $e->errorCode === 'one_page_choices' => $this->newOrderPage($ctx, 200, null, null, ['choices' => $e->detail['choices'] ?? []] + $extra),
                $e->errorCode === 'one_page_not_found' => $this->newOrderPage($ctx, 422, new CwException('not_found', Words::say('BUY_ERROR', 'not_found', $ask['q']), 422), null,
                    $extra),
                default => $this->newOrderPage($ctx, $e->httpStatus, $ask['action'] === 'new_supplier' || $ask['action'] === 'new_item' ? null : $e, null,
                    $extra + self::panelError($ask['action'], $e)),
            };
        }
        return FormOnce::redirect($r);
    }

    /**
     * GET /ui/purchasing/orders/products?supplier=&q=: the choices of the order's product box (PX8), a fragment app.js draws in the box's
     * list; read only. What the scan box would find (PurchaseOrders::resolve: a barcode, their code, a CW number, else words of the name, this
     * supplier's items first, at most PurchaseOrders::SEARCH_LIMIT), each with the box's words for it and what Add sends for it (its supplier
     * item, else the item). Fewer than 2 characters: nothing yet.
     */
    public function products(Context $ctx): HtmlResponse
    {
        $q = mb_substr(trim($ctx->req->param('q') ?? ''), 0, 100);
        $sid = UiRequest::id($ctx->req->param('supplier')) ?? 0;
        $choices = [];
        if (mb_strlen($q) >= 2) {
            $r = $this->service($ctx)->resolve($sid, $q);
            $rows = $r['status'] === 'choices' ? $r['choices'] : ($r['status'] === 'found' ? [self::foundRow($ctx->db, $r)] : []);
            $choices = array_map(self::productChoice(...), $rows);
        }
        return $ctx->fragment('po_products', ['choices' => $choices, 'says' => $choices === [] && mb_strlen($q) >= 2 ? Words::say('PO_ONE', 'none', $q) : '']);
    }

    /** A resolve() `found` as a choice row (the order's product box and the supplier invoice's item box, PV). @param array<string, mixed> $r @return array<string, mixed> */
    public static function foundRow(Db $db, array $r): array
    {
        $s = $db->one('SELECT code, name, brand FROM sku WHERE id = ?', [(int) $r['sku_id']]) ?? [];
        $si = $r['supplier_item'] ?? null;
        return ['sku_id' => (int) $r['sku_id'], 'sku_code' => (string) ($s['code'] ?? ''), 'name' => (string) ($s['name'] ?? ''), 'brand' => $s['brand'] ?? null,
            'supplier_item_id' => $si === null ? null : (int) $si['id'], 'units_per_pack' => (int) $r['units_per_pack'], 'supplier_code' => $si['supplier_code'] ?? null,
            'purchase_unit' => (string) $r['purchase_unit']];
    }

    /**
     * One choice of the product box: the box's words for it (its name, its CW number, its pack when it is set up: what app.js compares), its
     * two lines (the name;
     * the CW number, the brand and how this supplier sells it) and what Add sends (its supplier item, else the item).
     *
     * @param array<string, mixed> $c a resolve() choice
     * @return array{label: string, name: string, meta: string, si: string, sku: string}
     */
    public static function productChoice(array $c): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $c['name']));
        $si = $c['supplier_item_id'] ?? null;
        $supply = $si === null ? Words::PO_ONE['not_set_up']
            : Words::say('PO_ONE', 'set_up', (string) ($c['supplier_code'] ?? Words::ORDER['choice_no_code']), self::pack((string) $c['purchase_unit'], (int) $c['units_per_pack']));
        // The box's words: the name and the CW number, and for one of this supplier's supplies its pack, so that two packs of one product
        // never share their words (the Codex review of bd26fc3, finding 1; one supply a pack: uq_supplier_item_pack).
        $label = $name . ' · ' . $c['sku_code'] . ($si === null ? '' : ' · ' . self::pack((string) $c['purchase_unit'], (int) $c['units_per_pack']));
        return ['label' => $label, 'name' => $name,
            'meta' => implode(' · ', array_filter([(string) $c['sku_code'], (string) ($c['brand'] ?? ''), $supply], static fn (string $v): bool => $v !== '')),
            'si' => $si === null ? '' : (string) $si, 'sku' => $si === null ? (string) $c['sku_id'] : ''];
    }

    /** The fields "+ Add new supplier" carries back to the new page (what was typed before it), besides the supplier itself. */
    public const CARRIED = ['doc_date', 'expected_date', 'external_ref', 'note', 'q', 'packs', 'charge_description', 'charge_amount', 'charge_vat',
        'item_name', 'item_brand', 'item_barcode', 'item_code', 'item_upp', 'item_price']; // the new item typed before it too (Codex review of bd26fc3, finding 6)

    /**
     * What a draft's "+ Add new supplier" carries back to the draft's page (the Codex review of a90d22d, finding 1): what setNewSupplier does
     * not save with it (the details and the charge it does): the scan box and a new item not made yet.
     */
    public const DRAFT_CARRIED = ['q', 'packs', 'item_name', 'item_brand', 'item_barcode', 'item_code', 'item_upp', 'item_price'];

    /** The fields of the two "+ Add new" forms: never saved by Add or Save, so kept as typed when the page comes back. */
    public const UNSAVED = ['new_supplier_name', 'new_supplier_email', 'new_supplier_phone', 'item_name', 'item_brand', 'item_barcode', 'item_code', 'item_upp',
        'item_price', 'item_anyway'];

    /**
     * The product chosen in the box, carried through "+ Add new supplier" (the Codex review of 0917562, finding 1): as its item (`pick_sku`)
     * with the item's own words in the box, since a supply of the supplier before is not the new one's; Add then finds the item's supply
     * with the new supplier (none yet for a new one: a line without a supplier item). Nothing when the box no longer names its choice.
     *
     * @return array{q?: string, pick_sku?: string}
     */
    private function carriedPick(Context $ctx, UiRequest $req): array
    {
        $q = trim($req->field('q') ?? '');
        $si = UiRequest::id($req->field('pick_si'));
        $typed = $si !== null ? ['pick_si' => (string) $si] : ['pick_sku' => (string) ($req->field('pick_sku') ?? '')];
        $owner = $si === null ? null : (int) ($ctx->db->value('SELECT supplier_id FROM supplier_item WHERE id = ?', [$si]) ?? 0);
        $kept = $this->picked($ctx, $owner, $typed, $q);
        if ($kept['choice'] === null) {
            return [];
        }
        $sku = $si === null ? (int) $kept['sku'] : (int) $ctx->db->value('SELECT sku_id FROM supplier_item WHERE id = ?', [$si]);
        $own = $this->picked($ctx, null, ['pick_sku' => (string) $sku], self::productChoice(self::itemChoiceRow($ctx, $sku))['label']);
        return $own['choice'] === null ? [] : ['q' => $own['choice']['label'], 'pick_sku' => (string) $sku];
    }

    /** An item as a choice row without a supply (productChoice()'s input). @return array<string, mixed> */
    public static function itemChoiceRow(Context $ctx, int $sku): array
    {
        $r = $ctx->db->one('SELECT id, code, name, brand FROM sku WHERE id = ?', [$sku]) ?? ['id' => $sku, 'code' => '', 'name' => '', 'brand' => null];
        return ['sku_id' => (int) $r['id'], 'sku_code' => (string) $r['code'], 'name' => (string) $r['name'], 'brand' => $r['brand'], 'supplier_item_id' => null,
            'units_per_pack' => 1, 'supplier_code' => null, 'purchase_unit' => 'each'];
    }

    /**
     * @param list<string> $fields
     * @return array<string, string> those fields the form sent (empty ones left out, and a packs box holding 1)
     */
    private static function carried(UiRequest $req, array $fields = self::CARRIED): array
    {
        $out = [];
        foreach ($fields as $k) {
            $v = trim($req->field($k) ?? '');
            if ($v !== '' && !(in_array($k, ['packs', 'item_upp'], true) && $v === '1')) { // (1 is what those boxes hold anyway)
                $out[$k] = mb_substr($v, 0, $k === 'note' ? 1000 : 255);
            }
        }
        return $out;
    }

    /**
     * What the one form asks (PX2): the action (add, save, approve, new_supplier, new_item; a row's remove is a Save without that row), what
     * Add adds (a choice button's add_si / add_sku, else the product box's choice pick_si / pick_sku, else the scan text), the packs. Enter is
     * Add (the form's first button). A "+ Add new" form is sent by its own button only: what is left in it never makes anything on Add (the
     * Codex review of bd26fc3, finding 4; with the script, Enter in that form presses its button).
     *
     * @return array{action: string, q: string, packs: int, si: ?int, sku: ?int}
     */
    private static function asked(UiRequest $req): array
    {
        $action = $req->field('action') ?? 'add';
        $action = in_array($action, ['add', 'save', 'approve', 'new_supplier', 'new_item'], true) ? $action : 'add';
        $q = trim($req->field('q') ?? '');
        $si = UiRequest::id($req->field('add_si'));
        $sku = UiRequest::id($req->field('add_sku'));
        if (UiRequest::id($req->field('remove_line')) !== null) {
            return ['action' => 'save', 'q' => $q, 'packs' => self::packs($req), 'si' => null, 'sku' => null]; // a row's remove (PX4)
        }
        if ($si !== null || $sku !== null) {
            $action = 'add'; // a choice's own button
        } elseif ($action === 'add') {
            $si = UiRequest::id($req->field('pick_si'));
            $sku = $si === null ? UiRequest::id($req->field('pick_sku')) : null;
        }
        return ['action' => $action, 'q' => $q, 'packs' => self::packs($req), 'si' => $si, 'sku' => $sku];
    }

    /** The scan box's packs: an empty box is 1; anything but a whole number is 0 (refused by apply() when there is something to add). */
    private static function packs(UiRequest $req): int
    {
        $typed = trim($req->field('packs') ?? '');
        return $typed === '' ? 1 : (PoLinesFile::wholeNumber($typed) ?? 0);
    }

    /** A "+ Add new" action of a person who may not take it: the route's refusal (403) before anything is read or written. */
    private static function denied(Context $ctx, string $action): ?HtmlResponse
    {
        $perm = match ($action) {
            'new_supplier' => 'suppliers.manage',
            'new_item' => 'items.create',
            default => null,
        };
        if ($perm === null || $ctx->me()->can($perm)) {
            return null;
        }
        return $ctx->error(403, 'role_not_allowed', Kernel::refusal($perm, $ctx->me(), 'POST'));
    }

    /** @return array<string, mixed> what the form sent, for FormOnce's hash (without the token and the key) */
    private static function request(UiRequest $req): array
    {
        return array_diff_key($req->post, ['csrf' => 1, FormOnce::FIELD => 1]);
    }

    /** @return array<string, string> the order's details as the form sent them ($withSupplier: the supplier too, a draft's box) */
    private static function header(UiRequest $req, bool $withSupplier): array
    {
        $header = [];
        foreach (['expected_date', 'external_ref', 'note', 'doc_date'] + ($withSupplier ? [4 => 'supplier_id'] : []) as $k) {
            $v = $req->field($k);
            if ($v !== null && ($k !== 'supplier_id' || $v !== '')) {
                $header[$k] = $v;
            }
        }
        return $header;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>> the lines with the delivery charge typed in the summary, when one is (its words: "Delivery")
     */
    private static function withCharge(UiRequest $req, array $lines): array
    {
        $charge = trim($req->field('charge_amount') ?? '');
        if ($charge !== '') {
            $what = trim($req->field('charge_description') ?? '');
            $lines[] = ['kind' => 'charge', 'description' => $what === '' ? Words::PO_ONE['delivery_line'] : $what, 'pack_price' => $charge,
                'vat_code' => ($req->field('charge_vat') ?? '') === '' ? null : $req->field('charge_vat')];
        }
        return $lines;
    }

    /**
     * The one form applied to a draft, inside the caller's transaction (PX2): "+ Add new supplier" puts the new supplier on it with the
     * details typed (PurchaseOrders::setNewSupplier); everything else saves the table and the details (saveDraft), then Confirm approves,
     * "+ Add new item" makes the item and its line (addNewItem), Add adds the choice or what was scanned (addSupplierItem, addLine). The
     * statuses are the notices' (Add's `choices` and `not_found` are not: the caller shows them).
     *
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $lines
     * @param array{action: string, q: string, packs: int, si: ?int, sku: ?int} $ask
     * @return array{status: string, document: Document, choices?: list<array<string, mixed>>}
     */
    private function apply(Context $ctx, PurchaseOrders $svc, int $id, int $version, array $header, array $lines, array $ask): array
    {
        $adding = $ask['action'] === 'new_item' || ($ask['action'] === 'add' && ($ask['si'] !== null || $ask['sku'] !== null || $ask['q'] !== ''));
        if ($adding && ($ask['packs'] < 1 || $ask['packs'] > PoMath::MAX_PACKS)) {
            // Before anything is made (the Codex review of a90d22d, finding 2): 2.5 or 0 packs is refused, never turned into 1.
            throw new CwException('bad_number', Words::say('PO_ADD', 'bad_number', Words::PO_ADD['item_packs']), 422, ['field' => 'packs']);
        }
        $caller = $ctx->caller();
        if ($ask['action'] === 'new_supplier') {
            $r = $svc->setNewSupplier($caller, new Suppliers($ctx->db, $ctx->settings()), $id, $version, PoAddNew::supplierFields(PoAddNew::supplierTyped($ctx->req)),
                array_diff_key($header, ['supplier_id' => 1]), $lines);
            return ['status' => 'supplier_added', 'document' => $r['document']];
        }
        $doc = $svc->saveDraft($caller, $id, $version, $header, $lines);
        switch ($ask['action']) {
            case 'approve':
                // Confirm sits in the one form, so what is approved is what the buyer sees, typed edits included; a refused approval saves nothing.
                $d = $svc->approve($caller, $id, $doc->version);
                return ['status' => $d->status === 'awaiting_approval' ? 'submitted' : 'approved', 'document' => $d];
            case 'new_item':
                [$item, $supply] = PoAddNew::itemInput(PoAddNew::itemTyped($ctx->req));
                $r = $svc->addNewItem($caller, $id, $doc->version, new HandMade($ctx->db, $ctx->decisions()), $item, $supply, $ask['packs']);
                return ['status' => 'item_added', 'document' => $r['document']];
            case 'add':
                if ($ask['si'] !== null) {
                    return $svc->addSupplierItem($caller, $id, $doc->version, $ask['si'], $ask['packs']);
                }
                if ($ask['sku'] !== null || $ask['q'] !== '') {
                    $code = $ask['sku'] !== null ? (string) ($ctx->db->value('SELECT code FROM sku WHERE id = ?', [$ask['sku']]) ?? '') : $ask['q'];
                    return $svc->addLine($caller, $id, $doc->version, $code, $ask['packs']);
                }
                return ['status' => 'saved', 'document' => $doc]; // Enter with nothing to add saves the table, as before
            default:
                return ['status' => 'saved', 'document' => $doc];
        }
    }

    /** Where a done form leads: the draft's page with its notice (at the scan box, after anything but a confirmation or a new supplier). */
    private static function after(int $id, string $status): string
    {
        return Html::url('/ui/purchasing/orders/' . $id, ['notice' => $status]) . (in_array($status, ['approved', 'submitted', 'supplier_added'], true) ? '' : '#scan');
    }

    /**
     * A refusal of a "+ Add new" form, said in that form and with it open (the item's: the items that are there for the same name, the item
     * that has the barcode); none for the other actions.
     *
     * @return array<string, mixed>
     */
    private static function panelError(string $action, CwException $e): array
    {
        if ($action === 'new_supplier') {
            return ['supplierError' => PoAddNew::plainSupplier($e)];
        }
        if ($action !== 'new_item') {
            return [];
        }
        $same = $e->errorCode === 'same_item_exists' && is_array($e->detail['items'] ?? null) ? $e->detail['items'] : null;
        return ['itemError' => $same === null ? PoAddNew::plainItem($e) : null, 'itemSame' => $same,
            'itemOther' => $e->errorCode === 'barcode_on_other_item' && isset($e->detail['sku_id']) ? (int) $e->detail['sku_id'] : null];
    }

    /**
     * The one page with no draft yet (PX1): the editor's template with nothing saved, the supplier chosen in the form (or by the address).
     *
     * @param array<string, mixed> $extra typed, q, packs, choices, formKey, supplierError, itemError, itemSame, itemOther
     */
    private function newOrderPage(Context $ctx, int $status, ?CwException $error, ?string $notice, array $extra): HtmlResponse
    {
        $db = $ctx->db;
        $typed = $extra['typed'] ?? [];
        $sid = UiRequest::id(is_string($typed['supplier_id'] ?? null) ? $typed['supplier_id'] : null);
        $supplier = $sid === null ? [] : ($db->one('SELECT * FROM supplier WHERE id = ?', [$sid]) ?? []);
        $type = $ctx->documents()->typeInfo('PO');
        $zero = PoMath::money(0);
        $data = [
            'doc' => null,
            'title' => Words::PO_ONE['title'],
            'lines' => [],
            'units' => 0,
            'totals' => ['net' => $zero, 'vat' => $zero, 'gross' => $zero, 'by_code' => []],
            'vatLabels' => [],
            'warnings' => [],
            'supplierNeeds' => $supplier === [] ? null : self::supplierNeeds($supplier, (string) $supplier['name']),
            'limit' => $type['approval_rule'] !== 'none' ? Html::money((int) $type['approval_limit_units']) : null,
            'overLimit' => false,
            'error' => $error === null ? null : self::plain($error),
            'importErrors' => [],
            'editable' => true,
            'maxLines' => self::editorMaxLines(),
            'vatCodes' => $db->all('SELECT code, label FROM vat_code WHERE is_active = 1 ORDER BY sort_order, code'),
            'info' => ['doc_date' => (string) ($typed['doc_date'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d')),
                'expected_date' => (string) ($typed['expected_date'] ?? ''), 'external_ref' => (string) ($typed['external_ref'] ?? ''), 'note' => (string) ($typed['note'] ?? '')],
        ] + $this->oneData($ctx, null, $sid, 0, $extra);
        return $ctx->page('purchase_order_edit', $data, $status, ['title' => Words::PO_ONE['title'], 'active' => 'orders', 'notice' => $notice]);
    }

    /**
     * What the one page draws besides the order's own data (PX1-PX8): the form's address and key, the supplier's box (or the supplier fixed
     * by the products), the two "+ Add new" forms (who may, what was typed, open after a refusal, the items offered instead), the product box.
     *
     * @param array<string, mixed> $extra typed, q, packs, choices, formKey, supplierError, itemError, itemSame, itemOther
     * @return array<string, mixed>
     */
    private function oneData(Context $ctx, ?Document $doc, ?int $supplierId, int $lineCount, array $extra): array
    {
        $me = $ctx->me();
        $typed = $extra['typed'] ?? [];
        $text = static fn (string $k, string $default = ''): string => is_string($typed[$k] ?? null) ? trim((string) $typed[$k]) : $default;
        $fixed = $lineCount > 0;
        $same = is_array($extra['itemSame'] ?? null) ? array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'code' => (string) $s['code'], 'name' => (string) $s['name']],
            $extra['itemSame']) : null;
        $other = isset($extra['itemOther']) ? $ctx->db->one('SELECT id, code, name FROM sku WHERE id = ?', [(int) $extra['itemOther']]) : null;
        $itemError = $extra['itemError'] ?? null;
        $supplierError = $extra['supplierError'] ?? null;
        // A new item typed and not made yet (a refusal of another action, "+ Add new supplier" coming back): its form opens again with it.
        $itemTyped = array_filter(['item_name', 'item_brand', 'item_barcode', 'item_code', 'item_price'], static fn (string $k): bool => $text($k) !== '');
        $pick = $this->picked($ctx, $supplierId, $typed, (string) ($extra['q'] ?? ''));
        return [
            'isNew' => $doc === null,
            'formAction' => $doc === null ? '/ui/purchasing/orders' : '/ui/purchasing/orders/' . $doc->id . '/lines',
            'formKey' => (string) ($extra['formKey'] ?? FormOnce::newKey()),
            'supplierFixed' => $fixed,
            'supplierId' => $supplierId,
            'supplierChoices' => $fixed ? [] : self::supplierChoices($ctx->db),
            'canAddSupplier' => !$fixed && $me->can('suppliers.manage'),
            'askSupplier' => Words::askToAdd('suppliers.manage'),
            'supplierError' => $supplierError,
            'newSupplier' => ['name' => $text('new_supplier_name'), 'email' => $text('new_supplier_email'), 'phone' => $text('new_supplier_phone')],
            'canAddItem' => $me->can('items.create'),
            'askItem' => Words::askToAdd('items.create'),
            'itemOpen' => $itemError !== null || $same !== null || $other !== null || $itemTyped !== [],
            'itemError' => $itemError,
            'itemSame' => $same,
            'itemOther' => $other === null ? null : ['id' => (int) $other['id'], 'label' => $other['code'] . ' ' . $other['name']],
            'item' => ['name' => $text('item_name'), 'brand' => $text('item_brand'), 'barcode' => $text('item_barcode'), 'code' => $text('item_code'),
                'upp' => $text('item_upp', '1'), 'price' => $text('item_price'), 'anyway' => $text('item_anyway') === '1'],
            'productsUrl' => '/ui/purchasing/orders/products?' . ($fixed ? 'supplier=' . (int) $supplierId : ''),
            'pick' => $pick,
            'typed' => $typed,
            'choices' => $extra['choices'] ?? null,
            'q' => (string) ($extra['q'] ?? ''),
            'packs' => (string) ($extra['packs'] ?? '1'),
        ];
    }

    /**
     * The product box's choice kept after a refusal (the Codex review of bd26fc3, finding 5): the supplier item (or item) the form sent, when
     * the box still holds its words exactly and it is this supplier's (active, its item not joined into another); else none, and the box's
     * words are searched as typed. Drawn in the box's list and its hidden fields, so the corrected form adds that product.
     *
     * @param array<string, mixed> $typed the form as sent
     * @return array{si: string, sku: string, choice: ?array{label: string, name: string, meta: string, si: string, sku: string}}
     */
    private function picked(Context $ctx, ?int $supplierId, array $typed, string $q): array
    {
        $none = ['si' => '', 'sku' => '', 'choice' => null];
        $si = UiRequest::id(is_string($typed['pick_si'] ?? null) ? $typed['pick_si'] : null);
        $sku = $si === null ? UiRequest::id(is_string($typed['pick_sku'] ?? null) ? $typed['pick_sku'] : null) : null;
        if ($q === '' || ($si === null && $sku === null)) {
            return $none;
        }
        $row = $si !== null
            ? $ctx->db->one('SELECT s.id AS sku_id, s.code, s.name, s.brand, si.id AS si_id, si.units_per_pack, si.supplier_code, si.purchase_unit FROM supplier_item si '
                . 'JOIN sku s ON s.id = si.sku_id WHERE si.id = ? AND si.supplier_id = ? AND si.is_active = 1 AND s.merged_into_sku_id IS NULL', [$si, (int) $supplierId])
            : $ctx->db->one('SELECT id AS sku_id, code, name, brand, NULL AS si_id, NULL AS units_per_pack, NULL AS supplier_code, NULL AS purchase_unit FROM sku '
                . 'WHERE id = ? AND merged_into_sku_id IS NULL', [$sku]);
        if ($row === null) {
            return $none;
        }
        $choice = self::productChoice(['sku_id' => (int) $row['sku_id'], 'sku_code' => (string) $row['code'], 'name' => (string) $row['name'], 'brand' => $row['brand'],
            'supplier_item_id' => $row['si_id'] === null ? null : (int) $row['si_id'], 'units_per_pack' => (int) ($row['units_per_pack'] ?? 1),
            'supplier_code' => $row['supplier_code'], 'purchase_unit' => (string) ($row['purchase_unit'] ?? 'each')]);
        return $choice['label'] === $q ? ['si' => $choice['si'], 'sku' => $choice['sku'], 'choice' => $choice] : $none;
    }

    /**
     * The suppliers an order may be for (not stopped), as the supplier's box offers them: the name, and for one not usable yet its state
     * ("Elux (not approved yet)" in the plain select; the script's list shows the state on the choice's second line).
     *
     * @return list<array{id: int, name: string, label: string, meta: string}>
     */
    public static function supplierChoices(Db $db): array
    {
        return array_map(static function (array $s): array {
            $state = match ((string) $s['status']) {
                'draft' => Words::ORDERS['not_approved'],
                'pending_approval' => Words::ORDERS['waiting_ok'],
                default => '',
            };
            return ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'label' => $state === '' ? (string) $s['name'] : Words::say('PO_ONE', 'supplier_option', (string) $s['name'], $state),
                'meta' => $state === '' ? '' : mb_strtoupper(mb_substr($state, 0, 1)) . mb_substr($state, 1)];
        }, $db->all("SELECT id, name, status FROM supplier WHERE status <> 'inactive' ORDER BY name, id"));
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
        // What "+ Add new supplier" carried back on a draft (the scan box and a new item not made yet: the Codex review of a90d22d, finding 1).
        $typed = [];
        foreach ([...self::DRAFT_CARRIED, 'pick_sku'] as $k) {
            $v = $ctx->req->param($k);
            if ($v !== null) {
                $typed[$k] = mb_substr($v, 0, 255);
            }
        }
        return $this->page($ctx, $doc->id, 200, null, $this->notice($ctx, $doc), $typed === [] ? [] : ['typed' => $typed, 'q' => $typed['q'] ?? '',
            'packs' => $typed['packs'] ?? '1']);
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
            in_array($code, ['scan_pending', 'not_found', 'import_refused', 'no_file', 'upload_failed', 'bad_number'], true) => $e->getMessage(),
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

    /**
     * The draft's one form (spec §8.3; PO on one page, PX2): saves every edit, then does what was asked (apply()): Add (what was scanned,
     * searched or chosen), Save, Confirm, "+ Add new supplier", "+ Add new item". The two "+ Add new" actions create something, so they run
     * through FormOnce as well as the draft's version (sent twice: done once); the others carry the version only, as before.
     */
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
        $ask = self::asked($req);
        $denied = self::denied($ctx, $ask['action']);
        if ($denied !== null) {
            return $denied;
        }
        $svc = $this->service($ctx);
        $typed = $req->post;
        $q = $ask['q'];
        $kept = ['typed' => $typed, 'q' => $q, 'packs' => $req->field('packs') ?? '1'];
        $remove = UiRequest::id($req->field('remove_line'));
        // The draft's lines as the form edited them, read and checked when the work is done: for a "+ Add new" inside FormOnce, so the same
        // form sent again is answered by its first answer, never refused by the line it added (the Codex review of bd26fc3, finding 3).
        $edited = function () use ($svc, $id, $req, $editable, $count, $remove): array {
            $current = $svc->lines($id);
            return self::withCharge($req, $editable ? self::editedLines($req, $current, $count, $remove) : $current);
        };
        try {
            $header = self::header($req, true);
            if ($ask['action'] === 'approve' && $q !== '') {
                return $this->page($ctx, $id, 422, new CwException('scan_pending', Words::say('BUY_ERROR', 'scan_pending', $q), 422), null, $kept);
            }
            if ($ask['action'] === 'new_supplier' || $ask['action'] === 'new_item') {
                $r = FormOnce::run($ctx, 'ui.po.' . $ask['action'], ['id' => $id] + self::request($req), function (Db $db) use ($ctx, $req, $svc, $id, $version, $header, $edited, $ask): OpResult {
                    $res = $this->apply($ctx, $svc, $id, $version, $header, $edited(), $ask);
                    return OpResult::of(303, ['result' => $res['status'], 'document_id' => $id, 'redirect' => $res['status'] === 'supplier_added'
                        ? Html::url('/ui/purchasing/orders/' . $id, ['notice' => 'supplier_added'] + array_merge(self::carried($req, self::DRAFT_CARRIED), $this->carriedPick($ctx, $req)))
                        : self::after($id, $res['status'])]);
                });
                return FormOnce::redirect($r);
            }
            $lines = $edited();
            $result = $ctx->db->transaction(fn (): array => $this->apply($ctx, $svc, $id, $version, $header, $lines, $ask));
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->page($ctx, $id, 409, new CwException('version_conflict', Words::BUY_ERROR['version_conflict'], 409));
            }
            // Review finding 5 (pack PO, PO7): nothing was saved, so what was still to add comes back too: the scan text, its packs, the
            // charge and a "+ Add new" form (said in that form), as well as the table's edits.
            $panel = self::panelError($ask['action'], $e);
            return $this->page($ctx, $id, $e->httpStatus, $panel === [] ? $e : null, null, $kept + $panel + ['formKey' => PoAddNew::keyAgain($ctx, $e)]);
        }
        // The table and the charge were saved with an Add that found several products or none; the "+ Add new" forms were not: they come back
        // as typed, and nothing that was saved is typed again (the charge would be added twice: the Codex review of 0917562, finding 2).
        $unsaved = ['typed' => array_intersect_key($typed, array_flip(self::UNSAVED))];
        if ($result['status'] === 'choices') {
            return $this->page($ctx, $id, 200, null, null, ['choices' => $result['choices'], 'q' => $q, 'packs' => max(1, $ask['packs'])] + $unsaved);
        }
        if ($result['status'] === 'not_found') {
            return $this->page($ctx, $id, 422, new CwException('not_found', Words::say('BUY_ERROR', 'not_found', $q), 422), null, ['q' => $q,
                'packs' => $req->field('packs') ?? '1'] + $unsaved);
        }
        return HtmlResponse::redirect(self::after($id, $result['status'] === 'saved' && $remove !== null ? 'removed' : $result['status']));
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

    /** POST reopen (pack PO, PO8): a closed order waits for the rest again (a reason, audited). */
    public function reopen(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, function (PurchaseOrders $svc, int $id, int $version) use ($ctx): string {
            $svc->reopen($ctx->caller(), $id, $version, $ctx->req->field('reason') ?? '');
            return 'reopened';
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
     * The order's page: the one page (the editor) for its creator while it is a draft, else the read-only view with the actions the
     * person may use; also after a refused form (the error shown, answered under its status). $extra: typed (the posted
     * values to show again), choices + q + packs (an ambiguous search), importErrors, and oneData()'s (a "+ Add new" form's refusal,
     * the form key to draw again).
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
            'warnings' => in_array($doc->status, ['draft', 'awaiting_approval'], true)
                ? self::withoutSupplierWarning(PoWarnings::plain($svc->warnings($doc->id), $supplierName, $products), $supplier, $supplierName) : [],
            'supplierNeeds' => $doc->status === 'draft' ? self::supplierNeeds($supplier, $supplierName) : null, // pack PO (PO3); one line since PX9
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
            // PO on one page (PX1-PX9): the draft is the same page as a new order, now with its products.
            $data += [
                'editable' => self::editorFits($lines),
                'maxLines' => self::editorMaxLines(),
                'vatCodes' => $db->all('SELECT code, label FROM vat_code WHERE is_active = 1 ORDER BY sort_order, code'),
                'info' => ['doc_date' => (string) ($extra['typed']['doc_date'] ?? $doc->docDate), 'expected_date' => (string) ($extra['typed']['expected_date'] ?? ($po['expected_date'] ?? '')),
                    'external_ref' => (string) ($extra['typed']['external_ref'] ?? $doc->externalRef), 'note' => (string) ($extra['typed']['note'] ?? $doc->note)],
                'fileColumns' => array_values(array_diff(PoLinesFile::COLUMNS, ['line', 'item_name'])),
                'maxMb' => intdiv(PoLinesFile::MAX_BYTES, 1_048_576),
                'maxRows' => PoLinesFile::MAX_ROWS,
                'cancelReasons' => self::reasonChoices($db, 'draft_cancel'),
            ] + $this->oneData($ctx, $doc, UiRequest::id(is_string($extra['typed']['supplier_id'] ?? null) ? $extra['typed']['supplier_id'] : null) ?? (int) ($po['supplier_id'] ?? 0),
                count($lines), $extra);
            return $ctx->page('purchase_order_edit', $data, $status, $layout);
        }
        $data += $this->viewData($ctx, $doc, $po, $canPost, $supplierName);
        // The saved order read like a document (docs/decisions.md PV): the shared head and totals, and the next papers' ways in.
        $receivable = $doc->status === 'posted' && in_array($state, ['approved', 'sent', 'part_received'], true);
        $canReceive = $receivable && Documents::mayPost($me->roles, 'GRN');
        $canInvoice = $receivable && Documents::mayPost($me->roles, 'SINV');
        [$main, $more] = self::actions($data['can'], $state, $canReceive, $canInvoice);
        return $ctx->page('purchase_order', $data + self::docView($data, $doc, $po, $supplier, $supplierName) + [
            'canReceive' => $canReceive,
            'canInvoice' => $canInvoice,
            'mainAction' => $main,
            'moreActions' => $more,
            'progress' => self::progress($ctx, $doc, $po, $state, (int) $data['units'], (int) $data['received']),
        ], $status, $layout);
    }

    /**
     * The saved order's action bar (PV19, the approved design): the next step in the accent (confirm, send while not sent, receive the goods,
     * record its invoice, take it back, copy), the others under More in a fixed order. The keys are po_action's.
     *
     * @param array<string, bool> $can viewData()'s
     * @return array{0: ?string, 1: list<string>}
     */
    public static function actions(array $can, string $state, bool $canReceive, bool $canInvoice): array
    {
        $has = ['approve' => $can['approve'], 'withdraw' => $can['withdraw'], 'send' => $can['send'], 'receive' => $canReceive, 'invoice' => $canInvoice,
            'pdf' => true, 'copy' => $can['copy'], 'amend' => $can['amend'], 'close' => $can['close'], 'reopen' => $can['reopen'], 'cancel' => $can['cancel']];
        $main = match (true) {
            $has['approve'] => 'approve',
            $has['send'] && $state === 'approved' => 'send',
            $has['receive'] => 'receive',
            $has['invoice'] => 'invoice',
            $has['withdraw'] => 'withdraw',
            $has['send'] => 'send',
            $has['copy'] => 'copy',
            default => null,
        };
        $more = array_values(array_filter(array_keys($has), static fn (string $a): bool => $has[$a] && $a !== $main));
        return [$main, $more];
    }

    /**
     * The saved order's progress strip (PV19): units ordered, received, invoiced (the final invoices' goods lines on this order, net of their
     * credit notes: one indexed read on ix_sinv_line_po), the expected date and what is still to come; none for a draft or a cancelled order.
     *
     * @param array<string, mixed> $po
     * @return array{ordered: int, received: int, invoiced: int, expected: ?string, to_come: int}|null
     */
    private static function progress(Context $ctx, Document $doc, array $po, string $state, int $units, int $received): ?array
    {
        if ($doc->status !== 'posted' || $state === 'cancelled') {
            return null;
        }
        $invoiced = (int) $ctx->db->value("SELECT COALESCE(SUM(IF(si.kind = 'invoice', dl.qty, -dl.qty)), 0) FROM sinv_line sl JOIN supplier_invoice si ON si.document_id = sl.document_id "
            . "JOIN document d ON d.id = sl.document_id AND d.status = 'posted' JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no "
            . "WHERE sl.po_document_id = ? AND sl.kind = 'goods'", [$doc->id]);
        return ['ordered' => $units, 'received' => $received, 'invoiced' => max(0, $invoiced), 'expected' => ($po['expected_date'] ?? null) === null ? null : (string) $po['expected_date'],
            'to_come' => in_array($state, ['approved', 'sent', 'part_received'], true) ? max(0, $units - $received) : 0];
    }

    /**
     * A saved order's head and totals as a document (PV; the shared doc_party and doc_totals): the supplier's block, the order's facts (its
     * state, check, dates, reference, note, source, the orders it corrects or that correct it, who did what and when), the totals.
     *
     * @param array<string, mixed> $data page()'s and viewData()'s
     * @param array<string, mixed> $po
     * @param array<string, mixed> $supplier
     * @return array{party: array<string, mixed>, docFacts: list<array<string, mixed>>, totalRows: list<array<string, mixed>>}
     */
    private static function docView(array $data, Document $doc, array $po, array $supplier, string $supplierName): array
    {
        $people = $data['people'];
        $by = static fn (?string $at, ?string $who): ?string => $at === null ? null : Words::say('ORDER', 'by', Html::when($at), (string) $who);
        $place = trim(implode(' ', array_filter([(string) ($supplier['city'] ?? ''), (string) ($supplier['postcode'] ?? '')], static fn (string $v): bool => $v !== '')));
        $facts = [
            ['label' => Words::ORDER['status'], 'value' => Words::of('PO_STATE', (string) $data['state'])],
            ['label' => Words::ORDER['check'], 'value' => $doc->reviewState === null ? Words::ORDER['not_confirmed'] : Words::of('CHECK_STATE', $doc->reviewState)],
            ['label' => Words::ORDER['order_date'], 'value' => $doc->docDate === null ? null : Html::day($doc->docDate)],
            ['label' => Words::ORDER['expected'], 'value' => ($po['expected_date'] ?? null) === null ? null : Html::day((string) $po['expected_date'])],
            ['label' => Words::ORDER['ref'], 'value' => $doc->externalRef],
            ['label' => Words::ORDER['note'], 'value' => $doc->note],
            ['label' => Words::ORDER['source'], 'value' => Words::of('PO_SOURCE', (string) ($po['source'] ?? 'manual'))],
            ['label' => Words::ORDER['corrects'], 'value' => $data['amends'] === null ? null : (string) $data['amends']['number'],
                'href' => $data['amends'] === null ? null : '/ui/purchasing/orders/' . (int) $data['amends']['id']],
        ];
        foreach ($data['amendedBy'] as $a) {
            $facts[] = ['label' => Words::ORDER['corrected_by'], 'value' => (string) $a['label'], 'href' => '/ui/purchasing/orders/' . (int) $a['id']];
        }
        $sent = ($po['sent_at'] ?? null) === null ? null : Words::say('ORDER', 'sent_line', Html::when((string) $po['sent_at']), (string) $people['sent'],
            lcfirst(Words::of('SEND_VIA', (string) $po['sent_via']))) . (($po['sent_to'] ?? null) !== null ? ' ' . Words::say('ORDER', 'sent_to', (string) $po['sent_to']) : '');
        $cancelled = null;
        if ($data['reversal'] !== null) {
            $r = $data['reversal'];
            $cancelled = $r->number === null ? Words::ORDER['cancel_waiting'] : Html::day($r->docDate) . ' · ' . (string) ($data['reversalReason'] ?? '')
                . ($r->note !== null && $r->note !== '' ? ' – ' . $r->note : '') . ' (' . Words::say('ORDER', 'cancel_record', (string) $r->number) . ')';
        } elseif ($doc->cancelledAt !== null) {
            $cancelled = Words::say('ORDER', 'with_reason', Words::say('ORDER', 'by', Html::when($doc->cancelledAt), (string) $people['cancelled']), (string) $data['cancelReason']);
        }
        array_push($facts,
            ['label' => Words::ORDER['started'], 'value' => $by($doc->createdAt, (string) $people['created'])],
            ['label' => Words::ORDER['asked'], 'value' => $by($doc->submittedAt, (string) $people['submitted'])],
            ['label' => Words::ORDER['confirmed'], 'value' => $by($doc->postedAt, (string) $people['posted'])],
            ['label' => Words::ORDER['sent'], 'value' => $sent],
            ['label' => Words::ORDER['closed'], 'value' => ($po['closed_at'] ?? null) === null ? null : Words::say('ORDER', 'with_reason',
                Words::say('ORDER', 'by', Html::when((string) $po['closed_at']), (string) $people['closed']), (string) $po['close_reason'])],
            ['label' => Words::ORDER['cancelled'], 'value' => $cancelled],
            ['label' => Words::ORDER['cancel_pdf'], 'value' => $data['reversal'] === null ? null : Words::ORDER['cancel_pdf'],
                'href' => $data['reversal'] === null ? null : '/ui/purchasing/orders/' . (int) $data['reversal']->id . '/pdf'],
        );
        $count = count($data['lines']);
        $rows = [['label' => Words::ORDER['products'], 'value' => Words::say('ORDER', $count === 1 ? 'products_line_one' : 'products_line', $count, (int) $data['units'])
            . ((int) $data['received'] > 0 ? ', ' . Words::say('ORDER', 'delivered_line', (int) $data['received']) : '')],
            ['label' => Words::ORDER['net'], 'value' => (string) $data['totals']['net']]];
        foreach ($data['totals']['by_code'] as $code => $v) {
            $rows[] = ['label' => Words::say('ORDER', 'vat_line', (string) $code, $data['vatLabels'][$code] ?? $v['rate'] . '%'), 'value' => (string) $v['vat']];
        }
        $rows[] = ['label' => Words::ORDER['gross'], 'value' => (string) $data['totals']['gross'], 'grand' => true];
        return [
            'party' => ['label' => Words::DOC['party'], 'name' => $supplierName, 'href' => '/ui/purchasing/suppliers/' . (int) ($supplier['id'] ?? 0),
                'lines' => array_values(array_filter([(string) ($supplier['address_line1'] ?? ''), (string) ($supplier['address_line2'] ?? ''), $place,
                    (string) ($supplier['email'] ?? ''), (string) ($supplier['phone'] ?? '')], static fn (string $v): bool => trim($v) !== ''))],
            'docFacts' => array_values(array_map(static fn (array $f): array => array_filter($f, static fn (mixed $v): bool => $v !== null),
                array_filter($facts, static fn (array $f): bool => $f['value'] !== null && $f['value'] !== ''))),
            'totalRows' => $rows,
        ];
    }

    /**
     * Pack PO (PO3), one line since PO on one page (PX9): a supplier not approved yet is said once, with the link to its page: the order is
     * saved, not confirmed (the rule itself is PurchaseOrderHandler::validate's, unchanged); null when it is active (or stopped: the warnings
     * say that).
     *
     * @param array<string, mixed> $supplier the supplier row
     * @return array{text: string, link: string}|null
     */
    public static function supplierNeeds(array $supplier, string $name): ?array
    {
        $status = (string) ($supplier['status'] ?? '');
        if (!in_array($status, ['draft', 'pending_approval'], true)) {
            return null;
        }
        return ['text' => Words::say('PO_ONE', $status === 'draft' ? 'not_approved' : 'waiting', $name), 'link' => '/ui/purchasing/suppliers/' . (int) ($supplier['id'] ?? 0)];
    }

    /**
     * The draft's warnings without "you cannot confirm it until a reviewer approves the supplier" when supplierNeeds() says it in
     * steps (pack PO, PO3): one thing said once.
     *
     * @param list<string> $warnings
     * @param array<string, mixed> $supplier
     * @return list<string>
     */
    private static function withoutSupplierWarning(array $warnings, array $supplier, string $name): array
    {
        if (!in_array((string) ($supplier['status'] ?? ''), ['draft', 'pending_approval'], true)) {
            return $warnings;
        }
        $said = Words::say('PO_WARN', 'supplier_not_approved', $name);
        return array_values(array_filter($warnings, static fn (string $w): bool => $w !== $said));
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
                'reopen' => $canPost && $posted && $state === 'closed', // pack PO (PO8)
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
     * note; for a line without a supplier item also units per pack and "save as supplier item"; a charge's "remove" box; $remove: the row
     * whose remove was pressed (PO on one page, PX4). A field the page does not draw keeps the line's value.
     *
     * @param list<array<string, mixed>> $current
     * @return list<array<string, mixed>>
     */
    private static function editedLines(UiRequest $req, array $current, int $count, ?int $remove = null): array
    {
        if ($count !== count($current)) {
            throw new CwException('version_conflict', 'the lines changed since this page was drawn', 409);
        }
        $out = [];
        foreach ($current as $i => $l) {
            $n = $i + 1;
            if ($n === $remove) {
                continue; // the row's remove (PO on one page, PX4): not read (the Codex review of a90d22d, finding 3)
            }
            $packs = trim($req->field("line_{$n}_packs") ?? '');
            $p = PoLinesFile::wholeNumber($packs);
            if ($p === null) {
                throw new CwException('bad_line', "line {$n}, packs: a whole number (0 removes the line)", 422, ['line' => $n]);
            }
            if ($p === 0) {
                continue;
            }
            if ($l['kind'] === 'charge' && $req->field("line_{$n}_remove") === '1') {
                continue; // review finding 8 (pack PO, PO7): a charge's own "remove" box
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
     * `view` (the board's one Show menu, PV18; `show`): open (the default), all (every order, cancellation records too: the old `show=all`),
     * closed (delivered, closed and cancelled). The CSV keeps its own default (every order but the cancellation records, as before).
     *
     * @return array{state: ?string, supplier: ?int, q: string, rejected: bool, all: bool, drafts: bool, view: string}
     */
    private static function filters(UiRequest $req, bool $draftsOff = false): array
    {
        $drafts = $req->param('drafts');
        $show = $req->param('show');
        return [
            'view' => $show === 'all' || $show === 'closed' ? $show : 'open',
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
     * @param array{state: ?string, supplier: ?int, q: string, rejected: bool, all: bool, drafts: bool, view: string} $f
     * @param int|null $recent the board's days for "Received or closed" (PV18); null: the CSV, which keeps every order (no board view)
     * @return list<array<string, mixed>>
     */
    private function rows(Context $ctx, array $f, ?int $limit, ?int $recent = null): array
    {
        $where = ["d.doc_type = 'PO'"];
        $params = [];
        if ($recent !== null && $f['state'] === null && $f['view'] === 'open') {
            // The open orders board: not finished, and delivered or closed within the setting's days; no cancelled order, no record of one.
            $where[] = "(d.status IN ('draft', 'awaiting_approval') OR (d.status = 'posted' AND (po.state IN ('approved', 'sent', 'part_received') "
                . "OR (po.state IN ('received', 'closed') AND po.updated_at >= UTC_TIMESTAMP(6) - INTERVAL ? DAY))))";
            $params[] = max(1, $recent);
        } elseif ($recent !== null && $f['state'] === null && $f['view'] === 'closed') {
            $where[] = "(d.status IN ('cancelled', 'reversed') OR (d.status = 'posted' AND po.state IN ('received', 'closed', 'cancelled')))";
        }
        if (!$f['all'] && !($recent !== null && $f['view'] === 'all')) {
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
            $where[] = '(d.number LIKE ? OR d.external_ref LIKE ? OR s.name LIKE ?)'; // the supplier too: the list's one search (PV)
            array_push($params, $like, $like, $like);
        }
        if ($f['rejected']) {
            $where[] = "d.review_state = 'rejected'";
        }
        $rows = $ctx->db->all(
            'SELECT d.id, d.number, d.status, d.review_state, d.doc_date, d.external_ref, d.reverses_id, po.state, po.expected_date, po.net_total, po.vat_total, '
            . 'po.gross_total, po.sent_at, po.sent_via, po.source, s.id AS supplier_id, s.code AS supplier_code, s.name AS supplier_name, u.display_name AS created_by_name, '
            . 'o.number AS reverses_number, (SELECT COUNT(*) FROM document_line l WHERE l.document_id = d.id) AS `lines`, '
            . '(SELECT COALESCE(SUM(l.qty), 0) FROM document_line l WHERE l.document_id = d.id) AS units, '
            . '(SELECT COALESCE(SUM(pl.received_units), 0) FROM po_line pl WHERE pl.document_id = d.id) AS received, '
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
