<?php

declare(strict_types=1);

namespace CW\Invoices;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Movements;
use CW\Staff\StaffRoles;
use CW\StockOps\CostHints;
use CW\StockOps\StockOps;

/**
 * Returns to the supplier (pack C, plan IM7 "supplier return as a simple negative receipt with a reason, followed by the credit note";
 * docs/decisions.md PC13-PC16), on top of the document base and their handler (SupplierReturnHandler, which books the stock out).
 *
 * A return is a `document` (DN: the supplier's return or collection reference as external_ref, the day it went as doc_date, the
 * warehouse its units leave by default, the reason) with a `supplier_return` header (the supplier, the delivery and the invoice it is
 * about) and lines with a `dn_line`: an item, its units, the warehouse they leave (MAIN, VERIFY, UNSTAMPED, ...: our own stock), the
 * price per unit claimed back and the duty claimed with it; or a claim without goods (an amount and what it is for). Posted, the return
 * IS our debit note: what it claims is its lines' amounts. The supplier's credit note answers it (SupplierInvoices::createFromReturn).
 *
 * The quick way: createFromReceipt() starts the return of a delivery's exceptions: every unit the delivery put in VERIFY (damaged, wrong
 * item, over) or UNSTAMPED (quarantined) that has not gone back yet, from where it is, at the delivery's price, with the duty paid on it
 * when the supplier's invoice of that delivery charged duty on its own line.
 *
 * Every write: a staff caller allowed to post DN (doc.DN.post, never admin), ONE transaction, the document row locked and its version
 * checked by Documents, a draft changed only by its creator.
 */
final class SupplierReturns
{
    public const PATH = '/ui/purchasing/returns';
    public const LINE_FIELDS = ['sku_id', 'qty', 'warehouse_id', 'unit_cost', 'duty_amount', 'amount', 'reason_code', 'description', 'grn_document_id', 'grn_line_no'];
    public const HEADER_FIELDS = ['warehouse_id', 'reason_code', 'return_ref', 'return_date', 'note', 'grn_id', 'invoice_id'];
    public const MAX_LINES = 500;
    public const LIST_LIMIT = 200;
    public const STATES = ['draft', 'awaiting_approval', 'posted', 'reversed', 'cancelled'];
    public const FILE_ROLES = ['delivery_note' => 'delivery_note', 'photo' => 'photo', 'evidence' => 'other'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): FileStore)|null $files
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(private readonly Db $db, private readonly Documents $docs, private readonly ?\Closure $files = null, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * A new draft return to $supplierId. Header (optional): warehouse_id (default MAIN), reason_code, return_ref (the supplier's return
     * or collection reference), return_date (default today, UK), note, grn_id (the delivery it is about), invoice_id (the invoice).
     *
     * @param array<string, mixed> $header
     */
    public function createDraft(Caller $caller, int $supplierId, array $header = []): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $supplierId, $header): Document {
            self::mayPost($db, $caller);
            $s = $db->one('SELECT id, code FROM supplier WHERE id = ? FOR SHARE', [$supplierId])
                ?? throw new CwException('unknown_supplier', 'there is no such supplier', 404, ['field' => 'supplier_id']);
            $h = $this->header($supplierId, $header, null);
            $doc = $this->docs->createDraft($caller, 'DN', $this->docHeader($h));
            $db->exec('INSERT INTO supplier_return (document_id, supplier_id, grn_document_id, invoice_document_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$doc->id, $supplierId, $h['grn_id'], $h['invoice_id'], $this->nowDb(), $this->nowDb()]);
            Audit::write($db, $caller, 'return.create', 'document', (string) $doc->id, null, ['supplier' => (string) $s['code']]
                + array_filter(['delivery' => $h['grn_id'], 'invoice' => $h['invoice_id']]));
            return $doc;
        });
    }

    /**
     * The quick way: a return of a posted delivery's exceptions (its VERIFY and UNSTAMPED units not gone back yet), from where they are,
     * at the delivery's price, with the duty paid on them when the supplier's posted invoice of that delivery has a duty line. 422
     * nothing_to_return when the delivery has none.
     */
    public function createFromReceipt(Caller $caller, int $grnId): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $grnId): Document {
            self::mayPost($db, $caller);
            $g = $db->one('SELECT d.id, d.number, d.status, d.doc_type, gr.supplier_id FROM document d JOIN goods_receipt gr ON gr.document_id = d.id WHERE d.id = ?', [$grnId]);
            if ($g === null || $g['doc_type'] !== 'GRN' || $g['status'] !== 'posted') {
                throw new CwException('delivery_not_posted', 'only a posted delivery is returned from', 422, ['field' => 'grn_id']);
            }
            $invoice = $db->value("SELECT si.document_id FROM sinv_line sl JOIN supplier_invoice si ON si.document_id = sl.document_id AND si.kind = 'invoice' "
                . "JOIN document d ON d.id = sl.document_id AND d.status = 'posted' WHERE sl.grn_document_id = ? ORDER BY si.document_id LIMIT 1", [$grnId]);
            $dutyOnInvoice = $invoice !== null && $db->value("SELECT 1 FROM sinv_line WHERE document_id = ? AND kind = 'duty' LIMIT 1", [(int) $invoice]) !== null;
            $wh = [];
            foreach ($db->all("SELECT id, code FROM warehouse WHERE code IN ('VERIFY', 'UNSTAMPED')") as $w) {
                $wh[(string) $w['code']] = (int) $w['id'];
            }
            $lines = [];
            foreach ($db->all('SELECT g.line_no, g.packs, g.units_per_pack, g.verify_units, g.quarantine_units, g.expected_duty, dl.sku_id, dl.unit_cost FROM grn_line g '
                . 'JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no WHERE g.document_id = ? ORDER BY g.line_no', [$grnId]) as $l) {
                $paper = (int) $l['packs'] * (int) $l['units_per_pack'];
                foreach (InvoiceMatch::EXCEPTION_WAREHOUSES as $code => $column) {
                    // What went back from VERIFY (UNSTAMPED) already: accepted units sent back from elsewhere are not these (review
                    // finding 3).
                    $take = max(0, (int) $l[$column] - SupplierReturnHandler::returnedFrom($db, $grnId, (int) $l['line_no'], $column, null));
                    if ($take <= 0 || !isset($wh[$code])) {
                        continue;
                    }
                    $duty = '0.00';
                    if ($dutyOnInvoice && $l['expected_duty'] !== null && $paper > 0) {
                        $duty = InvoiceMath::fromE2(intdiv(InvoiceMath::e2((string) $l['expected_duty']) * $take, $paper));
                    }
                    $lines[] = ['sku_id' => (int) $l['sku_id'], 'qty' => $take, 'warehouse_id' => $wh[$code], 'unit_cost' => (string) $l['unit_cost'], 'duty_amount' => $duty,
                        'grn_document_id' => $grnId, 'grn_line_no' => (int) $l['line_no']];
                }
            }
            if ($lines === []) {
                throw new CwException('nothing_to_return', "{$g['number']} put nothing in VERIFY or UNSTAMPED that has not gone back already", 422, ['field' => 'grn_id']);
            }
            $doc = $this->createDraft($caller, (int) $g['supplier_id'], ['grn_id' => $grnId, 'invoice_id' => $invoice === null ? null : (int) $invoice]);
            return $this->saveLines($caller, $doc->id, $doc->version, $lines);
        });
    }

    /** @param array<string, mixed> $header */
    public function saveHeader(Caller $caller, int $id, int $version, array $header): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $header): Document {
            $cur = $this->current($id);
            self::mayPost($db, $caller);
            $h = $this->header((int) $cur['supplier_id'], $header, $cur);
            $doc = $this->docs->updateDraft($caller, $id, $version, $this->docHeader($h));
            $db->exec('UPDATE supplier_return SET grn_document_id = ?, invoice_document_id = ?, updated_at = ? WHERE document_id = ?',
                [$h['grn_id'], $h['invoice_id'], $this->nowDb(), $id]);
            Audit::write($db, $caller, 'return.header', 'document', (string) $id, null, ['version' => $doc->version]);
            $was = $cur['grn_document_id'] === null ? null : (int) $cur['grn_document_id'];
            if ($was !== $h['grn_id']) {
                // Another delivery (or none): the items going back come from its lines now (review finding 2).
                $lines = array_map(static fn (array $l): array => isset($l['sku_id']) ? ['grn_document_id' => null, 'grn_line_no' => null] + $l : $l, $this->lineRows($id));
                if ($lines !== []) {
                    $doc = $this->saveLines($caller, $id, $doc->version, $lines);
                }
            }
            return $doc;
        });
    }

    /**
     * Replaces every line of a draft: an item line (sku_id, qty, warehouse_id (default the return's), unit_cost: the price per unit
     * claimed, duty_amount: the duty claimed with it, reason_code (default the return's), description, grn_document_id + grn_line_no:
     * the delivery line it came with) or a claim without goods (amount, description, reason_code). The amount of an item line is units x
     * price, to the penny, plus the duty.
     *
     * @param list<array<string, mixed>> $lines
     */
    public function saveLines(Caller $caller, int $id, int $version, array $lines): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $lines): Document {
            $cur = $this->current($id);
            self::mayPost($db, $caller);
            $norm = $this->normaliseLines($cur, $lines);
            $codes = [];
            foreach ($db->all('SELECT id, code FROM warehouse') as $w) {
                $codes[(int) $w['id']] = (string) $w['code'];
            }
            $doc = $this->docs->setLines($caller, $id, $version, array_map(static function (array $n) use ($codes): array {
                $d = $n['doc'];
                if (isset($d['warehouse_id'])) {
                    $d['warehouse'] = $codes[$d['warehouse_id']] ?? '?';
                }
                unset($d['warehouse_id']);
                return $d;
            }, $norm));
            foreach ($norm as $i => $n) {
                $db->exec('INSERT INTO dn_line (document_id, line_no, grn_document_id, grn_line_no, duty_amount) VALUES (?, ?, ?, ?, ?)',
                    [$id, $i + 1, $n['ext']['grn_document_id'], $n['ext']['grn_line_no'], $n['ext']['duty_amount']]);
            }
            return $doc;
        });
    }

    /**
     * Adds an item by barcode, CW number or words (StockOps::resolve: `choices`, `not_found`): $qty units (a case barcode counts its
     * units), from $warehouseId (default the return's), at the price typed, else this supplier's last price of a unit, else the
     * average cost so far, else £0.
     *
     * @return array{status: string, document: Document, line_no?: int, choices?: list<array<string, mixed>>}
     */
    public function addItem(Caller $caller, int $id, int $version, string $q, int $qty, ?int $warehouseId, ?string $unitPrice, ?int $skuId = null): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $q, $qty, $warehouseId, $unitPrice, $skuId): array {
            $cur = $this->current($id);
            if ($qty < 1 || $qty > Documents::MAX_QTY) {
                throw new CwException('qty_positive', 'the units going back are a whole number above zero', 422, ['field' => 'qty']);
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
            $price = $unitPrice !== null && trim($unitPrice) !== '' ? Movements::normaliseCost(str_replace(['£', ','], '', trim($unitPrice)), 'unit_cost') : null;
            if ($price === null) {
                $si = $db->one('SELECT last_pack_price, units_per_pack FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND is_active = 1 AND last_pack_price IS NOT NULL '
                    . 'ORDER BY is_preferred DESC, id LIMIT 1', [(int) $cur['supplier_id'], $skuId]);
                $price = $si !== null ? \CW\PurchaseOrders\PoMath::unitCost((string) $si['last_pack_price'], (int) $si['units_per_pack'])
                    : ((new CostHints($db))->average([$skuId])[$skuId] ?? '0.000000');
                $price = CostHints::six((string) $price);
            }
            $lines = $this->lineRows($id);
            $lines[] = ['sku_id' => $skuId, 'qty' => $qty * $per, 'warehouse_id' => $warehouseId, 'unit_cost' => $price, 'duty_amount' => '0.00'];
            $doc = $this->saveLines($caller, $id, $version, $lines);
            return ['status' => 'added', 'document' => $doc, 'line_no' => count($lines)];
        });
    }

    /** Adds a claim without goods: an amount (GBP excl. VAT) and what it is for (a short delivery, an overcharge). */
    public function addClaim(Caller $caller, int $id, int $version, string $amount, string $description, ?string $reason): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version, $amount, $description, $reason): Document {
            $this->current($id);
            $lines = $this->lineRows($id);
            $lines[] = ['amount' => SupplierInvoices::money($amount, 'amount', false), 'description' => $description,
                'reason_code' => $reason === null || trim($reason) === '' ? null : trim($reason)];
            return $this->saveLines($caller, $id, $version, $lines);
        });
    }

    public function post(Caller $caller, int $id, int $version): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version): Document {
            $this->current($id);
            return $this->docs->post($caller, $id, $version);
        });
    }

    public function cancel(Caller $caller, int $id, int $version, string $reason): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version, $reason): Document {
            $this->current($id);
            return $this->docs->cancelDraft($caller, $id, $version, $reason);
        });
    }

    /** Cancels a posted return by its reversal: the stock comes back (a reviewer's OK first above the "stock put back" limit, I32). */
    public function reverse(Caller $caller, int $id, string $reasonCode, ?string $note): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $reasonCode, $note): Document {
            $this->isReturn($id) || throw new CwException('not_a_return', 'this is not a return to a supplier', 404);
            return $this->docs->reverse($caller, $id, $reasonCode, $note);
        });
    }

    public function withdraw(Caller $caller, int $id): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id): Document {
            $this->isReturn($id) || throw new CwException('not_a_return', 'this is not a return to a supplier', 404);
            $task = $db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = 'approval' AND state = 'open'", [$id])
                ?? throw new CwException('not_withdrawable', 'only a record waiting for a reviewer\'s OK is taken back', 409);
            return $this->docs->withdraw($caller, (int) $task);
        });
    }

    /** @return array{file_id: int, mime: string, attached: bool, deduped: bool} */
    public function attach(Caller $caller, int $id, string $path, string $name, string $role): array
    {
        if (!isset(self::FILE_ROLES[$role])) {
            throw new CwException('bad_file_role', 'attach the file as ' . implode(', ', array_keys(self::FILE_ROLES)), 400, ['field' => 'role']);
        }
        $files = $this->files === null ? throw new CwException('file_store_unconfigured', 'the file store is not set up on this server', 503) : ($this->files)();
        return $this->db->transaction(function (Db $db) use ($caller, $id, $path, $name, $role, $files): array {
            $row = $db->one('SELECT id, doc_type, status FROM document WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('unknown_document', 'there is no such record', 404);
            if ($row['doc_type'] !== 'DN') {
                throw new CwException('not_a_return', 'this is not a return to a supplier', 404);
            }
            self::mayPost($db, $caller);
            if ($row['status'] === 'cancelled') {
                throw new CwException('document_cancelled', 'a stopped record takes no files', 409);
            }
            $stored = $files->store($caller, $path, $name, self::FILE_ROLES[$role], null);
            $attached = $files->attach($caller, $id, $stored['id'], $role);
            return ['file_id' => $stored['id'], 'mime' => $stored['mime'], 'attached' => $attached, 'deduped' => $stored['deduped']];
        });
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    public function isReturn(int $id): bool
    {
        return $this->db->value('SELECT doc_type FROM document WHERE id = ?', [$id]) === 'DN';
    }

    /**
     * A return's page header: the document, the supplier, the delivery and invoice it is about, the claim, the credit notes that answer
     * it and what they credited, the people, the record it cancels and the one that cancels it.
     *
     * @return array<string, mixed>|null
     */
    public function record(int $id): ?array
    {
        $r = $this->db->one(
            'SELECT d.*, r.supplier_id, r.grn_document_id, r.invoice_document_id, r.claim_total, s.code AS supplier_code, s.name AS supplier_name, g.number AS grn_number, '
            . 'i.number AS invoice_number, w.name AS warehouse_name, rc.label AS reason_label, cb.display_name AS created_by_name, sb.display_name AS submitted_by_name, '
            . 'pb.display_name AS posted_by_name, xb.display_name AS cancelled_by_name, od.number AS reverses_number, rv.id AS reversed_by_id, rv.number AS reversed_by_number, '
            . 'rv.status AS reversed_by_status FROM document d LEFT JOIN supplier_return r ON r.document_id = COALESCE(d.reverses_id, d.id) LEFT JOIN supplier s ON s.id = r.supplier_id '
            . 'LEFT JOIN document g ON g.id = r.grn_document_id LEFT JOIN document i ON i.id = r.invoice_document_id LEFT JOIN warehouse w ON w.id = d.warehouse_id '
            . 'LEFT JOIN reason_code rc ON rc.code = d.reason_code LEFT JOIN staff_user cb ON cb.id = d.created_by LEFT JOIN staff_user sb ON sb.id = d.submitted_by '
            . 'LEFT JOIN staff_user pb ON pb.id = d.posted_by LEFT JOIN staff_user xb ON xb.id = d.cancelled_by LEFT JOIN document od ON od.id = d.reverses_id '
            . "LEFT JOIN document rv ON rv.reverses_id = d.id AND rv.status IN ('posted', 'awaiting_approval') WHERE d.id = ?",
            [$id],
        );
        if ($r === null || $r['doc_type'] !== 'DN') {
            return null;
        }
        $r['credit_notes'] = [];
        $r['credited_e2'] = 0;
        foreach ($this->db->all('SELECT d.id, d.number, d.status, d.external_ref, si.net_total FROM supplier_invoice si JOIN document d ON d.id = si.document_id '
            . "WHERE si.debit_note_document_id = ? AND si.kind = 'credit_note' AND d.status <> 'cancelled' ORDER BY d.id", [$r['reverses_id'] ?? $id]) as $c) {
            $r['credit_notes'][] = $c;
            if ($c['status'] === 'posted' && $c['net_total'] !== null) {
                $r['credited_e2'] += InvoiceMath::e2((string) $c['net_total']);
            }
        }
        $r['claim_e2'] = $r['claim_total'] === null ? null : InvoiceMath::e2((string) $r['claim_total']);
        $r['credit_state'] = self::creditState($r['claim_e2'], $r['credited_e2']);
        return $r;
    }

    /** 'open' (nothing credited), 'part' (less than the claim), 'credited' (the claim or more); null before posting. */
    public static function creditState(?int $claimE2, int $creditedE2): ?string
    {
        if ($claimE2 === null) {
            return null;
        }
        return $creditedE2 <= 0 ? 'open' : ($creditedE2 < $claimE2 ? 'part' : 'credited');
    }

    /**
     * A return's lines as its page shows them.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(int $id): array
    {
        $doc = $this->db->one('SELECT reverses_id FROM document WHERE id = ?', [$id]);
        $src = $doc !== null && $doc['reverses_id'] !== null ? (int) $doc['reverses_id'] : $id;
        return array_map(static function (array $r): array {
            foreach (['line_no', 'sku_id', 'qty', 'warehouse_id', 'grn_document_id', 'grn_line_no'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            return $r;
        }, $this->db->all(
            'SELECT dl.line_no, dl.sku_id, dl.qty, dl.warehouse_id, dl.unit_cost, dl.amount, dl.reason_code, dl.description, n.grn_document_id, n.grn_line_no, n.duty_amount, '
            . 's.code, s.name, w.name AS warehouse_name, rc.label AS reason_label, g.number AS grn_number FROM document_line dl '
            . 'LEFT JOIN dn_line n ON n.document_id = ? AND n.line_no = dl.line_no LEFT JOIN sku s ON s.id = dl.sku_id LEFT JOIN warehouse w ON w.id = dl.warehouse_id '
            . 'LEFT JOIN reason_code rc ON rc.code = dl.reason_code LEFT JOIN document g ON g.id = n.grn_document_id WHERE dl.document_id = ? ORDER BY dl.line_no',
            [$src, $id],
        ));
    }

    /**
     * What stops a draft from being posted (SupplierReturnHandler::problems).
     *
     * @return list<array{code: string, message: string, line?: int}>
     */
    public function problems(int $id): array
    {
        $doc = $this->docs->get($id);
        $h = $this->db->one('SELECT * FROM supplier_return WHERE document_id = ?', [$id]);
        if ($h === null || $doc->status !== 'draft') {
            return [];
        }
        return SupplierReturnHandler::problems($this->db, $doc, $h, $this->docs->lines($id), true);
    }

    /**
     * The returns, newest first (at most LIST_LIMIT), with their claim and what credit notes credited. Filters: q, state, supplier.
     *
     * @param array{q?: string, state?: ?string, supplier?: ?int, cancelled?: bool} $f
     * @return list<array<string, mixed>>
     */
    public function list(array $f): array
    {
        $where = ["d.doc_type = 'DN'"];
        $params = [];
        $state = $f['state'] ?? null;
        if ($state !== null && in_array($state, self::STATES, true)) {
            $where[] = 'd.status = ?';
            $params[] = $state;
        } elseif (!($f['cancelled'] ?? false)) {
            $where[] = "d.status <> 'cancelled'";
        }
        if (($f['supplier'] ?? null) !== null) {
            $where[] = 'r.supplier_id = ?';
            $params[] = (int) $f['supplier'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(d.number LIKE ? OR d.external_ref LIKE ? OR s.name LIKE ? OR s.code LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        return $this->db->all(
            'SELECT d.id, d.number, d.status, d.doc_date, d.created_at, d.posted_at, d.reverses_id, d.external_ref, r.supplier_id, s.name AS supplier_name, r.claim_total, '
            . 'g.number AS grn_number, rc.label AS reason_label, cb.display_name AS created_by_name, od.number AS reverses_number, '
            . '(SELECT COALESCE(SUM(ABS(x.qty)), 0) FROM document_line x WHERE x.document_id = d.id) AS units, COALESCE(cr.credited, 0) AS credited, '
            . "(SELECT t.kind FROM review_task t WHERE t.subject_type = 'document' AND t.subject_id = d.id AND t.state = 'open' ORDER BY t.id LIMIT 1) AS open_task "
            . 'FROM document d LEFT JOIN supplier_return r ON r.document_id = COALESCE(d.reverses_id, d.id) LEFT JOIN supplier s ON s.id = r.supplier_id '
            // What the supplier's posted credit notes credited against each return: ONE grouped read of the credit notes that name a return
            // (a range on ix_supplier_invoice_debit_note). A subquery per return chose a full scan of supplier_invoice when most rows have no
            // return (every invoice): 2.2-2.9 s for 500 returns on a filled schema, this shape about 10 ms (docs/decisions.md PC18).
            . "LEFT JOIN (SELECT c.debit_note_document_id AS dn, SUM(c.net_total) AS credited FROM supplier_invoice c JOIN document cd ON cd.id = c.document_id "
            . "  AND cd.status = 'posted' WHERE c.debit_note_document_id IS NOT NULL AND c.kind = 'credit_note' GROUP BY c.debit_note_document_id) cr ON cr.dn = d.id "
            . 'LEFT JOIN document g ON g.id = r.grn_document_id LEFT JOIN reason_code rc ON rc.code = d.reason_code LEFT JOIN staff_user cb ON cb.id = d.created_by '
            . 'LEFT JOIN document od ON od.id = d.reverses_id WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . self::LIST_LIMIT,
            $params,
        );
    }

    /**
     * What a return's forms offer: our switched-on warehouses, the reasons offered for returns that take stock down, the supplier's posted
     * deliveries and invoices.
     *
     * @return array{warehouses: list<array{id: int, code: string, name: string}>, reasons: list<array{code: string, label: string, needs_note: bool}>}
     */
    public function formChoices(): array
    {
        $warehouses = array_map(static fn (array $w): array => ['id' => (int) $w['id'], 'code' => (string) $w['code'], 'name' => (string) $w['name']],
            $this->db->all("SELECT id, code, name FROM warehouse WHERE is_active = 1 AND stock_owner = 'own' ORDER BY sort_order, id"));
        $reasons = array_map(static fn (array $r): array => ['code' => (string) $r['code'], 'label' => (string) $r['label'], 'needs_note' => (int) $r['needs_note'] === 1],
            $this->db->all("SELECT code, label, needs_note FROM reason_code WHERE FIND_IN_SET('supplier_return', applies_to) > 0 AND is_active = 1 AND system_only = 0 "
                . "AND direction <> 'increase' ORDER BY sort_order, code"));
        return ['warehouses' => $warehouses, 'reasons' => $reasons];
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the draft's document + supplier_return row (the document row locked) */
    private function current(int $id): array
    {
        $r = $this->db->one('SELECT d.doc_type, d.warehouse_id, d.reason_code, d.external_ref, d.doc_date, d.note, r.supplier_id, r.grn_document_id, r.invoice_document_id '
            . 'FROM document d JOIN supplier_return r ON r.document_id = d.id WHERE d.id = ? FOR UPDATE OF d', [$id])
            ?? throw new CwException('unknown_document', 'there is no such return', 404);
        if ($r['doc_type'] !== 'DN') {
            throw new CwException('not_a_return', 'this is not a return to a supplier', 404);
        }
        return $r;
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $cur
     * @return array{warehouse_id: ?int, reason_code: ?string, return_ref: ?string, return_date: ?string, note: ?string, grn_id: ?int, invoice_id: ?int}
     */
    private function header(int $supplierId, array $in, ?array $cur): array
    {
        foreach (array_keys($in) as $k) {
            if (!in_array($k, self::HEADER_FIELDS, true)) {
                throw new CwException('bad_field', 'a return header has ' . implode(', ', self::HEADER_FIELDS) . ', not ' . mb_substr((string) $k, 0, 40), 400);
            }
        }
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $h = [
            'warehouse_id' => $cur === null ? (int) $this->db->value("SELECT id FROM warehouse WHERE code = 'MAIN'") : $int($cur['warehouse_id']),
            'reason_code' => $cur['reason_code'] ?? null, 'return_ref' => $cur['external_ref'] ?? null,
            'return_date' => $cur['doc_date'] ?? ($cur === null ? ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d') : null),
            'note' => $cur['note'] ?? null, 'grn_id' => $int($cur['grn_document_id'] ?? null), 'invoice_id' => $int($cur['invoice_document_id'] ?? null),
        ];
        $id = static function (mixed $v, string $field): ?int {
            if ($v === null || $v === '' || $v === 0 || $v === '0') {
                return null;
            }
            if ((is_int($v) && $v > 0) || (is_string($v) && preg_match('/^[1-9][0-9]{0,18}$/D', $v) === 1)) {
                return (int) $v;
            }
            throw new CwException('bad_field', "{$field} is one of the list", 400, ['field' => $field]);
        };
        if (array_key_exists('warehouse_id', $in)) {
            $h['warehouse_id'] = $id($in['warehouse_id'], 'warehouse_id');
        }
        if (array_key_exists('reason_code', $in)) {
            $h['reason_code'] = is_string($in['reason_code']) && trim($in['reason_code']) !== '' ? trim($in['reason_code']) : null;
        }
        foreach (['return_ref' => 100, 'note' => 1000] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = $in[$k];
                if ($v !== null && (!is_string($v) || mb_strlen(trim($v)) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1)) {
                    throw new CwException('bad_field', "{$k}: text of at most {$max} characters", 422, ['field' => $k]);
                }
                $h[$k] = $v === null || trim($v) === '' ? null : trim($v);
            }
        }
        if (array_key_exists('return_date', $in) && $in['return_date'] !== null && $in['return_date'] !== '') {
            $h['return_date'] = (string) $in['return_date'];
        }
        if (array_key_exists('grn_id', $in)) {
            $h['grn_id'] = $id($in['grn_id'], 'grn_id');
            if ($h['grn_id'] !== null && $this->db->value("SELECT 1 FROM goods_receipt gr JOIN document d ON d.id = gr.document_id AND d.status = 'posted' "
                . 'WHERE gr.document_id = ? AND gr.supplier_id = ?', [$h['grn_id'], $supplierId]) === null) {
                throw new CwException('delivery_not_posted', 'the delivery a return is about is a posted delivery of the same supplier', 422, ['field' => 'grn_id']);
            }
        }
        if (array_key_exists('invoice_id', $in)) {
            $h['invoice_id'] = $id($in['invoice_id'], 'invoice_id');
            if ($h['invoice_id'] !== null && $this->db->value("SELECT 1 FROM supplier_invoice si JOIN document d ON d.id = si.document_id AND d.status = 'posted' "
                . "WHERE si.document_id = ? AND si.supplier_id = ? AND si.kind = 'invoice'", [$h['invoice_id'], $supplierId]) === null) {
                throw new CwException('invoice_not_posted', 'the invoice a return is about is a posted invoice of the same supplier', 422, ['field' => 'invoice_id']);
            }
        }
        if ($h['warehouse_id'] !== null) {
            $w = $this->db->one('SELECT is_active, CAST(stock_owner AS CHAR) AS stock_owner FROM warehouse WHERE id = ?', [$h['warehouse_id']]);
            if ($w === null || (int) $w['is_active'] !== 1 || $w['stock_owner'] !== 'own') {
                throw new CwException('warehouse_not_ours', 'a return leaves one of our own warehouses that is switched on', 422, ['field' => 'warehouse_id']);
            }
        }
        return $h;
    }

    /** @param array<string, mixed> $h @return array<string, mixed> Documents' header */
    private function docHeader(array $h): array
    {
        $code = $h['warehouse_id'] === null ? null : $this->db->value('SELECT code FROM warehouse WHERE id = ?', [$h['warehouse_id']]);
        return ['warehouse' => $code === null ? null : (string) $code, 'reason_code' => $h['reason_code'], 'external_ref' => $h['return_ref'], 'doc_date' => $h['return_date'],
            'note' => $h['note']];
    }

    /**
     * @param array<string, mixed> $cur
     * @return list<array{doc: array<string, mixed>, ext: array<string, mixed>}>
     */
    private function normaliseLines(array $cur, mixed $lines): array
    {
        if (!is_array($lines) || !array_is_list($lines) || count($lines) > self::MAX_LINES) {
            throw new CwException('too_many_lines', 'a return has at most ' . self::MAX_LINES . ' lines', 422);
        }
        $out = [];
        $taken = [];
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
            $desc = $l['description'] ?? null;
            $desc = is_string($desc) && trim($desc) !== '' ? mb_substr(trim($desc), 0, 255) : null;
            $reason = isset($l['reason_code']) && is_string($l['reason_code']) && trim($l['reason_code']) !== '' ? trim($l['reason_code']) : null;
            if (($l['sku_id'] ?? null) === null) {
                $amount = SupplierInvoices::money($l['amount'] ?? null, "lines[{$no}].amount", false);
                if ($desc === null) {
                    throw new CwException('bad_return_line', "line {$no}: say what the claim is for", 422, ['line' => $no]);
                }
                $out[] = ['doc' => ['amount' => $amount, 'description' => $desc, 'reason_code' => $reason],
                    'ext' => ['grn_document_id' => null, 'grn_line_no' => null, 'duty_amount' => '0.00']];
                continue;
            }
            $sku = $l['sku_id'];
            if (is_string($sku) && preg_match('/^[1-9][0-9]{0,9}$/D', $sku) === 1) {
                $sku = (int) $sku;
            }
            $qty = $l['qty'] ?? null;
            if (is_string($qty) && preg_match('/^[0-9]{1,8}$/D', trim($qty)) === 1) {
                $qty = (int) trim($qty);
            }
            if (!is_int($sku) || $sku < 1 || !is_int($qty) || $qty < 1 || $qty > Documents::MAX_QTY) {
                throw new CwException('qty_positive', "line {$no}: an item and its units going back, a whole number above zero", 422, ['line' => $no]);
            }
            $cost = Movements::normaliseCost(is_string($l['unit_cost'] ?? null) ? str_replace(['£', ','], '', trim($l['unit_cost'])) : ($l['unit_cost'] ?? null), "lines[{$no}].unit_cost");
            $duty = SupplierInvoices::money($l['duty_amount'] ?? '0', "lines[{$no}].duty_amount", false);
            $grn = isset($l['grn_document_id']) && $l['grn_document_id'] !== null ? (int) $l['grn_document_id'] : null;
            $grnLine = $grn === null ? null : (int) ($l['grn_line_no'] ?? 0);
            if ($grn !== null && $grnLine < 1) {
                throw new CwException('bad_line', "line {$no}: the delivery line", 422, ['line' => $no]);
            }
            $wh = isset($l['warehouse_id']) && $l['warehouse_id'] !== null && $l['warehouse_id'] !== '' ? (int) $l['warehouse_id']
                : ($cur['warehouse_id'] === null ? null : (int) $cur['warehouse_id']);
            if ($grn === null && $cur['grn_document_id'] !== null) {
                // An item added by hand to a return about a delivery comes from that delivery (review finding 2).
                [$grn, $grnLine] = $this->deliveryLineFor((int) $cur['grn_document_id'], $sku, $wh, $qty, $taken);
            }
            $amount = InvoiceMath::fromE2(InvoiceMath::unitsAt($qty, InvoiceMath::e6($cost)) + InvoiceMath::e2($duty));
            $out[] = ['doc' => ['sku_id' => $sku, 'qty' => $qty, 'warehouse_id' => $wh, 'unit_cost' => $cost, 'amount' => $amount, 'reason_code' => $reason, 'description' => $desc],
                'ext' => ['grn_document_id' => $grn, 'grn_line_no' => $grnLine, 'duty_amount' => $duty]];
        }
        return $out;
    }

    /**
     * The line of delivery $grnId an item added without one comes from (review finding 2): the first line of that item with room for
     * $qty in the part the warehouse it leaves from holds (VERIFY: the line's exceptions there; UNSTAMPED: its quarantined units;
     * anywhere else: its accepted units), after the posted returns and this draft's earlier lines ($taken); else the first line of that
     * item (the posting then says what it booked in); [null, null] when the item is not on the delivery (the posting refuses it:
     * line_not_on_delivery).
     *
     * @param array<string, int> $taken "line:column" => units this draft's earlier lines take
     * @return array{0: ?int, 1: ?int}
     */
    private function deliveryLineFor(int $grnId, int $skuId, ?int $warehouseId, int $qty, array &$taken): array
    {
        $code = $warehouseId === null ? null : $this->db->value('SELECT code FROM warehouse WHERE id = ?', [$warehouseId]);
        $column = InvoiceMatch::EXCEPTION_WAREHOUSES[(string) $code] ?? 'accepted_units';
        $first = null;
        foreach ($this->db->all('SELECT g.line_no, g.accepted_units, g.verify_units, g.quarantine_units FROM grn_line g JOIN document_line dl ON dl.document_id = g.document_id '
            . 'AND dl.line_no = g.line_no WHERE g.document_id = ? AND dl.sku_id = ? ORDER BY g.line_no', [$grnId, $skuId]) as $g) {
            $line = (int) $g['line_no'];
            $first ??= $line;
            $key = $line . ':' . $column;
            if ((int) ($g[$column] ?? 0) - SupplierReturnHandler::returnedFrom($this->db, $grnId, $line, $column, null) - ($taken[$key] ?? 0) >= $qty) {
                $taken[$key] = ($taken[$key] ?? 0) + $qty;
                return [$grnId, $line];
            }
        }
        if ($first === null) {
            return [null, null];
        }
        $taken[$first . ':' . $column] = ($taken[$first . ':' . $column] ?? 0) + $qty;
        return [$grnId, $first];
    }

    /** @return list<array<string, mixed>> the draft's lines in saveLines()'s shape */
    private function lineRows(int $id): array
    {
        $out = [];
        foreach ($this->db->all('SELECT dl.sku_id, dl.qty, dl.warehouse_id, dl.unit_cost, dl.amount, dl.reason_code, dl.description, n.grn_document_id, n.grn_line_no, n.duty_amount '
            . 'FROM document_line dl JOIN dn_line n ON n.document_id = dl.document_id AND n.line_no = dl.line_no WHERE dl.document_id = ? ORDER BY dl.line_no', [$id]) as $r) {
            if ($r['sku_id'] === null) {
                $out[] = ['amount' => InvoiceMath::fromE2(InvoiceMath::e2((string) $r['amount'])), 'description' => $r['description'], 'reason_code' => $r['reason_code']];
                continue;
            }
            $out[] = ['sku_id' => (int) $r['sku_id'], 'qty' => (int) $r['qty'], 'warehouse_id' => $r['warehouse_id'] === null ? null : (int) $r['warehouse_id'],
                'unit_cost' => (string) $r['unit_cost'], 'duty_amount' => (string) $r['duty_amount'], 'reason_code' => $r['reason_code'], 'description' => $r['description'],
                'grn_document_id' => $r['grn_document_id'] === null ? null : (int) $r['grn_document_id'], 'grn_line_no' => $r['grn_line_no'] === null ? null : (int) $r['grn_line_no']];
        }
        return $out;
    }

    private static function mayPost(Db $db, Caller $caller): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'returns are kept by staff', 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (!Documents::mayPost($roles, 'DN')) {
            throw new CwException(in_array('admin', $roles, true) ? 'admin_cannot_post' : 'role_not_allowed', 'you cannot keep returns to suppliers', 403);
        }
    }

    private function nowDb(): string
    {
        return Clock::db(($this->clock)());
    }
}
