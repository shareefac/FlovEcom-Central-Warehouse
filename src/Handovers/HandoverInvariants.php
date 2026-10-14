<?php

declare(strict_types=1);

namespace CW\Handovers;

use CW\Db;

/**
 * The nightly checks of the hand-overs (pack H; docs/decisions.md PH14), called by CW\Invariants::check, so bin/invariants.php, the
 * hammer and every stock test run them. Read only; at most MAX_PER_CHECK problems per check.
 *
 *  H1. every TRD document has a `handover` header; every final hand-over (posted or reversed, not itself a cancellation) has its sums,
 *      and exactly one `handover` entry on what its customer owes, of its gross, on its customer; every posted cancellation has the
 *      header of its original with the sums negated, and exactly one `handover_reversal` entry, the negation of the original's.
 *  H2. every entry that names a record names a final hand-over (a `handover` entry) or a posted cancellation of one (a
 *      `handover_reversal` entry).
 *  H3. a final hand-over's sums: net = the sum of its lines' units x price to the penny; VAT = net x its rate to the penny (none
 *      without a VAT code); gross = net + VAT.
 *  H4. a final hand-over (and its cancellation) books only `trade_sale` on hand at its warehouse, and per item the negation of its
 *      lines' units.
 *  H5. a payment reversal mirrors a payment of the same customer (the amount negated); a remembered price names an existing item, and
 *      one remembered from a hand-over names a final one of the same customer.
 */
final class HandoverInvariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::headers($db), ...self::entries($db), ...self::sums($db), ...self::ledger($db), ...self::payments($db)];
    }

    /** @return list<string> H1 */
    private static function headers(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT d.id, d.number, d.status, d.reverses_id, h.document_id AS h_id, h.counterparty_id, h.gross_amount, h.net_amount, "
            . "oh.gross_amount AS o_gross, oh.counterparty_id AS o_cp, "
            . "(SELECT COUNT(*) FROM counterparty_entry e WHERE e.document_id = d.id) AS n, "
            . "(SELECT MIN(e.kind) FROM counterparty_entry e WHERE e.document_id = d.id) AS kind, "
            . "(SELECT SUM(e.amount) FROM counterparty_entry e WHERE e.document_id = d.id) AS amount, "
            . "(SELECT MIN(e.counterparty_id) FROM counterparty_entry e WHERE e.document_id = d.id) AS e_cp "
            . "FROM document d LEFT JOIN handover h ON h.document_id = d.id LEFT JOIN handover oh ON oh.document_id = d.reverses_id WHERE d.doc_type = 'TRD' "
            . 'ORDER BY d.id') as $r) {
            if (count($v) >= self::MAX_PER_CHECK) {
                break;
            }
            $label = $r['number'] ?? ('draft #' . $r['id']);
            if ($r['reverses_id'] !== null && $r['status'] !== 'posted') {
                continue; // a cancellation waiting for an OK (or refused one) has no header yet: its handler runs when it is posted
            }
            if ($r['h_id'] === null) {
                $v[] = "hand-over {$label}: no header (customer)";
                continue;
            }
            $final = in_array($r['status'], ['posted', 'reversed'], true);
            if (!$final) {
                if ($r['gross_amount'] !== null || (int) $r['n'] !== 0) {
                    $v[] = "hand-over {$label} is {$r['status']} but has sums or entries on what its customer owes";
                }
                continue;
            }
            if ($r['gross_amount'] === null || $r['net_amount'] === null) {
                $v[] = "hand-over {$label} is final without its sums";
                continue;
            }
            if ($r['reverses_id'] === null) {
                if ((int) $r['n'] !== 1 || $r['kind'] !== 'handover' || (int) $r['e_cp'] !== (int) $r['counterparty_id'] || bccomp((string) $r['amount'], (string) $r['gross_amount'], 2) !== 0) {
                    $v[] = "hand-over {$label}: " . (int) $r['n'] . ' entries on what its customer owes (' . ($r['kind'] ?? 'none') . ', ' . ($r['amount'] ?? '0')
                        . "), expected one hand-over entry of {$r['gross_amount']} on its customer";
                }
                continue;
            }
            $want = $r['o_gross'] === null ? null : HandoverMath::negate((string) $r['o_gross']);
            if ($want === null || bccomp((string) $r['gross_amount'], $want, 2) !== 0 || (int) $r['o_cp'] !== (int) $r['counterparty_id']) {
                $v[] = "cancellation {$label}: its header is not its hand-over's negated";
            }
            if ((int) $r['n'] !== 1 || $r['kind'] !== 'handover_reversal' || (int) $r['e_cp'] !== (int) $r['counterparty_id']
                || $want === null || bccomp((string) $r['amount'], $want, 2) !== 0) {
                $v[] = "cancellation {$label}: " . (int) $r['n'] . ' entries on what its customer owes (' . ($r['kind'] ?? 'none') . ', ' . ($r['amount'] ?? '0')
                    . '), expected one cancellation entry of ' . ($want ?? '?');
            }
        }
        return $v;
    }

    /** @return list<string> H2 */
    private static function entries(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT e.id, e.kind, d.number, d.doc_type, d.status, d.reverses_id FROM counterparty_entry e LEFT JOIN document d ON d.id = e.document_id "
            . "WHERE e.document_id IS NOT NULL AND (d.id IS NULL OR d.doc_type <> 'TRD' "
            . "OR (e.kind = 'handover' AND (d.reverses_id IS NOT NULL OR d.status NOT IN ('posted', 'reversed'))) "
            . "OR (e.kind = 'handover_reversal' AND (d.reverses_id IS NULL OR d.status <> 'posted'))) ORDER BY e.id LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "customer entry {$r['id']} ({$r['kind']}) names " . ($r['number'] ?? 'no final record') . ', not a final ' . ($r['kind'] === 'handover' ? 'hand-over' : 'cancellation of one');
        }
        return $v;
    }

    /** @return list<string> H3 */
    private static function sums(Db $db): array
    {
        $v = [];
        $lines = [];
        foreach ($db->all("SELECT l.document_id, l.sku_id, l.qty, l.unit_cost FROM document_line l JOIN document d ON d.id = l.document_id "
            . "WHERE d.doc_type = 'TRD' AND d.status IN ('posted', 'reversed') AND l.sku_id IS NOT NULL ORDER BY l.document_id, l.line_no") as $l) {
            $lines[(int) $l['document_id']][] = $l;
        }
        foreach ($db->all("SELECT d.id, d.number, h.vat_code, h.vat_rate, h.net_amount, h.vat_amount, h.gross_amount FROM document d JOIN handover h ON h.document_id = d.id "
            . "WHERE d.doc_type = 'TRD' AND d.status IN ('posted', 'reversed') AND h.net_amount IS NOT NULL ORDER BY d.id") as $r) {
            if (count($v) >= self::MAX_PER_CHECK) {
                break;
            }
            $net = HandoverMath::net($lines[(int) $r['id']] ?? []);
            $vat = HandoverMath::vat($net, $r['vat_rate'] === null ? null : (string) $r['vat_rate']);
            if (bccomp($net, (string) $r['net_amount'], 2) !== 0 || bccomp($vat, (string) $r['vat_amount'], 2) !== 0
                || bccomp(bcadd($net, $vat, 2), (string) $r['gross_amount'], 2) !== 0 || ($r['vat_rate'] !== null && $r['vat_code'] === null)) {
                $v[] = "hand-over {$r['number']}: sums {$r['net_amount']} + {$r['vat_amount']} = {$r['gross_amount']}, its lines give {$net} + {$vat}";
            }
        }
        return $v;
    }

    /** @return list<string> H4 */
    private static function ledger(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT sl.id, d.number FROM stock_ledger sl JOIN document d ON d.id = sl.document_id WHERE d.doc_type = 'TRD' "
            . "AND (sl.movement_type <> 'trade_sale' OR sl.warehouse_id <> d.warehouse_id OR sl.bucket <> 'on_hand') ORDER BY sl.id LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "hand-over {$r['number']}: ledger row {$r['id']} is not a trade sale on hand at its warehouse";
        }
        foreach ($db->all('SELECT x.document_id, x.sku_id, x.q, y.q AS booked, d.number FROM (SELECT l.document_id, l.sku_id, SUM(l.qty) AS q FROM document_line l '
            . "JOIN document d ON d.id = l.document_id WHERE d.doc_type = 'TRD' AND d.status IN ('posted', 'reversed') AND l.sku_id IS NOT NULL GROUP BY l.document_id, l.sku_id) x "
            . 'JOIN document d ON d.id = x.document_id LEFT JOIN (SELECT sl.document_id, sl.sku_id, SUM(sl.qty_delta) AS q FROM stock_ledger sl JOIN document dd ON dd.id = sl.document_id '
            . "WHERE dd.doc_type = 'TRD' AND sl.bucket = 'on_hand' GROUP BY sl.document_id, sl.sku_id) y ON y.document_id = x.document_id AND y.sku_id = x.sku_id "
            . 'WHERE y.q IS NULL OR y.q <> -x.q ORDER BY x.document_id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "hand-over {$r['number']}: item {$r['sku_id']} booked " . ($r['booked'] ?? 'nothing') . ', its lines say ' . (-(int) $r['q']);
        }
        return $v;
    }

    /** @return list<string> H5 */
    private static function payments(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT r.id, r.counterparty_id, r.amount, p.kind AS p_kind, p.counterparty_id AS p_cp, p.amount AS p_amount FROM counterparty_entry r "
            . "LEFT JOIN counterparty_entry p ON p.id = r.reverses_id WHERE r.kind = 'payment_reversal' ORDER BY r.id") as $r) {
            if (count($v) >= self::MAX_PER_CHECK) {
                break;
            }
            if ($r['p_kind'] !== 'payment' || (int) $r['p_cp'] !== (int) $r['counterparty_id'] || bccomp((string) $r['amount'], HandoverMath::negate((string) $r['p_amount']), 2) !== 0) {
                $v[] = "customer entry {$r['id']}: a payment reversal that does not mirror a payment of its customer";
            }
        }
        foreach ($db->all('SELECT p.counterparty_id, p.sku_id, p.source, p.document_id FROM counterparty_price p LEFT JOIN sku s ON s.id = p.sku_id '
            . "LEFT JOIN document d ON d.id = p.document_id LEFT JOIN handover h ON h.document_id = p.document_id WHERE s.id IS NULL OR (p.source = 'handover' AND "
            . "(d.id IS NULL OR d.doc_type <> 'TRD' OR d.status NOT IN ('posted', 'reversed') OR d.reverses_id IS NOT NULL OR h.counterparty_id <> p.counterparty_id)) "
            . 'LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "remembered price of customer {$r['counterparty_id']} for item {$r['sku_id']}: no such item, or not from a final hand-over of that customer";
        }
        return $v;
    }
}
