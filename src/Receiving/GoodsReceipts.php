<?php

declare(strict_types=1);

namespace CW\Receiving;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\PurchaseOrders\PoLinesFile;
use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Settings;
use CW\Staff\StaffRoles;
use CW\Suppliers\SupplierItems;

/**
 * Receive (+ invoice) with duty-stamp checks (IM6, Phase I-3; docs/decisions.md I125-I147): the receipt-specific side of the GRN
 * document type, on top of the document base (Documents) and its handler (GoodsReceiptHandler).
 *
 * A receipt is a `document` (type GRN, warehouse MAIN, doc_date = the day the goods arrived, external_ref = the supplier's invoice
 * number) with a `goods_receipt` header extension and one `grn_line` per `document_line`. Who does what:
 *
 *  - the purchasing desk keys it (its creator, doc.GRN.post): the supplier, the PO it is against (optional), the invoice number
 *    (compulsory; one live receipt per supplier and number), when the goods arrived (backdated with a reason for a paper receiving
 *    sheet), the lines: "receive all as ordered" from the PO (copyFromPo), the supplier's invoice or packing-list spreadsheet
 *    (importLines, CSV / XLSX), a supplier code, a barcode (a barcode counts the units it stands for, never the supplier's pack
 *    instead: I173) or a search (addLine); each line in packs x units per pack at a provisional cost, with the selling mode on
 *    receipt ("default" or a chosen mode). The invoice copy (a PDF or a photo) is attached (attach(); one copy, one live receipt
 *    of the supplier: I171). Anyone who receives goods sets the supplier invoice of a draft another person keyed (setInvoice, I172).
 *  - the goods-in bench checks it on a tablet (anyone holding doc.GRN.post: bench()): supplier and paperwork credible; per line
 *    the duty stamp (on the outer retail pack and sealing it, its type, a scanned stamp code) and the exceptions (short, over,
 *    damaged, wrong item, unstamped + what happens to them), each line counted; when the goods really arrived; photos. Only the
 *    bench writes its findings; a line the desk adds or whose quantity changes is counted again (I168). The person who did a
 *    bench check, or set the invoice without keying the receipt, never reviews it (GoodsReceiptHandler implements
 *    ReviewInvolvement, I133, I172).
 *  - posting (post(): Documents::post) books it in one transaction (GoodsReceiptHandler): accepted units into MAIN, damaged / wrong
 *    item / over into VERIFY, quarantined unstamped units into UNSTAMPED, all as goods_in through Movements::bookForDocument with
 *    the line's unit cost (cost source `document`, value seq: C0); an incident per exception; the PO's receipts and state; the
 *    items' selling modes. A second person reviews it within 3 days (GRN document_type: review `all`; a rejected review reverses
 *    it).
 *
 * Every public write: a staff caller holding doc.GRN.post (never admin), roles re-read inside the transaction; ONE Db::transaction
 * (joining an open one); the document row locked first (FOR UPDATE), its version checked (409 version_conflict) and moved; a
 * draft's header and lines changed only by its creator (403 not_creator); the bench findings by anyone allowed to post receipts.
 * Lock order: document row -> supplier (FOR SHARE) -> goods_receipt -> grn_line / document_line -> (posting: number_series -> the
 * PO document and its rows -> grn rows -> item_selling_mode -> incident -> stock, GoodsReceiptHandler).
 */
final class GoodsReceipts
{
    public const LINE_FIELDS = ['sku_id', 'supplier_item_id', 'supplier_code', 'purchase_unit', 'units_per_pack', 'packs', 'pack_price', 'po_line_no', 'entry',
        'mode_choice', 'description'];
    public const BENCH_FIELDS = ['stamp_on_pack', 'stamp_type', 'stamp_code', 'short_units', 'over_units', 'damaged_units', 'wrong_item_units', 'unstamped_units',
        'unstamped_action', 'pre_october_evidence'];
    public const HEADER_FIELDS = ['invoice_number', 'invoice_date', 'delivery_note', 'received_at', 'paper_sheet', 'backdate_reason', 'note', 'po_id', 'supplier_id'];
    public const ENTRIES = ['manual', 'scan', 'po', 'file'];
    public const MODE_CHOICES = ['default', 'In-Stock', 'From-Warehouse', 'Out-Of-Stock'];
    public const UNSTAMPED_ACTIONS = ['quarantine', 'refuse', 'accept_pre_october'];
    public const STAMP_TYPES = ['digital', 'transitional'];
    /** A line's fields as the editor names them (the refusals say these, not the column names). */
    public const LINE_LABELS = ['sku_id' => 'item', 'supplier_item_id' => 'supplier item', 'supplier_code' => 'supplier code', 'purchase_unit' => 'purchase unit',
        'units_per_pack' => 'units per pack', 'packs' => 'packs', 'pack_price' => 'pack price', 'po_line_no' => 'order line', 'entry' => 'how it was keyed',
        'mode_choice' => 'selling mode', 'description' => 'note', 'checked_at' => 'bench check time', 'line_no' => 'line'];
    /** The bench's fields as its screen names them (the refusals say these, not the column names). */
    public const BENCH_LABELS = ['stamp_on_pack' => 'stamp on the pack', 'stamp_type' => 'stamp type', 'stamp_code' => 'stamp code', 'short_units' => 'short',
        'over_units' => 'over', 'damaged_units' => 'damaged', 'wrong_item_units' => 'wrong item', 'unstamped_units' => 'unstamped',
        'unstamped_action' => 'the unstamped units', 'pre_october_evidence' => "the supplier's evidence"];
    /** Attachment role => the stored file's kind (FileStore::KINDS). */
    public const FILE_ROLES = ['supplier_invoice' => 'supplier_invoice', 'delivery_note' => 'delivery_note', 'photo' => 'photo', 'evidence' => 'duty_evidence'];
    public const MAX_LINES = 2000;
    public const MAX_EXCEPTION_UNITS = 10_000_000;
    public const INVOICE_MAX = 64;
    public const SEARCH_LIMIT = 20;
    public const OPEN_PO_STATES = ['approved', 'sent', 'part_received'];

    private readonly Settings $settings;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private ?PurchaseOrders $pos = null;

    /**
     * @param (\Closure(): FileStore)|null $files the file store (attach(): 503 file_store_unconfigured without one)
     * @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (received at, the bench's times)
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
    }

    // ------------------------------------------------------------------------------------------
    // Drafts (the purchasing desk)
    // ------------------------------------------------------------------------------------------

    /**
     * A new draft receipt from $supplierId (not inactive: 422 supplier_inactive; a draft or pending supplier is allowed, posting
     * waits until it is active). $poId: the purchase order it is against (one of this supplier's, approved, sent or part-received:
     * 422 po_not_receivable). $copyDown: "receive all as ordered" at once. Header: invoice_number, invoice_date, delivery_note,
     * received_at (default now), paper_sheet, backdate_reason, note. A supplier invoice already on a live receipt: 409
     * duplicate_invoice. Audit grn.create.
     *
     * @param array<string, mixed> $header
     */
    public function createDraft(Caller $caller, int $supplierId, array $header = [], ?int $poId = null, bool $copyDown = false): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $supplierId, $header, $poId, $copyDown): Document {
            $this->poster($caller);
            $h = $this->headerFields($header);
            unset($h['po_id'], $h['supplier_id']);
            $s = $db->one('SELECT id, code, status FROM supplier WHERE id = ? FOR SHARE', [$supplierId])
                ?? throw new CwException('unknown_supplier', 'there is no such supplier', 404);
            if ($s['status'] === 'inactive') {
                throw new CwException('supplier_inactive', "supplier {$s['code']} is inactive: nothing is received from it", 422);
            }
            if ($poId !== null) {
                $this->checkPo($poId, $supplierId);
            }
            $received = $h['received_at'] ?? Clock::db($this->now());
            $doc = $this->docs->createDraft($caller, 'GRN', ['external_ref' => $h['invoice_number'] ?? null, 'doc_date' => self::ukDate($received), 'warehouse' => 'MAIN',
                'note' => $h['note'] ?? null]);
            $now = Clock::db($this->now());
            $key = ReceiptMath::invoiceKey($h['invoice_number'] ?? null);
            try {
                $db->exec('INSERT INTO goods_receipt (document_id, supplier_id, po_document_id, invoice_key, invoice_date, delivery_note, received_at, paper_sheet, '
                    . 'backdate_reason, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$doc->id, $supplierId, $poId, $key === '' ? null : $key, $h['invoice_date'] ?? null, $h['delivery_note'] ?? null, $received,
                        ($h['paper_sheet'] ?? false) ? 1 : 0, $h['backdate_reason'] ?? null, $now, $now]);
            } catch (\PDOException $e) {
                throw $this->duplicate($e, $supplierId, $key);
            }
            Audit::write($db, $caller, 'grn.create', 'document', (string) $doc->id, null,
                ['supplier' => (string) $s['code'], 'po' => $poId, 'invoice' => $h['invoice_number'] ?? null, 'received_at' => $received]);
            if ($copyDown && $poId !== null) {
                $doc = $this->copyFromPo($caller, $doc->id, $doc->version);
            }
            return $doc;
        });
    }

    /**
     * Saves a draft: the header (HEADER_FIELDS; the supplier changes only while the receipt has no lines, the PO only while no
     * line is received against it: 409 receipt_has_lines) and EVERY line (the list replaces the draft's lines; [] clears them).
     * Creator only. Version + 2 (Documents::updateDraft, then Documents::setLines, whose cascade removes the old grn_line rows),
     * then the grn_line rows.
     *
     * The bench's findings are never taken from the caller (only bench() writes them, audited grn.bench; I168): a line keeps the
     * findings and the check time of the stored line it came from (`line_no`, as lines() gives it) only while its item, supplier
     * item, units per pack and packs are unchanged.
     * A line added, or whose quantity or item changed, starts unchecked: the bench counts it again before the receipt posts.
     *
     * A line: {sku_id or supplier_item_id, packs, units_per_pack?, supplier_code?, purchase_unit?, pack_price?, po_line_no?
     * (null: linked to the PO's line of the item when there is one; 0: not received against the PO), entry?, mode_choice?,
     * description?, line_no? (the stored line it came from)}; the bench fields lines() returns with it are accepted and ignored. A supplier item gives the pack,
     * the unit and the code; without one the units per pack given (default 1), linked to this supplier's active supplier item of
     * that item and pack when there is one. The price (GBP excl. VAT per pack: provisional cost) defaults to the PO line's
     * (scaled to the pack), then the supplier item's last price, then its last PO price, else 0 (warned).
     *
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $lines
     */
    public function saveDraft(Caller $caller, int $id, int $version, array $header, array $lines): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $header, $lines): Document {
            $me = $this->poster($caller);
            $this->lockDraft($id, $version, $me);
            $h = $this->headerFields($header);
            $gr = $db->one('SELECT * FROM goods_receipt WHERE document_id = ? FOR UPDATE', [$id]) ?? throw new \LogicException("receipt {$id} has no header");
            $hasLines = (int) $db->value('SELECT COUNT(*) FROM document_line WHERE document_id = ?', [$id]) > 0;
            $supplierId = (int) $gr['supplier_id'];
            $poId = $gr['po_document_id'] === null ? null : (int) $gr['po_document_id'];
            $set = [];
            if (array_key_exists('supplier_id', $h) && $h['supplier_id'] !== null && $h['supplier_id'] !== $supplierId) {
                if ($hasLines) {
                    throw new CwException('receipt_has_lines', 'the supplier of a receipt changes only while it has no lines: remove them first, or start a new receipt', 409);
                }
                $s = $db->one('SELECT id, code, status FROM supplier WHERE id = ? FOR SHARE', [$h['supplier_id']])
                    ?? throw new CwException('unknown_supplier', 'there is no such supplier', 422, ['field' => 'supplier_id']);
                if ($s['status'] === 'inactive') {
                    throw new CwException('supplier_inactive', "supplier {$s['code']} is inactive: nothing is received from it", 422);
                }
                $supplierId = (int) $s['id'];
                $set['supplier_id'] = $supplierId;
                if ($poId !== null && !array_key_exists('po_id', $h)) {
                    $h['po_id'] = null; // a PO is the supplier's: changing the supplier takes it off
                }
            }
            if (array_key_exists('po_id', $h) && $h['po_id'] !== $poId) {
                if ($db->value('SELECT 1 FROM grn_line WHERE document_id = ? AND po_line_no IS NOT NULL LIMIT 1', [$id]) !== null) {
                    throw new CwException('receipt_has_lines', 'the purchase order of a receipt changes only while no line is received against it: set its lines to '
                        . '"not against the order" first, or start a new receipt', 409);
                }
                if ($h['po_id'] !== null) {
                    $this->checkPo($h['po_id'], $supplierId);
                }
                $poId = $h['po_id'];
                $set['po_document_id'] = $poId;
            }
            $docHeader = [];
            if (array_key_exists('invoice_number', $h)) {
                $docHeader['external_ref'] = $h['invoice_number'];
                $key = ReceiptMath::invoiceKey($h['invoice_number']);
                $set['invoice_key'] = $key === '' ? null : $key;
            }
            if (array_key_exists('note', $h)) {
                $docHeader['note'] = $h['note'];
            }
            foreach (['invoice_date', 'delivery_note', 'backdate_reason'] as $k) {
                if (array_key_exists($k, $h)) {
                    $set[$k] = $h[$k];
                }
            }
            if (array_key_exists('paper_sheet', $h)) {
                $set['paper_sheet'] = $h['paper_sheet'] ? 1 : 0;
            }
            if (array_key_exists('received_at', $h) && $h['received_at'] !== null) {
                $set['received_at'] = $h['received_at'];
                $docHeader['doc_date'] = self::ukDate($h['received_at']);
            }
            $paper = (int) ($set['paper_sheet'] ?? $gr['paper_sheet']) === 1;
            $reason = array_key_exists('backdate_reason', $set) ? $set['backdate_reason'] : $gr['backdate_reason'];
            if ($paper && $reason === null) {
                throw new CwException('backdate_reason_required', 'a receipt keyed from a paper receiving sheet says why (CW was down, ...): fill in the reason', 422,
                    ['field' => 'backdate_reason']);
            }
            $norm = $this->normaliseLines($id, $supplierId, $poId, $lines);
            $doc = $this->docs->updateDraft($caller, $id, $version, $docHeader);
            $doc = $this->docs->setLines($caller, $id, $doc->version, array_map(static fn (array $n): array => $n['doc'], $norm));
            $this->insertLines($id, $norm);
            $set['updated_at'] = Clock::db($this->now());
            try {
                $db->exec('UPDATE goods_receipt SET ' . implode(', ', array_map(static fn (string $k): string => "`{$k}` = ?", array_keys($set))) . ' WHERE document_id = ?',
                    [...array_values($set), $id]);
            } catch (\PDOException $e) {
                throw $this->duplicate($e, $supplierId, (string) (array_key_exists('invoice_key', $set) ? $set['invoice_key'] : $gr['invoice_key']), $id);
            }
            Audit::write($db, $caller, 'grn.save', 'document', (string) $id, null, ['version' => $doc->version, 'lines' => count($norm)]
                + (isset($set['supplier_id']) ? ['supplier' => $set['supplier_id']] : []) + (array_key_exists('po_document_id', $set) ? ['po' => $set['po_document_id']] : []));
            return $doc;
        });
    }

    /**
     * Adds a line from what was scanned or typed: a usable barcode (scanned()), this supplier's code, a CW code, or a search
     * (`choices`; the PO editor's rules, PurchaseOrders::resolve, for everything but a barcode). The same supplier item (or item
     * and pack) already on the receipt gets the packs added (`incremented`); else a new line (`added`), received against the
     * PO's line of the item when there is one. $price: the pack price typed with it (GBP; null keeps the line's). Saves the draft
     * (version + 2).
     *
     * A barcode counts the units it stands for (units_per_scan k: 1 for a unit's own barcode, k for an outer case), never the
     * supplier's pack instead (I173: the boxes-for-units bug): this supplier's supplier item of pack k gets one pack; else the
     * largest of its packs that divides k gets k / pack packs (a case of 10 = 10 packs of 1, or 2 packs of 5); else a line in
     * packs of k without a supplier item (a case of 5 when the supplier sells boxes of 10). One unit scanned when the supplier
     * sells the item only in bigger packs is ambiguous (one bottle, or one box?): the receipt's own line of the item is
     * incremented when there is exactly one, else `choices` asks once (the supplier's pack, or single units).
     *
     * @return array{status: string, document: Document, line_no?: int, units_added?: int, sku_id?: int, note?: ?string,
     *   choices?: list<array<string, mixed>>, choice_note?: string, q: string}
     */
    public function addLine(Caller $caller, int $id, int $version, string $q, int $packs = 1, ?string $price = null): array
    {
        $q = trim($q);
        return $this->db->transaction(function () use ($caller, $id, $version, $q, $packs, $price): array {
            $me = $this->poster($caller);
            $this->lockDraft($id, $version, $me);
            $supplierId = (int) ($this->header($id)['supplier_id'] ?? 0);
            $scan = $this->scanned($id, $supplierId, $q);
            if ($scan !== null) {
                if ($scan['status'] === 'choices') {
                    return ['status' => 'choices', 'document' => $this->docs->get($id), 'choices' => $scan['choices'], 'choice_note' => $scan['note'], 'q' => $q];
                }
                if ($packs < 1 || $packs > PoMath::MAX_PACKS) {
                    throw new CwException('bad_field', 'packs: a whole number from 1 to ' . number_format(PoMath::MAX_PACKS), 422, ['field' => 'packs']);
                }
                return $this->addResolved($caller, $id, $version, $scan['line'], $packs * $scan['per_scan'], 'scan', $price, $scan['note'] ?? null) + ['q' => $q];
            }
            $r = $this->pos()->resolve($supplierId, $q);
            if ($r['status'] !== 'found') {
                return ['status' => $r['status'], 'document' => $this->docs->get($id), 'choices' => $r['choices'] ?? [], 'q' => $q];
            }
            $si = $r['supplier_item'];
            return $this->addResolved($caller, $id, $version, ['sku_id' => (int) $r['sku_id'], 'supplier_item_id' => $si === null ? null : (int) $si['id'],
                'units_per_pack' => (int) $r['units_per_pack'], 'purchase_unit' => (string) $r['purchase_unit']], $packs, 'scan', $price) + ['q' => $q];
        });
    }

    /**
     * Adds (or increments) the line of one of the supplier's supplier items, or of an item without one (packs of 1): the
     * editor's "Add" buttons of a search.
     *
     * @return array{status: string, document: Document, line_no: int, units_added: int, sku_id: int, note: ?string}
     */
    public function addChoice(Caller $caller, int $id, int $version, ?int $supplierItemId, ?int $skuId, int $packs = 1, ?string $price = null): array
    {
        return $this->db->transaction(function () use ($caller, $id, $version, $supplierItemId, $skuId, $packs, $price): array {
            $me = $this->poster($caller);
            $this->lockDraft($id, $version, $me);
            $supplierId = (int) ($this->header($id)['supplier_id'] ?? 0);
            if ($supplierItemId !== null) {
                $si = $this->db->one('SELECT * FROM supplier_item WHERE id = ? AND supplier_id = ? AND is_active = 1', [$supplierItemId, $supplierId])
                    ?? throw new CwException('unknown_supplier_item', 'this supplier has no such active supplier item', 422);
                $line = ['sku_id' => (int) $si['sku_id'], 'supplier_item_id' => (int) $si['id'], 'units_per_pack' => (int) $si['units_per_pack'],
                    'purchase_unit' => (string) $si['purchase_unit']];
            } else {
                $sku = $this->db->one('SELECT id FROM sku WHERE id = ? AND merged_into_sku_id IS NULL', [(int) $skuId])
                    ?? throw new CwException('unknown_sku', 'there is no such item', 422);
                $line = ['sku_id' => (int) $sku['id'], 'supplier_item_id' => null, 'units_per_pack' => 1, 'purchase_unit' => 'each'];
            }
            return $this->addResolved($caller, $id, $version, $line, $packs, 'manual', $price);
        });
    }

    /**
     * "Receive all as ordered": adds a line for every item line of the receipt's PO that still expects units and is not on the
     * receipt yet (by PO line), in the PO's packs (outstanding / units per pack; when the outstanding units are not whole packs:
     * packs of 1 at the PO's unit cost) and at the PO's price. The desk then edits what arrived differently. Version + 2.
     */
    public function copyFromPo(Caller $caller, int $id, int $version): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version): Document {
            $me = $this->poster($caller);
            $this->lockDraft($id, $version, $me);
            $gr = $this->header($id) ?? throw new \LogicException("receipt {$id} has no header");
            if ($gr['po_document_id'] === null) {
                throw new CwException('no_purchase_order', 'this receipt is not against a purchase order: choose the order first', 409);
            }
            $poId = (int) $gr['po_document_id'];
            $this->checkPo($poId, (int) $gr['supplier_id']);
            $lines = $this->lines($id);
            $have = [];
            foreach ($lines as $l) {
                if (($l['po_line_no'] ?? 0) > 0) {
                    $have[(int) $l['po_line_no']] = true;
                }
            }
            $added = 0;
            foreach ($this->pos()->openLines($poId) as $o) {
                if (isset($have[$o['line_no']])) {
                    continue;
                }
                $upp = $o['units_per_pack'];
                $whole = $o['units_outstanding'] % $upp === 0;
                $lines[] = [
                    'sku_id' => $o['sku_id'],
                    'supplier_item_id' => $whole ? $o['supplier_item_id'] : null,
                    'supplier_code' => $o['supplier_code'],
                    'purchase_unit' => $whole ? $o['purchase_unit'] : 'each',
                    'units_per_pack' => $whole ? $upp : 1,
                    'packs' => $whole ? intdiv($o['units_outstanding'], $upp) : $o['units_outstanding'],
                    'pack_price' => $whole ? $o['pack_price'] : PoMath::fromE4(intdiv(PoMath::e6($o['unit_cost']) + 50, 100)),
                    'po_line_no' => $o['line_no'],
                    'entry' => 'po',
                ];
                $added++;
            }
            if ($added === 0) {
                throw new CwException('nothing_outstanding', 'every line of the order that still expects units is on this receipt already', 409);
            }
            return $this->saveDraft($caller, $id, $version, [], $lines);
        });
    }

    /**
     * Imports lines from the supplier's invoice or packing-list spreadsheet (CSV or XLSX; ReceiptLinesFile): ALL OR NOTHING for the
     * rows that name an item (a code, a barcode or a CW code); rows that name none (totals, delivery charges) are skipped and
     * listed. `append` adds the lines (the same supplier item gets the packs added); `replace` replaces every line (their bench
     * findings with them). Audit grn.import_lines.
     *
     * @return array{lines: int, errors: list<string>, skipped: list<string>, document: Document}
     */
    public function importLines(Caller $caller, int $id, int $version, string $path, string $name, string $mode): array
    {
        if (!in_array($mode, ['append', 'replace'], true)) {
            throw new CwException('bad_field', 'mode is append or replace', 400, ['field' => 'mode']);
        }
        $file = ReceiptLinesFile::read($path);
        $sha = (string) hash_file('sha256', $path);
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $file, $sha, $name, $mode): array {
            $me = $this->poster($caller);
            $this->lockDraft($id, $version, $me);
            $supplierId = (int) ($this->header($id)['supplier_id'] ?? 0);
            $parsed = ReceiptLinesFile::parse($file, fn (string $type, string $value): array|string => $this->resolveForFile($supplierId, $type, $value));
            if ($parsed['errors'] !== []) {
                return ['lines' => 0, 'errors' => $parsed['errors'], 'skipped' => $parsed['skipped'], 'document' => $this->docs->get($id)];
            }
            $lines = $mode === 'replace' ? [] : $this->lines($id);
            foreach ($parsed['lines'] as $l) {
                unset($l['row']);
                $merged = false;
                if ($mode === 'append') {
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
                    $lines[] = $l + ['entry' => 'file', 'po_line_no' => null];
                }
            }
            $doc = $this->saveDraft($caller, $id, $version, [], $lines);
            Audit::write($db, $caller, 'grn.import_lines', 'document', (string) $id, null, ['file' => mb_substr(basename($name), 0, 120), 'sha256' => $sha,
                'format' => $file['format'], 'rows' => count($parsed['lines']), 'skipped' => count($parsed['skipped']), 'mode' => $mode]);
            return ['lines' => count($parsed['lines']), 'errors' => [], 'skipped' => $parsed['skipped'], 'document' => $doc];
        });
    }

    // ------------------------------------------------------------------------------------------
    // The goods-in bench
    // ------------------------------------------------------------------------------------------

    /**
     * Records the goods-in bench check (any staff holding doc.GRN.post, the creator or not; never admin): $header paperwork_ok
     * ('1' supplier and paperwork credible, '0' not, '' not checked yet), bench_note, and arrived_now (true: the goods arrived now,
     * not when the receipt was keyed: received_at and the document date become now, I170); $lines line_no => the bench fields
     * (BENCH_FIELDS) of the lines counted now (their check time becomes now; `checked` => false records the fields but leaves the
     * line uncounted). Every exception is in central units and together they never exceed the line's units (short + damaged +
     * wrong item + unstamped; over is extra, and more over units than the line has are taken only with `over_confirmed`). Unstamped
     * units need what happens to them (quarantine, refuse, or accept with the supplier's evidence of manufacture before 1 Oct
     * 2026); "no stamp on the pack" needs every unit that arrived counted as unstamped. Draft only; version + 1; audit grn.bench
     * (the audit row is what keeps its author out of the receipt's review, I133).
     *
     * @param array<string, mixed> $header
     * @param array<int, array<string, mixed>> $lines
     */
    public function bench(Caller $caller, int $id, int $version, array $header, array $lines): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $header, $lines): Document {
            $this->poster($caller);
            $row = $this->lockGrn($id);
            if ($row['status'] !== 'draft') {
                throw new CwException('not_draft', 'this receipt is ' . str_replace('_', ' ', (string) $row['status']) . ': the bench checks a receipt before it is posted', 409);
            }
            $this->checkVersion($row, $version);
            $gr = $db->one('SELECT * FROM goods_receipt WHERE document_id = ? FOR UPDATE', [$id]) ?? throw new \LogicException("receipt {$id} has no header");
            $current = [];
            foreach ($db->all('SELECT g.*, dl.qty FROM grn_line g JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no '
                . 'WHERE g.document_id = ? ORDER BY g.line_no FOR UPDATE', [$id]) as $l) {
                $current[(int) $l['line_no']] = $l;
            }
            $now = Clock::db($this->now());
            $changed = [];
            foreach ($lines as $no => $f) {
                if (!is_int($no) || !isset($current[$no])) {
                    throw new CwException('bad_line', "the receipt has no line {$no}", 422, ['line' => $no]);
                }
                if (!is_array($f)) {
                    throw new CwException('bad_line', "line {$no}: the bench fields", 400, ['line' => $no]);
                }
                $units = (int) $current[$no]['packs'] * (int) $current[$no]['units_per_pack'];
                $b = self::benchFields($no, $f, $units);
                // More units over than the line has: a typo is likelier than a delivery twice the invoice (I174). Taken when the bench
                // confirms it, or when it is what was already recorded (confirmed then).
                $over = $b['over_units'];
                $confirmed = in_array($f['over_confirmed'] ?? null, [true, 1, '1', 'on'], true);
                if ($over > $units && $over !== (int) $current[$no]['over_units'] && !$confirmed) {
                    throw new CwException('over_unconfirmed', "Line {$no}: {$over} units over on a line of {$units}, more than the paperwork itself. Count again; "
                        . 'if it is right, tick "the over count is right".', 422, ['line' => $no, 'field' => 'over_units']);
                }
                $counted = !in_array($f['checked'] ?? true, [false, 0, '0', ''], true);
                $db->exec('UPDATE grn_line SET stamp_on_pack = ?, stamp_type = ?, stamp_code = ?, short_units = ?, over_units = ?, damaged_units = ?, wrong_item_units = ?, '
                    . 'unstamped_units = ?, unstamped_action = ?, pre_october_evidence = ?, checked_at = ? WHERE document_id = ? AND line_no = ?',
                    [$b['stamp_on_pack'], $b['stamp_type'], $b['stamp_code'], $b['short_units'], $b['over_units'], $b['damaged_units'], $b['wrong_item_units'],
                        $b['unstamped_units'], $b['unstamped_action'], $b['pre_october_evidence'], $counted ? $now : null, $id, $no]);
                $changed[$no] = $b + ($counted ? [] : ['counted' => false]);
            }
            $set = [];
            if (array_key_exists('paperwork_ok', $header)) {
                $p = $header['paperwork_ok'];
                $ok = match (true) {
                    $p === null, $p === '' => null,
                    $p === '1', $p === 1, $p === true => 1,
                    $p === '0', $p === 0, $p === false => 0,
                    default => throw new CwException('bad_field', 'paperwork_ok: 1 (credible), 0 (not credible) or empty (not checked yet)', 400, ['field' => 'paperwork_ok']),
                };
                $set = ['paperwork_ok' => $ok, 'checked_by' => $ok === null ? null : $caller->staffUserId, 'checked_actor' => $ok === null ? null : $caller->actor,
                    'checked_at' => $ok === null ? null : $now];
            }
            if (array_key_exists('bench_note', $header)) {
                $set['bench_note'] = self::text($header['bench_note'], 500, 'bench_note');
            }
            $arrived = null;
            if (in_array($header['arrived_now'] ?? null, [true, 1, '1', 'on'], true)) {
                // The goods arrived now, not when the desk started the receipt (keyed from the invoice before they came, I170).
                $arrived = ['from' => (string) $gr['received_at'], 'to' => $now];
                $set['received_at'] = $now;
                $db->exec('UPDATE document SET doc_date = ? WHERE id = ?', [self::ukDate($now), $id]);
            }
            $set['updated_at'] = $now;
            $db->exec('UPDATE goods_receipt SET ' . implode(', ', array_map(static fn (string $k): string => "`{$k}` = ?", array_keys($set))) . ' WHERE document_id = ?',
                [...array_values($set), $id]);
            $db->exec('UPDATE document SET version = version + 1, updated_at = ? WHERE id = ?', [$now, $id]);
            Audit::write($db, $caller, 'grn.bench', 'document', (string) $id, null, ['version' => $version + 1, 'lines' => $changed]
                + (array_key_exists('paperwork_ok', $set) ? ['paperwork_ok' => $set['paperwork_ok']] : []) + (isset($set['bench_note']) ? ['note' => $set['bench_note']] : [])
                + ($arrived === null ? [] : ['received_at' => $arrived]));
            return $this->docs->get($id);
        });
    }

    /**
     * The supplier invoice of a draft, set by anyone allowed to receive goods (I172): the invoice number (compulsory at posting,
     * one live receipt per supplier and number: 409 duplicate_invoice), its date and the delivery note. A receipt started at the
     * bench from the delivery note is completed by the desk this way; its lines stay with the person who keyed them. Version + 1;
     * audit grn.invoice (a person who set the invoice of a receipt they did not key never reviews it, I133).
     *
     * @param array<string, mixed> $fields invoice_number, invoice_date, delivery_note (only the keys given)
     */
    public function setInvoice(Caller $caller, int $id, int $version, array $fields): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $fields): Document {
            $this->poster($caller);
            $row = $this->lockGrn($id);
            if ($row['status'] !== 'draft') {
                throw new CwException('not_draft', $this->label($row) . ' is ' . str_replace('_', ' ', (string) $row['status']) . ': only a draft is changed', 409);
            }
            $this->checkVersion($row, $version);
            $h = $this->headerFields(array_intersect_key($fields, array_flip(['invoice_number', 'invoice_date', 'delivery_note'])));
            if ($h === []) {
                throw new CwException('bad_field', 'give the supplier invoice number, its date or the delivery note', 400);
            }
            $gr = $db->one('SELECT * FROM goods_receipt WHERE document_id = ? FOR UPDATE', [$id]) ?? throw new \LogicException("receipt {$id} has no header");
            $now = Clock::db($this->now());
            $set = ['updated_at' => $now];
            $doc = ['version = version + 1', 'updated_at = ?'];
            $docParams = [$now];
            if (array_key_exists('invoice_number', $h)) {
                $key = ReceiptMath::invoiceKey($h['invoice_number']);
                $set['invoice_key'] = $key === '' ? null : $key;
                $doc[] = 'external_ref = ?';
                $docParams[] = $h['invoice_number'];
            }
            foreach (['invoice_date', 'delivery_note'] as $k) {
                if (array_key_exists($k, $h)) {
                    $set[$k] = $h[$k];
                }
            }
            try {
                $db->exec('UPDATE goods_receipt SET ' . implode(', ', array_map(static fn (string $k): string => "`{$k}` = ?", array_keys($set))) . ' WHERE document_id = ?',
                    [...array_values($set), $id]);
            } catch (\PDOException $e) {
                throw $this->duplicate($e, (int) $gr['supplier_id'], (string) ($set['invoice_key'] ?? $gr['invoice_key'] ?? ''), $id);
            }
            $db->exec('UPDATE document SET ' . implode(', ', $doc) . ' WHERE id = ?', [...$docParams, $id]);
            Audit::write($db, $caller, 'grn.invoice', 'document', (string) $id, null, ['version' => $version + 1] + $h);
            return $this->docs->get($id);
        });
    }

    /**
     * Stores a file (FileStore: type sniffed, deduplicated, kept 7 years) and attaches it to the receipt under $role: the
     * supplier's invoice (a PDF, or a photo of a paper invoice: 415 otherwise), a delivery note, a photo (the bench's), duty
     * evidence (a supplier's "made before 1 Oct 2026" certificate). Any receipt but a cancelled one (photos may follow the
     * posting). Staff holding doc.GRN.post.
     *
     * @return array{file_id: int, mime: string, attached: bool, deduped: bool}
     */
    public function attach(Caller $caller, int $id, string $path, string $name, string $role, ?string $note = null): array
    {
        if (!isset(self::FILE_ROLES[$role])) {
            throw new CwException('bad_file_role', 'attach the file as ' . implode(', ', array_keys(self::FILE_ROLES)), 400, ['field' => 'role']);
        }
        if ($role === 'supplier_invoice' && is_file($path)) {
            // Sniffed before it is stored: a file that cannot be the invoice copy never reaches the write-once store.
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!in_array($mime, ReceiptPlan::INVOICE_MIMES, true)) {
                throw new CwException('type_not_allowed', "the supplier's invoice is attached as a PDF or a photo (JPEG, PNG), not {$mime}", 415);
            }
        }
        $files = $this->files === null ? throw new CwException('file_store_unconfigured', 'the file store is not set up on this server', 503) : ($this->files)();
        return $this->db->transaction(function (Db $db) use ($caller, $id, $path, $name, $role, $note, $files): array {
            $this->poster($caller);
            $row = $this->lockGrn($id);
            if ($row['status'] === 'cancelled') {
                throw new CwException('document_cancelled', 'a cancelled receipt takes no files', 409);
            }
            $stored = $files->store($caller, $path, $name, self::FILE_ROLES[$role], $note);
            if ($role === 'supplier_invoice' && !in_array($stored['mime'], ReceiptPlan::INVOICE_MIMES, true)) {
                throw new CwException('type_not_allowed', "the supplier's invoice is attached as a PDF or a photo (JPEG, PNG), not {$stored['mime']}", 415);
            }
            if ($role === 'supplier_invoice' && $stored['deduped']) {
                // The same invoice copy already on another live receipt of this supplier (its number typed differently, I171).
                $supplierId = (int) $db->value('SELECT supplier_id FROM goods_receipt WHERE document_id = ?', [$id]);
                $other = $db->one("SELECT d.id, d.number, d.status FROM document_file df JOIN goods_receipt g ON g.document_id = df.document_id AND g.supplier_id = ? "
                    . "JOIN document d ON d.id = df.document_id AND d.status IN ('draft', 'awaiting_approval', 'posted') WHERE df.file_id = ? AND df.role = 'supplier_invoice' "
                    . 'AND df.document_id <> ? ORDER BY d.id LIMIT 1', [$supplierId, $stored['id'], $id]);
                if ($other !== null) {
                    $label = $other['number'] ?? (str_replace('_', ' ', (string) $other['status']) . ' receipt #' . $other['id']);
                    throw new CwException('invoice_copy_elsewhere', "this file is already the supplier invoice of {$label}: a supplier's invoice is received once "
                        . '(attach the right invoice, or cancel the other receipt if it was keyed by mistake)', 409, ['other' => (int) $other['id']]);
                }
            }
            $attached = $files->attach($caller, $id, $stored['id'], $role);
            return ['file_id' => $stored['id'], 'mime' => $stored['mime'], 'attached' => $attached, 'deduped' => $stored['deduped']];
        });
    }

    /** Posts a draft receipt (Documents::post; GoodsReceiptHandler books it). */
    public function post(Caller $caller, int $id, int $version): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version): Document {
            $this->poster($caller);
            $row = $this->lockGrn($id);
            if ($row['status'] !== 'draft') {
                throw new CwException('not_draft', $this->label($row) . ' is ' . str_replace('_', ' ', (string) $row['status']) . ': only a draft is posted', 409,
                    ['status' => $row['status']]);
            }
            return $this->docs->post($caller, $id, $version);
        });
    }

    /** Cancels a draft receipt (never numbered, nothing booked); its supplier invoice number is free again. Reason 3-500 characters. */
    public function cancel(Caller $caller, int $id, int $version, string $reason): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $reason): Document {
            $this->poster($caller);
            $this->lockGrn($id);
            $doc = $this->docs->cancelDraft($caller, $id, $version, $reason);
            $db->exec('UPDATE goods_receipt SET invoice_key = NULL, updated_at = ? WHERE document_id = ?', [Clock::db($this->now()), $id]);
            Audit::write($db, $caller, 'grn.cancel', 'document', (string) $id, null, ['reason' => trim($reason)]);
            return $doc;
        });
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null the goods_receipt row */
    public function header(int $id): ?array
    {
        return $this->db->one('SELECT * FROM goods_receipt WHERE document_id = ?', [$id]);
    }

    /**
     * The lines of a receipt as saveDraft() takes them (line_no: the stored line, which carries its bench findings while its
     * quantity is unchanged, I168): po_line_no 0 = not received against the PO.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(int $id): array
    {
        $out = [];
        foreach ($this->db->all('SELECT g.*, dl.sku_id, dl.description FROM grn_line g JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no '
            . 'WHERE g.document_id = ? ORDER BY g.line_no', [$id]) as $r) {
            $out[] = [
                'line_no' => (int) $r['line_no'],
                'sku_id' => (int) $r['sku_id'],
                'supplier_item_id' => $r['supplier_item_id'] === null ? null : (int) $r['supplier_item_id'],
                'supplier_code' => $r['supplier_code'] === null ? null : (string) $r['supplier_code'],
                'purchase_unit' => (string) $r['purchase_unit'],
                'units_per_pack' => (int) $r['units_per_pack'],
                'packs' => (int) $r['packs'],
                'pack_price' => (string) $r['pack_price'],
                'po_line_no' => $r['po_line_no'] === null ? 0 : (int) $r['po_line_no'],
                'entry' => (string) $r['entry'],
                'mode_choice' => (string) $r['mode_choice'],
                'description' => $r['description'] === null ? null : (string) $r['description'],
                'checked_at' => $r['checked_at'] === null ? null : (string) $r['checked_at'],
                'stamp_on_pack' => $r['stamp_on_pack'] === null ? null : (int) $r['stamp_on_pack'],
                'stamp_type' => $r['stamp_type'] === null ? null : (string) $r['stamp_type'],
                'stamp_code' => $r['stamp_code'] === null ? null : (string) $r['stamp_code'],
                'short_units' => (int) $r['short_units'],
                'over_units' => (int) $r['over_units'],
                'damaged_units' => (int) $r['damaged_units'],
                'wrong_item_units' => (int) $r['wrong_item_units'],
                'unstamped_units' => (int) $r['unstamped_units'],
                'unstamped_action' => $r['unstamped_action'] === null ? null : (string) $r['unstamped_action'],
                'pre_october_evidence' => $r['pre_october_evidence'] === null ? null : (string) $r['pre_october_evidence'],
            ];
        }
        return $out;
    }

    /**
     * A stamp of what the desk's editor shows and changes (the header and every line's own fields, not the bench's findings): a
     * desk save refused only because the bench saved meanwhile leaves it unchanged, so the editor saves again on the current
     * version instead of throwing the typed edits away (I175).
     */
    public function deskStamp(int $id): string
    {
        $d = $this->db->one('SELECT external_ref, note FROM document WHERE id = ?', [$id]) ?? [];
        $g = $this->header($id) ?? [];
        $parts = [$d['external_ref'] ?? null, $d['note'] ?? null];
        foreach (['supplier_id', 'po_document_id', 'invoice_date', 'delivery_note', 'received_at', 'paper_sheet', 'backdate_reason'] as $k) {
            $parts[] = $g[$k] ?? null;
        }
        foreach ($this->lines($id) as $l) {
            $parts[] = [$l['line_no'], $l['sku_id'], $l['supplier_item_id'], $l['units_per_pack'], $l['packs'], $l['pack_price'], $l['po_line_no'], $l['mode_choice'],
                $l['description'], $l['supplier_code'], $l['purchase_unit']];
        }
        return substr(sha1(json_encode($parts, JSON_THROW_ON_ERROR)), 0, 20);
    }

    /**
     * Stamps of what the bench's page shows and changes: 'checklist' (the delivery's checklist and its received time) and each line
     * (line_no => its item and quantity, its findings and its check time). A bench save refused only because the desk saved
     * something else meanwhile (a price, a note) finds its stamps unchanged and saves again on the current version (I175).
     *
     * @return array{checklist: string, lines: array<int, string>, identity: array<int, string>}
     */
    public function benchStamps(int $id): array
    {
        $g = $this->header($id) ?? [];
        $out = ['checklist' => substr(sha1(json_encode([$g['paperwork_ok'] ?? null, $g['bench_note'] ?? null, $g['checked_by'] ?? null, $g['received_at'] ?? null],
            JSON_THROW_ON_ERROR)), 0, 20), 'lines' => [], 'identity' => []];
        foreach ($this->lines($id) as $l) {
            $identity = self::identity($l['sku_id'], $l['supplier_item_id'], $l['units_per_pack'], $l['packs']);
            $out['identity'][$l['line_no']] = $identity;
            $bench = [$identity, $l['checked_at']];
            foreach (self::BENCH_FIELDS as $k) {
                $bench[] = $l[$k];
            }
            $out['lines'][$l['line_no']] = substr(sha1(json_encode($bench, JSON_THROW_ON_ERROR)), 0, 20);
        }
        return $out;
    }

    /** What posting would do and what stops it now (ReceiptPlan::build, no locks): the editor's and the bench view's checklist. */
    public function plan(int $id): array
    {
        return ReceiptPlan::build($this->db, $id, $this->now(), false, false, $this->settings);
    }

    /**
     * The purchase orders goods can be received against (posted; approved, sent or part-received), newest first; of one supplier
     * when given.
     *
     * @return list<array<string, mixed>>
     */
    public function receivableOrders(?int $supplierId = null, int $limit = 200): array
    {
        $params = [];
        $where = "d.doc_type = 'PO' AND d.status = 'posted' AND d.reverses_id IS NULL AND p.state IN ('approved', 'sent', 'part_received')";
        if ($supplierId !== null) {
            $where .= ' AND p.supplier_id = ?';
            $params[] = $supplierId;
        }
        return $this->db->all(
            'SELECT d.id, d.number, d.doc_date, p.state, p.expected_date, p.supplier_id, s.code AS supplier_code, s.name AS supplier_name, '
            . "(SELECT COALESCE(SUM(GREATEST(0, dl.qty - pl.received_units)), 0) FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id "
            . "AND dl.line_no = pl.line_no WHERE pl.document_id = d.id AND pl.kind = 'item') AS outstanding "
            . "FROM document d JOIN purchase_order p ON p.document_id = d.id JOIN supplier s ON s.id = p.supplier_id WHERE {$where} ORDER BY d.id DESC LIMIT " . $limit,
            $params,
        );
    }

    /**
     * Stock now of these items: on_hand at MAIN and the available units at the sellable warehouses.
     *
     * @param list<int> $skuIds
     * @return array<int, array{on_hand: int, available: int}>
     */
    public function stockNow(array $skuIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $skuIds)));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['on_hand' => 0, 'available' => 0];
        }
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->all('SELECT b.sku_id, SUM(IF(w.code = \'MAIN\', b.on_hand, 0)) AS on_hand, SUM(IF(w.is_sellable = 1, b.on_hand - b.allocated - b.held, 0)) AS available '
                . 'FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id WHERE b.sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') GROUP BY b.sku_id',
                $chunk) as $r) {
                $out[(int) $r['sku_id']] = ['on_hand' => (int) $r['on_hand'], 'available' => (int) $r['available']];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * @param array{sku_id: int, supplier_item_id: ?int, units_per_pack: int, purchase_unit: string} $new
     * @return array{status: string, document: Document, line_no: int, units_added: int, sku_id: int, note: ?string}
     */
    private function addResolved(Caller $caller, int $id, int $version, array $new, int $packs, string $entry, ?string $price = null, ?string $note = null): array
    {
        if ($packs < 1 || $packs > PoMath::MAX_PACKS) {
            throw new CwException('bad_field', 'packs: a whole number from 1 to ' . number_format(PoMath::MAX_PACKS), 422, ['field' => 'packs']);
        }
        $price = $price === null || trim($price) === '' ? null : trim($price);
        $lines = $this->lines($id);
        $new += ['packs' => $packs, 'entry' => $entry, 'po_line_no' => null];
        if ($price !== null) {
            $new['pack_price'] = $price;
        }
        $status = 'added';
        $lineNo = count($lines) + 1;
        foreach ($lines as $i => $l) {
            if (self::sameLine($l, $new)) {
                $lines[$i]['packs'] = $l['packs'] + $packs;
                if ($price !== null) {
                    $lines[$i]['pack_price'] = $price;
                }
                $status = 'incremented';
                $lineNo = $i + 1;
                break;
            }
        }
        if ($status === 'added') {
            if (count($lines) >= self::MAX_LINES) {
                throw new CwException('too_many_lines', 'a receipt has at most ' . self::MAX_LINES . ' lines', 422);
            }
            $lines[] = $new;
        }
        $doc = $this->saveDraft($caller, $id, $version, [], $lines);
        return ['status' => $status, 'document' => $doc, 'line_no' => $lineNo, 'units_added' => $packs * (int) $new['units_per_pack'], 'sku_id' => (int) $new['sku_id'],
            'note' => $note];
    }

    /**
     * A usable barcode as the receipt line it adds (addLine's docblock, I173), or null when $q is not one (the other ways to
     * resolve it apply). ['status' => 'found', 'line' => {sku_id, supplier_item_id, units_per_pack, purchase_unit}, 'per_scan' =>
     * packs per scan, 'note' => what the desk is told when it is not the supplier's own pack] | ['status' => 'choices', ...].
     *
     * @return array<string, mixed>|null
     */
    private function scanned(int $id, int $supplierId, string $q): ?array
    {
        $digits = (string) preg_replace('/[\s-]+/', '', $q);
        if (preg_match('/^[0-9]{6,64}$/D', $digits) !== 1) {
            return null;
        }
        $b = $this->db->one('SELECT b.sku_id, b.units_per_scan, s.code, s.name, s.brand FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.barcode IN (?, ?) '
            . 'AND b.is_usable = 1 AND s.merged_into_sku_id IS NULL ORDER BY b.barcode = ? DESC LIMIT 1', [$digits, \CW\Matching\Gtin::key($digits) ?? $digits, $digits]);
        if ($b === null) {
            return null;
        }
        $sku = (int) $b['sku_id'];
        $k = max(1, (int) $b['units_per_scan']);
        $sis = $this->db->all('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND is_active = 1 ORDER BY is_preferred DESC, units_per_pack, id',
            [$supplierId, $sku]);
        $line = static fn (array $si): array => ['sku_id' => $sku, 'supplier_item_id' => (int) $si['id'], 'units_per_pack' => (int) $si['units_per_pack'],
            'purchase_unit' => (string) $si['purchase_unit']];
        $loose = ['sku_id' => $sku, 'supplier_item_id' => null, 'units_per_pack' => $k, 'purchase_unit' => $k > 1 ? 'case' : 'each'];
        foreach ($sis as $si) {
            if ((int) $si['units_per_pack'] === $k) {
                return ['status' => 'found', 'line' => $line($si), 'per_scan' => 1];
            }
        }
        $best = null;
        foreach ($sis as $si) {
            $p = (int) $si['units_per_pack'];
            if ($k % $p === 0 && ($best === null || $p > (int) $best['units_per_pack'])) {
                $best = $si;
            }
        }
        if ($best !== null) {
            return ['status' => 'found', 'line' => $line($best), 'per_scan' => intdiv($k, (int) $best['units_per_pack'])];
        }
        if ($sis === []) {
            return ['status' => 'found', 'line' => $loose, 'per_scan' => 1];
        }
        $packs = implode(' or ', array_unique(array_map(static fn (array $si): string => 'packs of ' . (int) $si['units_per_pack'], $sis)));
        if ($k > 1) {
            return ['status' => 'found', 'line' => $loose, 'per_scan' => 1, 'note' => "a case of {$k}, not this supplier's {$packs}: check the price"];
        }
        // One unit scanned, the supplier sells bigger packs: the receipt's own line of the item when there is exactly one.
        $mine = array_values(array_filter($this->lines($id), static fn (array $l): bool => $l['sku_id'] === $sku));
        if (count($mine) === 1) {
            return ['status' => 'found', 'line' => ['sku_id' => $sku, 'supplier_item_id' => $mine[0]['supplier_item_id'], 'units_per_pack' => $mine[0]['units_per_pack'],
                'purchase_unit' => $mine[0]['purchase_unit']], 'per_scan' => 1];
        }
        $choices = [];
        foreach ($sis as $si) {
            $choices[] = ['sku_id' => $sku, 'sku_code' => (string) $b['code'], 'name' => (string) $b['name'], 'brand' => $b['brand'], 'supplier_item_id' => (int) $si['id'],
                'units_per_pack' => (int) $si['units_per_pack'], 'supplier_code' => $si['supplier_code'], 'purchase_unit' => (string) $si['purchase_unit']];
        }
        $choices[] = ['sku_id' => $sku, 'sku_code' => (string) $b['code'], 'name' => (string) $b['name'], 'brand' => $b['brand'], 'supplier_item_id' => null,
            'units_per_pack' => null, 'supplier_code' => null, 'purchase_unit' => null];
        return ['status' => 'choices', 'choices' => $choices, 'note' => "That barcode is one unit of {$b['code']}, but this supplier sells it in {$packs}: "
            . 'add one of its packs, or single units?'];
    }

    /** What the bench counted a line as: its item, supplier item, pack and packs (I168). */
    private static function identity(int $skuId, ?int $supplierItemId, int $upp, int $packs): string
    {
        return $skuId . ':' . ($supplierItemId ?? '-') . ':' . $upp . ':' . $packs;
    }

    /** @param array<string, mixed> $r a grn_line row joined with its document_line's sku_id */
    private static function storedIdentity(array $r): string
    {
        return self::identity((int) $r['sku_id'], $r['supplier_item_id'] === null ? null : (int) $r['supplier_item_id'], (int) $r['units_per_pack'], (int) $r['packs']);
    }

    /** The same purchase: the same supplier item, or the same item and pack without one. */
    private static function sameLine(array $a, array $b): bool
    {
        if (($a['supplier_item_id'] ?? null) !== null || ($b['supplier_item_id'] ?? null) !== null) {
            return ($a['supplier_item_id'] ?? null) === ($b['supplier_item_id'] ?? null);
        }
        return (int) ($a['sku_id'] ?? 0) === (int) ($b['sku_id'] ?? 0) && (int) ($a['units_per_pack'] ?? 1) === (int) ($b['units_per_pack'] ?? 1);
    }

    /**
     * Checks and completes the lines of a save (saveDraft's docblock).
     *
     * @param mixed $lines
     * @return list<array{doc: array<string, mixed>, grn: array<string, mixed>}>
     */
    private function normaliseLines(int $id, int $supplierId, ?int $poId, mixed $lines): array
    {
        // The stored lines, for their bench findings (saveDraft's docblock, I168).
        $stored = [];
        foreach ($this->db->all('SELECT g.*, dl.sku_id FROM grn_line g JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no '
            . 'WHERE g.document_id = ? ORDER BY g.line_no', [$id]) as $r) {
            $stored[(int) $r['line_no']] = $r;
        }
        $used = [];
        if (!is_array($lines) || !array_is_list($lines)) {
            throw new CwException('bad_lines', 'lines must be a list', 400);
        }
        if (count($lines) > self::MAX_LINES) {
            throw new CwException('too_many_lines', 'a receipt has at most ' . self::MAX_LINES . ' lines (' . count($lines) . ' given)', 422);
        }
        $poLines = [];
        $poPacks = [];
        if ($poId !== null) {
            foreach ($this->db->all('SELECT pl.line_no, pl.kind, pl.units_per_pack, pl.pack_price, pl.received_units, dl.sku_id, dl.qty, dl.unit_cost FROM po_line pl '
                . 'JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no WHERE pl.document_id = ? ORDER BY pl.line_no', [$poId]) as $p) {
                $poLines[(int) $p['line_no']] = $p;
                if ($p['kind'] === 'item') {
                    $poPacks[(int) $p['sku_id']][] = $p;
                }
            }
        }
        $sis = [];
        $ids = [];
        foreach ($lines as $line) {
            if (is_array($line) && is_int($line['supplier_item_id'] ?? null)) {
                $ids[$line['supplier_item_id']] = true;
            }
        }
        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            foreach ($this->db->all('SELECT * FROM supplier_item WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $sis[(int) $r['id']] = $r;
            }
        }
        $out = [];
        foreach ($lines as $i => $line) {
            $no = $i + 1;
            $bad = static fn (string $field, string $why, int $status = 422): CwException => new CwException('bad_line', "Line {$no}, "
                . (self::LINE_LABELS[$field] ?? str_replace('_', ' ', $field)) . ": {$why}", $status, ['line' => $no, 'field' => $field]);
            if (!is_array($line)) {
                throw $bad('line', 'must be an object', 400);
            }
            foreach (array_keys($line) as $k) {
                if (!in_array($k, self::LINE_FIELDS, true) && !in_array($k, self::BENCH_FIELDS, true) && $k !== 'checked_at' && $k !== 'line_no') {
                    throw $bad((string) $k, 'a receipt line has ' . implode(', ', self::LINE_FIELDS), 400);
                }
            }
            $packs = self::int($line['packs'] ?? null, 1, PoMath::MAX_PACKS, $bad, 'packs');
            $si = null;
            $siId = $line['supplier_item_id'] ?? null;
            $skuId = $line['sku_id'] ?? null;
            if ($siId !== null) {
                $siId = self::int($siId, 1, PHP_INT_MAX, $bad, 'supplier_item_id');
                $si = $sis[$siId] ?? $this->db->one('SELECT * FROM supplier_item WHERE id = ?', [$siId]);
                if ($si === null || (int) $si['supplier_id'] !== $supplierId) {
                    throw $bad('supplier_item_id', 'this supplier has no such supplier item');
                }
                if ($skuId !== null && (int) $skuId !== (int) $si['sku_id']) {
                    throw $bad('sku_id', 'the item is not the supplier item\'s');
                }
                $skuId = (int) $si['sku_id'];
                $upp = (int) $si['units_per_pack'];
                if (($line['units_per_pack'] ?? null) !== null && self::int($line['units_per_pack'], 1, PoMath::MAX_UNITS_PER_PACK, $bad, 'units_per_pack') !== $upp) {
                    throw $bad('units_per_pack', "pack differs: the supplier item says {$upp}");
                }
            } else {
                if ($skuId === null) {
                    throw $bad('sku_id', 'a receipt line names its item', 400);
                }
                $skuId = self::int($skuId, 1, PHP_INT_MAX, $bad, 'sku_id');
                $upp = ($line['units_per_pack'] ?? null) === null ? 1 : self::int($line['units_per_pack'], 1, PoMath::MAX_UNITS_PER_PACK, $bad, 'units_per_pack');
                $si = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = ? AND is_active = 1', [$supplierId, $skuId, $upp]);
            }
            $units = $packs * $upp;
            if ($units > Documents::MAX_QTY) {
                throw $bad('packs', "{$packs} packs of {$upp} is more than " . number_format(Documents::MAX_QTY) . ' units on one line');
            }
            // The PO line: given (0 = not against the order), else the order's line of this item still expecting units, else its first.
            $poLineNo = null;
            $given = $line['po_line_no'] ?? null;
            if ($given !== null && $given !== '' && $given !== 0 && $given !== '0') {
                $poLineNo = self::int($given, 1, PHP_INT_MAX, $bad, 'po_line_no');
                if ($poId === null || !isset($poLines[$poLineNo]) || $poLines[$poLineNo]['kind'] !== 'item') {
                    throw $bad('po_line_no', 'the purchase order has no such item line');
                }
                if ((int) $poLines[$poLineNo]['sku_id'] !== $skuId) {
                    throw $bad('po_line_no', "the order's line {$poLineNo} is another item");
                }
            } elseif ($given === null && $poId !== null && isset($poPacks[$skuId])) {
                $candidates = $poPacks[$skuId];
                $poLineNo = (int) $candidates[0]['line_no'];
                foreach ($candidates as $p) {
                    if ((int) $p['qty'] > (int) $p['received_units']) {
                        $poLineNo = (int) $p['line_no'];
                        break;
                    }
                }
            }
            $price = $line['pack_price'] ?? null;
            if ($price !== null && !is_string($price) && !is_int($price)) {
                throw $bad('pack_price', 'an amount in GBP', 400);
            }
            $price = $price === null || trim((string) $price) === '' ? null : self::packPrice((string) $price, $bad);
            if ($price === null && $poLineNo !== null) {
                $p = $poLines[$poLineNo];
                $price = (int) $p['units_per_pack'] === $upp ? (string) $p['pack_price']
                    : PoMath::fromE4(min(PoMath::MAX_PACK_PRICE_E4, intdiv(PoMath::e6((string) $p['unit_cost']) * $upp + 50, 100)));
            }
            if ($price === null && $si !== null) {
                $price = $si['last_pack_price'] !== null ? (string) $si['last_pack_price'] : ($si['last_po_pack_price'] !== null ? (string) $si['last_po_pack_price'] : null);
            }
            $price ??= '0.0000';
            try {
                $amountE2 = PoMath::lineAmountE2($packs, PoMath::e4($price));
                $unitCost = PoMath::unitCost($price, $upp);
            } catch (\RangeException $e) {
                throw $bad('pack_price', $e->getMessage());
            }
            $code = $si !== null && $si['supplier_code'] !== null ? (string) $si['supplier_code'] : self::text($line['supplier_code'] ?? null, 64, 'supplier_code', $no);
            $unit = $si !== null ? (string) $si['purchase_unit'] : (self::text($line['purchase_unit'] ?? null, SupplierItems::PURCHASE_UNIT_MAX, 'purchase_unit', $no)
                ?? ($upp > 1 ? 'case' : 'each'));
            $entry = (string) ($line['entry'] ?? 'manual');
            if (!in_array($entry, self::ENTRIES, true)) {
                throw $bad('entry', 'manual, scan, po or file', 400);
            }
            $mode = (string) ($line['mode_choice'] ?? 'default');
            if (!in_array($mode, self::MODE_CHOICES, true)) {
                throw $bad('mode_choice', 'default, In-Stock, From-Warehouse or Out-Of-Stock');
            }
            // The bench's findings: the stored line's while its item and quantity are unchanged, else none (I168).
            $identity = self::identity($skuId, $si === null ? null : (int) $si['id'], $upp, $packs);
            $from = null;
            $hint = $line['line_no'] ?? null;
            if (is_int($hint) || (is_string($hint) && preg_match('/^[1-9][0-9]{0,8}$/D', $hint) === 1)) {
                $hint = (int) $hint;
                if (isset($stored[$hint]) && !isset($used[$hint]) && self::storedIdentity($stored[$hint]) === $identity) {
                    $from = $hint;
                }
            }
            $bench = ['stamp_on_pack' => null, 'stamp_type' => null, 'stamp_code' => null, 'short_units' => 0, 'over_units' => 0, 'damaged_units' => 0,
                'wrong_item_units' => 0, 'unstamped_units' => 0, 'unstamped_action' => null, 'pre_october_evidence' => null];
            $checkedAt = null;
            if ($from !== null) {
                $used[$from] = true;
                $sr = $stored[$from];
                foreach (['stamp_on_pack', 'short_units', 'over_units', 'damaged_units', 'wrong_item_units', 'unstamped_units'] as $k) {
                    $bench[$k] = $sr[$k] === null ? null : (int) $sr[$k];
                }
                foreach (['stamp_type', 'stamp_code', 'unstamped_action', 'pre_october_evidence'] as $k) {
                    $bench[$k] = $sr[$k] === null ? null : (string) $sr[$k];
                }
                $checkedAt = $sr['checked_at'] === null ? null : (string) $sr['checked_at'];
            }
            $out[] = [
                'doc' => ['sku_id' => $skuId, 'qty' => $units, 'unit_cost' => $unitCost, 'amount' => PoMath::fromE2($amountE2), 'warehouse' => 'MAIN',
                    'description' => self::text($line['description'] ?? null, 255, 'description', $no)],
                'grn' => ['supplier_item_id' => $si === null ? null : (int) $si['id'], 'supplier_code' => $code, 'purchase_unit' => $unit, 'units_per_pack' => $upp,
                    'packs' => $packs, 'pack_price' => $price, 'po_line_no' => $poLineNo, 'entry' => $entry, 'mode_choice' => $mode, 'checked_at' => $checkedAt] + $bench,
            ];
        }
        return $out;
    }

    /** @param list<array{doc: array<string, mixed>, grn: array<string, mixed>}> $norm */
    private function insertLines(int $id, array $norm): void
    {
        $cols = ['supplier_item_id', 'supplier_code', 'purchase_unit', 'units_per_pack', 'packs', 'pack_price', 'po_line_no', 'entry', 'mode_choice', 'checked_at',
            'stamp_on_pack', 'stamp_type', 'stamp_code', 'short_units', 'over_units', 'damaged_units', 'wrong_item_units', 'unstamped_units', 'unstamped_action',
            'pre_october_evidence'];
        foreach (array_chunk($norm, 300, true) as $chunk) {
            $params = [];
            foreach ($chunk as $i => $n) {
                array_push($params, $id, $i + 1);
                foreach ($cols as $c) {
                    $params[] = $n['grn'][$c];
                }
            }
            $this->db->exec('INSERT INTO grn_line (document_id, line_no, ' . implode(', ', $cols) . ') VALUES '
                . implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($cols) + 2, '?')) . ')')), $params);
        }
    }

    /**
     * The bench fields of one line, checked: stamp_on_pack (1, 0, empty), stamp_type (digital / transitional, with a stamp on the
     * pack), stamp_code (at most 128 printable characters), the exception units (0 .. MAX_EXCEPTION_UNITS; short + damaged + wrong
     * item + unstamped at most the line's units), unstamped_action (with unstamped units) and the supplier's evidence (to accept them).
     *
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    public static function benchFields(int $no, array $f, int $units): array
    {
        $bad = static fn (string $field, string $why, int $status = 422): CwException => new CwException('bad_bench', "Line {$no}, "
            . (self::BENCH_LABELS[$field] ?? str_replace('_', ' ', $field)) . ": {$why}", $status, ['line' => $no, 'field' => $field]);
        $stamp = $f['stamp_on_pack'] ?? null;
        $stamp = match (true) {
            $stamp === null, $stamp === '' => null,
            $stamp === 1, $stamp === '1', $stamp === true => 1,
            $stamp === 0, $stamp === '0', $stamp === false => 0,
            default => throw $bad('stamp_on_pack', 'yes (1), no (0) or not checked (empty)', 400),
        };
        $type = $f['stamp_type'] ?? null;
        $type = $type === null || $type === '' ? null : (is_string($type) && in_array($type, self::STAMP_TYPES, true) ? $type : throw $bad('stamp_type', 'digital or transitional'));
        if ($stamp !== 1) {
            $type = null;
        }
        $out = ['stamp_on_pack' => $stamp, 'stamp_type' => $type, 'stamp_code' => self::text($f['stamp_code'] ?? null, 128, 'stamp_code', $no)];
        foreach (['short_units', 'over_units', 'damaged_units', 'wrong_item_units', 'unstamped_units'] as $k) {
            $v = $f[$k] ?? 0;
            $out[$k] = $v === '' || $v === null ? 0 : self::int($v, 0, self::MAX_EXCEPTION_UNITS, $bad, $k);
        }
        $sum = $out['short_units'] + $out['damaged_units'] + $out['wrong_item_units'] + $out['unstamped_units'];
        if ($sum > $units) {
            throw new CwException('bad_bench', "Line {$no}: short, damaged, wrong item and unstamped are {$sum} units together, but the line has only {$units} "
                . '(units that arrived beyond the paperwork are "over")', 422, ['line' => $no]);
        }
        $arrived = $units - $out['short_units'] - $out['damaged_units'] - $out['wrong_item_units'];
        if ($stamp === 0 && $out['unstamped_units'] !== $arrived) {
            throw new CwException('bad_bench', "Line {$no}: \"no stamp on the pack\" means all {$arrived} units that arrived are unstamped: count them as unstamped, "
                . 'or answer "yes" and count only the unstamped ones', 422, ['line' => $no, 'field' => 'unstamped_units']);
        }
        $action = $f['unstamped_action'] ?? null;
        $action = $action === null || $action === '' ? null : (is_string($action) && in_array($action, self::UNSTAMPED_ACTIONS, true) ? $action
            : throw $bad('unstamped_action', 'quarantine, refuse or accept_pre_october'));
        $evidence = self::text($f['pre_october_evidence'] ?? null, 500, 'pre_october_evidence', $no);
        if ($out['unstamped_units'] === 0) {
            $action = null;
            $evidence = null;
        } elseif ($action === null) {
            throw $bad('unstamped_action', 'choose what happens to them: quarantine, refuse at the door, or accept with the supplier\'s evidence '
                . 'of manufacture before 1 Oct 2026');
        }
        if ($action !== 'accept_pre_october') {
            $evidence = null;
        } elseif ($evidence === null || mb_strlen($evidence) < ReceiptPlan::EVIDENCE_MIN) {
            throw $bad('pre_october_evidence', 'the supplier\'s evidence that the stock was made or imported before 1 Oct 2026 (at least '
                . ReceiptPlan::EVIDENCE_MIN . ' characters)');
        }
        return $out + ['unstamped_action' => $action, 'pre_october_evidence' => $evidence];
    }

    /**
     * The header fields of a create / save (HEADER_FIELDS), checked; only the keys given. received_at: 'Y-m-d H:i[:s]' or
     * 'Y-m-dTH:i' in UK time (a browser's datetime-local), stored UTC; at most a day ahead.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private function headerFields(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if (!in_array($k, self::HEADER_FIELDS, true)) {
                throw new CwException('bad_field', 'a receipt header has ' . implode(', ', self::HEADER_FIELDS) . ', not ' . mb_substr((string) $k, 0, 40), 400,
                    ['field' => mb_substr((string) $k, 0, 40)]);
            }
            switch ($k) {
                case 'po_id':
                case 'supplier_id':
                    if ($v === null || $v === '' || $v === '0' || $v === 0) {
                        $out[$k] = null;
                        break;
                    }
                    if (!is_int($v) && !(is_string($v) && preg_match('/^[1-9][0-9]{0,17}$/D', $v) === 1)) {
                        throw new CwException('bad_field', "{$k}: an id", 400, ['field' => $k]);
                    }
                    $out[$k] = (int) $v;
                    break;
                case 'paper_sheet':
                    $out[$k] = $v === true || $v === 1 || $v === '1' || $v === 'on';
                    break;
                case 'invoice_number':
                    $t = self::text($v, self::INVOICE_MAX, 'invoice_number');
                    $out[$k] = $t;
                    break;
                case 'delivery_note':
                    $out[$k] = self::text($v, 64, 'delivery_note');
                    break;
                case 'backdate_reason':
                    $out[$k] = self::text($v, 500, 'backdate_reason');
                    break;
                case 'note':
                    $out[$k] = self::text($v, 1000, 'note');
                    break;
                case 'invoice_date':
                    $t = self::text($v, 10, 'invoice_date');
                    if ($t !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $t) !== 1 || \DateTimeImmutable::createFromFormat('!Y-m-d', $t)?->format('Y-m-d') !== $t)) {
                        throw new CwException('bad_field', 'invoice date: a date, YYYY-MM-DD', 422, ['field' => 'invoice_date']);
                    }
                    $out[$k] = $t;
                    break;
                case 'received_at':
                    $t = self::text($v, 32, 'received_at');
                    if ($t === null) {
                        $out[$k] = null;
                        break;
                    }
                    $t = str_replace('T', ' ', $t);
                    $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $t, new \DateTimeZone('Europe/London'))
                        ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $t, new \DateTimeZone('Europe/London'));
                    if ($at === false || !in_array($at->format('Y-m-d H:i'), [substr($t, 0, 16)], true)) {
                        throw new CwException('bad_field', 'received at: a date and time (YYYY-MM-DD HH:MM, UK time)', 422, ['field' => 'received_at']);
                    }
                    if ($at > $this->now()->modify('+1 day')) {
                        throw new CwException('bad_field', 'received at: the goods cannot arrive in the future', 422, ['field' => 'received_at']);
                    }
                    $out[$k] = Clock::db($at);
                    break;
            }
        }
        return $out;
    }

    /** The PO a receipt may be against: a posted, receivable PO of this supplier (422 otherwise). */
    private function checkPo(int $poId, int $supplierId): void
    {
        $p = $this->db->one('SELECT d.number, d.status, d.doc_type, d.reverses_id, p.state, p.supplier_id FROM document d LEFT JOIN purchase_order p ON p.document_id = d.id WHERE d.id = ?',
            [$poId]);
        if ($p === null || $p['doc_type'] !== 'PO' || $p['reverses_id'] !== null) {
            throw new CwException('unknown_purchase_order', 'there is no such purchase order', 422, ['field' => 'po_id']);
        }
        $label = $p['number'] ?? 'that order';
        if ((int) $p['supplier_id'] !== $supplierId) {
            throw new CwException('po_other_supplier', "{$label} is another supplier's order", 422, ['field' => 'po_id']);
        }
        if ($p['status'] !== 'posted' || !in_array($p['state'], self::OPEN_PO_STATES, true)) {
            throw new CwException('po_not_receivable', "{$label} is " . str_replace('_', ' ', (string) ($p['status'] === 'posted' ? $p['state'] : $p['status']))
                . ': goods are received only against an approved, sent or part-received order', 422, ['field' => 'po_id']);
        }
    }

    /**
     * A lines-file identifier (cw_code, supplier_code, barcode) as ReceiptLinesFile::parse() wants it, or the error text.
     *
     * @return array<string, mixed>|string
     */
    private function resolveForFile(int $supplierId, string $type, string $value): array|string
    {
        switch ($type) {
            case 'supplier_code':
                $si = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND supplier_code = ? AND is_active = 1 ORDER BY id LIMIT 1', [$supplierId, $value]);
                if ($si === null && preg_match('/^cw-?0*[0-9]{1,10}$/iD', $value) === 1) {
                    return $this->resolveForFile($supplierId, 'cw_code', $value); // a CW code in the code column (a sheet CW exported)
                }
                if ($si === null) {
                    return "this supplier has no active item with the code {$value}";
                }
                return $this->fileItem((int) $si['sku_id'], $si, 1);
            case 'barcode':
                $digits = (string) preg_replace('/[\s-]+/', '', $value);
                if (preg_match('/^[0-9]{6,64}$/D', $digits) !== 1) {
                    return 'a barcode is 6 to 64 digits';
                }
                $b = $this->db->one('SELECT sku_id, units_per_scan FROM sku_barcode WHERE barcode IN (?, ?) AND is_usable = 1 ORDER BY barcode = ? DESC LIMIT 1',
                    [$digits, \CW\Matching\Gtin::key($digits) ?? $digits, $digits]);
                if ($b === null) {
                    return "no item has the usable barcode {$value}";
                }
                $k = (int) $b['units_per_scan'];
                $si = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = ? AND is_active = 1', [$supplierId, (int) $b['sku_id'], $k]);
                if ($si === null && $k === 1) {
                    $si = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND is_active = 1 ORDER BY is_preferred DESC, units_per_pack, id LIMIT 1',
                        [$supplierId, (int) $b['sku_id']]);
                }
                return $this->fileItem((int) $b['sku_id'], $si, $k);
            case 'cw_code':
                if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $value, $m) !== 1 || $this->db->value('SELECT 1 FROM sku WHERE id = ?', [(int) $m[1]]) === null) {
                    return "there is no item {$value}";
                }
                $si = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND is_active = 1 ORDER BY is_preferred DESC, units_per_pack, id LIMIT 1',
                    [$supplierId, (int) $m[1]]);
                return $this->fileItem((int) $m[1], $si, 1);
            default:
                throw new \LogicException("unknown identifier {$type}");
        }
    }

    /**
     * @param array<string, mixed>|null $si
     * @return array<string, mixed>|string
     */
    private function fileItem(int $sku, ?array $si, int $packOf): array|string
    {
        $s = $this->db->one('SELECT s.code, s.merged_into_sku_id, m.code AS into_code FROM sku s LEFT JOIN sku m ON m.id = s.merged_into_sku_id WHERE s.id = ?', [$sku]);
        if ($s === null) {
            return 'the item does not exist';
        }
        if ($s['merged_into_sku_id'] !== null) {
            return "item {$s['code']} was merged into {$s['into_code']}: use that one";
        }
        return ['sku_id' => $sku, 'sku_code' => (string) $s['code'], 'units_per_scan' => $packOf, 'supplier_item' => $si === null ? null : ['id' => (int) $si['id'],
            'units_per_pack' => (int) $si['units_per_pack'], 'purchase_unit' => (string) $si['purchase_unit'], 'supplier_code' => $si['supplier_code']]];
    }

    /** A 409 duplicate_invoice for a duplicate key on uq_goods_receipt_invoice (other PDOExceptions are rethrown). */
    private function duplicate(\PDOException $e, int $supplierId, string $key, ?int $self = null): \Throwable
    {
        if (Db::driverCode($e) !== 1062 || $key === '') {
            return $e;
        }
        $other = $this->db->one('SELECT d.id, d.number, d.status, u.display_name FROM goods_receipt g JOIN document d ON d.id = g.document_id LEFT JOIN staff_user u '
            . 'ON u.id = d.created_by WHERE g.supplier_id = ? AND g.invoice_key = ? AND g.document_id <> ?', [$supplierId, $key, $self ?? 0]);
        $label = $other === null ? 'another receipt' : ($other['number'] ?? str_replace('_', ' ', (string) $other['status']) . ' receipt #' . $other['id']
            . ($other['display_name'] !== null ? ' (' . $other['display_name'] . ')' : ''));
        return new CwException('duplicate_invoice', "this supplier's invoice {$key} is already on {$label}: a supplier invoice is received once "
            . '(cancel the other receipt first if it was keyed by mistake)', 409, ['other' => $other === null ? null : (int) $other['id']]);
    }

    /** @return array{id: int, roles: list<string>} */
    private function poster(Caller $caller): array
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'receipts are written by staff', 403);
        }
        $roles = StaffRoles::active($this->db, $caller->staffUserId);
        if (in_array('admin', $roles, true)) {
            throw new CwException('admin_cannot_post', 'admin manages people and roles and never drafts, posts or reverses documents', 403);
        }
        if (!Permissions::can($roles, 'doc.GRN.post')) {
            throw new CwException('role_not_allowed', (count($roles) === 1 ? 'your role (' : 'your roles (') . ($roles === [] ? 'none' : implode(', ', $roles))
                . ') cannot receive goods', 403, ['type' => 'GRN']);
        }
        return ['id' => $caller->staffUserId, 'roles' => $roles];
    }

    /** @return array<string, mixed> the receipt's document row, X-locked (404 unknown_receipt) */
    private function lockGrn(int $id): array
    {
        $row = $this->db->one('SELECT * FROM document WHERE id = ? FOR UPDATE', [$id]);
        if ($row === null || $row['doc_type'] !== 'GRN' || $row['reverses_id'] !== null) {
            throw new CwException('unknown_receipt', 'there is no such receipt', 404);
        }
        return $row;
    }

    /**
     * A draft's row, X-locked, of this version and created by $me.
     *
     * @param array{id: int, roles: list<string>} $me
     * @return array<string, mixed>
     */
    private function lockDraft(int $id, int $version, array $me): array
    {
        $row = $this->lockGrn($id);
        if ($row['status'] !== 'draft') {
            throw new CwException('not_draft', $this->label($row) . ' is ' . str_replace('_', ' ', (string) $row['status']) . ': only a draft is changed', 409,
                ['status' => $row['status']]);
        }
        $this->checkVersion($row, $version);
        if ((int) ($row['created_by'] ?? 0) !== $me['id']) {
            throw new CwException('not_creator', 'only the person who keyed a receipt changes its lines; the bench records its findings on the bench view, '
                . 'and anyone who receives goods can set the supplier invoice on the receipt\'s page', 403);
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function checkVersion(array $row, int $version): void
    {
        if ((int) $row['version'] !== $version) {
            throw new CwException('version_conflict', 'the receipt changed since this page was drawn: reload it and try again', 409,
                ['version' => (int) $row['version'], 'expected_version' => $version]);
        }
    }

    /** @param array<string, mixed> $row */
    private function label(array $row): string
    {
        return $row['number'] ?? str_replace('_', ' ', (string) $row['status']) . ' receipt #' . $row['id'];
    }

    private function pos(): PurchaseOrders
    {
        return $this->pos ??= new PurchaseOrders($this->db, $this->docs, $this->settings, null, $this->clock);
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)()->setTimezone(Clock::utc());
    }

    /** The UK date of a stored (UTC) time. */
    public static function ukDate(string $dbTime): string
    {
        return Clock::fromDb($dbTime)->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
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
        if (is_string($v)) {
            $w = PoLinesFile::wholeNumber($v);
            $v = $w ?? $v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw $bad($field, "a whole number from {$min} to " . ($max === PHP_INT_MAX ? 'any' : number_format($max)));
        }
        return $v;
    }

    /** A trimmed one-line text of at most $max characters, or null. */
    private static function text(mixed $v, int $max, string $field, ?int $line = null): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v)) {
            $v = (string) $v;
        }
        $label = self::LINE_LABELS[$field] ?? self::BENCH_LABELS[$field] ?? str_replace('_', ' ', $field);
        $where = $line === null ? $label : "Line {$line}, {$label}";
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            throw new CwException('bad_field', "{$where} must be text", 400, ['field' => $field] + ($line === null ? [] : ['line' => $line]));
        }
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v));
        if (mb_strlen($v) > $max) {
            throw new CwException('bad_field', "{$where}: at most {$max} characters", 422, ['field' => $field] + ($line === null ? [] : ['line' => $line]));
        }
        return $v === '' ? null : $v;
    }
}
