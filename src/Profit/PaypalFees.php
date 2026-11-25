<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Reports\ReportRefresh;
use CW\Settings;

/**
 * PayPal's payment fees in CW's profit (CW-1172; docs/decisions.md PPF1-PPF22), read from PayPal's own API every night (bin/paypal_fees.php).
 *
 *  run()      reads the days asked for from PayPal window by window (WINDOW_DAYS at most each; a window is stored whole or not at all), stores
 *             every transaction by account, PayPal's id and event code (a re-read changes the row in place with its history, never a second
 *             row), marks what is new or changed `due` in the same transaction (durable before acting: PPF9), then decides every due row.
 *  decide()   where each due row's money counts (PPF5-PPF7), CHUNK rows a guarded write: on its order (a sale's fee on the order's paid day;
 *             a refund's fee back, a chargeback's money and fees on their own UK day), or on the shop's "not on an order" line on its own day
 *             (no order number, a number no CW order has yet, a number of two or more orders); nowhere when it does not count (a hold, a
 *             payment sent, before the shop's first fee day, pending, denied, not understood). Before deciding it marks due again the rows whose
 *             order reached CW since (a number on no order, or on two) and the rows the shop's first fee day now puts on the other side.
 *
 * The profit days (PPF7, PPF8): profit_day.fee_cost (on orders) and fee_other (not on an order) change in the same transaction as the rows,
 * through DeliveryBills::guardedWrite (THE guarded write of the profit days' other figures: the order guards, the day rows in key order, the
 * orders' days read again under them, then the rows and the figures by the difference); an order paid or moved to another day takes its
 * sale fees along (OrderSnapshots passes orderFee() to ProfitDays::orderShare), and ProfitDays::compute() works both out from scratch with
 * dayFigures(): the kept days always equal a rebuild.
 */
final class PaypalFees
{
    /** The days of one PayPal window (PayPal allows 31; a week of Vape and Go is about 2,000 rows, well under any limit of PayPal's). */
    public const WINDOW_DAYS = 7;
    /** The rows one guarded write decides (each takes the guard of every order it names: within the profit days' hold budget, PPF15). */
    public const CHUNK = 200;
    /** How long a run waits for another one of the same account, in seconds. */
    public const LOCK_WAIT_SEC = 10;
    /** No ok run for this many hours: the health alert (the brief: 26 h). */
    public const STALE_HOURS = 26;
    /** A pending row is read again for at most this many days back (then it is said in --status and stays pending). */
    public const PENDING_DAYS = 31;
    public const DEFAULT_REREAD_DAYS = 7;
    public const DEFAULT_ALARM_PENCE = 5000;
    /** PayPal's statuses: S done, V reversed (it happened; the reversal is a row of its own), P pending, D denied (PPF5). */
    public const STATUS_COUNTS = ['S', 'V'];
    /** What PayPal says of a row, compared on a re-read (a change of one of them is a change of the row, PPF2). */
    private const FACTS = ['ref_txn_id', 'ref_type', 'status', 'initiated_at', 'pp_updated_at', 'day', 'currency', 'amount_pence', 'fee_currency', 'fee_pence',
        'invoice_state', 'invoice_ref', 'custom_state', 'custom_ref'];
    /** Where a row counts (decide()'s outcome). */
    private const PLACE = ['count_state', 'look_reason', 'form_shop', 'order_no', 'match_state', 'candidates', 'shop_channel', 'alloc_dest', 'alloc_channel',
        'alloc_order_ref', 'alloc_day', 'alloc_pence'];
    private const RETRY = 'paypal_retry';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    /** The run in progress (rematch() records the forms it decides by on it). */
    private ?int $runId = null;
    /** How long the last rematch() of decideAll() took (the full-size timing, PPF15). */
    public float $lastRematchSec = 0.0;
    /** @var array<string, float> its two parts (the orders reached CW since; the first fee day) */
    public array $rematchParts = [];

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock CW's now (tests move it)
     * @param (\Closure(int, float): void)|null $onChunk told the rows and seconds of each guarded write (the full-size timing, PPF15)
     */
    public function __construct(private readonly Db $db, ?\Closure $clock = null, private readonly ?\Closure $onChunk = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    // ---- settings --------------------------------------------------------------------------------------------------------------------

    /** The first UK day a shop's PayPal fees count (profit.paypal_from_<code>), null: not switched on. */
    public static function feesFrom(Db $db, string $shopCode): ?string
    {
        $v = $db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', ['profit.paypal_from_' . $shopCode]);
        $d = $v === null ? null : json_decode((string) $v, true);
        return is_string($d) && preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $d) === 1 ? $d : null;
    }

    /**
     * The shops a PayPal account takes payments for (profit.paypal_shops_<account>: shop codes separated by commas, the FIRST the account's
     * main shop, whose "not on an order" line takes the money no shop's form claims), each with how its order number looks in a PayPal invoice
     * (profit.paypal_form_<shop>: a pattern with ONE (part), the number). The owner, 25 Nov 2026: ONE account takes Vape and Go's, Electrofag's
     * and Hayati Vapes' payments (docs/decisions.md PPF21). A code CW has no shop of (hayati: a WooCommerce shop) is a shop outside CW: its rows
     * are kept, never counted (id null). [] when the setting is missing or names none; a shop without a pattern that compiles is refused.
     *
     * @return list<array{id: ?int, code: string, name: string, form: string}>
     */
    public static function accountShops(Db $db, string $account): array
    {
        $codes = self::text($db, 'profit.paypal_shops_' . $account);
        if ($codes === null || $codes === '') {
            return [];
        }
        $out = [];
        foreach (array_values(array_unique(array_map('trim', explode(',', $codes)))) as $code) {
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $code) !== 1) {
                throw new CwException('paypal_bad_shops', "profit.paypal_shops_{$account} names a shop code that is not one: nothing read", 422);
            }
            $form = self::text($db, 'profit.paypal_form_' . $code);
            // a pattern from ^ to $ with exactly one (part) that compiles: anything else refuses the run (never a guess at whose a row is)
            if ($form === null || preg_match('/^\^[^()]*\([^()]+\)[^()]*\$$/D', $form) !== 1 || @preg_match('/' . str_replace('/', '\/', $form) . '/D', '') === false) {
                throw new CwException('paypal_bad_form', "the order-number pattern of {$code} (profit.paypal_form_{$code}) is missing or not a pattern with one (part): nothing read", 422);
            }
            $c = $db->one('SELECT id, code, name FROM channel WHERE code = ?', [$code]);
            $out[] = ['id' => $c === null ? null : (int) $c['id'], 'code' => $code, 'name' => $c === null ? $code : (string) $c['name'],
                'form' => '/' . str_replace('/', '\/', $form) . '/D'];
        }
        // the main shop must be a CW shop: without it the account serves no shop CW has ([]: the run is refused, nothing is watched)
        return $out[0]['id'] === null ? [] : $out;
    }

    /** The shops and forms an account decides by, as a short fingerprint (a change re-decides every row of the account once: rematch()). */
    public static function formsSig(array $shops): string
    {
        return substr(hash('sha256', json_encode(array_map(static fn (array $s): array => [$s['code'], $s['id'], $s['form']], $shops), JSON_THROW_ON_ERROR)), 0, 16);
    }

    private static function text(Db $db, string $key): ?string
    {
        $v = $db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        $d = $v === null ? null : json_decode((string) $v, true);
        return is_string($d) ? $d : null;
    }

    /** @return list<string> every account with a shops setting (profit.paypal_shops_<account>), in name order */
    public static function accounts(Db $db): array
    {
        return array_map(static fn (mixed $k): string => substr((string) $k, strlen('profit.paypal_shops_')),
            $db->column("SELECT setting_key FROM app_setting WHERE setting_key LIKE 'profit.paypal\\_shops\\_%' ORDER BY setting_key"));
    }

    /** How many days back the nightly reads again (profit.paypal_reread_days, 1 to 31). */
    public static function rereadDays(Db $db): int
    {
        return max(1, min(PaypalApi::MAX_WINDOW_DAYS, Settings::number($db, 'profit.paypal_reread_days', self::DEFAULT_REREAD_DAYS)));
    }

    /** The "not on an order" alarm in pence (profit.paypal_not_on_order_alarm, pounds). */
    public static function alarmPence(Db $db): int
    {
        $v = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'profit.paypal_not_on_order_alarm'");
        $d = $v === null ? null : json_decode((string) $v, true);
        $d = is_int($d) ? (string) $d : $d;
        if (!is_string($d) || preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,2}))?$/D', $d, $m) !== 1) {
            return self::DEFAULT_ALARM_PENCE;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    // ---- the run ---------------------------------------------------------------------------------------------------------------------

    /**
     * One run (bin/paypal_fees.php): the UK days $fromDay..$toDay read from PayPal window by window and stored, then every due row decided.
     * A window that cannot be read whole stores nothing of itself and ends the run as failed (the windows before it stay stored, their rows
     * due until decided); a decision that cannot be made now (an order busy) leaves its rows due for the next run.
     *
     * @return array{run: int, windows: int, pages: int, read: int, new: int, changed: int, allocated: int, refreshed: ?string, seconds: array<string, float>}
     */
    public function run(Caller $caller, PaypalApi $api, string $fromDay, string $toDay, string $kind): array
    {
        self::authorise($caller);
        if (!in_array($kind, ['nightly', 'backfill'], true) || !self::isDay($fromDay) || !self::isDay($toDay) || $fromDay > $toDay) {
            throw new CwException('paypal_bad_days', 'a run reads whole UK days, the first not after the last', 422);
        }
        $account = $api->account();
        $shops = self::accountShops($this->db, $account);
        if ($shops === []) {
            throw new CwException('paypal_no_shops', "the PayPal account {$account} serves no shop CW has (Settings › Profit: profit.paypal_shops_{$account}): nothing read", 422);
        }
        // the key file names the account's main shop: one for another shop is refused (a key placed for the wrong account; PPF11)
        if ($shops[0]['code'] !== $api->shop()) {
            throw new CwException('paypal_key_refused', "the PayPal key of {$account} is for {$api->shop()}, the account's main shop is {$shops[0]['code']}: nothing read", 403);
        }
        $shop = $shops[0];
        return $this->locked($account, function () use ($caller, $api, $fromDay, $toDay, $kind, $shop, $account): array {
            $now = Clock::db(($this->clock)());
            // a run of this account that died (no other one can be running: this one holds the account's lock) is said to have failed; its
            // rows stay due and this run decides them
            $this->db->exec("UPDATE paypal_run SET state = 'failed', error = 'did not finish (the next run took over)', finished_at = ? WHERE account = ? AND state = 'running'",
                [$now, $account]);
            $runId = $this->db->insert('INSERT INTO paypal_run (account, channel_id, kind, from_day, to_day, actor, started_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$account, $shop['id'], $kind, $fromDay, $toDay, $caller->actor, $now]);
            $this->runId = $runId;
            $sum = ['run' => $runId, 'windows' => 0, 'pages' => 0, 'read' => 0, 'new' => 0, 'changed' => 0, 'allocated' => 0, 'refreshed' => null,
                'seconds' => ['paypal' => 0.0, 'parse' => 0.0, 'store' => 0.0, 'decide' => 0.0]];
            try {
                foreach (self::windows($fromDay, $toDay, ($this->clock)()) as [$a, $b]) {
                    $t = microtime(true);
                    $w = $api->window($a, $b);
                    $sum['seconds']['paypal'] += microtime(true) - $t;
                    $t = microtime(true);
                    $rows = [];
                    foreach ($w['rows'] as $i => $ti) {
                        $rows[] = PaypalRow::parse($ti, $i + 1);
                    }
                    $sum['seconds']['parse'] += microtime(true) - $t;
                    $t = microtime(true);
                    $s = $this->store($runId, $account, $shop['id'], $rows, $caller);
                    $sum['seconds']['store'] += microtime(true) - $t;
                    $sum['windows']++;
                    $sum['pages'] += $w['pages'];
                    $sum['read'] += count($rows);
                    $sum['new'] += $s['new'];
                    $sum['changed'] += $s['changed'];
                    $sum['refreshed'] = $w['refreshed'] ?? $sum['refreshed'];
                    $this->progress($runId, $sum);
                }
                $t = microtime(true);
                $sum['allocated'] = $this->decideAll($caller, $account, $runId);
                $sum['seconds']['decide'] = microtime(true) - $t;
                $sum['seconds']['rematch'] = $this->lastRematchSec;
                $sum['seconds'] = array_map(static fn (float $x): float => round($x, 2), $sum['seconds']);
            } catch (CwException | \PDOException $e) {
                $why = $e instanceof CwException ? $e->getMessage() : 'database error ' . (Db::driverCode($e) ?? 'unknown');
                $this->finish($runId, $sum, 'failed', mb_substr($why, 0, 255));
                throw $e;
            } finally {
                $this->runId = null;
            }
            $this->finish($runId, $sum, 'ok', null);
            return $sum;
        });
    }

    /**
     * Every due row of the account decided (decide()), CHUNK a guarded write, after marking due the rows whose decision may have changed since
     * (rematch()). Under the account's lock (run(), or bin/paypal_fees.php --decide).
     *
     * @return int rows whose place changed
     */
    public function decideAll(Caller $caller, string $account, ?int $runId): int
    {
        self::authorise($caller);
        $t = microtime(true);
        $this->rematch($account);
        $this->lastRematchSec = microtime(true) - $t;
        $changed = 0;
        $seen = [];
        while (true) {
            $ids = array_map('intval', $this->db->column('SELECT id FROM paypal_txn WHERE due = 1 AND account = ? ORDER BY id LIMIT ' . self::CHUNK, [$account]));
            if ($ids === []) {
                return $changed;
            }
            foreach ($ids as $id) {
                if (isset($seen[$id])) {
                    throw new \LogicException("PayPal row {$id} stayed due after its decision");
                }
                $seen[$id] = true;
            }
            $t = microtime(true);
            $changed += $this->decide($caller, $ids, $runId);
            if ($this->onChunk !== null) {
                ($this->onChunk)(count($ids), microtime(true) - $t);
            }
        }
    }

    /**
     * A run that could not start because the account's key was refused (missing, wrong mode, another account's: PPF11), recorded as a failed
     * run of the account (its main shop; nothing read) so the health alert says it at once (paypal_failed). Never the key's content.
     */
    public function refused(string $account, string $why): void
    {
        try {
            $shops = preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $account) === 1 ? self::accountShops($this->db, $account) : [];
        } catch (CwException) {
            $shops = []; // its shops' settings are wrong too: the run itself says so
        }
        if ($shops === []) {
            return;
        }
        $now = Clock::db(($this->clock)());
        $today = $this->today();
        $this->db->exec("INSERT INTO paypal_run (account, channel_id, kind, from_day, to_day, state, error, actor, started_at, finished_at) "
            . "VALUES (?, ?, 'nightly', ?, ?, 'failed', ?, 'system:paypal_fees', ?, ?)", [$account, $shops[0]['id'], $today, $today, mb_substr($why, 0, 255), $now, $now]);
    }

    /**
     * Rows decided before whose decision may be another now: a number no CW order had (or two had) that a CW order of one of the account's
     * shops now has, and rows a shop's first fee day now puts on the other side (the setting changed). Marked due (they are decided next).
     * Plain indexed reads, then marks by id (MySQL planned an UPDATE with a correlated EXISTS row by row: 12 s at the real size; PPF15).
     */
    public function rematch(string $account): int
    {
        $t0 = microtime(true);
        $shops = self::accountShops($this->db, $account);
        $n = 0;
        // the shops or their forms changed since the last ok run decided: every row of the account decided again, once (PPF21)
        $sig = self::formsSig($shops);
        $last = $this->db->value("SELECT forms_sig FROM paypal_run WHERE account = ? AND state = 'ok' AND forms_sig IS NOT NULL ORDER BY id DESC LIMIT 1", [$account]);
        if ($last !== null && $last !== $sig) {
            $n += $this->db->exec('UPDATE paypal_txn SET due = 1 WHERE account = ? AND due = 0', [$account]);
        }
        if ($this->runId !== null) {
            $this->db->exec('UPDATE paypal_run SET forms_sig = ? WHERE id = ?', [$sig, $this->runId]);
        }
        $waiting = $this->db->all("SELECT id, shop_channel, order_no FROM paypal_txn WHERE account = ? AND match_state IN ('no_order', 'ambiguous') AND due = 0 "
            . 'AND order_no IS NOT NULL ORDER BY id', [$account]);
        $found = self::orders($this->db, array_map(static fn (array $r): array => [(int) $r['shop_channel'], (string) $r['order_no']], $waiting));
        $ids = [];
        foreach ($waiting as $r) {
            if (isset($found[(int) $r['shop_channel'] . '|' . $r['order_no']])) {
                $ids[] = (int) $r['id'];
            }
        }
        foreach (array_chunk($ids, 1000) as $chunk) {
            $n += $this->db->exec('UPDATE paypal_txn SET due = 1 WHERE due = 0 AND id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk);
        }
        $this->rematchParts = ['orders' => round(microtime(true) - $t0, 3)];
        $t0 = microtime(true);
        foreach ($this->db->all('SELECT DISTINCT t.shop_channel, c.code FROM paypal_txn t JOIN channel c ON c.id = t.shop_channel WHERE t.account = ?', [$account]) as $r) {
            $from = self::feesFrom($this->db, (string) $r['code']);
            $n += $from === null
                ? $this->db->exec("UPDATE paypal_txn SET due = 1 WHERE account = ? AND shop_channel = ? AND due = 0 AND count_state IN ('counts', 'pending')",
                    [$account, (int) $r['shop_channel']])
                : $this->db->exec("UPDATE paypal_txn SET due = 1 WHERE account = ? AND shop_channel = ? AND due = 0 AND ((count_state = 'before_start' AND day >= ?) "
                    . "OR (count_state IN ('counts', 'pending') AND day < ?))", [$account, (int) $r['shop_channel'], $from, $from]);
        }
        $this->rematchParts['switch'] = round(microtime(true) - $t0, 3);
        return $n;
    }

    // ---- reading for the profit days and the pages ------------------------------------------------------------------------------------

    /**
     * An order's PayPal money that goes with its paid day (its sales' fees): OrderSnapshots::apply passes it to ProfitDays::orderShare, so an
     * order paid, or moved to another day, takes it along. A refund's or a chargeback's money on it counts on its own day (dayFigures()).
     */
    public static function orderFee(Db $db, int $channelId, string $ref): int
    {
        return (int) $db->value("SELECT COALESCE(SUM(alloc_pence), 0) FROM paypal_txn WHERE alloc_channel = ? AND alloc_order_ref = ? AND alloc_dest = 'order' "
            . 'AND alloc_day IS NULL', [$channelId, $ref]);
    }

    /**
     * A store's day of PayPal figures from scratch (ProfitDays::compute): the sale fees on the day's paid orders (by lower-case ref), the money
     * on orders that counts on this day itself (refunds, chargebacks, fees), the money on no order dated this day, and how many rows count on
     * the day itself.
     *
     * @return array{orders: array<string, int>, on_orders: int, other: int, rows: int}
     */
    public static function dayFigures(Db $db, int $channelId, string $day): array
    {
        $orders = [];
        foreach ($db->all("SELECT o.order_ref, SUM(t.alloc_pence) AS c FROM sale_order o JOIN paypal_txn t ON t.alloc_channel = o.channel_id AND t.alloc_order_ref = o.order_ref "
            . "AND t.alloc_dest = 'order' AND t.alloc_day IS NULL WHERE o.day = ? AND o.channel_id = ? GROUP BY o.order_ref", [$day, $channelId]) as $r) {
            $orders[strtolower((string) $r['order_ref'])] = (int) $r['c'];
        }
        $r = $db->one("SELECT COALESCE(SUM(IF(alloc_dest = 'order', alloc_pence, 0)), 0) AS on_orders, COALESCE(SUM(IF(alloc_dest = 'shop', alloc_pence, 0)), 0) AS other, "
            . 'COUNT(*) AS n FROM paypal_txn WHERE alloc_channel = ? AND alloc_day = ? AND alloc_dest IS NOT NULL', [$channelId, $day]) ?? [];
        return ['orders' => $orders, 'on_orders' => (int) ($r['on_orders'] ?? 0), 'other' => (int) ($r['other'] ?? 0), 'rows' => (int) ($r['n'] ?? 0)];
    }

    /** Whether any PayPal money counts on this store's day itself (a day row with it is kept: DeliveryBills::tidy). */
    public static function anyOnDay(Db $db, int $channelId, string $day): bool
    {
        return $db->value('SELECT 1 FROM paypal_txn WHERE alloc_channel = ? AND alloc_day = ? AND alloc_dest IS NOT NULL LIMIT 1', [$channelId, $day]) !== null;
    }

    /**
     * The PayPal rows on one order, for its page (docs/decisions.md PPF16): oldest first, with the day each counts on (null: the order's paid
     * day) and what it costs.
     *
     * @return list<array{day: string, code: string, name: ?string, kind: string, status: string, counts_on: ?string, pence: int}>
     */
    public static function orderLines(Db $db, int $channelId, string $ref): array
    {
        if ($db->value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paypal_txn'") === null) {
            return [];
        }
        return array_map(static fn (array $r): array => ['day' => (string) $r['day'], 'code' => (string) $r['event_code'], 'name' => PaypalCodes::name((string) $r['event_code']),
            'kind' => (string) $r['kind'], 'status' => (string) $r['status'], 'counts_on' => $r['alloc_day'] === null ? null : (string) $r['alloc_day'],
            'pence' => (int) $r['alloc_pence']], $db->all("SELECT day, event_code, kind, status, alloc_day, alloc_pence FROM paypal_txn WHERE alloc_channel = ? "
            . "AND alloc_order_ref = ? AND alloc_dest = 'order' ORDER BY initiated_at, id", [$channelId, $ref]));
    }

    /**
     * The health alert's PayPal problems (docs/decisions.md PPF14), per PayPal account with a shop switched on (its first fee day set and
     * reached), said in the account's main shop:
     *   paypal_not_read      no ok run of the account for STALE_HOURS (or none ever)
     *   paypal_failed        the account's newest run failed (its reason in CW's words)
     *   paypal_needs_look    rows not understood (an unknown code, another currency, a fee on an ignored row, a wrong sign, an unknown status)
     *                        since the account's first fee day: never counted until a person looks
     *   paypal_not_on_order  a day of the last re-read days whose money on no CW order (the main shop's line) is above the setting
     *
     * @return list<array{key: string, kind: string, channel: string, channel_id: int, name: string, mode: null, detail: string, data: array<string, int|string|null>}>
     */
    public static function health(Db $db, \DateTimeImmutable $now): array
    {
        if ($db->value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paypal_txn'") === null) {
            return [];
        }
        $today = ReportRefresh::ukDay(Clock::db($now));
        $out = [];
        foreach (self::accounts($db) as $account) {
            try {
                $shops = self::accountShops($db, $account);
            } catch (CwException $e) {
                // the account's shops or forms are set wrong: no row of it can be put on a shop, so it is said at once
                $out[] = ['key' => 'paypal_failed:' . $account, 'kind' => 'paypal_failed', 'detail' => $e->getMessage(), 'channel' => null, 'channel_id' => null,
                    'name' => 'PayPal ' . $account, 'mode' => null, 'data' => ['account' => $account, 'run' => 0, 'error' => $e->getMessage()]];
                continue;
            }
            $froms = array_filter(array_map(static fn (array $s): ?string => self::feesFrom($db, $s['code']), $shops), static fn (?string $d): bool => $d !== null && $d <= $today);
            if ($shops === [] || $froms === []) {
                continue;
            }
            $from = min($froms);
            $main = $shops[0];
            $ch = $main['id'];
            $base = ['channel' => $main['code'], 'channel_id' => $ch, 'name' => $main['name'], 'mode' => null];
            $last = $db->value("SELECT MAX(finished_at) FROM paypal_run WHERE account = ? AND state = 'ok'", [$account]);
            $hours = $last === null ? null : (int) floor(($now->getTimestamp() - Clock::fromDb((string) $last)->getTimestamp()) / 3600);
            if ($hours === null || $hours >= self::STALE_HOURS) {
                $out[] = ['key' => 'paypal_not_read:' . $account, 'kind' => 'paypal_not_read', 'detail' => $hours === null
                    ? sprintf('%s: PayPal (account %s) has never been read (its fees count from %s)', $main['name'], $account, $from)
                    : sprintf('%s: PayPal (account %s) not read for %d hours (limit %d): its fees are not up to date', $main['name'], $account, $hours, self::STALE_HOURS),
                    'data' => ['account' => $account, 'hours' => $hours, 'limit' => self::STALE_HOURS, 'from' => $from]] + $base;
            }
            // the newest run failed (PayPal not read whole, an order busy, the key refused): said at once, with CW's own words of why
            $newest = $db->one('SELECT id, state, error, finished_at FROM paypal_run WHERE account = ? ORDER BY id DESC LIMIT 1', [$account]);
            if ($newest !== null && $newest['state'] === 'failed') {
                $out[] = ['key' => 'paypal_failed:' . $account, 'kind' => 'paypal_failed', 'detail' => sprintf('%s: the last PayPal run (account %s) failed: %s', $main['name'],
                    $account, (string) $newest['error']), 'data' => ['account' => $account, 'run' => (int) $newest['id'], 'error' => (string) $newest['error']]] + $base;
            }
            $look = $db->one("SELECT COUNT(*) AS n, MAX(day) AS newest FROM paypal_txn WHERE account = ? AND count_state = 'look' AND day >= ?", [$account, $from]) ?? [];
            if ((int) ($look['n'] ?? 0) > 0) {
                $out[] = ['key' => 'paypal_needs_look:' . $account, 'kind' => 'paypal_needs_look', 'detail' => sprintf('%s: %d PayPal rows CW does not understand (newest %s): '
                    . 'not counted until they are looked at (bin/paypal_fees.php --status)', $main['name'], (int) $look['n'], (string) $look['newest']),
                    'data' => ['account' => $account, 'count' => (int) $look['n'], 'newest' => (string) $look['newest']]] + $base;
            }
            $alarm = self::alarmPence($db);
            $since = (new \DateTimeImmutable($today))->modify('-' . self::rereadDays($db) . ' days')->format('Y-m-d');
            $over = $db->all("SELECT alloc_day, SUM(alloc_pence) AS p FROM paypal_txn WHERE account = ? AND alloc_dest = 'shop' AND alloc_day >= ? AND alloc_day >= ? "
                . 'GROUP BY alloc_day HAVING p > ? ORDER BY alloc_day', [$account, $since, $from, $alarm]);
            if ($over !== []) {
                $top = $over[count($over) - 1];
                $out[] = ['key' => 'paypal_not_on_order:' . $account, 'kind' => 'paypal_not_on_order', 'detail' => sprintf('%s: %d day(s) with more than %s of PayPal money '
                    . 'on no CW order (newest %s: %s)', $main['name'], count($over), ProfitViews::gbp($alarm), (string) $top['alloc_day'], ProfitViews::gbp((int) $top['p'])),
                    'data' => ['account' => $account, 'days' => count($over), 'limit' => ProfitViews::gbp($alarm), 'day' => (string) $top['alloc_day'],
                        'pence' => ProfitViews::gbp((int) $top['p'])]] + $base;
            }
        }
        return $out;
    }

    /**
     * What bin/paypal_fees.php --status says of an account (read only, one moment of the database): its newest runs, its rows by kind and
     * state, the money counted on orders and not on an order since the shop's first fee day, what waits (due, pending, on orders not paid in
     * CW) and every row that needs a look (at most 50; never anything personal).
     *
     * @return array<string, mixed>
     */
    public static function status(Db $db, string $account): array
    {
        return ProfitDays::snapshot($db, static function (Db $db) use ($account): array {
            $runs = $db->all('SELECT id, kind, from_day, to_day, state, windows, pages, rows_read, rows_new, rows_changed, allocated, refreshed_to, error, started_at, finished_at '
                . 'FROM paypal_run WHERE account = ? ORDER BY id DESC LIMIT 5', [$account]);
            $shops = [];
            try {
                foreach (self::accountShops($db, $account) as $sh) {
                    $shops[$sh['code']] = $sh['id'] === null ? 'outside CW: kept, never counted' : (self::feesFrom($db, $sh['code']) ?? 'not switched on');
                }
            } catch (CwException $e) {
                $shops = ['(settings)' => $e->getMessage()];
            }
            $states = $db->all('SELECT kind, count_state, COUNT(*) AS n, SUM(cost_pence) AS cost FROM paypal_txn WHERE account = ? GROUP BY kind, count_state ORDER BY kind, count_state',
                [$account]);
            $money = $db->all("SELECT kind, alloc_dest, COALESCE(match_state, '') AS match_state, COUNT(*) AS n, SUM(alloc_pence) AS pence FROM paypal_txn "
                . 'WHERE account = ? AND alloc_dest IS NOT NULL GROUP BY kind, alloc_dest, match_state ORDER BY kind, alloc_dest, match_state', [$account]);
            $unpaid = $db->one("SELECT COUNT(*) AS n, COALESCE(SUM(t.alloc_pence), 0) AS pence FROM paypal_txn t JOIN sale_order o ON o.channel_id = t.alloc_channel "
                . "AND o.order_ref = t.alloc_order_ref WHERE t.account = ? AND t.alloc_dest = 'order' AND t.alloc_day IS NULL AND o.day IS NULL", [$account]) ?? [];
            $look = $db->all("SELECT day, event_code, status, kind, look_reason, currency, amount_pence, fee_pence FROM paypal_txn WHERE account = ? AND count_state = 'look' "
                . 'ORDER BY day DESC, id DESC LIMIT 50', [$account]);
            $wait = $db->one("SELECT SUM(due = 1) AS due, SUM(count_state = 'pending') AS pending, MIN(IF(count_state = 'pending', day, NULL)) AS oldest_pending "
                . 'FROM paypal_txn WHERE account = ?', [$account]) ?? [];
            $byShop = $db->all("SELECT c.code, t.alloc_dest, COUNT(*) AS n, SUM(t.alloc_pence) AS pence FROM paypal_txn t JOIN channel c ON c.id = t.alloc_channel "
                . 'WHERE t.account = ? AND t.alloc_dest IS NOT NULL GROUP BY c.code, t.alloc_dest ORDER BY c.code, t.alloc_dest', [$account]);
            return ['account' => $account, 'shops' => $shops, 'by_shop' => $byShop, 'runs' => $runs, 'states' => $states, 'money' => $money,
                'unpaid' => ['rows' => (int) ($unpaid['n'] ?? 0), 'pence' => (int) ($unpaid['pence'] ?? 0)], 'look' => $look,
                'due' => (int) ($wait['due'] ?? 0), 'pending' => (int) ($wait['pending'] ?? 0), 'oldest_pending' => $wait['oldest_pending'] ?? null];
        });
    }

    /**
     * The nightly's first day: the re-read days back from today, earlier when a costed row still pending is (at most PENDING_DAYS back),
     * never before the first fee day of the account's shops.
     */
    public function nightlyFrom(string $account): string
    {
        $today = ReportRefresh::ukDay(Clock::db(($this->clock)()));
        $from = (new \DateTimeImmutable($today))->modify('-' . self::rereadDays($this->db) . ' days')->format('Y-m-d');
        $floor = (new \DateTimeImmutable($today))->modify('-' . self::PENDING_DAYS . ' days')->format('Y-m-d');
        $pending = $this->db->value("SELECT MIN(day) FROM paypal_txn WHERE account = ? AND count_state = 'pending' AND day >= ?", [$account, $floor]);
        if ($pending !== null && (string) $pending < $from) {
            $from = (string) $pending;
        }
        $froms = array_filter(array_map(fn (array $s): ?string => self::feesFrom($this->db, $s['code']), self::accountShops($this->db, $account)));
        $start = $froms === [] ? null : min($froms);
        return $start !== null && $start > $from ? min($start, $today) : $from;
    }

    /** Today, UK. */
    public function today(): string
    {
        return ReportRefresh::ukDay(Clock::db(($this->clock)()));
    }

    // ---- the work --------------------------------------------------------------------------------------------------------------------

    /**
     * One window's rows stored in one transaction: a new row inserted (due), a row PayPal says something else of now changed in place (its
     * history before and after, version + 1, due), the same row only marked read by this run. The same transaction twice in one window: once
     * when both say the same, else the window is refused (it moved while it was read).
     *
     * @param list<array<string, mixed>> $rows PaypalRow::parse()d
     * @return array{new: int, changed: int}
     */
    private function store(int $runId, string $account, int $channelId, array $rows, Caller $caller): array
    {
        $byKey = [];
        foreach ($rows as $i => $r) {
            $k = $r['txn_id'] . '|' . $r['event_code'];
            if (isset($byKey[$k]) && $byKey[$k] !== $r) {
                throw new CwException('paypal_moved', 'PayPal gave the same transaction twice with different content in one window (row ' . ($i + 1) . '): nothing of it was '
                    . 'stored; the next run reads it again', 502);
            }
            $byKey[$k] = $r;
        }
        return $this->db->transaction(function (Db $db) use ($runId, $account, $channelId, $byKey, $caller): array {
            $now = Clock::db(($this->clock)());
            $have = [];
            // by the unique key's first two columns (account, txn_id: an index range per id), the event code compared here
            $ids = array_values(array_unique(array_map(static fn (string $k): string => explode('|', $k)[0], array_keys($byKey))));
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ($db->all('SELECT * FROM paypal_txn WHERE account = ? AND txn_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') FOR UPDATE',
                    [$account, ...$chunk]) as $t) {
                    $have[$t['txn_id'] . '|' . $t['event_code']] = $t;
                }
            }
            $new = [];
            $same = [];
            $changed = 0;
            foreach ($byKey as $k => $r) {
                $kind = PaypalCodes::kind((string) $r['event_code'], (int) $r['amount_pence']);
                $r += ['kind' => $kind, 'cost_pence' => PaypalCodes::cost($kind, (int) $r['amount_pence'], (int) $r['fee_pence'])];
                $old = $have[$k] ?? null;
                if ($old === null) {
                    $new[] = $r;
                    continue;
                }
                $before = self::facts($old);
                $after = self::facts($r);
                if ($before === $after) {
                    $same[] = (int) $old['id'];
                    continue;
                }
                $set = [];
                $params = [];
                foreach ([...self::FACTS, 'kind', 'cost_pence'] as $f) {
                    $set[] = "{$f} = ?";
                    $params[] = $r[$f];
                }
                $db->exec('UPDATE paypal_txn SET ' . implode(', ', $set) . ', due = 1, version = version + 1, last_run = ?, changed_at = ? WHERE id = ?',
                    [...$params, $runId, $now, (int) $old['id']]);
                $db->exec("INSERT INTO paypal_txn_history (txn_row, version, what, before_json, after_json, run_id, actor, at) VALUES (?, ?, 'changed', ?, ?, ?, ?, ?)",
                    [(int) $old['id'], (int) $old['version'] + 1, self::json($before), self::json($after), $runId, $caller->actor, $now]);
                $changed++;
            }
            $cols = ['account', 'channel_id', 'txn_id', 'event_code', ...self::FACTS, 'kind', 'cost_pence', 'count_state', 'due', 'first_run', 'last_run', 'read_at', 'changed_at'];
            foreach (array_chunk($new, 200) as $chunk) {
                $params = [];
                foreach ($chunk as $r) {
                    $params[] = $account;
                    $params[] = $channelId;
                    $params[] = $r['txn_id'];
                    $params[] = $r['event_code'];
                    foreach (self::FACTS as $f) {
                        $params[] = $r[$f];
                    }
                    // not counted until decided (due): decide() sets where it counts in the same guarded write as the profit days
                    array_push($params, $r['kind'], $r['cost_pence'], 'not_counted', 1, $runId, $runId, $now, $now);
                }
                $db->exec('INSERT INTO paypal_txn (' . implode(', ', $cols) . ') VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($cols), '?')) . ')')), $params);
            }
            foreach (array_chunk($same, 1000) as $chunk) {
                $db->exec('UPDATE paypal_txn SET last_run = ? WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$runId, ...$chunk]);
            }
            return ['new' => count($new), 'changed' => $changed];
        });
    }

    /**
     * Where these rows count (PPF5-PPF7), decided and written in ONE guarded write with the profit days (DeliveryBills::guardedWrite): the
     * rows as they count now are the plan's "before", as they will count its "after"; the orders they are on (by number, the shop's own
     * orders) are looked up in the plan and again right before the commit (verify: an order of the number stored meanwhile means the plan is
     * old: made again). Every row's due mark is cleared in the same write.
     *
     * @param list<int> $ids
     * @return int rows whose place changed
     */
    private function decide(Caller $caller, array $ids, ?int $runId): int
    {
        $bills = new DeliveryBills($this->db);
        return $bills->guardedWrite(function (Db $db) use ($ids, $runId, $caller): array {
            $rows = $db->all('SELECT * FROM paypal_txn WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE', $ids);
            $accounts = array_values(array_unique(array_column($rows, 'account')));
            if (count($accounts) !== 1) {
                throw new \LogicException('one PayPal account a decision');
            }
            // the account's shops and their forms, read once a plan (checklist 13); each row's shop by its invoice's form, then the orders of
            // that shop with its number; each CW shop's first fee day
            $shops = self::accountShops($db, (string) $accounts[0]);
            $from = [];
            foreach ($shops as $sh) {
                if ($sh['id'] !== null) {
                    $from[$sh['id']] = self::feesFrom($db, $sh['code']);
                }
            }
            $forms = [];
            $keys = [];
            foreach ($rows as $r) {
                $forms[(int) $r['id']] = $f = self::form($r, $shops);
                if ($f['id'] !== null && $f['no'] !== null) {
                    $keys[] = [$f['id'], $f['no']];
                }
            }
            $lookup = self::orders($db, $keys);
            $before = [];
            $after = [];
            $watch = [];
            $plans = [];
            foreach ($rows as $r) {
                $place = self::place($r, $forms[(int) $r['id']], $shops[0]['id'], $from, $lookup);
                $plans[(int) $r['id']] = [$r, $place];
                // its money as it counts now (before) and as it will (after); an order whose money counts on a day of its own is guarded too
                // (watch: its row is what the write's foreign key reads, so no snapshot holds it meanwhile)
                foreach (['before' => $r, 'after' => $place + $r] as $side => $x) {
                    $f = self::fact($x);
                    if ($f === null) {
                        continue;
                    }
                    if ($side === 'before') {
                        $before[] = $f;
                    } else {
                        $after[] = $f;
                    }
                    if ($f['state'] !== 'matched' && $x['alloc_dest'] === 'order') {
                        $watch[] = [(int) $x['alloc_channel'], (string) $x['alloc_order_ref']];
                    }
                }
            }
            return ['before' => $before, 'after' => $after, 'watch' => $watch,
                // the orders of the numbers read again last, right before the commit: one stored meanwhile (a second of a number, or the first)
                // makes the plan old (checklist 11: a match is exactly one order, re-checked under the write's guards)
                'verify' => static fn (Db $db): bool => self::orders($db, $keys) === $lookup,
                'write' => function (Db $db) use ($plans, $runId, $caller): int {
                    // a few statements whatever the chunk (the guards are held: the hold budget at full size, PPF15): the changed rows in ONE
                    // update (a CASE per column), their history in ONE insert, the others' due marks cleared in one more
                    $now = Clock::db(($this->clock)());
                    $changed = [];
                    $keep = [];
                    foreach ($plans as $id => [$r, $place]) {
                        $old = self::norm(array_intersect_key($r, array_flip(self::PLACE)));
                        $new = self::norm($place);
                        if ($old === $new) {
                            $keep[] = $id;
                        } else {
                            $changed[$id] = [(int) $r['version'] + 1, $old, $new];
                        }
                    }
                    if ($changed !== []) {
                        $ids = array_keys($changed);
                        $set = [];
                        $params = [];
                        foreach (self::PLACE as $f) {
                            $set[] = "{$f} = CASE id" . str_repeat(' WHEN ? THEN ?', count($ids)) . ' END';
                            foreach ($changed as $id => [, , $new]) {
                                array_push($params, $id, $new[$f]);
                            }
                        }
                        $n = $db->exec('UPDATE paypal_txn SET ' . implode(', ', $set) . ', due = 0, version = version + 1, changed_at = ? WHERE id IN ('
                            . implode(', ', array_fill(0, count($ids), '?')) . ')', [...$params, $now, ...$ids]);
                        if ($n !== count($ids)) {
                            throw new \LogicException("PayPal rows decided: {$n} of " . count($ids) . ' changed');
                        }
                        $params = [];
                        foreach ($changed as $id => [$version, $old, $new]) {
                            array_push($params, $id, $version, self::json($old), self::json($new), $runId, $caller->actor, $now);
                        }
                        $db->exec("INSERT INTO paypal_txn_history (txn_row, version, what, before_json, after_json, run_id, actor, at) VALUES "
                            . implode(', ', array_fill(0, count($changed), "(?, ?, 'allocated', ?, ?, ?, ?, ?)")), $params);
                    }
                    foreach (array_chunk($keep, 1000) as $chunk) {
                        $db->exec('UPDATE paypal_txn SET due = 0 WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk);
                    }
                    $changed = count($changed);
                    return $changed;
                }];
        });
    }

    /**
     * The shop a row belongs to by its invoice (else its custom field): the ONE shop of the account whose order-number form it matches, with
     * the number; none, or two shops' forms: unrecognised (code null: the account's main shop's "not on an order" line, PPF21).
     *
     * @param array<string, mixed> $r
     * @param list<array{id: ?int, code: string, name: string, form: string}> $shops
     * @return array{code: ?string, id: ?int, no: ?string}
     */
    private static function form(array $r, array $shops): array
    {
        foreach (['invoice_ref', 'custom_ref'] as $f) {
            if ($r[$f] === null) {
                continue;
            }
            $hits = [];
            foreach ($shops as $sh) {
                if (preg_match($sh['form'], (string) $r[$f], $m) === 1 && preg_match('/^[0-9]{1,12}$/D', $m[1] ?? '') === 1) {
                    $hits[] = ['code' => $sh['code'], 'id' => $sh['id'], 'no' => $m[1]];
                }
            }
            if (count($hits) === 1) {
                return $hits[0];
            }
            if ($hits !== []) {
                break; // two shops' forms: never guessed
            }
        }
        return ['code' => null, 'id' => null, 'no' => null];
    }

    /**
     * Where one row counts (PPF5-PPF7, PPF21), in this order: not understood (an unknown code, a fee on an ignored row, another currency, a wrong
     * sign, an unknown status: look, never counted); ignored (not counted); denied (not counted). Then its shop, by its invoice's form: a shop
     * CW does not hold (Hayati Vapes) is outside (kept, never counted); no shop's form: the account's main shop. Before that shop's first fee
     * day (or that shop not switched on: Electrofag until its day): before_start, never on another shop's lines; pending: read again later;
     * else it counts: a cost of 0 nowhere, else on its order (exactly one order of its shop has its number: a sale's fee on the order's paid
     * day, the others on their own day) or on its shop's "not on an order" line (a number of no order, or of two).
     *
     * @param array<string, mixed> $r a paypal_txn row
     * @param array{code: ?string, id: ?int, no: ?string} $form form()
     * @param array<int, ?string> $from the account's CW shops => their first fee day
     * @param array<string, list<string>> $lookup "channel|number" => the refs of that shop's orders with it
     * @return array<string, mixed> the PLACE columns
     */
    private static function place(array $r, array $form, int $main, array $from, array $lookup): array
    {
        $none = ['look_reason' => null, 'form_shop' => null, 'order_no' => null, 'match_state' => null, 'candidates' => 0, 'shop_channel' => null, 'alloc_dest' => null,
            'alloc_channel' => null, 'alloc_order_ref' => null, 'alloc_day' => null, 'alloc_pence' => 0];
        $kind = (string) $r['kind'];
        $status = (string) $r['status'];
        $look = match (true) {
            $kind === 'unknown' => 'unknown_code',
            $kind === 'ignore' => (int) $r['fee_pence'] !== 0 ? 'fee_on_ignored' : null,
            $r['currency'] !== 'GBP' || ($r['fee_currency'] !== null && $r['fee_currency'] !== 'GBP') => 'currency',
            !PaypalCodes::signOk($kind, (int) $r['amount_pence']) => 'sign',
            !in_array($status, ['S', 'V', 'P', 'D'], true) => 'status',
            default => null,
        };
        $named = ['form_shop' => $form['code'], 'order_no' => $form['no']] + $none;
        if ($look !== null) {
            return ['count_state' => 'look', 'look_reason' => $look] + $named;
        }
        if ($kind === 'ignore' || $status === 'D') {
            return ['count_state' => 'not_counted'] + $named;
        }
        if ($form['code'] !== null && $form['id'] === null) {
            return ['count_state' => 'outside'] + $named; // a shop CW does not hold (Hayati Vapes): kept, never counted, never on another shop's line
        }
        $ch = $form['id'] ?? $main;
        $shop = ['shop_channel' => $ch] + $named;
        $start = $from[$ch] ?? null;
        if ($start === null || (string) $r['day'] < $start) {
            return ['count_state' => 'before_start'] + $shop;
        }
        if ($status === 'P') {
            return ['count_state' => 'pending'] + $shop;
        }
        $hits = $form['no'] === null ? [] : ($lookup[$ch . '|' . $form['no']] ?? []);
        $match = $form['no'] === null ? 'no_number' : match (count($hits)) {
            0 => 'no_order',
            1 => 'order',
            default => 'ambiguous',
        };
        $place = ['count_state' => 'counts', 'match_state' => $match, 'candidates' => $match === 'ambiguous' ? min(255, count($hits)) : 0] + $shop;
        $cost = (int) $r['cost_pence'];
        if ($cost === 0) {
            return $place;
        }
        if ($match === 'order') {
            // a sale's fee on the order's paid day (owner rule 1); a refund's fee back, a chargeback's money and fees on their own day (rules 2, 3)
            return ['alloc_dest' => 'order', 'alloc_channel' => $ch, 'alloc_order_ref' => $hits[0], 'alloc_day' => $kind === 'sale' ? null : (string) $r['day'],
                'alloc_pence' => $cost] + $place;
        }
        // no order of its shop (or no shop's form: the main shop): that shop's "not on an order" line, on its own day (owner rule 4)
        return ['alloc_dest' => 'shop', 'alloc_channel' => $ch, 'alloc_order_ref' => null, 'alloc_day' => (string) $r['day'], 'alloc_pence' => $cost] + $place;
    }

    /**
     * The orders of each (shop, number), by "channel|number": their refs sorted (the plan's lookup and the verify's).
     *
     * @param list<array{0: int, 1: string}> $keys
     * @return array<string, list<string>>
     */
    private static function orders(Db $db, array $keys): array
    {
        $by = [];
        foreach ($keys as [$ch, $no]) {
            $by[$ch][$no] = true;
        }
        ksort($by);
        $out = [];
        foreach ($by as $ch => $nos) {
            foreach (array_chunk(array_map('strval', array_keys($nos)), 500) as $chunk) {
                foreach ($db->all('SELECT order_no, order_ref FROM sale_order WHERE channel_id = ? AND order_no IN (' . implode(', ', array_fill(0, count($chunk), '?'))
                    . ') ORDER BY order_no, order_ref', [$ch, ...$chunk]) as $o) {
                    $out[$ch . '|' . $o['order_no']][] = (string) $o['order_ref'];
                }
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * A row's place as a fact of DeliveryBills::guardedWrite: a sale's fee on an order counts on the order's paid day ('matched', field
     * fee_cost); money on an order on a day of its own, and money on no order, count on that day ('not_matched' in the shop, fields
     * fee_cost and fee_other). Null: it counts nowhere.
     *
     * @param array<string, mixed> $x the PLACE columns (and the row)
     * @return array{live: bool, state: string, ch: ?int, ref: ?string, inv_ch: int, date: string, cost: int, field: string}|null
     */
    private static function fact(array $x): ?array
    {
        if ($x['alloc_dest'] === null || (int) $x['alloc_pence'] === 0) {
            return null;
        }
        $ch = (int) $x['alloc_channel'];
        if ($x['alloc_dest'] === 'order' && $x['alloc_day'] === null) {
            return ['live' => true, 'state' => 'matched', 'ch' => $ch, 'ref' => (string) $x['alloc_order_ref'], 'inv_ch' => $ch, 'date' => (string) $x['day'],
                'cost' => (int) $x['alloc_pence'], 'field' => 'fee_cost'];
        }
        return ['live' => true, 'state' => 'not_matched', 'ch' => null, 'ref' => null, 'inv_ch' => $ch, 'date' => (string) $x['alloc_day'], 'cost' => (int) $x['alloc_pence'],
            'field' => $x['alloc_dest'] === 'order' ? 'fee_cost' : 'fee_other'];
    }

    /** @return array<string, mixed> the PLACE columns in one shape (ints as ints) */
    private static function norm(array $p): array
    {
        $out = [];
        foreach (self::PLACE as $f) {
            $v = $p[$f] ?? null;
            $out[$f] = in_array($f, ['candidates', 'shop_channel', 'alloc_channel', 'alloc_pence'], true) && $v !== null ? (int) $v : ($v === null ? null : (string) $v);
        }
        return $out;
    }

    /** @return array<string, mixed> what PayPal says of a row, in one shape */
    private static function facts(array $r): array
    {
        $out = [];
        foreach (self::FACTS as $f) {
            $v = $r[$f] ?? null;
            $out[$f] = in_array($f, ['amount_pence', 'fee_pence'], true) ? (int) $v : ($v === null ? null : (string) $v);
        }
        return $out;
    }

    private static function json(array $a): string
    {
        return json_encode($a, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The windows of the UK days $from..$to: WINDOW_DAYS days each, from the UK midnight of the first to the second before the UK midnight after
     * the last (the last never after now).
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public static function windows(string $from, string $to, \DateTimeImmutable $now): array
    {
        $out = [];
        for ($d = $from; $d <= $to; ) {
            $last = (new \DateTimeImmutable($d))->modify('+' . (self::WINDOW_DAYS - 1) . ' days')->format('Y-m-d');
            $last = min($last, $to);
            $a = ReportRefresh::bounds($d)[0];
            $b = ReportRefresh::bounds($last)[1]->modify('-1 second');
            if ($a > $now) {
                break;
            }
            $out[] = [$a, $b > $now ? $now : $b];
            $d = ReportRefresh::nextDay($last);
        }
        return $out;
    }

    private function progress(int $runId, array $sum): void
    {
        $this->db->exec('UPDATE paypal_run SET windows = ?, pages = ?, rows_read = ?, rows_new = ?, rows_changed = ?, refreshed_to = ? WHERE id = ?',
            [$sum['windows'], $sum['pages'], $sum['read'], $sum['new'], $sum['changed'], self::refreshed($sum['refreshed']), $runId]);
    }

    private function finish(int $runId, array $sum, string $state, ?string $error): void
    {
        $this->db->exec('UPDATE paypal_run SET state = ?, windows = ?, pages = ?, rows_read = ?, rows_new = ?, rows_changed = ?, allocated = ?, refreshed_to = ?, error = ?, '
            . 'finished_at = ? WHERE id = ?', [$state, $sum['windows'], $sum['pages'], $sum['read'], $sum['new'], $sum['changed'], $sum['allocated'],
            self::refreshed($sum['refreshed']), $error, Clock::db(($this->clock)()), $runId]);
    }

    private static function refreshed(?string $t): ?string
    {
        if ($t === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(Z|[+-]\d{2}:?\d{2})$/D', $t) !== 1) {
            return null;
        }
        return Clock::db((new \DateTimeImmutable($t))->setTimezone(Clock::utc()));
    }

    private static function isDay(string $d): bool
    {
        return preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/D', $d, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Only a server job (bin/paypal_fees.php, the tests): no person and no store writes PayPal's rows (PPF12). */
    private static function authorise(Caller $caller): void
    {
        if ($caller->staffUserId !== null || !str_starts_with($caller->actor, 'system:')) {
            throw new CwException('staff_cannot', 'PayPal\'s rows are written by the nightly job only', 403);
        }
    }

    /**
     * $fn under the account's PayPal lock (one run at a time), at most LOCK_WAIT_SEC, then 503 busy.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function locked(string $account, \Closure $fn): mixed
    {
        $name = 'cw_paypal:' . $account . ':' . (string) $this->db->value('SELECT DATABASE()');
        $name = strlen($name) <= 64 ? $name : 'cw_paypal:' . sha1($name);
        if ((int) $this->db->value('SELECT GET_LOCK(?, ?)', [$name, self::LOCK_WAIT_SEC]) !== 1) {
            throw new CwException('busy', 'another PayPal run of this account holds its lock: nothing was read; try again later', 503);
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
}
