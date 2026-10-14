<?php

declare(strict_types=1);

namespace CW\Invoices;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\DocumentHandler;
use CW\Documents\SizeApproval;
use CW\Idempotency;
use CW\Movements;
use CW\StockOps\CostHints;
use CW\StockOps\StockOpHandler;

/**
 * What a return to the supplier (DN, "supplier return / debit note") does when it is posted or reversed (pack C, plan IM7;
 * docs/decisions.md PC13-PC16). The return IS the debit note: what it claims back is its lines' amounts (the goods at the claimed price,
 * the duty paid on them: a retailer cannot reclaim duty on damaged stock, and claims without goods: short deliveries, overcharges).
 *
 *  validate()  problems(): every item line names a product, its units (more than 0), the warehouse it leaves (ours, switched on), a
 *              claimed price per unit and a reason offered for returns that takes stock down; amount = units x price (to the penny) +
 *              the duty claimed; a claim line has an amount and says what for; a delivery line it came with is a posted delivery of the
 *              same supplier and item, and never more units go back than that line booked in over every return; the invoice it is
 *              about is a posted invoice of the same supplier; never below zero for a protected product unless the reason allows it
 *              (StockOpHandler's rule).
 *  post()      the deliveries' document rows locked FOR UPDATE (InvoiceMatch::lockReceipts) and the rules checked again under them; the
 *              claim total; the write-once anchor; the invoices on those deliveries worked out again (returned units are no longer there
 *              to invoice: a return before the invoice lowers what waits to be invoiced, PC14); audit; then LAST the stock in ONE
 *              Movements::bookForDocument: a `supplier_return` per warehouse at the claimed price (evidence for IM8, C0 I1), and the
 *              below-zero rule again on the balances it now holds.
 *  reverse()   the matches worked out again; the stock comes back generically (Movements::reverseDocument). A cancellation that puts
 *              more stock back than the "stock put back" limit waits for a reviewer's OK first (I32).
 */
final class SupplierReturnHandler implements DocumentHandler, SizeApproval
{
    private const HEADER_FIXED = ['document_id', 'supplier_id', 'grn_document_id', 'invoice_document_id', 'claim_total'];
    private const LINE_FIXED = ['line_no', 'grn_document_id', 'grn_line_no', 'duty_amount'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    public function type(): string
    {
        return 'DN';
    }

    public function validate(Db $db, Document $doc, array $lines): void
    {
        $h = $db->one('SELECT * FROM supplier_return WHERE document_id = ?', [$doc->id])
            ?? throw new CwException('return_header_missing', "{$doc->label()} has no return header", 422);
        SupplierInvoiceHandler::refuse(self::problems($db, $doc, $h, $lines, false));
        // The stock rule last, as its own refusal (its detail names the product, what is free and what goes back: the screen's words).
        $short = self::shortfall($db, $doc, $lines, false);
        if ($short !== null) {
            throw $short;
        }
    }

    public function approvalUnits(Db $db, Document $doc, array $lines): int
    {
        return 0;
    }

    public function size(Db $db, Document $doc, array $lines): array
    {
        $units = 0;
        $claim = '0';
        foreach ($lines as $l) {
            $units += $l['sku_id'] === null ? 0 : abs((int) $l['qty']);
            $claim = bcadd($claim, (string) ($l['amount'] ?? '0'), 6);
        }
        return ['units' => $units, 'value' => CostHints::wholePounds($claim)];
    }

    public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int
    {
        $h = $db->one('SELECT * FROM supplier_return WHERE document_id = ? FOR UPDATE', [$doc->id])
            ?? throw new CwException('return_header_missing', "{$doc->label()} has no return header", 422);
        $match = new InvoiceMatch($db);
        $grns = self::receiptIds($db, $doc->id);
        $match->lockReceipts($grns);
        SupplierInvoiceHandler::refuse(self::problems($db, $doc, $h, $lines, false));
        $now = Clock::db(($this->clock)());
        $claim = 0;
        foreach ($lines as $l) {
            $claim += InvoiceMath::e2((string) $l['amount']);
        }
        $db->exec('UPDATE supplier_return SET claim_total = ?, updated_at = ? WHERE document_id = ?', [InvoiceMath::fromE2($claim), $now, $doc->id]);
        $content = self::content($db, $doc->id);
        $db->exec('INSERT INTO purchase_doc_posting (document_id, content_hash, content, created_at) VALUES (?, ?, ?, ?)', [$doc->id, hash('sha256', $content), $content, $now]);
        $match->refresh($match->invoicesOnReceipts($grns), $now);
        $match->storeReceipts($grns, $now);
        $codes = [];
        foreach ($db->all('SELECT id, code FROM warehouse') as $w) {
            $codes[(int) $w['id']] = (string) $w['code'];
        }
        $byWarehouse = [];
        $units = 0;
        foreach ($lines as $l) {
            if ($l['sku_id'] === null) {
                continue;
            }
            $wh = (int) ($l['warehouse_id'] ?? $doc->warehouseId);
            $byWarehouse[$codes[$wh]][] = ['document_line' => (int) $l['line_no'], 'sku_id' => (int) $l['sku_id'], 'qty' => (int) $l['qty'],
                'unit_cost' => (string) $l['unit_cost']];
            $units += (int) $l['qty'];
        }
        Audit::write($db, $caller, 'return.post', 'document', (string) $doc->id, $opKey . ':dn', ['number' => $doc->number, 'supplier' => (int) $h['supplier_id'],
            'claim' => InvoiceMath::fromE2($claim), 'units' => $units, 'lines' => count($lines), 'deliveries' => $grns]);
        // LAST: the stock, in one bookForDocument (one lock(), one flush(): I7).
        if ($byWarehouse !== []) {
            ksort($byWarehouse);
            $moves = [];
            foreach ($byWarehouse as $code => $ls) {
                $moves[] = ['type' => 'supplier_return', 'warehouse' => $code, 'note' => 'returned to the supplier', 'lines' => $ls];
            }
            (new Movements($db, null, $this->clock))->bookForDocument($caller, ['document_id' => $doc->id, 'doc_ref' => (string) $doc->number], $opKey, $moves);
            $short = self::shortfall($db, $doc, $lines, true);
            if ($short !== null) {
                throw $short;
            }
        }
        return $units;
    }

    public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void
    {
        $match = new InvoiceMatch($db);
        $grns = self::receiptIds($db, $original->id);
        $match->lockReceipts($grns);
        $now = Clock::db(($this->clock)());
        $match->refresh($match->invoicesOnReceipts($grns), $now);
        $match->storeReceipts($grns, $now);
        Audit::write($db, $caller, 'return.reverse', 'document', (string) $original->id, $opKey . ':dn', ['number' => $original->number,
            'reversed_by' => $reversal->number, 'reason' => $reversal->reasonCode]);
    }

    // ------------------------------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------------------------------

    /**
     * Everything that stops this return from being posted (code, message, line), in the order a person fixes them; $withStock: the
     * below-zero rule too (the draft's page; validate() and post() refuse with it on its own).
     *
     * @param array<string, mixed> $h supplier_return
     * @param list<array<string, mixed>> $lines Documents::lines() rows
     * @return list<array{code: string, message: string, line?: int}>
     */
    public static function problems(Db $db, Document $doc, array $h, array $lines, bool $withStock): array
    {
        $p = [];
        $add = static function (string $code, string $message, ?int $line = null) use (&$p): void {
            $p[] = ['code' => $code, 'message' => $message] + ($line === null ? [] : ['line' => $line]);
        };
        $supplier = (int) $h['supplier_id'];
        if ($h['invoice_document_id'] !== null) {
            $i = $db->one('SELECT d.status, d.doc_type, si.supplier_id FROM document d LEFT JOIN supplier_invoice si ON si.document_id = d.id WHERE d.id = ?',
                [(int) $h['invoice_document_id']]);
            if ($i === null || $i['doc_type'] !== 'SINV' || $i['status'] !== 'posted' || (int) $i['supplier_id'] !== $supplier) {
                $add('invoice_not_posted', 'The invoice this return is about must be a posted invoice of the same supplier.');
            }
        }
        $ext = [];
        foreach ($db->all('SELECT * FROM dn_line WHERE document_id = ? ORDER BY line_no', [$doc->id]) as $r) {
            $ext[(int) $r['line_no']] = $r;
        }
        $reasons = [];
        foreach ($db->all("SELECT code, label, CAST(direction AS CHAR) AS direction, below_zero FROM reason_code WHERE FIND_IN_SET('supplier_return', applies_to) > 0") as $r) {
            $reasons[(string) $r['code']] = $r;
        }
        $warehouses = [];
        foreach ($db->all('SELECT id, code, name, is_active, CAST(stock_owner AS CHAR) AS stock_owner FROM warehouse') as $w) {
            $warehouses[(int) $w['id']] = $w;
        }
        // The delivery the return is about (review finding 2): every item going back is one of its lines.
        $about = $h['grn_document_id'] === null ? null : (int) $h['grn_document_id'];
        $aboutNumber = $about === null ? null : (string) ($db->value('SELECT number FROM document WHERE id = ?', [$about]) ?? $about);
        $mine = [];
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            $e = $ext[$no] ?? null;
            if ($e === null) {
                $add('return_line_missing', "Line {$no} has no return details: save the lines again.", $no);
                continue;
            }
            $code = $l['reason_code'] ?? $doc->reasonCode;
            if ($code === null) {
                $add('reason_required', "Line {$no}: say why it goes back to the supplier.", $no);
            } elseif (!isset($reasons[$code])) {
                $add('reason_not_applicable', "Line {$no}: that reason is not offered for returns to the supplier.", $no);
            } elseif ($reasons[$code]['direction'] === 'increase') {
                $add('reason_direction', "Line {$no}: the reason {$reasons[$code]['label']} puts stock up, a return takes it down.", $no);
            }
            $amount = $l['amount'] === null ? null : InvoiceMath::e2((string) $l['amount']);
            $duty = InvoiceMath::e2((string) $e['duty_amount']);
            if ($l['sku_id'] === null) {
                if ($l['qty'] !== null || $amount === null || $amount <= 0 || $l['description'] === null || $duty !== 0 || $e['grn_document_id'] !== null) {
                    $add('bad_return_line', "Line {$no}: a claim without goods has an amount above £0 and says what it is for.", $no);
                }
                continue;
            }
            $qty = (int) ($l['qty'] ?? 0);
            if ($qty <= 0) {
                $add('bad_return_line', "Line {$no}: the units going back are a whole number above 0.", $no);
                continue;
            }
            $wh = $l['warehouse_id'] ?? $doc->warehouseId;
            $w = $wh === null ? null : ($warehouses[(int) $wh] ?? null);
            if ($w === null) {
                $add('warehouse_required', "Line {$no}: say which warehouse the units leave.", $no);
            } elseif ((int) $w['is_active'] !== 1) {
                $add('warehouse_inactive', "Line {$no}: {$w['name']} is switched off.", $no);
            } elseif ($w['stock_owner'] !== 'own') {
                $add('not_own_stock', "Line {$no}: {$w['name']} holds another account's stock: only our own stock goes back to our supplier.", $no);
            }
            if ($l['unit_cost'] === null) {
                $add('price_required', "Line {$no}: say the price per unit claimed back.", $no);
                continue;
            }
            if ($amount === null || $amount !== InvoiceMath::unitsAt($qty, InvoiceMath::e6((string) $l['unit_cost'])) + $duty) {
                $add('bad_return_line', "Line {$no}: the amount is the units x the price claimed, to the penny, plus the duty claimed.", $no);
            }
            if ($about !== null && (int) ($e['grn_document_id'] ?? 0) !== $about) {
                $add('line_not_on_delivery', "Line {$no}: this return is about {$aboutNumber}, and " . ($e['grn_document_id'] === null
                    ? 'this item is not on it' : 'this line names another delivery') . ': every item going back is one of its lines.', $no);
                continue;
            }
            if ($e['grn_document_id'] !== null) {
                // What goes back counts against what the delivery line put where it leaves from (PC14, review finding 3): VERIFY its
                // exceptions there, UNSTAMPED its quarantined units, anywhere else its accepted units.
                $column = InvoiceMatch::EXCEPTION_WAREHOUSES[(string) ($w['code'] ?? '')] ?? 'accepted_units';
                $k = $e['grn_document_id'] . ':' . $e['grn_line_no'] . ':' . $column;
                $mine[$k] = ($mine[$k] ?? 0) + $qty;
                $g = $db->one('SELECT d.status, d.doc_type, d.number, gr.supplier_id, dl.sku_id, g.accepted_units, g.verify_units, g.quarantine_units FROM document d '
                    . 'LEFT JOIN goods_receipt gr ON gr.document_id = d.id LEFT JOIN document_line dl ON dl.document_id = d.id AND dl.line_no = ? '
                    . 'LEFT JOIN grn_line g ON g.document_id = d.id AND g.line_no = ? WHERE d.id = ?', [(int) $e['grn_line_no'], (int) $e['grn_line_no'], (int) $e['grn_document_id']]);
                if ($g === null || $g['doc_type'] !== 'GRN' || $g['status'] !== 'posted') {
                    $add('delivery_not_posted', "Line {$no}: the delivery it came with is not a posted delivery.", $no);
                } elseif ((int) $g['supplier_id'] !== $supplier) {
                    $add('delivery_other_supplier', "Line {$no}: the delivery {$g['number']} is another supplier's.", $no);
                } elseif ($g['sku_id'] === null || (int) $g['sku_id'] !== (int) $l['sku_id']) {
                    $add('delivery_line_mismatch', "Line {$no}: line {$e['grn_line_no']} of {$g['number']} is another item.", $no);
                } else {
                    $booked = (int) $g[$column];
                    $before = self::returnedFrom($db, (int) $e['grn_document_id'], (int) $e['grn_line_no'], $column, $doc->id);
                    if ($before + $mine[$k] > $booked) {
                        $where = $column === 'accepted_units' ? 'into stock' : 'in ' . ($w['name'] ?? '');
                        $add('return_more_than_received', "Line {$no}: line {$e['grn_line_no']} of {$g['number']} put {$booked} units {$where}"
                            . ($before > 0 ? " and {$before} went back from there already" : '') . ': not ' . $mine[$k] . ' more from there.', $no);
                    }
                }
            }
        }
        if ($p === [] && $withStock) {
            $short = self::shortfall($db, $doc, $lines, false);
            if ($short !== null) {
                $add($short->errorCode, $short->getMessage(), $short->detail['line'] ?? null);
            }
        }
        return $p;
    }

    /**
     * The first (warehouse, item) this return takes where it may not go, as the refusal (null: none): a protected product (sell policy
     * strict or stopped, StockOpHandler::PROTECTED) may not end with less available than nothing, unless EVERY line taking it down has a
     * reason that allows it (reason_code.below_zero). $booked: the return's stock is on the balances already (post(), which holds them).
     *
     * @param list<array<string, mixed>> $lines
     */
    public static function shortfall(Db $db, Document $doc, array $lines, bool $booked): ?CwException
    {
        $down = [];
        foreach ($lines as $l) {
            if ($l['sku_id'] === null || (int) ($l['qty'] ?? 0) <= 0) {
                continue;
            }
            $key = (int) ($l['warehouse_id'] ?? $doc->warehouseId) . ':' . (int) $l['sku_id'];
            $code = $l['reason_code'] ?? $doc->reasonCode;
            $ok = $code !== null && (int) ($db->value('SELECT below_zero FROM reason_code WHERE code = ?', [$code]) ?? 0) === 1;
            $down[$key] ??= ['n' => 0, 'ok' => true, 'line' => (int) $l['line_no']];
            $down[$key]['n'] += (int) $l['qty'];
            $down[$key]['ok'] = $down[$key]['ok'] && $ok;
        }
        foreach ($down as $key => $d) {
            [$wh, $sku] = array_map('intval', explode(':', $key));
            $item = $db->one('SELECT code, CAST(sell_policy AS CHAR) AS sell_policy FROM sku WHERE id = ?', [$sku]);
            if ($item === null || !in_array($item['sell_policy'], StockOpHandler::PROTECTED, true) || $d['ok']) {
                continue;
            }
            $b = $db->one('SELECT on_hand, allocated, held FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [$wh, $sku]) ?? ['on_hand' => 0, 'allocated' => 0, 'held' => 0];
            $available = (int) $b['on_hand'] - ($booked ? 0 : $d['n']) - (int) $b['allocated'] - (int) $b['held'];
            if ($available < 0) {
                $have = $available + $d['n'];
                return new CwException('below_zero', "line {$d['line']}: {$item['code']} would go below zero ({$have} available, {$d['n']} going back)", 422,
                    ['line' => $d['line'], 'sku_code' => (string) $item['code'], 'have' => $have, 'wanted' => $d['n']]);
            }
        }
        return null;
    }

    /**
     * The units posted returns (other than $exceptDocumentId) took back from delivery line $grnId:$lineNo out of the part $column
     * counts (InvoiceMatch::EXCEPTION_WAREHOUSES: verify_units = from VERIFY, quarantine_units = from UNSTAMPED; accepted_units = from
     * anywhere else).
     */
    public static function returnedFrom(Db $db, int $grnId, int $lineNo, string $column, ?int $exceptDocumentId): int
    {
        $code = array_search($column, InvoiceMatch::EXCEPTION_WAREHOUSES, true);
        $codes = array_keys(InvoiceMatch::EXCEPTION_WAREHOUSES);
        [$where, $params] = $code !== false ? ['w.code = ?', [$code]]
            : ['(w.code IS NULL OR w.code NOT IN (' . implode(', ', array_fill(0, count($codes), '?')) . '))', $codes];
        return (int) $db->value("SELECT COALESCE(SUM(dl.qty), 0) FROM dn_line n JOIN document r ON r.id = n.document_id AND r.status = 'posted' "
            . 'JOIN document_line dl ON dl.document_id = n.document_id AND dl.line_no = n.line_no LEFT JOIN warehouse w ON w.id = COALESCE(dl.warehouse_id, r.warehouse_id) '
            . "WHERE n.grn_document_id = ? AND n.grn_line_no = ? AND n.document_id <> ? AND {$where}", [$grnId, $lineNo, $exceptDocumentId ?? 0, ...$params]);
    }

    /** @return list<int> the deliveries a return's lines (and header) name, in id order */
    public static function receiptIds(Db $db, int $documentId): array
    {
        $ids = [];
        foreach ($db->column('SELECT DISTINCT grn_document_id FROM dn_line WHERE document_id = ? AND grn_document_id IS NOT NULL', [$documentId]) as $g) {
            $ids[(int) $g] = true;
        }
        $out = array_keys($ids);
        sort($out);
        return $out;
    }

    /** The canonical JSON the posting anchor keeps: the header's fixed fields and every line extension (InvoiceInvariants V2). */
    public static function content(Db $db, int $documentId): string
    {
        $h = $db->one('SELECT * FROM supplier_return WHERE document_id = ?', [$documentId]) ?? throw new \LogicException("no supplier_return {$documentId}");
        $head = [];
        foreach (self::HEADER_FIXED as $k) {
            $head[$k] = $h[$k] === null ? null : (string) $h[$k];
        }
        $lines = [];
        foreach ($db->all('SELECT * FROM dn_line WHERE document_id = ? ORDER BY line_no', [$documentId]) as $r) {
            $row = [];
            foreach (self::LINE_FIXED as $k) {
                $row[$k] = $r[$k] === null ? null : (string) $r[$k];
            }
            $lines[] = $row;
        }
        return Idempotency::canonicalJson(['header' => $head, 'lines' => $lines]);
    }
}
