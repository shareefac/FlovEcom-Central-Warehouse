<?php

declare(strict_types=1);

namespace CW\Profit;

/**
 * Where every penny of a pooled bill's rows goes (Royal Mail; shipping plan v3 section 5; docs/decisions.md DL56-DL62). Pure: the rows, the
 * labels (each with where its cost belongs: LabelDestinations) and the account's settings in; the allocations out. The same inputs always
 * give the same allocations (every list is sorted here), so the stored allocations can be checked against a plan from scratch.
 *
 * Per product kind (carrier_product):
 *  flat (TPL, TPM, TRN)  THE DAY BOOK: the rows of one service day and product, across every live bill of the account (bill order: its date,
 *                        then its number). The day's price P = the FIRST bill's rows' net / quantity in whole pence (half up). EVERY label
 *                        manifested that day with the product's service gets P at once, on the first bill's rows (each row takes as many labels as
 *                        its quantity, the rest on its last row). The rest of each first-bill row, and the whole of every later bill's row of that
 *                        day (the tail Royal Mail bills a week later), is the day's line: "labels not charged" when negative, "billed, no label
 *                        found" when positive, split between the holders of the day's labels by their counts (each holder its share, truncated;
 *                        the pence left over to the main shop). A later bill's row so "takes back" from the line: no label's price moves.
 *  band (SD1)            a manifest's rows ("CLICK AND DROP M2281", or a row's sales order found on the manifest's labels) against that
 *                        manifest's labels of the service: by declared weight when the rows grouped by unit price fit the labels' bands one to one
 *                        (the cheapest price the lightest band; counts equal): each band's labels take its rows (each row as many as its
 *                        quantity, its net split over them, a penny more to the first ones); else every row's net split over every label
 *                        ("average"). No label: the row is the main shop's "not on an order".
 *  wrong_weight (RX1/A)  shared equally over the Special Delivery labels of the row's sales order (ADJ<sales order>RCN), the pence left over to
 *                        the main shop's "not on an order"; no such label: all of it there.
 *  surcharge, return     the main shop's "not on an order" (no parcel named).
 * A label's money goes where its destination says (an order, a shop's "not on an order", a party with no shop, before CW start, held). Every
 * row's allocations add up to its net exactly.
 */
final class PooledPlanner
{
    /**
     * @param list<array<string, mixed>> $rows the live bill rows of the keys planned: invoice_id, row_no, rank (the bill's order), service_date,
     *   product_code, qty, net, ref_kind, ref_no, sales_order
     * @param array<int, array<string, mixed>> $labels by id: id, tracking_no, service_code, manifest_day, manifest_no, weight_g, sales_order, dest
     *   (array{dest: string, channel_id: ?int, order_ref: ?string, parcel_ref: ?string, party_id: ?int, held_reason: ?string})
     * @param array{main: int, products: array<string, array{kind: string, service_code: ?string}>, bands: array<string, list<int>>} $ctx main: the
     *   main shop's channel; bands: product => its bands' top weights in grams, ascending
     * @return list<array<string, mixed>> the allocations, sorted
     */
    public static function plan(array $rows, array $labels, array $ctx): array
    {
        $out = [];
        $groups = [];
        foreach ($rows as $r) {
            $kind = $ctx['products'][(string) $r['product_code']]['kind'] ?? 'unknown';
            $key = self::key($r, $kind, $labels, $ctx);
            $groups[$key][] = $r;
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as $key => $set) {
            usort($set, static fn (array $a, array $b): int => [(string) $a['rank'], (int) $a['row_no']] <=> [(string) $b['rank'], (int) $b['row_no']]);
            $type = substr($key, 0, 1);
            if ($type === 'F') {
                [, $day, $product] = explode('|', $key);
                $service = (string) $ctx['products'][$product]['service_code'];
                $own = array_filter($labels, static fn (array $l): bool => $l['manifest_day'] === $day && $l['service_code'] === $service);
                array_push($out, ...self::flat($set, self::sorted($own), $ctx));
            } elseif ($type === 'M') {
                $m = (int) substr($key, 2);
                $services = self::bandServices($ctx);
                $own = array_filter($labels, static fn (array $l): bool => $l['manifest_no'] !== null && (int) $l['manifest_no'] === $m
                    && in_array($l['service_code'], $services, true));
                array_push($out, ...self::band($set, self::sorted($own), $ctx));
            } elseif ($type === 'S') {
                $so = substr($key, 2);
                $services = self::bandServices($ctx);
                $own = array_filter($labels, static fn (array $l): bool => $l['sales_order'] === $so && in_array($l['service_code'], $services, true));
                // its manifests' labels: one naming no sales order and the group is not known whole (DL83)
                $ms = array_values(array_unique(array_filter(array_map(static fn (array $l): ?int => $l['manifest_no'] === null ? null : (int) $l['manifest_no'], $own))));
                $unknown = array_filter($labels, static fn (array $l): bool => $l['sales_order'] === null && $l['manifest_no'] !== null
                    && in_array((int) $l['manifest_no'], $ms, true) && in_array($l['service_code'], $services, true)) !== [];
                array_push($out, ...($unknown ? self::waitAll($set) : self::wrongWeight($set, self::sorted($own), $ctx)));
            } else {
                foreach ($set as $r) {
                    $kind = $ctx['products'][(string) $r['product_code']]['kind'] ?? 'unknown';
                    $how = match ($kind) {
                        'surcharge' => 'surcharge',
                        'return' => 'return',
                        'band', 'wrong_weight' => 'no_batch',
                        default => 'unknown',
                    };
                    // a Special Delivery or wrong-weight row whose group CW cannot name (no manifest, its sales order on no label): its group is
                    // not complete: it waits after the main shop's CW start (DL71, DL83: the Codex check r7, H4)
                    $dest = $how === 'no_batch' && (self::waiting($r, $ctx) || self::afterStart([$r], $ctx)) ? self::held('no_report')
                        : self::mainDest($ctx, (string) $r['service_date']);
                    self::push($out, self::alloc($r, 'row', $dest['dest'] === 'held' ? 'no_report' : $how, null, $dest, (int) $r['net']));
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => self::sortKey($a) <=> self::sortKey($b));
        return $out;
    }

    /**
     * The key a row is planned under: F|day|product (flat), M|manifest (band), S|sales order (wrong weight), R|invoice|row (on its own).
     *
     * @param array<string, mixed> $r
     * @param array<int, array<string, mixed>> $labels
     * @param array<string, mixed> $ctx
     */
    public static function key(array $r, string $kind, array $labels, array $ctx): string
    {
        $own = 'R|' . (int) $r['invoice_id'] . '|' . (int) $r['row_no'];
        if ($kind === 'flat') {
            return 'F|' . $r['service_date'] . '|' . $r['product_code'];
        }
        if ($kind === 'band') {
            if ($r['ref_kind'] === 'manifest') {
                return 'M|' . (int) $r['ref_no'];
            }
            $m = $r['sales_order'] === null ? null : self::manifestOf((string) $r['sales_order'], $labels, $ctx);
            return $m === null ? $own : 'M|' . $m;
        }
        if ($kind === 'wrong_weight') {
            $so = $r['ref_kind'] === 'sales_order' ? (string) $r['ref_no'] : $r['sales_order'];
            return $so === null ? $own : 'S|' . $so;
        }
        return $own;
    }

    /** The one manifest of a sales order's Special Delivery labels; null: none, or two. @param array<int, array<string, mixed>> $labels */
    public static function manifestOf(string $salesOrder, array $labels, array $ctx): ?int
    {
        $services = self::bandServices($ctx);
        $found = [];
        foreach ($labels as $l) {
            if ($l['sales_order'] === $salesOrder && $l['manifest_no'] !== null && in_array($l['service_code'], $services, true)) {
                $found[(int) $l['manifest_no']] = true;
            }
        }
        return count($found) === 1 ? (int) array_key_first($found) : null;
    }

    /** @return list<string> the band products' label service codes */
    public static function bandServices(array $ctx): array
    {
        $out = [];
        foreach ($ctx['products'] as $p) {
            if ($p['kind'] === 'band' && $p['service_code'] !== null) {
                $out[] = (string) $p['service_code'];
            }
        }
        return $out;
    }

    /**
     * The day book of one service day and product (see the class).
     *
     * @param list<array<string, mixed>> $rows sorted by bill order, then row
     * @param list<array<string, mixed>> $labels sorted
     * @return list<array<string, mixed>>
     */
    private static function flat(array $rows, array $labels, array $ctx): array
    {
        $out = [];
        if (self::waiting($rows[0], $ctx)) {
            // the day's labels are not all in (Click & Drop's report not read yet: a label with no service, or none at all): the whole day held,
            // priced once they are (the Codex check r1, HIGH 1; DL71)
            foreach ($rows as $r) {
                self::push($out, self::alloc($r, 'row', 'no_report', null, self::held('no_report'), (int) $r['net']));
            }
            return $out;
        }
        // the first bill with units billed sets the price (a first bill of only a credit sets none: the Codex check r3, new MEDIUM 5)
        $first = (int) $rows[0]['invoice_id'];
        foreach ($rows as $r) {
            if ((int) $r['qty'] > 0) {
                $first = (int) $r['invoice_id'];
                break;
            }
        }
        $firstRows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['invoice_id'] === $first));
        $cap = array_values(array_filter($firstRows, static fn (array $r): bool => (int) $r['qty'] > 0));
        $qty = array_sum(array_map(static fn (array $r): int => (int) $r['qty'], $cap));
        $net = array_sum(array_map(static fn (array $r): int => (int) $r['net'], $cap));
        $price = $qty > 0 ? self::roundDiv($net, $qty) : 0;
        // the labels on the first bill's rows: each row as many as its quantity, the rest on its last row
        $on = [];
        $i = 0;
        $n = count($labels);
        $last = $cap === [] ? count($firstRows) - 1 : (int) array_search(end($cap), $firstRows, true);
        foreach ($firstRows as $k => $r) {
            $take = (int) $r['qty'] > 0 ? min((int) $r['qty'], $n - $i) : 0;
            $on[$k] = array_slice($labels, $i, max(0, $take));
            $i += max(0, $take);
        }
        if ($i < $n) {
            $on[$last] = [...$on[$last], ...array_slice($labels, $i)];
        }
        $shares = self::holders($labels);
        foreach ($rows as $r) {
            $k = array_search($r, $firstRows, true);
            if ($k !== false) {
                foreach ($on[$k] as $l) {
                    self::push($out, self::alloc($r, 'label', 'flat', (int) $l['id'], $l['dest'], $price), true);
                }
                $rest = (int) $r['net'] - count($on[$k]) * $price;
                $how = 'day';
            } else {
                $rest = (int) $r['net'];
                $how = 'tail';
            }
            foreach (self::split($rest, $shares, $ctx, (string) $r['service_date']) as [$dest, $p]) {
                self::push($out, self::alloc($r, 'day_line', $how, null, $dest, $p));
            }
        }
        return $out;
    }

    /**
     * A manifest's band rows against its labels (see the class).
     *
     * @param list<array<string, mixed>> $rows sorted
     * @param list<array<string, mixed>> $labels sorted
     * @return list<array<string, mixed>>
     */
    private static function band(array $rows, array $labels, array $ctx): array
    {
        $out = [];
        // DL82: the manifest is ONE group across all its rows and days, checked whole BEFORE any pricing or fallback: when any of its days
        // (its rows', its labels' manifest days) waits, or it has no label (unless all of it is before CW start), all of it waits (the Codex
        // checks r5 HIGH 2, r6 F2)
        if (self::groupWaits($rows, $labels, $ctx, true)) {
            return self::waitAll($rows);
        }
        if ($labels === []) {
            foreach ($rows as $r) {
                self::push($out, self::alloc($r, 'row', 'no_label', null, self::mainDest($ctx, (string) $r['service_date']), (int) $r['net']));
            }
            return $out;
        }
        $product = (string) $rows[0]['product_code'];
        $tops = $ctx['bands'][$product] ?? [];
        // each label's band (1 = the lightest); 0: no weight, or above every band
        $byBand = [];
        foreach ($labels as $l) {
            $b = 0;
            if ($l['weight_g'] !== null) {
                foreach ($tops as $no => $top) {
                    if ((int) $l['weight_g'] <= $top) {
                        $b = $no + 1;
                        break;
                    }
                }
            }
            $byBand[$b][] = $l;
        }
        ksort($byBand);
        // the rows grouped by unit price (net / quantity compared exactly), cheapest first
        $prices = [];
        $fits = !isset($byBand[0]);
        foreach ($rows as $r) {
            if ((int) $r['qty'] <= 0) {
                $fits = false;
                break;
            }
            $found = null;
            foreach ($prices as $pi => $p) {
                if ((int) $r['net'] * (int) $p['q'] === (int) $p['n'] * (int) $r['qty']) {
                    $found = $pi;
                    break;
                }
            }
            if ($found === null) {
                $prices[] = ['n' => (int) $r['net'], 'q' => (int) $r['qty'], 'rows' => [$r], 'qty' => (int) $r['qty']];
            } else {
                $prices[$found]['rows'][] = $r;
                $prices[$found]['qty'] += (int) $r['qty'];
            }
        }
        if ($fits) {
            usort($prices, static fn (array $a, array $b): int => ($a['n'] * $b['q']) <=> ($b['n'] * $a['q']));
            $bands = array_values($byBand);
            $fits = count($prices) === count($bands);
            foreach ($fits ? $prices : [] as $pi => $p) {
                if ($p['qty'] !== count($bands[$pi])) {
                    $fits = false;
                }
            }
        }
        if ($fits) {
            foreach ($prices as $pi => $p) {
                $i = 0;
                foreach ($p['rows'] as $r) {
                    $take = array_slice($bands[$pi], $i, (int) $r['qty']);
                    $i += (int) $r['qty'];
                    foreach (self::shares((int) $r['net'], count($take)) as $k => $pence) {
                        self::push($out, self::alloc($r, 'label', 'band', (int) $take[$k]['id'], $take[$k]['dest'], $pence), true);
                    }
                }
            }
            return $out;
        }
        foreach ($rows as $r) {
            foreach (self::shares((int) $r['net'], count($labels)) as $k => $pence) {
                self::push($out, self::alloc($r, 'label', 'average', (int) $labels[$k]['id'], $labels[$k]['dest'], $pence), true);
            }
        }
        return $out;
    }

    /**
     * Wrong-weight rows of one sales order shared over its Special Delivery labels (see the class).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $labels sorted
     * @return list<array<string, mixed>>
     */
    private static function wrongWeight(array $rows, array $labels, array $ctx): array
    {
        $out = [];
        // DL82: the sales order is ONE group across all its rows, checked whole first: when its labels' manifest days wait, or it has no label
        // (unless all of it is before CW start), all of it waits. Its rows' own dates are the adjustments' (no label is made on them): its days
        // are its labels' (the Codex checks r5 HIGH 2, r6 F2)
        if (self::groupWaits($rows, $labels, $ctx, false)) {
            return self::waitAll($rows);
        }
        foreach ($rows as $r) {
            $net = (int) $r['net'];
            if ($labels === []) {
                self::push($out, self::alloc($r, 'row', 'no_batch', null, self::mainDest($ctx, (string) $r['service_date']), $net));
                continue;
            }
            $each = intdiv($net, count($labels));
            foreach ($labels as $l) {
                self::push($out, self::alloc($r, 'wrong_weight', 'shared', (int) $l['id'], $l['dest'], $each));
            }
            self::push($out, self::alloc($r, 'row', 'left_over', null, self::mainDest($ctx, (string) $r['service_date']), $net - $each * count($labels)));
        }
        return $out;
    }

    /**
     * The holders of a day's labels and how many each holds: a shop (on its orders or its "not on an order"), before CW start in a shop, a
     * party with no shop, held (by reason).
     *
     * @param list<array<string, mixed>> $labels
     * @return array<string, array{dest: array<string, mixed>, n: int}>
     */
    private static function holders(array $labels): array
    {
        $out = [];
        foreach ($labels as $l) {
            $d = $l['dest'];
            $dest = match ($d['dest']) {
                'order', 'shop' => self::dest('shop', channel: (int) $d['channel_id']),
                'before_start' => self::dest('before_start', channel: (int) $d['channel_id']),
                'party' => self::dest('party', party: (int) $d['party_id']),
                default => self::dest('held', reason: (string) $d['held_reason']),
            };
            $k = self::destKey($dest);
            $out[$k] ??= ['dest' => $dest, 'n' => 0];
            $out[$k]['n']++;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * An amount split between the holders by their counts: each other holder its share truncated, the main shop the rest (also when it holds no
     * label: the pence left over). No holder: all of it the main shop's.
     *
     * @param array<string, array{dest: array<string, mixed>, n: int}> $holders
     * @return list<array{0: array<string, mixed>, 1: int}>
     */
    private static function split(int $amount, array $holders, array $ctx, string $day): array
    {
        if ($amount === 0) {
            return [];
        }
        $main = self::mainDest($ctx, $day);
        $mainKey = self::destKey($main);
        $total = array_sum(array_column($holders, 'n'));
        $out = [];
        $given = 0;
        foreach ($total === 0 ? [] : $holders as $k => $h) {
            if ($k === $mainKey) {
                continue;
            }
            $p = intdiv($amount * $h['n'], $total);
            $given += $p;
            $out[] = [$h['dest'], $p];
        }
        $out[] = [$main, $amount - $given];
        return $out;
    }

    /** An amount split into $n parts that add up to it: each intdiv, a penny more (or less, for a credit) to the first ones. @return list<int> */
    public static function shares(int $amount, int $n): array
    {
        if ($n <= 0) {
            return [];
        }
        $each = intdiv($amount, $n);
        $rest = $amount - $each * $n;
        $step = $rest < 0 ? -1 : 1;
        $out = array_fill(0, $n, $each);
        for ($i = 0; $i < abs($rest); $i++) {
            $out[$i] += $step;
        }
        return $out;
    }

    /** $a / $b rounded half away from zero ($b > 0). */
    public static function roundDiv(int $a, int $b): int
    {
        $q = intdiv(2 * abs($a) + $b, 2 * $b);
        return $a < 0 ? -$q : $q;
    }

    /** @return array{dest: string, channel_id: ?int, order_ref: null, parcel_ref: null, party_id: ?int, held_reason: ?string} */
    private static function dest(string $dest, ?int $channel = null, ?int $party = null, ?string $reason = null): array
    {
        return ['dest' => $dest, 'channel_id' => $channel, 'order_ref' => null, 'parcel_ref' => null, 'party_id' => $party, 'held_reason' => $reason];
    }

    /**
     * The main shop's "not on an order" on a day; on or before its first day in CW (ctx main_start; none yet: every day), before CW start, as
     * DPD's lines (DL10; DL71).
     */
    private static function mainDest(array $ctx, string $day): array
    {
        $start = $ctx['main_start'] ?? null;
        $before = array_key_exists('main_start', $ctx) && ($start === null || $day <= $start);
        return self::dest($before ? 'before_start' : 'shop', channel: (int) $ctx['main']);
    }

    /** @return array<string, mixed> held, for a reason */
    private static function held(string $reason): array
    {
        return self::dest('held', reason: $reason);
    }

    /**
     * Whether a row's day waits for its labels (ctx waiting: the days of the account whose labels are not all in: one with no service yet,
     * or none at all on a day after the account's first label; DL71).
     *
     * @param array<string, mixed> $r
     */
    /**
     * Whether a Special Delivery manifest or a wrong-weight sales order waits WHOLE (DL82): a day of it waits (its labels' manifest days; a
     * manifest's rows' days too), or it has no label while a row of it is after the main shop's CW start (no label is no sign its labels are
     * all in). Before CW start it never waits (its money is before CW start).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $labels
     */
    private static function groupWaits(array $rows, array $labels, array $ctx, bool $rowDays): bool
    {
        // a group with a row after the main shop's CW start needs ALL its days complete, the ones on or before the start too (the Codex check r7,
        // H6; DL83); a group all on or before it is before CW start
        $after = self::afterStart($rows, $ctx);
        $set = $after ? ($ctx['incomplete'] ?? $ctx['waiting'] ?? []) : ($ctx['waiting'] ?? []);
        foreach ($labels as $l) {
            if (($l['manifest_day'] ?? null) !== null && isset($set[(string) $l['manifest_day']])) {
                return true;
            }
        }
        if ($rowDays) {
            foreach ($rows as $r) {
                if (isset($set[(string) $r['service_date']])) {
                    return true;
                }
            }
        }
        return $labels === [] && $after;
    }

    /** Whether any of these rows is after the main shop's CW start (ctx main_start; none known: none). @param list<array<string, mixed>> $rows */
    private static function afterStart(array $rows, array $ctx): bool
    {
        $start = $ctx['main_start'] ?? null;
        return array_key_exists('main_start', $ctx) && $start !== null
            && array_filter($rows, static fn (array $r): bool => (string) $r['service_date'] > (string) $start) !== [];
    }

    /** Every row of a group held whole, waiting for its labels (DL82). @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private static function waitAll(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            self::push($out, self::alloc($r, 'row', 'no_report', null, self::held('no_report'), (int) $r['net']));
        }
        return $out;
    }

    /** Whether the manifest day of any of these labels waits for its labels (ctx waiting). @param list<array<string, mixed>> $labels */
    private static function labelsWait(array $labels, array $ctx): bool
    {
        foreach ($labels as $l) {
            if (($l['manifest_day'] ?? null) !== null && isset($ctx['waiting'][(string) $l['manifest_day']])) {
                return true;
            }
        }
        return false;
    }

    private static function waiting(array $r, array $ctx): bool
    {
        return isset($ctx['waiting'][(string) $r['service_date']]);
    }

    /** @param array<string, mixed> $d */
    public static function destKey(array $d): string
    {
        return $d['dest'] . '|' . ($d['channel_id'] ?? '') . '|' . strtolower((string) ($d['order_ref'] ?? '')) . '|' . strtolower((string) ($d['parcel_ref'] ?? ''))
            . '|' . ($d['party_id'] ?? '') . '|' . ($d['held_reason'] ?? '');
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $dest
     * @return array<string, mixed>
     */
    private static function alloc(array $r, string $kind, string $how, ?int $label, array $dest, int $pence): array
    {
        return ['invoice_id' => (int) $r['invoice_id'], 'row_no' => (int) $r['row_no'], 'kind' => $kind, 'how' => $how, 'label_id' => $label,
            'dest' => (string) $dest['dest'], 'channel_id' => $dest['channel_id'] === null ? null : (int) $dest['channel_id'],
            'order_ref' => $dest['order_ref'] === null ? null : (string) $dest['order_ref'], 'parcel_ref' => $dest['parcel_ref'] === null ? null : (string) $dest['parcel_ref'],
            'party_id' => $dest['party_id'] === null ? null : (int) $dest['party_id'], 'held_reason' => $dest['held_reason'] === null ? null : (string) $dest['held_reason'],
            'day' => (string) $r['service_date'], 'pence' => $pence];
    }

    /**
     * Adds an allocation; a zero one only for a label's price (its label is priced, at £0).
     *
     * @param list<array<string, mixed>> $out
     * @param array<string, mixed> $a
     */
    private static function push(array &$out, array $a, bool $keepZero = false): void
    {
        if ($a['pence'] !== 0 || $keepZero) {
            $out[] = $a;
        }
    }

    /** The identity of an allocation (what stays the same allocation from one plan to the next). @param array<string, mixed> $a */
    public static function identity(array $a): string
    {
        return implode('#', [(int) $a['invoice_id'], (int) $a['row_no'], $a['kind'], $a['how'], $a['label_id'] ?? '', self::destKey($a), (string) $a['day'], (int) $a['pence']]);
    }

    /** @return list<mixed> */
    private static function sortKey(array $a): array
    {
        return [(int) $a['invoice_id'], (int) $a['row_no'], (string) $a['kind'], (int) ($a['label_id'] ?? 0), self::destKey($a), (int) $a['pence']];
    }

    /**
     * @param array<int|string, array<string, mixed>> $labels
     * @return list<array<string, mixed>> by tracking number, then id
     */
    private static function sorted(array $labels): array
    {
        $l = array_values($labels);
        usort($l, static fn (array $a, array $b): int => [(string) $a['tracking_no'], (int) $a['id']] <=> [(string) $b['tracking_no'], (int) $b['id']]);
        return $l;
    }
}
