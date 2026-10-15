<?php

declare(strict_types=1);

namespace CW\Invoices;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\Db;
use CW\Idempotency;
use CW\PurchaseOrders\PoMath;
use CW\Settings;

/**
 * The 3-way match of a supplier invoice (pack C, plan IM7; docs/decisions.md PC2-PC5): each goods line against the order line (the
 * price agreed) and the delivery line (the units received), per line, and the invoice's state from its lines (InvoiceMath).
 *
 * What a goods line bills is its units and amount less what the supplier's posted credit notes took off that line. What its delivery
 * line still has to be invoiced ("available") is the units accepted into stock by the posted receipt, less the units returned to the
 * supplier by posted returns that name that delivery line from where accepted units are (a return from VERIFY or UNSTAMPED takes
 * back the line's damaged, wrong, over or unstamped units, never accepted: nothing off), less what the supplier's OTHER posted invoices
 * bill of it (net of their credit notes), less what this invoice's earlier lines bill of it: so one delivery may be invoiced over several invoices, and one
 * invoice may bill several deliveries, and a delivery billed twice shows on both. A reversed receipt has nothing to invoice. The
 * reference price is the order line's (pack price / units per pack), else the delivery's provisional cost, else none.
 *
 * The state of every POSTED invoice is stored on its supplier_invoice row (match_*), with the tolerances it was worked out with, so the
 * "To match" list is one indexed read. It is worked out again (refresh()) whenever something it depends on changes, inside that
 * change's transaction: the invoice's own posting and reversal, the posting and reversal of another invoice, a credit note or a return
 * on the same deliveries, and the reversal of a receipt (GoodsReceiptHandler::reverse calls afterReceiptReversed()). An invoice whose
 * differences a person accepted stays `accepted` while its match is still exactly what they accepted (the same lines off with the
 * same figures: fingerprint()); any other match drops the acceptance, and a later difference needs a person again. The nightly
 * check (InvoiceInvariants V4) works every stored match out again with the stored tolerances.
 *
 * Locks (no cycle with the postings, docs/decisions.md PC10): a caller that changes a delivery's figures locks the delivery's
 * `document` rows first (lockReceipts(), id order), then refresh() locks the supplier_invoice rows it rewrites (id order). It never
 * locks another invoice's `document` row.
 */
final class InvoiceMatch
{
    /** Setting key => its default (the plan's IM7 numbers; the quantity: exact). */
    public const RULE_SETTINGS = ['price_pct' => ['invoices.price_tolerance_pct', '2'], 'price_gbp' => ['invoices.price_tolerance_gbp', '5.00'],
        'qty_pct' => ['invoices.qty_tolerance_pct', '0'], 'total_per_line' => ['invoices.total_tolerance_per_line', '0.05'],
        // PC19 (0040): whether the price is checked at all ('1' / '0'); off by the owner's decision of 15 Oct 2026.
        'check_price' => ['invoices.check_price', '0']];

    /**
     * The warehouses a receipt puts a line's exceptions in (GoodsReceiptHandler: damaged, wrong and over units in VERIFY, quarantined
     * unstamped ones in UNSTAMPED), with the grn_line column that counts them. A return from anywhere else takes accepted units back.
     */
    public const EXCEPTION_WAREHOUSES = ['VERIFY' => 'verify_units', 'UNSTAMPED' => 'quarantine_units'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The tolerances in force now, as typed (decimal strings): read from the settings on every call (a change on the Settings page
     * applies to the next match).
     *
     * @return array{price_pct: string, price_gbp: string, qty_pct: string, total_per_line: string}
     */
    public function typedRules(): array
    {
        $s = new Settings($this->db);
        $out = [];
        foreach (self::RULE_SETTINGS as $k => [$key, $default]) {
            $v = $s->has($key) ? $s->get($key) : null;
            $out[$k] = match (true) {
                is_bool($v) => $v ? '1' : '0',
                $v === null, $v === '' => $default,
                default => (string) $v,
            };
        }
        /** @var array{price_pct: string, price_gbp: string, qty_pct: string, total_per_line: string, check_price: string} $out */
        return $out;
    }

    /**
     * The match of one invoice (SINV) as it would be posted now (a draft's preview) or as it is (a posted one): its state, the goods
     * lines off, the money to look at, the total's rounding, and every line's figures.
     *
     * @param array{price_pct: string, price_gbp: string, qty_pct: string, total_per_line: string}|null $typed the tolerances (null: now)
     * @return array{state: string, lines_off: int, difference_e2: int, total_diff_e2: int, sum_e2: int, rules: array<string, string>, lines: array<int, array<string, mixed>>}
     */
    public function compute(int $invoiceId, ?array $typed = null): array
    {
        $typed ??= $this->typedRules();
        $rules = InvoiceMath::rules($typed);
        $inv = $this->db->one('SELECT net_total, receive_mode FROM supplier_invoice WHERE document_id = ?', [$invoiceId]);
        $lines = $this->db->all(
            'SELECT dl.line_no, dl.sku_id, dl.qty, dl.unit_cost, dl.amount, sl.kind, sl.grn_document_id, sl.grn_line_no, sl.po_document_id, sl.po_line_no, '
            . 'a.state AS arrival_state, a.grn_document_id AS arrival_grn, a.grn_line_no AS arrival_grn_line '
            . 'FROM document_line dl JOIN sinv_line sl ON sl.document_id = dl.document_id AND sl.line_no = dl.line_no '
            . 'LEFT JOIN invoice_arrival a ON a.sinv_document_id = sl.document_id AND a.line_no = sl.line_no WHERE dl.document_id = ? ORDER BY dl.line_no',
            [$invoiceId],
        );
        // Pack J (docs/decisions.md PJ8): a goods line no delivery names, on an invoice whose goods come in with it (receive_mode set),
        // is on its way: billed units are "there" until a receipt takes it in; then the receipt's line of that item is its delivery
        // line (none: that item did not arrive, nothing is there).
        $arriving = $inv !== null && $inv['receive_mode'] !== null;
        foreach ($lines as $i => $l) {
            if ($l['grn_document_id'] === null && $l['arrival_state'] === 'received' && $l['arrival_grn_line'] !== null) {
                $lines[$i]['grn_document_id'] = $l['arrival_grn'];
                $lines[$i]['grn_line_no'] = $l['arrival_grn_line'];
            }
        }
        $credits = [];
        foreach ($this->db->all(
            'SELECT sl.credit_of_line_no AS line_no, SUM(dl.qty) AS qty, SUM(dl.amount) AS amount FROM supplier_invoice c '
            . "JOIN document cd ON cd.id = c.document_id AND cd.status = 'posted' "
            . "JOIN sinv_line sl ON sl.document_id = c.document_id AND sl.kind = 'goods' AND sl.credit_of_line_no IS NOT NULL "
            . 'JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no '
            . "WHERE c.credits_document_id = ? AND c.kind = 'credit_note' GROUP BY sl.credit_of_line_no",
            [$invoiceId],
        ) as $c) {
            $credits[(int) $c['line_no']] = ['units' => (int) $c['qty'], 'amount_e2' => InvoiceMath::e2((string) $c['amount'])];
        }
        $grnIds = [];
        $poIds = [];
        foreach ($lines as $l) {
            if ($l['grn_document_id'] !== null) {
                $grnIds[(int) $l['grn_document_id']] = true;
            }
            if ($l['po_document_id'] !== null) {
                $poIds[(int) $l['po_document_id']] = true;
            }
        }
        $grn = $this->receiptFacts(array_keys($grnIds), $invoiceId);
        foreach ($grn as $g) {
            if ($g['po_document_id'] !== null) {
                $poIds[$g['po_document_id']] = true;
            }
        }
        $po = $this->orderPrices(array_keys($poIds));

        $out = [];
        $used = [];
        $states = [];
        $off = 0;
        $difference = 0;
        $sum = 0;
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            $amount = InvoiceMath::e2((string) $l['amount']);
            $sum += $amount;
            if ($l['kind'] !== 'goods') {
                $out[$no] = ['line_no' => $no, 'kind' => (string) $l['kind'], 'state' => null, 'amount_e2' => $amount];
                continue;
            }
            $credited = $credits[$no] ?? ['units' => 0, 'amount_e2' => 0];
            $units = (int) $l['qty'] - $credited['units'];
            $billed = $amount - $credited['amount_e2'];
            $key = $l['grn_document_id'] === null ? null : $l['grn_document_id'] . ':' . $l['grn_line_no'];
            $g = $key === null ? null : ($grn[$key] ?? null);
            $reversed = $g !== null && $g['status'] !== 'posted';
            $available = null;
            if ($g !== null && !$reversed) {
                $available = $g['accepted'] - $g['returned'] - $g['invoiced_elsewhere'] - ($used[$key] ?? 0);
            } elseif ($key === null && $arriving && ($l['arrival_state'] === null || $l['arrival_state'] === 'open')) {
                $available = $units; // on its way (PJ8)
            } elseif ($key === null && $l['arrival_state'] === 'received') {
                $available = 0; // received without it: nothing came
            }
            if ($key !== null) {
                $used[$key] = ($used[$key] ?? 0) + $units;
            }
            $poKey = $l['po_document_id'] !== null ? $l['po_document_id'] . ':' . $l['po_line_no']
                : ($g !== null && $g['po_document_id'] !== null && $g['po_line_no'] !== null ? $g['po_document_id'] . ':' . $g['po_line_no'] : null);
            $ref = null;
            $refFrom = null;
            if ($poKey !== null && isset($po[$poKey])) {
                $ref = $po[$poKey];
                $refFrom = 'order';
            } elseif ($g !== null) {
                $ref = $g['unit_e6'];
                $refFrom = 'delivery';
            }
            $m = InvoiceMath::line(['units' => $units, 'amount_e2' => $billed, 'inv_unit_e6' => InvoiceMath::e6((string) $l['unit_cost']),
                'available' => $available, 'ref_unit_e6' => $ref, 'reversed' => $reversed], $rules);
            $states[] = $m['state'];
            if ($m['state'] === 'off') {
                $off++;
                $difference += max(0, $m['diff_e2']);
            }
            $out[$no] = ['line_no' => $no, 'kind' => 'goods', 'units' => $units, 'amount_e2' => $billed, 'credited_units' => $credited['units'],
                'credited_e2' => $credited['amount_e2'], 'available' => $available, 'accepted' => $g['accepted'] ?? null, 'returned' => $g['returned'] ?? null,
                'invoiced_elsewhere' => $g['invoiced_elsewhere'] ?? null, 'ref_unit_e6' => $ref, 'ref_from' => $refFrom, 'reversed' => $reversed,
                'arriving' => $key === null && $available !== null && $l['arrival_state'] !== 'received'] + $m;
        }
        $totalDiff = $inv === null || $inv['net_total'] === null ? 0 : $sum - InvoiceMath::e2((string) $inv['net_total']);
        if (!InvoiceMath::totalOk($sum, $sum - $totalDiff, count($lines), $rules['total_per_line_e2'])) {
            // Refused at posting (SupplierInvoiceHandler::validate); a draft's preview says so through the state.
            $states[] = 'off';
        }
        return ['state' => InvoiceMath::state($states, $totalDiff), 'lines_off' => $off, 'difference_e2' => $difference, 'total_diff_e2' => $totalDiff,
            'sum_e2' => $sum, 'rules' => $typed, 'lines' => $out];
    }

    /**
     * Works the stored match of these invoices out again (inside the caller's transaction): a posted invoice gets its state (kept
     * `accepted` while the match is what a person accepted), anything else (a draft, a cancelled or reversed invoice, a credit note)
     * none. Locks the supplier_invoice rows in id order (never their documents).
     *
     * @param list<int> $invoiceIds
     */
    public function refresh(array $invoiceIds, ?string $now = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
        sort($ids);
        if ($ids === []) {
            return;
        }
        if (!$this->db->inTransaction()) {
            throw new \LogicException('InvoiceMatch::refresh() runs inside the transaction that changed what the match reads');
        }
        $now ??= Clock::db(Clock::now());
        $typed = $this->typedRules();
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $rows = [];
            foreach ($this->db->all('SELECT document_id, kind, accepted_by, accepted_actor, accepted_at, accept_note, accepted_lines_off, accepted_difference, accepted_fingerprint '
                . "FROM supplier_invoice WHERE document_id IN ({$in}) ORDER BY document_id FOR UPDATE", $chunk) as $r) {
                $rows[(int) $r['document_id']] = $r;
            }
            $status = [];
            foreach ($this->db->all("SELECT id, status FROM document WHERE id IN ({$in})", $chunk) as $d) {
                $status[(int) $d['id']] = (string) $d['status'];
            }
            foreach ($rows as $id => $r) {
                if ($r['kind'] !== 'invoice' || ($status[$id] ?? '') !== 'posted') {
                    $this->db->exec('UPDATE supplier_invoice SET match_state = NULL, match_lines_off = NULL, match_difference = NULL, match_rules = NULL, '
                        . 'match_checked_at = NULL WHERE document_id = ? AND match_state IS NOT NULL', [$id]);
                    continue;
                }
                $m = $this->compute($id, $typed);
                $state = self::stored($m, $r);
                $this->db->exec('UPDATE supplier_invoice SET match_state = ?, match_lines_off = ?, match_difference = ?, match_rules = CAST(? AS JSON), match_checked_at = ?, '
                    . 'updated_at = ? WHERE document_id = ?', [$state, $m['lines_off'], InvoiceMath::fromE2($m['difference_e2']), Idempotency::json($typed), $now, $now, $id]);
                if ($r['accepted_at'] !== null && $state !== 'accepted') {
                    // The match is no longer what a person accepted (other lines off, other figures, or none off): the acceptance is
                    // dropped, so it can never cover a later difference nobody looked at (review finding 4). The audit row keeps it.
                    $this->db->exec('UPDATE supplier_invoice SET accepted_by = NULL, accepted_actor = NULL, accepted_at = NULL, accept_note = NULL, accepted_lines_off = NULL, '
                        . 'accepted_difference = NULL, accepted_fingerprint = NULL, updated_at = ? WHERE document_id = ?', [$now, $id]);
                    Audit::write($this->db, Caller::system('invoice_match'), 'invoice.accept_dropped', 'document', (string) $id, null, ['accepted_by' => $r['accepted_by'] === null
                        ? null : (int) $r['accepted_by'], 'accepted_actor' => $r['accepted_actor'], 'accepted_at' => $r['accepted_at'], 'note' => $r['accept_note'],
                        'accepted_lines_off' => (int) $r['accepted_lines_off'], 'accepted_difference' => $r['accepted_difference'], 'now' => $state,
                        'lines_off' => $m['lines_off'], 'difference' => InvoiceMath::fromE2($m['difference_e2'])]);
                }
            }
        }
    }

    /**
     * The state to store: the computed one, or `accepted` when it needs review and a person accepted exactly this: the same lines off
     * with the same figures (fingerprint(); review finding 4: the count and the money alone could match another difference).
     *
     * @param array{state: string, lines: array<int, array<string, mixed>>} $m
     * @param array<string, mixed> $row supplier_invoice (accepted_at, accepted_fingerprint)
     */
    public static function stored(array $m, array $row): string
    {
        if ($m['state'] === 'needs_review' && $row['accepted_at'] !== null && ($row['accepted_fingerprint'] ?? null) !== null
            && hash_equals((string) $row['accepted_fingerprint'], self::fingerprint($m))) {
            return 'accepted';
        }
        return $m['state'];
    }

    /**
     * What a person accepts when they accept an invoice's differences: every goods line outside the tolerance with its figures (the
     * units it bills, its money, the units there, the reference price, the extra units, the money above, why), as a sha256. Any change
     * of them, or another line going off, is another difference.
     *
     * @param array{lines: array<int, array<string, mixed>>} $m compute()
     */
    public static function fingerprint(array $m): string
    {
        $off = [];
        foreach ($m['lines'] as $no => $l) {
            if (($l['state'] ?? null) !== 'off') {
                continue;
            }
            $why = (array) ($l['why'] ?? []);
            sort($why);
            $off[] = [(int) $no, (int) $l['units'], (int) $l['amount_e2'], $l['available'] === null ? null : (int) $l['available'],
                $l['ref_unit_e6'] === null ? null : (int) $l['ref_unit_e6'], (int) ($l['excess'] ?? 0), (int) ($l['diff_e2'] ?? 0), (bool) $l['reversed'], $why];
        }
        return hash('sha256', Idempotency::canonicalJson($off));
    }

    /**
     * The posted invoices with a goods line on these deliveries (credit notes copy their invoice line's delivery, so the invoice they
     * credit is among them).
     *
     * @param list<int> $grnIds
     * @return list<int>
     */
    public function invoicesOnReceipts(array $grnIds): array
    {
        $grnIds = array_values(array_unique(array_map('intval', $grnIds)));
        if ($grnIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($grnIds, 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->column("SELECT DISTINCT COALESCE(si.credits_document_id, si.document_id) FROM sinv_line sl JOIN supplier_invoice si ON si.document_id = sl.document_id "
                . "JOIN document d ON d.id = sl.document_id AND d.status = 'posted' WHERE sl.kind = 'goods' AND sl.grn_document_id IN ({$in})", $chunk) as $id) {
                if ($id !== null) {
                    $out[(int) $id] = true;
                }
            }
            // Pack J (PJ8): the invoices whose goods a receipt took in from Arriving.
            foreach ($this->db->column("SELECT DISTINCT a.sinv_document_id FROM invoice_arrival a JOIN document d ON d.id = a.sinv_document_id AND d.status = 'posted' "
                . "WHERE a.state = 'received' AND a.grn_document_id IN ({$in})", $chunk) as $id) {
                $out[(int) $id] = true;
            }
        }
        $ids = array_keys($out);
        sort($ids);
        return $ids;
    }

    /**
     * Locks the `document` rows of these deliveries (FOR UPDATE, id order): what an invoice, a credit note or a return posting does
     * before it reads and changes what the deliveries have left to invoice (docs/decisions.md PC10).
     *
     * @param list<int> $grnIds
     * @return array<int, array{status: string, doc_type: string, number: ?string}>
     */
    public function lockReceipts(array $grnIds): array
    {
        $grnIds = array_values(array_unique(array_map('intval', $grnIds)));
        sort($grnIds);
        $out = [];
        foreach (array_chunk($grnIds, 500) as $chunk) {
            foreach ($this->db->all('SELECT id, status, doc_type, number FROM document WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') ORDER BY id FOR UPDATE',
                $chunk) as $r) {
                $out[(int) $r['id']] = ['status' => (string) $r['status'], 'doc_type' => (string) $r['doc_type'], 'number' => $r['number'] === null ? null : (string) $r['number']];
            }
        }
        return $out;
    }

    /**
     * Stores what each of these deliveries still has to be invoiced in grn_invoice_left (docs/decisions.md PC18), so "Not invoiced yet"
     * reads one row per delivery instead of adding every delivery line up. Called inside the transaction of every posting and reversal
     * of an invoice, a credit note or a return, with the deliveries it names locked (lockReceipts) and BEFORE any stock is booked (a
     * return books it last: lock order I21). A delivery that is no longer posted (reversed) is left alone: the list reads posted
     * deliveries only, and its row is never deleted. A delivery none of them has named has no row: everything it accepted is left.
     *
     * @param list<int> $grnIds
     */
    public function storeReceipts(array $grnIds, ?string $now = null): void
    {
        $grnIds = array_values(array_unique(array_map('intval', $grnIds)));
        if ($grnIds === []) {
            return;
        }
        if (!$this->db->inTransaction()) {
            throw new \LogicException('InvoiceMatch::storeReceipts() runs inside the transaction that changed what the deliveries have left');
        }
        $now ??= Clock::db(Clock::now());
        sort($grnIds);
        foreach (self::leftByReceipt($this->receiptFacts($grnIds)) as $grn => [$units, $valueE2]) {
            $this->db->exec('INSERT INTO grn_invoice_left (grn_document_id, units_left, value_left, updated_at) VALUES (?, ?, ?, ?) AS n '
                . 'ON DUPLICATE KEY UPDATE units_left = n.units_left, value_left = n.value_left, updated_at = n.updated_at',
                [$grn, $units, InvoiceMath::fromE2($valueE2), $now]);
        }
    }

    /**
     * What each POSTED delivery among receiptFacts() rows has left to invoice: per line, the units accepted less the accepted units
     * returned less those billed (at least 0), and their value at the line's provisional cost rounded to the penny
     * (as ROUND(units x unit_cost, 2)); added up per delivery.
     *
     * @param array<string, array{accepted: int, returned: int, invoiced_elsewhere: int, unit_e6: int, status: string}> $facts
     * @return array<int, array{0: int, 1: int}> delivery id => [units, value e2]
     */
    public static function leftByReceipt(array $facts): array
    {
        $out = [];
        foreach ($facts as $key => $f) {
            if ($f['status'] !== 'posted') {
                continue;
            }
            $grn = (int) strstr((string) $key, ':', true);
            $left = max(0, $f['accepted'] - $f['returned'] - $f['invoiced_elsewhere']);
            $out[$grn] ??= [0, 0];
            $out[$grn][0] += $left;
            $out[$grn][1] += intdiv($left * $f['unit_e6'] + 5_000, 10_000);
        }
        ksort($out);
        return $out;
    }

    /**
     * After a receipt's reversal (GoodsReceiptHandler::reverse, inside the reversal's transaction, the receipt's document row locked):
     * the invoices that bill it are worked out again (they now bill units that are not there).
     */
    public static function afterReceiptReversed(Db $db, int $grnId): void
    {
        $m = new self($db);
        $m->refresh($m->invoicesOnReceipts([$grnId]));
    }

    /**
     * What the delivery lines named have, per "grn:line": units accepted, returned (posted returns: `returned_all`, and `returned` the
     * part that left from where accepted units are, not VERIFY or UNSTAMPED), invoiced on the supplier's other
     * posted invoices net of their credit notes (not $invoiceId's, nor its credit notes'), the provisional unit cost (e6), the
     * receipt's status and its order line.
     *
     * @param list<int> $grnIds
     * @return array<string, array{accepted: int, returned: int, returned_all: int, exceptions: int, invoiced_elsewhere: int, unit_e6: int, status: string, po_document_id: ?int, po_line_no: ?int, sku_id: int}>
     */
    public function receiptFacts(array $grnIds, int $invoiceId = 0): array
    {
        if ($grnIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($grnIds, 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->all(
                'SELECT g.document_id, g.line_no, g.accepted_units, g.verify_units, g.quarantine_units, gd.status, dl.unit_cost, dl.sku_id, gr.po_document_id, g.po_line_no FROM grn_line g '
                . 'JOIN document gd ON gd.id = g.document_id JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no '
                . "JOIN goods_receipt gr ON gr.document_id = g.document_id WHERE g.document_id IN ({$in})",
                $chunk,
            ) as $r) {
                $out[$r['document_id'] . ':' . $r['line_no']] = ['accepted' => (int) ($r['accepted_units'] ?? 0), 'returned' => 0, 'returned_all' => 0,
                    'exceptions' => (int) ($r['verify_units'] ?? 0) + (int) ($r['quarantine_units'] ?? 0), 'invoiced_elsewhere' => 0,
                    'unit_e6' => $r['unit_cost'] === null ? 0 : InvoiceMath::e6((string) $r['unit_cost']), 'status' => (string) $r['status'],
                    'po_document_id' => $r['po_document_id'] === null ? null : (int) $r['po_document_id'], 'po_line_no' => $r['po_line_no'] === null ? null : (int) $r['po_line_no'],
                    'sku_id' => (int) $r['sku_id']];
            }
            // Units going back count against what the delivery line put where they leave from (PC14, review finding 3): from VERIFY
            // or UNSTAMPED, its exceptions (never accepted, never to be invoiced); from anywhere else, its accepted units (fewer to
            // invoice). `returned` is that accepted part.
            $exceptions = "'" . implode("', '", array_keys(self::EXCEPTION_WAREHOUSES)) . "'";
            foreach ($this->db->all(
                "SELECT dn.grn_document_id, dn.grn_line_no, SUM(dl.qty) AS units, SUM(IF(w.code IN ({$exceptions}), 0, dl.qty)) AS from_accepted FROM dn_line dn "
                . "JOIN document d ON d.id = dn.document_id AND d.status = 'posted' JOIN document_line dl ON dl.document_id = dn.document_id AND dl.line_no = dn.line_no "
                . "LEFT JOIN warehouse w ON w.id = COALESCE(dl.warehouse_id, d.warehouse_id) WHERE dn.grn_document_id IN ({$in}) GROUP BY dn.grn_document_id, dn.grn_line_no",
                $chunk,
            ) as $r) {
                $k = $r['grn_document_id'] . ':' . $r['grn_line_no'];
                if (isset($out[$k])) {
                    $out[$k]['returned_all'] = (int) $r['units'];
                    $out[$k]['returned'] = (int) $r['from_accepted'];
                }
            }
            foreach ($this->db->all(
                "SELECT sl.grn_document_id, sl.grn_line_no, SUM(IF(si.kind = 'invoice', dl.qty, -dl.qty)) AS units FROM sinv_line sl "
                . "JOIN supplier_invoice si ON si.document_id = sl.document_id JOIN document d ON d.id = sl.document_id AND d.status = 'posted' "
                . 'JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no '
                . "WHERE sl.grn_document_id IN ({$in}) AND sl.kind = 'goods' AND NOT (si.kind = 'invoice' AND si.document_id = ?) "
                . "AND NOT (si.kind = 'credit_note' AND si.credits_document_id <=> ?) GROUP BY sl.grn_document_id, sl.grn_line_no",
                [...$chunk, $invoiceId, $invoiceId],
            ) as $r) {
                $k = $r['grn_document_id'] . ':' . $r['grn_line_no'];
                if (isset($out[$k])) {
                    $out[$k]['invoiced_elsewhere'] = (int) $r['units'];
                }
            }
            // Pack J (PJ8): invoice lines a receipt took in from Arriving bill that receipt's line of their item (their own sinv_line names
            // no delivery: they were final before it), net of the credit notes on those lines.
            foreach ($this->db->all(
                'SELECT x.grn_document_id, x.grn_line_no, SUM(x.units) AS units FROM (SELECT a.grn_document_id, a.grn_line_no, dl.qty - COALESCE((SELECT SUM(cdl.qty) '
                . "FROM supplier_invoice c JOIN document cd ON cd.id = c.document_id AND cd.status = 'posted' JOIN sinv_line csl ON csl.document_id = c.document_id "
                . "AND csl.kind = 'goods' AND csl.credit_of_line_no = a.line_no JOIN document_line cdl ON cdl.document_id = csl.document_id AND cdl.line_no = csl.line_no "
                . "WHERE c.credits_document_id = a.sinv_document_id AND c.kind = 'credit_note'), 0) AS units FROM invoice_arrival a "
                . "JOIN document d ON d.id = a.sinv_document_id AND d.status = 'posted' JOIN document_line dl ON dl.document_id = a.sinv_document_id AND dl.line_no = a.line_no "
                . "WHERE a.state = 'received' AND a.grn_line_no IS NOT NULL AND a.grn_document_id IN ({$in}) AND a.sinv_document_id <> ?) x "
                . 'GROUP BY x.grn_document_id, x.grn_line_no',
                [...$chunk, $invoiceId],
            ) as $r) {
                $k = $r['grn_document_id'] . ':' . $r['grn_line_no'];
                if (isset($out[$k])) {
                    $out[$k]['invoiced_elsewhere'] += (int) $r['units'];
                }
            }
        }
        return $out;
    }

    /**
     * The unit price (e6) of these orders' item lines, per "po:line": pack price / units per pack (PoMath).
     *
     * @param list<int> $poIds
     * @return array<string, int>
     */
    public function orderPrices(array $poIds): array
    {
        if ($poIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($poIds, 500) as $chunk) {
            foreach ($this->db->all("SELECT document_id, line_no, pack_price, units_per_pack FROM po_line WHERE kind = 'item' AND document_id IN ("
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $out[$r['document_id'] . ':' . $r['line_no']] = PoMath::unitCostE6(PoMath::e4((string) $r['pack_price']), (int) $r['units_per_pack']);
            }
        }
        return $out;
    }
}
