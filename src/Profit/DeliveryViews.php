<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;
use CW\Reports\ReportRefresh;

/**
 * The read side of Profit > Delivery bills and of the delivery on an order's profit page (docs/decisions.md DL23). Reads only, by index:
 * the bills newest first, one bill's tie-out (its lines by state), its lines by state a page at a time (the lines on no order the dearest
 * first), and an order's parcels with what their bill lines cost.
 */
final class DeliveryViews
{
    /** Lines on one page of a bill. */
    public const PAGE = 100;
    /** The most bills the list shows (newest upload first). */
    public const LIST_LIMIT = 200;
    /** The filters of a bill's lines: the default shows the lines on no order (the ones to look at), the dearest first. */
    public const FILTERS = ['other' => ['not_matched', 'ambiguous'], 'not_matched' => ['not_matched'], 'ambiguous' => ['ambiguous'], 'matched' => ['matched'],
        'collection' => ['collection'], 'before_start' => ['before_start'], 'all' => DeliveryBills::STATES];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The bills, newest upload first, each with its lines on orders, on no order and before CW start.
     *
     * @return list<array<string, mixed>>
     */
    public function bills(): array
    {
        $rows = $this->db->all('SELECT i.id, i.carrier, i.account, i.invoice_no, i.period_from, i.period_to, i.net_pence, i.line_count, i.status, i.uploaded_actor, '
            . 'i.uploaded_at, i.ended_actor, c.name AS shop, u.display_name AS uploaded_name FROM carrier_invoice i JOIN channel c ON c.id = i.channel_id '
            . 'LEFT JOIN staff_user u ON u.id = i.uploaded_by ORDER BY i.uploaded_at DESC, i.id DESC LIMIT ' . self::LIST_LIMIT);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $states = $this->states($ids);
        // a pooled bill (Royal Mail, 0078: DL69) has no lines: its allocations summed by destination
        $pooled = (new RoyalMailViews($this->db))->sums(array_map(static fn (array $r): int => (int) $r['id'], array_values(array_filter($rows,
            static fn (array $r): bool => $r['carrier'] !== 'dpd'))));
        return array_map(static fn (array $r): array => $r + ['states' => $states[(int) $r['id']] ?? self::noStates(), 'pooled' => $r['carrier'] === 'dpd' ? null
            : ($pooled[(int) $r['id']] ?? ['order' => ['n' => 0, 'p' => 0], 'shop' => ['n' => 0, 'p' => 0], 'outside' => ['n' => 0, 'p' => 0],
                'before_start' => ['n' => 0, 'p' => 0]])], $rows);
    }

    /**
     * One bill: its row, its shop and who uploaded / ended it, the bill it replaced and the one that replaced it, and its tie-out: per state
     * its lines and pence, the lines on orders not paid yet, and whether the states add up to its net.
     *
     * @return array<string, mixed>|null
     */
    public function bill(int $id): ?array
    {
        $b = $this->db->one('SELECT i.*, c.name AS shop, u.display_name AS uploaded_name, e.display_name AS ended_name FROM carrier_invoice i '
            . 'JOIN channel c ON c.id = i.channel_id LEFT JOIN staff_user u ON u.id = i.uploaded_by LEFT JOIN staff_user e ON e.id = i.ended_by WHERE i.id = ?', [$id]);
        if ($b === null) {
            return null;
        }
        $states = $this->states([$id])[$id] ?? self::noStates();
        $unpaid = $this->db->one("SELECT COUNT(*) AS n, COALESCE(SUM(l.cost), 0) AS p FROM carrier_invoice_line l JOIN sale_order o ON o.channel_id = l.channel_id "
            . "AND o.order_ref = l.order_ref WHERE l.invoice_id = ? AND l.match_state IN ('matched', 'collection') AND o.paid_at IS NULL", [$id]) ?? [];
        $sum = array_sum(array_column($states, 'pence'));
        $lines = array_sum(array_column($states, 'lines'));
        return [
            'bill' => $b,
            'states' => $states,
            'on_orders' => ['lines' => $states['matched']['lines'] + $states['collection']['lines'], 'pence' => $states['matched']['pence'] + $states['collection']['pence']],
            'other' => ['lines' => $states['not_matched']['lines'] + $states['ambiguous']['lines'], 'pence' => $states['not_matched']['pence'] + $states['ambiguous']['pence']],
            'unpaid' => ['lines' => (int) ($unpaid['n'] ?? 0), 'pence' => (int) ($unpaid['p'] ?? 0)],
            'adds_up' => $sum === (int) $b['net_pence'] && $lines === (int) $b['line_count'],
            'replaced' => $b['supersedes_id'] === null ? null : $this->db->one('SELECT id, invoice_no, uploaded_at FROM carrier_invoice WHERE id = ?', [(int) $b['supersedes_id']]),
            'replaced_by' => $this->db->one('SELECT id, invoice_no, uploaded_at FROM carrier_invoice WHERE supersedes_id = ?', [$id]),
        ];
    }

    /**
     * A bill's lines of a filter, a page at a time: the lines on no order the dearest first, the others in line order; each with the order it
     * is on (its number) and, for an ambiguous one, the orders whose parcels fit.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function lines(int $id, string $filter, int $page): array
    {
        $states = self::FILTERS[$filter] ?? self::FILTERS['other'];
        $marks = implode(', ', array_fill(0, count($states), '?'));
        $total = (int) $this->db->value("SELECT COUNT(*) FROM carrier_invoice_line WHERE invoice_id = ? AND match_state IN ({$marks})", [$id, ...$states]);
        $pages = max(1, (int) ceil($total / self::PAGE));
        $page = max(1, min($page, $pages));
        $order = in_array($filter, ['other', 'not_matched', 'ambiguous'], true) ? 'l.cost DESC, l.line_no' : 'l.line_no';
        $shop = (int) $this->db->value('SELECT channel_id FROM carrier_invoice WHERE id = ?', [$id]);
        $rows = $this->db->all('SELECT l.line_no, l.line_date, l.consignment, l.marks, l.parcel_no, l.product, l.service, l.weight, l.items, l.vat_code, l.cost, '
            . 'l.match_state, l.channel_id, l.order_ref, l.parcel_ref, l.candidates, l.senders_ref, l.money, o.order_no FROM carrier_invoice_line l '
            . 'LEFT JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
            . "WHERE l.invoice_id = ? AND l.match_state IN ({$marks}) ORDER BY {$order} LIMIT " . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE), [$id, ...$states]);
        foreach ($rows as $i => $r) {
            $fits = [];
            if ($r['match_state'] === 'ambiguous') {
                // Two parcels of its consignment in the window, else two orders of its Senders Ref (a collection: the Codex check r2, LOW 1).
                $fits = $this->fits((string) $r['line_date'], $r['consignment']);
                if (count($fits) < 2 && $r['senders_ref'] !== null && array_key_exists(DpdInvoiceFile::COLLECTION, (array) json_decode((string) $r['money'], true))) {
                    $fits = array_map(static fn (array $o): array => ['order_ref' => (string) $o['order_ref'], 'order_no' => (string) $o['order_no'], 'parcel_ref' => '',
                        'label_at' => ''], $this->db->all('SELECT order_ref, order_no FROM sale_order WHERE channel_id = ? AND order_no = ? ORDER BY order_ref',
                        [$shop, DeliveryBills::orderNumber((string) $r['senders_ref'])]));
                }
            }
            $rows[$i]['fits'] = $fits;
            unset($rows[$i]['money']);
        }
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * The delivery of one order (its page): what its parcels' bill lines and the collections on it cost (live bills), each parcel with its
     * lines or "not billed yet"; for a first order, what its re-sends' lines cost too (they belong to it, PR143).
     *
     * @param list<array<string, mixed>> $parcels the order's sale_parcel rows
     * @param list<string> $resends its re-sends' refs (OrderSnapshots::descendants), [] for a re-send itself
     * @return array{cost: int, lines: int, resend_cost: int, resend_lines: int, parcels: list<array<string, mixed>>, collections: list<array<string, mixed>>,
     *   not_billed: int}
     */
    public static function order(Db $db, int $channelId, string $ref, array $parcels, array $resends): array
    {
        $own = $db->all("SELECT l.invoice_id, l.line_no, l.line_date, l.parcel_ref, l.match_state, l.cost, l.service, i.invoice_no, i.carrier FROM carrier_invoice_line l "
            . "JOIN carrier_invoice i ON i.id = l.invoice_id AND i.status = 'live' WHERE l.channel_id = ? AND l.order_ref = ? AND l.match_state IN ('matched', 'collection') "
            . 'ORDER BY l.line_date, l.invoice_id, l.line_no', [$channelId, $ref]);
        $byParcel = [];
        $collections = [];
        foreach ($own as $l) {
            if ($l['match_state'] === 'collection') {
                $collections[] = $l;
            } else {
                $byParcel[strtolower((string) $l['parcel_ref'])][] = $l;
            }
        }
        $out = [];
        $notBilled = 0;
        // Royal Mail's (pooled) allocations on the order's parcels (0078, DL69), and whether its shop is served by a Royal Mail account CW reads
        $rm = RoyalMailViews::orderParcels($db, $channelId, $ref);
        $rmRead = $db->value("SELECT 1 FROM carrier_account a JOIN carrier c ON c.code = a.carrier AND c.family = 'pooled' JOIN carrier_account_party ap ON ap.account_id = a.id "
            . "JOIN delivery_party p ON p.id = ap.party_id AND p.channel_id = ? WHERE a.state = 'active' LIMIT 1", [$channelId]) !== null;
        foreach ($parcels as $p) {
            $lines = $byParcel[strtolower((string) $p['parcel_ref'])] ?? [];
            $pooled = $rm[strtolower((string) $p['parcel_ref'])] ?? null;
            // A DPD parcel can be "not billed yet" (DL38); a Royal Mail one too once its shop is on a Royal Mail account CW reads (DL69); a manual
            // or in-store one never.
            $carrier = strtolower((string) $p['carrier']);
            $waits = $lines === [] && $pooled === null && $p['removed_at'] === null && (in_array($carrier, DeliveryBills::DPD_PARCELS, true)
                || ($rmRead && $carrier === 'royal_mail'));
            $out[] = $p + ['bill_lines' => $lines, 'bill_cost' => array_sum(array_map(static fn (array $l): int => (int) $l['cost'], $lines)) + ($pooled['pence'] ?? 0),
                'bills' => array_values(array_unique([...array_map(static fn (array $l): string => (string) $l['invoice_no'], $lines), ...($pooled['bills'] ?? [])])),
                'pooled' => $pooled, 'not_billed' => $waits];
            if ($waits) {
                $notBilled++;
            }
        }
        $pooledCost = array_sum(array_map(static fn (array $x): int => $x['pence'], $rm));
        $pooledN = (int) $db->value("SELECT COUNT(*) FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' WHERE a.channel_id = ? "
            . "AND a.order_ref = ? AND a.dest = 'order' AND a.ended_at IS NULL", [$channelId, $ref]);
        $rc = ['n' => 0, 'c' => 0];
        if ($resends !== []) {
            $rc = $db->one("SELECT COUNT(*) AS n, COALESCE(SUM(l.cost), 0) AS c FROM carrier_invoice_line l JOIN carrier_invoice i ON i.id = l.invoice_id AND i.status = 'live' "
                . "WHERE l.channel_id = ? AND l.order_ref IN (" . implode(', ', array_fill(0, count($resends), '?')) . ") AND l.match_state IN ('matched', 'collection')",
                [$channelId, ...$resends]) ?? $rc;
            $ra = $db->one("SELECT COUNT(*) AS n, COALESCE(SUM(a.pence), 0) AS c FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' "
                . 'WHERE a.channel_id = ? AND a.order_ref IN (' . implode(', ', array_fill(0, count($resends), '?')) . ") AND a.dest = 'order' AND a.ended_at IS NULL",
                [$channelId, ...$resends]) ?? ['n' => 0, 'c' => 0];
            $rc = ['n' => (int) $rc['n'] + (int) $ra['n'], 'c' => (int) $rc['c'] + (int) $ra['c']];
        }
        return ['cost' => array_sum(array_map(static fn (array $l): int => (int) $l['cost'], $own)) + $pooledCost, 'lines' => count($own) + $pooledN,
            'resend_cost' => (int) $rc['c'], 'resend_lines' => (int) $rc['n'], 'parcels' => $out, 'collections' => $collections, 'not_billed' => $notBilled];
    }

    /**
     * The orders whose DPD parcels fit an ambiguous line (its consignment, in the window): what a person checks.
     *
     * @return list<array{order_ref: string, order_no: string, parcel_ref: string, label_at: string}>
     */
    private function fits(string $date, ?string $consignment): array
    {
        if ($consignment === null || $consignment === '') {
            return [];
        }
        $keys = [$consignment];
        $out = [];
        foreach ($this->db->all('SELECT p.order_ref, p.parcel_ref, p.label_at, p.carrier, o.order_no FROM sale_parcel p JOIN sale_order o ON o.channel_id = p.channel_id '
            . 'AND o.order_ref = p.order_ref WHERE p.tracking_no IN (' . implode(', ', array_fill(0, count($keys), '?')) . ') AND p.removed_at IS NULL AND p.label_at IS NOT NULL '
            . 'ORDER BY p.label_at, p.order_ref', $keys) as $p) {
            $gap = DeliveryBills::daysBetween(ReportRefresh::ukDay((string) $p['label_at']), $date);
            if (in_array(strtolower((string) $p['carrier']), DeliveryBills::DPD_PARCELS, true) && $gap >= -DeliveryBills::WINDOW_AFTER && $gap <= DeliveryBills::WINDOW_BEFORE) {
                $out[] = ['order_ref' => (string) $p['order_ref'], 'order_no' => (string) $p['order_no'], 'parcel_ref' => (string) $p['parcel_ref'],
                    'label_at' => (string) $p['label_at']];
            }
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, array{lines: int, pence: int}>> bill id => state => its lines and pence
     */
    private function states(array $ids): array
    {
        $out = [];
        foreach ($this->db->all('SELECT invoice_id, match_state, COUNT(*) AS n, COALESCE(SUM(cost), 0) AS p FROM carrier_invoice_line WHERE invoice_id IN ('
            . implode(', ', array_fill(0, count($ids), '?')) . ') GROUP BY invoice_id, match_state', $ids) as $r) {
            $out[(int) $r['invoice_id']] ??= self::noStates();
            $out[(int) $r['invoice_id']][(string) $r['match_state']] = ['lines' => (int) $r['n'], 'pence' => (int) $r['p']];
        }
        return $out;
    }

    /** @return array<string, array{lines: int, pence: int}> */
    private static function noStates(): array
    {
        return array_fill_keys(DeliveryBills::STATES, ['lines' => 0, 'pence' => 0]);
    }
}
