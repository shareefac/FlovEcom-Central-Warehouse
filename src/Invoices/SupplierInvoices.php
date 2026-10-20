<?php

declare(strict_types=1);

namespace CW\Invoices;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\PurchaseOrders\PoMath;
use CW\Receiving\ReceiptMath;
use CW\Admin\ApprovalRules;
use CW\Staff\StaffRoles;
use CW\StockOps\StockOps;

/**
 * Supplier invoices and the supplier's credit notes (pack C, plan IM7; docs/decisions.md PC1-PC12), on top of the document base
 * (Documents: draft -> post (a reviewer's OK first when a rule asks) -> reverse; never deleted) and their handler
 * (SupplierInvoiceHandler).
 *
 * An invoice is a `document` (SINV: the supplier's invoice number as external_ref, the date on the invoice as doc_date) with a
 * `supplier_invoice` header (the supplier; the totals exactly as the paper states them: net, VAT, net + VAT) and lines, each with a
 * `sinv_line`: goods (an item in packs x units per pack at a pack price, for a delivery line and an order line) or a freight, duty,
 * service or other amount. A credit note (SCN) is the same, crediting an invoice (optional) and answering a debit note (optional); a
 * goods line of it credits one line of that invoice.
 *
 * The quick way (invoice with the delivery, today's normal way of working): createFromReceipt() starts the invoice of a posted delivery
 * with its number, its date, its copy of the invoice and a line for every delivery line still to be invoiced; the desk types the
 * total and the VAT from the paper and posts it. Several deliveries on one invoice: addReceipt() for each. One delivery over several
 * invoices: each invoice bills what it bills (the match counts what the other invoices bill). An invoice before its goods: addOrder().
 *
 * One live invoice per supplier and number, one live credit note per supplier and number (UNIQUE (supplier, kind, key): a duplicate is
 * 409 duplicate_invoice naming the earlier one); the number is free again once the record is cancelled or reversed.
 *
 * Every write: a staff caller allowed to post the type (doc.SINV.post / doc.SCN.post, never admin; Documents re-reads the roles), ONE
 * transaction (joining an open one), the document row locked and its version checked by Documents, a draft changed only by its
 * creator. accept() needs invoices.accept.
 */
final class SupplierInvoices
{
    public const KINDS = ['invoice' => 'SINV', 'credit_note' => 'SCN'];
    public const PATHS = ['invoice' => '/ui/purchasing/invoices', 'credit_note' => '/ui/purchasing/credit-notes'];
    public const CHARGE_KINDS = ['freight', 'duty', 'service', 'other'];
    public const LINE_FIELDS = ['kind', 'sku_id', 'packs', 'units_per_pack', 'pack_price', 'amount', 'description', 'grn_document_id', 'grn_line_no', 'po_document_id',
        'po_line_no', 'credit_of_line_no', 'supplier_code', 'purchase_unit', 'entry'];
    public const HEADER_FIELDS = ['invoice_number', 'invoice_date', 'net_total', 'vat_total', 'gross_total', 'note', 'credits_id', 'debit_note_id'];
    public const MAX_LINES = 1000;
    public const NUMBER_MAX = 64;
    public const NOTE_MAX = 1000;
    public const DESCRIPTION_MAX = 255;
    public const LIST_LIMIT = 200;
    public const STATES = ['draft', 'awaiting_approval', 'posted', 'reversed', 'cancelled'];
    /** Attachment role => the stored file's kind (FileStore::KINDS). */
    public const FILE_ROLES = ['supplier_invoice' => 'supplier_invoice', 'delivery_note' => 'delivery_note', 'evidence' => 'other'];
    /** An accepted difference's note (3-500 characters: it is the reason). */
    public const ACCEPT_NOTE_MIN = 3;
    public const ACCEPT_NOTE_MAX = 500;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private readonly InvoiceMatch $match;

    /**
     * @param (\Closure(): FileStore)|null $files the file store (attach() and the copy of a delivery's invoice; none: 503 / no copy)
     * @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock
     */
    public function __construct(private readonly Db $db, private readonly Documents $docs, private readonly ?\Closure $files = null, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->match = new InvoiceMatch($db);
    }

    /** The kind of a document type ('SINV' => 'invoice'), or null. */
    public static function kindOf(string $type): ?string
    {
        $k = array_search($type, self::KINDS, true);
        return $k === false ? null : $k;
    }

    // ------------------------------------------------------------------------------------------
    // Drafts
    // ------------------------------------------------------------------------------------------

    /**
     * A new draft invoice or credit note of $supplierId. Header (all optional on a draft): invoice_number, invoice_date (Y-m-d),
     * net_total, vat_total, gross_total (checked against net + VAT when typed), note; a credit note's credits_id (the invoice it reduces)
     * and debit_note_id (the return it answers). 409 duplicate_invoice (the number is on a live record of the supplier). Audit
     * invoice.create / credit_note.create.
     *
     * @param array<string, mixed> $header
     */
    public function createDraft(Caller $caller, string $kind, int $supplierId, array $header = []): Document
    {
        $type = self::KINDS[$kind] ?? throw new CwException('unknown_kind', 'there is no such kind of supplier invoice', 400);
        return $this->db->transaction(function (Db $db) use ($caller, $kind, $type, $supplierId, $header): Document {
            self::mayPost($db, $caller, $type);
            $s = $db->one('SELECT id, code, name FROM supplier WHERE id = ? FOR SHARE', [$supplierId])
                ?? throw new CwException('unknown_supplier', 'there is no such supplier', 404, ['field' => 'supplier_id']);
            $h = $this->header($kind, $supplierId, $header, null);
            $doc = $this->docs->createDraft($caller, $type, ['external_ref' => $h['invoice_number'], 'doc_date' => $h['invoice_date'], 'note' => $h['note']]);
            try {
                // Pack J (PJ6): an invoice's goods come in the way its supplier chose (else the setting) until the person chooses otherwise.
                $db->exec('INSERT INTO supplier_invoice (document_id, kind, supplier_id, invoice_key, net_total, vat_total, gross_total, credits_document_id, '
                    . 'debit_note_document_id, receive_mode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$doc->id, $kind, $supplierId, self::key($h['invoice_number']), $h['net_total'], $h['vat_total'], $h['gross_total'], $h['credits_id'], $h['debit_note_id'],
                        $kind === 'invoice' ? (new \CW\Arrivals\Arrivals($db))->prefsOf($supplierId)['mode'] : null, $this->nowDb(), $this->nowDb()]);
            } catch (\PDOException $e) {
                throw $this->duplicate($e, $kind, $supplierId, (string) self::key($h['invoice_number']), $doc->id);
            }
            Audit::write($db, $caller, $kind === 'invoice' ? 'invoice.create' : 'credit_note.create', 'document', (string) $doc->id, null,
                ['supplier' => (string) $s['code'], 'number' => $h['invoice_number']] + array_filter(['credits' => $h['credits_id'], 'debit_note' => $h['debit_note_id']]));
            return $doc;
        });
    }

    /**
     * The quick way: the invoice of a posted delivery, started with the delivery's supplier invoice number (left empty when a live
     * invoice of the supplier has it already: `number_taken` names it), the date on its invoice, its invoice copy (when the file store
     * is there) and a line for every delivery line with units still to invoice (addReceipt()). The desk then types the total without VAT
     * and the VAT exactly as the paper says, checks the lines against the paper, and posts.
     *
     * @return array{document: Document, number_taken: ?string, lines: int}
     */
    public function createFromReceipt(Caller $caller, int $grnId): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $grnId): array {
            self::mayPost($db, $caller, 'SINV');
            $g = $this->postedReceipt($grnId);
            $number = $g['external_ref'];
            $taken = null;
            $key = self::key($number);
            if ($key !== null) {
                $other = $db->one("SELECT d.id, d.number, d.status FROM supplier_invoice si JOIN document d ON d.id = si.document_id WHERE si.supplier_id = ? AND si.kind = 'invoice' "
                    . 'AND si.invoice_key = ?', [(int) $g['supplier_id'], $key]);
                if ($other !== null) {
                    $taken = (string) ($other['number'] ?? ('#' . $other['id']));
                    $number = null;
                }
            }
            $doc = $this->createDraft($caller, 'invoice', (int) $g['supplier_id'], ['invoice_number' => $number,
                'invoice_date' => $g['invoice_date'] ?? $g['doc_date']]);
            $doc = $this->addReceipt($caller, $doc->id, $doc->version, $grnId);
            $this->copyFiles($caller, $grnId, $doc->id);
            return ['document' => $this->docs->get($doc->id), 'number_taken' => $taken, 'lines' => count($this->docs->lines($doc->id))];
        });
    }

    /**
     * A credit note for one of our returns (the debit note): the supplier, the return it answers, the invoice the return names (when it
     * is a posted invoice of the supplier) and its lines: a goods credit for every returned item line on a delivery that invoice bills
     * (at most what is left to credit on it), the duty claimed, and the rest of the claim as "other" lines. The desk types the supplier's
     * credit note number, its date and totals, and posts.
     */
    public function createFromReturn(Caller $caller, int $dnId): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $dnId): Document {
            self::mayPost($db, $caller, 'SCN');
            $r = $db->one('SELECT d.id, d.status, d.doc_type, d.number, r.supplier_id, r.invoice_document_id, i.status AS invoice_status FROM document d '
                . 'JOIN supplier_return r ON r.document_id = d.id LEFT JOIN document i ON i.id = r.invoice_document_id WHERE d.id = ?', [$dnId]);
            if ($r === null || $r['doc_type'] !== 'DN' || $r['status'] !== 'posted') {
                throw new CwException('return_not_posted', 'only a posted return gets a credit note', 409);
            }
            $credits = $r['invoice_document_id'] !== null && $r['invoice_status'] === 'posted' ? (int) $r['invoice_document_id'] : null;
            $doc = $this->createDraft($caller, 'credit_note', (int) $r['supplier_id'], ['credits_id' => $credits, 'debit_note_id' => $dnId]);
            $invLines = [];
            if ($credits !== null) {
                foreach ($db->all('SELECT dl.line_no, dl.sku_id, dl.qty, dl.unit_cost, sl.units_per_pack, sl.pack_price, sl.grn_document_id, sl.grn_line_no, sl.po_document_id, sl.po_line_no FROM document_line dl '
                    . "JOIN sinv_line sl ON sl.document_id = dl.document_id AND sl.line_no = dl.line_no WHERE dl.document_id = ? AND sl.kind = 'goods' ORDER BY dl.line_no",
                    [$credits]) as $l) {
                    $invLines[] = $l;
                }
            }
            $left = [];
            foreach ($invLines as $l) {
                $left[(int) $l['line_no']] = (int) $l['qty'] - $this->creditedUnits($credits, (int) $l['line_no'], $doc->id);
            }
            $lines = [];
            foreach ($db->all('SELECT dl.line_no, dl.sku_id, dl.qty, dl.unit_cost, dl.amount, dl.description, n.grn_document_id, n.grn_line_no, n.duty_amount, s.code '
                . 'FROM document_line dl JOIN dn_line n ON n.document_id = dl.document_id AND n.line_no = dl.line_no LEFT JOIN sku s ON s.id = dl.sku_id '
                . 'WHERE dl.document_id = ? ORDER BY dl.line_no', [$dnId]) as $d) {
                $duty = InvoiceMath::e2((string) $d['duty_amount']);
                $goodsE2 = InvoiceMath::e2((string) $d['amount']) - $duty;
                $what = 'Return ' . $r['number'] . ' line ' . $d['line_no'] . ($d['code'] === null ? '' : ' ' . $d['code']);
                $remaining = (int) ($d['qty'] ?? 0);
                if ($d['sku_id'] !== null && $d['grn_document_id'] !== null) {
                    foreach ($invLines as $l) {
                        $no = (int) $l['line_no'];
                        if ((int) $l['sku_id'] === (int) $d['sku_id'] && (string) $l['grn_document_id'] === (string) $d['grn_document_id']
                            && (string) $l['grn_line_no'] === (string) $d['grn_line_no'] && $left[$no] > 0) {
                            $units = min($left[$no], $remaining);
                            $left[$no] -= $units;
                            $remaining -= $units;
                            // Keep complete packs at their original price when the return uses that unit cost.
                            $upp = (int) $l['units_per_pack'];
                            $whole = $units % $upp === 0 && (string) $l['unit_cost'] === (string) $d['unit_cost'];
                            $packs = $whole ? intdiv($units, $upp) : $units;
                            $price = $whole ? (string) $l['pack_price'] : self::price4((string) $d['unit_cost']);
                            $lines[] = ['kind' => 'goods', 'sku_id' => (int) $d['sku_id'], 'packs' => $packs, 'units_per_pack' => $whole ? $upp : 1,
                                'pack_price' => $price, 'credit_of_line_no' => $no, 'grn_document_id' => (int) $l['grn_document_id'],
                                'grn_line_no' => (int) $l['grn_line_no'], 'po_document_id' => $l['po_document_id'] === null ? null : (int) $l['po_document_id'],
                                'po_line_no' => $l['po_line_no'] === null ? null : (int) $l['po_line_no'], 'entry' => 'credit', 'description' => $what];
                            $goodsE2 -= PoMath::lineAmountE2($packs, PoMath::e4($price));
                            if ($remaining === 0) {
                                break;
                            }
                        }
                    }
                }
                if ($goodsE2 !== 0) {
                    $lines[] = ['kind' => 'other', 'amount' => InvoiceMath::fromE2($goodsE2), 'description' => $what, 'entry' => 'credit'];
                }
                if ($duty > 0) {
                    $lines[] = ['kind' => 'duty', 'amount' => InvoiceMath::fromE2($duty), 'description' => 'Duty: ' . $what, 'entry' => 'credit'];
                }
            }
            $lines = array_values(array_filter($lines, static fn (array $l): bool => $l['kind'] === 'goods' || InvoiceMath::e2((string) $l['amount']) !== 0));
            return $lines === [] ? $doc : $this->saveLines($caller, $doc->id, $doc->version, $lines);
        });
    }

    /**
     * Changes a draft's header (the keys given; '' clears an optional one). Creator only (Documents::updateDraft). The invoice a credit
     * note reduces changes only while none of its lines credits that invoice's lines (409 has_credit_lines).
     *
     * @param array<string, mixed> $header
     */
    public function saveHeader(Caller $caller, int $id, int $version, array $header): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $header): Document {
            [$kind, $cur] = $this->current($id);
            self::mayPost($db, $caller, self::KINDS[$kind]);
            $h = $this->header($kind, (int) $cur['supplier_id'], $header, $cur);
            if ($h['credits_id'] !== ($cur['credits_document_id'] === null ? null : (int) $cur['credits_document_id'])
                && $db->value('SELECT 1 FROM sinv_line WHERE document_id = ? AND credit_of_line_no IS NOT NULL LIMIT 1', [$id]) !== null) {
                throw new CwException('has_credit_lines', 'the invoice a credit note reduces changes only while none of its lines credits that invoice: remove those lines first',
                    409, ['field' => 'credits_id']);
            }
            $doc = $this->docs->updateDraft($caller, $id, $version, ['external_ref' => $h['invoice_number'], 'doc_date' => $h['invoice_date'], 'note' => $h['note']]);
            try {
                $db->exec('UPDATE supplier_invoice SET invoice_key = ?, net_total = ?, vat_total = ?, gross_total = ?, credits_document_id = ?, debit_note_document_id = ?, '
                    . 'updated_at = ? WHERE document_id = ?', [self::key($h['invoice_number']), $h['net_total'], $h['vat_total'], $h['gross_total'], $h['credits_id'],
                        $h['debit_note_id'], $this->nowDb(), $id]);
            } catch (\PDOException $e) {
                throw $this->duplicate($e, $kind, (int) $cur['supplier_id'], (string) self::key($h['invoice_number']), $id);
            }
            Audit::write($db, $caller, $kind === 'invoice' ? 'invoice.header' : 'credit_note.header', 'document', (string) $id, null,
                ['version' => $doc->version, 'number' => $h['invoice_number'], 'net' => $h['net_total'], 'vat' => $h['vat_total']]);
            return $doc;
        });
    }

    /**
     * Replaces every line of a draft (line_no 1..N in list order). A line: kind (goods | freight | duty | service | other); a goods line
     * names its item (sku_id), packs, units_per_pack and pack_price (GBP excl. VAT, as on the paper), and optionally the delivery line
     * (grn_document_id + grn_line_no), the order line (po_document_id + po_line_no) and, on a credit note, the invoice line it credits
     * (credit_of_line_no); any other line names an amount (GBP excl. VAT) and a description. At most MAX_LINES lines. Creator only.
     *
     * @param list<array<string, mixed>> $lines
     */
    public function saveLines(Caller $caller, int $id, int $version, array $lines): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $lines): Document {
            [$kind, $cur] = $this->current($id);
            self::mayPost($db, $caller, self::KINDS[$kind]);
            $norm = $this->normaliseLines($kind, $cur, $lines);
            // Pack J (PJ9): the duty stamp answered for "book as received" stays with a line whose item is unchanged.
            $answers = [];
            foreach ($db->all('SELECT sl.line_no, dl.sku_id, sl.stamp_answer FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no '
                . 'WHERE sl.document_id = ? AND sl.stamp_answer IS NOT NULL', [$id]) as $a) {
                $answers[(int) $a['line_no']] = [(int) $a['sku_id'], (string) $a['stamp_answer']];
            }
            $doc = $this->docs->setLines($caller, $id, $version, array_map(static fn (array $n): array => $n['doc'], $norm));
            foreach (array_chunk($norm, 300, true) as $chunk) {
                $params = [];
                foreach ($chunk as $i => $n) {
                    $e = $n['ext'];
                    array_push($params, $id, $i + 1, $e['kind'], $e['grn_document_id'], $e['grn_line_no'], $e['po_document_id'], $e['po_line_no'], $e['credit_of_line_no'],
                        $e['supplier_code'], $e['purchase_unit'], $e['units_per_pack'], $e['packs'], $e['pack_price'], $e['entry']);
                }
                $db->exec('INSERT INTO sinv_line (document_id, line_no, kind, grn_document_id, grn_line_no, po_document_id, po_line_no, credit_of_line_no, supplier_code, '
                    . 'purchase_unit, units_per_pack, packs, pack_price, entry) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                    $params);
            }
            foreach ($norm as $i => $n) {
                [$sku, $answer] = $answers[$i + 1] ?? [null, null];
                if ($answer !== null && $n['ext']['kind'] === 'goods' && (int) ($n['doc']['sku_id'] ?? 0) === $sku) {
                    $db->exec('UPDATE sinv_line SET stamp_answer = ? WHERE document_id = ? AND line_no = ?', [$answer, $id, $i + 1]);
                }
            }
            return $doc;
        });
    }

    /**
     * Adds a line for every line of a posted delivery of the same supplier with units still to invoice (accepted - returned - billed by
     * the supplier's posted invoices - already on this draft), in the delivery's packs when the units are whole packs, else in units,
     * at the delivery's price, linked to the delivery line and its order line. 422 nothing_to_invoice when none is left.
     */
    public function addReceipt(Caller $caller, int $id, int $version, int $grnId): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $grnId): Document {
            [$kind, $cur] = $this->current($id);
            if ($kind !== 'invoice') {
                throw new CwException('not_an_invoice', 'deliveries are added to an invoice, not to a credit note', 409);
            }
            $g = $this->postedReceipt($grnId);
            if ((int) $g['supplier_id'] !== (int) $cur['supplier_id']) {
                throw new CwException('delivery_other_supplier', "{$g['number']} is another supplier's delivery", 422, ['field' => 'grn_id']);
            }
            $lines = $this->lineRows($id);
            $onDraft = [];
            foreach ($lines as $l) {
                if ($l['grn_document_id'] !== null) {
                    $k = $l['grn_document_id'] . ':' . $l['grn_line_no'];
                    $onDraft[$k] = ($onDraft[$k] ?? 0) + (int) $l['packs'] * (int) $l['units_per_pack'];
                }
            }
            $facts = $this->match->receiptFacts([$grnId], $id);
            $added = 0;
            foreach ($db->all('SELECT g.line_no, g.supplier_item_id, g.supplier_code, g.purchase_unit, g.units_per_pack, g.pack_price, g.po_line_no, dl.sku_id, dl.unit_cost '
                . 'FROM grn_line g JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no WHERE g.document_id = ? ORDER BY g.line_no', [$grnId]) as $gl) {
                $k = $grnId . ':' . $gl['line_no'];
                $f = $facts[$k] ?? null;
                if ($f === null) {
                    continue;
                }
                $left = $f['accepted'] - $f['returned'] - $f['invoiced_elsewhere'] - ($onDraft[$k] ?? 0);
                if ($left <= 0) {
                    continue;
                }
                $upp = (int) $gl['units_per_pack'];
                $whole = $left % $upp === 0;
                $lines[] = ['kind' => 'goods', 'sku_id' => (int) $gl['sku_id'], 'packs' => $whole ? intdiv($left, $upp) : $left, 'units_per_pack' => $whole ? $upp : 1,
                    'pack_price' => $whole ? (string) $gl['pack_price'] : self::price4((string) $gl['unit_cost']), 'grn_document_id' => $grnId, 'grn_line_no' => (int) $gl['line_no'],
                    'po_document_id' => $g['po_document_id'] !== null && $gl['po_line_no'] !== null ? (int) $g['po_document_id'] : null,
                    'po_line_no' => $g['po_document_id'] !== null && $gl['po_line_no'] !== null ? (int) $gl['po_line_no'] : null,
                    'supplier_code' => $gl['supplier_code'], 'purchase_unit' => $whole ? (string) $gl['purchase_unit'] : 'each', 'entry' => 'receipt', 'description' => null];
                $added++;
            }
            if ($added === 0) {
                throw new CwException('nothing_to_invoice', "every unit of {$g['number']} is invoiced already (or was returned)", 422, ['field' => 'grn_id']);
            }
            $doc = $this->saveLines($caller, $id, $version, $lines);
            Audit::write($db, $caller, 'invoice.add_delivery', 'document', (string) $id, null, ['delivery' => $g['number'], 'lines' => $added]);
            return $doc;
        });
    }

    /**
     * Adds the item lines of a posted order of the same supplier with units not invoiced yet (ordered - billed by posted invoice lines on
     * that order line - already on this draft), at the order's price, linked to the order line only: an invoice before its goods. Such
     * lines need review until the delivery is billed instead (the match has no delivery for them). 422 nothing_to_invoice.
     */
    public function addOrder(Caller $caller, int $id, int $version, int $poId): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $poId): Document {
            [$kind, $cur] = $this->current($id);
            if ($kind !== 'invoice') {
                throw new CwException('not_an_invoice', 'orders are added to an invoice, not to a credit note', 409);
            }
            $po = $db->one('SELECT d.id, d.number, d.status, p.supplier_id FROM document d JOIN purchase_order p ON p.document_id = d.id WHERE d.id = ?', [$poId]);
            if ($po === null || $po['status'] !== 'posted') {
                throw new CwException('order_not_posted', 'only a confirmed order is added', 422, ['field' => 'po_id']);
            }
            if ((int) $po['supplier_id'] !== (int) $cur['supplier_id']) {
                throw new CwException('order_other_supplier', "{$po['number']} is another supplier's order", 422, ['field' => 'po_id']);
            }
            $lines = $this->lineRows($id);
            $onDraft = [];
            foreach ($lines as $l) {
                if ($l['po_document_id'] !== null && (int) $l['po_document_id'] === $poId) {
                    $k = (int) $l['po_line_no'];
                    $onDraft[$k] = ($onDraft[$k] ?? 0) + (int) $l['packs'] * (int) $l['units_per_pack'];
                }
            }
            $billed = [];
            foreach ($db->all("SELECT sl.po_line_no, SUM(IF(si.kind = 'invoice', dl.qty, -dl.qty)) AS units FROM sinv_line sl JOIN supplier_invoice si ON si.document_id = sl.document_id "
                . "JOIN document d ON d.id = sl.document_id AND d.status = 'posted' JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no "
                . "WHERE sl.po_document_id = ? AND sl.kind = 'goods' GROUP BY sl.po_line_no", [$poId]) as $b) {
                $billed[(int) $b['po_line_no']] = (int) $b['units'];
            }
            $added = 0;
            foreach ($db->all("SELECT p.line_no, p.supplier_code, p.purchase_unit, p.units_per_pack, p.packs, p.pack_price, dl.sku_id, dl.unit_cost FROM po_line p "
                . "JOIN document_line dl ON dl.document_id = p.document_id AND dl.line_no = p.line_no WHERE p.document_id = ? AND p.kind = 'item' ORDER BY p.line_no", [$poId]) as $pl) {
                $no = (int) $pl['line_no'];
                $upp = (int) $pl['units_per_pack'];
                $left = (int) $pl['packs'] * $upp - ($billed[$no] ?? 0) - ($onDraft[$no] ?? 0);
                if ($left <= 0 || $pl['sku_id'] === null) {
                    continue;
                }
                $whole = $left % $upp === 0;
                $lines[] = ['kind' => 'goods', 'sku_id' => (int) $pl['sku_id'], 'packs' => $whole ? intdiv($left, $upp) : $left, 'units_per_pack' => $whole ? $upp : 1,
                    'pack_price' => $whole ? (string) $pl['pack_price'] : self::price4((string) $pl['unit_cost']), 'po_document_id' => $poId, 'po_line_no' => $no,
                    'supplier_code' => $pl['supplier_code'], 'purchase_unit' => $whole ? (string) $pl['purchase_unit'] : 'each', 'entry' => 'order', 'description' => null];
                $added++;
            }
            if ($added === 0) {
                throw new CwException('nothing_to_invoice', "every unit of {$po['number']} is invoiced already", 422, ['field' => 'po_id']);
            }
            $doc = $this->saveLines($caller, $id, $version, $lines);
            Audit::write($db, $caller, 'invoice.add_order', 'document', (string) $id, null, ['order' => $po['number'], 'lines' => $added]);
            return $doc;
        });
    }

    /**
     * Adds a goods line by barcode, CW number or words (StockOps::resolve: `choices` when several match, `not_found` when none): packs of
     * the barcode's units (or of this supplier's pack of the item when the barcode is the unit's own), at the price typed, else the
     * supplier item's last price, else £0. Not linked to a delivery or an order (the match flags it: no delivery).
     *
     * @return array{status: string, document: Document, line_no?: int, choices?: list<array<string, mixed>>}
     */
    public function addItem(Caller $caller, int $id, int $version, string $q, int $packs, ?string $packPrice, ?int $skuId = null): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $q, $packs, $packPrice, $skuId): array {
            [, $cur] = $this->current($id);
            if ($packs < 1 || $packs > PoMath::MAX_PACKS) {
                throw new CwException('bad_line', 'packs: a whole number from 1 to ' . number_format(PoMath::MAX_PACKS), 422, ['field' => 'packs']);
            }
            $per = 1;
            if ($skuId === null) {
                $r = (new StockOps($db, $this->docs))->resolve($q);
                if ($r['status'] !== 'found') {
                    return ['status' => $r['status'], 'document' => $this->docs->get($id), 'choices' => $r['choices'] ?? []];
                }
                $skuId = (int) $r['sku_id'];
                $per = (int) $r['units'];
            } elseif ($db->value('SELECT 1 FROM sku WHERE id = ? AND merged_into_sku_id IS NULL', [$skuId]) === null) {
                throw new CwException('unknown_sku', 'there is no such item', 422);
            }
            $si = $db->one('SELECT supplier_code, purchase_unit, units_per_pack, last_pack_price FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND is_active = 1 '
                . 'ORDER BY is_preferred DESC, id LIMIT 1', [(int) $cur['supplier_id'], $skuId]);
            $upp = $per > 1 || $si === null ? $per : (int) $si['units_per_pack'];
            $price = $packPrice !== null && trim($packPrice) !== '' ? self::packPrice($packPrice, 'pack_price')
                : ($si !== null && $si['last_pack_price'] !== null && $upp === (int) $si['units_per_pack'] ? (string) $si['last_pack_price'] : '0.0000');
            $lines = $this->lineRows($id);
            $lines[] = ['kind' => 'goods', 'sku_id' => $skuId, 'packs' => $packs, 'units_per_pack' => $upp, 'pack_price' => $price,
                'supplier_code' => $si['supplier_code'] ?? null, 'purchase_unit' => $si !== null && $upp === (int) $si['units_per_pack'] ? (string) $si['purchase_unit'] : 'each',
                'entry' => 'manual', 'description' => null];
            $doc = $this->saveLines($caller, $id, $version, $lines);
            return ['status' => 'added', 'document' => $doc, 'line_no' => count($lines)];
        });
    }

    /** Adds a freight, duty, service or other line: an amount (GBP excl. VAT) and what it is for. */
    public function addCharge(Caller $caller, int $id, int $version, string $kind, string $amount, string $description): Document
    {
        if (!in_array($kind, self::CHARGE_KINDS, true)) {
            throw new CwException('bad_line', 'the kind of line is freight, duty, service or other', 422, ['field' => 'kind']);
        }
        return $this->db->transaction(function () use ($caller, $id, $version, $kind, $amount, $description): Document {
            $this->current($id);
            $lines = $this->lineRows($id);
            $lines[] = ['kind' => $kind, 'amount' => self::money($amount, 'amount', true), 'description' => $description, 'entry' => 'manual'];
            return $this->saveLines($caller, $id, $version, $lines);
        });
    }

    /**
     * A credit note: credits $units of line $invoiceLineNo of the invoice it reduces, at the price typed (per unit) or that line's unit
     * price; the line keeps the invoice line's delivery and order. 422 credit_more_than_invoiced beyond what is left to credit.
     */
    public function addCredit(Caller $caller, int $id, int $version, int $invoiceLineNo, int $units, ?string $unitPrice = null): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $invoiceLineNo, $units, $unitPrice): Document {
            [$kind, $cur] = $this->current($id);
            if ($kind !== 'credit_note' || $cur['credits_document_id'] === null) {
                throw new CwException('credited_invoice_required', 'say first which invoice the credit note reduces', 422, ['field' => 'credits_id']);
            }
            $inv = (int) $cur['credits_document_id'];
            $l = $db->one('SELECT dl.sku_id, dl.qty, dl.unit_cost, sl.kind, sl.grn_document_id, sl.grn_line_no, sl.po_document_id, sl.po_line_no FROM document_line dl '
                . 'JOIN sinv_line sl ON sl.document_id = dl.document_id AND sl.line_no = dl.line_no WHERE dl.document_id = ? AND dl.line_no = ?', [$inv, $invoiceLineNo]);
            if ($l === null || $l['kind'] !== 'goods') {
                throw new CwException('credit_line_mismatch', "the invoice has no goods line {$invoiceLineNo}", 422, ['field' => 'line']);
            }
            $lines = $this->lineRows($id);
            $onDraft = 0;
            foreach ($lines as $x) {
                if ($x['credit_of_line_no'] !== null && (int) $x['credit_of_line_no'] === $invoiceLineNo) {
                    $onDraft += (int) $x['packs'] * (int) $x['units_per_pack'];
                }
            }
            $left = (int) $l['qty'] - $this->creditedUnits($inv, $invoiceLineNo, $id) - $onDraft;
            if ($units < 1 || $units > $left) {
                throw new CwException('credit_more_than_invoiced', "line {$invoiceLineNo} has {$left} units left to credit", 422, ['field' => 'units', 'left' => max(0, $left)]);
            }
            $price = $unitPrice !== null && trim($unitPrice) !== '' ? self::packPrice($unitPrice, 'unit_price') : self::price4((string) $l['unit_cost']);
            $lines[] = ['kind' => 'goods', 'sku_id' => (int) $l['sku_id'], 'packs' => $units, 'units_per_pack' => 1, 'pack_price' => $price, 'credit_of_line_no' => $invoiceLineNo,
                'grn_document_id' => $l['grn_document_id'] === null ? null : (int) $l['grn_document_id'], 'grn_line_no' => $l['grn_line_no'] === null ? null : (int) $l['grn_line_no'],
                'po_document_id' => $l['po_document_id'] === null ? null : (int) $l['po_document_id'], 'po_line_no' => $l['po_line_no'] === null ? null : (int) $l['po_line_no'],
                'entry' => 'credit', 'description' => null];
            return $this->saveLines($caller, $id, $version, $lines);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Posting, stopping, reversing, files
    // ------------------------------------------------------------------------------------------

    /** Posts a draft (Documents::post: a reviewer's OK first when a rule asks; SupplierInvoiceHandler checks and records it). */
    public function post(Caller $caller, int $id, int $version): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version): Document {
            $this->current($id);
            return $this->docs->post($caller, $id, $version);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Pack J (0041; docs/decisions.md PJ6-PJ11): how an invoice's goods come in, and when it is made final
    // ------------------------------------------------------------------------------------------

    public const RECEIVE_MODES = ['count', 'received'];
    public const STAMP_ANSWERS = ['digital', 'transitional'];

    /**
     * How a draft invoice's goods not received yet come in when it is made final (count: "count on arrival", they wait in Arriving and
     * Goods In receives them; received: "book as received", a receipt is booked at once) and, for "book as received", the duty stamp on
     * the pack of each goods line ($stamps line_no => digital | transitional | '' for none given). Creator only (Documents::updateDraft:
     * version + 1); audit invoice.receiving.
     *
     * @param array<int, string> $stamps
     */
    public function setReceiving(Caller $caller, int $id, int $version, string $mode, array $stamps): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $mode, $stamps): Document {
            [$kind] = $this->current($id);
            self::mayPost($db, $caller, 'SINV');
            if ($kind !== 'invoice') {
                throw new CwException('not_an_invoice', 'only an invoice brings goods in', 409);
            }
            if (!in_array($mode, self::RECEIVE_MODES, true)) {
                throw new CwException('bad_mode', 'choose how its goods come in: count on arrival, or book as received', 400, ['field' => 'receive_mode']);
            }
            $doc = $this->docs->updateDraft($caller, $id, $version, []);
            $goods = array_map('intval', $db->column("SELECT line_no FROM sinv_line WHERE document_id = ? AND kind = 'goods'", [$id]));
            $set = [];
            foreach ($stamps as $no => $answer) {
                $answer = $answer === null ? '' : trim((string) $answer);
                if (!is_int($no) || !in_array($no, $goods, true)) {
                    throw new CwException('bad_line', "line {$no}: only a goods line has a duty stamp", 422, ['line' => $no]);
                }
                if ($answer !== '' && !in_array($answer, self::STAMP_ANSWERS, true)) {
                    throw new CwException('bad_stamp', "line {$no}: the stamp is digital or transitional", 400, ['line' => $no]);
                }
                $db->exec('UPDATE sinv_line SET stamp_answer = ? WHERE document_id = ? AND line_no = ?', [$answer === '' ? null : $answer, $id, $no]);
                $set[$no] = $answer === '' ? null : $answer;
            }
            $db->exec('UPDATE supplier_invoice SET receive_mode = ?, updated_at = ? WHERE document_id = ?', [$mode, $this->nowDb(), $id]);
            Audit::write($db, $caller, 'invoice.receiving', 'document', (string) $id, null, ['mode' => $mode, 'stamps' => $set, 'version' => $doc->version]);
            return $doc;
        });
    }

    /**
     * Sets (or, with null, takes off) the time a draft invoice is made final by the job (bin/submit_invoices.php, every minute), as the
     * person who sets it (their posting: their roles are checked again then). The time is in the future (422 bad_submit_at); there is no
     * fixed time, each invoice gets its own. Until then the draft is changed, re-timed or stopped as any draft; a failed attempt is kept
     * and not tried again until the time is set again. Creator only (version + 1); audit invoice.schedule.
     */
    public function schedule(Caller $caller, int $id, int $version, ?\DateTimeImmutable $at): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $at): Document {
            [$kind] = $this->current($id);
            self::mayPost($db, $caller, 'SINV');
            if ($kind !== 'invoice') {
                throw new CwException('not_an_invoice', 'a credit note is made final by hand', 409);
            }
            $now = ($this->clock)();
            if ($at !== null && $at <= $now) {
                throw new CwException('bad_submit_at', 'choose a time still to come (or make it final now)', 422, ['field' => 'submit_at']);
            }
            $doc = $this->docs->updateDraft($caller, $id, $version, []);
            if ($at === null) {
                $db->exec('UPDATE supplier_invoice SET submit_at = NULL, submit_by = NULL, submit_actor = NULL, submit_failed_at = NULL, submit_error = NULL, updated_at = ? '
                    . 'WHERE document_id = ?', [Clock::db($now), $id]);
            } else {
                $db->exec('UPDATE supplier_invoice SET submit_at = ?, submit_by = ?, submit_actor = ?, submit_failed_at = NULL, submit_error = NULL, updated_at = ? '
                    . 'WHERE document_id = ?', [Clock::db($at), $caller->staffUserId, $caller->actor, Clock::db($now), $id]);
            }
            Audit::write($db, $caller, 'invoice.schedule', 'document', (string) $id, null, ['submit_at' => $at === null ? null : Clock::iso(Clock::db($at)),
                'version' => $doc->version]);
            return $doc;
        });
    }

    /**
     * Makes a draft invoice final now (the "Make it final" button and the timed job). "Count on arrival" (and a credit note, and an
     * invoice whose goods all came on deliveries): Documents::post (SupplierInvoiceHandler books what is on its way). "Book as received"
     * with goods no delivery names: (1) every problem refuses first, nothing booked; (2) its receipt is booked and posted in a
     * transaction of its own (Arrivals::bookAsReceived: once, submit_receipt_id); (3) its lines are linked to that receipt and it is
     * posted. A failure after (2) leaves the receipt booked and the invoice a draft that names it: the next attempt goes on at (3).
     */
    public function submit(Caller $caller, int $id, int $version): Document
    {
        $h = SupplierInvoiceHandler::header($this->db, $id) ?? throw new CwException('unknown_document', 'there is no such supplier invoice', 404);
        if (($h['receive_mode'] ?? null) !== 'received' || $h['kind'] !== 'invoice') {
            return $this->post($caller, $id, $version);
        }
        $doc = $this->docs->get($id);
        if ($doc->status !== 'draft') {
            throw new CwException('not_draft', "{$doc->label()} is " . str_replace('_', ' ', $doc->status) . ': only a draft is made final', 409, ['status' => $doc->status]);
        }
        if ($doc->version !== $version) {
            throw new CwException('version_conflict', 'this invoice was changed meanwhile: reload it', 409, ['version' => $doc->version]);
        }
        // Review finding 3 (Codex, 15 Oct): the right to make it final is checked before its receipt is booked (again under the lock).
        self::mayPost($this->db, $caller, 'SINV');
        if ($h['submit_receipt_id'] === null) {
            if (SupplierInvoiceHandler::notReceived($this->db, $id) === []) {
                return $this->post($caller, $id, $version); // every goods line came on a delivery already
            }
            SupplierInvoiceHandler::refuse($this->problems($id)); // nothing is booked while anything stops it
        }
        // Review finding 2 (Codex, 15 Oct): the version checked above is checked again under the invoice's lock before the receipt is booked.
        $grnId = (new \CW\Arrivals\Arrivals($this->db, $this->docs, $this->files, $this->clock))->bookAsReceived($caller, $id, $version)->id;
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $grnId): Document {
            $this->current($id);
            $open = SupplierInvoiceHandler::notReceived($db, $id);
            $grnLines = $db->all('SELECT line_no, sku_id FROM document_line WHERE document_id = ? ORDER BY line_no', [$grnId]);
            if (count($open) > count($grnLines)) {
                throw new CwException('receipt_mismatch', 'the invoice\'s lines changed after its receipt was booked: cancel that receipt or put the lines back', 409);
            }
            $skus = [];
            foreach ($db->all('SELECT line_no, sku_id FROM document_line WHERE document_id = ?', [$id]) as $l) {
                $skus[(int) $l['line_no']] = (int) $l['sku_id'];
            }
            $taken = array_map('intval', $db->column('SELECT grn_line_no FROM sinv_line WHERE document_id = ? AND grn_document_id = ?', [$id, $grnId]));
            $free = array_values(array_filter($grnLines, static fn (array $g): bool => !in_array((int) $g['line_no'], $taken, true)));
            foreach ($open as $i => $no) {
                $g = $free[$i] ?? null;
                if ($g === null || (int) $g['sku_id'] !== ($skus[$no] ?? -1)) {
                    throw new CwException('receipt_mismatch', "line {$no} is not the item its receipt booked: put the line back, or cancel that receipt", 409, ['line' => $no]);
                }
                $db->exec('UPDATE sinv_line SET grn_document_id = ?, grn_line_no = ? WHERE document_id = ? AND line_no = ?', [$grnId, (int) $g['line_no'], $id, $no]);
            }
            return $this->docs->post($caller, $id, $version);
        });
    }

    /**
     * The timed drafts: those whose last attempt failed first (with why), then by time. Failed ones are what the alerts name.
     *
     * @return list<array<string, mixed>>
     */
    public function scheduled(?int $supplierId = null): array
    {
        return $this->db->all(
            'SELECT d.id, d.external_ref, d.version, si.supplier_id, s.name AS supplier_name, si.submit_at, si.submit_failed_at, si.submit_error, si.receive_mode, '
            . "u.display_name AS submit_by_name FROM supplier_invoice si JOIN document d ON d.id = si.document_id AND d.status = 'draft' JOIN supplier s ON s.id = si.supplier_id "
            . 'LEFT JOIN staff_user u ON u.id = si.submit_by WHERE si.submit_at IS NOT NULL' . ($supplierId === null ? '' : ' AND si.supplier_id = ?')
            . ' ORDER BY si.submit_failed_at IS NULL, si.submit_at, d.id LIMIT ' . self::LIST_LIMIT,
            $supplierId === null ? [] : [$supplierId],
        );
    }

    /** Stops a draft before it is posted ($reason 3-500 characters); its number is free again (SupplierInvoiceHandler::cancelled). */
    public function cancel(Caller $caller, int $id, int $version, string $reason): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version, $reason): Document {
            $this->current($id);
            return $this->docs->cancelDraft($caller, $id, $version, $reason);
        });
    }

    /**
     * Cancels a posted invoice or credit note by its reversal (Documents::reverse; a reason offered for cancellations). An invoice with a
     * posted credit note is refused (409 has_credit_notes). Returns the reversal.
     */
    public function reverse(Caller $caller, int $id, string $reasonCode, ?string $note): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $reasonCode, $note): Document {
            $this->kindOfDocument($id) ?? throw new CwException('not_an_invoice', 'this is not a supplier invoice or credit note', 404);
            return $this->docs->reverse($caller, $id, $reasonCode, $note);
        });
    }

    /** The requester takes back a record waiting for a reviewer's OK: it is a draft again (Documents::withdraw). */
    public function withdraw(Caller $caller, int $id): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id): Document {
            $this->kindOfDocument($id) ?? throw new CwException('not_an_invoice', 'this is not a supplier invoice or credit note', 404);
            $task = $db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = 'approval' AND state = 'open'", [$id])
                ?? throw new CwException('not_withdrawable', 'only a record waiting for a reviewer\'s OK is taken back', 409);
            return $this->docs->withdraw($caller, (int) $task);
        });
    }

    /**
     * Stores a file (FileStore: type sniffed, deduplicated, kept 7 years) and attaches it under $role (FILE_ROLES): the supplier's
     * invoice or credit note, a delivery note, other evidence. Any record but a stopped draft.
     *
     * @return array{file_id: int, mime: string, attached: bool, deduped: bool}
     */
    public function attach(Caller $caller, int $id, string $path, string $name, string $role): array
    {
        if (!isset(self::FILE_ROLES[$role])) {
            throw new CwException('bad_file_role', 'attach the file as ' . implode(', ', array_keys(self::FILE_ROLES)), 400, ['field' => 'role']);
        }
        $files = $this->files === null ? throw new CwException('file_store_unconfigured', 'the file store is not set up on this server', 503) : ($this->files)();
        return $this->db->transaction(function (Db $db) use ($caller, $id, $path, $name, $role, $files): array {
            $row = $db->one('SELECT id, doc_type, status FROM document WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('unknown_document', 'there is no such record', 404);
            if (self::kindOf((string) $row['doc_type']) === null) {
                throw new CwException('not_an_invoice', 'this is not a supplier invoice or credit note', 404);
            }
            self::mayPost($db, $caller, (string) $row['doc_type']);
            if ($row['status'] === 'cancelled') {
                throw new CwException('document_cancelled', 'a stopped record takes no files', 409);
            }
            $stored = $files->store($caller, $path, $name, self::FILE_ROLES[$role], null);
            $attached = $files->attach($caller, $id, $stored['id'], $role);
            return ['file_id' => $stored['id'], 'mime' => $stored['mime'], 'attached' => $attached, 'deduped' => $stored['deduped']];
        });
    }

    /**
     * A person accepts the differences of a posted invoice that needs review (invoices.accept; while approvals.invoice_accept_other is
     * on, not the person who posted it: 403 own_invoice). $note (3-500 characters) says why. $fingerprint is the displayed match's
     * InvoiceMatch::fingerprint(); changed figures are refused under the receipt locks (409 match_changed). An invoice that no longer
     * needs review is not accepted (409 not_needs_review). It stays `accepted` while its match is what was accepted.
     */
    public function accept(Caller $caller, int $id, string $note, string $fingerprint): Document
    {
        $note = trim($note);
        if (mb_strlen($note) < self::ACCEPT_NOTE_MIN || mb_strlen($note) > self::ACCEPT_NOTE_MAX) {
            throw new CwException('note_required', 'say in ' . self::ACCEPT_NOTE_MIN . ' to ' . self::ACCEPT_NOTE_MAX . ' characters why the differences are accepted', 400,
                ['field' => 'note']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $note, $fingerprint): Document {
            if ($caller->staffUserId === null) {
                throw new CwException('staff_required', 'invoices are checked by staff', 403);
            }
            $roles = StaffRoles::active($db, $caller->staffUserId);
            if (in_array('admin', $roles, true) || !Permissions::can($roles, 'invoices.accept')) {
                throw new CwException(in_array('admin', $roles, true) ? 'admin_cannot_post' : 'role_not_allowed', 'you cannot accept the differences of an invoice', 403);
            }
            $doc = $db->one('SELECT * FROM document WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('unknown_document', 'there is no such record', 404);
            if ($doc['doc_type'] !== 'SINV') {
                throw new CwException('not_an_invoice', 'only a supplier invoice is matched', 404);
            }
            if ($doc['status'] !== 'posted') {
                throw new CwException('not_needs_review', 'only a posted invoice that needs review is accepted', 409);
            }
            $switch = ApprovalRules::lockedSwitch($db, 'approvals.invoice_accept_other');
            if ($switch['on'] && (int) $doc['posted_by'] === $caller->staffUserId) {
                throw new CwException('own_invoice', 'you posted this invoice: another person accepts its differences', 403);
            }
            $grns = SupplierInvoiceHandler::receiptIds($db, $id);
            $this->match->lockReceipts($grns);
            $db->one('SELECT document_id FROM supplier_invoice WHERE document_id = ? FOR UPDATE', [$id]);
            $m = $this->match->compute($id);
            if ($m['state'] !== 'needs_review') {
                $this->match->refresh([$id], $this->nowDb());
                throw new CwException('not_needs_review', 'this invoice does not need review now', 409, ['state' => $m['state']]);
            }
            if (!hash_equals(InvoiceMatch::fingerprint($m), $fingerprint)) {
                throw new CwException('match_changed', 'the invoice differences changed: review the current figures before accepting', 409);
            }
            $now = $this->nowDb();
            $db->exec('UPDATE supplier_invoice SET accepted_by = ?, accepted_actor = ?, accepted_at = ?, accept_note = ?, accepted_lines_off = ?, accepted_difference = ?, '
                . 'accepted_fingerprint = ?, updated_at = ? WHERE document_id = ?', [$caller->staffUserId, $caller->actor, $now, $note, $m['lines_off'],
                    InvoiceMath::fromE2($m['difference_e2']), InvoiceMatch::fingerprint($m), $now, $id]);
            $this->match->refresh([$id], $now);
            Audit::write($db, $caller, 'invoice.accept', 'document', (string) $id, null, ['number' => $doc['number'], 'lines_off' => $m['lines_off'],
                'difference' => InvoiceMath::fromE2($m['difference_e2']), 'note' => $note] + ($switch['evidence'] === null ? [] : ['rule_evidence' => $switch['evidence']]));
            return $this->docs->get($id);
        });
    }

    /**
     * Works the stored match of these posted invoices out again with the tolerances in force now (the "To match" list's "Check again",
     * after a tolerance changed). doc.SINV.post or invoices.accept. Returns how many changed state.
     *
     * @param list<int> $ids
     */
    public function recheck(Caller $caller, array $ids): int
    {
        return $this->db->transaction(function (Db $db) use ($caller, $ids): int {
            if ($caller->staffUserId === null) {
                throw new CwException('staff_required', 'invoices are checked by staff', 403);
            }
            $roles = StaffRoles::active($db, $caller->staffUserId);
            if (!Documents::mayPost($roles, 'SINV') && (in_array('admin', $roles, true) || !Permissions::can($roles, 'invoices.accept'))) {
                throw new CwException('role_not_allowed', 'you cannot check invoices again', 403);
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
            if ($ids === []) {
                return 0;
            }
            $grns = [];
            foreach ($ids as $i) {
                array_push($grns, ...SupplierInvoiceHandler::receiptIds($db, $i));
            }
            $this->match->lockReceipts($grns);
            $in = implode(', ', array_fill(0, count($ids), '?'));
            $before = [];
            foreach ($db->all("SELECT document_id, match_state FROM supplier_invoice WHERE document_id IN ({$in})", $ids) as $r) {
                $before[(int) $r['document_id']] = $r['match_state'];
            }
            $this->match->refresh($ids, $this->nowDb());
            $changed = 0;
            foreach ($db->all("SELECT document_id, match_state FROM supplier_invoice WHERE document_id IN ({$in})", $ids) as $r) {
                $changed += ($before[(int) $r['document_id']] ?? null) !== $r['match_state'] ? 1 : 0;
            }
            Audit::write($db, $caller, 'invoice.recheck', null, null, null, ['invoices' => count($ids), 'changed' => $changed]);
            return $changed;
        });
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    /** The kind of a supplier invoice or credit note ('invoice' | 'credit_note'), or null for any other document. */
    public function kindOfDocument(int $id): ?string
    {
        $t = $this->db->value('SELECT doc_type FROM document WHERE id = ?', [$id]);
        return $t === null ? null : self::kindOf((string) $t);
    }

    /**
     * Everything a record's page shows about its header: the document row, the supplier, the totals and the match, the people, the
     * record it cancels and the one that cancels it, the invoice a credit note reduces and the return it answers, and what is owed.
     *
     * @return array<string, mixed>|null
     */
    public function record(int $id): ?array
    {
        $r = $this->db->one(
            'SELECT d.*, si.kind, si.supplier_id, si.net_total, si.vat_total, si.gross_total, si.credits_document_id, si.debit_note_document_id, si.match_state, '
            . 'si.match_lines_off, si.match_difference, si.match_checked_at, si.accepted_at, si.accept_note, s.code AS supplier_code, s.name AS supplier_name, '
            . 'si.receive_mode, si.submit_at, si.submit_by, si.submit_failed_at, si.submit_error, si.submit_receipt_id, tb.display_name AS submit_by_name, '
            . 'ci.number AS credits_number, dn.number AS debit_note_number, ab.display_name AS accepted_by_name, '
            . 'cb.display_name AS created_by_name, sb.display_name AS submitted_by_name, pb.display_name AS posted_by_name, xb.display_name AS cancelled_by_name, '
            . 'od.number AS reverses_number, rv.id AS reversed_by_id, rv.number AS reversed_by_number, rv.status AS reversed_by_status '
            . 'FROM document d LEFT JOIN supplier_invoice si ON si.document_id = COALESCE(d.reverses_id, d.id) LEFT JOIN supplier s ON s.id = si.supplier_id '
            . 'LEFT JOIN document ci ON ci.id = si.credits_document_id LEFT JOIN document dn ON dn.id = si.debit_note_document_id '
            . 'LEFT JOIN staff_user ab ON ab.id = si.accepted_by LEFT JOIN staff_user cb ON cb.id = d.created_by LEFT JOIN staff_user sb ON sb.id = d.submitted_by '
            . 'LEFT JOIN staff_user tb ON tb.id = si.submit_by '
            . 'LEFT JOIN staff_user pb ON pb.id = d.posted_by LEFT JOIN staff_user xb ON xb.id = d.cancelled_by LEFT JOIN document od ON od.id = d.reverses_id '
            . "LEFT JOIN document rv ON rv.reverses_id = d.id AND rv.status IN ('posted', 'awaiting_approval') WHERE d.id = ?",
            [$id],
        );
        if ($r === null || self::kindOf((string) $r['doc_type']) === null) {
            return null;
        }
        $r['kind'] = self::kindOf((string) $r['doc_type']);
        $r['credited_e2'] = 0;
        $r['credit_notes'] = [];
        if ($r['kind'] === 'invoice' && $r['reverses_id'] === null) {
            foreach ($this->db->all('SELECT d.id, d.number, d.status, d.external_ref, si.gross_total FROM supplier_invoice si JOIN document d ON d.id = si.document_id '
                . "WHERE si.credits_document_id = ? AND si.kind = 'credit_note' AND d.status IN ('draft', 'awaiting_approval', 'posted', 'reversed') ORDER BY d.id", [$id]) as $c) {
                $r['credit_notes'][] = $c;
                if ($c['status'] === 'posted' && $c['gross_total'] !== null) {
                    $r['credited_e2'] += InvoiceMath::e2((string) $c['gross_total']);
                }
            }
        }
        $r['owed_e2'] = $r['gross_total'] === null ? null : InvoiceMath::e2((string) $r['gross_total']) - $r['credited_e2'];
        return $r;
    }

    /**
     * A record's lines as its page shows them: the document line, its kind, the item, packs and pack price, the delivery and order it
     * bills, the invoice line it credits, the amount.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(int $id): array
    {
        $doc = $this->db->one('SELECT reverses_id FROM document WHERE id = ?', [$id]);
        $src = $doc !== null && $doc['reverses_id'] !== null ? (int) $doc['reverses_id'] : $id;
        $rows = $this->db->all(
            'SELECT dl.line_no, dl.sku_id, dl.qty, dl.unit_cost, dl.amount, dl.description, sl.kind, sl.grn_document_id, sl.grn_line_no, sl.po_document_id, sl.po_line_no, '
            . 'sl.credit_of_line_no, sl.supplier_code, sl.purchase_unit, sl.units_per_pack, sl.packs, sl.pack_price, sl.entry, sl.stamp_answer, s.code, s.name, '
            . 'g.number AS grn_number, p.number AS po_number FROM document_line dl LEFT JOIN sinv_line sl ON sl.document_id = ? AND sl.line_no = dl.line_no '
            . 'LEFT JOIN sku s ON s.id = dl.sku_id LEFT JOIN document g ON g.id = sl.grn_document_id LEFT JOIN document p ON p.id = sl.po_document_id '
            . 'WHERE dl.document_id = ? ORDER BY dl.line_no',
            [$src, $id],
        );
        return array_map(static function (array $r): array {
            foreach (['line_no', 'sku_id', 'qty', 'grn_document_id', 'grn_line_no', 'po_document_id', 'po_line_no', 'credit_of_line_no', 'units_per_pack', 'packs'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            $r['kind'] ??= 'other';
            return $r;
        }, $rows);
    }

    /**
     * The match of an invoice as the page shows it (InvoiceMatch::compute): its state now, and per goods line the figures.
     *
     * @return array<string, mixed>
     */
    public function matchOf(int $id): array
    {
        return $this->match->compute($id);
    }

    /**
     * What stops a draft from being posted (SupplierInvoiceHandler::problems), for its page.
     *
     * @return list<array{code: string, message: string, line?: int}>
     */
    public function problems(int $id): array
    {
        $doc = $this->docs->get($id);
        $h = SupplierInvoiceHandler::header($this->db, $id);
        if ($h === null || $doc->status !== 'draft') {
            return [];
        }
        $today = ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        return SupplierInvoiceHandler::problems($this->db, $doc, $h, $this->docs->lines($id), $today);
    }

    /**
     * The value-only events a posted record recorded (for the value engine, PC8).
     *
     * @return list<array<string, mixed>>
     */
    public function valueEvents(int $id): array
    {
        return $this->db->all('SELECT e.line_no, e.kind, e.units, e.amount, e.basis, e.effective_on, s.code, g.number AS grn_number FROM invoice_value_event e '
            . 'LEFT JOIN sku s ON s.id = e.sku_id LEFT JOIN document g ON g.id = e.grn_document_id WHERE e.document_id = ? ORDER BY e.id', [$id]);
    }

    /**
     * The list of a kind (newest first, at most LIST_LIMIT): number, supplier, the supplier's number, date, totals, status, match,
     * credit note's invoice. Filters: q (our number, theirs, the supplier's name or code), state, supplier id, match state.
     *
     * @param array{q?: string, state?: ?string, supplier?: ?int, match?: ?string, cancelled?: bool} $f
     * @return list<array<string, mixed>>
     */
    public function list(string $kind, array $f): array
    {
        $type = self::KINDS[$kind] ?? throw new \InvalidArgumentException("unknown kind {$kind}");
        $where = ['d.doc_type = ?'];
        $params = [$type];
        $state = $f['state'] ?? null;
        if ($state !== null && in_array($state, self::STATES, true)) {
            $where[] = 'd.status = ?';
            $params[] = $state;
        } elseif (!($f['cancelled'] ?? false)) {
            $where[] = "d.status <> 'cancelled'";
        }
        if (($f['supplier'] ?? null) !== null) {
            $where[] = 'si.supplier_id = ?';
            $params[] = (int) $f['supplier'];
        }
        if (($f['match'] ?? null) !== null && in_array($f['match'], InvoiceMath::STATES, true)) {
            $where[] = 'si.match_state = ?';
            $params[] = $f['match'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(d.number LIKE ? OR d.external_ref LIKE ? OR s.name LIKE ? OR s.code LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        return $this->db->all(
            'SELECT d.id, d.number, d.status, d.doc_date, d.created_at, d.posted_at, d.reverses_id, d.external_ref, si.supplier_id, s.name AS supplier_name, '
            . 'si.net_total, si.vat_total, si.gross_total, si.match_state, si.match_lines_off, si.match_difference, ci.number AS credits_number, '
            . 'cb.display_name AS created_by_name, od.number AS reverses_number, '
            . "(SELECT t.kind FROM review_task t WHERE t.subject_type = 'document' AND t.subject_id = d.id AND t.state = 'open' ORDER BY t.id LIMIT 1) AS open_task "
            . 'FROM document d LEFT JOIN supplier_invoice si ON si.document_id = COALESCE(d.reverses_id, d.id) LEFT JOIN supplier s ON s.id = si.supplier_id '
            . 'LEFT JOIN document ci ON ci.id = si.credits_document_id LEFT JOIN staff_user cb ON cb.id = d.created_by LEFT JOIN document od ON od.id = d.reverses_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . self::LIST_LIMIT,
            $params,
        );
    }

    /**
     * The final returns (debit notes) that claim against each invoice and are not cancelled (supplier_return.invoice_document_id: a
     * claim of what did not come, pack J, or a return started from the invoice): invoice id => its claims, oldest first, each with
     * how much the supplier's final credit notes answered of it (SupplierReturns::creditState: open, part, credited; a cancelled
     * credit note answers nothing).
     *
     * @param list<int> $invoiceIds
     * @return array<int, list<array{id: int, number: string, claim_total: string, credit_state: ?string}>>
     */
    public function claimsOn(array $invoiceIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT r.invoice_document_id AS invoice_id, d.id, d.number, r.claim_total, (SELECT COALESCE(SUM(c.net_total), 0) '
            . "FROM supplier_invoice c JOIN document cd ON cd.id = c.document_id WHERE c.debit_note_document_id = d.id AND c.kind = 'credit_note' "
            . "AND cd.status = 'posted') AS credited FROM supplier_return r JOIN document d ON d.id = r.document_id "
            . "WHERE r.invoice_document_id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ") AND d.status = 'posted' AND d.reverses_id IS NULL ORDER BY d.id", $ids) as $r) {
            $out[(int) $r['invoice_id']][] = ['id' => (int) $r['id'], 'number' => (string) $r['number'], 'claim_total' => (string) $r['claim_total'],
                'credit_state' => SupplierReturns::creditState($r['claim_total'] === null ? null : InvoiceMath::e2((string) $r['claim_total']),
                    InvoiceMath::e2((string) $r['credited']))];
        }
        return $out;
    }

    /**
     * The "To match" list: posted invoices whose match needs review (or, $show 'accepted', whose differences a person accepted), oldest
     * first (they wait longest), at most LIST_LIMIT. One read on (match_state, document_id).
     *
     * @return list<array<string, mixed>>
     */
    public function toMatch(string $show = 'needs_review', ?int $supplier = null): array
    {
        $show = in_array($show, ['needs_review', 'accepted'], true) ? $show : 'needs_review';
        return $this->db->all(
            'SELECT d.id, d.number, d.external_ref, d.doc_date, d.posted_at, si.supplier_id, s.name AS supplier_name, si.gross_total, si.match_state, si.match_lines_off, '
            . 'si.match_difference, si.match_checked_at, si.accepted_at, si.accept_note, ab.display_name AS accepted_by_name, pb.display_name AS posted_by_name '
            . 'FROM supplier_invoice si JOIN document d ON d.id = si.document_id JOIN supplier s ON s.id = si.supplier_id '
            . 'LEFT JOIN staff_user ab ON ab.id = si.accepted_by LEFT JOIN staff_user pb ON pb.id = d.posted_by '
            . "WHERE si.match_state = ? AND d.status = 'posted'" . ($supplier === null ? '' : ' AND si.supplier_id = ?') . ' ORDER BY si.document_id LIMIT ' . self::LIST_LIMIT,
            $supplier === null ? [$show] : [$show, $supplier],
        );
    }

    /** How many posted invoices need review now (the badge, for people who act on them). */
    public function toMatchCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM supplier_invoice si JOIN document d ON d.id = si.document_id AND d.status = 'posted' WHERE si.match_state = 'needs_review'");
    }

    /**
     * The deliveries not invoiced yet (GRNI, plan IM7): every posted delivery with units still to invoice (accepted - returned beyond the
     * line's exceptions - billed by posted invoices net of their credit notes), with those units and their worth at the delivery's
     * provisional cost, newest first (at most LIST_LIMIT). $supplier narrows it to one supplier (the invoice editor's "add a delivery").
     *
     * One indexed read (docs/decisions.md PC18): a delivery an invoice, credit note or return has named has its figures stored in
     * grn_invoice_left (InvoiceMatch::storeReceipts, by those postings; V7 checks them); one none has named has no row, and everything
     * it accepted is left (added up for the deliveries on the page only).
     *
     * @return list<array{id: int, number: string, external_ref: ?string, doc_date: ?string, posted_at: ?string, supplier_id: int, supplier_name: string, invoice_date: ?string, units_left: int, value_left: string}>
     */
    public function notInvoiced(?int $supplier = null, string $q = ''): array
    {
        $where = ["d.doc_type = 'GRN'", "d.status = 'posted'", '(gl.units_left > 0 OR (gl.grn_document_id IS NULL AND EXISTS (SELECT 1 FROM grn_line g '
            . 'WHERE g.document_id = d.id AND g.accepted_units > 0)))'];
        $params = [];
        if ($supplier !== null) {
            $where[] = 'gr.supplier_id = ?';
            $params[] = $supplier;
        }
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(d.number LIKE ? OR d.external_ref LIKE ? OR s.name LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $rows = $this->db->all(
            'SELECT d.id, d.number, d.external_ref, d.doc_date, d.posted_at, gr.supplier_id, s.name AS supplier_name, gr.invoice_date, gl.units_left, gl.value_left '
            . 'FROM document d JOIN goods_receipt gr ON gr.document_id = d.id JOIN supplier s ON s.id = gr.supplier_id '
            . 'LEFT JOIN grn_invoice_left gl ON gl.grn_document_id = d.id WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . self::LIST_LIMIT,
            $params,
        );
        $fresh = [];
        foreach ($rows as $r) {
            if ($r['units_left'] === null) {
                $fresh[] = (int) $r['id'];
            }
        }
        $all = [];
        foreach (array_chunk($fresh, 500) as $chunk) {
            foreach ($this->db->all('SELECT g.document_id, SUM(g.accepted_units) AS units, SUM(ROUND(g.accepted_units * dl.unit_cost, 2)) AS value FROM grn_line g '
                . 'JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no WHERE g.document_id IN (' . implode(', ', array_fill(0, count($chunk), '?'))
                . ') GROUP BY g.document_id', $chunk) as $a) {
                $all[(int) $a['document_id']] = [(int) $a['units'], InvoiceMath::fromE2(InvoiceMath::e2((string) ($a['value'] ?? '0')))];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            [$units, $value] = $r['units_left'] === null ? ($all[$id] ?? [0, '0.00']) : [(int) $r['units_left'], InvoiceMath::fromE2(InvoiceMath::e2((string) $r['value_left']))];
            $out[] = ['id' => $id, 'number' => (string) $r['number'], 'external_ref' => $r['external_ref'], 'doc_date' => $r['doc_date'], 'posted_at' => $r['posted_at'],
                'supplier_id' => (int) $r['supplier_id'], 'supplier_name' => (string) $r['supplier_name'], 'invoice_date' => $r['invoice_date'], 'units_left' => $units,
                'value_left' => $value];
        }
        return $out;
    }

    /**
     * The posted orders of a supplier an invoice may bill before its goods (approved, sent, part-received, received), newest first.
     *
     * @return list<array{id: int, number: string}>
     */
    public function orderChoices(int $supplierId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'number' => (string) $r['number']], $this->db->all(
            "SELECT d.id, d.number FROM purchase_order p JOIN document d ON d.id = p.document_id AND d.status = 'posted' WHERE p.supplier_id = ? "
            . "AND p.state IN ('approved', 'sent', 'part_received', 'received') ORDER BY d.id DESC LIMIT 50", [$supplierId]));
    }

    /**
     * The posted invoices of a supplier a credit note may reduce, newest first.
     *
     * @return list<array{id: int, number: string, external_ref: ?string}>
     */
    public function invoiceChoices(int $supplierId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'number' => (string) $r['number'], 'external_ref' => $r['external_ref']], $this->db->all(
            "SELECT d.id, d.number, d.external_ref FROM supplier_invoice si JOIN document d ON d.id = si.document_id AND d.status = 'posted' WHERE si.supplier_id = ? "
            . "AND si.kind = 'invoice' ORDER BY d.id DESC LIMIT 100", [$supplierId]));
    }

    /** Units of line $line of invoice $invoiceId credited by posted credit notes other than $except. */
    public function creditedUnits(?int $invoiceId, int $line, int $except = 0): int
    {
        if ($invoiceId === null) {
            return 0;
        }
        return (int) $this->db->value(
            "SELECT COALESCE(SUM(dl.qty), 0) FROM supplier_invoice c JOIN document d ON d.id = c.document_id AND d.status = 'posted' "
            . "JOIN sinv_line sl ON sl.document_id = c.document_id AND sl.kind = 'goods' AND sl.credit_of_line_no = ? "
            . 'JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no '
            . "WHERE c.credits_document_id = ? AND c.kind = 'credit_note' AND c.document_id <> ?", [$line, $invoiceId, $except]);
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * A draft's kind and header (document + supplier_invoice), the document row locked first (Documents locks it again in the same
     * transaction). 404 for a document that is not a supplier invoice or credit note.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function current(int $id): array
    {
        $r = $this->db->one('SELECT d.doc_type, d.status, d.external_ref, d.doc_date, d.note, si.supplier_id, si.net_total, si.vat_total, si.gross_total, '
            . 'si.credits_document_id, si.debit_note_document_id FROM document d JOIN supplier_invoice si ON si.document_id = d.id WHERE d.id = ? FOR UPDATE OF d', [$id])
            ?? throw new CwException('unknown_document', 'there is no such supplier invoice', 404);
        $kind = self::kindOf((string) $r['doc_type']) ?? throw new CwException('not_an_invoice', 'this is not a supplier invoice or credit note', 404);
        return [$kind, $r];
    }

    /**
     * A header as the record will hold it: the given keys over the current values, each checked.
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $cur
     * @return array{invoice_number: ?string, invoice_date: ?string, net_total: ?string, vat_total: ?string, gross_total: ?string, note: ?string, credits_id: ?int, debit_note_id: ?int}
     */
    private function header(string $kind, int $supplierId, array $in, ?array $cur): array
    {
        foreach (array_keys($in) as $k) {
            if (!in_array($k, self::HEADER_FIELDS, true)) {
                throw new CwException('bad_field', 'an invoice header has ' . implode(', ', self::HEADER_FIELDS) . ', not ' . mb_substr((string) $k, 0, 40), 400);
            }
        }
        $h = [
            'invoice_number' => $cur['external_ref'] ?? null, 'invoice_date' => $cur['doc_date'] ?? null, 'note' => $cur['note'] ?? null,
            'net_total' => $cur['net_total'] ?? null, 'vat_total' => $cur['vat_total'] ?? null,
            'credits_id' => isset($cur['credits_document_id']) ? (int) $cur['credits_document_id'] : null,
            'debit_note_id' => isset($cur['debit_note_document_id']) ? (int) $cur['debit_note_document_id'] : null,
        ];
        if (array_key_exists('invoice_number', $in)) {
            $h['invoice_number'] = self::text($in['invoice_number'], self::NUMBER_MAX, 'invoice_number');
        }
        if (array_key_exists('invoice_date', $in)) {
            $d = $in['invoice_date'];
            if ($d === null || $d === '') {
                $h['invoice_date'] = null;
            } elseif (!is_string($d) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) !== 1 || \DateTimeImmutable::createFromFormat('!Y-m-d', $d)?->format('Y-m-d') !== $d) {
                throw new CwException('bad_field', 'the date on the invoice is a date (YYYY-MM-DD)', 422, ['field' => 'invoice_date']);
            } else {
                $h['invoice_date'] = $d;
            }
        }
        if (array_key_exists('note', $in)) {
            $h['note'] = self::text($in['note'], self::NOTE_MAX, 'note');
        }
        foreach (['net_total', 'vat_total'] as $k) {
            if (array_key_exists($k, $in)) {
                $h[$k] = $in[$k] === null || (is_string($in[$k]) && trim($in[$k]) === '') ? null : self::money($in[$k], $k, false);
            }
        }
        $h['gross_total'] = $h['net_total'] !== null && $h['vat_total'] !== null ? InvoiceMath::fromE2(InvoiceMath::e2($h['net_total']) + InvoiceMath::e2($h['vat_total'])) : null;
        if (array_key_exists('gross_total', $in) && $in['gross_total'] !== null && !(is_string($in['gross_total']) && trim($in['gross_total']) === '')) {
            $typed = self::money($in['gross_total'], 'gross_total', false);
            if ($h['gross_total'] === null || $typed !== $h['gross_total']) {
                throw new CwException('gross_mismatch', $h['gross_total'] === null ? 'type the total without VAT and the VAT: the total to pay is the two added up'
                    : 'the total without VAT and the VAT add up to £' . $h['gross_total'] . ", not £{$typed}: check the three figures on the paper", 422, ['field' => 'gross_total']);
            }
        }
        foreach (['credits_id' => 'credits_id', 'debit_note_id' => 'debit_note_id'] as $k => $field) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = $in[$k];
            if ($v === null || $v === '' || $v === 0 || $v === '0') {
                $h[$k] = null;
                continue;
            }
            if ($kind !== 'credit_note') {
                throw new CwException('bad_field', 'only a credit note names an invoice or a return', 400, ['field' => $field]);
            }
            if (!(is_int($v) && $v > 0) && !(is_string($v) && preg_match('/^[1-9][0-9]{0,18}$/D', $v) === 1)) {
                throw new CwException('bad_field', "{$field} is a record of the list", 400, ['field' => $field]);
            }
            $id = (int) $v;
            if ($k === 'credits_id') {
                $ok = $this->db->value("SELECT 1 FROM supplier_invoice si JOIN document d ON d.id = si.document_id AND d.status = 'posted' WHERE si.document_id = ? "
                    . "AND si.kind = 'invoice' AND si.supplier_id = ?", [$id, $supplierId]);
                if ($ok === null) {
                    throw new CwException('credited_invoice_not_posted', 'the invoice a credit note reduces is a posted invoice of the same supplier', 422, ['field' => $field]);
                }
            } else {
                $ok = $this->db->value("SELECT 1 FROM supplier_return r JOIN document d ON d.id = r.document_id AND d.status = 'posted' WHERE r.document_id = ? AND r.supplier_id = ?",
                    [$id, $supplierId]);
                if ($ok === null) {
                    throw new CwException('debit_note_not_posted', 'the return a credit note answers is a posted return to the same supplier', 422, ['field' => $field]);
                }
            }
            $h[$k] = $id;
        }
        return $h;
    }

    /**
     * Lines as given, checked, as [doc => Documents' line, ext => the sinv_line columns].
     *
     * @param array<string, mixed> $cur
     * @return list<array{doc: array<string, mixed>, ext: array<string, mixed>}>
     */
    private function normaliseLines(string $kind, array $cur, mixed $lines): array
    {
        if (!is_array($lines) || !array_is_list($lines) || count($lines) > self::MAX_LINES) {
            throw new CwException('too_many_lines', 'an invoice has at most ' . self::MAX_LINES . ' lines', 422);
        }
        $supplier = (int) $cur['supplier_id'];
        $out = [];
        foreach ($lines as $i => $l) {
            $no = $i + 1;
            if (!is_array($l)) {
                throw new CwException('bad_line', "line {$no} must be an object", 400, ['line' => $no]);
            }
            foreach (array_keys($l) as $k) {
                if (!in_array($k, self::LINE_FIELDS, true)) {
                    throw new CwException('bad_line', "line {$no}: a line has " . implode(', ', self::LINE_FIELDS) . ', not ' . mb_substr((string) $k, 0, 40), 400, ['line' => $no]);
                }
            }
            $lk = (string) ($l['kind'] ?? 'goods');
            if (!in_array($lk, SupplierInvoiceHandler::LINE_KINDS, true)) {
                throw new CwException('bad_line', "line {$no}: the kind is goods, freight, duty, service or other", 422, ['line' => $no]);
            }
            $desc = self::text($l['description'] ?? null, self::DESCRIPTION_MAX, "lines[{$no}].description");
            $entry = (string) ($l['entry'] ?? 'manual');
            if (!in_array($entry, ['receipt', 'order', 'manual', 'credit'], true)) {
                $entry = 'manual';
            }
            if ($lk !== 'goods') {
                $amount = self::money($l['amount'] ?? null, "lines[{$no}].amount", $lk === 'other');
                if ($desc === null) {
                    $desc = ucfirst($lk);
                }
                $out[] = ['doc' => ['amount' => $amount, 'description' => $desc], 'ext' => ['kind' => $lk, 'grn_document_id' => null, 'grn_line_no' => null,
                    'po_document_id' => null, 'po_line_no' => null, 'credit_of_line_no' => null, 'supplier_code' => null, 'purchase_unit' => 'each', 'units_per_pack' => 1,
                    'packs' => null, 'pack_price' => null, 'entry' => $entry]];
                continue;
            }
            $sku = self::posInt($l['sku_id'] ?? null, "line {$no}: the item");
            $packs = self::posInt($l['packs'] ?? null, "line {$no}: packs", PoMath::MAX_PACKS);
            $upp = self::posInt($l['units_per_pack'] ?? 1, "line {$no}: units per pack", PoMath::MAX_UNITS_PER_PACK);
            $price = self::packPrice((string) ($l['pack_price'] ?? ''), "lines[{$no}].pack_price");
            $grn = isset($l['grn_document_id']) && $l['grn_document_id'] !== null ? self::posInt($l['grn_document_id'], "line {$no}: the delivery") : null;
            $grnLine = $grn === null ? null : self::posInt($l['grn_line_no'] ?? null, "line {$no}: the delivery line");
            $po = isset($l['po_document_id']) && $l['po_document_id'] !== null ? self::posInt($l['po_document_id'], "line {$no}: the order") : null;
            $poLine = $po === null ? null : self::posInt($l['po_line_no'] ?? null, "line {$no}: the order line");
            $creditOf = isset($l['credit_of_line_no']) && $l['credit_of_line_no'] !== null ? self::posInt($l['credit_of_line_no'], "line {$no}: the invoice line") : null;
            if ($creditOf !== null && $kind !== 'credit_note') {
                throw new CwException('bad_line', "line {$no}: only a credit note's line credits an invoice line", 422, ['line' => $no]);
            }
            if ($grn !== null) {
                $g = $this->db->one('SELECT gr.supplier_id, dl.sku_id FROM goods_receipt gr LEFT JOIN document_line dl ON dl.document_id = gr.document_id AND dl.line_no = ? '
                    . 'WHERE gr.document_id = ?', [$grnLine, $grn]);
                if ($g === null || (int) $g['supplier_id'] !== $supplier || $g['sku_id'] === null || (int) $g['sku_id'] !== $sku) {
                    throw new CwException('delivery_line_mismatch', "line {$no}: the delivery line is not this supplier's delivery of this item", 422, ['line' => $no]);
                }
            }
            if ($po !== null) {
                $o = $this->db->one('SELECT p.supplier_id, dl.sku_id FROM purchase_order p LEFT JOIN document_line dl ON dl.document_id = p.document_id AND dl.line_no = ? '
                    . 'WHERE p.document_id = ?', [$poLine, $po]);
                if ($o === null || (int) $o['supplier_id'] !== $supplier || $o['sku_id'] === null || (int) $o['sku_id'] !== $sku) {
                    throw new CwException('order_line_mismatch', "line {$no}: the order line is not this supplier's order of this item", 422, ['line' => $no]);
                }
            }
            try {
                $priceE4 = PoMath::e4($price);
                $amount = PoMath::fromE2(PoMath::lineAmountE2($packs, $priceE4));
                $unit = PoMath::fromE6(PoMath::unitCostE6($priceE4, $upp));
            } catch (\RangeException) {
                throw new CwException('bad_line', "line {$no}: the line is too large", 422, ['line' => $no]);
            }
            $out[] = ['doc' => ['sku_id' => $sku, 'qty' => $packs * $upp, 'unit_cost' => $unit, 'amount' => $amount, 'description' => $desc],
                'ext' => ['kind' => 'goods', 'grn_document_id' => $grn, 'grn_line_no' => $grnLine, 'po_document_id' => $po, 'po_line_no' => $poLine, 'credit_of_line_no' => $creditOf,
                    'supplier_code' => self::text($l['supplier_code'] ?? null, 64, "lines[{$no}].supplier_code"),
                    'purchase_unit' => self::text($l['purchase_unit'] ?? null, 32, "lines[{$no}].purchase_unit") ?? 'each', 'units_per_pack' => $upp, 'packs' => $packs,
                    'pack_price' => $price, 'entry' => $entry]];
        }
        return $out;
    }

    /**
     * The draft's lines in saveLines()'s shape (to append one).
     *
     * @return list<array<string, mixed>>
     */
    private function lineRows(int $id): array
    {
        $out = [];
        foreach ($this->db->all('SELECT dl.sku_id, dl.amount, dl.description, sl.* FROM document_line dl JOIN sinv_line sl ON sl.document_id = dl.document_id '
            . 'AND sl.line_no = dl.line_no WHERE dl.document_id = ? ORDER BY dl.line_no', [$id]) as $r) {
            $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
            if ($r['kind'] === 'goods') {
                $out[] = ['kind' => 'goods', 'sku_id' => (int) $r['sku_id'], 'packs' => (int) $r['packs'], 'units_per_pack' => (int) $r['units_per_pack'],
                    'pack_price' => (string) $r['pack_price'], 'description' => $r['description'], 'grn_document_id' => $int($r['grn_document_id']),
                    'grn_line_no' => $int($r['grn_line_no']), 'po_document_id' => $int($r['po_document_id']), 'po_line_no' => $int($r['po_line_no']),
                    'credit_of_line_no' => $int($r['credit_of_line_no']), 'supplier_code' => $r['supplier_code'], 'purchase_unit' => $r['purchase_unit'], 'entry' => $r['entry']];
            } else {
                $out[] = ['kind' => (string) $r['kind'], 'amount' => InvoiceMath::fromE2(InvoiceMath::e2((string) $r['amount'])), 'description' => $r['description'],
                    'entry' => $r['entry']];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> a posted delivery's document + goods_receipt (422 delivery_not_posted otherwise) */
    private function postedReceipt(int $grnId): array
    {
        $g = $this->db->one('SELECT d.id, d.number, d.status, d.doc_type, d.external_ref, d.doc_date, gr.supplier_id, gr.po_document_id, gr.invoice_date '
            . 'FROM document d JOIN goods_receipt gr ON gr.document_id = d.id WHERE d.id = ?', [$grnId]);
        if ($g === null || $g['doc_type'] !== 'GRN' || $g['status'] !== 'posted') {
            throw new CwException('delivery_not_posted', 'only a posted delivery is invoiced', 422, ['field' => 'grn_id']);
        }
        return $g;
    }

    /** Attaches the delivery's supplier invoice copies to the new invoice (when this server has a file store). */
    private function copyFiles(Caller $caller, int $grnId, int $invoiceId): void
    {
        if ($this->files === null) {
            return;
        }
        $ids = $this->db->column("SELECT file_id FROM document_file WHERE document_id = ? AND role = 'supplier_invoice' ORDER BY attached_at, file_id", [$grnId]);
        if ($ids === []) {
            return;
        }
        try {
            $store = ($this->files)();
        } catch (CwException) {
            return; // no file store on this server: the desk attaches the copy by hand
        }
        foreach ($ids as $f) {
            $store->attach($caller, $invoiceId, (int) $f, 'supplier_invoice');
        }
    }

    /** A 409 duplicate_invoice naming the earlier record for a duplicate key on uq_supplier_invoice_key (other PDOExceptions are rethrown). */
    private function duplicate(\PDOException $e, string $kind, int $supplierId, string $key, int $self): \Throwable
    {
        if (Db::driverCode($e) !== 1062 || $key === '') {
            return $e;
        }
        $other = $this->db->one('SELECT d.id, d.number, d.status, u.display_name FROM supplier_invoice si JOIN document d ON d.id = si.document_id '
            . 'LEFT JOIN staff_user u ON u.id = d.created_by WHERE si.supplier_id = ? AND si.kind = ? AND si.invoice_key = ? AND si.document_id <> ?', [$supplierId, $kind, $key, $self]);
        $what = $kind === 'invoice' ? 'invoice' : 'credit note';
        $label = $other === null ? 'another record' : ($other['number'] ?? (str_replace('_', ' ', (string) $other['status']) . " {$what} #" . $other['id']
            . ($other['display_name'] !== null ? ' (' . $other['display_name'] . ')' : '')));
        return new CwException('duplicate_invoice', "this supplier's {$what} {$key} is already booked as {$label}: a supplier's {$what} is booked once "
            . '(cancel the other one first if it was keyed by mistake)', 409, ['other' => $other === null ? null : (int) $other['id'],
                'other_label' => $label, 'key' => $key]);
    }

    private static function mayPost(Db $db, Caller $caller, string $type): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'supplier invoices are kept by staff', 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (!Documents::mayPost($roles, $type)) {
            throw new CwException(in_array('admin', $roles, true) ? 'admin_cannot_post' : 'role_not_allowed', "you cannot keep {$type} records", 403);
        }
    }

    /** The invoice key of a number (upper case, no spaces: ReceiptMath), or null for none. */
    public static function key(?string $number): ?string
    {
        $k = ReceiptMath::invoiceKey($number);
        return $k === '' ? null : mb_substr($k, 0, 64);
    }

    /**
     * An amount as typed (£, spaces and thousands commas ignored), at most 2 decimals, as a 2-decimal string; negative only when
     * $signed. 422 bad_amount.
     */
    public static function money(mixed $v, string $field, bool $signed): string
    {
        $s = is_int($v) ? (string) $v : (is_string($v) ? str_replace([',', '£', ' ', "\u{00A0}"], '', trim($v)) : '');
        if (preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/D', $s, $m) !== 1 || ($m[1] === '-' && !$signed)) {
            throw new CwException('bad_amount', "{$field}: an amount in pounds with at most 2 decimals" . ($signed ? '' : ', not below 0'), 422, ['field' => $field]);
        }
        $out = ltrim($m[2], '0');
        $out = ($out === '' ? '0' : $out) . '.' . str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' && $out !== '0.00' ? '-' . $out : $out;
    }

    /** A pack price as typed: 4 decimals at most, not below 0 (SupplierItems' rule), as a 4-decimal string. 422 bad_price. */
    public static function packPrice(string $raw, string $field): string
    {
        return \CW\Suppliers\SupplierItems::packPrice($raw, $field);
    }

    /** A 6-decimal unit cost rounded half-up to a 4-decimal pack price (a pack of 1). */
    public static function price4(string $unitCost): string
    {
        return PoMath::fromE4(intdiv(PoMath::e6($unitCost) + 50, 100));
    }

    private static function posInt(mixed $v, string $what, int $max = 2_000_000_000): int
    {
        if (is_string($v) && preg_match('/^[0-9]{1,10}$/D', trim($v)) === 1) {
            $v = (int) trim($v);
        }
        if (!is_int($v) || $v < 1 || $v > $max) {
            throw new CwException('bad_line', "{$what} is a whole number from 1 to " . number_format($max), 422);
        }
        return $v;
    }

    /** A trimmed text of at most $max characters without control characters, or null (absent or blank). 400 bad_field. */
    private static function text(mixed $v, int $max, string $field): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1) {
            throw new CwException('bad_field', "{$field} must be text", 400, ['field' => $field]);
        }
        $v = trim($v);
        if (mb_strlen($v) > $max) {
            throw new CwException('bad_field', "{$field} is longer than {$max} characters", 422, ['field' => $field]);
        }
        return $v === '' ? null : $v;
    }

    private function nowDb(): string
    {
        return Clock::db(($this->clock)());
    }
}
