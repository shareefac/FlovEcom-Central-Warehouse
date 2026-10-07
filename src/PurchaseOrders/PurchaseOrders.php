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
use CW\Files\FileStore;
use CW\Matching\Gtin;
use CW\Settings;
use CW\Staff\StaffRoles;
use CW\Suppliers\SupplierItems;
use CW\Ui\Queries;

/**
 * Purchase orders (IM5; docs/decisions.md I48-I59, spec §6.3): the PO-specific side of the PO document type, on top of the
 * document base (Documents) and its handler (PurchaseOrderHandler).
 *
 * A PO is a `document` (type PO) with a `purchase_order` header extension and one `po_line` per `document_line`:
 *   draft (its creator edits it freely) --approve = Documents::post--> posted: numbered, content fixed (posted_hash +
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
 * (409 version_conflict) and moved; a draft changed only by its creator (403 not_creator). Money: integers only (PoMath).
 * Nothing here books stock (spec §0.4). Lock order (§6.9): document row(s) -> review task -> supplier FOR SHARE ->
 * number_series -> purchase_order -> po_line -> supplier_item -> supplier_item_price -> po_posting.
 */
final class PurchaseOrders
{
    public const SOURCES = ['manual', 'reorder', 'copy', 'amend', 'import_file', 'erp_seed'];
    public const SEND_VIA = ['email' => 'e-mail', 'portal' => 'supplier portal', 'phone' => 'phone', 'in_person' => 'in person', 'imported' => 'imported',
        'other' => 'other'];
    /** Reasons a buyer gives when cancelling a posted PO (reversal reasons, spec 0010); po_amended is the amendment's own. */
    public const CANCEL_REASONS = ['not_needed', 'supplier_cannot_supply', 'entered_in_error', 'duplicate', 'other'];
    public const AMEND_REASONS = ['po_amended', 'supplier_cannot_supply', 'entered_in_error', 'other'];
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
        'description', 'suggested_units', 'save_item'];
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
     * Saves a draft: the header (expected_date, external_ref, note, doc_date; supplier_id only while the draft has no lines)
     * and EVERY line (the list replaces the draft's lines; [] clears them). Creator only. Version + 2 (Documents::updateDraft,
     * then Documents::setLines, whose cascade removes the old po_line rows), then the po_line rows and the totals.
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
            $me = $this->poster($caller);
            $this->lockDraft($id, $version, $me);
            $h = $this->headerFields($header, true);
            $current = $this->poRow($id);
            $supplierId = (int) $current['supplier_id'];
            $changed = isset($h['supplier_id']) && $h['supplier_id'] !== $supplierId;
            if ($changed) {
                if ((int) $db->value('SELECT COUNT(*) FROM document_line WHERE document_id = ?', [$id]) > 0) {
                    throw new CwException('supplier_has_lines', 'the supplier of a draft changes only while it has no lines: remove them first, or start a new order', 409);
                }
                $supplierId = (int) $h['supplier_id'];
            }
            $supplier = $db->one('SELECT * FROM supplier WHERE id = ? FOR SHARE', [$supplierId])
                ?? throw new CwException('unknown_supplier', 'there is no such supplier', 422, ['field' => 'supplier_id']);
            if ($changed && $supplier['status'] === 'inactive') {
                throw new CwException('supplier_inactive', "supplier {$supplier['code']} is inactive: it gets no new purchase orders", 422);
            }
            $db->one('SELECT document_id FROM purchase_order WHERE document_id = ? FOR UPDATE', [$id]);
            $norm = $this->normaliseLines($caller, $supplier, $lines);
            $doc = $this->docs->updateDraft($caller, $id, $version, array_intersect_key($h, array_flip(['external_ref', 'note', 'doc_date'])));
            $doc = $this->docs->setLines($caller, $id, $doc->version, array_map(static fn (array $n): array => $n['doc'], $norm));
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
                'net' => PoMath::fromE2($t['net_e2'])] + ($changed ? ['supplier' => (string) $supplier['code']] : []));
            return $doc;
        });
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
            $me = $this->poster($caller);
            $this->lockDraft($id, $version, $me);
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
            $me = $this->poster($caller);
            $this->lockDraft($id, $version, $me);
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
            $me = $this->poster($caller);
            $this->lockDraft($id, $version, $me);
            $supplier = $db->one('SELECT * FROM supplier WHERE id = ?', [(int) $this->poRow($id)['supplier_id']]) ?? throw new \LogicException('PO without supplier');
            $vat = array_map(static fn (array $v): bool => $v['active'], $this->vatCodes());
            $parsed = PoLinesFile::parse($file['header'], $file['rows'], fn (string $type, string $value): array|string => $this->resolveForFile((int) $supplier['id'],
                $type, $value), $vat);
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
            return ['lines' => count($parsed['lines']), 'errors' => [], 'document' => $doc];
        });
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
     * po_has_receipts once goods were received: close it instead). Returns the PO.
     */
    public function cancel(Caller $caller, int $id, int $version, string $reason, ?string $note): Document
    {
        if (!in_array($reason, self::CANCEL_REASONS, true)) {
            throw new CwException('bad_reason', 'choose why the order is cancelled', 400, ['field' => 'reason_code']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $reason, $note): Document {
            $me = $this->poster($caller);
            $row = $this->lockPo($id);
            $this->checkVersion($row, $version);
            if ($row['reverses_id'] !== null) {
                throw new CwException('not_cancellable', "{$this->label($row)} is itself a cancellation", 409);
            }
            $note = $note === null || trim($note) === '' ? null : trim($note);
            $label = (string) $db->value('SELECT label FROM reason_code WHERE code = ?', [$reason]);
            $text = mb_substr($label . ($note === null ? '' : ': ' . $note), 0, Documents::NOTE_MAX);
            switch ($row['status']) {
                case 'draft':
                    if ($reason === 'other' && $note === null) {
                        throw new CwException('note_required', 'say why in the note', 422, ['field' => 'note']);
                    }
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
                    $this->docs->reverse($caller, $id, $reason, $note);
                    return $this->docs->get($id);
                default:
                    throw new CwException('not_cancellable', "{$this->label($row)} is " . str_replace('_', ' ', (string) $row['status']) . ': nothing to cancel', 409);
            }
        });
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
        if (!in_array($reason, self::AMEND_REASONS, true)) {
            throw new CwException('bad_reason', 'choose why the order is amended', 400, ['field' => 'reason_code']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $reason, $note): Document {
            $this->poster($caller);
            $row = $this->lockPo($id);
            if ($row['status'] !== 'posted' || $row['reverses_id'] !== null) {
                throw new CwException('not_amendable', "{$this->label($row)} is " . ($row['reverses_id'] !== null ? 'a cancellation' : str_replace('_', ' ', (string) $row['status']))
                    . ': only an approved purchase order is amended (a draft is simply edited)', 409);
            }
            $po = $this->poRow($id);
            $db->one('SELECT id FROM supplier WHERE id = ? FOR SHARE', [(int) $po['supplier_id']]);
            $lines = $this->copyLines($id, (int) $po['supplier_id']);
            $rev = $this->docs->reverse($caller, $id, $reason, $note);
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
     * The state follows (none left: back to sent or approved, by sent_at).
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
            'SELECT pl.*, dl.sku_id, dl.description FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no '
            . 'WHERE pl.document_id = ? ORDER BY pl.line_no',
            [$id],
        ) as $r) {
            $out[] = ['kind' => (string) $r['kind'], 'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'],
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
            'terms' => (string) $this->settings->get('po.terms'),
        ];
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
     * null, units_per_pack, purchase_unit), `choices` (several candidates) or `not_found`.
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
        $found = (new Queries($this->db))->searchSkus($q, self::SEARCH_LIMIT);
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
                'supplier_code' => $si['supplier_code'] ?? null, 'purchase_unit' => $si['purchase_unit'] ?? null];
        }
        usort($choices, static fn (array $a, array $b): int => [$a['supplier_item_id'] === null, ] <=> [$b['supplier_item_id'] === null]);
        return ['status' => 'choices', 'choices' => $choices];
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
                'supplier_code' => $si['supplier_code'], 'purchase_unit' => (string) $si['purchase_unit']], $sis)];
        }
        return ['status' => 'found', 'sku_id' => $sku, 'supplier_item' => null, 'units_per_pack' => $packOf, 'purchase_unit' => $packOf > 1 ? 'case' : 'each'];
    }

    /** @param array<string, mixed> $si @return array<string, mixed> */
    private static function found(int $sku, array $si): array
    {
        return ['status' => 'found', 'sku_id' => $sku, 'supplier_item' => $si, 'units_per_pack' => (int) $si['units_per_pack'], 'purchase_unit' => (string) $si['purchase_unit']];
    }

    /**
     * A lines-file identifier (cw_code, supplier_code, barcode) as PoLinesFile::parse() wants it, or the error text.
     *
     * @return array<string, mixed>|string
     */
    private function resolveForFile(int $supplierId, string $type, string $value): array|string
    {
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
     * @return list<array{doc: array<string, mixed>, po: array<string, mixed>, calc: array{amount_e2: int, vat_code: string, rate_e2: int}}>
     */
    private function normaliseLines(Caller $caller, array $supplier, mixed $lines): array
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
            $no = $i + 1;
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
                $out[] = ['doc' => ['amount' => $amount, 'description' => $description],
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
            $out[] = ['doc' => ['sku_id' => $skuId, 'qty' => $qty, 'unit_cost' => $unitCost, 'amount' => PoMath::fromE2($amountE2), 'description' => $description],
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
        $allowed = $sign > 0 ? self::OPEN_STATES : [...self::OPEN_STATES, 'received'];
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
        $state = match (true) {
            $any && $all => 'received',
            $any => 'part_received',
            $po['sent_at'] !== null => 'sent',
            default => 'approved',
        };
        $this->db->exec('UPDATE purchase_order SET state = ?, updated_at = ? WHERE document_id = ?', [$state, $this->nowDb(), $poId]);
        Audit::write($this->db, $caller, 'po.receipt', 'document', (string) $poId, null,
            ['number' => $row['number'], 'grn' => mb_substr($grnLabel, 0, 64), 'direction' => $sign > 0 ? 'in' : 'back', 'lines' => $lineUnits, 'state' => $state]);
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
     * A draft's row, X-locked, of this version and created by $me.
     *
     * @param array{id: int, roles: list<string>} $me
     * @return array<string, mixed>
     */
    private function lockDraft(int $id, int $version, array $me): array
    {
        $row = $this->lockPo($id);
        if ($row['status'] !== 'draft') {
            throw new CwException('not_draft', "{$this->label($row)} is " . str_replace('_', ' ', (string) $row['status']) . ': only a draft is changed', 409,
                ['status' => $row['status']]);
        }
        $this->checkVersion($row, $version);
        if ((int) ($row['created_by'] ?? 0) !== $me['id']) {
            throw new CwException('not_creator', 'only the person who created a draft changes it (the review rule names its creator, I19)', 403);
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function checkVersion(array $row, int $version): void
    {
        if ((int) $row['version'] !== $version) {
            throw new CwException('version_conflict', 'the purchase order changed since this page was drawn: reload it and try again', 409,
                ['version' => (int) $row['version'], 'expected_version' => $version]);
        }
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
