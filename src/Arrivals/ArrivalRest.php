<?php

declare(strict_types=1);

namespace CW\Arrivals;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Invoices\InvoiceMath;
use CW\Settings;

/**
 * CW-1155 "Receive the rest" (docs/decisions.md RR1-RR20): the units an invoice line counted on arrival did not bring on its first receipt,
 * and what became of them later. The owner, 22 Nov 2026: Goods In stays as it is (the shops do not sell the missing units), the invoice
 * waits for the rest, and when the rest comes it is received on the SAME invoice and the shops sell it again; if it never comes, it is
 * claimed as today.
 *
 * "Still owed" is never stored (RR2). Per invoice line whose arrival row a receipt took in (the FIRST receipt, invoice_arrival.grn_document_id):
 *
 *     short    = the line's units - what the first receipt brought of it (Arrivals::missingOf's rule: accepted + set aside + quarantined,
 *                a receipt line shared by two invoice lines given to them in line order)
 *     owed     = short - units credited on that line (posted credit notes) - what POSTED later receipts brought (rows `rest`)
 *                - what a LIVE claim claims (rows `claim`; a live claim of the first receipt with no rows, made before 0076 or by hand,
 *                claims everything) - what a person stopped waiting for (rows `waive`)      (never below 0)
 *     missing  = the same without the claims: what "Claim what did not come" claims.
 *
 * Every row names the first receipt it is about: once that receipt is taken back (allowed only with no later receipt standing, RR11) its
 * rows count no more. Rows are written by: the posting of a later receipt (rest, ArrivalBooking::receiptPosted), the claim (claim,
 * Arrivals::claimMissing) and the acceptance of the invoice's difference (waive, SupplierInvoices::accept); never changed or removed.
 */
final class ArrivalRest
{
    /** The statuses of a claim (a debit note) that claims its units. */
    public const LIVE_CLAIM = ['draft', 'awaiting_approval', 'posted'];
    /** The statuses of a later receipt that is "taking in the rest" until it is posted or cancelled. */
    public const LIVE_RECEIPT = ['draft', 'awaiting_approval'];
    public const LATE_DAYS_DEFAULT = 14;

    /**
     * Per invoice line a receipt took in, what came and what became of the rest (inside or outside a transaction; the callers that act on
     * it hold the invoice's arrival rows).
     *
     * @return array<int, array{line_no: int, sku_id: int, units: int, unit_cost: string, first_grn: int, first_grn_line: ?int, came: int, short: int,
     *   credited: int, rest: int, claimed: int, waived: int, legacy_claim: ?int, owed: int, missing: int}>
     */
    public static function lines(Db $db, int $invoiceId): array
    {
        $rows = $db->all("SELECT a.line_no, a.sku_id, a.units, a.grn_document_id, a.grn_line_no, dl.unit_cost FROM invoice_arrival a JOIN document_line dl "
            . "ON dl.document_id = a.sinv_document_id AND dl.line_no = a.line_no WHERE a.sinv_document_id = ? AND a.state = 'received' ORDER BY a.line_no", [$invoiceId]);
        if ($rows === []) {
            return [];
        }
        // What each first receipt brought, given to the invoice lines in line order (missingOf's rule).
        $came = [];
        foreach (array_values(array_unique(array_map(static fn (array $r): int => (int) $r['grn_document_id'], $rows))) as $g) {
            foreach ($db->all('SELECT line_no, COALESCE(accepted_units, 0) + COALESCE(verify_units, 0) + COALESCE(quarantine_units, 0) AS units FROM grn_line '
                . 'WHERE document_id = ?', [$g]) as $l) {
                $came[$g][(int) $l['line_no']] = (int) $l['units'];
            }
        }
        // Every posted credit note of a line; the part of it answering a live claim with rows (debit_note_document_id) is the same units as
        // that claim (the Codex checks of a0d33a2 M2 and of 3ae3345 M): counted once, at most the units the claim holds on that line.
        $credited = [];
        $answers = [];
        foreach ($db->all('SELECT sl.credit_of_line_no AS line_no, c.debit_note_document_id AS dn, SUM(dl.qty) AS qty FROM supplier_invoice c '
            . "JOIN document cd ON cd.id = c.document_id AND cd.status = 'posted' "
            . "JOIN sinv_line sl ON sl.document_id = c.document_id AND sl.kind = 'goods' AND sl.credit_of_line_no IS NOT NULL "
            . 'JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no '
            . "WHERE c.credits_document_id = ? AND c.kind = 'credit_note' GROUP BY sl.credit_of_line_no, c.debit_note_document_id", [$invoiceId]) as $c) {
            $credited[(int) $c['line_no']] = ($credited[(int) $c['line_no']] ?? 0) + (int) $c['qty'];
            if ($c['dn'] !== null) {
                $answers[(int) $c['line_no']][(int) $c['dn']] = (int) $c['qty'];
            }
        }
        $facts = [];
        $claimsOf = [];
        // A claim row counts while its return is live AND still names the first receipt it was made for (a return whose delivery was changed
        // by hand reserves nothing: the Codex check of 3ae3345, M).
        foreach ($db->all('SELECT r.line_no, r.kind, r.units, r.first_grn_document_id, r.document_id, d.status, sr.grn_document_id AS claim_grn FROM invoice_arrival_rest r '
            . 'LEFT JOIN document d ON d.id = r.document_id LEFT JOIN supplier_return sr ON sr.document_id = r.document_id WHERE r.sinv_document_id = ?', [$invoiceId]) as $f) {
            $counts = match ((string) $f['kind']) {
                'rest' => $f['status'] === 'posted',
                'claim' => in_array($f['status'], self::LIVE_CLAIM, true) && (int) ($f['claim_grn'] ?? 0) === (int) $f['first_grn_document_id'],
                default => true,
            };
            if ($counts) {
                $k = (int) $f['line_no'] . ':' . (int) $f['first_grn_document_id'];
                $facts[$k][(string) $f['kind']] = ($facts[$k][(string) $f['kind']] ?? 0) + (int) $f['units'];
                if ($f['kind'] === 'claim') {
                    $claimsOf[$k][(int) $f['document_id']] = ($claimsOf[$k][(int) $f['document_id']] ?? 0) + (int) $f['units'];
                }
            }
        }
        $legacy = [];
        $out = [];
        foreach ($rows as $r) {
            $no = (int) $r['line_no'];
            $g = (int) $r['grn_document_id'];
            $line = $r['grn_line_no'] === null ? null : (int) $r['grn_line_no'];
            $got = $line === null ? 0 : min((int) $r['units'], $came[$g][$line] ?? 0);
            if ($line !== null) {
                $came[$g][$line] = ($came[$g][$line] ?? 0) - $got;
            }
            $legacy[$g] ??= self::legacyClaim($db, $g);
            $f = $facts[$no . ':' . $g] ?? [];
            $short = max(0, (int) $r['units'] - $got);
            $overlap = 0;
            foreach ($answers[$no] ?? [] as $dn => $units) {
                $overlap += min($units, $claimsOf[$no . ':' . $g][$dn] ?? 0);
            }
            $credit = ($credited[$no] ?? 0) - $overlap;
            $settled = $credit + ($f['rest'] ?? 0) + ($f['waive'] ?? 0);
            $missing = max(0, $short - $settled);
            $out[$no] = ['line_no' => $no, 'sku_id' => (int) $r['sku_id'], 'units' => (int) $r['units'], 'unit_cost' => (string) ($r['unit_cost'] ?? '0'), 'first_grn' => $g,
                'first_grn_line' => $line, 'came' => $got, 'short' => $short, 'credited' => $credit, 'rest' => $f['rest'] ?? 0, 'claimed' => $f['claim'] ?? 0,
                'waived' => $f['waive'] ?? 0, 'legacy_claim' => $legacy[$g] ?: null, 'owed' => $legacy[$g] > 0 ? 0 : max(0, $missing - ($f['claim'] ?? 0)), 'missing' => $missing];
        }
        return $out;
    }

    /**
     * What an acceptance stops waiting for, as the person saw it: "line:units" of each owed line (SupplierInvoices::accept compares it with the
     * rows it writes the waivers from).
     *
     * @param list<array{line_no: int, units: int}> $owed owed()
     */
    public static function owedKey(array $owed): string
    {
        return implode(',', array_map(static fn (array $o): string => $o['line_no'] . ':' . $o['units'], $owed));
    }

    /**
     * The invoice lines still owed (owed > 0), with their item and money (units x the invoice line's unit price), for the screens and the
     * receipt of the rest.
     *
     * @return list<array{line_no: int, sku_id: int, code: string, name: string, units: int, unit_cost: string, amount: string, first_grn: int, first_grn_line: ?int}>
     */
    public static function owed(Db $db, int $invoiceId): array
    {
        $out = [];
        $lines = array_filter(self::lines($db, $invoiceId), static fn (array $l): bool => $l['owed'] > 0);
        $names = self::names($db, array_values(array_unique(array_map(static fn (array $l): int => $l['sku_id'], $lines))));
        foreach ($lines as $l) {
            $out[] = ['line_no' => $l['line_no'], 'sku_id' => $l['sku_id'], 'code' => $names[$l['sku_id']][0] ?? ('#' . $l['sku_id']), 'name' => $names[$l['sku_id']][1] ?? '',
                'units' => $l['owed'], 'unit_cost' => $l['unit_cost'], 'amount' => self::amount($l['owed'], $l['unit_cost']), 'first_grn' => $l['first_grn'],
                'first_grn_line' => $l['first_grn_line']];
        }
        return $out;
    }

    /**
     * What an invoice is still waiting for, in one line for the screens: the units and money owed, since when (the first receipt's posting),
     * the days (UK calendar days) and whether that is late (arrivals.rest_late_days). Null when nothing is owed.
     *
     * @return array{units: int, amount: string, lines: int, since: string, days: int, late: bool, first_grn: int, first_number: string}|null
     */
    public static function waiting(Db $db, int $invoiceId, \DateTimeImmutable $now, ?int $lateDays = null): ?array
    {
        $owed = self::owed($db, $invoiceId);
        if ($owed === []) {
            return null;
        }
        $units = 0;
        $e2 = 0;
        foreach ($owed as $o) {
            $units += $o['units'];
            $e2 += InvoiceMath::e2($o['amount']);
        }
        $first = $owed[0]['first_grn'];
        $g = $db->one('SELECT number, posted_at FROM document WHERE id = ?', [$first]) ?? ['number' => null, 'posted_at' => null];
        $since = (string) ($g['posted_at'] ?? '');
        $days = $since === '' ? 0 : self::days($since, $now);
        return ['units' => $units, 'amount' => InvoiceMath::fromE2($e2), 'lines' => count($owed), 'since' => $since, 'days' => $days,
            'late' => $days >= ($lateDays ?? self::lateDays($db)), 'first_grn' => $first, 'first_number' => (string) ($g['number'] ?? '')];
    }

    /** UK calendar days from a UTC database time to now (0: today). */
    public static function days(string $utc, \DateTimeImmutable $now): int
    {
        $uk = new \DateTimeZone('Europe/London');
        $from = new \DateTimeImmutable((new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($uk)->format('Y-m-d'), $uk);
        $to = new \DateTimeImmutable($now->setTimezone($uk)->format('Y-m-d'), $uk);
        return max(0, (int) $from->diff($to)->days * ($to >= $from ? 1 : -1));
    }

    public static function lateDays(Db $db): int
    {
        $s = new Settings($db);
        return $s->has('arrivals.rest_late_days') ? max(1, (int) $s->get('arrivals.rest_late_days')) : self::LATE_DAYS_DEFAULT;
    }

    /**
     * The first receipt of an invoice (the one its arrival rows name; one receipt closes the whole invoice, PJ3), when one took them in.
     */
    public static function firstReceipt(Db $db, int $invoiceId): ?int
    {
        $g = $db->value("SELECT MIN(grn_document_id) FROM invoice_arrival WHERE sinv_document_id = ? AND state = 'received'", [$invoiceId]);
        return $g === null ? null : (int) $g;
    }

    /** The live receipt taking in the rest of an invoice (a draft made from it while its goods were received by another one), if any. */
    public static function liveRestReceipt(Db $db, int $invoiceId, ?int $except = null): ?int
    {
        $g = $db->value("SELECT MAX(g.grn_document_id) FROM goods_receipt_invoice g JOIN document d ON d.id = g.grn_document_id AND d.status IN ('draft', 'awaiting_approval') "
            . "WHERE g.sinv_document_id = ? AND g.grn_document_id <> ? AND EXISTS (SELECT 1 FROM invoice_arrival a WHERE a.sinv_document_id = g.sinv_document_id "
            . "AND a.state = 'received' AND a.grn_document_id <> g.grn_document_id)", [$invoiceId, $except ?? 0]);
        return $g === null ? null : (int) $g;
    }

    /**
     * The live claim of a first receipt (a return to the supplier about it with no item lines: Arrivals::claimMissing's, or one made by
     * hand), its id, or null.
     */
    public static function liveClaim(Db $db, int $grnId): ?int
    {
        $id = $db->value("SELECT MIN(r.document_id) FROM supplier_return r JOIN document d ON d.id = r.document_id AND d.status IN ('draft', 'awaiting_approval', 'posted') "
            . 'AND d.reverses_id IS NULL WHERE r.grn_document_id = ? AND NOT EXISTS (SELECT 1 FROM document_line l WHERE l.document_id = r.document_id AND l.sku_id IS NOT NULL)',
            [$grnId]);
        return $id === null ? null : (int) $id;
    }

    /** A live claim of a first receipt that has no `claim` rows (made before 0076, or by hand): it claims everything that receipt did not bring. */
    private static function legacyClaim(Db $db, int $grnId): int
    {
        return (int) ($db->value("SELECT MIN(r.document_id) FROM supplier_return r JOIN document d ON d.id = r.document_id AND d.status IN ('draft', 'awaiting_approval', 'posted') "
            . 'AND d.reverses_id IS NULL WHERE r.grn_document_id = ? AND NOT EXISTS (SELECT 1 FROM document_line l WHERE l.document_id = r.document_id AND l.sku_id IS NOT NULL) '
            . "AND NOT EXISTS (SELECT 1 FROM invoice_arrival_rest x WHERE x.kind = 'claim' AND x.document_id = r.document_id)", [$grnId]) ?? 0);
    }

    /** The money of $units at a unit price (half-up to the penny, as the claim). */
    public static function amount(int $units, string $unitCost): string
    {
        return InvoiceMath::fromE2(InvoiceMath::unitsAt($units, InvoiceMath::e6($unitCost)));
    }

    // ------------------------------------------------------------------------------------------
    // The rest received (inside the later receipt's posting, ArrivalBooking::receiptPosted; the invoice's arrival rows locked)
    // ------------------------------------------------------------------------------------------

    /**
     * What stops a later receipt of an invoice from posting as its rest (the receipt page shows them; the posting refuses with the first,
     * under the arrival rows' lock): a line of an item the invoice is not waiting for, or more units of an item on the paperwork than it
     * is still waiting for (not_owed); none of it waited for any more because it was claimed (claimed). Empty for any other receipt.
     *
     * @param array<int, array<string, mixed>> $lines ReceiptPlan's lines (line_no => sku_id, units on the paperwork)
     * @return list<array{code: string, message: string, line?: int}>
     */
    public static function problems(Db $db, int $grnId, array $lines): array
    {
        $inv = self::restOf($db, $grnId);
        if ($inv === null) {
            return [];
        }
        $number = (string) ($db->value('SELECT COALESCE(number, external_ref) FROM document WHERE id = ?', [$inv]) ?? '');
        $out = [];
        // It stays the rest of THAT invoice (the Codex check of a0d33a2, H2): the invoice's supplier and supplier number, and no delivery
        // note of another receipt of it (the same delivery keyed twice).
        $mine = $db->one('SELECT g.supplier_id, g.invoice_key, g.delivery_note, d.external_ref FROM goods_receipt g JOIN document d ON d.id = g.document_id WHERE g.document_id = ?',
            [$grnId]) ?? [];
        $theirs = $db->one('SELECT si.supplier_id, d.external_ref FROM supplier_invoice si JOIN document d ON d.id = si.document_id WHERE si.document_id = ?', [$inv]) ?? [];
        if ((int) ($mine['supplier_id'] ?? 0) !== (int) ($theirs['supplier_id'] ?? -1)
            || \CW\Receiving\ReceiptMath::invoiceKey((string) ($mine['external_ref'] ?? '')) !== \CW\Receiving\ReceiptMath::invoiceKey((string) ($theirs['external_ref'] ?? ''))) {
            $out[] = ['code' => 'rest_invoice_changed', 'message' => "This receipt takes in the rest of {$number}: its supplier and supplier invoice number are that invoice's "
                . '(' . (string) ($theirs['external_ref'] ?? '') . '). Put them back, or cancel it and receive the delivery as its own.'];
        }
        $note = mb_strtoupper(trim((string) ($mine['delivery_note'] ?? '')));
        if ($note !== '') {
            foreach ($db->all("SELECT d.id, d.number, g.delivery_note FROM goods_receipt_invoice l JOIN goods_receipt g ON g.document_id = l.grn_document_id JOIN document d "
                . "ON d.id = g.document_id AND d.status IN ('draft', 'awaiting_approval', 'posted') WHERE l.sinv_document_id = ? AND l.grn_document_id <> ?", [$inv, $grnId]) as $o) {
                if (mb_strtoupper(trim((string) $o['delivery_note'])) === $note) {
                    $out[] = ['code' => 'rest_same_delivery', 'message' => 'This delivery note is on ' . ($o['number'] ?? ('receipt #' . $o['id']))
                        . ' already: it is the same delivery. Correct the delivery note, or cancel this receipt.'];
                    break;
                }
            }
        }
        $all = self::lines($db, $inv);
        $owed = [];
        $claimed = [];
        foreach ($all as $l) {
            $owed[$l['sku_id']] = ($owed[$l['sku_id']] ?? 0) + $l['owed'];
            if ($l['claimed'] > 0 || $l['legacy_claim'] !== null) {
                $claimed[$l['sku_id']] = $l['legacy_claim'] ?? self::claimOf($db, $inv, $l['line_no'], $l['first_grn']);
            }
        }
        ksort($lines);
        $left = $owed;
        foreach ($lines as $no => $l) {
            $sku = (int) $l['sku_id'];
            $units = (int) $l['units'];
            if (($left[$sku] ?? 0) >= $units) {
                $left[$sku] -= $units;
                continue;
            }
            if (($owed[$sku] ?? 0) === 0 && isset($claimed[$sku])) {
                $dn = (string) ($db->value('SELECT COALESCE(number, CONCAT(\'draft return #\', id)) FROM document WHERE id = ?', [(int) $claimed[$sku]]) ?? '');
                $out[] = ['code' => 'claimed', 'message' => "The missing units were claimed on {$dn}. Cancel that claim first if the goods came after all.", 'line' => (int) $no];
                continue;
            }
            $out[] = ['code' => 'not_owed', 'message' => "This receipt only takes in what {$number} is still waiting for: line {$no} is not (take it off and receive it as its "
                . 'own delivery).', 'line' => (int) $no];
        }
        return $out;
    }

    /**
     * The posting of a later receipt of an invoice whose goods another receipt took in: refused (409 claimed, 422 not_owed) unless it takes in
     * only what the invoice still waits for; then each of its lines is given to the owed invoice lines of its item in line order (what came:
     * accepted + set aside + quarantined, at most what each still waits for) as `rest` rows, and each short incident of those lines is
     * resolved once nothing is owed for it. No movement of Incoming: the owed units were never there (the receipt's own goods_in puts what
     * came on hand, its selling mode puts it back on sale).
     *
     * @param array<int, array<string, mixed>> $planLines the receipt's lines as its posting planned them
     */
    public static function posted(Db $db, Document $grn, int $invoiceId, array $planLines, string $now, Caller $caller): void
    {
        $first = self::problems($db, $grn->id, $planLines);
        if ($first !== []) {
            throw new CwException($first[0]['code'], $first[0]['message'], in_array($first[0]['code'], ['claimed', 'rest_same_delivery'], true) ? 409 : 422, ['problems' => $first]);
        }
        $lines = self::lines($db, $invoiceId);
        $owed = [];
        foreach ($lines as $l) {
            if ($l['owed'] > 0) {
                $owed[$l['sku_id']][] = $l['line_no'];
            }
        }
        $left = array_map(static fn (array $l): int => $l['owed'], $lines);
        ksort($planLines);
        // The receipt was made line for line from the owed invoice lines (Arrivals::restReceiptOf): the n-th line of an item takes the n-th owed
        // invoice line of that item first (the Codex check of a0d33a2, H3: one item on two lines at two prices, the first counted short on the
        // receipt, the second's units are the second's), then what is left of it goes to the item's other owed lines in line order.
        $came = [];
        $bySku = [];
        foreach ($planLines as $no => $l) {
            $s = $l['split'];
            $came[(int) $no] = (int) $s['accepted'] + (int) $s['verify'] + (int) $s['quarantine'];
            $bySku[(int) $l['sku_id']][] = (int) $no;
        }
        $take = [];
        $give = static function (int $no, int $invLine) use (&$take, &$came, &$left): void {
            $t = min($came[$no], $left[$invLine]);
            if ($t > 0) {
                $take[$no][$invLine] = ($take[$no][$invLine] ?? 0) + $t;
                $came[$no] -= $t;
                $left[$invLine] -= $t;
            }
        };
        foreach ($bySku as $sku => $nos) {
            $inv = $owed[$sku] ?? [];
            // First the owed line of the item at the receipt line's own unit price (the draft keeps each invoice line's price: a line taken off
            // the draft never moves the next one's units to another price; the Codex check of 3ae3345, H2), then the n-th to the n-th.
            foreach ($nos as $no) {
                foreach ($inv as $invLine) {
                    if (\CW\Invoices\InvoiceMath::e6((string) $planLines[$no]['unit_cost']) === \CW\Invoices\InvoiceMath::e6($lines[$invLine]['unit_cost'])) {
                        $give($no, $invLine);
                    }
                }
            }
            foreach ($nos as $i => $no) {
                if (isset($inv[$i])) {
                    $give($no, $inv[$i]);
                }
            }
            foreach ($nos as $no) {
                foreach ($inv as $invLine) {
                    $give($no, $invLine);
                }
            }
        }
        ksort($take);
        $written = [];
        foreach ($take as $no => $parts) {
            ksort($parts);
            foreach ($parts as $invLine => $units) {
                if ($units <= 0) {
                    continue;
                }
                $db->exec('INSERT INTO invoice_arrival_rest (sinv_document_id, line_no, first_grn_document_id, first_grn_line_no, kind, document_id, document_line_no, units, '
                    . "created_by, created_actor, created_at) VALUES (?, ?, ?, ?, 'rest', ?, ?, ?, ?, ?, ?)",
                    [$invoiceId, $invLine, $lines[$invLine]['first_grn'], $lines[$invLine]['first_grn_line'], $grn->id, (int) $no, $units, $caller->staffUserId, $caller->actor,
                        $now]);
                $written[] = ['line' => $invLine, 'receipt_line' => (int) $no, 'units' => $units];
            }
        }
        $number = (string) ($db->value('SELECT number FROM document WHERE id = ?', [$invoiceId]) ?? '');
        Audit::write($db, $caller, 'arrival.rest_received', 'document', (string) $grn->id, null, ['invoice' => $number, 'rest' => $written]);
        self::resolveIncidents($db, $caller, $grn, $invoiceId, $now, array_values(array_unique(array_column($written, 'line'))));
    }

    /**
     * The receipt of the rest taken back (inside its reversal, after it is marked reversed: its rows count no more; the arrival rows locked):
     * a short incident that the rest resolved by itself is open again when its lines are owed again.
     */
    public static function reversed(Db $db, Document $original, string $now, Caller $caller): void
    {
        $invoices = array_map('intval', $db->column("SELECT DISTINCT sinv_document_id FROM invoice_arrival_rest WHERE kind = 'rest' AND document_id = ? ORDER BY sinv_document_id",
            [$original->id]));
        foreach ($invoices as $inv) {
            $lines = self::lines($db, $inv);
            foreach (self::incidentsOf($db, $inv, $lines, 'resolved') as $i) {
                if (array_filter($i['lines'], static fn (int $no): bool => ($lines[$no]['owed'] ?? 0) > 0) === []) {
                    continue;
                }
                $last = $db->value("SELECT action FROM audit_log WHERE entity_type = 'incident' AND entity_id = ? AND action IN ('incident.resolve', 'incident.rest_resolved', "
                    . "'incident.rest_reopened') ORDER BY id DESC LIMIT 1", [(string) $i['id']]);
                if ($last !== 'incident.rest_resolved') {
                    continue; // a person closed it: theirs
                }
                $db->exec("UPDATE incident SET status = 'open', resolution = NULL, resolved_by = NULL, resolved_actor = NULL, resolved_at = NULL WHERE id = ? AND status = 'resolved'",
                    [$i['id']]);
                Audit::write($db, $caller, 'incident.rest_reopened', 'incident', (string) $i['id'], null, ['receipt' => (string) $original->number,
                    'document_id' => $i['document_id'], 'line_no' => $i['line_no'], 'invoice' => $inv]);
            }
        }
    }

    /**
     * Refuses the reversal of a FIRST receipt while a later receipt of its invoice stands (posted, or a draft taking in the rest): taking the
     * first back would put its units on their way again while the rest is on hand (409 rest_received). Read before anything of the reversal
     * is written (the first receipt's document row is held by the reversal; receiveRest() takes it too).
     */
    public static function refuseFirstReversal(Db $db, Document $original): void
    {
        $inv = $db->value("SELECT MIN(sinv_document_id) FROM invoice_arrival WHERE grn_document_id = ? AND state = 'received'", [$original->id]);
        if ($inv === null) {
            return;
        }
        $later = $db->one("SELECT d.id, d.number, d.status FROM goods_receipt_invoice g JOIN document d ON d.id = g.grn_document_id AND d.status IN ('draft', 'awaiting_approval', "
            . "'posted') WHERE g.sinv_document_id = ? AND g.grn_document_id <> ? ORDER BY d.id LIMIT 1", [(int) $inv, $original->id]);
        if ($later !== null) {
            $label = $later['number'] ?? ('draft receipt #' . $later['id']);
            throw new CwException('rest_received', "{$label} took in the rest of this invoice: cancel that receipt first", 409, ['receipt' => (int) $later['id']]);
        }
    }

    /** The invoice a receipt takes in the rest of (it is made from an invoice whose goods another receipt took in), or null. */
    public static function restOf(Db $db, int $grnId): ?int
    {
        $inv = $db->value('SELECT g.sinv_document_id FROM goods_receipt_invoice g WHERE g.grn_document_id = ? AND EXISTS (SELECT 1 FROM invoice_arrival a '
            . "WHERE a.sinv_document_id = g.sinv_document_id AND a.state = 'received' AND a.grn_document_id <> g.grn_document_id) "
            . "AND NOT EXISTS (SELECT 1 FROM invoice_arrival o WHERE o.sinv_document_id = g.sinv_document_id AND o.state = 'open')", [$grnId]);
        return $inv === null ? null : (int) $inv;
    }

    // ------------------------------------------------------------------------------------------

    /**
     * Resolves each open short incident about an invoice line this receipt brought some of ($brought) once nothing is owed for any line it
     * is about (another line settled otherwise, a credit, is never said to have come: the Codex check of a0d33a2, M5).
     *
     * @param list<int> $brought
     */
    private static function resolveIncidents(Db $db, Caller $caller, Document $grn, int $invoiceId, string $now, array $brought): void
    {
        $lines = self::lines($db, $invoiceId);
        $text = "The rest came on {$grn->number}.";
        foreach (self::incidentsOf($db, $invoiceId, $lines, 'open') as $i) {
            if ($i['document_id'] === $grn->id) {
                continue; // its own shortfall did not come on it
            }
            if (array_intersect($i['lines'], $brought) === [] || array_filter($i['lines'], static fn (int $no): bool => ($lines[$no]['owed'] ?? 0) > 0) !== []) {
                continue; // not about what came, or part of it is still waited for
            }
            $n = $db->exec("UPDATE incident SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_actor = ?, resolved_at = ? WHERE id = ? AND status = 'open'",
                [$text, $caller->staffUserId, $caller->actor, $now, $i['id']]);
            if ($n > 0) {
                Audit::write($db, $caller, 'incident.rest_resolved', 'incident', (string) $i['id'], null, ['status' => 'resolved', 'note' => $text, 'receipt' => (string) $grn->number,
                    'document_id' => $i['document_id'], 'line_no' => $i['line_no'], 'units' => $i['units'], 'auto' => true]);
            }
        }
    }

    /**
     * The short incidents (status $status) of the receipt lines that took in, or were to take in, the invoice's lines counted on arrival:
     * the first receipt's line of each, and each later receipt's line that brought some of it; with the invoice lines each is about.
     *
     * @param array<int, array<string, mixed>> $lines lines()
     * @return list<array{id: int, document_id: int, line_no: int, units: int, lines: list<int>}>
     */
    private static function incidentsOf(Db $db, int $invoiceId, array $lines, string $status): array
    {
        $about = [];
        foreach ($lines as $l) {
            if ($l['first_grn_line'] !== null) {
                $about[$l['first_grn'] . ':' . $l['first_grn_line']][] = $l['line_no'];
            }
        }
        foreach ($db->all("SELECT r.line_no, r.document_id, r.document_line_no FROM invoice_arrival_rest r JOIN document d ON d.id = r.document_id AND d.status = 'posted' "
            . "WHERE r.sinv_document_id = ? AND r.kind = 'rest' ORDER BY r.id", [$invoiceId]) as $r) {
            if (isset($lines[(int) $r['line_no']]) && $lines[(int) $r['line_no']]['first_grn'] > 0) {
                $about[(int) $r['document_id'] . ':' . (int) $r['document_line_no']][] = (int) $r['line_no'];
            }
        }
        // A later receipt's short line is about the invoice lines its rows name AND the item's invoice lines at its own unit price (the draft keeps
        // each owed line's price; all the item's lines when none has it): its shortfall is of those (the Codex checks of 3ae3345, 6c78108 and
        // 55a4a42: a line that brought nothing, a line kept for one owed line, a line that took in two lines of one price).
        $first = array_values(array_unique(array_map(static fn (array $l): int => $l['first_grn'], $lines)));
        foreach ($db->all("SELECT g.document_id, g.line_no, dl.sku_id, dl.unit_cost FROM goods_receipt_invoice l JOIN document d ON d.id = l.grn_document_id AND d.status = 'posted' "
            . 'JOIN grn_line g ON g.document_id = l.grn_document_id JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no '
            . 'WHERE l.sinv_document_id = ? AND g.short_units > 0', [$invoiceId]) as $g) {
            if (in_array((int) $g['document_id'], $first, true)) {
                continue;
            }
            $sku = array_values(array_filter($lines, static fn (array $l): bool => $l['sku_id'] === (int) $g['sku_id']));
            if ($sku === []) {
                continue;
            }
            $atPrice = array_values(array_filter($sku, static fn (array $l): bool => InvoiceMath::e6($l['unit_cost']) === InvoiceMath::e6((string) $g['unit_cost'])));
            $k = (int) $g['document_id'] . ':' . (int) $g['line_no'];
            $about[$k] = array_values(array_unique([...($about[$k] ?? []), ...array_column($atPrice === [] ? $sku : $atPrice, 'line_no')]));
        }
        if ($about === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_keys($about), 200) as $chunk) {
            $keys = array_map(static fn (string $k): string => 'grn:' . $k . ':short', $chunk);
            foreach ($db->all('SELECT id, document_id, line_no, units FROM incident WHERE status = ? AND dedupe_key IN (' . implode(', ', array_fill(0, count($keys), '?')) . ') '
                . 'ORDER BY id', [$status, ...$keys]) as $i) {
                $out[] = ['id' => (int) $i['id'], 'document_id' => (int) $i['document_id'], 'line_no' => (int) $i['line_no'], 'units' => (int) $i['units'],
                    'lines' => array_values(array_unique($about[(int) $i['document_id'] . ':' . (int) $i['line_no']]))];
            }
        }
        return $out;
    }

    /** The live claim that claimed an invoice line's units (a `claim` row of a live return), or null. */
    private static function claimOf(Db $db, int $invoiceId, int $lineNo, int $firstGrn): ?int
    {
        $id = $db->value("SELECT MIN(r.document_id) FROM invoice_arrival_rest r JOIN document d ON d.id = r.document_id AND d.status IN ('draft', 'awaiting_approval', 'posted') "
            . "WHERE r.kind = 'claim' AND r.sinv_document_id = ? AND r.line_no = ? AND r.first_grn_document_id = ?", [$invoiceId, $lineNo, $firstGrn]);
        return $id === null ? null : (int) $id;
    }

    /**
     * @param list<int> $skus
     * @return array<int, array{0: string, 1: string}> code, name
     */
    private static function names(Db $db, array $skus): array
    {
        $out = [];
        foreach (array_chunk($skus, 500) as $chunk) {
            foreach ($db->all('SELECT id, code, name FROM sku WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $s) {
                $out[(int) $s['id']] = [(string) $s['code'], (string) $s['name']];
            }
        }
        return $out;
    }
}
