<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Clock;
use CW\Db;

/**
 * The read side of a Royal Mail (pooled) bill on Profit > Delivery bills (docs/decisions.md DL69). Reads only:
 *  - where its money went: per destination (on orders per shop, a shop's "not on an order" with the day lines apart, a party with no shop
 *    such as Hayati Vapes, before CW start, held per reason), adding up to its net;
 *  - the tie-out per service day and product of its flat rows: the day's labels, the units billed by every live bill, the labels priced, the
 *    day's line ("N labels not charged" or "N billed, no label found") and whether the day is open (waiting for the next bill: the account's
 *    next bill not stored yet and the day less than OPEN_DAYS old) or closed, "needs a look" only on a closed day LOOK_PCT and LOOK_LABELS apart;
 *  - its Special Delivery manifests (labels, billed, by weight or the average);
 *  - its rows as printed (the reference in its known form only);
 *  - the accounts' labels: read up to which day, the last pull, how many labels CW holds.
 */
final class RoyalMailViews
{
    /** A day stays open this many days after it (or until the account's next bill is stored). */
    public const OPEN_DAYS = 14;
    /** A closed day needs a look when its labels and units billed are more than this percent apart ... */
    public const LOOK_PCT = 5;
    /** ... and more than this many labels. */
    public const LOOK_LABELS = 10;

    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null)
    {
    }

    /**
     * One pooled bill's page.
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
        $account = $this->db->one('SELECT id, state FROM carrier_account WHERE carrier = ? AND account_no = ?', [(string) $b['carrier'], (string) $b['account']]);
        $live = $b['status'] === 'live';
        // where its money went (its live allocations; a bill that no longer counts: what it had, ended with it)
        $where = [];
        foreach ($this->db->all('SELECT a.dest, a.kind, a.channel_id, a.party_id, a.held_reason, COUNT(*) AS n, SUM(a.pence) AS p, c.name AS shop, pt.name AS party '
            . 'FROM carrier_allocation a LEFT JOIN channel c ON c.id = a.channel_id LEFT JOIN delivery_party pt ON pt.id = a.party_id WHERE a.invoice_id = ? '
            . ($live ? 'AND a.ended_at IS NULL ' : 'AND a.ended_at >= (SELECT x.ended_at FROM carrier_invoice x WHERE x.id = a.invoice_id) ')
            . 'GROUP BY a.dest, a.kind, a.channel_id, a.party_id, a.held_reason, c.name, pt.name ORDER BY a.dest, c.name, pt.name, a.held_reason', [$id]) as $r) {
            $group = match ((string) $r['dest']) {
                'order' => 'order',
                'shop' => $r['kind'] === 'day_line' ? 'day_line' : 'shop',
                default => (string) $r['dest'],
            };
            $k = $group . '|' . ($r['channel_id'] ?? '') . '|' . ($r['party_id'] ?? '') . '|' . ($r['held_reason'] ?? '');
            $where[$k] ??= ['group' => $group, 'name' => (string) ($r['shop'] ?? $r['party'] ?? ''), 'reason' => $r['held_reason'], 'n' => 0, 'pence' => 0];
            $where[$k]['n'] += (int) $r['n'];
            $where[$k]['pence'] += (int) $r['p'];
        }
        $order = ['order' => 1, 'shop' => 2, 'day_line' => 3, 'party' => 4, 'before_start' => 5, 'held' => 6];
        uasort($where, static fn (array $a, array $b): int => [$order[$a['group']] ?? 9, $a['name'], (string) $a['reason']] <=> [$order[$b['group']] ?? 9, $b['name'],
            (string) $b['reason']]);
        $sum = array_sum(array_column($where, 'pence'));
        $totals = array_fill_keys(array_keys($order), 0);
        foreach ($where as $w) {
            $totals[$w['group']] += $w['pence'];
        }
        $rows = $this->db->all('SELECT row_no, service_date, product_code, qty, net_pence, gross_pence, vat_code, ref_kind, ref_no, sales_order FROM carrier_bill_row '
            . 'WHERE invoice_id = ? ORDER BY row_no', [$id]);
        $rowsNet = array_sum(array_map(static fn (array $r): int => (int) $r['net_pence'], $rows));
        return [
            'bill' => $b,
            'where' => array_values($where),
            'totals' => $totals,
            'adds_up' => $sum === (int) $b['net_pence'] && $rowsNet === (int) $b['net_pence'] && count($rows) === (int) $b['line_count'],
            'days' => $account === null ? [] : $this->days((int) $account['id'], $b, $rows),
            'manifests' => $this->manifests($id),
            'rows' => $rows,
            'not_stored' => $b['status'] === 'undone' && $b['ended_actor'] === RoyalMailBills::NOT_STORED,
            'replaced' => $b['supersedes_id'] === null ? null : $this->db->one('SELECT id, invoice_no, uploaded_at FROM carrier_invoice WHERE id = ?', [(int) $b['supersedes_id']]),
            'replaced_by' => $this->db->one("SELECT id, invoice_no, uploaded_at FROM carrier_invoice WHERE supersedes_id = ? AND status <> 'loading' "
                . "AND NOT (status = 'undone' AND ended_actor = ?)", [$id, RoyalMailBills::NOT_STORED]),
        ];
    }

    /**
     * Where a checked bill's money would go (the upload screen's check, DL79), in the groups and order of its page once stored (bill()):
     * on orders per shop, a shop's "not on an order", the days' lines, a party with no shop, before CW start per shop, held per reason.
     *
     * @param array<string, int> $where RoyalMailBills::preview()['where']: "dest|shop|party|reason|day_line" => pence
     * @return list<array{group: string, name: string, reason: ?string, pence: int}>
     */
    public function checkWhere(array $where): array
    {
        $shops = [];
        foreach ($this->db->all('SELECT id, name FROM channel') as $r) {
            $shops[(int) $r['id']] = (string) $r['name'];
        }
        $parties = [];
        foreach ($this->db->all('SELECT id, name FROM delivery_party') as $r) {
            $parties[(int) $r['id']] = (string) $r['name'];
        }
        $out = [];
        foreach ($where as $k => $pence) {
            [$dest, $ch, $party, $reason, $dayLine] = array_pad(explode('|', (string) $k), 5, '');
            $group = match ($dest) {
                'order' => 'order',
                'shop' => $dayLine === 'day_line' ? 'day_line' : 'shop',
                default => $dest,
            };
            $key = $group . '|' . $ch . '|' . $party . '|' . $reason;
            $out[$key] ??= ['group' => $group, 'name' => $ch !== '' ? ($shops[(int) $ch] ?? '') : ($party !== '' ? ($parties[(int) $party] ?? '') : ''),
                'reason' => $reason === '' ? null : $reason, 'pence' => 0];
            $out[$key]['pence'] += (int) $pence;
        }
        $order = ['order' => 1, 'shop' => 2, 'day_line' => 3, 'party' => 4, 'before_start' => 5, 'held' => 6];
        uasort($out, static fn (array $a, array $b): int => [$order[$a['group']] ?? 9, $a['name'], (string) $a['reason']] <=> [$order[$b['group']] ?? 9, $b['name'],
            (string) $b['reason']]);
        return array_values($out);
    }

    /**
     * The tie-out of the bill's flat days (service day x product): labels, units billed by every live bill, labels priced, the day's line, open
     * or closed, needs a look.
     *
     * @param array<string, mixed> $bill
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function days(int $accountId, array $bill, array $rows): array
    {
        $flat = [];
        foreach ($this->db->all("SELECT product_code, service_code FROM carrier_product WHERE carrier = ? AND kind = 'flat'", [(string) $bill['carrier']]) as $p) {
            $flat[(string) $p['product_code']] = (string) $p['service_code'];
        }
        $keys = [];
        foreach ($rows as $r) {
            if (isset($flat[(string) $r['product_code']])) {
                $keys[$r['service_date'] . '|' . $r['product_code']] = [(string) $r['service_date'], (string) $r['product_code']];
            }
        }
        ksort($keys);
        $today = (($this->clock ?? static fn (): \DateTimeImmutable => Clock::now())())->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        $out = [];
        foreach ($keys as [$day, $product]) {
            $labels = (int) $this->db->value('SELECT COUNT(*) FROM carrier_label WHERE account_id = ? AND manifest_day = ? AND service_code = ?',
                [$accountId, $day, $flat[$product]]);
            $billed = $this->db->one("SELECT COALESCE(SUM(r.qty), 0) AS q, COALESCE(SUM(r.net_pence), 0) AS p, MAX(i.invoice_date) AS last, MIN(i.invoice_date) AS first "
                . "FROM carrier_bill_row r JOIN carrier_invoice i ON i.id = r.invoice_id AND i.status = 'live' AND i.carrier = ? AND i.account = ? "
                . 'WHERE r.service_date = ? AND r.product_code = ?', [(string) $bill['carrier'], (string) $bill['account'], $day, $product]) ?? [];
            $alloc = $this->db->one("SELECT SUM(a.kind = 'label') AS priced, COALESCE(SUM(CASE WHEN a.kind = 'label' THEN a.pence ELSE 0 END), 0) AS priced_p, "
                . "COALESCE(SUM(CASE WHEN a.kind = 'day_line' THEN a.pence ELSE 0 END), 0) AS line_p FROM carrier_allocation a JOIN carrier_bill_row r ON r.invoice_id = a.invoice_id "
                . "AND r.row_no = a.row_no JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' AND i.carrier = ? AND i.account = ? "
                . 'WHERE a.ended_at IS NULL AND r.service_date = ? AND r.product_code = ?', [(string) $bill['carrier'], (string) $bill['account'], $day, $product]) ?? [];
            $first = (string) ($billed['first'] ?? '');
            $next = $first === '' ? null : $this->db->value("SELECT 1 FROM carrier_invoice WHERE status = 'live' AND carrier = ? AND account = ? AND invoice_date > ? LIMIT 1",
                [(string) $bill['carrier'], (string) $bill['account'], $first]);
            $closed = $next !== null || DeliveryBills::daysBetween($day, $today) > self::OPEN_DAYS;
            $q = (int) ($billed['q'] ?? 0);
            $diff = $labels - $q;
            $look = $closed && abs($diff) > self::LOOK_LABELS && abs($diff) * 100 > self::LOOK_PCT * max($labels, $q);
            $out[] = ['day' => $day, 'product' => $product, 'labels' => $labels, 'billed' => $q, 'billed_pence' => (int) ($billed['p'] ?? 0),
                'priced' => (int) ($alloc['priced'] ?? 0), 'priced_pence' => (int) ($alloc['priced_p'] ?? 0), 'line_pence' => (int) ($alloc['line_p'] ?? 0),
                'difference' => $diff, 'closed' => $closed, 'look' => $look];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the bill's Special Delivery manifests: rows, units, net, labels, how they were priced */
    private function manifests(int $id): array
    {
        $out = [];
        foreach ($this->db->all("SELECT r.ref_no AS manifest, COUNT(*) AS rows_n, SUM(r.qty) AS qty, SUM(r.net_pence) AS net FROM carrier_bill_row r JOIN carrier_product p "
            . "ON p.carrier = 'royal_mail' AND p.product_code = r.product_code AND p.kind = 'band' WHERE r.invoice_id = ? AND r.ref_kind = 'manifest' GROUP BY r.ref_no "
            . 'ORDER BY CAST(r.ref_no AS UNSIGNED)', [$id]) as $m) {
            $how = $this->db->column("SELECT DISTINCT a.how FROM carrier_allocation a JOIN carrier_bill_row r ON r.invoice_id = a.invoice_id AND r.row_no = a.row_no "
                . "WHERE a.invoice_id = ? AND r.ref_kind = 'manifest' AND r.ref_no = ? AND a.ended_at IS NULL", [$id, (string) $m['manifest']]);
            $labels = (int) $this->db->value("SELECT COUNT(DISTINCT a.label_id) FROM carrier_allocation a JOIN carrier_bill_row r ON r.invoice_id = a.invoice_id "
                . "AND r.row_no = a.row_no WHERE a.invoice_id = ? AND r.ref_kind = 'manifest' AND r.ref_no = ? AND a.ended_at IS NULL AND a.label_id IS NOT NULL",
                [$id, (string) $m['manifest']]);
            $out[] = ['manifest' => (string) $m['manifest'], 'rows' => (int) $m['rows_n'], 'qty' => (int) $m['qty'], 'net' => (int) $m['net'], 'labels' => $labels,
                'how' => implode(', ', array_map('strval', $how))];
        }
        return $out;
    }

    /**
     * The pooled accounts with their labels: how many CW holds, read up to which day, the last pull and its error.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        return $this->db->all("SELECT a.id, a.account_no, a.name, a.state, a.key_name, p.read_to, p.last_try_at, p.last_ok_at, p.last_error, "
            . '(SELECT COUNT(*) FROM carrier_label l WHERE l.account_id = a.id) AS labels, (SELECT MAX(l.manifest_day) FROM carrier_label l WHERE l.account_id = a.id) AS last_day '
            . "FROM carrier_account a JOIN carrier c ON c.code = a.carrier AND c.family = 'pooled' LEFT JOIN carrier_label_pull p ON p.account_id = a.id ORDER BY a.id");
    }

    /**
     * A list's pooled bills summed by destination (their live allocations): on orders, a shop's "not on an order", in no shop's profit (a party
     * with no shop, held), before CW start.
     *
     * @param list<int> $ids
     * @return array<int, array{order: array{n: int, p: int}, shop: array{n: int, p: int}, outside: array{n: int, p: int}, before_start: array{n: int, p: int}}>
     */
    public function sums(array $ids): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        // a live bill: its live allocations; a replaced or undone one: what it had when it stopped counting (its allocations ended at or after its
        // own end, however many sweeps that took); a bill loading or not stored: nothing (the Codex checks r1, r2, LOW 20)
        foreach ($this->db->all("SELECT a.invoice_id, a.dest, COUNT(*) AS n, SUM(a.pence) AS p FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id "
            . "WHERE a.invoice_id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ") AND ((i.status = 'live' AND a.ended_at IS NULL) OR (i.status IN "
            . "('superseded', 'undone') AND i.ended_actor <> ? AND a.ended_at >= i.ended_at)) "
            . 'GROUP BY a.invoice_id, a.dest', [...$ids, RoyalMailBills::NOT_STORED]) as $r) {
            $id = (int) $r['invoice_id'];
            $out[$id] ??= ['order' => ['n' => 0, 'p' => 0], 'shop' => ['n' => 0, 'p' => 0], 'outside' => ['n' => 0, 'p' => 0], 'before_start' => ['n' => 0, 'p' => 0]];
            $k = match ((string) $r['dest']) {
                'order' => 'order',
                'shop' => 'shop',
                'before_start' => 'before_start',
                default => 'outside',
            };
            $out[$id][$k]['n'] += (int) $r['n'];
            $out[$id][$k]['p'] += (int) $r['p'];
        }
        return $out;
    }

    /**
     * An order's Royal Mail cost per parcel (live allocations on it): parcel ref => its pence, its bills, and what for (the label's price, a
     * wrong-weight share).
     *
     * @param list<string> $refs the order and, for a first order, its re-sends
     * @return array<string, array{pence: int, bills: list<string>, kinds: array<string, int>}>
     */
    public static function orderParcels(Db $db, int $channelId, string $ref): array
    {
        $out = [];
        foreach ($db->all("SELECT a.parcel_ref, a.kind, SUM(a.pence) AS p, i.invoice_no FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id "
            . "AND i.status = 'live' WHERE a.channel_id = ? AND a.order_ref = ? AND a.dest = 'order' AND a.ended_at IS NULL GROUP BY a.parcel_ref, a.kind, i.invoice_no "
            . 'ORDER BY a.parcel_ref, i.invoice_no, a.kind', [$channelId, $ref]) as $r) {
            $k = strtolower((string) $r['parcel_ref']);
            $out[$k] ??= ['pence' => 0, 'bills' => [], 'kinds' => []];
            $out[$k]['pence'] += (int) $r['p'];
            $out[$k]['bills'][] = (string) $r['invoice_no'];
            $out[$k]['kinds'][(string) $r['kind']] = ($out[$k]['kinds'][(string) $r['kind']] ?? 0) + (int) $r['p'];
            $out[$k]['bills'] = array_values(array_unique($out[$k]['bills']));
        }
        return $out;
    }
}
