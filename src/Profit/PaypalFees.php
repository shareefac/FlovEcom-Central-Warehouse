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
 * PayPal's payment fees in CW's profit (CW-1172; docs/decisions.md PPF1-PPF40), read from PayPal's own API every night (bin/paypal_fees.php).
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
    /**
     * PayPal's data older than this many hours when CW read it (its last_refreshed_datetime against the run's own time; PayPal says up to 3 hours
     * is usual): the stale alert (final review c3: measured at the read, never against the clock between two nightlies).
     */
    public const STALE_LAG_HOURS = 12;
    /** A pending row is read again for at most this many days back (then it is said in --status and stays pending). */
    public const PENDING_DAYS = 31;
    public const DEFAULT_REREAD_DAYS = 7;
    public const DEFAULT_ALARM_PENCE = 5000;
    /** PayPal's statuses: S done, V reversed (it happened; the reversal is a row of its own), P pending, D denied (PPF5). */
    public const STATUS_COUNTS = ['S', 'V'];
    /** What PayPal says of a row, compared on a re-read (a change of one of them is a change of the row, PPF2). */
    private const PP_FACTS = ['ref_txn_id', 'ref_type', 'status', 'initiated_at', 'pp_updated_at', 'day', 'currency', 'amount_pence', 'fee_currency', 'fee_pence'];
    /** What a shop's form captured of its invoice and custom field (only the order number and whose: never the text, PPF36). */
    private const CAPTURES = ['invoice_state', 'invoice_shop', 'invoice_no', 'custom_state', 'custom_shop', 'custom_no'];
    private const FACTS = [...self::PP_FACTS, ...self::CAPTURES];
    /** A change of one of these in a row's place re-decides the rows that refer to it (its refunds, chargebacks, fees: final review a5). */
    private const IDENTITY = ['count_state', 'look_reason', 'form_shop', 'order_no', 'match_state', 'match_order_ref', 'shop_channel'];
    /** Where a row counts (decide()'s outcome). */
    private const PLACE = ['count_state', 'look_reason', 'form_shop', 'order_no', 'match_state', 'matched_by', 'match_order_ref', 'candidates', 'shop_channel',
        'refund_offset_pence', 'refund_seen_pence', 'decided_sig', 'alloc_dest', 'alloc_channel', 'alloc_order_ref', 'alloc_day', 'alloc_pence'];
    /** The kinds whose shop and order follow PayPal's reference to their sale (fix round 1, the lead's decision 3). */
    private const REFERRING = ['refund', 'chargeback', 'fee'];
    private const RETRY = 'paypal_retry';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
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
     * The main shop is PINNED (profit.paypal_main_<account>): a list that does not start with it is refused (paypal_bad_shops) before anything
     * is read or decided, so reordering the list never moves money to another shop's line (final review a8, PPF35).
     *
     * @return list<array{id: ?int, code: string, name: string, form: string}>
     */
    public static function accountShops(Db $db, string $account): array
    {
        $codes = self::text($db, 'profit.paypal_shops_' . $account);
        if ($codes === null || $codes === '') {
            return [];
        }
        $main = self::text($db, 'profit.paypal_main_' . $account);
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
        if ($main === null || $main === '' || $out[0]['code'] !== $main) {
            throw new CwException('paypal_bad_shops', "profit.paypal_shops_{$account} must start with the account's pinned main shop (profit.paypal_main_{$account}"
                . ($main === null || $main === '' ? ': not set' : ": {$main}") . '): nothing read or moved', 422);
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
        if (!is_string($d) || preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,2}))?$/D', $d, $m) !== 1) { // Settings refuses anything else (rule pence)
            return self::DEFAULT_ALARM_PENCE;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    // ---- the run ---------------------------------------------------------------------------------------------------------------------

    /**
     * One run (bin/paypal_fees.php): the UK days $fromDay..$toDay read from PayPal window by window and stored, then every due row decided.
     * A window that cannot be read whole stores nothing of itself and ends the run as failed (the windows before it stay stored, their rows
     * due until decided); a decision that cannot be made now (an order busy) leaves its rows due for the next run.
     * Before anything is read, at EVERY run (final review b1, PPF39): the account's shops (its main shop pinned), the key's shop = the main shop,
     * the key's merchant = CW's own setting of it (profit.paypal_merchant_<account>), and the live host when the account needs it
     * (profit.paypal_live_<account>); a refusal is recorded as a failed run, so the health alert says it at once (b2).
     * Each window stored proves the days it read only as far as PayPal says its data is complete (its last_refreshed_datetime): the run's
     * covered_to moves on window by window (kept when a later window fails), never past a day PayPal had not finished (final review c1, c2).
     *
     * @return array{run: int, windows: int, pages: int, read: int, new: int, changed: int, allocated: int, refreshed: ?string, covered: ?string, seconds: array<string, float>}
     */
    public function run(Caller $caller, PaypalApi $api, string $fromDay, string $toDay, string $kind, ?float $deadline = null): array
    {
        self::authorise($caller);
        if (!in_array($kind, ['nightly', 'backfill'], true) || !self::isDay($fromDay) || !self::isDay($toDay) || $fromDay > $toDay) {
            throw new CwException('paypal_bad_days', 'a run reads whole UK days, the first not after the last', 422);
        }
        $account = $api->account();
        try {
            $shops = self::accountShops($this->db, $account);
            if ($shops === []) {
                throw new CwException('paypal_no_shops', "the PayPal account {$account} serves no shop CW has (Settings › Profit: profit.paypal_shops_{$account}): nothing read", 422);
            }
            // the key file names the account's main shop: one for another shop is refused (a key placed for the wrong account; PPF11)
            if ($shops[0]['code'] !== $api->shop()) {
                throw new CwException('paypal_key_refused', "the PayPal key of {$account} is for {$api->shop()}, the account's main shop is {$shops[0]['code']}: nothing read", 403);
            }
            // the merchant and the host, held by CW apart from the key (a sandbox key or another merchant's, placed by mistake: never read)
            $merchant = self::text($this->db, 'profit.paypal_merchant_' . $account);
            if ($merchant === null || $merchant === '') {
                throw new CwException('paypal_key_refused', "CW holds no merchant of the PayPal account {$account} (profit.paypal_merchant_{$account}): nothing read", 403);
            }
            if (!hash_equals($merchant, $api->merchant())) {
                throw new CwException('paypal_key_refused', "the PayPal key of {$account} is not of the merchant CW holds for it (profit.paypal_merchant_{$account}): nothing read", 403);
            }
            if (Settings::flag($this->db, 'profit.paypal_live_' . $account, true) && !$api->live()) {
                throw new CwException('paypal_key_refused', "the PayPal key of {$account} is not for PayPal's live host, which the account needs (profit.paypal_live_{$account}): nothing read", 403);
            }
        } catch (CwException $e) {
            $this->refused($account, $e->getMessage());
            throw $e;
        }
        $shop = $shops[0];
        return $this->locked($account, function () use ($caller, $api, $fromDay, $toDay, $kind, $shop, $shops, $account, $deadline): array {
            $now = Clock::db(($this->clock)());
            // a run of this account that died (no other one can be running: this one holds the account's lock) is said to have failed; its
            // rows stay due and this run decides them
            $this->db->exec("UPDATE paypal_run SET state = 'failed', error = 'did not finish (the next run took over)', finished_at = ? WHERE account = ? AND state = 'running'",
                [$now, $account]);
            $runId = $this->db->insert('INSERT INTO paypal_run (account, channel_id, kind, from_day, to_day, actor, started_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$account, $shop['id'], $kind, $fromDay, $toDay, $caller->actor, $now]);
            $sum = ['run' => $runId, 'windows' => 0, 'pages' => 0, 'read' => 0, 'new' => 0, 'changed' => 0, 'allocated' => 0, 'refreshed' => null, 'covered' => null,
                'seconds' => ['paypal' => 0.0, 'parse' => 0.0, 'store' => 0.0, 'decide' => 0.0]];
            $covering = true;
            try {
                foreach (self::windows($fromDay, $toDay, ($this->clock)()) as [$a, $b]) {
                    self::inTime($deadline, 'the next window');
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
                    $first = ReportRefresh::ukDay(Clock::db($a));
                    $last = ReportRefresh::ukDay(Clock::db($b));
                    $s = $this->store($runId, $account, $shop['id'], $rows, $caller, $shops, $first, $last);
                    $sum['seconds']['store'] += microtime(true) - $t;
                    $sum['windows']++;
                    $sum['pages'] += $w['pages'];
                    $sum['read'] += count($rows);
                    $sum['new'] += $s['new'];
                    $sum['changed'] += $s['changed'];
                    $sum['refreshed'] = $w['refreshed'] ?? $sum['refreshed'];
                    // the days this window PROVES read: up to the last whole UK day before PayPal's refresh time (none when it gave none); the
                    // run's coverage goes on only while each window proved all of its days (a gap stops it: the next run reads from there)
                    $proven = self::proven($last, $w['refreshed']);
                    if ($covering) {
                        if ($proven !== null && $proven >= $first) {
                            $sum['covered'] = $proven;
                        }
                        $covering = $proven !== null && $proven >= $last;
                    }
                    $this->progress($runId, $sum);
                }
                $t = microtime(true);
                $sum['allocated'] = $this->decideAll($caller, $account, $runId, $deadline);
                $sum['seconds']['decide'] = microtime(true) - $t;
                $sum['seconds']['rematch'] = $this->lastRematchSec;
                $sum['seconds'] = array_map(static fn (float $x): float => round($x, 2), $sum['seconds']);
            } catch (CwException | \PDOException $e) {
                $why = $e instanceof CwException ? $e->getMessage() : 'database error ' . (Db::driverCode($e) ?? 'unknown');
                $this->finish($runId, $sum, 'failed', mb_substr($why, 0, 255));
                throw $e;
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
    public function decideAll(Caller $caller, string $account, ?int $runId, ?float $deadline = null): int
    {
        self::authorise($caller);
        // the account's shops checked first (its main shop pinned): refused before any money moves (final review a8)
        self::accountShops($this->db, $account);
        $changed = 0;
        $this->lastRematchSec = 0.0;
        // a few rounds at most: a row decided now (a sale read now) may be what a refund or a chargeback refers to, decided again at once (fix
        // round 1); a row whose place changes marks its children due in the same write (a5), decided in the same loop
        $seen = [];
        for ($round = 1; $round <= 4; $round++) {
            $t = microtime(true);
            $marked = $this->rematch($account);
            $this->lastRematchSec += microtime(true) - $t;
            if ($round > 1 && $marked === 0) {
                break;
            }
            while (true) {
                $ids = array_map('intval', $this->db->column('SELECT id FROM paypal_txn WHERE due = 1 AND account = ? ORDER BY id LIMIT ' . self::CHUNK, [$account]));
                if ($ids === []) {
                    break;
                }
                foreach ($ids as $id) {
                    // a child is decided again when its parent moves (fee -> chargeback -> sale: three at most); more is a loop, never money
                    $seen[$id] = ($seen[$id] ?? 0) + 1;
                    if ($seen[$id] > 8) {
                        throw new \LogicException("PayPal row {$id} stayed due after its decision");
                    }
                }
                self::inTime($deadline, 'the next decisions (the rows left stay due)');
                $t = microtime(true);
                $changed += $this->decide($caller, $ids, $runId);
                if ($this->onChunk !== null) {
                    ($this->onChunk)(count($ids), microtime(true) - $t);
                }
            }
        }
        return $changed;
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
     * Rows decided before whose decision may be another now, marked due (decided next; due_since = now):
     *  - a number no CW order of its shop had (or two had) that one has now; a row on an order whose number changed, or that has a second order
     *    of its number now (a renumbered or doubled order; also a second order stored right after the guarded write's last look: fix round 1);
     *  - a row decided by other shops or forms than now (its decided_sig: every decision keeps the forms it was made by, --decide too);
     *  - a refund, chargeback or fee not matched by its reference whose referred row (its sale) is read now;
     *  - a chargeback on an order whose refund budget changed since (the refunds CW counts in profit, less PayPal's own refunds of the order:
     *    the order paid or not, a re-send, a refund, a PayPal refund read: PPF23, PPF34);
     *  - rows a shop's first fee day now puts on the other side (the setting changed).
     * Plain indexed reads, then marks by id (MySQL planned an UPDATE with a correlated EXISTS row by row: 12 s at the real size; PPF15).
     */
    public function rematch(string $account): int
    {
        $t0 = microtime(true);
        $shops = self::accountShops($this->db, $account);
        $sig = self::formsSig($shops);
        $ids = [];
        // the numbers: rows on no order or two, and rows on an order
        $rows = $this->db->all("SELECT id, shop_channel, order_no, match_state, match_order_ref, candidates FROM paypal_txn WHERE account = ? AND due = 0 AND order_no IS NOT NULL "
            . "AND shop_channel IS NOT NULL AND match_state IN ('no_order', 'ambiguous', 'order') ORDER BY id", [$account]);
        $found = self::orders($this->db, array_map(static fn (array $r): array => [(int) $r['shop_channel'], (string) $r['order_no']], $rows));
        foreach ($rows as $r) {
            $hits = $found[(int) $r['shop_channel'] . '|' . $r['order_no']] ?? [];
            if (match ($r['match_state']) {
                'order' => $hits !== [(string) $r['match_order_ref']],
                'ambiguous' => count($hits) !== (int) $r['candidates'],
                default => $hits !== [],
            }) {
                $ids[] = (int) $r['id'];
            }
        }
        $this->rematchParts = ['orders' => round(microtime(true) - $t0, 3)];
        $t0 = microtime(true);
        // decided by other shops or forms (a NULL: never decided, due anyway)
        array_push($ids, ...array_map('intval', $this->db->column('SELECT id FROM paypal_txn WHERE account = ? AND due = 0 AND decided_sig IS NOT NULL AND decided_sig <> ?',
            [$account, $sig])));
        // the referred row read since
        array_push($ids, ...array_map('intval', $this->db->column("SELECT t.id FROM paypal_txn t JOIN paypal_txn p ON p.account = t.account AND p.txn_id = t.ref_txn_id "
            . "WHERE t.account = ? AND t.due = 0 AND t.kind IN ('refund', 'chargeback', 'fee') AND (t.matched_by IS NULL OR t.matched_by <> 'reference') "
            . "AND t.count_state <> 'look' "
            . "AND p.kind IN ('sale', 'chargeback') AND p.form_shop IS NOT NULL", [$account])));
        // a chargeback's order's refund budget changed (a few rows: chargebacks are rare; the budget worked out as decide() does)
        foreach ($this->db->all("SELECT id, shop_channel, order_no, match_order_ref, refund_seen_pence FROM paypal_txn WHERE account = ? AND due = 0 "
            . "AND kind = 'chargeback' AND refund_seen_pence IS NOT NULL ORDER BY id", [$account]) as $r) {
            $budget = max(0, self::cwRefunds($this->db, (int) $r['shop_channel'], (string) $r['match_order_ref'])
                - self::paypalRefunds($this->db, $account, (int) $r['shop_channel'], (string) $r['order_no']));
            if ($budget !== (int) $r['refund_seen_pence']) {
                $ids[] = (int) $r['id'];
            }
        }
        $this->rematchParts['decided'] = round(microtime(true) - $t0, 3);
        $t0 = microtime(true);
        foreach ($this->db->all('SELECT DISTINCT t.shop_channel, c.code FROM paypal_txn t JOIN channel c ON c.id = t.shop_channel WHERE t.account = ?', [$account]) as $r) {
            $from = self::feesFrom($this->db, (string) $r['code']);
            array_push($ids, ...array_map('intval', $from === null
                ? $this->db->column("SELECT id FROM paypal_txn WHERE account = ? AND shop_channel = ? AND due = 0 AND count_state IN ('counts', 'pending')",
                    [$account, (int) $r['shop_channel']])
                : $this->db->column("SELECT id FROM paypal_txn WHERE account = ? AND shop_channel = ? AND due = 0 AND ((count_state = 'before_start' AND day >= ?) "
                    . "OR (count_state IN ('counts', 'pending') AND day < ?))", [$account, (int) $r['shop_channel'], $from, $from])));
        }
        $this->rematchParts['switch'] = round(microtime(true) - $t0, 3);
        return $this->markDue(array_values(array_unique($ids)));
    }

    /** Marks these rows due now (due_since: now; the ones due already keep theirs). @param list<int> $ids */
    private function markDue(array $ids): int
    {
        $n = 0;
        $now = Clock::db(($this->clock)());
        foreach (array_chunk($ids, 1000) as $chunk) {
            $n += $this->db->exec('UPDATE paypal_txn SET due = 1, due_since = ? WHERE due = 0 AND id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$now, ...$chunk]);
        }
        return $n;
    }

    /** Refused when the run's deadline passed (the job's overall deadline, below the installer's drain: fix round 1). */
    private static function inTime(?float $deadline, string $what): void
    {
        if ($deadline !== null && microtime(true) >= $deadline) {
            throw new CwException('paypal_deadline', "the run's time ran out before {$what}: what was stored stays (its rows due); the next run goes on", 504);
        }
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
            // proven read without a gap to the day before yesterday at least (the nightly at 05:20 proves yesterday once PayPal's data of it is
            // complete; a run after a long outage that skipped days, or one of stale data, never clears it: fix round 1, final review c1, c3)
            $covered = self::coveredUntil($db, $account, $from);
            $due = (new \DateTimeImmutable($today))->modify('-2 days')->format('Y-m-d');
            if ($hours !== null && $hours < self::STALE_HOURS && ($covered === null || $covered < $due)) {
                $hours = self::STALE_HOURS; // said as not read: a gap of days is not read
            }
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
            // PayPal's own data old WHEN CW READ IT (an answer of 200 with an old last_refreshed_datetime, or none: its completeness unknown), by
            // the newest ok run: measured at the read, so a healthy nightly is never stale the next morning (fix round 1, final review c3)
            $fresh = $db->one("SELECT refreshed_to, finished_at FROM paypal_run WHERE account = ? AND state = 'ok' ORDER BY id DESC LIMIT 1", [$account]);
            if ($fresh !== null && ($fresh['refreshed_to'] === null
                || Clock::fromDb((string) $fresh['finished_at'])->getTimestamp() - Clock::fromDb((string) $fresh['refreshed_to'])->getTimestamp() >= self::STALE_LAG_HOURS * 3600)) {
                $to = $fresh['refreshed_to'] === null ? null : (string) Clock::iso((string) $fresh['refreshed_to']);
                $out[] = ['key' => 'paypal_stale:' . $account, 'kind' => 'paypal_stale', 'detail' => $to === null
                    ? sprintf('%s: PayPal did not say how complete its data was at the last read: those days are not proven read', $main['name'])
                    : sprintf('%s: PayPal said its data was complete only up to %s when CW last read it', $main['name'], $to),
                    'data' => ['account' => $account, 'to' => $to]] + $base;
            }
            // the "not on an order" money, per shop and day (each shop's own line: fix round 1)
            $alarm = self::alarmPence($db);
            $since = (new \DateTimeImmutable($today))->modify('-' . self::rereadDays($db) . ' days')->format('Y-m-d');
            $over = $db->all("SELECT t.alloc_channel, c.code, c.name, t.alloc_day, SUM(t.alloc_pence) AS p FROM paypal_txn t JOIN channel c ON c.id = t.alloc_channel "
                . "WHERE t.account = ? AND t.alloc_dest = 'shop' AND t.alloc_day >= ? AND t.alloc_day >= ? GROUP BY t.alloc_channel, c.code, c.name, t.alloc_day HAVING p > ? "
                . 'ORDER BY t.alloc_channel, t.alloc_day', [$account, $since, $from, $alarm]);
            $byShop = [];
            foreach ($over as $o) {
                $byShop[(int) $o['alloc_channel']][] = $o;
            }
            foreach ($byShop as $ch => $list) {
                $top = $list[count($list) - 1];
                $out[] = ['key' => 'paypal_not_on_order:' . $top['code'], 'kind' => 'paypal_not_on_order', 'channel' => (string) $top['code'], 'channel_id' => $ch,
                    'name' => (string) $top['name'], 'mode' => null, 'detail' => sprintf('%s: %d day(s) with more than %s of PayPal money on no CW order (newest %s: %s)',
                    $top['name'], count($list), ProfitViews::gbp($alarm), (string) $top['alloc_day'], ProfitViews::gbp((int) $top['p'])),
                    'data' => ['account' => $account, 'days' => count($list), 'limit' => ProfitViews::gbp($alarm), 'day' => (string) $top['alloc_day'],
                        'pence' => ProfitViews::gbp((int) $top['p'])]];
            }
        }
        return $out;
    }

    /**
     * What bin/paypal_fees.php --status says of an account (read only, one moment of the database): its newest runs, its rows by kind and
     * state, the money counted on orders and not on an order since the shop's first fee day, what waits (due, pending, on orders not paid in
     * CW) and the rows that need a look (all counted: look_total; the newest 50 shown, each with CW's own row id; never anything personal).
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
            $look = $db->all("SELECT id, day, event_code, status, kind, look_reason, currency, amount_pence, fee_pence FROM paypal_txn WHERE account = ? AND count_state = 'look' "
                . 'ORDER BY day DESC, id DESC LIMIT 50', [$account]);
            $lookTotal = (int) $db->value("SELECT COUNT(*) FROM paypal_txn WHERE account = ? AND count_state = 'look'", [$account]);
            $wait = $db->one("SELECT SUM(due = 1) AS due, SUM(count_state = 'pending') AS pending, MIN(IF(count_state = 'pending', day, NULL)) AS oldest_pending "
                . 'FROM paypal_txn WHERE account = ?', [$account]) ?? [];
            $byShop = $db->all("SELECT c.code, t.alloc_dest, COUNT(*) AS n, SUM(t.alloc_pence) AS pence FROM paypal_txn t JOIN channel c ON c.id = t.alloc_channel "
                . 'WHERE t.account = ? AND t.alloc_dest IS NOT NULL GROUP BY c.code, t.alloc_dest ORDER BY c.code, t.alloc_dest', [$account]);
            return ['account' => $account, 'shops' => $shops, 'by_shop' => $byShop, 'runs' => $runs, 'states' => $states, 'money' => $money,
                'unpaid' => ['rows' => (int) ($unpaid['n'] ?? 0), 'pence' => (int) ($unpaid['pence'] ?? 0)], 'look' => $look, 'look_total' => $lookTotal,
                'due' => (int) ($wait['due'] ?? 0), 'pending' => (int) ($wait['pending'] ?? 0), 'oldest_pending' => $wait['oldest_pending'] ?? null];
        });
    }

    /**
     * The nightly's first day: the re-read days back from today, earlier when a costed row still pending is (at most PENDING_DAYS back), when
     * the proven coverage ends earlier (a long outage, a stale feed, a catch-up cut by the deadline: from the last day proven, the run resumes),
     * or when rows were read under other shops' forms than now (they are read again, so a form put back recovers what it captures: final
     * review a7); never before the first fee day of the account's shops.
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
        $shops = self::accountShops($this->db, $account);
        $froms = array_filter(array_map(fn (array $s): ?string => self::feesFrom($this->db, $s['code']), $shops));
        $start = $froms === [] ? null : min($froms);
        if ($start !== null) {
            $old = $this->db->value("SELECT MIN(day) FROM paypal_txn WHERE account = ? AND day >= ? AND (read_sig IS NULL OR read_sig <> ?) "
                . "AND (invoice_state <> 'none' OR custom_state <> 'none')", [$account, $start, self::formsSig($shops)]);
            if ($old !== null && (string) $old < $from) {
                $from = (string) $old;
            }
        }
        // after a long outage: back to the last day read without a gap, a day before it (the lead's decision 5, fix round 1)
        if ($start !== null) {
            $covered = self::coveredUntil($this->db, $account, $start);
            $back = $covered === null ? $start : (new \DateTimeImmutable($covered))->modify('-1 day')->format('Y-m-d');
            if ($back < $from) {
                $from = $back;
            }
        }
        return $start !== null && $start > $from ? min($start, $today) : $from;
    }

    /**
     * The last UK day the account's runs PROVED read without a gap from $start (each run from its from_day to its covered_to: the windows it
     * stored whole with PayPal's data complete past their days; a run that failed later keeps what it proved), null when none proved $start
     * (fix round 1: a long outage is read back, and the "not read" alert stays until it is; final review c1, c2).
     */
    public static function coveredUntil(Db $db, string $account, string $start): ?string
    {
        $runs = $db->all('SELECT from_day, covered_to AS to_day FROM paypal_run WHERE account = ? AND covered_to IS NOT NULL AND covered_to >= ? ORDER BY from_day, covered_to',
            [$account, $start]);
        $covered = (new \DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
        $any = false;
        foreach ($runs as $r) {
            $next = (new \DateTimeImmutable($covered))->modify('+1 day')->format('Y-m-d');
            if ((string) $r['from_day'] <= $next && (string) $r['to_day'] > $covered) {
                $covered = (string) $r['to_day'];
                $any = true;
            }
        }
        return $any ? $covered : null;
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
     *  - The invoice and custom fields: only what ONE shop's form captures is kept (the shop and the order number; never the text: a form like
     *    ^([0-9]{7})-.+$ would keep a phone number with it, final review e1); a field that matches no form is kept as "dropped", two forms as
     *    "overlap". A capture kept before stays when this read's forms capture nothing (a form narrowed or edited meanwhile): the evidence of
     *    whose order it is is never lost to a re-read (final review a7, PPF36); a capture of this read replaces it.
     *  - Which version wins (final review a9, PPF37): PayPal's transaction_updated_date, to the microsecond. An older version than the one kept
     *    changes nothing; the same time with other facts keeps the facts and marks the row pp_conflict (a look, never counted) until a newer
     *    version says which; a newer one replaces them (and clears the conflict).
     *  - Every row of the window's days is marked read with the account's shops and forms of now (read_sig: rows read under other forms are
     *    read again by the nightly, nightlyFrom()).
     *
     * @param list<array<string, mixed>> $rows PaypalRow::parse()d
     * @return array{new: int, changed: int}
     */
    private function store(int $runId, string $account, int $channelId, array $rows, Caller $caller, array $shops, string $firstDay, string $lastDay): array
    {
        $sig = self::formsSig($shops);
        $byKey = [];
        $seenRaw = [];
        foreach ($rows as $i => $r) {
            $k = $r['txn_id'] . '|' . $r['event_code'];
            if (isset($seenRaw[$k]) && $seenRaw[$k] !== $r) {
                throw new CwException('paypal_moved', 'PayPal gave the same transaction twice with different content in one window (row ' . ($i + 1) . '): nothing of it was '
                    . 'stored; the next run reads it again', 502);
            }
            $seenRaw[$k] = $r;
            foreach (['invoice', 'custom'] as $f) {
                [$r["{$f}_state"], $r["{$f}_shop"], $r["{$f}_no"]] = self::capture($r["{$f}_raw"], $shops);
                unset($r["{$f}_raw"]); // the text itself goes no further than this line
            }
            $byKey[$k] = $r;
        }
        unset($seenRaw);
        return $this->db->transaction(function (Db $db) use ($runId, $account, $channelId, $byKey, $caller, $sig, $firstDay, $lastDay): array {
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
                $old = $have[$k] ?? null;
                if ($old === null) {
                    $kind = PaypalCodes::kind((string) $r['event_code'], (int) $r['amount_pence']);
                    $new[] = $r + ['kind' => $kind, 'cost_pence' => PaypalCodes::cost($kind, (int) $r['amount_pence'], (int) $r['fee_pence']), 'pp_conflict' => 0];
                    continue;
                }
                // the captures: this read's, else the one kept (evidence never lost to a narrowed form)
                foreach (['invoice', 'custom'] as $f) {
                    if ($r["{$f}_state"] !== 'order' && $old["{$f}_state"] === 'order') {
                        [$r["{$f}_state"], $r["{$f}_shop"], $r["{$f}_no"]] = ['order', (string) $old["{$f}_shop"], (string) $old["{$f}_no"]];
                    }
                }
                $before = self::facts($old) + ['kind' => (string) $old['kind'], 'cost_pence' => (int) $old['cost_pence'], 'pp_conflict' => (int) $old['pp_conflict']];
                $theirs = self::facts($r);
                $age = $old['pp_updated_at'] === null || $r['pp_updated_at'] === null ? 1 : strcmp((string) $r['pp_updated_at'], (string) $old['pp_updated_at']);
                $conflicting = null;
                if ($age < 0) {
                    $same[] = (int) $old['id']; // an older version than the one kept: changes nothing
                    continue;
                }
                if ($age === 0 && array_intersect_key($theirs, array_flip(self::PP_FACTS)) !== array_intersect_key($before, array_flip(self::PP_FACTS))) {
                    // the same time, other facts: the kept facts stay, the row needs a look until a newer version says which
                    $conflicting = array_intersect_key($theirs, array_flip(self::PP_FACTS));
                    $after = array_merge($before, array_intersect_key($theirs, array_flip(self::CAPTURES)), ['pp_conflict' => 1]);
                } else {
                    $kind = PaypalCodes::kind((string) $r['event_code'], (int) $r['amount_pence']);
                    // what PayPal says AND what the rules make of it (a release that classes a code anew re-decides a row it reads again; PP3)
                    $after = $theirs + ['kind' => $kind, 'cost_pence' => PaypalCodes::cost($kind, (int) $r['amount_pence'], (int) $r['fee_pence']),
                        'pp_conflict' => $age === 0 ? (int) $old['pp_conflict'] : 0];
                }
                if ($before === $after) {
                    $same[] = (int) $old['id'];
                    continue;
                }
                $set = [];
                $params = [];
                foreach ([...self::FACTS, 'kind', 'cost_pence', 'pp_conflict'] as $f) {
                    $set[] = "{$f} = ?";
                    $params[] = $after[$f];
                }
                $db->exec('UPDATE paypal_txn SET ' . implode(', ', $set) . ', read_sig = ?, due = 1, due_since = COALESCE(due_since, ?), version = version + 1, last_run = ?, '
                    . 'changed_at = ? WHERE id = ?', [...$params, $sig, $now, $runId, $now, (int) $old['id']]);
                $db->exec("INSERT INTO paypal_txn_history (txn_row, version, what, before_json, after_json, run_id, actor, at) VALUES (?, ?, 'changed', ?, ?, ?, ?, ?)",
                    [(int) $old['id'], (int) $old['version'] + 1, self::json($before), self::json($after + ($conflicting === null ? [] : ['conflicting' => $conflicting])),
                        $runId, $caller->actor, $now]);
                $changed++;
            }
            $cols = ['account', 'channel_id', 'txn_id', 'event_code', ...self::FACTS, 'kind', 'cost_pence', 'pp_conflict', 'read_sig', 'count_state', 'due', 'due_since',
                'first_run', 'last_run', 'read_at', 'changed_at'];
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
                    array_push($params, $r['kind'], $r['cost_pence'], 0, $sig, 'not_counted', 1, $now, $runId, $runId, $now, $now);
                }
                $db->exec('INSERT INTO paypal_txn (' . implode(', ', $cols) . ') VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($cols), '?')) . ')')), $params);
            }
            foreach (array_chunk($same, 1000) as $chunk) {
                $db->exec('UPDATE paypal_txn SET last_run = ?, read_sig = ? WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$runId, $sig, ...$chunk]);
            }
            // the window's days read whole under the forms of now (a row PayPal no longer gives is not read again for ever)
            $db->exec('UPDATE paypal_txn SET read_sig = ? WHERE account = ? AND day BETWEEN ? AND ? AND (read_sig IS NULL OR read_sig <> ?)',
                [$sig, $account, $firstDay, $lastDay, $sig]);
            return ['new' => count($new), 'changed' => $changed];
        });
    }

    /**
     * What the account's shops' forms capture of an invoice or custom field as read: [order, shop, number] when exactly ONE form matches
     * (its (part) digits only), [dropped] when none, [overlap] when two; [none] when PayPal gave none.
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private static function capture(?string $raw, array $shops): array
    {
        if ($raw === null || $raw === '') {
            return ['none', null, null];
        }
        $hits = [];
        foreach ($shops as $sh) {
            if (preg_match($sh['form'], $raw, $m) === 1 && preg_match('/^[0-9]{1,12}$/D', $m[1] ?? '') === 1) {
                $hits[] = [$sh['code'], $m[1]];
            }
        }
        return match (count($hits)) {
            0 => ['dropped', null, null],
            1 => ['order', $hits[0][0], $hits[0][1]],
            default => ['overlap', null, null],
        };
    }

    /**
     * Where these rows count (PPF5-PPF7), decided and written in ONE guarded write with the profit days (DeliveryBills::guardedWrite): the
     * rows as they count now are the plan's "before", as they will count its "after"; the orders they are on (by number, the shop's own
     * orders) are looked up in the plan and again right before the commit (verify: an order of the number stored meanwhile means the plan is
     * old: made again). Every row's due mark is cleared in the same write.
     *  - With a refund or a chargeback come every refund and chargeback on the same order number of its shop, now AND before (an order's
     *    chargebacks share its refund budget: one moved to another order gives its old order's budget to the others, final review a2).
     *  - A row whose shop, number, order or state changes marks due, in the same write, every row that refers to it (its refunds, chargebacks,
     *    fees: they follow it, final review a5); they are decided in the same decideAll().
     *
     * @param list<int> $ids
     * @return int rows whose place changed
     */
    private function decide(Caller $caller, array $ids, ?int $runId): int
    {
        $bills = new DeliveryBills($this->db);
        return $bills->guardedWrite(function (Db $db) use ($ids, $runId, $caller): array {
            for ($pass = 1; ; $pass++) {
                $rows = $db->all('SELECT * FROM paypal_txn WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE', $ids);
                $accounts = array_values(array_unique(array_column($rows, 'account')));
                if (count($accounts) !== 1) {
                    throw new \LogicException('one PayPal account a decision');
                }
                $account = (string) $accounts[0];
                // the account's shops and their forms, read once a plan (checklist 13); each row's shop by PayPal's reference to its sale, else
                // by its captured invoice; then the orders of that shop with its number; each CW shop's first fee day
                $shops = self::accountShops($db, $account);
                $sig = self::formsSig($shops);
                $from = [];
                foreach ($shops as $sh) {
                    if ($sh['id'] !== null) {
                        $from[$sh['id']] = self::feesFrom($db, $sh['code']);
                    }
                }
                $forms = self::resolveForms($db, $account, $rows, $shops);
                // the refunds and chargebacks of the same order numbers (by the shop's code and the number), as resolved now and as stored
                $nums = [];
                foreach ($rows as $r) {
                    if (!in_array($r['kind'], ['refund', 'chargeback'], true)) {
                        continue;
                    }
                    $f = $forms[(int) $r['id']];
                    if ($f['id'] !== null && $f['no'] !== null) {
                        $nums[$f['code'] . '|' . $f['no']] = [(string) $f['code'], (string) $f['no']];
                    }
                    if ($r['form_shop'] !== null && $r['order_no'] !== null) {
                        $nums[$r['form_shop'] . '|' . $r['order_no']] = [(string) $r['form_shop'], (string) $r['order_no']];
                    }
                }
                $more = [];
                foreach ($nums as [$code, $no]) {
                    foreach ($db->column("SELECT id FROM paypal_txn WHERE account = ? AND order_no = ? AND kind IN ('refund', 'chargeback') AND form_shop = ?",
                        [$account, $no, $code]) as $sib) {
                        if (!in_array((int) $sib, $ids, true)) {
                            $more[] = (int) $sib;
                        }
                    }
                }
                if ($more === [] || $pass >= 6) {
                    break;
                }
                $ids = array_values(array_unique([...$ids, ...$more]));
            }
            $keys = [];
            foreach ($forms as $f) {
                if ($f['id'] !== null && $f['no'] !== null) {
                    $keys[] = [$f['id'], $f['no']];
                }
            }
            $lookup = self::orders($db, $keys);
            // each row's place without a refund offset first (which chargebacks count on which order, which PayPal refunds are of it), then
            // the offsets of the chargebacks that count (final review a1)
            $prelim = [];
            foreach ($rows as $r) {
                $prelim[(int) $r['id']] = self::place($r, $forms[(int) $r['id']], $shops[0]['id'], $from, $lookup, null);
            }
            $offsets = self::chargebackOffsets($db, $account, $rows, $prelim, $lookup);
            $before = [];
            $after = [];
            $watch = [];
            $plans = [];
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $place = (isset($offsets[$id]) ? self::place($r, $forms[$id], $shops[0]['id'], $from, $lookup, $offsets[$id]) : $prelim[$id]) + ['decided_sig' => $sig];
                $plans[$id] = [$r, $place];
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
                // makes the plan old (checklist 11: a match is exactly one order, re-checked under the write's guards); the refund budgets too
                'verify' => static fn (Db $db): bool => self::orders($db, $keys) === $lookup && self::chargebackOffsets($db, $account, $rows, $prelim, $lookup) === $offsets,
                'write' => function (Db $db) use ($plans, $runId, $caller, $account): int {
                    // a few statements whatever the chunk (the guards are held: the hold budget at full size, PPF15): the changed rows in ONE
                    // update (a CASE per column), their history in ONE insert, the others' due marks cleared in one more
                    $now = Clock::db(($this->clock)());
                    $changed = [];
                    $keep = [];
                    $moved = [];
                    foreach ($plans as $id => [$r, $place]) {
                        $old = self::norm(array_intersect_key($r, array_flip(self::PLACE)));
                        $new = self::norm($place);
                        if ($old === $new) {
                            $keep[] = $id;
                        } else {
                            $changed[$id] = [(int) $r['version'] + 1, $old, $new];
                            if (array_intersect_key($old, array_flip(self::IDENTITY)) !== array_intersect_key($new, array_flip(self::IDENTITY))) {
                                $moved[] = (string) $r['txn_id'];
                            }
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
                        $n = $db->exec('UPDATE paypal_txn SET ' . implode(', ', $set) . ', due = 0, due_since = NULL, version = version + 1, changed_at = ? WHERE id IN ('
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
                        $db->exec('UPDATE paypal_txn SET due = 0, due_since = NULL WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk);
                    }
                    // the rows that refer to a row that moved follow it: due now, decided next (durable in this same write)
                    foreach (array_chunk(array_values(array_unique($moved)), 500) as $chunk) {
                        $db->exec('UPDATE paypal_txn SET due = 1, due_since = ? WHERE account = ? AND due = 0 AND ref_txn_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                            [$now, $account, ...$chunk]);
                    }
                    return count($changed);
                }];
        });
    }

    /**
     * Each row's shop and order number (fix round 1, the lead's decisions 2 and 3; final review a6): a refund, a chargeback or a fee follows
     * PayPal's reference to the row it is of (its sale; a chargeback fee's chargeback, then its sale), when that row has a shop; else, and for
     * a sale, the ONE shop whose form captured its invoice (else its custom field). state: ok; none (no shop's form); overlap (two shops'
     * forms); conflict (its reference says one shop or number, its invoice another); unresolved (the row it refers to has no clear shop
     * itself: a conflict, two forms, a shop lost, a reference of its own unresolved: held with it, never put on the main shop's line);
     * missing (PayPal's reference names a transaction CW does not hold, and its own fields name no shop: held for a look, never put on the
     * main shop's line by default).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array{id: ?int, code: string, name: string, form: string}> $shops
     * @return array<int, array{code: ?string, id: ?int, no: ?string, by: string, state: string}> by row id
     */
    private static function resolveForms(Db $db, string $account, array $rows, array $shops): array
    {
        // the rows referred to, three steps up at most (a chargeback fee -> its chargeback -> its sale), read once
        $byTxn = [];
        foreach ($rows as $r) {
            $byTxn[(string) $r['txn_id']][] = $r;
        }
        $want = array_values(array_unique(array_filter(array_map(static fn (array $r): ?string => in_array($r['kind'], self::REFERRING, true) ? $r['ref_txn_id'] : null,
            $rows))));
        for ($step = 0; $step < 3 && $want !== []; $step++) {
            $next = [];
            foreach (array_chunk(array_values(array_filter($want, static fn (string $t): bool => !isset($byTxn[$t]))), 500) as $chunk) {
                foreach ($db->all('SELECT * FROM paypal_txn WHERE account = ? AND txn_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$account, ...$chunk]) as $p) {
                    $byTxn[(string) $p['txn_id']][] = $p;
                    if (in_array($p['kind'], self::REFERRING, true) && $p['ref_txn_id'] !== null) {
                        $next[] = (string) $p['ref_txn_id'];
                    }
                }
            }
            $want = array_values(array_unique($next));
        }
        $rank = ['sale' => 0, 'chargeback' => 1, 'fee' => 2, 'refund' => 3];
        $resolve = static function (array $r, int $depth) use (&$resolve, $byTxn, $rank, $shops): array {
            $own = self::form($r, $shops);
            if ($depth > 3 || !in_array($r['kind'], self::REFERRING, true) || $r['ref_txn_id'] === null) {
                return $own;
            }
            $parents = array_values(array_filter($byTxn[(string) $r['ref_txn_id']] ?? [], static fn (array $p): bool => isset($rank[$p['kind']]) && $p['id'] !== $r['id']));
            if ($parents === []) {
                // a transaction CW does not hold (before what was read, or not read yet): its own invoice says its shop; without one it waits
                // for a look (a reference that is not a transaction, an agreement's, never comes: its own fields as for any row)
                return $own['state'] === 'ok' || ($r['ref_type'] !== null && $r['ref_type'] !== 'TXN') ? $own : ['state' => 'missing'] + $own;
            }
            usort($parents, static fn (array $a, array $b): int => $rank[$a['kind']] <=> $rank[$b['kind']]);
            $ref = $resolve($parents[0], $depth + 1);
            if ($ref['state'] === 'none') {
                // the row it refers to names no shop either: as that row (the main shop's line), unless it had a shop and lost it
                return $parents[0]['form_shop'] !== null ? ['state' => 'unresolved'] + $own : $own;
            }
            if ($ref['state'] !== 'ok') {
                return ['state' => 'unresolved'] + $own;
            }
            if ($own['state'] === 'ok' && ($own['code'] !== $ref['code'] || $own['no'] !== $ref['no'])) {
                return ['state' => 'conflict'] + $own;
            }
            return ['by' => 'reference'] + $ref;
        };
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $resolve($r, 0);
        }
        return $out;
    }

    /**
     * The shop a row belongs to by what the forms captured of its invoice (else its custom field): the shop and the number kept at the store
     * (PPF36); two shops' forms (overlap); none (nothing captured, or the shop it was captured for is no longer one of the account's).
     *
     * @param array<string, mixed> $r
     * @param list<array{id: ?int, code: string, name: string, form: string}> $shops
     * @return array{code: ?string, id: ?int, no: ?string, by: string, state: string}
     */
    private static function form(array $r, array $shops): array
    {
        foreach (['invoice', 'custom'] as $by) {
            if ($r["{$by}_state"] === 'overlap') {
                return ['code' => null, 'id' => null, 'no' => null, 'by' => $by, 'state' => 'overlap']; // two shops' forms: never guessed
            }
            if ($r["{$by}_state"] !== 'order') {
                continue;
            }
            foreach ($shops as $sh) {
                if ($sh['code'] === $r["{$by}_shop"]) {
                    return ['code' => $sh['code'], 'id' => $sh['id'], 'no' => (string) $r["{$by}_no"], 'by' => $by, 'state' => 'ok'];
                }
            }
        }
        return ['code' => null, 'id' => null, 'no' => null, 'by' => 'none', 'state' => 'none'];
    }

    /**
     * The refunds CW counts in profit on an order (inc VAT: lines, order level, delivery): none when the order is not paid in CW (it counts
     * nothing yet) or is a re-send (its refunds take nothing off: PR129). Final review a4, PPF34.
     */
    private static function cwRefunds(Db $db, int $channelId, string $ref): int
    {
        $o = $db->one('SELECT day, parent_ref, line_refund_inc + order_refund_inc + delivery_refund_inc AS r FROM sale_order WHERE channel_id = ? AND order_ref = ?',
            [$channelId, $ref]);
        return $o === null || $o['day'] === null || $o['parent_ref'] !== null ? 0 : max(0, (int) $o['r']);
    }

    /**
     * PayPal's own refunds of an order number of a shop as decided (paid out by PayPal, so money out beside any chargeback: final review a3,
     * PPF34): done or reversed, counted or before the shop's first fee day.
     */
    private static function paypalRefunds(Db $db, string $account, int $channelId, string $no): int
    {
        return (int) $db->value("SELECT COALESCE(SUM(-amount_pence), 0) FROM paypal_txn WHERE account = ? AND order_no = ? AND kind = 'refund' AND shop_channel = ? "
            . "AND status IN ('S', 'V') AND count_state IN ('counts', 'before_start') AND amount_pence < 0", [$account, $no, $channelId]);
    }

    /**
     * The refund offset of each chargeback that COUNTS on an order (PPF23, the lead's decision 1; final review a1, a3, a4; PPF34): the order's
     * budget is the refunds CW counts in profit on it (cwRefunds) less PayPal's own refunds of it (paid out by PayPal: real money out beside
     * the chargeback, never offset), at 0 at least; the order's countable chargebacks in time order use it up, each its money taken back at
     * most; a pending, denied, not understood or not-on-this-order chargeback uses none; a reversal (money back) uses none and counts whole;
     * the fees always count. By row id; only the chargebacks that count on an order.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $prelim each row's place without an offset
     * @param array<string, list<string>> $lookup
     * @return array<int, array{offset: int, seen: int}>
     */
    private static function chargebackOffsets(Db $db, string $account, array $rows, array $prelim, array $lookup): array
    {
        $byOrder = [];
        $paidOut = [];
        foreach ($rows as $r) {
            $p = $prelim[(int) $r['id']];
            if ($r['kind'] === 'chargeback' && $p['count_state'] === 'counts' && $p['match_state'] === 'order') {
                $byOrder[$p['shop_channel'] . '|' . $p['match_order_ref']][] = $r;
            }
            // PayPal's own refunds among the rows (every one of the order's number is with them: decide() brings them)
            if ($r['kind'] === 'refund' && in_array($p['count_state'], ['counts', 'before_start'], true) && in_array($r['status'], ['S', 'V'], true)
                && $p['shop_channel'] !== null && $p['order_no'] !== null && (int) $r['amount_pence'] < 0) {
                $paidOut[$p['shop_channel'] . '|' . $p['order_no']] = ($paidOut[$p['shop_channel'] . '|' . $p['order_no']] ?? 0) - (int) $r['amount_pence'];
            }
        }
        $out = [];
        ksort($byOrder, SORT_STRING);
        foreach ($byOrder as $k => $list) {
            [$ch, $ref] = explode('|', $k, 2);
            $no = (string) $prelim[(int) $list[0]['id']]['order_no'];
            $budget = max(0, self::cwRefunds($db, (int) $ch, $ref) - ($paidOut[$ch . '|' . $no] ?? 0));
            usort($list, static fn (array $a, array $b): int => [(string) $a['initiated_at'], (int) $a['id']] <=> [(string) $b['initiated_at'], (int) $b['id']]);
            $left = $budget;
            foreach ($list as $r) {
                $taken = -(int) $r['amount_pence'];
                $use = $taken > 0 ? min($taken, $left) : 0;
                $left -= $use;
                $out[(int) $r['id']] = ['offset' => $use, 'seen' => $budget];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Where one row counts (PPF5-PPF7, PPF21-PPF24, PPF34-PPF37), in this order: not understood (an unknown code, an unknown status, a fee on
     * an ignored row, another currency, a wrong sign, two versions of PayPal at the same time: look, never counted); ignored (not counted);
     * denied (not counted). Then its shop: its reference and its invoice disagree, two shops' forms, the row it refers to unresolved or not
     * held with no shop of its own: look; no shop's form: the account's main shop's "not on an order" line, unless it had a shop before (its
     * shop went: look, never another shop's line); a shop CW does not hold (Hayati Vapes): outside (kept, never counted). Before that shop's
     * first fee day (or that shop not switched on: Electrofag until its day): before_start; pending: read again later; else it counts: on its
     * order (exactly one order of its shop has its number: a sale's fee on the order's paid day, the others on their own day; a chargeback's
     * money only beyond its refund offset) or on its shop's "not on an order" line; nothing of 0.
     *
     * @param array<string, mixed> $r a paypal_txn row
     * @param array{code: ?string, id: ?int, no: ?string, by: string, state: string} $form resolveForms()
     * @param array<int, ?string> $from the account's CW shops => their first fee day
     * @param array<string, list<string>> $lookup "channel|number" => the refs of that shop's orders with it
     * @param array{offset: int, seen: int}|null $cb chargebackOffsets() (null: no offset)
     * @return array<string, mixed> the PLACE columns (but decided_sig)
     */
    private static function place(array $r, array $form, int $main, array $from, array $lookup, ?array $cb): array
    {
        $none = ['look_reason' => null, 'form_shop' => null, 'order_no' => null, 'match_state' => null, 'matched_by' => null, 'match_order_ref' => null, 'candidates' => 0,
            'shop_channel' => null, 'refund_offset_pence' => 0, 'refund_seen_pence' => null, 'alloc_dest' => null, 'alloc_channel' => null, 'alloc_order_ref' => null,
            'alloc_day' => null, 'alloc_pence' => 0];
        $kind = (string) $r['kind'];
        $status = (string) $r['status'];
        $look = match (true) {
            $kind === 'unknown' => 'unknown_code',
            // an unknown status is said whatever the kind, before an ignored row is let go (final review e2)
            !in_array($status, ['S', 'V', 'P', 'D'], true) => 'status',
            $kind === 'ignore' => (int) $r['fee_pence'] !== 0 ? 'fee_on_ignored' : null,
            $r['currency'] !== 'GBP' || ($r['fee_currency'] !== null && $r['fee_currency'] !== 'GBP') => 'currency',
            !PaypalCodes::signOk($kind, (int) $r['amount_pence']) => 'sign',
            (int) ($r['pp_conflict'] ?? 0) === 1 => 'pp_conflict',
            default => null,
        };
        $named = ['form_shop' => $form['code'], 'order_no' => $form['no'], 'matched_by' => $form['by']] + $none;
        if ($look !== null) {
            return ['count_state' => 'look', 'look_reason' => $look] + $named;
        }
        if ($kind === 'ignore' || $status === 'D') {
            return ['count_state' => 'not_counted'] + $named;
        }
        // the shop's form edited, or the reference and the invoice disagreeing, never moves money to another shop (the lead's decision 2)
        $kept = ['form_shop' => $r['form_shop'], 'order_no' => $r['order_no'], 'matched_by' => $form['by']] + $none;
        $held = ['conflict' => 'ref_conflict', 'overlap' => 'form_overlap', 'unresolved' => 'ref_unresolved', 'missing' => 'ref_missing'][$form['state']] ?? null;
        if ($held !== null) {
            return ['count_state' => 'look', 'look_reason' => $held] + $kept;
        }
        if ($form['state'] === 'none' && $r['form_shop'] !== null) {
            return ['count_state' => 'look', 'look_reason' => 'form_lost'] + $kept;
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
        $place = ['count_state' => 'counts', 'match_state' => $match, 'match_order_ref' => $match === 'order' ? $hits[0] : null,
            'candidates' => $match === 'ambiguous' ? min(255, count($hits)) : 0] + $shop;
        if ($match === 'order' && $kind === 'chargeback' && $cb !== null) {
            $place = ['refund_offset_pence' => $cb['offset'], 'refund_seen_pence' => $cb['seen']] + $place;
        }
        $cost = (int) $r['cost_pence'] - (int) ($place['refund_offset_pence'] ?? 0);
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
            $out[$f] = in_array($f, ['candidates', 'shop_channel', 'alloc_channel', 'alloc_pence', 'refund_offset_pence', 'refund_seen_pence'], true) && $v !== null ? (int) $v
                : ($v === null ? null : (string) $v);
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
        $this->db->exec('UPDATE paypal_run SET windows = ?, pages = ?, rows_read = ?, rows_new = ?, rows_changed = ?, refreshed_to = ?, covered_to = ? WHERE id = ?',
            [$sum['windows'], $sum['pages'], $sum['read'], $sum['new'], $sum['changed'], self::refreshed($sum['refreshed']), $sum['covered'], $runId]);
    }

    private function finish(int $runId, array $sum, string $state, ?string $error): void
    {
        $this->db->exec('UPDATE paypal_run SET state = ?, windows = ?, pages = ?, rows_read = ?, rows_new = ?, rows_changed = ?, allocated = ?, refreshed_to = ?, covered_to = ?, '
            . 'error = ?, finished_at = ? WHERE id = ?', [$state, $sum['windows'], $sum['pages'], $sum['read'], $sum['new'], $sum['changed'], $sum['allocated'],
            self::refreshed($sum['refreshed']), $sum['covered'], $error, Clock::db(($this->clock)()), $runId]);
    }

    /**
     * The last UK day a window of $last proves read: the day before the UK day of PayPal's refresh time (that day is not finished in PayPal's
     * data), at most $last; null when PayPal gave no refresh time (its completeness unknown: nothing proven, final review c1).
     */
    private static function proven(string $last, ?string $refreshed): ?string
    {
        if (self::refreshed($refreshed) === null) {
            return null;
        }
        $done = (new \DateTimeImmutable(ReportRefresh::ukDay((string) $refreshed)))->modify('-1 day')->format('Y-m-d');
        return min($last, $done);
    }

    /** PayPal's refresh time as PaypalApi read it (CW's UTC database time, or null). */
    private static function refreshed(?string $t): ?string
    {
        return $t !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $t) === 1 ? $t : null;
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
