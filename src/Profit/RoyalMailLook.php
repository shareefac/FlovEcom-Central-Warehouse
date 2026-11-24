<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;
use CW\Settings;

/**
 * The "needs a look" list of a Royal Mail (pooled) account (docs/decisions.md DL86 3-7): what staff should look at, NEVER money (every penny
 * of every row is allocated by PooledPlanner whatever is on this list). Of the days covered by a report after the main shop's CW start:
 *  parcel_missing  a CW Royal Mail parcel (a manual one with a Royal Mail number too), outbound, labelled that day (UK), in no report of the
 *                  account: it gets no cost, the unit billed for it is in the day's billed-more line (DL86 3; the Codex check r10 #10)
 *  odd_service     a label of that day with no service, a service CW does not map, or another than its CW parcel's: it got no price (DL86 4)
 *  billed_more     a day x Tracked product billed more units (every live bill) than its priced labels by MORE than the setting: red (DL86 7)
 *  manifest        a Special Delivery manifest billed more units than its labels by more than the setting (one with no label at all): red
 *  no_labels       a wrong-weight sales order with no label, a Special Delivery row CW cannot put on one manifest: on the main shop's "not on
 *                  an order" (DL86 5, 6; the Special Delivery one red)
 * The alarm is a CW setting (`delivery.rm_billed_more_pct`, the owner's 1%): red when (billed - labels) x 100 > setting x labels.
 */
final class RoyalMailLook
{
    public const SETTING = 'delivery.rm_billed_more_pct';
    /** The alarm while its setting row is not there: 1.0% (the owner, 24 Nov 2026). */
    public const DEFAULT_BP = 100;

    /** The alarm in basis points of the labels (100 = 1%). */
    public static function alarmBp(Db $db): int
    {
        $s = new Settings($db);
        $v = $s->has(self::SETTING) ? $s->get(self::SETTING) : null;
        return (is_string($v) ? ProfitMath::vatBp($v) : null) ?? self::DEFAULT_BP;
    }

    /** The alarm as a person reads it: "1", "2.5" (percent). */
    public static function pctText(int $bp): string
    {
        return rtrim(rtrim(sprintf('%d.%02d', intdiv($bp, 100), $bp % 100), '0'), '.');
    }

    /** Whether units billed are above the labels by more than the alarm ($bp basis points of the labels; no label and a unit billed: red). */
    public static function red(int $billed, int $labels, int $bp): bool
    {
        return $billed > $labels && ($billed - $labels) * 10000 > $bp * $labels;
    }

    /** The list for a stored bill: its rows' days (with every live bill of them). @return list<array<string, mixed>> */
    public static function forBill(Db $db, int $invoiceId): array
    {
        $inv = $db->one('SELECT carrier, account FROM carrier_invoice WHERE id = ?', [$invoiceId]);
        $id = $inv === null ? null : $db->value('SELECT id FROM carrier_account WHERE carrier = ? AND account_no = ?', [(string) $inv['carrier'], (string) $inv['account']]);
        if ($id === null) {
            return [];
        }
        return self::forDays($db, (int) $id, array_map('strval', $db->column('SELECT DISTINCT service_date FROM carrier_bill_row WHERE invoice_id = ?', [$invoiceId])));
    }

    /**
     * The list for the days of an account's live bills within the nightly's days (Profit > Delivery bills, the health alert).
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(Db $db, int $accountId, \DateTimeImmutable $now, int $days = RoyalMailBills::REPLAN_DAYS): array
    {
        $a = CarrierSettings::account($db, $accountId);
        $since = $now->setTimezone(new \DateTimeZone('Europe/London'))->modify("-{$days} days")->format('Y-m-d');
        return self::forDays($db, $accountId, array_map('strval', $db->column("SELECT DISTINCT r.service_date FROM carrier_bill_row r JOIN carrier_invoice i "
            . "ON i.id = r.invoice_id AND i.status = 'live' AND i.carrier = ? AND i.account = ? WHERE r.service_date >= ?", [$a['carrier'], $a['account_no'], $since])));
    }

    /**
     * The list of these days (service or manifest days, UK): only those covered by a report import, after the main shop's CW start.
     *
     * @param list<string> $days
     * @param list<array<string, mixed>> $newRows the rows of a bill being checked (invoice 0), counted with the live bills
     * @param list<int> $gone bills that stop counting with it (a corrected file's old bill)
     * @return list<array<string, mixed>> each with kind, day, red and its own fields, by day, then kind
     */
    public static function forDays(Db $db, int $accountId, array $days, array $newRows = [], array $gone = []): array
    {
        $account = CarrierSettings::account($db, $accountId);
        $start = DeliveryBills::startDay($db, (int) $account['main_channel']);
        $marks = static fn (int $n): string => implode(', ', array_fill(0, $n, '?'));
        $days = array_values(array_unique(array_filter(array_map('strval', $days), static fn (string $d): bool => $start !== null && $d > $start)));
        sort($days, SORT_STRING);
        $covered = [];
        foreach (array_chunk($days, 300) as $chunk) {
            foreach ($db->column('SELECT DISTINCT day FROM carrier_day_cover WHERE account_id = ? AND day IN (' . $marks(count($chunk)) . ')', [$account['id'], ...$chunk]) as $d) {
                $covered[(string) $d] = true;
            }
        }
        $days = array_values(array_filter($days, static fn (string $d): bool => isset($covered[$d])));
        if ($days === []) {
            return [];
        }
        $products = CarrierSettings::products($db, $account['carrier']);
        $flat = [];
        $band = [];
        $kind = [];
        foreach ($products as $code => $p) {
            $kind[$code] = $p['kind'];
            if ($p['kind'] === 'flat' && $p['service_code'] !== null) {
                $flat[$p['service_code']] = $code;
            } elseif ($p['kind'] === 'band' && $p['service_code'] !== null) {
                $band[] = $p['service_code'];
            }
        }
        $mapped = [...array_keys($flat), ...$band];
        $bp = self::alarmBp($db);
        $out = [];
        // the labels of the days: their counts per day and service, and the odd ones (DL86 4)
        $count = [];
        $odd = [];
        foreach (array_chunk($days, 31) as $chunk) {
            $in = $marks(count($chunk));
            foreach ($db->all("SELECT manifest_day, service_code, COUNT(*) AS n FROM carrier_label WHERE account_id = ? AND manifest_day IN ({$in}) "
                . 'GROUP BY manifest_day, service_code', [$account['id'], ...$chunk]) as $r) {
                if ($r['service_code'] !== null && isset($flat[(string) $r['service_code']])) {
                    $k = $r['manifest_day'] . '|' . $flat[(string) $r['service_code']];
                    $count[$k] = ($count[$k] ?? 0) + (int) $r['n'];
                }
            }
            foreach ($db->all("SELECT tracking_no, service_code, manifest_day FROM carrier_label WHERE account_id = ? AND manifest_day IN ({$in}) AND (service_code IS NULL"
                . ($mapped === [] ? '' : ' OR service_code NOT IN (' . $marks(count($mapped)) . ')') . ')', [$account['id'], ...$chunk, ...$mapped]) as $r) {
                $odd[] = ['kind' => 'odd_service', 'day' => (string) $r['manifest_day'], 'red' => false, 'tracking_no' => strtoupper((string) $r['tracking_no']),
                    'why' => $r['service_code'] === null ? 'no_service' : 'unknown_service', 'service' => $r['service_code'] === null ? null : (string) $r['service_code'],
                    'cw_service' => null, 'product' => null];
            }
            foreach (self::differs($db, (int) $account['id'], 'l.manifest_day IN (' . $in . ')', $chunk, $mapped) as $r) {
                $product = $flat[(string) $r['service_code']] ?? null;
                if ($product !== null) {
                    $count[$r['manifest_day'] . '|' . $product]--;
                }
                $odd[] = ['kind' => 'odd_service', 'day' => (string) $r['manifest_day'], 'red' => false, 'tracking_no' => strtoupper((string) $r['tracking_no']),
                    'why' => 'service_differs', 'service' => (string) $r['service_code'], 'cw_service' => (string) $r['cw'], 'product' => $product];
            }
        }
        // the rows of the days: every live bill's (the bills going left out, the one being checked in)
        $not = $gone === [] ? '' : ' AND i.id NOT IN (' . $marks(count($gone)) . ')';
        $rows = [];
        foreach (array_chunk($days, 300) as $chunk) {
            foreach ($db->all("SELECT r.service_date, r.product_code, r.qty, r.net_pence, r.ref_kind, r.ref_no, r.sales_order FROM carrier_bill_row r JOIN carrier_invoice i "
                . "ON i.id = r.invoice_id AND i.status = 'live' AND i.carrier = ? AND i.account = ? WHERE r.service_date IN (" . $marks(count($chunk)) . "){$not}",
                [$account['carrier'], $account['account_no'], ...$chunk, ...$gone]) as $r) {
                $rows[] = ['service_date' => (string) $r['service_date'], 'product_code' => (string) $r['product_code'], 'qty' => (int) $r['qty'], 'net' => (int) $r['net_pence'],
                    'ref_kind' => (string) $r['ref_kind'], 'ref_no' => $r['ref_no'] === null ? null : (string) $r['ref_no'],
                    'sales_order' => $r['sales_order'] === null ? null : (string) $r['sales_order']];
            }
        }
        $here = array_flip($days);
        foreach ($newRows as $r) {
            if (isset($here[(string) $r['service_date']])) {
                $rows[] = $r;
            }
        }
        // a day x Tracked product billed more than its priced labels by more than the setting: red (DL86 7)
        $billed = [];
        foreach ($rows as $r) {
            if (($kind[(string) $r['product_code']] ?? null) === 'flat') {
                $k = $r['service_date'] . '|' . $r['product_code'];
                $billed[$k] = ($billed[$k] ?? 0) + (int) $r['qty'];
            }
        }
        ksort($billed);
        $redDays = [];
        foreach ($billed as $k => $q) {
            [$d, $product] = explode('|', $k);
            if (self::red($q, $count[$k] ?? 0, $bp)) {
                $redDays[$k] = true;
                $out[] = ['kind' => 'billed_more', 'day' => $d, 'red' => true, 'product' => $product, 'labels' => $count[$k] ?? 0, 'billed' => $q];
            }
        }
        // Special Delivery by manifest (DL86 5): the units of its rows on every live bill against its labels; a row naming only its sales order on
        // the one manifest of that sales order's labels, else no manifest (the main shop's: listed, red); wrong weight with no label (DL86 6)
        $manifests = [];
        $soRows = [];
        $ww = [];
        foreach ($rows as $r) {
            $k = $kind[(string) $r['product_code']] ?? null;
            if ($k === 'band') {
                if ($r['ref_kind'] === 'manifest') {
                    $manifests[(int) $r['ref_no']] ??= (string) $r['service_date'];
                } else {
                    $soRows[] = $r;
                }
            } elseif ($k === 'wrong_weight') {
                $ww[] = $r;
            }
        }
        $so = [];
        foreach ([...$soRows, ...$ww] as $r) {
            $s = $r['ref_kind'] === 'sales_order' ? $r['ref_no'] : $r['sales_order'];
            if ($s !== null) {
                $so[(string) $s] = [];
            }
        }
        foreach (array_chunk(array_keys($so), 500) as $chunk) {
            if ($band === []) {
                break;
            }
            foreach ($db->all('SELECT sales_order, manifest_no FROM carrier_label WHERE account_id = ? AND sales_order IN (' . $marks(count($chunk)) . ') AND service_code IN ('
                . $marks(count($band)) . ')', [$account['id'], ...array_map('strval', $chunk), ...$band]) as $l) {
                $so[(string) $l['sales_order']][] = $l['manifest_no'] === null ? null : (int) $l['manifest_no'];
            }
        }
        $soManifest = [];
        foreach ($soRows as $r) {
            $found = array_values(array_unique(array_filter($so[(string) $r['sales_order']] ?? [], static fn (?int $m): bool => $m !== null)));
            if ($r['sales_order'] !== null && count($found) === 1) {
                $manifests[$found[0]] ??= (string) $r['service_date'];
                $soManifest[] = [$found[0], $r];
            } else {
                $out[] = ['kind' => 'no_labels', 'day' => (string) $r['service_date'], 'red' => true, 'product' => (string) $r['product_code'], 'manifest' => null,
                    'sales_order' => $r['sales_order'], 'pence' => (int) $r['net']];
            }
        }
        foreach ($ww as $r) {
            $s = $r['ref_kind'] === 'sales_order' ? $r['ref_no'] : $r['sales_order'];
            if ($s === null || ($so[(string) $s] ?? []) === []) {
                $out[] = ['kind' => 'no_labels', 'day' => (string) $r['service_date'], 'red' => false, 'product' => (string) $r['product_code'], 'manifest' => null,
                    'sales_order' => $s === null ? null : (string) $s, 'pence' => (int) $r['net']];
            }
        }
        if ($manifests !== [] && $band !== []) {
            ksort($manifests);
            $ids = array_keys($manifests);
            $units = array_fill_keys($ids, 0);
            $labels = array_fill_keys($ids, 0);
            foreach (array_chunk($ids, 300) as $chunk) {
                $in = $marks(count($chunk));
                // every live bill's rows of the manifest, whatever their day (DL86 5)
                foreach ($db->all("SELECT r.ref_no, SUM(r.qty) AS q FROM carrier_bill_row r JOIN carrier_invoice i ON i.id = r.invoice_id AND i.status = 'live' AND i.carrier = ? "
                    . "AND i.account = ? JOIN carrier_product p ON p.carrier = i.carrier AND p.product_code = r.product_code AND p.kind = 'band' WHERE r.ref_kind = 'manifest' "
                    . "AND r.ref_no IN ({$in}){$not} GROUP BY r.ref_no", [$account['carrier'], $account['account_no'], ...array_map('strval', $chunk), ...$gone]) as $r) {
                    $units[(int) $r['ref_no']] += (int) $r['q'];
                }
                foreach ($db->all("SELECT manifest_no, COUNT(*) AS n FROM carrier_label WHERE account_id = ? AND manifest_no IN ({$in}) AND service_code IN ("
                    . $marks(count($band)) . ') GROUP BY manifest_no', [$account['id'], ...$chunk, ...$band]) as $r) {
                    $labels[(int) $r['manifest_no']] += (int) $r['n'];
                }
                foreach (self::differs($db, (int) $account['id'], 'l.manifest_no IN (' . $in . ')', $chunk, $band) as $r) {
                    $labels[(int) $r['manifest_no']]--;
                }
            }
            foreach ($newRows as $r) {
                if (($kind[(string) $r['product_code']] ?? null) === 'band' && $r['ref_kind'] === 'manifest' && isset($units[(int) $r['ref_no']])) {
                    $units[(int) $r['ref_no']] += (int) $r['qty'];
                }
            }
            foreach ($soManifest as [$m, $r]) {
                $units[$m] += (int) $r['qty'];
            }
            foreach ($manifests as $m => $day) {
                if (self::red($units[$m], $labels[$m], $bp)) {
                    $out[] = ['kind' => 'manifest', 'day' => $day, 'red' => true, 'manifest' => $m, 'labels' => $labels[$m], 'billed' => $units[$m]];
                }
            }
        }
        // CW Royal Mail parcels of the days in no report of the account (DL86 3): royal_mail, or made by hand with a Royal Mail number (check d,
        // DL57); outbound (a return's service is no label of the account); of a shop the account serves
        $shops = array_values(array_filter(array_map(static fn (array $p): ?int => $p['channel_id'], CarrierSettings::parties($db, (int) $account['id']))));
        $returns = array_map('strval', array_keys(array_filter($products, static fn (array $p): bool => $p['kind'] === 'return')));
        if ($shops !== []) {
            $utc = new \DateTimeZone('UTC');
            $uk = new \DateTimeZone('Europe/London');
            foreach (array_chunk($days, 31) as $chunk) {
                $lo = (new \DateTimeImmutable($chunk[0] . ' 00:00:00', $uk))->setTimezone($utc)->format('Y-m-d H:i:s');
                $hi = (new \DateTimeImmutable(end($chunk) . ' 00:00:00', $uk))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
                $set = array_flip($chunk);
                foreach ($db->all('SELECT p.channel_id, p.order_ref, p.parcel_ref, p.tracking_no, p.service_code, p.label_at, c.name AS shop FROM sale_parcel p '
                    . 'JOIN channel c ON c.id = p.channel_id LEFT JOIN carrier_label l ON l.account_id = ? AND l.tracking_no = p.tracking_no '
                    . "WHERE (p.carrier = 'royal_mail' OR ((p.carrier = 'manual' OR (p.carrier = '' AND p.is_manual = 1)) AND REGEXP_LIKE(UPPER(p.tracking_no), "
                    . "'^[A-Z]{2}[0-9]{9}GB$', 'c'))) AND p.removed_at IS NULL AND p.tracking_no IS NOT NULL AND p.tracking_no <> '' AND p.label_at >= ? AND p.label_at < ? "
                    . 'AND p.channel_id IN (' . $marks(count($shops)) . ')' . ($returns === [] ? '' : ' AND p.service_code NOT IN (' . $marks(count($returns)) . ')')
                    . ' AND l.id IS NULL ORDER BY p.tracking_no', [$account['id'], $lo, $hi, ...$shops, ...$returns]) as $p) {
                    $d = RoyalMailBills::ukDay((string) $p['label_at']);
                    if (isset($set[$d])) {
                        $product = $flat[(string) $p['service_code']] ?? null;
                        $out[] = ['kind' => 'parcel_missing', 'day' => $d, 'red' => false, 'channel_id' => (int) $p['channel_id'], 'shop' => (string) $p['shop'],
                            'order_ref' => (string) $p['order_ref'], 'parcel_ref' => (string) $p['parcel_ref'], 'tracking_no' => strtoupper((string) $p['tracking_no']),
                            'service' => (string) $p['service_code'], 'product' => $product];
                    }
                }
            }
        }
        // a missing parcel or an odd label is red when its day x product is (DL86 7: "not red unless over the setting")
        foreach ([...$odd] as $i) {
            $out[] = $i;
        }
        foreach ($out as $n => $i) {
            if (in_array($i['kind'], ['parcel_missing', 'odd_service'], true) && ($i['product'] ?? null) !== null) {
                $out[$n]['red'] = isset($redDays[$i['day'] . '|' . $i['product']]);
            }
        }
        $order = ['billed_more' => 1, 'manifest' => 2, 'no_labels' => 3, 'parcel_missing' => 4, 'odd_service' => 5];
        usort($out, static fn (array $a, array $b): int => [$a['day'], $order[$a['kind']], (string) ($a['product'] ?? ''), (int) ($a['manifest'] ?? 0),
            (string) ($a['tracking_no'] ?? $a['sales_order'] ?? '')] <=> [$b['day'], $order[$b['kind']], (string) ($b['product'] ?? ''), (int) ($b['manifest'] ?? 0),
            (string) ($b['tracking_no'] ?? $b['sales_order'] ?? '')]);
        return $out;
    }

    /**
     * The labels (of a mapped service) whose CW Royal Mail parcel of the same tracking number has another service (DL86 4), one row a label.
     *
     * @param list<mixed> $params the condition's
     * @param list<string> $services the mapped services the labels have
     * @return list<array<string, mixed>>
     */
    private static function differs(Db $db, int $accountId, string $where, array $params, array $services): array
    {
        if ($services === []) {
            return [];
        }
        return $db->all('SELECT l.id, l.tracking_no, l.service_code, l.manifest_day, l.manifest_no, MIN(p.service_code) AS cw FROM carrier_label l JOIN sale_parcel p '
            . "ON p.tracking_no = l.tracking_no AND p.removed_at IS NULL AND (p.carrier = 'royal_mail' OR p.is_manual = 1) AND p.service_code <> '' "
            . "WHERE l.account_id = ? AND {$where} AND l.service_code IN (" . implode(', ', array_fill(0, count($services), '?')) . ') AND p.service_code <> l.service_code '
            . 'GROUP BY l.id, l.tracking_no, l.service_code, l.manifest_day, l.manifest_no ORDER BY l.id', [$accountId, ...$params, ...$services]);
    }
}
