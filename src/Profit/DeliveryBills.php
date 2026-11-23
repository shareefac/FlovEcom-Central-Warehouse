<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Reports\ReportRefresh;
use CW\Staff\StaffRoles;

/**
 * The carriers' bills in CW (delivery cost in profit, step 2 part 1; docs/decisions.md DL1-DL24): DPD's weekly CSV read
 * (DpdInvoiceFile), its lines put on CW's own parcels, and what they cost counted in the profit days. ONE service for the screen
 * (Profit > Delivery bills) and the nightly job (bin/match_delivery_bills.php).
 *
 *  upload()   a bill read from its file and stored whole with its lines (nothing when the file is refused). The same bytes as a live bill:
 *             "same", nothing changes. Another file of a bill already live: refused (already uploaded); a corrected file goes through
 *             replace(), never by accident.
 *  replace()  a corrected file of a live bill: the old bill is superseded (kept, counting nothing), the new one live, in one transaction.
 *  undo()     "Undo this upload": the bill undone (kept, counting nothing).
 *  rematch()  every line of a live bill still not matched or ambiguous tried again (its parcel may have reached CW since): after each
 *             upload and nightly. A line put on a parcel or an order is never moved to another one.
 *
 * Matching (DL7-DL10), per line, in this order:
 *  1. matched: the consignment (or the parcel number) is the tracking number of exactly ONE CW parcel of DPD (carrier dpd), not removed,
 *     whose label day (UK) is at most WINDOW_BEFORE days before the line's date and at most WINDOW_AFTER days after it (DPD uses a number
 *     again after months); two or more such parcels: ambiguous (a list to check).
 *  2. collection: no parcel, money in "Third Party Collection" and a Senders Ref (digits) that is the number of exactly one order of the
 *     shop the account bills: a return DPD collected, its cost on that order.
 *  3. before_start: the line's date is on or before the day of the first reservation in CW of the shop the account bills (taken from CW,
 *     OrderSnapshots::firstReservation): CW holds no orders before it. Shown on the bill; not in the profit.
 *  4. not_matched: anything else. In the profit as "DPD charges not on an order" on the line's date.
 *
 * The profit (DL11-DL14): a matched or collection line of a live bill counts on the order it is on, on that order's paid day
 * (profit_day.delivery_cost; an order not paid counts nothing yet, as its goods); a not_matched or ambiguous line counts on its own date in
 * the shop the account bills (profit_day.delivery_other); a before_start line nowhere. Every write takes the day rows it changes
 * (ProfitDays::guard, THE lock of a store's day that every snapshot takes before its order) in key order first, checks that no order it
 * counts moved to another day meanwhile (an order not paid is held with a shared lock so it cannot be paid meanwhile), and then writes the
 * lines and the day figures in the same transaction: the days always equal what ProfitDays::compute() works out from scratch.
 */
final class DeliveryBills
{
    public const PERM_VIEW = 'delivery.view';
    public const PERM_UPLOAD = 'delivery.upload';
    /** The carriers whose bills CW reads (Royal Mail later: DL24). */
    public const CARRIERS = ['dpd'];
    public const STATES = ['matched', 'collection', 'before_start', 'not_matched', 'ambiguous'];
    /** The states that put a line's cost on an order. */
    public const ON_ORDER = ['matched', 'collection'];
    /** The states counted as "charges not on an order" (in the profit). */
    public const OTHER = ['not_matched', 'ambiguous'];
    /** The parcels a DPD line may be matched to: their carrier word in CW (SnapshotBody::CARRIERS). */
    public const DPD_PARCELS = ['dpd', 'dpd_local'];
    /** A bill line is dated at most this many days after its parcel's label day ... */
    public const WINDOW_BEFORE = 10;
    /** ... and at most this many days before it. */
    public const WINDOW_AFTER = 3;
    /** How long a write waits for another one (the screen and the nightly job), in seconds: then 503 busy. */
    public const LOCK_WAIT_SEC = 10;
    /** How many times a write is tried again when an order it counts moved to another day while it read. */
    public const TRIES = 5;
    private const RETRY = 'delivery_retry';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private readonly ProfitSettings $settings;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null, ?ProfitSettings $settings = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->settings = $settings ?? new ProfitSettings($db);
    }

    /**
     * A bill read from its file and stored (DL3-DL6, DL16).
     *
     * @param ?int $shop the shop the account bills, when CW does not know the account yet and none of its parcels are CW's
     * @return array{result: string, invoice_id: int, invoice_no: string, uploaded_at: string, rematched: int}
     */
    public function upload(Caller $caller, string $carrier, string $bytes, ?int $shop = null): array
    {
        $this->authorise($caller);
        $bill = self::read($carrier, $bytes, $this->settings->vatBp());
        return $this->locked(function () use ($caller, $bill, $shop): array {
            $live = $this->db->one('SELECT id, file_sha256, uploaded_at FROM carrier_invoice WHERE live_key = ?', [self::key($bill)]);
            if ($live !== null) {
                if ($live['file_sha256'] === $bill['sha256']) {
                    return ['result' => 'same', 'invoice_id' => (int) $live['id'], 'invoice_no' => $bill['invoice_no'], 'uploaded_at' => (string) $live['uploaded_at'],
                        'rematched' => 0];
                }
                throw new CwException('already_uploaded', sprintf('DPD bill %s was uploaded on %s and counts already. To load a corrected file of it, open the bill '
                    . 'and use "Replace with a corrected file". Nothing was saved.', $bill['invoice_no'], self::when((string) $live['uploaded_at'])), 409,
                    ['invoice_id' => (int) $live['id']]);
            }
            $id = $this->store($caller, $bill, $shop, null);
            $n = $this->rematchAll($caller);
            return ['result' => 'stored', 'invoice_id' => $id, 'invoice_no' => $bill['invoice_no'], 'uploaded_at' => (string) $this->db->value(
                'SELECT uploaded_at FROM carrier_invoice WHERE id = ?', [$id]), 'rematched' => $n];
        });
    }

    /**
     * A corrected file of a live bill (DL17): the old one superseded (its lines kept, counting nothing), the new one live, one transaction.
     *
     * @return array{result: string, invoice_id: int, invoice_no: string, uploaded_at: string, rematched: int}
     */
    public function replace(Caller $caller, int $invoiceId, string $bytes): array
    {
        $this->authorise($caller);
        $old = $this->db->one('SELECT * FROM carrier_invoice WHERE id = ?', [$invoiceId]) ?? throw new CwException('not_found', 'there is no such bill', 404);
        $bill = self::read((string) $old['carrier'], $bytes, $this->settings->vatBp());
        return $this->locked(function () use ($caller, $invoiceId, $bill): array {
            $old = (array) $this->db->one('SELECT * FROM carrier_invoice WHERE id = ?', [$invoiceId]);
            if ($old['status'] !== 'live') {
                throw new CwException('not_live', 'this bill does not count any more (' . ($old['status'] === 'undone' ? 'its upload was undone' : 'a corrected file replaced it')
                    . '): upload the file as a new bill instead. Nothing was saved.', 409);
            }
            if (self::key($bill) !== (string) $old['live_key']) {
                throw new CwException('other_bill', sprintf('this file is DPD bill %s of account %s, not bill %s of account %s: nothing was saved.', $bill['invoice_no'],
                    $bill['account'], (string) $old['invoice_no'], (string) $old['account']), 422);
            }
            if ($bill['sha256'] === $old['file_sha256']) {
                return ['result' => 'same', 'invoice_id' => $invoiceId, 'invoice_no' => $bill['invoice_no'], 'uploaded_at' => (string) $old['uploaded_at'], 'rematched' => 0];
            }
            $id = $this->store($caller, $bill, (int) $old['channel_id'], $old);
            $n = $this->rematchAll($caller);
            return ['result' => 'stored', 'invoice_id' => $id, 'invoice_no' => $bill['invoice_no'], 'uploaded_at' => (string) $this->db->value(
                'SELECT uploaded_at FROM carrier_invoice WHERE id = ?', [$id]), 'rematched' => $n];
        });
    }

    /** "Undo this upload" (DL18): the bill undone, its lines kept, counting nothing; the days it counted on follow. */
    public function undo(Caller $caller, int $invoiceId): void
    {
        $this->authorise($caller);
        $this->locked(function () use ($caller, $invoiceId): void {
            $this->guarded(function (Db $db) use ($caller, $invoiceId): array {
                $inv = $db->one('SELECT * FROM carrier_invoice WHERE id = ?', [$invoiceId]) ?? throw new CwException('not_found', 'there is no such bill', 404);
                if ($inv['status'] !== 'live') {
                    throw new CwException('not_live', 'this bill does not count any more: nothing to undo.', 409);
                }
                $before = self::facts($db, [$invoiceId], true);
                return ['before' => $before, 'after' => [], 'write' => function (Db $db) use ($caller, $inv): void {
                    $now = Clock::db(($this->clock)());
                    $db->exec("UPDATE carrier_invoice SET status = 'undone', ended_by = ?, ended_actor = ?, ended_at = ? WHERE id = ? AND status = 'live'",
                        [$caller->staffUserId, $caller->actor, $now, (int) $inv['id']]);
                    Audit::write($db, $caller, 'delivery_bill.undo', 'carrier_invoice', (string) $inv['id'], null, ['carrier' => (string) $inv['carrier'],
                        'account' => (string) $inv['account'], 'invoice' => (string) $inv['invoice_no'], 'net_pence' => (int) $inv['net_pence']]);
                }];
            });
        });
    }

    /**
     * Every line of a live bill not matched or ambiguous tried again (DL10): after each upload and nightly (bin/match_delivery_bills.php).
     *
     * @return int the lines whose state changed
     */
    public function rematch(Caller $caller): int
    {
        if ($caller->staffUserId !== null) {
            $this->authorise($caller);
        }
        return $this->locked(fn (): int => $this->rematchAll($caller));
    }

    /**
     * What an upload of the file would store, nothing written (bin/delivery_bill.php without --upload; the tests): the bill's top, the shop
     * the account bills, the live bill of the same key if any, and per state its lines and pence. Never a line's content.
     *
     * @return array{carrier: string, account: string, invoice_no: string, period_from: string, period_to: string, net: int, vat: int, gross: int,
     *   lines: int, sha256: string, shop: int, live_id: ?int, same_file: bool, states: array<string, array{lines: int, pence: int}>}
     */
    public function preview(string $carrier, string $bytes, ?int $shop = null): array
    {
        $bill = self::read($carrier, $bytes, $this->settings->vatBp());
        $hits = self::parcels($this->db, $bill['lines']);
        $channel = self::accountShop($this->db, (string) $bill['carrier'], (string) $bill['account'], $hits, $shop);
        $match = self::decide($this->db, $bill['lines'], $hits, $channel, self::startDay($this->db, $channel));
        $states = array_fill_keys(self::STATES, ['lines' => 0, 'pence' => 0]);
        foreach ($bill['lines'] as $i => $l) {
            $states[$match[$i]['state']]['lines']++;
            $states[$match[$i]['state']]['pence'] += (int) $l['cost'];
        }
        $live = $this->db->one('SELECT id, file_sha256 FROM carrier_invoice WHERE live_key = ?', [self::key($bill)]);
        return ['carrier' => (string) $bill['carrier'], 'account' => (string) $bill['account'], 'invoice_no' => (string) $bill['invoice_no'],
            'period_from' => (string) $bill['period_from'], 'period_to' => (string) $bill['period_to'], 'net' => (int) $bill['net'], 'vat' => (int) $bill['vat'],
            'gross' => (int) $bill['gross'], 'lines' => count($bill['lines']), 'sha256' => (string) $bill['sha256'], 'shop' => $channel,
            'live_id' => $live === null ? null : (int) $live['id'], 'same_file' => $live !== null && $live['file_sha256'] === $bill['sha256'], 'states' => $states];
    }

    // ---- reading -------------------------------------------------------------------------------------------------------------------

    /**
     * The order's own delivery cost: its live matched and collection lines (pence ex VAT, credits included). OrderSnapshots::apply counts
     * it on the order's day; ProfitDays::compute() works it out the same way.
     */
    public static function orderCost(Db $db, int $channelId, string $ref): int
    {
        return (int) $db->value("SELECT COALESCE(SUM(l.cost), 0) FROM carrier_invoice_line l JOIN carrier_invoice i ON i.id = l.invoice_id AND i.status = 'live' "
            . "WHERE l.channel_id = ? AND l.order_ref = ? AND l.match_state IN ('matched', 'collection')", [$channelId, $ref]);
    }

    /**
     * A store's day of delivery figures from scratch (ProfitDays::compute): delivery_cost by order (the live lines on the day's paid orders)
     * and delivery_other (the live lines on no order dated that day, of the bills of the accounts the store's shop is billed on).
     *
     * @return array{orders: array<string, int>, other: int, other_lines: int} orders: lower-case order ref => pence
     */
    public static function dayFigures(Db $db, int $channelId, string $day): array
    {
        $orders = [];
        foreach ($db->all("SELECT o.order_ref, SUM(l.cost) AS c FROM sale_order o JOIN carrier_invoice_line l ON l.channel_id = o.channel_id AND l.order_ref = o.order_ref "
            . "AND l.match_state IN ('matched', 'collection') JOIN carrier_invoice i ON i.id = l.invoice_id AND i.status = 'live' WHERE o.day = ? AND o.channel_id = ? "
            . 'GROUP BY o.order_ref', [$day, $channelId]) as $r) {
            $orders[strtolower((string) $r['order_ref'])] = (int) $r['c'];
        }
        $o = $db->one("SELECT COALESCE(SUM(l.cost), 0) AS c, COUNT(*) AS n FROM carrier_invoice_line l JOIN carrier_invoice i ON i.id = l.invoice_id AND i.status = 'live' "
            . "AND i.channel_id = ? WHERE l.match_state IN ('not_matched', 'ambiguous') AND l.line_date = ?", [$channelId, $day]) ?? [];
        return ['orders' => $orders, 'other' => (int) ($o['c'] ?? 0), 'other_lines' => (int) ($o['n'] ?? 0)];
    }

    // ---- the work ------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the bill read from its file */
    private static function read(string $carrier, string $bytes, int $vatBp): array
    {
        if (!in_array($carrier, self::CARRIERS, true)) {
            throw new CwException('bad_carrier', 'CW reads DPD bills only in this version', 422, ['field' => 'carrier']);
        }
        return DpdInvoiceFile::parse($bytes, $vatBp);
    }

    /** @param array<string, mixed> $bill */
    private static function key(array $bill): string
    {
        return $bill['carrier'] . ':' . $bill['account'] . ':' . $bill['invoice_no'];
    }

    /**
     * The new bill and its lines stored, matched, counted (and $old superseded): one guarded transaction.
     *
     * @param array<string, mixed> $bill
     * @param array<string, mixed>|null $old the live bill a corrected file replaces
     */
    private function store(Caller $caller, array $bill, ?int $shop, ?array $old): int
    {
        return $this->guarded(function (Db $db) use ($caller, $bill, $shop, $old): array {
            $lines = $bill['lines'];
            $hits = self::parcels($db, $lines);
            $channel = $old !== null ? (int) $old['channel_id'] : self::accountShop($db, (string) $bill['carrier'], (string) $bill['account'], $hits, $shop);
            $start = self::startDay($db, $channel);
            $match = self::decide($db, $lines, $hits, $channel, $start);
            $after = [];
            foreach ($lines as $i => $l) {
                $after[] = ['live' => true, 'state' => $match[$i]['state'], 'ch' => $match[$i]['channel_id'], 'ref' => $match[$i]['order_ref'], 'inv_ch' => $channel,
                    'date' => $l['line_date'], 'cost' => (int) $l['cost']];
            }
            $before = $old === null ? [] : self::facts($db, [(int) $old['id']], true);
            return ['before' => $before, 'after' => $after, 'write' => function (Db $db) use ($caller, $bill, $channel, $match, $old): int {
                $now = Clock::db(($this->clock)());
                if ($old !== null) {
                    // The old bill first: its key is free for the new one (one live bill per key, uq_carrier_invoice_live).
                    $db->exec("UPDATE carrier_invoice SET status = 'superseded', ended_by = ?, ended_actor = ?, ended_at = ? WHERE id = ? AND status = 'live'",
                        [$caller->staffUserId, $caller->actor, $now, (int) $old['id']]);
                }
                try {
                    $id = $db->insert('INSERT INTO carrier_invoice (carrier, account, invoice_no, invoice_date, period_from, period_to, channel_id, net_pence, vat_pence, '
                        . 'gross_pence, line_count, file_sha256, status, supersedes_id, uploaded_by, uploaded_actor, uploaded_at) '
                        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'live', ?, ?, ?, ?)",
                        [$bill['carrier'], $bill['account'], $bill['invoice_no'], $bill['invoice_date'], $bill['period_from'], $bill['period_to'], $channel, $bill['net'],
                            $bill['vat'], $bill['gross'], count($bill['lines']), $bill['sha256'], $old === null ? null : (int) $old['id'], $caller->staffUserId,
                            $caller->actor, $now]);
                } catch (\PDOException $e) {
                    if (Db::driverCode($e) === 1062) {
                        throw new CwException('already_uploaded', "DPD bill {$bill['invoice_no']} was uploaded meanwhile: nothing was saved.", 409);
                    }
                    throw $e;
                }
                $cols = ['invoice_id', 'line_no', 'line_date', 'consignment', 'parcel_no', 'header_parcel', 'product_code', 'product', 'service_code', 'service', 'weight',
                    'items', 'senders_ref', 'vat_code', 'marks', 'money', 'cost', 'match_state', 'channel_id', 'order_ref', 'parcel_ref', 'candidates', 'matched_at'];
                foreach (array_chunk($bill['lines'], 200, true) as $chunk) {
                    $params = [];
                    foreach ($chunk as $i => $l) {
                        $m = $match[$i];
                        array_push($params, $id, $l['line_no'], $l['line_date'], $l['consignment'], $l['parcel_no'], $l['header_parcel'], $l['product_code'], $l['product'],
                            $l['service_code'], $l['service'], $l['weight'], $l['items'], $l['senders_ref'], $l['vat_code'], $l['marks'],
                            json_encode((object) $l['money'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $l['cost'], $m['state'], $m['channel_id'], $m['order_ref'],
                            $m['parcel_ref'], $m['candidates'], in_array($m['state'], self::ON_ORDER, true) ? $now : null);
                    }
                    $db->exec('INSERT INTO carrier_invoice_line (' . implode(', ', $cols) . ') VALUES '
                        . implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($cols), '?')) . ')')), $params);
                }
                $counts = array_count_values(array_column($match, 'state'));
                Audit::write($db, $caller, $old === null ? 'delivery_bill.upload' : 'delivery_bill.replace', 'carrier_invoice', (string) $id, null, [
                    'carrier' => $bill['carrier'], 'account' => $bill['account'], 'invoice' => $bill['invoice_no'], 'lines' => count($bill['lines']),
                    'net_pence' => $bill['net'], 'sha256' => $bill['sha256'], 'states' => $counts] + ($old === null ? [] : ['replaces' => (int) $old['id']]));
                return $id;
            }];
        });
    }

    /** Every line of a live bill still not matched or ambiguous, tried again (inside the delivery lock). */
    private function rematchAll(Caller $caller): int
    {
        return $this->guarded(function (Db $db) use ($caller): array {
            $rows = $db->all("SELECT l.invoice_id, l.line_no, l.line_date, l.consignment, l.parcel_no, l.senders_ref, l.money, l.cost, l.match_state, l.candidates, "
                . "i.channel_id AS inv_ch "
                . "FROM carrier_invoice_line l JOIN carrier_invoice i ON i.id = l.invoice_id AND i.status = 'live' WHERE l.match_state IN ('not_matched', 'ambiguous') "
                . 'ORDER BY l.invoice_id, l.line_no');
            if ($rows === []) {
                return ['before' => [], 'after' => [], 'write' => static fn (): int => 0];
            }
            $lines = array_map(static fn (array $r): array => ['line_date' => (string) $r['line_date'], 'consignment' => $r['consignment'], 'parcel_no' => $r['parcel_no'],
                'senders_ref' => $r['senders_ref'], 'collection' => array_key_exists(DpdInvoiceFile::COLLECTION, (array) json_decode((string) $r['money'], true))], $rows);
            $hits = self::parcels($db, $lines);
            $byShop = [];
            foreach ($rows as $i => $r) {
                $byShop[(int) $r['inv_ch']][$i] = $lines[$i];
            }
            $match = [];
            foreach ($byShop as $ch => $set) {
                $match += self::decide($db, $set, $hits, $ch, self::startDay($db, $ch));
            }
            $before = [];
            $after = [];
            $changed = [];
            foreach ($rows as $i => $r) {
                $m = $match[$i];
                if ($m['state'] === $r['match_state'] && (int) $m['candidates'] === (int) $r['candidates']) {
                    continue; // as it was
                }
                $was = ['live' => true, 'state' => (string) $r['match_state'], 'ch' => null, 'ref' => null, 'inv_ch' => (int) $r['inv_ch'], 'date' => (string) $r['line_date'],
                    'cost' => (int) $r['cost']];
                if ($m['state'] === 'ambiguous' && $r['match_state'] === 'ambiguous') {
                    $changed[] = [$r, $m]; // the count of parcels that fit changed: no figure moves
                    continue;
                }
                $before[] = $was;
                $after[] = ['live' => true, 'state' => $m['state'], 'ch' => $m['channel_id'], 'ref' => $m['order_ref'], 'inv_ch' => (int) $r['inv_ch'],
                    'date' => (string) $r['line_date'], 'cost' => (int) $r['cost']];
                $changed[] = [$r, $m];
            }
            return ['before' => $before, 'after' => $after, 'write' => function (Db $db) use ($caller, $changed): int {
                $now = Clock::db(($this->clock)());
                $n = 0;
                $states = [];
                foreach ($changed as [$r, $m]) {
                    $n += $db->exec('UPDATE carrier_invoice_line SET match_state = ?, channel_id = ?, order_ref = ?, parcel_ref = ?, candidates = ?, matched_at = ? '
                        . "WHERE invoice_id = ? AND line_no = ? AND match_state IN ('not_matched', 'ambiguous')", [$m['state'], $m['channel_id'], $m['order_ref'],
                            $m['parcel_ref'], $m['candidates'], in_array($m['state'], self::ON_ORDER, true) ? $now : null, (int) $r['invoice_id'], (int) $r['line_no']]);
                    if ($m['state'] !== $r['match_state']) {
                        $states[$r['match_state'] . '>' . $m['state']] = ($states[$r['match_state'] . '>' . $m['state']] ?? 0) + 1;
                    }
                }
                if ($states !== []) {
                    Audit::write($db, $caller, 'delivery_bill.rematch', 'carrier_invoice', null, null, ['changed' => $states]);
                }
                return array_sum($states);
            }];
        });
    }

    /**
     * The CW parcels of DPD whose tracking number is a line's consignment or parcel number (not removed, with a label time).
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, list<array{channel_id: int, order_ref: string, parcel_ref: string, label_day: string}>> tracking number => parcels
     */
    private static function parcels(Db $db, array $lines): array
    {
        $keys = [];
        foreach ($lines as $l) {
            foreach ([$l['consignment'] ?? null, $l['parcel_no'] ?? null] as $k) {
                if ($k !== null && $k !== '') {
                    $keys[(string) $k] = true;
                }
            }
        }
        $out = [];
        foreach (array_chunk(array_map('strval', array_keys($keys)), 500) as $chunk) {
            foreach ($db->all('SELECT channel_id, order_ref, parcel_ref, tracking_no, carrier, label_at FROM sale_parcel WHERE tracking_no IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ') AND removed_at IS NULL AND label_at IS NOT NULL', $chunk) as $p) {
                if (!in_array(strtolower((string) $p['carrier']), self::DPD_PARCELS, true)) {
                    continue;
                }
                $out[(string) $p['tracking_no']][] = ['channel_id' => (int) $p['channel_id'], 'order_ref' => (string) $p['order_ref'],
                    'parcel_ref' => (string) $p['parcel_ref'], 'label_day' => ReportRefresh::ukDay((string) $p['label_at'])];
            }
        }
        return $out;
    }

    /**
     * Each line's state (DL7-DL10) in the order: one parcel in the window, a collection on one order of the account's shop, before the
     * shop's start, not matched.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, list<array<string, mixed>>> $hits parcels()
     * @return array<int, array{state: string, channel_id: ?int, order_ref: ?string, parcel_ref: ?string, candidates: int}> by the lines' keys
     */
    private static function decide(Db $db, array $lines, array $hits, int $shop, ?string $startDay): array
    {
        $out = [];
        $refs = [];
        foreach ($lines as $i => $l) {
            $fit = [];
            foreach ([$l['consignment'] ?? null, $l['parcel_no'] ?? null] as $k) {
                foreach ($k === null ? [] : ($hits[(string) $k] ?? []) as $p) {
                    $gap = self::days((string) $p['label_day'], (string) $l['line_date']);
                    if ($gap >= -self::WINDOW_AFTER && $gap <= self::WINDOW_BEFORE) {
                        $fit[strtolower($p['channel_id'] . '|' . $p['order_ref'] . '|' . $p['parcel_ref'])] = $p;
                    }
                }
            }
            if (count($fit) === 1) {
                $p = array_values($fit)[0];
                $out[$i] = ['state' => 'matched', 'channel_id' => (int) $p['channel_id'], 'order_ref' => (string) $p['order_ref'], 'parcel_ref' => (string) $p['parcel_ref'],
                    'candidates' => 0];
            } elseif (count($fit) > 1) {
                $out[$i] = self::none('ambiguous', min(255, count($fit)));
            } elseif (($l['collection'] ?? false) === true && ($l['senders_ref'] ?? null) !== null) {
                $refs[(string) $l['senders_ref']] = true;
            }
        }
        $orders = [];
        foreach (array_chunk(array_map('strval', array_keys($refs)), 500) as $chunk) {
            foreach ($db->all('SELECT order_ref, order_no FROM sale_order WHERE channel_id = ? AND order_no IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$shop, ...$chunk]) as $o) {
                $orders[(string) $o['order_no']][] = (string) $o['order_ref'];
            }
        }
        foreach ($lines as $i => $l) {
            if (isset($out[$i])) {
                continue;
            }
            $found = ($l['collection'] ?? false) === true && ($l['senders_ref'] ?? null) !== null ? ($orders[(string) $l['senders_ref']] ?? []) : [];
            if (count($found) === 1) {
                $out[$i] = ['state' => 'collection', 'channel_id' => $shop, 'order_ref' => $found[0], 'parcel_ref' => null, 'candidates' => 0];
            } elseif (count($found) > 1) {
                $out[$i] = self::none('ambiguous', min(255, count($found)));
            } elseif ($startDay === null || (string) $l['line_date'] <= $startDay) {
                $out[$i] = self::none('before_start');
            } else {
                $out[$i] = self::none('not_matched');
            }
        }
        return $out;
    }

    /** @return array{state: string, channel_id: null, order_ref: null, parcel_ref: null, candidates: int} */
    private static function none(string $state, int $candidates = 0): array
    {
        return ['state' => $state, 'channel_id' => null, 'order_ref' => null, 'parcel_ref' => null, 'candidates' => $candidates];
    }

    /** Whole days from $from to $to (Y-m-d; negative when $to is before $from). */
    private static function days(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from . ' 00:00:00', Clock::utc());
        $b = new \DateTimeImmutable($to . ' 00:00:00', Clock::utc());
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /**
     * The shop an account bills (DL8): the one its earlier bills name; for an account new to CW, the shop most of the file's parcels are
     * CW parcels of (a shop chosen on the form must agree); none of them CW's: the shop chosen on the form; none chosen: refused.
     *
     * @param array<string, list<array<string, mixed>>> $hits
     */
    private static function accountShop(Db $db, string $carrier, string $account, array $hits, ?int $choice): int
    {
        if ($choice !== null && $db->value('SELECT id FROM channel WHERE id = ?', [$choice]) === null) {
            throw new CwException('bad_shop', 'there is no such shop', 422, ['field' => 'shop']);
        }
        $known = $db->value('SELECT channel_id FROM carrier_invoice WHERE carrier = ? AND account = ? ORDER BY id DESC LIMIT 1', [$carrier, $account]);
        $name = static fn (int $id): string => (string) ($db->value('SELECT name FROM channel WHERE id = ?', [$id]) ?? $id);
        if ($known !== null) {
            if ($choice !== null && $choice !== (int) $known) {
                throw new CwException('account_shop', sprintf('DPD account %s bills %s in CW (its earlier bills): leave the shop empty, or choose %s. Nothing was saved.',
                    $account, $name((int) $known), $name((int) $known)), 422, ['field' => 'shop']);
            }
            return (int) $known;
        }
        $votes = [];
        foreach ($hits as $parcels) {
            foreach ($parcels as $p) {
                $votes[(int) $p['channel_id']] = ($votes[(int) $p['channel_id']] ?? 0) + 1;
            }
        }
        if ($votes !== []) {
            arsort($votes);
            $top = (int) array_key_first($votes);
            if ($choice !== null && $choice !== $top) {
                throw new CwException('account_shop', sprintf('the parcels on this bill are %s\'s in CW, not %s\'s: choose %s or leave the shop empty. Nothing was saved.',
                    $name($top), $name($choice), $name($top)), 422, ['field' => 'shop']);
            }
            return $top;
        }
        if ($choice !== null) {
            return $choice;
        }
        throw new CwException('account_shop_unknown', sprintf('DPD account %s is new to CW and none of the parcels on this bill are CW\'s: choose the shop the account '
            . 'bills and upload the file again. Nothing was saved.', $account), 422, ['field' => 'shop']);
    }

    /** The UK day of the shop's first reservation in CW (OrderSnapshots::firstReservation); null: none yet (every line is before it). */
    public static function startDay(Db $db, int $channelId): ?string
    {
        $first = OrderSnapshots::firstReservation($db, $channelId);
        return $first === null ? null : ReportRefresh::ukDay($first);
    }

    /**
     * The lines of these bills as facts for the day figures: whether they count, their state, the order they are on, the shop of the bill
     * and their date and cost.
     *
     * @param list<int> $ids
     * @return list<array{live: bool, state: string, ch: ?int, ref: ?string, inv_ch: int, date: string, cost: int}>
     */
    private static function facts(Db $db, array $ids, bool $live): array
    {
        $out = [];
        foreach ($db->all('SELECT l.match_state, l.channel_id, l.order_ref, l.line_date, l.cost, i.channel_id AS inv_ch FROM carrier_invoice_line l '
            . 'JOIN carrier_invoice i ON i.id = l.invoice_id WHERE l.invoice_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
            $out[] = ['live' => $live, 'state' => (string) $r['match_state'], 'ch' => $r['channel_id'] === null ? null : (int) $r['channel_id'],
                'ref' => $r['order_ref'] === null ? null : (string) $r['order_ref'], 'inv_ch' => (int) $r['inv_ch'], 'date' => (string) $r['line_date'],
                'cost' => (int) $r['cost']];
        }
        return $out;
    }

    /**
     * One delivery write (DL13): $plan reads what it changes ({before, after, write}: the facts of the lines as they count now and as they
     * will count, and the writes); the day rows both sets count on are locked in key order (ProfitDays::guard); the orders they count on
     * are read again and an order not paid is held with a shared lock (so it cannot be paid meanwhile); when an order moved to another day
     * since the plan read it, the transaction is rolled back and tried again (TRIES times); else the writes, the day figures by the
     * difference, and the day rows left with nothing to count removed. The caller holds the delivery lock.
     *
     * @template T
     * @param \Closure(Db): array{before: list<array<string, mixed>>, after: list<array<string, mixed>>, write: \Closure(Db): T} $plan
     * @return T
     */
    private function guarded(\Closure $plan): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $this->db->transaction(static function (Db $db) use ($plan): mixed {
                    $p = $plan($db);
                    $facts = [...$p['before'], ...$p['after']];
                    $owners = [];
                    foreach ($facts as $f) {
                        if ($f['live'] && in_array($f['state'], self::ON_ORDER, true)) {
                            $owners[$f['ch'] . '|' . strtolower((string) $f['ref'])] = [(int) $f['ch'], (string) $f['ref']];
                        }
                    }
                    ksort($owners, SORT_STRING);
                    $days = self::orderDays($db, $owners, false);
                    $old = self::contributions($p['before'], $days);
                    $new = self::contributions($p['after'], $days);
                    $pairs = [];
                    foreach ([...array_keys($old), ...array_keys($new)] as $k) {
                        $pairs[substr($k, 0, strrpos($k, '|'))] = true;
                    }
                    $pairs = array_keys($pairs);
                    // profit_day's key order (day, channel_id), as every snapshot takes its own store's days (ProfitDays::guard)
                    usort($pairs, static function (string $a, string $b): int {
                        [$dayA, $chA] = explode('|', $a);
                        [$dayB, $chB] = explode('|', $b);
                        return [$dayA, (int) $chA] <=> [$dayB, (int) $chB];
                    });
                    foreach ($pairs as $pair) {
                        [$day, $ch] = explode('|', $pair);
                        ProfitDays::guard($db, (int) $ch, [$day]);
                    }
                    // The orders counted, read again now that their days are held: one paid meanwhile, or moved, means the plan is old.
                    if (self::orderDays($db, $owners, true) !== $days) {
                        throw new CwException(self::RETRY, 'an order of these lines moved to another day: tried again', 409);
                    }
                    $result = ($p['write'])($db);
                    $delta = [];
                    foreach ([[$old, -1], [$new, 1]] as [$set, $sign]) {
                        foreach ($set as $k => $v) {
                            $delta[$k] = ($delta[$k] ?? 0) + $sign * $v;
                        }
                    }
                    ksort($delta, SORT_STRING);
                    foreach ($delta as $k => $v) {
                        if ($v === 0) {
                            continue;
                        }
                        [$day, $ch, $field] = explode('|', $k);
                        $db->exec("UPDATE profit_day SET {$field} = {$field} + ? WHERE day = ? AND channel_id = ?", [$v, $day, (int) $ch]);
                    }
                    foreach ($pairs as $pair) {
                        [$day, $ch] = explode('|', $pair);
                        self::tidy($db, (int) $ch, $day);
                    }
                    return $result;
                });
            } catch (CwException $e) {
                if ($e->errorCode !== self::RETRY) {
                    throw $e;
                }
                if ($try >= self::TRIES) {
                    throw new CwException('busy', 'the orders of these lines kept moving to other days while they were saved: nothing was saved; try again in a minute', 503);
                }
            }
        }
    }

    /**
     * What facts count, by "day|channel|field" (field delivery_cost or delivery_other).
     *
     * @param list<array<string, mixed>> $facts
     * @param array<string, ?string> $days owner key => its paid day
     * @return array<string, int>
     */
    private static function contributions(array $facts, array $days): array
    {
        $out = [];
        foreach ($facts as $f) {
            if (!$f['live']) {
                continue;
            }
            if (in_array($f['state'], self::ON_ORDER, true)) {
                $day = $days[$f['ch'] . '|' . strtolower((string) $f['ref'])] ?? null;
                if ($day === null) {
                    continue; // an order not paid counts nothing yet (as its goods)
                }
                $k = $day . '|' . (int) $f['ch'] . '|delivery_cost';
            } elseif (in_array($f['state'], self::OTHER, true)) {
                $k = $f['date'] . '|' . (int) $f['inv_ch'] . '|delivery_other';
            } else {
                continue; // before CW start: not in the profit
            }
            $out[$k] = ($out[$k] ?? 0) + (int) $f['cost'];
        }
        return $out;
    }

    /**
     * The paid day of each order (null: not paid), in key order. $lock: the orders not paid are read with a shared lock (FOR SHARE), so a
     * snapshot cannot pay one before this transaction ends; a paid one cannot move without the day row this transaction holds.
     *
     * @param array<string, array{0: int, 1: string}> $owners key => [channel, ref]
     * @return array<string, ?string>
     */
    private static function orderDays(Db $db, array $owners, bool $lock): array
    {
        $out = [];
        $byShop = [];
        foreach ($owners as $k => [$ch, $ref]) {
            $out[$k] = null;
            $byShop[$ch][] = $ref;
        }
        ksort($byShop);
        foreach ($byShop as $ch => $refs) {
            foreach (array_chunk($refs, 500) as $chunk) {
                $marks = implode(', ', array_fill(0, count($chunk), '?'));
                foreach ($db->all("SELECT order_ref, day FROM sale_order WHERE channel_id = ? AND order_ref IN ({$marks}) ORDER BY order_ref", [$ch, ...$chunk]) as $r) {
                    $out[$ch . '|' . strtolower((string) $r['order_ref'])] = $r['day'] === null ? null : (string) $r['day'];
                }
                if ($lock) {
                    $unpaid = array_values(array_filter($chunk, static fn (string $r): bool => $out[$ch . '|' . strtolower($r)] === null));
                    if ($unpaid !== []) {
                        foreach ($db->all('SELECT order_ref, day FROM sale_order WHERE channel_id = ? AND order_ref IN (' . implode(', ', array_fill(0, count($unpaid), '?'))
                            . ') ORDER BY order_ref FOR SHARE', [$ch, ...$unpaid]) as $r) {
                            $out[$ch . '|' . strtolower((string) $r['order_ref'])] = $r['day'] === null ? null : (string) $r['day'];
                        }
                    }
                }
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** A store's day row left with nothing to count (every figure 0, no paid order, no line on no order dated that day): removed. */
    private static function tidy(Db $db, int $channelId, string $day): void
    {
        $row = $db->one('SELECT ' . implode(', ', ProfitDays::DAY_FIELDS) . ' FROM profit_day WHERE day = ? AND channel_id = ?', [$day, $channelId]);
        if ($row === null || array_filter(array_map('intval', $row)) !== []) {
            return;
        }
        if ($db->value('SELECT 1 FROM sale_order WHERE day = ? AND channel_id = ? LIMIT 1', [$day, $channelId]) !== null
            || $db->value("SELECT 1 FROM carrier_invoice_line l JOIN carrier_invoice i ON i.id = l.invoice_id AND i.status = 'live' AND i.channel_id = ? "
                . "WHERE l.match_state IN ('not_matched', 'ambiguous') AND l.line_date = ? LIMIT 1", [$channelId, $day]) !== null) {
            return;
        }
        $db->exec('DELETE FROM profit_day WHERE day = ? AND channel_id = ?', [$day, $channelId]);
    }

    /**
     * $fn under the delivery lock (one write at a time: the screen and the nightly job), at most LOCK_WAIT_SEC, then 503 busy.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function locked(\Closure $fn): mixed
    {
        $name = 'cw_delivery_bills:' . (string) $this->db->value('SELECT DATABASE()');
        if ((int) $this->db->value('SELECT GET_LOCK(?, ?)', [$name, self::LOCK_WAIT_SEC]) !== 1) {
            throw new CwException('busy', 'another delivery bill is being saved right now: try again in a minute. Nothing was saved.', 503);
        }
        try {
            return $fn();
        } finally {
            try {
                $this->db->value('SELECT RELEASE_LOCK(?)', [$name]);
            } catch (\Throwable) {
                // the connection is gone: MySQL released it with the session
            }
        }
    }

    /** Only a person holding delivery.upload (the Delivery bills job), never admin (I12), re-read now. */
    private function authorise(Caller $caller): void
    {
        if ($caller->staffUserId === null) {
            if (str_starts_with($caller->actor, 'system:')) {
                return; // a server tool (bin/match_delivery_bills.php, the tests)
            }
            throw new CwException('staff_required', 'only staff upload delivery bills', 403);
        }
        $roles = StaffRoles::active($this->db, $caller->staffUserId);
        if (in_array('admin', $roles, true)) {
            throw new CwException('admin_cannot_post', 'admin manages people and roles only', 403);
        }
        if (!Permissions::can($roles, self::PERM_UPLOAD)) {
            throw new CwException('role_not_allowed', 'your jobs cannot upload, replace or undo delivery bills', 403);
        }
    }

    /** A stored UTC time as the UK sees it ("20 Nov 2026 15:40"). */
    private static function when(string $utc): string
    {
        return Clock::fromDb($utc)->setTimezone(new \DateTimeZone('Europe/London'))->format('j M Y H:i');
    }
}
