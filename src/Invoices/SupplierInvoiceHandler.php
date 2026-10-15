<?php

declare(strict_types=1);

namespace CW\Invoices;

use CW\Arrivals\ArrivalBooking;
use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\CancelHook;
use CW\Documents\Document;
use CW\Documents\DocumentHandler;
use CW\Documents\SizeApproval;
use CW\Idempotency;
use CW\Movements;
use CW\PurchaseOrders\PoMath;
use CW\Receiving\ReceiptMath;
use CW\Settings;
use CW\StockOps\CostHints;

/**
 * What a supplier invoice (SINV) and a supplier's credit note (SCN) do when they are posted or reversed (pack C, plan IM7;
 * docs/decisions.md PC1-PC12). Neither moves stock: a delivery's stock was booked by its receipt (IM6) at the provisional cost.
 *
 *  validate()       problems(): the invoice number and date, the totals as stated (VAT exactly as on the paper; the lines add up to
 *                   the total excl. VAT within the tolerance), every line's formulas (a goods line is packs x units per pack at a
 *                   pack price; a freight, duty, service or other line an amount), and every link: a delivery line of a posted receipt
 *                   of the same supplier and item, an order line of a posted order of the same supplier and item; a credit note's
 *                   invoice (posted, the same supplier), the invoice line each goods line credits (the same item and delivery, never
 *                   more units than it billed over every credit note), its debit note (a posted return of the same supplier).
 *  approvalUnits()  an invoice with the OK first for differences switched on (approval_rule over_difference, off by default, PC6): the
 *                   money its match charges above what was ordered and received, in whole pounds rounded up, when it needs review;
 *                   worked out with the deliveries locked (held through the posting).
 *  cancelled()      a draft stopped or an approval request refused (CancelHook): the supplier's number is free again.
 *  size()           the OK first for a big record (off by default): the goods units and the net amount in whole pounds.
 *  post()           the deliveries' document rows locked FOR UPDATE (InvoiceMatch::lockReceipts, id order; a credit note locks its
 *                   invoice's document row first) and the links checked again under the locks; the write-once anchor; the value-only
 *                   events (a price that differs from the delivery's provisional cost, freight and duty spread over the invoice's
 *                   delivered goods, a credit: invoice_value_event, waiting for the value engine, PC8); audit; and the stored match of
 *                   this invoice (a credit note: of the invoice it credits) and of every invoice on the same deliveries worked out
 *                   again (InvoiceMatch::refresh).
 *  reverse()        an invoice with a posted credit note is not reversed (409 has_credit_notes: reverse the credit note first); the
 *                   invoice number is free again; the value events negated under the reversal; the matches worked out again.
 */
final class SupplierInvoiceHandler implements DocumentHandler, SizeApproval, CancelHook
{
    /** document type => supplier_invoice.kind */
    public const KINDS = ['SINV' => 'invoice', 'SCN' => 'credit_note'];
    public const LINE_KINDS = ['goods', 'freight', 'duty', 'service', 'other'];
    /** The fixed fields of the header and the lines the posting anchor keeps (purchase_doc_posting). */
    private const HEADER_FIXED = ['document_id', 'kind', 'supplier_id', 'net_total', 'vat_total', 'gross_total', 'credits_document_id', 'debit_note_document_id'];
    private const LINE_FIXED = ['line_no', 'kind', 'grn_document_id', 'grn_line_no', 'po_document_id', 'po_line_no', 'credit_of_line_no', 'supplier_code',
        'purchase_unit', 'units_per_pack', 'packs', 'pack_price', 'entry'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly string $docType, ?\Closure $clock = null)
    {
        if (!isset(self::KINDS[$docType])) {
            throw new \InvalidArgumentException("not a supplier invoice type: {$docType}");
        }
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /** @return array<string, self> type => handler (DocumentHandlers::all) */
    public static function all(Db $db, ?\Closure $clock = null): array
    {
        return ['SINV' => new self($db, 'SINV', $clock), 'SCN' => new self($db, 'SCN', $clock)];
    }

    public function type(): string
    {
        return $this->docType;
    }

    public function validate(Db $db, Document $doc, array $lines): void
    {
        $h = self::header($db, $doc->id) ?? throw new CwException('invoice_header_missing', "{$doc->label()} has no supplier invoice header", 422);
        self::refuse(self::problems($db, $doc, $h, $lines, $this->today()));
    }

    public function approvalUnits(Db $db, Document $doc, array $lines): int
    {
        if ($this->docType !== 'SINV') {
            return 0;
        }
        // Decided with the deliveries locked (review finding 1): the locks post() takes, held until this posting ends, so another
        // invoice's posting on them cannot slip between this decision and this posting (PC10).
        $match = new InvoiceMatch($db);
        $match->lockReceipts(self::receiptIds($db, $doc->id));
        $m = $match->compute($doc->id);
        return $m['state'] === 'needs_review' ? intdiv($m['difference_e2'] + 99, 100) : 0;
    }

    /**
     * A draft stopped, or an approval request refused (review finding 5): the supplier's number is free again. A reversal's request
     * has no header of its own: nothing to do.
     */
    public function cancelled(Db $db, Document $doc, Caller $caller): void
    {
        if ($doc->isReversal()) {
            return;
        }
        $db->exec('UPDATE supplier_invoice SET invoice_key = NULL, updated_at = ? WHERE document_id = ? AND invoice_key IS NOT NULL', [Clock::db(($this->clock)()), $doc->id]);
        // Pack J (PJ10): a stopped or refused draft keeps no time to be made final.
        $db->exec('UPDATE supplier_invoice SET submit_at = NULL, submit_by = NULL, submit_actor = NULL, submit_failed_at = NULL, submit_error = NULL '
            . 'WHERE document_id = ? AND submit_at IS NOT NULL', [$doc->id]);
    }

    public function size(Db $db, Document $doc, array $lines): array
    {
        $units = 0;
        $net = '0';
        foreach ($lines as $l) {
            if ($l['sku_id'] !== null && $l['qty'] !== null) {
                $units += abs((int) $l['qty']);
            }
            if ($l['amount'] !== null) {
                $net = bcadd($net, (string) $l['amount'], 6);
            }
        }
        return ['units' => $units, 'value' => CostHints::wholePounds(bccomp($net, '0', 6) < 0 ? '0' : $net)];
    }

    public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int
    {
        // Lock order (PC10): the invoice a credit note credits, the deliveries, then the supplier_invoice rows (this one first). The
        // header is read before its lock only for the invoice it credits, which a draft's creator alone changes (and the document row
        // this posting holds keeps them out).
        $credited = $db->value('SELECT credits_document_id FROM supplier_invoice WHERE document_id = ?', [$doc->id]);
        $credited = $credited === null ? null : (int) $credited;
        if ($credited !== null) {
            $db->one('SELECT id FROM document WHERE id = ? FOR UPDATE', [$credited]);
        }
        $match = new InvoiceMatch($db);
        $match->lockReceipts(self::receiptIds($db, $doc->id, $credited));
        $h = $db->one('SELECT * FROM supplier_invoice WHERE document_id = ? FOR UPDATE', [$doc->id])
            ?? throw new CwException('invoice_header_missing', "{$doc->label()} has no supplier invoice header", 422);
        // Again under the locks: a receipt reversed, or another credit note posted, since validate() read them.
        self::refuse(self::problems($db, $doc, $h, $lines, $this->today()));
        if ($h['receive_mode'] === 'received' && self::notReceived($db, $doc->id) !== []) {
            // Pack J (PJ9): "book as received" books its receipt first (SupplierInvoices::submit); a posting that skips it is refused.
            throw new CwException('receive_first', "{$doc->label()} books its goods as received: make it final with its own button (it books the receipt first)", 409);
        }
        $now = Clock::db(($this->clock)());
        $content = self::content($db, $doc->id);
        $db->exec('INSERT INTO purchase_doc_posting (document_id, content_hash, content, created_at) VALUES (?, ?, ?, ?)', [$doc->id, hash('sha256', $content), $content, $now]);
        $events = $this->valueEvents($db, $doc, $h, $lines);
        foreach ($events as $e) {
            self::insertEvent($db, $doc->id, $e, (string) $doc->docDate, $now);
        }
        $units = 0;
        foreach ($lines as $l) {
            $units += $l['sku_id'] === null ? 0 : abs((int) $l['qty']);
        }
        $grns = self::receiptIds($db, $doc->id, $credited);
        $refresh = $match->invoicesOnReceipts($grns);
        $refresh[] = $credited ?? $doc->id;
        $match->refresh($refresh, $now);
        $match->storeReceipts($grns, $now);
        $state = $this->docType === 'SINV' ? $db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$doc->id]) : null;
        // Pack J (PJ2, PJ7): "count on arrival": the goods no delivery names are on their way (invoice_arrival), and the timed posting's
        // time is used up. FK rows first, the stock LAST (I21).
        $incoming = $this->docType === 'SINV' ? ArrivalBooking::invoicePosted($db, $doc, $lines, $now) : [];
        $db->exec('UPDATE supplier_invoice SET submit_at = NULL, submit_by = NULL, submit_actor = NULL, submit_failed_at = NULL, submit_error = NULL '
            . 'WHERE document_id = ? AND submit_at IS NOT NULL', [$doc->id]);
        Audit::write($db, $caller, $this->docType === 'SINV' ? 'invoice.post' : 'credit_note.post', 'document', (string) $doc->id, $opKey . ':inv',
            ['number' => $doc->number, 'supplier_invoice' => $doc->externalRef, 'supplier' => (int) $h['supplier_id'], 'net' => $h['net_total'], 'vat' => $h['vat_total'],
                'gross' => $h['gross_total'], 'lines' => count($lines), 'value_events' => count($events)]
            + ($state === null ? [] : ['match' => $state]) + ($credited === null ? [] : ['credits' => $credited])
            + ($h['receive_mode'] === null ? [] : ['receive_mode' => $h['receive_mode'], 'incoming_units' => array_sum(array_column($incoming, 'qty'))]));
        if ($incoming !== []) {
            (new Movements($db, null, $this->clock))->bookForDocument($caller, ['document_id' => $doc->id, 'doc_ref' => (string) $doc->number], $opKey,
                [['type' => 'incoming', 'warehouse' => 'MAIN', 'note' => 'on its way: ' . ($doc->externalRef ?? ''), 'lines' => $incoming]]);
        }
        return $units;
    }

    /**
     * Pack J: the goods lines of an invoice no delivery names and no arrival row holds (what "book as received" still has to receive).
     *
     * @return list<int> line numbers
     */
    public static function notReceived(Db $db, int $documentId): array
    {
        return array_map('intval', $db->column("SELECT sl.line_no FROM sinv_line sl LEFT JOIN invoice_arrival a ON a.sinv_document_id = sl.document_id AND a.line_no = sl.line_no "
            . "WHERE sl.document_id = ? AND sl.kind = 'goods' AND sl.grn_document_id IS NULL AND a.line_no IS NULL ORDER BY sl.line_no", [$documentId]));
    }

    public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void
    {
        $h = $db->one('SELECT * FROM supplier_invoice WHERE document_id = ?', [$original->id])
            ?? throw new CwException('invoice_header_missing', "{$original->label()} has no supplier invoice header", 422);
        if ($this->docType === 'SINV') {
            $cn = $db->value("SELECT d.number FROM supplier_invoice c JOIN document d ON d.id = c.document_id AND d.status = 'posted' WHERE c.credits_document_id = ? "
                . "AND c.kind = 'credit_note' ORDER BY d.id LIMIT 1", [$original->id]);
            if ($cn !== null) {
                throw new CwException('has_credit_notes', "{$original->number} has the credit note {$cn}: cancel the credit note first, then the invoice", 409,
                    ['credit_note' => (string) $cn]);
            }
        }
        $credited = $h['credits_document_id'] === null ? null : (int) $h['credits_document_id'];
        $match = new InvoiceMatch($db);
        $grns = self::receiptIds($db, $original->id, $credited);
        // The deliveries before any supplier_invoice row (PC10: a receipt's reversal holds its document row and then takes those rows).
        $match->lockReceipts($grns);
        $now = Clock::db(($this->clock)());
        // Pack J (PJ4): refused once a receipt took its goods in; else what is still on its way is cancelled (the generic negation takes
        // the units out of Incoming afterwards).
        ArrivalBooking::invoiceReversed($db, $original, $now);
        $db->exec('UPDATE supplier_invoice SET invoice_key = NULL, updated_at = ? WHERE document_id = ?', [$now, $original->id]);
        $n = $db->exec('INSERT INTO invoice_value_event (document_id, line_no, kind, sku_id, grn_document_id, grn_line_no, units, amount, basis, effective_on, created_at) '
            . 'SELECT ?, line_no, kind, sku_id, grn_document_id, grn_line_no, units, -amount, basis, effective_on, ? FROM invoice_value_event WHERE document_id = ? ORDER BY id',
            [$reversal->id, $now, $original->id]);
        $refresh = $match->invoicesOnReceipts($grns);
        $refresh[] = $original->id;
        if ($credited !== null) {
            $refresh[] = $credited;
        }
        $match->refresh($refresh, $now);
        $match->storeReceipts($grns, $now);
        Audit::write($db, $caller, $this->docType === 'SINV' ? 'invoice.reverse' : 'credit_note.reverse', 'document', (string) $original->id, $opKey . ':inv',
            ['number' => $original->number, 'reversed_by' => $reversal->number, 'reason' => $reversal->reasonCode, 'value_events_negated' => $n]);
    }

    // ------------------------------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------------------------------

    /**
     * Everything that stops this record from being posted, in the order a person fixes them (code, message, line when it is one line's).
     * The service's page shows them on a draft; validate() refuses with the first.
     *
     * @param array<string, mixed> $h the supplier_invoice row
     * @param list<array<string, mixed>> $lines Documents::lines() rows
     * @return list<array{code: string, message: string, line?: int}>
     */
    public static function problems(Db $db, Document $doc, array $h, array $lines, string $today): array
    {
        $p = [];
        $add = static function (string $code, string $message, ?int $line = null) use (&$p): void {
            $p[] = ['code' => $code, 'message' => $message] + ($line === null ? [] : ['line' => $line]);
        };
        $credit = $h['kind'] === 'credit_note';
        $what = $credit ? 'credit note' : 'invoice';
        if ($doc->externalRef === null || ReceiptMath::invoiceKey($doc->externalRef) === '') {
            $add('invoice_number_required', "Type the supplier's {$what} number: it is compulsory, and a supplier's {$what} is booked once.");
        }
        if ($doc->docDate === null) {
            $add('invoice_date_required', "Type the date on the supplier's {$what}.");
        } elseif ($doc->docDate > $today) {
            $add('invoice_date_future', "The date on the {$what} is after today: check it.");
        }
        if ($h['net_total'] === null || $h['vat_total'] === null) {
            $add('totals_required', "Type the {$what}'s total without VAT and its VAT, exactly as the paper says them.");
        }
        $ext = [];
        foreach ($db->all('SELECT * FROM sinv_line WHERE document_id = ? ORDER BY line_no', [$doc->id]) as $r) {
            $ext[(int) $r['line_no']] = $r;
        }
        $sum = 0;
        $supplier = (int) $h['supplier_id'];
        $credited = $h['credits_document_id'] === null ? null : (int) $h['credits_document_id'];
        if ($credit && $credited !== null) {
            $inv = $db->one('SELECT d.status, d.doc_type, d.number, si.supplier_id FROM document d LEFT JOIN supplier_invoice si ON si.document_id = d.id WHERE d.id = ?', [$credited]);
            if ($inv === null || $inv['doc_type'] !== 'SINV' || $inv['status'] !== 'posted' || (int) $inv['supplier_id'] !== $supplier) {
                $add('credited_invoice_not_posted', 'The invoice this credit note reduces must be a posted invoice of the same supplier.');
            }
        }
        if ($credit && $h['debit_note_document_id'] !== null) {
            $dn = $db->one('SELECT d.status, d.doc_type, r.supplier_id FROM document d LEFT JOIN supplier_return r ON r.document_id = d.id WHERE d.id = ?',
                [(int) $h['debit_note_document_id']]);
            if ($dn === null || $dn['doc_type'] !== 'DN' || $dn['status'] !== 'posted' || (int) $dn['supplier_id'] !== $supplier) {
                $add('debit_note_not_posted', 'The return this credit note answers must be a posted return to the same supplier.');
            }
        }
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            $e = $ext[$no] ?? null;
            if ($e === null) {
                $add('invoice_line_missing', "Line {$no} has no invoice details: save the lines again.", $no);
                continue;
            }
            $amount = $l['amount'] === null ? null : InvoiceMath::e2((string) $l['amount']);
            $sum += $amount ?? 0;
            if ($e['kind'] === 'goods') {
                $bad = self::goodsFormula($l, $e);
                if ($bad !== null) {
                    $add('bad_invoice_line', "Line {$no}: {$bad}.", $no);
                    continue;
                }
                foreach (self::linkProblems($db, $l, $e, $supplier, $credit, $credited, $doc->id) as [$code, $message]) {
                    $add($code, "Line {$no}: {$message}", $no);
                }
            } else {
                if ($l['sku_id'] !== null || $l['qty'] !== null || $amount === null) {
                    $add('bad_invoice_line', "Line {$no}: a {$e['kind']} line has an amount and no item.", $no);
                } elseif ($amount === 0 || ($amount < 0 && ($credit || $e['kind'] !== 'other'))) {
                    $add('bad_invoice_line', "Line {$no}: the amount is more than £0" . ($credit ? '' : ' (only an "other" line, a discount, may be below £0)') . '.', $no);
                }
            }
        }
        if ($h['net_total'] !== null && $lines !== []) {
            $stated = InvoiceMath::e2((string) $h['net_total']);
            $rules = InvoiceMath::rules((new InvoiceMatch($db))->typedRules());
            if (!InvoiceMath::totalOk($sum, $stated, count($lines), $rules['total_per_line_e2'])) {
                $add('total_mismatch', 'The lines add up to ' . PoMath::money($sum) . " but the {$what} says " . PoMath::money($stated)
                    . ' without VAT: check the lines and the total.');
            }
        }
        if ($sum < 0) {
            $add('total_negative', "The {$what}'s lines add up to less than £0.");
        }
        if (!$credit && ($h['receive_mode'] ?? null) === 'received' && $doc->status === 'draft') {
            // Pack J (PJ9): "book as received" books a receipt of the goods no delivery names: the duty stamp is asked here (the bench is
            // skipped), and the receipt keeps the invoice's copy, as every receipt does.
            $open = [];
            foreach ($lines as $l) {
                $e = $ext[(int) $l['line_no']] ?? null;
                if ($e !== null && $e['kind'] === 'goods' && $e['grn_document_id'] === null && $l['sku_id'] !== null) {
                    $open[(int) $l['line_no']] = (int) $l['sku_id'];
                }
            }
            if ($open !== []) {
                $comp = (new \CW\Catalogue\ItemCompliance($db))->receiving(array_values(array_unique($open)));
                foreach ($open as $no => $sku) {
                    if (($comp[$sku]['stamp_required'] ?? true) && ($ext[$no]['stamp_answer'] ?? null) === null) {
                        $add('stamp_answer_required', "Line {$no}: say which duty stamp is on the pack (digital or transitional): booked as received, nobody checks it at the bench.", $no);
                    }
                }
                if ($db->value("SELECT 1 FROM document_file WHERE document_id = ? AND role = 'supplier_invoice' LIMIT 1", [$doc->id]) === null) {
                    $add('invoice_copy_required', 'Attach the supplier\'s invoice (a PDF or a photo): booked as received, its receipt keeps the copy.');
                }
            }
        }
        return $p;
    }

    /** A goods line against its formulas (null: fine): qty = packs x units per pack > 0, unit cost and amount from PoMath. */
    private static function goodsFormula(array $l, array $e): ?string
    {
        if ($l['sku_id'] === null) {
            return 'a goods line names an item';
        }
        $packs = (int) $e['packs'];
        $upp = (int) $e['units_per_pack'];
        if ((int) ($l['qty'] ?? 0) <= 0 || (int) $l['qty'] !== $packs * $upp) {
            return 'the units are packs x units per pack, more than 0';
        }
        $priceE4 = PoMath::e4((string) $e['pack_price']);
        if ($l['unit_cost'] === null || PoMath::e6((string) $l['unit_cost']) !== PoMath::unitCostE6($priceE4, $upp)) {
            return 'the unit price is the pack price / units per pack';
        }
        if ($l['amount'] === null || InvoiceMath::e2((string) $l['amount']) !== PoMath::lineAmountE2($packs, $priceE4)) {
            return 'the amount is packs x the pack price';
        }
        return null;
    }

    /**
     * The problems of a goods line's links: its delivery line, its order line, and on a credit note the invoice line it credits.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function linkProblems(Db $db, array $l, array $e, int $supplier, bool $credit, ?int $credited, int $self): array
    {
        $out = [];
        $sku = (int) $l['sku_id'];
        // A credit note's line keeps its invoice line's delivery and order (checked against that line below): a delivery cancelled since
        // is exactly what a supplier may credit, so its status is not asked again.
        if ($e['grn_document_id'] !== null && !$credit) {
            $g = $db->one('SELECT d.status, d.doc_type, d.number, gr.supplier_id, dl.sku_id FROM document d LEFT JOIN goods_receipt gr ON gr.document_id = d.id '
                . 'LEFT JOIN document_line dl ON dl.document_id = d.id AND dl.line_no = ? LEFT JOIN grn_line g ON g.document_id = d.id AND g.line_no = ? WHERE d.id = ?',
                [(int) $e['grn_line_no'], (int) $e['grn_line_no'], (int) $e['grn_document_id']]);
            if ($g === null || $g['doc_type'] !== 'GRN' || $g['status'] !== 'posted') {
                $out[] = ['delivery_not_posted', 'the delivery it bills is not a posted delivery (cancelled since?): take it off or link another.'];
            } elseif ((int) $g['supplier_id'] !== $supplier) {
                $out[] = ['delivery_other_supplier', "the delivery {$g['number']} is another supplier's."];
            } elseif ($g['sku_id'] === null || (int) $g['sku_id'] !== $sku) {
                $out[] = ['delivery_line_mismatch', "line {$e['grn_line_no']} of {$g['number']} is another item."];
            }
        }
        if ($e['po_document_id'] !== null && !$credit) {
            $o = $db->one('SELECT d.status, d.doc_type, d.number, p.supplier_id, dl.sku_id, pl.kind FROM document d LEFT JOIN purchase_order p ON p.document_id = d.id '
                . 'LEFT JOIN document_line dl ON dl.document_id = d.id AND dl.line_no = ? LEFT JOIN po_line pl ON pl.document_id = d.id AND pl.line_no = ? WHERE d.id = ?',
                [(int) $e['po_line_no'], (int) $e['po_line_no'], (int) $e['po_document_id']]);
            if ($o === null || $o['doc_type'] !== 'PO' || $o['status'] !== 'posted') {
                $out[] = ['order_not_posted', 'the order it bills is not a confirmed order.'];
            } elseif ((int) $o['supplier_id'] !== $supplier) {
                $out[] = ['order_other_supplier', "the order {$o['number']} is another supplier's."];
            } elseif ($o['kind'] !== 'item' || $o['sku_id'] === null || (int) $o['sku_id'] !== $sku) {
                $out[] = ['order_line_mismatch', "line {$e['po_line_no']} of {$o['number']} is another item."];
            }
        }
        if (!$credit) {
            if ($e['credit_of_line_no'] !== null) {
                $out[] = ['bad_invoice_line', 'an invoice line credits nothing.'];
            }
            return $out;
        }
        if ($e['credit_of_line_no'] === null) {
            // Goods credited are always a line of the invoice the credit note reduces; anything else is an "other" line.
            return [...$out, ['credit_line_required', 'a goods line of a credit note credits a line of the invoice it reduces: use an "other" line for anything else.']];
        }
        if ($credited === null) {
            return [...$out, ['credited_invoice_required', 'a line that credits an invoice line needs the invoice the credit note reduces.']];
        }
        $of = (int) $e['credit_of_line_no'];
        $il = $db->one('SELECT dl.sku_id, dl.qty, sl.kind, sl.grn_document_id, sl.grn_line_no FROM document_line dl JOIN sinv_line sl ON sl.document_id = dl.document_id '
            . 'AND sl.line_no = dl.line_no WHERE dl.document_id = ? AND dl.line_no = ?', [$credited, $of]);
        if ($il === null || $il['kind'] !== 'goods' || (int) $il['sku_id'] !== $sku) {
            return [...$out, ['credit_line_mismatch', "line {$of} of the invoice is not this item's goods line."]];
        }
        if (($il['grn_document_id'] === null ? null : $il['grn_document_id'] . ':' . $il['grn_line_no'])
            !== ($e['grn_document_id'] === null ? null : $e['grn_document_id'] . ':' . $e['grn_line_no'])) {
            $out[] = ['credit_line_mismatch', "the delivery is not the one line {$of} of the invoice bills."];
        }
        $before = (int) $db->value(
            'SELECT COALESCE(SUM(dl.qty), 0) FROM supplier_invoice c JOIN document d ON d.id = c.document_id AND d.status = \'posted\' '
            . "JOIN sinv_line sl ON sl.document_id = c.document_id AND sl.kind = 'goods' AND sl.credit_of_line_no = ? JOIN document_line dl ON dl.document_id = sl.document_id "
            . "AND dl.line_no = sl.line_no WHERE c.credits_document_id = ? AND c.kind = 'credit_note' AND c.document_id <> ?",
            [$of, $credited, $self]);
        $mine = (int) $db->value('SELECT COALESCE(SUM(dl.qty), 0) FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no '
            . "WHERE sl.document_id = ? AND sl.kind = 'goods' AND sl.credit_of_line_no = ?", [$self, $of]);
        if ($before + $mine > (int) $il['qty']) {
            $out[] = ['credit_more_than_invoiced', "credits {$mine} units of line {$of}, which billed " . (int) $il['qty'] . ($before > 0 ? " and has {$before} credited already" : '') . '.'];
        }
        return $out;
    }

    /**
     * The value-only events of a posting (PC8): an invoice's goods line on a delivery whose amount differs from the delivery's units at
     * its provisional cost (price_difference); its freight and duty lines spread over its delivered goods lines (by value, or by ml of
     * liquid: invoices.freight_spread / invoices.duty_spread); a credit note's goods line on a delivery (credit, the amount taken off).
     *
     * @param array<string, mixed> $h
     * @param list<array<string, mixed>> $lines
     * @return list<array{line_no: int, kind: string, sku_id: int, grn_document_id: ?int, grn_line_no: ?int, units: int, amount_e2: int, basis: string}>
     */
    private function valueEvents(Db $db, Document $doc, array $h, array $lines): array
    {
        $ext = [];
        foreach ($db->all('SELECT * FROM sinv_line WHERE document_id = ?', [$doc->id]) as $r) {
            $ext[(int) $r['line_no']] = $r;
        }
        $grnCost = [];
        $keys = [];
        foreach ($ext as $e) {
            if ($e['grn_document_id'] !== null) {
                $keys[] = [(int) $e['grn_document_id'], (int) $e['grn_line_no']];
            }
        }
        foreach ($keys as [$g, $n]) {
            $grnCost[$g . ':' . $n] ??= InvoiceMath::e6((string) ($db->value('SELECT unit_cost FROM document_line WHERE document_id = ? AND line_no = ?', [$g, $n]) ?? '0'));
        }
        $out = [];
        $goods = [];
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            $e = $ext[$no] ?? null;
            if ($e === null || $e['kind'] !== 'goods' || $e['grn_document_id'] === null) {
                continue;
            }
            $amount = InvoiceMath::e2((string) $l['amount']);
            $base = ['line_no' => $no, 'sku_id' => (int) $l['sku_id'], 'grn_document_id' => (int) $e['grn_document_id'], 'grn_line_no' => (int) $e['grn_line_no'],
                'units' => (int) $l['qty']];
            if ($this->docType === 'SCN') {
                $out[] = $base + ['kind' => 'credit', 'amount_e2' => -$amount, 'basis' => 'line'];
                continue;
            }
            $goods[$no] = $base + ['amount' => $amount];
            $diff = $amount - InvoiceMath::unitsAt((int) $l['qty'], $grnCost[$e['grn_document_id'] . ':' . $e['grn_line_no']]);
            if ($diff !== 0) {
                $out[] = $base + ['kind' => 'price_difference', 'amount_e2' => $diff, 'basis' => 'delivery_cost'];
            }
        }
        if ($this->docType !== 'SINV' || $goods === []) {
            return $out;
        }
        $settings = new Settings($db);
        $ml = null;
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            $kind = $ext[$no]['kind'] ?? null;
            if ($kind !== 'freight' && $kind !== 'duty') {
                continue;
            }
            $key = $kind === 'freight' ? 'invoices.freight_spread' : 'invoices.duty_spread';
            $by = $settings->has($key) ? (string) $settings->get($key) : ($kind === 'freight' ? 'value' : 'ml');
            $weights = [];
            if ($by === 'ml') {
                $ml ??= self::mlOf($db, array_values(array_unique(array_column($goods, 'sku_id'))));
                foreach ($goods as $g => $x) {
                    $weights[$g] = $x['units'] * ($ml[$x['sku_id']] ?? 0);
                }
                if (array_sum($weights) <= 0) {
                    $by = 'value'; // no liquid known: by value
                }
            }
            if ($by !== 'ml') {
                $weights = array_map(static fn (array $x): int => max(0, $x['amount']), $goods);
            }
            foreach (InvoiceMath::spread(InvoiceMath::e2((string) $l['amount']), $weights) as $g => $share) {
                if ($share === 0) {
                    continue;
                }
                $x = $goods[$g];
                $out[] = ['line_no' => $no, 'kind' => $kind, 'sku_id' => $x['sku_id'], 'grn_document_id' => $x['grn_document_id'], 'grn_line_no' => $x['grn_line_no'],
                    'units' => $x['units'], 'amount_e2' => $share, 'basis' => $by];
            }
        }
        return $out;
    }

    /**
     * ml of liquid per unit (x 10: tenths of a ml) of these items' cards (0 when not known).
     *
     * @param list<int> $skus
     * @return array<int, int>
     */
    private static function mlOf(Db $db, array $skus): array
    {
        $out = [];
        foreach (array_chunk($skus, 500) as $chunk) {
            foreach ($db->all('SELECT sku_id, liquid_ml FROM item_card WHERE sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $out[(int) $r['sku_id']] = $r['liquid_ml'] === null ? 0 : (int) round((float) $r['liquid_ml'] * 10);
            }
        }
        return $out;
    }

    /** @param array{line_no: int, kind: string, sku_id: int, grn_document_id: ?int, grn_line_no: ?int, units: int, amount_e2: int, basis: string} $e */
    private static function insertEvent(Db $db, int $documentId, array $e, string $on, string $now): void
    {
        $db->exec('INSERT INTO invoice_value_event (document_id, line_no, kind, sku_id, grn_document_id, grn_line_no, units, amount, basis, effective_on, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$documentId, $e['line_no'], $e['kind'], $e['sku_id'], $e['grn_document_id'], $e['grn_line_no'], $e['units'], InvoiceMath::fromE2($e['amount_e2']), $e['basis'],
                $on, $now]);
    }

    /**
     * The deliveries a posting reads and changes: the receipts this record's goods lines bill, and a credit note's invoice's.
     *
     * @return list<int>
     */
    public static function receiptIds(Db $db, int $documentId, ?int $credited = null): array
    {
        $ids = [];
        foreach ([$documentId, $credited] as $d) {
            if ($d === null) {
                continue;
            }
            foreach ($db->column("SELECT DISTINCT grn_document_id FROM sinv_line WHERE document_id = ? AND kind = 'goods' AND grn_document_id IS NOT NULL", [$d]) as $g) {
                $ids[(int) $g] = true;
            }
            // Pack J (PJ8): the receipts that took its goods in from Arriving.
            foreach ($db->column('SELECT DISTINCT grn_document_id FROM invoice_arrival WHERE sinv_document_id = ? AND grn_document_id IS NOT NULL', [$d]) as $g) {
                $ids[(int) $g] = true;
            }
        }
        $out = array_keys($ids);
        sort($out);
        return $out;
    }

    /** @return array<string, mixed>|null the supplier_invoice row */
    public static function header(Db $db, int $documentId): ?array
    {
        return $db->one('SELECT * FROM supplier_invoice WHERE document_id = ?', [$documentId]);
    }

    /**
     * The canonical JSON the posting anchor keeps: the header's fixed fields and every line extension's (InvoiceInvariants V2).
     */
    public static function content(Db $db, int $documentId): string
    {
        $h = $db->one('SELECT * FROM supplier_invoice WHERE document_id = ?', [$documentId]) ?? throw new \LogicException("no supplier_invoice {$documentId}");
        $head = [];
        foreach (self::HEADER_FIXED as $k) {
            $head[$k] = $h[$k] === null ? null : (string) $h[$k];
        }
        if (($h['receive_mode'] ?? null) !== null) {
            $head['receive_mode'] = (string) $h['receive_mode']; // pack J: only when set, so a record posted before 0041 keeps its content (V2)
        }
        $lines = [];
        foreach ($db->all('SELECT * FROM sinv_line WHERE document_id = ? ORDER BY line_no', [$documentId]) as $r) {
            $row = [];
            foreach (self::LINE_FIXED as $k) {
                $row[$k] = $r[$k] === null ? null : (string) $r[$k];
            }
            if (($r['stamp_answer'] ?? null) !== null) {
                $row['stamp_answer'] = (string) $r['stamp_answer'];
            }
            $lines[] = $row;
        }
        return Idempotency::canonicalJson(['header' => $head, 'lines' => $lines]);
    }

    /** Refuses with the first problem (all of them in detail.problems). @param list<array{code: string, message: string, line?: int}> $problems */
    public static function refuse(array $problems): void
    {
        if ($problems === []) {
            return;
        }
        $first = $problems[0];
        $more = count($problems) - 1;
        throw new CwException($first['code'], $first['message'] . ($more > 0 ? " (and {$more} more problem" . ($more === 1 ? '' : 's') . ' to fix before posting)' : ''),
            422, ['problems' => $problems] + (isset($first['line']) ? ['line' => $first['line']] : []));
    }

    private function today(): string
    {
        return ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
    }
}
