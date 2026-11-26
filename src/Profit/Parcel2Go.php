<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Reports\ReportRefresh;
use CW\Settings;

/**
 * Parcel2Go's labels in CW's delivery cost (CW-1160, 0085; docs/decisions.md PGL1-PGL12; design cw_work/runs/P2G_STATE.md). ONE service for
 * the 10-minute job (bin/p2g_pull.php), the export import, the go-live kit's switch and undo, the screens and the health alert.
 *
 *  run()       the account's recent lines read (P2gApi::recent), new ones stored DUE before anything else is asked (a run that stops leaves
 *              them due), their tracking numbers asked for, each due line's BASKET invoice read (P2gInvoice: one per checkout, a row per label,
 *              an "Extra Payment" row a later charge of an earlier label) and stored as ONE Parcel2Go bill with its money placed, and every bill of
 *              the last REPLAN_DAYS whose money is not all on orders placed again (a CW parcel or a tracking number may have come).
 *  import()    the "My Orders" export (P2gCsv): its lines and tracking numbers added (fill-only), proved to be THIS account's first (PGL8).
 *  switchOn() / switchOff()   the account's first day and state (the kit only); undoAll() every Parcel2Go bill of an account switched off undone.
 *
 * Where a label's money goes (PGL4; the owner's answers of 26 Nov 2026, Q2; DL86 "no hold"): each row of a bill goes on the parcels of the
 * label it NAMES (an Extra Payment on the earlier label's parcels, wherever that label was paid), shared over them (PooledPlanner::shares):
 *   a CW parcel already pinned to the line's parcel: that order (sticky, as DPD's DL7 and Royal Mail's DL74);
 *   no tracking number yet: waits `held: no_tracking` (in no profit) up to delivery.p2g_tracking_wait_days, then the main shop's "not on an
 *     order" (how no_tracking) until the number comes;
 *   the number on exactly ONE CW parcel (not removed, its label day from WINDOW_BEFORE days before the line's paid day to WINDOW_AFTER days
 *     after), of ANY shop the account serves, whatever its carrier word: on that order, in that order's shop, UNLESS the parcel already holds
 *     another carrier account's cost (a live DPD line on it, or a live allocation of another bill: `costed_twice`, PGL5 never twice) or the
 *     number is on another Parcel2Go line too (`tracking_twice`): then the main shop's "not on an order";
 *   two or more CW parcels (`two_parcels`), a shop the account does not serve (`other_shop`): the main shop's "not on an order";
 *   no CW parcel: before CW start when the line's paid day is on or before the main shop's start (Vape Big before 17 Nov), else the main
 *     shop's "not on an order" (`no_parcel`). Never by any reference.
 *   A parcel whose money on an order would be below 0 (a discount larger than the price) goes to the main shop (`refund_cap`).
 * The main shop is the account's (Vape and Go's account: Vape and Go; Vape Big's: Vape Big). Every money write goes through
 * DeliveryBills::guardedWrite under the delivery bills' lock (the order guards, the day rows, the orders read again, the plan's parcels
 * resolved again under the guards); a row's live allocations always add up to its net (DB8); nothing is ever deleted.
 */
final class Parcel2Go
{
    public const CARRIER = 'parcel2go';
    public const FROM_SETTING = 'delivery.p2g_from_';
    public const WAIT_SETTING = 'delivery.p2g_tracking_wait_days';
    public const PRICE_WAIT_SETTING = 'delivery.p2g_price_wait_hours';
    /** A CW parcel's label day may be at most this many days before the line's paid day ... */
    public const WINDOW_BEFORE = 3;
    /** ... and at most this many after it (staff type the tracking number into the shop later). WAITS FOR THE PROBE (U9: measured on Nov). */
    public const WINDOW_AFTER = 14;
    /** The lines whose money is not on an order are placed again for this many days after they were paid. */
    public const REPLAN_DAYS = 60;
    public const PRICE_PER_RUN = 40;
    public const TRACK_PER_RUN = 60;
    /** A line's tracking number is asked for again at most once in this many minutes. */
    public const TRACK_EVERY_MIN = 60;
    /** The waits after a failed read of a line's invoice, in minutes (then refused). */
    public const RETRY_MIN = [10, 30, 120, 360, 720, 1440];
    /** The recent list's length (P2gApi): a full list of only new lines means lines may have been missed. */
    public const RECENT_FULL = 25;
    /** The CW lock of every Parcel2Go write (run, import, switch, undo): one at a time per schema. */
    public const LOCK = 'cw_p2g:';
    public const LOCK_WAIT_SEC = 30;
    /** The quick stop waits this long for a run of the job to end (the job's own deadline is shorter). */
    public const STOP_WAIT_SEC = 300;
    /** Parcel2Go's answers that are a later try, not a refusal (the final review, F4). */
    public const API_LATER = [404, 408, 409, 425];
    /** A label Parcel2Go's API refused (api_<status>) is due again after this many hours (F4: once a day). */
    public const API_AGAIN_HOURS = 24;
    /** The export's ownership rule (PGL8): at least this many of its lines already known for THIS account (all, when it has fewer). */
    public const IMPORT_KNOWN = 5;
    /** The reasons the main shop takes a label's money (carrier_allocation.how), in staff words. */
    public const HOWS = [
        'label' => 'on its order', 'no_parcel' => 'no CW order has this tracking number', 'before_start' => 'before the shop was in CW',
        'no_tracking' => 'its tracking number is not known yet', 'two_parcels' => 'two CW parcels have this tracking number',
        'other_shop' => 'its CW order is of a shop this account does not serve', 'costed_twice' => 'its CW parcel already has another carrier\'s cost',
        'tracking_twice' => 'another Parcel2Go label has the same tracking number', 'refund_cap' => 'a discount larger than the price'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private readonly DeliveryBills $bills;
    /** @var array<int, ?string> the shops' first days, read once per run (F11) */
    private array $starts = [];

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null, int $lockWaitSec = DeliveryBills::LOCK_WAIT_SEC)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->bills = new DeliveryBills($db, $this->clock, null, $lockWaitSec);
    }

    // ---- settings ------------------------------------------------------------------------------------------------------------------

    /**
     * The Parcel2Go accounts by key name, each with its main shop and whether it is switched on (state active AND a first day).
     *
     * @return array<string, array{id: int, key: string, account_no: string, name: string, state: string, party: string, main: int, from: ?string, on: bool}>
     */
    public static function accounts(Db $db): array
    {
        $out = [];
        foreach ($db->all("SELECT a.id, a.account_no, a.name, a.key_name, a.state, p.code AS party, p.channel_id AS main FROM carrier_account a JOIN delivery_party p "
            . "ON p.id = a.main_party_id WHERE a.carrier = ? AND a.key_name IS NOT NULL ORDER BY a.id", [self::CARRIER]) as $r) {
            if ($r['main'] === null) {
                continue;
            }
            $raw = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?", [self::FROM_SETTING . $r['party']]);
            $from = is_string($raw) ? json_decode($raw, true) : null;
            $from = is_string($from) && preg_match('/^20[0-9]{2}-[01][0-9]-[0-3][0-9]$/D', $from) === 1 ? $from : null;
            $out[(string) $r['key_name']] = ['id' => (int) $r['id'], 'key' => (string) $r['key_name'], 'account_no' => (string) $r['account_no'], 'name' => (string) $r['name'],
                'state' => (string) $r['state'], 'party' => (string) $r['party'], 'main' => (int) $r['main'], 'from' => $from,
                'on' => $r['state'] === 'active' && $from !== null];
        }
        return $out;
    }

    /** @return array{id: int, key: string, account_no: string, name: string, state: string, party: string, main: int, from: ?string, on: bool} */
    public static function account(Db $db, string $key): array
    {
        return self::accounts($db)[$key] ?? throw new CwException('not_found', 'there is no such Parcel2Go account in CW', 404, ['field' => 'account']);
    }

    private function int(string $key, int $default): int
    {
        $v = (new Settings($this->db))->get($key);
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : $default);
    }

    private function now(): string
    {
        return Clock::db(($this->clock)());
    }

    // ---- the job -------------------------------------------------------------------------------------------------------------------

    /**
     * One run of the 10-minute job for one account (see the class). Nothing when the account is switched off.
     *
     * @return array<string, int|string|null>
     */
    public function run(Caller $caller, string $key, P2gApi $api, P2gPdfText $pdf, ?float $deadline = null): array
    {
        return $this->locked(function () use ($caller, $key, $api, $pdf, $deadline): array {
            $acct = self::account($this->db, $key);
            if (!$acct['on']) {
                return ['result' => 'off'];
            }
            if ($api->account() !== $key) {
                throw new CwException('p2g_key_refused', 'the Parcel2Go key is for another account: nothing was read', 403);
            }
            $this->starts = [];
            $run = $this->startRun($acct, 'pull', $caller);
            $sum = ['listed' => 0, 'lines_new' => 0, 'tracking_found' => 0, 'priced' => 0, 'refused' => 0, 'placed' => 0, 'gap_seen' => 0];
            try {
                $lines = $api->recent();
                $sum = $this->takeLines($acct, $lines, $sum, $run);
                $this->tracking($acct, $api, $deadline, $sum);
                $this->dueAgain($acct);
                $this->prices($caller, $acct, $api, $pdf, $deadline, $sum);
                $this->replan($caller, $acct, $run, $deadline, $sum);
                $this->endRun($run, 'ok', $sum, $api->calls(), null);
            } catch (\Throwable $e) {
                $this->endRun($run, 'failed', $sum, $api->calls(), self::why($e));
                throw $e;
            }
            return ['result' => 'ok', 'run' => $run, 'calls' => $api->calls()] + $sum;
        });
    }

    /**
     * The recent list's lines stored: new ones with their parcels, due when paid on or after the account's first day; known ones only fill
     * what is empty (PGL3). A line the list names twice is taken once. One transaction. The gap check (PGL9): a full list of only new lines
     * is written WITH the labels (the run's gap_seen and its days: from the newest paid day CW knew to the oldest of the new list), so a run
     * stopped later keeps it (the final review, H8); only an export covering those days clears it (health()).
     *
     * @param list<array<string, mixed>> $lines P2gApi::recent()
     * @param array<string, int> $sum
     * @return array<string, int>
     */
    private function takeLines(array $acct, array $lines, array $sum, int $run): array
    {
        $sum['listed'] = count($lines);
        if ($lines === []) {
            return $sum;
        }
        $full = count($lines) >= self::RECENT_FULL;
        $once = [];
        foreach ($lines as $l) {
            $once[(int) $l['order_line_id']] ??= $l;
        }
        $lines = array_values($once);
        $now = $this->now();
        return $this->db->transaction(function (Db $db) use ($acct, $lines, $sum, $now, $full, $run): array {
            $ids = array_map(static fn (array $l): int => (int) $l['order_line_id'], $lines);
            $known = [];
            foreach ($db->all('SELECT * FROM p2g_line WHERE account_id = ? AND order_line_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') FOR UPDATE',
                [$acct['id'], ...$ids]) as $r) {
                $known[(int) $r['order_line_id']] = $r;
            }
            $before = (int) $db->value('SELECT COUNT(*) FROM p2g_line WHERE account_id = ?', [$acct['id']]);
            if ($full && $known === [] && $before > 0) {
                $sum['gap_seen'] = 1;
                $newest = $db->value('SELECT MAX(paid_day) FROM p2g_line WHERE account_id = ?', [$acct['id']]);
                $days = array_values(array_filter(array_map(static fn (array $l): ?string => $l['paid_at'] === null ? null : ReportRefresh::ukDay((string) $l['paid_at']), $lines)));
                $from = $newest === null ? ($acct['from'] ?? ReportRefresh::ukDay($now)) : (string) $newest;
                $to = $days === [] ? ReportRefresh::ukDay($now) : min($days);
                $db->exec('UPDATE p2g_run SET gap_seen = 1, gap_from = ?, gap_to = ? WHERE id = ?', [min($from, $to), max($from, $to), $run]);
            }
            foreach ($lines as $l) {
                $id = (int) $l['order_line_id'];
                $paidDay = $l['paid_at'] === null ? null : ReportRefresh::ukDay((string) $l['paid_at']);
                if (!isset($known[$id])) {
                    $db->exec('INSERT INTO p2g_line (account_id, order_line_id, transaction_id, source, kind, p2g_source, service_slug, courier_slug, country_iso3, parcels, '
                        . "paid_at, paid_day, doc_hash, price_state, first_at, updated_at) VALUES (?, ?, ?, 'api', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$acct['id'], $id, $l['transaction_id'], $l['kind'], $l['p2g_source'], $l['service_slug'], $l['courier_slug'], $l['country_iso3'], $l['parcels'],
                            $l['paid_at'], $paidDay, $l['doc_hash'], self::stateFor($acct, (string) $l['kind'], $paidDay), $now, $now]);
                    $this->parcelRows($db, $acct['id'], $id, (int) $l['parcels']);
                    $sum['lines_new']++;
                    continue;
                }
                $k = $known[$id];
                // fill only what is empty; a line not paid when first seen becomes due once it is (its state worked out now); a label first seen
                // as an Extra Payment of a basket (source invoice, not needed, no price: its day the invoice's) takes its own paid day and state
                $stub = self::stub($k);
                $paidAt = $stub && $l['paid_at'] !== null ? $l['paid_at'] : ($k['paid_at'] ?? $l['paid_at']);
                $day = $paidAt === null ? null : ReportRefresh::ukDay((string) $paidAt);
                $state = ($k['price_state'] === 'unpaid' && $paidAt !== null) || $stub ? self::stateFor($acct, (string) $k['kind'], $day) : (string) $k['price_state'];
                $db->exec('UPDATE p2g_line SET transaction_id = COALESCE(transaction_id, ?), p2g_source = COALESCE(p2g_source, ?), service_slug = COALESCE(service_slug, ?), '
                    . 'courier_slug = COALESCE(courier_slug, ?), country_iso3 = COALESCE(country_iso3, ?), doc_hash = COALESCE(doc_hash, ?), paid_at = ?, paid_day = ?, '
                    . 'price_state = ?, parcels = GREATEST(parcels, ?), updated_at = ? WHERE account_id = ? AND order_line_id = ?',
                    [$l['transaction_id'], $l['p2g_source'], $l['service_slug'], $l['courier_slug'], $l['country_iso3'], $l['doc_hash'], $paidAt, $day, $state, $l['parcels'], $now,
                        $acct['id'], $id]);
                $this->parcelRows($db, $acct['id'], $id, max((int) $k['parcels'], (int) $l['parcels']));
            }
            return $sum;
        });
    }

    /** A label CW first saw as an Extra Payment of another basket (its own price never asked for, its day the invoice's). @param array<string, mixed> $l */
    private static function stub(array $l): bool
    {
        return $l['source'] === 'invoice' && $l['price_state'] === 'not_needed' && $l['invoice_id'] === null && $l['kind'] === 'parcel';
    }

    /** A new line's price state: a top-up never (PGL7), not paid yet, before the account's first day, else due. */
    private static function stateFor(array $acct, string $kind, ?string $paidDay): string
    {
        return match (true) {
            $kind === 'topup' => 'not_needed',
            $paidDay === null => 'unpaid',
            $acct['from'] === null || $paidDay < $acct['from'] => 'not_needed',
            default => 'due',
        };
    }

    private function parcelRows(Db $db, int $accountId, int $id, int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $db->exec('INSERT INTO p2g_parcel (account_id, order_line_id, parcel_no) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE parcel_no = parcel_no', [$accountId, $id, $i]);
        }
    }

    /**
     * The tracking numbers asked for: lines due, priced or refused in the last REPLAN_DAYS, and lines a live bill charges (an Extra Payment of an
     * older label), with a parcel whose number is not known, at most once an hour each. A number is taken only when Parcel2Go names as many new
     * numbers as the line has parcels without one (never a guess).
     *
     * @param array<string, int> $sum
     */
    private function tracking(array $acct, P2gApi $api, ?float $deadline, array &$sum): void
    {
        $now = $this->now();
        $since = self::addDays(ReportRefresh::ukDay($now), -self::REPLAN_DAYS);
        $todo = $this->db->all("SELECT l.order_line_id FROM p2g_line l WHERE l.account_id = ? AND l.paid_day >= ? AND (l.track_next_at IS NULL OR l.track_next_at <= ?) "
            . "AND (l.price_state IN ('due', 'priced', 'refused') OR EXISTS (SELECT 1 FROM p2g_charge c JOIN carrier_invoice i ON i.id = c.invoice_id AND i.status = 'live' "
            . 'WHERE c.account_id = l.account_id AND c.order_line_id = l.order_line_id)) AND EXISTS (SELECT 1 FROM p2g_parcel p WHERE p.account_id = l.account_id '
            . 'AND p.order_line_id = l.order_line_id AND p.tracking_no IS NULL) ORDER BY l.paid_at, l.order_line_id LIMIT ' . self::TRACK_PER_RUN, [$acct['id'], $since, $now]);
        foreach ($todo as $t) {
            if ($deadline !== null && microtime(true) >= $deadline - 20) {
                return;
            }
            $id = (int) $t['order_line_id'];
            try {
                $numbers = $api->tracking($id);
            } catch (CwException $e) {
                if ($e->errorCode === 'p2g_deadline') {
                    return;
                }
                $numbers = [];
            } catch (\Throwable) {
                $numbers = []; // a fault in CW with this label's answer: asked again in an hour, the run goes on
            }
            $next = Clock::db(Clock::fromDb($now)->modify('+' . self::TRACK_EVERY_MIN . ' minutes'));
            $this->db->transaction(function (Db $db) use ($acct, $id, $numbers, $next, &$sum): void {
                $sum['tracking_found'] += self::fillTracking($db, $acct['id'], $id, $numbers, 'tracking_api');
                $db->exec('UPDATE p2g_line SET track_next_at = ? WHERE account_id = ? AND order_line_id = ?', [$next, $acct['id'], $id]);
            });
        }
    }

    /**
     * Tracking numbers put on a line's parcels that have none (in parcel order), only when there are exactly as many new numbers as such
     * parcels; a number already on a parcel of the line is not new. The export's numbers (source report) also REPLACE the tracking API's on
     * parcels no order took (unpinned; F8: the API's first number may be a courier's other number), only when the export names as many new
     * numbers as there are such parcels whose number it does not name. @param list<?string> $numbers @return int how many were put
     */
    private static function fillTracking(Db $db, int $accountId, int $id, array $numbers, string $source): int
    {
        $put = self::fillEmpty($db, $accountId, $id, $numbers, $source);
        if ($put > 0 || $source !== 'report') {
            return $put;
        }
        $ex = array_values(array_unique(array_filter($numbers, static fn (?string $t): bool => $t !== null)));
        sort($ex, SORT_STRING);
        $ps = $db->all('SELECT parcel_no, tracking_no, tracking_source, cw_parcel_ref FROM p2g_parcel WHERE account_id = ? AND order_line_id = ? ORDER BY parcel_no FOR UPDATE',
            [$accountId, $id]);
        $have = array_map(static fn (array $p): string => (string) $p['tracking_no'], array_filter($ps, static fn (array $p): bool => $p['tracking_no'] !== null));
        $new = array_values(array_diff($ex, $have));
        $old = array_values(array_filter($ps, static fn (array $p): bool => $p['tracking_no'] !== null && !in_array((string) $p['tracking_no'], $ex, true)));
        if ($new === [] || count($new) !== count($old)) {
            return 0;
        }
        foreach ($old as $p) {
            if ($p['tracking_source'] !== 'tracking_api' || $p['cw_parcel_ref'] !== null) {
                return 0; // a pinned parcel's number, or one the export itself gave, never moves
            }
        }
        foreach ($old as $i => $p) {
            $db->exec("UPDATE p2g_parcel SET tracking_no = ?, tracking_source = 'report' WHERE account_id = ? AND order_line_id = ? AND parcel_no = ? AND cw_parcel_ref IS NULL "
                . "AND tracking_source = 'tracking_api'", [$new[$i], $accountId, $id, (int) $p['parcel_no']]);
        }
        return count($old);
    }

    /** @param list<?string> $numbers @return int */
    private static function fillEmpty(Db $db, int $accountId, int $id, array $numbers, string $source): int
    {
        $missing = array_map('intval', $db->column('SELECT parcel_no FROM p2g_parcel WHERE account_id = ? AND order_line_id = ? AND tracking_no IS NULL ORDER BY parcel_no FOR UPDATE',
            [$accountId, $id]));
        $have = array_map('strval', $db->column('SELECT tracking_no FROM p2g_parcel WHERE account_id = ? AND order_line_id = ? AND tracking_no IS NOT NULL', [$accountId, $id]));
        $new = array_values(array_diff(array_values(array_unique(array_filter($numbers, static fn (?string $t): bool => $t !== null))), $have));
        sort($new, SORT_STRING);
        if ($missing === [] || count($new) !== count($missing)) {
            return 0;
        }
        foreach ($missing as $i => $no) {
            $db->exec('UPDATE p2g_parcel SET tracking_no = ?, tracking_source = ? WHERE account_id = ? AND order_line_id = ? AND parcel_no = ? AND tracking_no IS NULL',
                [$new[$i], $source, $accountId, $id, $no]);
        }
        return count($missing);
    }

    /**
     * Each due line's basket invoice read and stored (oldest paid first, PRICE_PER_RUN lines a run; one invoice prices every label of its
     * basket, so the others are no longer due). Asked for by the basket (OrderId, its TransactionId) when the API gave it, else by the label
     * (OrderLineId with its hash): WAITS FOR THE PROBE which of them Parcel2Go serves. A refusal of the invoice (its layout, another basket, a
     * top-up, a row that is not the label's own service: store()) or of the call (4xx) marks the line refused with its reason; Parcel2Go out
     * of reach or busy, a 404/408/409/425 (API_LATER) or a fault in CW with this one label leaves it due for a later try (RETRY_MIN), the run
     * goes on with the next.
     *
     * @param array<string, int> $sum
     */
    private function prices(Caller $caller, array $acct, P2gApi $api, P2gPdfText $pdf, ?float $deadline, array &$sum): void
    {
        $now = $this->now();
        $due = $this->db->all("SELECT order_line_id FROM p2g_line WHERE account_id = ? AND price_state = 'due' AND (next_try_at IS NULL OR next_try_at <= ?) "
            . 'ORDER BY paid_at, order_line_id LIMIT ' . self::PRICE_PER_RUN, [$acct['id'], $now]);
        foreach ($due as $d) {
            if ($deadline !== null && microtime(true) >= $deadline - 20) {
                return;
            }
            $id = (int) $d['order_line_id'];
            $line = self::line($this->db, $acct['id'], $id);
            if ($line === null || $line['price_state'] !== 'due') {
                continue; // priced meanwhile by its basket's invoice
            }
            $tx = $line['transaction_id'] === null ? null : (int) $line['transaction_id'];
            $code = null;
            $transient = false;
            try {
                $bytes = null;
                if ($tx !== null) {
                    try {
                        $bytes = $api->invoice($tx, 'OrderId', null);
                    } catch (CwException $e) {
                        if ($e->errorCode !== 'p2g_refused') {
                            throw $e;
                        }
                    }
                }
                $bytes ??= $api->invoice($id, 'OrderLineId', $line['doc_hash'] === null ? null : (string) $line['doc_hash']);
                $inv = P2gInvoice::read($pdf->text($bytes), $id, $tx);
                $r = $this->store($caller, $acct, $inv);
                $sum['priced'] += $r === 'same' ? 0 : 1;
                $sum['placed'] += $r === 'same' ? 0 : 1;
                continue;
            } catch (CwException $e) {
                $status = (int) ($e->detail['status'] ?? 0);
                [$code, $transient] = match ($e->errorCode) {
                    'p2g_invoice_refused' => [(string) ($e->detail['refuse'] ?? 'unknown_line'), false],
                    'p2g_pdf_text' => ['pdf_text', true],
                    'p2g_refused' => ['api_' . $status, in_array($status, self::API_LATER, true)],
                    'p2g_no_invoice' => ['no_invoice', true],
                    'p2g_bill_conflict' => [(string) ($e->detail['refuse'] ?? 'bill_conflict'), false],
                    'p2g_deadline' => ['deadline', true],
                    'p2g_unreachable', 'p2g_shape', 'p2g_token', 'busy' => [$e->errorCode, true],
                    default => ['error', true],
                };
                if ($e->errorCode === 'p2g_deadline') {
                    return;
                }
            } catch (\Throwable) {
                [$code, $transient] = ['error', true]; // a fault in CW with this one label (its own later try): the run goes on
            }
            $tries = (int) $line['price_tries'] + 1;
            $refused = !$transient || $tries > count(self::RETRY_MIN);
            $wait = self::RETRY_MIN[min($tries, count(self::RETRY_MIN)) - 1];
            $this->db->exec("UPDATE p2g_line SET price_state = ?, refuse_code = ?, price_tries = ?, next_try_at = ?, updated_at = ? WHERE account_id = ? AND order_line_id = ? "
                . "AND price_state = 'due'", [$refused ? 'refused' : 'due', $code, $tries, Clock::db(Clock::fromDb($now)->modify("+{$wait} minutes")), $now, $acct['id'], $id]);
            $sum['refused'] += $refused ? 1 : 0;
        }
    }

    /**
     * Labels Parcel2Go's API refused (api_<status>, paid in the last REPLAN_DAYS) due again once API_AGAIN_HOURS have passed since (F4): a
     * refusal of the API may be Parcel2Go's passing state; a refusal of the invoice itself (its layout, its rows) is not tried again by itself.
     */
    private function dueAgain(array $acct): void
    {
        $now = $this->now();
        $this->db->exec("UPDATE p2g_line SET price_state = 'due', price_tries = 0, next_try_at = NULL, updated_at = ? WHERE account_id = ? AND price_state = 'refused' "
            . "AND refuse_code LIKE 'api\\_%' AND updated_at <= ? AND paid_day >= ?", [$now, $acct['id'],
                Clock::db(Clock::fromDb($now)->modify('-' . self::API_AGAIN_HOURS . ' hours')), self::addDays(ReportRefresh::ukDay($now), -self::REPLAN_DAYS)]);
    }

    /**
     * Every live bill of the account of the last REPLAN_DAYS with money not on an order placed again (PGL4: a CW parcel or a tracking number may
     * have come); a bill whose places are what they were is not written. Within the run's deadline, resumable (H8 / F11): a run stopped by its
     * deadline keeps the last bill it placed (p2g_run.replan_after) and the next run goes on after it; a bill whose money is only before CW
     * start with every tracking number known is left (nothing can move it).
     *
     * @param array<string, int> $sum
     */
    private function replan(Caller $caller, array $acct, int $run, ?float $deadline, array &$sum): void
    {
        $since = self::addDays(ReportRefresh::ukDay($this->now()), -self::REPLAN_DAYS);
        $after = (int) ($this->db->value("SELECT replan_after FROM p2g_run WHERE account_id = ? AND kind = 'pull' AND id < ? AND state <> 'running' ORDER BY id DESC LIMIT 1",
            [$acct['id'], $run]) ?? 0);
        $bills = array_map('intval', $this->db->column("SELECT DISTINCT i.id FROM carrier_invoice i JOIN carrier_allocation a ON a.invoice_id = i.id AND a.ended_at IS NULL "
            . "AND a.dest <> 'order' WHERE i.carrier = ? AND i.account = ? AND i.status = 'live' AND i.period_to >= ? AND i.id > ? AND (a.dest <> 'before_start' "
            . 'OR EXISTS (SELECT 1 FROM p2g_charge c JOIN p2g_parcel p ON p.account_id = c.account_id AND p.order_line_id = c.order_line_id AND p.tracking_no IS NULL '
            . 'WHERE c.invoice_id = i.id)) ORDER BY i.id', [self::CARRIER, $acct['account_no'], $since, $after]));
        $last = null;
        foreach ($bills as $bill) {
            if ($deadline !== null && microtime(true) >= $deadline - 20) {
                break;
            }
            if ($this->place($caller, $acct, $bill)) {
                $sum['placed']++;
            }
            $last = $bill;
        }
        $done = $last === null ? $bills === [] : $last === end($bills);
        $this->db->exec('UPDATE p2g_run SET replan_after = ? WHERE id = ?', [$done ? null : ($last ?? ($after === 0 ? null : $after)), $run]);
    }

    // ---- the money -----------------------------------------------------------------------------------------------------------------

    /**
     * A basket's invoice stored as ONE Parcel2Go bill (carrier_invoice TX-<n> + a row per label + p2g_charge) with its money placed, in one
     * guarded write. A label it names that CW has not seen is added (source invoice; its tracking number asked for later). Its price rows'
     * labels become priced by it. The same invoice again (by what CW keeps of it): "same". Another reading of the same number: the old bill
     * superseded, the new one live, the days moved by the difference. Refused whole (p2g_bill_conflict, its detail 'refuse' the code; nothing
     * written; the final review, H3 / M10 / H7): a label of it that is another Parcel2Go account's (other_account), ANY row naming a PrePay
     * top-up (topup), a price row of a label priced by another live bill (priced_elsewhere), of a label whose known basket is another
     * (other_basket) or whose known service (Parcel2Go's list or export) is not the row's (service_differs), a price row printing fewer parcels
     * than CW holds for its label (parcels_differ). A price row printing MORE parcels gives its label the missing parcels (their tracking
     * numbers asked for later) and the other live bills charging that label are placed again. A label CW saw unpaid is paid by the invoice in
     * the same write as its state (paid_at and price_state together).
     *
     * @param array<string, mixed> $inv P2gInvoice::read()
     * @return string stored | same | replaced
     */
    public function store(Caller $caller, array $acct, array $inv): string
    {
        $this->bills->authoriseWrite($caller);
        $result = 'stored';
        $grown = [];
        $this->bills->inLock(function () use ($caller, $acct, $inv, &$result, &$grown): void {
            $this->bills->guardedWrite(function (Db $db) use ($caller, $acct, $inv, &$result, &$grown): array {
                $grown = [];
                $conflict = static fn (string $code, string $why): CwException => new CwException('p2g_bill_conflict', "Parcel2Go invoice {$inv['invoice_no']} was not stored: {$why}",
                    409, ['refuse' => $code]);
                $ids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['order_line_id'], $inv['rows'])));
                $lines = [];
                foreach ($db->all('SELECT * FROM p2g_line WHERE order_line_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids) as $l) {
                    if ((int) $l['account_id'] !== $acct['id']) {
                        throw $conflict('other_account', 'one of its labels is another Parcel2Go account\'s');
                    }
                    $lines[(int) $l['order_line_id']] = $l;
                }
                $old = $db->one('SELECT * FROM carrier_invoice WHERE live_key = ?', [self::CARRIER . ':' . $acct['account_no'] . ':' . $inv['invoice_no']]);
                $priceIds = [];
                $slots = [];
                foreach ($inv['rows'] as $r) {
                    $id = (int) $r['order_line_id'];
                    $l = $lines[$id] ?? null;
                    if ($l !== null && $l['kind'] === 'topup') {
                        throw $conflict('topup', 'one of its rows names a PrePay top-up');
                    }
                    if ($r['kind'] !== 'price') {
                        continue;
                    }
                    if ($l !== null && $l['invoice_id'] !== null && ($old === null || (int) $l['invoice_id'] !== (int) $old['id'])) {
                        throw $conflict('priced_elsewhere', 'one of its labels is priced on another invoice');
                    }
                    if ($l !== null && $l['transaction_id'] !== null && (int) $l['transaction_id'] !== (int) $inv['transaction_id']) {
                        throw $conflict('other_basket', 'one of its price rows is a label paid in another basket');
                    }
                    if ($l !== null && $l['service_slug'] !== null && !self::stub($l) && (string) $l['service_slug'] !== (string) $r['service']) {
                        throw $conflict('service_differs', 'one of its price rows is not its label\'s own service');
                    }
                    if ($l !== null) {
                        $held = (int) $db->value('SELECT COUNT(*) FROM p2g_parcel WHERE account_id = ? AND order_line_id = ?', [$acct['id'], $id]);
                        if ((int) $r['parcels'] < $held) {
                            throw $conflict('parcels_differ', 'it prints fewer parcels for a label than CW holds for it');
                        }
                        if ((int) $r['parcels'] > max($held, (int) $l['parcels'])) {
                            $slots[$id] = (int) $r['parcels'];
                        }
                    }
                    $priceIds[] = $id;
                }
                if ($old !== null && (string) $old['file_sha256'] === (string) $inv['sha256']) {
                    $result = 'same';
                    $now = $this->now();
                    return ['before' => [], 'after' => [], 'write' => static function (Db $db) use ($acct, $old, $priceIds, $now): void {
                        self::markPriced($db, $acct['id'], $priceIds, (int) $old['id'], $now);
                    }];
                }
                $now = $this->now();
                $date = (string) $inv['invoice_date'];
                $dateAt = Clock::db(new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('Europe/London')));
                // a label of the invoice CW has not seen: added as the invoice names it (no tracking number yet); one known but not paid yet in
                // CW's eyes is paid by this invoice
                $stubs = [];
                foreach ($inv['rows'] as $r) {
                    $id = (int) $r['order_line_id'];
                    if (!isset($lines[$id]) && !isset($stubs[$id])) {
                        $stubs[$id] = ['account_id' => $acct['id'], 'order_line_id' => $id, 'transaction_id' => $r['kind'] === 'price' ? $inv['transaction_id'] : null,
                            'source' => 'invoice', 'kind' => 'parcel', 'service_slug' => $r['service'], 'parcels' => (int) $r['parcels'], 'paid_at' => $dateAt, 'paid_day' => $date,
                            'price_state' => $r['kind'] === 'price' ? 'due' : 'not_needed', 'invoice_id' => null];
                    }
                }
                $all = $lines + $stubs;
                foreach ($all as $id => $l) {
                    if ($l['paid_at'] === null) {
                        $all[$id]['paid_at'] = $dateAt;
                        $all[$id]['paid_day'] = $date;
                    }
                    if (isset($slots[$id])) {
                        $all[$id]['parcels'] = $slots[$id];
                    }
                }
                $rows = [];
                foreach ($inv['rows'] as $k => $r) {
                    $l = $all[(int) $r['order_line_id']];
                    $rows[] = ['invoice_id' => 0, 'row_no' => $k + 1, 'order_line_id' => (int) $r['order_line_id'], 'net' => (int) $r['net'], 'kind' => (string) $r['kind'],
                        'day' => $r['kind'] === 'price' ? (string) $l['paid_day'] : $date];
                }
                $was = $old === null ? [] : self::liveAllocations($db, [(int) $old['id']]);
                $plan = $this->planBill($db, $acct, $rows, $all);
                $result = $old === null ? 'stored' : 'replaced';
                $grown = array_keys($slots);
                // the verify (after the write) resolves each parcel again WITHOUT the pins this write made (H5 / F9): what it trusts is what was
                // there before, read again under the guards
                $mine = self::pinKeys($plan['pins']);
                return ['before' => self::facts($was), 'after' => self::facts($plan['alloc']), 'versions' => $plan['versions'],
                    'verify' => fn (Db $db): bool => $this->planBill($db, $acct, $rows, $all, $mine)['dests'] === $plan['dests'],
                    'write' => function (Db $db) use ($caller, $acct, $inv, $old, $stubs, $all, $priceIds, $rows, $plan, $was, $now, $slots): void {
                        foreach ($stubs as $s) {
                            $db->exec('INSERT INTO p2g_line (account_id, order_line_id, transaction_id, source, kind, service_slug, parcels, paid_at, paid_day, price_state, first_at, '
                                . 'updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$s['account_id'], $s['order_line_id'], $s['transaction_id'], $s['source'], $s['kind'],
                                    $s['service_slug'], $s['parcels'], $s['paid_at'], $s['paid_day'], $s['price_state'], $now, $now]);
                            $this->parcelRows($db, $acct['id'], (int) $s['order_line_id'], (int) $s['parcels']);
                        }
                        foreach ($all as $id => $l) {
                            // a label CW saw unpaid: paid by this invoice (its day the invoice's), its state with it in the same write (H6 / F3: never
                            // paid and unpaid at once); a price row printing more parcels than CW held: the label's missing parcels
                            $db->exec("UPDATE p2g_line SET price_state = IF(price_state = 'unpaid', ?, price_state), paid_at = COALESCE(paid_at, ?), "
                                . 'paid_day = COALESCE(paid_day, ?), transaction_id = COALESCE(transaction_id, ?), parcels = GREATEST(parcels, ?), updated_at = ? '
                                . 'WHERE account_id = ? AND order_line_id = ?', [self::stateFor($acct, (string) $l['kind'], (string) $l['paid_day']), $l['paid_at'], $l['paid_day'],
                                    in_array($id, $priceIds, true) ? $inv['transaction_id'] : null, (int) $l['parcels'], $now, $acct['id'], $id]);
                            if (isset($slots[$id])) {
                                $this->parcelRows($db, $acct['id'], $id, $slots[$id]);
                            }
                        }
                        if ($old !== null) {
                            self::endAllocations($db, $was, $now);
                            $db->exec("UPDATE carrier_invoice SET status = 'superseded', ended_actor = ?, ended_at = ? WHERE id = ? AND status = 'live'",
                                [$caller->actor, $now, (int) $old['id']]) === 1 || throw new CwException('busy', 'this invoice\'s bill changed while it was saved: tried again', 503);
                            // a label the old reading priced and this one does not: due again (it is priced by the invoice that names it)
                            $keep = $priceIds === [] ? [0] : $priceIds;
                            $db->exec("UPDATE p2g_line SET price_state = 'due', invoice_id = NULL, updated_at = ? WHERE account_id = ? AND invoice_id = ? AND order_line_id NOT IN ("
                                . implode(', ', array_fill(0, count($keep), '?')) . ')', [$now, $acct['id'], (int) $old['id'], ...$keep]);
                        }
                        $bill = $db->insert('INSERT INTO carrier_invoice (carrier, account, invoice_no, invoice_date, period_from, period_to, channel_id, net_pence, vat_pence, '
                            . "gross_pence, line_count, file_sha256, status, supersedes_id, uploaded_by, uploaded_actor, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'live', "
                            . '?, NULL, ?, ?)', [self::CARRIER, $acct['account_no'], $inv['invoice_no'], $inv['invoice_date'], min(array_column($rows, 'day')),
                                max(array_column($rows, 'day')), $acct['main'], $inv['net'], $inv['vat'], $inv['gross'], count($inv['rows']), $inv['sha256'],
                                $old === null ? null : (int) $old['id'], mb_substr($caller->actor, 0, 64), $now]);
                        foreach ($inv['rows'] as $k => $r) {
                            $db->exec("INSERT INTO carrier_bill_row (invoice_id, row_no, service_date, product_code, qty, net_pence, gross_pence, vat_code, ref_kind) VALUES "
                                . "(?, ?, ?, ?, ?, ?, ?, ?, 'none')", [$bill, $k + 1, $rows[$k]['day'], $r['code'], (int) $r['parcels'], $r['net'], $r['gross'],
                                    (int) $r['rate_bp'] > 0 ? 'S' : 'Z']);
                            $db->exec('INSERT INTO p2g_charge (invoice_id, row_no, account_id, order_line_id, kind) VALUES (?, ?, ?, ?, ?)',
                                [$bill, $k + 1, $acct['id'], (int) $r['order_line_id'], $r['kind']]);
                        }
                        self::insertAllocations($db, array_map(static fn (array $a): array => ['invoice_id' => $bill] + $a, $plan['alloc']), $now);
                        self::pin($db, $acct, $plan['pins'], $now);
                        self::markPriced($db, $acct['id'], $priceIds, $bill, $now);
                        foreach ($inv['rows'] as $r) {
                            if ($r['service'] !== null) {
                                $db->exec('UPDATE p2g_line SET service_slug = COALESCE(service_slug, ?) WHERE account_id = ? AND order_line_id = ?',
                                    [$r['service'], $acct['id'], (int) $r['order_line_id']]);
                            }
                        }
                        Audit::write($db, $caller, 'p2g.invoice', 'carrier_invoice', (string) $bill, null, ['account' => $acct['key'], 'invoice' => $inv['invoice_no'],
                            'net' => $inv['net'], 'rows' => count($inv['rows']), 'replaced' => $old === null ? null : (int) $old['id']]);
                    }];
            });
        });
        if ($grown !== [] && $result !== 'same') {
            // a label given more parcels: the other live bills charging it (an Extra Payment of it) shared over its parcels again
            foreach (array_map('intval', $this->db->column("SELECT DISTINCT c.invoice_id FROM p2g_charge c JOIN carrier_invoice i ON i.id = c.invoice_id AND i.status = 'live' "
                . 'WHERE c.account_id = ? AND c.order_line_id IN (' . implode(', ', array_fill(0, count($grown), '?')) . ') ORDER BY c.invoice_id', [$acct['id'], ...$grown])) as $bill) {
                $this->place($caller, $acct, $bill);
            }
        }
        return $result;
    }

    /**
     * A basket's invoice read again by its transaction and stored (M11: a corrected invoice; `--refresh-invoice`): the bill there stays live until
     * the new reading is read and stored (store(): "replaced", the old superseded); an invoice that does not read leaves it as it is (refused).
     */
    public function refreshInvoice(Caller $caller, string $key, P2gApi $api, P2gPdfText $pdf, int $transactionId): string
    {
        $this->bills->authoriseWrite($caller);
        return $this->locked(function () use ($caller, $key, $api, $pdf, $transactionId): string {
            $acct = self::account($this->db, $key);
            if (!$acct['on']) {
                throw new CwException('p2g_off', 'this Parcel2Go account is switched off: nothing was read', 409);
            }
            if ($api->account() !== $key) {
                throw new CwException('p2g_key_refused', 'the Parcel2Go key is for another account: nothing was read', 403);
            }
            $this->starts = [];
            $inv = P2gInvoice::read($pdf->text($api->invoice($transactionId, 'OrderId', null)), null, $transactionId);
            $r = $this->store($caller, $acct, $inv);
            Audit::write($this->db, $caller, 'p2g.refresh_invoice', 'carrier_account', (string) $acct['id'], null, ['account' => $key, 'invoice' => $inv['invoice_no'], 'result' => $r]);
            return $r;
        });
    }

    /** @param list<array{0: int, 1: int, 2: int, 3: string, 4: string}> $pins @return array<string, true> "P2G number:parcel" of each */
    private static function pinKeys(array $pins): array
    {
        $out = [];
        foreach ($pins as [$id, $no]) {
            $out[$id . ':' . $no] = true;
        }
        return $out;
    }

    /** The labels a bill's price rows price: priced by it (a top-up never: refused before). @param list<int> $ids */
    private static function markPriced(Db $db, int $accountId, array $ids, int $bill, string $now): void
    {
        foreach ($ids as $id) {
            $db->exec("UPDATE p2g_line SET price_state = 'priced', invoice_id = ?, refuse_code = NULL, next_try_at = NULL, updated_at = ? WHERE account_id = ? AND order_line_id = ? "
                . "AND kind <> 'topup'", [$bill, $now, $accountId, $id]);
        }
    }

    /** A live bill's money placed again (PGL4): written only when its places changed (allocations ended, the new ones added, the days moved). */
    public function place(Caller $caller, array $acct, int $bill): bool
    {
        $this->bills->authoriseWrite($caller);
        $changed = false;
        $this->bills->inLock(function () use ($acct, $bill, &$changed): void {
            $this->bills->guardedWrite(function (Db $db) use ($acct, $bill, &$changed): array {
                $live = $db->value("SELECT 1 FROM carrier_invoice WHERE id = ? AND carrier = ? AND account = ? AND status = 'live'", [$bill, self::CARRIER, $acct['account_no']]);
                $rows = $live === null ? [] : array_map(static fn (array $r): array => ['invoice_id' => $bill, 'row_no' => (int) $r['row_no'],
                    'order_line_id' => (int) $r['order_line_id'], 'net' => (int) $r['net_pence'], 'day' => (string) $r['service_date'], 'kind' => (string) $r['kind']],
                    $db->all('SELECT r.row_no, r.net_pence, r.service_date, c.kind, c.order_line_id FROM carrier_bill_row r JOIN p2g_charge c ON c.invoice_id = r.invoice_id '
                        . 'AND c.row_no = r.row_no WHERE r.invoice_id = ? ORDER BY r.row_no', [$bill]));
                if ($rows === []) {
                    $changed = false;
                    return ['before' => [], 'after' => [], 'write' => static fn (Db $db): null => null];
                }
                $all = [];
                foreach (array_unique(array_column($rows, 'order_line_id')) as $id) {
                    $all[(int) $id] = self::line($db, $acct['id'], (int) $id);
                }
                $was = self::liveAllocations($db, [$bill]);
                $plan = $this->planBill($db, $acct, $rows, $all);
                $want = array_map(static fn (array $a): array => ['invoice_id' => $bill] + $a, $plan['alloc']);
                $changed = self::identities($was) !== self::identities($want);
                if (!$changed) {
                    return ['before' => [], 'after' => [], 'write' => static fn (Db $db): null => null];
                }
                $now = $this->now();
                $mine = self::pinKeys($plan['pins']);
                return ['before' => self::facts($was), 'after' => self::facts($want), 'versions' => $plan['versions'],
                    'verify' => fn (Db $db): bool => $this->planBill($db, $acct, $rows, $all, $mine)['dests'] === $plan['dests'],
                    'write' => static function (Db $db) use ($acct, $was, $want, $plan, $now): void {
                        self::endAllocations($db, $was, $now);
                        self::insertAllocations($db, $want, $now);
                        self::pin($db, $acct, $plan['pins'], $now);
                    }];
            });
        });
        return $changed;
    }

    /**
     * Where each row's money goes (see the class): every row on ITS label's parcels (an Extra Payment on the earlier label it names), shared
     * over them; the allocations (without invoice_id), each label's parcels' destinations, the pins to write and the snapshots of the orders it
     * puts money on (DeliveryBills::orderVersions).
     *
     * @param list<array{invoice_id: int, row_no: int, order_line_id: int, net: int, day: string, kind: string}> $rows
     * @param array<int, array<string, mixed>> $lines by P2G number (a label not stored yet: as it will be; its 'parcels' what it will hold)
     * @param array<string, true> $ignorePins "P2G number:parcel" whose pin is not trusted (the verify: the pins its own write made)
     * @return array{alloc: list<array<string, mixed>>, dests: array<string, array<string, mixed>>, pins: list<array{0: int, 1: int, 2: int, 3: string, 4: string}>,
     *   versions: array<string, string>}
     */
    private function planBill(Db $db, array $acct, array $rows, array $lines, array $ignorePins = []): array
    {
        $dests = [];
        $pins = [];
        $owners = [];
        $parcels = [];
        foreach (array_unique(array_column($rows, 'order_line_id')) as $id) {
            $id = (int) $id;
            $line = $lines[$id];
            $ps = $db->all('SELECT parcel_no, tracking_no, cw_channel_id, cw_order_ref, cw_parcel_ref FROM p2g_parcel WHERE account_id = ? AND order_line_id = ? ORDER BY parcel_no',
                [$acct['id'], $id]);
            // a label the invoice adds, or a parcel its price row prints that CW did not hold: no tracking number yet
            for ($i = count($ps) + 1; $i <= max(1, (int) $line['parcels']); $i++) {
                $ps[] = ['parcel_no' => $i, 'tracking_no' => null, 'cw_channel_id' => null, 'cw_order_ref' => null, 'cw_parcel_ref' => null];
            }
            foreach ($ps as $k => $p) {
                if (isset($ignorePins[$id . ':' . $p['parcel_no']])) {
                    $ps[$k] = ['cw_channel_id' => null, 'cw_order_ref' => null, 'cw_parcel_ref' => null] + $p;
                }
            }
            $parcels[$id] = $ps;
            foreach ($ps as $p) {
                $d = $this->dest($db, $acct, $line, $p);
                $dests[$id . ':' . $p['parcel_no']] = $d;
                if ($d['dest'] === 'order') {
                    $owners[$d['channel_id'] . '|' . strtolower((string) $d['order_ref'])] = [(int) $d['channel_id'], (string) $d['order_ref']];
                    if ($p['cw_parcel_ref'] === null) {
                        $pins[] = [$id, (int) $p['parcel_no'], (int) $d['channel_id'], (string) $d['order_ref'], (string) $d['parcel_ref']];
                    }
                }
            }
        }
        $alloc = [];
        foreach ($rows as $r) {
            $ps = $parcels[(int) $r['order_line_id']];
            foreach (PooledPlanner::shares((int) $r['net'], count($ps)) as $i => $pence) {
                if ($pence === 0) {
                    continue;
                }
                $d = $dests[$r['order_line_id'] . ':' . $ps[$i]['parcel_no']];
                // money on an order is never below 0 (checklist 1; the reader refuses rows below 0 today: a credit is not read yet)
                if ($d['dest'] === 'order' && $pence < 0) {
                    $d = self::shop($acct, 'refund_cap');
                }
                $alloc[] = ['row_no' => (int) $r['row_no'], 'kind' => 'row', 'how' => $d['how'], 'label_id' => null, 'dest' => $d['dest'], 'channel_id' => $d['channel_id'],
                    'order_ref' => $d['order_ref'], 'parcel_ref' => $d['parcel_ref'], 'party_id' => null, 'held_reason' => $d['held_reason'], 'day' => (string) $r['day'],
                    'pence' => $pence];
            }
        }
        ksort($owners, SORT_STRING);
        ksort($dests, SORT_STRING);
        return ['alloc' => $alloc, 'dests' => $dests, 'pins' => $pins, 'versions' => $owners === [] ? [] : DeliveryBills::orderVersions($db, $owners)];
    }

    /**
     * One parcel's destination (see the class). Every main-shop outcome (no tracking number after the wait, two CW parcels, another shop, a
     * cost already there, no CW parcel) of a label paid on or before the main shop's first CW day is before CW start (M9 / F7: never in the
     * shop's profit before it was in CW); it stays eligible for an order when a CW parcel comes (replan()).
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed> $p its p2g_parcel row
     * @return array{dest: string, how: string, channel_id: ?int, order_ref: ?string, parcel_ref: ?string, held_reason: ?string}
     */
    private function dest(Db $db, array $acct, array $line, array $p): array
    {
        $d = $this->destOf($db, $acct, $line, $p);
        if ($d['dest'] === 'shop') {
            $start = $this->startDay($db, $acct['main']);
            if ($start === null || (string) $line['paid_day'] <= $start) {
                return ['dest' => 'before_start', 'how' => 'before_start', 'channel_id' => $acct['main'], 'order_ref' => null, 'parcel_ref' => null, 'held_reason' => null];
            }
        }
        return $d;
    }

    /** A shop's first CW day (DeliveryBills::startDay), read once per run. */
    private function startDay(Db $db, int $channel): ?string
    {
        if (!array_key_exists($channel, $this->starts)) {
            $this->starts[$channel] = DeliveryBills::startDay($db, $channel);
        }
        return $this->starts[$channel];
    }

    /** @return array{dest: string, how: string, channel_id: ?int, order_ref: ?string, parcel_ref: ?string, held_reason: ?string} */
    private function destOf(Db $db, array $acct, array $line, array $p): array
    {
        if ($p['cw_parcel_ref'] !== null) {
            return ['dest' => 'order', 'how' => 'label', 'channel_id' => (int) $p['cw_channel_id'], 'order_ref' => (string) $p['cw_order_ref'],
                'parcel_ref' => (string) $p['cw_parcel_ref'], 'held_reason' => null];
        }
        $day = (string) $line['paid_day'];
        if ($p['tracking_no'] === null) {
            $waitDays = max(0, $this->int(self::WAIT_SETTING, 3));
            $waited = (($this->clock)()->getTimestamp() - Clock::fromDb((string) $line['paid_at'])->getTimestamp()) >= $waitDays * 86400;
            return $waited ? self::shop($acct, 'no_tracking') : ['dest' => 'held', 'how' => 'no_tracking', 'channel_id' => null, 'order_ref' => null, 'parcel_ref' => null,
                'held_reason' => 'no_tracking'];
        }
        $tracking = (string) $p['tracking_no'];
        $fits = [];
        foreach (LabelDestinations::parcels($db, [$tracking])[$tracking] ?? [] as $c) {
            $gap = DeliveryBills::daysBetween($day, (string) $c['label_day']);
            if ($gap >= -self::WINDOW_BEFORE && $gap <= self::WINDOW_AFTER) {
                $fits[strtolower($c['channel_id'] . '|' . $c['order_ref'] . '|' . $c['parcel_ref'])] = $c;
            }
        }
        if (count($fits) > 1) {
            return self::shop($acct, 'two_parcels');
        }
        if (count($fits) === 1) {
            $c = array_values($fits)[0];
            $ch = (int) $c['channel_id'];
            if (!self::serves($db, $acct['id'], $ch, $day)) {
                return self::shop($acct, 'other_shop');
            }
            // the same number on another Parcel2Go label (of any account): never two prices on one parcel
            if ($db->value('SELECT 1 FROM p2g_parcel WHERE tracking_no = ? AND NOT (account_id = ? AND order_line_id = ?) LIMIT 1',
                [$tracking, $acct['id'], (int) $line['order_line_id']]) !== null) {
                return self::shop($acct, 'tracking_twice');
            }
            // the parcel already holds another carrier account's cost (PGL5): a live DPD line, or a live allocation of a bill that is no Parcel2Go
            // bill of this account (a Royal Mail label's price, the other Parcel2Go account's)
            if ($db->value("SELECT 1 FROM carrier_invoice_line l JOIN carrier_invoice i ON i.id = l.invoice_id AND i.status = 'live' WHERE l.match_state = 'matched' "
                . 'AND l.channel_id = ? AND l.order_ref = ? AND l.parcel_ref = ? LIMIT 1', [$ch, $c['order_ref'], $c['parcel_ref']]) !== null
                || $db->value("SELECT 1 FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' WHERE a.ended_at IS NULL "
                . "AND a.dest = 'order' AND a.channel_id = ? AND a.order_ref = ? AND a.parcel_ref = ? AND NOT (i.carrier = ? AND i.account = ?) LIMIT 1",
                [$ch, $c['order_ref'], $c['parcel_ref'], self::CARRIER, $acct['account_no']]) !== null) {
                return self::shop($acct, 'costed_twice');
            }
            return ['dest' => 'order', 'how' => 'label', 'channel_id' => $ch, 'order_ref' => (string) $c['order_ref'], 'parcel_ref' => (string) $c['parcel_ref'],
                'held_reason' => null];
        }
        return self::shop($acct, 'no_parcel'); // before CW start when paid on or before the shop's start (dest())
    }

    /** @return array{dest: string, how: string, channel_id: int, order_ref: null, parcel_ref: null, held_reason: null} the main shop's "not on an order" */
    private static function shop(array $acct, string $how): array
    {
        return ['dest' => 'shop', 'how' => $how, 'channel_id' => $acct['main'], 'order_ref' => null, 'parcel_ref' => null, 'held_reason' => null];
    }

    /** Whether the account serves the shop on the day (carrier_account_party with a FROM day on or before it). */
    private static function serves(Db $db, int $accountId, int $channel, string $day): bool
    {
        return $db->value('SELECT 1 FROM carrier_account_party ap JOIN delivery_party p ON p.id = ap.party_id WHERE ap.account_id = ? AND p.channel_id = ? '
            . 'AND ap.from_day <= ? AND (p.channel_from IS NULL OR p.channel_from <= ?) LIMIT 1', [$accountId, $channel, $day, $day]) !== null;
    }

    /** The pins written (only from NULL: a parcel's pin never moves; PGL4). @param list<array{0: int, 1: int, 2: int, 3: string, 4: string}> $pins */
    private static function pin(Db $db, array $acct, array $pins, string $now): void
    {
        foreach ($pins as [$id, $no, $ch, $ref, $pref]) {
            $db->exec('UPDATE p2g_parcel SET cw_channel_id = ?, cw_order_ref = ?, cw_parcel_ref = ?, pinned_at = ? WHERE account_id = ? AND order_line_id = ? AND parcel_no = ? '
                . 'AND cw_parcel_ref IS NULL AND tracking_no IS NOT NULL', [$ch, $ref, $pref, $now, $acct['id'], $id, $no]) === 1
                || throw new CwException('busy', 'this label\'s parcel was pinned meanwhile: tried again', 503);
        }
    }

    /** @return array<string, mixed>|null */
    private static function line(Db $db, int $accountId, int $id): ?array
    {
        return $db->one('SELECT * FROM p2g_line WHERE account_id = ? AND order_line_id = ?', [$accountId, $id]);
    }

    /** @param list<int> $bills @return list<array<string, mixed>> */
    private static function liveAllocations(Db $db, array $bills): array
    {
        if ($bills === []) {
            return [];
        }
        return $db->all('SELECT * FROM carrier_allocation WHERE ended_at IS NULL AND invoice_id IN (' . implode(', ', array_fill(0, count($bills), '?')) . ') ORDER BY id', $bills);
    }

    /** @param list<array<string, mixed>> $a @return list<array<string, mixed>> */
    private static function facts(array $a): array
    {
        return array_values(array_filter(array_map([RoyalMailBills::class, 'fact'], $a)));
    }

    /** @param list<array<string, mixed>> $a @return list<string> what each allocation is, sorted (for "nothing changed") */
    private static function identities(array $a): array
    {
        $out = array_map(static fn (array $x): string => implode('|', [(int) $x['invoice_id'], (int) $x['row_no'], $x['how'], $x['dest'], $x['channel_id'] ?? '',
            strtolower((string) ($x['order_ref'] ?? '')), strtolower((string) ($x['parcel_ref'] ?? '')), $x['held_reason'] ?? '', (string) $x['day'], (int) $x['pence']]), $a);
        sort($out, SORT_STRING);
        return $out;
    }

    /** @param list<array<string, mixed>> $was */
    private static function endAllocations(Db $db, array $was, string $now): void
    {
        foreach (array_chunk(array_map(static fn (array $a): int => (int) $a['id'], $was), 500) as $chunk) {
            $db->exec('UPDATE carrier_allocation SET ended_at = ? WHERE ended_at IS NULL AND id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$now, ...$chunk]) === count($chunk) || throw new CwException('busy', 'this label\'s money moved while it was saved: tried again', 503);
        }
    }

    /** @param list<array<string, mixed>> $alloc */
    private static function insertAllocations(Db $db, array $alloc, string $now): void
    {
        $cols = ['invoice_id', 'row_no', 'kind', 'how', 'label_id', 'dest', 'channel_id', 'order_ref', 'parcel_ref', 'party_id', 'held_reason', 'day', 'pence', 'made_at'];
        foreach (array_chunk($alloc, 200) as $chunk) {
            $params = [];
            foreach ($chunk as $a) {
                array_push($params, (int) $a['invoice_id'], $a['row_no'], $a['kind'], $a['how'], $a['label_id'], $a['dest'], $a['channel_id'], $a['order_ref'], $a['parcel_ref'],
                    $a['party_id'], $a['held_reason'], $a['day'], $a['pence'], $now);
            }
            $db->exec('INSERT INTO carrier_allocation (' . implode(', ', $cols) . ') VALUES '
                . implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($cols), '?')) . ')')), $params);
        }
    }

    // ---- the export ----------------------------------------------------------------------------------------------------------------

    /**
     * The "My Orders" export of the account (P2gCsv) read: refused whole when it is not proved the account's (PGL8: at least IMPORT_KNOWN of its
     * lines, or all when it has fewer, already known for THIS account from its API list, and none known for another account); else its new
     * lines stored (source report, due when Created on or after the first day) and the tracking numbers of known lines' parcels filled (only
     * where empty, only when the export gives as many numbers as the line has parcels without one). The file is never kept.
     *
     * @return array{rows: int, lines: int, lines_new: int, tracking_filled: int}
     */
    public function import(Caller $caller, string $key, string $path): array
    {
        $this->bills->authoriseWrite($caller);
        $csv = P2gCsv::read($path);
        return $this->locked(function () use ($caller, $key, $csv): array {
            $acct = self::account($this->db, $key);
            if (!$acct['on']) {
                throw new CwException('p2g_off', 'this Parcel2Go account is switched off: its export is read once it is switched on', 409);
            }
            $ids = array_keys($csv['lines']);
            $mine = 0;
            $other = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ($this->db->all('SELECT account_id, COUNT(*) AS n FROM p2g_line WHERE order_line_id IN (' . implode(', ', array_fill(0, count($chunk), '?'))
                    . ') GROUP BY account_id', $chunk) as $r) {
                    if ((int) $r['account_id'] === $acct['id']) {
                        $mine += (int) $r['n'];
                    } else {
                        $other += (int) $r['n'];
                    }
                }
            }
            if ($other > 0) {
                throw new CwException('p2g_csv_account', "this export has {$other} labels of another Parcel2Go account: it is not {$acct['name']}'s. Nothing was read", 422);
            }
            if ($mine < min(self::IMPORT_KNOWN, count($ids))) {
                throw new CwException('p2g_csv_account', sprintf('only %d of this export\'s labels are known as %s\'s (at least %d must be: export it on the day, after the job ran). '
                    . 'Nothing was read', $mine, $acct['name'], min(self::IMPORT_KNOWN, count($ids))), 422);
            }
            $run = $this->startRun($acct, 'import', $caller);
            $now = $this->now();
            $out = $this->db->transaction(function (Db $db) use ($caller, $acct, $csv, $now): array {
                $new = 0;
                $filled = 0;
                foreach ($csv['lines'] as $id => $l) {
                    $day = ReportRefresh::ukDay($l['paid_at']);
                    $k = self::line($db, $acct['id'], $id);
                    if ($k === null) {
                        $db->exec('INSERT INTO p2g_line (account_id, order_line_id, source, kind, service_slug, country_iso3, parcels, paid_at, paid_day, price_state, first_at, '
                            . "updated_at) VALUES (?, ?, 'report', 'parcel', ?, ?, ?, ?, ?, ?, ?, ?)", [$acct['id'], $id, $l['service_slug'], $l['country'], count($l['tracking']),
                                $l['paid_at'], $day, self::stateFor($acct, 'parcel', $day), $now, $now]);
                        $this->parcelRows($db, $acct['id'], $id, count($l['tracking']));
                        $new++;
                    } else {
                        $db->exec('UPDATE p2g_line SET service_slug = COALESCE(service_slug, ?), country_iso3 = COALESCE(country_iso3, ?), updated_at = ? WHERE account_id = ? '
                            . 'AND order_line_id = ?', [$l['service_slug'], $l['country'], $now, $acct['id'], $id]);
                        if (self::stub($k) || ($k['price_state'] === 'unpaid' && $k['kind'] === 'parcel')) {
                            // first seen as an Extra Payment of another basket, or unpaid when the list last showed it (H6): its own day (the export's
                            // Created) and its own price due
                            $db->exec('UPDATE p2g_line SET paid_at = ?, paid_day = ?, price_state = ?, updated_at = ? WHERE account_id = ? AND order_line_id = ?',
                                [$l['paid_at'], $day, self::stateFor($acct, 'parcel', $day), $now, $acct['id'], $id]);
                        }
                    }
                    $filled += self::fillTracking($db, $acct['id'], $id, $l['tracking'], 'report');
                }
                $days = array_map(static fn (array $l): string => ReportRefresh::ukDay($l['paid_at']), $csv['lines']);
                $db->exec('INSERT INTO p2g_import (account_id, file_sha256, rows_read, line_count, lines_new, tracking_filled, first_day, last_day, actor, imported_at) VALUES '
                    . '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$acct['id'], $csv['sha256'], $csv['rows'], count($csv['lines']), $new, $filled, min($days), max($days), mb_substr($caller->actor, 0, 64), $now]);
                return ['rows' => $csv['rows'], 'lines' => count($csv['lines']), 'lines_new' => $new, 'tracking_filled' => $filled];
            });
            $this->endRun($run, 'ok', ['listed' => $out['lines'], 'lines_new' => $out['lines_new'], 'tracking_found' => $out['tracking_filled']], 0, null);
            Audit::write($this->db, $caller, 'p2g.import', 'carrier_account', (string) $acct['id'], null, ['account' => $acct['key']] + $out);
            return $out;
        });
    }

    // ---- the go-live kit's steps ---------------------------------------------------------------------------------------------------

    /** The account switched on from $from (its first day and state active), audited. The kit only (after the owner's keys and the probe). */
    public function switchOn(Caller $caller, string $key, string $from): void
    {
        if (preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/D', $from) !== 1) {
            throw new CwException('bad_day', 'the first day is a date (YYYY-MM-DD)', 400);
        }
        $this->locked(function () use ($caller, $key, $from): void {
            $acct = self::account($this->db, $key);
            (new Settings($this->db))->set($caller, self::FROM_SETTING . $acct['party'], $from, 'Parcel2Go switched on (go-live kit)');
            $this->db->exec("UPDATE carrier_account SET state = 'active' WHERE id = ?", [$acct['id']]);
            $run = $this->startRun($acct, 'switch', $caller);
            $this->endRun($run, 'ok', [], 0, null);
            Audit::write($this->db, $caller, 'p2g.switch_on', 'carrier_account', (string) $acct['id'], null, ['account' => $key, 'from' => $from]);
        });
    }

    /** The account switched off (its jobs stop; nothing counted is removed): the quick stop, waiting up to STOP_WAIT_SEC for a run to end. */
    public function switchOff(Caller $caller, string $key): void
    {
        $this->locked(function () use ($caller, $key): void {
            $acct = self::account($this->db, $key);
            (new Settings($this->db))->set($caller, self::FROM_SETTING . $acct['party'], '', 'Parcel2Go switched off');
            $this->db->exec("UPDATE carrier_account SET state = 'off' WHERE id = ?", [$acct['id']]);
            $run = $this->startRun($acct, 'switch', $caller);
            $this->endRun($run, 'ok', [], 0, null);
            Audit::write($this->db, $caller, 'p2g.switch_off', 'carrier_account', (string) $acct['id'], null, ['account' => $key]);
        }, self::STOP_WAIT_SEC);
    }

    /**
     * Every live Parcel2Go bill of an account SWITCHED OFF undone (its allocations ended, the days moved; one guarded write a bill) and its lines
     * due again (a later switch-on prices them again). The go-live kit's undo, under the Parcel2Go lock (no run of the job meanwhile).
     *
     * @return array{bills: int, pence: int}
     */
    public function undoAll(Caller $caller, string $key): array
    {
        $this->bills->authoriseWrite($caller);
        return $this->locked(function () use ($caller, $key): array {
            $acct = self::account($this->db, $key);
            if ($acct['state'] !== 'off' || $acct['from'] !== null) {
                throw new CwException('p2g_on', 'switch the Parcel2Go account off first (--switch-off): nothing was undone', 409);
            }
            $run = $this->startRun($acct, 'undo', $caller);
            $n = 0;
            $pence = 0;
            foreach (array_map('intval', $this->db->column("SELECT id FROM carrier_invoice WHERE carrier = ? AND account = ? AND status = 'live' ORDER BY id",
                [self::CARRIER, $acct['account_no']])) as $bill) {
                $this->bills->inLock(function () use ($caller, $bill, &$n, &$pence): void {
                    $this->bills->guardedWrite(function (Db $db) use ($caller, $bill, &$n, &$pence): array {
                        $was = self::liveAllocations($db, [$bill]);
                        $now = $this->now();
                        return ['before' => self::facts($was), 'after' => [], 'write' => function (Db $db) use ($caller, $bill, $was, $now, &$n, &$pence): void {
                            self::endAllocations($db, $was, $now);
                            $db->exec("UPDATE carrier_invoice SET status = 'undone', ended_actor = ?, ended_at = ? WHERE id = ? AND status = 'live'", [$caller->actor, $now, $bill]) === 1
                                || throw new CwException('busy', 'a Parcel2Go bill changed while it was undone: tried again', 503);
                            $db->exec("UPDATE p2g_line SET price_state = 'due', invoice_id = NULL, price_tries = 0, next_try_at = NULL, updated_at = ? WHERE invoice_id = ?", [$now, $bill]);
                            $n++;
                            $pence += (int) $db->value('SELECT net_pence FROM carrier_invoice WHERE id = ?', [$bill]);
                        }];
                    });
                });
            }
            $this->endRun($run, 'ok', ['placed' => $n], 0, null);
            Audit::write($this->db, $caller, 'p2g.undo_all', 'carrier_account', (string) $acct['id'], null, ['account' => $key, 'bills' => $n, 'pence' => $pence]);
            return ['bills' => $n, 'pence' => $pence];
        });
    }

    /** Refused lines (e.g. after the reader was corrected to the real layout) due again, audited. @return int how many */
    public function retryRefused(Caller $caller, string $key): int
    {
        return $this->locked(function () use ($caller, $key): int {
            $acct = self::account($this->db, $key);
            $n = $this->db->exec("UPDATE p2g_line SET price_state = 'due', price_tries = 0, next_try_at = NULL, updated_at = ? WHERE account_id = ? AND price_state = 'refused'",
                [$this->now(), $acct['id']]);
            Audit::write($this->db, $caller, 'p2g.retry_refused', 'carrier_account', (string) $acct['id'], null, ['account' => $key, 'lines' => $n]);
            return $n;
        });
    }

    // ---- reading -------------------------------------------------------------------------------------------------------------------

    /**
     * An account's figures for the tool and the kit's gates (read only): lines by price state and source, the live bills, where their money is
     * by destination and reason, the lines on CW orders by shop.
     *
     * @return array<string, mixed>
     */
    public static function status(Db $db, string $key, ?string $from = null, ?string $to = null): array
    {
        $a = self::account($db, $key);
        $w = 'l.account_id = ?' . ($from !== null ? ' AND l.paid_day >= ?' : '') . ($to !== null ? ' AND l.paid_day <= ?' : '');
        $p = array_values(array_filter([$a['id'], $from, $to], static fn ($x): bool => $x !== null));
        $states = [];
        foreach ($db->all("SELECT l.price_state, l.source, COUNT(*) AS n FROM p2g_line l WHERE {$w} GROUP BY l.price_state, l.source ORDER BY l.price_state, l.source", $p) as $r) {
            $states[$r['price_state'] . '|' . $r['source']] = (int) $r['n'];
        }
        $money = [];
        // each live row's own allocations (by invoice AND row), its label the one the row names: every penny once (F1 / M14)
        foreach ($db->all("SELECT a.dest, a.how, a.channel_id, COUNT(DISTINCT l.order_line_id) AS n, SUM(a.pence) AS p FROM p2g_charge c JOIN carrier_invoice i ON i.id = c.invoice_id "
            . "AND i.status = 'live' JOIN carrier_allocation a ON a.invoice_id = c.invoice_id AND a.row_no = c.row_no AND a.ended_at IS NULL JOIN p2g_line l "
            . "ON l.account_id = c.account_id AND l.order_line_id = c.order_line_id WHERE {$w} GROUP BY a.dest, a.how, a.channel_id ORDER BY a.dest, a.how, a.channel_id", $p) as $r) {
            $money[] = ['dest' => (string) $r['dest'], 'how' => (string) $r['how'], 'channel_id' => $r['channel_id'] === null ? null : (int) $r['channel_id'], 'lines' => (int) $r['n'],
                'pence' => (int) $r['p']];
        }
        $refused = [];
        foreach ($db->all("SELECT l.refuse_code, COUNT(*) AS n FROM p2g_line l WHERE {$w} AND l.refuse_code IS NOT NULL GROUP BY l.refuse_code ORDER BY l.refuse_code", $p) as $r) {
            $refused[(string) $r['refuse_code']] = (int) $r['n'];
        }
        $lines = (int) $db->value("SELECT COUNT(*) FROM p2g_line l WHERE {$w}", $p);
        $tracked = (int) $db->value("SELECT COUNT(DISTINCT l.order_line_id) FROM p2g_line l JOIN p2g_parcel x ON x.account_id = l.account_id AND x.order_line_id = l.order_line_id "
            . "AND x.tracking_no IS NOT NULL WHERE {$w}", $p);
        $live = $db->one("SELECT COUNT(*) AS n, COALESCE(SUM(net_pence), 0) AS p FROM carrier_invoice WHERE carrier = ? AND account = ? AND status = 'live'",
            [self::CARRIER, $a['account_no']]) ?? ['n' => 0, 'p' => 0];
        $alloc = (int) $db->value("SELECT COALESCE(SUM(x.pence), 0) FROM carrier_allocation x JOIN carrier_invoice i ON i.id = x.invoice_id AND i.status = 'live' "
            . 'AND i.carrier = ? AND i.account = ? WHERE x.ended_at IS NULL', [self::CARRIER, $a['account_no']]);
        $run = $db->one('SELECT id, kind, state, error, started_at, finished_at FROM p2g_run WHERE account_id = ? ORDER BY id DESC LIMIT 1', [$a['id']]);
        return ['account' => $a, 'lines' => $lines, 'tracked' => $tracked, 'states' => $states, 'refused' => $refused, 'money' => $money,
            'bills' => (int) $live['n'], 'bills_net' => (int) $live['p'], 'allocated' => $alloc, 'last_run' => $run];
    }

    /**
     * The health alert's Parcel2Go checks (PGL9), per account switched on: no good run for an hour, its newest run failed, lines may have been
     * missed (a full recent list of new lines, no export read since), paid labels without a price for longer than the setting.
     *
     * @return list<array<string, mixed>>
     */
    public static function health(Db $db, \DateTimeImmutable $now): array
    {
        if ($db->value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'p2g_run'") === null) {
            return [];
        }
        $raw = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?", [self::PRICE_WAIT_SETTING]);
        $waitH = is_numeric($x = json_decode((string) ($raw ?? '6'), true)) ? max(1, (int) $x) : 6;
        $out = [];
        $nowDb = Clock::db($now);
        foreach (self::accounts($db) as $key => $a) {
            if (!$a['on']) {
                continue;
            }
            $base = ['channel' => null, 'channel_id' => $a['main'], 'name' => $a['name'], 'mode' => null];
            $on = $db->value("SELECT MAX(started_at) FROM p2g_run WHERE account_id = ? AND kind = 'switch' AND state = 'ok'", [$a['id']]);
            $ok = $db->value("SELECT MAX(finished_at) FROM p2g_run WHERE account_id = ? AND kind = 'pull' AND state = 'ok'", [$a['id']]);
            $mins = static fn (?string $t): ?int => $t === null ? null : (int) floor(($now->getTimestamp() - Clock::fromDb($t)->getTimestamp()) / 60);
            $okMin = $mins($ok === null ? null : (string) $ok);
            $onMin = $mins($on === null ? null : (string) $on);
            if (($okMin === null && ($onMin === null || $onMin >= 60)) || ($okMin !== null && $okMin >= 60)) {
                $out[] = ['key' => 'p2g_not_read:' . $key, 'kind' => 'p2g_not_read', 'detail' => $okMin === null
                    ? "{$a['name']}: Parcel2Go has not been read well since the account was switched on (bin/p2g_pull.php)"
                    : "{$a['name']}: Parcel2Go has not been read well for {$okMin} minutes (bin/p2g_pull.php): its labels' costs wait",
                    'data' => ['account' => $key, 'minutes' => $okMin]] + $base;
            }
            $newest = $db->one("SELECT state, error FROM p2g_run WHERE account_id = ? AND kind = 'pull' ORDER BY id DESC LIMIT 1", [$a['id']]);
            if ($newest !== null && $newest['state'] === 'failed') {
                $out[] = ['key' => 'p2g_failed:' . $key, 'kind' => 'p2g_failed', 'detail' => "{$a['name']}: the last Parcel2Go read failed: " . (string) $newest['error'],
                    'data' => ['account' => $key, 'error' => (string) $newest['error']]] + $base;
            }
            // a gap (written with the labels) is cleared only by an export read after it whose days cover it (H8)
            $gap = $db->one("SELECT r.started_at, r.gap_from, r.gap_to FROM p2g_run r WHERE r.account_id = ? AND r.kind = 'pull' AND r.gap_seen = 1 AND NOT EXISTS "
                . '(SELECT 1 FROM p2g_import m WHERE m.account_id = r.account_id AND m.imported_at >= r.started_at AND (r.gap_from IS NULL OR m.first_day <= r.gap_from) '
                . 'AND (r.gap_to IS NULL OR m.last_day >= r.gap_to)) ORDER BY r.id DESC LIMIT 1', [$a['id']]);
            if ($gap !== null) {
                $days = $gap['gap_from'] === null ? '' : " ({$gap['gap_from']} to {$gap['gap_to']})";
                $out[] = ['key' => 'p2g_gap:' . $key, 'kind' => 'p2g_gap', 'detail' => "{$a['name']}: more Parcel2Go labels were made between two reads than Parcel2Go's list shows "
                    . "(25){$days}: some may be missing. Export My Orders from Parcel2Go for those days and give it to the CW team",
                    'data' => ['account' => $key, 'since' => (string) $gap['started_at'], 'from' => $gap['gap_from'], 'to' => $gap['gap_to']]] + $base;
            }
            // a CW parcel with this account's money and another carrier's cost too (H1 / F10: DPD or Royal Mail matched it after Parcel2Go):
            // both real costs stay; the order is named to look at (DB15 says it too)
            $twice = $db->all("SELECT DISTINCT a.channel_id, a.order_ref FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' "
                . "AND i.carrier = ? AND i.account = ? WHERE a.ended_at IS NULL AND a.dest = 'order' AND (EXISTS (SELECT 1 FROM carrier_allocation b JOIN carrier_invoice j "
                . "ON j.id = b.invoice_id AND j.status = 'live' WHERE b.ended_at IS NULL AND b.dest = 'order' AND b.channel_id = a.channel_id AND b.order_ref = a.order_ref "
                . 'AND b.parcel_ref = a.parcel_ref AND NOT (j.carrier = i.carrier AND j.account = i.account)) OR EXISTS (SELECT 1 FROM carrier_invoice_line l '
                . "JOIN carrier_invoice k ON k.id = l.invoice_id AND k.status = 'live' WHERE l.match_state = 'matched' AND l.channel_id = a.channel_id AND l.order_ref = a.order_ref "
                . 'AND l.parcel_ref = a.parcel_ref)) ORDER BY a.channel_id, a.order_ref LIMIT 21', [self::CARRIER, $a['account_no']]);
            if ($twice !== []) {
                $refs = array_map(static fn (array $r): string => (string) $r['order_ref'], array_slice($twice, 0, 20));
                $out[] = ['key' => 'p2g_needs_look:' . $key, 'kind' => 'p2g_needs_look', 'detail' => sprintf('%s: %s%d CW orders have a Parcel2Go label\'s cost and another carrier\'s '
                    . 'cost on the same parcel (both are counted): look at orders %s and tell the CW team which is right', $a['name'], count($twice) > 20 ? 'more than ' : '',
                    min(20, count($twice)), implode(', ', $refs)), 'data' => ['account' => $key, 'orders' => $refs]] + $base;
            }
            $limit = Clock::db($now->modify("-{$waitH} hours"));
            $w = $db->one("SELECT COUNT(*) AS n, MIN(paid_at) AS oldest FROM p2g_line WHERE account_id = ? AND price_state IN ('due', 'refused') AND paid_at < ?", [$a['id'], $limit]);
            if ($w !== null && (int) $w['n'] > 0) {
                $out[] = ['key' => 'p2g_waiting:' . $key, 'kind' => 'p2g_waiting', 'detail' => sprintf('%s: %d paid Parcel2Go labels have no price after %d hours (the oldest paid %s UTC): '
                    . 'their invoices could not be read. Tell the CW team', $a['name'], (int) $w['n'], $waitH, (string) $w['oldest']),
                    'data' => ['account' => $key, 'count' => (int) $w['n'], 'hours' => $waitH]] + $base;
            }
            unset($nowDb);
        }
        return $out;
    }

    // ---- runs and the lock ---------------------------------------------------------------------------------------------------------

    private function startRun(array $acct, string $kind, Caller $caller): int
    {
        return $this->db->insert("INSERT INTO p2g_run (account_id, kind, state, actor, started_at) VALUES (?, ?, 'running', ?, ?)",
            [$acct['id'], $kind, substr($caller->actor, 0, 64), $this->now()]);
    }

    /** @param array<string, int> $sum */
    private function endRun(int $run, string $state, array $sum, int $calls, ?string $error): void
    {
        $this->db->exec('UPDATE p2g_run SET state = ?, calls = ?, listed = ?, lines_new = ?, tracking_found = ?, priced = ?, refused = ?, placed = ?, gap_seen = ?, error = ?, '
            . 'finished_at = ? WHERE id = ?', [$state, $calls, $sum['listed'] ?? 0, $sum['lines_new'] ?? 0, $sum['tracking_found'] ?? 0, $sum['priced'] ?? 0, $sum['refused'] ?? 0,
                $sum['placed'] ?? 0, $sum['gap_seen'] ?? 0, $error === null ? null : mb_substr($error, 0, 255), $this->now(), $run]);
    }

    /** A failure in CW's own words: a CwException's message (CW writes them; never Parcel2Go's text), else its class and code only. */
    public static function why(\Throwable $e): string
    {
        if ($e instanceof CwException) {
            return mb_substr($e->getMessage(), 0, 240);
        }
        $code = $e instanceof \PDOException ? Db::driverCode($e) : null;
        return 'an error in CW (' . (new \ReflectionClass($e))->getShortName() . ($code === null ? '' : ' ' . $code) . '): tell the CW team';
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function locked(\Closure $fn, int $waitSec = self::LOCK_WAIT_SEC): mixed
    {
        $name = self::LOCK . (string) $this->db->value('SELECT DATABASE()');
        if ((int) $this->db->value('SELECT GET_LOCK(?, ?)', [$name, $waitSec]) !== 1) {
            throw new CwException('busy', 'another Parcel2Go run holds its lock right now: nothing was done (the next run goes on)', 503);
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

    private static function addDays(string $day, int $n): string
    {
        return (new \DateTimeImmutable($day . ' 00:00:00', Clock::utc()))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }
}
