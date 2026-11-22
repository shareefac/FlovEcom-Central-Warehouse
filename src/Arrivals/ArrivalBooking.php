<?php

declare(strict_types=1);

namespace CW\Arrivals;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Invoices\InvoiceMatch;

/**
 * What the postings of pack J write (docs/decisions.md PJ1-PJ4, PJ7-PJ9), called inside those postings' transactions, every row with a
 * foreign key BEFORE the stock (I21; the stock last, in the caller's one Movements::bookForDocument):
 *
 *  invoicePosted()    a supplier invoice made final with "count on arrival" (receive_mode count): an invoice_arrival row per goods line
 *                     not received yet (no delivery named), its units put in Incoming when the supplier sells before arrival (the
 *                     snapshot of supplier_arrival_pref or the setting); returns the `incoming` movement lines (the invoice's line
 *                     numbers) for the units put in Incoming.
 *  invoiceReversed()  the invoice cancelled: refused (409 arrived) once a receipt took any of its lines in (reverse the receipt
 *                     first); else its open rows are cancelled (the generic negation takes the units out of Incoming).
 *  receiptPosted()    a receipt made from an invoice (goods_receipt_invoice) posted: every open row of that invoice is closed by it
 *                     (received, linked to the receipt's lines of the same item one to one in line order, the last one shared by any
 *                     row left over, or to none: that item did not arrive), and the
 *                     units its sellable rows put in Incoming are taken out (`arrive`, returned for the caller's booking): what came
 *                     is on hand through the receipt's own goods_in, what did not is gone. The invoice's 3-way match is worked out
 *                     again (it now reads the receipt's figures).
 *  receiptReversed()  that receipt cancelled: its rows are open again (the generic negation puts the units back in Incoming).
 *
 * Locks: the invoice_arrival rows of one invoice FOR UPDATE (line order), then the supplier_invoice rows the match refresh rewrites
 * (InvoiceMatch: id order), every caller already past its own document row: a receipt's posting (receipt row, number series, PO,
 * receipt rows) and an invoice's posting or reversal (invoice row, number series, the deliveries) take them in this order.
 */
final class ArrivalBooking
{
    /**
     * @param list<array<string, mixed>> $lines Documents::lines() of the invoice
     * @return list<array{document_line: int, sku_id: int, qty: int}> the units put in Incoming
     */
    public static function invoicePosted(Db $db, Document $doc, array $lines, string $now): array
    {
        $h = $db->one('SELECT supplier_id, kind, receive_mode FROM supplier_invoice WHERE document_id = ?', [$doc->id]);
        if ($h === null || $h['kind'] !== 'invoice' || $h['receive_mode'] !== 'count') {
            return [];
        }
        $ext = [];
        foreach ($db->all('SELECT line_no, kind, grn_document_id FROM sinv_line WHERE document_id = ?', [$doc->id]) as $r) {
            $ext[(int) $r['line_no']] = $r;
        }
        $sellable = (new Arrivals($db))->prefsOf((int) $h['supplier_id'])['sell_before'];
        $main = (int) $db->value("SELECT id FROM warehouse WHERE code = 'MAIN'");
        $out = [];
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            $e = $ext[$no] ?? null;
            if ($e === null || $e['kind'] !== 'goods' || $e['grn_document_id'] !== null || $l['sku_id'] === null || (int) $l['qty'] <= 0) {
                continue;
            }
            $db->exec('INSERT INTO invoice_arrival (sinv_document_id, line_no, sku_id, warehouse_id, units, sellable, state, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$doc->id, $no, (int) $l['sku_id'], $main, (int) $l['qty'], $sellable ? 1 : 0, 'open', $now]);
            if ($sellable) {
                $out[] = ['document_line' => $no, 'sku_id' => (int) $l['sku_id'], 'qty' => (int) $l['qty']];
            }
        }
        return $out;
    }

    public static function invoiceReversed(Db $db, Document $original, string $now): void
    {
        $rows = $db->all('SELECT line_no, state FROM invoice_arrival WHERE sinv_document_id = ? ORDER BY line_no FOR UPDATE', [$original->id]);
        foreach ($rows as $r) {
            if ($r['state'] === 'received') {
                $grn = (string) $db->value('SELECT d.number FROM invoice_arrival a JOIN document d ON d.id = a.grn_document_id WHERE a.sinv_document_id = ? '
                    . "AND a.state = 'received' ORDER BY a.line_no LIMIT 1", [$original->id]);
                throw new CwException('arrived', "{$original->number}'s goods were received on {$grn}: cancel that receipt first, or book the supplier's credit note",
                    409, ['receipt' => $grn]);
            }
        }
        $db->exec("UPDATE invoice_arrival SET state = 'cancelled', closed_at = ? WHERE sinv_document_id = ? AND state = 'open'", [$now, $original->id]);
    }

    /**
     * @param array<int, array<string, mixed>> $planLines the receipt's lines (line_no => at least sku_id), as its posting planned them
     * @return array{invoice: ?int, number: ?string, warehouse: ?string, lines: list<array{document_line: int, sku_id: int, qty: int}>}
     */
    public static function receiptPosted(Db $db, Document $grn, array $planLines, string $now, ?Caller $caller = null): array
    {
        $inv = $db->value('SELECT sinv_document_id FROM goods_receipt_invoice WHERE grn_document_id = ?', [$grn->id]);
        if ($inv === null) {
            return ['invoice' => null, 'number' => null, 'warehouse' => null, 'lines' => []];
        }
        $inv = (int) $inv;
        // Every arrival row of the invoice under lock (line order), the open ones taken in below (CW-1155: the received ones are what a later
        // receipt of the rest is checked against).
        $all = $db->all('SELECT line_no, sku_id, units, sellable, warehouse_id, state, grn_document_id FROM invoice_arrival WHERE sinv_document_id = ? ORDER BY line_no FOR UPDATE',
            [$inv]);
        $rows = array_values(array_filter($all, static fn (array $r): bool => $r['state'] === 'open'));
        $number = (string) $db->value('SELECT number FROM document WHERE id = ?', [$inv]);
        if ($rows === []) {
            // CW-1155 (docs/decisions.md RR5): a later receipt of an invoice whose goods another receipt took in brings the rest of what that
            // one did not: only what the invoice still waits for (refused otherwise), recorded line by line; nothing was in Incoming for it.
            if (array_filter($all, static fn (array $r): bool => $r['state'] === 'received' && (int) $r['grn_document_id'] !== $grn->id) !== []) {
                ArrivalRest::posted($db, $grn, $inv, $planLines, $now, $caller ?? Caller::system('receive_rest'));
                $match = new InvoiceMatch($db);
                $match->refresh([$inv], $now);
                // This receipt (the billed units are given to the parts in id order: an earlier part keeps its share, the first receipt its own;
                // storing those would S-lock their document rows, which a claim or receiveRest may hold: the deadlocks seen in ReceiveRestRaceTest).
                $match->storeReceipts([$grn->id], $now);
            }
            return ['invoice' => $inv, 'number' => $number, 'warehouse' => null, 'lines' => []];
        }
        // Review finding 1 (Codex, 15 Oct): the receipt's lines of each item, in line order, are given to the invoice's rows of that item
        // one to one, in line order (the receipt was made line for line from the invoice); a row left over shares the item's last line
        // (two invoice lines keyed as one on the receipt: the match and missingOf() take that line's units in turn).
        ksort($planLines);
        $bySku = [];
        foreach ($planLines as $no => $l) {
            $bySku[(int) $l['sku_id']][] = (int) $no;
        }
        $taken = [];
        $out = [];
        $warehouse = null;
        foreach ($rows as $r) {
            $sku = (int) $r['sku_id'];
            $lines = $bySku[$sku] ?? [];
            $i = $taken[$sku] ?? 0;
            $line = $lines === [] ? null : $lines[min($i, count($lines) - 1)];
            $taken[$sku] = $i + 1;
            $db->exec("UPDATE invoice_arrival SET state = 'received', grn_document_id = ?, grn_line_no = ?, closed_at = ? WHERE sinv_document_id = ? AND line_no = ?",
                [$grn->id, $line, $now, $inv, (int) $r['line_no']]);
            if ((int) $r['sellable'] === 1) {
                $warehouse ??= (string) $db->value('SELECT code FROM warehouse WHERE id = ?', [(int) $r['warehouse_id']]);
                $out[] = ['document_line' => (int) $r['line_no'], 'sku_id' => (int) $r['sku_id'], 'qty' => (int) $r['units']];
            }
        }
        $match = new InvoiceMatch($db);
        $match->refresh([$inv], $now);
        // The receipt is invoiced by this invoice (PC18: "Not invoiced yet" reads what it has left).
        $match->storeReceipts([$grn->id], $now);
        return ['invoice' => $inv, 'number' => $number, 'warehouse' => $warehouse, 'lines' => $out];
    }

    /**
     * The arrival rows of the invoice a receipt was made from, FOR UPDATE (line order): a receipt's reversal takes them after its order's rows
     * and before its incidents (CW-1155, docs/decisions.md RR12: the lock order of a posting, so a reversal and the posting of a receipt of
     * the rest never wait on each other in a circle).
     */
    public static function lockFor(Db $db, int $grnId): void
    {
        $inv = $db->value('SELECT sinv_document_id FROM goods_receipt_invoice WHERE grn_document_id = ?', [$grnId]);
        if ($inv !== null) {
            // A part of the rest taken back gives its billed units to the later parts: their document rows first, in id order (as an acceptance
            // or a credit note's posting takes the receipts), then the arrival rows (CW-1155, RR22).
            (new InvoiceMatch($db))->lockReceipts(ArrivalRest::laterParts($db, (int) $inv, $grnId));
            self::lockInvoice($db, (int) $inv);
        }
    }

    /** An invoice's arrival rows FOR UPDATE (line order); none: nothing. */
    public static function lockInvoice(Db $db, int $invoiceId): void
    {
        $db->all('SELECT line_no FROM invoice_arrival WHERE sinv_document_id = ? ORDER BY line_no FOR UPDATE', [$invoiceId]);
    }

    public static function receiptReversed(Db $db, Document $original, string $now, ?Caller $caller = null): void
    {
        // CW-1155 (RR10): a receipt of the rest taken back: what it brought is owed again (its rows count no more), and the short incidents its
        // rest resolved by themselves are open again. The invoice's match is worked out by InvoiceMatch::afterReceiptReversed.
        ArrivalRest::reversed($db, $original, $now, $caller ?? Caller::system('receive_rest'));
        $invoices = array_map('intval', $db->column('SELECT DISTINCT sinv_document_id FROM invoice_arrival WHERE grn_document_id = ? ORDER BY sinv_document_id', [$original->id]));
        if ($invoices === []) {
            return;
        }
        foreach ($invoices as $inv) {
            $db->all('SELECT line_no FROM invoice_arrival WHERE sinv_document_id = ? ORDER BY line_no FOR UPDATE', [$inv]);
        }
        $db->exec("UPDATE invoice_arrival SET state = 'open', grn_document_id = NULL, grn_line_no = NULL, closed_at = NULL WHERE grn_document_id = ?", [$original->id]);
        (new InvoiceMatch($db))->refresh($invoices, $now);
    }
}
