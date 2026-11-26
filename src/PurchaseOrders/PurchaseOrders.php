<?php

declare(strict_types=1);

namespace CW\PurchaseOrders;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Catalogue\ItemCompliance;
use CW\Catalogue\ItemRules;
use CW\Clock;
use CW\Company\CompanyDetails;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Documents\DraftChanges;
use CW\Files\FileStore;
use CW\Items\HandMade;
use CW\Live\LiveChanges;
use CW\Live\Presence;
use CW\Matching\Gtin;
use CW\Settings;
use CW\Staff\StaffRoles;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Ui\Queries;

/**
 * Purchase orders (IM5; docs/decisions.md I48-I59, spec §6.3): the PO-specific side of the PO document type, on top of the
 * document base (Documents) and its handler (PurchaseOrderHandler).
 *
 * A PO is a `document` (type PO) with a `purchase_order` header extension and one `po_line` per `document_line`:
 *   draft (anyone who writes orders edits it freely, DA1) --approve = Documents::post--> posted: numbered, content fixed (posted_hash +
 *   po_posting), reviewed by a second person within 7 days; over the value limit it first waits in awaiting_approval for a
 *   reviewer (blocking). After approval the PO's own state moves: approved -> sent (markSent; re-sending allowed) ->
 *   part_received / received (applyReceipt, from the I-3 GRN) -> closed (close, from part_received: the rest is not
 *   expected). Cancel: a draft is cancelled (Documents::cancelDraft), a posted PO is reversed (Documents::reverse: a
 *   "Cancellation of PO-x" document in the same series; refused once goods were received). Amend = that reversal + a new
 *   draft copied from it, in ONE transaction. Immutability starts at approval (not at "sent"): every change afterwards is a
 *   reversal plus a new PO (I17, I18).
 *
 * Every public write: a staff caller holding doc.PO.post (never admin: 403 admin_cannot_post), roles re-read inside the
 * transaction; ONE Db::transaction (joining an open one); the document row locked first (FOR UPDATE), its version checked
 * (409 version_conflict, naming who saved it last: DraftChanges::conflict) and moved; a draft changed by anyone who writes orders, not only
 * its creator (docs/decisions.md DA1; the owner, 18 Nov 2026), created_by kept and each save audited under its person. Money: integers only (PoMath).
 * Nothing here books stock (spec §0.4). Lock order (§6.9): document row(s) -> review task -> supplier FOR SHARE ->
 * number_series -> purchase_order -> po_line -> supplier_item -> supplier_item_price -> po_posting.
 */
final class PurchaseOrders
{
    public const SOURCES = ['manual', 'reorder', 'copy', 'amend', 'import_file', 'erp_seed'];
    public const SEND_VIA = ['email' => 'e-mail', 'portal' => 'supplier portal', 'phone' => 'phone', 'in_person' => 'in person', 'imported' => 'imported',
        'other' => 'other'];
    /**
     * Where a reason is offered on the order screens (reason_code.applies_to, 0019; review finding I6, Y51): cancelling a confirmed
     * order (`cancel`), a draft or an order waiting for its OK (`draft_cancel`), correcting a confirmed order (`amend`). The lists
     * come from the Reasons page (reasons()), never from the code: the owner adds, renames, switches off and places reasons there.
     */
    public const REASON_USES = ['cancel' => 'po_cancel', 'draft_cancel' => 'po_draft_cancel', 'amend' => 'po_amend'];
    /** The states in which goods are still expected (onOrder, applyReceipt). */
    public const OPEN_STATES = ['approved', 'sent', 'part_received'];
    public const STATES = ['approved', 'sent', 'part_received', 'received', 'closed', 'cancelled'];
    public const MAX_LINES = PurchaseOrderHandler::MAX_LINES;
    /**
     * Lines the one-form editor carries at most; the form must also stay below max_input_vars (PurchaseOrdersController::
     * editorFits: about 240 lines with supplier items at 1000, I73). Larger orders use the file import (spec §8.2).
     */
    public const MAX_EDITOR_LINES = 300;
    public const HEADER_FIELDS = ['expected_date', 'external_ref', 'note', 'doc_date', 'supplier_id'];
    /** The order date: at most this many days ahead of today (UK) and back (I81). */
    public const DOC_DATE_AHEAD_DAYS = 31;
    public const DOC_DATE_BACK_DAYS = 731;
    public const LINE_FIELDS = ['kind', 'sku_id', 'supplier_item_id', 'supplier_code', 'purchase_unit', 'units_per_pack', 'packs', 'pack_price', 'vat_code',
        'description', 'suggested_units', 'save_item', 'uid'];
    public const SEARCH_LIMIT = 20;

    private readonly Settings $settings;
    private readonly SupplierItems $supplierItems;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    /** @var array<string, array{rate: string, active: bool}>|null */
    private ?array $vat = null;

    /**
     * @param (\Closure(): ?FileStore)|null $files the file store when this server has one (markSent archives the PDF), else null
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly Db $db,
        private readonly Documents $docs,
        ?Settings $settings = null,
        private readonly ?\Closure $files = null,
        ?\Closure $clock = null,
    ) {
        $this->settings = $settings ?? new Settings($db);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->supplierItems = new SupplierItems($db, $this->clock);
    }

    // ------------------------------------------------------------------------------------------
    // Drafts
    // ------------------------------------------------------------------------------------------

    /**
     * A new draft PO for $supplierId: doc_date today (UK) unless given, warehouse MAIN, the purchase_order row. The supplier
     * may be draft or waiting for approval (the order is prepared while the approval waits; approval is refused until the
     * supplier is active), never inactive (422 supplier_inactive). Audit po.create.
     *
     * @param array<string, mixed> $header expected_date, external_ref (the supplier's quote), note (printed "Notes to supplier"), doc_date
     */
    public function createDraft(Caller $caller, int $supplierId, array $header, string $source = 'manual', ?int $amendsId = null): Document
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("unknown PO source {$source}");
        }
        return $this->db->transaction(function (Db $db) use ($caller, $supplierId, $header, $source, $amendsId): Document {
            $this->poster($caller);
            $h = $this->headerFields($header, false);
            $s = $db->one('SELECT id, code, status FROM supplier WHERE id = ? FOR SHARE', [$supplierId])
                ?? throw new CwException('unknown_supplier', 'there is no such supplier', 404);
            if ($s['status'] === 'inactive') {
                throw new CwException('supplier_inactive', "supplier {$s['code']} is inactive: it gets no new purchase orders", 422);
            }
            $doc = $this->docs->createDraft($caller, 'PO', ['doc_date' => $h['doc_date'] ?? $this->today(), 'warehouse' => 'MAIN',
                'external_ref' => $h['external_ref'] ?? null, 'note' => $h['note'] ?? null]);
            $now = $this->nowDb();
            $db->exec('INSERT INTO purchase_order (document_id, supplier_id, source, expected_date, amends_document_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$doc->id, $supplierId, $source, $h['expected_date'] ?? null, $amendsId, $now, $now]);
            Audit::write($db, $caller, 'po.create', 'document', (string) $doc->id, null,
                ['supplier' => (string) $s['code'], 'source' => $source] + ($amendsId === null ? [] : ['amends' => $amendsId]));
            return $doc;
        });
    }

    /**
     * Saves a draft: the header (expected_date, external_ref, note, doc_date, supplier_id) and EVERY line (the list replaces the draft's
     * lines; [] clears them). Anyone who writes orders (DA1). Version + 2 (Documents::updateDraft, then Documents::setLines, whose cascade removes the old
     * po_line rows), then the po_line rows and the totals. A new supplier (the owner, 6 Nov 2026: "also in draft and new easily supplier
     * change option"; docs/decisions.md KG30) is checked as a new order's (never an inactive one) and the lines given, in the old supplier's
     * terms, are moved to it in the same save (repoint()): never one dropped.
     *
     * A line: {kind: item|charge, sku_id?, supplier_item_id?, supplier_code?, purchase_unit?, units_per_pack?, packs,
     * pack_price?, vat_code?, description?, suggested_units?, save_item?}. An item line with a supplier item takes its pack,
     * unit and code; without one, the units per pack given (default 1, 'each'), linked to the supplier's active supplier item
     * of that item and pack when there is one; save_item creates that supplier item in the same transaction. The price
     * defaults to the supplier item's last price (none: 0, warned). The VAT code defaults to the supplier's, then
     * po.default_vat_code. A charge line (delivery, ...) has a description and an amount (its pack_price, whole pence > 0).
     *
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $lines
     */
    public function saveDraft(Caller $caller, int $id, int $version, array $header, array $lines): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $header, $lines): Document {
            $this->poster($caller);
            $this->lockDraft($id, $version);
            $h = $this->headerFields($header, true);
            $current = $this->poRow($id);
            $supplierId = (int) $current['supplier_id'];
            $changed = isset($h['supplier_id']) && $h['supplier_id'] !== $supplierId;
            $from = $supplierId;
            if ($changed) {
                $supplierId = (int) $h['supplier_id'];
            }
            $supplier = $db->one('SELECT * FROM supplier WHERE id = ? FOR SHARE', [$supplierId])
                ?? throw new CwException('unknown_supplier', 'there is no such supplier', 422, ['field' => 'supplier_id']);
            if ($changed && $supplier['status'] === 'inactive') {
                throw new CwException('supplier_inactive', "supplier {$supplier['code']} is inactive: it gets no new purchase orders", 422);
            }
            $db->one('SELECT document_id FROM purchase_order WHERE document_id = ? FOR UPDATE', [$id]);
            if ($changed) {
                $lines = $this->repoint($from, $supplierId, $lines);
            }
            $norm = $this->normaliseLines($caller, $supplier, $lines);
            $doc = $this->docs->updateDraft($caller, $id, $version, array_intersect_key($h, array_flip(['external_ref', 'note', 'doc_date'])));
            // A line's own version (docs/decisions.md LS2): kept while its content is the same, the order's own row (po_line) included.
            $doc = $this->docs->setLines($caller, $id, $doc->version, array_map(static fn (array $n): array => $n['doc'], $norm),
                array_map(static fn (array $n): string => self::poKey($n['po']), $norm), $this->oldKeys($id));
            foreach (array_chunk($norm, 500, true) as $chunk) {
                $params = [];
                foreach ($chunk as $i => $n) {
                    $p = $n['po'];
                    array_push($params, $id, $i + 1, $p['kind'], $p['supplier_item_id'], $p['supplier_code'], $p['purchase_unit'], $p['units_per_pack'], $p['packs'],
                        $p['pack_price'], $p['vat_code'], $p['vat_rate'], $p['suggested_units']);
                }
                $db->exec('INSERT INTO po_line (document_id, line_no, kind, supplier_item_id, supplier_code, purchase_unit, units_per_pack, packs, pack_price, vat_code, '
                    . 'vat_rate, suggested_units) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
            }
            $t = PoMath::totals(array_map(static fn (array $n): array => $n['calc'], $norm));
            $set = 'supplier_id = ?, net_total = ?, vat_total = ?, gross_total = ?, updated_at = ?';
            $params = [$supplierId, PoMath::fromE2($t['net_e2']), PoMath::fromE2($t['vat_e2']), PoMath::fromE2($t['gross_e2']), $this->nowDb()];
            if (array_key_exists('expected_date', $h)) {
                $set .= ', expected_date = ?';
                $params[] = $h['expected_date'];
            }
            $params[] = $id;
            $db->exec("UPDATE purchase_order SET {$set} WHERE document_id = ?", $params);
            Audit::write($db, $caller, 'po.save', 'document', (string) $id, null, ['version' => $doc->version, 'lines' => count($norm),
                'net' => PoMath::fromE2($t['net_e2'])] + ($changed ? ['supplier' => (string) $supplier['code'], 'from_supplier' => $from,
                    'unpriced' => count(array_filter($norm, static fn (array $n): bool => $n['po']['kind'] === 'item' && $n['po']['supplier_item_id'] === null))] : []));
            if ($changed && $this->liveOn()) {
                // Every line moved to the new supplier (KG30): the line-by-line changes say so (the History card, the other open pages: LS5).
                $old = $db->one('SELECT name FROM supplier WHERE id = ?', [$from]);
                LiveChanges::record($db, $caller, 'po', $id, 'supplier', null, null, ['name' => $old['name'] ?? null], ['name' => (string) $supplier['name']], null,
                    $doc->version, null, $this->nowDb());
            }
            return $doc;
        });
    }

    /**
     * A draft's lines moved to its new supplier (docs/decisions.md KG30), before they are checked: an item line of another supplier's pack
     * (or of an item alone) takes the new supplier's pack of the same item when it has one (the same items per pack first, then its
     * preferred, then the smallest), with its code, unit and pack; the packs keep the units when the new pack holds them whole, else the
     * next whole pack up. Its price is the new supplier's (normaliseLines: the pack's last price) unless the person typed one: a price
     * other than the old pack's last price (or 0 when it had none) stays, per unit (scaled to the new pack). An item the new supplier does
     * not sell stays as a line of the item alone, its items per pack and price kept, the old supplier's code gone (the page says how many:
     * "N lines have no price from X yet"). Charge lines, lines of the new supplier's own packs and lines normaliseLines will refuse in words
     * (no item, a pack of no supplier, packs not a whole number) go through unchanged. Public for a new order's page: a line picked
     * before its supplier box changed is moved so ($from 0: no old supplier, a price typed is any but £0). Reads only.
     *
     * @param list<mixed> $lines
     * @return list<mixed>
     */
    public function moveLines(int $from, int $to, array $lines): array
    {
        return $this->repoint($from, $to, $lines);
    }

    /**
     * @param list<mixed> $lines
     * @return list<mixed>
     */
    private function repoint(int $from, int $to, array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (!is_array($l) || ($l['kind'] ?? 'item') !== 'item') {
                $out[] = $l;
                continue;
            }
            $old = null;
            if (is_int($l['supplier_item_id'] ?? null)) {
                $old = $this->db->one('SELECT * FROM supplier_item WHERE id = ?', [$l['supplier_item_id']]);
                if ($old === null || (int) $old['supplier_id'] === $to) {
                    $out[] = $l;
                    continue;
                }
            } elseif (($l['supplier_item_id'] ?? null) !== null || $from === 0) {
                $out[] = $l; // no old supplier (a new order's page): a line of an item alone is the chosen supplier's already
                continue;
            }
            $sku = $old !== null ? (int) $old['sku_id'] : (is_int($l['sku_id'] ?? null) ? $l['sku_id'] : null);
            $packs = $l['packs'] ?? null;
            $upp = $old !== null ? (int) $old['units_per_pack'] : ($l['units_per_pack'] ?? 1);
            if ($sku === null || !is_int($packs) || $packs < 1 || !is_int($upp) || $upp < 1) {
                $out[] = $l;
                continue;
            }
            if ($old === null) {
                $old = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = ? AND is_active = 1 ORDER BY id LIMIT 1',
                    [$from, $sku, $upp]);
            }
            $price = $l['pack_price'] ?? null;
            $price = is_string($price) && trim($price) !== '' ? trim($price) : null;
            $typed = false;
            $priceE4 = null;
            if ($price !== null) {
                try {
                    // As normaliseLines reads it (£, spaces, commas; the Codex review of 04421ad, finding 8), so a price typed so is moved too.
                    $price = SupplierItems::packPrice($price);
                    $priceE4 = PoMath::e4($price);
                    $typed = $priceE4 !== ($old !== null && $old['last_pack_price'] !== null ? PoMath::e4((string) $old['last_pack_price']) : 0);
                } catch (\RangeException | \InvalidArgumentException | CwException) {
                    $out[] = $l; // normaliseLines says what is wrong with it
                    continue;
                }
            }
            $si = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND is_active = 1 ORDER BY units_per_pack = ? DESC, is_preferred DESC, '
                . 'units_per_pack, id LIMIT 1', [$to, $sku, $upp]);
            $keep = array_diff_key($l, array_flip(['supplier_item_id', 'sku_id', 'units_per_pack', 'supplier_code', 'purchase_unit', 'save_item', 'pack_price', 'packs']));
            if ($si === null) {
                $out[] = ['sku_id' => $sku, 'units_per_pack' => $upp, 'packs' => $packs, 'purchase_unit' => $old !== null ? (string) $old['purchase_unit'] : ($l['purchase_unit'] ?? null),
                    'pack_price' => $price ?? ($old !== null && $old['last_pack_price'] !== null ? (string) $old['last_pack_price'] : null)] + $keep;
                continue;
            }
            $k = (int) $si['units_per_pack'];
            $units = $packs * $upp;
            $out[] = ['supplier_item_id' => (int) $si['id'], 'packs' => $k === $upp ? $packs : max(1, intdiv($units + $k - 1, $k)),
                'pack_price' => !$typed || $priceE4 === null ? null : ($k === $upp ? $price : PoMath::fromE4(intdiv($priceE4 * $k * 2 + $upp, 2 * $upp)))] + $keep;
        }
        return $out;
    }

    /**
     * Adds a line to a draft from what was scanned or typed, in this order (spec §6.3):
     *   1. digits (6-64): a usable barcode (Gtin key). A case barcode (units_per_scan k > 1) takes the supplier's supplier item
     *      of pack k when there is one; otherwise the supplier's preferred or only supplier item of the item; otherwise a line
     *      without a supplier item in packs of k;
     *   2. this supplier's supplier code (trimmed, any case);
     *   3. a CW code (CW-000123);
     *   4. a search (Queries::searchSkus, at most 20; items this supplier sells first): `choices`.
     * The same supplier item (or, without one, the same item and pack) already on the draft gets $packs more (a repeated
     * scan adds a pack): `incremented`; otherwise a new line: `added`. Saves the draft (version + 2).
     *
     * @return array{status: 'added'|'incremented'|'choices'|'not_found', document: Document, line_no?: int, choices?: list<array<string, mixed>>, q: string}
     */
    public function addLine(Caller $caller, int $id, int $version, string $q, int $packs = 1): array
    {
        $q = trim($q);
        return $this->db->transaction(function () use ($caller, $id, $version, $q, $packs): array {
            $this->poster($caller);
            $this->lockDraft($id, $version);
            $supplierId = (int) $this->poRow($id)['supplier_id'];
            $r = $this->resolve($supplierId, $q);
            if ($r['status'] !== 'found') {
                return ['status' => $r['status'], 'document' => $this->docs->get($id), 'choices' => $r['choices'] ?? [], 'q' => $q];
            }
            return $this->addResolved($caller, $id, $version, $r, $packs) + ['q' => $q];
        });
    }

    /**
     * Adds (or increments) the line of one of the supplier's supplier items (a "Choose" button of the editor).
     *
     * @return array{status: 'added'|'incremented', document: Document, line_no: int}
     */
    public function addSupplierItem(Caller $caller, int $id, int $version, int $supplierItemId, int $packs = 1): array
    {
        return $this->db->transaction(function () use ($caller, $id, $version, $supplierItemId, $packs): array {
            $this->poster($caller);
            $this->lockDraft($id, $version);
            $supplierId = (int) $this->poRow($id)['supplier_id'];
            $si = $this->db->one('SELECT * FROM supplier_item WHERE id = ? AND supplier_id = ? AND is_active = 1', [$supplierItemId, $supplierId])
                ?? throw new CwException('unknown_supplier_item', 'this supplier has no such active supplier item', 422);
            return $this->addResolved($caller, $id, $version, self::found((int) $si['sku_id'], $si), $packs);
        });
    }

    /**
     * Imports lines from a CSV or XLSX file (spec §6.6; PoLinesFile): ALL OR NOTHING. With any refused row nothing changes
     * and the errors come back ("row N, column: message", at most 50). `append` adds the lines (a line of a supplier item
     * already on the draft gets the packs added); `replace` replaces every line. Audit po.import_lines.
     *
     * @return array{lines: int, errors: list<string>, document: Document}
     */
    public function importLines(Caller $caller, int $id, int $version, string $path, string $name, string $mode): array
    {
        if (!in_array($mode, ['append', 'replace'], true)) {
            throw new CwException('bad_field', 'mode is append or replace', 400, ['field' => 'mode']);
        }
        $file = PoLinesFile::read($path);
        $sha = (string) hash_file('sha256', $path);
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $file, $sha, $name, $mode): array {
            $this->poster($caller);
            $this->lockDraft($id, $version);
            $supplier = $db->one('SELECT * FROM supplier WHERE id = ?', [(int) $this->poRow($id)['supplier_id']]) ?? throw new \LogicException('PO without supplier');
            $vat = array_map(static fn (array $v): bool => $v['active'], $this->vatCodes());
            $parsed = PoLinesFile::parse($file['header'], $file['rows'], fn (string $type, string $value, ?array $within = null): array|string
                => $this->resolveForFile((int) $supplier['id'], $type, $value, $within), $vat);
            if ($parsed['errors'] !== []) {
                return ['lines' => 0, 'errors' => $parsed['errors'], 'document' => $this->docs->get($id)];
            }
            $lines = $mode === 'replace' ? [] : $this->lines($id);
            foreach ($parsed['lines'] as $l) {
                unset($l['row']);
                $merged = false;
                if ($mode === 'append' && $l['kind'] === 'item') {
                    foreach ($lines as &$cur) {
                        if (self::sameLine($cur, $l)) {
                            $cur['packs'] += $l['packs'];
                            $merged = true;
                            break;
                        }
                    }
                    unset($cur);
                }
                if (!$merged) {
                    $lines[] = $l;
                }
            }
            $doc = $this->saveDraft($caller, $id, $version, [], $lines);
            Audit::write($db, $caller, 'po.import_lines', 'document', (string) $id, null,
                ['file' => mb_substr(basename($name), 0, 120), 'sha256' => $sha, 'format' => $file['format'], 'rows' => count($parsed['lines']), 'mode' => $mode]);
            if ($this->liveOn()) {
                // The lines from a file (LS5): every line new (replace) or some added (append), one change in the History card.
                LiveChanges::record($db, $caller, 'po', $id, 'replace', null, null, null, ['lines' => count($parsed['lines']), 'mode' => $mode], null, $doc->version, null,
                    $this->nowDb());
            }
            return ['lines' => count($parsed['lines']), 'errors' => [], 'document' => $doc];
        });
    }

    // ------------------------------------------------------------------------------------------
    // Select or add new (the owner, 15 Oct 2026; pack PO, docs/decisions.md PO1-PO6)
    // ------------------------------------------------------------------------------------------

    /**
     * "Add a new supplier" while starting an order: the supplier is made as the supplier screens make one (Suppliers::create: a
     * DRAFT supplier, suppliers.manage, its own checks and audit) and a draft order is started for it, in ONE transaction. The order
     * is prepared while the supplier is not approved yet; confirming it still waits for the supplier's approval (PurchaseOrderHandler,
     * unchanged). The caller writes purchase orders too (doc.PO.post). Audit supplier.create and po.create (source manual).
     *
     * @param array<string, mixed> $fields Suppliers::FIELDS (name required)
     * @return array{supplier: array<string, mixed>, document: Document}
     */
    public function createWithNewSupplier(Caller $caller, Suppliers $suppliers, array $fields): array
    {
        return $this->db->transaction(function () use ($caller, $suppliers, $fields): array {
            $this->poster($caller);
            $s = $suppliers->create($caller, $fields);
            return ['supplier' => $s, 'document' => $this->createDraft($caller, (int) $s['id'], [])];
        });
    }

    /**
     * "+ Add new supplier" on the order's page before the order exists (PO on one page, docs/decisions.md PX3): the supplier alone, made as
     * the supplier screens make one (Suppliers::create: a DRAFT supplier, suppliers.manage, its own checks and audit), for a person who
     * writes purchase orders (doc.PO.post). No order is started: the page comes back with the supplier chosen, and the order is made by its
     * first product or Save.
     *
     * @param array<string, mixed> $fields Suppliers::FIELDS (name required)
     * @return array<string, mixed> the supplier row
     */
    public function newSupplier(Caller $caller, Suppliers $suppliers, array $fields): array
    {
        return $this->db->transaction(function () use ($caller, $suppliers, $fields): array {
            $this->poster($caller);
            return $suppliers->create($caller, $fields);
        });
    }

    /**
     * "Add a new supplier" on a draft: the supplier is made as the supplier screens make one (a DRAFT supplier) and put on this draft at
     * once, in ONE transaction. Anyone who writes orders, at $version (the draft's row is locked first, then the supplier is written: the order of
     * saveDraft). The draft is saved with $header and $lines as typed on the page with it (PO on one page, PX3); its lines move to the new
     * supplier as any change of supplier moves them (saveDraft, docs/decisions.md KG30: a new supplier sells none of them yet, so each
     * stays a line of its item, its price kept).
     *
     * @param array<string, mixed> $fields Suppliers::FIELDS (name required)
     * @param array<string, mixed> $header saveDraft's header (its supplier_id is the new supplier's)
     * @param list<array<string, mixed>>|null $lines saveDraft's lines (null: the draft's own)
     * @return array{supplier: array<string, mixed>, document: Document}
     */
    public function setNewSupplier(Caller $caller, Suppliers $suppliers, int $id, int $version, array $fields, array $header = [], ?array $lines = null): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $suppliers, $id, $version, $fields, $header, $lines): array {
            $this->poster($caller);
            $this->lockDraft($id, $version);
            $s = $suppliers->create($caller, $fields);
            return ['supplier' => $s, 'document' => $this->saveDraft($caller, $id, $version, ['supplier_id' => (int) $s['id']] + $header, $lines ?? $this->lines($id))];
        });
    }

    /**
     * "+ Add new item"'s item and supply, without a line (addNewItem's first half, shared with the lines' grid's "+ Add new item" by the
     * script, docs/decisions.md KG21): Pack B's item (HandMade::create: items.create, a barcode on another item 409, the same name 409 with
     * the items there), then $supplierId's pack of it when a code, a pack or a price is typed (SupplierItems::create, preferred when the
     * item has none). One transaction (the caller's when there is one). The caller checks who may (its route's permission).
     *
     * @param array<string, mixed> $item HandMade::create's input
     * @param array<string, mixed> $supply supplier_code, units_per_pack, pack_price, purchase_unit
     * @return array{made: array<string, mixed>, supplier_item: ?array<string, mixed>}
     */
    public function makeItem(Caller $caller, int $supplierId, HandMade $hand, array $item, array $supply): array
    {
        return $this->db->transaction(function () use ($caller, $supplierId, $hand, $item, $supply): array {
            $made = $hand->create($caller, $item);
            $sku = (int) $made['sku_id'];
            $code = $supply['supplier_code'] ?? null;
            $code = $code === null || trim($code) === '' ? null : trim($code);
            $upp = (int) ($supply['units_per_pack'] ?? 1);
            $price = $supply['pack_price'] ?? null;
            $price = $price === null || trim($price) === '' ? null : trim($price);
            $si = null;
            if ($code !== null || $price !== null || $upp > 1) {
                $unit = $supply['purchase_unit'] ?? null;
                $si = $this->supplierItems->create($caller, $supplierId, $sku, ['supplier_code' => $code, 'units_per_pack' => (string) $upp,
                    'purchase_unit' => $unit === null || trim($unit) === '' ? ($upp > 1 ? 'case' : 'each') : trim($unit), 'is_preferred' => SupplierItems::PREFERRED_AUTO],
                    $price === null ? null : ['pack_price' => $price]);
            }
            return ['made' => $made, 'supplier_item' => $si];
        });
    }

    /**
     * "Add a new item" on a draft's line, in ONE transaction: the draft locked first (at $version), then the item made by hand
     * with Pack B's rules (HandMade::create: items.create; a barcode on another item refused, 409 barcode_on_other_item; the same name
     * and identity refused unless `anyway`, 409 same_item_exists), then, when a supplier code, a price or a pack of more than 1 is
     * given, this supplier's supplier item for it (SupplierItems::create: suppliers.manage; preferred when the item has no main
     * supply yet; the price recorded as its first price), and last the line ($packs packs; addResolved, as a scan). Audit po.new_item
     * besides the services' own rows.
     *
     * @param array<string, mixed> $item HandMade::create's input (card, barcode, barcode_units, anyway)
     * @param array{supplier_code?: ?string, units_per_pack?: int, purchase_unit?: ?string, pack_price?: ?string} $supply
     * @return array{status: 'added'|'incremented', document: Document, line_no: int, sku_id: int, code: string, supplier_item_id: ?int}
     */
    public function addNewItem(Caller $caller, int $id, int $version, HandMade $hand, array $item, array $supply, int $packs): array
    {
        if ($packs < 1 || $packs > PoMath::MAX_PACKS) {
            throw new CwException('bad_field', 'packs: a whole number from 1 to ' . PoMath::MAX_PACKS, 422, ['field' => 'packs']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $hand, $item, $supply, $packs): array {
            $this->poster($caller);
            $this->lockDraft($id, $version);
            $supplierId = (int) $this->poRow($id)['supplier_id'];
            $x = $this->makeItem($caller, $supplierId, $hand, $item, $supply);
            $made = $x['made'];
            $sku = (int) $made['sku_id'];
            $si = $x['supplier_item'];
            $r = $si !== null ? self::found($sku, $si) : ['status' => 'found', 'sku_id' => $sku, 'supplier_item' => null, 'units_per_pack' => 1, 'purchase_unit' => 'each'];
            $res = $this->addResolved($caller, $id, $version, $r, $packs);
            Audit::write($db, $caller, 'po.new_item', 'document', (string) $id, null, ['sku_id' => $sku, 'code' => $made['code'],
                'supplier_item_id' => $si === null ? null : (int) $si['id'], 'packs' => $packs, 'line_no' => $res['line_no']]);
            return $res + ['sku_id' => $sku, 'code' => (string) $made['code'], 'supplier_item_id' => $si === null ? null : (int) $si['id']];
        });
    }

    // ------------------------------------------------------------------------------------------
    // Each box saved by itself (the live drafts plan, step 4; docs/decisions.md LS1-LS14)
    // ------------------------------------------------------------------------------------------

    /** What a line's boxes change in place (setLine): its packs, its pack's price, its items per pack, its product. */
    public const LIVE_FIELDS = ['packs', 'pack_price', 'units_per_pack', 'supplier_item_id', 'sku_id'];
    /** The head's boxes saved by themselves (setHeaderField); the supplier and the order date are whole saves. */
    public const LIVE_HEADER = ['expected_date', 'external_ref', 'note'];
    /** The same product added again by someone else within this many seconds of their change is asked first (line_exists). */
    public const SAME_PRODUCT_SECONDS = 120;

    /**
     * One line's boxes saved (docs/decisions.md LS3; the plan's 4.2): the line by its own id, at the version the page saw ($rev), with the boxes
     * changed ($fields: LIVE_FIELDS; 400 bad_field for another). Checked by normaliseLines() as a whole save checks a line (the same pack
     * and money rules: one line through the same code), then:
     *  - its values as they are stored already: 200 `unchanged`, nothing written (a retry, two people typing the same figure);
     *  - the line changed since the page saw it: 409 line_conflict (its version now, who changed it and when, their values) and a
     *    draft_change `conflict` (the measure); every other line is untouched;
     *  - no longer on the draft: 409 line_gone (who removed it and when, its product);
     *  - otherwise in place: the document line (its version + 1: Documents::changeLine), its po_line, the totals over every line; the
     *    draft's version + 1; a draft_change `set`; the hour's audit row (LiveChanges::record).
     * A product changed (supplier_item_id, or sku_id with its items per pack) keeps the line, its place and its id; its price is the new pack's
     * last price unless one is given. Anyone who writes orders (DA1), checked again (poster()); 409 not_draft once it is not a draft.
     *
     * @param array<string, mixed> $fields
     * @return array{status: 'saved'|'unchanged', document: Document, uid: int, rev: int}
     */
    public function setLine(Caller $caller, int $id, int $uid, int $rev, array $fields, ?string $tab = null): array
    {
        foreach (array_keys($fields) as $k) {
            if (!in_array($k, self::LIVE_FIELDS, true)) {
                throw new CwException('bad_field', 'a line saved by itself has ' . implode(', ', self::LIVE_FIELDS) . ', not ' . mb_substr((string) $k, 0, 40), 400,
                    ['field' => mb_substr((string) $k, 0, 40)]);
            }
        }
        try {
            return $this->db->transaction(function (Db $db) use ($caller, $id, $uid, $rev, $fields, $tab): array {
                $this->poster($caller);
                $supplier = $this->lockLive($id);
                $cur = $this->liveLine($id, $uid) ?? throw $this->gone($id, $uid);
                $line = self::asLine($cur);
                if (array_key_exists('supplier_item_id', $fields) || array_key_exists('sku_id', $fields)) {
                    // Another product on this line (KG22 in place): the new pack's code, unit and price, unless a price is given.
                    unset($line['supplier_item_id'], $line['sku_id'], $line['supplier_code'], $line['purchase_unit'], $line['units_per_pack']);
                    $line['pack_price'] = null;
                    if (($fields['supplier_item_id'] ?? null) !== null) {
                        $line['supplier_item_id'] = $fields['supplier_item_id'];
                    } else {
                        $line['sku_id'] = $fields['sku_id'] ?? null;
                    }
                }
                foreach (['packs', 'pack_price', 'units_per_pack'] as $k) {
                    if (array_key_exists($k, $fields)) {
                        $line[$k] = $fields[$k];
                    }
                }
                $n = $this->normaliseLines($caller, $supplier, [$line], (int) $cur['line_no'] - 1)[0];
                if (self::contentKey($n['po'], $n['doc']['sku_id'] ?? null, $n['doc']['description'] ?? null) === self::rowKey($cur)) {
                    return ['status' => 'unchanged', 'document' => $this->docs->get($id), 'uid' => $uid, 'rev' => (int) $cur['rev']];
                }
                if ((int) $cur['rev'] !== $rev) {
                    throw $this->lineConflict($cur);
                }
                $doc = $this->docs->changeLine($caller, $id, $uid, $rev, $n['doc']);
                $this->writePoLine($id, (int) $cur['line_no'], $n['po']);
                $this->retotal($id);
                LiveChanges::record($db, $caller, 'po', $id, 'set', $uid, null, self::change($cur), self::change($this->liveLine($id, $uid) ?? $cur), null, $doc->version, $tab,
                    $this->nowDb());
                return ['status' => 'saved', 'document' => $doc, 'uid' => $uid, 'rev' => $rev + 1];
            });
        } catch (CwException $e) {
            if ($e->errorCode === 'line_conflict') {
                LiveChanges::conflict($this->db, $caller, 'po', $id, $uid, null, ['rev' => $rev] + $fields, $tab, $this->nowDb());
            }
            throw $e;
        }
    }

    /**
     * A product added from the page by itself (LS3): `$pick` is one of the supplier's packs (supplier_item_id), an item not set up with the
     * supplier (sku_id, units_per_pack) or a charge (kind charge, description: the delivery typed); $price as typed (null: the pack's last).
     * The page's key ($opKey: 400 bad_key) makes a retry do nothing (`done`, the line it made). The same purchase already on the draft
     * (sameLine) gets the packs added (today's rule: `incremented`), unless someone else added or changed that line within
     * SAME_PRODUCT_SECONDS and the page did not ask for more ($more): 409 line_exists (their line, packs, who and when), never a silent double.
     * A new line goes last (Documents::appendLine: the next free id, version 1). At most MAX_LINES lines.
     *
     * @param array<string, mixed> $pick
     * @return array{status: 'added'|'incremented'|'done', document: Document, uid: ?int}
     */
    public function addLineLive(Caller $caller, int $id, array $pick, int $packs, ?string $price, string $opKey, bool $more = false, ?string $tab = null): array
    {
        $opKey = LiveChanges::key($opKey);
        $charge = ($pick['kind'] ?? 'item') === 'charge';
        if (!$charge && ($packs < 1 || $packs > PoMath::MAX_PACKS)) {
            throw new CwException('bad_line', 'packs: a whole number from 1 to ' . number_format(PoMath::MAX_PACKS), 422, ['field' => 'packs']);
        }
        $new = $charge
            ? ['kind' => 'charge', 'description' => is_string($pick['description'] ?? null) ? $pick['description'] : null, 'pack_price' => $price]
            : ['kind' => 'item'] + (($pick['supplier_item_id'] ?? null) !== null ? ['supplier_item_id' => $pick['supplier_item_id']]
                : ['sku_id' => $pick['sku_id'] ?? null, 'units_per_pack' => $pick['units_per_pack'] ?? null]) + ['packs' => $packs, 'pack_price' => $price];
        return $this->db->transaction(function (Db $db) use ($caller, $id, $new, $packs, $opKey, $more, $tab, $charge): array {
            $me = $this->poster($caller);
            $supplier = $this->lockLive($id);
            $done = LiveChanges::done($db, 'po', $id, $opKey);
            if ($done !== null) {
                return self::replayed($done, $this->docs->get($id));
            }
            $count = (int) $db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$id]);
            $n = $this->normaliseLines($caller, $supplier, [$new], $count)[0];
            if (!$charge) {
                $same = $this->sameOnDraft($id, $n['po'], $n['doc']['sku_id']);
                if ($same !== null) {
                    return $this->addTo($caller, $me['id'], $id, $same, $packs, $more, $supplier, 'add', $opKey, null, $tab);
                }
            }
            return $this->appendLive($caller, $id, $n, 'add', $opKey, null, $tab);
        });
    }

    /**
     * One line removed from the page by itself (LS3), at the version the page saw: changed since: 409 line_conflict ("Remove it anyway" sends
     * the version now); no longer there (someone removed it too): 200 `unchanged`, done once. The lines are written again in their order
     * without it (Documents::replaceLinesLive: the others keep their ids and versions, the numbers follow), the totals; a draft_change
     * `remove` holding the line as it was (Undo puts it back from there).
     *
     * @return array{status: 'removed'|'unchanged', document: Document, uid: int}
     */
    public function removeLine(Caller $caller, int $id, int $uid, int $rev, ?string $tab = null): array
    {
        try {
            return $this->db->transaction(function (Db $db) use ($caller, $id, $uid, $rev, $tab): array {
                $this->poster($caller);
                $supplier = $this->lockLive($id);
                $cur = $this->liveLine($id, $uid);
                if ($cur === null) {
                    return ['status' => 'unchanged', 'document' => $this->docs->get($id), 'uid' => $uid];
                }
                if ((int) $cur['rev'] !== $rev) {
                    throw $this->lineConflict($cur);
                }
                $lines = array_values(array_filter($this->lines($id), static fn (array $l): bool => $l['uid'] !== $uid));
                $doc = $this->rewrite($caller, $id, $supplier, $lines);
                LiveChanges::record($db, $caller, 'po', $id, 'remove', $uid, null, self::change($cur, true), null, null, $doc->version, $tab, $this->nowDb());
                return ['status' => 'removed', 'document' => $doc, 'uid' => $uid];
            });
        } catch (CwException $e) {
            if ($e->errorCode === 'line_conflict') {
                LiveChanges::conflict($this->db, $caller, 'po', $id, $uid, null, ['rev' => $rev, 'remove' => true], $tab, $this->nowDb());
            }
            throw $e;
        }
    }

    /**
     * Undo (Alt/Option+U, LS8): puts back the caller's OWN line $uid removed from this draft (their newest `remove` of it: 409
     * nothing_to_restore for a line someone else removed, or one put back already), as it was, with a new id: after the line $afterUid when
     * that is still there, else at its old place as near as the lines left allow. The page's key: a retry does nothing (`done`). The same
     * purchase added meanwhile: the question of addLineLive (409 line_exists; $more: its packs added, `incremented`).
     *
     * @return array{status: 'restored'|'incremented'|'done', document: Document, uid: ?int}
     */
    public function restoreLine(Caller $caller, int $id, int $uid, ?int $afterUid, string $opKey, bool $more = false, ?string $tab = null): array
    {
        $opKey = LiveChanges::key($opKey);
        return $this->db->transaction(function (Db $db) use ($caller, $id, $uid, $afterUid, $opKey, $more, $tab): array {
            $me = $this->poster($caller);
            $supplier = $this->lockLive($id);
            $done = LiveChanges::done($db, 'po', $id, $opKey);
            if ($done !== null) {
                return self::replayed($done, $this->docs->get($id));
            }
            $removed = $db->one("SELECT id, CAST(before_json AS CHAR) AS b FROM draft_change WHERE kind = 'po' AND record_id = ? AND op = 'remove' AND line_uid = ? "
                . 'AND staff_user_id = ? ORDER BY id DESC LIMIT 1', [$id, $uid, $me['id']]);
            $was = $removed === null ? null : json_decode((string) $removed['b'], true);
            $back = $removed === null ? null : $db->value("SELECT 1 FROM draft_change WHERE kind = 'po' AND record_id = ? AND op = 'restore' AND id > ? "
                . "AND CAST(JSON_UNQUOTE(JSON_EXTRACT(before_json, '$.from_uid')) AS UNSIGNED) = ? LIMIT 1", [$id, (int) $removed['id'], $uid]);
            if (!is_array($was) || $back !== null || $this->liveLine($id, $uid) !== null) {
                throw new CwException('nothing_to_restore', 'only a line you removed, and not put back yet, is put back', 409, ['uid' => $uid]);
            }
            $line = array_intersect_key($was, array_flip(['kind', 'supplier_item_id', 'sku_id', 'supplier_code', 'purchase_unit', 'units_per_pack', 'packs', 'pack_price',
                'vat_code', 'description', 'suggested_units']));
            if (($line['supplier_item_id'] ?? null) !== null) {
                unset($line['sku_id'], $line['units_per_pack'], $line['supplier_code'], $line['purchase_unit']);
            }
            $lines = $this->lines($id);
            $n = $this->normaliseLines($caller, $supplier, [$line], count($lines))[0];
            if ($n['po']['kind'] === 'item') {
                $same = $this->sameOnDraft($id, $n['po'], $n['doc']['sku_id']);
                if ($same !== null) {
                    return $this->addTo($caller, $me['id'], $id, $same, (int) $n['po']['packs'], $more, $supplier, 'restore', $opKey, ['from_uid' => $uid], $tab);
                }
            }
            $uids = array_column($lines, 'uid');
            $at = $afterUid !== null && in_array($afterUid, $uids, true) ? (int) array_search($afterUid, $uids, true) + 1
                : min(max(0, (int) ($was['line'] ?? 1) - 1), count($lines));
            array_splice($lines, $at, 0, [$line]);
            $doc = $this->rewrite($caller, $id, $supplier, $lines);
            $newUid = (int) $db->value('SELECT uid FROM document_line WHERE document_id = ? AND line_no = ?', [$id, $at + 1]);
            LiveChanges::record($db, $caller, 'po', $id, 'restore', $newUid, null, ['from_uid' => $uid], self::change($this->liveLine($id, $newUid) ?? []), $opKey,
                $doc->version, $tab, $this->nowDb());
            return ['status' => 'restored', 'document' => $doc, 'uid' => $newUid, 'rev' => 1];
        });
    }

    /**
     * One head box saved by itself (LS4): expected_date, external_ref or note (LIVE_HEADER; 400 bad_field for another: the supplier and the
     * order date are whole saves), checked as a whole save checks it. $base: the value the page showed. The value stored already: 200
     * `unchanged`; the stored value no longer $base: 409 field_conflict (the value now, who changed it and when) and a draft_change
     * `conflict`; otherwise saved (the draft's version + 1) with a draft_change `header`.
     *
     * @return array{status: 'saved'|'unchanged', document: Document, value: ?string} value: what the box holds as stored by (or found at) this save
     */
    public function setHeaderField(Caller $caller, int $id, string $field, ?string $base, ?string $value, ?string $tab = null): array
    {
        if (!in_array($field, self::LIVE_HEADER, true)) {
            throw new CwException('bad_field', 'a box saved by itself is ' . implode(', ', self::LIVE_HEADER) . ', not ' . mb_substr($field, 0, 40), 400,
                ['field' => mb_substr($field, 0, 40)]);
        }
        try {
            return $this->db->transaction(function (Db $db) use ($caller, $id, $field, $base, $value, $tab): array {
                $this->poster($caller);
                $this->lockLive($id);
                $want = $this->headerFields([$field => $value], true)[$field] ?? null;
                try {
                    $seen = $this->headerFields([$field => $base], true)[$field] ?? null;
                } catch (CwException) {
                    $seen = false; // the page showed no value CW could store: never the value now
                }
                $now = $field === 'expected_date' ? $db->value('SELECT expected_date FROM purchase_order WHERE document_id = ?', [$id])
                    : $db->value("SELECT {$field} FROM document WHERE id = ?", [$id]);
                $now = $now === null ? null : (string) $now;
                if ($want === $now) {
                    return ['status' => 'unchanged', 'document' => $this->docs->get($id), 'value' => $now];
                }
                if ($seen !== $now) {
                    $by = $db->one("SELECT c.staff_user_id, c.created_at, u.display_name, CAST(c.after_json AS CHAR) AS a FROM draft_change c LEFT JOIN staff_user u "
                        . "ON u.id = c.staff_user_id WHERE c.kind = 'po' AND c.record_id = ? AND c.op = 'header' AND c.field = ? ORDER BY c.id DESC LIMIT 1", [$id, $field]);
                    // That box's own last change names who, while it still holds the value now (a whole save may have changed it since: then
                    // who saved the draft last; the Codex review of b40f96a, a4).
                    if ($by !== null && (json_decode((string) $by['a'], true)['value'] ?? null) !== $now) {
                        $by = null;
                    }
                    $by ??= ($last = DraftChanges::last($db, $id)) === null ? null : ['staff_user_id' => $last['staff_user_id'], 'display_name' => $last['name'], 'created_at' => $last['at']];
                    throw new CwException('field_conflict', "{$field} changed since the page was drawn", 409, ['field' => $field, 'theirs' => $now,
                        'by_id' => $by === null ? null : (int) $by['staff_user_id'], 'by' => $by === null ? null : (string) $by['display_name'],
                        'at' => $by === null ? null : (string) $by['created_at']]);
                }
                if ($field === 'expected_date') {
                    $db->exec('UPDATE purchase_order SET expected_date = ?, updated_at = ? WHERE document_id = ?', [$want, $this->nowDb(), $id]);
                    $doc = $this->docs->changeHeaderLive($caller, $id, []);
                } else {
                    $doc = $this->docs->changeHeaderLive($caller, $id, [$field => $want]);
                    $stored = $field === 'note' ? $doc->note : $doc->externalRef;
                    $want = $stored === null || $stored === '' ? null : $stored;
                }
                LiveChanges::record($db, $caller, 'po', $id, 'header', null, $field, ['value' => $now], ['value' => $want], null, $doc->version, $tab, $this->nowDb());
                return ['status' => 'saved', 'document' => $doc, 'value' => $want];
            });
        } catch (CwException $e) {
            if ($e->errorCode === 'field_conflict') {
                LiveChanges::conflict($this->db, $caller, 'po', $id, null, $field, ['base' => $base, 'value' => $value], $tab, $this->nowDb());
            }
            throw $e;
        }
    }

    /**
     * A draft's lines for the page that saves box by box (the fragment po_live, LS10): each line's own id, version and who changed it last.
     *
     * @return array<int, array{uid: ?int, rev: int, changed_by: ?int, changed_at: ?string, by: ?string}> line_no => its own marks
     */
    public function lineMarks(int $id): array
    {
        $out = [];
        foreach ($this->db->all('SELECT dl.line_no, dl.uid, dl.rev, dl.changed_by, dl.changed_at, u.display_name FROM document_line dl LEFT JOIN staff_user u ON u.id = dl.changed_by '
            . 'WHERE dl.document_id = ? ORDER BY dl.line_no', [$id]) as $r) {
            $out[(int) $r['line_no']] = ['uid' => $r['uid'] === null ? null : (int) $r['uid'], 'rev' => (int) $r['rev'], 'changed_by' => $r['changed_by'] === null ? null
                : (int) $r['changed_by'], 'changed_at' => $r['changed_at'] === null ? null : (string) $r['changed_at'], 'by' => $r['display_name'] === null ? null : (string) $r['display_name']];
        }
        return $out;
    }

    /** Whether live.mode is "on" (each box saves by itself: the whole saves record their own line-by-line change too). */
    private function liveOn(): bool
    {
        return Presence::settings($this->db)['mode'] === 'on';
    }

    /**
     * The draft's row X-locked first (§6.9), 409 not_draft (what it became, its number, who) otherwise; the supplier FOR SHARE; the
     * purchase_order row FOR UPDATE (the lock order of saveDraft).
     *
     * @return array<string, mixed> the supplier row
     */
    private function lockLive(int $id): array
    {
        $row = $this->lockPo($id);
        if ($row['status'] !== 'draft') {
            throw Documents::notDraft($this->db, $row);
        }
        $sid = (int) $this->poRow($id)['supplier_id'];
        $supplier = $this->db->one('SELECT * FROM supplier WHERE id = ? FOR SHARE', [$sid]) ?? throw new \LogicException('PO without supplier');
        $this->db->one('SELECT document_id FROM purchase_order WHERE document_id = ? FOR UPDATE', [$id]);
        return $supplier;
    }

    /** @return array<string, mixed>|null a line by its own id: its document line, its po_line and its product's name (the draft row is locked) */
    private function liveLine(int $id, int $uid): ?array
    {
        return $this->db->one('SELECT dl.line_no, dl.uid, dl.rev, dl.changed_by, dl.changed_at, dl.sku_id, dl.qty, dl.unit_cost, dl.amount, dl.description, pl.kind, '
            . 'pl.supplier_item_id, pl.supplier_code, pl.purchase_unit, pl.units_per_pack, pl.packs, pl.pack_price, pl.vat_code, pl.vat_rate, pl.suggested_units, s.name AS sku_name, '
            . 'u.display_name AS by_name FROM document_line dl JOIN po_line pl ON pl.document_id = dl.document_id AND pl.line_no = dl.line_no LEFT JOIN sku s ON s.id = dl.sku_id '
            . 'LEFT JOIN staff_user u ON u.id = dl.changed_by WHERE dl.document_id = ? AND dl.uid = ?', [$id, $uid]);
    }

    /**
     * A line as saveDraft() takes it, from liveLine().
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function asLine(array $r): array
    {
        if ($r['kind'] === 'charge') {
            return ['kind' => 'charge', 'description' => $r['description'], 'pack_price' => (string) $r['pack_price'], 'vat_code' => (string) $r['vat_code']];
        }
        $l = ['kind' => 'item', 'packs' => (int) $r['packs'], 'pack_price' => (string) $r['pack_price'], 'vat_code' => (string) $r['vat_code'], 'description' => $r['description'],
            'suggested_units' => $r['suggested_units'] === null ? null : (int) $r['suggested_units']];
        if ($r['supplier_item_id'] !== null) {
            return $l + ['supplier_item_id' => (int) $r['supplier_item_id']];
        }
        return $l + ['sku_id' => (int) $r['sku_id'], 'units_per_pack' => (int) $r['units_per_pack'], 'supplier_code' => $r['supplier_code'], 'purchase_unit' => (string) $r['purchase_unit']];
    }

    /**
     * What a line is, for the line-by-line changes and the refusals (packs, price, items per pack, the product; $all: enough to put it back).
     *
     * @param array<string, mixed> $r liveLine()
     * @return array<string, mixed>
     */
    private static function change(array $r, bool $all = false): array
    {
        if ($r === []) {
            return [];
        }
        $out = ['line' => (int) $r['line_no'], 'kind' => (string) $r['kind'], 'packs' => (int) $r['packs'], 'pack_price' => (string) $r['pack_price'],
            'units_per_pack' => (int) $r['units_per_pack'], 'purchase_unit' => (string) $r['purchase_unit'], 'supplier_item_id' => $r['supplier_item_id'] === null ? null : (int) $r['supplier_item_id'],
            'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'], 'name' => (string) ($r['kind'] === 'charge' ? ($r['description'] ?? '') : ($r['sku_name'] ?? ''))];
        if ($all) {
            $out += ['vat_code' => (string) $r['vat_code'], 'description' => $r['description'], 'supplier_code' => $r['supplier_code'],
                'suggested_units' => $r['suggested_units'] === null ? null : (int) $r['suggested_units']];
        }
        return $out;
    }

    /** @param array<string, mixed> $cur liveLine() */
    private function lineConflict(array $cur): CwException
    {
        return new CwException('line_conflict', 'line ' . $cur['line_no'] . ' changed since the page was drawn', 409, ['uid' => (int) $cur['uid'], 'rev' => (int) $cur['rev'],
            'line' => (int) $cur['line_no'], 'by_id' => $cur['changed_by'] === null ? null : (int) $cur['changed_by'], 'by' => $cur['by_name'] === null ? null : (string) $cur['by_name'],
            'at' => $cur['changed_at'] === null ? null : (string) $cur['changed_at'], 'theirs' => self::change($cur)]);
    }

    private function gone(int $id, int $uid): CwException
    {
        $r = $this->db->one("SELECT c.staff_user_id, c.created_at, CAST(c.before_json AS CHAR) AS b, u.display_name FROM draft_change c LEFT JOIN staff_user u ON u.id = c.staff_user_id "
            . "WHERE c.kind = 'po' AND c.record_id = ? AND c.op = 'remove' AND c.line_uid = ? ORDER BY c.id DESC LIMIT 1", [$id, $uid]);
        $was = $r === null ? null : json_decode((string) $r['b'], true);
        return new CwException('line_gone', "line id {$uid} is no longer on this draft", 409, ['uid' => $uid, 'by_id' => $r === null ? null : (int) $r['staff_user_id'],
            'by' => $r === null ? null : (string) $r['display_name'], 'at' => $r === null ? null : (string) $r['created_at'], 'name' => is_array($was) ? ($was['name'] ?? null) : null,
            'was' => is_array($was) ? $was : null]);
    }

    /**
     * The line of the draft that is the same purchase as $po (sameLine: the same supplier item, or the same item and pack without one).
     *
     * @param array<string, mixed> $po normaliseLines()'s po part
     * @return array<string, mixed>|null liveLine()
     */
    private function sameOnDraft(int $id, array $po, ?int $skuId): ?array
    {
        $want = ['kind' => 'item', 'supplier_item_id' => $po['supplier_item_id'], 'sku_id' => $skuId, 'units_per_pack' => (int) $po['units_per_pack']];
        foreach ($this->lines($id) as $l) {
            if ($l['uid'] !== null && self::sameLine($l, $want)) {
                return $this->liveLine($id, $l['uid']);
            }
        }
        return null;
    }

    /**
     * $packs added to the line $same (an add or an undo of the same purchase): asked first when someone else added or changed it within
     * SAME_PRODUCT_SECONDS and the page did not ask for more (409 line_exists).
     *
     * @param array<string, mixed> $same liveLine()
     * @param array<string, mixed> $supplier
     * @param array<string, mixed>|null $before
     * @return array{status: 'incremented', document: Document, uid: int}
     */
    private function addTo(Caller $caller, int $me, int $id, array $same, int $packs, bool $more, array $supplier, string $op, string $opKey, ?array $before, ?string $tab): array
    {
        $recent = $same['changed_at'] !== null && Clock::fromDb((string) $same['changed_at']) > ($this->clock)()->modify('-' . self::SAME_PRODUCT_SECONDS . ' seconds');
        if (!$more && $same['changed_by'] !== null && (int) $same['changed_by'] !== $me && $recent) {
            throw new CwException('line_exists', 'line ' . $same['line_no'] . ' is this product, added or changed a moment ago by someone else', 409, ['uid' => (int) $same['uid'],
                'line' => (int) $same['line_no'], 'packs' => (int) $same['packs'], 'by_id' => (int) $same['changed_by'], 'by' => $same['by_name'] === null ? null : (string) $same['by_name'],
                'at' => (string) $same['changed_at'], 'name' => (string) ($same['sku_name'] ?? ''), 'pack' => ['purchase_unit' => (string) $same['purchase_unit'],
                    'units_per_pack' => (int) $same['units_per_pack']], 'adding' => $packs]);
        }
        $line = self::asLine($same);
        $line['packs'] = (int) $same['packs'] + $packs;
        $n = $this->normaliseLines($caller, $supplier, [$line], (int) $same['line_no'] - 1)[0];
        $uid = (int) $same['uid'];
        $doc = $this->docs->changeLine($caller, $id, $uid, (int) $same['rev'], $n['doc']);
        $this->writePoLine($id, (int) $same['line_no'], $n['po']);
        $this->retotal($id);
        $rev = (int) $same['rev'] + 1;
        // The version it gave the line is kept with the change: a retry with the same key says it (replayed), so the page saves the packs typed
        // since on it (the Codex verify of be061fd, 1).
        LiveChanges::record($this->db, $caller, 'po', $id, $op, $uid, null, ($before ?? []) + self::change($same), self::change($this->liveLine($id, $uid) ?? $same) + ['rev' => $rev],
            $opKey, $doc->version, $tab, $this->nowDb());
        return ['status' => 'incremented', 'document' => $doc, 'uid' => $uid, 'rev' => $rev];
    }

    /**
     * A new line after the last (Documents::appendLine), its po_line, the totals, the change recorded.
     *
     * @param array{doc: array<string, mixed>, po: array<string, mixed>, calc: array<string, mixed>} $n
     * @param array<string, mixed>|null $before
     * @return array{status: 'added', document: Document, uid: int}
     */
    private function appendLive(Caller $caller, int $id, array $n, string $op, string $opKey, ?array $before, ?string $tab): array
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$id]) >= self::MAX_LINES) {
            throw new CwException('too_many_lines', 'a purchase order has at most ' . self::MAX_LINES . ' lines', 422);
        }
        $r = $this->docs->appendLine($caller, $id, $n['doc']);
        $p = $n['po'];
        $this->db->exec('INSERT INTO po_line (document_id, line_no, kind, supplier_item_id, supplier_code, purchase_unit, units_per_pack, packs, pack_price, vat_code, vat_rate, '
            . 'suggested_units) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$id, $r['line_no'], $p['kind'], $p['supplier_item_id'], $p['supplier_code'], $p['purchase_unit'],
                $p['units_per_pack'], $p['packs'], $p['pack_price'], $p['vat_code'], $p['vat_rate'], $p['suggested_units']]);
        $this->retotal($id);
        LiveChanges::record($this->db, $caller, 'po', $id, $op, $r['uid'], null, $before, self::change($this->liveLine($id, $r['uid']) ?? []), $opKey, $r['document']->version, $tab,
            $this->nowDb());
        return ['status' => 'added', 'document' => $r['document'], 'uid' => $r['uid'], 'rev' => 1];
    }

    /**
     * An add or an undo sent again with its key (its first answer lost): `done`, the line it made or added to, and what it did then (`was`:
     * added, incremented, restored; the version an added or restored line got: 1; the version packs added to a line gave it, kept with the
     * change: null for one recorded before it was kept), so the page treats the replay as it would have treated the first answer (the Codex
     * re-check of c84b4bb; the Codex verify of be061fd, 1).
     *
     * @param array<string, mixed> $done the draft_change row of the key
     * @return array{status: 'done', document: Document, uid: ?int, was: string, rev: ?int}
     */
    private static function replayed(array $done, Document $doc): array
    {
        $before = json_decode((string) ($done['before_json'] ?? 'null'), true);
        $grew = is_array($before) && array_key_exists('packs', $before); // packs added to a line already there
        $after = json_decode((string) ($done['after_json'] ?? 'null'), true);
        $grewTo = is_array($after) && is_int($after['rev'] ?? null) && $after['rev'] > 0 ? $after['rev'] : null;
        $was = $grew ? 'incremented' : ($done['op'] === 'restore' ? 'restored' : 'added');
        return ['status' => 'done', 'document' => $doc, 'uid' => $done['line_uid'] === null ? null : (int) $done['line_uid'], 'was' => $was, 'rev' => $grew ? $grewTo : 1];
    }

    /**
     * Every line written again in this order (a removal, an undo: the numbers follow), as saveDraft() writes them but without the header,
     * the version check or its audit rows (Documents::replaceLinesLive): the ids carried, the versions kept for lines left as they were.
     *
     * @param array<string, mixed> $supplier
     * @param list<array<string, mixed>> $lines
     */
    private function rewrite(Caller $caller, int $id, array $supplier, array $lines): Document
    {
        $norm = $this->normaliseLines($caller, $supplier, $lines);
        $doc = $this->docs->replaceLinesLive($caller, $id, array_map(static fn (array $n): array => $n['doc'], $norm),
            array_map(static fn (array $n): string => self::poKey($n['po']), $norm), $this->oldKeys($id));
        foreach (array_chunk($norm, 500, true) as $chunk) {
            $params = [];
            foreach ($chunk as $i => $n) {
                $p = $n['po'];
                array_push($params, $id, $i + 1, $p['kind'], $p['supplier_item_id'], $p['supplier_code'], $p['purchase_unit'], $p['units_per_pack'], $p['packs'],
                    $p['pack_price'], $p['vat_code'], $p['vat_rate'], $p['suggested_units']);
            }
            $this->db->exec('INSERT INTO po_line (document_id, line_no, kind, supplier_item_id, supplier_code, purchase_unit, units_per_pack, packs, pack_price, vat_code, '
                . 'vat_rate, suggested_units) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
        }
        $this->retotal($id);
        return $doc;
    }

    /** @param array<string, mixed> $p normaliseLines()'s po part */
    private function writePoLine(int $id, int $lineNo, array $p): void
    {
        $this->db->exec('UPDATE po_line SET kind = ?, supplier_item_id = ?, supplier_code = ?, purchase_unit = ?, units_per_pack = ?, packs = ?, pack_price = ?, vat_code = ?, '
            . 'vat_rate = ?, suggested_units = ? WHERE document_id = ? AND line_no = ?', [$p['kind'], $p['supplier_item_id'], $p['supplier_code'], $p['purchase_unit'],
                $p['units_per_pack'], $p['packs'], $p['pack_price'], $p['vat_code'], $p['vat_rate'], $p['suggested_units'], $id, $lineNo]);
    }

    /** The totals of the order over every line (PoMath::totals, as saveDraft): always the sum of the lines. */
    private function retotal(int $id): void
    {
        $t = PoMath::totals(array_map(static fn (array $r): array => ['amount_e2' => PoMath::e2((string) $r['amount']), 'vat_code' => (string) $r['vat_code'],
            'rate_e2' => PoMath::e2((string) $r['vat_rate'])], $this->db->all('SELECT dl.amount, pl.vat_code, pl.vat_rate FROM po_line pl JOIN document_line dl '
            . 'ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no WHERE pl.document_id = ? ORDER BY pl.line_no', [$id])));
        $this->db->exec('UPDATE purchase_order SET net_total = ?, vat_total = ?, gross_total = ?, updated_at = ? WHERE document_id = ?',
            [PoMath::fromE2($t['net_e2']), PoMath::fromE2($t['vat_e2']), PoMath::fromE2($t['gross_e2']), $this->nowDb(), $id]);
    }

    /**
     * The order's own part of a line's content (po_line), as one text: what Documents::setLines compares, with the document line, to keep a
     * line's version (LS2).
     *
     * @param array<string, mixed> $p normaliseLines()'s po part, or a po_line row
     */
    private static function poKey(array $p): string
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        return json_encode([(string) $p['kind'], $int($p['supplier_item_id']), $p['supplier_code'] === null ? null : (string) $p['supplier_code'], (string) $p['purchase_unit'],
            (int) $p['units_per_pack'], (int) $p['packs'], PoMath::e4((string) $p['pack_price']), (string) $p['vat_code'], PoMath::e2((string) $p['vat_rate']),
            $int($p['suggested_units'])], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $po @return string a line's whole content: its po_line, its item and its note */
    private static function contentKey(array $po, mixed $skuId, mixed $description): string
    {
        return self::poKey($po) . '|' . ($skuId === null ? '' : (int) $skuId) . '|' . ($description === null ? '' : (string) $description);
    }

    /** @param array<string, mixed> $r liveLine() */
    private static function rowKey(array $r): string
    {
        return self::contentKey($r, $r['sku_id'], $r['description']);
    }

    /** @return array<int, string> the order's own part of each line's content, by the line's own id (before a whole save) */
    private function oldKeys(int $id): array
    {
        $out = [];
        foreach ($this->db->all('SELECT dl.uid, pl.* FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no '
            . 'WHERE pl.document_id = ? AND dl.uid IS NOT NULL', [$id]) as $r) {
            $out[(int) $r['uid']] = self::poKey($r);
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // After the draft
    // ------------------------------------------------------------------------------------------

    /** Approves (posts) a draft: Documents::post (numbered, or waiting for the blocking approval above the value limit). */
    public function approve(Caller $caller, int $id, int $version): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version): Document {
            $this->poster($caller);
            $row = $this->lockPo($id);
            if ($row['status'] !== 'draft') {
                throw new CwException('not_draft', "{$this->label($row)} is " . str_replace('_', ' ', (string) $row['status']) . ': only a draft is approved', 409);
            }
            return $this->docs->post($caller, $id, $version);
        });
    }

    /**
     * Why a PO should not go to the supplier yet (review finding, I86): its review was rejected (the buyer cancels or amends
     * it), the company details it was approved with are not confirmed (its PDF says "do not send"), or they carry a change of
     * the company details that a reviewer rejected after the order was approved (I98: its PDF says so too). markSent()
     * refuses while there are any, unless the person acknowledges them.
     *
     * @return list<string>
     */
    public function sendWarnings(int $id): array
    {
        $r = $this->db->one('SELECT d.review_state, p.company_snapshot FROM document d JOIN purchase_order p ON p.document_id = d.id WHERE d.id = ?', [$id]);
        if ($r === null) {
            return [];
        }
        $out = [];
        if ($r['review_state'] === 'rejected') {
            $out[] = 'Its review was rejected: cancel or amend it rather than send it.';
        }
        $company = $r['company_snapshot'] === null ? null : json_decode((string) $r['company_snapshot'], true);
        if (!is_array($company) || ($company['confirmed'] ?? false) !== true) {
            $out[] = 'The company details it was approved with are not confirmed: its PDF says "company details not confirmed - do not send".';
        }
        if (is_array($company) && (new CompanyDetails($this->db))->rejectedIn($company) !== null) {
            $out[] = 'The company details it was approved with include a change a reviewer rejected (see Company details): cancel or amend it rather than send it.';
        }
        return $out;
    }

    /**
     * Records that an approved PO went to the supplier (re-sending allowed): state sent, sent_at/by/via/to. When this server
     * has a file store, the PDF as sent is stored (kind generated_pdf) and attached (role generated_pdf); otherwise that is
     * skipped (purchase_order.sent_file_id stays as it was). The document's version moves. Audit po.send. While
     * sendWarnings() lists anything, 409 send_warnings unless $acknowledged (the person ticked "send anyway"; the audit
     * row keeps what they acknowledged; an import of orders ERPNext already sent passes true).
     */
    public function markSent(Caller $caller, int $id, int $version, string $via, ?string $to, bool $acknowledged = false): Document
    {
        if (!isset(self::SEND_VIA[$via])) {
            throw new CwException('bad_field', 'sent via one of ' . implode(', ', array_keys(self::SEND_VIA)), 400, ['field' => 'via']);
        }
        $to = $to === null ? null : trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $to));
        if ($to !== null && (!mb_check_encoding($to, 'UTF-8') || mb_strlen($to) > 191)) {
            throw new CwException('bad_field', 'sent to: at most 191 characters', 400, ['field' => 'to']);
        }
        $to = $to === '' ? null : $to;
        $store = $this->files === null ? null : ($this->files)();
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $via, $to, $store, $acknowledged): Document {
            $me = $this->poster($caller);
            $row = $this->lockPo($id);
            $this->checkVersion($row, $version);
            if ($row['status'] !== 'posted' || $row['reverses_id'] !== null) {
                throw new CwException('not_sendable', "{$this->label($row)} is " . ($row['reverses_id'] !== null ? 'a cancellation' : str_replace('_', ' ', (string) $row['status']))
                    . ': only an approved purchase order is sent', 409);
            }
            $po = $db->one('SELECT * FROM purchase_order WHERE document_id = ? FOR UPDATE', [$id]) ?? throw new \LogicException('PO without header');
            if (!in_array($po['state'], ['approved', 'sent'], true)) {
                throw new CwException('not_sendable', "{$row['number']} is " . str_replace('_', ' ', (string) $po['state']) . ': it is not sent (again)', 409,
                    ['state' => $po['state']]);
            }
            $warnings = $this->sendWarnings($id);
            if ($warnings !== [] && !$acknowledged) {
                throw new CwException('send_warnings', "{$row['number']} is not marked as sent: " . implode(' ', $warnings) . ' Tick "send anyway" to send it all the same.',
                    409, ['warnings' => $warnings]);
            }
            $fileId = null;
            if ($store !== null) {
                $tmp = tempnam(sys_get_temp_dir(), 'cw-po-');
                if ($tmp === false) {
                    throw new \RuntimeException('cannot create a temporary file');
                }
                try {
                    file_put_contents($tmp, (new PurchaseOrderPdf())->render($this->pdfData($id)));
                    $stored = $store->store($caller, $tmp, "{$row['number']}.pdf", 'generated_pdf', "{$row['number']} as sent");
                    $store->attach($caller, $id, $stored['id'], 'generated_pdf');
                    $fileId = $stored['id'];
                } finally {
                    @unlink($tmp);
                }
            }
            $now = $this->nowDb();
            $db->exec("UPDATE purchase_order SET state = 'sent', sent_at = ?, sent_by = ?, sent_via = ?, sent_to = ?, sent_file_id = COALESCE(?, sent_file_id), "
                . 'updated_at = ? WHERE document_id = ?', [$now, $me['id'], $via, $to, $fileId, $now, $id]);
            $db->exec('UPDATE document SET version = version + 1, updated_at = ? WHERE id = ?', [$now, $id]);
            Audit::write($db, $caller, 'po.send', 'document', (string) $id, null, ['number' => $row['number'], 'via' => $via, 'to' => $to,
                'resend' => $po['state'] === 'sent', 'file_id' => $fileId, 'pdf_archived' => $fileId !== null] + ($warnings === [] ? [] : ['acknowledged' => $warnings]));
            return $this->docs->get($id);
        });
    }

    /**
     * Cancels a PO: a draft is cancelled (the reason's label and the note as its cancel reason); an approval request is
     * withdrawn and cancelled by the person who asked for it (others: 409 awaiting_approval); a posted PO is reversed
     * (Documents::reverse: a cancellation document numbered in the PO series, reviewed like a PO; refused with 409
     * po_has_receipts once goods were received: close it instead). The reason is one the Reasons page offers for that kind of
     * cancellation now (REASON_USES: po_draft_cancel before the order is confirmed, po_cancel after; 400 bad_reason), with its note
     * when it needs one (422 note_required). Returns the PO.
     */
    public function cancel(Caller $caller, int $id, int $version, string $reason, ?string $note): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $reason, $note): Document {
            $me = $this->poster($caller);
            $row = $this->lockPo($id);
            $this->checkVersion($row, $version);
            if ($row['reverses_id'] !== null) {
                throw new CwException('not_cancellable', "{$this->label($row)} is itself a cancellation", 409);
            }
            $note = $note === null || trim($note) === '' ? null : trim($note);
            // The reason must be one the Reasons page offers for this kind of cancellation now (I6).
            $use = self::REASON_USES[$row['status'] === 'posted' ? 'cancel' : 'draft_cancel'];
            $r = self::reasonFor($db, $use, $reason, 'choose why the order is cancelled');
            if ((int) $r['needs_note'] === 1 && $note === null) {
                throw new CwException('note_required', 'say why in the note', 422, ['field' => 'note']);
            }
            $text = mb_substr((string) $r['label'] . ($note === null ? '' : ': ' . $note), 0, Documents::NOTE_MAX);
            switch ($row['status']) {
                case 'draft':
                    return $this->docs->cancelDraft($caller, $id, $version, $text);
                case 'awaiting_approval':
                    if ((int) $row['submitted_by'] !== $me['id']) {
                        throw new CwException('awaiting_approval', "{$this->label($row)} waits for a reviewer's approval: only the person who asked for it "
                            . 'withdraws it (or a reviewer rejects it)', 409);
                    }
                    $task = (int) $db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = 'approval' AND state = 'open'", [$id]);
                    $doc = $this->docs->withdraw($caller, $task);
                    return $this->docs->cancelDraft($caller, $id, $doc->version, $text);
                case 'posted':
                    $this->docs->reverse($caller, $id, $reason, $note, $use);
                    return $this->docs->get($id);
                default:
                    throw new CwException('not_cancellable', "{$this->label($row)} is " . str_replace('_', ' ', (string) $row['status']) . ': nothing to cancel', 409);
            }
        });
    }

    /**
     * The reasons the order screens offer for $use (REASON_USES): switched on, not CW's own, placed there on the Reasons page; in
     * the page's order, with the name people read (the reason's own label: the Reasons page renames it).
     *
     * @return list<array{code: string, label: string, needs_note: bool}>
     */
    public static function reasons(Db $db, string $use): array
    {
        $out = [];
        foreach ($db->all('SELECT code, label, needs_note FROM reason_code WHERE FIND_IN_SET(?, applies_to) > 0 AND is_active = 1 AND system_only = 0 '
            . 'ORDER BY sort_order, code', [$use]) as $r) {
            $out[] = ['code' => (string) $r['code'], 'label' => (string) $r['label'], 'needs_note' => (int) $r['needs_note'] === 1];
        }
        return $out;
    }

    /**
     * A reason as the order screens may use it for $use now (400 bad_reason otherwise: unknown, not placed there, switched off, or
     * CW's own). @return array<string, mixed> the reason_code row
     */
    private static function reasonFor(Db $db, string $use, string $code, string $why): array
    {
        $r = $db->one('SELECT code, label, needs_note FROM reason_code WHERE code = ? AND FIND_IN_SET(?, applies_to) > 0 AND is_active = 1 AND system_only = 0',
            [$code, $use]);
        return $r ?? throw new CwException('bad_reason', $why, 400, ['field' => 'reason_code']);
    }

    /**
     * Withdraws the approval request of a PO waiting above the value limit (the requester only): the PO is a draft again.
     */
    public function withdraw(Caller $caller, int $id, int $version): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version): Document {
            $this->poster($caller);
            $row = $this->lockPo($id);
            $this->checkVersion($row, $version);
            if ($row['status'] !== 'awaiting_approval') {
                throw new CwException('not_withdrawable', "{$this->label($row)} does not wait for an approval", 409);
            }
            $task = (int) $db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = 'approval' AND state = 'open'", [$id]);
            return $this->docs->withdraw($caller, $task);
        });
    }

    /**
     * Amends a posted PO: its reversal (reason po_amended unless another is given; refused once goods were received) and a
     * new draft copied from it (source amend, amends_document_id, the original's lines, packs and prices, its supplier quote
     * reference and notes) in ONE transaction. Returns the new draft (its PDF prints "Amends PO-x").
     */
    public function amend(Caller $caller, int $id, string $reason, ?string $note): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $reason, $note): Document {
            $this->poster($caller);
            self::reasonFor($db, self::REASON_USES['amend'], $reason, 'choose why the order is amended');
            $row = $this->lockPo($id);
            if ($row['status'] !== 'posted' || $row['reverses_id'] !== null) {
                throw new CwException('not_amendable', "{$this->label($row)} is " . ($row['reverses_id'] !== null ? 'a cancellation' : str_replace('_', ' ', (string) $row['status']))
                    . ': only an approved purchase order is amended (a draft is simply edited)', 409);
            }
            $po = $this->poRow($id);
            $db->one('SELECT id FROM supplier WHERE id = ? FOR SHARE', [(int) $po['supplier_id']]);
            $lines = $this->copyLines($id, (int) $po['supplier_id']);
            $rev = $this->docs->reverse($caller, $id, $reason, $note, self::REASON_USES['amend']);
            $new = $this->createDraft($caller, (int) $po['supplier_id'], ['external_ref' => $row['external_ref'], 'note' => $row['note'],
                'expected_date' => $po['expected_date']], 'amend', $id);
            $new = $this->saveDraft($caller, $new->id, $new->version, [], $lines);
            Audit::write($db, $caller, 'po.amend', 'document', (string) $id, null, ['number' => $row['number'], 'cancelled_by' => $rev->number, 'new_draft' => $new->id]);
            return $new;
        });
    }

    /** A new draft copied from any PO (a cancellation: from its original): lines, packs and the original prices. */
    public function copy(Caller $caller, int $id): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id): Document {
            $this->poster($caller);
            $src = $this->docs->find($id);
            if ($src === null || $src->docType !== 'PO') {
                throw new CwException('unknown_purchase_order', 'there is no such purchase order', 404);
            }
            if ($src->reversesId !== null) {
                $src = $this->docs->get($src->reversesId);
            }
            $po = $this->poRow($src->id);
            $lines = $this->copyLines($src->id, (int) $po['supplier_id']);
            $new = $this->createDraft($caller, (int) $po['supplier_id'], ['note' => $src->note], 'copy');
            $new = $this->saveDraft($caller, $new->id, $new->version, [], $lines);
            Audit::write($db, $caller, 'po.copy', 'document', (string) $new->id, null, ['from' => $src->id, 'from_label' => $src->label()]);
            return $new;
        });
    }

    /** Closes a part-received PO (the rest is no longer expected; 409 not_closable otherwise). $reason 3-500 characters. */
    public function close(Caller $caller, int $id, int $version, string $reason): Document
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < Documents::NOTE_MIN || mb_strlen($reason) > Documents::NOTE_MAX || !mb_check_encoding($reason, 'UTF-8')) {
            throw new CwException('reason_required', 'say in 3 to 500 characters why the rest is no longer expected', 400, ['field' => 'reason']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $reason): Document {
            $me = $this->poster($caller);
            $row = $this->lockPo($id);
            $this->checkVersion($row, $version);
            $po = $db->one('SELECT state FROM purchase_order WHERE document_id = ? FOR UPDATE', [$id]);
            if ($row['status'] !== 'posted' || $po === null || $po['state'] !== 'part_received') {
                throw new CwException('not_closable', "{$this->label($row)} is " . str_replace('_', ' ', (string) ($po['state'] ?? $row['status']))
                    . ': only a part-received order is closed (cancel one with nothing received)', 409);
            }
            $now = $this->nowDb();
            $db->exec("UPDATE purchase_order SET state = 'closed', closed_at = ?, closed_by = ?, close_reason = ?, updated_at = ? WHERE document_id = ?",
                [$now, $me['id'], $reason, $now, $id]);
            $db->exec('UPDATE document SET version = version + 1, updated_at = ? WHERE id = ?', [$now, $id]);
            Audit::write($db, $caller, 'po.close', 'document', (string) $id, null, ['number' => $row['number'], 'reason' => $reason]);
            return $this->docs->get($id);
        });
    }

    /**
     * Opens a closed order again (review finding 2, pack PO, PO8: "Reopen order"): the rest is expected after all. Its state goes back
     * to what its receipts say (part_received; received when every line is complete), the close is cleared and the reason given is
     * audited (po.reopen, with the close reason it undoes). 409 not_reopenable unless closed; $reason 3-500 characters (400
     * reopen_reason_required); a buyer (doc.PO.post); the version the page was drawn with.
     */
    public function reopen(Caller $caller, int $id, int $version, string $reason): Document
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < Documents::NOTE_MIN || mb_strlen($reason) > Documents::NOTE_MAX || !mb_check_encoding($reason, 'UTF-8')) {
            throw new CwException('reopen_reason_required', 'say in 3 to 500 characters why the rest is expected again', 400, ['field' => 'reason']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $reason): Document {
            $this->poster($caller);
            $row = $this->lockPo($id);
            $this->checkVersion($row, $version);
            $po = $db->one('SELECT state, sent_at, close_reason, closed_at FROM purchase_order WHERE document_id = ? FOR UPDATE', [$id]);
            if ($row['status'] !== 'posted' || $row['reverses_id'] !== null || $po === null || $po['state'] !== 'closed') {
                throw new CwException('not_reopenable', "{$this->label($row)} is " . str_replace('_', ' ', (string) ($po['state'] ?? $row['status']))
                    . ': only a closed order is reopened', 409);
            }
            $any = false;
            $all = true;
            foreach ($db->all("SELECT dl.qty, pl.received_units FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no "
                . "WHERE pl.document_id = ? AND pl.kind = 'item'", [$id]) as $l) {
                $any = $any || (int) $l['received_units'] > 0;
                $all = $all && (int) $l['received_units'] >= (int) $l['qty'];
            }
            $state = self::receiptState($any, $all, $po['sent_at'] !== null);
            $now = $this->nowDb();
            $db->exec('UPDATE purchase_order SET state = ?, closed_at = NULL, closed_by = NULL, close_reason = NULL, updated_at = ? WHERE document_id = ?',
                [$state, $now, $id]);
            $db->exec('UPDATE document SET version = version + 1, updated_at = ? WHERE id = ?', [$now, $id]);
            Audit::write($db, $caller, 'po.reopen', 'document', (string) $id, null, ['number' => $row['number'], 'reason' => $reason,
                'close_reason' => $po['close_reason'], 'closed_at' => $po['closed_at'], 'state' => $state]);
            return $this->docs->get($id);
        });
    }

    /** An order's state from its receipts: every item line complete -> received; some -> part_received; none -> sent or approved. */
    private static function receiptState(bool $any, bool $all, bool $sent): string
    {
        return match (true) {
            $any && $all => 'received',
            $any => 'part_received',
            $sent => 'sent',
            default => 'approved',
        };
    }

    // ------------------------------------------------------------------------------------------
    // Receipts (Phase I-3's GRN calls these inside its posting transaction)
    // ------------------------------------------------------------------------------------------

    /**
     * The item lines of a PO for the I-3 copy-down: ordered, received and outstanding central units, pack and price.
     * $outstandingOnly: only the lines with units still to come. A PO that expects nothing (not posted, or received,
     * closed, cancelled) has no open lines: [].
     *
     * @return list<array{line_no: int, sku_id: int, supplier_item_id: ?int, supplier_code: ?string, purchase_unit: string, units_per_pack: int, packs: int, units_ordered: int, units_received: int, units_outstanding: int, pack_price: string, unit_cost: string, vat_code: string}>
     */
    public function openLines(int $poId, bool $outstandingOnly = true): array
    {
        $open = $this->db->value("SELECT 1 FROM document d JOIN purchase_order po ON po.document_id = d.id WHERE d.id = ? AND d.status = 'posted' "
            . "AND po.state IN ('approved', 'sent', 'part_received')", [$poId]);
        if ($open === null && $outstandingOnly) {
            return [];
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT pl.line_no, dl.sku_id, pl.supplier_item_id, pl.supplier_code, pl.purchase_unit, pl.units_per_pack, pl.packs, dl.qty, pl.received_units, '
            . "pl.pack_price, dl.unit_cost, pl.vat_code FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no "
            . "WHERE pl.document_id = ? AND pl.kind = 'item' ORDER BY pl.line_no",
            [$poId],
        ) as $r) {
            $outstanding = max(0, (int) $r['qty'] - (int) $r['received_units']);
            if ($outstandingOnly && $outstanding === 0) {
                continue;
            }
            $out[] = ['line_no' => (int) $r['line_no'], 'sku_id' => (int) $r['sku_id'], 'supplier_item_id' => $r['supplier_item_id'] === null ? null : (int) $r['supplier_item_id'],
                'supplier_code' => $r['supplier_code'] === null ? null : (string) $r['supplier_code'], 'purchase_unit' => (string) $r['purchase_unit'],
                'units_per_pack' => (int) $r['units_per_pack'], 'packs' => (int) $r['packs'], 'units_ordered' => (int) $r['qty'],
                'units_received' => (int) $r['received_units'], 'units_outstanding' => $outstanding, 'pack_price' => (string) $r['pack_price'],
                'unit_cost' => (string) $r['unit_cost'], 'vat_code' => (string) $r['vat_code']];
        }
        return $out;
    }

    /**
     * Adds received central units to PO lines ($lineUnits: line_no => units > 0), INSIDE the caller's transaction (the GRN
     * posting; \LogicException otherwise). Locks the PO document row, then purchase_order. The PO must be posted and
     * approved, sent or part-received (409 po_not_receivable). The state follows: every item line received >= ordered ->
     * received; some -> part_received. Over-delivery tolerance is the GRN's (I-3). Audit po.receipt.
     *
     * @param array<int, int> $lineUnits
     */
    public function applyReceipt(int $poId, array $lineUnits, string $grnLabel, ?Caller $caller = null): void
    {
        $this->receipt($poId, $lineUnits, $grnLabel, $caller, 1);
    }

    /**
     * Takes received units back (a GRN reversal), inside the caller's transaction; never below 0 (409 receipt_below_zero).
     * The state follows (none left: back to sent or approved, by sent_at). A CLOSED order takes its receipts back too (review
     * finding 2, pack PO, PO8): it stays closed while something is still received (the rest is still not expected); with
     * nothing received it is open again (sent or approved: "closed" means "the rest of a part delivery is not coming"), its
     * close cleared and the close reason kept in the audit row (`reopened_from_closed`).
     *
     * @param array<int, int> $lineUnits
     */
    public function reverseReceipt(int $poId, array $lineUnits, string $grnLabel, ?Caller $caller = null): void
    {
        $this->receipt($poId, $lineUnits, $grnLabel, $caller, -1);
    }

    /**
     * Central units still expected per item: Σ max(0, qty − received) over posted POs that are approved, sent or
     * part-received (the reorder list's "on order"). Every id asked for is in the result (0 when nothing is on order).
     *
     * @param list<int> $skuIds
     * @return array<int, int>
     */
    public function onOrder(array $skuIds): array
    {
        return $this->sumBySku($skuIds,
            'SELECT dl.sku_id, SUM(GREATEST(0, dl.qty - pl.received_units)) AS n FROM document_line dl '
            . 'JOIN document d ON d.id = dl.document_id JOIN po_line pl ON pl.document_id = dl.document_id AND pl.line_no = dl.line_no '
            . "JOIN purchase_order po ON po.document_id = d.id WHERE d.doc_type = 'PO' AND d.status = 'posted' AND po.state IN ('approved', 'sent', 'part_received') "
            . "AND pl.kind = 'item' AND dl.sku_id IN (%s) GROUP BY dl.sku_id");
    }

    /**
     * Central units on PO drafts and on POs waiting for their approval (shown on the reorder list, not counted).
     *
     * @param list<int> $skuIds
     * @return array<int, int>
     */
    public function inDrafts(array $skuIds): array
    {
        return $this->sumBySku($skuIds,
            "SELECT dl.sku_id, SUM(dl.qty) AS n FROM document_line dl JOIN document d ON d.id = dl.document_id WHERE d.doc_type = 'PO' "
            . "AND d.status IN ('draft', 'awaiting_approval') AND dl.sku_id IS NOT NULL AND dl.sku_id IN (%s) GROUP BY dl.sku_id");
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null the purchase_order row */
    public function headerRow(int $id): ?array
    {
        return $this->db->one('SELECT * FROM purchase_order WHERE document_id = ?', [$id]);
    }

    /**
     * The lines of a PO as saveDraft() takes them (copy, add, import keep what is there).
     *
     * @return list<array<string, mixed>>
     */
    public function lines(int $id): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT pl.*, dl.sku_id, dl.description, dl.uid FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no '
            . 'WHERE pl.document_id = ? ORDER BY pl.line_no',
            [$id],
        ) as $r) {
            $out[] = ['uid' => $r['uid'] === null ? null : (int) $r['uid'], 'kind' => (string) $r['kind'], 'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'],
                'supplier_item_id' => $r['supplier_item_id'] === null ? null : (int) $r['supplier_item_id'],
                'supplier_code' => $r['supplier_code'] === null ? null : (string) $r['supplier_code'], 'purchase_unit' => (string) $r['purchase_unit'],
                'units_per_pack' => (int) $r['units_per_pack'], 'packs' => (int) $r['packs'], 'pack_price' => (string) $r['pack_price'], 'vat_code' => (string) $r['vat_code'],
                'description' => $r['description'] === null ? null : (string) $r['description'],
                'suggested_units' => $r['suggested_units'] === null ? null : (int) $r['suggested_units']];
        }
        return $out;
    }

    /**
     * Things a buyer should know before approving (warnings, never refusals: spec §6.3): the supplier not active yet, an
     * overseas route not approved, due diligence overdue, the net below the supplier's minimum order, lines at a price of
     * 0, merged items, supplier items switched off.
     *
     * @return list<string>
     */
    public function warnings(int $id): array
    {
        $po = $this->headerRow($id);
        if ($po === null) {
            return [];
        }
        $s = $this->db->one('SELECT * FROM supplier WHERE id = ?', [(int) $po['supplier_id']]) ?? [];
        $out = [];
        if (($s['status'] ?? '') !== 'active') {
            $out[] = "Supplier {$s['code']} is " . str_replace('_', ' ', (string) ($s['status'] ?? 'unknown'))
                . ': the order can be drafted, but it is approved only once a second person has approved the supplier.';
        } elseif ((int) $s['is_overseas'] === 1 && $s['import_route_approved_at'] === null) {
            $out[] = "Supplier {$s['code']} is overseas and its import route is not approved: the order cannot be approved until it is.";
        } elseif ($this->db->value('SELECT 1 FROM review_task WHERE open_key = ? AND reason = ?', ['supplier:' . (int) $s['id'] . ':approval', 'import_route']) !== null) {
            $out[] = "A change of {$s['code']}'s import route or overseas status waits for a second person: the order cannot be approved until it is approved.";
        }
        if (($s['dd_next_review_on'] ?? null) !== null && (string) $s['dd_next_review_on'] < $this->today()) {
            $out[] = "Due diligence of {$s['code']} is overdue: the next review was due on {$s['dd_next_review_on']}.";
        }
        if (($s['min_order_value'] ?? null) !== null && PoMath::e2((string) $po['net_total']) < PoMath::e2((string) $s['min_order_value'])
            && $this->db->value('SELECT 1 FROM document_line WHERE document_id = ? LIMIT 1', [$id]) !== null) {
            $out[] = 'The net total ' . PoMath::money(PoMath::e2((string) $po['net_total'])) . " is below {$s['code']}'s minimum order of "
                . PoMath::money(PoMath::e2((string) $s['min_order_value'])) . '.';
        }
        // CW-1153 (docs/decisions.md VB8): a shop's own items are not bought on our order (the approval refuses them).
        foreach (PurchaseOrderHandler::ownItemLines($this->db, $id) as $o) {
            $out[] = PurchaseOrderHandler::ownItemWords($o['code'], $o['shop']);
        }
        $cardOf = [];
        foreach ($this->db->all(
            'SELECT pl.line_no, pl.kind, pl.pack_price, dl.sku_id, s.code, s.merged_into_sku_id, m.code AS merged_code, si.is_active AS si_active FROM po_line pl '
            . 'JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no LEFT JOIN sku s ON s.id = dl.sku_id '
            . 'LEFT JOIN sku m ON m.id = s.merged_into_sku_id LEFT JOIN supplier_item si ON si.id = pl.supplier_item_id WHERE pl.document_id = ? ORDER BY pl.line_no',
            [$id],
        ) as $l) {
            if ($l['kind'] === 'item' && PoMath::e4((string) $l['pack_price']) === 0) {
                $out[] = "Line {$l['line_no']} ({$l['code']}) has a price of £0.";
            }
            if ($l['merged_into_sku_id'] !== null) {
                $out[] = "Line {$l['line_no']}: item {$l['code']} was merged into {$l['merged_code']}: replace the line (the order cannot be saved or approved with it).";
            }
            if ($l['si_active'] !== null && (int) $l['si_active'] !== 1) {
                $out[] = "Line {$l['line_no']}: the supplier item of {$l['code']} was switched off: remove the line or switch it on again.";
            }
            if ($l['sku_id'] !== null) {
                $cardOf[(int) $l['sku_id']][] = $l;
            }
        }
        // IM3 (I103, I105): what the item cards say about the lines' items.
        foreach ((new ItemCompliance($this->db))->statusOf(array_keys($cardOf)) as $sku => $st) {
            foreach ($cardOf[$sku] as $l) {
                if ($st['blocked'] !== []) {
                    $out[] = "Line {$l['line_no']}: {$l['code']} is blocked by its item card (" . ItemRules::labels($st['blocked']) . '): the order cannot be approved with it.';
                }
                if ($st['warnings'] !== []) {
                    $out[] = "Line {$l['line_no']}: {$l['code']}'s item card, not confirmed " . ($st['enforced'] ? 'since it changed' : 'yet') . ', says '
                        . ItemRules::labels($st['warnings']) . ': check it before ordering (once confirmed, it blocks the item).';
                }
                if ($st['discontinued']) {
                    $out[] = "Line {$l['line_no']}: {$l['code']} is marked discontinued on its item card.";
                }
            }
        }
        return $out;
    }

    /**
     * Everything the PDF prints (PurchaseOrderPdf::render): a posted PO from its snapshots (decision 9: what was approved),
     * a draft from the company details in use (Settings::company(): company_profile since 0013, I91) and the current supplier;
     * a cancellation document prints its original's lines.
     *
     * @return array<string, mixed>
     */
    public function pdfData(int $id): array
    {
        $doc = $this->docs->find($id);
        if ($doc === null || $doc->docType !== 'PO') {
            throw new CwException('unknown_purchase_order', 'there is no such purchase order', 404);
        }
        $cancellation = null;
        $orig = $doc;
        if ($doc->reversesId !== null) {
            $orig = $this->docs->get($doc->reversesId);
            $cancellation = ['number' => $doc->number, 'reason' => (string) ($this->db->value('SELECT label FROM reason_code WHERE code = ?', [$doc->reasonCode]) ?? $doc->reasonCode),
                'note' => $doc->note, 'date' => $doc->docDate];
        }
        $po = $this->headerRow($orig->id) ?? throw new CwException('po_header_missing', 'this purchase order has no header', 422);
        $posted = $po['state'] !== null && $po['company_snapshot'] !== null;
        $company = $posted ? (array) json_decode((string) $po['company_snapshot'], true) : $this->settings->company();
        // An order approved with company details a reviewer later rejected prints a "do not send" banner of its own (I98).
        $rejected = $posted && $doc->reversesId === null && in_array($po['state'], CompanyDetails::OPEN_ORDER_STATES, true)
            && (new CompanyDetails($this->db))->rejectedIn($company) !== null;
        $supplierRow = $this->db->one('SELECT * FROM supplier WHERE id = ?', [(int) $po['supplier_id']]) ?? [];
        $supplier = $posted ? (array) json_decode((string) $po['supplier_snapshot'], true) : PurchaseOrderHandler::supplierSnapshot($supplierRow);
        $lines = [];
        $calc = [];
        foreach ($this->db->all(
            'SELECT pl.*, dl.qty, dl.amount, dl.description, s.code AS sku_code, s.name AS sku_name FROM po_line pl '
            . 'JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no LEFT JOIN sku s ON s.id = dl.sku_id '
            . 'WHERE pl.document_id = ? ORDER BY pl.line_no',
            [$orig->id],
        ) as $r) {
            $amountE2 = PoMath::e2((string) $r['amount']);
            $calc[] = ['amount_e2' => $amountE2, 'vat_code' => (string) $r['vat_code'], 'rate_e2' => PoMath::e2((string) $r['vat_rate'])];
            $lines[] = ['line_no' => (int) $r['line_no'], 'kind' => (string) $r['kind'], 'supplier_code' => $r['supplier_code'], 'sku_code' => $r['sku_code'],
                'description' => $r['kind'] === 'charge' ? (string) $r['description'] : (string) ($r['sku_name'] ?? '') . ($r['description'] !== null ? ' — ' . $r['description'] : ''),
                'purchase_unit' => (string) $r['purchase_unit'], 'units_per_pack' => (int) $r['units_per_pack'], 'packs' => (int) $r['packs'],
                'units' => $r['qty'] === null ? null : (int) $r['qty'], 'pack_price' => (string) $r['pack_price'], 'vat_code' => (string) $r['vat_code'],
                'amount_e2' => $amountE2];
        }
        $amends = $po['amends_document_id'] === null ? null : $this->db->value('SELECT number FROM document WHERE id = ?', [(int) $po['amends_document_id']]);
        $reversedBy = $orig->status === 'reversed' ? $this->db->value("SELECT number FROM document WHERE reverses_id = ? AND status = 'posted'", [$orig->id]) : null;
        return [
            'number' => $orig->number,
            'label' => $orig->number ?? 'draft #' . $orig->id,
            'id' => $orig->id,
            'status' => $orig->status,
            'state' => $po['state'],
            'posted' => $posted,
            'order_date' => $orig->docDate,
            'expected_date' => $po['expected_date'],
            'external_ref' => $orig->externalRef,
            'note' => $orig->note,
            'amends' => $amends === null ? null : (string) $amends,
            'cancellation' => $cancellation,
            'cancelled_by' => $reversedBy === null ? null : (string) $reversedBy,
            'company' => $company,
            'company_rejected' => $rejected,
            'supplier' => $supplier,
            'lines' => $lines,
            'totals' => PoMath::totals($calc),
            'terms' => $posted ? $this->termsAsApproved($po, $orig) : (string) $this->settings->get('po.terms'),
        ];
    }

    /**
     * The terms an approved order prints (pack PO, PO9, review finding 7): its snapshot; an order approved before 0042 has none, so
     * the version of the setting in force when it was approved (its history, config_change), else the setting as it is now.
     *
     * @param array<string, mixed> $po the purchase_order row
     */
    private function termsAsApproved(array $po, Document $doc): string
    {
        if (($po['terms_snapshot'] ?? null) !== null) {
            return (string) $po['terms_snapshot'];
        }
        if ($doc->postedAt !== null) {
            $v = $this->db->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(state, '$.value')) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'po.terms' "
                . 'AND created_at <= ? ORDER BY version DESC LIMIT 1', [$doc->postedAt]);
            $decoded = $v === null ? null : json_decode((string) $v, true);
            if (is_string($decoded)) {
                return $decoded;
            }
        }
        return (string) $this->settings->get('po.terms');
    }

    /**
     * The rows of the lines file (CSV / XLSX export, spec §6.6): one per line, in the columns PoLinesFile::COLUMNS.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $id): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT pl.*, dl.qty, dl.amount, dl.description, s.code AS sku_code, s.name AS sku_name, si.supplier_code AS si_code, '
            . '(SELECT b.barcode FROM sku_barcode b WHERE b.sku_id = dl.sku_id AND b.is_usable = 1 ORDER BY b.units_per_scan, b.barcode LIMIT 1) AS barcode '
            . 'FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no LEFT JOIN sku s ON s.id = dl.sku_id '
            . 'LEFT JOIN supplier_item si ON si.id = pl.supplier_item_id WHERE pl.document_id = ? ORDER BY pl.line_no',
            [$id],
        ) as $r) {
            $charge = $r['kind'] === 'charge';
            $out[] = ['line' => (int) $r['line_no'], 'cw_code' => $r['sku_code'], 'supplier_code' => $r['supplier_code'] ?? $r['si_code'],
                'barcode' => $r['barcode'], 'item_name' => $charge ? null : $r['sku_name'], 'purchase_unit' => $charge ? 'charge' : (string) $r['purchase_unit'],
                'units_per_pack' => $charge ? null : (int) $r['units_per_pack'], 'packs' => $charge ? null : (int) $r['packs'],
                'units' => $r['qty'] === null ? null : (int) $r['qty'], 'pack_price' => (string) $r['pack_price'], 'vat_code' => (string) $r['vat_code'],
                'line_total' => PoMath::fromE2(PoMath::e2((string) $r['amount'])), 'note' => $r['description']];
        }
        return $out;
    }

    /**
     * Resolves what was scanned or typed to an item of this supplier (addLine's order): `found` (sku_id, supplier_item or
     * null, units_per_pack, purchase_unit), `choices` (several candidates, each with its supply's last pack price: the lines' grid fills a
     * line with it, docs/decisions.md KG2) or `not_found`.
     *
     * @return array<string, mixed>
     */
    public function resolve(int $supplierId, string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return ['status' => 'not_found'];
        }
        if (preg_match('/^[0-9]{6,64}$/D', $q) === 1) {
            $b = $this->db->one('SELECT b.sku_id, b.units_per_scan FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.barcode IN (?, ?) AND b.is_usable = 1 '
                . 'AND s.merged_into_sku_id IS NULL ORDER BY b.barcode = ? DESC LIMIT 1', [$q, Gtin::key($q) ?? $q, $q]);
            if ($b !== null) {
                $sku = (int) $b['sku_id'];
                $k = (int) $b['units_per_scan'];
                if ($k > 1) {
                    $si = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = ? AND is_active = 1', [$supplierId, $sku, $k]);
                    if ($si !== null) {
                        return self::found($sku, $si);
                    }
                }
                return $this->forItem($supplierId, $sku, $k);
            }
        }
        $si = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND supplier_code = ? AND is_active = 1 ORDER BY id LIMIT 1', [$supplierId, $q]);
        if ($si !== null && $this->db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [(int) $si['sku_id']]) === null) {
            return self::found((int) $si['sku_id'], $si);
        }
        if (preg_match('/^cw-?0*([0-9]{1,10})$/iD', $q, $m) === 1) {
            $sku = $this->db->one('SELECT id FROM sku WHERE id = ? AND merged_into_sku_id IS NULL', [(int) $m[1]]);
            if ($sku !== null) {
                return $this->forItem($supplierId, (int) $sku['id'], 1);
            }
        }
        $found = (new Queries($this->db))->searchSkus($q, self::SEARCH_LIMIT, false, true);
        if ($found === []) {
            return ['status' => 'not_found'];
        }
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $found);
        $sis = [];
        foreach ($this->db->all('SELECT * FROM supplier_item WHERE supplier_id = ? AND is_active = 1 AND sku_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') '
            . 'ORDER BY is_preferred DESC, id', [$supplierId, ...$ids]) as $si) {
            $sis[(int) $si['sku_id']] ??= $si;
        }
        $choices = [];
        foreach ($found as $s) {
            $si = $sis[(int) $s['id']] ?? null;
            $choices[] = ['sku_id' => (int) $s['id'], 'sku_code' => (string) $s['code'], 'name' => (string) $s['name'], 'brand' => $s['brand'],
                'supplier_item_id' => $si === null ? null : (int) $si['id'], 'units_per_pack' => $si === null ? null : (int) $si['units_per_pack'],
                'supplier_code' => $si['supplier_code'] ?? null, 'purchase_unit' => $si['purchase_unit'] ?? null, 'last_pack_price' => $si['last_pack_price'] ?? null];
        }
        // The order (the Chrome check of 8f6fbb7, docs/decisions.md PV31): the name the words are, then names beginning with them, then the
        // rest; in each, this supplier's items before those not set up with it yet; then as found (by name).
        $norm = static fn (string $v): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $v)));
        $want = $norm($q);
        $rank = [];
        foreach ($choices as $i => $c) {
            $name = $norm((string) $c['name']);
            $rank[$i] = [$name === $want ? 0 : (str_starts_with($name, $want) ? 1 : 2), $c['supplier_item_id'] === null ? 1 : 0, $i];
        }
        uksort($choices, static fn (int $a, int $b): int => $rank[$a] <=> $rank[$b]);
        return ['status' => 'choices', 'choices' => array_values($choices)];
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $r a `found` resolve() result
     * @return array{status: 'added'|'incremented', document: Document, line_no: int}
     */
    private function addResolved(Caller $caller, int $id, int $version, array $r, int $packs): array
    {
        if ($packs < 1 || $packs > PoMath::MAX_PACKS) {
            throw new CwException('bad_field', 'packs: a whole number from 1 to ' . PoMath::MAX_PACKS, 422, ['field' => 'packs']);
        }
        $lines = $this->lines($id);
        $new = ['kind' => 'item', 'sku_id' => $r['sku_id'], 'supplier_item_id' => $r['supplier_item']['id'] ?? null, 'units_per_pack' => $r['units_per_pack'],
            'purchase_unit' => $r['purchase_unit'], 'packs' => $packs];
        $status = 'added';
        $lineNo = count($lines) + 1;
        foreach ($lines as $i => $l) {
            if (self::sameLine($l, $new)) {
                $lines[$i]['packs'] = $l['packs'] + $packs;
                $status = 'incremented';
                $lineNo = $i + 1;
                break;
            }
        }
        if ($status === 'added') {
            if (count($lines) >= self::MAX_LINES) {
                throw new CwException('too_many_lines', 'a purchase order has at most ' . self::MAX_LINES . ' lines', 422);
            }
            $lines[] = $new;
        }
        $doc = $this->saveDraft($caller, $id, $version, [], $lines);
        return ['status' => $status, 'document' => $doc, 'line_no' => $lineNo];
    }

    /** Whether two lines are the same purchase (the same supplier item, or the same item and pack without one). */
    private static function sameLine(array $a, array $b): bool
    {
        if (($a['kind'] ?? 'item') !== 'item' || ($b['kind'] ?? 'item') !== 'item') {
            return false;
        }
        if (($a['supplier_item_id'] ?? null) !== null || ($b['supplier_item_id'] ?? null) !== null) {
            return ($a['supplier_item_id'] ?? null) === ($b['supplier_item_id'] ?? null);
        }
        return (int) ($a['sku_id'] ?? 0) === (int) ($b['sku_id'] ?? 0) && (int) ($a['units_per_pack'] ?? 1) === (int) ($b['units_per_pack'] ?? 1);
    }

    /**
     * An item of this supplier: the preferred or only active supplier item, several -> choices among them, none -> a line
     * without a supplier item in packs of $packOf.
     *
     * @return array<string, mixed>
     */
    private function forItem(int $supplierId, int $sku, int $packOf): array
    {
        $sis = $this->db->all('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND is_active = 1 ORDER BY is_preferred DESC, units_per_pack, id',
            [$supplierId, $sku]);
        if (count($sis) === 1 || ($sis !== [] && (int) $sis[0]['is_preferred'] === 1)) {
            return self::found($sku, $sis[0]);
        }
        if ($sis !== []) {
            $s = $this->db->one('SELECT code, name, brand FROM sku WHERE id = ?', [$sku]) ?? [];
            return ['status' => 'choices', 'choices' => array_map(static fn (array $si): array => ['sku_id' => $sku, 'sku_code' => (string) ($s['code'] ?? ''),
                'name' => (string) ($s['name'] ?? ''), 'brand' => $s['brand'] ?? null, 'supplier_item_id' => (int) $si['id'], 'units_per_pack' => (int) $si['units_per_pack'],
                'supplier_code' => $si['supplier_code'], 'purchase_unit' => (string) $si['purchase_unit'], 'last_pack_price' => $si['last_pack_price']], $sis)];
        }
        return ['status' => 'found', 'sku_id' => $sku, 'supplier_item' => null, 'units_per_pack' => $packOf, 'purchase_unit' => $packOf > 1 ? 'case' : 'each'];
    }

    /** @param array<string, mixed> $si @return array<string, mixed> */
    private static function found(int $sku, array $si): array
    {
        return ['status' => 'found', 'sku_id' => $sku, 'supplier_item' => $si, 'units_per_pack' => (int) $si['units_per_pack'], 'purchase_unit' => (string) $si['purchase_unit']];
    }

    /**
     * A lines-file identifier (cw_code, supplier_code, barcode) as PoLinesFile::parse() wants it, or the error text. $within: the
     * row's identifier that names its line (PoLinesFile::PRECEDENCE, pack PO PO9): this one then names only its item (and a barcode
     * the units it stands for, `pack_of`), so a CW number of an item sold in several packs no longer contradicts the supplier code.
     *
     * @param array<string, mixed>|null $within
     * @return array<string, mixed>|string
     */
    private function resolveForFile(int $supplierId, string $type, string $value, ?array $within = null): array|string
    {
        if ($within !== null && in_array($type, ['cw_code', 'barcode'], true)) {
            $packOf = 1;
            if ($type === 'cw_code') {
                if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $value, $m) !== 1 || $this->db->value('SELECT 1 FROM sku WHERE id = ?', [(int) $m[1]]) === null) {
                    return "there is no item {$value}";
                }
                $sku = (int) $m[1];
            } else {
                if (preg_match('/^[0-9 ]{6,64}$/D', $value) !== 1) {
                    return 'a barcode is 6 to 64 digits';
                }
                $digits = str_replace(' ', '', $value);
                $b = $this->db->one('SELECT sku_id, units_per_scan FROM sku_barcode WHERE barcode IN (?, ?) AND is_usable = 1 ORDER BY barcode = ? DESC LIMIT 1',
                    [$digits, Gtin::key($digits) ?? $digits, $digits]);
                if ($b === null) {
                    return "no item has the usable barcode {$value}";
                }
                $sku = (int) $b['sku_id'];
                $packOf = (int) $b['units_per_scan'];
            }
            $code = $this->db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
            return ['sku_id' => $sku, 'sku_code' => (string) $code, 'supplier_item' => null, 'pack_of' => $packOf];
        }
        switch ($type) {
            case 'supplier_code':
                $si = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND supplier_code = ? AND is_active = 1 ORDER BY id LIMIT 1', [$supplierId, $value]);
                if ($si === null) {
                    return "this supplier has no active item with the code {$value}";
                }
                $r = self::found((int) $si['sku_id'], $si);
                break;
            case 'barcode':
                if (preg_match('/^[0-9 ]{6,64}$/D', $value) !== 1) {
                    return 'a barcode is 6 to 64 digits';
                }
                $digits = str_replace(' ', '', $value);
                $b = $this->db->one('SELECT sku_id, units_per_scan FROM sku_barcode WHERE barcode IN (?, ?) AND is_usable = 1 ORDER BY barcode = ? DESC LIMIT 1',
                    [$digits, Gtin::key($digits) ?? $digits, $digits]);
                if ($b === null) {
                    return "no item has the usable barcode {$value}";
                }
                $k = (int) $b['units_per_scan'];
                $si = $k > 1 ? $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = ? AND is_active = 1',
                    [$supplierId, (int) $b['sku_id'], $k]) : null;
                $r = $si !== null ? self::found((int) $b['sku_id'], $si) : $this->forItem($supplierId, (int) $b['sku_id'], 1);
                break;
            case 'cw_code':
                if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $value, $m) !== 1 || $this->db->value('SELECT 1 FROM sku WHERE id = ?', [(int) $m[1]]) === null) {
                    return "there is no item {$value}";
                }
                $r = $this->forItem($supplierId, (int) $m[1], 1);
                break;
            default:
                throw new \LogicException("unknown identifier {$type}");
        }
        if ($r['status'] === 'choices') {
            return 'this supplier sells the item in several packs (' . implode(', ', array_map(static fn (array $c): string => (string) $c['units_per_pack'], $r['choices']))
                . '): give the supplier_code';
        }
        $s = $this->db->one('SELECT s.code, s.merged_into_sku_id, m.code AS into_code FROM sku s LEFT JOIN sku m ON m.id = s.merged_into_sku_id WHERE s.id = ?', [$r['sku_id']]);
        if ($s === null) {
            return 'the item does not exist';
        }
        if ($s['merged_into_sku_id'] !== null) {
            return "item {$s['code']} was merged into {$s['into_code']}: use that one";
        }
        $si = $r['supplier_item'];
        return ['sku_id' => (int) $r['sku_id'], 'sku_code' => (string) $s['code'], 'supplier_item' => $si === null ? null : ['id' => (int) $si['id'],
            'units_per_pack' => (int) $si['units_per_pack'], 'purchase_unit' => (string) $si['purchase_unit'], 'supplier_code' => $si['supplier_code'],
            'last_pack_price' => $si['last_pack_price'] === null ? null : (string) $si['last_pack_price']]];
    }

    /**
     * The lines of a PO for a new draft (copy, amend): the same items, packs and prices; a supplier item that is no longer
     * active (or no longer this supplier's) is dropped from the line, which keeps its item, pack and code; a VAT code no
     * longer in use falls back to the default.
     *
     * @return list<array<string, mixed>>
     */
    private function copyLines(int $id, int $supplierId): array
    {
        $vat = $this->vatCodes();
        $out = [];
        foreach ($this->lines($id) as $l) {
            unset($l['uid']); // another order's lines: the new draft gives its own ids (LV8)
            if ($l['supplier_item_id'] !== null && $this->db->value('SELECT 1 FROM supplier_item WHERE id = ? AND supplier_id = ? AND is_active = 1',
                [$l['supplier_item_id'], $supplierId]) === null) {
                $l['supplier_item_id'] = null;
            }
            if (!($vat[$l['vat_code']]['active'] ?? false)) {
                $l['vat_code'] = null;
            }
            $l['suggested_units'] = null;
            $out[] = $l;
        }
        return $out;
    }

    /**
     * Checks and completes the lines of a save (class docblock of saveDraft).
     *
     * @param array<string, mixed> $supplier
     * @param mixed $lines
     * @param int $offset the number of the first line less one (a line saved by itself: its own number in the words)
     * @return list<array{doc: array<string, mixed>, po: array<string, mixed>, calc: array{amount_e2: int, vat_code: string, rate_e2: int}}>
     */
    private function normaliseLines(Caller $caller, array $supplier, mixed $lines, int $offset = 0): array
    {
        if (!is_array($lines) || !array_is_list($lines)) {
            throw new CwException('bad_lines', 'lines must be a list', 400);
        }
        if (count($lines) > self::MAX_LINES) {
            throw new CwException('too_many_lines', 'a purchase order has at most ' . self::MAX_LINES . ' lines (' . count($lines) . ' given)', 422);
        }
        $supplierId = (int) $supplier['id'];
        $vat = $this->vatCodes();
        $defaultVat = (string) ($supplier['default_vat_code'] ?? '');
        if (!($vat[$defaultVat]['active'] ?? false)) {
            $defaultVat = (string) $this->settings->get('po.default_vat_code');
        }
        // The supplier items the lines name, read in two queries (not one per line: a 300-line save is 300 round trips less).
        $byId = [];
        $byPack = [];
        $ids = [];
        $skus = [];
        foreach ($lines as $line) {
            if (is_array($line) && is_int($line['supplier_item_id'] ?? null)) {
                $ids[$line['supplier_item_id']] = true;
            } elseif (is_array($line) && is_int($line['sku_id'] ?? null)) {
                $skus[$line['sku_id']] = true;
            }
        }
        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            foreach ($this->db->all('SELECT * FROM supplier_item WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $byId[(int) $r['id']] = $r;
            }
        }
        foreach (array_chunk(array_keys($skus), 1000) as $chunk) {
            foreach ($this->db->all('SELECT * FROM supplier_item WHERE supplier_id = ? AND is_active = 1 AND sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$supplierId, ...$chunk]) as $r) {
                $byPack[(int) $r['sku_id'] . ':' . (int) $r['units_per_pack']] = $r;
            }
        }
        $out = [];
        foreach ($lines as $i => $line) {
            $no = $i + 1 + $offset; // one line saved by itself names its own number (setLine, addLineLive)
            $bad = static fn (string $field, string $why, int $status = 422): CwException => new CwException('bad_line', "line {$no}, {$field}: {$why}", $status,
                ['line' => $no, 'field' => $field]);
            if (!is_array($line)) {
                throw $bad('line', 'must be an object', 400);
            }
            foreach (array_keys($line) as $k) {
                if (!in_array($k, self::LINE_FIELDS, true)) {
                    throw $bad((string) $k, 'a PO line has ' . implode(', ', self::LINE_FIELDS), 400);
                }
            }
            $kind = (string) ($line['kind'] ?? 'item');
            // The line's own id when it names one (a line of this draft read by lines(): LV8, LV18), checked by Documents::setLines.
            $uid = [];
            if (array_key_exists('uid', $line)) {
                if ($line['uid'] !== null && !is_int($line['uid'])) {
                    throw $bad('uid', 'the id of a line of this draft, or none', 400);
                }
                $uid = ['uid' => $line['uid']];
            }
            $description = self::text($line['description'] ?? null, 255, $bad, 'description');
            $vatCode = self::text($line['vat_code'] ?? null, 4, $bad, 'vat_code');
            $vatCode = $vatCode === null ? $defaultVat : strtoupper($vatCode);
            if (!isset($vat[$vatCode])) {
                throw $bad('vat_code', "there is no VAT code {$vatCode}");
            }
            if (!$vat[$vatCode]['active']) {
                throw $bad('vat_code', "the VAT code {$vatCode} is no longer in use");
            }
            $rateE2 = PoMath::e2($vat[$vatCode]['rate']);
            $price = $line['pack_price'] ?? null;
            if ($price !== null && !is_string($price) && !is_int($price)) {
                throw $bad('pack_price', 'an amount in GBP', 400);
            }
            $price = $price === null || trim((string) $price) === '' ? null : self::packPrice((string) $price, $bad);
            if ($kind === 'charge') {
                if ($description === null) {
                    throw $bad('description', 'a charge line says what it is (delivery, ...)');
                }
                if ($price === null || PoMath::e4($price) <= 0 || PoMath::e4($price) % 100 !== 0) {
                    throw $bad('pack_price', 'a charge is an amount of more than £0 in whole pence');
                }
                foreach (['sku_id', 'supplier_item_id'] as $k) {
                    if (($line[$k] ?? null) !== null) {
                        throw $bad($k, 'a charge line has no item');
                    }
                }
                $amount = PoMath::fromE2(intdiv(PoMath::e4($price), 100));
                $out[] = ['doc' => ['amount' => $amount, 'description' => $description] + $uid,
                    'po' => ['kind' => 'charge', 'supplier_item_id' => null, 'supplier_code' => null, 'purchase_unit' => 'each', 'units_per_pack' => 1, 'packs' => 1,
                        'pack_price' => $price, 'vat_code' => $vatCode, 'vat_rate' => $vat[$vatCode]['rate'], 'suggested_units' => null],
                    'calc' => ['amount_e2' => PoMath::e2($amount), 'vat_code' => $vatCode, 'rate_e2' => $rateE2]];
                continue;
            }
            if ($kind !== 'item') {
                throw $bad('kind', 'item or charge', 400);
            }
            $packs = self::int($line['packs'] ?? null, 1, PoMath::MAX_PACKS, $bad, 'packs');
            $si = null;
            $siId = $line['supplier_item_id'] ?? null;
            $skuId = $line['sku_id'] ?? null;
            if ($siId !== null) {
                $siId = self::int($siId, 1, PHP_INT_MAX, $bad, 'supplier_item_id');
                $si = $byId[$siId] ?? $this->db->one('SELECT * FROM supplier_item WHERE id = ?', [$siId]);
                if ($si === null || (int) $si['supplier_id'] !== $supplierId) {
                    throw $bad('supplier_item_id', 'this supplier has no such supplier item');
                }
                if ((int) $si['is_active'] !== 1) {
                    throw $bad('supplier_item_id', 'the supplier item ' . ($si['supplier_code'] ?? '#' . $si['id']) . ' is switched off: remove the line or switch it on');
                }
                if ($skuId !== null && (int) $skuId !== (int) $si['sku_id']) {
                    throw $bad('sku_id', 'the item is not the supplier item\'s');
                }
                $skuId = (int) $si['sku_id'];
                $upp = (int) $si['units_per_pack'];
                if (($line['units_per_pack'] ?? null) !== null && self::int($line['units_per_pack'], 1, PoMath::MAX_UNITS_PER_PACK, $bad, 'units_per_pack') !== $upp) {
                    throw $bad('units_per_pack', "pack differs: supplier item says {$upp}");
                }
            } else {
                if ($skuId === null) {
                    throw $bad('sku_id', 'an item line names its item', 400);
                }
                $skuId = self::int($skuId, 1, PHP_INT_MAX, $bad, 'sku_id');
                $upp = ($line['units_per_pack'] ?? null) === null ? 1 : self::int($line['units_per_pack'], 1, PoMath::MAX_UNITS_PER_PACK, $bad, 'units_per_pack');
                $si = $byPack["{$skuId}:{$upp}"] ?? (isset($skus[$skuId]) ? null
                    : $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = ? AND is_active = 1', [$supplierId, $skuId, $upp]));
                if ($si === null && !empty($line['save_item'])) {
                    $code = self::text($line['supplier_code'] ?? null, 64, $bad, 'supplier_code');
                    $unit = self::text($line['purchase_unit'] ?? null, SupplierItems::PURCHASE_UNIT_MAX, $bad, 'purchase_unit');
                    $si = $this->supplierItems->create($caller, $supplierId, $skuId, ['supplier_code' => $code, 'purchase_unit' => $unit ?? ($upp > 1 ? 'case' : 'each'),
                        'units_per_pack' => (string) $upp, 'is_preferred' => SupplierItems::PREFERRED_AUTO]);
                }
            }
            if ($si !== null) {
                $code = $si['supplier_code'] === null ? null : (string) $si['supplier_code'];
                $unit = (string) $si['purchase_unit'];
                $price ??= $si['last_pack_price'] === null ? null : (string) $si['last_pack_price'];
            } else {
                $code = self::text($line['supplier_code'] ?? null, 64, $bad, 'supplier_code');
                $unit = self::text($line['purchase_unit'] ?? null, SupplierItems::PURCHASE_UNIT_MAX, $bad, 'purchase_unit') ?? ($upp > 1 ? 'case' : 'each');
            }
            $price ??= '0.0000';
            $qty = $packs * $upp;
            if ($qty > Documents::MAX_QTY) {
                throw $bad('packs', "{$packs} packs of {$upp} is more than " . number_format(Documents::MAX_QTY) . ' units on one line');
            }
            try {
                $amountE2 = PoMath::lineAmountE2($packs, PoMath::e4($price));
                $unitCost = PoMath::unitCost($price, $upp);
            } catch (\RangeException $e) {
                throw $bad('pack_price', $e->getMessage());
            }
            $suggested = ($line['suggested_units'] ?? null) === null ? null : self::int($line['suggested_units'], 0, 2_000_000_000, $bad, 'suggested_units');
            $out[] = ['doc' => ['sku_id' => $skuId, 'qty' => $qty, 'unit_cost' => $unitCost, 'amount' => PoMath::fromE2($amountE2), 'description' => $description] + $uid,
                'po' => ['kind' => 'item', 'supplier_item_id' => $si === null ? null : (int) $si['id'], 'supplier_code' => $code, 'purchase_unit' => $unit,
                    'units_per_pack' => $upp, 'packs' => $packs, 'pack_price' => $price, 'vat_code' => $vatCode, 'vat_rate' => $vat[$vatCode]['rate'],
                    'suggested_units' => $suggested],
                'calc' => ['amount_e2' => $amountE2, 'vat_code' => $vatCode, 'rate_e2' => $rateE2]];
        }
        return $out;
    }

    /** @param \Closure(string, string, int=): CwException $bad */
    private static function packPrice(string $raw, \Closure $bad): string
    {
        try {
            $p = SupplierItems::packPrice($raw);
        } catch (CwException) {
            throw $bad('pack_price', 'an amount in GBP of at least 0 with at most 4 decimals');
        }
        if (PoMath::e4($p) > PoMath::MAX_PACK_PRICE_E4) {
            throw $bad('pack_price', 'at most £' . number_format(intdiv(PoMath::MAX_PACK_PRICE_E4, 10_000)) . ' a pack');
        }
        return $p;
    }

    /** @param \Closure(string, string, int=): CwException $bad */
    private static function int(mixed $v, int $min, int $max, \Closure $bad, string $field): int
    {
        if (is_string($v) && preg_match('/^\s*(\d{1,18})\s*$/D', $v, $m) === 1) {
            $v = (int) $m[1];
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw $bad($field, "a whole number from {$min} to " . ($max === PHP_INT_MAX ? 'any' : number_format($max)));
        }
        return $v;
    }

    /** @param \Closure(string, string, int=): CwException $bad */
    private static function text(mixed $v, int $max, \Closure $bad, string $field): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            throw $bad($field, 'must be text', 400);
        }
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v));
        if (mb_strlen($v) > $max) {
            throw $bad($field, "at most {$max} characters");
        }
        return $v === '' ? null : $v;
    }

    /**
     * The header fields of a create / save: expected_date (Y-m-d or empty), external_ref, note, doc_date, supplier_id (save).
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> only the keys given
     */
    private function headerFields(array $in, bool $save): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if (!in_array($k, self::HEADER_FIELDS, true) || ($k === 'supplier_id' && !$save)) {
                throw new CwException('bad_field', 'a purchase order header has ' . implode(', ', self::HEADER_FIELDS) . ', not ' . mb_substr((string) $k, 0, 40), 400,
                    ['field' => mb_substr((string) $k, 0, 40)]);
            }
            if ($k === 'supplier_id') {
                if (!is_int($v) && !(is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/D', $v) === 1)) {
                    throw new CwException('bad_field', 'supplier_id: a supplier', 400, ['field' => 'supplier_id']);
                }
                $out[$k] = (int) $v;
                continue;
            }
            if ($v !== null && !is_string($v)) {
                throw new CwException('bad_field', "{$k} must be text", 400, ['field' => $k]);
            }
            $v = $v === null ? null : trim($v);
            $v = $v === '' ? null : $v;
            if (($k === 'expected_date' || $k === 'doc_date') && $v !== null
                && (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1 || \DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') !== $v)) {
                throw new CwException('bad_field', str_replace('_', ' ', $k) . ': a date, YYYY-MM-DD', 400, ['field' => $k]);
            }
            if ($k === 'doc_date' && $v === null) {
                continue; // the order date is never cleared
            }
            if ($k === 'doc_date') {
                // Bounded (review nit, I81): a typo such as 2027 would set last_po_on in the future and freeze every later
                // PO price of its items until then; old open POs from ERPNext stay importable.
                $today = self::dayNo($this->today());
                if (self::dayNo($v) > $today + self::DOC_DATE_AHEAD_DAYS || self::dayNo($v) < $today - self::DOC_DATE_BACK_DAYS) {
                    throw new CwException('bad_field', 'order date: from ' . self::DOC_DATE_BACK_DAYS . ' days ago to ' . self::DOC_DATE_AHEAD_DAYS . ' days ahead', 422,
                        ['field' => 'doc_date']);
                }
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** Days since 1970-01-01 of a Y-m-d date (calendar arithmetic, no time zone). */
    private static function dayNo(string $ymd): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));
        return intdiv(gmmktime(0, 0, 0, $m, $d, $y), 86_400);
    }

    /** @return array<string, array{rate: string, active: bool}> */
    private function vatCodes(): array
    {
        if ($this->vat === null) {
            $this->vat = [];
            foreach ($this->db->all('SELECT code, rate_percent, is_active FROM vat_code') as $v) {
                $this->vat[(string) $v['code']] = ['rate' => (string) $v['rate_percent'], 'active' => (int) $v['is_active'] === 1];
            }
        }
        return $this->vat;
    }

    /**
     * @param array<int, int> $lineUnits
     */
    private function receipt(int $poId, array $lineUnits, string $grnLabel, ?Caller $caller, int $sign): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('PO receipts are applied inside the GRN posting\'s transaction');
        }
        $caller ??= Caller::system('po_receipt');
        $row = $this->db->one('SELECT * FROM document WHERE id = ? FOR UPDATE', [$poId]);
        if ($row === null || $row['doc_type'] !== 'PO' || $row['reverses_id'] !== null) {
            throw new CwException('unknown_purchase_order', 'there is no such purchase order', 404);
        }
        $po = $this->db->one('SELECT * FROM purchase_order WHERE document_id = ? FOR UPDATE', [$poId]) ?? throw new \LogicException('PO without header');
        $allowed = $sign > 0 ? self::OPEN_STATES : [...self::OPEN_STATES, 'received', 'closed'];
        if ($row['status'] !== 'posted' || !in_array($po['state'], $allowed, true)) {
            throw new CwException('po_not_receivable', ($row['number'] ?? 'this order') . ' is ' . str_replace('_', ' ', (string) ($po['state'] ?? $row['status']))
                . ($sign > 0 ? ': goods are received only against an approved, sent or part-received order' : ': its receipts cannot be taken back'), 409);
        }
        $lines = PurchaseOrderHandler::poLines($this->db, $poId);
        foreach ($lineUnits as $no => $units) {
            if (!is_int($no) || !is_int($units) || $units <= 0) {
                throw new \InvalidArgumentException('receipts are line_no => units > 0');
            }
            $l = $lines[$no] ?? throw new CwException('bad_receipt', "{$row['number']} has no line {$no}", 422, ['line' => $no]);
            if ($l['kind'] !== 'item') {
                throw new CwException('bad_receipt', "{$row['number']} line {$no} is a charge: nothing is received on it", 422, ['line' => $no]);
            }
            if ($sign < 0 && (int) $l['received_units'] < $units) {
                throw new CwException('receipt_below_zero', "{$row['number']} line {$no}: {$units} units cannot be taken back, only {$l['received_units']} were received", 409,
                    ['line' => $no]);
            }
            $this->db->exec('UPDATE po_line SET received_units = received_units + ? WHERE document_id = ? AND line_no = ?', [$sign * $units, $poId, $no]);
        }
        $any = false;
        $all = true;
        foreach ($this->db->all("SELECT dl.qty, pl.received_units FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no "
            . "WHERE pl.document_id = ? AND pl.kind = 'item'", [$poId]) as $l) {
            $any = $any || (int) $l['received_units'] > 0;
            $all = $all && (int) $l['received_units'] >= (int) $l['qty'];
        }
        $state = self::receiptState($any, $all, $po['sent_at'] !== null);
        $reopened = null;
        if ($po['state'] === 'closed') {
            if ($any) {
                $state = 'closed'; // the rest is still not expected (PO8)
            } else {
                $reopened = (string) $po['close_reason'];
            }
        }
        $this->db->exec('UPDATE purchase_order SET state = ?, closed_at = IF(? = 1, NULL, closed_at), closed_by = IF(? = 1, NULL, closed_by), '
            . 'close_reason = IF(? = 1, NULL, close_reason), updated_at = ? WHERE document_id = ?',
            [$state, $reopened !== null ? 1 : 0, $reopened !== null ? 1 : 0, $reopened !== null ? 1 : 0, $this->nowDb(), $poId]);
        Audit::write($this->db, $caller, 'po.receipt', 'document', (string) $poId, null,
            ['number' => $row['number'], 'grn' => mb_substr($grnLabel, 0, 64), 'direction' => $sign > 0 ? 'in' : 'back', 'lines' => $lineUnits, 'state' => $state]
            + ($reopened === null ? [] : ['reopened_from_closed' => $reopened]));
    }

    /**
     * @param list<int> $skuIds
     * @return array<int, int>
     */
    private function sumBySku(array $skuIds, string $sql): array
    {
        $out = [];
        $ids = array_values(array_unique(array_map('intval', $skuIds)));
        foreach ($ids as $id) {
            $out[$id] = 0;
        }
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->all(sprintf($sql, implode(', ', array_fill(0, count($chunk), '?'))), $chunk) as $r) {
                $out[(int) $r['sku_id']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> the PO's document row, X-locked (404 unknown_purchase_order) */
    private function lockPo(int $id): array
    {
        $row = $this->db->one('SELECT * FROM document WHERE id = ? FOR UPDATE', [$id]);
        if ($row === null || $row['doc_type'] !== 'PO') {
            throw new CwException('unknown_purchase_order', 'there is no such purchase order', 404);
        }
        return $row;
    }

    /**
     * A draft's row, X-locked, of this version: changed by anyone who writes orders (the caller checked by poster() first), not only its
     * creator (DA1).
     *
     * @return array<string, mixed>
     */
    private function lockDraft(int $id, int $version): array
    {
        $row = $this->lockPo($id);
        if ($row['status'] !== 'draft') {
            throw new CwException('not_draft', "{$this->label($row)} is " . str_replace('_', ' ', (string) $row['status']) . ': only a draft is changed', 409,
                ['status' => $row['status']]);
        }
        $this->checkVersion($row, $version);
        return $row;
    }

    /**
     * The version the form was drawn with (409 version_conflict): a draft's refusal names who saved it last (DraftChanges::conflict, DA3); a
     * later step of an order (cancel, send, close) keeps the plain refusal, as its last change is no draft's.
     *
     * @param array<string, mixed> $row the order's document row, X-locked
     */
    private function checkVersion(array $row, int $version): void
    {
        if ((int) $row['version'] === $version) {
            return;
        }
        if ($row['status'] === 'draft') {
            throw DraftChanges::conflict($this->db, (int) $row['id'], 'purchase order', (int) $row['version'], $version);
        }
        throw new CwException('version_conflict', 'the purchase order changed since this page was drawn: reload it and try again', 409,
            ['version' => (int) $row['version'], 'expected_version' => $version]);
    }

    /** @return array<string, mixed> */
    private function poRow(int $id): array
    {
        return $this->headerRow($id) ?? throw new CwException('po_header_missing', 'this purchase order has no header', 422);
    }

    /** @param array<string, mixed> $row */
    private function label(array $row): string
    {
        return $row['number'] ?? (($row['reverses_id'] ?? null) !== null ? 'cancellation' : 'draft') . ' #' . $row['id'];
    }

    /** @return array{id: int, roles: list<string>} */
    private function poster(Caller $caller): array
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'purchase orders are written by staff', 403);
        }
        $roles = StaffRoles::active($this->db, $caller->staffUserId);
        if (in_array('admin', $roles, true)) {
            throw new CwException('admin_cannot_post', 'admin manages people and roles and never drafts, posts or reverses documents (I12)', 403);
        }
        if (!Permissions::can($roles, 'doc.PO.post')) {
            throw new CwException('role_not_allowed', (count($roles) === 1 ? 'your role (' : 'your roles (') . ($roles === [] ? 'none' : implode(', ', $roles))
                . ') cannot write purchase orders', 403, ['type' => 'PO']);
        }
        return ['id' => $caller->staffUserId, 'roles' => $roles];
    }

    private function today(): string
    {
        return ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
    }

    private function nowDb(): string
    {
        return Clock::db(($this->clock)());
    }
}
