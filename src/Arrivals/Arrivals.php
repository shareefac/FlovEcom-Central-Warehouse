<?php

declare(strict_types=1);

namespace CW\Arrivals;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Invoices\SupplierInvoices;
use CW\Invoices\SupplierReturns;
use CW\Receiving\GoodsReceipts;
use CW\Settings;
use CW\Staff\StaffRoles;

/**
 * Pack J, the owner's day-one purchasing workflow (docs/decisions.md PJ1-PJ16): goods on a final supplier invoice that have not arrived
 * yet, and the ways they come in.
 *
 *  prefsOf() / setPrefs()   a supplier's own choices (supplier_arrival_pref, audit supplier.arrivals; suppliers.manage): "sell before it
 *                           arrives" (its goods on a final invoice count towards what the websites sell) and how its invoices come in
 *                           (count on arrival, or book as received); without its own, the settings arrivals.sell_before_default and
 *                           arrivals.default_mode.
 *  arriving()               Goods In > Arriving: per final invoice, what is still on its way (open invoice_arrival rows), what of it the
 *                           websites sell, and whether it is late (arrivals.late_hours after the invoice was made final).
 *  receive()                Goods In's "Receive": a draft receipt of everything still on its way on that invoice (its number, its order
 *                           when one, its copy), the usual bench and stamp check follow; one live receipt per invoice (the same draft
 *                           again). Posting it closes the invoice's Arriving (ArrivalBooking::receiptPosted).
 *  missingOf() / claimMissing()  what such a receipt did not bring of the invoice (units on the invoice line less those the receipt booked
 *                           in), at the invoice's price, and one click to claim it: a return to the supplier (pack C's DN, our debit
 *                           note) about that delivery and invoice made only of claims, no goods: it moves no stock. One live claim per
 *                           receipt (the same draft again).
 *  bookAsReceived()         "book as received", step 1 (SupplierInvoices::submit): the receipt of the invoice's goods no delivery names,
 *                           booked and posted at once in one transaction (accepted in full; the duty stamp as the invoice says; the
 *                           invoice's copy), once (supplier_invoice.submit_receipt_id under the invoice's row lock).
 */
final class Arrivals
{
    public const MODES = ['count', 'received'];
    public const ARRIVING_LIMIT = 500;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): FileStore)|null $files the file store (copies of the invoice; none: the receipt waits for its copy)
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(private readonly Db $db, private readonly ?Documents $docs = null, private readonly ?\Closure $files = null, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    // ------------------------------------------------------------------------------------------
    // A supplier's choices
    // ------------------------------------------------------------------------------------------

    /** @return array{sell_before: bool, mode: string, own: bool} */
    public function prefsOf(int $supplierId): array
    {
        $r = $this->db->one('SELECT sell_before_arrival, default_mode FROM supplier_arrival_pref WHERE supplier_id = ?', [$supplierId]);
        if ($r !== null) {
            return ['sell_before' => (int) $r['sell_before_arrival'] === 1, 'mode' => (string) $r['default_mode'], 'own' => true];
        }
        $s = new Settings($this->db);
        $sell = $s->has('arrivals.sell_before_default') ? $s->get('arrivals.sell_before_default') : true;
        $mode = $s->has('arrivals.default_mode') ? $s->get('arrivals.default_mode') : 'count';
        return ['sell_before' => $sell !== false, 'mode' => in_array($mode, self::MODES, true) ? (string) $mode : 'count', 'own' => false];
    }

    public function setPrefs(Caller $caller, int $supplierId, bool $sellBefore, string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new CwException('bad_mode', 'choose how its invoices come in: count on arrival, or book as received', 400, ['field' => 'default_mode']);
        }
        $this->db->transaction(function (Db $db) use ($caller, $supplierId, $sellBefore, $mode): void {
            self::may($db, $caller, 'suppliers.manage', 'change a supplier');
            $s = $db->one('SELECT id, code, status FROM supplier WHERE id = ? FOR SHARE', [$supplierId]) ?? throw new CwException('unknown_supplier', 'there is no such supplier', 404);
            if ($s['status'] === 'pending_approval') {
                // As its other details (Suppliers::change): not changed while a reviewer looks at it.
                throw new CwException('supplier_pending', "{$s['code']} is waiting for approval: withdraw the activation request first to change it", 409);
            }
            $before = $db->one('SELECT sell_before_arrival, default_mode FROM supplier_arrival_pref WHERE supplier_id = ? FOR UPDATE', [$supplierId]);
            $now = Clock::db(($this->clock)());
            $db->exec('INSERT INTO supplier_arrival_pref (supplier_id, sell_before_arrival, default_mode, updated_by, updated_actor, updated_at) VALUES (?, ?, ?, ?, ?, ?) AS n '
                . 'ON DUPLICATE KEY UPDATE sell_before_arrival = n.sell_before_arrival, default_mode = n.default_mode, updated_by = n.updated_by, '
                . 'updated_actor = n.updated_actor, updated_at = n.updated_at', [$supplierId, $sellBefore ? 1 : 0, $mode, $caller->staffUserId, $caller->actor, $now]);
            Audit::write($db, $caller, 'supplier.arrivals', 'supplier', (string) $supplierId, null, ['supplier' => (string) $s['code'],
                'before' => $before === null ? null : ['sell_before' => (int) $before['sell_before_arrival'] === 1, 'mode' => (string) $before['default_mode']],
                'after' => ['sell_before' => $sellBefore, 'mode' => $mode]]);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Goods In > Arriving
    // ------------------------------------------------------------------------------------------

    /**
     * Per final invoice with goods on their way, oldest first (at most ARRIVING_LIMIT).
     *
     * @return list<array{invoice_id: int, number: string, external_ref: ?string, posted_at: string, supplier_id: int, supplier_name: string, lines: int, units: int,
     *     sellable_units: int, late: bool, hours: int, receipt_id: ?int}>
     */
    public function arriving(?int $supplierId = null): array
    {
        $s = new Settings($this->db);
        $lateHours = $s->has('arrivals.late_hours') ? (int) $s->get('arrivals.late_hours') : 24;
        $now = ($this->clock)();
        $rows = $this->db->all(
            'SELECT a.sinv_document_id, d.number, d.external_ref, d.posted_at, si.supplier_id, s.name AS supplier_name, COUNT(*) AS line_count, SUM(a.units) AS units, '
            . 'SUM(IF(a.sellable = 1, a.units, 0)) AS sellable_units, (SELECT MAX(g.grn_document_id) FROM goods_receipt_invoice g JOIN document gd ON gd.id = g.grn_document_id '
            . "AND gd.status IN ('draft', 'awaiting_approval') WHERE g.sinv_document_id = a.sinv_document_id) AS receipt_id "
            . 'FROM invoice_arrival a JOIN document d ON d.id = a.sinv_document_id JOIN supplier_invoice si ON si.document_id = a.sinv_document_id '
            . "JOIN supplier s ON s.id = si.supplier_id WHERE a.state = 'open'" . ($supplierId === null ? '' : ' AND si.supplier_id = ?')
            . ' GROUP BY a.sinv_document_id, d.number, d.external_ref, d.posted_at, si.supplier_id, s.name ORDER BY d.posted_at, a.sinv_document_id LIMIT ' . self::ARRIVING_LIMIT,
            $supplierId === null ? [] : [$supplierId],
        );
        $out = [];
        foreach ($rows as $r) {
            $hours = intdiv(max(0, $now->getTimestamp() - (new \DateTimeImmutable((string) $r['posted_at'], Clock::utc()))->getTimestamp()), 3600);
            $out[] = ['invoice_id' => (int) $r['sinv_document_id'], 'number' => (string) $r['number'], 'external_ref' => $r['external_ref'], 'posted_at' => (string) $r['posted_at'],
                'supplier_id' => (int) $r['supplier_id'], 'supplier_name' => (string) $r['supplier_name'], 'lines' => (int) $r['line_count'], 'units' => (int) $r['units'],
                'sellable_units' => (int) $r['sellable_units'], 'late' => $hours >= $lateHours, 'hours' => $hours,
                'receipt_id' => $r['receipt_id'] === null ? null : (int) $r['receipt_id']];
        }
        return $out;
    }

    /** How many final invoices have goods on their way (the tab's badge), and how many of them are late. @return array{all: int, late: int} */
    public function counts(): array
    {
        $late = 0;
        $all = 0;
        foreach ($this->arriving() as $r) {
            $all++;
            $late += $r['late'] ? 1 : 0;
        }
        return ['all' => $all, 'late' => $late];
    }

    /**
     * Goods In's "Receive": the draft receipt of everything still on its way on a final invoice (doc.GRN.post). A live receipt already
     * made from it: that one. 409 nothing_arriving when nothing is on its way.
     */
    public function receive(Caller $caller, int $invoiceId): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $invoiceId): Document {
            self::may($db, $caller, 'doc.GRN.post', 'receive goods');
            $inv = $db->one("SELECT d.id, d.number, d.status, d.external_ref, d.doc_date, si.supplier_id FROM document d JOIN supplier_invoice si ON si.document_id = d.id "
                . "AND si.kind = 'invoice' WHERE d.id = ? FOR UPDATE OF d", [$invoiceId]) ?? throw new CwException('unknown_document', 'there is no such supplier invoice', 404);
            $live = $db->value("SELECT MAX(g.grn_document_id) FROM goods_receipt_invoice g JOIN document gd ON gd.id = g.grn_document_id AND gd.status IN ('draft', 'awaiting_approval') "
                . 'WHERE g.sinv_document_id = ?', [$invoiceId]);
            if ($live !== null) {
                return $this->documents()->get((int) $live);
            }
            $rows = $this->openLines($invoiceId);
            if ($inv['status'] !== 'posted' || $rows === []) {
                throw new CwException('nothing_arriving', "{$inv['number']} has nothing on its way", 409);
            }
            return $this->receiptOf($caller, $inv, $rows, false);
        });
    }

    /**
     * What a receipt made from an invoice did not bring of that invoice, per invoice line: its units less what the receipt booked in of
     * that item (accepted, set aside to check or quarantined: those have their own ways back), at the invoice's price. CW-1155 (docs/decisions.md
     * RR2): less what came since on receipts of the rest, what the supplier credited of the line and what a person stopped waiting for
     * (ArrivalRest::lines `missing`: one rule for the claim and the rest).
     *
     * @return list<array{line_no: int, sku_id: int, code: string, name: string, units: int, unit_cost: string, amount: string}>
     */
    public function missingOf(int $grnId): array
    {
        $out = [];
        foreach (array_map('intval', $this->db->column("SELECT DISTINCT sinv_document_id FROM invoice_arrival WHERE grn_document_id = ? AND state = 'received' "
            . 'ORDER BY sinv_document_id', [$grnId])) as $inv) {
            $lines = array_filter(ArrivalRest::lines($this->db, $inv), static fn (array $l): bool => $l['first_grn'] === $grnId && $l['missing'] > 0);
            $names = [];
            foreach ($lines === [] ? [] : $this->db->all('SELECT id, code, name FROM sku WHERE id IN (' . implode(', ', array_fill(0, count($lines), '?')) . ')',
                array_values(array_map(static fn (array $l): int => $l['sku_id'], $lines))) as $n) {
                $names[(int) $n['id']] = $n;
            }
            foreach ($lines as $l) {
                $out[] = ['line_no' => $l['line_no'], 'sku_id' => $l['sku_id'], 'code' => (string) ($names[$l['sku_id']]['code'] ?? ''),
                    'name' => (string) ($names[$l['sku_id']]['name'] ?? ''), 'units' => $l['missing'], 'unit_cost' => $l['unit_cost'],
                    'amount' => ArrivalRest::amount($l['missing'], $l['unit_cost'])];
            }
        }
        return $out;
    }

    /**
     * CW-1155 (docs/decisions.md RR2): what a final invoice counted on arrival is still waiting for, per invoice line: the units its first
     * receipt did not bring, less what came since, was credited, claimed or stopped waiting for; at the invoice's price. [] when nothing.
     *
     * @return list<array{line_no: int, sku_id: int, code: string, name: string, units: int, unit_cost: string, amount: string, first_grn: int, first_grn_line: ?int}>
     */
    public function owedOf(int $invoiceId): array
    {
        return ArrivalRest::owed($this->db, $invoiceId);
    }

    /**
     * CW-1155 "Receive the rest" (docs/decisions.md RR3, RR4): a draft receipt, from the SAME supplier invoice, of only the units it is still
     * waiting for (doc.GRN.post), the usual bench and stamp check and posting follow (its posting: ArrivalBooking::receiptPosted). A live
     * receipt already made from the invoice: that one (pressed twice, or two people at once: the same draft). 409 claimed when what is
     * missing was claimed (cancel the claim first), 409 nothing_owed when the invoice waits for nothing.
     *
     * Locks: the invoice's document row, then its first receipt's (as claimMissing() and SupplierInvoices::accept, RR12): one of them at a
     * time per invoice; the first receipt's reversal waits too.
     */
    public function receiveRest(Caller $caller, int $invoiceId): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $invoiceId): Document {
            self::may($db, $caller, 'doc.GRN.post', 'receive goods');
            $inv = $db->one("SELECT d.id, d.number, d.status, d.external_ref, d.doc_date, si.supplier_id FROM document d JOIN supplier_invoice si ON si.document_id = d.id "
                . "AND si.kind = 'invoice' WHERE d.id = ? FOR UPDATE OF d", [$invoiceId]) ?? throw new CwException('unknown_document', 'there is no such supplier invoice', 404);
            $live = $db->value("SELECT MAX(g.grn_document_id) FROM goods_receipt_invoice g JOIN document gd ON gd.id = g.grn_document_id AND gd.status IN ('draft', 'awaiting_approval') "
                . 'WHERE g.sinv_document_id = ?', [$invoiceId]);
            if ($live !== null) {
                return $this->documents()->get((int) $live);
            }
            $first = ArrivalRest::firstReceipt($db, $invoiceId);
            if ($inv['status'] !== 'posted' || $first === null) {
                throw new CwException('nothing_owed', "{$inv['number']} is not waiting for anything", 409);
            }
            $db->one('SELECT id FROM document WHERE id = ? FOR UPDATE', [$first]);
            $owed = ArrivalRest::owed($db, $invoiceId);
            if ($owed === []) {
                $claim = ArrivalRest::liveClaim($db, $first);
                if ($claim !== null) {
                    $dn = $this->documents()->get($claim)->label();
                    throw new CwException('claimed', "The missing units were claimed on {$dn}. Cancel that claim first if the goods came after all.", 409, ['claim' => $claim]);
                }
                throw new CwException('nothing_owed', "{$inv['number']} is not waiting for anything", 409);
            }
            return $this->restReceiptOf($caller, $inv, $owed);
        });
    }

    /**
     * One click: the claim of what a receipt made from an invoice did not bring (doc.DN.post): a draft return to the supplier about that
     * delivery and invoice, one claim line (no item: no stock moves) per invoice line short, reason "supplier error" when it is offered.
     * A live claim of that receipt already: that one. 409 not_from_invoice, 422 nothing_missing.
     */
    public function claimMissing(Caller $caller, int $grnId): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $grnId): Document {
            self::may($db, $caller, 'doc.DN.post', 'claim from a supplier');
            // CW-1155 (docs/decisions.md RR12): the invoice's document row first, then the receipt's (as receiveRest()), then the invoice's
            // arrival rows (as the postings): a claim and the receipt of the rest never take the same units.
            $invOf = $db->value('SELECT sinv_document_id FROM goods_receipt_invoice WHERE grn_document_id = ?', [$grnId]);
            if ($invOf !== null) {
                $db->one('SELECT id FROM document WHERE id = ? FOR UPDATE', [(int) $invOf]);
            }
            $g = $db->one("SELECT d.id, d.number, d.status, gri.sinv_document_id, gr.supplier_id FROM document d JOIN goods_receipt gr ON gr.document_id = d.id "
                . 'LEFT JOIN goods_receipt_invoice gri ON gri.grn_document_id = d.id WHERE d.id = ? FOR UPDATE OF d', [$grnId])
                ?? throw new CwException('unknown_document', 'there is no such receipt', 404);
            if ($g['sinv_document_id'] === null || $g['status'] !== 'posted') {
                throw new CwException('not_from_invoice', "{$g['number']} was not received against a supplier invoice", 409);
            }
            $db->all('SELECT line_no FROM invoice_arrival WHERE sinv_document_id = ? ORDER BY line_no FOR UPDATE', [(int) $g['sinv_document_id']]);
            $live = ArrivalRest::liveClaim($db, $grnId);
            if ($live !== null) {
                return $this->documents()->get($live);
            }
            $rest = ArrivalRest::liveRestReceipt($db, (int) $g['sinv_document_id']);
            if ($rest !== null) {
                $label = $this->documents()->get($rest)->label();
                throw new CwException('rest_being_received', "{$label} is taking in the rest of this invoice. Book it in or cancel it first.", 409, ['receipt' => $rest]);
            }
            $missing = $this->missingOf($grnId);
            if ($missing === []) {
                throw new CwException('nothing_missing', "{$g['number']} brought everything its invoice billed", 422);
            }
            $number = (string) $db->value('SELECT number FROM document WHERE id = ?', [(int) $g['sinv_document_id']]);
            $reason = $db->value("SELECT code FROM reason_code WHERE code = 'supplier_error' AND is_active = 1 AND FIND_IN_SET('supplier_return', applies_to) > 0");
            $returns = new SupplierReturns($db, $this->documents(), $this->files);
            $dn = $returns->createDraft($caller, (int) $g['supplier_id'], ['grn_id' => $grnId, 'invoice_id' => (int) $g['sinv_document_id']]
                + ($reason === null ? [] : ['reason_code' => (string) $reason]));
            $lines = array_map(static fn (array $m): array => ['amount' => $m['amount'], 'description' => mb_substr("Missing on arrival: {$m['units']} x {$m['code']} {$m['name']} "
                . "({$number} line {$m['line_no']})", 0, 255)], $missing);
            $dn = $returns->saveLines($caller, $dn->id, $dn->version, $lines);
            // CW-1155 (RR8): what it claims, line by line: those units are not waited for while the claim is live (draft, waiting, posted).
            $firstLines = [];
            foreach (ArrivalRest::lines($db, (int) $g['sinv_document_id']) as $l) {
                $firstLines[$l['line_no']] = $l['first_grn_line'];
            }
            $now = Clock::db(($this->clock)());
            foreach (array_values($missing) as $i => $m) {
                $db->exec('INSERT INTO invoice_arrival_rest (sinv_document_id, line_no, first_grn_document_id, first_grn_line_no, kind, document_id, document_line_no, units, '
                    . "created_by, created_actor, created_at) VALUES (?, ?, ?, ?, 'claim', ?, ?, ?, ?, ?, ?)",
                    [(int) $g['sinv_document_id'], $m['line_no'], $grnId, $firstLines[$m['line_no']] ?? null, $dn->id, $i + 1, $m['units'], $caller->staffUserId, $caller->actor, $now]);
            }
            Audit::write($db, $caller, 'arrival.claim', 'document', (string) $dn->id, null, ['receipt' => (string) $g['number'], 'invoice' => $number,
                'missing' => array_map(static fn (array $m): array => ['line' => $m['line_no'], 'sku' => $m['code'], 'units' => $m['units'], 'amount' => $m['amount']], $missing)]);
            return $dn;
        });
    }

    /**
     * "Book as received", step 1: the receipt of the invoice's goods no delivery names, booked and posted now (all accepted; the duty
     * stamp the invoice gives; the invoice's copy). Once: the invoice's row is locked and submit_receipt_id names a receipt already
     * booked (then that one). The caller holds doc.GRN.post (the receipt is theirs) and doc.SINV.post (the invoice is made final next).
     * Review findings 2 and 3 (Codex, 15 Oct): under the invoice's lock, before anything is booked, the invoice is still the version
     * the person (or the timed job) made final, and the person may make it final: a change meanwhile (a new time, a stop) or a right
     * taken away books no receipt.
     */
    public function bookAsReceived(Caller $caller, int $invoiceId, ?int $expectedVersion = null): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $invoiceId, $expectedVersion): Document {
            self::may($db, $caller, 'doc.SINV.post', 'make supplier invoices final');
            $inv = $db->one("SELECT d.id, d.number, d.status, d.version, d.external_ref, d.doc_date, si.supplier_id, si.receive_mode, si.submit_receipt_id FROM document d "
                . "JOIN supplier_invoice si ON si.document_id = d.id AND si.kind = 'invoice' WHERE d.id = ? FOR UPDATE OF d", [$invoiceId])
                ?? throw new CwException('unknown_document', 'there is no such supplier invoice', 404);
            if ($expectedVersion !== null && (int) $inv['version'] !== $expectedVersion) {
                throw new CwException('version_conflict', 'this invoice was changed meanwhile: reload it', 409, ['version' => (int) $inv['version']]);
            }
            if ($inv['submit_receipt_id'] !== null) {
                $g = $this->documents()->get((int) $inv['submit_receipt_id']);
                if ($g->status === 'posted') {
                    return $g;
                }
                throw new CwException('receipt_not_posted', "the receipt of {$inv['external_ref']} ({$g->label()}) is " . str_replace('_', ' ', $g->status), 409);
            }
            if ($inv['status'] !== 'draft' || $inv['receive_mode'] !== 'received') {
                throw new CwException('not_book_as_received', 'only a draft invoice booked as received books its receipt this way', 409);
            }
            $rows = [];
            foreach (\CW\Invoices\SupplierInvoiceHandler::notReceived($db, $invoiceId) as $no) {
                $rows[] = $no;
            }
            if ($rows === []) {
                throw new CwException('nothing_to_receive', 'every goods line of this invoice came on a delivery already', 409);
            }
            $grn = $this->receiptOf($caller, $inv, $rows, true);
            $grns = new GoodsReceipts($db, $this->documents(), null, $this->files, $this->clock);
            // The bench is skipped: the paperwork is the invoice, the stamp the answer it gives (receipt line i = invoice line $rows[i]).
            $bench = [];
            $answers = [];
            foreach ($rows as $no) {
                $answers[] = $db->value('SELECT stamp_answer FROM sinv_line WHERE document_id = ? AND line_no = ?', [$invoiceId, $no]);
            }
            foreach ($answers as $i => $answer) {
                $bench[$i + 1] = $answer === null ? ['stamp_on_pack' => ''] : ['stamp_on_pack' => '1', 'stamp_type' => (string) $answer];
            }
            $grn = $grns->bench($caller, $grn->id, $grn->version, ['paperwork_ok' => '1', 'bench_note' => 'booked as received from ' . ($inv['external_ref'] ?? '')], $bench);
            $db->exec('UPDATE supplier_invoice SET submit_receipt_id = ?, updated_at = ? WHERE document_id = ?', [$grn->id, Clock::db(($this->clock)()), $invoiceId]);
            return $grns->post($caller, $grn->id, $grn->version);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * A draft receipt of these invoice lines (arrival rows, or the lines "book as received" takes): the supplier's invoice number and
     * date, its order when every line comes from one, a line per invoice line (its packs, pack price, supplier code, order line), the
     * invoice's copy, and the link goods_receipt_invoice. In the caller's transaction.
     *
     * @param array<string, mixed> $inv
     * @param list<int> $lineNos
     */
    private function receiptOf(Caller $caller, array $inv, array $lineNos, bool $auto): Document
    {
        $db = $this->db;
        $in = implode(', ', array_fill(0, count($lineNos), '?'));
        $lines = $db->all("SELECT sl.line_no, sl.po_document_id, sl.po_line_no, sl.supplier_code, sl.purchase_unit, sl.units_per_pack, sl.packs, sl.pack_price, dl.sku_id, "
            . "dl.description FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no WHERE sl.document_id = ? AND sl.line_no IN ({$in}) "
            . 'ORDER BY sl.line_no', [(int) $inv['id'], ...$lineNos]);
        $pos = array_values(array_unique(array_filter(array_map(static fn (array $l): ?int => $l['po_document_id'] === null ? null : (int) $l['po_document_id'], $lines))));
        $po = count($pos) === 1 ? $pos[0] : null;
        if ($po !== null && $db->value("SELECT 1 FROM purchase_order WHERE document_id = ? AND state IN ('approved', 'sent', 'part_received')", [$po]) === null) {
            $po = null; // closed or fully received since: the receipt goes without it
        }
        $grns = new GoodsReceipts($db, $this->documents(), null, $this->files, $this->clock);
        $grn = $grns->createDraft($caller, (int) $inv['supplier_id'], ['invoice_number' => $inv['external_ref'], 'invoice_date' => $inv['doc_date']], $po);
        $grnLines = [];
        foreach ($lines as $l) {
            $packs = $l['packs'] === null ? null : (int) $l['packs'];
            $grnLines[] = ['sku_id' => (int) $l['sku_id'], 'supplier_code' => $l['supplier_code'], 'purchase_unit' => $l['purchase_unit'] ?? 'each',
                'units_per_pack' => (int) $l['units_per_pack'], 'packs' => $packs, 'pack_price' => (string) $l['pack_price'],
                'po_line_no' => $po !== null && $l['po_document_id'] !== null && (int) $l['po_document_id'] === $po ? (int) $l['po_line_no'] : null,
                'entry' => $po !== null && $l['po_document_id'] !== null ? 'po' : 'manual'];
        }
        $grn = $grns->saveDraft($caller, $grn->id, $grn->version, [], $grnLines);
        $this->copyFiles($caller, (int) $inv['id'], $grn->id);
        $db->exec('INSERT INTO goods_receipt_invoice (grn_document_id, sinv_document_id, auto, created_at) VALUES (?, ?, ?, ?)',
            [$grn->id, (int) $inv['id'], $auto ? 1 : 0, Clock::db(($this->clock)())]);
        Audit::write($db, $caller, $auto ? 'arrival.book_received' : 'arrival.receive', 'document', (string) $grn->id, null,
            ['invoice' => (string) $inv['number'] !== '' ? (string) $inv['number'] : (string) $inv['external_ref'], 'lines' => count($grnLines)]);
        return $this->documents()->get($grn->id);
    }

    /**
     * CW-1155 (RR3): the draft receipt of the rest: the supplier's invoice number as its next part (GoodsReceipts::createDraft $restOf), its
     * date, its order when every owed line comes from one still receivable, one line per owed invoice line with the owed units (the invoice
     * line's packs and pack price when they are whole packs of it, else single units at its unit price), the invoice's copy, and the link
     * goods_receipt_invoice (auto 0). Audit arrival.receive_rest. In the caller's transaction.
     *
     * @param array<string, mixed> $inv
     * @param list<array<string, mixed>> $owed ArrivalRest::owed()
     */
    private function restReceiptOf(Caller $caller, array $inv, array $owed): Document
    {
        $db = $this->db;
        $nos = array_map(static fn (array $o): int => (int) $o['line_no'], $owed);
        $units = array_combine($nos, array_map(static fn (array $o): int => (int) $o['units'], $owed));
        $in = implode(', ', array_fill(0, count($nos), '?'));
        $lines = $db->all("SELECT sl.line_no, sl.po_document_id, sl.po_line_no, sl.supplier_code, sl.purchase_unit, sl.units_per_pack, sl.pack_price, dl.sku_id, dl.unit_cost "
            . "FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no WHERE sl.document_id = ? AND sl.line_no IN ({$in}) "
            . 'ORDER BY sl.line_no', [(int) $inv['id'], ...$nos]);
        $pos = array_values(array_unique(array_filter(array_map(static fn (array $l): ?int => $l['po_document_id'] === null ? null : (int) $l['po_document_id'], $lines))));
        $po = count($pos) === 1 ? $pos[0] : null;
        if ($po !== null && $db->value("SELECT 1 FROM purchase_order WHERE document_id = ? AND state IN ('approved', 'sent', 'part_received')", [$po]) === null) {
            $po = null; // closed or fully received since: the receipt goes without it
        }
        $grns = new GoodsReceipts($db, $this->documents(), null, $this->files, $this->clock);
        $grn = $grns->createDraft($caller, (int) $inv['supplier_id'], ['invoice_number' => $inv['external_ref'], 'invoice_date' => $inv['doc_date']], $po, false, (int) $inv['id']);
        // The link first: the checks of one invoice number on several receipts and of its copy on them know it from here on.
        $db->exec('INSERT INTO goods_receipt_invoice (grn_document_id, sinv_document_id, auto, created_at) VALUES (?, ?, 0, ?)',
            [$grn->id, (int) $inv['id'], Clock::db(($this->clock)())]);
        $grnLines = [];
        foreach ($lines as $l) {
            $want = $units[(int) $l['line_no']];
            $upp = max(1, (int) $l['units_per_pack']);
            $whole = $want % $upp === 0;
            $grnLines[] = ['sku_id' => (int) $l['sku_id'], 'supplier_code' => $l['supplier_code'], 'purchase_unit' => $whole ? ($l['purchase_unit'] ?? 'each') : 'each',
                'units_per_pack' => $whole ? $upp : 1, 'packs' => $whole ? intdiv($want, $upp) : $want,
                'pack_price' => $whole ? (string) $l['pack_price'] : SupplierInvoices::price4((string) $l['unit_cost']),
                'po_line_no' => $po !== null && $l['po_document_id'] !== null && (int) $l['po_document_id'] === $po ? (int) $l['po_line_no'] : 0,
                'entry' => $po !== null && $l['po_document_id'] !== null ? 'po' : 'manual'];
        }
        $grn = $grns->saveDraft($caller, $grn->id, $grn->version, [], $grnLines);
        $this->copyFiles($caller, (int) $inv['id'], $grn->id);
        // Its record says which invoice line each of its lines is (receipt line i = lines[i-1]): the posting gives each line's units to that one
        // (ArrivalRest::draftLines, RR27).
        Audit::write($db, $caller, 'arrival.receive_rest', 'document', (string) $grn->id, null, ['invoice' => (string) $inv['number'],
            'lines' => array_map(static fn (array $l): int => (int) $l['line_no'], $lines),
            // ... and what each line is (item, units per pack, pack price): a line taken off and keyed again elsewhere is no longer it (RR28)
            'shape' => array_map(static fn (array $g): array => [(int) $g['sku_id'], (int) $g['units_per_pack'], (string) $g['pack_price'], (int) ($g['po_line_no'] ?? 0)],
                $grnLines),
            'owed' => array_map(static fn (array $o): array => ['line' => $o['line_no'], 'sku' => $o['code'], 'units' => $o['units']], $owed)]);
        return $this->documents()->get($grn->id);
    }

    /** @return list<int> the invoice's arrival rows still on their way (line numbers) */
    private function openLines(int $invoiceId): array
    {
        return array_map('intval', $this->db->column("SELECT line_no FROM invoice_arrival WHERE sinv_document_id = ? AND state = 'open' ORDER BY line_no", [$invoiceId]));
    }

    /** The invoice's copies (role supplier_invoice) on the receipt too (the receipt needs one, I171); none without a file store. */
    private function copyFiles(Caller $caller, int $invoiceId, int $grnId): void
    {
        if ($this->files === null) {
            return;
        }
        $ids = $this->db->column("SELECT file_id FROM document_file WHERE document_id = ? AND role = 'supplier_invoice' ORDER BY attached_at, file_id", [$invoiceId]);
        if ($ids === []) {
            return;
        }
        try {
            $store = ($this->files)();
        } catch (CwException) {
            return;
        }
        foreach ($ids as $f) {
            $store->attach($caller, $grnId, (int) $f, 'supplier_invoice');
        }
    }

    private function documents(): Documents
    {
        return $this->docs ?? new Documents($this->db, \CW\Documents\DocumentHandlers::all($this->db, $this->clock));
    }

    private static function may(Db $db, Caller $caller, string $perm, string $what): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', "only staff {$what}", 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (in_array('admin', $roles, true)) {
            throw new CwException('admin_cannot_post', 'admin manages people and roles only', 403);
        }
        if (!Permissions::can($roles, $perm)) {
            throw new CwException('role_not_allowed', "your roles cannot {$what}", 403);
        }
    }
}
