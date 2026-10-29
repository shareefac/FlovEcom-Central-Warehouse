<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Invoices\InvoiceMath;
use CW\Invoices\SupplierInvoices;
use CW\Items\HandMade;
use CW\OpResult;
use CW\PurchaseOrders\PoLinesFile;
use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Kernel;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * The supplier invoice on ONE page (the owner, 29 Oct 2026: "purchase order / purchase invoice / sales invoice — keep always user
 * experience much good" and "professional: refer Zoho Inventory"; docs/decisions.md PV1-PV12), the purchase order's one page (PX1-PX11)
 * for an invoice: "New supplier invoice" opens it at once and nothing is written by opening it (newPage); its one form (create) makes the
 * draft and applies everything typed with it in ONE transaction under FormOnce, so the same form sent twice makes one draft and a refusal
 * leaves nothing; the draft is the same page after, its creator's, and its one form posts to the draft's /lines (lines), applied in one
 * transaction too. One form: the supplier (the order page's dropdown with "+ Add new supplier", before the draft exists only: an invoice's
 * supplier is fixed once it is saved), the supplier's number, the dates, "From" (an open delivery or order of that supplier fills the
 * lines), the lines (each edited in place with its remove; "+ Add line" is the order page's item box with "+ Add new item"; a charge), the
 * notes and the file, the totals as the paper says them, and the bar: Save as draft, Save and make final. Every rule of pack C and pack J
 * is the service's and unchanged (doc.SINV.post, the creator's drafts, FormOnce, versions, the duplicate number, VAT as stated, the 3-way
 * match and invoices.accept, the timed posting); this class reads the form and says the refusals in words.
 */
final class InvoiceOnePage
{
    public const NEW = '/ui/purchasing/invoices/new';
    public const PRODUCTS = '/ui/purchasing/invoices/products';
    public const BASE = '/ui/purchasing/invoices';
    /** The header fields of the one form (only those the form sent are saved on a draft). */
    public const HEADER = ['invoice_number', 'invoice_date', 'due_date', 'note', 'net_total', 'vat_total'];
    /** What "+ Add new supplier" carries back to the new page (what was typed before it), besides the supplier itself. */
    public const CARRIED = ['invoice_number', 'invoice_date', 'due_date', 'note', 'net_total', 'vat_total', 'from', 'q', 'packs', 'price', 'item_name', 'item_brand',
        'item_barcode', 'item_code', 'item_upp', 'item_price', 'charge_amount', 'charge_description', 'charge_kind']; // the charge: the Codex review of e1db3a4, M3 (PV23)
    /** The fields an Add that found several items or none did not save: they come back as typed on the draft's page. */
    public const UNSAVED = ['item_name', 'item_brand', 'item_barcode', 'item_code', 'item_upp', 'item_price', 'item_anyway'];
    /** The one form's actions (the default, Enter, is Add: the form's first button). */
    public const ACTIONS = ['add', 'add_charge', 'from', 'save', 'post', 'new_supplier', 'new_item'];
    /**
     * The one form's fields outside its line rows, with room to spare (csrf, version, line_count, form_key, the button pressed, the supplier and
     * the three of "+ Add new supplier", 4 header fields, the due date drawn, "From", the item box's 5, the 7 of "+ Add new item", the charge's 3,
     * the note, the file's kind, the two totals, a row's remove or a choice: 38). A line row sends 2 (the Codex review of 6418b15, finding 3).
     */
    public const FIXED_FIELDS = 48;
    public const LINE_FIELDS = 2;

    /** Whether the one form can carry these many lines under PHP's max_input_vars (read on the server); else they are shown read-only. */
    public static function linesFit(int $lines): bool
    {
        return self::FIXED_FIELDS + self::LINE_FIELDS * $lines < UiRequest::maxInputVars();
    }

    // ------------------------------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------------------------------

    /**
     * GET /ui/purchasing/invoices/new: the one page at once, with no draft (PV1): the address may name the supplier, "From" (the order's
     * page: "Record its invoice") and carry what was typed before a new supplier was added.
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
        $typed['pick_sku'] = (string) (UiRequest::id($ctx->req->param('pick_sku')) ?? ''); // the item chosen before a new supplier (checked by picked())
        $n = $ctx->req->param('notice');
        return $this->editPage($ctx, null, 200, null, $n === 'supplier_added' ? Words::INVOICE_NOTICE['supplier_added'] : null, ['typed' => $typed]);
    }

    /**
     * GET /ui/purchasing/invoices/products?supplier=&q=: the item box's choices, the order page's fragment (po_products; PX8) from the
     * invoice desk's own route: what the scan box finds (PurchaseOrders::resolve, read only: a barcode, their code, a CW number, else words
     * of the name, this supplier's packs first), each of this supplier's packs its own choice. Fewer than 2 characters: nothing yet.
     */
    public function products(Context $ctx): HtmlResponse
    {
        $q = mb_substr(trim($ctx->req->param('q') ?? ''), 0, 100);
        $sid = UiRequest::id($ctx->req->param('supplier')) ?? 0;
        $choices = [];
        if (mb_strlen($q) >= 2) {
            $r = (new PurchaseOrders($ctx->db, $ctx->documents(), $ctx->settings()))->resolve($sid, $q);
            $rows = $r['status'] === 'choices' ? $r['choices'] : ($r['status'] === 'found' ? [PurchaseOrdersController::foundRow($ctx->db, $r)] : []);
            $choices = array_map(PurchaseOrdersController::productChoice(...), $rows);
        }
        return $ctx->fragment('po_products', ['choices' => $choices, 'says' => $choices === [] && mb_strlen($q) >= 2 ? Words::say('PO_ONE', 'none', $q) : '']);
    }

    /**
     * POST /ui/purchasing/invoices: the one page's form before the invoice exists (PV2), everything through FormOnce:
     *  - "+ Add new supplier": the supplier alone (Suppliers::create, a draft supplier, suppliers.manage), then the page again with it chosen;
     *  - else, with a supplier and something to keep (a line, "From", a charge, "+ Add new item", Save as draft, Save and make final): the
     *    draft is made and everything applied to it in ONE transaction; a refusal (a duplicate number, another supplier's delivery, a refused
     *    "make final"), a search with several items or none, rolls it all back: nothing is left.
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
                $r = FormOnce::run($ctx, 'ui.invoice.new_supplier', self::request($req), function (Db $db) use ($ctx, $req, $fields): OpResult {
                    $s = (new Suppliers($db, $ctx->settings()))->create($ctx->caller(), $fields);
                    return OpResult::of(303, ['result' => 'supplier_added', 'supplier_id' => (int) $s['id'],
                        'redirect' => Html::url(self::NEW, ['supplier' => (int) $s['id']] + array_merge(self::carried($req), self::carriedPick($ctx, $req))
                            + ['notice' => 'supplier_added'])]);
                });
                return FormOnce::redirect($r);
            }
            if ($sid === null) {
                throw new CwException('need_supplier', Words::INVOICE_ERROR['need_supplier'], 422, ['field' => 'supplier_id']);
            }
            self::check($ask, true);
            $header = self::header($req);
            $cleared = ($header['due_date'] ?? '') === '' && trim((string) ($req->field('due_shown') ?? '')) !== '';
            if (($header['due_date'] ?? '') === '' && !$cleared) {
                unset($header['due_date']); // none was shown: the supplier's terms fill it (PV8; the Codex review of 6418b15, finding 4)
            }
            $file = self::file($ctx);
            $save = self::saveOnly($ask);
            $r = FormOnce::run($ctx, 'ui.invoice.create', self::request($req), function (Db $db) use ($ctx, $sid, $header, $save, $file, $cleared): OpResult {
                $ask = $save;
                $si = $ctx->supplierInvoices();
                $caller = $ctx->caller();
                $source = $ask['source'];
                $taken = null;
                if ($source !== null && $source[0] === 'grn') {
                    // "From" a delivery: the existing quick way (its number, date, copy and lines), then what was typed over it.
                    $owner = $db->value('SELECT supplier_id FROM goods_receipt WHERE document_id = ?', [$source[1]]);
                    if ($owner !== null && (int) $owner !== $sid) {
                        throw new CwException('delivery_other_supplier', 'that delivery is another supplier\'s', 422, ['field' => 'from']);
                    }
                    $x = $si->createFromReceipt($caller, $source[1]);
                    $doc = $x['document'];
                    $taken = $x['number_taken'];
                    $typed = array_filter($header, static fn (string $v): bool => trim($v) !== '') + ($cleared ? ['due_date' => ''] : []);
                    if (!array_key_exists('due_date', $header) && isset($typed['invoice_date']) && self::realDate($typed['invoice_date'])) {
                        // The date typed over the delivery's: the terms' due date follows it (none shown, none typed).
                        $days = $db->value('SELECT payment_terms_days FROM supplier WHERE id = ?', [$sid]);
                        if ($days !== null) {
                            $typed['due_date'] = (new \DateTimeImmutable($typed['invoice_date']))->modify('+' . (int) $days . ' days')->format('Y-m-d');
                        }
                    }
                    $res = $this->apply($ctx, $doc->id, $doc->version, $typed === [] ? null : $typed, null, ['source' => null] + $ask, $file);
                    $res['status'] = $res['status'] === 'saved' ? ($taken === null ? 'from_receipt' : 'number_taken') : $res['status'];
                } else {
                    $doc = $si->createDraft($caller, 'invoice', $sid, $header);
                    $res = $this->apply($ctx, $doc->id, $doc->version, null, null, $ask, $file);
                    $res['status'] = $res['status'] === 'saved' ? 'created' : $res['status'];
                }
                if ($res['status'] === 'choices' || $res['status'] === 'not_found') {
                    // Nothing to keep yet: the draft goes with the rest of the transaction; the page shows the choices or says so.
                    throw new CwException('one_page_' . $res['status'], '', $res['status'] === 'choices' ? 200 : 422, ['choices' => $res['choices'] ?? []]);
                }
                // The version this form saved (PV21): "make final" posts that version, so the same form sent again never makes later edits final.
                return OpResult::of(303, ['result' => $res['status'], 'document_id' => $doc->id, 'version' => $ctx->documents()->get($doc->id)->version,
                    'redirect' => self::after($doc->id, $res, $taken)]);
            });
        } catch (CwException $e) {
            $extra = ['typed' => $req->post, 'formKey' => PoAddNew::keyAgain($ctx, $e)];
            return $this->refused($ctx, null, $e, $ask, $extra);
        }
        return $ask['action'] === 'post' ? self::makeFinal($ctx, (int) $r->body['document_id'], isset($r->body['version']) ? (int) $r->body['version'] : null)
            : FormOnce::redirect($r);
    }

    /**
     * POST /ui/purchasing/invoices/{id}/lines (an invoice's): the draft's one form (PV3): the header fields it sent, the lines as the table
     * edited them (a row's remove leaves it out), then what was asked ("From", Add, a charge, "+ Add new item", Save as draft, Save and make
     * final), in ONE transaction: a refusal saves nothing and the page comes back with what was typed. "+ Add new item" makes an item, so it
     * runs through FormOnce as well as the version. A form of the old lines table (no action, only its rows) saves the rows, as before.
     */
    public function lines(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        if ($ctx->supplierInvoices()->kindOfDocument($id) !== 'invoice') {
            return $ctx->error(404, 'not_found', 'there is no such record', [self::BASE, Words::INVOICE_KIND['invoice']['tab']]);
        }
        $version = UiRequest::id($req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        $ask = self::asked($req);
        $denied = self::denied($ctx, $ask['action'] === 'new_supplier' ? 'save' : $ask['action']);
        if ($denied !== null) {
            return $denied;
        }
        $typed = $req->post;
        try {
            self::check($ask, false);
            $header = self::header($req, true);
            $file = self::file($ctx);
            $save = self::saveOnly($ask);
            $work = function () use ($ctx, $id, $version, $header, $save, $file): array {
                $lines = self::editedLines($ctx, $id, $save['remove']);
                return $this->apply($ctx, $id, $version, $header === [] ? null : $header, $lines, $save, $file) + ['saved' => $ctx->documents()->get($id)->version];
            };
            if ($ask['action'] === 'new_item') {
                $r = FormOnce::run($ctx, 'ui.invoice.new_item', ['id' => $id] + self::request($req), function (Db $db) use ($id, $work): OpResult {
                    $res = $work();
                    return OpResult::of(303, ['result' => $res['status'], 'document_id' => $id, 'redirect' => self::after($id, $res, null)]);
                });
                return FormOnce::redirect($r);
            }
            $res = $ctx->db->transaction(static fn (): array => $work());
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return self::draftPage($ctx, $id, 409, new CwException('version_conflict', Words::BUY_ERROR['version_conflict'], 409), []);
            }
            return $this->refused($ctx, $id, $e, $ask, ['typed' => $typed, 'formKey' => PoAddNew::keyAgain($ctx, $e)]);
        }
        // An Add that found several items or none saved the rest of the form; the "+ Add new" form was not saved: it comes back as typed.
        $unsaved = ['typed' => array_intersect_key($typed, array_flip(self::UNSAVED))];
        if ($res['status'] === 'choices') {
            return self::draftPage($ctx, $id, 200, null, ['choices' => $res['choices'], 'q' => $ask['q'], 'packs' => (string) max(1, $ask['packs']),
                'price' => (string) ($req->field('price') ?? '')] + $unsaved);
        }
        if ($res['status'] === 'not_found') {
            return self::draftPage($ctx, $id, 422, new CwException('not_found', Words::say('STOCK_ERROR', 'not_found', $ask['q']), 422),
                ['q' => $ask['q'], 'packs' => (string) ($req->field('packs') ?? '1'), 'price' => (string) ($req->field('price') ?? '')] + $unsaved);
        }
        return $ask['action'] === 'post' ? self::makeFinal($ctx, $id, (int) $res['saved']) : HtmlResponse::redirect(self::after($id, $res, null));
    }

    /**
     * "Save and make final" is two steps (the Codex review of 6418b15, finding 1): the save, committed (with a new draft and its file), then
     * the posting exactly as pack C and pack J make it final (SupplierInvoices::submit in its own transactions: "book as received" books its
     * receipt first, PJ9), never inside the save, so no lock is taken out of the order those postings keep. A refusal leaves the draft saved
     * and says so ("Saved as a draft, not made final: …" with the problems), never a half-final invoice. A form sent again finds the invoice
     * final already and lands on it. It posts the version the save left ($saved; the Codex review of e1db3a4, M1, PV21): a draft changed
     * since (the same form sent again after other edits) is refused as changed meanwhile, never made final unseen. $saved null: a form
     * remembered by FormOnce before PV21, which posts the draft as it is (as before).
     */
    private static function makeFinal(Context $ctx, int $id, ?int $saved): HtmlResponse
    {
        $doc = $ctx->documents()->get($id);
        if ($doc->status !== 'draft') {
            return self::finalRedirect($id, $doc);
        }
        try {
            $doc = $ctx->supplierInvoices()->submit($ctx->caller(), $id, $saved ?? $doc->version);
        } catch (CwException $e) {
            $now = $ctx->documents()->get($id);
            if ($now->status !== 'draft') {
                return self::finalRedirect($id, $now); // the same form sent twice: the other one made it final
            }
            return self::draftPage($ctx, $id, $e->httpStatus, new CwException('not_final', Words::say('PI_ONE', 'not_final', InvoicesController::plain($e)),
                $e->httpStatus, $e->detail), []);
        }
        return self::finalRedirect($id, $doc);
    }

    /** Where a final (or waiting) invoice's form lands: its page with what happened. */
    private static function finalRedirect(int $id, Document $doc): HtmlResponse
    {
        return HtmlResponse::redirect(match ($doc->status) {
            'posted' => Html::url(self::BASE . '/' . $id, ['notice' => 'posted', 'number' => $doc->number]),
            'awaiting_approval' => Html::url(self::BASE . '/' . $id, ['notice' => 'submitted']),
            default => self::BASE . '/' . $id,
        });
    }

    /** The save part of what was asked: "Save and make final" saves first, as "Save as draft" does (makeFinal() posts after). @param array<string, mixed> $ask @return array<string, mixed> */
    private static function saveOnly(array $ask): array
    {
        return $ask['action'] === 'post' ? ['action' => 'save'] + $ask : $ask;
    }

    /**
     * The item chosen in the box, carried through "+ Add new supplier" (the order page's rule, carriedPick(); the Codex review of 6418b15,
     * finding 6): as its item (`pick_sku`) with the item's own words, since a pack of the supplier before is not the new one's. Nothing when
     * the box no longer names its choice.
     *
     * @return array{q?: string, pick_sku?: string}
     */
    private static function carriedPick(Context $ctx, UiRequest $req): array
    {
        $q = trim($req->field('q') ?? '');
        $si = UiRequest::id($req->field('pick_si'));
        $typed = $si !== null ? ['pick_si' => (string) $si] : ['pick_sku' => (string) ($req->field('pick_sku') ?? '')];
        $owner = $si === null ? null : (int) ($ctx->db->value('SELECT supplier_id FROM supplier_item WHERE id = ?', [$si]) ?? 0);
        $kept = self::picked($ctx, $owner, $typed, $q);
        if ($kept['choice'] === null) {
            return [];
        }
        $sku = $si === null ? (int) $kept['sku'] : (int) $ctx->db->value('SELECT sku_id FROM supplier_item WHERE id = ?', [$si]);
        $own = self::picked($ctx, null, ['pick_sku' => (string) $sku], PurchaseOrdersController::productChoice(PurchaseOrdersController::itemChoiceRow($ctx, $sku))['label']);
        return $own['choice'] === null ? [] : ['q' => $own['choice']['label'], 'pick_sku' => (string) $sku];
    }

    // ------------------------------------------------------------------------------------------
    // The work
    // ------------------------------------------------------------------------------------------

    /**
     * The one form applied to a draft, inside the caller's transaction: the header typed, the lines as edited, "From" (a delivery's or an
     * order's lines: SupplierInvoices::addReceipt / addOrder, which refuse another supplier's), then the action (Add: addItem, a pack of the
     * supplier or the item chosen or what was scanned; a charge; "+ Add new item": Pack B's item, the supplier's pack when a code, a pack or
     * a price is typed, its line), then the file. Never the posting: "make final" is makeFinal(), after this is committed.
     * The statuses are the notices' (Add's `choices` and `not_found` are not: the caller shows them).
     *
     * @param array<string, string>|null $header
     * @param list<array<string, mixed>>|null $lines
     * @param array<string, mixed> $ask asked()
     * @param array{name: string, path: string, role: string}|null $file
     * @return array{status: string, document: Document, choices?: list<array<string, mixed>>}
     */
    private function apply(Context $ctx, int $id, int $version, ?array $header, ?array $lines, array $ask, ?array $file): array
    {
        $si = $ctx->supplierInvoices();
        $caller = $ctx->caller();
        $doc = $ctx->documents()->get($id);
        $v = $version;
        $status = $ask['action'] === 'remove' ? 'removed' : 'saved';
        if ($header !== null) {
            $doc = $si->saveHeader($caller, $id, $v, $header);
            $v = $doc->version;
        }
        if ($lines !== null) {
            $doc = $si->saveLines($caller, $id, $v, $lines);
            $v = $doc->version;
        }
        $source = $ask['source'];
        if ($source !== null) {
            $doc = $source[0] === 'grn' ? $si->addReceipt($caller, $id, $v, $source[1]) : $si->addOrder($caller, $id, $v, $source[1]);
            $v = $doc->version;
            $status = $source[0] === 'grn' ? 'delivery_added' : 'order_added';
        }
        if ($ask['charge']['amount'] !== '' || $ask['action'] === 'add_charge') {
            // A charge typed is kept with whatever is pressed, as the order page's delivery charge is (PX10).
            $doc = $si->addCharge($caller, $id, $v, $ask['charge']['kind'], $ask['charge']['amount'], $ask['charge']['description']);
            $v = $doc->version;
            $status = $ask['action'] === 'add_charge' || $status === 'saved' ? 'charge_added' : $status;
        }
        switch ($ask['action']) {
            case 'new_item':
                [$item, $supply] = PoAddNew::itemInput(PoAddNew::itemTyped($ctx->req));
                $made = (new HandMade($ctx->db, $ctx->decisions()))->create($caller, $item);
                $sku = (int) $made['sku_id'];
                $code = trim((string) ($supply['supplier_code'] ?? ''));
                $price = trim((string) ($supply['pack_price'] ?? ''));
                $upp = (int) $supply['units_per_pack'];
                $pack = null;
                if ($code !== '' || $price !== '' || $upp > 1) {
                    // As the order's page does (PX4): this supplier's pack of the new item, when a code, a pack or a price is typed.
                    $pack = (new SupplierItems($ctx->db))->create($caller, (int) $si->record($id)['supplier_id'], $sku, ['supplier_code' => $code === '' ? null : $code,
                        'units_per_pack' => (string) $upp, 'purchase_unit' => $upp > 1 ? 'case' : 'each', 'is_preferred' => SupplierItems::PREFERRED_AUTO],
                        $price === '' ? null : ['pack_price' => $price]);
                }
                $r = $si->addItem($caller, $id, $v, '', $ask['packs'], null, $pack === null ? $sku : null, $pack === null ? null : (int) $pack['id'], $pack === null ? $upp : null);
                $doc = $r['document'];
                $v = $doc->version;
                $status = 'item_added';
                break;
            case 'add':
                if ($ask['si'] !== null || $ask['sku'] !== null || $ask['q'] !== '') {
                    $r = $si->addItem($caller, $id, $v, $ask['q'], $ask['packs'], $ask['price'] === '' ? null : $ask['price'], $ask['sku'], $ask['si']);
                    if ($r['status'] !== 'added') {
                        return ['status' => (string) $r['status'], 'document' => $r['document'], 'choices' => $r['choices'] ?? []];
                    }
                    $doc = $r['document'];
                    $v = $doc->version;
                    $status = 'item_added';
                }
                break;
        }
        if ($file !== null) {
            // Attached with the save, committed with it: a "make final" refused after it keeps the file with the draft (the Codex review of
            // 6418b15, finding 2). Only a commit that fails after the file is stored leaves bytes without a row: FileStore::verify counts
            // those orphans, as for every attachment.
            $si->attach($caller, $id, $file['path'], $file['name'], $file['role']);
        }
        return ['status' => $status, 'document' => $doc];
    }

    /**
     * What the one form asks: the action, what Add adds (a choice's button add_sku, else the item box's choice pick_si / pick_sku, else the
     * scan text), the packs and price typed with it, the charge, "From" (`grn:<id>` or `po:<id>`, applied with any action but "+ Add new
     * supplier"), the row removed. A "+ Add new" form is sent by its own button only (PX7).
     *
     * @return array{action: string, q: string, packs: int, price: string, si: ?int, sku: ?int, source: ?array{0: string, 1: int}, remove: ?int,
     *     charge: array{kind: string, amount: string, description: string}}
     */
    private static function asked(UiRequest $req): array
    {
        $action = $req->field('action') ?? 'add';
        $action = in_array($action, self::ACTIONS, true) ? $action : 'add';
        $q = trim($req->field('q') ?? '');
        $packsTyped = trim($req->field('packs') ?? '');
        $packs = $packsTyped === '' ? 1 : (PoLinesFile::wholeNumber($packsTyped) ?? 0);
        $sku = UiRequest::id($req->field('add_sku'));
        $si = null;
        $remove = UiRequest::id($req->field('remove_line'));
        if ($remove !== null) {
            $action = 'remove';
        } elseif ($sku !== null) {
            $action = 'add'; // a choice's own button
        } elseif ($action === 'add') {
            $si = UiRequest::id($req->field('pick_si'));
            $sku = $si === null ? UiRequest::id($req->field('pick_sku')) : null;
            if ($si === null && $sku === null && $q === '' && trim($req->field('charge_amount') ?? '') !== '') {
                $action = 'add_charge'; // Enter in the charge's amount: that charge (the form's first button is Add)
            }
        }
        $from = trim($req->field('from') ?? '');
        $source = preg_match('/^(grn|po):([1-9][0-9]{0,17})$/D', $from, $m) === 1 && $action !== 'new_supplier' ? [$m[1], (int) $m[2]] : null;
        return ['action' => $action, 'q' => $q, 'packs' => $packs, 'price' => trim($req->field('price') ?? ''), 'si' => $si, 'sku' => $sku, 'source' => $source,
            'remove' => $remove,
            'charge' => ['kind' => (string) ($req->field('charge_kind') ?? ''), 'amount' => trim($req->field('charge_amount') ?? ''),
                'description' => trim($req->field('charge_description') ?? '')]];
    }

    /**
     * The refusals made before anything is read (422, in words): on the new page Add with nothing to add (on a draft it saves the form, as
     * Enter in a field does: the form's first button is Add), "Fill the lines" with nothing chosen, "make final" with words left in the
     * item box, packs that are not a whole number.
     *
     * @param array<string, mixed> $ask
     */
    private static function check(array $ask, bool $new): void
    {
        if ($new && $ask['action'] === 'add' && $ask['si'] === null && $ask['sku'] === null && $ask['q'] === '' && $ask['source'] === null) {
            throw new CwException('need_product', Words::INVOICE_ERROR['need_product'], 422, ['field' => 'q']);
        }
        if ($ask['action'] === 'from' && $ask['source'] === null) {
            throw new CwException('need_source', Words::INVOICE_ERROR['need_source'], 422, ['field' => 'from']);
        }
        if ($ask['action'] === 'post' && $ask['q'] !== '') {
            // As the order page's Confirm (PO7): what is final is what the person sees, so words left in the item box stop it.
            throw new CwException('scan_pending', Words::say('INVOICE_ERROR', 'scan_pending', $ask['q']), 422, ['field' => 'q']);
        }
        $adding = $ask['action'] === 'new_item' || ($ask['action'] === 'add' && ($ask['si'] !== null || $ask['sku'] !== null || $ask['q'] !== ''));
        if ($adding && ($ask['packs'] < 1 || $ask['packs'] > PoMath::MAX_PACKS)) {
            throw new CwException('bad_qty', Words::INVOICE_ERROR['bad_qty'], 422, ['field' => 'packs']);
        }
    }

    /** A "+ Add new" action of a person who may not take it: the route's refusal (403) before anything is read or written (PX5). */
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

    /**
     * The header fields the form sent (on the new page every one, empty ones too; on a draft only those it sent, so an old lines form
     * changes no header).
     *
     * @return array<string, string>
     */
    private static function header(UiRequest $req, bool $sentOnly = false): array
    {
        $out = [];
        foreach (self::HEADER as $k) {
            $v = $req->field($k);
            if ($v !== null || !$sentOnly) {
                $out[$k] = (string) ($v ?? '');
            }
        }
        return $out;
    }

    /** @return array<string, mixed> what the form sent, for FormOnce's hash (without the token and the key) */
    private static function request(UiRequest $req): array
    {
        return array_diff_key($req->post, ['csrf' => 1, FormOnce::FIELD => 1]);
    }

    /** @return array<string, string> the CARRIED fields the form sent (empty ones left out, and a packs box holding 1) */
    private static function carried(UiRequest $req): array
    {
        $out = [];
        foreach (self::CARRIED as $k) {
            $v = trim($req->field($k) ?? '');
            if ($k === 'charge_kind' && trim($req->field('charge_amount') ?? '') === '' && trim($req->field('charge_description') ?? '') === '') {
                continue; // the charge's kind only with a charge typed
            }
            if ($v !== '' && !(in_array($k, ['packs', 'item_upp'], true) && $v === '1')) {
                $out[$k] = mb_substr($v, 0, $k === 'note' ? 1000 : 255);
            }
        }
        return $out;
    }

    /** @return array{name: string, path: string, role: string}|null the file chosen in the form (none: null; 400/413 in words when it did not come) */
    private static function file(Context $ctx): ?array
    {
        if ($ctx->req->file('file') === null) {
            return null;
        }
        $f = InvoicesController::upload($ctx);
        $role = (string) ($ctx->req->field('file_role') ?? 'supplier_invoice');
        return $f + ['role' => isset(SupplierInvoices::FILE_ROLES[$role]) ? $role : 'supplier_invoice'];
    }

    /**
     * The lines of a draft with the table's edits applied (InvoicesController's naming: p_<n> packs, pp_<n> pack price, a_<n> amount, d_<n>
     * words, x_<n> remove) and the row $remove left out (not read). The table must hold the stored lines (`line_count`).
     *
     * @return list<array<string, mixed>>|null null: the form drew no lines table
     */
    private static function editedLines(Context $ctx, int $id, ?int $remove): ?array
    {
        if ($ctx->req->field('line_count') === null) {
            return null;
        }
        return InvoicesController::editedLines($ctx, $id, $remove);
    }

    /** Where a done form leads: the draft's page at its lines, or the invoice once final, with its notice. @param array{status: string, document: Document} $res */
    private static function after(int $id, array $res, ?string $taken): string
    {
        $s = $res['status'];
        $query = ['notice' => $s];
        if ($s === 'posted') {
            $query['number'] = $res['document']->number;
        } elseif ($s === 'number_taken') {
            $query['other'] = $taken;
        }
        return Html::url(self::BASE . '/' . $id, $query) . (in_array($s, ['posted', 'submitted'], true) ? '' : '#lines');
    }

    /**
     * A refused form, on the page again with what was typed: the choices of an Add that found several items, its "not found", a "+ Add new"
     * form's refusal said in that form, else the refusal at the top (with the problems of a refused "make final").
     *
     * @param array<string, mixed> $ask
     * @param array<string, mixed> $extra
     */
    private function refused(Context $ctx, ?int $id, CwException $e, array $ask, array $extra): HtmlResponse
    {
        $extra += ['q' => $ask['q'], 'packs' => (string) ($ctx->req->field('packs') ?? '1'), 'price' => (string) ($ctx->req->field('price') ?? '')];
        $show = fn (int $status, ?CwException $error, array $more): HtmlResponse => $id === null
            ? $this->editPage($ctx, null, $status, $error, null, $more + $extra) : self::draftPage($ctx, $id, $status, $error, $more + $extra);
        return match (true) {
            $e->errorCode === 'one_page_choices' => $show(200, null, ['choices' => $e->detail['choices'] ?? []]),
            $e->errorCode === 'one_page_not_found' => $show(422, new CwException('not_found', Words::say('STOCK_ERROR', 'not_found', $ask['q']), 422), []),
            $ask['action'] === 'new_supplier' => $show($e->httpStatus, null, ['supplierError' => PoAddNew::plainSupplier($e)]),
            $ask['action'] === 'new_item' => $show($e->httpStatus, null, self::itemError($e)),
            default => $show($e->httpStatus, $e, []),
        };
    }

    /**
     * A saved invoice's page with a refusal or the choices: the one page for its creator while it is a draft, else the saved document's
     * view (InvoicesController::page decides, as on every route of an invoice).
     *
     * @param array<string, mixed> $extra
     */
    private static function draftPage(Context $ctx, int $id, int $status, ?CwException $error, array $extra): HtmlResponse
    {
        return (new InvoicesController())->page($ctx, 'invoice', $id, $status, $error, null, $extra);
    }

    /** A refusal of "+ Add new item" in its form: the items there of the same name, the item that has the barcode, else its words. @return array<string, mixed> */
    private static function itemError(CwException $e): array
    {
        $same = $e->errorCode === 'same_item_exists' && is_array($e->detail['items'] ?? null) ? $e->detail['items'] : null;
        return ['itemError' => $same === null ? PoAddNew::plainItem($e) : null, 'itemSame' => $same,
            'itemOther' => $e->errorCode === 'barcode_on_other_item' && isset($e->detail['sku_id']) ? (int) $e->detail['sku_id'] : null];
    }

    // ------------------------------------------------------------------------------------------
    // The page's data
    // ------------------------------------------------------------------------------------------

    /**
     * The one page: a new invoice ($id null: nothing saved, the supplier chosen in the form or by the address) or its creator's draft.
     *
     * @param array<string, mixed> $extra typed, q, packs, price, choices, formKey, supplierError, itemError, itemSame, itemOther
     */
    public function editPage(Context $ctx, ?int $id, int $status, ?CwException $error, ?string $notice, array $extra): HtmlResponse
    {
        $db = $ctx->db;
        $me = $ctx->me();
        $si = $ctx->supplierInvoices();
        $typed = is_array($extra['typed'] ?? null) ? $extra['typed'] : [];
        $text = static fn (string $k, string $default = ''): string => is_string($typed[$k] ?? null) ? (string) $typed[$k] : $default;
        $rec = $id === null ? null : $si->record($id);
        $doc = $rec === null ? null : Document::fromRow($rec);
        $supplierId = $rec !== null ? (int) $rec['supplier_id'] : UiRequest::id($text('supplier_id') === '' ? null : $text('supplier_id'));
        $supplier = $supplierId === null ? null : $db->one('SELECT id, name, code, status, payment_terms_days FROM supplier WHERE id = ?', [$supplierId]);
        $lines = $id === null ? [] : $si->lines($id);
        $sum = 0;
        foreach ($lines as &$l) {
            $sum += $l['amount'] === null ? 0 : InvoiceMath::e2((string) $l['amount']);
            $l['packs_text'] = $l['kind'] === 'goods' ? ReceivingController::packsText((int) $l['packs'], (string) ($l['purchase_unit'] ?? 'each'), (int) $l['units_per_pack']) : null;
            $l['kind_word'] = Words::of('INVOICE_LINE', (string) $l['kind']);
            $l['price_text'] = $l['pack_price'] === null ? '' : self::price((string) $l['pack_price']);
            $l['amount_text'] = $l['amount'] === null ? '' : InvoiceMath::fromE2(InvoiceMath::e2((string) $l['amount']));
        }
        unset($l);
        $value = static fn (string $k, mixed $stored): string => array_key_exists($k, $typed) && is_string($typed[$k]) ? (string) $typed[$k] : ($stored === null ? '' : (string) $stored);
        $form = [
            'invoice_number' => $value('invoice_number', $rec['external_ref'] ?? null),
            'invoice_date' => $value('invoice_date', $rec['doc_date'] ?? null),
            'due_date' => $value('due_date', $rec['due_date'] ?? null),
            'note' => $value('note', $rec['note'] ?? null),
            'net_total' => $value('net_total', $rec['net_total'] ?? null),
            'vat_total' => $value('vat_total', $rec['vat_total'] ?? null),
        ];
        $terms = $supplier === null || $supplier['payment_terms_days'] === null ? null : (int) $supplier['payment_terms_days'];
        // From the supplier's payment terms (PV8) on the new page only, unless the person cleared the one shown; a saved draft shows its own
        // (a due date cleared there stays cleared: the Codex review of 6418b15, finding 4). Only a real date is worked from (finding 8).
        $clearedHere = $doc === null && trim($text('due_shown')) !== '' && trim($form['due_date']) === '';
        $dueAuto = false;
        if ($doc === null && !$clearedHere && $form['due_date'] === '' && $terms !== null && self::realDate($form['invoice_date'])) {
            $form['due_date'] = (new \DateTimeImmutable($form['invoice_date']))->modify('+' . $terms . ' days')->format('Y-m-d');
            $dueAuto = true;
        }
        $net = self::moneyE2($form['net_total']);
        $vat = self::moneyE2($form['vat_total']);
        $total = ($net ?? $sum) + ($vat ?? 0);
        $fixed = $doc !== null;
        $same = is_array($extra['itemSame'] ?? null) ? array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'code' => (string) $s['code'], 'name' => (string) $s['name']],
            $extra['itemSame']) : null;
        $other = isset($extra['itemOther']) ? $db->one('SELECT id, code, name FROM sku WHERE id = ?', [(int) $extra['itemOther']]) : null;
        $itemError = $extra['itemError'] ?? null;
        $itemTyped = array_filter(['item_name', 'item_brand', 'item_barcode', 'item_code', 'item_price'], static fn (string $k): bool => trim($text($k)) !== '');
        $q = (string) ($extra['q'] ?? $text('q'));
        $pick = self::picked($ctx, $supplierId, $typed, $q);
        $editable = $doc !== null && $doc->status === 'draft';
        $problems = $doc !== null ? $si->problems($id) : [];
        $data = [
            'isNew' => $doc === null,
            'doc' => $doc,
            'rec' => $rec,
            'title' => $doc === null ? Words::PI_ONE['title'] : Words::docTitle($doc->docType, $doc->number, (string) ($rec['supplier_name'] ?? '')),
            'formAction' => $doc === null ? self::BASE : self::BASE . '/' . $doc->id . '/lines',
            'formKey' => (string) ($extra['formKey'] ?? FormOnce::newKey()),
            'supplierFixed' => $fixed,
            'supplierId' => $supplierId,
            'supplierName' => (string) ($supplier['name'] ?? ''),
            'supplierChoices' => $fixed ? [] : self::supplierChoices($db),
            'terms' => $terms,
            'canAddSupplier' => !$fixed && $me->can('suppliers.manage'),
            'askSupplier' => Words::askToAdd('suppliers.manage'),
            'supplierError' => $extra['supplierError'] ?? null,
            'newSupplier' => ['name' => $text('new_supplier_name'), 'email' => $text('new_supplier_email'), 'phone' => $text('new_supplier_phone')],
            'canAddItem' => $me->can('items.create'),
            'askItem' => Words::askToAdd('items.create'),
            'itemOpen' => $itemError !== null || $same !== null || $other !== null || $itemTyped !== [],
            'itemError' => $itemError,
            'itemSame' => $same,
            'itemOther' => $other === null ? null : ['id' => (int) $other['id'], 'label' => $other['code'] . ' ' . $other['name']],
            'item' => ['name' => $text('item_name'), 'brand' => $text('item_brand'), 'barcode' => $text('item_barcode'), 'code' => $text('item_code'),
                'upp' => $text('item_upp', '1'), 'price' => $text('item_price'), 'anyway' => $text('item_anyway') === '1'],
            'productsUrl' => self::PRODUCTS . '?' . ($fixed ? 'supplier=' . (int) $supplierId : ''),
            'pick' => $pick,
            'q' => $q,
            'packs' => (string) ($extra['packs'] ?? ($text('packs') === '' ? '1' : $text('packs'))),
            'price' => (string) ($extra['price'] ?? $text('price')),
            'choices' => $extra['choices'] ?? null,
            // While the supplier can still change (the new page), every supplier's open deliveries and orders, each naming its own: the script
            // keeps the chosen one's (the Codex review of 6418b15, finding 5); a draft's supplier is fixed.
            'sources' => self::sources($ctx, $fixed ? $supplierId : null),
            'dueAuto' => $dueAuto,
            // What the box showed when the page was drawn: a due date the person cleared keeps its marker through a refused button (the
            // Codex review of e1db3a4, M2, PV22), so the save after it does not fill the terms' date again.
            'dueShown' => $clearedHere ? $text('due_shown') : $form['due_date'],
            'linesFit' => self::linesFit(count($lines)),
            'from' => $text('from'),
            'charge' => ['kind' => $text('charge_kind', 'freight'), 'amount' => $text('charge_amount'), 'description' => $text('charge_description')],
            'chargeOpen' => $text('charge_amount') !== '' || $text('charge_description') !== '',
            'chargeKinds' => array_intersect_key(Words::INVOICE_LINE, array_flip(SupplierInvoices::CHARGE_KINDS)),
            'lines' => $lines,
            'typedLines' => $typed,
            'form' => $form,
            'totals' => [
                ['label' => Words::DOC['sub_total'], 'value' => Html::money(InvoiceMath::fromE2($sum))],
                ['label' => Words::PI_ONE['paper_net'], 'input' => ['name' => 'net_total', 'id' => 'pi-net', 'value' => $form['net_total']]],
                ['label' => Words::PI_ONE['paper_vat'], 'input' => ['name' => 'vat_total', 'id' => 'pi-vat', 'value' => $form['vat_total']]],
                ['label' => Words::DOC['total'], 'value' => Html::money(InvoiceMath::fromE2($total)), 'grand' => true],
            ],
            'fileRoles' => array_intersect_key(Words::INVOICE_FILE, SupplierInvoices::FILE_ROLES),
            'files' => $id === null ? [] : array_map(static fn (array $f): array => $f + ['what' => Words::INVOICE_FILE[(string) $f['role']] ?? Words::of('RECEIPT_FILE', (string) $f['role'])],
                ReceivingController::fileRows($db->all('SELECT f.id, f.original_name, f.mime, f.size_bytes, df.role, df.attached_at FROM document_file df '
                    . 'JOIN stored_file f ON f.id = df.file_id WHERE df.document_id = ? ORDER BY df.attached_at, f.id', [$id]))),
            'maxMb' => intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576),
            'problems' => $problems,
            // How it would match if it were made final now (pack C's 3-way match, PC2), in one line above the lines.
            'match' => $doc !== null && $lines !== [] ? InvoicesController::matchView($si->matchOf($id), false) : null,
            'error' => $error === null ? null : InvoicesController::plain($error),
            'errorCode' => $error?->errorCode,
            'problemsOfError' => $error !== null && is_array($error->detail['problems'] ?? null) ? array_map(static fn (array $p): string => InvoicesController::problemText($p),
                $error->detail['problems']) : [],
            'arrival' => $rec === null || $doc === null ? null : ArrivalsController::invoiceBox($ctx, $rec, $doc, $editable, $lines), // pack J (PJ6, PJ9, PJ10)
            'clockChoice' => $extra['clock'] ?? null,
            'ruleWaits' => $doc !== null && self::ruleWaits($ctx),
            'typedStop' => (string) ($extra['stop'] ?? ''),
            'k' => Words::INVOICE_KIND['invoice'],
        ];
        return $ctx->page('invoice_edit', $data, $status, ['title' => $doc === null ? Words::PI_ONE['title'] : Words::docTitle($doc->docType, $doc->number), 'notice' => $notice]);
    }

    /** "2026-10-01" is a real date; "2026-99-99" is not. */
    private static function realDate(string $v): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) === 1 && \DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v;
    }

    /** Whether making an invoice final waits for a reviewer's OK first (a rule on the Approval rules page). */
    private static function ruleWaits(Context $ctx): bool
    {
        $t = $ctx->documents()->typeInfo('SINV');
        return $t['approval_rule'] !== 'none' || (int) ($t['size_approval'] ?? 0) === 1;
    }

    /** A pack price as the box shows it: pounds and pence, and the 3rd and 4th decimals only when there are any ("2.50", "2.1234"). */
    private static function price(string $stored): string
    {
        if (preg_match('/^(-?[0-9]+)\.([0-9]{2})([0-9]*)$/D', $stored, $m) !== 1) {
            return $stored;
        }
        return $m[1] . '.' . $m[2] . rtrim($m[3], '0');
    }

    /** A figure typed in pounds (e.g. "40.00") in pence, or null when it is not one. */
    private static function moneyE2(string $typed): ?int
    {
        $t = trim($typed);
        return preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/D', $t) === 1 ? InvoiceMath::e2($t) : null;
    }

    /**
     * The suppliers an invoice may name, as the supplier's box offers them: every supplier (an invoice of a supplier switched off since is
     * still booked, as before); one not approved yet, or switched off, says so on its second line (the order page's words); its payment
     * terms in days ride on its option for the due date.
     *
     * @return list<array{id: int, name: string, label: string, meta: string, terms: string}>
     */
    public static function supplierChoices(Db $db): array
    {
        return array_map(static function (array $s): array {
            $state = match ((string) $s['status']) {
                'draft' => Words::ORDERS['not_approved'],
                'pending_approval' => Words::ORDERS['waiting_ok'],
                'inactive' => mb_strtolower(Words::SUPPLIER_STATUS['inactive']),
                default => '',
            };
            return ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'label' => $state === '' ? (string) $s['name'] : Words::say('PO_ONE', 'supplier_option', (string) $s['name'], $state),
                'meta' => $state === '' ? '' : mb_strtoupper(mb_substr($state, 0, 1)) . mb_substr($state, 1), 'terms' => $s['payment_terms_days'] === null ? '' : (string) (int) $s['payment_terms_days']];
        }, $db->all('SELECT id, name, status, payment_terms_days FROM supplier ORDER BY name, id'));
    }

    /**
     * "From" (PV4): the deliveries not invoiced yet (SupplierInvoices::notInvoiced) and the open orders (approved, sent, part received) of
     * the supplier, or of every supplier while none is chosen (each naming its supplier; the script keeps the chosen one's).
     *
     * @return array{deliveries: list<array{value: string, supplier: int, label: string}>, orders: list<array{value: string, supplier: int, label: string}>}
     */
    public static function sources(Context $ctx, ?int $supplierId): array
    {
        $deliveries = array_map(static fn (array $r): array => ['value' => 'grn:' . (int) $r['id'], 'supplier' => (int) $r['supplier_id'],
            'label' => Words::say('PI_ONE', 'from_delivery', (string) $r['number'], (string) $r['supplier_name'], Html::day($r['doc_date'] === null ? null : (string) $r['doc_date']),
                (int) $r['units_left'])],
            $ctx->supplierInvoices()->notInvoiced($supplierId));
        $orders = array_map(static fn (array $r): array => ['value' => 'po:' . (int) $r['id'], 'supplier' => (int) $r['supplier_id'],
            'label' => Words::say('PI_ONE', 'from_order', (string) $r['number'], (string) $r['supplier_name'], Html::day($r['doc_date'] === null ? null : (string) $r['doc_date']))],
            $ctx->db->all("SELECT d.id, d.number, d.doc_date, p.supplier_id, s.name AS supplier_name FROM purchase_order p JOIN document d ON d.id = p.document_id "
                . "AND d.status = 'posted' JOIN supplier s ON s.id = p.supplier_id WHERE p.state IN ('approved', 'sent', 'part_received')"
                . ($supplierId === null ? '' : ' AND p.supplier_id = ?') . ' ORDER BY d.id DESC LIMIT ' . SupplierInvoices::LIST_LIMIT, $supplierId === null ? [] : [$supplierId]));
        return ['deliveries' => $deliveries, 'orders' => $orders];
    }

    /**
     * The item box's choice kept after a refusal (the order page's rule, the Codex review of bd26fc3, finding 5): the pack (or item) the
     * form sent, while the box still holds its words exactly and it is this supplier's; else none, and the words are searched as typed.
     *
     * @param array<string, mixed> $typed
     * @return array{si: string, sku: string, choice: ?array{label: string, name: string, meta: string, si: string, sku: string}}
     */
    private static function picked(Context $ctx, ?int $supplierId, array $typed, string $q): array
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
        $choice = PurchaseOrdersController::productChoice(['sku_id' => (int) $row['sku_id'], 'sku_code' => (string) $row['code'], 'name' => (string) $row['name'],
            'brand' => $row['brand'], 'supplier_item_id' => $row['si_id'] === null ? null : (int) $row['si_id'], 'units_per_pack' => (int) ($row['units_per_pack'] ?? 1),
            'supplier_code' => $row['supplier_code'], 'purchase_unit' => (string) ($row['purchase_unit'] ?? 'each')]);
        return $choice['label'] === $q ? ['si' => $choice['si'], 'sku' => $choice['sku'], 'choice' => $choice] : $none;
    }

    /** Whether this person is the draft's creator who may change it (the one page); others get the saved document's view. */
    public static function editable(Context $ctx, Document $doc): bool
    {
        return $doc->status === 'draft' && Documents::mayPost($ctx->me()->roles, $doc->docType) && $doc->createdBy === $ctx->me()->id;
    }
}
